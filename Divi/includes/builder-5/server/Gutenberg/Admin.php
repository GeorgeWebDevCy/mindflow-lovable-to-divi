<?php
/**
 * Gutenberg: Admin Asset Loading and Portability
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\Gutenberg;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\Utility\Conditions;
use ET\Builder\VisualBuilder\Assets\AssetsUtility;
use ET\Builder\VisualBuilder\Assets\PackageBuildManager;

/**
 * Gutenberg Admin class.
 *
 * Handles asset loading and portability for Layout Block in the Gutenberg block editor.
 * Instantiated and initialized at the bottom of this file.
 *
 * @since ??
 */
class Admin {
	/**
	 * Initialize the class.
	 *
	 * Registers hooks for asset loading and portability.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function initialize(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'load_visual_builder_dependencies' ] );
		add_action( 'admin_init', [ $this, 'register_portability' ] );
	}

	/**
	 * Enqueue scripts and styles on Gutenberg block editor page.
	 *
	 * Uses PackageBuildManager to enqueue D5 assets (same as old D4 implementation).
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function load_visual_builder_dependencies(): void {
		if ( ! Conditions::is_block_editor() ) {
			return;
		}

		// Enqueue media library script so ETSelect is available in topWindow.
		et_builder_enqueue_assets_head();

		// Register vendor dependencies before PackageBuildManager enqueues scripts.
		AssetsUtility::enqueue_visual_builder_dependencies();

		wp_register_script(
			'react-tiny-mce',
			ET_BUILDER_5_URI . '/visual-builder/assets/tinymce/tinymce.min.js',
			[],
			ET_BUILDER_VERSION,
			false
		);

		// Use PackageBuildManager to enqueue D5 assets.
		PackageBuildManager::register_divi_package_builds();
		PackageBuildManager::enqueue_scripts();
		PackageBuildManager::enqueue_styles();
	}

	/**
	 * Register portability functionality for Layout Block.
	 *
	 * @since ??
	 *
	 * @return void
	 */
	public function register_portability(): void {
		// Register portability only if not already registered.
		if ( ! et_core_is_builder_used_on_current_request() ) {
			return;
		}

		et_core_portability_link(
			'et_builder',
			[
				'name' => esc_html__( 'Divi Builder', 'et_builder_5' ),
				'view' => ! is_customize_preview(),
			]
		);
	}
}

// Instantiate and initialize.
$admin = new Admin();
$admin->initialize();
