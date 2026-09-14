<?php
/**
 * Fetch and cluster today's stock headlines from RSS.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pulls Google News / Yahoo / LTN feeds and keeps Taipei-today items.
 */
class Collector {
	private const USER_AGENT = 'Mozilla/5.0 (compatible; StockHotNews/1.0; WordPress)';

	/**
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
	 */
	public function collect( string $market, int $timeoutSec, int $maxHeadlines ): array {
		$todayLabel = $this->taipeiToday();
		$warnings   = array();
		$clusters   = array();

		foreach ( $this->feedList( $market ) as $feedName => $url ) {
			try {
				$rows = $this->fetchFeed( $feedName, $url, $timeoutSec );
			} catch ( \RuntimeException $error ) {
				$warnings[] = $error->getMessage();
				continue;
			}
			foreach ( $rows as $row ) {
				if ( ! $this->isToday( (string) ( $row['publishedRaw'] ?? '' ), $todayLabel ) ) {
					continue;
				}
				$title  = (string) ( $row['title'] ?? '' );
				$source = $this->inferSource( $title, (string) ( $row['source'] ?? '' ), $feedName );
				if ( $this->shouldSkip( $title, $source ) ) {
					continue;
				}
				$key = $this->normalizeTitle( $title );
				if ( $key === '' ) {
					continue;
				}
				if ( isset( $clusters[ $key ] ) ) {
					$clusters[ $key ]['clusterCount'] = (int) $clusters[ $key ]['clusterCount'] + 1;
					if ( $source !== '' && $source !== $clusters[ $key ]['source'] && ! in_array( $source, $clusters[ $key ]['extraSources'], true ) ) {
						$clusters[ $key ]['extraSources'][] = $source;
					}
					continue;
				}
				$clusters[ $key ] = array(
					'itemId'        => 0,
					'title'         => $title,
					'url'           => (string) ( $row['url'] ?? '' ),
					'source'        => $source,
					'feedName'      => $feedName,
					'publishedAt'   => $this->formatPublished( (string) ( $row['publishedRaw'] ?? '' ) ),
					'snippet'       => (string) ( $row['snippet'] ?? '' ),
					'clusterCount'  => 1,
					'extraSources'  => array(),
				);
			}
		}

		$items = array_values( $clusters );
		usort(
			$items,
			function ( $left, $right ) {
				$score = $this->hotScore( $right ) <=> $this->hotScore( $left );
				if ( $score !== 0 ) {
					return $score;
				}
				return strcmp( (string) ( $right['publishedAt'] ?? '' ), (string) ( $left['publishedAt'] ?? '' ) );
			}
		);

		$capped = array();
		$index  = 1;
		foreach ( $items as $item ) {
			if ( $index > $maxHeadlines ) {
				break;
			}
			$item['itemId'] = $index;
			$capped[]       = $item;
			++$index;
		}

		if ( $capped === array() ) {
			$detail = $warnings !== array() ? implode( '; ', $warnings ) : 'all feeds returned zero items';
			throw new \RuntimeException( '今天找不到股市新聞標題。原因：' . $detail );
		}

		return array( $capped, $warnings );
	}

	public function hotScore( array $item ): int {
		$title = (string) ( $item['title'] ?? '' );
		$score = ( (int) ( $item['clusterCount'] ?? 1 ) ) * 10;
		$hits  = preg_match_all( '/台股|大盤|加權|台積電|TSMC|外資|投信|自營|法人|重挫|暴跌|崩跌|暴漲|創高|創低|跌停|漲停|期指|權值|半導體|美股|聯準會|\bFed\b/u', $title );
		$score += 4 * ( is_int( $hits ) ? $hits : 0 );
		if ( preg_match( '/娛樂|星聞|魯冰花|哲哲/u', $title ) ) {
			$score -= 24;
		}
		return $score;
	}

	public function taipeiToday(): string {
		return $this->nowTaipei()->format( 'Y-m-d' );
	}

	public function nowTaipei(): \DateTimeImmutable {
		try {
			$zone = new \DateTimeZone( 'Asia/Taipei' );
		} catch ( \Exception $error ) {
			error_log( 'Stock Hot News: Asia/Taipei timezone missing, using UTC+8. ' . $error->getMessage() );
			$zone = new \DateTimeZone( '+08:00' );
		}
		return new \DateTimeImmutable( 'now', $zone );
	}

