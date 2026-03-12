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
			$this->build_portfolio_intro_row( $context ),
			$this->build_portfolio_loop_row( $context ),
		];

		if ( 'home' === $context ) {
			$blocks[] = $this->build_portfolio_archive_button_row();
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
								'class' => 'dmf-portfolio-loop-item',
								'style' => $this->build_inline_style(
									[
										'display'       => 'flex',
										'flex-direction' => 'column',
										'gap'           => '1rem',
										'flex'          => '1 1 20rem',
										'min-width'     => '18rem',
										'max-width'     => 'calc((100% - 3rem) / 3)',
										'padding'       => '1.5rem',
										'background'    => 'var(--gcid-dmf-card, #edeced)',
										'border'        => '0.0625rem solid var(--gcid-dmf-border, #a1a5a4)',
										'border-radius' => 'var(--gvid-dmf-radius-lg)',
										'box-shadow'    => '0 1rem 2.25rem color-mix(in srgb, var(--gcid-dmf-primary, #131b26) 8%, transparent)',
										'box-sizing'    => 'border-box',
									]
								),
							]
						),
					],
				],
			],
			implode(
				"\n",
				[
					$this->build_portfolio_card_image_block(),
					$this->build_portfolio_card_title_block(),
					$this->build_portfolio_card_excerpt_block(),
					$this->build_portfolio_card_button_block(),
				]
			)
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
								'class' => 'dmf-portfolio-loop-container',
								'style' => $this->build_inline_style(
									[
										'display'         => 'flex',
										'flex-wrap'       => 'wrap',
										'align-items'     => 'stretch',
										'justify-content' => 'flex-start',
										'gap'             => '1.5rem',
										'width'           => '100%',
									]
								),
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
				],
			],
			$column_block
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

	private function build_portfolio_intro_markup( $context ) {
		if ( 'home' === $context ) {
			return <<<'HTML'
<div style="text-align:center;padding:0.625rem 0 0.25rem">
	<div style="font-family:var(--gvid-dmf-body-font);font-size:var(--gvid-dmf-text-xs);font-weight:700;letter-spacing:0.22em;text-transform:uppercase;color:var(--gcid-dmf-accent, #941213);margin-bottom:0.875rem">Portfolio</div>
	<h2 style="font-family:var(--gvid-dmf-heading-font);font-size:clamp(2rem, 4.8vw, 3.4rem);font-weight:700;line-height:1.12;color:var(--gcid-dmf-foreground, #131b26);margin:0 0 1rem 0">Recent <span style="display:inline-block;background:linear-gradient(135deg, var(--gcid-dmf-accent, #941213), var(--gcid-dmf-accent-deep, #893637));color:transparent;-webkit-background-clip:text;background-clip:text">Projects</span></h2>
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
		return $this->render_divi_block(
			'button',
			[
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
			]
		);
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
			$portfolio_template = $this->upsert_portfolio_single_theme_template( true );
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

		$portfolio_template = $this->upsert_portfolio_single_theme_template( false );
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

	private function upsert_portfolio_single_theme_template( $dry_run ) {
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
		$updated                 = [
			sprintf( 'Portfolio single body layout #%d', $body_layout_id ),
		];

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
					'header' => [
						'id'      => 0,
						'enabled' => '1',
					],
					'body'   => [
						'id'      => $body_layout_id,
						'enabled' => '1',
					],
					'footer' => [
						'id'      => 0,
						'enabled' => '1',
					],
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
		$hero_copy_group = $this->render_divi_block(
			'group',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Hero Copy Group',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-single-copy',
								'style' => $this->build_inline_style(
									[
										'display'        => 'flex',
										'flex-direction' => 'column',
										'align-items'    => 'flex-start',
										'gap'            => '1.25rem',
									]
								),
							]
						),
					],
				],
			],
			implode(
				"\n",
				[
					$this->build_portfolio_single_eyebrow_block(),
					$this->build_portfolio_single_title_block(),
					$this->build_portfolio_single_excerpt_block(),
					$this->build_portfolio_button_block(
						'Portfolio Single Back Button',
						'Back to Portfolio',
						esc_url( $this->get_portfolio_page_url() ),
						'left'
					),
				]
			)
		);

		$left_column = $this->render_divi_block(
			'column',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Hero Copy Column',
							],
						],
					],
					'advanced'   => [
						'type' => [
							'desktop' => [
								'value' => '1_2',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'style' => $this->build_inline_style(
									[
										'margin'  => '0',
										'padding' => '0',
									]
								),
							]
						),
					],
				],
			],
			$hero_copy_group
		);

		$right_column = $this->render_divi_block(
			'column',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Hero Image Column',
							],
						],
					],
					'advanced'   => [
						'type' => [
							'desktop' => [
								'value' => '1_2',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'style' => $this->build_inline_style(
									[
										'margin'  => '0',
										'padding' => '0',
									]
								),
							]
						),
					],
				],
			],
			$this->build_portfolio_single_featured_image_block()
		);

		$hero_row = $this->render_divi_block(
			'row',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Hero Row',
							],
						],
					],
					'advanced'   => [
						'columnStructure' => [
							'desktop' => [
								'value' => '1_2,1_2',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-single-hero-row',
								'style' => $this->build_inline_style(
									[
										'width'      => '100%',
										'max-width'  => '80rem',
										'margin'     => '0 auto',
										'padding'    => 'clamp(8rem, 12vw, 10rem) 1.5rem clamp(3rem, 6vw, 4.5rem)',
										'box-sizing' => 'border-box',
									]
								),
							]
						),
					],
				],
			],
			$left_column . "\n" . $right_column
		);

		$hero_section = $this->render_divi_block(
			'section',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Hero Section',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-single-hero',
								'style' => $this->build_inline_style(
									[
										'background' => 'var(--gcid-dmf-primary, #131b26)',
										'margin'     => '0',
										'padding'    => '0',
									]
								),
							]
						),
					],
				],
			],
			$hero_row
		);

		$content_column = $this->render_divi_block(
			'column',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'     => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Content Column',
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
			$this->render_divi_block(
				'post-content',
				[
					'builderVersion' => 0.7,
					'module'         => [
						'meta'       => [
							'adminLabel' => [
								'desktop' => [
									'value' => 'Portfolio Single Post Content',
								],
							],
						],
						'decoration' => [
							'attributes' => $this->build_custom_attributes(
								[
									'class' => 'dmf-portfolio-single-post-content',
								]
							),
						],
					],
				]
			)
		);

		$content_row = $this->render_divi_block(
			'row',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Content Row',
							],
						],
					],
					'advanced'   => [
						'columnStructure' => [
							'desktop' => [
								'value' => '4_4',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-single-content-row',
								'style' => $this->build_inline_style(
									[
										'width'      => '100%',
										'max-width'  => '70rem',
										'margin'     => '0 auto',
										'padding'    => 'clamp(2.5rem, 5vw, 3.5rem) 1.5rem clamp(4rem, 8vw, 5.5rem)',
										'box-sizing' => 'border-box',
									]
								),
							]
						),
					],
				],
			],
			$content_column
		);

		$content_section = $this->render_divi_block(
			'section',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta'       => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Content Section',
							],
						],
					],
					'decoration' => [
						'attributes' => $this->build_custom_attributes(
							[
								'class' => 'dmf-portfolio-single-content',
								'style' => $this->build_inline_style(
									[
										'background' => 'var(--gcid-dmf-background, #fafafa)',
										'margin'     => '0',
										'padding'    => '0',
									]
								),
							]
						),
					],
				],
			],
			$content_row
		);

		return $this->render_divi_block(
			'placeholder',
			[],
			$hero_section . "\n" . $content_section
		);
	}

	private function build_portfolio_single_eyebrow_block() {
		return $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta' => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Eyebrow',
							],
						],
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
											'font-size'      => 'var(--gvid-dmf-text-xs)',
											'font-weight'    => '700',
											'letter-spacing' => '0.22em',
											'text-transform' => 'uppercase',
											'color'          => 'var(--gcid-dmf-accent, #941213)',
											'margin'         => '0',
										]
									)
								),
								'Portfolio'
							),
						],
					],
				],
			]
		);
	}

	private function build_portfolio_single_title_block() {
		return $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta' => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Title',
							],
						],
					],
				],
				'content'        => [
					'innerContent' => [
						'desktop' => [
							'value' => sprintf(
								'<h1 style="%1$s">%2$s</h1>',
								esc_attr(
									$this->build_inline_style(
										[
											'font-family' => 'var(--gvid-dmf-heading-font)',
											'font-size'   => 'clamp(2.5rem, 5vw, 4.5rem)',
											'font-weight' => '700',
											'line-height' => '1.04',
											'color'       => 'var(--gcid-dmf-white, #fafafa)',
											'margin'      => '0',
										]
									)
								),
								$this->build_dynamic_content_token(
									'post_title',
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

	private function build_portfolio_single_excerpt_block() {
		return $this->render_divi_block(
			'text',
			[
				'builderVersion' => 0.7,
				'module'         => [
					'meta' => [
						'adminLabel' => [
							'desktop' => [
								'value' => 'Portfolio Single Excerpt',
							],
						],
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
											'font-size'   => 'clamp(1rem, calc(1rem + 0.35vw), 1.25rem)',
											'line-height' => '1.85',
											'color'       => 'color-mix(in srgb, var(--gcid-dmf-white, #fafafa) 74%, transparent)',
											'margin'      => '0',
											'max-width'   => '42rem',
										]
									)
								),
								$this->build_dynamic_content_token(
									'post_excerpt',
									[
										'before' => '',
										'after'  => '',
										'words'  => 34,
									]
								)
							),
						],
					],
				],
			]
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
								'class' => 'dmf-portfolio-single-image',
								'style' => $this->build_inline_style(
									[
										'width'         => '100%',
										'overflow'      => 'hidden',
										'border-radius' => '1.5rem',
										'box-shadow'    => '0 1.5rem 3rem color-mix(in srgb, var(--gcid-dmf-foreground, #131b26) 18%, transparent)',
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

		if ( 'et_header_layout' !== $post_type ) {
			return (string) $content;
		}

		return $this->apply_global_header_layout_fix( (string) $content );
	}

	private function apply_global_header_layout_fix( $content ) {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return (string) $content;
		}

		$blocks = parse_blocks( (string) $content );

		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return (string) $content;
		}

		$blocks     = $this->mutate_global_header_blocks( $blocks );
		$serialized = serialize_blocks( $blocks );

		return is_string( $serialized ) && '' !== $serialized ? $serialized : (string) $content;
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
								'padding'    => '0.3rem 1.5rem 0.25rem',
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
								'margin'  => '0',
								'padding' => '0',
							]
						),
					]
				);
				$block['innerBlocks']   = ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
				$block['innerBlocks'][] = $this->build_header_runtime_block();
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
						'top'        => '0',
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
		$attrs['module']['decoration']['background']['desktop']['sticky']['color'] = 'rgba(237, 236, 237, 0.96)';
		$attrs['module']['decoration']['sticky']['desktop']['value'] = [
			'position'   => 'top',
			'offset'     => [
				'top'         => 'var(--wp-admin--admin-bar--height, 0px)',
				'bottom'      => '0px',
				'surrounding' => 'on',
			],
			'limit'      => [
				'top'    => '',
				'bottom' => '',
			],
			'transition' => 'on',
		];
		$attrs['module']['decoration']['transition']['desktop']['value'] = [
			'duration'   => '220ms',
			'delay'      => '0ms',
			'speedCurve' => 'ease',
		];

		return $attrs;
	}

	private function mutate_global_header_menu_attrs( array $attrs ) {
		$nav_color                = 'color-mix(in srgb, var(--gcid-dmf-white, #fafafa) 76%, transparent)';
		$nav_active_color         = 'var(--gcid-dmf-white, #fafafa)';
		$scrolled_nav_color       = 'var(--gcid-dmf-muted, #486262)';
		$scrolled_nav_active_color = 'var(--gcid-dmf-foreground, #131b26)';

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
		$attrs['logo']['decoration']['sizing']['desktop']['value']['width']       = 'clamp(7rem, calc(6.45rem + 1.5vw), 8.4rem)';
		$attrs['logo']['decoration']['sizing']['desktop']['value']['maxWidth']    = 'clamp(7rem, calc(6.45rem + 1.5vw), 8.4rem)';
		$attrs['logo']['decoration']['sizing']['desktop']['value']['maxHeight']   = '2.8rem';
		$attrs['menu']['advanced']['activeLinkColor']['desktop']['value']         = $nav_active_color;
		$attrs['menu']['advanced']['activeLinkColor']['desktop']['sticky']        = $scrolled_nav_active_color;
		$attrs['menu']['decoration']['font']['font']['desktop']['value']['color'] = $nav_color;
		$attrs['menu']['decoration']['font']['font']['desktop']['sticky']['color'] = $scrolled_nav_color;
		$attrs['menuDropdown']['advanced']['activeLinkColor']['desktop']['value'] = 'var(--gcid-dmf-accent, #941213)';
		$attrs['menuDropdown']['advanced']['lineColor']['desktop']['value']       = 'var(--gcid-dmf-border, #a1a5a4)';
		$attrs['menuDropdown']['decoration']['font']['font']['desktop']['value']['color'] = 'var(--gcid-dmf-foreground, #131b26)';
		$attrs['menuMobile']['decoration']['font']['font']['desktop']['value']['color']   = $nav_active_color;
		$attrs['menuMobile']['decoration']['font']['font']['desktop']['sticky']['color']  = $scrolled_nav_active_color;
		$attrs['cartIcon']['decoration']['font']['font']['desktop']['value']['color']      = $nav_active_color;
		$attrs['cartIcon']['decoration']['font']['font']['desktop']['sticky']['color']     = $scrolled_nav_active_color;
		$attrs['searchIcon']['decoration']['font']['font']['desktop']['value']['color']    = $nav_active_color;
		$attrs['searchIcon']['decoration']['font']['font']['desktop']['sticky']['color']   = $scrolled_nav_active_color;
		$attrs['hamburgerMenuIcon']['decoration']['font']['font']['desktop']['value']['color'] = $nav_active_color;
		$attrs['hamburgerMenuIcon']['decoration']['font']['font']['desktop']['sticky']['color'] = $scrolled_nav_active_color;
		$attrs['module']['decoration']['transition']['desktop']['value'] = [
			'duration'   => '220ms',
			'delay'      => '0ms',
			'speedCurve' => 'ease',
		];

		return $attrs;
	}

	private function build_header_runtime_block() {
		return [
			'blockName'    => 'divi/text',
			'attrs'        => [
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
										'display'        => 'none',
										'width'          => '0',
										'height'         => '0',
										'overflow'       => 'hidden',
										'pointer-events' => 'none',
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
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	private function build_header_runtime_markup() {
		return <<<'HTML'
<style id="dmf-header-runtime-styles">
.dmf-global-header-shell{
	background:transparent !important;
	transition:background-color 220ms ease, color 220ms ease;
}
.dmf-global-header-shell.dmf-header-is-scrolled{
	background:rgba(237,236,237,0.96) !important;
	position:fixed !important;
	top:var(--wp-admin--admin-bar--height,0px) !important;
	right:0 !important;
	left:0 !important;
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
.dmf-global-header-shell .dmf-global-header-menu .dmf-menu-cta>a{
	background:var(--gcid-dmf-white,#fafafa) !important;
	border:1px solid color-mix(in srgb, var(--gcid-dmf-border,#a1a5a4) 84%, transparent) !important;
	border-radius:0.8rem !important;
	box-shadow:0 0.55rem 1.25rem rgba(19,27,38,0.08);
	color:var(--gcid-dmf-foreground,#131b26) !important;
	display:inline-flex !important;
	align-items:center !important;
	line-height:1.1 !important;
	padding:0.62rem 1rem !important;
}
.dmf-global-header-shell .dmf-global-header-menu .dmf-menu-cta>a:hover{
	background:var(--gcid-dmf-card,#edeced) !important;
	opacity:1 !important;
}
</style>
<script id="dmf-header-runtime-script">
(function(){
	if(window.__dmfHeaderRuntimeLoaded){return;}
	window.__dmfHeaderRuntimeLoaded=true;
	var ticking=false;
	var threshold=12;
	function getHeaders(){
		return Array.prototype.slice.call(document.querySelectorAll('.dmf-global-header-shell'));
	}
	function updateHeaders(){
		var isScrolled=window.scrollY>threshold;
		getHeaders().forEach(function(header){
			header.classList.toggle('dmf-header-is-scrolled',isScrolled);
		});
		ticking=false;
	}
	function requestUpdate(){
		if(ticking){return;}
		ticking=true;
		window.requestAnimationFrame(updateHeaders);
	}
	function init(){
		if(!getHeaders().length){return;}
		updateHeaders();
		window.addEventListener('scroll',requestUpdate,{passive:true});
		window.addEventListener('resize',requestUpdate);
		window.addEventListener('orientationchange',requestUpdate);
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
			return (string) $content;
		}

		$blocks = parse_blocks( (string) $content );

		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return (string) $content;
		}

		$blocks     = $this->mutate_overlay_hero_page_blocks( $blocks );
		$serialized = serialize_blocks( $blocks );

		return is_string( $serialized ) && '' !== $serialized ? $serialized : (string) $content;
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
								'background' => 'var(--gcid-dmf-primary, #131b26)',
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
