<?php
/**
 * Daily WP-Cron scheduling and background search jobs.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the daily search event in sync with settings.
 */
class Hooks {
	private const LOCK_KEY = 'stock_hot_news_run_lock';
	private const EXEC_OPTION = '_stock_hot_news_exec';
	private const TOKEN_KEY = 'stock_hot_news_run_token';
	private const LOCK_TTL  = 1200;

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var Runner
	 */
	private $runner;

	public function __construct( Options $options, Runner $runner ) {
		$this->options = $options;
		$this->runner  = $runner;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'maybeSchedule' ) );
		add_action( STOCK_HOT_NEWS_CRON_HOOK, array( $this, 'runScheduled' ) );
		add_action( STOCK_HOT_NEWS_RUN_NOW, array( $this, 'runQueued' ) );
		add_action( 'wp_ajax_stock_hot_news_run_now', array( $this, 'handleAjaxRunNow' ) );
		add_action( 'wp_ajax_nopriv_stock_hot_news_run_now', array( $this, 'handleAjaxRunNow' ) );
		add_filter( 'http_request_host_is_external', array( $this, 'allowLocalAiHost' ), 10, 3 );
	}

	/**
	 * Allow the configured LAN model host through WordPress HTTP SSRF checks.
	 *
	 * @param mixed $allow Current allow flag.
	 * @param mixed $host  Request host.
	 * @param mixed $url   Request URL.
	 */
	public function allowLocalAiHost( $allow, $host, $url ): bool {
		unset( $url );
		$base       = (string) ( $this->options->resolvedAi()['baseUrl'] ?? '' );
		$parsed     = wp_parse_url( $base );
		$configured = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
		$host       = strtolower( (string) $host );
		if ( $configured !== '' && $host === $configured ) {
			return true;
		}
		if ( in_array( $host, array( '127.0.0.1', 'localhost' ), true ) ) {
			return true;
		}
		return (bool) $allow;
	}

	public function maybeSchedule(): void {
		$enabled = (string) $this->options->get( 'enabled', '1' ) === '1'
			&& (string) $this->options->get( 'scheduleEnabled', '' ) === '1';
		$next    = wp_next_scheduled( STOCK_HOT_NEWS_CRON_HOOK );

		if ( ! $enabled ) {
			if ( $next ) {
				wp_clear_scheduled_hook( STOCK_HOT_NEWS_CRON_HOOK );
			}
			return;
		}

		$target = $this->nextRunTimestamp();
		if ( $next && abs( $next - $target ) < 120 ) {
			return;
		}
		if ( $next ) {
			wp_clear_scheduled_hook( STOCK_HOT_NEWS_CRON_HOOK );
		}
		$scheduled = wp_schedule_event( $target, 'daily', STOCK_HOT_NEWS_CRON_HOOK );
		if ( false === $scheduled ) {
			error_log( 'Stock Hot News: failed to schedule the daily search event.' );
		}
	}

	public function isBusy(): bool {
		$this->clearStaleLock();
		if ( get_transient( self::LOCK_KEY ) || get_option( self::EXEC_OPTION ) ) {
			return true;
		}
		$status = (string) ( $this->options->state()['runStatus'] ?? 'idle' );
		return in_array( $status, array( 'queued', 'running', 'covering' ), true );
	}

	/**
	 * Queue a search so the browser can leave immediately.
	 *
	 * @return true|\WP_Error
	 */
	public function queueRun( bool $createPost ) {
		$this->clearStaleLock();
		if ( $this->isBusy() ) {
			return new \WP_Error( 'already_running', '搜尋已在背景執行中，請稍候刷新本頁。' );
		}

		$jobId = wp_generate_uuid4();
		set_transient( self::LOCK_KEY, $jobId, self::LOCK_TTL );
		set_transient(
			self::TOKEN_KEY,
			array(
				'jobId'      => $jobId,
				'createPost' => $createPost,
			),
			self::LOCK_TTL
		);

		$patched = $this->options->patchState(
			array(
				'runStatus'        => 'queued',
				'runStartedAt'     => (string) time(),
				'queuedCreatePost' => $createPost,
				'lastError'        => '',
			)
		);
		if ( $patched !== true ) {
			delete_transient( self::LOCK_KEY );
			delete_transient( self::TOKEN_KEY );
			return new \WP_Error( 'queue_state', '無法把搜尋排進背景工作，請再試一次。' );
		}

		wp_clear_scheduled_hook( STOCK_HOT_NEWS_RUN_NOW );
		$scheduled = wp_schedule_single_event( time(), STOCK_HOT_NEWS_RUN_NOW );
		if ( false === $scheduled && ! wp_next_scheduled( STOCK_HOT_NEWS_RUN_NOW ) ) {
			error_log( 'Stock Hot News: failed to schedule the background search event.' );
		}

		$this->kickLoopback( $jobId );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return true;
	}

	/**
	 * Continue the queued job after the browser has already been redirected.
	 */
	public function continueAfterRedirect(): void {
		if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			$this->runQueued();
		}
	}

	public function handleAjaxRunNow(): void {
		$jobId = isset( $_REQUEST['job'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['job'] ) ) : '';
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) ) : '';
		if ( $jobId === '' || $token === '' || ! hash_equals( wp_hash( $jobId, 'nonce' ), $token ) ) {
			status_header( 403 );
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}
		$stored = get_transient( self::TOKEN_KEY );
		if ( ! is_array( $stored ) || (string) ( $stored['jobId'] ?? '' ) !== $jobId ) {
			status_header( 409 );
			wp_die( 'stale', '', array( 'response' => 409 ) );
		}
		$this->runQueued();
		wp_die( 'ok' );
	}

	public function runScheduled(): void {
		if ( (string) $this->options->get( 'enabled', '1' ) !== '1' ) {
			return;
		}
		$this->clearStaleLock();
		if ( $this->isBusy() ) {
			error_log( 'Stock Hot News: skipped scheduled run because another search is already running.' );
			return;
		}
		$createPost = (string) $this->options->get( 'createPost', '1' ) === '1';
		$queued     = $this->queueRun( $createPost );
		if ( is_wp_error( $queued ) ) {
			error_log( 'Stock Hot News: scheduled queue failed: ' . $queued->get_error_message() );
			return;
		}
		$this->runQueued();
	}

	public function runQueued(): void {
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			$coverTimeout = max( 60, min( 600, (int) $this->options->get( 'codexTimeout', 180 ) ) );
			set_time_limit( max( 300, 180 + $coverTimeout ) );
		}

		if ( get_option( self::EXEC_OPTION ) ) {
			return;
		}
		if ( ! add_option( self::EXEC_OPTION, (string) time(), '', false ) ) {
			return;
		}

		$token      = get_transient( self::TOKEN_KEY );
		$state      = $this->options->state();
		$createPost = ! empty( $state['queuedCreatePost'] );
		if ( is_array( $token ) && array_key_exists( 'createPost', $token ) ) {
			$createPost = (bool) $token['createPost'];
		}

		$this->options->patchState(
			array(
				'runStatus'    => 'running',
				'runStartedAt' => (string) time(),
			)
		);

		try {
			$result = $this->runner->run( $createPost );
			if ( is_wp_error( $result ) ) {
				$this->options->patchState(
					array(
						'runStatus'        => 'error',
						'queuedCreatePost' => false,
					)
				);
			} else {
				$this->options->patchState(
					array(
						'runStatus'        => 'done',
						'queuedCreatePost' => false,
					)
				);
			}
		} catch ( \Throwable $error ) {
			error_log( 'Stock Hot News: background run failed: ' . $error->getMessage() );
			$this->options->patchState(
				array(
					'runStatus'        => 'error',
					'lastError'        => $error->getMessage(),
					'lastRunAt'        => wp_date( 'Y-m-d H:i:s' ),
					'queuedCreatePost' => false,
				)
			);
		} finally {
			delete_transient( self::LOCK_KEY );
			delete_option( self::EXEC_OPTION );
			delete_transient( self::TOKEN_KEY );
			wp_clear_scheduled_hook( STOCK_HOT_NEWS_RUN_NOW );
		}
	}

	public function nextRunTimestamp(): int {
		$hour   = max( 0, min( 23, (int) $this->options->get( 'scheduleHour', 16 ) ) );
		$minute = max( 0, min( 59, (int) $this->options->get( 'scheduleMinute', 30 ) ) );
		$now    = ( new Collector() )->nowTaipei();
		$today  = $now->setTime( $hour, $minute, 0 );
		if ( $today <= $now ) {
			$today = $today->modify( '+1 day' );
		}
		return $today->getTimestamp();
	}

	private function kickLoopback( string $jobId ): void {
		$url = add_query_arg(
			array(
				'action' => 'stock_hot_news_run_now',
				'job'    => $jobId,
				'token'  => wp_hash( $jobId, 'nonce' ),
			),
			admin_url( 'admin-ajax.php' )
		);
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			error_log( 'Stock Hot News: background loopback failed: ' . $response->get_error_message() );
		}
	}

	private function clearStaleLock(): void {
		$state     = $this->options->state();
		$status    = (string) ( $state['runStatus'] ?? 'idle' );
		$startedAt = (int) ( $state['runStartedAt'] ?? 0 );
		$isBusy    = in_array( $status, array( 'queued', 'running', 'covering' ), true );
		$stale     = $startedAt > 0 && ( time() - $startedAt ) > self::LOCK_TTL;
		if ( $isBusy && $stale ) {
			delete_transient( self::LOCK_KEY );
			delete_option( self::EXEC_OPTION );
			delete_transient( self::TOKEN_KEY );
			$this->options->patchState(
				array(
					'runStatus'        => 'error',
					'lastError'        => '上一次背景搜尋逾時未完成，已解除鎖定。請再按一次立即搜尋。',
					'queuedCreatePost' => false,
				)
			);
		}
	}
}
