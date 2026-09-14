<?php
/**
 * Rank collected headlines with a local OpenAI-compatible model.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls /v1/chat/completions and falls back to headline-frequency ranking.
 */
class Ranker {
	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $marketData
	 * @return array<string, mixed>
	 */
	public function rank( array $items, string $todayLabel, string $market, int $topicLimit, array $marketData = array() ): array {
		$ai = $this->options->resolvedAi();
		if ( ! $ai['enabled'] ) {
			$report = $this->fallbackReport( $items, $todayLabel, $market, $topicLimit, $marketData, 'AI 未啟用或設定不完整，改以證交所數字與標題熱度撰稿。' );
			$report['aiWarning'] = '尚未就緒：請在設定頁填入 API 網址與模型名稱。';
			return $report;
		}

		try {
			$prompts = $this->buildPrompt( $todayLabel, $market, $items, $topicLimit, $marketData );
			if ( ! $this->modelLooksReady() ) {
				throw new \RuntimeException( '模型尚未就緒或無回應，改以證交所數字撰稿，避免長時間等待。' );
			}
			$raw    = $this->chatJson( $ai, $prompts['system'], $prompts['user'] );
			$report = $this->parseReport( $raw, $items, $todayLabel, $market, $ai['model'], $topicLimit, $marketData );
			$report['aiWarning'] = '';
			return $report;
		} catch ( \RuntimeException $error ) {
			if ( (string) $this->options->get( 'requireAi', '' ) === '1' ) {
				throw $error;
			}
			error_log( 'Stock Hot News: AI ranking failed, using fallback. ' . $error->getMessage() );
			$report = $this->fallbackReport( $items, $todayLabel, $market, $topicLimit, $marketData, '本地模型不可用，改以證交所數字與標題熱度撰稿。' );
			$report['aiWarning'] = $error->getMessage();
			return $report;
		}
	}

