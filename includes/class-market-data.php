<?php
/**
 * Official TWSE close data used as "已確認" facts.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches index, market breadth, institutional flow, and key quotes.
 */
class MarketData {
	private const USER_AGENT = 'Mozilla/5.0 (compatible; StockHotNews/1.1; WordPress)';

	/**
	 * Always include these names even if they are not in the news.
	 *
	 * @var array<int, string>
	 */
	private const CORE_CODES = array(
		'2330',
		'2317',
		'2454',
		'2308',
		'2382',
		'2303',
		'2412',
		'2881',
		'2891',
		'1303',
		'6505',
		'3231',
		'6442',
		'3450',
	);

	/**
	 * @param array<int, array<string, mixed>> $headlines
	 * @return array<string, mixed>
	 */
	public function fetch( string $isoDate, array $headlines, int $timeoutSec ): array {
		$ymd     = str_replace( '-', '', $isoDate );
		$payload = array(
			'date'          => $isoDate,
			'available'     => false,
			'warning'       => '',
			'index'         => array(),
			'breadth'       => array(),
			'institutional' => array(),
			'quotes'        => array(),
			'movers'        => array(),
			'sourceUrl'     => 'https://www.twse.com.tw/zh/trading/historical/mi-index.html',
			'instUrl'       => 'https://www.twse.com.tw/zh/trading/foreign/bfi82u.html',
		);

		try {
			$indexJson = $this->getJson(
				'https://www.twse.com.tw/rwd/zh/afterTrading/MI_INDEX?date=' . rawurlencode( $ymd ) . '&type=ALLBUT0999&response=json',
				$timeoutSec
			);
			$payload   = $this->parseIndexPack( $payload, $indexJson, $headlines );
		} catch ( \RuntimeException $error ) {
			$payload['warning'] = $error->getMessage();
			error_log( 'Stock Hot News: TWSE index fetch failed. ' . $error->getMessage() );
		}

		try {
			$instJson                 = $this->getJson(
				'https://www.twse.com.tw/rwd/zh/fund/BFI82U?dayDate=' . rawurlencode( $ymd ) . '&type=day&response=json',
				$timeoutSec
			);
			$payload['institutional'] = $this->parseInstitutional( $instJson );
		} catch ( \RuntimeException $error ) {
			if ( $payload['warning'] === '' ) {
				$payload['warning'] = $error->getMessage();
			}
			error_log( 'Stock Hot News: TWSE institutional fetch failed. ' . $error->getMessage() );
		}

		return $payload;
	}

