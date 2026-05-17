<?php

namespace WP_Abilities_Test;

/**
 * Pure serializer: converts a decoded JSON block array into a
 * serialized Gutenberg post_content string.
 *
 * JSON in → block HTML string out. No side-effects.
 */
class Block_Serializer {

	/**
	 * @param array $blocks Decoded array of block descriptor objects.
	 * @return string Serialized post_content ready for wp_insert_post().
	 */
	public static function serialize( array $blocks ): string {
		$parts = array();

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				error_log( sprintf( '[wp-abilities-api-test] Block at index %d is not an object — skipped.', $index ) );
				continue;
			}

			$serialized = self::serialize_one( $block );

			if ( null !== $serialized ) {
				$parts[] = $serialized;
			}
		}

		return implode( "\n\n", $parts );
	}

	private static function serialize_one( array $block ): ?string {
		$type = $block['type'] ?? '';

		switch ( $type ) {
			case 'core/heading':
				return self::heading( $block );
			case 'core/paragraph':
				return self::paragraph( $block );
			case 'core/list':
				return self::list_block( $block );
			case 'core/image':
				return self::image( $block );
			case 'core/quote':
				return self::quote( $block );
			default:
				error_log( sprintf( '[wp-abilities-api-test] Unrecognized block type "%s" — skipped.', esc_attr( $type ) ) );
				return null;
		}
	}

	private static function heading( array $block ): string {
		$level   = max( 1, min( 6, (int) ( $block['level'] ?? 2 ) ) );
		$content = wp_kses_post( $block['content'] ?? '' );
		$html    = "<h{$level} class=\"wp-block-heading\">{$content}</h{$level}>";

		return serialize_block( array(
			'blockName'    => 'core/heading',
			'attrs'        => array( 'level' => $level ),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		) );
	}

	private static function paragraph( array $block ): string {
		$content = wp_kses_post( $block['content'] ?? '' );
		$html    = "<p>{$content}</p>";

		return serialize_block( array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		) );
	}

	private static function list_block( array $block ): string {
		$ordered = ! empty( $block['ordered'] );
		$tag     = $ordered ? 'ol' : 'ul';
		$items   = array_map(
			static function ( $item ) {
				return '<li>' . wp_kses_post( (string) $item ) . '</li>';
			},
			(array) ( $block['items'] ?? array() )
		);
		$html = "<{$tag}>" . implode( '', $items ) . "</{$tag}>";

		return serialize_block( array(
			'blockName'    => 'core/list',
			'attrs'        => array( 'ordered' => $ordered ),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		) );
	}

	private static function image( array $block ): string {
		$url  = esc_url( $block['url'] ?? '' );
		$alt  = esc_attr( wp_strip_all_tags( $block['alt'] ?? '' ) );
		$html = "<figure class=\"wp-block-image\"><img src=\"{$url}\" alt=\"{$alt}\"/></figure>";

		return serialize_block( array(
			'blockName'    => 'core/image',
			'attrs'        => array( 'url' => $url, 'alt' => $alt ),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		) );
	}

	private static function quote( array $block ): string {
		$content  = wp_kses_post( $block['content'] ?? '' );
		$citation = wp_kses_post( $block['citation'] ?? '' );
		$cite     = $citation ? "<cite>{$citation}</cite>" : '';
		$html     = "<blockquote class=\"wp-block-quote\"><p>{$content}</p>{$cite}</blockquote>";

		return serialize_block( array(
			'blockName'    => 'core/quote',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		) );
	}
}
