<?php
/**
 * Plugin settings and last-run state.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes stock_hot_news options.
 */
class Options {
	public const OPTION_KEY = 'stock_hot_news';
	public const STATE_KEY  = 'stock_hot_news_state';

	/**
	 * @var array<string, mixed>
	 */
	private $settings = array();

	/**
	 * Default settings shown on first install.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'         => '1',
			'reuseLineAi'     => '1',
			'aiEnabled'       => '1',
			'aiBaseUrl'       => 'http://127.0.0.1:8080/v1',
			'aiApiKey'        => 'local',
			'aiModel'         => '',
			'market'          => 'tw',
			'topicLimit'      => 5,
			'maxHeadlines'    => 40,
			'fetchTimeout'    => 8,
			'chatTimeout'     => 45,
			'requireAi'       => '',
			'extraPrompt'     => '',
			'createPost'      => '1',
			'postStatus'      => 'draft',
			'categorySlug'    => 'stock-views',
			'coverImageId'    => 0,
			'generateCover'   => '',
			'unloadTextModel' => '',
			'codexBin'        => '',
			'codexHome'       => '',
			'codexUserHome'   => '',
			'codexApiKey'     => '',
			'codexTimeout'    => 180,
			'coverSize'       => '1536x1024',
			'scheduleEnabled' => '',
			'scheduleHour'    => 16,
			'scheduleMinute'  => 30,
			'disclaimer'      => '本文是個人研究紀錄，整理當日台股盤後公開資訊，不構成投資建議、招攬或獲利保證。股價以臺灣證券交易所盤後資料為準；新聞題材是整理，不是已驗證的單一因果。',
		);
	}

	/**
	 * Empty last-run snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaultState(): array {
		return array(
			'runStatus'         => 'idle',
			'runStartedAt'      => '',
			'queuedCreatePost'  => false,
			'lastRunAt'         => '',
			'lastError'         => '',
			'lastWarnings'      => array(),
			'lastPostId'        => 0,
			'lastModel'         => '',
			'lastReport'        => array(),
			'lastHtml'          => '',
			'lastImagePrompt'   => '',
			'lastCoverId'       => 0,
		);
	}

	/**
	 * Load settings from the database.
	 */
	public function load(): void {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			error_log( 'Stock Hot News: stored settings are not an array; using defaults.' );
			$stored = array();
		}
		$this->settings = array_merge( self::defaults(), $stored );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( $this->settings === array() ) {
			$this->load();
		}
		return $this->settings;
	}

	/**
	 * @param mixed $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->all();
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}
		return $default;
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public function save( array $settings ): bool {
		$this->settings = array_merge( self::defaults(), $settings );
		$updated        = update_option( self::OPTION_KEY, $this->settings, false );
		if ( false === $updated && get_option( self::OPTION_KEY ) !== $this->settings ) {
			error_log( 'Stock Hot News: failed to save settings.' );
			return false;
		}
		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$stored = get_option( self::STATE_KEY, array() );
		if ( ! is_array( $stored ) ) {
			error_log( 'Stock Hot News: stored run state is not an array; using defaults.' );
			$stored = array();
		}
		return array_merge( self::defaultState(), $stored );
	}

	/**
	 * @param array<string, mixed> $state Last-run payload.
	 */
	public function saveState( array $state ): bool {
		$merged  = array_merge( self::defaultState(), $state );
		$updated = update_option( self::STATE_KEY, $merged, false );
		if ( false === $updated && get_option( self::STATE_KEY ) !== $merged ) {
			error_log( 'Stock Hot News: failed to save last-run state.' );
			return false;
		}
		return true;
	}

	/**
	 * Merge a partial snapshot into the stored last-run state.
	 *
	 * @param array<string, mixed> $patch Fields to overwrite.
	 */
	public function patchState( array $patch ): bool {
		return $this->saveState( array_merge( $this->state(), $patch ) );
	}

	/**
	 * Resolved chat API credentials, optionally borrowed from LINE Post Notify.
	 *
	 * @return array{enabled:bool,baseUrl:string,apiKey:string,model:string}
	 */
	public function resolvedAi(): array {
		$settings = $this->all();
		$enabled  = (string) ( $settings['aiEnabled'] ?? '' ) === '1';
		$baseUrl  = trim( (string) ( $settings['aiBaseUrl'] ?? '' ) );
		$apiKey   = trim( (string) ( $settings['aiApiKey'] ?? '' ) );
		$model    = trim( (string) ( $settings['aiModel'] ?? '' ) );

		if ( (string) ( $settings['reuseLineAi'] ?? '' ) === '1' && function_exists( 'linePostNotifyGetSettings' ) ) {
			$line = linePostNotifyGetSettings();
			if ( ! is_array( $line ) ) {
				error_log( 'Stock Hot News: LINE settings were not an array; ignored.' );
			} else {
				if ( $apiKey === '' ) {
					$apiKey = trim( (string) ( $line['aiApiKey'] ?? '' ) );
				}
				if ( $baseUrl === '' || $baseUrl === 'https://api.openai.com/v1' ) {
					$lineBase = trim( (string) ( $line['aiBaseUrl'] ?? '' ) );
					if ( $lineBase !== '' ) {
						$baseUrl = $lineBase;
					}
				}
				if ( $model === '' || $model === 'gpt-4o-mini' ) {
					$lineModel = trim( (string) ( $line['aiModel'] ?? '' ) );
					if ( $lineModel !== '' ) {
						$model = $lineModel;
					}
				}
				if ( (string) ( $line['aiEnabled'] ?? '' ) === '1' && $apiKey === '' ) {
					$enabled = true;
				}
			}
		}

		if ( $apiKey === '' ) {
			$apiKey = 'local';
		}

		return array(
			'enabled' => $enabled && $baseUrl !== '' && $model !== '',
			'baseUrl' => $baseUrl,
			'apiKey'  => $apiKey,
			'model'   => $model,
		);
	}

	/**
	 * Chat completions URL for an OpenAI-compatible server.
	 */
	public static function chatEndpoint( string $baseUrl ): string {
		$baseUrl = untrailingslashit( $baseUrl );
		if ( preg_match( '#/chat/completions$#', $baseUrl ) ) {
			return $baseUrl;
		}
		if ( preg_match( '#/v1$#', $baseUrl ) ) {
			return $baseUrl . '/chat/completions';
		}
		return $baseUrl . '/v1/chat/completions';
	}

	/**
	 * Models list URL for an OpenAI-compatible server.
	 */
	public static function modelsEndpoint( string $baseUrl ): string {
		$baseUrl = untrailingslashit( $baseUrl );
		if ( preg_match( '#/models$#', $baseUrl ) ) {
			return $baseUrl;
		}
		if ( preg_match( '#/v1$#', $baseUrl ) ) {
			return $baseUrl . '/models';
		}
		return $baseUrl . '/v1/models';
	}
}
