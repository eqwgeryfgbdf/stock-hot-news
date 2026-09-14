<?php
/**
 * Build a cover prompt, free VRAM, then draw with Codex CLI.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Steps 3–7 of the publish pipeline: prompt, unload, Codex image, upload, featured image.
 */
class CoverGenerator {
	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var Publisher
	 */
	private $publisher;

	public function __construct( Options $options, Publisher $publisher ) {
		$this->options   = $options;
		$this->publisher = $publisher;
	}

	/**
	 * Generate a Codex cover for a saved draft and attach it.
	 *
	 * @param array<string, mixed> $report Ranked report.
	 * @return array{coverImageId:int,imagePrompt:string,outputPath:string,warnings:array<int,string>}
	 */
	public function attachCover( array $report, int $postId ): array {
		$warnings = array();
		$prompt   = $this->buildImagePrompt( $report );
		if ( (string) $this->options->get( 'unloadTextModel', '1' ) === '1' ) {
			$unloaded = $this->unloadTextModel();
			if ( is_wp_error( $unloaded ) ) {
				$warnings[] = $unloaded->get_error_message();
			}
		}

		$generated = $this->generateWithCodex( $prompt, (string) ( $report['date'] ?? '' ) );
		if ( is_wp_error( $generated ) ) {
			$warnings[] = $generated->get_error_message();
			return array(
				'coverImageId' => (int) ( $report['coverImageId'] ?? 0 ),
				'imagePrompt'  => $prompt,
				'outputPath'   => '',
				'warnings'     => $warnings,
			);
		}

		$uploaded = $this->uploadToMediaLibrary( $generated, $postId, $report );
		if ( is_wp_error( $uploaded ) ) {
			$warnings[] = $uploaded->get_error_message();
			return array(
				'coverImageId' => (int) ( $report['coverImageId'] ?? 0 ),
				'imagePrompt'  => $prompt,
				'outputPath'   => $generated,
				'warnings'     => $warnings,
			);
		}

		return array(
			'coverImageId' => $uploaded,
			'imagePrompt'  => $prompt,
			'outputPath'   => $generated,
			'warnings'     => $warnings,
		);
	}

	/**
	 * @param array<string, mixed> $report
	 */
	public function buildImagePrompt( array $report ): string {
		$title      = trim( (string) ( $report['suggestedTitle'] ?? '' ) );
		$excerpt    = trim( (string) ( $report['excerpt'] ?? $report['lede'] ?? '' ) );
		$categories = array_values( array_filter( array_map( 'strval', (array) ( $report['categories'] ?? array() ) ) ) );
		$date       = trim( (string) ( $report['date'] ?? '' ) );
		$move       = trim( (string) ( $report['market']['indexMove'] ?? '' ) );
		$close      = trim( (string) ( $report['market']['indexClose'] ?? '' ) );
		$mood       = trim( (string) ( $report['marketMood'] ?? '' ) );
		$size       = $this->coverSize();

		if ( $title === '' ) {
			$title = $date !== '' ? '臺股盤勢（' . $date . '）' : '臺股盤勢';
		}
		$categoryLabel = $categories === array() ? '台股、盤後新聞' : implode( '、', $categories );

		$lines   = array();
		$lines[] = 'Editorial infographic cover for a Traditional Chinese Taiwan stock-market blog.';
		$lines[] = 'Exact output size: ' . $size . ' landscape PNG.';
		$lines[] = 'Clean modern data-visual design, not a screenshot, not a photo collage.';
		$lines[] = 'Taiwan market color convention: red means up, green means down.';
		$lines[] = 'No real-company logos, no watermarks, no QR codes, no unreadable gibberish text.';
		$lines[] = 'At most one short Traditional Chinese headline; keep other labels minimal.';
		$lines[] = 'Title: ' . $title;
		$lines[] = 'Excerpt: ' . $excerpt;
		$lines[] = 'Category: ' . $categoryLabel;
		if ( $date !== '' ) {
			$lines[] = 'Date: ' . $date;
		}
		if ( $close !== '' ) {
			$lines[] = 'Index close: ' . $close . ( $move !== '' ? ' (' . $move . ')' : '' );
		}
		if ( $mood !== '' ) {
			$lines[] = 'Market mood: ' . $mood;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Temporarily unload the local llama.cpp text model so VRAM is free.
	 *
	 * @return true|\WP_Error
	 */
	public function unloadTextModel() {
		$ai = $this->options->resolvedAi();
		$modelName = trim( (string) ( $ai['model'] ?? '' ) );
		$origin    = $this->serverOrigin( (string) ( $ai['baseUrl'] ?? '' ) );
		if ( $origin === '' || $modelName === '' ) {
			return new \WP_Error( 'unload_skip', '文字模型網址或名稱是空的，略過卸載。' );
		}

		$endpoints = array(
			$origin . '/models/unload',
			$origin . '/v1/models/unload',
		);
		$lastError = '沒有得到卸載回應。';
		foreach ( $endpoints as $endpoint ) {
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'        => 'application/json',
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . (string) $ai['apiKey'],
					),
					'body'    => wp_json_encode( array( 'model' => $modelName ) ),
				)
			);
			if ( is_wp_error( $response ) ) {
				$lastError = '無法連線卸載文字模型：' . $response->get_error_message();
				continue;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );
			if ( $status === 404 ) {
				$lastError = '卸載端點不存在：' . $endpoint;
				continue;
			}
			// Already unloaded / not running is the desired VRAM state.
			if ( $status === 400 && preg_match( '/not running|already|unloaded/i', $raw ) ) {
				delete_transient( 'stock_hot_news_probe' );
				return true;
			}
			if ( $status < 200 || $status >= 300 ) {
				$lastError = '卸載文字模型 HTTP ' . $status . '：' . wp_strip_all_tags( $raw );
				continue;
			}
			delete_transient( 'stock_hot_news_probe' );
			return true;
		}

