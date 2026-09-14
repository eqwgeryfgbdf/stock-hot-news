<?php
/**
 * Render a stock-watch article matching the site template.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the zh-TW 盤勢 HTML used on stock-watch-YYYY-MM-DD posts.
 */
class Renderer {
	/**
	 * @param array<string, mixed> $report Ranked article payload.
	 */
	public function toHtml( array $report ): string {
		$date       = (string) ( $report['date'] ?? '' );
		$dateLabel  = $this->chineseDate( $date );
		$disclaimer = (string) ( $report['disclaimer'] ?? '本文是個人研究紀錄，整理當日台股盤後公開資訊，不構成投資建議、招攬或獲利保證。' );
		$market     = is_array( $report['market'] ?? null ) ? $report['market'] : array();
		$indexClose = (string) ( $market['indexClose'] ?? '' );
		$indexMove  = (string) ( $market['indexMove'] ?? '' );
		$toneClass  = $this->moveTone( (string) ( $market['indexSign'] ?? '' ) );
		$lede      = (string) ( $report['lede'] ?? '' );
		$overview  = (string) ( $report['overview'] ?? '' );
		$excerpt   = (string) ( $report['excerpt'] ?? '' );
		$background = trim( (string) ( $report['background'] ?? '' ) );
		if ( $background === '' ) {
			$background = '多家盤前與盤中報導會並列國際消息作為市場壓力背景。這些因素可能同時發生，但股市波動通常由多項因素共同形成，因此本文不把任何一項寫成當日漲跌的唯一原因。';
		}
		$conclusion = trim( (string) ( $report['conclusion'] ?? '' ) );
		if ( $conclusion === '' ) {
			$conclusion = $excerpt !== '' ? $excerpt : $lede;
		}
		$comment    = $this->suggestionComment( $report );

		$parts   = array();
		$parts[] = $comment;
		$parts[] = '<article class="stock-watch-article" style="width: 100%; max-width: 100%; margin: 0; padding: 20px 4px; background: #faf9f5; color: #333; font-family: -apple-system,BlinkMacSystemFont,\'PingFang TC\',\'Noto Sans TC\',\'Microsoft JhengHei\',sans-serif; font-size: 17px; line-height: 1.9; box-sizing: border-box;">';
		$parts[] = '<header style="margin-bottom: 28px; text-align: center;">' . esc_html( $lede !== '' ? $lede : $this->defaultLede( $dateLabel, $indexClose, $indexMove, $report ) );
		$parts[] = $this->coverFigure( $report );
		$parts[] = '</header>';
		$parts[] = '<aside style="margin: 24px 0; padding: 16px 18px; border-left: 6px solid #a93226; border-radius: 8px; background: #fff3f1;"><strong style="color: #a93226;">風險聲明：</strong>' . esc_html( $disclaimer ) . '</aside>';
		$parts[] = '<h2 style="margin: 34px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #d8aaa5; color: #a93226; font-size: 24px;">' . esc_html( $dateLabel . '台股盤勢' ) . '</h2>';
		$parts[] = $this->overviewHtml( $overview, $indexClose, $indexMove, $toneClass, $market );
		$parts[] = '<h2 style="margin: 34px 0 18px; padding-bottom: 8px; border-bottom: 2px solid #d8aaa5; color: #a93226; font-size: 24px;">新聞熱點 TOP 5</h2>';
		$parts[] = $this->topicsHtml( $report );
		$parts[] = '<h2 style="margin: 34px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #d8aaa5; color: #a93226; font-size: 24px;">法人與盤面廣度</h2>';
		$parts[] = $this->breadthTable( $report );
		$parts[] = '<p>' . esc_html( (string) ( $report['institutionalNote'] ?? '' ) ) . '</p>';
		$parts[] = '<h2 style="margin: 34px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #d8aaa5; color: #a93226; font-size: 24px;">下跌背景：哪些是事實，哪些是解讀？</h2>';
		$parts[] = '<p>' . esc_html( $background ) . '</p>';
		$parts[] = '<h2 style="margin: 34px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #d8aaa5; color: #a93226; font-size: 24px;">後市觀察</h2>';
		$parts[] = $this->outlookHtml( $report );
		$parts[] = '<h2 style="margin: 34px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #d8aaa5; color: #a93226; font-size: 24px;">結論</h2>';
		$parts[] = '<p>' . esc_html( $conclusion ) . '</p>';
		$parts[] = $this->relatedReadingHtml( $date );
		$parts[] = $this->sourcesFooter( $report );
		$parts[] = '</article>';

		return implode( "\n", $parts );
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function suggestionComment( array $report ): string {
		$title = (string) ( $report['suggestedTitle'] ?? '' );
		$excerpt = (string) ( $report['excerpt'] ?? '' );
		$cats = implode( '、', array_map( 'strval', (array) ( $report['categories'] ?? array( '台股', '盤後新聞' ) ) ) );
		$tags = implode( '、', array_map( 'strval', (array) ( $report['tags'] ?? array() ) ) );
		$blob = '建議標題：' . $title . ' 文章摘要：' . $excerpt . ' 建議分類：' . $cats . ' 建議標籤：' . $tags;
		$imagePrompt = trim( (string) ( $report['imagePrompt'] ?? '' ) );
		if ( $imagePrompt !== '' ) {
			$blob .= ' 圖片提示詞：' . $imagePrompt;
		}
		return '<!-- ' . str_replace( array( '<!--', '-->' ), '', $blob ) . ' -->';
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function coverFigure( array $report ): string {
		$imageId = (int) ( $report['coverImageId'] ?? 0 );
		if ( $imageId <= 0 ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $imageId, 'full' );
		if ( ! is_string( $src ) || $src === '' ) {
			$src = wp_get_attachment_image_url( $imageId, 'large' );
		}
		if ( ! is_string( $src ) || $src === '' ) {
			return '';
		}
		$alt = (string) get_post_meta( $imageId, '_wp_attachment_image_alt', true );
		if ( $alt === '' ) {
			$alt = '台股盤勢重點資訊圖；紅色代表上漲、綠色代表下跌。';
		}
		$html = wp_get_attachment_image(
			$imageId,
			'full',
			false,
			array(
				'class' => 'alignnone wp-image-' . $imageId,
				'alt'   => $alt,
				'style' => 'width: 100%; height: auto; max-width: 100%;',
			)
		);
		if ( ! is_string( $html ) || $html === '' ) {
			$html = '<img class="alignnone wp-image-' . (int) $imageId . '" src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" style="width: 100%; height: auto; max-width: 100%;" />';
		}
		return '<figure style="width: 100%; max-width: 100%; margin: 18px 0 0;">' . $html . '<figcaption style="margin-top: 8px; color: #64748b; font-size: 14px;">圖為原創新聞重點資訊圖；紅色代表上漲、綠色代表下跌。</figcaption></figure>';
	}

	/**
	 * @param array<string, mixed> $market
	 */
	private function overviewHtml( string $overview, string $indexClose, string $indexMove, array $tone, array $market ): string {
		if ( $overview !== '' ) {
			return '<p>' . $this->emphasizeNumbers( $overview, $tone ) . '</p>';
		}
		$up   = (string) ( $market['up'] ?? '' );
		$down = (string) ( $market['down'] ?? '' );
		$flat = (string) ( $market['flat'] ?? '' );
		$text = '台股終場收在 ' . $indexClose . ' 點，' . $indexMove . '。';
		if ( $up !== '' ) {
			$text .= '上市股票上漲 ' . $up . ' 家、下跌 ' . $down . ' 家、平盤 ' . $flat . ' 家。';
		}
		return '<p>' . $this->emphasizeNumbers( $text, $tone ) . '</p>';
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function topicsHtml( array $report ): string {
		$html  = '';
		$rank  = 1;
		foreach ( array_slice( (array) ( $report['hotTopics'] ?? array() ), 0, 5 ) as $topic ) {
			if ( ! is_array( $topic ) ) {
				continue;
			}
			$tone    = $this->topicTone( (string) ( $topic['tone'] ?? 'mixed' ) );
			$title   = (string) ( $topic['topic'] ?? '' );
			$confirm = (string) ( $topic['confirmed'] ?? $topic['summary'] ?? '' );
			$analysis = (string) ( $topic['analysis'] ?? $topic['whyHot'] ?? '' );
			$html   .= '<section style="margin: 22px 0; padding: 18px; border-radius: 12px; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.05);">' . "\n";
			$html   .= '<h3 style="margin: 0 0 12px; padding-left: 12px; border-left: 5px solid ' . esc_attr( $tone['border'] ) . '; color: ' . esc_attr( $tone['color'] ) . '; font-size: 21px; line-height: 1.5;">'
				. esc_html( $rank . '. ' . $title ) . '</h3>' . "\n";
			$html   .= '<p><strong>已確認：</strong>' . esc_html( $confirm ) . "</p>\n";
			$html   .= '<p><strong>分析：</strong>' . esc_html( $analysis ) . "</p>\n";
			$html   .= "</section>\n";
			++$rank;
		}
		return $html;
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function breadthTable( array $report ): string {
		$market = is_array( $report['market'] ?? null ) ? $report['market'] : array();
		$inst   = (string) ( $report['institutionalSummary'] ?? '官方資料待更新' );
		$up     = (string) ( $market['up'] ?? '—' );
		$down   = (string) ( $market['down'] ?? '—' );
		$flat   = (string) ( $market['flat'] ?? '—' );
		$th     = 'padding: 11px; border: 1px solid #ddd; background: #f1d9d6;';
		$td     = 'padding: 10px; border: 1px solid #ddd;';
		$html   = '<div style="max-width: 100%; overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; background: #fff; font-size: 16px;"><thead><tr>';
		$html  .= '<th style="' . $th . ' text-align: left;">項目</th>';
		$html  .= '<th style="' . $th . ' text-align: right;">結果</th></tr></thead><tbody>';
		$html  .= '<tr><td style="' . $td . '">三大法人買賣超</td><td style="' . $td . ' text-align: right;">' . esc_html( $inst ) . '</td></tr>';
		$html  .= '<tr><td style="' . $td . '">上市股票上漲／下跌／平盤</td><td style="' . $td . ' text-align: right;">' . esc_html( $up . '／' . $down . '／' . $flat . ' 家' ) . '</td></tr>';
		$html  .= '</tbody></table></div>';
		return $html;
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function outlookHtml( array $report ): string {
		$items = (array) ( $report['outlook'] ?? array() );
		if ( $items === array() ) {
			return '<p>觀察大型權值股能否止穩，以及局部強勢能否擴散。</p>';
		}
		$html = '<ol style="padding-left: 24px;">';
		foreach ( $items as $item ) {
			$text = trim( (string) $item );
			if ( $text === '' ) {
				continue;
			}
			$html .= '<li style="margin-bottom: 12px;">' . esc_html( $text ) . '</li>';
		}
		$html .= '</ol>';
		return $html;
	}

	private function relatedReadingHtml( string $date ): string {
		$parts = array();
		$prev  = $this->previousWatchPost( $date );
		if ( $prev instanceof \WP_Post ) {
			$url = get_permalink( $prev );
			if ( is_string( $url ) && $url !== '' ) {
				$parts[] = '<a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $prev ) ) . '</a>';
			}
		}
		$hub = get_page_by_path( 'taiwan-stock-watch' );
		if ( $hub instanceof \WP_Post ) {
			$url = get_permalink( $hub );
			if ( is_string( $url ) && $url !== '' ) {
				$parts[] = '<a href="' . esc_url( $url ) . '">台股觀察總覽</a>';
			}
		}
		$about = get_page_by_path( 'about' );
		if ( $about instanceof \WP_Post ) {
			$url = get_permalink( $about );
			if ( is_string( $url ) && $url !== '' ) {
				$parts[] = '<a href="' . esc_url( $url ) . '">關於我與資料來源說明</a>';
			}
		}
		if ( $parts === array() ) {
			return '';
		}
		return '<p class="seo-related-reading">延伸閱讀：' . implode( '、', $parts ) . '。</p>';
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function sourcesFooter( array $report ): string {
		$generated = (string) ( $report['generatedAt'] ?? '' );
		$html      = '<footer style="margin-top: 38px; padding-top: 20px; border-top: 1px solid #ccc; font-size: 15px;">';
		$html     .= '<h2 style="margin: 0 0 12px; color: #a93226; font-size: 21px;">資料來源</h2><ul>';
		$sources   = (array) ( $report['sources'] ?? array() );
		if ( $sources === array() ) {
			$sources[] = array(
				'label' => '臺灣證券交易所－每日收盤行情',
				'url'   => 'https://www.twse.com.tw/zh/trading/historical/mi-index.html',
			);
		}
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$label = (string) ( $source['label'] ?? '' );
			$url   = esc_url( (string) ( $source['url'] ?? '' ) );
			if ( $label === '' ) {
				continue;
			}
			if ( $url !== '' ) {
				$html .= '<li><a href="' . $url . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></li>';
			} else {
				$html .= '<li>' . esc_html( $label ) . '</li>';
			}
		}
		$html .= '</ul>';
		$html .= '<p style="color: #64748b;">本文整理時間為 ' . esc_html( $generated ) . '。內容僅供新聞閱讀與研究參考，不構成任何投資建議。</p>';
		$html .= '</footer>';
		return $html;
	}

	private function previousWatchPost( string $date ): ?\WP_Post {
		$timestamp = strtotime( $date . ' 12:00:00' );
		if ( $timestamp === false ) {
			return null;
		}
		for ( $i = 1; $i <= 5; $i++ ) {
			$prev = strtotime( '-' . $i . ' day', $timestamp );
			if ( $prev === false ) {
				continue;
			}
			$day = wp_date( 'Y-m-d', $prev );
			if ( ! is_string( $day ) || $day === '' ) {
				continue;
			}
			$slug = 'stock-watch-' . $day;
			$query = new \WP_Query(
				array(
					'name'           => $slug,
					'post_type'      => 'post',
					'post_status'    => array( 'publish', 'draft' ),
					'posts_per_page' => 1,
				)
			);
			if ( $query->have_posts() && $query->posts[0] instanceof \WP_Post ) {
				return $query->posts[0];
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function defaultLede( string $dateLabel, string $indexClose, string $indexMove, array $report ): string {
		$mood = (string) ( $report['marketMood'] ?? '' );
		$base = $dateLabel . '台股盤勢';
		if ( $indexClose !== '' ) {
			$base .= '：加權指數收 ' . $indexClose . ' 點，' . $indexMove . '。';
		}
		if ( $mood !== '' ) {
			$base .= $mood;
		}
		return $base;
	}

	/**
	 * @return array{border:string,color:string}
	 */
	private function topicTone( string $tone ): array {
		if ( $tone === 'up' ) {
			return array(
				'border' => '#c0392b',
				'color'  => '#8f231a',
			);
		}
		if ( $tone === 'down' ) {
			return array(
				'border' => '#14804a',
				'color'  => '#11663d',
			);
		}
		return array(
			'border' => '#59636e',
			'color'  => '#333333',
		);
	}

	/**
	 * @return array{color:string}
	 */
	private function moveTone( string $sign ): array {
		if ( $sign === '+' ) {
			return array( 'color' => '#c0392b' );
		}
		return array( 'color' => '#14804a' );
	}

	/**
	 * @param array{color:string} $tone
	 */
	private function emphasizeNumbers( string $text, array $tone ): string {
		$escaped = esc_html( $text );
		$color   = esc_attr( $tone['color'] );
		$wrapped = preg_replace(
			'/(\d{1,3}(?:,\d{3})*(?:\.\d+)?\s*點|\d{1,3}(?:,\d{3})*(?:\.\d+)?\s*%|下跌\s*\d[^\s。]{0,20}|上漲\s*\d[^\s。]{0,20})/u',
			'<strong style="color: ' . $color . ';">$1</strong>',
			$escaped
		);
		return is_string( $wrapped ) ? $wrapped : $escaped;
	}

	private function chineseDate( string $isoDate ): string {
		$timestamp = strtotime( $isoDate . ' 12:00:00' );
		if ( $timestamp === false ) {
			return $isoDate;
		}
		return wp_date( 'Y 年 n 月 j 日', $timestamp ) ?: $isoDate;
	}
}
