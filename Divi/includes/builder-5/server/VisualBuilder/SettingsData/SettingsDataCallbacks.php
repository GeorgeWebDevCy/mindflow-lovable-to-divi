<?php
/**
 * Visual Builder Settings.
 *
 * @package Divi
 * @since ??
 */

namespace ET\Builder\VisualBuilder\SettingsData;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

use ET\Builder\Framework\Breakpoint\Breakpoint;
use ET\Builder\Framework\Customizer\Customizer;
use ET\Builder\Framework\Settings\PageSettings;
use ET\Builder\Framework\Settings\Settings;
use ET\Builder\Framework\Theme\Theme;
use ET\Builder\Framework\Utility\ArrayUtility;
use ET\Builder\Framework\Utility\LocaleUtility;
use ET\Builder\Framework\Utility\Conditions;
use ET\Builder\Framework\Utility\Shortcode;

use ET\Builder\Framework\Portability\PortabilityPost;
use ET\Builder\Framework\Utility\SiteSettings;
use ET\Builder\Framework\Utility\DependencyChangeDetector;
use ET\Builder\Packages\Conversion\Conversion;
use ET\Builder\Packages\Conversion\LegacyAttributeNames;
use ET\Builder\Packages\GlobalData\GlobalPreset;
use ET\Builder\Packages\GlobalLayout\GlobalLayout;
use ET\Builder\Packages\Module\Layout\Components\DynamicContent\DynamicContentOptions;
use ET\Builder\Packages\ModuleUtils\ModuleUtils;
use ET\Builder\Packages\WooCommerce\WooCommerceUtils;
use ET\Builder\Services\EmailAccountService\EmailAccountService;
use ET\Builder\Services\SpamProtectionService\SpamProtectionService;
use ET\Builder\ThemeBuilder\Layout;
use ET\Builder\VisualBuilder\AppPreferences\AppPreferences;
use ET\Builder\VisualBuilder\REST\Nonce;
use ET\Builder\VisualBuilder\Saving\SavingUtility;
use ET\Builder\VisualBuilder\Shortcode\ShortcodeUtility;
use ET\Builder\VisualBuilder\Taxonomy;
use ET\Builder\VisualBuilder\TemplatePlaceholder;
use ET\Builder\VisualBuilder\Workspace\Workspace;
use ET\Builder\Packages\Conversion\ShortcodeMigration;
use ET\Builder\VisualBuilder\OffCanvas\OffCanvasHooks;
use ET\Builder\Packages\ModuleUtils\CanvasUtils;

/**
 * Class that provides Settings Data callbacks.
 *
 * @since ??
 */
class SettingsDataCallbacks {
	/**
	 * Current post ID for REST API context.
	 *
	 * @since ??
	 *
	 * @var int
	 */
	private static $_current_post_id = 0;
	/**
	 * Current Theme Builder layout IDs for REST API context.
	 *
	 * @since ??
	 *
	 * @var array<string,int>
	 */
	private static $_current_theme_builder_layout_ids = [];

	/**
	 * Current main loop type for REST API context.
	 *
	 * @since ??
	 *
	 * @var string
	 */
	private static $_current_main_loop_type = 'singular';

	/**
	 * Current main loop settings data for REST API context.
	 *
	 * @since ??
	 *
	 * @var array
	 */
	private static $_current_main_loop_settings_data = [];

	/**
	 * Set the current post ID for REST API context.
	 *
	 * @since ??
	 *
	 * @param int $post_id Post ID.
	 */
	public static function set_current_post_id( int $post_id ): void {
		self::$_current_post_id = $post_id;
	}

	/**
	 * Get the current post ID for REST API context.
	 *
	 * @since ??
	 *
	 * @return int Post ID, or 0 if not set.
	 */
	public static function get_current_post_id(): int {
		return self::$_current_post_id;
	}

	/**
	 * Set Theme Builder layout IDs for REST API context.
	 *
	 * @since ??
	 *
	 * @param array<string,int> $layout_ids Layout IDs keyed by layout area.
	 */
	public static function set_current_theme_builder_layout_ids( array $layout_ids ): void {
		self::$_current_theme_builder_layout_ids = $layout_ids;
	}

	/**
	 * Get Theme Builder layout IDs for REST API context.
	 *
	 * @since ??
	 *
	 * @return array<string,int>
	 */
	public static function get_current_theme_builder_layout_ids(): array {
		return self::$_current_theme_builder_layout_ids;
	}

	/**
	 * Set current mainLoopType for REST API context.
	 *
	 * @since ??
	 *
	 * @param string $main_loop_type Main loop type.
	 */
	public static function set_current_main_loop_type( string $main_loop_type ): void {
		self::$_current_main_loop_type = $main_loop_type;
	}

	/**
	 * Get current mainLoopType for REST API context.
	 *
	 * @since ??
	 *
	 * @return string
	 */
	public static function get_current_main_loop_type(): string {
		return self::$_current_main_loop_type;
	}

	/**
	 * Set current mainLoopSettingsData for REST API context.
	 *
	 * @since ??
	 *
	 * @param array $data Main loop settings data.
	 */
	public static function set_current_main_loop_settings_data( array $data ): void {
		self::$_current_main_loop_settings_data = $data;
	}

	/**
	 * Get current mainLoopSettingsData for REST API context.
	 *
	 * @since ??
	 *
	 * @return array
	 */
	public static function get_current_main_loop_settings_data(): array {
		return self::$_current_main_loop_settings_data;
	}
	/**
	 * Get `breakpoints` setting data.
	 *
	 * @since ??
	 */
	public static function breakpoints() {
		return Breakpoint::get_settings_values();
	}

	/**
	 * Get `conditionalTags` setting data.
	 *
	 * @since ??
	 */
	public static function conditional_tags() {
		static $return = null;

		if ( null === $return || Conditions::is_test_env() ) {
			$return = et_fb_conditional_tag_params();
		}

		return $return;
	}

	/**
	 * Get `currentPage` setting data.
	 *
	 * @since ??
	 */
	public static function current_page() {
		static $return = null;

		if ( null === $return || Conditions::is_test_env() ) {
			$return = et_fb_current_page_params();

			// Determine the main loop type and override post-derived fields for non-singular pages.
			// On non-singular pages WordPress sets global $post to the first post in the main
			// query loop, so the values produced by et_fb_current_page_params() are misleading.
			$return['mainLoopType'] = self::_get_current_main_loop_type();

			if ( 'singular' !== $return['mainLoopType'] ) {
				$non_singular_data              = self::_get_non_singular_page_data( $return['mainLoopType'] );
				$return['id']                   = false;
				$return['title']                = $non_singular_data['title'];
				$return['thumbnailUrl']         = $non_singular_data['thumbnailUrl'];
				$return['thumbnailId']          = $non_singular_data['thumbnailId'];
				$return['mainLoopSettingsData'] = $non_singular_data['mainLoopSettingsData'];
			}

			// When translations are disabled, override post type label with English version.
			$disable_translations = et_get_option( 'divi_disable_translations', 'off' );
			if ( 'on' === $disable_translations && isset( $return['postTypeLabel'] ) ) {
				global $post;
				$post_type = $post->post_type ?? '';

				if ( ! empty( $post_type ) ) {
					// Format slug (e.g., 'product' -> 'Product', 'custom_post_type' -> 'Custom Post Type').
					$return['postTypeLabel'] = ucwords( str_replace( [ '-', '_' ], ' ', $post_type ) );
				}
			}
		}

		return $return;
	}

