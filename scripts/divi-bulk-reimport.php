<?php

use ET\Builder\Framework\Portability\PortabilityPost;
use ET\Builder\Packages\GlobalData\GlobalData;

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run this with wp eval-file inside a WordPress install.\n";
	return;
}

$assoc_args = isset( $assoc_args ) && is_array( $assoc_args ) ? $assoc_args : [];
$args       = isset( $args ) && is_array( $args ) ? $args : [];

dmf_bootstrap_admin_user();

$exports_arg = dmf_cli_option( 'exports' );
$home_slug_arg = dmf_cli_option( 'home-slug' );

$exports_dir = isset( $assoc_args['exports'] )
	? wp_normalize_path( (string) $assoc_args['exports'] )
	: ( ! empty( $exports_arg )
		? wp_normalize_path( $exports_arg )
		: ( ! empty( $args[0] )
			? wp_normalize_path( (string) $args[0] )
			: wp_normalize_path( dirname( __DIR__ ) . '/divi-exports' ) ) );
$dry_run     = array_key_exists( 'dry-run', $assoc_args ) || dmf_cli_flag( 'dry-run' );
$skip_pages  = array_key_exists( 'skip-pages', $assoc_args ) || dmf_cli_flag( 'skip-pages' );
$skip_theme  = array_key_exists( 'skip-theme', $assoc_args ) || dmf_cli_flag( 'skip-theme' );
$skip_menu   = array_key_exists( 'skip-menu', $assoc_args ) || dmf_cli_flag( 'skip-menu' );
$home_slug   = isset( $assoc_args['home-slug'] )
	? sanitize_title( (string) $assoc_args['home-slug'] )
	: ( ! empty( $home_slug_arg )
		? sanitize_title( $home_slug_arg )
		: ( ! empty( $args[1] ) ? sanitize_title( (string) $args[1] ) : '' ) );

$page_map = [
	[
		'label' => 'Home',
		'slug'  => '__front_page__',
		'file'  => 'layout-home.json',
	],
	[
		'label' => 'Portfolio',
		'slug'  => 'portfolio',
		'file'  => 'layout-portfolio.json',
	],
	[
		'label' => 'Brand Strategy & Identity',
		'slug'  => 'brand-strategy-identity',
		'file'  => 'layout-case-study-brand-strategy-identity.json',
	],
	[
		'label' => 'Social Media Campaign',
		'slug'  => 'social-media-campaign',
		'file'  => 'layout-case-study-social-media-campaign.json',
	],
	[
		'label' => 'E-Commerce Website Redesign',
		'slug'  => 'ecommerce-website-redesign',
		'file'  => 'layout-case-study-ecommerce-website-redesign.json',
	],
	[
		'label' => 'PPC Performance Campaign',
		'slug'  => 'ppc-performance-campaign',
		'file'  => 'layout-case-study-ppc-performance-campaign.json',
	],
	[
		'label' => 'Email Automation System',
		'slug'  => 'email-automation-system',
		'file'  => 'layout-case-study-email-automation-system.json',
	],
	[
		'label' => 'AI-Powered Ad Campaign',
		'slug'  => 'ai-powered-ad-campaign',
		'file'  => 'layout-case-study-ai-powered-ad-campaign.json',
	],
];

$menu_blueprint = [
	[
		'type'  => 'page',
		'label' => 'Home',
		'slug'  => '__front_page__',
	],
	[
		'type'  => 'custom',
		'label' => 'About',
		'url'   => '/#about',
	],
	[
		'type'  => 'custom',
		'label' => 'Services',
		'url'   => '/#services',
	],
	[
		'type'  => 'page',
		'label' => 'Portfolio',
		'slug'  => 'portfolio',
	],
	[
		'type'  => 'custom',
		'label' => 'Process',
		'url'   => '/#process',
	],
	[
		'type'  => 'custom',
		'label' => 'Contact',
		'url'   => '/#contact',
	],
	[
		'type'    => 'custom',
		'label'   => 'Free Consultation',
		'url'     => '/#contact',
		'classes' => [ 'dmf-menu-cta' ],
	],
];

if ( ! is_dir( $exports_dir ) ) {
	dmf_error( "Export directory not found: {$exports_dir}" );
}

