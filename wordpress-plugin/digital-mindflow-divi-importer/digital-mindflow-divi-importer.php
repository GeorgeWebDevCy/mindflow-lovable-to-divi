<?php
/**
 * Plugin Name: Digital MindFlow Divi Importer
 * Description: WP admin importer for the Digital MindFlow Divi 5 layouts, Theme Builder template, primary navigation, and native Divi portfolio loop fix.
 * Version: 0.1.14
 * Author: George Nicolaou
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DMF_DIVI_IMPORTER_VERSION', '0.1.14' );
define( 'DMF_DIVI_IMPORTER_FILE', __FILE__ );
define( 'DMF_DIVI_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'DMF_DIVI_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once DMF_DIVI_IMPORTER_DIR . 'includes/class-dmf-divi-import-runner.php';
require_once DMF_DIVI_IMPORTER_DIR . 'includes/class-dmf-divi-import-admin.php';

DMF_Divi_Import_Admin::boot();