	/**
	 * Determine the current main loop type for the Visual Builder.
	 *
	 * Returns 'singular' when a real post object is being edited, otherwise
	 * returns a specific non-singular type string that the frontend uses to
	 * decide which page-settings fields are applicable.
	 *
	 * @since ??
	 *
	 * @return string Main loop type identifier.
	 */
	private static function _get_current_main_loop_type(): string {
		if ( is_singular() ) {
			return 'singular';
		}

		if ( is_category() ) {
			return 'category';
		}

		if ( is_tag() ) {
			return 'tag';
		}

		if ( is_tax() ) {
			return 'taxonomy';
		}

		if ( is_author() ) {
			return 'author';
		}

		if ( is_date() ) {
			return 'date';
		}

		if ( is_post_type_archive() ) {
			return 'post_type_archive';
		}

		if ( is_search() ) {
			return 'search';
		}

		if ( is_home() ) {
			return 'home';
		}

		if ( is_404() ) {
			return '404';
		}

		return 'singular';
	}

	/**
	 * Get page data appropriate for a non-singular main loop type.
	 *
	 * Each non-singular main loop type has different data available from WordPress.
	 * This method returns the correct title, thumbnail URL, and thumbnail ID
	 * for the given loop type instead of the misleading values that come from
	 * the first post in the main query loop.
	 *
	 * The `mainLoopSettingsData` key contains editable field values specific to the
	 * loop type (e.g. term name/slug/description for taxonomy archives, author
	 * display name/bio for author archives). These values are consumed by the
	 * page settings store on the frontend.
	 *
	 * @since ??
	 *
	 * @param string $main_loop_type Non-singular main loop type identifier.
	 *
	 * @return array{title: string, thumbnailUrl: string, thumbnailId: string, mainLoopSettingsData: array} Page data.
	 */
	private static function _get_non_singular_page_data( string $main_loop_type ): array {
		$data = [
			'title'                => '',
			'thumbnailUrl'         => '',
			'thumbnailId'          => '',
			'mainLoopSettingsData' => [],
		];

		switch ( $main_loop_type ) {
			case 'category':
			case 'tag':
			case 'taxonomy':
				$term = get_queried_object();
				if ( $term instanceof \WP_Term ) {
					$data['title'] = $term->name;

					$data['mainLoopSettingsData'] = [
						'termId'          => $term->term_id,
						'taxonomy'        => $term->taxonomy,
						'termName'        => $term->name,
						'termSlug'        => $term->slug,
						'termDescription' => $term->description,
						'canEdit'         => current_user_can( 'edit_term', $term->term_id ),
					];

					$thumbnail_id = get_term_meta( $term->term_id, 'thumbnail_id', true );
					if ( $thumbnail_id ) {
						$data['thumbnailId']  = $thumbnail_id;
						$data['thumbnailUrl'] = wp_get_attachment_url( (int) $thumbnail_id );
					}
				}
				break;

			case 'author':
				$author = get_queried_object();
				if ( $author instanceof \WP_User ) {
					$data['title'] = $author->display_name;

					$author_description           = get_user_meta( $author->ID, 'description', true );
					$data['mainLoopSettingsData'] = [
						'authorId'          => $author->ID,
						'authorDisplayName' => $author->display_name,
						'authorDescription' => $author_description ? $author_description : '',
						'canEdit'           => current_user_can( 'edit_user', $author->ID ),
					];
				}
				break;

			case 'date':
				$data['title']                = get_the_archive_title();
				$data['mainLoopSettingsData'] = [
					'pageInfo' => $data['title'],
				];
				break;

			case 'post_type_archive':
				$data['title']                = post_type_archive_title( '', false );
				$data['mainLoopSettingsData'] = [
					'pageInfo' => $data['title'],
					'postType' => '',
				];

				$queried_object = get_queried_object();
				if ( $queried_object instanceof \WP_Post_Type && ! empty( $queried_object->name ) ) {
					$data['mainLoopSettingsData']['postType'] = sanitize_key( $queried_object->name );
				} else {
					$post_type = get_query_var( 'post_type' );
					if ( is_array( $post_type ) ) {
						$post_type = $post_type[0] ?? '';
					}
					if ( is_string( $post_type ) && '' !== $post_type ) {
						$data['mainLoopSettingsData']['postType'] = sanitize_key( $post_type );
					}
				}
				break;

			case 'search':
				$data['title']                = get_search_query();
				$data['mainLoopSettingsData'] = [
					'pageInfo' => sprintf(
						/* translators: %s: search query. */
						esc_html__( 'Search results for: %s', 'et_builder_5' ),
						get_search_query()
					),
				];
				break;

			case 'home':
				$page_for_posts = (int) get_option( 'page_for_posts' );
				if ( $page_for_posts > 0 ) {
					$data['title'] = get_the_title( $page_for_posts );
				}

				$data['mainLoopSettingsData'] = [
					'pageInfo' => $data['title'] ? $data['title'] : esc_html__( 'Homepage', 'et_builder_5' ),
				];
				break;

			case '404':
				$data['mainLoopSettingsData'] = [
					'pageInfo' => esc_html__( '404 Error Page', 'et_builder_5' ),
				];
				break;
		}

		$data['title']        = $data['title'] ? esc_html( $data['title'] ) : '';
		$data['thumbnailUrl'] = $data['thumbnailUrl'] ? esc_url( $data['thumbnailUrl'] ) : '';

		return $data;
	}

	/**
	 * Get `currentUser` setting data.
	 *
	 * @since ??
	 */
	public static function current_user() {
		static $return = null;

		$user         = wp_get_current_user();
		$capabilities = $user->allcaps;

		/**
		 * Handle multisite subsite capabilities issue:
		 * In WordPress multisite, user capabilities are stored per-site in the wp_X_usermeta table.
		 * When a user is added to a subsite, they need to be explicitly granted capabilities for that site.
		 * If the user hasn't been properly added to the subsite or their capabilities haven't been set,
		 * wp_get_current_user()->allcaps will return an empty array on subsites.
		 * As a fallback, we check the main site's capabilities since users typically have their
		 * full set of capabilities defined there.
		 */
		if ( empty( $capabilities ) && is_multisite() && ! is_main_site() ) {
			// Temporarily switch to the main site to get the user's capabilities.
			switch_to_blog( get_main_site_id() );
			$capabilities = wp_get_current_user()->allcaps;
			// Restore the current site context.
			restore_current_blog();
		}

		if ( null === $return || Conditions::is_test_env() ) {
			$return = [
				'role'         => et_pb_get_current_user_role(),
				'capabilities' => $capabilities,
			];
		}

		return $return;
	}

	/**
	 * Get `customizer` setting data.
	 */
	public static function customizer() {
		static $return = null;

		if ( null === $return ) {
			$return = Customizer::get_settings_values();
		}

		return $return;
	}

