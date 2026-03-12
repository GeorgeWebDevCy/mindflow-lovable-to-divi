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
			$this->build_code_module( 'Portfolio Loop Runtime', $this->build_portfolio_loop_runtime_markup(), 'dmf-portfolio-loop-runtime' ),
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
										$this->build_group_module(
											'Home Hero Actions',
											[
												$this->build_button_module( 'Home Hero Primary Button', 'Request Free Consultation', home_url( '/#contact' ), 'center', 'dmf-button dmf-button--primary' ),
												$this->build_button_module( 'Home Hero Secondary Button', 'Explore Our Services', home_url( '/#services' ), 'center', 'dmf-button dmf-button--secondary' ),
											],
											'dmf-home-actions'
										),
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
		return <<<'HTML'
<div class="dmf-contact-form-shell">
	<form class="dmf-contact-form" action="mailto:info@mindflowdigital.com" method="post" enctype="text/plain">
		<label class="dmf-form-field">
			<span class="dmf-form-label">Full Name *</span>
			<input type="text" name="name" maxlength="100" placeholder="Your name" required>
		</label>
		<label class="dmf-form-field">
			<span class="dmf-form-label">Email Address *</span>
			<input type="email" name="email" maxlength="255" placeholder="you@company.com" required>
		</label>
		<label class="dmf-form-field">
			<span class="dmf-form-label">Message *</span>
			<textarea name="message" rows="6" maxlength="1000" placeholder="Tell us about your project and goals..." required></textarea>
		</label>
		<button class="dmf-form-submit" type="submit">Send Message</button>
	</form>
