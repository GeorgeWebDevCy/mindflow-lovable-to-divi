<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DMF_Divi_Import_Admin {

	const PAGE_SLUG = 'dmf-divi-importer';

	const ACTION = 'dmf_divi_importer_run';

	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_run' ] );
	}

	public static function register_page() {
		add_management_page(
			'Digital MindFlow Divi Importer',
			'DMF Divi Importer',
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this importer.', 'dmf-divi-importer' ) );
		}

		check_admin_referer( self::ACTION );

		$runner = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
		$report = [
			'status'  => 'success',
			'title'   => 'Import complete.',
			'summary' => [],
		];

		try {
			$summary = $runner->run(
				[
					'dry_run'       => ! empty( $_POST['dry_run'] ),
					'include_pages' => ! empty( $_POST['include_pages'] ),
					'include_theme' => ! empty( $_POST['include_theme'] ),
					'include_menu'  => ! empty( $_POST['include_menu'] ),
					'create_missing_pages' => ! empty( $_POST['create_missing_pages'] ),
					'home_slug'     => sanitize_text_field( wp_unslash( $_POST['home_slug'] ?? '' ) ),
				]
			);

			$report['title']   = ! empty( $summary['dry_run'] ) ? 'Dry run complete.' : 'Import complete.';
			$report['summary'] = $summary;
		} catch ( Throwable $error ) {
			$report['status'] = 'error';
			$report['title']  = 'Import failed.';
			$report['error']  = $error->getMessage();
		}

		set_transient( self::notice_key(), $report, MINUTE_IN_SECONDS * 10 );

		wp_safe_redirect(
			add_query_arg(
				[
					'page' => self::PAGE_SLUG,
				],
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dmf-divi-importer' ) );
		}

		$runner        = new DMF_Divi_Import_Runner( DMF_DIVI_IMPORTER_DIR . 'exports' );
		$notice        = get_transient( self::notice_key() );
		$missing_files = $runner->get_missing_export_files();
		$divi_ready    = $runner->is_divi_ready();

		delete_transient( self::notice_key() );
		?>
		<div class="wrap">
			<h1>Digital MindFlow Divi Importer</h1>
			<p>This temporary tool imports the bundled Divi 5 layouts, updates the global Theme Builder header and footer, and creates the primary WordPress menu used by the Divi 5 menu block.</p>

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
						Export path:
						<code><?php echo esc_html( $runner->get_exports_dir() ); ?></code>
					</li>
				</ul>

				<?php if ( ! empty( $missing_files ) ) : ?>
					<div class="notice notice-error inline">
						<p><strong>Missing export files:</strong> <?php echo esc_html( implode( ', ', $missing_files ) ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! $divi_ready ) : ?>
					<div class="notice notice-error inline">
						<p>Activate Divi before running this importer.</p>
					</div>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

				<h2 style="margin-top: 0;">Run Import</h2>
				<p>Leave all three items enabled for a full refresh of the current setup.</p>

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
									Update page layouts by slug
								</label>
								<label style="display:block; margin-bottom:8px;">
									<input type="checkbox" name="include_theme" value="1" checked>
									Update global Theme Builder header and footer
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

			<div style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px; margin-top: 20px;">
				<h2 style="margin-top: 0;">What This Does</h2>
				<ul style="list-style: disc; padding-left: 20px;">
					<li>Finds pages by slug first, then falls back to exact page-title matching.</li>
					<li>Can create any missing pages automatically before importing their layouts.</li>
					<li>Reimports the bundled Divi page layouts onto the existing pages with the expected slugs.</li>
					<li>Updates the default global Theme Builder template using the bundled header and footer export.</li>
					<li>Creates or replaces a WordPress menu named <code>Digital MindFlow Primary Navigation</code> and assigns it to <code>primary-menu</code>.</li>
					<li>Reimports the shared Divi 5 global variables and color tokens used by the layouts.</li>
				</ul>
				<p>Deactivate and delete this plugin after the import is signed off.</p>
			</div>
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
			'pages_updated' => 'Updated pages',
			'pages_created' => 'Created pages',
			'pages_missing' => 'Missing pages',
			'theme_updated' => 'Theme Builder',
			'menu_updated'  => 'Primary menu',
			'warnings'      => 'Warnings',
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

	private static function notice_key() {
		return 'dmf_divi_import_notice_' . get_current_user_id();
	}
}