if ( ! class_exists( PortabilityPost::class ) || ! class_exists( GlobalData::class ) ) {
	dmf_error( 'Divi portability classes are not loaded. Activate Divi before running this script.' );
}

$summary = [
	'pages_updated' => [],
	'pages_missing' => [],
	'theme_updated' => [],
	'menu_updated'  => [],
];

dmf_log( $dry_run ? 'Dry run enabled. No database writes will be made.' : 'Starting Divi bulk reimport.' );
dmf_log( "Using export directory: {$exports_dir}" );

if ( ! $skip_pages ) {
	foreach ( $page_map as $item ) {
		$page = dmf_find_target_page( $item['slug'], $home_slug );

		if ( ! $page ) {
			$summary['pages_missing'][] = $item['label'];
			dmf_warn( "Skipping {$item['label']}: target page not found." );
			continue;
		}

		$export = dmf_load_export_file( $exports_dir . '/' . $item['file'], 'et_builder' );
		dmf_import_global_data( $export, $dry_run );
		dmf_import_page_layout( $page, $export, $dry_run );

		$summary['pages_updated'][] = "{$item['label']} (#{$page->ID})";
	}
}

if ( ! $skip_theme ) {
	$theme_export = dmf_load_export_file(
		$exports_dir . '/theme-builder-global-header-footer.json',
		'et_theme_builder'
	);

	dmf_import_global_data( $theme_export, $dry_run );
	$updated_theme = dmf_import_global_theme_template( $theme_export, $dry_run );
	$summary['theme_updated'] = $updated_theme;
}

if ( ! $skip_menu ) {
	$summary['menu_updated'] = dmf_sync_primary_menu( $menu_blueprint, $home_slug, $dry_run );
}

if ( ! $dry_run ) {
	dmf_flush_divi_caches();
}

dmf_log( 'Reimport complete.' );
dmf_log( 'Updated pages: ' . ( empty( $summary['pages_updated'] ) ? 'none' : implode( ', ', $summary['pages_updated'] ) ) );
dmf_log( 'Missing pages: ' . ( empty( $summary['pages_missing'] ) ? 'none' : implode( ', ', $summary['pages_missing'] ) ) );
dmf_log( 'Theme template: ' . ( empty( $summary['theme_updated'] ) ? 'none' : implode( ', ', $summary['theme_updated'] ) ) );
dmf_log( 'Primary menu: ' . ( empty( $summary['menu_updated'] ) ? 'none' : implode( ', ', $summary['menu_updated'] ) ) );

function dmf_bootstrap_admin_user(): void {
	if ( get_current_user_id() > 0 ) {
		return;
	}

	$admins = get_users(
		[
			'role__in' => [ 'administrator' ],
			'number'   => 1,
			'fields'   => 'ids',
		]
	);

	if ( ! empty( $admins[0] ) ) {
		wp_set_current_user( (int) $admins[0] );
	}
}

function dmf_cli_flag( string $name ): bool {
	$needle = '--' . ltrim( $name, '-' );

	foreach ( $_SERVER['argv'] ?? [] as $arg ) {
		if ( $needle === $arg ) {
			return true;
		}
	}

	return false;
}

function dmf_cli_option( string $name ): ?string {
	$prefix = '--' . ltrim( $name, '-' ) . '=';

	foreach ( $_SERVER['argv'] ?? [] as $arg ) {
		if ( 0 === strpos( $arg, $prefix ) ) {
			return substr( $arg, strlen( $prefix ) );
		}
	}

	return null;
}

function dmf_find_target_page( string $slug, string $home_slug = '' ): ?WP_Post {
	if ( '__front_page__' === $slug ) {
		$front_page_id = (int) get_option( 'page_on_front' );

		if ( $front_page_id > 0 ) {
			$front_page = get_post( $front_page_id );
			if ( $front_page instanceof WP_Post && 'page' === $front_page->post_type ) {
				return $front_page;
			}
		}

		$candidates = array_filter(
			array_unique(
				array_merge(
					$home_slug ? [ $home_slug ] : [],
					[ 'home', 'homepage', 'index' ]
				)
			)
		);

		foreach ( $candidates as $candidate_slug ) {
			$page = get_page_by_path( $candidate_slug, OBJECT, 'page' );
			if ( $page instanceof WP_Post ) {
				return $page;
			}
		}

		$home_pages = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		foreach ( $home_pages as $candidate_page ) {
			if (
				$candidate_page instanceof WP_Post &&
				0 === strcasecmp( $candidate_page->post_title, 'Home' )
			) {
				return $candidate_page;
			}
		}

		return null;
	}

	$page = get_page_by_path( $slug, OBJECT, 'page' );

	return $page instanceof WP_Post ? $page : null;
}

