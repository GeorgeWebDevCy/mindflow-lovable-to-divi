<?php
/**
 * Plugin Name: Digital MindFlow Divi Importer
 * Description: WP admin importer for the Digital MindFlow Divi 5 layouts, Blog/page setup, Theme Builder templates, primary navigation, and native Divi portfolio loop fix.
 * Version: 0.1.121
 * Author: George Nicolaou
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DMF_DIVI_IMPORTER_VERSION', '0.1.121' );
define( 'DMF_DIVI_IMPORTER_FILE', __FILE__ );
define( 'DMF_DIVI_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'DMF_DIVI_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

function dmf_divi_importer_cleanup_stale_activation_entries() {
	$current_plugin = plugin_basename( DMF_DIVI_IMPORTER_FILE );
	$active_plugins = get_option( 'active_plugins', [] );

	if ( is_array( $active_plugins ) ) {
		$active_plugins = array_values(
			array_filter(
				$active_plugins,
				static function ( $plugin ) use ( $current_plugin ) {
					$plugin = (string) $plugin;

					if ( $plugin === $current_plugin ) {
						return true;
					}

					return 0 !== strpos( $plugin, 'digital-mindflow-divi-importer' );
				}
			)
		);

		update_option( 'active_plugins', $active_plugins );
	}

	if ( is_multisite() ) {
		$network_plugins = get_site_option( 'active_sitewide_plugins', [] );

		if ( is_array( $network_plugins ) ) {
			foreach ( array_keys( $network_plugins ) as $plugin ) {
				$plugin = (string) $plugin;

				if ( $plugin === $current_plugin ) {
					continue;
				}

				if ( 0 === strpos( $plugin, 'digital-mindflow-divi-importer' ) ) {
					unset( $network_plugins[ $plugin ] );
				}
			}

			update_site_option( 'active_sitewide_plugins', $network_plugins );
		}
	}
}

register_activation_hook( __FILE__, 'dmf_divi_importer_cleanup_stale_activation_entries' );
dmf_divi_importer_cleanup_stale_activation_entries();

require_once DMF_DIVI_IMPORTER_DIR . 'includes/class-dmf-divi-import-logger.php';
require_once DMF_DIVI_IMPORTER_DIR . 'includes/class-dmf-divi-import-runner.php';
require_once DMF_DIVI_IMPORTER_DIR . 'includes/class-dmf-divi-import-admin.php';

DMF_Divi_Import_Admin::boot();