	/**
	 * Compact text block for the model prompt.
	 *
	 * @param array<string, mixed> $data
	 */
	public function toPromptText( array $data ): string {
		$lines = array();
		$index = is_array( $data['index'] ?? null ) ? $data['index'] : array();
		if ( $index !== array() ) {
			$lines[] = sprintf(
				'加權指數收 %s 點，漲跌 %s 點、漲跌幅 %s%%。',
				(string) ( $index['close'] ?? '' ),
				(string) ( $index['changeText'] ?? '' ),
				(string) ( $index['percentText'] ?? '' )
			);
		} else {
			$lines[] = '加權指數：證交所尚未提供或讀取失敗。';
		}

		$breadth = is_array( $data['breadth'] ?? null ) ? $data['breadth'] : array();
		if ( $breadth !== array() ) {
			$lines[] = sprintf(
				'上市股票上漲 %s 家、下跌 %s 家、平盤 %s 家。',
				(string) ( $breadth['up'] ?? '—' ),
				(string) ( $breadth['down'] ?? '—' ),
				(string) ( $breadth['flat'] ?? '—' )
			);
		}

		$inst = is_array( $data['institutional'] ?? null ) ? $data['institutional'] : array();
		if ( $inst !== array() ) {
			$lines[] = '三大法人買賣超（元）：';
			foreach ( $inst['rows'] ?? array() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$lines[] = sprintf(
					'- %s：買進 %s、賣出 %s、差額 %s',
					(string) ( $row['name'] ?? '' ),
					(string) ( $row['buy'] ?? '' ),
					(string) ( $row['sell'] ?? '' ),
					(string) ( $row['net'] ?? '' )
				);
			}
		} else {
			$lines[] = '三大法人：官方資料待更新，不可用前一日數字代替。';
		}

		$lines[] = '焦點個股（證交所收盤）：';
		foreach ( (array) ( $data['quotes'] ?? array() ) as $quote ) {
			if ( is_array( $quote ) && (string) ( $quote['line'] ?? '' ) !== '' ) {
				$lines[] = '- ' . (string) $quote['line'];
			}
		}

		if ( ! empty( $data['movers'] ) && is_array( $data['movers'] ) ) {
			$lines[] = '成交金額較大且漲跌幅明顯的個股：';
			foreach ( $data['movers'] as $quote ) {
				if ( is_array( $quote ) && (string) ( $quote['line'] ?? '' ) !== '' ) {
					$lines[] = '- ' . (string) $quote['line'];
				}
			}
		}

		if ( (string) ( $data['warning'] ?? '' ) !== '' ) {
			$lines[] = '資料警示：' . (string) $data['warning'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getJson( string $url, int $timeoutSec ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => max( 8, $timeoutSec ),
				'redirection' => 3,
				'headers'     => array(
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'application/json,text/plain,*/*',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( '無法讀取證交所資料：' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 || $raw === '' ) {
			throw new \RuntimeException( '證交所 HTTP ' . $status . '。' );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( '證交所回傳不是 JSON。' );
		}
		$stat = (string) ( $decoded['stat'] ?? '' );
		if ( $stat !== '' && $stat !== 'OK' ) {
			throw new \RuntimeException( '證交所狀態：' . $stat );
		}
		return $decoded;
	}

	/**
	 * @param array<string, mixed>             $payload
	 * @param array<string, mixed>             $json
	 * @param array<int, array<string, mixed>> $headlines
	 * @return array<string, mixed>
	 */
	private function parseIndexPack( array $payload, array $json, array $headlines ): array {
		$tables = is_array( $json['tables'] ?? null ) ? $json['tables'] : array();
		foreach ( $tables as $table ) {
			if ( ! is_array( $table ) ) {
				continue;
			}
			$title  = (string) ( $table['title'] ?? '' );
			$fields = is_array( $table['fields'] ?? null ) ? $table['fields'] : array();
			$rows   = is_array( $table['data'] ?? null ) ? $table['data'] : array();
			if ( $this->fieldHas( $fields, '收盤指數' ) ) {
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) || (string) ( $row[0] ?? '' ) !== '發行量加權股價指數' ) {
						continue;
					}
					$sign   = $this->parseSign( (string) ( $row[2] ?? '' ) );
					$points = ltrim( $this->normalizeNumber( (string) ( $row[3] ?? '' ) ), '+-' );
					$pct    = ltrim( $this->normalizeNumber( (string) ( $row[4] ?? '' ) ), '+-' );
					$payload['index'] = array(
						'name'        => '發行量加權股價指數',
						'close'       => (string) ( $row[1] ?? '' ),
						'sign'        => $sign,
						'change'      => $points,
						'percent'     => $pct,
						'changeText'  => $sign . $points,
						'percentText' => $sign . $pct,
					);
					$payload['available'] = true;
				}
			}
			if ( $title === '漲跌證券數合計' || $this->fieldHas( $fields, '整體市場' ) ) {
				$payload['breadth'] = $this->parseBreadth( $rows );
			}
			if ( $this->fieldHas( $fields, '證券代號' ) && $this->fieldHas( $fields, '收盤價' ) ) {
				$wanted  = $this->wantedCodes( $headlines );
				$quotes  = array();
				$movers  = array();
				$scored  = array();
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$quote = $this->parseQuote( $row );
					if ( $quote === null ) {
						continue;
					}
					if ( isset( $wanted[ $quote['code'] ] ) ) {
						$quotes[] = $quote;
					}
					$amount = $this->toFloat( $quote['amount'] );
					if ( $amount >= 500000000 && abs( $quote['percentValue'] ) >= 1.5 ) {
						$scored[] = $quote;
					}
				}
				usort(
					$scored,
					static function ( $left, $right ) {
						return abs( $right['percentValue'] ) <=> abs( $left['percentValue'] );
					}
				);
				$payload['quotes'] = $quotes;
				$payload['movers'] = array_slice( $scored, 0, 12 );
			}
		}
		return $payload;
	}