function dmf_sync_primary_menu( array $blueprint, string $home_slug, bool $dry_run ): array {
	$location  = 'primary-menu';
	$menu_name = 'Digital MindFlow Primary Navigation';
	$menu      = wp_get_nav_menu_object( $menu_name );
	$menu_id   = $menu ? (int) $menu->term_id : 0;
	$items     = [];

	if ( ! isset( get_registered_nav_menus()[ $location ] ) ) {
		dmf_warn( "Theme location '{$location}' is not registered on this install. The menu will still be created." );
	}

	dmf_log(
		sprintf(
			'%s WordPress primary navigation menu.',
			$dry_run ? 'Would sync' : 'Syncing'
		)
	);

	foreach ( $blueprint as $index => $item ) {
		if ( 'page' === ( $item['type'] ?? '' ) ) {
			$page = dmf_find_target_page( (string) ( $item['slug'] ?? '' ), $home_slug );

			if ( ! $page ) {
				dmf_warn( "Skipping menu item {$item['label']}: page not found." );
				continue;
			}

			$items[] = [
				'type'       => 'post_type',
				'title'      => (string) $item['label'],
				'position'   => $index + 1,
				'object_id'  => (int) $page->ID,
				'object'     => 'page',
				'classes'    => $item['classes'] ?? [],
			];

			continue;
		}

		$items[] = [
			'type'       => 'custom',
			'title'      => (string) $item['label'],
			'position'   => $index + 1,
			'url'        => dmf_menu_url( (string) ( $item['url'] ?? '/' ) ),
			'classes'    => $item['classes'] ?? [],
		];
	}

	if ( $dry_run ) {
		return [
			"Would sync {$menu_name}",
			"Location {$location}",
			'Items ' . count( $items ),
		];
	}

	if ( $menu_id <= 0 ) {
		$menu_id = wp_create_nav_menu( $menu_name );

		if ( is_wp_error( $menu_id ) ) {
			dmf_error( 'Failed to create navigation menu: ' . $menu_id->get_error_message() );
		}

		$menu_id = (int) $menu_id;
	}

	$existing_items = wp_get_nav_menu_items(
		$menu_id,
		[
			'post_status' => 'any',
		]
	);

	foreach ( $existing_items ?: [] as $existing_item ) {
		if ( $existing_item instanceof WP_Post ) {
			wp_delete_post( $existing_item->ID, true );
		}
	}

	$created_count = 0;

	foreach ( $items as $item ) {
		$menu_item_data = [
			'menu-item-title'    => $item['title'],
			'menu-item-position' => $item['position'],
			'menu-item-status'   => 'publish',
			'menu-item-type'     => $item['type'],
		];

		if ( 'post_type' === $item['type'] ) {
			$menu_item_data['menu-item-object-id'] = $item['object_id'];
			$menu_item_data['menu-item-object']    = $item['object'];
		} else {
			$menu_item_data['menu-item-url'] = $item['url'];
		}

		$menu_item_id = wp_update_nav_menu_item( $menu_id, 0, $menu_item_data );

		if ( is_wp_error( $menu_item_id ) ) {
			dmf_error( 'Failed to create menu item ' . $item['title'] . ': ' . $menu_item_id->get_error_message() );
		}

		if ( ! empty( $item['classes'] ) ) {
			update_post_meta( (int) $menu_item_id, '_menu_item_classes', array_values( (array) $item['classes'] ) );
		}

		$created_count++;
	}

	$locations              = get_theme_mod( 'nav_menu_locations', [] );
	$locations[ $location ] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );

	return [
		"Menu {$menu_name} #{$menu_id}",
		"Location {$location}",
		'Items ' . $created_count,
	];
}