	/**
	 * Get `dynamicContent` setting data.
	 *
	 * @since ??
	 */
	public static function dynamic_content() {
		static $cache = [];

		// TODO feat(D5, Translation): Handle locale switching for user profile language preference [https://github.com/elegantthemes/Divi/issues/45526].
		global $post;
		$post_id     = $post->ID ?? '';
		$user_locale = get_user_locale();

		// Check permission for custom fields.
		// If user doesn't have permission, use 'edit' context to hide custom fields.
		// If user has permission, use 'display' context to show custom fields.
		// This ensures custom field options are not included in DiviSettingsData when permission is off.
		$can_read_custom_fields = et_pb_is_allowed( 'read_dynamic_content_custom_fields' );
		$context                = $can_read_custom_fields ? 'display' : 'edit';

		// Create cache key based on post ID, user locale, and permission status.
		// This ensures different cache entries for users with/without permission.
		$cache_key = $post_id . '_' . $user_locale . '_' . ( $can_read_custom_fields ? 'allowed' : 'restricted' );

		if ( ! isset( $cache[ $cache_key ] ) ) {
			// Handle locale switching for user profile language preference in Visual Builder.
			$locale_switched = LocaleUtility::maybe_switch_locale( 'user' );

			// Get dynamic content options with appropriate context based on permission.
			$cache[ $cache_key ] = [
				'options' => DynamicContentOptions::get_options( $post_id, $context ),
			];

			// Restore original locale if it was switched by us.
			if ( $locale_switched ) {
				LocaleUtility::maybe_restore_locale( $locale_switched );
			}
		}

		return $cache[ $cache_key ];
	}

	/**
	 * Get `fonts` setting data.
	 *
	 * @since ??
	 */
	public static function fonts() {
		static $return = null;

		if ( null === $return ) {
			$heading_font        = et_get_option( 'heading_font', 'Open Sans' );
			$body_font           = et_get_option( 'body_font', 'Open Sans' );
			$heading_font_weight = et_get_option( 'heading_font_weight', '500' );
			$body_font_weight    = et_get_option( 'body_font_weight', '500' );

			$customizer_fonts = [
				'heading' => [
					'label'      => esc_html__( 'Headings', 'et_builder_5' ),
					'fontId'     => '--et_global_heading_font',
					'fontName'   => $heading_font ? $heading_font : 'Open Sans',
					'fontWeight' => $heading_font_weight ? $heading_font_weight : '500',
				],
				'body'    => [
					'label'      => esc_html__( 'Body', 'et_builder_5' ),
					'fontId'     => '--et_global_body_font',
					'fontName'   => $body_font ? $body_font : 'Open Sans',
					'fontWeight' => $body_font_weight ? $body_font_weight : '500',
				],
			];

			$google_fonts = array_merge(
				[ 'Default' => [] ],
				et_builder_get_websafe_fonts(),
				et_builder_get_google_fonts()
			);

			ksort( $google_fonts );

			$return = [
				'custom'     => et_builder_get_custom_fonts(),
				'customizer' => $customizer_fonts,
				'formats'    => et_pb_get_supported_font_formats(),
				'google'     => $google_fonts,
				'icons'      => et_pb_get_font_icon_symbols(),
				'iconsDown'  => et_pb_get_font_down_icon_symbols(),
				'removed'    => et_builder_old_fonts_mapping(),
			];
		}

		return $return;
	}

	/**
	 * Get `globalPresets` settings data.
	 *
	 * @since ??
	 */
	public static function global_presets() {
		static $return = null;

		if ( null === $return ) {
			// Convert D4 presets to D5 format if they haven't been converted yet.
			// This ensures conversion happens before preset data is sent to client.
			GlobalPreset::maybe_convert_legacy_presets();

			$return = [
				'data'                 => (object) GlobalPreset::get_data(),
				'legacyData'           => (object) GlobalPreset::get_legacy_data(),
				'isLegacyDataImported' => 'yes' === GlobalPreset::is_legacy_presets_imported(),
			];
		}

		return $return;
	}

	/**
	 * Get `google` settings data.
	 *
	 * @since ??
	 */
	public static function google() {
		static $return = null;

		if ( null === $return ) {
			$google_api_settings = et_pb_is_allowed( 'theme_options' ) ? get_option( 'et_google_api_settings' ) : [];
			$google_api_key      = $google_api_settings['api_key'] ?? '';

			$return = [
				// phpcs:ignore ET.Comments.Todo.TodoFound -- Valid D5 Todo task.
				// TODO feat(D5, Refactor) this should be secret.
				'APIKey'           => $google_api_key,
				'mapsScriptNotice' => ! et_pb_enqueue_google_maps_script(),
			];
		}

		return $return;
	}

