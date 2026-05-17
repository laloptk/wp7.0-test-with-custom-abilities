<?php

namespace WP_Abilities_Test\Abilities;

use WP_Abilities_Test\Ability;
use WP_Abilities_Test\Block_Serializer;

class Write_Post_Ability extends Ability {

	public function get_name(): string {
		return 'wp-abilities-api-test/write-post';
	}

	public function get_label(): string {
		return __( 'Write Post', 'wp-abilities-api-test' );
	}

	public function get_description(): string {
		return __( 'Generates a draft post from a title and optional notes using WordPress AI.', 'wp-abilities-api-test' );
	}

	public function get_category(): string {
		return 'content';
	}

	public function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'     => array(
					'type'        => 'string',
					'description' => 'The title of the post to generate.',
				),
				'notes'     => array(
					'type'        => 'string',
					'description' => 'Optional context or notes for the AI.',
				),
				'max_words' => array(
					'type'        => 'integer',
					'description' => 'Approximate maximum word count for the generated post. Defaults to 300.',
					'minimum'     => 100,
					'maximum'     => 700,
					'default'     => 300,
				),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

	public function get_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array(
					'type'        => 'integer',
					'description' => 'The ID of the created draft post.',
				),
				'post_url' => array(
					'type'        => 'string',
					'description' => 'The edit URL of the created post.',
				),
				'title'    => array(
					'type' => 'string',
				),
			),
		);
	}

	public function execute( $input ) {
		$title     = sanitize_text_field( $input['title'] ?? '' );
		$notes     = sanitize_textarea_field( $input['notes'] ?? '' );
		$max_words = max( 100, min( 700, (int) ( $input['max_words'] ?? 300 ) ) );

		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_title', __( 'A post title is required.', 'wp-abilities-api-test' ) );
		}

		// Step 1: AI generation — returns raw JSON string.
		$json = $this->generate_content( $title, $notes, $max_words );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		// Step 2: Serialize JSON block descriptors to Gutenberg post_content.
		$content = $this->json_to_blocks( $json );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		// Step 3: Save — unchanged.
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'post_id'  => $post_id,
			'post_url' => get_edit_post_link( $post_id, 'raw' ),
			'title'    => $title,
		);
	}

	private function generate_content( string $title, string $notes, int $max_words ): string|\WP_Error {
		if ( ! function_exists( 'WordPress\AI\get_ai_service' ) ) {
			return new \WP_Error(
				'ai_plugin_missing',
				__( 'The WordPress AI plugin is not active.', 'wp-abilities-api-test' )
			);
		}

		if ( ! \WordPress\AI\has_ai_credentials() ) {
			return new \WP_Error(
				'ai_no_credentials',
				__( 'No AI credentials are configured. Please check the AI plugin settings.', 'wp-abilities-api-test' )
			);
		}

		// Format instructions come first so the model commits to JSON output
		// before reading the writing task.
		$prompt =
			"Respond with ONLY a valid JSON array — no markdown, no explanation, no code fences.\n" .
			"Start your response with [ and end with ].\n\n" .
			"Each array element must be one of these exact shapes:\n" .
			'{"type":"core/heading","level":2,"content":"Your heading text"}' . "\n" .
			'{"type":"core/paragraph","content":"Your paragraph text"}' . "\n" .
			'{"type":"core/list","ordered":false,"items":["First item","Second item"]}' . "\n" .
			'{"type":"core/quote","content":"Quote text","citation":"Author name"}' . "\n\n" .
			sprintf( 'Task: write a blog post titled "%s". Keep the total post under %d words.', $title, $max_words );

		if ( ! empty( $notes ) ) {
			$prompt .= "\nAdditional instructions: " . $notes;
		}

		// max_words * 2 gives enough token headroom for both the prose content
		// and the JSON structural keys (type, content, items, etc.) that wrap it.
		$max_tokens = $max_words * 2;

		$builder = \WordPress\AI\get_ai_service()->create_textgen_prompt(
			$prompt,
			array(
				'system_instruction' => 'You are a blog writing assistant. Always respond with a raw JSON array only — no markdown, no explanation, no code fences. Keep your response concise so the JSON array is always complete.',
				'max_tokens'         => $max_tokens,
			)
		);

		if ( ! $builder->is_supported_for_text_generation() ) {
			return new \WP_Error(
				'ai_unsupported',
				__( 'No AI model is available for text generation.', 'wp-abilities-api-test' )
			);
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (string) $result;
	}

	private function json_to_blocks( string $raw ): string|\WP_Error {
		$cleaned = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
		$cleaned = preg_replace( '/\s*```$/i', '', $cleaned );

		$blocks = json_decode( $cleaned, true );

		if ( ! is_array( $blocks ) ) {
			error_log( '[wp-abilities-api-test] AI returned malformed JSON: ' . $raw );
			return new \WP_Error(
				'invalid_json',
				__( 'AI returned malformed JSON — could not parse block data.', 'wp-abilities-api-test' )
			);
		}

		return Block_Serializer::serialize( $blocks );
	}
}