function dmf_menu_url( string $url ): string {
	if ( preg_match( '#^https?://#i', $url ) ) {
		return esc_url_raw( $url );
	}

	if ( 0 === strpos( $url, '/#' ) ) {
		return esc_url_raw( home_url( '/' ) . substr( $url, 1 ) );
	}

	if ( 0 === strpos( $url, '#' ) ) {
		return esc_url_raw( home_url( '/' ) . $url );
	}

	return esc_url_raw( home_url( $url ) );
}

function dmf_load_export_file( string $file_path, string $expected_context ): array {
	if ( ! file_exists( $file_path ) ) {
		dmf_error( "Export file not found: {$file_path}" );
	}

	$contents = file_get_contents( $file_path );
	if ( false === $contents ) {
		dmf_error( "Unable to read export file: {$file_path}" );
	}

	$decoded = json_decode( $contents, true );
	if ( ! is_array( $decoded ) ) {
		dmf_error( "Invalid JSON in export file: {$file_path}" );
	}

	$context = $decoded['context'] ?? '';
	if ( $expected_context !== $context ) {
		dmf_error( "Unexpected context in {$file_path}. Expected {$expected_context}, got {$context}." );
	}

	return $decoded;
}

function dmf_import_global_data( array $export, bool $dry_run ): void {
	if ( $dry_run ) {
		return;
	}

	if ( ! empty( $export['global_colors'] ) ) {
		$portability = new PortabilityPost( 'et_builder' );
		$colors      = $portability->import_global_colors( $export['global_colors'] );

		if ( ! empty( $colors ) ) {
			GlobalData::set_global_colors( $colors, true );
		}
	}

	if ( ! empty( $export['global_variables'] ) ) {
		GlobalData::import_global_variables( $export['global_variables'] );
	}
}

function dmf_import_page_layout( WP_Post $page, array $export, bool $dry_run ): void {
	$content = dmf_get_single_layout_content( $export['data'] ?? [] );

	dmf_log(
		sprintf(
			'%s page %s (#%d)',
			$dry_run ? 'Would update' : 'Updating',
			$page->post_title,
			$page->ID
		)
	);

	if ( $dry_run ) {
		return;
	}

	$result = wp_update_post(
		[
			'ID'           => $page->ID,
			'post_content' => wp_slash( $content ),
		],
		true
	);

	if ( is_wp_error( $result ) ) {
		dmf_error( 'Failed to update page #' . $page->ID . ': ' . $result->get_error_message() );
	}

	update_post_meta( $page->ID, '_et_pb_use_builder', 'on' );
	update_post_meta( $page->ID, '_et_pb_use_divi_5', 'on' );
	update_post_meta( $page->ID, '_et_pb_built_for_post_type', 'page' );
	update_post_meta( $page->ID, '_et_pb_show_page_creation', 'off' );
}