	/**
	 * @param array<int, mixed> $rows
	 * @return array<string, string>
	 */
	private function parseBreadth( array $rows ): array {
		$out = array(
			'up'   => '',
			'down' => '',
			'flat' => '',
		);
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = (string) ( $row[0] ?? '' );
			$stock = (string) ( $row[2] ?? $row[1] ?? '' );
			$stock = preg_replace( '/\(.*$/', '', $stock ) ?? $stock;
			if ( str_starts_with( $label, '上漲' ) ) {
				$out['up'] = trim( $stock );
			} elseif ( str_starts_with( $label, '下跌' ) ) {
				$out['down'] = trim( $stock );
			} elseif ( str_contains( $label, '持平' ) ) {
				$out['flat'] = trim( $stock );
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $json
	 * @return array<string, mixed>
	 */
	private function parseInstitutional( array $json ): array {
		$rows = array();
		foreach ( (array) ( $json['data'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || count( $row ) < 4 ) {
				continue;
			}
			$rows[] = array(
				'name' => trim( (string) $row[0] ),
				'buy'  => trim( (string) $row[1] ),
				'sell' => trim( (string) $row[2] ),
				'net'  => trim( (string) $row[3] ),
			);
		}
		if ( $rows === array() ) {
			return array();
		}
		return array(
			'title' => (string) ( $json['title'] ?? '' ),
			'rows'  => $rows,
		);
	}

	/**
	 * @param array<int, mixed> $row
	 * @return array<string, mixed>|null
	 */
	private function parseQuote( array $row ): ?array {
		$code = trim( (string) ( $row[0] ?? '' ) );
		$name = trim( (string) ( $row[1] ?? '' ) );
		if ( $code === '' || $name === '' ) {
			return null;
		}
		$close  = (string) ( $row[8] ?? '' );
		$sign   = $this->parseSign( (string) ( $row[9] ?? '' ) );
		$diff   = $this->normalizeNumber( (string) ( $row[10] ?? '' ) );
		$high   = (string) ( $row[6] ?? '' );
		$low    = (string) ( $row[7] ?? '' );
		$open   = (string) ( $row[5] ?? '' );
		$amount = (string) ( $row[4] ?? '' );
		$closeN = $this->toFloat( $close );
		$diffN  = $this->toFloat( $diff );
		$signed = ( $sign === '-' ? -1 : 1 ) * $diffN;
		$prev   = $closeN - $signed;
		$pct    = ( $prev > 0 ) ? ( $signed / $prev ) * 100 : 0.0;
		$lowHit = ( $close !== '' && $low !== '' && $this->toFloat( $close ) === $this->toFloat( $low ) );
		$highHit = ( $close !== '' && $high !== '' && $this->toFloat( $close ) === $this->toFloat( $high ) );
		$pctText = sprintf( '%s%.2f%%', $pct >= 0 ? '+' : '', $pct );
		$line    = sprintf(
			'%s %s 收 %s 元，%s%s（約 %s）；開 %s、高 %s、低 %s%s',
			$code,
			$name,
			$close,
			$sign,
			$diff,
			$pctText,
			$open,
			$high,
			$low,
			$lowHit ? '，收在當日低點' : ( $highHit ? '，收在當日高點' : '' )
		);
		return array(
			'code'          => $code,
			'name'          => $name,
			'close'         => $close,
			'sign'          => $sign,
			'change'        => $diff,
			'percentText'   => $pctText,
			'percentValue'  => $pct,
			'open'          => $open,
			'high'          => $high,
			'low'           => $low,
			'amount'        => $amount,
			'closeAtLow'    => $lowHit,
			'closeAtHigh'   => $highHit,
			'line'          => $line,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $headlines
	 * @return array<string, bool>
	 */
	private function wantedCodes( array $headlines ): array {
		$wanted = array();
		foreach ( self::CORE_CODES as $code ) {
			$wanted[ $code ] = true;
		}
		foreach ( $headlines as $item ) {
			$title = (string) ( $item['title'] ?? '' );
			if ( preg_match_all( '/\b(\d{4})\b/', $title, $matches ) ) {
				foreach ( $matches[1] as $code ) {
					$wanted[ $code ] = true;
				}
			}
		}
		return $wanted;
	}

	/**
	 * @param array<int, mixed> $fields
	 */
	private function fieldHas( array $fields, string $needle ): bool {
		foreach ( $fields as $field ) {
			if ( is_string( $field ) && str_contains( $field, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function parseSign( string $html ): string {
		$plain = wp_strip_all_tags( $html );
		if ( str_contains( $html, 'green' ) || str_contains( $plain, '-' ) ) {
			return '-';
		}
		if ( str_contains( $html, 'red' ) || str_contains( $plain, '+' ) ) {
			return '+';
		}
		return '';
	}

	private function normalizeNumber( string $value ): string {
		$value = trim( str_replace( ',', '', wp_strip_all_tags( $value ) ) );
		return $value;
	}

	private function toFloat( string $value ): float {
		$normalized = $this->normalizeNumber( $value );
		if ( $normalized === '' || $normalized === '--' ) {
			return 0.0;
		}
		return (float) $normalized;
	}
}
