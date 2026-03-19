<?php

use ET\Builder\Framework\Portability\PortabilityPost;
use ET\Builder\Packages\GlobalData\GlobalData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DMF_Divi_Import_Runner {

	private $exports_dir;

	private $warnings = [];

	private $page_map = [
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
	];

	private $menu_blueprint = [
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

	public function __construct( $exports_dir ) {
		$this->exports_dir = wp_normalize_path( (string) $exports_dir );
	}

	public function get_exports_dir() {
		return $this->exports_dir;
	}

	public function get_expected_export_files() {
		$files = wp_list_pluck( $this->page_map, 'file' );
		$files[] = 'layout-global-header.json';
		$files[] = 'layout-global-footer.json';
		$files[] = 'theme-builder-global-header-footer.json';

		return array_values( array_unique( $files ) );
	}

	public function get_missing_export_files() {
		$missing = [];

		foreach ( $this->get_expected_export_files() as $file ) {
			if ( ! file_exists( $this->exports_dir . '/' . $file ) ) {
				$missing[] = $file;
			}
		}

		return $missing;
	}

	public function is_divi_ready() {
		return class_exists( PortabilityPost::class ) &&
			class_exists( GlobalData::class ) &&
			function_exists( 'et_theme_builder_store_template' ) &&
			function_exists( 'et_theme_builder_insert_layout' );
	}

	public function run( array $args = [] ) {
		$this->warnings = [];
		$this->log(
			'info',
			'Import run started.',
			[
				'dry_run'              => ! empty( $args['dry_run'] ),
				'include_pages'        => ! empty( $args['include_pages'] ),
				'include_theme'        => ! empty( $args['include_theme'] ),
				'include_menu'         => ! empty( $args['include_menu'] ),
				'create_missing_pages' => ! empty( $args['create_missing_pages'] ),
				'home_slug'            => sanitize_title( (string) ( $args['home_slug'] ?? '' ) ),
				'exports_dir'          => $this->exports_dir,
			]
		);

		if ( ! is_dir( $this->exports_dir ) ) {
			$this->log( 'error', 'Bundled export directory not found.', [ 'exports_dir' => $this->exports_dir ] );
			throw new RuntimeException( 'Bundled export directory not found.' );
		}

		$missing_files = $this->get_missing_export_files();
		if ( ! empty( $missing_files ) ) {
			$this->log( 'error', 'Bundled export files are missing.', [ 'missing_files' => $missing_files ] );
			throw new RuntimeException(
				'Bundled export files are missing: ' . implode( ', ', $missing_files )
			);
		}

		if ( ! $this->is_divi_ready() ) {
			$this->log( 'error', 'Divi 5 portability classes are not loaded.' );
			throw new RuntimeException( 'Divi 5 portability classes are not loaded. Activate Divi before running the importer.' );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$dry_run       = ! empty( $args['dry_run'] );
		$include_pages = ! empty( $args['include_pages'] );
		$include_theme = ! empty( $args['include_theme'] );
		$include_menu  = ! empty( $args['include_menu'] );
		$create_missing_pages = ! empty( $args['create_missing_pages'] );
		$home_slug     = sanitize_title( (string) ( $args['home_slug'] ?? '' ) );

		$summary = [
			'dry_run'                 => $dry_run,
			'pages_updated'           => [],
			'pages_created'           => [],
			'pages_missing'           => [],
			'portfolio_loops_updated' => [],
			'portfolio_loops_missing' => [],
			'theme_updated'           => [],
			'menu_updated'            => [],
			'warnings'                => [],
		];

		if ( $include_pages ) {
			foreach ( $this->page_map as $item ) {
				$page = $this->find_target_page( $item['slug'], $home_slug, $item['label'] );

				if ( ! $page ) {
					if ( $create_missing_pages ) {
						$summary['pages_created'][] = sprintf(
							'%s (slug: %s)%s',
							$item['label'],
							$this->target_slug( $item['slug'], $home_slug ),
							$dry_run ? ' [dry run]' : ''
						);

						if ( $dry_run ) {
							continue;
						}

						$page = $this->create_target_page( $item, $home_slug );
					}

					if ( ! $page ) {
						$summary['pages_missing'][] = $item['label'];
						continue;
					}
				}

				$export = $this->load_export_file( $this->exports_dir . '/' . $item['file'], 'et_builder' );
				$this->import_global_data( $export, $dry_run );
				$this->import_page_layout( $page, $export, $dry_run );

				$summary['pages_updated'][] = sprintf( '%s (#%d)', $item['label'], $page->ID );
			}

			$portfolio_fix_summary               = $this->apply_portfolio_loop_fix(
				[
					'dry_run' => $dry_run,
					'home_slug' => $home_slug,
				]
			);
			$summary['portfolio_loops_updated'] = $portfolio_fix_summary['updated'];
			$summary['portfolio_loops_missing'] = $portfolio_fix_summary['missing'];
		}

		if ( $include_theme ) {
			$theme_export = $this->load_export_file(
				$this->exports_dir . '/theme-builder-global-header-footer.json',
				'et_theme_builder'
			);

			$this->import_global_data( $theme_export, $dry_run );
			$summary['theme_updated'] = $this->import_global_theme_template( $theme_export, $dry_run );
		}

		if ( $include_menu ) {
			$summary['menu_updated'] = $this->sync_primary_menu( $home_slug, $dry_run, $create_missing_pages );
		}

		if ( ! $dry_run ) {
			$this->flush_divi_caches();
		}

		$summary['warnings'] = $this->warnings;
		$this->log( 'info', 'Import run completed.', $summary );

		return $summary;
	}

	public function fix_portfolio_loops( array $args = [] ) {
		$this->warnings = [];
		$this->log(
			'info',
			'Portfolio loop fix started.',
			[
				'dry_run'   => ! empty( $args['dry_run'] ),
				'home_slug' => sanitize_title( (string) ( $args['home_slug'] ?? '' ) ),
			]
		);

		$summary = $this->apply_portfolio_loop_fix( $args );

		if ( empty( $args['dry_run'] ) && ( ! isset( $args['flush_cache'] ) || ! empty( $args['flush_cache'] ) ) ) {
			$this->flush_divi_caches();
		}

		$summary['warnings'] = $this->warnings;
		$this->log( 'info', 'Portfolio loop fix completed.', $summary );

		return $summary;
	}

	public function refresh_portfolio_single_template( array $args = [] ) {
		$this->warnings = [];
		$this->log(
			'info',
			'Portfolio single template refresh started.',
			[
				'dry_run' => ! empty( $args['dry_run'] ),
			]
		);

		$dry_run = ! empty( $args['dry_run'] );
		$summary = $this->upsert_portfolio_single_theme_template( $dry_run );

		if ( ! $dry_run && ( ! isset( $args['flush_cache'] ) || ! empty( $args['flush_cache'] ) ) ) {
			$this->flush_divi_caches();
		}

		$summary['warnings'] = $this->warnings;
		$this->log( 'info', 'Portfolio single template refresh completed.', $summary );

		return $summary;
	}

	public function capture_header_diagnostics( array $args = [] ) {
		$home_slug    = sanitize_title( (string) ( $args['home_slug'] ?? '' ) );
		$diagnostics  = [
			'site'               => [
				'home_url'          => home_url( '/' ),
				'show_on_front'     => get_option( 'show_on_front' ),
				'page_on_front'     => (int) get_option( 'page_on_front' ),
				'admin_bar_showing' => is_admin_bar_showing(),
			],
			'divi_theme_options' => $this->get_current_divi_header_theme_options(),
			'theme_builder'      => $this->get_header_theme_builder_diagnostics(),
			'home_page'          => $this->get_home_page_header_diagnostics( $home_slug ),
		];

		$this->log( 'info', 'Header diagnostics captured.', $diagnostics );

		return $diagnostics;
	}

	private function warn( $message ) {
		$this->warnings[] = (string) $message;
		$this->log( 'warning', (string) $message );
	}

	private function log( $level, $message, array $context = [] ) {
		if ( class_exists( 'DMF_Divi_Import_Logger' ) ) {
			DMF_Divi_Import_Logger::log( $level, $message, $context );
		}
	}

	private function get_current_divi_header_theme_options() {
		$options = [];

		foreach ( $this->get_desired_divi_header_theme_options() as $option_name => $desired_value ) {
			$options[ $option_name ] = [
				'current' => $this->get_divi_theme_option( $option_name, null ),
				'desired' => $desired_value,
			];
		}

		return $options;
	}

	private function get_header_theme_builder_diagnostics() {
		$default_template = $this->get_existing_default_template();
		$header_layout_id = $default_template ? (int) get_post_meta( $default_template->ID, '_et_header_layout_id', true ) : 0;
		$header_enabled   = $default_template ? get_post_meta( $default_template->ID, '_et_header_layout_enabled', true ) : '';
		$header_layout    = $header_layout_id > 0 ? get_post( $header_layout_id ) : null;
		$blocks           = [];

		if ( $header_layout instanceof WP_Post && function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( (string) $header_layout->post_content );
		}

		return $this->filter_empty_diagnostic_values(
			[
				'default_template'      => $this->summarize_post( $default_template ),
				'header_layout_id'      => $header_layout_id,
				'header_layout_enabled' => $header_enabled,
				'header_layout_post'    => $this->summarize_post( $header_layout ),
				'parsed_block_count'    => is_array( $blocks ) ? count( $blocks ) : 0,
				'admin_labels'          => $this->collect_divi_admin_labels( $blocks, 20 ),
				'global_header_section' => $this->summarize_divi_block( $this->find_divi_block_by_admin_label( $blocks, 'Global Header Section' ) ),
				'header_row'            => $this->summarize_divi_block( $this->find_divi_block_by_admin_label( $blocks, 'Header Row' ) ),
				'primary_navigation'    => $this->summarize_divi_block( $this->find_divi_block_by_admin_label( $blocks, 'Primary Navigation' ) ),
			]
		);
	}

	private function get_home_page_header_diagnostics( $home_slug ) {
		$page   = $this->find_target_page( '__front_page__', $home_slug, 'Home' );
		$blocks = [];

		if ( $page instanceof WP_Post && function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( (string) $page->post_content );
		}

		return $this->filter_empty_diagnostic_values(
			[
				'page'              => $this->summarize_post( $page ),
				'page_meta'         => $page instanceof WP_Post
					? [
						'_et_pb_use_builder'         => get_post_meta( $page->ID, '_et_pb_use_builder', true ),
						'_et_pb_built_for_post_type' => get_post_meta( $page->ID, '_et_pb_built_for_post_type', true ),
						'_et_pb_page_layout'         => get_post_meta( $page->ID, '_et_pb_page_layout', true ),
						'_wp_page_template'          => get_post_meta( $page->ID, '_wp_page_template', true ),
					]
					: [],
				'parsed_block_count' => is_array( $blocks ) ? count( $blocks ) : 0,
				'admin_labels'       => $this->collect_divi_admin_labels( $blocks, 24 ),
				'hero_section'       => $this->summarize_divi_block( $this->find_divi_block_by_admin_label( $blocks, 'Home Hero Section' ) ),
				'hero_row'           => $this->summarize_divi_block( $this->find_divi_block_by_admin_label( $blocks, 'Hero Row' ) ),
			]
		);
	}

	private function summarize_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return [];
		}

		return [
			'id'        => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'status'    => (string) $post->post_status,
			'slug'      => (string) $post->post_name,
			'title'     => (string) $post->post_title,
		];
	}

	private function summarize_divi_block( $block ) {
		if ( ! is_array( $block ) ) {
			return [];
		}

		$attrs   = (array) ( $block['attrs'] ?? [] );
		$summary = [
			'block_name'        => (string) ( $block['blockName'] ?? '' ),
			'admin_label'       => $this->get_divi_block_admin_label( $block ),
			'custom_attributes' => $this->extract_custom_attributes_map( $attrs ),
			'spacing'           => $this->get_array_path( $attrs, [ 'module', 'decoration', 'spacing', 'desktop', 'value' ], [] ),
			'background'        => $this->filter_empty_diagnostic_values(
				[
					'value'  => $this->get_array_path( $attrs, [ 'module', 'decoration', 'background', 'desktop', 'value', 'color' ], '' ),
					'sticky' => $this->get_array_path( $attrs, [ 'module', 'decoration', 'background', 'desktop', 'sticky', 'color' ], '' ),
				]
			),
			'sticky'            => $this->get_array_path( $attrs, [ 'module', 'decoration', 'sticky', 'desktop', 'value' ], [] ),
			'transition'        => $this->get_array_path( $attrs, [ 'module', 'decoration', 'transition', 'desktop', 'value' ], [] ),
		];

		if ( 'divi/menu' === ( $block['blockName'] ?? '' ) ) {
			$summary['logo_sizing'] = $this->get_array_path( $attrs, [ 'logo', 'decoration', 'sizing', 'desktop', 'value' ], [] );
			$summary['menu_colors'] = $this->filter_empty_diagnostic_values(
				[
					'menu_text'          => [
						'value'  => $this->get_array_path( $attrs, [ 'menu', 'decoration', 'font', 'font', 'desktop', 'value', 'color' ], '' ),
						'sticky' => $this->get_array_path( $attrs, [ 'menu', 'decoration', 'font', 'font', 'desktop', 'sticky', 'color' ], '' ),
					],
					'active_link'        => [
						'value'  => $this->get_array_path( $attrs, [ 'menu', 'advanced', 'activeLinkColor', 'desktop', 'value' ], '' ),
						'sticky' => $this->get_array_path( $attrs, [ 'menu', 'advanced', 'activeLinkColor', 'desktop', 'sticky' ], '' ),
					],
					'mobile_menu_text'   => [
						'value'  => $this->get_array_path( $attrs, [ 'menuMobile', 'decoration', 'font', 'font', 'desktop', 'value', 'color' ], '' ),
						'sticky' => $this->get_array_path( $attrs, [ 'menuMobile', 'decoration', 'font', 'font', 'desktop', 'sticky', 'color' ], '' ),
					],
					'hamburger_icon'     => [
						'value'  => $this->get_array_path( $attrs, [ 'hamburgerMenuIcon', 'decoration', 'font', 'font', 'desktop', 'value', 'color' ], '' ),
						'sticky' => $this->get_array_path( $attrs, [ 'hamburgerMenuIcon', 'decoration', 'font', 'font', 'desktop', 'sticky', 'color' ], '' ),
					],
					'dropdown_link_text' => $this->get_array_path( $attrs, [ 'menuDropdown', 'decoration', 'font', 'font', 'desktop', 'value', 'color' ], '' ),
				]
			);
		}

		return $this->filter_empty_diagnostic_values( $summary );
	}

	private function extract_custom_attributes_map( array $attrs ) {
		$entries = $this->get_array_path( $attrs, [ 'module', 'decoration', 'attributes', 'desktop', 'value', 'attributes' ], [] );
		$mapped  = [];

		if ( ! is_array( $entries ) ) {
			return $mapped;
		}

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name  = trim( (string) ( $entry['name'] ?? '' ) );
			$value = (string) ( $entry['value'] ?? '' );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$mapped[ $name ] = 'style' === $name
				? [
					'raw'    => $value,
					'parsed' => $this->parse_inline_style_string( $value ),
				]
				: $value;
		}

		return $mapped;
	}

	private function parse_inline_style_string( $style ) {
		$styles = [];

		foreach ( explode( ';', (string) $style ) as $declaration ) {
			$declaration = trim( $declaration );

			if ( '' === $declaration || false === strpos( $declaration, ':' ) ) {
				continue;
			}

			list( $property, $value ) = array_map( 'trim', explode( ':', $declaration, 2 ) );

			if ( '' === $property || '' === $value ) {
				continue;
			}

			$styles[ $property ] = $value;
		}

		return $styles;
	}

	private function get_array_path( array $source, array $path, $default = null ) {
		$value = $source;

		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	private function filter_empty_diagnostic_values( array $values ) {
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = $this->filter_empty_diagnostic_values( $value );
			}

			if ( [] === $value || null === $value || '' === $value ) {
				unset( $values[ $key ] );
				continue;
			}

			$values[ $key ] = $value;
		}

		return $values;
	}

	private function collect_divi_admin_labels( array $blocks, $limit = 20 ) {
		$labels = [];
		$this->append_divi_admin_labels( $blocks, $labels, (int) $limit );

		return $labels;
	}

	private function append_divi_admin_labels( array $blocks, array &$labels, $limit ) {
		foreach ( $blocks as $block ) {
			if ( count( $labels ) >= $limit ) {
				return;
			}

			if ( ! is_array( $block ) ) {
				continue;
			}

			$admin_label = $this->get_divi_block_admin_label( $block );

			if ( '' !== $admin_label ) {
				$labels[] = $admin_label;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->append_divi_admin_labels( $block['innerBlocks'], $labels, $limit );
			}
		}
	}

	private function find_divi_block_by_admin_label( array $blocks, $label ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( $label === $this->get_divi_block_admin_label( $block ) ) {
				return $block;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = $this->find_divi_block_by_admin_label( $block['innerBlocks'], $label );

				if ( ! empty( $found ) ) {
					return $found;
				}
			}
		}

		return [];
	}

	private function find_target_page( $slug, $home_slug = '', $title = '' ) {
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

			return $this->find_page_by_title( $title ? $title : 'Home' );
		}

		$page = get_page_by_path( $slug, OBJECT, 'page' );

		if ( $page instanceof WP_Post ) {
			return $page;
		}

		return $title ? $this->find_page_by_title( $title ) : null;
	}

	private function find_page_by_title( $title ) {
		$title = trim( (string) $title );

		if ( '' === $title ) {
			return null;
		}

		$pages = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => [ 'publish', 'private', 'draft', 'pending', 'future' ],
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		foreach ( $pages as $page ) {
			if ( $page instanceof WP_Post && 0 === strcasecmp( $page->post_title, $title ) ) {
				return $page;
			}
		}

		return null;
	}

	private function target_slug( $slug, $home_slug = '' ) {
		if ( '__front_page__' === $slug ) {
			return $home_slug ? $home_slug : 'home';
		}

		return sanitize_title( (string) $slug );
	}

	private function create_target_page( array $item, $home_slug = '' ) {
		$title = sanitize_text_field( (string) ( $item['label'] ?? 'Imported Page' ) );
		$slug  = $this->target_slug( (string) ( $item['slug'] ?? '' ), $home_slug );

		$result = wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => '',
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException(
				'Failed to create page "' . $title . '": ' . $result->get_error_message()
			);
		}

		$page = get_post( (int) $result );

		if ( ! $page instanceof WP_Post ) {
			throw new RuntimeException( 'Created page could not be loaded: ' . $title );
		}

		$this->log(
			'info',
			'Target page created.',
			[
				'label'   => $title,
				'page_id' => (int) $page->ID,
				'slug'    => $slug,
			]
		);

		if ( '__front_page__' === ( $item['slug'] ?? '' ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) $page->ID );
		}

		update_post_meta( $page->ID, '_et_pb_page_layout', 'et_full_width_page' );
		update_post_meta( $page->ID, '_wp_page_template', 'default' );

		return $page;
	}

	private function load_export_file( $file_path, $expected_context ) {
		if ( ! file_exists( $file_path ) ) {
			throw new RuntimeException( 'Export file not found: ' . $file_path );
		}

		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			throw new RuntimeException( 'Unable to read export file: ' . $file_path );
		}

		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'Invalid JSON in export file: ' . $file_path );
		}

		$context = $decoded['context'] ?? '';
		if ( $expected_context !== $context ) {
			throw new RuntimeException(
				sprintf(
					'Unexpected portability context in %1$s. Expected %2$s, got %3$s.',
					$file_path,
					$expected_context,
					$context
				)
			);
		}

		return $decoded;
	}

	private function import_global_data( array $export, $dry_run ) {
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

	private function import_page_layout( WP_Post $page, array $export, $dry_run ) {
		$content = $this->normalize_imported_page_layout_content(
			$page,
			$this->get_single_layout_content( $export['data'] ?? [] )
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
			throw new RuntimeException(
				'Failed to update page #' . $page->ID . ': ' . $result->get_error_message()
			);
		}

		$this->configure_divi_page_meta( (int) $page->ID );
		$this->log(
			'info',
			'Page layout imported.',
			[
				'page_id'    => (int) $page->ID,
				'page_title' => (string) $page->post_title,
				'page_slug'  => (string) $page->post_name,
			]
		);
	}

	private function apply_portfolio_loop_fix( array $args = [] ) {
		$dry_run   = ! empty( $args['dry_run'] );
		$home_slug = sanitize_title( (string) ( $args['home_slug'] ?? '' ) );
		$summary   = [
			'updated' => [],
			'missing' => [],
		];
		$targets   = [
			[
				'label'   => 'Home',
				'slug'    => '__front_page__',
				'context' => 'home',
			],
			[
				'label'   => 'Portfolio',
				'slug'    => 'portfolio',
				'context' => 'portfolio',
			],
		];

		foreach ( $targets as $target ) {
			$page = $this->find_target_page( $target['slug'], $home_slug, $target['label'] );

			if ( ! $page instanceof WP_Post ) {
				$summary['missing'][] = $target['label'];
				$this->log(
					'warning',
					'Portfolio loop target page missing.',
					[
						'label'   => (string) $target['label'],
						'context' => (string) $target['context'],
					]
				);
				continue;
			}

			$fix_state = $this->apply_portfolio_loop_template_to_page(
				$page,
				(string) $target['context'],
				$dry_run
			);

			if ( 'missing-section' === $fix_state ) {
				$this->warn(
					sprintf(
						'Could not locate the static portfolio section on %1$s (#%2$d).',
						$target['label'],
						$page->ID
					)
				);
				continue;
			}

			if ( 'unchanged' === $fix_state ) {
				$summary['updated'][] = sprintf( '%s (#%d) already uses the native Divi portfolio loop', $target['label'], $page->ID );
				$this->log(
					'info',
					'Portfolio loop already up to date.',
					[
						'label'   => (string) $target['label'],
						'page_id' => (int) $page->ID,
						'context' => (string) $target['context'],
					]
				);
				continue;
			}

			$summary['updated'][] = sprintf(
				'%s (#%d)%s',
				$target['label'],
				$page->ID,
				$dry_run ? ' [dry run]' : ''
			);
			$this->log(
				'info',
				'Portfolio loop applied to page.',
				[
					'label'   => (string) $target['label'],
					'page_id' => (int) $page->ID,
					'context' => (string) $target['context'],
					'dry_run' => $dry_run,
				]
			);
		}

		return $summary;
	}

	private function apply_portfolio_loop_template_to_page( WP_Post $page, $context, $dry_run ) {
		$current_content = (string) $page->post_content;
		$replacement     = $this->build_portfolio_loop_section( (string) $context );

		if ( false !== strpos( $current_content, $replacement ) ) {
			return 'unchanged';
		}

		$content = $this->replace_existing_portfolio_loop_markup(
			$current_content,
			(string) $context,
			$replacement
		);

		if ( null === $content ) {
			return 'missing-section';
		}

		if ( $dry_run ) {
			return 'updated';
		}

		$result = wp_update_post(
			[
				'ID'           => $page->ID,
				'post_content' => wp_slash( $content ),
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException(
				'Failed to update portfolio loop on page #' . $page->ID . ': ' . $result->get_error_message()
			);
		}

		$this->configure_divi_page_meta( (int) $page->ID );

		return 'updated';
	}

	private function replace_existing_portfolio_loop_markup( $content, $context, $replacement ) {
		$content            = (string) $content;
		$context            = sanitize_key( (string) $context );
		$replacement        = (string) $replacement;
		$legacy_shortcode   = sprintf( '[dmf_portfolio_loop context="%s"]', $context );
		$legacy_block_regex = '#<!--\s+wp:shortcode\s+-->\s*' . preg_quote( $legacy_shortcode, '#' ) . '\s*<!--\s+/wp:shortcode\s+-->#';
		$replaced_content   = preg_replace( $legacy_block_regex, $replacement, $content, 1, $replacements );

		if ( null !== $replaced_content && 1 === (int) $replacements ) {
			return $replaced_content;
		}

		if ( false !== strpos( $content, $legacy_shortcode ) ) {
			return str_replace( $legacy_shortcode, $replacement, $content );
		}

		return $this->replace_divi_section_by_label(
			$content,
			'Portfolio Projects Section',
			$replacement
		);
	}

	private function build_portfolio_loop_section( $context ) {
		$context = 'home' === sanitize_key( (string) $context ) ? 'home' : 'portfolio';
		$blocks  = [
			$this->build_code_module( 'Portfolio Loop Runtime', $this->build_portfolio_loop_runtime_markup(), 'dmf-portfolio-loop-runtime' ),
			$this->build_portfolio_loop_row( $context ),
		];

		if ( 'home' === $context ) {
			array_splice( $blocks, 1, 0, [ $this->build_portfolio_intro_row( $context ) ] );
		}

		if ( 'home' === $context ) {
			$blocks[] = $this->build_portfolio_archive_button_row();
		} else {
			$blocks[] = $this->build_portfolio_archive_cta_row();
		}

		return $this->render_divi_block(
			'section',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta' => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Projects Section',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-loop-shell dmf-portfolio-loop-shell--' . $context,
							]
						),
					],
				],
			],
			implode( "\n", $blocks )
		);
	}

	private function build_portfolio_intro_row( $context ) {
		$text_block = $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta' => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Intro',
							],
						],
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => $this->build_portfolio_intro_markup( $context ),
						],
					],
				],
			]
		);

		$column_block = $this->render_divi_block(
			'column',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'     => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Intro Column',
							],
						],
					],
					'advanced' => [
						'type' => [
							'desktop' => [
								'value' => '4_4',
							],
						],
					],
				],
			],
			$text_block
		);

		return $this->render_divi_block(
			'row',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'     => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Intro Row',
							],
						],
					],
					'advanced' => [
						'columnStructure' => [
							'desktop' => [
								'value' => '4_4',
							],
						],
					],
				],
			],
			$column_block
		);
	}

	private function build_portfolio_loop_row( $context ) {
		$context         = 'home' === sanitize_key( (string) $context ) ? 'home' : 'portfolio';
		$item_style      = 'home' === $context
			? [
				'display'        => 'flex',
				'flex-direction' => 'column',
				'gap'            => '1rem',
				'flex'           => '1 1 20rem',
				'min-width'      => '18rem',
				'max-width'      => 'calc((100% - 3rem) / 3)',
				'padding'        => '1.5rem',
				'background'     => 'var(--gcid-dmf-card, #edeced)',
				'border'         => '0.0625rem solid var(--gcid-dmf-border, #a1a5a4)',
				'border-radius'  => 'var(--gvid-dmf-radius-lg)',
				'box-shadow'     => '0 1rem 2.25rem color-mix(in srgb, var(--gcid-dmf-primary, #2b5b5b) 8%, transparent)',
				'box-sizing'     => 'border-box',
			]
			: [
				'display'        => 'flex',
				'flex-direction' => 'column',
				'gap'            => '1rem',
				'flex'           => '1 1 calc((100% - 2rem) / 2)',
				'min-width'      => '20rem',
				'max-width'      => 'none',
				'padding'        => '1rem',
				'background'     => 'var(--gcid-dmf-card, #edeced)',
				'border'         => '0.0625rem solid var(--gcid-dmf-border, #a1a5a4)',
				'border-radius'  => 'var(--gvid-dmf-radius-lg)',
				'box-shadow'     => '0 1rem 2.25rem color-mix(in srgb, var(--gcid-dmf-primary, #2b5b5b) 8%, transparent)',
				'box-sizing'     => 'border-box',
			];
		$container_style = [
			'display'         => 'flex',
			'flex-wrap'       => 'wrap',
			'align-items'     => 'stretch',
			'justify-content' => 'flex-start',
			'gap'             => 'home' === $context ? '1.5rem' : '2rem',
			'width'           => '100%',
		];
		$row_attributes  = 'home' === $context
			? []
			: $this->build_custom_attributes(
				[
					'class' => 'dmf-portfolio-loop-row dmf-portfolio-loop-row--portfolio',
					'style' => $this->build_inline_style(
						[
							'width'     => 'min(96rem, calc(100% - 2rem))',
							'max-width' => '96rem',
							'margin'    => '0 auto',
						]
					),
				]
			);

		$loop_group_block = $this->render_divi_block(
			'group',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Loop Group',
							],
						],
					],
					'advanced'   => [
						'loop' => [
							'desktop' => [
								'value' => [
									'enable'             => 'on',
									'queryType'          => 'post_types',
									'subTypes'           => [
										[
											'value' => 'portfolio',
										],
									],
									'orderBy'            => 'date',
									'order'              => 'descending',
									'postPerPage'        => (string) $this->get_portfolio_loop_posts_per_page( $context ),
									'ignoreStickysPost'  => 'on',
									'excludeCurrentPost' => 'off',
									'loopId'             => 'home' === $context ? 'dmfPortfolioHomeLoop' : 'dmfPortfolioArchiveLoop',
								],
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-loop-item dmf-portfolio-loop-item--' . $context,
								'style' => $this->build_inline_style( $item_style ),
							]
						),
					],
				],
			],
			implode( "\n", $this->get_portfolio_loop_item_blocks( $context ) )
		);

		$container_group_block = $this->render_divi_block(
			'group',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Loop Container',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-loop-container dmf-portfolio-loop-container--' . $context,
								'style' => $this->build_inline_style( $container_style ),
							]
						),
					],
				],
			],
			$loop_group_block
		);

		$column_block = $this->render_divi_block(
			'column',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'     => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Loop Column',
							],
						],
					],
					'advanced' => [
						'type' => [
							'desktop' => [
								'value' => '4_4',
							],
						],
					],
				],
			],
			$container_group_block
		);

		return $this->render_divi_block(
			'row',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'     => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Loop Row',
							],
						],
					],
					'advanced' => [
						'columnStructure' => [
							'desktop' => [
								'value' => '4_4',
							],
						],
					],
					'decoration' => [
						'attributes' => $row_attributes,
					],
				],
			],
			$column_block
		);
	}

	private function get_portfolio_loop_item_blocks( $context ) {
		if ( 'home' === $context ) {
			return [
				$this->build_portfolio_card_image_block(),
				$this->build_portfolio_card_title_block(),
				$this->build_portfolio_card_excerpt_block(),
				$this->build_portfolio_card_button_block(),
			];
		}

		return [
			$this->build_portfolio_archive_media_group(),
			$this->build_portfolio_archive_body_group(),
		];
	}

	private function build_portfolio_archive_media_group() {
		$children    = [ $this->build_portfolio_archive_image_block() ];
		$badge_block = $this->build_portfolio_archive_badge_block();

		if ( '' !== $badge_block ) {
			$children[] = $badge_block;
		}

		return $this->build_group_module(
			'DMF Portfolio Archive Media',
			$children,
			'dmf-portfolio-card-media',
			[
				'position'      => 'relative',
				'margin'        => '-1rem -1rem 0',
				'width'         => 'calc(100% + 2rem)',
				'overflow'      => 'hidden',
				'border-radius' => 'calc(var(--gvid-dmf-radius-lg) - 0.0625rem) calc(var(--gvid-dmf-radius-lg) - 0.0625rem) 0 0',
			]
		);
	}

	private function build_portfolio_archive_body_group() {
		$children   = [
			$this->build_portfolio_archive_eyebrow_block(),
			$this->build_portfolio_archive_title_block(),
		];
		$tags_block = $this->build_portfolio_archive_tags_block();

		if ( '' !== $tags_block ) {
			$children[] = $tags_block;
		}

		$meta_children = array_filter(
			[
				$this->build_portfolio_archive_meta_block( 'Goal', 'goal' ),
				$this->build_portfolio_archive_meta_block( 'Outcome', 'outcome' ),
			]
		);

		if ( empty( $meta_children ) ) {
			$children[] = $this->build_portfolio_archive_summary_block();
		} else {
			$children = array_merge( $children, $meta_children );
		}

		$children[] = $this->build_portfolio_archive_link_block();

		return $this->build_group_module(
			'DMF Portfolio Archive Body',
			$children,
			'dmf-portfolio-card-body',
			[
				'display'        => 'flex',
				'flex-direction' => 'column',
				'gap'            => '0.5rem',
				'flex'           => '1 1 auto',
			]
		);
	}

	private function build_portfolio_archive_eyebrow_block() {
		return $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Archive Eyebrow',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-card-eyebrow',
							]
						),
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => sprintf(
								'<div style="%1$s">%2$s</div>',
								esc_attr(
									$this->build_inline_style(
										[
											'font-family'    => 'var(--gvid-dmf-body-font)',
											'font-size'      => 'clamp(0.705rem, calc(0.705rem + 0.12vw), 0.765rem)',
											'font-weight'    => '700',
											'letter-spacing' => '0.14em',
											'text-transform' => 'uppercase',
											'color'          => 'var(--gcid-dmf-muted, #486262)',
											'margin'         => '0',
										]
									)
								),
								$this->build_portfolio_loop_meta_token( $this->get_portfolio_loop_meta_key( 'small_top_title' ) )
							),
						],
					],
				],
			]
		);
	}

	private function build_portfolio_archive_image_block() {
		return $this->render_divi_block(
			'image',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Archive Image',
							],
						],
					],
					'advanced'   => [
						'spacing' => [
							'desktop' => [
								'value' => [
									'showBottomSpace' => 'off',
								],
							],
						],
						'sizing'  => [
							'desktop' => [
								'value' => [
									'forceFullwidth' => 'on',
								],
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-card-image dmf-portfolio-card-image--archive',
								'style' => $this->build_inline_style(
									[
										'width'         => '100%',
										'overflow'      => 'hidden',
										'border-radius' => '0',
									]
								),
							]
						),
					],
				],
				'image'          => [
					'innerContent' => [
						'desktop' => [
							'value' => [
								'src'        => $this->build_dynamic_content_token(
									'loop_post_featured_image',
									[
										'before'         => '',
										'after'          => '',
										'thumbnail_size' => 'large',
									]
								),
								'alt'        => $this->build_dynamic_content_token(
									'loop_post_featured_image_alt_text',
									[
										'before' => '',
										'after'  => '',
									]
								),
								'linkUrl'    => $this->build_dynamic_content_token(
									'loop_post_link',
									[
										'before' => '',
										'after'  => '',
									]
								),
								'linkTarget' => 'off',
							],
						],
					],
					'advanced'     => [
						'lightbox' => [
							'desktop' => [
								'value' => 'off',
							],
						],
						'overlay'  => [
							'desktop' => [
								'value' => [
									'use' => 'off',
								],
							],
						],
					],
				],
			]
		);
	}

	private function build_portfolio_archive_badge_block() {
		$taxonomy = $this->get_portfolio_loop_taxonomy_slug( 'badge' );

		if ( '' === $taxonomy ) {
			return '';
		}

		return $this->build_text_module(
			'DMF Portfolio Archive Badge',
			'<div class="dmf-portfolio-card-badge-list">' . $this->build_portfolio_loop_terms_token( $taxonomy ) . '</div>',
			'dmf-portfolio-card-badge'
		);
	}

	private function build_portfolio_archive_title_block() {
		$title_token = $this->build_portfolio_loop_meta_token( $this->get_portfolio_loop_meta_key( 'portfolio_title' ) );

		if ( '' === $title_token ) {
			$title_token = $this->build_dynamic_content_token(
				'loop_post_title',
				[
					'before' => '',
					'after'  => '',
				]
			);
		}

		return $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Archive Title',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-card-title dmf-portfolio-card-title--archive',
							]
						),
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => sprintf(
								'<h3 style="%1$s">%2$s</h3>',
								esc_attr(
									$this->build_inline_style(
										[
											'font-family' => 'var(--gvid-dmf-heading-font)',
											'font-size'   => 'clamp(1.38rem, calc(1.28rem + 0.34vw), 1.68rem)',
											'font-weight' => '700',
											'line-height' => '1.2',
											'color'       => 'var(--gcid-dmf-foreground, #131b26)',
											'margin'      => '0',
										]
									)
								),
								$title_token
							),
						],
					],
				],
			]
		);
	}

	private function build_portfolio_archive_tags_block() {
		$taxonomy = $this->get_portfolio_loop_taxonomy_slug( 'tags' );

		if ( '' === $taxonomy ) {
			return '';
		}

		return $this->build_text_module(
			'DMF Portfolio Archive Tags',
			'<div class="dmf-portfolio-card-tags-list">' . $this->build_portfolio_loop_terms_token( $taxonomy ) . '</div>',
			'dmf-portfolio-card-tags'
		);
	}

	private function build_portfolio_archive_summary_block() {
		return $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Archive Summary',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-card-summary',
							]
						),
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => sprintf(
								'<p style="%1$s">%2$s</p>',
								esc_attr(
									$this->build_inline_style(
										[
											'font-family' => 'var(--gvid-dmf-body-font)',
											'font-size'   => 'clamp(0.8906rem, calc(0.8906rem + 0.16vw), 0.965rem)',
											'line-height' => '1.75',
											'color'       => 'var(--gcid-dmf-muted, #486262)',
											'margin'      => '0',
										]
									)
								),
								$this->build_dynamic_content_token(
									'loop_post_excerpt',
									[
										'before' => '',
										'after'  => '',
										'words'  => 28,
									]
								)
							),
						],
					],
				],
			]
		);
	}

	private function build_portfolio_archive_meta_block( $label, $slot ) {
		$meta_key = $this->get_portfolio_loop_meta_key( $slot );

		if ( '' === $meta_key ) {
			return '';
		}

		return $this->build_text_module(
			sprintf( 'DMF Portfolio Archive %s', (string) $label ),
			sprintf(
				'<div class="dmf-portfolio-card-meta-label">%1$s</div><div class="dmf-portfolio-card-meta-value">%2$s</div>',
				esc_html( strtoupper( (string) $label ) ),
				$this->build_portfolio_loop_meta_token( $meta_key )
			),
			'dmf-portfolio-card-meta dmf-portfolio-card-meta--' . sanitize_key( (string) $slot )
		);
	}

	private function build_portfolio_archive_link_block() {
		return $this->build_text_module(
			'DMF Portfolio Archive Link',
			sprintf(
				'<a class="dmf-portfolio-card-link-anchor" href="%1$s">View Case Study <span aria-hidden="true">↗</span></a>',
				$this->build_dynamic_content_token(
					'loop_post_link',
					[
						'before' => '',
						'after'  => '',
					]
				)
			),
			'dmf-portfolio-card-link'
		);
	}

	private function build_portfolio_loop_terms_token( $taxonomy ) {
		$taxonomy = sanitize_key( (string) $taxonomy );

		if ( '' === $taxonomy ) {
			return '';
		}

		return $this->build_dynamic_content_token(
			'loop_post_terms',
			[
				'before'        => '',
				'after'         => '',
				'taxonomy_type' => $taxonomy,
				'separator'     => '',
				'links'         => 'on',
			]
		);
	}

	private function build_portfolio_loop_meta_token( $meta_key ) {
		$meta_key = trim( (string) $meta_key );

		if ( '' === $meta_key ) {
			return '';
		}

		return $this->build_dynamic_content_token(
			'loop_post_meta_key_manual_custom_field_value',
			[
				'before'               => '',
				'after'                => '',
				'select_loop_meta_key' => 'loop_post_meta_key_manual_custom_field_value',
				'loop_meta_key'        => $meta_key,
				'date_format'          => 'default',
				'enable_html'          => 'off',
			]
		);
	}

	private function build_portfolio_archive_button_row() {
		$column_block = $this->render_divi_block(
			'column',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'     => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio CTA Column',
							],
						],
					],
					'advanced' => [
						'type' => [
							'desktop' => [
								'value' => '4_4',
							],
						],
					],
				],
			],
			$this->build_portfolio_button_block(
				'DMF Portfolio Archive Button',
				'View Full Portfolio',
				esc_url( $this->get_portfolio_page_url() ),
				'center'
			)
		);

		return $this->render_divi_block(
			'row',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'     => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio CTA Row',
							],
						],
					],
					'advanced' => [
						'columnStructure' => [
							'desktop' => [
								'value' => '4_4',
							],
						],
					],
				],
			],
			$column_block
		);
	}

	private function build_portfolio_archive_cta_row() {
		$copy_block = $this->build_text_module(
			'Portfolio Archive CTA Copy',
			'<p style="font-family:var(--gvid-dmf-body-font);font-size:clamp(1.02rem, calc(1.02rem + 0.2vw), 1.16rem);line-height:1.7;color:var(--gcid-dmf-muted, #486262);margin:0;text-align:center">Ready to become our next success story?</p>',
			'dmf-portfolio-archive-cta-copy'
		);

		$button_block = $this->build_text_module(
			'Portfolio Archive CTA Button',
			sprintf(
				'<a class="dmf-portfolio-archive-cta-anchor" href="%1$s"><span>Start Your Project</span><span class="dmf-portfolio-archive-cta-arrow" aria-hidden="true">→</span></a>',
				esc_url( home_url( '/#contact' ) )
			),
			'dmf-portfolio-archive-cta-button'
		);

		$column_block = $this->build_column_module(
			'Portfolio Archive CTA Column',
			[
				$copy_block,
				$button_block,
			],
			'4_4',
			'dmf-portfolio-archive-cta-column',
			[
				'display'        => 'flex',
				'flex-direction' => 'column',
				'align-items'    => 'center',
				'gap'            => '1.375rem',
			]
		);

		return $this->build_row_module(
			'Portfolio Archive CTA Row',
			[ $column_block ],
			'4_4',
			'dmf-portfolio-archive-cta-row',
			[
				'width'      => 'min(96rem, calc(100% - 2rem))',
				'max-width'  => '96rem',
				'margin'     => '0 auto',
				'padding-top' => '1rem',
			]
		);
	}

	private function build_portfolio_intro_markup( $context ) {
		if ( 'home' === $context ) {
			return <<<'HTML'
<div style="text-align:center;padding:0.625rem 0 0.25rem">
	<div style="font-family:var(--gvid-dmf-body-font);font-size:var(--gvid-dmf-text-xs);font-weight:700;letter-spacing:0.22em;text-transform:uppercase;color:var(--gcid-dmf-primary, #2b5b5b);margin-bottom:0.875rem">Portfolio</div>
	<h2 style="font-family:var(--gvid-dmf-heading-font);font-size:clamp(2rem, 4.8vw, 3.4rem);font-weight:700;line-height:1.12;color:var(--gcid-dmf-foreground, #131b26);margin:0 0 1rem 0">Recent <span style="display:inline-block;color:var(--gcid-dmf-accent, #941213)">Projects</span></h2>
	<p style="font-family:var(--gvid-dmf-body-font);font-size:clamp(0.9987rem, calc(0.9987rem + 0.24vw), 1.1688rem);line-height:1.8;color:var(--gcid-dmf-muted, #486262);margin:0 auto;max-width:43.75rem">Explore a live selection of Portfolio posts powered by Divi 5's native loop builder.</p>
</div>
HTML;
		}

		return <<<'HTML'
<div style="text-align:center;padding:0.625rem 0 0.25rem">
	<p style="font-family:var(--gvid-dmf-body-font);font-size:clamp(0.9987rem, calc(0.9987rem + 0.24vw), 1.1688rem);line-height:1.8;color:var(--gcid-dmf-muted, #486262);margin:0 auto;max-width:43.75rem">A curated selection of campaigns, brand systems, websites, automation flows, and next-gen advertising work.</p>
</div>
HTML;
	}

	private function build_portfolio_card_image_block() {
		return $this->render_divi_block(
			'image',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Card Image',
							],
						],
					],
					'advanced'   => [
						'spacing' => [
							'desktop' => [
								'value' => [
									'showBottomSpace' => 'off',
								],
							],
						],
						'sizing'  => [
							'desktop' => [
								'value' => [
									'forceFullwidth' => 'on',
								],
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-card-image',
								'style' => $this->build_inline_style(
									[
										'width'         => '100%',
										'overflow'      => 'hidden',
										'border-radius' => '1.25rem',
									]
								),
							]
						),
					],
				],
				'image'          => [
					'innerContent' => [
						'desktop' => [
							'value' => [
								'src'        => $this->build_dynamic_content_token(
									'loop_post_featured_image',
									[
										'before'         => '',
										'after'          => '',
										'thumbnail_size' => 'large',
									]
								),
								'alt'        => $this->build_dynamic_content_token(
									'loop_post_featured_image_alt_text',
									[
										'before' => '',
										'after'  => '',
									]
								),
								'linkUrl'    => $this->build_dynamic_content_token(
									'loop_post_link',
									[
										'before' => '',
										'after'  => '',
									]
								),
								'linkTarget' => 'off',
							],
						],
					],
					'advanced'     => [
						'lightbox' => [
							'desktop' => [
								'value' => 'off',
							],
						],
						'overlay'  => [
							'desktop' => [
								'value' => [
									'use' => 'off',
								],
							],
						],
					],
				],
			]
		);
	}

	private function build_portfolio_card_title_block() {
		return $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Card Title',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-card-title',
							]
						),
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => sprintf(
								'<h3 style="%1$s">%2$s</h3>',
								esc_attr(
									$this->build_inline_style(
										[
											'font-family' => 'var(--gvid-dmf-heading-font)',
											'font-size'   => 'clamp(1.61rem, calc(1.61rem + 0.4vw), 1.995rem)',
											'font-weight' => '700',
											'line-height' => '1.2',
											'color'       => 'var(--gcid-dmf-foreground, #131b26)',
											'margin'      => '0',
										]
									)
								),
								$this->build_dynamic_content_token(
									'loop_post_title',
									[
										'before' => '',
										'after'  => '',
									]
								)
							),
						],
					],
				],
			]
		);
	}

	private function build_portfolio_card_excerpt_block() {
		return $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'DMF Portfolio Card Excerpt',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-card-excerpt',
								'style' => $this->build_inline_style(
									[
										'flex' => '1 0 auto',
									]
								),
							]
						),
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => sprintf(
								'<p style="%1$s">%2$s</p>',
								esc_attr(
									$this->build_inline_style(
										[
											'font-family' => 'var(--gvid-dmf-body-font)',
											'font-size'   => 'clamp(0.8906rem, calc(0.8906rem + 0.18vw), 0.9938rem)',
											'line-height' => '1.8',
											'color'       => 'var(--gcid-dmf-muted, #486262)',
											'margin'      => '0',
										]
									)
								),
								$this->build_dynamic_content_token(
									'loop_post_excerpt',
									[
										'before' => '',
										'after'  => '',
										'words'  => 24,
									]
								)
							),
						],
					],
				],
			]
		);
	}

	private function build_portfolio_card_button_block() {
		return $this->build_portfolio_button_block(
			'DMF Portfolio Card Button',
			'View Project',
			$this->build_dynamic_content_token(
				'loop_post_link',
				[
					'before' => '',
					'after'  => '',
				]
			),
			'left'
		);
	}

	private function build_portfolio_button_block( $admin_label, $text, $url, $alignment = 'left' ) {
		return $this->build_button_module( $admin_label, $text, $url, $alignment, 'dmf-portfolio-button' );
	}

	private function build_button_module( $admin_label, $text, $url, $alignment = 'left', $class = '' ) {
		$attrs = [
			'builderVersion' => 0.7,
			'module'         => [
				'meta'     => [
					'adminLabel' => [
						'desktop' => [
							'value' => (string) $admin_label,
						],
					],
				],
				'advanced' => [
					'html'      => [
						'desktop' => [
							'value' => [
								'elementType' => 'a',
							],
						],
					],
					'alignment' => [
						'desktop' => [
							'value' => (string) $alignment,
						],
					],
				],
			],
			'button'         => [
				'innerContent' => [
					'desktop' => [
						'value' => [
							'text'       => (string) $text,
							'linkUrl'    => (string) $url,
							'linkTarget' => 'off',
							'rel'        => [],
						],
					],
				],
				'decoration'   => [
					'button' => [
						'desktop' => [
							'value' => [
								'enable' => 'off',
								'icon'   => [
									'enable' => 'off',
								],
							],
						],
					],
				],
			],
		];

		if ( '' !== trim( (string) $class ) ) {
			$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes(
				[
					'class' => trim( (string) $class ),
				]
			);
		}

		return $this->render_divi_block( 'button', $attrs );
	}

	private function build_text_module( $admin_label, $html, $class = '' ) {
		$attrs = [
			'builderVersion' => 0.7,
			'module'         => [
				'meta' => [
					'adminLabel' => [
						'desktop' => [
							'value' => (string) $admin_label,
						],
					],
				],
			],
			'content'        => [
				'innerContent' => [
					'desktop' => [
						'value' => (string) $html,
					],
				],
			],
		];

		if ( '' !== trim( (string) $class ) ) {
			$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes(
				[
					'class' => trim( (string) $class ),
				]
			);
		}

		return $this->render_divi_block( 'text', $attrs );
	}

	private function build_code_module( $admin_label, $markup, $class = '', $hidden = true ) {
		$attributes = [];

		if ( '' !== trim( (string) $class ) ) {
			$attributes['class'] = trim( (string) $class );
		}

		if ( $hidden ) {
			$attributes['style'] = $this->build_inline_style(
				[
					'display'        => 'none',
					'width'          => '0',
					'height'         => '0',
					'overflow'       => 'hidden',
					'pointer-events' => 'none',
				]
			);
		}

		return $this->render_divi_block(
			'code',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => (string) $admin_label,
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes( $attributes ),
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => (string) $markup,
						],
					],
				],
			]
		);
	}

	private function build_shortcode_block( $shortcode ) {
		return sprintf(
			"<!-- wp:shortcode -->\n%1\$s\n<!-- /wp:shortcode -->",
			trim( (string) $shortcode )
		);
	}

	private function build_group_module( $admin_label, array $children, $class = '', array $style = [], array $extra_attributes = [] ) {
		$attrs = [
			'builderVersion' => 0.7,
			'module'         => [
				'meta' => [
					'adminLabel' => [
						'desktop' => [
							'value' => (string) $admin_label,
						],
					],
				],
			],
		];

		$attributes = array_filter(
			array_merge(
				$extra_attributes,
				'' !== trim( (string) $class ) ? [ 'class' => trim( (string) $class ) ] : []
			),
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		if ( ! empty( $style ) ) {
			$attributes['style'] = $this->build_inline_style( $style );
		}

		if ( ! empty( $attributes ) ) {
			$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes( $attributes );
		}

		return $this->render_divi_block( 'group', $attrs, implode( "\n", $children ) );
	}

	private function build_loop_group_module( $admin_label, array $children, array $loop_args, $class = '', array $style = [], array $extra_attributes = [] ) {
		$attrs = [
			'builderVersion' => 0.7,
			'module'         => [
				'meta'     => [
					'adminLabel' => [
						'desktop' => [
							'value' => (string) $admin_label,
						],
					],
				],
				'advanced' => [
					'loop' => $this->build_portfolio_single_loop_settings( $loop_args ),
				],
			],
		];

		$attributes = array_filter(
			array_merge(
				$extra_attributes,
				'' !== trim( (string) $class ) ? [ 'class' => trim( (string) $class ) ] : []
			),
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		if ( ! empty( $style ) ) {
			$attributes['style'] = $this->build_inline_style( $style );
		}

		if ( ! empty( $attributes ) ) {
			$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes( $attributes );
		}

		return $this->render_divi_block( 'group', $attrs, implode( "\n", $children ) );
	}

	private function build_column_module( $admin_label, array $children, $type = '4_4', $class = '', array $style = [], array $extra_attributes = [] ) {
		$attrs = [
			'builderVersion' => 0.7,
			'module'         => [
				'meta'     => [
					'adminLabel' => [
						'desktop' => [
							'value' => (string) $admin_label,
						],
					],
				],
				'advanced' => [
					'type' => [
						'desktop' => [
							'value' => (string) $type,
						],
					],
				],
			],
		];

		$attributes = array_filter(
			array_merge(
				$extra_attributes,
				'' !== trim( (string) $class ) ? [ 'class' => trim( (string) $class ) ] : []
			),
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		if ( ! empty( $style ) ) {
			$attributes['style'] = $this->build_inline_style( $style );
		}

		if ( ! empty( $attributes ) ) {
			$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes( $attributes );
		}

		return $this->render_divi_block( 'column', $attrs, implode( "\n", $children ) );
	}

	private function build_row_module( $admin_label, array $children, $structure = '4_4', $class = '', array $style = [], array $extra_attributes = [] ) {
		$attrs = [
			'builderVersion' => 0.7,
			'module'         => [
				'meta'     => [
					'adminLabel' => [
						'desktop' => [
							'value' => (string) $admin_label,
						],
					],
				],
				'advanced' => [
					'columnStructure' => [
						'desktop' => [
							'value' => (string) $structure,
						],
					],
				],
			],
		];

		$attributes = array_filter(
			array_merge(
				$extra_attributes,
				'' !== trim( (string) $class ) ? [ 'class' => trim( (string) $class ) ] : []
			),
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		if ( ! empty( $style ) ) {
			$attributes['style'] = $this->build_inline_style( $style );
		}

		if ( ! empty( $attributes ) ) {
			$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes( $attributes );
		}

		return $this->render_divi_block( 'row', $attrs, implode( "\n", $children ) );
	}

	private function build_section_module( $admin_label, array $children, $class = '', array $style = [], array $extra_attributes = [] ) {
		$attrs = [
			'builderVersion' => 0.7,
			'module'         => [
				'meta' => [
					'adminLabel' => [
						'desktop' => [
							'value' => (string) $admin_label,
						],
					],
				],
			],
		];

		$attributes = array_filter(
			array_merge(
				$extra_attributes,
				'' !== trim( (string) $class ) ? [ 'class' => trim( (string) $class ) ] : []
			),
			static function ( $value ) {
				return '' !== trim( (string) $value );
			}
		);

		if ( ! empty( $style ) ) {
			$attributes['style'] = $this->build_inline_style( $style );
		}

		if ( ! empty( $attributes ) ) {
			$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes( $attributes );
		}

		return $this->render_divi_block( 'section', $attrs, implode( "\n", $children ) );
	}

	private function build_icon_markup( $icon ) {
		$icon   = sanitize_key( (string) $icon );
		$common = 'class="dmf-inline-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

		switch ( $icon ) {
			case 'message-square':
				return '<svg ' . $common . '><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
			case 'chart-column':
				return '<svg ' . $common . '><path d="M3 3v16a2 2 0 0 0 2 2h16"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg>';
			case 'rocket':
				return '<svg ' . $common . '><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"></path><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"></path></svg>';
			case 'mail':
				return '<svg ' . $common . '><rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>';
			case 'phone':
				return '<svg ' . $common . '><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>';
			case 'map-pin':
				return '<svg ' . $common . '><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"></path><circle cx="12" cy="10" r="3"></circle></svg>';
			case 'graduation-cap':
				return '<svg ' . $common . '><path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"></path><path d="M22 10v6"></path><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"></path></svg>';
			case 'bot':
				return '<svg ' . $common . '><path d="M12 8V4H8"></path><rect width="16" height="12" x="4" y="8" rx="2"></rect><path d="M2 14h2"></path><path d="M20 14h2"></path><path d="M15 13v2"></path><path d="M9 13v2"></path></svg>';
			case 'brain-circuit':
				return '<svg ' . $common . '><path d="M12 5a3 3 0 1 0-5.997.125 4 4 0 0 0-2.526 5.77 4 4 0 0 0 .556 6.588A4 4 0 1 0 12 18Z"></path><path d="M9 13a4.5 4.5 0 0 0 3-4"></path><path d="M6.003 5.125A3 3 0 0 0 6.401 6.5"></path><path d="M3.477 10.896a4 4 0 0 1 .585-.396"></path><path d="M6 18a4 4 0 0 1-1.967-.516"></path><path d="M12 13h4"></path><path d="M12 18h6a2 2 0 0 1 2 2v1"></path><path d="M12 8h8"></path><path d="M16 8V5a2 2 0 0 1 2-2"></path><circle cx="16" cy="13" r=".5"></circle><circle cx="18" cy="3" r=".5"></circle><circle cx="20" cy="21" r=".5"></circle><circle cx="20" cy="8" r=".5"></circle></svg>';
		}

		return '<svg ' . $common . '><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="4"></circle></svg>';
	}

	private function build_home_icon_markup( $src, $alt = '' ) {
		return sprintf(
			'<span class="dmf-card-icon-frame"><img class="dmf-card-icon-media" src="%1$s" alt="%2$s"></span>',
			esc_url( (string) $src ),
			esc_attr( (string) $alt )
		);
	}

	private function build_home_inline_icon_markup( $icon ) {
		return '<span class="dmf-card-icon-frame">' . $this->build_icon_markup( $icon ) . '</span>';
	}

	private function build_home_hero_section() {
		$hero_actions = sprintf(
			'<div class="dmf-home-actions"><a class="dmf-hero-action dmf-hero-action--primary" href="%1$s">Request Free Consultation</a><a class="dmf-hero-action dmf-hero-action--secondary" href="%2$s">Explore Our Services</a></div>',
			esc_url( home_url( '/#contact' ) ),
			esc_url( home_url( '/#services' ) )
		);

		return $this->build_section_module(
			'Home Hero Section',
			[
				$this->build_code_module( 'Home Runtime', $this->build_home_runtime_markup(), 'dmf-home-runtime' ),
				$this->build_row_module(
					'Hero Row',
					[
						$this->build_column_module(
							'Hero Column',
							[
								$this->build_group_module(
									'Home Hero Stack',
									[
										$this->build_text_module( 'Home Hero Eyebrow', '<div class="dmf-hero-eyebrow">Strategy · Data · Creativity</div>', 'dmf-home-text' ),
										$this->build_text_module( 'Home Hero Title', '<h1 class="dmf-hero-title">Providing a Strong <span class="dmf-text-gradient">Online Presence</span> Through Strategic Digital Marketing</h1>', 'dmf-home-text' ),
										$this->build_text_module( 'Home Hero Copy', '<p class="dmf-hero-copy">We offer strategic, modern and effective solutions for businesses that want to grow their online presence. Let us take you one step closer to your business goals.</p>', 'dmf-home-text' ),
										$this->build_text_module( 'Home Hero Actions', $hero_actions, 'dmf-home-text' ),
										$this->build_text_module( 'Home Hero Scroll Indicator', '<div class="dmf-hero-scroll"><span></span></div>', 'dmf-home-text' ),
									],
									'dmf-home-hero-stack'
								),
							],
							'4_4',
							'dmf-home-shell-column'
						),
					],
					'4_4',
					'dmf-home-shell-row'
				),
			],
			'dmf-home-hero-section',
			[
				'background' => "url('https://mindflowdigital.com/wp-content/uploads/2026/02/hero-bg.jpg') center/cover no-repeat",
			],
			[
				'id' => 'home',
			]
		);
	}

	private function get_about_value_cards() {
		return [
			[
				'icon'  => 'https://mindflowdigital.com/wp-content/uploads/2026/02/mission.svg',
				'title' => 'Our Mission',
				'text'  => "To develop trusted business partnerships by providing the highest level of digital marketing services that contribute to our client's growth, success, and the community's development.",
			],
			[
				'icon'  => 'https://mindflowdigital.com/wp-content/uploads/2026/02/vision.svg',
				'title' => 'Our Vision',
				'text'  => 'Our team consists of highly skilled professionals who are passionate about what they do. We believe that if you communicate with people right, you can gain excellence.',
			],
			[
				'icon'  => 'https://mindflowdigital.com/wp-content/uploads/2026/02/approach.svg',
				'title' => 'Our Approach',
				'text'  => 'Through creative and customized strategy, we meet your business expectations. We use the latest tools, trends, and the appropriate platforms for your brand to achieve the best results.',
			],
		];
	}

	private function build_about_section() {
		$value_cards = [];

		foreach ( $this->get_about_value_cards() as $index => $card ) {
			$value_cards[] = $this->build_group_module(
				sprintf( 'About Value Card %d', $index + 1 ),
				[
					$this->build_text_module(
						sprintf( 'About Value Icon %d', $index + 1 ),
						$this->build_home_icon_markup( $card['icon'], $card['title'] ),
						'dmf-card-icon'
					),
					$this->build_text_module( sprintf( 'About Value Title %d', $index + 1 ), '<h3 class="dmf-card-title">' . esc_html( $card['title'] ) . '</h3>', 'dmf-home-text' ),
					$this->build_text_module( sprintf( 'About Value Copy %d', $index + 1 ), '<p class="dmf-card-copy">' . esc_html( $card['text'] ) . '</p>', 'dmf-home-text' ),
				],
				'dmf-lift-card'
			);
		}

		return $this->build_section_module(
			'About Section',
			[
				$this->build_row_module(
					'About Layout Row',
					[
						$this->build_column_module(
							'About Layout Column',
							[
								$this->build_group_module(
									'About Section Shell',
									[
										$this->build_group_module(
											'About Split Layout',
											[
												$this->build_group_module(
													'About Image Group',
													[
														$this->render_divi_block(
															'image',
															[
																'builderVersion' => 0.7,
																'module'         => [
																	'meta'       => [
																		'adminLabel' => [
																			'desktop' => [
																				'value' => 'About Image',
																			],
																		],
																	],
																	'advanced'   => [
																		'spacing' => [
																			'desktop' => [
																				'value' => [
																					'showBottomSpace' => 'off',
																				],
																			],
																		],
																		'sizing'  => [
																			'desktop' => [
																				'value' => [
																					'forceFullwidth' => 'on',
																				],
																			],
																		],
																	],
																	'decoration' => [
																		'attributes' => $this->build_custom_attributes(
																			[
																				'class' => 'dmf-about-image',
																			]
																		),
																	],
																],
																'image'          => [
																	'innerContent' => [
																		'desktop' => [
																			'value' => [
																				'src'        => 'https://mindflowdigital.com/wp-content/uploads/2026/02/about-creative.jpg',
																				'alt'        => 'Digital MindFlow creative concept',
																				'linkUrl'    => '',
																				'linkTarget' => 'off',
																			],
																		],
																	],
																	'advanced'     => [
																		'lightbox' => [
																			'desktop' => [
																				'value' => 'off',
																			],
																		],
																		'overlay'  => [
																			'desktop' => [
																				'value' => [
																					'use' => 'off',
																				],
																			],
																		],
																	],
																],
															]
														),
													],
													'dmf-home-media'
												),
												$this->build_group_module(
													'About Copy Group',
													[
														$this->build_text_module( 'About Eyebrow', '<span class="dmf-section-eyebrow">About Us</span>', 'dmf-home-text' ),
														$this->build_text_module( 'About Title', '<h2 class="dmf-section-title">We Are <span class="dmf-text-gradient">Digital MindFlow</span></h2>', 'dmf-home-text' ),
														$this->build_text_module( 'About Copy Intro', '<p class="dmf-section-body">A studio offering digital marketing services, specializing in consultation, social media, email marketing, website design and Google Ads for businesses, brands and individuals.</p>', 'dmf-home-text' ),
														$this->build_text_module( 'About Copy Body', '<p class="dmf-section-body">We are professional, passionate, and strongly committed to what we do. With our experience, we aim to help our clients achieve their goals taking into account individual requirements and unique demands.</p>', 'dmf-home-text' ),
													],
													'dmf-home-copy'
												),
											],
											'dmf-home-split'
										),
										$this->build_group_module( 'About Values Container', $value_cards, 'dmf-flex-cards dmf-flex-cards--three dmf-values-cards' ),
									],
									'dmf-home-shell'
								),
							],
							'4_4',
							'dmf-home-shell-column'
						),
					],
					'4_4',
					'dmf-home-shell-row'
				),
			],
			'dmf-home-section dmf-home-section--light dmf-about-section',
			[],
			[
				'id' => 'about',
			]
		);
	}

	private function get_service_cards() {
		return [
			[
				'icon_type'   => 'image',
				'icon'        => 'https://mindflowdigital.com/wp-content/uploads/2026/02/consultation.svg',
				'title'       => 'Consultation',
				'description' => 'Digital marketing services built on strategy, driven by data and delivering effective practices.',
				'items'       => [ 'Vision & Brand Positioning', 'Competitive Analysis', 'Market Research', 'Target Audience & Re-targeting' ],
			],
			[
				'icon_type'   => 'image',
				'icon'        => 'https://mindflowdigital.com/wp-content/uploads/2026/02/social.svg',
				'title'       => 'Social Media Marketing',
				'description' => 'Custom content for the proper platform for your niche that attracts potential customers.',
				'items'       => [ 'Strategy & Monthly Content Plan', 'Instagram, Facebook, LinkedIn, TikTok', 'Content Creation & Hashtags', 'Social Media Advertising' ],
			],
			[
				'icon_type'   => 'image',
				'icon'        => 'https://mindflowdigital.com/wp-content/uploads/2026/02/email.svg',
				'title'       => 'Email Marketing',
				'description' => 'We can make some inboxes really happy. We know how your potential customers actually open your emails.',
				'items'       => [ 'List Building & Segmentation', 'Email Design & Content', 'Automation & Campaigns', 'Analytics & Reporting' ],
			],
			[
				'icon_type'   => 'image',
				'icon'        => 'https://mindflowdigital.com/wp-content/uploads/2026/02/seo.svg',
				'title'       => 'SEO',
				'description' => 'Comprehensive search engine optimization to boost your organic visibility and rankings.',
				'items'       => [ 'On-page & Off-page Optimization', 'Local SEO', 'Technical SEO', 'Content Strategy' ],
			],
			[
				'icon_type'   => 'image',
				'icon'        => 'https://mindflowdigital.com/wp-content/uploads/2026/02/ppc.svg',
				'title'       => 'PPC & Google Ads',
				'description' => 'Beat the competition and take your business to the top of results with the right strategy and keywords.',
				'items'       => [ 'Google & Bing Ads', 'Facebook & Display Ads', 'Audience Targeting & Leads', 'Conversion Tracking & Analytics' ],
			],
			[
				'icon_type'   => 'image',
				'icon'        => 'https://mindflowdigital.com/wp-content/uploads/2026/02/web.svg',
				'title'       => 'Web Design',
				'description' => 'Increase your online presence with a website that reflects your brand and takes your business to the next level.',
				'items'       => [ 'Web Development & Redesign', 'Content Creation & Visuals', 'SEO & Performance', 'Testing & Launch' ],
			],
			[
				'icon_type'   => 'image',
				'icon'        => 'https://mindflowdigital.com/wp-content/uploads/2026/02/ai-ads.svg',
				'title'       => 'AI-Powered Advertising',
				'description' => 'Next-gen advertising leveraging AI for smarter targeting, creative generation, and presence across AI answer engines.',
				'items'       => [ 'LLM Ads (ChatGPT, Gemini, Perplexity)', 'Generative Engine Optimization (GEO)', 'CTV Ads & Shoppable Experiences', 'AI-Generated Creatives & Commercials' ],
			],
			[
				'icon_type'   => 'svg',
				'icon'        => 'graduation-cap',
				'title'       => 'Marketing Training',
				'description' => 'Empower your team with hands-on workshops and frameworks to execute data-driven marketing campaigns independently.',
				'items'       => [ 'Team Workshops & Strategy Sessions', 'SEO, PPC & Social Media Training', 'Analytics & Reporting Mastery', 'Custom Playbooks & SOPs' ],
			],
			[
				'icon_type'   => 'svg',
				'icon'        => 'bot',
				'title'       => 'AI Training',
				'description' => 'Equip your team with the skills to integrate AI tools into everyday workflows for productivity and competitive edge.',
				'items'       => [ 'AI Tool Mastery (ChatGPT, Midjourney & more)', 'Prompt Engineering Workshops', 'Workflow Automation with AI', 'Custom AI Playbooks & Guidelines' ],
			],
		];
	}

	private function build_services_section() {
		$cards = [];

		foreach ( $this->get_service_cards() as $index => $card ) {
			$icon_markup = 'svg' === $card['icon_type']
				? $this->build_home_inline_icon_markup( $card['icon'] )
				: $this->build_home_icon_markup( $card['icon'], $card['title'] );

			$list_items = array_map(
				static function ( $item ) {
					return '<li>' . esc_html( $item ) . '</li>';
				},
				$card['items']
			);

			$cards[] = $this->build_group_module(
				sprintf( 'Service Card %d', $index + 1 ),
				[
					$this->build_text_module( sprintf( 'Service Icon %d', $index + 1 ), $icon_markup, 'dmf-card-icon' ),
					$this->build_text_module( sprintf( 'Service Title %d', $index + 1 ), '<h3 class="dmf-card-title">' . esc_html( $card['title'] ) . '</h3>', 'dmf-home-text' ),
					$this->build_text_module( sprintf( 'Service Copy %d', $index + 1 ), '<p class="dmf-card-copy">' . esc_html( $card['description'] ) . '</p>', 'dmf-home-text' ),
					$this->build_text_module( sprintf( 'Service List %d', $index + 1 ), '<ul class="dmf-service-list">' . implode( '', $list_items ) . '</ul>', 'dmf-home-text' ),
				],
				'dmf-lift-card dmf-service-card'
			);
		}

		return $this->build_section_module(
			'Services Section',
			[
				$this->build_row_module(
					'Services Layout Row',
					[
						$this->build_column_module(
							'Services Layout Column',
							[
								$this->build_group_module(
									'Services Section Shell',
									[
										$this->build_text_module( 'Services Eyebrow', '<span class="dmf-section-eyebrow">What We Do</span>', 'dmf-home-text dmf-section-header dmf-section-header--center' ),
										$this->build_text_module( 'Services Title', '<h2 class="dmf-section-title dmf-section-title--center">Our <span class="dmf-text-gradient">Services</span></h2>', 'dmf-home-text' ),
										$this->build_text_module( 'Services Body', '<p class="dmf-section-body dmf-section-body--center">We offer a full suite of digital marketing services to help your business thrive in the digital landscape.</p>', 'dmf-home-text' ),
										$this->build_group_module( 'Services Cards Container', $cards, 'dmf-flex-cards dmf-flex-cards--three dmf-services-cards' ),
									],
									'dmf-home-shell dmf-home-stack'
								),
							],
							'4_4',
							'dmf-home-shell-column'
						),
					],
					'4_4',
					'dmf-home-shell-row'
				),
			],
			'dmf-home-section dmf-home-section--muted dmf-services-section',
			[],
			[
				'id' => 'services',
			]
		);
	}

	private function get_process_steps() {
		return [
			[
				'step'        => '01',
				'icon'        => 'message-square',
				'title'       => 'Discovery & Strategy',
				'description' => 'We start with a deep dive into your business, understanding your goals, audience, and competition. A comprehensive strategy is developed that is tailored to your unique needs.',
			],
			[
				'step'        => '02',
				'icon'        => 'chart-column',
				'title'       => 'Execute & Optimize',
				'description' => 'We implement the strategy across the right channels - social media, SEO, ads, email. Every campaign is continuously monitored, tested, and refined for peak performance.',
			],
			[
				'step'        => '03',
				'icon'        => 'rocket',
				'title'       => 'Grow & Scale',
				'description' => 'With data-driven insights and transparent reporting, we identify opportunities to expand your reach, increase conversions, and scale your success to the next level.',
			],
		];
	}

	private function build_process_section() {
		$steps = [];

		foreach ( $this->get_process_steps() as $index => $step ) {
			$steps[] = $this->build_group_module(
				sprintf( 'Process Step %d', $index + 1 ),
				[
					$this->build_text_module( sprintf( 'Process Number %d', $index + 1 ), '<div class="dmf-process-number">' . esc_html( $step['step'] ) . '</div>', 'dmf-home-text' ),
					$this->build_text_module( sprintf( 'Process Icon %d', $index + 1 ), '<div class="dmf-process-icon-frame">' . $this->build_icon_markup( $step['icon'] ) . '</div>', 'dmf-home-text' ),
					$this->build_text_module( sprintf( 'Process Title %d', $index + 1 ), '<h3 class="dmf-card-title">' . esc_html( $step['title'] ) . '</h3>', 'dmf-home-text' ),
					$this->build_text_module( sprintf( 'Process Copy %d', $index + 1 ), '<p class="dmf-card-copy">' . esc_html( $step['description'] ) . '</p>', 'dmf-home-text' ),
				],
				'dmf-process-step'
			);
		}

		return $this->build_section_module(
			'Process Section',
			[
				$this->build_row_module(
					'Process Layout Row',
					[
						$this->build_column_module(
							'Process Layout Column',
							[
								$this->build_group_module(
									'Process Section Shell',
									[
										$this->build_text_module( 'Process Eyebrow', '<span class="dmf-section-eyebrow">How We Work</span>', 'dmf-home-text dmf-section-header dmf-section-header--center' ),
										$this->build_text_module( 'Process Title', '<h2 class="dmf-section-title dmf-section-title--center">Our <span class="dmf-text-gradient">Process</span></h2>', 'dmf-home-text' ),
										$this->build_text_module( 'Process Body', '<p class="dmf-section-body dmf-section-body--center">A simple, proven three-step approach to driving real results for your business.</p>', 'dmf-home-text' ),
										$this->build_group_module( 'Process Steps Container', $steps, 'dmf-flex-cards dmf-flex-cards--three dmf-process-steps' ),
									],
									'dmf-home-shell dmf-home-stack'
								),
							],
							'4_4',
							'dmf-home-shell-column'
						),
					],
					'4_4',
					'dmf-home-shell-row'
				),
			],
			'dmf-home-section dmf-home-section--light dmf-process-section',
			[],
			[
				'id' => 'process',
			]
		);
	}

	private function build_contact_form_markup() {
		return $this->build_group_module(
			'Contact Form Shell',
			[
				$this->build_shortcode_block( '[fluentform id="4"]' ),
			],
			'dmf-contact-form-shell',
			[
				'padding'     => '1.75rem',
				'border'      => '0.0625rem solid var(--gcid-dmf-border, #a1a5a4)',
				'border-radius' => '1.35rem',
				'background'  => 'var(--gcid-dmf-background, #fafafa)',
				'box-shadow'  => '0 1rem 2.25rem color-mix(in srgb, var(--gcid-dmf-primary, #2b5b5b) 8%, transparent)',
				'height'      => '100%',
				'box-sizing'  => 'border-box',
			]
		);
	}

	private function build_contact_section() {
		$email_icon_url = esc_url( 'https://mindflowdigital.com/wp-content/uploads/2026/03/email.svg' );
		$phone_icon_url = esc_url( 'https://mindflowdigital.com/wp-content/uploads/2026/03/phone.svg' );
		$location_icon_url = esc_url( 'https://mindflowdigital.com/wp-content/uploads/2026/03/location-icon.svg' );

		$header_style  = $this->build_inline_style(
			[
				'text-align' => 'center',
				'padding'    => '0.875rem 0 0.5rem',
			]
		);
		$eyebrow_style = $this->build_inline_style(
			[
				'font-family'    => 'var(--gvid-dmf-body-font)',
				'font-size'      => 'var(--gvid-dmf-text-xs)',
				'font-weight'    => '700',
				'letter-spacing' => '0.22em',
				'text-transform' => 'uppercase',
				'color'          => 'var(--gcid-dmf-primary, #2b5b5b)',
				'margin-bottom'  => '0.875rem',
			]
		);
		$title_style   = $this->build_inline_style(
			[
				'font-family' => 'var(--gvid-dmf-heading-font)',
				'font-size'   => 'clamp(2rem, 4.5vw, 3.375rem)',
				'font-weight' => '700',
				'line-height' => '1.15',
				'color'       => 'var(--gcid-dmf-foreground, #131b26)',
				'margin'      => '0 0 1.125rem 0',
			]
		);
		$accent_text_style = $this->build_inline_style(
			[
				'display' => 'inline-block',
				'color'   => 'var(--gcid-dmf-accent, #941213)',
			]
		);
		$body_style         = $this->build_inline_style(
			[
				'font-family' => 'var(--gvid-dmf-body-font)',
				'font-size'   => 'clamp(0.9987rem, calc(0.9987rem + 0.24vw), 1.1688rem)',
				'line-height' => '1.8',
				'color'       => 'var(--gcid-dmf-muted, #486262)',
				'margin'      => '0 auto',
				'max-width'   => '45rem',
			]
		);
		$info_panel_style   = $this->build_inline_style(
			[
				'display'        => 'flex',
				'flex-direction' => 'column',
				'gap'            => '1.75rem',
			]
		);
		$info_title_style   = $this->build_inline_style(
			[
				'font-family' => 'var(--gvid-dmf-heading-font)',
				'font-size'   => 'clamp(1.175rem, calc(1.175rem + 0.24vw), 1.375rem)',
				'font-weight' => '600',
				'line-height' => '1.25',
				'color'       => 'var(--gcid-dmf-foreground, #131b26)',
				'margin'      => '0 0 1.5rem 0',
			]
		);
		$info_links_style   = $this->build_inline_style(
			[
				'display'        => 'flex',
				'flex-direction' => 'column',
				'gap'            => '1.125rem',
			]
		);
		$contact_item_style = $this->build_inline_style(
			[
				'display'         => 'flex',
				'align-items'     => 'center',
				'gap'             => '0.9rem',
				'text-decoration' => 'none',
				'color'           => 'var(--gcid-dmf-muted, #486262)',
			]
		);
		$contact_icon_style = $this->build_inline_style(
			[
				'display'         => 'inline-flex',
				'align-items'     => 'center',
				'justify-content' => 'center',
				'width'           => '3rem',
				'height'          => '3rem',
				'flex-shrink'     => '0',
				'border-radius'   => '1rem',
				'background'      => 'color-mix(in srgb, var(--gcid-dmf-primary, #2b5b5b) 12%, transparent)',
				'color'           => 'var(--gcid-dmf-primary, #2b5b5b)',
			]
		);
		$contact_icon_image_style = $this->build_inline_style(
			[
				'display'    => 'block',
				'width'      => '1.2rem',
				'height'     => '1.2rem',
				'object-fit' => 'contain',
			]
		);
		$contact_text_style = $this->build_inline_style(
			[
				'font-family' => 'var(--gvid-dmf-body-font)',
				'font-size'   => 'clamp(0.95rem, calc(0.95rem + 0.18vw), 1.06rem)',
				'line-height' => '1.65',
				'color'       => 'var(--gcid-dmf-muted, #486262)',
			]
		);
		$callout_style      = $this->build_inline_style(
			[
				'display'        => 'flex',
				'flex-direction' => 'column',
				'align-items'    => 'flex-start',
				'gap'            => '1rem',
				'padding'        => '1.75rem',
				'border-radius'  => '1.35rem',
				'background'     => 'var(--gcid-dmf-primary, #2b5b5b)',
				'color'          => 'var(--gcid-dmf-white, #fafafa)',
			]
		);
		$callout_title_style = $this->build_inline_style(
			[
				'font-family' => 'var(--gvid-dmf-heading-font)',
				'font-size'   => 'clamp(1.175rem, calc(1.175rem + 0.24vw), 1.375rem)',
				'font-weight' => '600',
				'line-height' => '1.25',
				'color'       => 'var(--gcid-dmf-white, #fafafa)',
				'margin'      => '0',
			]
		);
		$callout_body_style = $this->build_inline_style(
			[
				'font-family' => 'var(--gvid-dmf-body-font)',
				'font-size'   => 'clamp(0.89rem, calc(0.89rem + 0.16vw), 0.98rem)',
				'line-height' => '1.75',
				'color'       => 'color-mix(in srgb, var(--gcid-dmf-white, #fafafa) 78%, transparent)',
				'margin'      => '0',
			]
		);
		$callout_button_style = $this->build_inline_style(
			[
				'display'         => 'inline-flex',
				'align-items'     => 'center',
				'justify-content' => 'center',
				'align-self'      => 'flex-start',
				'gap'             => '0.5rem',
				'width'           => 'fit-content',
				'max-width'       => '100%',
				'min-height'      => '2.95rem',
				'padding'         => '0.85rem 1.35rem',
				'border-radius'   => '0.8rem',
				'border'          => '0.0625rem solid color-mix(in srgb, var(--gcid-dmf-accent, #941213) 88%, var(--gcid-dmf-foreground, #131b26))',
				'background'      => 'linear-gradient(135deg, var(--gcid-dmf-accent, #941213), var(--gcid-dmf-accent-deep, #893637))',
				'color'           => 'var(--gcid-dmf-white, #fafafa)',
				'font-family'     => 'var(--gvid-dmf-body-font)',
				'font-size'       => '0.95rem',
				'font-weight'     => '700',
				'line-height'     => '1',
				'text-decoration' => 'none',
				'white-space'     => 'nowrap',
				'box-shadow'      => '0 0.95rem 1.9rem color-mix(in srgb, var(--gcid-dmf-accent, #941213) 22%, transparent)',
			]
		);

		$info_items = [
			sprintf(
				'<a href="mailto:info@mindflowdigital.com" style="%1$s"><span style="%2$s"><img src="%3$s" alt="" style="%4$s"></span><span style="%5$s">info@mindflowdigital.com</span></a>',
				$contact_item_style,
				$contact_icon_style,
				$email_icon_url,
				$contact_icon_image_style,
				$contact_text_style
			),
			sprintf(
				'<a href="tel:+35799882116" style="%1$s"><span style="%2$s"><img src="%3$s" alt="" style="%4$s"></span><span style="%5$s">+357 99 882116</span></a>',
				$contact_item_style,
				$contact_icon_style,
				$phone_icon_url,
				$contact_icon_image_style,
				$contact_text_style
			),
			sprintf(
				'<div style="%1$s"><span style="%2$s"><img src="%3$s" alt="" style="%4$s"></span><span style="%5$s">Paphos, Cyprus</span></div>',
				$contact_item_style,
				$contact_icon_style,
				$location_icon_url,
				$contact_icon_image_style,
				$contact_text_style
			),
		];

		$header_content = sprintf(
			<<<HTML
<div style="%1\$s">
	<div style="%2\$s">Get In Touch</div>
	<h2 style="%3\$s">Let's <span style="%4\$s">Work Together</span></h2>
	<p style="%5\$s">Ready to grow your online presence? Get in touch for a free consultation and let's discuss your goals.</p>
</div>
HTML,
			$header_style,
			$eyebrow_style,
			$title_style,
			$accent_text_style,
			$body_style
		);
		$info_content = sprintf(
			<<<HTML
<div style="%1\$s">
	<div>
		<h3 style="%2\$s">Contact Information</h3>
		<div style="%3\$s">%4\$s</div>
	</div>
	<div style="%5\$s">
		<h4 style="%6\$s">Book a Discovery Call</h4>
		<p style="%7\$s">Schedule a free 30-minute call with our team to discuss your business goals and how we can help.</p>
		<a href="tel:+35799882116" style="%8\$s">Call Now</a>
	</div>
</div>
HTML,
			$info_panel_style,
			$info_title_style,
			$info_links_style,
			implode( '', $info_items ),
			$callout_style,
			$callout_title_style,
			$callout_body_style,
			$callout_button_style
		);

		return $this->build_section_module(
			'Contact Section',
			[
				$this->build_row_module(
					'Contact Header Row',
					[
						$this->build_column_module(
							'Contact Header Column',
							[
								$this->build_text_module( 'Contact Header', $header_content ),
							],
							'4_4',
							'',
							[
								'padding' => '0',
								'margin'  => '0',
							]
						),
					],
					'4_4',
					'dmf-home-contact-header-row',
					[
						'width'      => 'min(82rem, calc(100% - 3rem))',
						'max-width'  => 'none',
						'margin'     => '0 auto',
						'padding'    => '0 0 1rem',
						'box-sizing' => 'border-box',
					]
				),
				$this->build_row_module(
					'Contact Content Row',
					[
						$this->build_column_module(
							'Contact Info Column',
							[
								$this->build_text_module( 'Contact Info', $info_content ),
							],
							'2_5',
							'dmf-home-contact-info-column',
							[
								'float'      => 'none',
								'clear'      => 'none',
								'margin'     => '0',
								'padding'    => '0',
								'width'      => '24rem',
								'max-width'  => '24rem',
								'flex'       => '0 0 24rem',
								'box-sizing' => 'border-box',
							]
						),
						$this->build_column_module(
							'Contact Form Column',
							[
								$this->build_contact_form_markup(),
							],
							'3_5',
							'dmf-home-contact-form-column',
							[
								'float'      => 'none',
								'clear'      => 'none',
								'margin'     => '0',
								'padding'    => '0',
								'width'      => 'auto',
								'max-width'  => 'none',
								'min-width'  => '0',
								'flex'       => '1 1 0',
								'box-sizing' => 'border-box',
							]
						),
					],
					'2_5,3_5',
					'dmf-home-contact-content-row',
					[
						'width'           => 'min(82rem, calc(100% - 3rem))',
						'max-width'       => 'none',
						'margin'          => '0 auto',
						'padding'         => '1.25rem 0 0',
						'box-sizing'      => 'border-box',
						'display'         => 'flex',
						'flex-wrap'       => 'nowrap',
						'align-items'     => 'stretch',
						'justify-content' => 'space-between',
						'gap'             => '2.5rem',
					]
				),
			],
			'dmf-home-contact-section',
			[
				'background' => 'var(--gcid-dmf-card, #edeced)',
				'padding'    => 'clamp(5rem, 8vw, 7rem) 0',
			],
			[
				'id' => 'contact',
			]
		);
	}

	private function build_global_footer_section() {
		$quick_links = '<div class="dmf-footer-links"><a href="/#about">About</a><a href="/#services">Services</a><a href="/#process">Process</a><a href="/#contact">Contact</a></div>';
		$contact_links = sprintf(
			'<div class="dmf-footer-links dmf-footer-links--contact"><a href="mailto:info@mindflowdigital.com"><span class="dmf-footer-link-icon">%1$s</span><span>info@mindflowdigital.com</span></a><a href="tel:+35799882116"><span class="dmf-footer-link-icon">%2$s</span><span>+357 99 882116</span></a><span class="dmf-footer-static"><span class="dmf-footer-link-icon">%3$s</span><span>Paphos, Cyprus</span></span></div>',
			$this->build_icon_markup( 'mail' ),
			$this->build_icon_markup( 'phone' ),
			$this->build_icon_markup( 'map-pin' )
		);

		return $this->build_section_module(
			'Global Footer Section',
			[
				$this->build_code_module( 'Footer Runtime', $this->build_footer_runtime_markup(), 'dmf-footer-runtime' ),
				$this->build_row_module(
					'Footer Row',
					[
						$this->build_column_module(
							'Footer Column',
							[
								$this->build_group_module(
									'Footer Shell',
									[
										$this->build_group_module(
											'Footer Columns',
											[
												$this->build_group_module(
													'Footer Brand Group',
													[
														$this->build_text_module( 'Footer Brand', '<div class="dmf-footer-brand">Digital<span class="dmf-text-gradient">MindFlow</span></div>', 'dmf-home-text' ),
														$this->build_text_module( 'Footer Brand Copy', '<p class="dmf-footer-copy">A studio offering strategic, modern and effective digital marketing solutions for businesses that want to grow their online presence.</p>', 'dmf-home-text' ),
													],
													'dmf-footer-brand-group'
												),
												$this->build_group_module(
													'Footer Links Group',
													[
														$this->build_text_module( 'Footer Links Title', '<h4 class="dmf-footer-heading">Quick Links</h4>', 'dmf-home-text' ),
														$this->build_text_module( 'Footer Links', $quick_links, 'dmf-home-text' ),
													],
													'dmf-footer-links-group'
												),
												$this->build_group_module(
													'Footer Contact Group',
													[
														$this->build_text_module( 'Footer Contact Title', '<h4 class="dmf-footer-heading">Contact</h4>', 'dmf-home-text' ),
														$this->build_text_module( 'Footer Contact Links', $contact_links, 'dmf-home-text' ),
													],
													'dmf-footer-contact-group'
												),
											],
											'dmf-footer-columns'
										),
										$this->build_group_module(
											'Footer Bottom',
											[
												$this->build_text_module( 'Footer Copyright', '<span class="dmf-footer-meta">© 2026 Digital MindFlow. All rights reserved.</span>', 'dmf-home-text' ),
												$this->build_text_module( 'Footer Meta', '<span class="dmf-footer-meta">Marketing Services · Paphos, Cyprus</span>', 'dmf-home-text' ),
											],
											'dmf-footer-bottom'
										),
									],
									'dmf-footer-shell'
								),
							],
							'4_4',
							'dmf-footer-column'
						),
					],
					'4_4',
					'dmf-footer-row'
				),
			],
			'dmf-footer-section',
			[
				'background' => '#181D25',
				'margin'     => '0',
				'padding'    => '0',
			]
		);
	}

	private function build_home_runtime_markup() {
		return <<<'HTML'
<style id="dmf-home-runtime-styles">
.dmf-home-runtime{display:none!important}
.dmf-text-gradient{display:inline-block;color:var(--gcid-dmf-accent,#941213)}
.dmf-home-shell-row,.dmf-home-shell-column,.dmf-home-shell,.dmf-home-text{position:relative}
.dmf-home-shell-row{width:100%!important;max-width:100%!important;margin:0!important;padding:0 1.5rem!important;box-sizing:border-box}
.dmf-home-shell-column{padding:0!important;margin:0 auto!important}
.dmf-home-shell{width:100%;max-width:80rem;margin:0 auto;display:flex;flex-direction:column;gap:clamp(2.5rem,5vw,4rem)}
.dmf-home-section{padding:clamp(5rem,8vw,7rem) 0}
.dmf-home-section--light{background:var(--gcid-dmf-background,#fafafa)}
.dmf-home-section--muted{background:var(--gcid-dmf-card,#edeced)}
.dmf-home-hero-section{position:relative;overflow:hidden;padding:clamp(7.25rem,11vw,9rem) 0 clamp(4.5rem,7vw,5.75rem)}
.dmf-home-hero-section::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,color-mix(in srgb,var(--gcid-dmf-foreground,#131b26) 46%,transparent),color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 58%,transparent));opacity:.78}
.dmf-home-hero-stack{position:relative;z-index:1;min-height:clamp(39rem,calc(100vh - 5.5rem),50rem);display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:1.5rem;width:min(100%,68rem);max-width:68rem;margin:0 auto;padding:clamp(1.25rem,2vw,2rem) 0}
.dmf-home-hero-stack .et_pb_module,.dmf-home-hero-stack .et_pb_module_inner,.dmf-home-hero-stack .et_pb_text_inner{width:100%!important;max-width:100%!important;margin-left:auto!important;margin-right:auto!important;text-align:center!important}
.dmf-hero-eyebrow{display:inline-flex;align-items:center;gap:.5rem;padding:.45rem 1rem;border-radius:999px;border:1px solid color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 36%,transparent);background:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 8%,transparent);color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-body-font);font-size:var(--gvid-dmf-text-sm);font-weight:700}
.dmf-hero-title,.dmf-section-title{font-family:var(--gvid-dmf-heading-font);font-size:clamp(2.5rem,6.5vw,4.75rem);font-weight:700;line-height:1.08;color:var(--gcid-dmf-white,#fafafa);margin:0}
.dmf-hero-title{max-width:14ch;margin:0 auto}
.dmf-section-title{font-size:clamp(2rem,4.5vw,3.5rem);line-height:1.12;color:var(--gcid-dmf-foreground,#131b26)}
.dmf-hero-copy,.dmf-section-body,.dmf-card-copy{font-family:var(--gvid-dmf-body-font);font-size:clamp(.98rem,calc(.96rem + .25vw),1.16rem);line-height:1.8;color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 74%,transparent);margin:0;max-width:43rem}
.dmf-hero-copy{max-width:42rem;margin:0 auto;text-align:center}
.dmf-section-body,.dmf-card-copy{color:var(--gcid-dmf-muted,#486262);max-width:46rem}
.dmf-section-header--center,.dmf-section-title--center,.dmf-section-body--center{text-align:center;align-self:center}
.dmf-section-eyebrow{display:block;font-family:var(--gvid-dmf-body-font);font-size:var(--gvid-dmf-text-xs);font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:var(--gcid-dmf-primary,#2b5b5b)}
.dmf-home-split{display:flex;flex-wrap:wrap;align-items:center;gap:clamp(2rem,4vw,4rem)}
.dmf-home-media{flex:1 1 22rem;min-width:min(100%,19rem)}
.dmf-home-copy{flex:1 1 24rem;min-width:min(100%,19rem);display:flex;flex-direction:column;gap:1rem}
.dmf-about-image img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;border-radius:1.5rem;box-shadow:0 1.5rem 3.5rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 14%,transparent)}
.dmf-home-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:1rem}
.dmf-hero-action{display:inline-flex;align-items:center;justify-content:center;min-height:2.8rem;padding:.78rem 1.2rem;border:1px solid transparent;border-radius:.62rem;font-family:var(--gvid-dmf-body-font);font-size:.92rem;font-weight:700;line-height:1;letter-spacing:.01em;text-decoration:none;white-space:nowrap;box-sizing:border-box;transition:transform .2s ease,background-color .2s ease,border-color .2s ease,box-shadow .2s ease,color .2s ease}
.dmf-hero-action:hover{transform:translateY(-1px)}
.dmf-hero-action--primary{color:var(--gcid-dmf-white,#fafafa);background:var(--gcid-dmf-primary,#2b5b5b);border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 78%,var(--gcid-dmf-foreground,#131b26));box-shadow:0 .95rem 1.9rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 24%,transparent)}
.dmf-hero-action--primary:hover{color:var(--gcid-dmf-white,#fafafa);background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 88%,var(--gcid-dmf-foreground,#131b26));border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 82%,var(--gcid-dmf-foreground,#131b26));box-shadow:0 1.05rem 2.05rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 28%,transparent)}
.dmf-hero-action--secondary{color:var(--gcid-dmf-foreground,#131b26);background:var(--gcid-dmf-white,#fafafa);border-color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 88%,var(--gcid-dmf-primary,#2b5b5b));box-shadow:0 .95rem 1.9rem color-mix(in srgb,var(--gcid-dmf-foreground,#131b26) 16%,transparent)}
.dmf-hero-action--secondary:hover{color:var(--gcid-dmf-foreground,#131b26);background:var(--gcid-dmf-card,#edeced);border-color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 70%,var(--gcid-dmf-primary,#2b5b5b));box-shadow:0 1.05rem 2.05rem color-mix(in srgb,var(--gcid-dmf-foreground,#131b26) 20%,transparent)}
.dmf-button .et_pb_button,.dmf-button a.et_pb_button{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:2.8rem!important;padding:.78rem 1.2rem!important;border-width:1px!important;border-style:solid!important;border-radius:.62rem!important;background-image:none!important;font-family:var(--gvid-dmf-body-font)!important;font-size:.92rem!important;font-weight:700!important;line-height:1!important;letter-spacing:.01em!important;text-decoration:none!important;white-space:nowrap!important;box-sizing:border-box!important;-webkit-text-fill-color:currentColor!important;transition:transform .2s ease,opacity .2s ease,background-color .2s ease,border-color .2s ease,box-shadow .2s ease!important}
.dmf-button .et_pb_button:after,.dmf-button a.et_pb_button:after{display:none!important}
.dmf-button .et_pb_button:hover,.dmf-button a.et_pb_button:hover{transform:translateY(-1px)!important;opacity:1!important}
.dmf-button--primary .et_pb_button,.dmf-button--primary a.et_pb_button{color:var(--gcid-dmf-white,#fafafa)!important;background:var(--gcid-dmf-primary,#2b5b5b)!important;background-color:var(--gcid-dmf-primary,#2b5b5b)!important;border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 78%,var(--gcid-dmf-foreground,#131b26))!important;box-shadow:0 .95rem 1.9rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 24%,transparent)!important}
.dmf-button--primary .et_pb_button:hover,.dmf-button--primary a.et_pb_button:hover{background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 88%,var(--gcid-dmf-foreground,#131b26))!important;background-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 88%,var(--gcid-dmf-foreground,#131b26))!important;border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 82%,var(--gcid-dmf-foreground,#131b26))!important;box-shadow:0 1.05rem 2.05rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 28%,transparent)!important}
.dmf-button--accent .et_pb_button,.dmf-button--accent a.et_pb_button{color:var(--gcid-dmf-white,#fafafa)!important;background:linear-gradient(135deg,var(--gcid-dmf-accent,#941213),var(--gcid-dmf-accent-deep,#893637))!important;background-color:var(--gcid-dmf-accent,#941213)!important;border-color:color-mix(in srgb,var(--gcid-dmf-accent,#941213) 74%,var(--gcid-dmf-foreground,#131b26))!important;box-shadow:0 .95rem 1.9rem color-mix(in srgb,var(--gcid-dmf-accent,#941213) 24%,transparent)!important}
.dmf-button--accent .et_pb_button:hover,.dmf-button--accent a.et_pb_button:hover{background:linear-gradient(135deg,color-mix(in srgb,var(--gcid-dmf-accent,#941213) 92%,var(--gcid-dmf-foreground,#131b26)),color-mix(in srgb,var(--gcid-dmf-accent-deep,#893637) 92%,var(--gcid-dmf-foreground,#131b26)))!important;background-color:color-mix(in srgb,var(--gcid-dmf-accent,#941213) 88%,var(--gcid-dmf-foreground,#131b26))!important;border-color:color-mix(in srgb,var(--gcid-dmf-accent,#941213) 84%,var(--gcid-dmf-foreground,#131b26))!important;box-shadow:0 1.05rem 2.05rem color-mix(in srgb,var(--gcid-dmf-accent,#941213) 28%,transparent)!important}
.dmf-button--secondary .et_pb_button,.dmf-button--secondary a.et_pb_button{color:var(--gcid-dmf-foreground,#131b26)!important;background:var(--gcid-dmf-white,#fafafa)!important;background-color:var(--gcid-dmf-white,#fafafa)!important;border-color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 88%,var(--gcid-dmf-primary,#2b5b5b))!important;box-shadow:0 .95rem 1.9rem color-mix(in srgb,var(--gcid-dmf-foreground,#131b26) 16%,transparent)!important}
.dmf-button--secondary .et_pb_button:hover,.dmf-button--secondary a.et_pb_button:hover{color:var(--gcid-dmf-foreground,#131b26)!important;background:var(--gcid-dmf-card,#edeced)!important;background-color:var(--gcid-dmf-card,#edeced)!important;border-color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 70%,var(--gcid-dmf-primary,#2b5b5b))!important;box-shadow:0 1.05rem 2.05rem color-mix(in srgb,var(--gcid-dmf-foreground,#131b26) 20%,transparent)!important}
.dmf-hero-scroll{display:flex;justify-content:center;padding-top:.75rem}
.dmf-hero-scroll span{display:block;width:1.5rem;height:2.5rem;border:2px solid color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 30%,transparent);border-radius:999px;position:relative}
.dmf-hero-scroll span::before{content:"";position:absolute;left:50%;top:.45rem;width:.38rem;height:.38rem;background:var(--gcid-dmf-primary,#2b5b5b);border-radius:999px;transform:translateX(-50%);animation:dmfHeroScroll 1.5s infinite}
@keyframes dmfHeroScroll{0%{transform:translate(-50%,0);opacity:1}50%{transform:translate(-50%,.75rem);opacity:.55}100%{transform:translate(-50%,0);opacity:1}}
.dmf-flex-cards{display:flex;flex-wrap:wrap;gap:1.5rem}
.dmf-flex-cards--three>.dmf-lift-card,.dmf-flex-cards--three>.dmf-process-step{flex:1 1 calc((100% - 3rem)/3);min-width:18rem}
.dmf-lift-card{display:flex;flex-direction:column;gap:1rem;padding:2rem;border:1px solid var(--gcid-dmf-border,#a1a5a4);border-radius:1.35rem;background:var(--gcid-dmf-background,#fafafa);box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 8%,transparent);transition:transform .28s ease,box-shadow .28s ease,border-color .28s ease}
.dmf-values-cards .dmf-lift-card{background:var(--gcid-dmf-card,#edeced)}
.dmf-lift-card:hover{transform:translateY(-8px);border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 34%,transparent);box-shadow:0 1.35rem 2.8rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 16%,transparent)}
.dmf-card-icon{line-height:0}
.dmf-card-icon-frame,.dmf-process-icon-frame,.dmf-contact-link__icon{display:inline-flex;align-items:center;justify-content:center;width:3.25rem;height:3.25rem;border-radius:1rem;background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 12%,transparent);color:var(--gcid-dmf-primary,#2b5b5b);transition:background-color .28s ease,color .28s ease,filter .28s ease}
.dmf-card-icon-media{display:block;width:1.45rem;height:1.45rem;object-fit:contain;transition:filter .28s ease}
.dmf-inline-icon{width:1.45rem;height:1.45rem}
.dmf-lift-card:hover .dmf-card-icon-frame{background:var(--gcid-dmf-primary,#2b5b5b);color:var(--gcid-dmf-white,#fafafa)}
.dmf-lift-card:hover .dmf-card-icon-media{filter:brightness(0) invert(1)}
.dmf-card-title,.dmf-panel-title,.dmf-callout-title{font-family:var(--gvid-dmf-heading-font);font-size:clamp(1.12rem,calc(1.1rem + .28vw),1.38rem);font-weight:600;line-height:1.25;color:var(--gcid-dmf-foreground,#131b26);margin:0}
.dmf-service-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.7rem}
.dmf-service-list li{position:relative;padding-left:1rem;font-family:var(--gvid-dmf-body-font);font-size:clamp(.87rem,calc(.86rem + .16vw),.98rem);line-height:1.7;color:var(--gcid-dmf-foreground,#131b26)}
.dmf-service-list li::before{content:"";position:absolute;left:0;top:.72rem;width:.42rem;height:.42rem;border-radius:999px;background:var(--gcid-dmf-primary,#2b5b5b)}
.dmf-process-steps{position:relative;align-items:stretch}
.dmf-process-step{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;text-align:center;gap:.9rem}
.dmf-process-number{display:inline-flex;align-items:center;justify-content:center;width:4rem;height:4rem;border-radius:1.15rem;background:var(--gcid-dmf-primary,#2b5b5b);color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-heading-font);font-size:1.18rem;font-weight:700;box-shadow:0 1.25rem 3rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 15%,transparent)}
.dmf-process-icon-frame{width:2.75rem;height:2.75rem;border-radius:999px}
.dmf-contact-panels{display:flex;flex-wrap:wrap;gap:2rem}
.dmf-contact-panel{display:flex;flex-direction:column;gap:1.5rem}
.dmf-contact-panel--info{flex:1 1 21rem;min-width:min(100%,19rem)}
.dmf-contact-panel--form{flex:1.35 1 31rem;min-width:min(100%,19rem)}
.dmf-contact-links{display:flex;flex-direction:column;gap:1rem}
.dmf-contact-link{display:flex;align-items:center;gap:.9rem;color:var(--gcid-dmf-muted,#486262);text-decoration:none}
.dmf-contact-link__text{font-family:var(--gvid-dmf-body-font);font-size:clamp(.87rem,calc(.86rem + .16vw),.98rem);line-height:1.6}
.dmf-contact-link:hover{color:var(--gcid-dmf-foreground,#131b26)}
.dmf-contact-link:hover .dmf-contact-link__icon{background:var(--gcid-dmf-primary,#2b5b5b);color:var(--gcid-dmf-white,#fafafa)}
.dmf-contact-callout{display:flex;flex-direction:column;gap:1rem;padding:1.6rem;border-radius:1.35rem;background:var(--gcid-dmf-primary,#2b5b5b);color:var(--gcid-dmf-white,#fafafa)}
.dmf-callout-title{color:var(--gcid-dmf-white,#fafafa)}
.dmf-callout-copy{font-family:var(--gvid-dmf-body-font);font-size:clamp(.87rem,calc(.86rem + .16vw),.98rem);line-height:1.8;color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 74%,transparent);margin:0}
.dmf-contact-form-shell{padding:1.75rem;border:1px solid var(--gcid-dmf-border,#a1a5a4);border-radius:1.35rem;background:var(--gcid-dmf-background,#fafafa);box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 8%,transparent)}
.dmf-contact-form-shell form,.dmf-contact-form-shell .fluentform{margin:0}
.dmf-contact-form-shell .ff-el-group,.dmf-contact-form-shell .ff-t-cell,.dmf-contact-form-shell .ff-btn-submit-wrapper{margin-bottom:1rem}
.dmf-contact-form-shell .ff-el-input--label label,.dmf-contact-form-shell label{font-family:var(--gvid-dmf-body-font);font-size:clamp(.77rem,calc(.76rem + .1vw),.85rem);font-weight:700;line-height:1.35;color:var(--gcid-dmf-foreground,#131b26)}
.dmf-contact-form-shell .ff-el-form-control,.dmf-contact-form-shell input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="hidden"]),.dmf-contact-form-shell textarea,.dmf-contact-form-shell select{width:100%;border:1px solid var(--gcid-dmf-border,#a1a5a4);border-radius:1rem;background:var(--gcid-dmf-background,#fafafa);padding:1rem 1.1rem;font-family:var(--gvid-dmf-body-font);font-size:clamp(.89rem,calc(.88rem + .15vw),.99rem);color:var(--gcid-dmf-foreground,#131b26);box-sizing:border-box}
.dmf-contact-form-shell textarea{resize:vertical;min-height:9.5rem}
.dmf-contact-form-shell .ff-el-form-control:focus,.dmf-contact-form-shell input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="hidden"]):focus,.dmf-contact-form-shell textarea:focus,.dmf-contact-form-shell select:focus{outline:2px solid color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 35%,transparent);outline-offset:0;border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 44%,transparent)}
.dmf-contact-form-shell .ff-btn-submit,.dmf-contact-form-shell button[type="submit"],.dmf-contact-form-shell input[type="submit"]{display:inline-flex;align-items:center;justify-content:center;min-height:2.8rem;padding:.78rem 1.2rem;border:1px solid color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 78%,var(--gcid-dmf-foreground,#131b26));border-radius:.62rem;background:var(--gcid-dmf-primary,#2b5b5b);background-color:var(--gcid-dmf-primary,#2b5b5b);background-image:none;color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-body-font);font-size:.92rem;font-weight:700;line-height:1;letter-spacing:.01em;white-space:nowrap;box-shadow:0 .95rem 1.9rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 24%,transparent);cursor:pointer;box-sizing:border-box;transition:transform .2s ease,background-color .2s ease,border-color .2s ease,box-shadow .2s ease}
.dmf-contact-form-shell .ff-btn-submit:hover,.dmf-contact-form-shell button[type="submit"]:hover,.dmf-contact-form-shell input[type="submit"]:hover{transform:translateY(-1px);background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 88%,var(--gcid-dmf-foreground,#131b26));background-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 88%,var(--gcid-dmf-foreground,#131b26));border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 82%,var(--gcid-dmf-foreground,#131b26));box-shadow:0 1.05rem 2.05rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 28%,transparent)}
.dmf-contact-form{display:flex;flex-direction:column;gap:1rem}
.dmf-form-field{display:flex;flex-direction:column;gap:.5rem}
.dmf-form-label{font-family:var(--gvid-dmf-body-font);font-size:clamp(.77rem,calc(.76rem + .1vw),.85rem);font-weight:700;color:var(--gcid-dmf-foreground,#131b26)}
.dmf-contact-form input,.dmf-contact-form textarea{width:100%;border:1px solid var(--gcid-dmf-border,#a1a5a4);border-radius:1rem;background:var(--gcid-dmf-background,#fafafa);padding:1rem 1.1rem;font-family:var(--gvid-dmf-body-font);font-size:clamp(.89rem,calc(.88rem + .15vw),.99rem);color:var(--gcid-dmf-foreground,#131b26);box-sizing:border-box}
.dmf-contact-form textarea{resize:vertical;min-height:9.5rem}
.dmf-contact-form input:focus,.dmf-contact-form textarea:focus{outline:2px solid color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 35%,transparent);outline-offset:0;border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 44%,transparent)}
.dmf-form-submit{display:inline-flex;align-items:center;justify-content:center;align-self:flex-start;min-height:2.8rem;padding:.78rem 1.2rem;border:1px solid color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 78%,var(--gcid-dmf-foreground,#131b26));border-radius:.62rem;background:var(--gcid-dmf-primary,#2b5b5b);background-color:var(--gcid-dmf-primary,#2b5b5b);background-image:none;color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-body-font);font-size:.92rem;font-weight:700;line-height:1;letter-spacing:.01em;white-space:nowrap;box-shadow:0 .95rem 1.9rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 24%,transparent);cursor:pointer;box-sizing:border-box;transition:transform .2s ease,background-color .2s ease,border-color .2s ease,box-shadow .2s ease}
.dmf-form-submit:hover{transform:translateY(-1px);background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 88%,var(--gcid-dmf-foreground,#131b26));background-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 88%,var(--gcid-dmf-foreground,#131b26));border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 82%,var(--gcid-dmf-foreground,#131b26));box-shadow:0 1.05rem 2.05rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 28%,transparent)}
@media (max-width: 980px){.dmf-flex-cards--three>.dmf-lift-card,.dmf-flex-cards--three>.dmf-process-step{flex-basis:calc((100% - 1.5rem)/2)}}
@media (max-width: 767px){.dmf-home-hero-section{padding-top:6.75rem}.dmf-home-hero-stack{min-height:clamp(32rem,calc(100vh - 4.5rem),40rem);width:100%}.dmf-home-shell-row{padding:0 1rem!important}.dmf-hero-title{max-width:11.5ch}.dmf-flex-cards--three>.dmf-lift-card,.dmf-flex-cards--three>.dmf-process-step,.dmf-contact-panel--info,.dmf-contact-panel--form{flex-basis:100%}.dmf-home-actions{width:100%}.dmf-hero-action,.dmf-button,.dmf-button .et_pb_button,.dmf-button a.et_pb_button,.dmf-form-submit,.dmf-contact-form-shell .ff-btn-submit,.dmf-contact-form-shell button[type="submit"],.dmf-contact-form-shell input[type="submit"]{width:100%!important}}
</style>
HTML;
	}

	private function build_footer_runtime_markup() {
		return <<<'HTML'
<style id="dmf-footer-runtime-styles">
.dmf-footer-runtime{display:none!important}
.dmf-text-gradient{display:inline-block;background:linear-gradient(135deg,var(--gcid-dmf-primary,#2b5b5b),var(--gcid-dmf-accent,#941213));color:transparent;-webkit-background-clip:text;background-clip:text}
.dmf-footer-row,.dmf-footer-column{width:100%!important;max-width:100%!important;margin:0!important;padding:0!important}
.dmf-footer-shell{width:min(80rem,calc(100% - 3rem));margin:0 auto;padding:4rem 0 2rem;display:flex;flex-direction:column;gap:3rem}
.dmf-footer-columns{display:flex;flex-wrap:wrap;gap:2.5rem}
.dmf-footer-brand-group{flex:1.6 1 24rem;min-width:min(100%,18rem);display:flex;flex-direction:column;gap:1rem}
.dmf-footer-links-group,.dmf-footer-contact-group{flex:1 1 14rem;min-width:min(100%,13rem);display:flex;flex-direction:column;gap:1rem}
.dmf-footer-brand{font-family:var(--gvid-dmf-heading-font);font-size:clamp(1.18rem,calc(1.14rem + .24vw),1.38rem);font-weight:700;color:var(--gcid-dmf-white,#fafafa)}
.dmf-footer-copy{font-family:var(--gvid-dmf-body-font);font-size:clamp(.84rem,calc(.82rem + .14vw),.93rem);line-height:1.8;color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 60%,transparent);margin:0;max-width:32rem}
.dmf-footer-heading{font-family:var(--gvid-dmf-heading-font);font-size:var(--gvid-dmf-text-sm);font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 82%,transparent);margin:0}
.dmf-footer-links{display:flex;flex-direction:column;gap:.8rem}
.dmf-footer-links a,.dmf-footer-static{display:flex;align-items:center;gap:.6rem;font-family:var(--gvid-dmf-body-font);font-size:clamp(.84rem,calc(.82rem + .14vw),.93rem);color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 62%,transparent);text-decoration:none}
.dmf-footer-links a:hover{color:var(--gcid-dmf-primary,#2b5b5b)}
.dmf-footer-link-icon{display:inline-flex;align-items:center;justify-content:center;width:1rem;height:1rem;color:inherit}
.dmf-footer-link-icon .dmf-inline-icon{width:1rem;height:1rem}
.dmf-footer-bottom{padding-top:1.5rem;border-top:1px solid color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 10%,transparent);display:flex;flex-wrap:wrap;justify-content:space-between;gap:.75rem}
.dmf-footer-meta{font-family:var(--gvid-dmf-body-font);font-size:clamp(.72rem,calc(.71rem + .08vw),.8rem);color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 40%,transparent)}
@media (max-width: 767px){.dmf-footer-shell{width:min(80rem,calc(100% - 2rem));padding:3rem 0 1.5rem}}
</style>
HTML;
	}

	private function build_portfolio_loop_runtime_markup() {
		return <<<'HTML'
<style id="dmf-portfolio-loop-runtime-styles">
.dmf-portfolio-loop-runtime{display:none!important}
.dmf-portfolio-loop-shell .dmf-portfolio-loop-container{display:flex!important;flex-wrap:wrap!important;align-items:stretch!important;width:100%!important}
.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-container{gap:1.5rem!important}
.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-item{flex:1 1 calc((100% - 3rem)/3)!important;min-width:18rem!important;max-width:none!important;padding:0!important;background:transparent!important;border:0!important;box-shadow:none!important}
.dmf-portfolio-loop-shell--home .dmf-portfolio-card-image{position:relative!important;display:block!important;aspect-ratio:4/3;overflow:hidden!important;border-radius:1.35rem!important}
.dmf-portfolio-loop-shell--home .dmf-portfolio-card-image img{width:100%!important;height:100%!important;object-fit:cover!important;transition:transform .45s ease!important}
.dmf-portfolio-loop-shell--home .dmf-portfolio-card-image::before{content:"";position:absolute;inset:0;background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 34%,transparent);opacity:0;transition:opacity .28s ease;z-index:1}
.dmf-portfolio-loop-shell--home .dmf-portfolio-card-image::after{content:"↗";position:absolute;top:50%;left:50%;width:3rem;height:3rem;display:flex;align-items:center;justify-content:center;border-radius:999px;background:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 16%,transparent);color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-heading-font);font-size:1.25rem;transform:translate(-50%,-50%);opacity:0;transition:opacity .28s ease,transform .28s ease;z-index:2}
.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image img{transform:scale(1.05)}
.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::before,.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::after{opacity:1}
.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::after{transform:translate(-50%,-50%) scale(1)}
.dmf-portfolio-loop-shell--home .dmf-portfolio-card-title h3{font-size:clamp(1.18rem,calc(1.1rem + .35vw),1.42rem)!important;transition:color .2s ease}
.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-item:hover .dmf-portfolio-card-title h3{color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-portfolio-loop-shell--home .dmf-portfolio-card-excerpt p{max-width:36rem!important}
.dmf-portfolio-loop-shell--home .dmf-portfolio-button .et_pb_button,.dmf-portfolio-loop-shell--home .dmf-portfolio-button a.et_pb_button{padding:0!important;border:0!important;background:transparent!important;color:var(--gcid-dmf-primary,#2b5b5b)!important;box-shadow:none!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-container{gap:2rem!important;align-items:stretch!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item{display:flex!important;flex-direction:column!important;align-self:stretch!important;flex:1 1 calc((100% - 2rem)/2)!important;min-width:0!important;max-width:none!important;padding:1rem!important;background:var(--gcid-dmf-card,#edeced)!important;border:0.0625rem solid var(--gcid-dmf-border,#a1a5a4)!important;box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 8%,transparent)!important;transition:transform .28s ease,box-shadow .28s ease,border-color .28s ease!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item:hover{transform:translateY(-.35rem)!important;box-shadow:0 1.4rem 2.8rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 12%,transparent)!important;border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 24%,var(--gcid-dmf-border,#a1a5a4))!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-media{position:relative!important;display:flex!important;flex:1 1 auto!important;margin:-1rem -1rem 0!important;width:calc(100% + 2rem)!important;overflow:hidden!important;border-radius:calc(var(--gvid-dmf-radius-lg) - 0.0625rem) calc(var(--gvid-dmf-radius-lg) - 0.0625rem) 0 0!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-image{position:relative!important;display:flex!important;flex:1 1 auto!important;height:auto!important;min-height:clamp(26rem,38vw,47.5rem)!important;overflow:hidden!important;border-radius:0!important;background:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-image .et_pb_image_wrap,.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-image picture{display:block!important;width:100%!important;height:100%!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-image img{display:block!important;width:100%!important;height:100%!important;object-fit:cover!important;transition:transform .45s ease!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-image::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 8%,transparent) 0%,color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 44%,transparent) 100%);opacity:0;transition:opacity .28s ease;z-index:1}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-image::after{content:"↗";position:absolute;top:50%;left:50%;width:3.25rem;height:3.25rem;display:flex;align-items:center;justify-content:center;border-radius:999px;background:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 18%,transparent);color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-heading-font);font-size:1.35rem;transform:translate(-50%,-50%) scale(.9);opacity:0;transition:opacity .28s ease,transform .28s ease;z-index:2}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image img{transform:scale(1.07)!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::before,.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::after{opacity:1}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::after{transform:translate(-50%,-50%) scale(1)}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-badge{position:absolute!important;top:1rem!important;left:1rem!important;z-index:3!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-badge-list{display:flex!important;flex-wrap:wrap!important;gap:.5rem!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-badge-list a{display:inline-flex!important;align-items:center!important;padding:.42rem .82rem!important;border-radius:999px!important;background:var(--gcid-dmf-accent,#941213)!important;color:var(--gcid-dmf-white,#fafafa)!important;font-family:var(--gvid-dmf-body-font)!important;font-size:clamp(.7rem,calc(.7rem + .08vw),.76rem)!important;font-weight:700!important;line-height:1.1!important;letter-spacing:.02em!important;text-decoration:none!important;pointer-events:auto!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-body{display:flex!important;flex-direction:column!important;gap:.5rem!important;flex:0 0 auto!important;height:auto!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-eyebrow{margin-top:.05rem!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-title h3{font-size:clamp(1.38rem,calc(1.28rem + .34vw),1.68rem)!important;transition:color .2s ease!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item:hover .dmf-portfolio-card-title h3{color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-tags-list{display:flex!important;flex-wrap:wrap!important;gap:.35rem!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-tags-list a{display:inline-flex!important;align-items:center!important;padding:.22rem .56rem!important;border-radius:999px!important;background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 12%,transparent)!important;color:var(--gcid-dmf-muted,#486262)!important;font-family:var(--gvid-dmf-body-font)!important;font-size:clamp(.68rem,calc(.68rem + .06vw),.74rem)!important;font-weight:700!important;line-height:1.05!important;letter-spacing:.01em!important;text-decoration:none!important;transition:background-color .2s ease,color .2s ease!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item:hover .dmf-portfolio-card-tags-list a{background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 18%,transparent)!important;color:var(--gcid-dmf-foreground,#131b26)!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-meta{display:grid!important;gap:.14rem!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-meta-label{font-family:var(--gvid-dmf-body-font)!important;font-size:clamp(.7rem,calc(.7rem + .07vw),.76rem)!important;font-weight:700!important;letter-spacing:.12em!important;text-transform:uppercase!important;color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-meta-value{display:block!important;overflow:hidden!important;max-height:calc(1.46em * 2)!important;font-family:var(--gvid-dmf-body-font)!important;font-size:clamp(.88rem,calc(.88rem + .12vw),.95rem)!important;line-height:1.46!important;color:var(--gcid-dmf-muted,#486262)!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-summary{flex:1 1 auto!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-summary p{max-width:none!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-link{margin-top:auto!important;padding-top:.2rem!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-link-anchor{display:inline-flex!important;align-items:center!important;gap:.35rem!important;padding:0!important;border:0!important;background:transparent!important;color:var(--gcid-dmf-accent-deep,#893637)!important;box-shadow:none!important;font-family:var(--gvid-dmf-body-font)!important;font-size:clamp(.9rem,calc(.9rem + .12vw),.98rem)!important;font-weight:700!important;line-height:1.2!important;text-decoration:none!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-link-anchor:hover{opacity:1!important;color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-archive-cta-row{padding-top:1rem!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-archive-cta-column{display:flex!important;flex-direction:column!important;align-items:center!important;gap:1.375rem!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-archive-cta-copy p{margin:0!important;text-align:center!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-archive-cta-anchor{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:.55rem!important;padding:.95rem 1.55rem!important;border-radius:var(--gvid-dmf-radius-md)!important;border:0.0625rem solid color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 78%,var(--gcid-dmf-foreground,#131b26))!important;background:var(--gcid-dmf-primary,#2b5b5b)!important;color:var(--gcid-dmf-white,#fafafa)!important;box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 25%,transparent)!important;font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-base)!important;font-weight:700!important;line-height:1.1!important;text-decoration:none!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-archive-cta-arrow{display:inline-flex!important;align-items:center!important;justify-content:center!important;font-size:1rem!important;line-height:1!important}
.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-archive-cta-anchor:hover{opacity:1!important;transform:translateY(-1px)!important}
@media (max-width: 980px){.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-item{flex-basis:calc((100% - 1.5rem)/2)!important}.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item{flex-basis:100%!important}.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-image{height:28rem!important;min-height:28rem!important}}
@media (max-width: 767px){.dmf-portfolio-loop-shell--home .dmf-portfolio-loop-item{flex-basis:100%!important}.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-container{gap:1.5rem!important}.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-loop-item{padding:1rem!important}.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-media{margin:-1rem -1rem 0!important;width:calc(100% + 2rem)!important}.dmf-portfolio-loop-shell--portfolio .dmf-portfolio-card-image{height:20rem!important;min-height:20rem!important}}
</style>
HTML;
	}

	private function get_portfolio_loop_taxonomy_slug( $slot ) {
		$config = $this->get_portfolio_loop_taxonomy_config();

		return isset( $config[ $slot ] ) ? (string) $config[ $slot ] : '';
	}

	private function get_portfolio_loop_meta_key( $slot ) {
		$config = $this->get_portfolio_loop_meta_config();

		return isset( $config[ $slot ] ) ? (string) $config[ $slot ] : '';
	}

	private function get_portfolio_loop_taxonomy_config() {
		static $config = null;

		if ( null !== $config ) {
			return $config;
		}

		$config     = [
			'badge' => '',
			'tags'  => '',
		];
		$taxonomies = get_object_taxonomies( 'portfolio', 'objects' );

		if ( ! is_array( $taxonomies ) || empty( $taxonomies ) ) {
			return $config;
		}

		$public_taxonomies = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_object( $taxonomy ) || empty( $taxonomy->name ) || empty( $taxonomy->public ) ) {
				continue;
			}

			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}

			$public_taxonomies[ $taxonomy->name ] = $taxonomy;
		}

		if ( empty( $public_taxonomies ) ) {
			return $config;
		}

		$config['badge'] = $this->pick_portfolio_loop_taxonomy_slug(
			$public_taxonomies,
			[
				'portfolio_category',
				'portfolio_categories',
				'portfolio_type',
				'case_study_category',
				'case_study_type',
				'project_type',
				'service_category',
				'category',
			],
			true
		);

		if ( '' === $config['badge'] ) {
			$config['badge'] = $this->pick_portfolio_loop_taxonomy_slug( $public_taxonomies, [], true );
		}

		$config['tags'] = $this->pick_portfolio_loop_taxonomy_slug(
			$public_taxonomies,
			[
				'portfolio_tag',
				'portfolio_tags',
				'project_tag',
				'project_tags',
				'case_study_tag',
				'case_study_tags',
				'service_tag',
				'service_tags',
				'post_tag',
			],
			false,
			[ $config['badge'] ]
		);

		if ( '' === $config['tags'] ) {
			$config['tags'] = $this->pick_portfolio_loop_taxonomy_slug(
				$public_taxonomies,
				[],
				false,
				[ $config['badge'] ]
			);
		}

		if ( '' === $config['tags'] ) {
			$config['tags'] = $this->pick_portfolio_loop_taxonomy_slug(
				$public_taxonomies,
				[],
				null,
				[ $config['badge'] ]
			);
		}

		if ( '' === $config['badge'] ) {
			$config['badge'] = $this->pick_portfolio_loop_taxonomy_slug(
				$public_taxonomies,
				[],
				null,
				[ $config['tags'] ]
			);
		}

		if ( '' === $config['tags'] && '' !== $config['badge'] ) {
			$config['tags'] = $config['badge'];
		}

		return $config;
	}

	private function get_portfolio_loop_meta_config() {
		static $config = null;

		if ( null !== $config ) {
			return $config;
		}

		$config = [
			'small_top_title' => 'small_top_title',
			'portfolio_title' => 'portfolio_title',
			'goal'            => 'goal',
			'outcome'         => 'outcome',
		];

		return $config;
	}

	private function pick_portfolio_loop_taxonomy_slug( array $taxonomies, array $preferred_slugs = [], $hierarchical = null, array $exclude = [] ) {
		$exclude = array_filter(
			array_map( 'sanitize_key', $exclude ),
			static function ( $slug ) {
				return '' !== $slug;
			}
		);

		foreach ( $preferred_slugs as $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug || in_array( $slug, $exclude, true ) || ! isset( $taxonomies[ $slug ] ) ) {
				continue;
			}

			if ( null !== $hierarchical && (bool) $taxonomies[ $slug ]->hierarchical !== (bool) $hierarchical ) {
				continue;
			}

			return $slug;
		}

		foreach ( $taxonomies as $slug => $taxonomy ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug || in_array( $slug, $exclude, true ) ) {
				continue;
			}

			if ( null !== $hierarchical && (bool) $taxonomy->hierarchical !== (bool) $hierarchical ) {
				continue;
			}

			return $slug;
		}

		return '';
	}

	private function get_portfolio_page_url() {
		$page = $this->find_target_page( 'portfolio', '', 'Portfolio' );

		if ( $page instanceof WP_Post ) {
			$permalink = get_permalink( $page );
			if ( $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/portfolio/' );
	}

	private function get_portfolio_loop_posts_per_page( $context ) {
		return 'home' === $context ? 3 : 999;
	}

	private function build_inline_style( array $declarations ) {
		$styles = [];

		foreach ( $declarations as $property => $value ) {
			$property = trim( (string) $property );
			$value    = trim( (string) $value );

			if ( '' === $property || '' === $value ) {
				continue;
			}

			$styles[] = $property . ': ' . $value;
		}

		return empty( $styles ) ? '' : implode( '; ', $styles ) . ';';
	}

	private function build_spacing_settings( array $padding = [], array $margin = [] ) {
		$spacing = [
			'desktop' => [
				'value' => [],
			],
		];

		if ( ! empty( $padding ) ) {
			$spacing['desktop']['value']['padding'] = wp_parse_args(
				$padding,
				[
					'top'            => '',
					'right'          => '',
					'bottom'         => '',
					'left'           => '',
					'syncVertical'   => 'off',
					'syncHorizontal' => 'off',
				]
			);
		}

		if ( ! empty( $margin ) ) {
			$spacing['desktop']['value']['margin'] = wp_parse_args(
				$margin,
				[
					'top'            => '',
					'right'          => '',
					'bottom'         => '',
					'left'           => '',
					'syncVertical'   => 'off',
					'syncHorizontal' => 'off',
				]
			);
		}

		return $spacing;
	}

	private function build_custom_attributes( array $attributes, $target_element = '' ) {
		$entries = [];

		foreach ( $attributes as $name => $value ) {
			$name  = trim( (string) $name );
			$value = trim( (string) $value );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$entries[] = [
				'id'            => uniqid( 'dmfAttr', true ),
				'name'          => $name,
				'value'         => $value,
				'adminLabel'    => 'class' === $name ? 'CSS Class' : ( 'style' === $name ? 'Inline Style' : ucfirst( $name ) ),
				'targetElement' => (string) $target_element,
			];
		}

		if ( empty( $entries ) ) {
			return [];
		}

		return [
			'desktop' => [
				'value' => [
					'attributes' => array_values( $entries ),
				],
			],
		];
	}

	private function build_dynamic_content_token( $name, array $settings = [] ) {
		return '$variable(' . wp_json_encode(
			[
				'type'  => 'content',
				'value' => [
					'name'     => (string) $name,
					'settings' => $settings,
				],
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . ')$';
	}

	private function render_divi_block( $block_name, array $attrs = [], $inner_content = null ) {
		$encoded_attrs = empty( $attrs )
			? ''
			: ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( null === $inner_content ) {
			return sprintf(
				'<!-- wp:divi/%1$s%2$s /-->',
				(string) $block_name,
				$encoded_attrs
			);
		}

		return sprintf(
			"<!-- wp:divi/%1\$s%2\$s -->\n%3\$s\n<!-- /wp:divi/%1\$s -->",
			(string) $block_name,
			$encoded_attrs,
			(string) $inner_content
		);
	}

	private function replace_divi_section_by_label( $content, $label, $replacement ) {
		$content     = (string) $content;
		$label_token = '"adminLabel":{"desktop":{"value":"' . (string) $label . '"}}';
		$label_pos   = strpos( $content, $label_token );

		if ( false === $label_pos ) {
			return null;
		}

		$start = strrpos( substr( $content, 0, $label_pos ), '<!-- wp:divi/section ' );
		$end   = strpos( $content, '<!-- /wp:divi/section -->', $label_pos );

		if ( false === $start || false === $end ) {
			return null;
		}

		$end += strlen( '<!-- /wp:divi/section -->' );

		return substr( $content, 0, $start ) . $replacement . substr( $content, $end );
	}

	private function replace_divi_block_by_label( $content, $label, $replacement ) {
		$content     = (string) $content;
		$label_token = '"adminLabel":{"desktop":{"value":"' . (string) $label . '"}}';
		$label_pos   = strpos( $content, $label_token );

		if ( false === $label_pos ) {
			return null;
		}

		$start = strrpos( substr( $content, 0, $label_pos ), '<!-- wp:divi/' );
		if ( false === $start ) {
			return null;
		}

		$header_end = strpos( $content, '-->', $start );
		if ( false === $header_end ) {
			return null;
		}

		$header_markup = substr( $content, $start, $header_end + 3 - $start );
		if ( preg_match( '#<!-- wp:divi/([a-z0-9_-]+)#i', $header_markup, $matches ) !== 1 ) {
			return null;
		}

		$block_name = strtolower( (string) $matches[1] );
		$end        = $header_end + 3;

		if ( false === strpos( $header_markup, '/-->' ) ) {
			$closing = '<!-- /wp:divi/' . $block_name . ' -->';
			$close_pos = strpos( $content, $closing, $header_end );
			if ( false === $close_pos ) {
				return null;
			}
			$end = $close_pos + strlen( $closing );
		}

		return substr( $content, 0, $start ) . (string) $replacement . substr( $content, $end );
	}

	private function inject_divi_block_into_container_by_label( $content, $container_label, $container_block_name, $block_markup ) {
		$content     = (string) $content;
		$label_token = '"adminLabel":{"desktop":{"value":"' . (string) $container_label . '"}}';
		$label_pos   = strpos( $content, $label_token );

		if ( false === $label_pos ) {
			return null;
		}

		$start_token = '<!-- wp:divi/' . (string) $container_block_name . ' ';
		$start = strrpos( substr( $content, 0, $label_pos ), $start_token );
		if ( false === $start ) {
			return null;
		}

		$end_token = '<!-- /wp:divi/' . (string) $container_block_name . ' -->';
		$end = strpos( $content, $end_token, $label_pos );
		if ( false === $end ) {
			return null;
		}

		return substr( $content, 0, $end ) . (string) $block_markup . substr( $content, $end );
	}

	private function upsert_divi_block_in_container_by_label( $content, $container_label, $container_block_name, $block_label, $block_markup ) {
		$replaced = $this->replace_divi_block_by_label( $content, $block_label, $block_markup );

		if ( null !== $replaced ) {
			return $replaced;
		}

		$injected = $this->inject_divi_block_into_container_by_label( $content, $container_label, $container_block_name, $block_markup );

		return null !== $injected ? $injected : (string) $content;
	}

	private function upsert_runtime_snippet( $content, $key, $markup ) {
		$content = (string) $content;
		$key     = sanitize_key( (string) $key );
		$markup  = trim( (string) $markup );

		if ( '' === $key || '' === $markup ) {
			return $content;
		}

		$start_marker = '<!-- dmf-runtime:' . $key . ':start -->';
		$end_marker   = '<!-- dmf-runtime:' . $key . ':end -->';
		$pattern      = '#' . preg_quote( $start_marker, '#' ) . '.*?' . preg_quote( $end_marker, '#' ) . '#s';
		$snippet      = $start_marker . "\n" . $markup . "\n" . $end_marker;

		if ( preg_match( $pattern, $content ) ) {
			return (string) preg_replace( $pattern, $snippet, $content, 1 );
		}

		return rtrim( $content ) . "\n" . $snippet . "\n";
	}

	private function is_home_target_page( WP_Post $page ) {
		$front_page_id = (int) get_option( 'page_on_front' );

		return (int) $page->ID === $front_page_id || 'home' === sanitize_title( (string) $page->post_name );
	}

	private function apply_named_page_layout_fixes( WP_Post $page, $content ) {
		if ( $this->is_home_target_page( $page ) ) {
			return $this->apply_home_page_layout_fixes( $content );
		}

		return (string) $content;
	}

	private function apply_home_page_layout_fixes( $content ) {
		$content = (string) $content;

		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );

		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return $content;
		}

		$blocks     = $this->prune_removed_home_service_card_blocks( $blocks );
		$blocks     = $this->mutate_home_hover_blocks( $blocks );
		$serialized = serialize_blocks( $blocks );

		if ( ! is_string( $serialized ) || '' === $serialized ) {
			$serialized = $content;
		}

		$home_section_replacements = [
			'Home Hero Section'       => $this->build_home_hero_section(),
			'About Section'           => $this->build_about_section(),
			'Services Section'        => $this->build_services_section(),
			'Portfolio Projects Section' => $this->build_portfolio_loop_section( 'home' ),
			'Process Section'         => $this->build_process_section(),
			'Contact Section'         => $this->build_contact_section(),
		];

		foreach ( $home_section_replacements as $section_label => $replacement_markup ) {
			$replaced = $this->replace_divi_section_by_label( $serialized, $section_label, $replacement_markup );

			if ( is_string( $replaced ) ) {
				$serialized = $replaced;
			}
		}

		$serialized = $this->upsert_divi_block_in_container_by_label(
			$serialized,
			'Home Hero Section',
			'section',
			'Home Card Hover Runtime Row',
			$this->build_home_card_hover_runtime_row_markup()
		);

		$removed = $this->replace_divi_block_by_label( $serialized, 'Featured Projects CTA Row', '' );

		return is_string( $removed ) ? $removed : $serialized;
	}

	private function mutate_home_hover_blocks( array $blocks ) {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$this->mutate_home_hover_block( $block );
		}

		unset( $block );

		return $blocks;
	}

	private function prune_removed_home_service_card_blocks( array $blocks ) {
		$pruned = [];
		$labels = $this->get_removed_home_service_card_labels();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( 'divi/column' === (string) ( $block['blockName'] ?? '' ) && $this->block_contains_any_admin_label( $block, $labels ) ) {
				continue;
			}

			if ( in_array( $this->get_divi_block_admin_label( $block ), $labels, true ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->prune_removed_home_service_card_blocks( $block['innerBlocks'] );
			}

			$pruned[] = $block;
		}

		return $pruned;
	}

	private function block_contains_any_admin_label( array $block, array $labels ) {
		if ( in_array( $this->get_divi_block_admin_label( $block ), $labels, true ) ) {
			return true;
		}

		foreach ( $block['innerBlocks'] ?? [] as $inner_block ) {
			if ( is_array( $inner_block ) && $this->block_contains_any_admin_label( $inner_block, $labels ) ) {
				return true;
			}
		}

		return false;
	}

	private function mutate_home_hover_block( array &$block ) {
		$contains_info_card    = false;
		$contains_project_card = false;
		$block_name            = (string) ( $block['blockName'] ?? '' );
		$admin_label           = $this->get_divi_block_admin_label( $block );

		$this->append_home_layout_classes( $block, $admin_label );

		if ( 'divi/text' === $block_name ) {
			$markup = (string) ( $block['attrs']['content']['innerContent']['desktop']['value'] ?? '' );

			if ( '' !== $markup ) {
				$card_type      = $this->classify_home_card_label( $admin_label );
				$updated_markup = $this->decorate_home_card_markup( $admin_label, $markup );

				if ( $updated_markup !== $markup ) {
					$block['attrs']['content']['innerContent']['desktop']['value'] = $updated_markup;
				}

				$contains_info_card    = 'info' === $card_type;
				$contains_project_card = 'project' === $card_type;
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as &$inner_block ) {
				$child_flags = $this->mutate_home_hover_block( $inner_block );

				$contains_info_card    = $contains_info_card || ! empty( $child_flags['info'] );
				$contains_project_card = $contains_project_card || ! empty( $child_flags['project'] );
			}

			unset( $inner_block );
		}

		if ( 'divi/column' === $block_name && ( $contains_info_card || $contains_project_card ) ) {
			$this->append_divi_block_class( $block, 'dmf-home-equal-column' );
		}

		if ( 'divi/row' === $block_name && ( $contains_info_card || $contains_project_card ) ) {
			$this->append_divi_block_class( $block, 'dmf-home-equal-row' );
		}

		return [
			'info'    => $contains_info_card,
			'project' => $contains_project_card,
		];
	}

	private function append_home_layout_classes( array &$block, $admin_label ) {
		$admin_label = (string) $admin_label;

		$class_map = [
			'Contact Section'      => 'dmf-home-contact-section',
			'Contact Header Row'   => 'dmf-home-contact-header-row',
			'Contact Content Row'  => 'dmf-home-contact-content-row',
			'Contact Info Column'  => 'dmf-home-contact-info-column',
			'Contact Form Column'  => 'dmf-home-contact-form-column',
		];

		if ( isset( $class_map[ $admin_label ] ) ) {
			$this->append_divi_block_class( $block, $class_map[ $admin_label ] );
		}

		if ( 'Contact Section' === $admin_label ) {
			$this->append_divi_block_style_properties(
				$block,
				[
					'background' => 'var(--gcid-dmf-card, #edeced)',
					'padding'    => 'clamp(5rem, 8vw, 7rem) 0',
				]
			);
		} elseif ( 'Contact Header Row' === $admin_label ) {
			$this->append_divi_block_style_properties(
				$block,
				[
					'width'      => 'min(82rem, calc(100% - 3rem))',
					'max-width'  => 'none',
					'margin'     => '0 auto',
					'padding'    => '0 0 1rem',
					'box-sizing' => 'border-box',
				]
			);
		} elseif ( 'Contact Content Row' === $admin_label ) {
			$this->append_divi_block_style_properties(
				$block,
				[
					'width'          => 'min(82rem, calc(100% - 3rem))',
					'max-width'      => 'none',
					'margin'         => '0 auto',
					'padding'        => '1.25rem 0 0',
					'box-sizing'     => 'border-box',
					'display'        => 'flex',
					'flex-wrap'      => 'nowrap',
					'align-items'    => 'stretch',
					'justify-content'=> 'space-between',
					'gap'            => '2.5rem',
				]
			);
		} elseif ( 'Contact Info Column' === $admin_label ) {
			$this->append_divi_block_style_properties(
				$block,
				[
					'float'      => 'none',
					'clear'      => 'none',
					'margin'     => '0',
					'padding'    => '0',
					'width'      => '24rem',
					'max-width'  => '24rem',
					'flex'       => '0 0 24rem',
					'box-sizing' => 'border-box',
				]
			);
		} elseif ( 'Contact Form Column' === $admin_label ) {
			$this->append_divi_block_style_properties(
				$block,
				[
					'float'      => 'none',
					'clear'      => 'none',
					'margin'     => '0',
					'padding'    => '0',
					'width'      => 'auto',
					'max-width'  => 'none',
					'min-width'  => '0',
					'flex'       => '1 1 0',
					'box-sizing' => 'border-box',
				]
			);
		}
	}

	private function classify_home_card_label( $admin_label ) {
		$admin_label = (string) $admin_label;

		if ( in_array( $admin_label, $this->get_home_about_card_labels(), true ) || in_array( $admin_label, $this->get_home_service_card_labels(), true ) ) {
			return 'info';
		}

		if ( in_array( $admin_label, $this->get_home_featured_project_card_labels(), true ) ) {
			return 'project';
		}

		return '';
	}

	private function decorate_home_card_markup( $admin_label, $markup ) {
		$admin_label = (string) $admin_label;
		$markup      = (string) $markup;
		$service_card_inline_icons = $this->get_home_service_card_inline_icon_map();

		if ( 'About Image' === $admin_label ) {
			return $this->remove_about_image_decorative_square( $markup );
		}

		if ( array_key_exists( $admin_label, $this->get_home_process_card_icon_map() ) ) {
			return $this->decorate_home_process_card_markup( $admin_label, $markup );
		}

		if ( isset( $service_card_inline_icons[ $admin_label ] ) ) {
			$markup = $this->replace_home_card_inline_icon_markup( $markup, $service_card_inline_icons[ $admin_label ] );
		}

		if ( in_array( $admin_label, $this->get_home_about_card_labels(), true ) || in_array( $admin_label, $this->get_home_service_card_labels(), true ) ) {
			return $this->decorate_home_info_card_markup( $markup );
		}

		if ( in_array( $admin_label, $this->get_home_featured_project_card_labels(), true ) ) {
			return $this->decorate_home_featured_project_card_markup( $markup );
		}

		return $markup;
	}

	private function remove_about_image_decorative_square( $markup ) {
		$markup = (string) $markup;

		$updated = preg_replace(
			'#<span\b(?=[^>]*style="[^"]*position:absolute[^"]*right:-1rem[^"]*bottom:-1rem[^"]*width:6rem[^"]*height:6rem[^"]*z-index:-1[^"]*")[^>]*>\s*</span>#i',
			'',
			$markup,
			1
		);

		return is_string( $updated ) ? $updated : $markup;
	}

	private function get_home_process_card_icon_map() {
		return [
			'Discovery & Strategy Card' => [
				'url' => 'https://mindflowdigital.com/wp-content/uploads/2026/03/message.svg',
				'alt' => 'Message icon',
			],
			'Execute & Optimize Card'   => [
				'url' => 'https://mindflowdigital.com/wp-content/uploads/2026/03/chart.svg',
				'alt' => 'Chart icon',
			],
			'Grow & Scale Card'         => [
				'url' => 'https://mindflowdigital.com/wp-content/uploads/2026/03/rocket.svg',
				'alt' => 'Rocket icon',
			],
		];
	}

	private function decorate_home_process_card_markup( $admin_label, $markup ) {
		$icon = $this->get_home_process_card_icon_map()[ (string) $admin_label ] ?? null;

		if ( ! is_array( $icon ) || empty( $icon['url'] ) ) {
			return (string) $markup;
		}

		$replacement = sprintf(
			'<div class="dmf-home-process-icon-frame"><span class="dmf-home-process-icon" style="--dmf-process-icon:url(\'%1$s\')" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></div>',
			esc_url( (string) $icon['url'] ),
			esc_html( (string) ( $icon['alt'] ?? '' ) )
		);

		$updated = preg_replace(
			'#<div\b(?=[^>]*style="[^"]*display:inline-flex[^"]*width:2\.5rem[^"]*height:2\.5rem[^"]*border-radius:999rem[^"]*")[^>]*>\s*[123]\s*</div>#i',
			$replacement,
			(string) $markup,
			1
		);

		return is_string( $updated ) ? $updated : (string) $markup;
	}

	private function get_home_about_card_labels() {
		return array_map(
			static function ( array $card ) {
				return (string) $card['title'] . ' Card';
			},
			$this->get_about_value_cards()
		);
	}

	private function get_home_service_card_labels() {
		return array_map(
			static function ( array $card ) {
				return (string) $card['title'] . ' Card';
			},
			$this->get_service_cards()
		);
	}

	private function get_home_service_card_inline_icon_map() {
		return [
			'Marketing Training Card' => 'graduation-cap',
			'AI Training Card'        => 'bot',
		];
	}

	private function get_home_featured_project_card_labels() {
		return [
			'Social Media Campaign Card',
			'E-Commerce Website Redesign Card',
			'PPC Performance Campaign Card',
		];
	}

	private function get_removed_home_service_card_labels() {
		return [];
	}

	private function decorate_home_info_card_markup( $markup ) {
		$markup = $this->add_class_to_first_tag( $markup, 'div', 'dmf-home-hover-card' );
		$markup = $this->add_class_to_first_tag( $markup, 'div', 'dmf-home-hover-icon', 'display:inline-flex;width:3.5rem;height:3.5rem' );
		$markup = $this->add_class_to_first_tag( $markup, 'div', 'dmf-home-hover-icon', 'display:inline-flex;width:3rem;height:3rem' );
		$markup = $this->add_class_to_first_tag( $markup, 'img', 'dmf-home-hover-icon-media', 'display:block;object-fit:contain' );
		$markup = $this->add_class_to_first_tag( $markup, 'h3', 'dmf-home-hover-title' );

		return $markup;
	}

	private function replace_home_card_inline_icon_markup( $markup, $icon ) {
		$markup      = (string) $markup;
		$icon_markup = $this->build_icon_markup( $icon );
		$marker      = 'display:inline-flex;width:3rem;height:3rem';
		$start       = strpos( $markup, $marker );

		if ( false === $start ) {
			return $markup;
		}

		$content_start = strpos( $markup, '>', $start );

		if ( false === $content_start ) {
			return $markup;
		}

		$content_end = false;
		$end_markers = [
			"</div>\n    <h3",
			'</div>\n    <h3',
			"</div>\n<h3",
			'</div>\n<h3',
		];

		foreach ( $end_markers as $end_marker ) {
			$content_end = strpos( $markup, $end_marker, $content_start );

			if ( false !== $content_end ) {
				break;
			}
		}

		if ( false === $content_end ) {
			return $markup;
		}

		return substr( $markup, 0, $content_start + 1 ) . $icon_markup . substr( $markup, $content_end );
	}

	private function decorate_home_featured_project_card_markup( $markup ) {
		$markup = $this->add_class_to_first_tag( $markup, 'div', 'dmf-home-hover-project', 'height:100%' );
		$markup = $this->add_class_to_first_tag( $markup, 'a', 'dmf-home-hover-project-link' );
		$markup = $this->add_class_to_first_tag( $markup, 'div', 'dmf-home-hover-project-media', 'position:relative;overflow:hidden' );
		$markup = $this->add_class_to_first_tag( $markup, 'img', 'dmf-home-hover-project-image', 'display:block;width:100%;height:100%;object-fit:cover' );
		$markup = $this->add_class_to_first_tag( $markup, 'h3', 'dmf-home-hover-project-title' );

		return $markup;
	}

	private function add_class_to_first_tag( $markup, $tag, $class_name, $required_fragment = '' ) {
		$markup            = (string) $markup;
		$tag               = trim( (string) $tag );
		$class_name        = trim( (string) $class_name );
		$required_fragment = trim( (string) $required_fragment );

		if ( '' === $markup || '' === $tag || '' === $class_name ) {
			return $markup;
		}

		$pattern = '' === $required_fragment
			? '#<' . preg_quote( $tag, '#' ) . '\b[^>]*>#i'
			: '#<' . preg_quote( $tag, '#' ) . '\b(?=[^>]*' . preg_quote( $required_fragment, '#' ) . ')[^>]*>#i';

		$updated = preg_replace_callback(
			$pattern,
			function ( array $matches ) use ( $class_name ) {
				return $this->append_class_to_markup_tag( $matches[0], $class_name );
			},
			$markup,
			1
		);

		return is_string( $updated ) ? $updated : $markup;
	}

	private function append_class_to_markup_tag( $tag_markup, $class_name ) {
		$tag_markup  = (string) $tag_markup;
		$class_name  = trim( (string) $class_name );

		if ( '' === $tag_markup || '' === $class_name ) {
			return $tag_markup;
		}

		if ( preg_match( '/\bclass=(["\'])(.*?)\1/i', $tag_markup ) ) {
			$updated = preg_replace_callback(
				'/\bclass=(["\'])(.*?)\1/i',
				static function ( array $matches ) use ( $class_name ) {
					$existing_classes = preg_split( '/\s+/', trim( (string) $matches[2] ) );
					$existing_classes = array_filter( is_array( $existing_classes ) ? $existing_classes : [] );

					foreach ( preg_split( '/\s+/', $class_name ) as $new_class ) {
						$new_class = trim( (string) $new_class );

						if ( '' === $new_class || in_array( $new_class, $existing_classes, true ) ) {
							continue;
						}

						$existing_classes[] = $new_class;
					}

					return 'class=' . $matches[1] . implode( ' ', $existing_classes ) . $matches[1];
				},
				$tag_markup,
				1
			);

			return is_string( $updated ) ? $updated : $tag_markup;
		}

		$updated = preg_replace( '/(\s*\/?>)$/', ' class="' . $class_name . '"$1', $tag_markup, 1 );

		return is_string( $updated ) ? $updated : $tag_markup;
	}

	private function append_divi_block_class( array &$block, $class_name ) {
		$class_name = trim( (string) $class_name );

		if ( '' === $class_name ) {
			return;
		}

		$entries = $block['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'] ?? [];

		if ( ! is_array( $entries ) ) {
			$entries = [];
		}

		foreach ( $entries as &$entry ) {
			if ( ! is_array( $entry ) || 'class' !== (string) ( $entry['name'] ?? '' ) ) {
				continue;
			}

			$existing_classes = preg_split( '/\s+/', trim( (string) ( $entry['value'] ?? '' ) ) );
			$existing_classes = array_filter( is_array( $existing_classes ) ? $existing_classes : [] );

			foreach ( preg_split( '/\s+/', $class_name ) as $new_class ) {
				$new_class = trim( (string) $new_class );

				if ( '' === $new_class || in_array( $new_class, $existing_classes, true ) ) {
					continue;
				}

				$existing_classes[] = $new_class;
			}

			$entry['value'] = implode( ' ', $existing_classes );
			$block['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'] = array_values( $entries );

			return;
		}

		unset( $entry );

		$entries[] = [
			'id'            => uniqid( 'dmfAttr', true ),
			'name'          => 'class',
			'value'         => $class_name,
			'adminLabel'    => 'CSS Class',
			'targetElement' => '',
		];

		$block['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'] = array_values( $entries );
	}

	private function append_divi_block_style_properties( array &$block, array $styles ) {
		$entries = $block['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'] ?? [];

		if ( ! is_array( $entries ) ) {
			$entries = [];
		}

		$style_index = null;
		$style_map   = [];

		foreach ( $entries as $index => $entry ) {
			if ( ! is_array( $entry ) || 'style' !== (string) ( $entry['name'] ?? '' ) ) {
				continue;
			}

			$style_index = $index;
			$style_map   = $this->parse_inline_style_map( (string) ( $entry['value'] ?? '' ) );
			break;
		}

		foreach ( $styles as $property => $value ) {
			$property = trim( (string) $property );
			$value    = trim( (string) $value );

			if ( '' === $property || '' === $value ) {
				continue;
			}

			$style_map[ $property ] = $value;
		}

		$style_value = $this->build_inline_style( $style_map );

		if ( null !== $style_index ) {
			$entries[ $style_index ]['value'] = $style_value;
		} elseif ( '' !== $style_value ) {
			$entries[] = [
				'id'            => uniqid( 'dmfAttr', true ),
				'name'          => 'style',
				'value'         => $style_value,
				'adminLabel'    => 'Inline Style',
				'targetElement' => '',
			];
		}

		$block['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'] = array_values( $entries );
	}

	private function parse_inline_style_map( $style_string ) {
		$style_string = (string) $style_string;
		$style_map    = [];

		foreach ( explode( ';', $style_string ) as $declaration ) {
			$declaration = trim( (string) $declaration );

			if ( '' === $declaration || false === strpos( $declaration, ':' ) ) {
				continue;
			}

			list( $property, $value ) = array_map( 'trim', explode( ':', $declaration, 2 ) );

			if ( '' === $property || '' === $value ) {
				continue;
			}

			$style_map[ $property ] = $value;
		}

		return $style_map;
	}

	private function build_home_card_hover_runtime_row_markup() {
		return $this->build_row_module(
			'Home Card Hover Runtime Row',
			[
				$this->build_column_module(
					'Home Card Hover Runtime Column',
					[
						$this->build_code_module( 'Home Card Hover Runtime', $this->build_home_card_hover_runtime_markup(), 'dmf-home-card-hover-runtime', false ),
					],
					'4_4',
					'dmf-home-card-hover-runtime-column',
					[
						'width'      => '0',
						'max-width'  => '0',
						'height'     => '0',
						'margin'     => '0',
						'padding'    => '0',
						'overflow'   => 'hidden',
						'box-sizing' => 'border-box',
					]
				),
			],
			'4_4',
			'dmf-home-card-hover-runtime-row',
			[
				'width'      => '0',
				'max-width'  => '0',
				'height'     => '0',
				'margin'     => '0',
				'padding'    => '0',
				'overflow'   => 'hidden',
				'box-sizing' => 'border-box',
			]
		);
	}

	private function build_home_card_hover_runtime_markup() {
		return <<<'HTML'
<style id="dmf-home-card-hover-runtime-styles">
.dmf-home-card-hover-runtime{display:none!important}
.dmf-home-equal-row{display:flex!important;flex-wrap:wrap!important;align-items:stretch!important}
.dmf-home-equal-column{display:flex!important;flex-direction:column!important}
.dmf-home-equal-column .et_pb_module,.dmf-home-equal-column .et_pb_module_inner,.dmf-home-equal-column .et_pb_text_inner{height:100%!important}
.dmf-home-hover-card,.dmf-home-hover-project{position:relative;display:flex;flex-direction:column;height:100%;transform:translateY(0);transition:transform .28s ease,box-shadow .28s ease,border-color .28s ease}
.dmf-home-hover-card:hover,.dmf-home-hover-project:hover{transform:translateY(-8px)}
.dmf-home-hover-card:hover{border-color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 34%,transparent)!important;box-shadow:0 1.35rem 2.8rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 16%,transparent)!important}
.dmf-home-hover-icon{transition:background-color .28s ease,color .28s ease}
.dmf-home-hover-icon-media{transition:filter .28s ease}
.dmf-home-hover-title{transition:color .2s ease}
.dmf-home-hover-card:hover .dmf-home-hover-icon{background:var(--gcid-dmf-primary,#2b5b5b)!important;color:var(--gcid-dmf-white,#fafafa)!important}
.dmf-home-hover-card:hover .dmf-home-hover-icon-media{filter:brightness(0) invert(1)}
.dmf-home-hover-card:hover .dmf-home-hover-title{color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-home-hover-project-link{display:flex;flex-direction:column;height:100%}
.dmf-home-hover-project-media{position:relative}
.dmf-home-hover-project-media::before{content:"";position:absolute;inset:0;background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 34%,transparent);opacity:0;transition:opacity .28s ease;z-index:1;pointer-events:none}
.dmf-home-hover-project-media::after{content:"↗";position:absolute;top:50%;left:50%;width:3rem;height:3rem;display:flex;align-items:center;justify-content:center;border-radius:999px;background:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 16%,transparent);color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-heading-font);font-size:1.25rem;transform:translate(-50%,-50%) scale(.92);opacity:0;transition:opacity .28s ease,transform .28s ease;z-index:2;pointer-events:none}
.dmf-home-hover-project-image{transition:transform .45s ease}
.dmf-home-hover-project-title{transition:color .2s ease}
.dmf-home-hover-project:hover .dmf-home-hover-project-media::before,.dmf-home-hover-project:hover .dmf-home-hover-project-media::after{opacity:1}
.dmf-home-hover-project:hover .dmf-home-hover-project-media::after{transform:translate(-50%,-50%) scale(1)}
.dmf-home-hover-project:hover .dmf-home-hover-project-image{transform:scale(1.05)}
.dmf-home-hover-project:hover .dmf-home-hover-project-title{color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-home-process-icon-frame{display:inline-flex;align-items:center;justify-content:center;width:2.5rem;height:2.5rem;border-radius:999rem;background:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 14%,transparent);margin-bottom:1rem}
.dmf-home-process-icon{display:block;width:1.15rem;height:1.15rem;background:var(--gcid-dmf-primary,#2b5b5b);-webkit-mask:var(--dmf-process-icon) center/contain no-repeat;mask:var(--dmf-process-icon) center/contain no-repeat}
.dmf-home-contact-section{background:var(--gcid-dmf-card,#edeced)!important;padding:clamp(5rem,8vw,7rem) 0!important}
.dmf-home-contact-header-row,.dmf-home-contact-content-row{width:min(82rem,calc(100% - 3rem))!important;max-width:none!important;margin:0 auto!important}
.dmf-home-contact-header-row{padding:0 0 1rem!important}
.dmf-home-contact-content-row{display:flex!important;flex-wrap:nowrap!important;align-items:stretch!important;gap:2.5rem!important;padding:1.25rem 0 0!important}
.dmf-home-contact-content-row>.et_pb_column{float:none!important;clear:none!important;margin:0!important;padding:0!important;width:auto!important;max-width:none!important}
.dmf-home-contact-content-row>.dmf-home-contact-info-column{flex:0 0 24rem!important;width:24rem!important;max-width:24rem!important}
.dmf-home-contact-content-row>.dmf-home-contact-form-column{flex:1 1 0!important;width:auto!important;max-width:none!important;min-width:0!important}
.dmf-home-contact-form-column .et_pb_module,.dmf-home-contact-form-column .et_pb_module_inner,.dmf-home-contact-info-column .et_pb_module,.dmf-home-contact-info-column .et_pb_module_inner{height:100%!important}
@media (max-width: 980px){.dmf-home-equal-row{display:flex!important}.dmf-home-equal-column{width:100%!important}}
@media (max-width: 980px){.dmf-home-contact-header-row,.dmf-home-contact-content-row{width:min(82rem,calc(100% - 2rem))!important}.dmf-home-contact-content-row{flex-wrap:wrap!important}.dmf-home-contact-content-row>.dmf-home-contact-info-column,.dmf-home-contact-content-row>.dmf-home-contact-form-column{flex:1 1 100%!important;width:100%!important;max-width:100%!important}}
</style>
HTML;
	}

	private function apply_global_footer_layout_fix( $content ) {
		$replacement = $this->build_global_footer_section();
		$updated     = $this->replace_divi_section_by_label( (string) $content, 'Global Footer Section', $replacement );

		return null !== $updated ? $updated : (string) $content;
	}

	private function configure_divi_page_meta( $page_id ) {
		update_post_meta( $page_id, '_et_pb_use_builder', 'on' );
		update_post_meta( $page_id, '_et_pb_use_divi_5', 'on' );
		update_post_meta( $page_id, '_et_pb_built_for_post_type', 'page' );
		update_post_meta( $page_id, '_et_pb_show_page_creation', 'off' );
		update_post_meta( $page_id, '_et_pb_page_layout', 'et_full_width_page' );
		update_post_meta( $page_id, '_wp_page_template', 'default' );
	}

	private function import_global_theme_template( array $export, $dry_run ) {
		if ( empty( $export['templates'] ) || empty( $export['layouts'] ) ) {
			throw new RuntimeException( 'Theme Builder export is missing templates or layouts.' );
		}

		$template_export  = $this->get_default_template_export( $export['templates'] );
		$default_template = $this->get_existing_default_template();
		$layout_ids       = [
			'header' => 0,
			'body'   => 0,
			'footer' => 0,
		];
		$updated          = [];

		foreach ( $layout_ids as $layout_type => $unused ) {
			$layout_ref = $template_export['layouts'][ $layout_type ] ?? [ 'id' => 0, 'enabled' => false ];
			$source_id  = (int) ( $layout_ref['id'] ?? 0 );

			if ( $source_id <= 0 ) {
				continue;
			}

			if ( empty( $export['layouts'][ $source_id ] ) || ! is_array( $export['layouts'][ $source_id ] ) ) {
				throw new RuntimeException(
					sprintf( 'Theme Builder layout %1$d for %2$s is missing from export.', $source_id, $layout_type )
				);
			}

			$existing_layout_id = $default_template
				? (int) get_post_meta( $default_template->ID, "_et_{$layout_type}_layout_id", true )
				: 0;

			$layout_ids[ $layout_type ] = $this->upsert_theme_builder_layout(
				$export['layouts'][ $source_id ],
				$existing_layout_id,
				$dry_run
			);

			$updated[] = ucfirst( $layout_type ) . ' #' . $layout_ids[ $layout_type ];
		}

		if ( $dry_run ) {
			$updated = array_merge( $updated, $this->normalize_divi_header_theme_options( true ) );
			$portfolio_template = $this->upsert_portfolio_single_theme_template(
				true,
				[
					'header' => [
						'id'      => $layout_ids['header'],
						'enabled' => ! empty( $template_export['layouts']['header']['enabled'] ),
					],
					'footer' => [
						'id'      => $layout_ids['footer'],
						'enabled' => ! empty( $template_export['layouts']['footer']['enabled'] ),
					],
				]
			);
			$updated            = array_merge( $updated, $portfolio_template['updated'] );

			foreach (
				$this->find_templates_with_body_overrides(
					array_filter(
						[
							$default_template ? (int) $default_template->ID : 0,
							(int) ( $portfolio_template['template_id'] ?? 0 ),
						]
					)
				) as $template
			) {
				$updated[] = sprintf( 'Clear stale body override on template #%d', $template->ID );
			}

			$updated = array_values( array_unique( $updated ) );
			$this->log(
				'info',
				'Theme Builder import dry run prepared.',
				[
					'updated' => $updated,
				]
			);

			return $updated;
		}

		$theme_builder_id = function_exists( 'et_theme_builder_get_theme_builder_post_id' )
			? (int) et_theme_builder_get_theme_builder_post_id( true, true )
			: 0;

		if ( $theme_builder_id <= 0 ) {
			throw new RuntimeException( 'Unable to resolve the Divi Theme Builder post.' );
		}

		$template_input = [
			'id'                  => $default_template ? $default_template->ID : 0,
			'title'               => $template_export['title'] ?? 'Imported Template',
			'autogenerated_title' => ! empty( $template_export['autogenerated_title'] ) ? '1' : '0',
			'default'             => ! empty( $template_export['default'] ) ? '1' : '0',
			'enabled'             => ! empty( $template_export['enabled'] ) ? '1' : '0',
			'use_on'              => $template_export['use_on'] ?? [],
			'exclude_from'        => $template_export['exclude_from'] ?? [],
			'layouts'             => [
				'header' => [
					'id'      => $layout_ids['header'],
					'enabled' => ! empty( $template_export['layouts']['header']['enabled'] ) ? '1' : '0',
				],
				'body'   => [
					'id'      => $layout_ids['body'],
					// Divi treats a disabled body area as a full body override even with no body layout.
					'enabled' => $this->theme_template_area_enabled_flag( 'body', $layout_ids['body'], $template_export ),
				],
				'footer' => [
					'id'      => $layout_ids['footer'],
					'enabled' => ! empty( $template_export['layouts']['footer']['enabled'] ) ? '1' : '0',
				],
			],
		];

		$template_id = et_theme_builder_store_template( $theme_builder_id, $template_input, true );

		if ( ! $template_id ) {
			throw new RuntimeException( 'Theme Builder template could not be saved.' );
		}

		$this->attach_template_to_theme_builder_post( $theme_builder_id, (int) $template_id );

		$portfolio_template = $this->upsert_portfolio_single_theme_template(
			false,
			[
				'header' => [
					'id'      => $layout_ids['header'],
					'enabled' => ! empty( $template_export['layouts']['header']['enabled'] ),
				],
				'footer' => [
					'id'      => $layout_ids['footer'],
					'enabled' => ! empty( $template_export['layouts']['footer']['enabled'] ),
				],
			]
		);
		$updated            = array_merge( $updated, $portfolio_template['updated'] );
		$updated            = array_merge(
			$updated,
			$this->neutralize_other_theme_builder_body_overrides(
				array_filter(
					[
						(int) $template_id,
						(int) ( $portfolio_template['template_id'] ?? 0 ),
					]
				)
			)
		);
		$updated = array_merge( $updated, $this->normalize_divi_header_theme_options( false ) );
		$updated = array_values( array_unique( $updated ) );
		$this->log(
			'info',
			'Theme Builder import completed.',
			[
				'updated' => $updated,
			]
		);

		return $updated;
	}

	private function upsert_portfolio_single_theme_template( $dry_run, array $chrome_layouts = [] ) {
		$existing_template       = $this->get_existing_portfolio_single_template();
		$existing_body_layout_id = $existing_template
			? (int) get_post_meta( $existing_template->ID, '_et_body_layout_id', true )
			: 0;
		$body_layout_id          = $this->upsert_theme_builder_layout(
			$this->build_portfolio_single_body_layout_export(),
			$existing_body_layout_id,
			$dry_run
		);
		$template_title          = 'Digital MindFlow Portfolio Single';
		$template_id             = $existing_template ? (int) $existing_template->ID : 0;
		$chrome_layouts          = $this->resolve_portfolio_single_chrome_layouts( $existing_template, $chrome_layouts );
		$updated                 = [
			sprintf( 'Portfolio single body layout #%d', $body_layout_id ),
		];

		if ( (int) $chrome_layouts['header']['id'] > 0 ) {
			$updated[] = sprintf( 'Portfolio single header layout #%d', (int) $chrome_layouts['header']['id'] );
		}

		if ( (int) $chrome_layouts['footer']['id'] > 0 ) {
			$updated[] = sprintf( 'Portfolio single footer layout #%d', (int) $chrome_layouts['footer']['id'] );
		}

		if ( $dry_run ) {
			$updated[] = sprintf(
				'Portfolio single Theme Builder template #%d',
				$template_id > 0 ? $template_id : 999998
			);

			return [
				'template_id' => $template_id > 0 ? $template_id : 999998,
				'updated'     => $updated,
			];
		}

		$theme_builder_id = function_exists( 'et_theme_builder_get_theme_builder_post_id' )
			? (int) et_theme_builder_get_theme_builder_post_id( true, true )
			: 0;

		if ( $theme_builder_id <= 0 ) {
			throw new RuntimeException( 'Unable to resolve the Divi Theme Builder post.' );
		}

		$template_id = et_theme_builder_store_template(
			$theme_builder_id,
			[
				'id'                  => $template_id,
				'title'               => $template_title,
				'autogenerated_title' => '0',
				'default'             => '0',
				'enabled'             => '1',
				'use_on'              => [ $this->get_portfolio_single_template_condition() ],
				'exclude_from'        => [],
				'layouts'             => [
					'header' => $chrome_layouts['header'],
					'body'   => [
						'id'      => $body_layout_id,
						'enabled' => '1',
					],
					'footer' => $chrome_layouts['footer'],
				],
			],
			true
		);

		if ( ! $template_id ) {
			throw new RuntimeException( 'Portfolio single Theme Builder template could not be saved.' );
		}

		$this->attach_template_to_theme_builder_post( $theme_builder_id, (int) $template_id );
		$updated[] = sprintf( 'Portfolio single Theme Builder template #%d', $template_id );

		return [
			'template_id' => (int) $template_id,
			'updated'     => $updated,
		];
	}

	private function resolve_portfolio_single_chrome_layouts( $existing_template = null, array $preferred_layouts = [] ) {
		$default_template = $this->get_existing_default_template();
		$layouts          = [];

		foreach ( [ 'header', 'footer' ] as $layout_type ) {
			$layout_id = 0;
			$enabled   = '1';

			if ( ! empty( $preferred_layouts[ $layout_type ] ) && is_array( $preferred_layouts[ $layout_type ] ) ) {
				$layout_id = (int) ( $preferred_layouts[ $layout_type ]['id'] ?? 0 );
				$enabled   = ! empty( $preferred_layouts[ $layout_type ]['enabled'] ) ? '1' : '0';
			}

			if ( $layout_id <= 0 && $default_template instanceof WP_Post ) {
				$layout_id = (int) get_post_meta( $default_template->ID, "_et_{$layout_type}_layout_id", true );
				$enabled   = '1' === (string) get_post_meta( $default_template->ID, "_et_{$layout_type}_layout_enabled", true ) ? '1' : '0';
			}

			if ( $layout_id <= 0 && $existing_template instanceof WP_Post ) {
				$layout_id = (int) get_post_meta( $existing_template->ID, "_et_{$layout_type}_layout_id", true );
				$enabled   = '1' === (string) get_post_meta( $existing_template->ID, "_et_{$layout_type}_layout_enabled", true ) ? '1' : '0';
			}

			if ( $layout_id <= 0 ) {
				$this->warn(
					sprintf(
						'Global %s layout is not available for explicit portfolio single template assignment.',
						$layout_type
					)
				);
				$enabled = '1';
			}

			$layouts[ $layout_type ] = [
				'id'      => $layout_id,
				'enabled' => $enabled,
			];
		}

		return $layouts;
	}

	private function build_portfolio_single_body_layout_export() {
		return [
			'context'       => 'et_builder',
			'data'          => [
				'62003' => $this->build_portfolio_single_body_layout_content(),
			],
			'images'        => [],
			'thumbnails'    => [],
			'post_title'    => 'Digital MindFlow Portfolio Single Body',
			'post_type'     => 'et_body_layout',
			'theme_builder' => [
				'is_global' => false,
			],
			'post_meta'     => [
				[
					'key'   => '_et_pb_use_builder',
					'value' => 'on',
				],
				[
					'key'   => '_et_pb_use_divi_5',
					'value' => 'on',
				],
			],
		];
	}

	private function build_portfolio_single_body_layout_content() {
		$hero_section = $this->build_section_module(
			'Portfolio Single Hero Section',
			[
				$this->build_code_module( 'Portfolio Single Styles', $this->build_portfolio_single_styles_markup(), 'dmf-portfolio-single__styles' ),
				$this->build_row_module(
					'Portfolio Single Hero Row',
					[
						$this->build_column_module(
							'Portfolio Single Hero Copy Column',
							[
								$this->build_group_module(
									'Portfolio Single Hero Copy',
									[
										$this->build_portfolio_single_back_link_block(),
										$this->build_portfolio_single_badge_block(),
										$this->build_portfolio_single_title_block(),
										$this->build_portfolio_single_subtitle_block(),
										$this->build_portfolio_single_meta_block(),
									],
									'dmf-portfolio-single__hero-copy'
								),
							],
							'1_2',
							'dmf-portfolio-single__hero-copy-column',
							[
								'margin'  => '0',
								'padding' => '0',
							]
						),
						$this->build_column_module(
							'Portfolio Single Hero Image Column',
							[
								$this->build_portfolio_single_featured_image_block(),
							],
							'1_2',
							'dmf-portfolio-single__hero-image-column',
							[
								'margin'  => '0',
								'padding' => '0',
							]
						),
					],
					'1_2,1_2',
					'dmf-portfolio-single__hero-row',
					[
						'width'      => '100%',
						'max-width'  => '80rem',
						'margin'     => '0 auto',
						'padding'    => 'clamp(8rem, 12vw, 10rem) 1.5rem clamp(3rem, 6vw, 4.5rem)',
						'box-sizing' => 'border-box',
					]
				),
			],
			'dmf-portfolio-single__hero',
			[
				'background' => 'var(--gcid-dmf-primary, #2b5b5b)',
				'margin'     => '0',
				'padding'    => '0',
			]
		);

		$metrics_section = $this->build_section_module(
			'Portfolio Single Metrics Section',
			[
				$this->build_row_module(
					'Portfolio Single Metrics Row',
					[
						$this->build_column_module(
							'Portfolio Single Metrics Column',
							[
								$this->build_group_module(
									'Portfolio Single Metrics Grid',
									[
										$this->build_portfolio_single_metric_item_block(
											'Portfolio Single Top Metrics Item',
											'dmf-portfolio-single__metric-card'
										),
									],
									'dmf-portfolio-single__metrics-grid'
								),
							],
							'4_4',
							'dmf-portfolio-single__metrics-column'
						),
					],
					'4_4',
					'dmf-portfolio-single__metrics-row',
					[
						'width'      => '100%',
						'max-width'  => '80rem',
						'margin'     => '0 auto',
						'padding'    => '0 1.5rem',
						'box-sizing' => 'border-box',
					]
				),
			],
			'dmf-portfolio-single__metrics-section',
			[
				'background' => '#F4F3F0',
				'margin'     => '0',
				'padding'    => '1.85rem 0',
			]
		);

		$content_section = $this->build_section_module(
			'Portfolio Single Content Section',
			[
				$this->build_row_module(
					'Portfolio Single Content Row',
					[
						$this->build_column_module(
							'Portfolio Single Content Column',
							[
								$this->build_group_module(
									'Portfolio Single Content Stack',
									[
										$this->build_portfolio_single_copy_section(
											'Portfolio Single Overview Section',
											'dmf-portfolio-single__section dmf-portfolio-single__section--overview',
											'Project',
											'Overview',
											'project_overview'
										),
										$this->build_portfolio_single_copy_section(
											'Portfolio Single Challenge Section',
											'dmf-portfolio-single__section dmf-portfolio-single__section--challenge',
											'The',
											'Challenge',
											'project_challenge'
										),
										$this->build_portfolio_single_approach_section(),
										$this->build_portfolio_single_results_section(),
										$this->build_portfolio_single_quote_block(),
									],
									'dmf-portfolio-single__content-stack'
								),
							],
							'4_4',
							'dmf-portfolio-single__content-column'
						),
					],
					'4_4',
					'dmf-portfolio-single__content-row',
					[
						'width'      => '100%',
						'max-width'  => '62rem',
						'margin'     => '0 auto',
						'padding'    => 'clamp(3rem, 6vw, 4.75rem) 1.5rem clamp(4rem, 8vw, 5.5rem)',
						'box-sizing' => 'border-box',
					]
				),
			],
			'dmf-portfolio-single__content-section',
			[
				'background' => 'var(--gcid-dmf-background, #fafafa)',
				'margin'     => '0',
				'padding'    => '0',
			]
		);

		$bottom_section = $this->build_section_module(
			'Portfolio Single Bottom Section',
			[
				$this->build_row_module(
					'Portfolio Single Bottom Row',
					[
						$this->build_column_module(
							'Portfolio Single Next Project Column',
							[
								$this->build_portfolio_single_next_project_block(),
							],
							'1_2',
							'dmf-portfolio-single__bottom-column dmf-portfolio-single__bottom-column--next'
						),
						$this->build_column_module(
							'Portfolio Single CTA Column',
							[
								$this->build_portfolio_single_cta_block(),
							],
							'1_2',
							'dmf-portfolio-single__bottom-column dmf-portfolio-single__bottom-column--cta'
						),
					],
					'1_2,1_2',
					'dmf-portfolio-single__bottom-row',
					[
						'width'      => '100%',
						'max-width'  => '80rem',
						'margin'     => '0 auto',
						'padding'    => '2rem 1.5rem',
						'box-sizing' => 'border-box',
					]
				),
			],
			'dmf-portfolio-single__bottom-section',
			[
				'background' => '#F4F3F0',
				'margin'     => '0',
				'padding'    => '0',
			]
		);

		return $this->render_divi_block(
			'placeholder',
			[],
			implode(
				"\n",
				[
					$hero_section,
					$metrics_section,
					$content_section,
					$bottom_section,
				]
			)
		);
	}

	private function build_portfolio_single_back_link_block() {
		return $this->build_text_module(
			'Portfolio Single Back Link',
			sprintf(
				'<a class="dmf-portfolio-single__back-link" href="%1$s"><span class="dmf-portfolio-single__back-link-arrow" aria-hidden="true">&larr;</span><span>Back to Portfolio</span></a>',
				esc_url( $this->get_portfolio_page_url() )
			),
			'dmf-portfolio-single__back'
		);
	}

	private function build_portfolio_single_badge_block() {
		$taxonomy   = $this->get_portfolio_loop_taxonomy_slug( 'badge' );
		$badge_html = '';

		if ( '' !== $taxonomy ) {
			$badge_html = $this->build_portfolio_single_terms_token(
				$taxonomy,
				[
					'separator' => '',
					'links'     => 'on',
				]
			);
		}

		return $this->build_text_module(
			'Portfolio Single Badge',
			'<div class="dmf-portfolio-single__badge-list">' . $badge_html . '</div>',
			'dmf-portfolio-single__badge'
		);
	}

	private function build_portfolio_single_title_block() {
		return $this->build_text_module(
			'Portfolio Single Title',
			'<h1 class="dmf-portfolio-single__title">' . $this->build_dynamic_content_token(
				'post_title',
				[
					'before' => '',
					'after'  => '',
				]
			) . '</h1>',
			'dmf-portfolio-single__title-wrap'
		);
	}

	private function build_portfolio_single_subtitle_block() {
		return $this->build_text_module(
			'Portfolio Single Subtitle',
			'<div class="dmf-portfolio-single__subtitle">' . $this->build_portfolio_single_current_meta_token( 'small_top_title' ) . '</div>',
			'dmf-portfolio-single__subtitle-wrap'
		);
	}

	private function build_portfolio_single_meta_block() {
		$taxonomy  = $this->get_portfolio_loop_taxonomy_slug( 'tags' );
		$tags_html = '';

		if ( '' !== $taxonomy ) {
			$tags_html = $this->build_portfolio_single_terms_token(
				$taxonomy,
				[
					'separator' => '',
					'links'     => 'on',
				]
			);
		}

		return $this->build_text_module(
			'Portfolio Single Meta',
			'<div class="dmf-portfolio-single__meta"><div class="dmf-portfolio-single__timeline">' . $this->build_portfolio_single_clock_icon_markup() . '<span class="dmf-portfolio-single__timeline-label">Timeline: </span><span class="dmf-portfolio-single__timeline-value">' . $this->build_portfolio_single_current_meta_token( 'timeline' ) . '</span></div><div class="dmf-portfolio-single__tag-list">' . $tags_html . '</div></div>',
			'dmf-portfolio-single__meta-wrap'
		);
	}

	private function build_portfolio_single_featured_image_block() {
		return $this->render_divi_block(
			'image',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Featured Image',
							],
						],
					],
					'advanced'   => [
						'spacing' => [
							'desktop' => [
								'value' => [
									'showBottomSpace' => 'off',
								],
							],
						],
						'sizing'  => [
							'desktop' => [
								'value' => [
									'forceFullwidth' => 'on',
								],
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-single__image',
								'style' => $this->build_inline_style(
									[
										'width'         => '100%',
										'overflow'      => 'hidden',
										'border-radius' => '1.75rem',
										'box-shadow'    => '0 1.75rem 4rem color-mix(in srgb, var(--gcid-dmf-foreground, #131b26) 22%, transparent)',
									]
								),
							]
						),
					],
				],
				'image'          => [
					'innerContent' => [
						'desktop' => [
							'value' => [
								'src'        => $this->build_dynamic_content_token(
									'post_featured_image',
									[
										'before'         => '',
										'after'          => '',
										'thumbnail_size' => 'large',
									]
								),
								'alt'        => $this->build_dynamic_content_token(
									'post_featured_image_alt_text',
									[
										'before' => '',
										'after'  => '',
									]
								),
								'linkUrl'    => '',
								'linkTarget' => 'off',
							],
						],
					],
					'advanced'     => [
						'lightbox' => [
							'desktop' => [
								'value' => 'off',
							],
						],
						'overlay'  => [
							'desktop' => [
								'value' => [
									'use' => 'off',
								],
							],
						],
					],
				],
			]
		);
	}

	private function build_portfolio_single_copy_section( $admin_label, $class, $lead, $accent, $meta_key ) {
		return $this->build_group_module(
			(string) $admin_label,
			[
				$this->build_portfolio_single_section_heading_block(
					$admin_label . ' Heading',
					(string) $lead,
					(string) $accent
				),
				$this->build_portfolio_single_body_block(
					$admin_label . ' Body',
					(string) $meta_key
				),
			],
			(string) $class
		);
	}

	private function build_portfolio_single_approach_section() {
		return $this->build_group_module(
			'Portfolio Single Approach Section',
			[
				$this->build_portfolio_single_section_heading_block( 'Portfolio Single Approach Heading', 'Our', 'Approach' ),
				$this->build_group_module(
					'Portfolio Single Approach List',
					[
						$this->build_portfolio_single_approach_item_block(),
					],
					'dmf-portfolio-single__approach-list'
				),
			],
			'dmf-portfolio-single__section dmf-portfolio-single__section--approach'
		);
	}

	private function build_portfolio_single_results_section() {
		return $this->build_group_module(
			'Portfolio Single Results Section',
			[
				$this->build_portfolio_single_section_heading_block( 'Portfolio Single Results Heading', 'The', 'Results' ),
				$this->build_portfolio_single_body_block(
					'Portfolio Single Results Intro',
					'outcome',
					'dmf-portfolio-single__body dmf-portfolio-single__body--results-intro'
				),
				$this->build_group_module(
					'Portfolio Single Results Grid',
					[
						$this->build_portfolio_single_result_card_block(),
					],
					'dmf-portfolio-single__results-grid'
				),
			],
			'dmf-portfolio-single__section dmf-portfolio-single__section--results'
		);
	}

	private function build_portfolio_single_quote_block() {
		return $this->build_group_module(
			'Portfolio Single Quote Block',
			[
				$this->build_text_module(
					'Portfolio Single Quote Mark',
					'<div class="dmf-portfolio-single__quote-mark">&ldquo;</div>',
					'dmf-portfolio-single__quote-mark-wrap'
				),
				$this->build_text_module(
					'Portfolio Single Quote Text',
					'<div class="dmf-portfolio-single__quote-text">' . $this->build_portfolio_single_current_meta_token( 'testimonial_quote' ) . '</div>',
					'dmf-portfolio-single__quote-text-wrap'
				),
				$this->build_text_module(
					'Portfolio Single Quote Author',
					'<div class="dmf-portfolio-single__quote-author">' . $this->build_portfolio_single_current_meta_token( 'testimonial_author' ) . '</div>',
					'dmf-portfolio-single__quote-author-wrap'
				),
				$this->build_text_module(
					'Portfolio Single Quote Role',
					'<div class="dmf-portfolio-single__quote-role">' . $this->build_portfolio_single_current_meta_token( 'testimonial_role' ) . '</div>',
					'dmf-portfolio-single__quote-role-wrap'
				),
			],
			'dmf-portfolio-single__quote-box'
		);
	}

	private function build_portfolio_single_next_project_block() {
		return $this->build_group_module(
			'Portfolio Single Next Project',
			[
				$this->build_text_module(
					'Portfolio Single Next Project Label',
					'<div class="dmf-portfolio-single__next-project-label">Next Project</div>',
					'dmf-portfolio-single__next-project-label-wrap'
				),
				$this->render_divi_block(
					'post-nav',
					[
						'builderVersion' => 0.7,
						'module'         => [
							'meta'       => [
								'adminLabel' => [
									'desktop' => [
										'value' => 'Portfolio Single Next Project Navigation',
									],
								],
							],
							'advanced'   => [
								'inSameTerm'   => [
									'desktop' => [
										'value' => 'off',
									],
								],
								'taxonomyName' => [
									'desktop' => [
										'value' => '',
									],
								],
								'targetLoop'   => [
									'desktop' => [
										'value' => 'main_query',
									],
								],
							],
							'decoration' => [
								'attributes' => $this->build_custom_attributes(
									[
										'class' => 'dmf-portfolio-single__post-nav',
									]
								),
							],
						],
						'links'          => [
							'advanced' => [
								'prevText' => [
									'desktop' => [
										'value' => '',
									],
								],
								'nextText' => [
									'desktop' => [
										'value' => '%title',
									],
								],
								'showPrev' => [
									'desktop' => [
										'value' => 'off',
									],
								],
								'showNext' => [
									'desktop' => [
										'value' => 'on',
									],
								],
							],
						],
					]
				),
			],
			'dmf-portfolio-single__next-project'
		);
	}

	private function build_portfolio_single_cta_block() {
		return $this->build_text_module(
			'Portfolio Single CTA',
			sprintf(
				'<div class="dmf-portfolio-single__cta-wrap"><a class="dmf-portfolio-single__cta-link" href="%1$s">Start Your Project <span aria-hidden="true">&rarr;</span></a></div>',
				esc_url( home_url( '/#contact' ) )
			),
			'dmf-portfolio-single__cta'
		);
	}

	private function build_portfolio_single_section_heading_block( $admin_label, $lead, $accent ) {
		return $this->build_text_module(
			(string) $admin_label,
			sprintf(
				'<h2 class="dmf-portfolio-single__section-title">%1$s <span>%2$s</span></h2>',
				esc_html( (string) $lead ),
				esc_html( (string) $accent )
			),
			'dmf-portfolio-single__section-title-wrap'
		);
	}

	private function build_portfolio_single_body_block( $admin_label, $meta_key, $class = 'dmf-portfolio-single__body' ) {
		return $this->build_text_module(
			(string) $admin_label,
			'<div class="' . esc_attr( (string) $class ) . '">' . $this->build_portfolio_single_current_meta_token( $meta_key ) . '</div>',
			'dmf-portfolio-single__body-wrap'
		);
	}

	private function build_portfolio_single_metric_item_block( $admin_label, $class ) {
		return $this->build_loop_group_module(
			(string) $admin_label,
			[
				$this->build_text_module(
					$admin_label . ' Value',
					'<div class="dmf-portfolio-single__metric-value">' . $this->build_portfolio_single_repeater_token( 'result_metrics', 'metric_value' ) . '</div>',
					'dmf-portfolio-single__metric-value-wrap'
				),
				$this->build_text_module(
					$admin_label . ' Label',
					'<div class="dmf-portfolio-single__metric-label">' . $this->build_portfolio_single_repeater_token( 'result_metrics', 'metric_label' ) . '</div>',
					'dmf-portfolio-single__metric-label-wrap'
				),
			],
			[
				'queryType'          => 'repeater_result_metrics',
				'orderBy'            => 'date',
				'order'              => 'ascending',
				'postPerPage'        => '8',
				'postOffset'         => '0',
				'excludeCurrentPost' => 'off',
				'ignoreStickysPost'  => 'on',
				'loopId'             => sanitize_key( (string) $admin_label ),
			],
			(string) $class
		);
	}

	private function build_portfolio_single_result_card_block() {
		return $this->build_portfolio_single_metric_item_block(
			'Portfolio Single Results Card',
			'dmf-portfolio-single__result-card'
		);
	}

	private function build_portfolio_single_approach_item_block() {
		return $this->build_loop_group_module(
			'Portfolio Single Approach Item',
			[
				$this->build_text_module(
					'Portfolio Single Approach Copy',
					'<div class="dmf-portfolio-single__approach-copy">' . $this->build_portfolio_single_repeater_token( 'approach_steps', 'step_text' ) . '</div>',
					'dmf-portfolio-single__approach-copy-wrap'
				),
			],
			[
				'queryType'          => 'repeater_approach_steps',
				'orderBy'            => 'date',
				'order'              => 'ascending',
				'postPerPage'        => '12',
				'postOffset'         => '0',
				'excludeCurrentPost' => 'off',
				'ignoreStickysPost'  => 'on',
				'loopId'             => 'dmfPortfolioSingleApproach',
			],
			'dmf-portfolio-single__approach-item'
		);
	}

	private function build_portfolio_single_current_meta_token( $meta_key, array $settings = [] ) {
		$meta_key = sanitize_key( (string) $meta_key );

		if ( '' === $meta_key ) {
			return '';
		}

		return $this->build_dynamic_content_token(
			'custom_meta_' . $meta_key,
			array_merge(
				[
					'before' => '',
					'after'  => '',
				],
				$settings
			)
		);
	}

	private function build_portfolio_single_terms_token( $taxonomy, array $settings = [] ) {
		$taxonomy = sanitize_key( (string) $taxonomy );

		if ( '' === $taxonomy ) {
			return '';
		}

		return $this->build_dynamic_content_token(
			'post_categories',
			array_merge(
				[
					'before'        => '',
					'after'         => '',
					'category_type' => $taxonomy,
					'separator'     => '',
					'links'         => 'on',
				],
				$settings
			)
		);
	}

	private function build_portfolio_single_repeater_token( $repeater_name, $field_name, array $settings = [] ) {
		$repeater_name = sanitize_key( (string) $repeater_name );
		$field_name    = sanitize_key( (string) $field_name );

		if ( '' === $repeater_name || '' === $field_name ) {
			return '';
		}

		return $this->build_dynamic_content_token(
			'loop_acf_' . $repeater_name . '|||' . $field_name,
			array_merge(
				[
					'before' => '',
					'after'  => '',
				],
				$settings
			)
		);
	}

	private function build_portfolio_single_loop_settings( array $loop_args ) {
		$defaults  = [
			'queryType'          => 'post_types',
			'subTypes'           => [],
			'orderBy'            => 'date',
			'order'              => 'descending',
			'postPerPage'        => '10',
			'postOffset'         => '0',
			'excludeCurrentPost' => 'off',
			'ignoreStickysPost'  => 'on',
			'loopId'             => 'dmfPortfolioSingleLoop',
		];
		$loop_args = wp_parse_args( $loop_args, $defaults );
		$value     = [
			'enable'             => 'on',
			'queryType'          => (string) $loop_args['queryType'],
			'orderBy'            => (string) $loop_args['orderBy'],
			'order'              => (string) $loop_args['order'],
			'postPerPage'        => (string) $loop_args['postPerPage'],
			'postOffset'         => (string) $loop_args['postOffset'],
			'excludeCurrentPost' => (string) $loop_args['excludeCurrentPost'],
			'ignoreStickysPost'  => (string) $loop_args['ignoreStickysPost'],
			'loopId'             => (string) $loop_args['loopId'],
		];

		if ( ! empty( $loop_args['subTypes'] ) && is_array( $loop_args['subTypes'] ) ) {
			$value['subTypes'] = array_map(
				static function ( $sub_type ) {
					return [
						'value' => sanitize_key( (string) $sub_type ),
					];
				},
				array_values( $loop_args['subTypes'] )
			);
		}

		return [
			'desktop' => [
				'value' => $value,
			],
		];
	}

	private function build_portfolio_single_clock_icon_markup() {
		return '<svg class="dmf-portfolio-single__timeline-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>';
	}

	private function build_portfolio_single_styles_markup() {
		return <<<'HTML'
<style>
.dmf-portfolio-single__hero-row,.dmf-portfolio-single__metrics-row,.dmf-portfolio-single__content-row,.dmf-portfolio-single__bottom-row{width:100%!important;margin-left:auto!important;margin-right:auto!important}
.dmf-portfolio-single__hero-copy{display:flex!important;flex-direction:column!important;align-items:flex-start!important;gap:1rem!important}
.dmf-portfolio-single__back-link{display:inline-flex!important;align-items:center!important;gap:.55rem!important;font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-sm)!important;font-weight:600!important;line-height:1.4!important;color:rgba(250,250,250,.72)!important;text-decoration:none!important}
.dmf-portfolio-single__back-link:hover{opacity:1!important;color:var(--gcid-dmf-white,#fafafa)!important}
.dmf-portfolio-single__back-link-arrow{font-size:1rem!important;line-height:1!important}
.dmf-portfolio-single__badge-list{display:flex!important;flex-wrap:wrap!important;gap:.5rem!important}
.dmf-portfolio-single__badge-list:empty,.dmf-portfolio-single__subtitle:empty,.dmf-portfolio-single__quote-role:empty,.dmf-portfolio-single__quote-author:empty{display:none!important}
.dmf-portfolio-single__badge-list a{display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:.5rem .95rem!important;border-radius:999px!important;background:var(--gcid-dmf-accent,#941213)!important;color:var(--gcid-dmf-white,#fafafa)!important;font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-xs)!important;font-weight:700!important;line-height:1.1!important;letter-spacing:.02em!important;text-decoration:none!important}
.dmf-portfolio-single__title{margin:0!important;font-family:var(--gvid-dmf-heading-font)!important;font-size:clamp(2.6rem,5vw,4.4rem)!important;font-weight:700!important;line-height:1.05!important;color:var(--gcid-dmf-white,#fafafa)!important}
.dmf-portfolio-single__subtitle{font-family:var(--gvid-dmf-body-font)!important;font-size:clamp(1rem,calc(1rem + .24vw),1.18rem)!important;line-height:1.7!important;color:rgba(250,250,250,.76)!important}
.dmf-portfolio-single__meta{display:flex!important;flex-wrap:wrap!important;align-items:center!important;gap:.85rem 1rem!important}
.dmf-portfolio-single__timeline{display:inline-flex!important;align-items:center!important;gap:.45rem!important;color:rgba(250,250,250,.74)!important;font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-sm)!important;line-height:1.5!important}
.dmf-portfolio-single__timeline-icon{width:1rem!important;height:1rem!important;flex:0 0 auto!important}
.dmf-portfolio-single__timeline:has(.dmf-portfolio-single__timeline-value:empty){display:none!important}
.dmf-portfolio-single__tag-list{display:flex!important;flex-wrap:wrap!important;gap:.5rem!important}
.dmf-portfolio-single__tag-list:empty{display:none!important}
.dmf-portfolio-single__tag-list a{display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:.42rem .76rem!important;border-radius:999px!important;background:rgba(250,250,250,.12)!important;color:rgba(250,250,250,.84)!important;font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-xs)!important;font-weight:600!important;line-height:1.1!important;text-decoration:none!important}
.dmf-portfolio-single__meta:has(.dmf-portfolio-single__timeline-value:empty):has(.dmf-portfolio-single__tag-list:empty){display:none!important}
.dmf-portfolio-single__image img{display:block!important;width:100%!important;height:100%!important;min-height:24rem!important;max-height:34rem!important;object-fit:cover!important;border-radius:1.75rem!important}
.dmf-portfolio-single__metrics-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:1.25rem!important}
.dmf-portfolio-single__metric-card,.dmf-portfolio-single__result-card{text-align:center!important}
.dmf-portfolio-single__metric-value{font-family:var(--gvid-dmf-heading-font)!important;font-size:clamp(2rem,3vw,2.8rem)!important;font-weight:700!important;line-height:1.05!important;color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-portfolio-single__metric-label{margin-top:.35rem!important;font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-sm)!important;line-height:1.45!important;color:var(--gcid-dmf-muted,#486262)!important}
.dmf-portfolio-single__metrics-grid>.entry,.dmf-portfolio-single__approach-list>.entry,.dmf-portfolio-single__results-grid>.entry{display:none!important}
.dmf-portfolio-single__metrics-section:has(.dmf-portfolio-single__metrics-grid:empty),.dmf-portfolio-single__metrics-section:has(.dmf-portfolio-single__metrics-grid>.entry:only-child){display:none!important}
.dmf-portfolio-single__content-stack{display:flex!important;flex-direction:column!important;gap:3rem!important}
.dmf-portfolio-single__section{display:flex!important;flex-direction:column!important;gap:1rem!important}
.dmf-portfolio-single__section-title{margin:0!important;font-family:var(--gvid-dmf-heading-font)!important;font-size:clamp(2rem,4vw,3rem)!important;font-weight:700!important;line-height:1.14!important;color:var(--gcid-dmf-foreground,#131b26)!important}
.dmf-portfolio-single__section-title span{color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-portfolio-single__body{font-family:var(--gvid-dmf-body-font)!important;font-size:clamp(.98rem,calc(.98rem + .18vw),1.08rem)!important;line-height:1.9!important;color:var(--gcid-dmf-muted,#486262)!important;white-space:pre-line!important}
.dmf-portfolio-single__section--overview:has(.dmf-portfolio-single__body:empty),.dmf-portfolio-single__section--challenge:has(.dmf-portfolio-single__body:empty){display:none!important}
.dmf-portfolio-single__approach-list{display:flex!important;flex-direction:column!important;gap:1rem!important;counter-reset:dmf-approach!important}
.dmf-portfolio-single__approach-item{counter-increment:dmf-approach!important;display:grid!important;grid-template-columns:auto minmax(0,1fr)!important;align-items:start!important;gap:1rem!important}
.dmf-portfolio-single__approach-item::before{content:counter(dmf-approach)!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;width:2rem!important;height:2rem!important;border-radius:999px!important;background:var(--gcid-dmf-primary,#2b5b5b)!important;color:var(--gcid-dmf-white,#fafafa)!important;font-family:var(--gvid-dmf-heading-font)!important;font-size:.92rem!important;font-weight:700!important;line-height:1!important}
.dmf-portfolio-single__approach-copy{padding-top:.15rem!important;font-family:var(--gvid-dmf-body-font)!important;font-size:clamp(.98rem,calc(.98rem + .12vw),1.04rem)!important;line-height:1.8!important;color:var(--gcid-dmf-muted,#486262)!important;white-space:pre-line!important}
.dmf-portfolio-single__section--approach:has(.dmf-portfolio-single__approach-list:empty),.dmf-portfolio-single__section--approach:has(.dmf-portfolio-single__approach-list>.entry:only-child){display:none!important}
.dmf-portfolio-single__body--results-intro:empty{display:none!important}
.dmf-portfolio-single__results-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:1rem!important}
.dmf-portfolio-single__result-card{padding:1.55rem 1.2rem!important;border:1px solid color-mix(in srgb,var(--gcid-dmf-border,#a1a5a4) 45%,transparent)!important;border-radius:1.25rem!important;background:var(--gcid-dmf-white,#fafafa)!important;box-shadow:0 1rem 2.5rem color-mix(in srgb,var(--gcid-dmf-foreground,#131b26) 6%,transparent)!important}
.dmf-portfolio-single__section--results:has(.dmf-portfolio-single__body--results-intro:empty):has(.dmf-portfolio-single__results-grid:empty),.dmf-portfolio-single__section--results:has(.dmf-portfolio-single__body--results-intro:empty):has(.dmf-portfolio-single__results-grid>.entry:only-child){display:none!important}
.dmf-portfolio-single__quote-box{margin-top:.5rem!important;padding:2rem 2.25rem!important;border-radius:1.5rem!important;background:var(--gcid-dmf-primary,#2b5b5b)!important;display:flex!important;flex-direction:column!important;gap:.65rem!important}
.dmf-portfolio-single__quote-box:has(.dmf-portfolio-single__quote-text:empty){display:none!important}
.dmf-portfolio-single__quote-mark{font-family:var(--gvid-dmf-heading-font)!important;font-size:3rem!important;line-height:1!important;color:color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 65%,var(--gcid-dmf-white,#fafafa))!important}
.dmf-portfolio-single__quote-text{font-family:var(--gvid-dmf-heading-font)!important;font-size:clamp(1.35rem,2.4vw,2rem)!important;font-weight:600!important;line-height:1.45!important;color:var(--gcid-dmf-white,#fafafa)!important;white-space:pre-line!important}
.dmf-portfolio-single__quote-author{font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-sm)!important;font-weight:700!important;line-height:1.5!important;color:var(--gcid-dmf-white,#fafafa)!important}
.dmf-portfolio-single__quote-role{font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-sm)!important;line-height:1.5!important;color:rgba(250,250,250,.68)!important}
.dmf-portfolio-single__bottom-row{align-items:center!important}
.dmf-portfolio-single__next-project{display:flex!important;flex-direction:column!important;align-items:flex-start!important;gap:.45rem!important}
.dmf-portfolio-single__next-project:has(.dmf-portfolio-single__post-nav:empty),.dmf-portfolio-single__next-project:has(.dmf-portfolio-single__post-nav .nav-next:empty){display:none!important}
.dmf-portfolio-single__next-project-label{font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-xs)!important;font-weight:700!important;line-height:1.2!important;letter-spacing:.16em!important;text-transform:uppercase!important;color:var(--gcid-dmf-muted,#486262)!important}
.dmf-portfolio-single__post-nav{margin:0!important;width:auto!important}
.dmf-portfolio-single__post-nav .nav-single{display:block!important;margin:0!important;text-align:left!important}
.dmf-portfolio-single__post-nav .nav-previous,.dmf-portfolio-single__post-nav .meta-nav:empty{display:none!important}
.dmf-portfolio-single__post-nav .nav-next{float:none!important;display:block!important;margin:0!important;text-align:left!important}
.dmf-portfolio-single__post-nav .nav-next a{display:inline-flex!important;align-items:center!important;gap:.45rem!important;font-family:var(--gvid-dmf-heading-font)!important;font-size:clamp(1.15rem,1.9vw,1.45rem)!important;font-weight:700!important;line-height:1.25!important;color:var(--gcid-dmf-foreground,#131b26)!important;text-decoration:none!important;padding:0!important;background:none!important;border:0!important;box-shadow:none!important}
.dmf-portfolio-single__post-nav .nav-next a:hover{opacity:1!important;color:var(--gcid-dmf-primary,#2b5b5b)!important}
.dmf-portfolio-single__post-nav .nav-next .meta-nav{display:inline-flex!important;align-items:center!important;justify-content:center!important;font-size:1rem!important;line-height:1!important;color:inherit!important}
.dmf-portfolio-single__cta-wrap{display:flex!important;justify-content:flex-end!important}
.dmf-portfolio-single__cta-link{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:.55rem!important;padding:.95rem 1.5rem!important;border-radius:1rem!important;border:1px solid color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 78%,var(--gcid-dmf-foreground,#131b26))!important;background:var(--gcid-dmf-primary,#2b5b5b)!important;color:var(--gcid-dmf-white,#fafafa)!important;box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-primary,#2b5b5b) 24%,transparent)!important;font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-base)!important;font-weight:700!important;line-height:1.1!important;text-decoration:none!important}
.dmf-portfolio-single__cta-link:hover{opacity:1!important;transform:translateY(-1px)!important}
@media (max-width:980px){
.dmf-portfolio-single__hero-row{padding-top:clamp(7rem,16vw,8.5rem)!important}
.dmf-portfolio-single__image img{min-height:20rem!important;max-height:none!important}
.dmf-portfolio-single__metrics-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}
.dmf-portfolio-single__bottom-row{display:flex!important;flex-direction:column!important;align-items:flex-start!important;gap:1.5rem!important}
.dmf-portfolio-single__cta-wrap{justify-content:flex-start!important}
}
@media (max-width:767px){
.dmf-portfolio-single__hero-row,.dmf-portfolio-single__metrics-row,.dmf-portfolio-single__content-row,.dmf-portfolio-single__bottom-row{padding-left:1rem!important;padding-right:1rem!important}
.dmf-portfolio-single__metrics-grid,.dmf-portfolio-single__results-grid{grid-template-columns:1fr!important}
.dmf-portfolio-single__quote-box{padding:1.5rem!important}
.dmf-portfolio-single__image img{min-height:17rem!important;border-radius:1.25rem!important}
}
</style>
HTML;
	}

	private function upsert_theme_builder_layout( array $layout_export, $existing_layout_id, $dry_run ) {
		$post_type = sanitize_key( (string) ( $layout_export['post_type'] ?? '' ) );
		$title     = sanitize_text_field( (string) ( $layout_export['post_title'] ?? 'Theme Builder Layout' ) );
		$content   = $this->normalize_theme_builder_layout_content(
			$layout_export,
			$this->get_single_layout_content( $layout_export['data'] ?? [] )
		);

		if ( '' === $post_type ) {
			throw new RuntimeException( 'Theme Builder layout export is missing post_type.' );
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
				throw new RuntimeException(
					'Failed to update Theme Builder layout #' . $existing_layout_id . ': ' . $result->get_error_message()
				);
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
				throw new RuntimeException( 'Failed to create Theme Builder layout: ' . $message );
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

	private function normalize_theme_builder_layout_content( array $layout_export, $content ) {
		$post_type = sanitize_key( (string) ( $layout_export['post_type'] ?? '' ) );

		if ( 'et_header_layout' === $post_type ) {
			return $this->apply_global_header_layout_fix( (string) $content );
		}

		if ( 'et_footer_layout' === $post_type ) {
			return $this->apply_global_footer_layout_fix( (string) $content );
		}

		return (string) $content;
	}

	private function apply_global_header_layout_fix( $content ) {
		$content = (string) $content;

		if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = parse_blocks( $content );

			if ( ! empty( $blocks ) && is_array( $blocks ) ) {
				$blocks     = $this->mutate_global_header_blocks( $blocks );
				$serialized = serialize_blocks( $blocks );

				if ( is_string( $serialized ) && '' !== $serialized ) {
					$content = $serialized;
				}
			}
		}

		$removed_cta_button = $this->replace_divi_block_by_label( $content, 'Header CTA Button', '' );

		if ( is_string( $removed_cta_button ) ) {
			$content = $removed_cta_button;
		}

		return $this->upsert_divi_block_in_container_by_label(
			$content,
			'Global Header Section',
			'section',
			'Header Runtime Row',
			$this->build_header_runtime_row_markup()
		);
	}

	private function build_global_header_layout_content( $content ) {
		$content    = (string) $content;
		$menu_block = null;

		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $content );

			if ( ! empty( $blocks ) && is_array( $blocks ) ) {
				$menu_block = $this->find_divi_block_by_admin_label( $blocks, 'Primary Navigation' );
			}
		}

		if ( is_array( $menu_block ) ) {
			$menu_block['attrs'] = $this->mutate_global_header_menu_attrs( $menu_block['attrs'] ?? [] );
			$menu_markup         = function_exists( 'serialize_blocks' ) ? serialize_blocks( [ $menu_block ] ) : '';
		} else {
			$menu_markup = '';
		}

		if ( ! is_string( $menu_markup ) || '' === trim( $menu_markup ) ) {
			$menu_markup = $this->render_divi_block(
				'placeholder',
				[],
				'<!-- Missing Primary Navigation menu block -->'
			);
		}

		return $this->build_section_module(
			'Global Header Section',
			[
				$this->build_row_module(
					'Header Runtime Row',
					[
						$this->build_column_module(
							'Header Runtime Column',
							[
								$this->build_header_runtime_block_markup(),
							],
							'4_4',
							'dmf-header-runtime-column'
						),
					],
					'4_4',
					'dmf-header-runtime-row'
				),
				$this->build_row_module(
					'Header Row',
					[
						$this->build_column_module(
							'Header Menu Column',
							[
								$menu_markup,
								$this->build_header_cta_button_block_markup(),
							],
							'4_4',
							'dmf-global-header-column'
						),
					],
					'4_4',
					'dmf-global-header-row'
				),
			],
			'dmf-global-header-shell',
			[
				'position' => 'absolute',
				'top'      => '0',
				'right'    => '0',
				'left'     => '0',
				'width'    => '100%',
				'margin'   => '0',
				'padding'  => '0',
				'z-index'  => '999',
				'overflow' => 'visible',
			]
		);
	}

	private function mutate_global_header_blocks( array $blocks ) {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name  = (string) ( $block['blockName'] ?? '' );
			$admin_label = $this->get_divi_block_admin_label( $block );

			if ( 'divi/section' === $block_name && 'Global Header Section' === $admin_label ) {
				$block['attrs'] = $this->mutate_global_header_section_attrs( $block['attrs'] ?? [] );
			} elseif ( 'divi/row' === $block_name && 'Header Row' === $admin_label ) {
				$block['attrs']['module']['decoration']['attributes'] = $this->build_custom_attributes(
					[
						'class' => 'dmf-global-header-row',
						'style' => $this->build_inline_style(
							[
								'width'      => '100%',
								'max-width'  => '80rem',
								'margin'     => '0 auto',
								'min-height' => '64px',
								'padding'    => '0 1.5rem',
								'background' => 'transparent',
								'box-sizing' => 'border-box',
							]
						),
					]
				);
				$block['attrs']['module']['decoration']['spacing'] = $this->build_spacing_settings(
					[
						'top'    => '0px',
						'right'  => '0px',
						'bottom' => '0px',
						'left'   => '0px',
					]
				);
			} elseif ( 'divi/column' === $block_name && 'Header Menu Column' === $admin_label ) {
				$block['attrs']['module']['decoration']['attributes'] = $this->build_custom_attributes(
					[
						'class' => 'dmf-global-header-column',
						'style' => $this->build_inline_style(
							[
								'background' => 'transparent',
								'margin'     => '0',
								'padding'    => '0',
							]
						),
					]
				);
			} elseif ( 'divi/menu' === $block_name && 'Primary Navigation' === $admin_label ) {
				$block['attrs'] = $this->mutate_global_header_menu_attrs( $block['attrs'] ?? [] );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->mutate_global_header_blocks( $block['innerBlocks'] );
			}
		}

		unset( $block );

		return $blocks;
	}

	private function get_divi_block_admin_label( array $block ) {
		return (string) ( $block['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? '' );
	}

	private function mutate_global_header_section_attrs( array $attrs ) {
		$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes(
			[
				'class' => 'dmf-global-header-shell',
				'style' => $this->build_inline_style(
					[
						'position'   => 'absolute',
						'top'        => 'var(--wp-admin--admin-bar--height, 0px)',
						'right'      => '0',
						'left'       => '0',
						'width'      => '100%',
						'margin'     => '0',
						'padding'    => '0',
						'z-index'    => '999',
						'overflow'   => 'visible',
					]
				),
			]
		);
		$attrs['module']['decoration']['spacing'] = $this->build_spacing_settings(
			[
				'top'    => '0px',
				'right'  => '0px',
				'bottom' => '0px',
				'left'   => '0px',
			],
			[
				'top'    => '0px',
				'right'  => '0px',
				'bottom' => '0px',
				'left'   => '0px',
			]
		);
		$attrs['module']['decoration']['background']['desktop']['value']['color']  = 'transparent';
		unset( $attrs['module']['decoration']['background']['desktop']['sticky'] );
		unset( $attrs['module']['decoration']['sticky'] );
		$attrs['module']['decoration']['transition']['desktop']['value'] = [
			'duration'   => '220ms',
			'delay'      => '0ms',
			'speedCurve' => 'ease',
		];

		return $attrs;
	}

	private function mutate_global_header_menu_attrs( array $attrs ) {
		$attrs['module']['decoration']['attributes'] = $this->build_custom_attributes(
			[
				'class' => 'dmf-global-header-menu',
				'style' => $this->build_inline_style(
					[
						'width'      => '100%',
						'background' => 'transparent',
						'border'     => '0',
						'box-shadow' => 'none',
					]
				),
			]
		);
		$attrs['module']['decoration']['transition']['desktop']['value'] = [
			'duration'   => '220ms',
			'delay'      => '0ms',
			'speedCurve' => 'ease',
		];

		return $attrs;
	}

	private function build_header_runtime_block_markup() {
		return $this->render_divi_block(
			'code',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Header Runtime',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-header-runtime',
								'style' => $this->build_inline_style(
									[
										'position'       => 'absolute',
										'width'          => '0',
										'height'         => '0',
										'overflow'       => 'hidden',
										'opacity'        => '0',
										'pointer-events' => 'none',
										'margin'         => '0',
										'padding'        => '0',
									]
								),
							]
						),
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => $this->build_header_runtime_markup(),
						],
					],
				],
			]
		);
	}

	private function build_header_runtime_row_markup() {
		return $this->build_row_module(
			'Header Runtime Row',
			[
				$this->build_column_module(
					'Header Runtime Column',
					[
						$this->build_header_runtime_block_markup(),
					],
					'4_4',
					'dmf-header-runtime-column'
				),
			],
			'4_4',
			'dmf-header-runtime-row'
		);
	}

	private function build_header_cta_button_block_markup() {
		return $this->build_button_module(
			'Header CTA Button',
			'Free Consultation',
			home_url( '/#contact' ),
			'left',
			'dmf-header-cta-button'
		);
	}

	private function build_header_runtime_markup() {
		return <<<'HTML'
<style id="dmf-header-runtime-styles">
.et-l--header{
	position:absolute !important;
	top:var(--wp-admin--admin-bar--height,0px) !important;
	right:0 !important;
	left:0 !important;
	width:100% !important;
	z-index:999 !important;
	background:transparent !important;
}
.et-l--header .et_builder_inner_content,
.et-l--header .et_pb_section{
	background:transparent !important;
}
.dmf-global-header-shell{
	position:absolute !important;
	top:var(--wp-admin--admin-bar--height,0px) !important;
	right:0 !important;
	left:0 !important;
	width:100% !important;
	background:transparent !important;
	transition:background-color 220ms ease, color 220ms ease;
}
.dmf-header-runtime-row,
.dmf-header-runtime-column{
	width:0 !important;
	max-width:0 !important;
	height:0 !important;
	margin:0 !important;
	padding:0 !important;
	overflow:hidden !important;
}
.dmf-global-header-shell,
.dmf-global-header-shell .et_pb_section{
	margin:0 !important;
	padding:0 !important;
}
.dmf-global-header-shell .dmf-global-header-row{
	width:min(80rem,calc(100% - 3rem)) !important;
	max-width:none !important;
	margin:0 auto !important;
	min-height:64px !important;
	padding:0 !important;
	display:flex !important;
	align-items:center !important;
}
.dmf-global-header-shell.dmf-header-is-scrolled{
	background:rgba(237,236,237,0.96) !important;
	position:fixed !important;
	top:var(--wp-admin--admin-bar--height,0px) !important;
	right:0 !important;
	left:0 !important;
}
.dmf-global-header-shell .dmf-global-header-column{
	padding:0 !important;
	margin:0 !important;
}
.dmf-global-header-shell .dmf-global-header-menu{
	background:transparent !important;
	margin:0 !important;
	padding:0 !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__wrap{
	width:100% !important;
	min-height:64px !important;
	justify-content:space-between !important;
	align-items:center !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__logo-wrap{
	margin-right:1.35rem !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__logo-wrap img{
	max-height:2.7rem !important;
	width:auto !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__menu,
.dmf-global-header-shell .dmf-global-header-menu .et-menu-nav{
	margin-left:auto !important;
}
.dmf-global-header-shell .dmf-global-header-menu ul.et-menu{
	align-items:center !important;
	gap:1.15rem !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et-menu > li{
	padding:0 !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et-menu>li>a,
.dmf-global-header-shell .dmf-global-header-menu .et-menu-nav>ul>li>a,
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__menu>nav>ul>li>a,
.dmf-global-header-shell .dmf-global-header-menu .et_mobile_menu a,
.dmf-global-header-shell .dmf-global-header-menu .mobile_menu_bar:before,
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__icon,
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__search-button,
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__cart-button{
	color:rgba(250,250,250,0.76) !important;
	font-family:var(--gvid-dmf-body-font) !important;
	font-size:.92rem !important;
	font-weight:600 !important;
	line-height:1 !important;
	padding:0 !important;
	transition:color 220ms ease, opacity 220ms ease, background-color 220ms ease;
}
.dmf-global-header-shell .dmf-global-header-menu .current-menu-item>a,
.dmf-global-header-shell .dmf-global-header-menu .current-menu-ancestor>a,
.dmf-global-header-shell .dmf-global-header-menu .current_page_item>a,
.dmf-global-header-shell .dmf-global-header-menu .current-page-ancestor>a{
	color:var(--gcid-dmf-white,#fafafa) !important;
}
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .et-menu>li>a,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .et-menu-nav>ul>li>a,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .et_pb_menu__menu>nav>ul>li>a,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .et_mobile_menu a,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .mobile_menu_bar:before,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .et_pb_menu__icon,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .et_pb_menu__search-button,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .et_pb_menu__cart-button{
	color:var(--gcid-dmf-muted,#486262) !important;
}
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .current-menu-item>a,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .current-menu-ancestor>a,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .current_page_item>a,
.dmf-global-header-shell.dmf-header-is-scrolled .dmf-global-header-menu .current-page-ancestor>a{
	color:var(--gcid-dmf-foreground,#131b26) !important;
}
.dmf-global-header-shell .dmf-global-header-menu .dmf-menu-cta>a,
.dmf-global-header-shell .dmf-global-header-menu li.dmf-menu-cta>a,
.dmf-global-header-shell .dmf-global-header-menu .et-menu li.dmf-menu-cta>a,
.dmf-global-header-shell .dmf-global-header-menu .et-menu-nav li.dmf-menu-cta>a,
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__menu li.dmf-menu-cta>a,
.dmf-global-header-shell .dmf-global-header-menu a.dmf-menu-cta{
	background:var(--gcid-dmf-white,#fafafa) !important;
	background-color:var(--gcid-dmf-white,#fafafa) !important;
	background-image:none !important;
	border:1px solid color-mix(in srgb, var(--gcid-dmf-primary,#2b5b5b) 22%, transparent) !important;
	border-radius:0.58rem !important;
	box-shadow:0 .6rem 1.4rem color-mix(in srgb, var(--gcid-dmf-primary,#2b5b5b) 12%, transparent) !important;
	display:inline-flex !important;
	align-items:center !important;
	justify-content:center !important;
	font-family:var(--gvid-dmf-body-font) !important;
	font-size:0.82rem !important;
	font-weight:700 !important;
	line-height:1 !important;
	letter-spacing:.01em !important;
	margin:0 !important;
	min-height:2.35rem !important;
	min-width:max-content !important;
	max-width:none !important;
	padding:0.5rem 0.92rem !important;
	white-space:nowrap !important;
	text-decoration:none !important;
	word-break:normal !important;
	overflow-wrap:normal !important;
	-webkit-text-fill-color:var(--gcid-dmf-foreground,#131b26) !important;
	color:var(--gcid-dmf-foreground,#131b26) !important;
	box-sizing:border-box !important;
}
.dmf-global-header-shell .dmf-global-header-menu .dmf-menu-cta>a:after,
.dmf-global-header-shell .dmf-global-header-menu li.dmf-menu-cta>a:after,
.dmf-global-header-shell .dmf-global-header-menu .et-menu li.dmf-menu-cta>a:after,
.dmf-global-header-shell .dmf-global-header-menu .et-menu-nav li.dmf-menu-cta>a:after,
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__menu li.dmf-menu-cta>a:after,
.dmf-global-header-shell .dmf-global-header-menu a.dmf-menu-cta:after{
	display:none !important;
}
.dmf-global-header-shell .dmf-global-header-menu .dmf-menu-cta>a:hover,
.dmf-global-header-shell .dmf-global-header-menu li.dmf-menu-cta>a:hover,
.dmf-global-header-shell .dmf-global-header-menu .et-menu li.dmf-menu-cta>a:hover,
.dmf-global-header-shell .dmf-global-header-menu .et-menu-nav li.dmf-menu-cta>a:hover,
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__menu li.dmf-menu-cta>a:hover,
.dmf-global-header-shell .dmf-global-header-menu a.dmf-menu-cta:hover{
	background:var(--gcid-dmf-white,#fafafa) !important;
	background-color:var(--gcid-dmf-white,#fafafa) !important;
	background-image:none !important;
	border-color:color-mix(in srgb, var(--gcid-dmf-primary,#2b5b5b) 32%, transparent) !important;
	box-shadow:0 .8rem 1.65rem color-mix(in srgb, var(--gcid-dmf-primary,#2b5b5b) 16%, transparent) !important;
	transform:translateY(-1px) !important;
	opacity:1 !important;
}
@media (max-width: 980px){
	.dmf-global-header-shell .dmf-global-header-row{
		width:min(80rem,calc(100% - 2rem)) !important;
		min-height:64px !important;
		padding:0 !important;
	}
	.dmf-global-header-shell .dmf-global-header-menu li.dmf-menu-cta{
		display:none !important;
	}
}
</style>
<script id="dmf-header-runtime-script">
(function(){
	if(window.__dmfHeaderRuntimeLoaded){return;}
	window.__dmfHeaderRuntimeLoaded=true;
	var initialized=false;
	var ticking=false;
	var threshold=12;
	var observer=null;
	var headerStyles={
		backgroundTop:'transparent',
		backgroundScrolled:'rgba(237,236,237,0.96)',
		linkTop:'rgba(250,250,250,0.76)',
		linkActiveTop:'var(--gcid-dmf-white,#fafafa)',
		linkScrolled:'var(--gcid-dmf-muted,#486262)',
		linkActiveScrolled:'var(--gcid-dmf-foreground,#131b26)',
		ctaBackground:'var(--gcid-dmf-white,#fafafa)',
		ctaText:'var(--gcid-dmf-foreground,#131b26)',
		ctaBorder:'1px solid color-mix(in srgb, var(--gcid-dmf-primary,#2b5b5b) 22%, transparent)'
	};
	var menuLinkSelector='.dmf-global-header-menu .et-menu>li>a,.dmf-global-header-menu .et-menu-nav>ul>li>a,.dmf-global-header-menu .et_pb_menu__menu>nav>ul>li>a,.dmf-global-header-menu .et_mobile_menu a';
	var activeLinkSelector='.dmf-global-header-menu .current-menu-item>a,.dmf-global-header-menu .current-menu-ancestor>a,.dmf-global-header-menu .current_page_item>a,.dmf-global-header-menu .current-page-ancestor>a';
	var ctaLinkSelector='.dmf-global-header-menu .dmf-menu-cta>a,.dmf-global-header-menu li.dmf-menu-cta>a,.dmf-global-header-menu a.dmf-menu-cta';
	function setImportant(node,property,value){
		if(!node){return;}
		node.style.setProperty(property,value,'important');
	}
	function isCtaLink(link){
		if(!link){return false;}
		if(link.classList && link.classList.contains('dmf-menu-cta')){return true;}
		var item=link.closest ? link.closest('li') : null;
		return !!(item && item.classList && item.classList.contains('dmf-menu-cta'));
	}
	function applyCtaState(header){
		Array.prototype.slice.call(header.querySelectorAll(ctaLinkSelector)).forEach(function(link){
			setImportant(link,'background',headerStyles.ctaBackground);
			setImportant(link,'background-color',headerStyles.ctaBackground);
			setImportant(link,'color',headerStyles.ctaText);
			setImportant(link,'border',headerStyles.ctaBorder);
		});
	}
	function getHeaders(){
		return Array.prototype.slice.call(document.querySelectorAll('.dmf-global-header-shell'));
	}
	function getScrollTop(){
		return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
	}
	function applyHeaderState(header,isScrolled){
		var linkColor=isScrolled ? headerStyles.linkScrolled : headerStyles.linkTop;
		var activeColor=isScrolled ? headerStyles.linkActiveScrolled : headerStyles.linkActiveTop;
		header.classList.toggle('dmf-header-is-scrolled',isScrolled);
		setImportant(header,'background',isScrolled ? headerStyles.backgroundScrolled : headerStyles.backgroundTop);
		setImportant(header,'position',isScrolled ? 'fixed' : 'absolute');
		setImportant(header,'top','var(--wp-admin--admin-bar--height,0px)');
		setImportant(header,'right','0');
		setImportant(header,'left','0');
		setImportant(header,'width','100%');
		Array.prototype.slice.call(header.querySelectorAll(menuLinkSelector)).forEach(function(link){
			if(isCtaLink(link)){return;}
			setImportant(link,'color',linkColor);
		});
		Array.prototype.slice.call(header.querySelectorAll(activeLinkSelector)).forEach(function(link){
			if(isCtaLink(link)){return;}
			setImportant(link,'color',activeColor);
		});
		applyCtaState(header);
	}
	function updateHeaders(){
		var headers=getHeaders();
		var isScrolled=getScrollTop()>threshold;
		headers.forEach(function(header){
			applyHeaderState(header,isScrolled);
		});
		document.documentElement.classList.toggle('dmf-header-is-scrolled',isScrolled);
		ticking=false;
	}
	function requestUpdate(){
		if(ticking){return;}
		ticking=true;
		window.requestAnimationFrame(updateHeaders);
	}
	function bind(){
		if(initialized){return;}
		initialized=true;
		updateHeaders();
		window.addEventListener('scroll',requestUpdate,{passive:true});
		window.addEventListener('resize',requestUpdate);
		window.addEventListener('orientationchange',requestUpdate);
		window.addEventListener('load',requestUpdate);
		window.addEventListener('pageshow',requestUpdate);
		window.setTimeout(requestUpdate,60);
		window.setTimeout(requestUpdate,220);
	}
	function init(){
		if(getHeaders().length){
			bind();
			return;
		}
		if(observer){return;}
		observer=new MutationObserver(function(){
			if(!getHeaders().length){return;}
			observer.disconnect();
			observer=null;
			bind();
		});
		observer.observe(document.documentElement,{childList:true,subtree:true});
	}
	if(document.readyState==='loading'){
		document.addEventListener('DOMContentLoaded',init);
	}else{
		init();
	}
})();
</script>
HTML;
	}

	private function normalize_divi_header_theme_options( $dry_run ) {
		$desired_options = $this->get_desired_divi_header_theme_options();
		$updated_messages = [];

		foreach ( $desired_options as $option_name => $desired_value ) {
			$current_value = $this->get_divi_theme_option( $option_name, null );

			if ( $current_value === $desired_value ) {
				continue;
			}

			if ( ! $dry_run ) {
				$this->set_divi_theme_option( $option_name, $desired_value );
			}

			if ( 'boxed_layout' === $option_name ) {
				$updated_messages[] = 'Disable Divi boxed layout for a full-width header';
			} elseif ( 'divi_fixed_nav' === $option_name ) {
				$updated_messages[] = 'Disable Divi fixed navigation so the Theme Builder header controls the sticky state';
			} elseif ( in_array( $option_name, [ 'primary_nav_bg', 'fixed_primary_nav_bg', 'menu_link', 'menu_link_active', 'fixed_menu_link', 'fixed_menu_link_active' ], true ) ) {
				$updated_messages[] = 'Apply palette-aware initial and scrolled header colors';
			}
		}

		return array_values( array_unique( $updated_messages ) );
	}

	private function get_desired_divi_header_theme_options() {
		return [
			'divi_fixed_nav'                  => 'off',
			'boxed_layout'                    => false,
			'primary_nav_bg'                  => 'rgba(19, 27, 38, 0.82)',
			'fixed_primary_nav_bg'            => '#edeced',
			'menu_link'                       => 'rgba(250, 250, 250, 0.76)',
			'menu_link_active'                => '#fafafa',
			'fixed_menu_link'                 => '#486262',
			'fixed_menu_link_active'          => '#131b26',
			'primary_nav_dropdown_bg'         => '#fafafa',
			'primary_nav_dropdown_link_color' => '#131b26',
			'primary_nav_dropdown_line_color' => '#a1a5a4',
			'mobile_primary_nav_bg'           => '#fafafa',
			'mobile_menu_link'                => '#131b26',
		];
	}

	private function get_divi_theme_option( $option_name, $default_value = '' ) {
		if ( function_exists( 'et_get_option' ) ) {
			return et_get_option( (string) $option_name, $default_value );
		}

		$options = get_option( 'et_divi', [] );

		if ( is_array( $options ) && array_key_exists( (string) $option_name, $options ) ) {
			return $options[ (string) $option_name ];
		}

		return $default_value;
	}

	private function set_divi_theme_option( $option_name, $value ) {
		if ( function_exists( 'et_update_option' ) ) {
			et_update_option( (string) $option_name, $value );
			return;
		}

		$options = get_option( 'et_divi', [] );

		if ( ! is_array( $options ) ) {
			$options = [];
		}

		$options[ (string) $option_name ] = $value;

		update_option( 'et_divi', $options );
	}

	private function normalize_imported_page_layout_content( WP_Post $page, $content ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $this->apply_named_page_layout_fixes( $page, (string) $content );
		}

		$blocks = parse_blocks( (string) $content );

		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return $this->apply_named_page_layout_fixes( $page, (string) $content );
		}

		$blocks     = $this->mutate_overlay_hero_page_blocks( $blocks );
		$serialized = serialize_blocks( $blocks );

		if ( ! is_string( $serialized ) || '' === $serialized ) {
			$serialized = (string) $content;
		}

		return $this->apply_named_page_layout_fixes( $page, $serialized );
	}

	private function mutate_overlay_hero_page_blocks( array $blocks ) {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name  = (string) ( $block['blockName'] ?? '' );
			$admin_label = $this->get_divi_block_admin_label( $block );

			if ( 'divi/section' === $block_name && in_array( $admin_label, [ 'Home Hero Section', 'Portfolio Hero Section', 'Case Study Hero Section' ], true ) ) {
				$block['attrs']['module']['decoration']['attributes'] = $this->build_custom_attributes(
					[
						'class' => 'dmf-overlay-hero-section',
						'style' => $this->build_inline_style(
							[
								'background' => 'var(--gcid-dmf-primary, #2b5b5b)',
								'margin'     => '0',
								'padding'    => '0',
							]
						),
					]
				);
			} elseif ( 'divi/row' === $block_name && in_array( $admin_label, [ 'Hero Row', 'Portfolio Hero Row' ], true ) ) {
				$block['attrs']['module']['decoration']['attributes'] = $this->build_custom_attributes(
					[
						'class' => 'dmf-overlay-hero-row',
						'style' => $this->build_inline_style(
							[
								'width'      => '100%',
								'max-width'  => '100%',
								'margin'     => '0',
								'padding'    => '0',
								'box-sizing' => 'border-box',
							]
						),
					]
				);
			} elseif ( 'divi/column' === $block_name && in_array( $admin_label, [ 'Hero Column', 'Portfolio Hero Column' ], true ) ) {
				$block['attrs']['module']['decoration']['attributes'] = $this->build_custom_attributes(
					[
						'class' => 'dmf-overlay-hero-column',
						'style' => $this->build_inline_style(
							[
								'margin'  => '0',
								'padding' => '0',
							]
						),
					]
				);
			} elseif ( 'divi/row' === $block_name && 'Case Study Hero Row' === $admin_label ) {
				$block['attrs']['module']['decoration']['attributes'] = $this->build_custom_attributes(
					[
						'class' => 'dmf-case-study-hero-row',
						'style' => $this->build_inline_style(
							[
								'width'      => '100%',
								'max-width'  => '80rem',
								'margin'     => '0 auto',
								'padding'    => 'clamp(7rem, 10vw, 8.5rem) 1.5rem clamp(3rem, 6vw, 4.25rem)',
								'box-sizing' => 'border-box',
							]
						),
					]
				);
			} elseif ( 'divi/column' === $block_name && in_array( $admin_label, [ 'Hero Copy Column', 'Hero Image Column' ], true ) ) {
				$block['attrs']['module']['decoration']['attributes'] = $this->build_custom_attributes(
					[
						'class' => 'dmf-case-study-hero-column',
						'style' => $this->build_inline_style(
							[
								'margin'  => '0',
								'padding' => '0',
							]
						),
					]
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->mutate_overlay_hero_page_blocks( $block['innerBlocks'] );
			}
		}

		unset( $block );

		return $blocks;
	}

	private function theme_template_area_enabled_flag( $layout_type, $layout_id, array $template_export ) {
		if ( 'body' === $layout_type && (int) $layout_id <= 0 ) {
			return '1';
		}

		return ! empty( $template_export['layouts'][ $layout_type ]['enabled'] ) ? '1' : '0';
	}

	private function find_templates_with_body_overrides( array $exclude_template_ids = [] ) {
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

			if ( in_array( (int) $template->ID, $exclude_template_ids, true ) ) {
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

	private function neutralize_other_theme_builder_body_overrides( array $keep_template_ids ) {
		$updated = [];

		foreach ( $this->find_templates_with_body_overrides( $keep_template_ids ) as $template ) {
			update_post_meta( $template->ID, '_et_body_layout_id', 0 );
			update_post_meta( $template->ID, '_et_body_layout_enabled', '1' );
			$updated[] = sprintf( 'Cleared stale body override on template #%d', $template->ID );
		}

		return $updated;
	}

	private function sync_primary_menu( $home_slug, $dry_run, $create_missing_pages = false ) {
		$location  = 'primary-menu';
		$menu_name = 'Digital MindFlow Primary Navigation';
		$menu      = wp_get_nav_menu_object( $menu_name );
		$menu_id   = $menu ? (int) $menu->term_id : 0;
		$items     = [];

		$registered_menus = get_registered_nav_menus();
		if ( ! isset( $registered_menus[ $location ] ) ) {
			$this->warn( "Theme location '{$location}' is not registered on this install." );
		}

		foreach ( $this->menu_blueprint as $index => $item ) {
			if ( 'page' === ( $item['type'] ?? '' ) ) {
				$page = $this->find_target_page(
					(string) ( $item['slug'] ?? '' ),
					$home_slug,
					(string) ( $item['label'] ?? '' )
				);

				if ( ! $page ) {
					if ( $create_missing_pages ) {
						if ( $dry_run ) {
							$items[] = [
								'type'     => 'placeholder',
								'title'    => (string) $item['label'],
								'position' => $index + 1,
							];
							continue;
						}

						$page = $this->create_target_page(
							[
								'label' => (string) $item['label'],
								'slug'  => (string) ( $item['slug'] ?? '' ),
							],
							$home_slug
						);
					}

					if ( ! $page ) {
						$this->warn( 'Skipping menu item ' . $item['label'] . ': page not found.' );
						continue;
					}
				}

				$items[] = [
					'type'      => 'post_type',
					'title'     => (string) $item['label'],
					'position'  => $index + 1,
					'object_id' => (int) $page->ID,
					'object'    => 'page',
					'classes'   => $item['classes'] ?? [],
				];

				continue;
			}

			$items[] = [
				'type'     => 'custom',
				'title'    => (string) $item['label'],
				'position' => $index + 1,
				'url'      => $this->menu_url( (string) ( $item['url'] ?? '/' ) ),
				'classes'  => $item['classes'] ?? [],
			];
		}

		if ( $dry_run ) {
			$summary = [
				"Would sync {$menu_name}",
				"Location {$location}",
				'Items ' . count( $items ),
			];
			$this->log(
				'info',
				'Primary menu dry run prepared.',
				[
					'menu_name' => $menu_name,
					'location'  => $location,
					'items'     => count( $items ),
				]
			);

			return $summary;
		}

		if ( $menu_id <= 0 ) {
			$menu_id = wp_create_nav_menu( $menu_name );

			if ( is_wp_error( $menu_id ) ) {
				throw new RuntimeException( 'Failed to create navigation menu: ' . $menu_id->get_error_message() );
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
			if ( 'placeholder' === $item['type'] ) {
				continue;
			}

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
				throw new RuntimeException(
					'Failed to create menu item ' . $item['title'] . ': ' . $menu_item_id->get_error_message()
				);
			}

			if ( ! empty( $item['classes'] ) ) {
				update_post_meta( (int) $menu_item_id, '_menu_item_classes', array_values( (array) $item['classes'] ) );
			}

			$created_count++;
		}

		$locations              = get_theme_mod( 'nav_menu_locations', [] );
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		$this->log(
			'info',
			'Primary menu synced.',
			[
				'menu_id'   => (int) $menu_id,
				'menu_name' => $menu_name,
				'location'  => $location,
				'items'     => $created_count,
			]
		);

		return [
			"Menu {$menu_name} #{$menu_id}",
			"Location {$location}",
			'Items ' . $created_count,
		];
	}

	private function menu_url( $url ) {
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

	private function get_single_layout_content( array $data ) {
		$layout_values = array_values( $data );

		if ( 1 !== count( $layout_values ) || ! is_string( $layout_values[0] ) ) {
			throw new RuntimeException( 'Expected a single layout content payload in export data.' );
		}

		return $layout_values[0];
	}

	private function get_default_template_export( array $templates ) {
		foreach ( $templates as $template ) {
			if ( ! empty( $template['default'] ) ) {
				return $template;
			}
		}

		if ( ! empty( $templates[0] ) && is_array( $templates[0] ) ) {
			return $templates[0];
		}

		throw new RuntimeException( 'No Theme Builder template found in export.' );
	}

	private function get_existing_default_template() {
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

	private function get_existing_portfolio_single_template() {
		if ( ! defined( 'ET_THEME_BUILDER_TEMPLATE_POST_TYPE' ) ) {
			return null;
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

		foreach ( $templates as $template ) {
			if ( ! $template instanceof WP_Post ) {
				continue;
			}

			$use_on = get_post_meta( $template->ID, '_et_use_on', false );

			if ( is_array( $use_on ) && in_array( $this->get_portfolio_single_template_condition(), $use_on, true ) ) {
				return $template;
			}
		}

		return null;
	}

	private function get_portfolio_single_template_condition() {
		return 'singular:post_type:portfolio:all';
	}

	private function attach_template_to_theme_builder_post( $theme_builder_id, $template_id ) {
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
	}

	private function flush_divi_caches() {
		if ( function_exists( 'et_update_option' ) ) {
			et_update_option( 'et_pb_clear_templates_cache', true );
		}

		if ( class_exists( 'ET_Core_PageResource' ) ) {
			ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
		}
	}
}