	/**
	 * Probe /v1/models so the settings screen can show load status.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function probe( bool $useCache = true ) {
		$cacheKey = 'stock_hot_news_probe';
		if ( $useCache ) {
			$cached = get_transient( $cacheKey );
			if ( is_array( $cached ) && array_key_exists( 'ok', $cached ) ) {
				if ( $cached['ok'] === true && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
					return $cached['data'];
				}
				if ( $cached['ok'] === false ) {
					return new \WP_Error(
						(string) ( $cached['code'] ?? 'ai_http' ),
						(string) ( $cached['message'] ?? '無法連線到模型列表。' )
					);
				}
			}
		}

		$ai = $this->options->resolvedAi();
		if ( $ai['baseUrl'] === '' || $ai['model'] === '' ) {
			return new \WP_Error( 'ai_incomplete', 'API 網址或模型名稱是空的。' );
		}
		$endpoint = Options::modelsEndpoint( $ai['baseUrl'] );
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 4,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $ai['apiKey'],
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->rememberProbe( $cacheKey, new \WP_Error( 'ai_http', '無法連線到模型列表：' . $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->rememberProbe( $cacheKey, new \WP_Error( 'ai_api', '模型列表 HTTP ' . $status . '：' . wp_strip_all_tags( $raw ) ) );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return $this->rememberProbe( $cacheKey, new \WP_Error( 'ai_parse', '模型列表不是有效 JSON。' ) );
		}
		$ids     = array();
		$matched = null;
		foreach ( $decoded['data'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$modelId = (string) ( $row['id'] ?? '' );
			if ( $modelId !== '' ) {
				$ids[] = $modelId;
			}
			if ( $modelId === $ai['model'] ) {
				$matched = $row;
			}
		}
		$statusValue = 'missing';
		$failed      = false;
		if ( is_array( $matched ) ) {
			$block       = is_array( $matched['status'] ?? null ) ? $matched['status'] : array();
			$statusValue = (string) ( $block['value'] ?? 'unknown' );
			$failed      = ! empty( $block['failed'] );
		}
		return $this->rememberProbe(
			$cacheKey,
			array(
				'model'     => $ai['model'],
				'available' => $ids,
				'status'    => $statusValue,
				'failed'    => $failed,
				'endpoint'  => $endpoint,
			)
		);
	}

	/**
	 * Skip chat when llama.cpp is loading, failed, or unloaded.
	 */
	private function modelLooksReady(): bool {
		$probe = $this->probe( false );
		if ( is_wp_error( $probe ) ) {
			return false;
		}
		if ( ! empty( $probe['failed'] ) ) {
			return false;
		}
		$status = strtolower( (string) ( $probe['status'] ?? '' ) );
		if ( in_array( $status, array( 'loading', 'failed', 'unloaded', 'missing' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result Probe payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function rememberProbe( string $cacheKey, $result ) {
		if ( is_wp_error( $result ) ) {
			set_transient(
				$cacheKey,
				array(
					'ok'      => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				20
			);
			return $result;
		}
		set_transient(
			$cacheKey,
			array(
				'ok'   => true,
				'data' => $result,
			),
			45
		);
		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $marketData
	 * @return array{system:string,user:string}
	 */
	private function buildPrompt( string $todayLabel, string $market, array $items, int $topicLimit, array $marketData ): array {
		unset( $market );
		$lines = array();
		foreach ( $items as $item ) {
			$when        = (string) ( $item['publishedAt'] ?? '' ) !== '' ? (string) $item['publishedAt'] : '時間未知';
			$clusterNote = '重複' . (int) ( $item['clusterCount'] ?? 1 ) . '則';
			$lines[]     = sprintf(
				"[%d] %s | %s | %s | %s\n%s",
				(int) $item['itemId'],
				$when,
				(string) $item['source'],
				$clusterNote,
				(string) $item['title'],
				(string) $item['url']
			);
		}

		$facts  = ( new MarketData() )->toPromptText( $marketData );
		$system = '你是台灣台股盤後編輯。使用台灣繁體中文，只輸出 JSON。'
			. '「已確認」只能寫證交所數字或新聞清單裡出現的事實，不可捏造價格、點數、法人金額或連結。'
			. '「分析」才可以寫解讀，且不可把任何單一國際新聞寫成當日漲跌的唯一原因。'
			. '這不是投資建議。';
		$extra  = trim( (string) $this->options->get( 'extraPrompt', '' ) );
		if ( $extra !== '' ) {
			$system .= "\n\n額外規則：\n" . $extra;
		}

		$user = "日期：{$todayLabel}\n\n【證交所已確認數字】\n{$facts}\n\n"
			. "請寫一篇可直接刊登的台股盤後稿，選 {$topicLimit} 則熱點（優先權值股、廣度、法人、題材分歧）。\n\n"
			. "輸出 JSON，鍵名必須是：\n"
			. "suggestedTitle, excerpt, categories, tags, lede, overview, marketMood, hotTopics, institutionalSummary, institutionalNote, background, outlook, conclusion, sources, topHeadlines\n\n"
			. "規則：\n"
			. "- suggestedTitle 格式如「9月2日臺股盤勢：回吐 784 點，台積電與台達電重挫、光聖逆勢漲停」\n"
			. "- excerpt 80–140 字，含收盤點數與 2–3 檔個股\n"
			. "- categories 預設 [\"台股\",\"盤後新聞\"]\n"
			. "- tags 含台股、台股盤後，以及文中個股\n"
			. "- lede 是 header 一段話，含日期、加權收盤、漲跌、權值股與逆勢股\n"
			. "- overview 是盤勢段，必須使用證交所點數與漲跌家數\n"
			. "- hotTopics 每項含 rank, topic, tone(up|down|mixed), confirmed, analysis, tickers, relatedIds\n"
			. "- confirmed 只寫已確認事實；analysis 寫 1–2 句解讀\n"
			. "- relatedIds 必須是下方新聞 id\n"
			. "- institutionalSummary 如「合計賣超 1,152 億」；沒資料就寫「官方資料待更新」\n"
			. "- outlook 是 3 句字串陣列\n"
			. "- sources 是 [{label,url}]，至少含證交所，其餘只能用下方新聞真實網址\n"
			. "- 不要 markdown\n\n"
			. "新聞清單：\n"
			. implode( "\n\n", $lines );

		return array(
			'system' => $system,
			'user'   => $user,
		);
	}

	/**
	 * @param array{enabled:bool,baseUrl:string,apiKey:string,model:string} $ai
	 * @return array<string, mixed>
	 */
	private function chatJson( array $ai, string $systemPrompt, string $userPrompt ): array {
		$endpoint = Options::chatEndpoint( $ai['baseUrl'] );
		$timeout  = max( 15, min( 60, (int) $this->options->get( 'chatTimeout', 45 ) ) );
		$payload  = array(
			'model'                 => $ai['model'],
			'temperature'           => 0.2,
			'max_tokens'            => 4000,
			'chat_template_kwargs'  => array( 'enable_thinking' => false ),
			'messages'              => array(
				array(
					'role'    => 'system',
					'content' => $systemPrompt,
				),
				array(
					'role'    => 'user',
					'content' => $userPrompt,
				),
			),
		);

		$withFormat = $payload;
		$withFormat['response_format'] = array( 'type' => 'json_object' );
		$decoded = $this->postChat( $endpoint, $ai['apiKey'], $withFormat, $timeout );
		if ( is_wp_error( $decoded ) ) {
			$message = $decoded->get_error_message();
			if ( str_contains( strtolower( $message ), 'response_format' ) || str_contains( $message, 'HTTP 400' ) ) {
				$decoded = $this->postChat( $endpoint, $ai['apiKey'], $payload, $timeout );
			}
		}
		if ( is_wp_error( $decoded ) ) {
			throw new \RuntimeException( $decoded->get_error_message() );
		}
		$text = $this->extractMessageText( $decoded );
		return $this->extractJsonObject( $text );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	private function postChat( string $endpoint, string $apiKey, array $payload, int $timeout ) {
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || $encoded === '' ) {
			return new \WP_Error( 'ai_encode', '無法編碼 AI 請求內容。' );
		}
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $apiKey,
				),
				'body'    => $encoded,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'ai_http', '無法連線到 AI API：' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $raw, true );
			$detail  = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? $raw ) : $raw;
			return new \WP_Error( 'ai_api', 'AI API 回傳 HTTP ' . $status . '：' . wp_strip_all_tags( $detail ) );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'ai_parse', 'AI API 回應不是有效 JSON。' );
		}
		return $decoded;
	}

	/**
	 * @param array<string, mixed> $chatPayload
	 */
	private function extractMessageText( array $chatPayload ): string {
		$choices = $chatPayload['choices'] ?? null;
		if ( ! is_array( $choices ) || $choices === array() ) {
			throw new \RuntimeException( 'Chat API 沒有回傳 choices。' );
		}
		$message = is_array( $choices[0] ) ? ( $choices[0]['message'] ?? null ) : null;
		if ( ! is_array( $message ) ) {
			throw new \RuntimeException( 'Chat API 回傳的 choice 沒有 message。' );
		}
		$content = $message['content'] ?? '';
		if ( is_array( $content ) ) {
			$parts = array();
			foreach ( $content as $part ) {
				if ( is_array( $part ) && in_array( (string) ( $part['type'] ?? '' ), array( 'text', 'output_text' ), true ) ) {
					$parts[] = (string) ( $part['text'] ?? '' );
				} elseif ( is_string( $part ) ) {
					$parts[] = $part;
				}
			}
			$content = implode( '', $parts );
		}
		if ( ! is_string( $content ) || trim( $content ) === '' ) {
			throw new \RuntimeException( 'Chat API 回傳的內容是空的。' );
		}
		return $content;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function extractJsonObject( string $text ): array {
		$cleaned = preg_replace( '/<think>.*?<\/think>/is', '', $text ) ?? $text;
		$cleaned = trim( $cleaned );
		if ( preg_match( '/```(?:json)?\s*(.*?)\s*```/is', $cleaned, $fenced ) ) {
			$cleaned = trim( $fenced[1] );
		}
		$start = strpos( $cleaned, '{' );
		$end   = strrpos( $cleaned, '}' );
		if ( $start === false || $end === false || $end <= $start ) {
			$snippet = function_exists( 'mb_substr' ) ? mb_substr( $cleaned, 0, 400 ) : substr( $cleaned, 0, 400 );
			throw new \RuntimeException( '模型沒有回傳 JSON 物件。原文：' . $snippet );
		}
		$blob    = substr( $cleaned, $start, $end - $start + 1 );
		$decoded = json_decode( $blob, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( '模型 JSON 無法解析。' );
		}
		return $decoded;
	}

	/**
	 * @param array<string, mixed>             $raw
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $marketData
	 * @return array<string, mixed>
	 */
	private function parseReport( array $raw, array $items, string $todayLabel, string $market, string $modelName, int $topicLimit, array $marketData ): array {
		$validIds = array();
		$byId     = array();
		foreach ( $items as $item ) {
			$id              = (int) $item['itemId'];
			$validIds[ $id ] = true;
			$byId[ $id ]     = $item;
		}
		$topicsRaw = $raw['hotTopics'] ?? null;
		if ( ! is_array( $topicsRaw ) || $topicsRaw === array() ) {
			throw new \RuntimeException( '模型 JSON 缺少非空的 hotTopics。' );
		}

		$hotTopics = array();
		$index     = 1;
		foreach ( $topicsRaw as $row ) {
			if ( count( $hotTopics ) >= $topicLimit || ! is_array( $row ) ) {
				continue;
			}
			$topic = trim( (string) ( $row['topic'] ?? '' ) );
			if ( $topic === '' ) {
				continue;
			}
			$relatedIds = $this->asIntList( $row['relatedIds'] ?? array(), $validIds );
			$confirm    = trim( (string) ( $row['confirmed'] ?? $row['summary'] ?? '' ) );
			$analysis   = trim( (string) ( $row['analysis'] ?? $row['whyHot'] ?? '' ) );
			$tone       = (string) ( $row['tone'] ?? 'mixed' );
			if ( ! in_array( $tone, array( 'up', 'down', 'mixed' ), true ) ) {
				$tone = 'mixed';
			}
			$hotTopics[] = array(
				'rank'       => $index,
				'topic'      => $topic,
				'tone'       => $tone,
				'confirmed'  => $confirm !== '' ? $confirm : ( $relatedIds !== array() ? (string) $byId[ $relatedIds[0] ]['title'] : '待補事實。' ),
				'analysis'   => $analysis !== '' ? $analysis : '短線需觀察權值股與題材能否延續。',
				'tickers'    => $this->asStringList( $row['tickers'] ?? array() ),
				'relatedIds' => $relatedIds,
			);
			++$index;
		}
		if ( $hotTopics === array() ) {
			throw new \RuntimeException( '模型沒有產出可用的熱點主題。' );
		}

		$topHeadlines = $this->asIntList( $raw['topHeadlines'] ?? array(), $validIds );
		$sources      = $this->parseSources( $raw['sources'] ?? array(), $items, $marketData );
		$outlook      = $this->asStringList( $raw['outlook'] ?? array() );

		return $this->reportShape(
			$todayLabel,
			$market,
			$raw,
			$hotTopics,
			$topHeadlines,
			$items,
			$modelName,
			$marketData,
			$sources,
			$outlook
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $marketData
	 * @return array<string, mixed>
	 */
	private function fallbackReport( array $items, string $todayLabel, string $market, int $topicLimit, array $marketData, string $mood ): array {
		$collector = new Collector();
		usort(
			$items,
			function ( $left, $right ) use ( $collector ) {
				return $collector->hotScore( $right ) <=> $collector->hotScore( $left );
			}
		);
		$hotTopics = array();
		$used      = array();
		$rank      = 1;
		foreach ( $items as $item ) {
			if ( $rank > $topicLimit ) {
				break;
			}
			$topicTitle  = preg_replace( '/\s+[-–—]\s+[^-–—]{1,40}\s*$/u', '', (string) $item['title'] ) ?? (string) $item['title'];
			$hotTopics[] = array(
				'rank'       => $rank,
				'topic'      => trim( $topicTitle ),
				'tone'       => 'mixed',
				'confirmed'  => (string) ( $item['title'] ?? '' ),
				'analysis'   => '此則為模型離線時依標題熱度列入，需對照證交所數字解讀。',
				'tickers'    => array(),
				'relatedIds' => array( (int) $item['itemId'] ),
			);
			$used[] = (int) $item['itemId'];
			++$rank;
		}

		$raw = array(
			'marketMood'            => $mood,
			'suggestedTitle'        => '',
			'excerpt'               => '',
			'lede'                  => '',
			'overview'              => '',
			'institutionalSummary'  => '',
			'institutionalNote'     => '',
			'background'            => '',
			'conclusion'            => '',
			'categories'            => array( '台股', '盤後新聞' ),
			'tags'                  => array( '台股', '台股盤後' ),
			'topHeadlines'          => $used,
		);
		return $this->reportShape( $todayLabel, $market, $raw, $hotTopics, $used, $items, 'fallback-frequency', $marketData, array(), array() );
	}

	/**
	 * @param array<string, mixed>             $raw
	 * @param array<int, array<string, mixed>> $hotTopics
	 * @param array<int, int>                  $topHeadlines
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $marketData
	 * @param array<int, array<string, string>> $sources
	 * @param array<int, string>               $outlook
	 * @return array<string, mixed>
	 */
	private function reportShape( string $todayLabel, string $marketKey, array $raw, array $hotTopics, array $topHeadlines, array $items, string $modelName, array $marketData, array $sources, array $outlook ): array {
		$index   = is_array( $marketData['index'] ?? null ) ? $marketData['index'] : array();
		$breadth = is_array( $marketData['breadth'] ?? null ) ? $marketData['breadth'] : array();
		$close   = (string) ( $index['close'] ?? '' );
		$sign    = (string) ( $index['sign'] ?? '' );
		$change  = (string) ( $index['change'] ?? '' );
		$pct     = (string) ( $index['percent'] ?? '' );
		$move    = '';
		if ( $change !== '' ) {
			$verb = $sign === '+' ? '上漲' : '下跌';
			$move = $verb . ' ' . $change . ' 點、' . ( $sign === '+' ? '漲幅' : '跌幅' ) . ' ' . ltrim( $pct, '+-' ) . '%';
		}

		$md         = (int) wp_date( 'n', strtotime( $todayLabel . ' 12:00:00' ) ?: time() );
		$dd         = (int) wp_date( 'j', strtotime( $todayLabel . ' 12:00:00' ) ?: time() );
		$defaultTitle = $md . '月' . $dd . '日臺股盤勢';
		if ( $change !== '' ) {
			$defaultTitle .= '：' . ( $sign === '+' ? '上漲' : '回吐' ) . ' ' . $change . ' 點';
		}

		$instSummary = trim( (string) ( $raw['institutionalSummary'] ?? '' ) );
		if ( $instSummary === '' ) {
			$instSummary = $this->defaultInstitutionalSummary( $marketData );
		}
		$instNote = trim( (string) ( $raw['institutionalNote'] ?? '' ) );
		if ( $instNote === '' ) {
			$instNote = $instSummary === '官方資料待更新'
				? '截至撰稿時，證交所三大法人統計尚未回傳符合條件資料，因此不以前一日資料代替。'
				: '三大法人數字以證交所當日統計為準。';
		}

		$disclaimer = trim( (string) $this->options->get( 'disclaimer', '' ) );
		if ( $disclaimer === '' ) {
			$disclaimer = '本文是個人研究紀錄，整理當日台股盤後公開資訊，不構成投資建議、招攬或獲利保證。股價以臺灣證券交易所盤後資料為準；新聞題材是整理，不是已驗證的單一因果。';
		}

		$tags = $this->asStringList( $raw['tags'] ?? array() );
		if ( ! in_array( '台股', $tags, true ) ) {
			array_unshift( $tags, '台股' );
		}
		if ( ! in_array( '台股盤後', $tags, true ) ) {
			$tags[] = '台股盤後';
		}

		$lede = trim( (string) ( $raw['lede'] ?? '' ) );
		if ( $lede === '' && $close !== '' ) {
			$lede = wp_date( 'Y 年 n 月 j 日', strtotime( $todayLabel . ' 12:00:00' ) ?: time() ) . '台股盤勢：加權指數收 ' . $close . ' 點，' . $move . '。';
		}

		$overview = trim( (string) ( $raw['overview'] ?? '' ) );
		if ( $overview === '' && $close !== '' ) {
			$overview = '台股終場收在 ' . $close . ' 點，' . $move . '。';
			if ( (string) ( $breadth['up'] ?? '' ) !== '' ) {
				$overview .= '上市股票上漲 ' . $breadth['up'] . ' 家、下跌 ' . $breadth['down'] . ' 家、平盤 ' . $breadth['flat'] . ' 家。';
			}
		}

		$excerpt = trim( (string) ( $raw['excerpt'] ?? '' ) );
		if ( $excerpt === '' ) {
			$excerpt = $lede;
		}

		if ( $sources === array() ) {
			$sources[] = array(
				'label' => '臺灣證券交易所－每日收盤行情',
				'url'   => (string) ( $marketData['sourceUrl'] ?? 'https://www.twse.com.tw/zh/trading/historical/mi-index.html' ),
			);
		}

		if ( $outlook === array() ) {
			$outlook = array(
				'觀察大型權值股能否止穩。',
				'追蹤強勢題材是否擴散，或仍停留在少數個股。',
				'對照三大法人後續資料，判斷回檔是否伴隨明顯外資賣壓。',
			);
		}

		$conclusion = trim( (string) ( $raw['conclusion'] ?? '' ) );
		if ( $conclusion === '' ) {
			$conclusion = $excerpt;
		}

		$outletCount = array();
		foreach ( $items as $item ) {
			$outletCount[ (string) $item['source'] ] = true;
		}

		return array(
			'date'                  => $todayLabel,
			'marketKey'             => $marketKey,
			'marketMood'            => trim( (string) ( $raw['marketMood'] ?? '' ) ),
			'suggestedTitle'        => trim( (string) ( $raw['suggestedTitle'] ?? '' ) ) !== '' ? trim( (string) $raw['suggestedTitle'] ) : $defaultTitle,
			'excerpt'               => $excerpt,
			'lede'                  => $lede,
			'overview'              => $overview,
			'categories'            => $this->asStringList( $raw['categories'] ?? array( '台股', '盤後新聞' ) ),
			'tags'                  => $tags,
			'hotTopics'             => $hotTopics,
			'topHeadlines'          => $topHeadlines,
			'institutionalSummary'  => $instSummary,
			'institutionalNote'     => $instNote,
			'background'            => trim( (string) ( $raw['background'] ?? '' ) ),
			'outlook'               => $outlook,
			'conclusion'            => $conclusion,
			'sources'               => $sources,
			'disclaimer'            => $disclaimer,
			'market'                => array(
				'indexClose' => $close,
				'indexMove'  => $move,
				'indexSign'  => $sign,
				'up'         => (string) ( $breadth['up'] ?? '' ),
				'down'       => (string) ( $breadth['down'] ?? '' ),
				'flat'       => (string) ( $breadth['flat'] ?? '' ),
			),
			'coverImageId'          => (int) $this->options->get( 'coverImageId', 0 ),
			'sourceCount'           => count( $outletCount ),
			'itemCount'             => count( $items ),
			'model'                 => $modelName,
			'generatedAt'           => ( new Collector() )->nowTaipei()->format( 'Y-m-d H:i:s' ),
			'headlines'             => $items,
			'aiWarning'             => '',
		);
	}

	/**
	 * @param mixed                            $rawSources
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $marketData
	 * @return array<int, array<string, string>>
	 */
	private function parseSources( $rawSources, array $items, array $marketData ): array {
		$allowed = array();
		foreach ( $items as $item ) {
			$allowed[] = (string) ( $item['url'] ?? '' );
		}
		$out = array(
			array(
				'label' => '臺灣證券交易所－每日收盤行情',
				'url'   => (string) ( $marketData['sourceUrl'] ?? 'https://www.twse.com.tw/zh/trading/historical/mi-index.html' ),
			),
		);
		if ( ! is_array( $rawSources ) ) {
			return $out;
		}
		foreach ( $rawSources as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = trim( (string) ( $row['label'] ?? '' ) );
			$url   = esc_url_raw( (string) ( $row['url'] ?? '' ) );
			if ( $label === '' || $url === '' ) {
				continue;
			}
			if ( str_contains( $url, 'twse.com.tw' ) || in_array( $url, $allowed, true ) ) {
				$out[] = array(
					'label' => $label,
					'url'   => $url,
				);
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $marketData
	 */
	private function defaultInstitutionalSummary( array $marketData ): string {
		$rows = is_array( $marketData['institutional']['rows'] ?? null ) ? $marketData['institutional']['rows'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( (string) ( $row['name'] ?? '' ) === '合計' ) {
				$net = (string) ( $row['net'] ?? '' );
				if ( $net === '' ) {
					break;
				}
				$numeric = (float) str_replace( ',', '', $net );
				$yi      = abs( $numeric ) / 100000000;
				$label   = $numeric < 0 ? '合計賣超' : '合計買超';
				return $label . ' ' . number_format( $yi, 0 ) . ' 億';
			}
		}
		return '官方資料待更新';
	}

	/**
	 * @param mixed $value
	 * @return array<int, string>
	 */
	private function asStringList( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $item ) {
			$text = trim( (string) $item );
			if ( $text !== '' ) {
				$result[] = $text;
			}
		}
		return $result;
	}

	/**
	 * @param mixed             $value
	 * @param array<int, bool> $validIds
	 * @return array<int, int>
	 */
	private function asIntList( $value, array $validIds ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $item ) {
			$number = (int) $item;
			if ( $number > 0 && isset( $validIds[ $number ] ) && ! in_array( $number, $result, true ) ) {
				$result[] = $number;
			}
		}
		return $result;
	}
}