	/**
	 * Get `layout` settings data.
	 *
	 * @since ??
	 */
	public static function layout() {
		static $return = null;

		if ( null === $return ) {
			global $post;

			$post_id        = isset( $post->ID ) ? absint( $post->ID ) : 0;
			$post_type      = isset( $post->post_type ) ? $post->post_type : 'post';
			$layout_type    = '';
			$layout_scope   = '';
			$remote_item_id = '';
			$template_type  = '';

			// phpcs:ignore ET.Comments.Todo.TodoFound -- Valid D5 Todo task.
			// TODO feat(D5, Coverage) more will happen here. See: et_fb_get_dynamic_backend_helpers().
			if ( 'et_pb_layout' === $post_type ) {
				$layout_type   = et_fb_get_layout_type( $post_id );
				$layout_scope  = et_fb_get_layout_term_slug( $post_id, 'scope' );
				$template_type = get_post_meta( $post_id, '_et_pb_template_type', true );

				// Only set the remote_item_id if temp post still exists.
				if ( ! empty( $_GET['cloudItem'] ) && get_post_status( $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- This function does not change any state, and is therefore not susceptible to CSRF.
					$remote_item_id = (int) sanitize_text_field( wp_unslash( $_GET['cloudItem'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- This function does not change any state, and is therefore not susceptible to CSRF.
				}
			}

			$return = [
				'type'         => $layout_type,
				'scope'        => $layout_scope,
				'templateType' => $template_type,
				'remoteItemId' => $remote_item_id,
			];
		}

		return $return;
	}

	/**
	 * Get `markups` setting data.
	 *
	 * @since ??
	 */
	public static function markups() {
		static $return = null;

		if ( null === $return ) {
			$return = [
				'commentsModule' => TemplatePlaceholder::comments(),
			];
		}

		return $return;
	}

	/**
	 * Get `navMenus` setting data.
	 *
	 * @since ??
	 */
	public static function nav_menus() {
		static $return = null;

		if ( null === $return ) {
			$return = [
				'options' => et_builder_get_nav_menus_options(),
			];
		}

		return $return;
	}

	/**
	 * Get `nonces` setting data.
	 *
	 * @since ??
	 */
	public static function nonces() {
		static $return = null;

		if ( null === $return ) {
			$return = Nonce::get_data();
		}

		return $return;
	}

	/**
	 * Get `post` setting data.
	 *
	 * @since ??
	 */
	public static function post() {
		static $return = null;

		if ( null === $return ) {
			global $post;

			$post_id      = isset( $post->ID ) ? absint( $post->ID ) : 0;
			$post_content = isset( $post->post_content ) ? $post->post_content : '';
			$post_type    = isset( $post->post_type ) ? $post->post_type : 'post';
			$post_status  = isset( $post->post_status ) ? $post->post_status : false;

			// Apply full PHP conversion for visual builder BEFORE D5-to-D5 migrations run.
			// This ensures D4 content is converted to D5 blocks before D5 migrations try to process it.
			$has_shortcode = Shortcode::has_builder_shortcode( $post_content );
			if ( $post_content && $has_shortcode ) {
				// Initialize shortcode framework (handles module loading automatically).
				Conversion::initialize_shortcode_framework();

				// Prepare for D4 to D5 conversion by ensuring module definitions are available.
				// This is critical for attribute mappings to work properly.
				do_action( 'divi_visual_builder_before_d4_conversion' );

				// Apply full conversion (includes migration + format conversion).
				$post_content = Conversion::maybeConvertContent( $post_content );
			}

			// Deglobalize nested global modules when editing a global module template in Divi Library.
			// Nested global modules are not allowed, so remove any nested divi/global-layout blocks.
			if ( $post_content && 'et_pb_layout' === $post_type && GlobalLayout::is_global_layout_template( $post_id ) ) {
				$portability_instance = new PortabilityPost( 'et_builder' );
				$post_content         = $portability_instance->maybe_deglobalize_nested_global_modules( $post_content );
			}

			/**
			 * Filters the raw post content that is used for the visual builder.
			 *
			 * @since      ??
			 *
			 * @param string $post_content Raw post content that is used for the visual builder.
			 * @param int    $post_id      Post ID.
			 *
			 * @deprecated 5.0.0 Use the {@see 'divi_visual_builder_settings_data_post_content'} filter instead.
			 */
			$post_content = apply_filters(
				'et_fb_load_raw_post_content',
				$post_content,
				$post_id
			);

			/**
			 * Filters the raw post content that is used for the visual builder.
			 *
			 * @since ??
			 *
			 * @param string $post_content Raw post content that is used for the visual builder.
			 * @param int    $post_id      Post ID.
			 */
			$raw_post_content = apply_filters( 'divi_visual_builder_settings_data_post_content', $post_content, $post_id );

			// Match client-side wrapPlaceholderBlock() to ensure PHP/JS serialization parity.
			if ( ! empty( $raw_post_content ) && ! str_contains( $raw_post_content, '<!-- wp:divi/placeholder -->' ) ) {
				$raw_post_content = ModuleUtils::wrap_placeholder_block( $raw_post_content );
			}

			// If page is not singular and uses theme builder, set $post_status to 'publish'
			// to get the 'Save' button instead of 'Draft' and 'Publish'.
			if ( ! is_singular() && et_fb_is_theme_builder_used_on_page() && et_pb_is_allowed( 'theme_builder' ) ) {
				$post_status = 'publish';
			}

			$request_type = $post_type;

			// Set request_type on 404 pages.
			if ( is_404() ) {
				$request_type = '404';
			}

			// Set request_type on Archive pages.
			if ( is_archive() ) {
				$request_type = 'archive';
			}

			// Set request_type on the homepage.
			if ( is_home() ) {
				$request_type = 'home';
			}

			$return = [
				'content'     => $raw_post_content,
				'id'          => $post_id,
				'title'       => get_the_title( $post_id ),
				'type'        => $post_type,
				'requestType' => $request_type,
				'status'      => $post_status,
				'url'         => get_permalink( $post_id ),
				'editUrl'     => get_edit_post_link( $post_id, 'raw' ),
				'iframeSrc'   => ( isset( $_SERVER['HTTP_HOST'] ) && isset( $_SERVER['REQUEST_URI'] ) ) ?
					( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
					. sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			];
		}

		return $return;
	}

	/**
	 * Build a Theme Builder template payload for a layout post.
	 *
	 * @since ??
	 *
	 * @param array  $theme_builder_layouts Theme Builder layouts data.
	 * @param string $layout_post_type_key  Theme Builder layout post type key constant value.
	 * @param string $expected_post_type    Expected layout post type.
	 * @param array  $global_layout_ids     Global layout IDs grouped by layout post type key.
	 *
	 * @return array{id:int,title:string,content:string,isGlobal:bool}
	 */
	private static function _get_theme_builder_template_data(
		array $theme_builder_layouts,
		string $layout_post_type_key,
		string $expected_post_type,
		array $global_layout_ids = []
	): array {
		$layout_data = $theme_builder_layouts[ $layout_post_type_key ] ?? [];
		$layout_id   = intval( $layout_data['id'] ?? 0 );

		if ( 0 === $layout_id ) {
			return [
				'id'       => 0,
				'title'    => '',
				'content'  => '',
				'isGlobal' => false,
			];
		}

		$layout_post = get_post( $layout_id );

		if ( ! $layout_post || $expected_post_type !== $layout_post->post_type ) {
			return [
				'id'       => 0,
				'title'    => '',
				'content'  => '',
				'isGlobal' => false,
			];
		}

		$layout_content = $layout_post->post_content;

		// Apply conversion if needed (similar to post content processing).
		$has_shortcode = Shortcode::has_builder_shortcode( $layout_content );
		if ( $layout_content && $has_shortcode ) {
			Conversion::initialize_shortcode_framework();
			do_action( 'divi_visual_builder_before_d4_conversion' );
			$layout_content = Conversion::maybeConvertContent( $layout_content );
		}

		// Wrap placeholder block if needed.
		if ( ! empty( $layout_content ) && ! str_contains( $layout_content, '<!-- wp:divi/placeholder -->' ) ) {
			$layout_content = ModuleUtils::wrap_placeholder_block( $layout_content );
		}

		// Check if this template is global.
		// A layout is considered global if:
		// 1) It is marked global on the active template payload, OR
		// 2) Its ID matches the shared global layout ID for this area.
		$is_global = ( isset( $layout_data['global'] ) && true === $layout_data['global'] )
			|| isset( $global_layout_ids[ $layout_post_type_key ][ $layout_id ] );

		return [
			'id'       => $layout_id,
			'title'    => get_the_title( $layout_id ),
			'content'  => $layout_content,
			'isGlobal' => $is_global,
		];
	}

	/**
	 * Get `themeBuilderTemplates` setting data.
	 *
	 * Provides Theme Builder template content (header, footer, body) for the current post.
	 * This data is used to populate the Visual Builder with template content.
	 *
	 * @since ??
	 */
	public static function theme_builder_templates() {
		static $return = null;

		if ( null === $return ) {
			// Check if Theme Builder templates should be shown based on user preference.
			$show_theme_builder_templates = et_get_option( 'et_fb_pref_show_theme_builder_templates', true, '', true );
			global $post;
			$post_type              = $post->post_type ?? 'post';
			$is_tb_layout_post_type = et_theme_builder_is_layout_post_type( $post_type );
			// In Theme Builder layout editor, template areas must remain available even if the preference is disabled.
			$should_show_theme_builder_templates = $is_tb_layout_post_type || $show_theme_builder_templates;
			$active_tb_layout                    = Layout::get_layout_based_on_post_type( $post_type );
			$active_template_areas               = $is_tb_layout_post_type
				? [ $active_tb_layout ]
				: [ 'header', 'body', 'footer' ];
			$template_definitions                = [
				'header' => [
					'layoutPostTypeKey' => ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE,
					'expectedPostType'  => 'et_header_layout',
				],
				'body'   => [
					'layoutPostTypeKey' => ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE,
					'expectedPostType'  => 'et_body_layout',
				],
				'footer' => [
					'layoutPostTypeKey' => ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE,
					'expectedPostType'  => 'et_footer_layout',
				],
			];

			$templates = [
				'header'      => [
					'id'       => 0,
					'title'    => '',
					'content'  => '',
					'isGlobal' => false,
				],
				'footer'      => [
					'id'       => 0,
					'title'    => '',
					'content'  => '',
					'isGlobal' => false,
				],
				'body'        => [
					'id'       => 0,
					'title'    => '',
					'content'  => '',
					'isGlobal' => false,
				],
				'postContent' => [
					'id'       => 0,
					'title'    => '',
					'content'  => '',
					'isGlobal' => false,
				],
			];

			// When Theme Builder templates are hidden, short-circuit before any TB template fetching.
			if ( ! $should_show_theme_builder_templates ) {
				// Get post content (for postContent layout).
				global $post;
				$post_id = isset( $post->ID ) ? $post->ID : 0;

				if ( is_singular() && $post_id > 0 ) {
					$post_content_data        = self::post();
					$templates['postContent'] = [
						'id'       => $post_id,
						'title'    => $post_content_data['title'] ?? '',
						'content'  => $post_content_data['content'] ?? '',
						'isGlobal' => false,
					];
				}

				$return = $templates;
				return $return;
			}

			// Reuse already computed theme builder areas from `theme_builder()` when available.
			$theme_builder_data    = self::theme_builder();
			$theme_builder_layouts = isset( $theme_builder_data['themeBuilderAreas'] ) && is_array( $theme_builder_data['themeBuilderAreas'] )
				? $theme_builder_data['themeBuilderAreas']
				: et_theme_builder_get_template_layouts();
			$active_layout_ids     = [];

			foreach ( $active_template_areas as $area ) {
				$definition = $template_definitions[ $area ] ?? null;

				if ( null === $definition ) {
					continue;
				}

				$layout_post_type_key = $definition['layoutPostTypeKey'];
				$layout_data          = $theme_builder_layouts[ $layout_post_type_key ] ?? [];
				$layout_id            = intval( $layout_data['id'] ?? 0 );

				if ( $layout_id <= 0 ) {
					continue;
				}

				$has_assigned_layout = $is_tb_layout_post_type
					? true
					: ( ! empty( $layout_data['override'] ) && ! empty( $layout_data['enabled'] ) );

				if ( $has_assigned_layout ) {
					$active_layout_ids[ $layout_post_type_key ] = $layout_id;
				}
			}

			// No assigned TB layouts for this request context: skip expensive global template lookups.
			if ( empty( $active_layout_ids ) ) {
				$post_id = isset( $post->ID ) ? $post->ID : 0;

				if ( ! $is_tb_layout_post_type && is_singular() && $post_id > 0 ) {
					$post_content_data        = self::post();
					$templates['postContent'] = [
						'id'       => $post_id,
						'title'    => $post_content_data['title'] ?? '',
						'content'  => $post_content_data['content'] ?? '',
						'isGlobal' => false,
					];
				}

				$return = $templates;
				return $return;
			}

			$global_layout_ids = [
				ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE => [],
				ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE   => [],
				ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE => [],
			];
			$area_name_by_key  = [
				ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE => 'header',
				ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE   => 'body',
				ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE => 'footer',
			];
			$lookup_pending    = [];

			// First, trust explicit global flags from assigned layout data.
			foreach ( $active_layout_ids as $layout_post_type_key => $layout_id ) {
				$layout_data = $theme_builder_layouts[ $layout_post_type_key ] ?? [];

				if ( ! empty( $layout_data['global'] ) ) {
					$global_layout_ids[ $layout_post_type_key ][ $layout_id ] = true;
					continue;
				}

				$area_name = $area_name_by_key[ $layout_post_type_key ] ?? '';

				if ( '' !== $area_name ) {
					$lookup_pending[ $layout_post_type_key ] = [
						'layoutId' => $layout_id,
						'areaName' => $area_name,
					];
				}
			}

			// For unresolved active layouts, scan template assignments just until all are resolved.
			// This avoids building a full global ID map for every template on every app load.
			if ( ! empty( $lookup_pending ) ) {
				$theme_builder_templates = et_theme_builder_get_theme_builder_templates( true, false );

				foreach ( $theme_builder_templates as $template ) {
					$is_default_template = ! empty( $template['default'] );
					$layouts             = $template['layouts'] ?? [];

					foreach ( $lookup_pending as $layout_post_type_key => $lookup ) {
						$area_name   = $lookup['areaName'];
						$expected_id = $lookup['layoutId'];
						$area_layout = $layouts[ $area_name ] ?? [];
						$area_id     = intval( $area_layout['id'] ?? 0 );

						if ( $area_id <= 0 || $expected_id !== $area_id ) {
							continue;
						}

						$is_area_global = ! empty( $area_layout['global'] );
						if ( $is_default_template || $is_area_global ) {
							$global_layout_ids[ $layout_post_type_key ][ $area_id ] = true;
							unset( $lookup_pending[ $layout_post_type_key ] );
						}
					}

					if ( empty( $lookup_pending ) ) {
						break;
					}
				}
			}

			if ( ! empty( $theme_builder_layouts ) && $should_show_theme_builder_templates ) {
				foreach ( $active_template_areas as $area ) {
					$definition = $template_definitions[ $area ] ?? null;
					if ( null === $definition ) {
						continue;
					}

					$layout_post_type_key = $definition['layoutPostTypeKey'];
					if ( ! isset( $active_layout_ids[ $layout_post_type_key ] ) ) {
						continue;
					}

					$templates[ $area ] = self::_get_theme_builder_template_data(
						$theme_builder_layouts,
						$layout_post_type_key,
						$definition['expectedPostType'],
						$global_layout_ids
					);
				}
			}

			// Get post content (for postContent layout).
			$post_id         = isset( $post->ID ) ? $post->ID : 0;
			$body_layout     = $theme_builder_layouts[ ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE ] ?? [];
			$has_body_layout = ! empty( $body_layout['override'] ) && ! empty( $body_layout['enabled'] );
			$load_post_data  = ! $is_tb_layout_post_type && ( is_singular() || $has_body_layout );

			if ( $load_post_data && $post_id > 0 ) {
				$post_content_data        = self::post();
				$templates['postContent'] = [
					'id'       => $post_id,
					'title'    => $post_content_data['title'] ?? '',
					'content'  => $post_content_data['content'] ?? '',
					'isGlobal' => false,
				];
			}

			$return = $templates;
		}

		return $return;
	}

	/**
	 * Get `preferences` setting data.
	 *
	 * @since ??
	 *
	 * @return array Array of app preferences.
	 */
	public static function preferences(): array {
		static $return = null;

		if ( null === $return ) {
			$clean_preferences = [];
			$app_preferences   = AppPreferences::mapping();

			foreach ( $app_preferences as $preference_key => $preference ) {
				$option_name  = 'et_fb_pref_' . $preference['key'];
				$option_value = et_get_option( $option_name, $preference['default'], '', true );

				// If options available, verify returned value against valid options. Return default if fails.
				if ( isset( $preference['options'] ) ) {
					$options       = $preference['options'];
					$valid_options = isset( $options[0] ) ? $options : array_keys( $options );
					// phpcs:ignore WordPress.PHP.StrictInArray -- $valid_options array has strings and numbers values.
					if ( ! in_array( (string) $option_value, $valid_options ) ) {
						$option_value = $preference['default'];
					}
				}

				/**
				 * Fix(D5, Theme): Manually set 'd5-enhanced' as app theme for the entire Visual Builder.
				 * We have completely migrated to the new d5-enhanced design. This is to be removed
				 * once all d4 variants of components are removed.
				 */
				if ( 'et_fb_pref_app_theme' === $option_name ) {
					$option_value = 'd5-enhanced';
				}

				$option_value                         = SavingUtility::parse_value_type( $option_value, $preference['type'] );
				$clean_preferences[ $preference_key ] = $option_value;
			}

			/**
			 * Filter to modify Divi Builder app preferences data.
			 *
			 * @since ??
			 *
			 * @param array $clean_preferences Array of preferences.
			 */
			$return = apply_filters( 'divi_visual_builder_preferences_data', $clean_preferences );
		}

		return $return;
	}

	/**
	 * Get `services` setting data.
	 *
	 * @since ??
	 */
	public static function services() {
		static $return = null;

		if ( null === $return ) {
			$return = [
				'email'          => EmailAccountService::definition(),
				'spamProtection' => SpamProtectionService::definition(),
			];
		}

		return $return;
	}

	/**
	 * Get `settings` setting data.
	 *
	 * @since ??
	 */
	public static function settings() {
		static $return = null;

		if ( null === $return ) {
			// GMT Offset.
			$gmt_offset = get_option( 'gmt_offset' );

			// Get Sidebar values.
			$sidebar_values = Theme::get_sidebar_areas();

			$return = [
				'cookiePath'   => SITECOOKIEPATH,
				'page'         => [
					'items'  => PageSettings::get_registered_items(),
					'values' => Settings::get_settings_values(),
				],
				'role'         => et_pb_get_role_settings(),
				'site'         => [
					'gmtOffsetString' => SiteSettings::get_gmt_offset_string( $gmt_offset ),
					'url'             => get_site_url(),
				],
				'theme'        => [
					'widgetAreas' => $sidebar_values['widget_areas'],
					'defaultArea' => $sidebar_values['area'],
				],
				'previewNonce' => wp_create_nonce( 'et_pb_preview_nonce' ),
			];
		}

		return $return;
	}

	/**
	 * Shortcode module definitions for structure modules.
	 *
	 * @since ??
	 */
	public static function structure_module_definitions() {
		// Load the main structure elements if not already loaded.
		if ( ! class_exists( 'ET_Builder_Section' ) ) {
			require_once ET_BUILDER_DIR . '/main-structure-elements.php';
		}

		// Get all modules definitions.
		// We do it this way because `get_structure_modules()` doesn't include `ET_Builder_Column`.
		$all_modules = \ET_Builder_Element::get_parent_and_child_modules( 'et_pb_layout' );

		// Filter out non-structure modules.
		$modules = array_filter(
			$all_modules,
			function ( $module ) {
				return ! empty( $module->is_structure_element );
			}
		);

		// Build the definitions.
		$definitions = [];
		foreach ( $modules as $module ) {
			$definitions[ $module->slug ] = [
				'name'   => $module->name,
				'plural' => $module->plural,
				'slug'   => $module->slug,
				'title'  => $module->name,
			];
		}

		return $definitions;
	}

	/**
	 * Shortcode module definitions setting data.
	 *
	 * @since ??
	 */
	public static function shortcode_module_definitions() {
		static $return = null;

		if ( null === $return ) {
			// fire the actions to initialize any Divi Extensions.
			do_action( 'divi_extensions_init' );
			do_action( 'et_builder_ready' );
			do_action( 'divi_visual_builder_before_get_shortcode_module_definitions' );

			$return = \ET_Builder_Element::get_shortcode_module_definitions();
		}

		return $return;
	}

	/**
	 * Get `shortcodeTags` setting data.
	 *
	 * @since ??
	 */
	public static function shortcode_tags() {
		static $return = null;

		if ( null === $return ) {
			// Initialize shortcode framework so 3rd-party module classes are
			// available and registered to WP shortcode tags.
			Conversion::initialize_shortcode_framework();

			$return = ShortcodeUtility::get_shortcode_tags();
		}

		return $return;
	}

	/**
	 * Get `styles` setting data.
	 *
	 * @since ??
	 */
	public static function styles() {
		static $return = null;

		if ( null === $return ) {
			$return = [
				'acceptableCSSStringValues' => et_builder_get_acceptable_css_string_values( 'all' ),
				'customizer'                => [
					'body'   => [
						'fontHeight' => floatval( et_get_option( 'body_font_height', '1.7' ) ),
						'fontSize'   => absint( et_get_option( 'body_font_size', '14' ) ),
					],
					'layout' => [
						'contentWidth' => absint( et_get_option( 'content_width', '1080' ) ),
					],
				],
			];
		}

		return $return;
	}

	/**
	 * Get `taxonomy` setting data.
	 *
	 * @since ??
	 */
	public static function taxonomy() {
		static $return = null;

		if ( null === $return ) {
			// Divi Taxonomies.
			$layout_taxonomies = Taxonomy::get_terms();

			/**
			 * Filters the taxonomies that are used for the layout category and layout tag.
			 *
			 * @since      ??
			 *
			 * @param array $layout_taxonomies Taxonomies that are used for the layout category and layout tag.
			 *
			 * @deprecated 5.0.0 Use the {@see 'divi_visual_builder_settings_data_layout_taxonomies'} filter instead.
			 */
			$layout_taxonomies = apply_filters(
				'et_fb_taxonomies',
				$layout_taxonomies
			);

			/**
			 * Filters the taxonomies that are used for the layout category and layout tag.
			 *
			 * @since ??
			 *
			 * @param array $layout_taxonomies Taxonomies that are used for the layout category and layout tag.
			 */
			$get_taxonomies = apply_filters( 'divi_visual_builder_settings_data_layout_taxonomies', $layout_taxonomies );

			// Legacy structure for backwards compatibility.
			$return = [
				'layoutCategory'    => array_key_exists( 'layout_category', $get_taxonomies ) ? $get_taxonomies['layout_category'] : [],
				'layoutTag'         => array_key_exists( 'layout_tag', $get_taxonomies ) ? $get_taxonomies['layout_tag'] : [],
				'projectCategories' => array_key_exists( 'project_category', $layout_taxonomies ) ? $layout_taxonomies['project_category'] : (object) [],
				'postCategories'    => array_key_exists( 'category', $layout_taxonomies ) ? $layout_taxonomies['category'] : (object) [],
				'productCategories' => array_key_exists( 'product_cat', $layout_taxonomies ) ? $layout_taxonomies['product_cat'] : (object) [],
			];

			// Add all other taxonomies dynamically to support external modules.
			foreach ( $get_taxonomies as $taxonomy_name => $terms ) {
				if ( ! in_array( $taxonomy_name, [ 'layout_category', 'layout_tag', 'project_category', 'category', 'product_cat' ], true ) ) {
					// Convert taxonomy name to camelCase format for consistency.
					$camel_case_name            = lcfirst( str_replace( ' ', '', ucwords( str_replace( [ '-', '_' ], ' ', $taxonomy_name ) ) ) );
					$return[ $camel_case_name ] = $terms;
				}
			}

			// New structure: organize taxonomies by post type for dynamic support.
			$return['byPostType'] = [];

			// Taxonomies to exclude (same list as CategoriesRESTController).
			$excluded_taxonomies = [
				'post_tag',
				'project_tag',
				'product_tag',
				'post_format',
				'nav_menu',
				'link_category',
				'post_status',
				'product_type',
				'product_brand',
				'product_visibility',
				'product_shipping_class',
			];

			// Get all public post types.
			$post_types = get_post_types( [ 'public' => true ], 'objects' );

			foreach ( $post_types as $post_type_slug => $post_type_object ) {
				// Get taxonomies associated with this post type.
				$post_type_taxonomies = get_object_taxonomies( $post_type_slug, 'objects' );

				$categories = [];

				foreach ( $post_type_taxonomies as $taxonomy_slug => $taxonomy_object ) {
					// Skip excluded taxonomies.
					if ( in_array( $taxonomy_slug, $excluded_taxonomies, true ) ) {
						continue;
					}

					// Include both hierarchical (categories) and non-hierarchical (tags) taxonomies.
					// This enables support for ACF custom taxonomies and other non-hierarchical taxonomies.
					if ( isset( $get_taxonomies[ $taxonomy_slug ] ) ) {
						$categories[] = [
							'slug'  => $taxonomy_slug,
							'name'  => $taxonomy_object->label,
							'terms' => $get_taxonomies[ $taxonomy_slug ],
						];
					}
				}

				// Only add post types that have category taxonomies.
				if ( ! empty( $categories ) ) {
					$return['byPostType'][ $post_type_slug ] = [
						'categories' => $categories,
					];
				}
			}
		}

		return $return;
	}

	/**
	 * Get `postTypes` setting data.
	 *
	 * Returns post type slugs and their display labels for use in the Visual Builder.
	 *
	 * @since ??
	 */
	public static function post_types() {
		static $return = null;

		if ( null === $return ) {
			$return = et_get_registered_post_type_options( false, false );
		}

		return $return;
	}

	/**
	 * Get `themeBuilder` setting data.
	 *
	 * @since ??
	 */
	public static function theme_builder() {
		static $return = null;

		if ( null === $return ) {
			global $post;
			$post_type = $post->post_type ?? 'post';

			// phpcs:ignore ET.Comments.Todo.TodoFound -- Legacy TODO: May not be tracked in GitHub issues yet. Preserve for future tracking/removal.
			// TODO feat(D5, Theme Builder) Maybe remove these parameters. Check whether these are used or not.
			// At the moment these are straight copy from Divi 4 counterpart.
			// Validate the Theme Builder body layout and its post content module, if any.
			$theme_builder_layouts    = et_theme_builder_get_template_layouts();
			$has_tb_layouts           = ! empty( $theme_builder_layouts );
			$is_tb_layout             = et_theme_builder_is_layout_post_type( $post_type );
			$tb_body_layout           = ArrayUtility::get_value( $theme_builder_layouts, ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE, [] );
			$tb_body_has_post_content = $tb_body_layout && et_theme_builder_layout_has_post_content( $tb_body_layout );
			$has_valid_body_layout    = ! $has_tb_layouts || $is_tb_layout || $tb_body_has_post_content;

			$return = [
				'layout'             => Layout::get_layout_based_on_post_type( $post_type ),

				// phpcs:ignore ET.Comments.Todo.TodoFound -- Legacy TODO: May not be tracked in GitHub issues yet. Preserve for future tracking/removal.
				// TODO feat(D5, Theme Builder) Maybe remove these parameters. Check whether these are used or not.
				// At the moment these are straight copy from Divi 4 counterpart.
				'isLayout'           => et_theme_builder_is_layout_post_type( $post_type ),
				'layoutPostTypes'    => et_theme_builder_get_layout_post_types(),
				'bodyLayoutPostType' => ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE,
				'postContentModules' => et_theme_builder_get_post_content_modules(),
				'hasValidBodyLayout' => $has_valid_body_layout,
				'themeBuilderAreas'  => $theme_builder_layouts,
			];
		}

		return $return;
	}

	/**
	 * Get `tinymce` setting data.
	 *
	 * @since ??
	 */
	public static function tinymce() {
		static $return = null;

		if ( null === $return ) {
			$tinymce_default_plugins = [
				'autolink',
				'autoresize',
				'charmap',
				'emoticons',
				'fullscreen',
				'image',
				'link',
				'lists',
				'paste',
				'preview',
				'print',
				'table',
				'textcolor',
				'wpview',
			];

			/**
			 * Filters the TinyMCE plugins that are used for the visual builder.
			 *
			 * @since      ??
			 *
			 * @param array $tinymce_defaults_plugins TinyMCE plugins that are used for the visual builder.
			 *
			 * @deprecated 5.0.0 Use the {@see 'divi_visual_builder_tinymce_plugins'} filter instead.
			 */
			$tinymce_default_plugins = apply_filters(
				'et_fb_tinymce_plugins',
				$tinymce_default_plugins
			);

			/**
			 * Filters the TinyMCE plugins that are used for the visual builder.
			 *
			 * @since ??
			 *
			 * @param array $tinymce_default_plugins TinyMCE plugins that are used for the visual builder.
			 */
			$tinymce_plugins = apply_filters( 'divi_visual_builder_tinymce_plugins', $tinymce_default_plugins );

			$return = [
				'skinUrl'  => ET_BUILDER_5_URI . '/visual-builder-assets/tinymce-skin',
				'cssFiles' => esc_url( includes_url( 'js/tinymce' ) . '/skins/wordpress/wp-content.css' ),
				'plugins'  => $tinymce_plugins,
			];
		}

		return $return;
	}

	/**
	 * Get `urls` setting data.
	 *
	 * @since ??
	 */
	public static function urls() {
		static $return = null;

		if ( null === $return ) {
			$return = [
				'admin'                  => admin_url(),
				'adminOptionsGeneralUrl' => esc_url( admin_url( 'options-general.php' ) ),
				'ajax'                   => is_ssl() ? admin_url( 'admin-ajax.php' ) : admin_url( 'admin-ajax.php', 'http' ),
				'builderImages'          => esc_url( ET_BUILDER_URI . '/images' ),
				'builder5Images'         => esc_url( ET_BUILDER_5_URI . '/images' ),
				'themeOptions'           => esc_url( et_pb_get_options_page_link() ),
				'homeUrl'                => esc_url( home_url( '/' ) ),
				'restRootUrl'            => esc_url( get_rest_url() ),
			];
		}

		return $return;
	}

	/**
	 * Retrieve WooCommerce settings and configuration data.
	 *
	 * This method provides WooCommerce-specific settings including default values,
	 * module options, and UI messages for the visual builder. It includes proper
	 * caching and early returns for performance optimization.
	 *
	 * @since ??
	 *
	 * @return array Associative array containing WooCommerce settings and default values.
	 *               Returns empty array if not in REST API/VB context.
	 */
	public static function woocommerce(): array {
		// Skip processing if not in Visual Builder, REST API, or Theme Builder context.
		if ( ! (
			Conditions::is_rest_api_request() ||
			Conditions::is_vb_app_window() ||
			Conditions::is_tb_enabled()
		) ) {
			return [];
		}

		static $return = null;

		// Cache the result for performance, but refresh in test environments.
		if ( null === $return || Conditions::is_test_env() ) {
			$return = [
				'defaults'                          => [
					'columnsPosts' => WooCommerceUtils::get_default_columns_posts(),
					'homeUrl'      => esc_url_raw( get_home_url() ),
					'pageType'     => WooCommerceUtils::get_default_page_type(),
					'product'      => WooCommerceUtils::get_default_product(),
					'productTabs'  => WooCommerceUtils::get_default_product_tabs(),
				],
				'inactiveModuleNotice'              => esc_html__(
					'WooCommerce must be active for this module to appear',
					'et_builder_5'
				),
				'isWooCommerceActive'               => Conditions::is_woocommerce_enabled(),
				'productTabsOptions'                => Conditions::is_tb_enabled() && Conditions::is_woocommerce_enabled()
					? WooCommerceUtils::set_default_product_tabs_options()
					: WooCommerceUtils::get_product_tabs_options(),
				'woocommerceModuleMarkup'           => WooCommerceUtils::get_current_page_woocommerce_components_markup(),
				'isCheckoutContext'                 => WooCommerceUtils::is_checkout_context(),
				'hasBillingOnlyShippingDestination' => WooCommerceUtils::has_billing_only_shipping_destination(),
			];
		}

		return $return;
	}

	/**
	 * Get workspaces data.
	 *
	 * @since ??
	 */
	public static function workspaces() {
		static $return = null;

		if ( null === $return ) {
			$return = Workspace::get_items();
		}

		return $return;
	}

	/**
	 * Get the builder version.
	 *
	 * @since ??
	 */
	public static function get_the_builder_version() {
		if ( ! defined( 'ET_BUILDER_VERSION' ) ) {
			return '0';
		}
		return ET_BUILDER_VERSION;
	}

	/**
	 * Get legacy attribute names from migration classes
	 *
	 * @since ??
	 *
	 * @return array Array of legacy attribute names
	 */
	public static function legacy_attribute_names() {
		return LegacyAttributeNames::get_legacy_attribute_names();
	}

	/**
	 * Get dependency change detection data for attrs maps cache invalidation.
	 *
	 * @since ??
	 *
	 * @return array Dependency change detection information.
	 */
	public static function dependency_change_detection() {
		static $return = null;

		if ( null === $return ) {
			$return = DependencyChangeDetector::get_change_data();
		}

		return $return;
	}

	/**
	 * Get `offCanvas` setting data.
	 *
	 * @since ??
	 *
	 * @return array Off-canvas data including canvases, activeCanvasId, and mainCanvasName.
	 */
	public static function off_canvas() {
		// Get post ID from SettingsDataController (set before callbacks are invoked).
		$post_id                = self::get_current_post_id();
		$post                   = get_post( $post_id );
		$post_type              = $post instanceof \WP_Post ? $post->post_type : '';
		$is_tb_layout_post_type = ! empty( $post_type ) && et_theme_builder_is_layout_post_type( $post_type );
		$active_tb_layout       = $is_tb_layout_post_type
			? Layout::get_layout_based_on_post_type( $post_type )
			: '';

		$main_loop_type          = self::get_current_main_loop_type();
		$main_loop_settings_data = self::get_current_main_loop_settings_data();

		// Keep the post_id guard for singular pages (and when mainLoopType is undefined / defaults to singular).
		// Non-singular pages can have no stable post ID (currentPage.id is false), but can still own canvases
		// via a main-loop context key.
		if ( ! $post_id && ( ! is_string( $main_loop_type ) || '' === $main_loop_type || 'singular' === $main_loop_type ) ) {
			return [
				'canvases'       => [],
				'activeCanvasId' => '',
				'mainCanvasName' => '',
			];
		}

		// Start with a consistent return shape.
		$off_canvas_data = [
			'canvases'       => [],
			'activeCanvasId' => '',
			'mainCanvasName' => '',
		];

		// Load post-backed canvases when a post ID exists.
		if ( $post_id ) {
			$off_canvas_data = OffCanvasHooks::get_off_canvas_data_for_post( $post_id );
		}

		// Include context-backed canvases for non-singular main loop types.
		// This ensures archive pages list canvases stored under `_divi_canvas_parent_context`.
		$context_key = CanvasUtils::get_main_loop_parent_context_key( $main_loop_type, $main_loop_settings_data );

		if ( is_string( $context_key ) && '' !== $context_key ) {
			$context_off_canvas_data = OffCanvasHooks::get_off_canvas_data_for_context( $context_key );

			$context_canvases = $context_off_canvas_data['canvases'] ?? [];
			if ( is_array( $context_canvases ) && ! empty( $context_canvases ) ) {
				foreach ( $context_canvases as $canvas_id => $context_canvas ) {
					$off_canvas_data['canvases'][ $canvas_id ] = $context_canvas;
				}
			}
		}

		// Resolve assigned template layout IDs directly from Theme Builder layout assignments.
		// Do not rely on `theme_builder_templates()` here because that payload can be filtered
		// by UI preferences (e.g. show/hide template rendering in builder), while off-canvas
		// ownership mapping must always include assigned template canvases.
		$theme_builder_layouts = [];
		$current_tb_layout_ids = self::get_current_theme_builder_layout_ids();

		// Prefer layout IDs resolved from the initial app-load context.
		// This preserves archive/term template ownership in REST callbacks.
		if ( ! empty( array_filter( $current_tb_layout_ids ) ) ) {
			$theme_builder_layouts = [
				ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE => [ 'id' => absint( $current_tb_layout_ids['header'] ?? 0 ) ],
				ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE   => [ 'id' => absint( $current_tb_layout_ids['body'] ?? 0 ) ],
				ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE => [ 'id' => absint( $current_tb_layout_ids['footer'] ?? 0 ) ],
			];
		}

		// If app-load IDs are unavailable, resolve from current query context.
		if ( empty( $theme_builder_layouts ) ) {
			$theme_builder_layouts = et_theme_builder_get_template_layouts();
		}

		// Last fallback: resolve by post context.
		if ( empty( $theme_builder_layouts ) ) {
			$tb_request = \ET_Theme_Builder_Request::from_post( $post_id );
			if ( $tb_request ) {
				$theme_builder_layouts = et_theme_builder_get_template_layouts( $tb_request );
			}
		}
		$get_assigned_layout_id = static function ( array $layouts, string $layout_key ): int {
			$layout_data = $layouts[ $layout_key ] ?? [];
			// In some Theme Builder contexts, `override` can be false/omitted even when
			// a layout ID is active (e.g. default/global assignment paths). Rely on the
			// resolved layout ID directly so template-owned canvases are still included.
			return absint( $layout_data['id'] ?? 0 );
		};
		$template_post_ids      = [
			'header' => $get_assigned_layout_id( $theme_builder_layouts, ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE ),
			'body'   => $get_assigned_layout_id( $theme_builder_layouts, ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE ),
			'footer' => $get_assigned_layout_id( $theme_builder_layouts, ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE ),
		];

		// In Theme Builder layout editor, only canvases from the active layout area
		// should be included in the payload (e.g. header editor shows header canvases only).
		if ( $is_tb_layout_post_type && in_array( $active_tb_layout, [ 'header', 'body', 'footer' ], true ) ) {
			foreach ( [ 'header', 'body', 'footer' ] as $layout ) {
				if ( $layout !== $active_tb_layout ) {
					$template_post_ids[ $layout ] = 0;
				}
			}
		}

		foreach ( $template_post_ids as $layout => $template_post_id ) {
			if ( 0 === $template_post_id || $template_post_id === $post_id ) {
				continue;
			}

			$template_off_canvas_data = OffCanvasHooks::get_off_canvas_data_for_post( $template_post_id );
			$template_canvases        = $template_off_canvas_data['canvases'] ?? [];

			if ( ! is_array( $template_canvases ) || empty( $template_canvases ) ) {
				continue;
			}

			foreach ( $template_canvases as $canvas_id => $template_canvas ) {
				$is_global = ! empty( $template_canvas['isGlobal'] );

				if ( ! $is_global ) {
					$template_canvas['parentPostId']       = $template_post_id;
					$template_canvas['themeBuilderLayout'] = $layout;
				}

				$off_canvas_data['canvases'][ $canvas_id ] = $template_canvas;
			}
		}
		return $off_canvas_data;
	}
}