		return new \WP_Error( 'unload_failed', $lastError );
	}

	/**
	 * @return string|\WP_Error Absolute PNG path.
	 */
	private function generateWithCodex( string $prompt, string $date ) {
		$codexBin = $this->resolveCodexBin();
		if ( $codexBin === '' ) {
			return new \WP_Error( 'codex_missing', '找不到 Codex CLI（codex）。請在設定填寫路徑，或把 codex 加進 PATH。' );
		}

		$workDir = $this->prepareWorkDir( $date );
		if ( is_wp_error( $workDir ) ) {
			return $workDir;
		}

		$outPath = $workDir . '/cover.png';
		if ( file_exists( $outPath ) ) {
			$outPath = $workDir . '/cover-' . wp_generate_uuid4() . '.png';
		}

		$brief  = $this->codexBrief( $prompt, $outPath, $this->coverSize() );
		$timeout = max( 60, min( 600, (int) $this->options->get( 'codexTimeout', 180 ) ) );
		$command = array(
			$codexBin,
			'exec',
			'--skip-git-repo-check',
			'--sandbox',
			'workspace-write',
			'--ephemeral',
			'-C',
			$workDir,
			$brief,
		);
		$timeoutBin = $this->resolveTimeoutBin();
		if ( $timeoutBin !== '' ) {
			array_unshift( $command, $timeoutBin, (string) $timeout );
		}

		$home = $this->resolveUserHome();
		$env  = $this->processEnv( $home );
		$proc = proc_open(
			$command,
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$workDir,
			$env
		);
		if ( ! is_resource( $proc ) ) {
			return new \WP_Error( 'codex_spawn', '無法啟動 Codex CLI：' . $codexBin );
		}

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		if ( $stdout === false ) {
			$stdout = '';
		}
		if ( $stderr === false ) {
			$stderr = '';
		}
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exitCode = proc_close( $proc );

		$found = $this->findGeneratedImage( $outPath, $workDir, $home, (string) $stdout );
		if ( is_string( $found ) && $found !== '' ) {
			return $found;
		}

		$detail = trim( (string) $stderr . "\n" . (string) $stdout );
		$detail = wp_strip_all_tags( $detail );
		if ( function_exists( 'mb_substr' ) ) {
			$detail = mb_substr( $detail, -1200 );
		} else {
			$detail = substr( $detail, -1200 );
		}
		if ( $exitCode === 124 ) {
			return new \WP_Error( 'codex_timeout', 'Codex CLI 繪圖逾時（' . $timeout . ' 秒）。' . ( $detail !== '' ? ' ' . $detail : '' ) );
		}
		return new \WP_Error(
			'codex_no_image',
			'Codex CLI 沒有產出封面圖（exit ' . (string) $exitCode . '）。' . ( $detail !== '' ? ' ' . $detail : '' )
		);
	}

	/**
	 * @param array<string, mixed> $report
	 * @return int|\WP_Error Attachment ID.
	 */
	private function uploadToMediaLibrary( string $path, int $postId, array $report ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'cover_unreadable', '封面檔讀不到：' . $path );
		}
		$imageInfo = getimagesize( $path );
		if ( $imageInfo === false ) {
			return new \WP_Error( 'cover_not_image', 'Codex 產出的檔案不是有效圖片：' . $path );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$date     = (string) ( $report['date'] ?? gmdate( 'Y-m-d' ) );
		$filename = 'stock-watch-' . sanitize_file_name( $date ) . '-cover.png';
		$tmpPath  = wp_tempnam( $filename );
		if ( ! is_string( $tmpPath ) || $tmpPath === '' ) {
			return new \WP_Error( 'cover_tmp', '無法建立上傳暫存檔。' );
		}
		if ( ! copy( $path, $tmpPath ) ) {
			wp_delete_file( $tmpPath );
			return new \WP_Error( 'cover_copy', '無法複製封面到上傳暫存檔。' );
		}

		$fileArray = array(
			'name'     => $filename,
			'tmp_name' => $tmpPath,
			'type'     => (string) ( $imageInfo['mime'] ?? 'image/png' ),
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $tmpPath ),
		);
		$title = trim( (string) ( $report['suggestedTitle'] ?? '' ) );
		$desc  = $title !== '' ? $title . ' 封面圖' : '台股盤勢封面圖';
		$attachmentId = media_handle_sideload( $fileArray, $postId, $desc );
		if ( is_wp_error( $attachmentId ) ) {
			if ( file_exists( $tmpPath ) ) {
				wp_delete_file( $tmpPath );
			}
			return new \WP_Error( 'cover_upload', '上傳封面到媒體庫失敗：' . $attachmentId->get_error_message() );
		}

		$altSaved = update_post_meta( (int) $attachmentId, '_wp_attachment_image_alt', $desc );
		if ( false === $altSaved ) {
			error_log( 'Stock Hot News: failed to save cover alt text for attachment ' . (int) $attachmentId );
		}

		$applied = $this->publisher->applyCover( $postId, (int) $attachmentId );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return (int) $attachmentId;
	}

	private function coverSize(): string {
		$size = trim( (string) $this->options->get( 'coverSize', '1536x1024' ) );
		if ( ! preg_match( '/^\d{3,4}x\d{3,4}$/', $size ) ) {
			return '1536x1024';
		}
		return $size;
	}

	private function codexBrief( string $prompt, string $outPath, string $size ): string {
		return implode(
			"\n",
			array(
				'$imagegen Generate one landscape blog cover image.',
				'Output size: ' . $size . ' PNG.',
				'Save the final PNG to this exact path, overwriting if needed:',
				$outPath,
				'Print ONLY the absolute path of the saved PNG on the last line.',
				'Do not write other image files.',
				'',
				'Image brief:',
				$prompt,
			)
		);
	}

	/**
	 * @return string|\WP_Error
	 */
	private function prepareWorkDir( string $date ) {
		$date = preg_replace( '/[^0-9\-]/', '', $date );
		if ( ! is_string( $date ) || $date === '' ) {
			$date = gmdate( 'Y-m-d' );
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'upload_dir', '無法取得上傳目錄：' . (string) $upload['error'] );
		}
		$base = trailingslashit( (string) $upload['basedir'] ) . 'stock-hot-news/' . $date;
		if ( ! wp_mkdir_p( $base ) ) {
			return new \WP_Error( 'cover_dir', '無法建立封面工作目錄：' . $base );
		}
		return $base;
	}

	private function resolveCodexBin(): string {
		$configured = trim( (string) $this->options->get( 'codexBin', '' ) );
		if ( $configured !== '' && is_executable( $configured ) ) {
			return $configured;
		}
		$home = $this->resolveUserHome();
		$candidates = array(
			$configured,
			'/usr/local/bin/codex',
			'/usr/bin/codex',
			$home . '/.local/bin/codex',
			$home . '/.npm-global/bin/codex',
			$home . '/bin/codex',
		);
		$path = (string) getenv( 'PATH' );
		if ( $path !== '' ) {
			foreach ( explode( PATH_SEPARATOR, $path ) as $dir ) {
				$candidates[] = rtrim( $dir, '/\\' ) . '/codex';
			}
		}
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && $candidate !== '' && is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	private function resolveTimeoutBin(): string {
		foreach ( array( '/usr/bin/timeout', '/bin/timeout' ) as $bin ) {
			if ( is_executable( $bin ) ) {
				return $bin;
			}
		}
		return '';
	}

	private function resolveUserHome(): string {
		$configured = trim( (string) $this->options->get( 'codexUserHome', '' ) );
		if ( $configured !== '' && is_dir( $configured ) ) {
			return untrailingslashit( $configured );
		}
		$envHome = (string) getenv( 'HOME' );
		if ( $envHome !== '' && is_dir( $envHome . '/.codex' ) ) {
			return untrailingslashit( $envHome );
		}
		if ( $envHome !== '' ) {
			return untrailingslashit( $envHome );
		}
		return '';
	}

	/**
	 * @return array<string, string>
	 */
	private function processEnv( string $home ): array {
		$env = array();
		foreach ( array( 'PATH', 'LANG', 'LC_ALL', 'TERM', 'USER', 'LOGNAME', 'SHELL', 'OPENAI_API_KEY' ) as $key ) {
			$value = getenv( $key );
			if ( is_string( $value ) && $value !== '' ) {
				$env[ $key ] = $value;
			}
		}
		$env['HOME']       = $home;
		$env['CODEX_HOME'] = $home . '/.codex';
		$configuredHome    = trim( (string) $this->options->get( 'codexHome', '' ) );
		if ( $configuredHome !== '' ) {
			$env['CODEX_HOME'] = untrailingslashit( $configuredHome );
		}
		$apiKey = trim( (string) $this->options->get( 'codexApiKey', '' ) );
		if ( $apiKey !== '' ) {
			$env['OPENAI_API_KEY'] = $apiKey;
		}
		$pathExtra = '/usr/local/bin:/usr/bin:/bin:' . $home . '/.local/bin';
		$env['PATH'] = isset( $env['PATH'] ) ? $pathExtra . PATH_SEPARATOR . $env['PATH'] : $pathExtra;
		return $env;
	}

	private function findGeneratedImage( string $expected, string $workDir, string $home, string $stdout ): string {
		if ( $this->isUsableImage( $expected ) ) {
			return $expected;
		}
		$lines = preg_split( '/\R/', $stdout );
		if ( is_array( $lines ) ) {
			for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
				$line = trim( (string) $lines[ $i ] );
				if ( $line !== '' && $this->isUsableImage( $line ) ) {
					return $line;
				}
			}
		}
		$newest = $this->newestImageIn( $workDir );
		if ( $newest !== '' ) {
			return $newest;
		}
		$codexHome = trim( (string) $this->options->get( 'codexHome', '' ) );
		if ( $codexHome === '' ) {
			$codexHome = $home . '/.codex';
		}
		return $this->newestImageIn( untrailingslashit( $codexHome ) . '/generated_images' );
	}

	private function newestImageIn( string $dir ): string {
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return '';
		}
		$matches = array();
		foreach ( array( 'png', 'jpg', 'jpeg', 'webp' ) as $ext ) {
			$found = glob( $dir . '/*.' . $ext );
			if ( is_array( $found ) ) {
				$matches = array_merge( $matches, $found );
			}
		}
		$newest     = '';
		$newestTime = 0;
		foreach ( $matches as $file ) {
			if ( ! $this->isUsableImage( $file ) ) {
				continue;
			}
			$mtime = (int) filemtime( $file );
			if ( $mtime >= $newestTime ) {
				$newestTime = $mtime;
				$newest     = $file;
			}
		}
		return $newest;
	}

	private function isUsableImage( string $path ): bool {
		if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		if ( filesize( $path ) < 1024 ) {
			return false;
		}
		return getimagesize( $path ) !== false;
	}

	private function serverOrigin( string $baseUrl ): string {
		$parsed = wp_parse_url( $baseUrl );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return '';
		}
		$port = isset( $parsed['port'] ) ? ':' . (string) $parsed['port'] : '';
		return $parsed['scheme'] . '://' . $parsed['host'] . $port;
	}
}
