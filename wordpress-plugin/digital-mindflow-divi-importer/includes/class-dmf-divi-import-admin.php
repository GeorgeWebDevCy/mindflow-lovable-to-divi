<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DMF_Divi_Import_Admin {

	const PAGE_SLUG = 'dmf-divi-importer';

	const ACTION = 'dmf_divi_importer_run';

	const FIX_ACTION = 'dmf_divi_importer_fix_portfolio_loops';

	const PORTFOLIO_BODY_ACTION = 'dmf_divi_importer_refresh_portfolio_body';

	const HEADER_DIAGNOSTIC_ACTION = 'dmf_divi_importer_capture_header_diagnostics';

	const CURRENT_SITE_ACTION = 'dmf_divi_importer_apply_current_site_exports';

	const PORTFOLIO_ENHANCEMENTS_ACTION = 'dmf_divi_importer_apply_portfolio_case_study_enhancements';

	const CLEAR_LOG_ACTION = 'dmf_divi_importer_clear_log';

	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_run' ] );
		add_action( 'admin_post_' . self::FIX_ACTION, [ __CLASS__, 'handle_fix_portfolio_loops' ] );
		add_action( 'admin_post_' . self::PORTFOLIO_BODY_ACTION, [ __CLASS__, 'handle_refresh_portfolio_body' ] );
		add_action( 'admin_post_' . self::HEADER_DIAGNOSTIC_ACTION, [ __CLASS__, 'handle_capture_header_diagnostics' ] );
		add_action( 'admin_post_' . self::CURRENT_SITE_ACTION, [ __CLASS__, 'handle_apply_current_site_exports' ] );
		add_action( 'admin_post_' . self::PORTFOLIO_ENHANCEMENTS_ACTION, [ __CLASS__, 'handle_apply_portfolio_case_study_enhancements' ] );
		add_action( 'admin_post_' . self::CLEAR_LOG_ACTION, [ __CLASS__, 'handle_clear_log' ] );
		add_action( 'acf/init', [ __CLASS__, 'register_portfolio_field_group' ] );
	}

	public static function register_page() {
		add_menu_page(
			'Digital MindFlow Divi Importer',
			'MindFlow Divi',
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-layout',
			58
		);
	}

	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this importer.', 'dmf-divi-importer' ) );
		}

		check_admin_referer( self::ACTION );
		$args = [
			'dry_run'              => ! empty( $_POST['dry_run'] ),
			'include_pages'        => ! empty( $_POST['include_pages'] ),
			'include_theme'        => ! empty( $_POST['include_theme'] ),
			'include_menu'         => ! empty( $_POST['include_menu'] ),
			'create_missing_pages' => ! empty( $_POST['create_missing_pages'] ),
			'home_slug'            => sanitize_text_field( wp_unslash( $_POST['home_slug'] ?? '' ) ),
		];
		DMF_Divi_Import_Logger::log( 'info', 'Admin import action submitted.', $args );

		$runner = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
		$report = [
			'status'  => 'success',
			'title'   => 'Import complete.',
			'summary' => [],
		];

		try {
			$summary = $runner->run( $args );

			$report['title']   = ! empty( $summary['dry_run'] ) ? 'Dry run complete.' : 'Import complete.';
			$report['summary'] = $summary;
		} catch ( Throwable $error ) {
			$report['status'] = 'error';
			$report['title']  = 'Import failed.';
			$report['error']  = $error->getMessage();
			DMF_Divi_Import_Logger::log(
				'error',
				'Import action failed.',
				[
					'message' => $error->getMessage(),
					'type'    => get_class( $error ),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				]
			);
		}

		set_transient( self::notice_key(), $report, MINUTE_IN_SECONDS * 10 );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_fix_portfolio_loops() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'dmf-divi-importer' ) );
		}

		check_admin_referer( self::FIX_ACTION );
		$args = [
			'home_slug' => sanitize_text_field( wp_unslash( $_POST['home_slug'] ?? '' ) ),
		];
		DMF_Divi_Import_Logger::log( 'info', 'Admin portfolio loop fix submitted.', $args );

		$runner = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
		$report = [
			'status'  => 'success',
			'title'   => 'Portfolio loop fix complete.',
			'summary' => [],
		];

		try {
			$summary = $runner->fix_portfolio_loops( $args );

			$report['summary'] = [
				'portfolio_loops_updated' => $summary['updated'] ?? [],
				'portfolio_loops_missing' => $summary['missing'] ?? [],
				'warnings'                => $summary['warnings'] ?? [],
			];
		} catch ( Throwable $error ) {
			$report['status'] = 'error';
			$report['title']  = 'Portfolio loop fix failed.';
			$report['error']  = $error->getMessage();
			DMF_Divi_Import_Logger::log(
				'error',
				'Portfolio loop fix action failed.',
				[
					'message' => $error->getMessage(),
					'type'    => get_class( $error ),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				]
			);
		}

		set_transient( self::notice_key(), $report, MINUTE_IN_SECONDS * 10 );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_refresh_portfolio_body() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'dmf-divi-importer' ) );
		}

		check_admin_referer( self::PORTFOLIO_BODY_ACTION );

		$runner = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
		$report = [
			'status'  => 'success',
			'title'   => 'Portfolio body layout refreshed.',
			'summary' => [],
		];

		DMF_Divi_Import_Logger::log( 'info', 'Admin portfolio body refresh submitted.' );

		try {
			$summary = $runner->refresh_portfolio_single_template();

			$report['summary'] = [
				'theme_updated' => $summary['updated'] ?? [],
				'warnings'      => $summary['warnings'] ?? [],
			];
		} catch ( Throwable $error ) {
			$report['status'] = 'error';
			$report['title']  = 'Portfolio body layout refresh failed.';
			$report['error']  = $error->getMessage();
			DMF_Divi_Import_Logger::log(
				'error',
				'Portfolio body refresh action failed.',
				[
					'message' => $error->getMessage(),
					'type'    => get_class( $error ),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				]
			);
		}

		set_transient( self::notice_key(), $report, MINUTE_IN_SECONDS * 10 );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_apply_current_site_exports() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'dmf-divi-importer' ) );
		}

		check_admin_referer( self::CURRENT_SITE_ACTION );
		$args = [
			'dry_run'              => ! empty( $_POST['dry_run'] ),
			'create_missing_pages' => ! empty( $_POST['create_missing_pages'] ),
			'home_slug'            => sanitize_text_field( wp_unslash( $_POST['home_slug'] ?? '' ) ),
		];

		DMF_Divi_Import_Logger::log( 'info', 'Admin current site export refresh submitted.', $args );

		$runner = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
		$report = [
			'status'  => 'success',
			'title'   => 'Current site snapshot applied.',
			'summary' => [],
		];

		try {
			$summary = $runner->apply_current_site_exports( $args );

			$report['title']   = ! empty( $summary['dry_run'] )
				? 'Current site snapshot dry run complete.'
				: 'Current site snapshot applied.';
			$report['summary'] = $summary;
		} catch ( Throwable $error ) {
			$report['status'] = 'error';
			$report['title']  = 'Current site snapshot failed.';
			$report['error']  = $error->getMessage();
			DMF_Divi_Import_Logger::log(
				'error',
				'Current site export refresh failed.',
				[
					'message' => $error->getMessage(),
					'type'    => get_class( $error ),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				]
			);
		}

		set_transient( self::notice_key(), $report, MINUTE_IN_SECONDS * 10 );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_apply_portfolio_case_study_enhancements() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'dmf-divi-importer' ) );
		}

		check_admin_referer( self::PORTFOLIO_ENHANCEMENTS_ACTION );

		$args = [
			'dry_run' => ! empty( $_POST['dry_run'] ),
		];

		DMF_Divi_Import_Logger::log( 'info', 'Admin portfolio case study enhancements submitted.', $args );

		$runner = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
		$report = [
			'status'  => 'success',
			'title'   => 'Portfolio case study enhancements applied.',
			'summary' => [],
		];

		try {
			$summary = $runner->apply_portfolio_case_study_enhancements( $args );

			$report['title']   = ! empty( $summary['dry_run'] )
				? 'Portfolio case study enhancements dry run complete.'
				: 'Portfolio case study enhancements applied.';
			$report['summary'] = $summary;
		} catch ( Throwable $error ) {
			$report['status'] = 'error';
			$report['title']  = 'Portfolio case study enhancements failed.';
			$report['error']  = $error->getMessage();
			DMF_Divi_Import_Logger::log(
				'error',
				'Portfolio case study enhancements action failed.',
				[
					'message' => $error->getMessage(),
					'type'    => get_class( $error ),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				]
			);
		}

		set_transient( self::notice_key(), $report, MINUTE_IN_SECONDS * 10 );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'dmf-divi-importer' ) );
		}

		check_admin_referer( self::CLEAR_LOG_ACTION );

		DMF_Divi_Import_Logger::clear();

		set_transient(
			self::notice_key(),
			[
				'status' => 'success',
				'title'  => 'Logger cleared.',
			],
			MINUTE_IN_SECONDS * 10
		);

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function register_portfolio_field_group() {
		try {
			$runner = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
			$runner->ensure_portfolio_field_groups_registered();
		} catch ( Throwable $error ) {
			DMF_Divi_Import_Logger::log(
				'error',
				'Portfolio ACF field group registration failed.',
				[
					'message' => $error->getMessage(),
					'type'    => get_class( $error ),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				]
			);
		}
	}

	public static function handle_capture_header_diagnostics() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'dmf-divi-importer' ) );
		}

		check_admin_referer( self::HEADER_DIAGNOSTIC_ACTION );

		$args = [
			'home_slug' => sanitize_text_field( wp_unslash( $_POST['home_slug'] ?? '' ) ),
		];

		DMF_Divi_Import_Logger::log( 'info', 'Admin header diagnostics submitted.', $args );

		$runner = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );

		try {
			$diagnostics = $runner->capture_header_diagnostics( $args );

			set_transient(
				self::notice_key(),
				[
					'status'  => 'success',
					'title'   => 'Header diagnostics captured.',
					'summary' => [
						'diagnostics' => [
							'Latest header diagnostics were added to the logger below.',
							sprintf(
								'Default template: %s',
								! empty( $diagnostics['theme_builder']['default_template']['id'] )
									? '#' . (int) $diagnostics['theme_builder']['default_template']['id']
									: 'not found'
							),
							sprintf(
								'Header layout: %s',
								! empty( $diagnostics['theme_builder']['header_layout_id'] )
									? '#' . (int) $diagnostics['theme_builder']['header_layout_id']
									: 'not found'
							),
							sprintf(
								'Home page: %s',
								! empty( $diagnostics['home_page']['page']['id'] )
									? '#' . (int) $diagnostics['home_page']['page']['id']
									: 'not found'
							),
						],
					],
				],
				MINUTE_IN_SECONDS * 10
			);
		} catch ( Throwable $error ) {
			DMF_Divi_Import_Logger::log(
				'error',
				'Header diagnostics action failed.',
				[
					'message' => $error->getMessage(),
					'type'    => get_class( $error ),
					'file'    => $error->getFile(),
					'line'    => $error->getLine(),
				]
			);

			set_transient(
				self::notice_key(),
				[
					'status' => 'error',
					'title'  => 'Header diagnostics failed.',
					'error'  => $error->getMessage(),
				],
				MINUTE_IN_SECONDS * 10
			);
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dmf-divi-importer' ) );
		}

		$runner                = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
		$notice                = get_transient( self::notice_key() );
		$missing_files         = $runner->get_missing_export_files();
		$current_missing_files = $runner->get_current_site_missing_export_files();
		$divi_ready            = $runner->is_divi_ready();
		$acf_ready             = $runner->is_acf_ready();
		$log_entries           = DMF_Divi_Import_Logger::get_entries( 100 );
		$log_count             = DMF_Divi_Import_Logger::count();

		delete_transient( self::notice_key() );
		?>
		<div class="wrap">
			<h1>Digital MindFlow Divi Importer</h1>
			<p>This tool imports the bundled Divi 5 Home and Portfolio layouts, updates the global Theme Builder header and footer, creates a single Theme Builder body template for <code>portfolio</code> items, creates the primary WordPress menu used by the Divi 5 menu block, can switch the Home and Portfolio portfolio sections over to native Divi 5 Portfolio post type loops, can reapply the current live Home plus global Theme Builder snapshot bundled in the plugin, and keeps a persistent log for troubleshooting.</p>

			<?php self::render_notice( $notice ); ?>

			<div style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<h2 style="margin-top: 0;">Environment Check</h2>
				<ul style="list-style: disc; padding-left: 20px;">
					<li>
						Divi 5 portability API:
						<strong><?php echo $divi_ready ? 'ready' : 'not ready'; ?></strong>
					</li>
					<li>
						Bundled export files:
						<strong><?php echo empty( $missing_files ) ? 'complete' : 'missing files'; ?></strong>
					</li>
					<li>
						ACF field registration:
						<strong><?php echo $acf_ready ? 'ready' : 'not ready'; ?></strong>
					</li>
					<li>
						Current site snapshot files:
						<strong><?php echo empty( $current_missing_files ) ? 'complete' : 'missing files'; ?></strong>
					</li>
					<li>
						Export path:
						<code><?php echo esc_html( $runner->get_exports_dir() ); ?></code>
					</li>
					<li>
						Current snapshot path:
						<code><?php echo esc_html( $runner->get_current_site_exports_dir() ); ?></code>
					</li>
				</ul>

				<?php if ( ! empty( $missing_files ) ) : ?>
					<div class="notice notice-error inline">
						<p><strong>Missing export files:</strong> <?php echo esc_html( implode( ', ', $missing_files ) ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $current_missing_files ) ) : ?>
					<div class="notice notice-error inline">
						<p><strong>Missing current snapshot files:</strong> <?php echo esc_html( implode( ', ', $current_missing_files ) ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! $divi_ready ) : ?>
					<div class="notice notice-error inline">
						<p>Activate Divi before running this importer.</p>
					</div>
				<?php endif; ?>

				<?php if ( ! $acf_ready ) : ?>
					<div class="notice notice-error inline">
						<p>Activate Advanced Custom Fields before applying the portfolio case study enhancements.</p>
					</div>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

				<h2 style="margin-top: 0;">Run Import</h2>
				<p>Leave all three items enabled for a full refresh of the current Home, Portfolio, Theme Builder, and menu setup.</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="dmf-home-slug">Home page slug</label>
							</th>
							<td>
								<input type="text" id="dmf-home-slug" name="home_slug" class="regular-text" placeholder="home">
								<p class="description">Optional. Only used if WordPress static front page is not already set.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Import scope</th>
							<td>
								<label style="display:block; margin-bottom:8px;">
									<input type="checkbox" name="create_missing_pages" value="1" checked>
									Create missing WordPress pages automatically when needed
								</label>
								<label style="display:block; margin-bottom:8px;">
									<input type="checkbox" name="include_pages" value="1" checked>
									Update page layouts by slug (Home and Portfolio only)
								</label>
								<label style="display:block; margin-bottom:8px;">
									<input type="checkbox" name="include_theme" value="1" checked>
									Update global Theme Builder header/footer and the single portfolio template
								</label>
								<label style="display:block;">
									<input type="checkbox" name="include_menu" value="1" checked>
									Create or sync the normal WordPress primary menu
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Safety</th>
							<td>
								<label>
									<input type="checkbox" name="dry_run" value="1">
									Dry run only
								</label>
								<p class="description">Dry run previews what will be touched without writing to the database.</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit" style="padding-bottom: 0;">
					<button type="submit" class="button button-primary" <?php disabled( ! empty( $missing_files ) || ! $divi_ready ); ?>>
						Run Import
					</button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<?php wp_nonce_field( self::CURRENT_SITE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CURRENT_SITE_ACTION ); ?>">

				<h2 style="margin-top: 0;">Apply Current Site Snapshot</h2>
				<p>Reimport only the currently working Home layout plus the currently working default global Theme Builder header/footer snapshot bundled in <code>exports/current-site</code>. This does not touch the Portfolio page layout or the WordPress menu.</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="dmf-home-slug-current">Home page slug</label>
							</th>
							<td>
								<input type="text" id="dmf-home-slug-current" name="home_slug" class="regular-text" placeholder="home">
								<p class="description">Optional fallback if WordPress static front page is not already configured.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Options</th>
							<td>
								<label style="display:block; margin-bottom:8px;">
									<input type="checkbox" name="create_missing_pages" value="1" checked>
									Create the Home page automatically if it is missing
								</label>
								<label style="display:block;">
									<input type="checkbox" name="dry_run" value="1">
									Dry run only
								</label>
								<p class="description">Dry run previews the Home page and Theme Builder updates without writing to the database.</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit" style="padding-bottom: 0;">
					<button type="submit" class="button button-secondary" <?php disabled( ! empty( $current_missing_files ) || ! $divi_ready ); ?>>
						Apply Current Site Snapshot
					</button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<?php wp_nonce_field( self::PORTFOLIO_ENHANCEMENTS_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PORTFOLIO_ENHANCEMENTS_ACTION ); ?>">

				<h2 style="margin-top: 0;">Apply Portfolio Case Study Enhancements</h2>
				<p>Apply only the next portfolio-specific changes: landscape Portfolio page card images, the refreshed single <code>portfolio</code> body layout with overview/challenge image sections plus a project gallery, and the bundled ACF field definition for those new image fields.</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">Safety</th>
							<td>
								<label>
									<input type="checkbox" name="dry_run" value="1">
									Dry run only
								</label>
								<p class="description">Dry run previews the Portfolio page and Theme Builder changes without writing to the database.</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit" style="padding-bottom: 0;">
					<button type="submit" class="button button-secondary" <?php disabled( ! $divi_ready || ! $acf_ready ); ?>>
						Apply Portfolio Case Study Enhancements
					</button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<?php wp_nonce_field( self::FIX_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::FIX_ACTION ); ?>">

				<h2 style="margin-top: 0;">Fix Portfolio Loops</h2>
				<p>Replace the static imported portfolio cards on the Home and Portfolio pages with native Divi 5 loop-builder output from the <code>portfolio</code> post type.</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="dmf-home-slug-fix">Home page slug</label>
							</th>
							<td>
								<input type="text" id="dmf-home-slug-fix" name="home_slug" class="regular-text" placeholder="home">
								<p class="description">Optional fallback if WordPress static front page is not already configured.</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit" style="padding-bottom: 0;">
					<button type="submit" class="button button-secondary">
						Fix Portfolio Loops
					</button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<?php wp_nonce_field( self::PORTFOLIO_BODY_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PORTFOLIO_BODY_ACTION ); ?>">

				<h2 style="margin-top: 0;">Refresh Portfolio Body Layout Only</h2>
				<p>Rebuild only the dedicated Theme Builder body template for single <code>portfolio</code> posts. This does not reimport the global header, global footer, Home page, Portfolio page, or menu.</p>

				<p class="submit" style="padding-bottom: 0;">
					<button type="submit" class="button button-secondary" <?php disabled( ! empty( $missing_files ) || ! $divi_ready ); ?>>
						Refresh Portfolio Body Layout Only
					</button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<?php wp_nonce_field( self::HEADER_DIAGNOSTIC_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::HEADER_DIAGNOSTIC_ACTION ); ?>">

				<h2 style="margin-top: 0;">Capture Header Diagnostics</h2>
				<p>Use this when the header overlay, sticky state, or white spacing looks wrong. It logs the current Theme Builder header template, header module attrs, homepage hero attrs, and relevant Divi theme options.</p>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="dmf-home-slug-diagnostics">Home page slug</label>
							</th>
							<td>
								<input type="text" id="dmf-home-slug-diagnostics" name="home_slug" class="regular-text" placeholder="home">
								<p class="description">Optional fallback if WordPress static front page is not already configured.</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit" style="padding-bottom: 0;">
					<button type="submit" class="button button-secondary">
						Capture Header Diagnostics
					</button>
				</p>
			</form>

			<div style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<h2 style="margin-top: 0;">What This Does</h2>
				<ul style="list-style: disc; padding-left: 20px;">
					<li>Finds pages by slug first, then falls back to exact page-title matching.</li>
					<li>Can create any missing pages automatically before importing their layouts.</li>
					<li>Reimports only the bundled Home and Portfolio Divi layouts onto the existing pages with the expected slugs.</li>
					<li>Can replace the static Home and Portfolio portfolio sections with native Divi 5 loop-builder content from the Portfolio post type.</li>
					<li>Updates the default global Theme Builder template using the bundled header and footer export.</li>
					<li>Can reapply the bundled current-site snapshot for the live Home page plus the default global Theme Builder header/footer.</li>
					<li>Creates or updates a dedicated Theme Builder body template for all single <code>portfolio</code> posts.</li>
					<li>Creates or replaces a WordPress menu named <code>Digital MindFlow Primary Navigation</code> and assigns it to <code>primary-menu</code>.</li>
					<li>Reimports the shared Divi 5 global variables and color tokens used by the layouts.</li>
				</ul>
				<p>The Portfolio loop fix writes native Divi content into the pages, so the site does not need this plugin active just to render those loops.</p>
			</div>

			<?php self::render_log_panel( $log_entries, $log_count ); ?>
		</div>
		<?php
	}

	private static function render_notice( $notice ) {
		if ( empty( $notice ) || ! is_array( $notice ) ) {
			return;
		}

		$status = ( $notice['status'] ?? 'success' ) === 'error' ? 'error' : 'success';
		$title  = (string) ( $notice['title'] ?? '' );
		?>
		<div class="notice notice-<?php echo esc_attr( $status ); ?> is-dismissible">
			<p><strong><?php echo esc_html( $title ); ?></strong></p>
			<?php if ( ! empty( $notice['error'] ) ) : ?>
				<p><?php echo esc_html( $notice['error'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $notice['summary'] ) && is_array( $notice['summary'] ) ) : ?>
				<?php self::render_summary( $notice['summary'] ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_summary( array $summary ) {
		$sections = [
			'pages_updated'           => 'Updated pages',
			'pages_created'           => 'Created pages',
			'pages_missing'           => 'Missing pages',
			'acf_updated'             => 'ACF field groups',
			'portfolio_loops_updated' => 'Portfolio loop pages',
			'portfolio_loops_missing' => 'Portfolio loop pages missing',
			'theme_updated'           => 'Theme Builder',
			'menu_updated'            => 'Primary menu',
			'diagnostics'             => 'Diagnostics',
			'warnings'                => 'Warnings',
		];

		foreach ( $sections as $key => $label ) {
			if ( empty( $summary[ $key ] ) || ! is_array( $summary[ $key ] ) ) {
				continue;
			}
			?>
			<p style="margin-bottom: 4px;"><strong><?php echo esc_html( $label ); ?>:</strong></p>
			<ul style="list-style: disc; padding-left: 20px; margin-top: 0;">
				<?php foreach ( $summary[ $key ] as $item ) : ?>
					<li><?php echo esc_html( (string) $item ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php
		}
	}

	private static function render_log_panel( array $entries, $log_count ) {
		?>
		<div style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
			<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
				<div>
					<h2 style="margin: 0;">Logger</h2>
					<p style="margin: 8px 0 0 0;">Recent plugin activity and errors. Keep this when something breaks, then send me the relevant entries.</p>
					<p style="margin: 8px 0 0 0;"><strong>Stored entries:</strong> <?php echo esc_html( (string) $log_count ); ?> / <?php echo esc_html( (string) DMF_Divi_Import_Logger::MAX_ENTRIES ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
					<?php wp_nonce_field( self::CLEAR_LOG_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_LOG_ACTION ); ?>">
					<button type="submit" class="button button-secondary" <?php disabled( empty( $entries ) ); ?>>Clear Log</button>
				</form>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<p style="margin-bottom: 0;">No log entries yet.</p>
			<?php else : ?>
				<div style="margin-top: 16px; display: grid; gap: 12px;">
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						$level        = (string) ( $entry['level'] ?? 'info' );
						$message      = (string) ( $entry['message'] ?? '' );
						$timestamp    = (string) ( $entry['timestamp'] ?? '' );
						$request_id   = (string) ( $entry['request_id'] ?? '' );
						$user_id      = (int) ( $entry['user_id'] ?? 0 );
						$context_json = ! empty( $entry['context'] )
							? wp_json_encode( $entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
							: '';
						$accent       = 'info' === $level ? '#2271b1' : ( 'warning' === $level ? '#996800' : '#b32d2e' );
						?>
						<div style="border: 1px solid #dcdcde; border-left: 4px solid <?php echo esc_attr( $accent ); ?>; border-radius: 6px; padding: 12px 14px; background: #fcfcfd;">
							<div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
								<div>
									<div style="font-weight:600;"><?php echo esc_html( strtoupper( $level ) ); ?></div>
									<div style="margin-top:4px;"><?php echo esc_html( $message ); ?></div>
								</div>
								<div style="text-align:right; color:#50575e;">
									<div><?php echo esc_html( $timestamp ); ?></div>
									<div>Request: <code><?php echo esc_html( $request_id ); ?></code></div>
									<div>User: <code><?php echo esc_html( (string) $user_id ); ?></code></div>
								</div>
							</div>
							<?php if ( '' !== $context_json ) : ?>
								<pre style="margin:12px 0 0 0; padding:12px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; overflow:auto; white-space:pre-wrap;"><?php echo esc_html( $context_json ); ?></pre>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function page_url( array $args = [] ) {
		return add_query_arg(
			array_merge(
				[
					'page' => self::PAGE_SLUG,
				],
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	private static function notice_key() {
		return 'dmf_divi_import_notice_' . get_current_user_id();
	}
}