function dmf_import_global_theme_template( array $export, bool $dry_run ): array {
	if ( empty( $export['templates'] ) || empty( $export['layouts'] ) ) {
		dmf_error( 'Theme Builder export is missing templates or layouts.' );
	}

	$template_export  = dmf_get_default_template_export( $export['templates'] );
	$default_template = dmf_get_existing_default_template();
	$layout_ids       = [
		'header' => 0,
		'body'   => 0,
		'footer' => 0,
	];
	$updated = [];

	foreach ( $layout_ids as $layout_type => $unused ) {
		$layout_ref = $template_export['layouts'][ $layout_type ] ?? [ 'id' => 0, 'enabled' => false ];
		$source_id  = (int) ( $layout_ref['id'] ?? 0 );

		if ( $source_id <= 0 ) {
			continue;
		}

		if ( empty( $export['layouts'][ $source_id ] ) || ! is_array( $export['layouts'][ $source_id ] ) ) {
			dmf_error( "Theme Builder layout {$source_id} for {$layout_type} is missing from export." );
		}

		$existing_layout_id = $default_template
			? (int) get_post_meta( $default_template->ID, "_et_{$layout_type}_layout_id", true )
			: 0;

		$layout_ids[ $layout_type ] = dmf_upsert_theme_builder_layout(
			$export['layouts'][ $source_id ],
			$existing_layout_id,
			$dry_run
		);

		$updated[] = ucfirst( $layout_type ) . ' #' . $layout_ids[ $layout_type ];
	}

	dmf_log( $dry_run ? 'Would update default Theme Builder template.' : 'Updating default Theme Builder template.' );

	if ( $dry_run ) {
		foreach ( dmf_find_templates_with_body_overrides( $default_template ? (int) $default_template->ID : 0 ) as $template ) {
			$updated[] = sprintf( 'Clear stale body override on template #%d', $template->ID );
		}

		return $updated;
	}

	$theme_builder_id = function_exists( 'et_theme_builder_get_theme_builder_post_id' )
		? (int) et_theme_builder_get_theme_builder_post_id( true, true )
		: 0;

	if ( $theme_builder_id <= 0 ) {
		dmf_error( 'Unable to resolve the Divi Theme Builder post.' );
	}

	$template_input = [
		'id'                 => $default_template ? $default_template->ID : 0,
		'title'              => $template_export['title'] ?? 'Imported Template',
		'autogenerated_title' => ! empty( $template_export['autogenerated_title'] ) ? '1' : '0',
		'default'            => ! empty( $template_export['default'] ) ? '1' : '0',
		'enabled'            => ! empty( $template_export['enabled'] ) ? '1' : '0',
		'use_on'             => $template_export['use_on'] ?? [],
		'exclude_from'       => $template_export['exclude_from'] ?? [],
		'layouts'            => [
			'header' => [
				'id'      => $layout_ids['header'],
				'enabled' => ! empty( $template_export['layouts']['header']['enabled'] ) ? '1' : '0',
			],
			'body'   => [
				'id'      => $layout_ids['body'],
				// Divi treats a disabled body area as a full body override even with no body layout.
				'enabled' => dmf_theme_template_area_enabled_flag( 'body', $layout_ids['body'], $template_export ),
			],
			'footer' => [
				'id'      => $layout_ids['footer'],
				'enabled' => ! empty( $template_export['layouts']['footer']['enabled'] ) ? '1' : '0',
			],
		],
	];

	$template_id = et_theme_builder_store_template( $theme_builder_id, $template_input, true );

	if ( ! $template_id ) {
		dmf_error( 'Theme Builder template could not be saved.' );
	}

	$existing_template_ids = array_map(
		'strval',
		array_map(
			'absint',
			get_post_meta( $theme_builder_id, '_et_template', false )
		)
	);

	if ( ! in_array( (string) $template_id, $existing_template_ids, true ) ) {
		add_post_meta( $theme_builder_id, '_et_template', $template_id );
	}

	$updated = array_merge( $updated, dmf_neutralize_other_theme_builder_body_overrides( (int) $template_id ) );

	return $updated;
}

function dmf_theme_template_area_enabled_flag( string $layout_type, int $layout_id, array $template_export ): string {
	if ( 'body' === $layout_type && $layout_id <= 0 ) {
		return '1';
	}

	return ! empty( $template_export['layouts'][ $layout_type ]['enabled'] ) ? '1' : '0';
}

function dmf_find_templates_with_body_overrides( int $exclude_template_id = 0 ): array {
	if ( ! defined( 'ET_THEME_BUILDER_TEMPLATE_POST_TYPE' ) ) {
		return [];
	}

	$templates = get_posts(
		[
			'post_type'      => ET_THEME_BUILDER_TEMPLATE_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		]
	);

	$matches = [];

	foreach ( $templates as $template ) {
		if ( ! $template instanceof WP_Post ) {
			continue;
		}

		if ( $exclude_template_id > 0 && (int) $template->ID === $exclude_template_id ) {
			continue;
		}

		$body_id      = (int) get_post_meta( $template->ID, '_et_body_layout_id', true );
		$body_enabled = get_post_meta( $template->ID, '_et_body_layout_enabled', true );

		if ( $body_id > 0 || '1' !== (string) $body_enabled ) {
			$matches[] = $template;
		}
	}

	return $matches;
}

