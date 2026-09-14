<?php
/**
 * Plugin bootstrap.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires options, search pipeline, cron, MCP, and admin UI.
 */
class Plugin {
	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate(): void {
		$plugin = self::instance();
		$plugin->options->load();
		if ( get_option( Options::OPTION_KEY, null ) === null ) {
			add_option( Options::OPTION_KEY, Options::defaults(), '', false );
		}
		if ( get_option( Options::STATE_KEY, null ) === null ) {
			add_option( Options::STATE_KEY, Options::defaultState(), '', false );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( STOCK_HOT_NEWS_CRON_HOOK );
		wp_clear_scheduled_hook( STOCK_HOT_NEWS_RUN_NOW );
		delete_transient( 'stock_hot_news_run_lock' );
		delete_option( '_stock_hot_news_exec' );
		delete_transient( 'stock_hot_news_run_token' );
		delete_transient( 'stock_hot_news_probe' );
	}

	private function __construct() {
		$this->options = new Options();
	}

	public function boot(): void {
		$this->options->load();

		$collector  = new Collector();
		$marketData = new MarketData();
		$ranker     = new Ranker( $this->options );
		$renderer   = new Renderer();
		$publisher      = new Publisher( $this->options );
		$coverGenerator = new CoverGenerator( $this->options, $publisher );
		$runner         = new Runner( $this->options, $collector, $ranker, $renderer, $publisher, $marketData, $coverGenerator );
		$hooks     = new Hooks( $this->options, $runner );

		$hooks->register();
		( new Abilities( $runner ) )->register();
		( new Admin( $this->options, $runner, $ranker, $hooks ) )->register();
	}

	public function options(): Options {
		return $this->options;
	}
}
