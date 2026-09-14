<?php
/**
 * Settings screen.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings under Settings → 熱門股市新聞.
 */
class Admin {
	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var Runner
	 */
	private $runner;

	/**
	 * @var Ranker
	 */
	private $ranker;

	/**
	 * @var Hooks
	 */
	private $hooks;

	public function __construct( Options $options, Runner $runner, Ranker $ranker, Hooks $hooks ) {
		$this->options = $options;
		$this->runner  = $runner;
		$this->ranker  = $ranker;
		$this->hooks   = $hooks;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPage' ) );
		add_action( 'admin_post_stock_hot_news_save', array( $this, 'handleSave' ) );
		add_action( 'admin_post_stock_hot_news_run', array( $this, 'handleRun' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( STOCK_HOT_NEWS_FILE ), array( $this, 'actionLinks' ) );
	}

	/**
	 * @param array<int, string> $links Plugin links.
	 * @return array<int, string>
	 */
	public function actionLinks( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=stock-hot-news' ) ) . '">設定</a>';
		return $links;
	}

	public function addPage(): void {
		add_options_page(
			'熱門股市新聞',
			'熱門股市新聞',
			'manage_options',
			'stock-hot-news',
			array( $this, 'renderPage' )
		);
	}

	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限修改這些設定。' ) );
		}
		check_admin_referer( 'stock_hot_news_save' );

		$current = $this->options->all();
		$posted  = wp_unslash( $_POST );
		$apiKey  = isset( $posted['aiApiKey'] ) ? trim( (string) $posted['aiApiKey'] ) : '';
		if ( $apiKey === '' ) {
			$apiKey = (string) ( $current['aiApiKey'] ?? 'local' );
		}
		$codexApiKey = isset( $posted['codexApiKey'] ) ? trim( (string) $posted['codexApiKey'] ) : '';
		if ( $codexApiKey === '' ) {
			$codexApiKey = (string) ( $current['codexApiKey'] ?? '' );
		}

		$market = sanitize_key( (string) ( $posted['market'] ?? 'tw' ) );
		if ( ! in_array( $market, array( 'tw', 'us', 'all' ), true ) ) {
			$market = 'tw';
		}
		$postStatus = sanitize_key( (string) ( $posted['postStatus'] ?? 'draft' ) );
		if ( ! in_array( $postStatus, array( 'draft', 'pending', 'publish' ), true ) ) {
			$postStatus = 'draft';
		}

		$saved = $this->options->save(
			array(
				'enabled'         => empty( $posted['enabled'] ) ? '' : '1',
				'reuseLineAi'     => empty( $posted['reuseLineAi'] ) ? '' : '1',
				'aiEnabled'       => empty( $posted['aiEnabled'] ) ? '' : '1',
				'aiBaseUrl'       => esc_url_raw( (string) ( $posted['aiBaseUrl'] ?? '' ) ),
				'aiApiKey'        => $apiKey,
				'aiModel'         => sanitize_text_field( (string) ( $posted['aiModel'] ?? '' ) ),
				'market'          => $market,
				'topicLimit'      => max( 1, min( 10, (int) ( $posted['topicLimit'] ?? 5 ) ) ),
				'maxHeadlines'    => max( 10, min( 120, (int) ( $posted['maxHeadlines'] ?? 40 ) ) ),
				'fetchTimeout'    => max( 5, min( 30, (int) ( $posted['fetchTimeout'] ?? 8 ) ) ),
				'chatTimeout'     => max( 15, min( 90, (int) ( $posted['chatTimeout'] ?? 45 ) ) ),
				'requireAi'       => empty( $posted['requireAi'] ) ? '' : '1',
				'extraPrompt'     => sanitize_textarea_field( (string) ( $posted['extraPrompt'] ?? '' ) ),
				'createPost'      => empty( $posted['createPost'] ) ? '' : '1',
				'postStatus'      => $postStatus,
				'categorySlug'    => sanitize_title( (string) ( $posted['categorySlug'] ?? 'stock-views' ) ),
				'coverImageId'    => max( 0, (int) ( $posted['coverImageId'] ?? 0 ) ),
				'generateCover'   => empty( $posted['generateCover'] ) ? '' : '1',
				'unloadTextModel' => empty( $posted['unloadTextModel'] ) ? '' : '1',
				'codexBin'        => sanitize_text_field( (string) ( $posted['codexBin'] ?? '' ) ),
				'codexHome'       => sanitize_text_field( (string) ( $posted['codexHome'] ?? '' ) ),
				'codexUserHome'   => sanitize_text_field( (string) ( $posted['codexUserHome'] ?? '' ) ),
				'codexApiKey'     => $codexApiKey,
				'codexTimeout'    => max( 60, min( 600, (int) ( $posted['codexTimeout'] ?? 180 ) ) ),
				'coverSize'       => $this->sanitizeCoverSize( (string) ( $posted['coverSize'] ?? '1536x1024' ) ),
				'scheduleEnabled' => empty( $posted['scheduleEnabled'] ) ? '' : '1',
				'scheduleHour'    => max( 0, min( 23, (int) ( $posted['scheduleHour'] ?? 16 ) ) ),
				'scheduleMinute'  => max( 0, min( 59, (int) ( $posted['scheduleMinute'] ?? 30 ) ) ),
				'disclaimer'      => sanitize_text_field( (string) ( $posted['disclaimer'] ?? '' ) ),
			)
		);
		if ( $saved !== true ) {
			wp_die( esc_html( '儲存設定失敗，請再試一次。' ) );
		}

