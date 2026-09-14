<?php
/**
 * Save the briefing as a WordPress stock-watch post.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates or updates a draft at /YYYY/MM/DD/stock-watch-YYYY-MM-DD/.
 */
class Publisher {
	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * @param array<string, mixed> $report Ranked report.
	 * @return int|\WP_Error Post ID or error.
	 */
	public function saveDraft( array $report, string $html ) {
		$date = (string) ( $report['date'] ?? '' );
		if ( $date === '' ) {
			return new \WP_Error( 'missing_date', '報告缺少日期，無法建立文章。' );
		}

		$slug  = 'stock-watch-' . $date;
		$title = trim( (string) ( $report['suggestedTitle'] ?? '' ) );
		if ( $title === '' ) {
			$title = '臺股盤勢（' . $date . '）';
		}

		$status = (string) $this->options->get( 'postStatus', 'draft' );
		if ( ! in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ) {
			$status = 'draft';
		}

		$existingId = $this->existingPostId( $date, $slug );
		if ( $existingId > 0 ) {
			$existing = get_post( $existingId );
			if ( $existing instanceof \WP_Post && $existing->post_status === 'publish' ) {
				return new \WP_Error( 'already_published', '今日已有發佈文章（#' . $existingId . '），為避免覆蓋未自動改寫。請先改回草稿再執行。' );
			}
		}

		$postDate = $this->postDateFor( $date );
		$postData = array(
			'post_title'    => $title,
			'post_name'     => $slug,
			'post_content'  => $html,
			'post_status'   => $status,
			'post_type'     => 'post',
			'post_excerpt'  => (string) ( $report['excerpt'] ?? '' ),
			'post_date'     => $postDate,
			'post_date_gmt' => get_gmt_from_date( $postDate ),
		);

		$categoryIds = $this->categoryIds( $report );
		if ( $categoryIds !== array() ) {
			$postData['post_category'] = $categoryIds;
		}

		if ( $existingId > 0 ) {
			$postData['ID'] = $existingId;
		}

		kses_remove_filters();
		if ( $existingId > 0 ) {
			$result = wp_update_post( wp_slash( $postData ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postData ), true );
		}
		kses_init_filters();

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'post_save', '無法寫入文章：' . $result->get_error_message() );
		}
		$postId = (int) $result;
		if ( $postId <= 0 ) {
			return new \WP_Error( 'post_save', '寫入文章後沒有得到有效的文章 ID。' );
		}

		$dateSaved = update_post_meta( $postId, '_stock_hot_news_date', $date );
		if ( false === $dateSaved ) {
			error_log( 'Stock Hot News: failed to save date meta for post ' . $postId );
		}
		$encoded = wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( is_string( $encoded ) && $encoded !== '' ) {
			$reportSaved = update_post_meta( $postId, '_stock_hot_news_report', $encoded );
			if ( false === $reportSaved ) {
				error_log( 'Stock Hot News: failed to save report meta for post ' . $postId );
			}
		}

		$tags = array_values( array_filter( array_map( 'strval', (array) ( $report['tags'] ?? array() ) ) ) );
		if ( $tags !== array() ) {
			$tagResult = wp_set_post_tags( $postId, $tags, false );
			if ( is_wp_error( $tagResult ) ) {
				error_log( 'Stock Hot News: failed to save tags for post ' . $postId . ': ' . $tagResult->get_error_message() );
			}
		}

		$coverId = (int) ( $report['coverImageId'] ?? $this->options->get( 'coverImageId', 0 ) );
		if ( $coverId > 0 && wp_attachment_is_image( $coverId ) ) {
			$thumb = set_post_thumbnail( $postId, $coverId );
			if ( false === $thumb ) {
				error_log( 'Stock Hot News: failed to set featured image ' . $coverId . ' on post ' . $postId );
			}
		}

		return $postId;
	}

	/**
	 * Set the featured image after Codex uploads a new cover.
	 *
	 * @return true|\WP_Error
	 */
	public function applyCover( int $postId, int $coverId ) {
		if ( $postId <= 0 ) {
			return new \WP_Error( 'cover_post', '沒有有效的文章 ID，無法設定特色圖片。' );
		}
		if ( $coverId <= 0 || ! wp_attachment_is_image( $coverId ) ) {
			return new \WP_Error( 'cover_image', '封面附件 ' . $coverId . ' 不是有效圖片。' );
		}
		$thumb = set_post_thumbnail( $postId, $coverId );
		if ( false === $thumb ) {
			return new \WP_Error( 'cover_thumb', '無法把附件 ' . $coverId . ' 設成文章 ' . $postId . ' 的特色圖片。' );
		}
		return true;
	}

	/**
	 * Replace draft HTML after the generated cover is attached.
	 *
	 * @param array<string, mixed> $report
	 * @return true|\WP_Error
	 */
	public function updateContent( int $postId, string $html, array $report ) {
		if ( $postId <= 0 ) {
			return new \WP_Error( 'content_post', '沒有有效的文章 ID，無法更新正文。' );
		}
		kses_remove_filters();
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $postId,
					'post_content' => $html,
					'post_excerpt' => (string) ( $report['excerpt'] ?? '' ),
				)
			),
			true
		);
		kses_init_filters();
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'content_save', '無法更新文章正文：' . $result->get_error_message() );
		}

		$encoded = wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( is_string( $encoded ) && $encoded !== '' ) {
			$reportSaved = update_post_meta( $postId, '_stock_hot_news_report', $encoded );
			if ( false === $reportSaved ) {
				error_log( 'Stock Hot News: failed to refresh report meta for post ' . $postId );
			}
		}
		$prompt = trim( (string) ( $report['imagePrompt'] ?? '' ) );
		if ( $prompt !== '' ) {
			$promptSaved = update_post_meta( $postId, '_stock_hot_news_image_prompt', $prompt );
			if ( false === $promptSaved ) {
				error_log( 'Stock Hot News: failed to save image prompt for post ' . $postId );
			}
		}
		return true;
	}

	private function existingPostId( string $date, string $slug ): int {
		$bySlug = new \WP_Query(
			array(
				'name'           => $slug,
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'pending', 'future', 'publish' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $bySlug->posts !== array() ) {
			return (int) $bySlug->posts[0];
		}
		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'pending', 'future', 'publish' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_stock_hot_news_date',
				'meta_value'     => $date,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		if ( $query->posts === array() ) {
			return 0;
		}
		return (int) $query->posts[0];
	}

	private function postDateFor( string $date ): string {
		$hour   = max( 0, min( 23, (int) $this->options->get( 'scheduleHour', 16 ) ) );
		$minute = max( 0, min( 59, (int) $this->options->get( 'scheduleMinute', 30 ) ) );
		return sprintf( '%s %02d:%02d:00', $date, $hour, $minute );
	}

	/**
	 * @param array<string, mixed> $report
	 * @return array<int, int>
	 */
	private function categoryIds( array $report ): array {
		$ids  = array();
		$slug = sanitize_title( (string) $this->options->get( 'categorySlug', 'stock-views' ) );
		if ( $slug !== '' ) {
			$term = get_category_by_slug( $slug );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			} else {
				error_log( 'Stock Hot News: category slug "' . $slug . '" was not found.' );
			}
		}
		foreach ( (array) ( $report['categories'] ?? array() ) as $name ) {
			$name = trim( (string) $name );
			if ( $name === '' ) {
				continue;
			}
			$term = get_term_by( 'name', $name, 'category' );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