	/**
	 * @return array<string, string>
	 */
	private function feedList( string $market ): array {
		$locale   = 'hl=zh-TW&gl=TW&ceid=TW:zh-Hant';
		$twStock  = 'https://news.google.com/rss/search?q=' . rawurlencode( '台股 OR 股市 when:1d' ) . '&' . $locale;
		$usStock  = 'https://news.google.com/rss/search?q=' . rawurlencode( '美股 when:1d' ) . '&' . $locale;
		$business = 'https://news.google.com/rss/headlines/section/topic/BUSINESS?' . $locale;
		$yahoo    = 'https://tw.news.yahoo.com/rss/stock';
		$ltn      = 'https://news.ltn.com.tw/rss/business.xml';

		if ( $market === 'us' ) {
			return array(
				'google-us-stock'    => $usStock,
				'google-tw-business' => $business,
			);
		}
		if ( $market === 'all' ) {
			return array(
				'google-tw-stock'    => $twStock,
				'google-us-stock'    => $usStock,
				'google-tw-business' => $business,
				'yahoo-stock'        => $yahoo,
				'ltn-business'       => $ltn,
			);
		}
		return array(
			'google-tw-stock'    => $twStock,
			'google-tw-business' => $business,
			'yahoo-stock'        => $yahoo,
			'ltn-business'       => $ltn,
		);
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function fetchFeed( string $feedName, string $url, int $timeoutSec ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => max( 5, $timeoutSec ),
				'redirection' => 5,
				'headers'     => array(
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'application/rss+xml, application/xml, text/xml, */*',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( '無法讀取 ' . $feedName . '：' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 || $body === '' ) {
			throw new \RuntimeException( '讀取 ' . $feedName . ' 失敗，HTTP ' . $status . '。' );
		}
		return $this->parseXml( $feedName, $body );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function parseXml( string $feedName, string $xml ): array {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadXML( $xml );
		libxml_clear_errors();
		if ( $loaded !== true ) {
			throw new \RuntimeException( '無法解析 ' . $feedName . ' 的 RSS XML。' );
		}

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//*[local-name()="item" or local-name()="entry"]' );
		if ( $nodes === false ) {
			throw new \RuntimeException( '搜尋 ' . $feedName . ' 的新聞節點失敗。' );
		}

		$rows = array();
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$title = $this->stripHtml( $this->childText( $node, 'title' ) );
			$link  = $this->childText( $node, 'link' );
			if ( $link === '' ) {
				$linkEl = $this->childElement( $node, 'link' );
				if ( $linkEl instanceof \DOMElement ) {
					$link = trim( $linkEl->getAttribute( 'href' ) );
				}
			}
			$source       = $this->stripHtml( $this->childText( $node, 'source' ) );
			$publishedRaw = $this->childText( $node, 'pubDate' )
				?: $this->childText( $node, 'published' )
				?: $this->childText( $node, 'updated' )
				?: $this->childText( $node, 'date' );
			$snippet = $this->stripHtml( $this->childText( $node, 'description' ) ?: $this->childText( $node, 'summary' ) );
			if ( $title === '' || $link === '' ) {
				continue;
			}
			$rows[] = array(
				'title'         => $title,
				'url'           => $link,
				'source'        => $source,
				'publishedRaw'  => $publishedRaw,
				'snippet'       => function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 280 ) : substr( $snippet, 0, 280 ),
			);
		}
		return $rows;
	}

	private function childText( \DOMElement $parent, string $localName ): string {
		$child = $this->childElement( $parent, $localName );
		if ( ! $child instanceof \DOMElement ) {
			return '';
		}
		return trim( (string) $child->textContent );
	}

	private function childElement( \DOMElement $parent, string $localName ): ?\DOMElement {
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof \DOMElement && $child->localName === $localName ) {
				return $child;
			}
		}
		return null;
	}

	private function stripHtml( string $value ): string {
		$text = wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		return trim( $text );
	}

	private function inferSource( string $title, string $explicitSource, string $feedName ): string {
		if ( $explicitSource !== '' ) {
			return $explicitSource;
		}
		if ( preg_match( '/\s+[-–—]\s+([^-–—]{1,40})\s*$/u', $title, $match ) ) {
			return trim( $match[1] );
		}
		return $feedName;
	}

	private function shouldSkip( string $title, string $source ): bool {
		return (bool) preg_match( '/股市爆料|爆料同學會|PTT|mobile01/iu', $title . ' ' . $source );
	}

	private function normalizeTitle( string $title ): string {
		$withoutSource = preg_replace( '/\s+[-–—]\s+[^-–—]{1,40}\s*$/u', '', $title ) ?? $title;
		$folded        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $withoutSource, 'UTF-8' ) : strtolower( $withoutSource );
		$folded        = preg_replace( '/[^\w\x{4e00}-\x{9fff}]+/u', '', $folded ) ?? $folded;
		return trim( $folded );
	}

	private function isToday( string $publishedRaw, string $todayLabel ): bool {
		if ( $publishedRaw === '' ) {
			return true;
		}
		$timestamp = strtotime( $publishedRaw );
		if ( $timestamp === false ) {
			return true;
		}
		return $this->nowTaipei()->setTimestamp( $timestamp )->format( 'Y-m-d' ) === $todayLabel;
	}

	private function formatPublished( string $publishedRaw ): string {
		if ( $publishedRaw === '' ) {
			return '';
		}
		$timestamp = strtotime( $publishedRaw );
		if ( $timestamp === false ) {
			return '';
		}
		return $this->nowTaipei()->setTimestamp( $timestamp )->format( 'Y-m-d H:i' );
	}
}