		$this->hooks->maybeSchedule();
		wp_safe_redirect( admin_url( 'options-general.php?page=stock-hot-news&saved=1' ) );
		exit;
	}

	private function sanitizeCoverSize( string $size ): string {
		$size = strtolower( trim( $size ) );
		if ( preg_match( '/^\d{3,4}x\d{3,4}$/', $size ) ) {
			return $size;
		}
		return '1536x1024';
	}

	public function handleRun(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限執行搜尋。' ) );
		}
		check_admin_referer( 'stock_hot_news_run' );

		$posted     = wp_unslash( $_POST );
		$createPost = ! empty( $posted['createPostNow'] );
		$queued     = $this->hooks->queueRun( $createPost );
		$query      = array( 'page' => 'stock-hot-news' );
		if ( is_wp_error( $queued ) ) {
			$query['run_error'] = '1';
			$this->options->patchState(
				array(
					'lastError' => $queued->get_error_message(),
				)
			);
		} else {
			$query['queued'] = '1';
		}

		nocache_headers();
		wp_safe_redirect( admin_url( 'options-general.php?' . http_build_query( $query ) ) );
		if ( ! is_wp_error( $queued ) ) {
			$this->hooks->continueAfterRedirect();
		}
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限查看這個頁面。' ) );
		}
		$settings  = $this->options->all();
		$ai        = $this->options->resolvedAi();
		$isBusy    = $this->hooks->isBusy();
		$state     = $this->options->state();
		$runStatus = (string) ( $state['runStatus'] ?? 'idle' );
		$probe     = null;
		if ( ! $isBusy ) {
			$probe = $this->ranker->probe();
		}
		$nextCron = wp_next_scheduled( STOCK_HOT_NEWS_CRON_HOOK );
		if ( $isBusy ) {
			echo '<meta http-equiv="refresh" content="4">';
			if ( $runStatus === 'queued' && function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
		?>
		<div class="wrap">
			<h1>熱門股市新聞</h1>
			<p>產出格式對齊 <code>/YYYY/MM/DD/stock-watch-YYYY-MM-DD/</code> 盤勢文：證交所收盤數字 + 新聞熱點 TOP 5（已確認／分析）+ 法人表 + 資料來源。建議先存草稿再發佈。</p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>
			<?php endif; ?>
			<?php if ( $runStatus === 'queued' ) : ?>
				<div class="notice notice-info"><p>已改在背景搜尋，這個頁面會自動刷新。瀏覽器不會再等到斷線。</p></div>
			<?php elseif ( $runStatus === 'running' ) : ?>
				<div class="notice notice-info"><p>正在抓證交所與新聞（模型沒就緒會立刻改用標題熱度，避免卡住其他服務）。</p></div>
			<?php elseif ( $runStatus === 'covering' ) : ?>
				<div class="notice notice-info"><p>文稿已寫入草稿，正在用 Codex CLI 繪製封面（會先卸載文字模型）。</p></div>
			<?php elseif ( $runStatus === 'done' && ( isset( $_GET['queued'] ) || isset( $_GET['ran'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>搜尋完成。<?php echo (int) ( $state['lastPostId'] ?? 0 ) > 0 ? esc_html( ' 已建立／更新草稿。' ) : ''; ?></p></div>
			<?php elseif ( isset( $_GET['ran'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>搜尋完成。<?php echo isset( $_GET['posted'] ) ? esc_html( ' 已建立／更新草稿。' ) : ''; ?></p></div>
			<?php endif; ?>
			<?php if ( ( $runStatus === 'error' || isset( $_GET['run_error'] ) ) && (string) ( $state['lastError'] ?? '' ) !== '' ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( (string) $state['lastError'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'stock_hot_news_save' ); ?>
				<input type="hidden" name="action" value="stock_hot_news_save">

				<h2 class="title">模型</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">啟用</th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( (string) $settings['enabled'] === '1' ); ?>>
								允許搜尋與排程
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">AI 來源</th>
						<td>
							<label>
								<input type="checkbox" name="aiEnabled" value="1" <?php checked( (string) $settings['aiEnabled'] === '1' ); ?>>
								用 OpenAI 相容 API 排序摘要
							</label>
							<br>
							<label>
								<input type="checkbox" name="reuseLineAi" value="1" <?php checked( (string) $settings['reuseLineAi'] === '1' ); ?>>
								若本外掛沒填金鑰，沿用「LINE 發文通知」的 AI 設定
							</label>
							<br>
							<label>
								<input type="checkbox" name="requireAi" value="1" <?php checked( (string) $settings['requireAi'] === '1' ); ?>>
								模型失敗時不要改用標題熱度排序
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiBaseUrl">API 網址</label></th>
						<td>
							<input class="regular-text" type="url" id="aiBaseUrl" name="aiBaseUrl" value="<?php echo esc_attr( (string) $settings['aiBaseUrl'] ); ?>">
							<p class="description">預設 <code>http://127.0.0.1:8080/v1</code>（本機 OpenAI-compatible API）</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiApiKey">API Key</label></th>
						<td>
							<input class="regular-text" type="password" id="aiApiKey" name="aiApiKey" value="" autocomplete="off" placeholder="<?php echo (string) $settings['aiApiKey'] !== '' ? '已儲存，留空則不更改' : ''; ?>">
							<p class="description">本地 llama.cpp 可填 <code>local</code>。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiModel">模型</label></th>
						<td><input class="regular-text" type="text" id="aiModel" name="aiModel" value="<?php echo esc_attr( (string) $settings['aiModel'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="extraPrompt">額外規則</label></th>
						<td><textarea class="large-text" rows="3" id="extraPrompt" name="extraPrompt"><?php echo esc_textarea( (string) $settings['extraPrompt'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="chatTimeout">模型逾時（秒）</label></th>
						<td>
							<input class="small-text" type="number" min="15" max="90" id="chatTimeout" name="chatTimeout" value="<?php echo esc_attr( (string) (int) $settings['chatTimeout'] ); ?>">
							<p class="description">模型沒回應就改用標題熱度。預設 45 秒，避免佔住 GPU。</p>
						</td>
					</tr>
				</table>

				<h2 class="title">新聞範圍</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="market">市場</label></th>
						<td>
							<select id="market" name="market">
								<option value="tw" <?php selected( (string) $settings['market'], 'tw' ); ?>>台股</option>
								<option value="us" <?php selected( (string) $settings['market'], 'us' ); ?>>美股</option>
								<option value="all" <?php selected( (string) $settings['market'], 'all' ); ?>>台股 + 美股</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="topicLimit">熱門主題數</label></th>
						<td>
							<input class="small-text" type="number" min="1" max="10" id="topicLimit" name="topicLimit" value="<?php echo esc_attr( (string) (int) $settings['topicLimit'] ); ?>">
							<p class="description">盤勢文「新聞熱點 TOP 5」建議填 5。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="maxHeadlines">送給模型的標題上限</label></th>
						<td><input class="small-text" type="number" min="10" max="120" id="maxHeadlines" name="maxHeadlines" value="<?php echo esc_attr( (string) (int) $settings['maxHeadlines'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="fetchTimeout">RSS 逾時（秒）</label></th>
						<td>
							<input class="small-text" type="number" min="5" max="30" id="fetchTimeout" name="fetchTimeout" value="<?php echo esc_attr( (string) (int) $settings['fetchTimeout'] ); ?>">
							<p class="description">每個 RSS 來源的上限，預設 8 秒。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="disclaimer">結尾聲明</label></th>
						<td><input class="large-text" type="text" id="disclaimer" name="disclaimer" value="<?php echo esc_attr( (string) $settings['disclaimer'] ); ?>"></td>
					</tr>
				</table>

				<h2 class="title">寫入文章與排程</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">自動建稿</th>
						<td>
							<label>
								<input type="checkbox" name="createPost" value="1" <?php checked( (string) $settings['createPost'] === '1' ); ?>>
								排程執行時建立或更新草稿
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="postStatus">文章狀態</label></th>
						<td>
							<select id="postStatus" name="postStatus">
								<option value="draft" <?php selected( (string) $settings['postStatus'], 'draft' ); ?>>草稿</option>
								<option value="pending" <?php selected( (string) $settings['postStatus'], 'pending' ); ?>>待審</option>
								<option value="publish" <?php selected( (string) $settings['postStatus'], 'publish' ); ?>>直接發佈</option>
							</select>
							<p class="description">建議先存草稿，確認後再發佈（發佈才會觸發 LINE 通知）。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="categorySlug">分類 slug</label></th>
						<td>
							<input class="regular-text" type="text" id="categorySlug" name="categorySlug" value="<?php echo esc_attr( (string) $settings['categorySlug'] ); ?>">
							<p class="description">預設 <code>stock-views</code>。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coverImageId">備用封面圖 ID</label></th>
						<td>
							<input class="small-text" type="number" min="0" id="coverImageId" name="coverImageId" value="<?php echo esc_attr( (string) (int) ( $settings['coverImageId'] ?? 0 ) ); ?>">
							<p class="description">Codex 繪圖失敗時沿用這個媒體庫附件。0 代表失敗就不插圖。</p>
						</td>
					</tr>
				</table>

				<h2 class="title">封面繪圖（Codex CLI，目前略過）</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">建稿後繪圖</th>
						<td>
							<label>
								<input type="checkbox" name="generateCover" value="1" <?php checked( (string) ( $settings['generateCover'] ?? '' ) === '1' ); ?>>
								從標題、摘要、分類組提示詞，用 <code>codex exec</code> + <code>$imagegen</code> 產生封面
							</label>
							<br>
							<label>
								<input type="checkbox" name="unloadTextModel" value="1" <?php checked( (string) ( $settings['unloadTextModel'] ?? '' ) === '1' ); ?>>
								繪圖前先 POST <code>/models/unload</code> 卸載本地文字模型
							</label>
							<p class="description">目前預設關閉。請先手動上傳封面；需要時再勾選啟用 Codex。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="codexBin">codex 路徑</label></th>
						<td>
							<input class="regular-text" type="text" id="codexBin" name="codexBin" value="<?php echo esc_attr( (string) ( $settings['codexBin'] ?? '' ) ); ?>" placeholder="/usr/local/bin/codex">
							<p class="description">留空則自動尋找 PATH、<code>~/.local/bin/codex</code>。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="codexUserHome">執行身分 HOME</label></th>
						<td>
							<input class="regular-text" type="text" id="codexUserHome" name="codexUserHome" value="<?php echo esc_attr( (string) ( $settings['codexUserHome'] ?? '' ) ); ?>" placeholder="/home/your-user">
							<p class="description">PHP 常不是登入 Codex 的那個使用者。請指到有 <code>~/.codex</code> 的家目錄。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="codexHome">CODEX_HOME</label></th>
						<td>
							<input class="regular-text" type="text" id="codexHome" name="codexHome" value="<?php echo esc_attr( (string) ( $settings['codexHome'] ?? '' ) ); ?>" placeholder="~/.codex">
							<p class="description">通常留空即可，會用 HOME 底下的 <code>.codex</code>。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="codexApiKey">OPENAI_API_KEY（選填）</label></th>
						<td>
							<input class="regular-text" type="password" id="codexApiKey" name="codexApiKey" value="" autocomplete="off" placeholder="<?php echo (string) ( $settings['codexApiKey'] ?? '' ) !== '' ? '已儲存，留空則不更改' : 'ChatGPT 登入可不填'; ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="coverSize">封面尺寸</label></th>
						<td>
							<input class="regular-text" type="text" id="coverSize" name="coverSize" value="<?php echo esc_attr( (string) ( $settings['coverSize'] ?? '1536x1024' ) ); ?>">
							<p class="description">例如 <code>1536x1024</code>（橫式 OG）。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="codexTimeout">繪圖逾時（秒）</label></th>
						<td>
							<input class="small-text" type="number" min="60" max="600" id="codexTimeout" name="codexTimeout" value="<?php echo esc_attr( (string) (int) ( $settings['codexTimeout'] ?? 180 ) ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">每日排程</th>
						<td>
							<label>
								<input type="checkbox" name="scheduleEnabled" value="1" <?php checked( (string) $settings['scheduleEnabled'] === '1' ); ?>>
								啟用 WP-Cron 每日搜尋
							</label>
							<br>
							<label for="scheduleHour">台北時間</label>
							<input class="small-text" type="number" min="0" max="23" id="scheduleHour" name="scheduleHour" value="<?php echo esc_attr( (string) (int) $settings['scheduleHour'] ); ?>">
							:
							<input class="small-text" type="number" min="0" max="59" id="scheduleMinute" name="scheduleMinute" value="<?php echo esc_attr( (string) (int) $settings['scheduleMinute'] ); ?>">
							<?php if ( $nextCron ) : ?>
								<p class="description">下次預計：<?php echo esc_html( wp_date( 'Y-m-d H:i', $nextCron ) ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( '儲存設定' ); ?>
			</form>

			<h2>立即搜尋</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'stock_hot_news_run' ); ?>
				<input type="hidden" name="action" value="stock_hot_news_run">
				<p>
					<?php submit_button( '只搜尋、不建稿', 'secondary', 'submit', false, $isBusy ? array( 'disabled' => 'disabled' ) : array() ); ?>
					<button type="submit" name="createPostNow" value="1" class="button button-primary" <?php disabled( $isBusy ); ?>>搜尋並建立／更新草稿</button>
				</p>
				<p class="description">按下去會立刻回到本頁，搜尋在背景跑。封面繪圖目前略過，建稿後請手動補圖，或沿用備用封面 ID。</p>
			</form>

			<h2>目前狀態</h2>
			<p><?php echo $ai['enabled'] ? esc_html( 'API 已設定，模型：' . $ai['model'] ) : esc_html( '尚未就緒：請填 API 網址與模型。' ); ?></p>
			<?php if ( $isBusy ) : ?>
				<p class="description">背景搜尋進行中，暫不探測模型，以免再開一次長連線。</p>
			<?php elseif ( is_wp_error( $probe ) ) : ?>
				<p class="description"><?php echo esc_html( $probe->get_error_message() ); ?></p>
			<?php elseif ( is_array( $probe ) ) : ?>
				<p class="description">
					伺服器狀態：<?php echo esc_html( (string) $probe['status'] ); ?>
					<?php echo ! empty( $probe['failed'] ) ? esc_html( '（載入失敗）' ) : ''; ?>
					　可用模型：<?php echo esc_html( implode( ', ', (array) $probe['available'] ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( (string) ( $state['lastRunAt'] ?? '' ) !== '' ) : ?>
				<h2>最近一次結果</h2>
				<p>
					時間：<?php echo esc_html( (string) $state['lastRunAt'] ); ?>
					　模型：<?php echo esc_html( (string) $state['lastModel'] ); ?>
					<?php if ( (int) ( $state['lastPostId'] ?? 0 ) > 0 ) : ?>
						　<a href="<?php echo esc_url( get_edit_post_link( (int) $state['lastPostId'] ) ); ?>">編輯草稿 #<?php echo esc_html( (string) (int) $state['lastPostId'] ); ?></a>
					<?php endif; ?>
					<?php if ( (int) ( $state['lastCoverId'] ?? 0 ) > 0 ) : ?>
						　封面附件 #<?php echo esc_html( (string) (int) $state['lastCoverId'] ); ?>
					<?php endif; ?>
				</p>
				<?php if ( (string) ( $state['lastImagePrompt'] ?? '' ) !== '' ) : ?>
					<p class="description">圖片提示詞：<?php echo esc_html( (string) $state['lastImagePrompt'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $state['lastWarnings'] ) && is_array( $state['lastWarnings'] ) ) : ?>
					<ul>
						<?php foreach ( $state['lastWarnings'] as $warning ) : ?>
							<li><?php echo esc_html( (string) $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( (string) ( $state['lastHtml'] ?? '' ) !== '' ) : ?>
					<div class="card" style="max-width:920px;padding:16px 20px">
						<?php echo wp_kses_post( (string) $state['lastHtml'] ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
