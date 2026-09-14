<?php
/**
 * WordPress Abilities / MCP tools.
 *
 * @package StockHotNews
 */

namespace StockHotNews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets an AI agent trigger today's search.
 */
class Abilities {
	/**
	 * @var Runner
	 */
	private $runner;

	public function __construct( Runner $runner ) {
		$this->runner = $runner;
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
	}

	public function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'stock-hot-news',
			array(
				'label'       => '熱門股市新聞',
				'description' => 'Search and rank today\'s popular stock headlines.',
			)
		);
	}

	public function registerAbilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			error_log( 'Stock Hot News: wp_register_ability is unavailable; MCP tools were not registered.' );
			return;
		}

		$mcpMeta = array(
			'mcp'          => array( 'public' => true ),
			'show_in_rest' => true,
		);

		wp_register_ability(
			'stock-hot-news/run-search',
			array(
				'label'               => 'Run today\'s hot stock news search',
				'description'         => 'Fetch today\'s stock RSS headlines, rank them with the configured local model, optionally save a draft, then generate a cover with Codex CLI.',
				'category'            => 'stock-hot-news',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'createPost' => array(
							'type'        => 'boolean',
							'description' => 'If true, create or update a draft post from the briefing.',
							'default'     => false,
						),
					),
				),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function ( $input ) {
					$createPost = ! empty( $input['createPost'] );
					$result     = $this->runner->run( $createPost );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					unset( $result['html'] );
					return $result;
				},
				'meta'                => $mcpMeta,
			)
		);
	}
}
