<?php

namespace WP_Abilities_Test\Abilities;

use WP_Abilities_Test\Ability;
use WP_Abilities_Test\Block_Serializer;

class Serialize_Blocks_Ability extends Ability {

	public function get_name(): string {
		return 'wp-abilities-api-test/serialize-blocks';
	}

	public function get_label(): string {
		return __( 'Serialize Blocks', 'wp-abilities-api-test' );
	}

	public function get_description(): string {
		return __( 'Converts a JSON array of block descriptors into a serialized Gutenberg post_content string.', 'wp-abilities-api-test' );
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'blocks' => array(
					'type'        => 'array',
					'description' => 'Array of block descriptor objects.',
					'items'       => array( 'type' => 'object' ),
				),
			),
			'required'             => array( 'blocks' ),
			'additionalProperties' => false,
		);
	}

	public function get_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'        => 'string',
					'description' => 'Serialized Gutenberg block markup ready for post_content.',
				),
			),
		);
	}

	public function execute( $input ) {
		$blocks = $input['blocks'] ?? array();

		if ( ! is_array( $blocks ) ) {
			return new \WP_Error( 'invalid_input', __( '"blocks" must be an array.', 'wp-abilities-api-test' ) );
		}

		return array( 'content' => Block_Serializer::serialize( $blocks ) );
	}
}
