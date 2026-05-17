<?php
/**
 * Plugin Name:  WP Abilities API Test
 * Description:  Registers a write-post ability using the WP Abilities API (WordPress 6.9+).
 * Version:      1.0.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bail with an admin notice on WordPress versions that don't have the Abilities API.
if ( ! class_exists( 'WP_Ability' ) ) {
	add_action(
		'admin_notices',
		static function () {
			wp_admin_notice(
				esc_html__( 'WP Abilities API Test requires WordPress 6.9 or newer.', 'wp-abilities-api-test' ),
				array( 'type' => 'error' )
			);
		}
	);
	return;
}

require_once __DIR__ . '/includes/class-ability.php';
require_once __DIR__ . '/includes/class-ability-factory.php';
require_once __DIR__ . '/includes/class-ability-orchestrator.php';
require_once __DIR__ . '/includes/class-block-serializer.php';
require_once __DIR__ . '/includes/abilities/class-write-post-ability.php';
require_once __DIR__ . '/includes/abilities/class-serialize-blocks-ability.php';

use WP_Abilities_Test\Ability_Orchestrator;
use WP_Abilities_Test\Abilities\Write_Post_Ability;
use WP_Abilities_Test\Abilities\Serialize_Blocks_Ability;

// Boot the orchestrator and register all abilities.
add_action(
	'plugins_loaded',
	static function () {
		$orchestrator = new Ability_Orchestrator();
		$orchestrator->add( new Write_Post_Ability() );
		$orchestrator->add( new Serialize_Blocks_Ability() );
		$orchestrator->register_all();
	}
);

// REST endpoint: POST /wp-abilities-test/v1/execute
// Body: { "ability": "namespace/name", "input": { ... } }
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'wp-abilities-test/v1',
			'/execute',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$ability_name = sanitize_text_field( $request->get_param( 'ability' ) ?? '' );
					$input        = $request->get_param( 'input' ) ?? array();

					if ( empty( $ability_name ) ) {
						return new WP_Error( 'missing_ability', 'The "ability" parameter is required.', array( 'status' => 400 ) );
					}

					$ability = wp_get_ability( $ability_name );
					if ( ! $ability ) {
						return new WP_Error( 'ability_not_found', sprintf( 'Ability "%s" is not registered.', $ability_name ), array( 'status' => 404 ) );
					}

					$result = $ability->execute( $input );

					if ( is_wp_error( $result ) ) {
						$result->add_data( array( 'status' => 500 ) );
						return $result;
					}

					return rest_ensure_response( $result );
				},
				'args'                => array(
					'ability' => array(
						'type'     => 'string',
						'required' => true,
					),
					'input'   => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
	}
);

// Post-list dialog: button + modal on Posts → All Posts.
add_action(
	'admin_enqueue_scripts',
	static function () {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-post' !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'wp-abilities-test-dialog',
			plugin_dir_url( __FILE__ ) . 'assets/css/post-list-dialog.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'wp-abilities-test-dialog',
			plugin_dir_url( __FILE__ ) . 'assets/js/post-list-dialog.js',
			array( 'wp-i18n', 'wp-api-fetch' ),
			'1.0.0',
			true
		);
	}
);
