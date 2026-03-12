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
		$files[] = 'layout-404.json';

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

		if ( ! is_dir( $this->exports_dir ) ) {
			throw new RuntimeException( 'Bundled export directory not found.' );
		}

		$missing_files = $this->get_missing_export_files();
		if ( ! empty( $missing_files ) ) {
			throw new RuntimeException(
				'Bundled export files are missing: ' . implode( ', ', $missing_files )
			);
		}

		if ( ! $this->is_divi_ready() ) {
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

		return $summary;
	}

	public function fix_portfolio_loops( array $args = [] ) {
		$this->warnings = [];

		$summary = $this->apply_portfolio_loop_fix( $args );

		if ( empty( $args['dry_run'] ) && ( ! isset( $args['flush_cache'] ) || ! empty( $args['flush_cache'] ) ) ) {
			$this->flush_divi_caches();
		}

		$summary['warnings'] = $this->warnings;

		return $summary;
	}

	private function warn( $message ) {
		$this->warnings[] = (string) $message;
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
		$content = $this->get_single_layout_content( $export['data'] ?? [] );

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
				continue;
			}

			$summary['updated'][] = sprintf(
				'%s (#%d)%s',
				$target['label'],
				$page->ID,
				$dry_run ? ' [dry run]' : ''
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
			foreach ( $this->find_templates_with_body_overrides( $default_template ? (int) $default_template->ID : 0 ) as $template ) {
				$updated[] = sprintf( 'Clear stale body override on template #%d', $template->ID );
			}

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

		$updated = array_merge( $updated, $this->neutralize_other_theme_builder_body_overrides( (int) $template_id ) );

		return $updated;
	}

	private function upsert_theme_builder_layout( array $layout_export, $existing_layout_id, $dry_run ) {
		$post_type = sanitize_key( (string) ( $layout_export['post_type'] ?? '' ) );
		$title     = sanitize_text_field( (string) ( $layout_export['post_title'] ?? 'Theme Builder Layout' ) );
		$content   = $this->get_single_layout_content( $layout_export['data'] ?? [] );

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

	private function theme_template_area_enabled_flag( $layout_type, $layout_id, array $template_export ) {
		if ( 'body' === $layout_type && (int) $layout_id <= 0 ) {
			return '1';
		}

		return ! empty( $template_export['layouts'][ $layout_type ]['enabled'] ) ? '1' : '0';
	}

	private function find_templates_with_body_overrides( $exclude_template_id = 0 ) {
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

	private function neutralize_other_theme_builder_body_overrides( $keep_template_id ) {
		$updated = [];

		foreach ( $this->find_templates_with_body_overrides( $keep_template_id ) as $template ) {
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
			return [
				"Would sync {$menu_name}",
				"Location {$location}",
				'Items ' . count( $items ),
			];
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

	private function flush_divi_caches() {
		if ( function_exists( 'et_update_option' ) ) {
			et_update_option( 'et_pb_clear_templates_cache', true );
		}

		if ( class_exists( 'ET_Core_PageResource' ) ) {
			ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
		}
	}
}
