<?php
/**
 * Orchestrate collect → rank → optional publish.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-shot pipeline used by the settings screen, cron, and MCP.
 */
class Runner {
	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var Collector
	 */
	private $collector;

	/**
	 * @var Ranker
	 */
	private $ranker;

	/**
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * @var Publisher
	 */
	private $publisher;

	/**
	 * @var MarketData
	 */
	private $marketData;

	/**
	 * @var CoverGenerator
	 */
	private $coverGenerator;

	public function __construct( Options $options, Collector $collector, Ranker $ranker, Renderer $renderer, Publisher $publisher, MarketData $marketData, CoverGenerator $coverGenerator ) {
		$this->options        = $options;
		$this->collector      = $collector;
		$this->ranker         = $ranker;
		$this->renderer       = $renderer;
		$this->publisher      = $publisher;
		$this->marketData     = $marketData;
		$this->coverGenerator = $coverGenerator;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run( bool $createPost ) {
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			$coverTimeout = max( 60, min( 600, (int) $this->options->get( 'codexTimeout', 180 ) ) );
			set_time_limit( max( 300, 180 + $coverTimeout ) );
		}

		$market       = (string) $this->options->get( 'market', 'tw' );
		$topicLimit   = max( 1, min( 10, (int) $this->options->get( 'topicLimit', 5 ) ) );
		$maxHeadlines = max( 10, min( 120, (int) $this->options->get( 'maxHeadlines', 40 ) ) );
		$timeout      = max( 5, min( 30, (int) $this->options->get( 'fetchTimeout', 8 ) ) );
		if ( ! in_array( $market, array( 'tw', 'us', 'all' ), true ) ) {
			$market = 'tw';
		}

		try {
			[ $items, $warnings ] = $this->collector->collect( $market, $timeout, $maxHeadlines );
			$todayLabel           = $this->collector->taipeiToday();
			$official             = $this->marketData->fetch( $todayLabel, $items, $timeout );
			if ( (string) ( $official['warning'] ?? '' ) !== '' ) {
				$warnings[] = (string) $official['warning'];
			}
			$report = $this->ranker->rank( $items, $todayLabel, $market, $topicLimit, $official );
		} catch ( \RuntimeException $error ) {
			$saved = $this->options->saveState(
				array_merge(
					$this->options->state(),
					array(
						'runStatus'        => 'error',
						'queuedCreatePost' => false,
						'lastRunAt'        => $this->collector->nowTaipei()->format( 'Y-m-d H:i:s' ),
						'lastError'        => $error->getMessage(),
						'lastWarnings'     => array(),
						'lastPostId'       => 0,
						'lastModel'        => '',
						'lastReport'       => array(),
						'lastHtml'         => '',
					)
				)
			);
			if ( $saved !== true ) {
				error_log( 'Stock Hot News: failed to persist error state.' );
			}
			return new \WP_Error( 'run_failed', $error->getMessage() );
		}

		$html   = $this->renderer->toHtml( $report );
		$postId = 0;
		if ( $createPost ) {
			$savedPost = $this->publisher->saveDraft( $report, $html );
			if ( is_wp_error( $savedPost ) ) {
				$warnings[] = $savedPost->get_error_message();
			} else {
				$postId = (int) $savedPost;
			}
		}

		if ( $postId > 0 && (string) $this->options->get( 'generateCover', '1' ) === '1' ) {
			$this->options->patchState(
				array(
					'runStatus' => 'covering',
				)
			);
			$cover = $this->coverGenerator->attachCover( $report, $postId );
			$report['imagePrompt']  = (string) ( $cover['imagePrompt'] ?? '' );
			$report['coverPath']    = (string) ( $cover['outputPath'] ?? '' );
			$newCoverId             = (int) ( $cover['coverImageId'] ?? 0 );
			if ( $newCoverId > 0 ) {
				$report['coverImageId'] = $newCoverId;
			}
			foreach ( (array) ( $cover['warnings'] ?? array() ) as $warning ) {
				$warning = trim( (string) $warning );
				if ( $warning !== '' ) {
					$warnings[] = $warning;
				}
			}
			$html      = $this->renderer->toHtml( $report );
			$refreshed = $this->publisher->updateContent( $postId, $html, $report );
			if ( is_wp_error( $refreshed ) ) {
				$warnings[] = $refreshed->get_error_message();
			}
		}

		$stateSaved = $this->options->saveState(
			array_merge(
				$this->options->state(),
				array(
					'runStatus'        => 'done',
					'queuedCreatePost' => false,
					'lastRunAt'        => $this->collector->nowTaipei()->format( 'Y-m-d H:i:s' ),
					'lastError'        => '',
					'lastWarnings'     => array_values( $warnings ),
					'lastPostId'       => $postId,
					'lastModel'        => (string) ( $report['model'] ?? '' ),
					'lastReport'       => $report,
					'lastHtml'         => $html,
					'lastImagePrompt'  => (string) ( $report['imagePrompt'] ?? '' ),
					'lastCoverId'      => (int) ( $report['coverImageId'] ?? 0 ),
				)
			)
		);
		if ( $stateSaved !== true ) {
			error_log( 'Stock Hot News: failed to persist last-run state.' );
			return new \WP_Error( 'state_save', '搜尋完成，但無法儲存結果到資料庫。' );
		}

		$report['postId']   = $postId;
		$report['html']     = $html;
		$report['warnings'] = array_values( $warnings );
		return $report;
	}
}