function dmf_neutralize_other_theme_builder_body_overrides( int $keep_template_id ): array {
	$updated = [];

	foreach ( dmf_find_templates_with_body_overrides( $keep_template_id ) as $template ) {
		update_post_meta( $template->ID, '_et_body_layout_id', 0 );
		update_post_meta( $template->ID, '_et_body_layout_enabled', '1' );
		$updated[] = sprintf( 'Cleared stale body override on template #%d', $template->ID );
	}

	return $updated;
}

function dmf_upsert_theme_builder_layout( array $layout_export, int $existing_layout_id, bool $dry_run ): int {
	$post_type = sanitize_key( (string) ( $layout_export['post_type'] ?? '' ) );
	$title     = sanitize_text_field( (string) ( $layout_export['post_title'] ?? 'Theme Builder Layout' ) );
	$content   = dmf_get_single_layout_content( $layout_export['data'] ?? [] );

	if ( '' === $post_type ) {
		dmf_error( 'Theme Builder layout export is missing post_type.' );
	}

	if ( $dry_run ) {
		return $existing_layout_id > 0 ? $existing_layout_id : 999999;
	}

	$target_id = 0;

	if (
		$existing_layout_id > 0 &&
		get_post_type( $existing_layout_id ) === $post_type &&
		'publish' === get_post_status( $existing_layout_id )
	) {
		$result = wp_update_post(
			[
				'ID'           => $existing_layout_id,
				'post_title'   => $title,
				'post_content' => wp_slash( $content ),
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			dmf_error( 'Failed to update Theme Builder layout #' . $existing_layout_id . ': ' . $result->get_error_message() );
		}

		$target_id = $existing_layout_id;
	} else {
		$result = et_theme_builder_insert_layout(
			[
				'post_type'    => $post_type,
				'post_title'   => $title,
				'post_content' => wp_slash( $content ),
			]
		);

		if ( is_wp_error( $result ) || ! $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : 'unknown error';
			dmf_error( 'Failed to create Theme Builder layout: ' . $message );
		}

		$target_id = (int) $result;
	}

	foreach ( $layout_export['post_meta'] ?? [] as $meta_entry ) {
		if ( empty( $meta_entry['key'] ) ) {
			continue;
		}

		update_post_meta(
			$target_id,
			sanitize_text_field( (string) $meta_entry['key'] ),
			$meta_entry['value'] ?? ''
		);
	}

	update_post_meta( $target_id, '_et_pb_use_builder', 'on' );
	update_post_meta( $target_id, '_et_pb_use_divi_5', 'on' );

	return $target_id;
}

function dmf_get_single_layout_content( array $data ): string {
	$layout_values = array_values( $data );

	if ( 1 !== count( $layout_values ) || ! is_string( $layout_values[0] ) ) {
		dmf_error( 'Expected a single layout content payload in export data.' );
	}

	return $layout_values[0];
}

function dmf_get_default_template_export( array $templates ): array {
	foreach ( $templates as $template ) {
		if ( ! empty( $template['default'] ) ) {
			return $template;
		}
	}

	if ( ! empty( $templates[0] ) && is_array( $templates[0] ) ) {
		return $templates[0];
	}

	dmf_error( 'No Theme Builder template found in export.' );
}

function dmf_get_existing_default_template(): ?WP_Post {
	if ( ! defined( 'ET_THEME_BUILDER_TEMPLATE_POST_TYPE' ) ) {
		return null;
	}

	$templates = get_posts(
		[
			'post_type'      => ET_THEME_BUILDER_TEMPLATE_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_key'       => '_et_default',
			'meta_value'     => '1',
		]
	);

	return ! empty( $templates[0] ) && $templates[0] instanceof WP_Post ? $templates[0] : null;
}

function dmf_flush_divi_caches(): void {
	if ( function_exists( 'et_update_option' ) ) {
		et_update_option( 'et_pb_clear_templates_cache', true );
	}

	if ( class_exists( 'ET_Core_PageResource' ) ) {
		ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
	}
}

function dmf_log( string $message ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $message );
		return;
	}

	echo $message . "\n";
}

function dmf_warn( string $message ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		WP_CLI::warning( $message );
		return;
	}

	echo 'Warning: ' . $message . "\n";
}

function dmf_error( string $message ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $message );
	}

	wp_die( esc_html( $message ) );
}
