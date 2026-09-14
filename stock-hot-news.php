<?php
/**
 * Plugin Name: Stock Hot News
 * Description: Build a daily Taiwan stock-watch post from TWSE closes and RSS news, then generate a cover with Codex CLI.
 * Version: 1.2.0
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Author: Roy
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stock-hot-news
 *
 * @package StockHotNews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STOCK_HOT_NEWS_VERSION', '1.2.0' );
define( 'STOCK_HOT_NEWS_FILE', __FILE__ );
define( 'STOCK_HOT_NEWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'STOCK_HOT_NEWS_CRON_HOOK', 'stock_hot_news_daily' );
define( 'STOCK_HOT_NEWS_RUN_NOW', 'stock_hot_news_run_now' );

require_once STOCK_HOT_NEWS_DIR . 'includes/class-options.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-collector.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-market-data.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-ranker.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-renderer.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-publisher.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-cover-generator.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-runner.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-hooks.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-abilities.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-admin.php';
require_once STOCK_HOT_NEWS_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'StockHotNews\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'StockHotNews\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\StockHotNews\Plugin::instance()->boot();
	}
);