</div>
HTML;
	}

	private function build_contact_section() {
		$info_items = [
			sprintf(
				'<a class="dmf-contact-link" href="mailto:info@mindflowdigital.com"><span class="dmf-contact-link__icon">%1$s</span><span class="dmf-contact-link__text">info@mindflowdigital.com</span></a>',
				$this->build_icon_markup( 'mail' )
			),
			sprintf(
				'<a class="dmf-contact-link" href="tel:+35799882116"><span class="dmf-contact-link__icon">%1$s</span><span class="dmf-contact-link__text">+357 99 882116</span></a>',
				$this->build_icon_markup( 'phone' )
			),
			sprintf(
				'<div class="dmf-contact-link"><span class="dmf-contact-link__icon">%1$s</span><span class="dmf-contact-link__text">Paphos, Cyprus</span></div>',
				$this->build_icon_markup( 'map-pin' )
			),
		];

		return $this->build_section_module(
			'Contact Section',
			[
				$this->build_row_module(
					'Contact Layout Row',
					[
						$this->build_column_module(
							'Contact Layout Column',
							[
								$this->build_group_module(
									'Contact Section Shell',
									[
										$this->build_text_module( 'Contact Eyebrow', '<span class="dmf-section-eyebrow">Get In Touch</span>', 'dmf-home-text dmf-section-header dmf-section-header--center' ),
										$this->build_text_module( 'Contact Title', '<h2 class="dmf-section-title dmf-section-title--center">Let&#39;s <span class="dmf-text-gradient">Work Together</span></h2>', 'dmf-home-text' ),
										$this->build_text_module( 'Contact Body', '<p class="dmf-section-body dmf-section-body--center">Ready to grow your online presence? Get in touch for a free consultation and let&#39;s discuss your goals.</p>', 'dmf-home-text' ),
										$this->build_group_module(
											'Contact Panels',
											[
												$this->build_group_module(
													'Contact Info Panel',
													[
														$this->build_text_module( 'Contact Info Title', '<h3 class="dmf-panel-title">Contact Information</h3>', 'dmf-home-text' ),
														$this->build_text_module( 'Contact Links', '<div class="dmf-contact-links">' . implode( '', $info_items ) . '</div>', 'dmf-home-text' ),
														$this->build_group_module(
															'Contact Callout',
															[
																$this->build_text_module( 'Contact Callout Title', '<h4 class="dmf-callout-title">Book a Discovery Call</h4>', 'dmf-home-text' ),
																$this->build_text_module( 'Contact Callout Copy', '<p class="dmf-callout-copy">Schedule a free 30-minute call with our team to discuss your business goals and how we can help.</p>', 'dmf-home-text' ),
																$this->build_button_module( 'Contact Call Button', 'Call Now', 'tel:+35799882116', 'left', 'dmf-button dmf-button--accent' ),
															],
															'dmf-contact-callout'
														),
													],
													'dmf-contact-panel dmf-contact-panel--info'
												),
												$this->build_group_module(
													'Contact Form Panel',
													[
														$this->build_code_module( 'Contact Form Markup', $this->build_contact_form_markup(), 'dmf-contact-form-runtime', false ),
													],
													'dmf-contact-panel dmf-contact-panel--form'
												),
											],
											'dmf-contact-panels'
										),
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
			'dmf-home-section dmf-home-section--muted dmf-contact-section',
			[],
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
				'background' => 'var(--gcid-dmf-primary, #131b26)',
				'margin'     => '0',
				'padding'    => '0',
			]
		);
	}

	private function build_home_runtime_markup() {
		return <<<'HTML'
<style id="dmf-home-runtime-styles">
.dmf-home-runtime{display:none!important}
.dmf-text-gradient{display:inline-block;background:linear-gradient(135deg,var(--gcid-dmf-accent,#941213),var(--gcid-dmf-overlay,#2b5b5b));color:transparent;-webkit-background-clip:text;background-clip:text}
.dmf-home-shell-row,.dmf-home-shell-column,.dmf-home-shell,.dmf-home-text{position:relative}
.dmf-home-shell-row{width:100%!important;max-width:100%!important;margin:0!important;padding:0 1.5rem!important;box-sizing:border-box}
.dmf-home-shell-column{padding:0!important;margin:0 auto!important}
.dmf-home-shell{width:100%;max-width:80rem;margin:0 auto;display:flex;flex-direction:column;gap:clamp(2.5rem,5vw,4rem)}
.dmf-home-section{padding:clamp(5rem,8vw,7rem) 0}
.dmf-home-section--light{background:var(--gcid-dmf-background,#fafafa)}
.dmf-home-section--muted{background:var(--gcid-dmf-card,#edeced)}
.dmf-home-hero-section{position:relative;overflow:hidden;padding:clamp(8.5rem,14vw,11rem) 0 clamp(5rem,8vw,6.5rem)}
.dmf-home-hero-section::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,color-mix(in srgb,var(--gcid-dmf-primary,#131b26) 72%,transparent),color-mix(in srgb,var(--gcid-dmf-overlay,#2b5b5b) 84%,transparent));opacity:.92}
.dmf-home-hero-stack{position:relative;z-index:1;min-height:calc(100vh - 1rem);display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:1.5rem;max-width:66rem;margin:0 auto}
.dmf-hero-eyebrow{display:inline-flex;align-items:center;gap:.5rem;padding:.45rem 1rem;border-radius:999px;border:1px solid color-mix(in srgb,var(--gcid-dmf-accent,#941213) 28%,transparent);background:color-mix(in srgb,var(--gcid-dmf-accent,#941213) 10%,transparent);color:var(--gcid-dmf-accent,#941213);font-family:var(--gvid-dmf-body-font);font-size:var(--gvid-dmf-text-sm);font-weight:700}
.dmf-hero-title,.dmf-section-title{font-family:var(--gvid-dmf-heading-font);font-size:clamp(2.5rem,6.5vw,4.75rem);font-weight:700;line-height:1.08;color:var(--gcid-dmf-white,#fafafa);margin:0}
.dmf-section-title{font-size:clamp(2rem,4.5vw,3.5rem);line-height:1.12;color:var(--gcid-dmf-foreground,#131b26)}
.dmf-hero-copy,.dmf-section-body,.dmf-card-copy{font-family:var(--gvid-dmf-body-font);font-size:clamp(.98rem,calc(.96rem + .25vw),1.16rem);line-height:1.8;color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 74%,transparent);margin:0;max-width:43rem}
.dmf-section-body,.dmf-card-copy{color:var(--gcid-dmf-muted,#486262);max-width:46rem}
.dmf-section-header--center,.dmf-section-title--center,.dmf-section-body--center{text-align:center;align-self:center}
.dmf-section-eyebrow{display:block;font-family:var(--gvid-dmf-body-font);font-size:var(--gvid-dmf-text-xs);font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:var(--gcid-dmf-accent,#941213)}
.dmf-home-split{display:flex;flex-wrap:wrap;align-items:center;gap:clamp(2rem,4vw,4rem)}
.dmf-home-media{flex:1 1 22rem;min-width:min(100%,19rem)}
.dmf-home-copy{flex:1 1 24rem;min-width:min(100%,19rem);display:flex;flex-direction:column;gap:1rem}
.dmf-about-image img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;border-radius:1.5rem;box-shadow:0 1.5rem 3.5rem color-mix(in srgb,var(--gcid-dmf-primary,#131b26) 14%,transparent)}
.dmf-home-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:1rem}
.dmf-button .et_pb_button,.dmf-button a.et_pb_button{display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:.95rem 1.55rem!important;border-radius:.9rem!important;font-family:var(--gvid-dmf-body-font)!important;font-size:var(--gvid-dmf-text-base)!important;font-weight:700!important;line-height:1.1!important;text-decoration:none!important;transition:transform .2s ease,opacity .2s ease,background-color .2s ease,border-color .2s ease,box-shadow .2s ease!important}
.dmf-button .et_pb_button:hover,.dmf-button a.et_pb_button:hover{transform:translateY(-2px)!important;opacity:1!important}
.dmf-button--primary .et_pb_button,.dmf-button--primary a.et_pb_button{color:var(--gcid-dmf-white,#fafafa)!important;background:linear-gradient(135deg,var(--gcid-dmf-accent,#941213),color-mix(in srgb,var(--gcid-dmf-accent,#941213) 68%,var(--gcid-dmf-overlay,#2b5b5b)))!important;border:1px solid color-mix(in srgb,var(--gcid-dmf-accent,#941213) 80%,transparent)!important;box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-accent,#941213) 25%,transparent)!important}
.dmf-button--secondary .et_pb_button,.dmf-button--secondary a.et_pb_button{color:var(--gcid-dmf-white,#fafafa)!important;background:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 7%,transparent)!important;border:1px solid color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 28%,transparent)!important;box-shadow:none!important}
.dmf-button--accent .et_pb_button,.dmf-button--accent a.et_pb_button{color:var(--gcid-dmf-white,#fafafa)!important;background:linear-gradient(135deg,var(--gcid-dmf-accent,#941213),color-mix(in srgb,var(--gcid-dmf-accent,#941213) 68%,var(--gcid-dmf-overlay,#2b5b5b)))!important;border:1px solid transparent!important;box-shadow:0 .8rem 1.75rem color-mix(in srgb,var(--gcid-dmf-accent,#941213) 24%,transparent)!important}
.dmf-hero-scroll{display:flex;justify-content:center;padding-top:.75rem}
.dmf-hero-scroll span{display:block;width:1.5rem;height:2.5rem;border:2px solid color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 30%,transparent);border-radius:999px;position:relative}
.dmf-hero-scroll span::before{content:"";position:absolute;left:50%;top:.45rem;width:.38rem;height:.38rem;background:var(--gcid-dmf-accent,#941213);border-radius:999px;transform:translateX(-50%);animation:dmfHeroScroll 1.5s infinite}
@keyframes dmfHeroScroll{0%{transform:translate(-50%,0);opacity:1}50%{transform:translate(-50%,.75rem);opacity:.55}100%{transform:translate(-50%,0);opacity:1}}
.dmf-flex-cards{display:flex;flex-wrap:wrap;gap:1.5rem}
.dmf-flex-cards--three>.dmf-lift-card,.dmf-flex-cards--three>.dmf-process-step{flex:1 1 calc((100% - 3rem)/3);min-width:18rem}
.dmf-lift-card{display:flex;flex-direction:column;gap:1rem;padding:2rem;border:1px solid var(--gcid-dmf-border,#a1a5a4);border-radius:1.35rem;background:var(--gcid-dmf-background,#fafafa);box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-primary,#131b26) 8%,transparent);transition:transform .28s ease,box-shadow .28s ease,border-color .28s ease}
.dmf-values-cards .dmf-lift-card{background:var(--gcid-dmf-card,#edeced)}
.dmf-lift-card:hover{transform:translateY(-8px);border-color:color-mix(in srgb,var(--gcid-dmf-accent,#941213) 34%,transparent);box-shadow:0 1.35rem 2.8rem color-mix(in srgb,var(--gcid-dmf-primary,#131b26) 16%,transparent)}
.dmf-card-icon{line-height:0}
.dmf-card-icon-frame,.dmf-process-icon-frame,.dmf-contact-link__icon{display:inline-flex;align-items:center;justify-content:center;width:3.25rem;height:3.25rem;border-radius:1rem;background:color-mix(in srgb,var(--gcid-dmf-accent,#941213) 12%,transparent);color:var(--gcid-dmf-accent,#941213);transition:background-color .28s ease,color .28s ease,filter .28s ease}
.dmf-card-icon-media{display:block;width:1.45rem;height:1.45rem;object-fit:contain;transition:filter .28s ease}
.dmf-inline-icon{width:1.45rem;height:1.45rem}
.dmf-lift-card:hover .dmf-card-icon-frame{background:var(--gcid-dmf-accent,#941213);color:var(--gcid-dmf-white,#fafafa)}
.dmf-lift-card:hover .dmf-card-icon-media{filter:brightness(0) invert(1)}
.dmf-card-title,.dmf-panel-title,.dmf-callout-title{font-family:var(--gvid-dmf-heading-font);font-size:clamp(1.12rem,calc(1.1rem + .28vw),1.38rem);font-weight:600;line-height:1.25;color:var(--gcid-dmf-foreground,#131b26);margin:0}
.dmf-service-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.7rem}
.dmf-service-list li{position:relative;padding-left:1rem;font-family:var(--gvid-dmf-body-font);font-size:clamp(.87rem,calc(.86rem + .16vw),.98rem);line-height:1.7;color:var(--gcid-dmf-foreground,#131b26)}
.dmf-service-list li::before{content:"";position:absolute;left:0;top:.72rem;width:.42rem;height:.42rem;border-radius:999px;background:var(--gcid-dmf-accent,#941213)}
.dmf-process-steps{position:relative;align-items:stretch}
.dmf-process-step{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;text-align:center;gap:.9rem}
.dmf-process-number{display:inline-flex;align-items:center;justify-content:center;width:4rem;height:4rem;border-radius:1.15rem;background:var(--gcid-dmf-primary,#131b26);color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-heading-font);font-size:1.18rem;font-weight:700;box-shadow:0 1.25rem 3rem color-mix(in srgb,var(--gcid-dmf-primary,#131b26) 15%,transparent)}
.dmf-process-icon-frame{width:2.75rem;height:2.75rem;border-radius:999px}
.dmf-contact-panels{display:flex;flex-wrap:wrap;gap:2rem}
.dmf-contact-panel{display:flex;flex-direction:column;gap:1.5rem}
.dmf-contact-panel--info{flex:1 1 21rem;min-width:min(100%,19rem)}
.dmf-contact-panel--form{flex:1.35 1 31rem;min-width:min(100%,19rem)}
.dmf-contact-links{display:flex;flex-direction:column;gap:1rem}
.dmf-contact-link{display:flex;align-items:center;gap:.9rem;color:var(--gcid-dmf-muted,#486262);text-decoration:none}
.dmf-contact-link__text{font-family:var(--gvid-dmf-body-font);font-size:clamp(.87rem,calc(.86rem + .16vw),.98rem);line-height:1.6}
.dmf-contact-link:hover{color:var(--gcid-dmf-foreground,#131b26)}
.dmf-contact-link:hover .dmf-contact-link__icon{background:var(--gcid-dmf-accent,#941213);color:var(--gcid-dmf-white,#fafafa)}
.dmf-contact-callout{display:flex;flex-direction:column;gap:1rem;padding:1.6rem;border-radius:1.35rem;background:var(--gcid-dmf-primary,#131b26);color:var(--gcid-dmf-white,#fafafa)}
.dmf-callout-title{color:var(--gcid-dmf-white,#fafafa)}
.dmf-callout-copy{font-family:var(--gvid-dmf-body-font);font-size:clamp(.87rem,calc(.86rem + .16vw),.98rem);line-height:1.8;color:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 74%,transparent);margin:0}
.dmf-contact-form-shell{padding:1.75rem;border:1px solid var(--gcid-dmf-border,#a1a5a4);border-radius:1.35rem;background:var(--gcid-dmf-background,#fafafa);box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-primary,#131b26) 8%,transparent)}
.dmf-contact-form{display:flex;flex-direction:column;gap:1rem}
.dmf-form-field{display:flex;flex-direction:column;gap:.5rem}
.dmf-form-label{font-family:var(--gvid-dmf-body-font);font-size:clamp(.77rem,calc(.76rem + .1vw),.85rem);font-weight:700;color:var(--gcid-dmf-foreground,#131b26)}
.dmf-contact-form input,.dmf-contact-form textarea{width:100%;border:1px solid var(--gcid-dmf-border,#a1a5a4);border-radius:1rem;background:var(--gcid-dmf-background,#fafafa);padding:1rem 1.1rem;font-family:var(--gvid-dmf-body-font);font-size:clamp(.89rem,calc(.88rem + .15vw),.99rem);color:var(--gcid-dmf-foreground,#131b26);box-sizing:border-box}
.dmf-contact-form textarea{resize:vertical;min-height:9.5rem}
.dmf-contact-form input:focus,.dmf-contact-form textarea:focus{outline:2px solid color-mix(in srgb,var(--gcid-dmf-accent,#941213) 35%,transparent);outline-offset:0;border-color:color-mix(in srgb,var(--gcid-dmf-accent,#941213) 44%,transparent)}
.dmf-form-submit{display:inline-flex;align-items:center;justify-content:center;align-self:flex-start;padding:.95rem 1.6rem;border:0;border-radius:.95rem;background:linear-gradient(135deg,var(--gcid-dmf-accent,#941213),color-mix(in srgb,var(--gcid-dmf-accent,#941213) 68%,var(--gcid-dmf-overlay,#2b5b5b)));color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-body-font);font-size:var(--gvid-dmf-text-base);font-weight:700;box-shadow:0 1rem 2.25rem color-mix(in srgb,var(--gcid-dmf-accent,#941213) 25%,transparent);cursor:pointer}
@media (max-width: 980px){.dmf-flex-cards--three>.dmf-lift-card,.dmf-flex-cards--three>.dmf-process-step{flex-basis:calc((100% - 1.5rem)/2)}}
@media (max-width: 767px){.dmf-home-hero-section{padding-top:7.5rem}.dmf-home-shell-row{padding:0 1rem!important}.dmf-flex-cards--three>.dmf-lift-card,.dmf-flex-cards--three>.dmf-process-step,.dmf-contact-panel--info,.dmf-contact-panel--form{flex-basis:100%}.dmf-home-actions{width:100%}.dmf-button{width:100%}.dmf-button .et_pb_button,.dmf-button a.et_pb_button,.dmf-form-submit{width:100%!important}}
</style>
HTML;
	}

	private function build_footer_runtime_markup() {
		return <<<'HTML'
<style id="dmf-footer-runtime-styles">
.dmf-footer-runtime{display:none!important}
.dmf-text-gradient{display:inline-block;background:linear-gradient(135deg,var(--gcid-dmf-accent,#941213),var(--gcid-dmf-overlay,#2b5b5b));color:transparent;-webkit-background-clip:text;background-clip:text}
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
.dmf-footer-links a:hover{color:var(--gcid-dmf-accent,#941213)}
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
.dmf-portfolio-loop-shell .dmf-portfolio-loop-container{display:flex!important;flex-wrap:wrap!important;align-items:stretch!important;gap:1.5rem!important;width:100%!important}
.dmf-portfolio-loop-shell .dmf-portfolio-loop-item{flex:1 1 calc((100% - 3rem)/3)!important;min-width:18rem!important;max-width:none!important;padding:0!important;background:transparent!important;border:0!important;box-shadow:none!important}
.dmf-portfolio-loop-shell .dmf-portfolio-card-image{position:relative!important;display:block!important;aspect-ratio:4/3;overflow:hidden!important;border-radius:1.35rem!important}
.dmf-portfolio-loop-shell .dmf-portfolio-card-image img{width:100%!important;height:100%!important;object-fit:cover!important;transition:transform .45s ease!important}
.dmf-portfolio-loop-shell .dmf-portfolio-card-image::before{content:"";position:absolute;inset:0;background:color-mix(in srgb,var(--gcid-dmf-primary,#131b26) 34%,transparent);opacity:0;transition:opacity .28s ease;z-index:1}
.dmf-portfolio-loop-shell .dmf-portfolio-card-image::after{content:"↗";position:absolute;top:50%;left:50%;width:3rem;height:3rem;display:flex;align-items:center;justify-content:center;border-radius:999px;background:color-mix(in srgb,var(--gcid-dmf-white,#fafafa) 16%,transparent);color:var(--gcid-dmf-white,#fafafa);font-family:var(--gvid-dmf-heading-font);font-size:1.25rem;transform:translate(-50%,-50%);opacity:0;transition:opacity .28s ease,transform .28s ease;z-index:2}
.dmf-portfolio-loop-shell .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image img{transform:scale(1.05)}
.dmf-portfolio-loop-shell .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::before,.dmf-portfolio-loop-shell .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::after{opacity:1}
.dmf-portfolio-loop-shell .dmf-portfolio-loop-item:hover .dmf-portfolio-card-image::after{transform:translate(-50%,-50%) scale(1)}
.dmf-portfolio-loop-shell .dmf-portfolio-card-title h3{font-size:clamp(1.18rem,calc(1.1rem + .35vw),1.42rem)!important;transition:color .2s ease}
.dmf-portfolio-loop-shell .dmf-portfolio-loop-item:hover .dmf-portfolio-card-title h3{color:var(--gcid-dmf-accent,#941213)!important}
.dmf-portfolio-loop-shell .dmf-portfolio-card-excerpt p{max-width:36rem!important}
.dmf-portfolio-loop-shell .dmf-portfolio-button .et_pb_button,.dmf-portfolio-loop-shell .dmf-portfolio-button a.et_pb_button{padding:0!important;border:0!important;background:transparent!important;color:var(--gcid-dmf-accent,#941213)!important;box-shadow:none!important}
@media (max-width: 980px){.dmf-portfolio-loop-shell .dmf-portfolio-loop-item{flex-basis:calc((100% - 1.5rem)/2)!important}}
@media (max-width: 767px){.dmf-portfolio-loop-shell .dmf-portfolio-loop-item{flex-basis:100%!important}}
</style>
HTML;
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
			return $this->apply_home_page_layout_fixes( (string) $content );
		}

		return (string) $content;
	}

	private function apply_home_page_layout_fixes( $content ) {
		$replacements = [
			'Home Hero Section' => $this->build_home_hero_section(),
			'About Section'     => $this->build_about_section(),
			'Services Section'  => $this->build_services_section(),
			'Process Section'   => $this->build_process_section(),
			'Contact Section'   => $this->build_contact_section(),
		];

		$content = (string) $content;

		foreach ( $replacements as $label => $replacement ) {
			$updated = $this->replace_divi_section_by_label( $content, $label, $replacement );

			if ( null !== $updated ) {
				$content = $updated;
				continue;
			}

			$this->warn( sprintf( 'Could not replace the "%s" section during home page normalization.', $label ) );
		}

		return $content;
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

		if ( 'et_header_layout' === $post_type ) {
			return $this->apply_global_header_layout_fix( (string) $content );
		}

		if ( 'et_footer_layout' === $post_type ) {
			return $this->apply_global_footer_layout_fix( (string) $content );
		}

		return (string) $content;
	}

	private function apply_global_header_layout_fix( $content ) {
		return $this->build_global_header_layout_content( (string) $content );
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
								'display'    => 'flex',
								'align-items' => 'center',
								'gap'        => '0.9rem',
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
						'flex'       => '1 1 auto',
						'min-width'  => '0',
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
	top:0 !important;
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
	top:0 !important;
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
	padding:.45rem 0 .35rem !important;
}
.dmf-global-header-shell.dmf-header-is-scrolled{
	background:rgba(237,236,237,0.96) !important;
	position:fixed !important;
	top:var(--wp-admin--admin-bar--height,0px) !important;
	right:0 !important;
	left:0 !important;
}
.dmf-global-header-shell .dmf-global-header-menu li.dmf-menu-cta{
	display:none !important;
}
.dmf-global-header-shell .dmf-global-header-column{
	display:flex !important;
	align-items:center !important;
	justify-content:space-between !important;
	flex-wrap:nowrap !important;
	gap:.9rem !important;
	padding:0 !important;
	margin:0 !important;
}
.dmf-global-header-shell .dmf-global-header-menu{
	flex:1 1 auto !important;
	min-width:0 !important;
	background:transparent !important;
	margin:0 !important;
	padding:0 !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__wrap{
	width:100% !important;
	justify-content:space-between !important;
	align-items:center !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__logo-wrap{
	margin-right:1.15rem !important;
}
.dmf-global-header-shell .dmf-global-header-menu .et_pb_menu__menu,
.dmf-global-header-shell .dmf-global-header-menu .et-menu-nav{
	margin-left:auto !important;
}
.dmf-global-header-shell .dmf-global-header-menu ul.et-menu{
	gap:1.05rem !important;
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
	font-size:.94rem !important;
	font-weight:600 !important;
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
.dmf-global-header-shell .dmf-header-cta-button{
	flex:0 0 auto !important;
	margin:0 !important;
}
.dmf-global-header-shell .dmf-header-cta-button .et_pb_button_module_wrapper{
	margin:0 !important;
}
.dmf-global-header-shell .dmf-header-cta-button .et_pb_button,
.dmf-global-header-shell .dmf-header-cta-button a.et_pb_button{
	background:var(--gcid-dmf-white,#fafafa) !important;
	border:1px solid color-mix(in srgb, var(--gcid-dmf-border,#a1a5a4) 84%, transparent) !important;
	border-radius:0.65rem !important;
	box-shadow:none !important;
	color:var(--gcid-dmf-foreground,#131b26) !important;
	display:inline-flex !important;
	align-items:center !important;
	font-size:0.88rem !important;
	line-height:1.1 !important;
	margin:0 !important;
	min-height:auto !important;
	padding:0.38rem 0.72rem !important;
	white-space:nowrap !important;
	text-decoration:none !important;
}
.dmf-global-header-shell .dmf-header-cta-button .et_pb_button:after,
.dmf-global-header-shell .dmf-header-cta-button a.et_pb_button:after{
	display:none !important;
}
.dmf-global-header-shell .dmf-header-cta-button .et_pb_button:hover,
.dmf-global-header-shell .dmf-header-cta-button a.et_pb_button:hover{
	background:var(--gcid-dmf-card,#edeced) !important;
	opacity:1 !important;
}
@media (max-width: 980px){
	.dmf-global-header-shell .dmf-global-header-row{
		width:min(80rem,calc(100% - 2rem)) !important;
	}
	.dmf-global-header-shell .dmf-header-cta-button{
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
		linkActiveScrolled:'var(--gcid-dmf-foreground,#131b26)'
	};
	var menuLinkSelector='.dmf-global-header-menu .et-menu>li>a,.dmf-global-header-menu .et-menu-nav>ul>li>a,.dmf-global-header-menu .et_pb_menu__menu>nav>ul>li>a,.dmf-global-header-menu .et_mobile_menu a';
	var activeLinkSelector='.dmf-global-header-menu .current-menu-item>a,.dmf-global-header-menu .current-menu-ancestor>a,.dmf-global-header-menu .current_page_item>a,.dmf-global-header-menu .current-page-ancestor>a';
	function setImportant(node,property,value){
		if(!node){return;}
		node.style.setProperty(property,value,'important');
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
		setImportant(header,'top',isScrolled ? 'var(--wp-admin--admin-bar--height,0px)' : '0');
		setImportant(header,'right','0');
		setImportant(header,'left','0');
		setImportant(header,'width','100%');
		Array.prototype.slice.call(header.querySelectorAll(menuLinkSelector)).forEach(function(link){
			setImportant(link,'color',linkColor);
		});
		Array.prototype.slice.call(header.querySelectorAll(activeLinkSelector)).forEach(function(link){
			setImportant(link,'color',activeColor);
		});
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
