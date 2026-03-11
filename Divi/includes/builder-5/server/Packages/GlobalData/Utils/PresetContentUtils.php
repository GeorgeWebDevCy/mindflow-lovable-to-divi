<?php
/**
 * Preset Content Utilities
 *
 * Shared utilities for applying preset IDs to block content.
 * Used by both migration path and regular import path.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Packages\GlobalData\Utils;

use ET\Builder\Packages\GlobalData\GlobalPreset;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Preset Content Utilities
 *
 * @since ??
 */
class PresetContentUtils {

	/**
	 * Apply default imported preset IDs to block content.
	 *
	 * Only assigns presets when modulePreset is missing, empty, or "default".
	 *
	 * @param string $post_content The post content (Gutenberg blocks).
	 * @param array  $default_imported_presets Default imported module preset IDs. Format: [ 'divi/section' => [ 'presetId' => 'abc123', 'moduleName' => 'divi/section' ], ... ].
	 * @return string The updated post content.
	 */
	public static function apply_default_imported_presets_to_content( string $post_content, array $default_imported_presets ): string {
		if ( empty( $post_content ) || empty( $default_imported_presets ) ) {
			return $post_content;
		}

		$blocks = parse_blocks( $post_content );

		if ( empty( $blocks ) ) {
			return $post_content;
		}

		$blocks = self::_apply_default_imported_presets_to_blocks( $blocks, $default_imported_presets );

		return serialize_blocks( $blocks );
	}

	/**
	 * Recursively apply default imported preset IDs to blocks.
	 *
	 * @param array $blocks Blocks array.
	 * @param array $default_imported_presets Default imported module preset IDs.
	 * @return array Updated blocks array.
	 */
	private static function _apply_default_imported_presets_to_blocks( array $blocks, array $default_imported_presets ): array {
		foreach ( $blocks as &$block ) {
			$block_name = $block['blockName'] ?? '';

			if ( ! empty( $block_name ) ) {
				if ( isset( $default_imported_presets[ $block_name ] ) ) {
					$attrs              = $block['attrs'] ?? [];
					$module_preset_attr = $attrs['modulePreset'] ?? '';

					$normalized = GlobalPreset::normalize_preset_stack( $module_preset_attr );

					if ( empty( $normalized ) ) {
						$block['attrs']['modulePreset'] = $default_imported_presets[ $block_name ]['presetId'];
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::_apply_default_imported_presets_to_blocks(
					$block['innerBlocks'],
					$default_imported_presets
				);
			}
		}

		return $blocks;
	}
}
