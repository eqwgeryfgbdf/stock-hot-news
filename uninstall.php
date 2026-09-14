<?php
/**
 * Remove plugin settings and last-run state.
 *
 * @package StockHotNews
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settingsDeleted = delete_option( 'stock_hot_news' );
if ( false === $settingsDeleted && get_option( 'stock_hot_news', null ) !== null ) {
	error_log( 'Stock Hot News: uninstall could not delete settings.' );
}

$stateDeleted = delete_option( 'stock_hot_news_state' );
if ( false === $stateDeleted && get_option( 'stock_hot_news_state', null ) !== null ) {
	error_log( 'Stock Hot News: uninstall could not delete last-run state.' );
}

$dateMetaDeleted = delete_post_meta_by_key( '_stock_hot_news_date' );
if ( false === $dateMetaDeleted ) {
	error_log( 'Stock Hot News: uninstall could not delete date meta.' );
}

$promptMetaDeleted = delete_post_meta_by_key( '_stock_hot_news_image_prompt' );
if ( false === $promptMetaDeleted ) {
	error_log( 'Stock Hot News: uninstall could not delete image prompt meta.' );
}
