<?php
/**
 * Plugin Name: StudioFAQ Accordion Builder
 * Plugin URI: https://wpmanagestudio.com/studiofaq-accordion-builder/
 * Description: Effortlessly generate, customize, and insert clean FAQ accordions using ChatGPT, Google Gemini, Kimi AI, or Groq, with optional semantic FAQ markup.
 * Version: 1.0.0
 * Author: WP Manage Studio
 * Author URI: https://wpmanagestudio.com/
 * Support Email: hello@wpmanagestudio.com
 * Text Domain: studiofaq-accordion-builder
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package StudioFAQ_Accordion_Builder
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ----------------------------------------------------------------------
// Constants.
// ----------------------------------------------------------------------
define( 'STUDIOFAQ_ACCORDION_BUILDER_VERSION', '1.0.0' );
define( 'STUDIOFAQ_ACCORDION_BUILDER_PATH', plugin_dir_path( __FILE__ ) );
define( 'STUDIOFAQ_ACCORDION_BUILDER_URL', plugin_dir_url( __FILE__ ) );
define( 'STUDIOFAQ_ACCORDION_BUILDER_BASENAME', plugin_basename( __FILE__ ) );
define( 'STUDIOFAQ_ACCORDION_BUILDER_FILE', __FILE__ );

/**
 * Cache-busting version string for a single enqueued asset file.
 *
 * STUDIOFAQ_ACCORDION_BUILDER_VERSION (the plugin's displayed "Version:")
 * intentionally doesn't change on every small CSS/JS tweak, but browsers
 * cache enqueued assets by their `?ver=` query string — so without this,
 * updating admin.js/admin.css on disk would not be picked up by a
 * browser that already cached the previous request for the same URL.
 * Using the file's own modification time as the `ver` argument instead
 * means every asset automatically gets a fresh URL whenever its file
 * actually changes, with no need to bump the plugin version for that.
 *
 * @param string $relative_path Asset path relative to the plugin root, e.g. 'assets/js/admin.js'.
 * @return string
 */
function studiofaq_accordion_builder_asset_version( $relative_path ) {
	$file = STUDIOFAQ_ACCORDION_BUILDER_PATH . ltrim( $relative_path, '/' );
	return file_exists( $file ) ? (string) filemtime( $file ) : STUDIOFAQ_ACCORDION_BUILDER_VERSION;
}

/**
 * Activation: schedules the daily cron job that sweeps up stale
 * rate-limit rows (see StudioFAQ_Accordion_Builder_Handler::cleanup_stale_rate_limit_rows()).
 * Requires includes/class-ai-handler.php only for its CLEANUP_CRON_HOOK
 * constant name — loaded directly here since plugins_loaded (where
 * init_classes() normally requires it) hasn't fired yet during activation.
 */
function studiofaq_accordion_builder_activate() {
	require_once STUDIOFAQ_ACCORDION_BUILDER_PATH . 'includes/class-ai-handler.php';

	if ( ! wp_next_scheduled( StudioFAQ_Accordion_Builder_Handler::CLEANUP_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', StudioFAQ_Accordion_Builder_Handler::CLEANUP_CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'studiofaq_accordion_builder_activate' );

/**
 * Deactivation: only unschedules cron — deliberately does NOT delete any
 * settings, FAQ data, or rate-limit rows. Deactivation is reversible by
 * design; destructive cleanup belongs in uninstall.php, which only runs
 * when the user explicitly deletes the plugin (and even then, only if
 * "Delete all plugin data" is checked).
 */
function studiofaq_accordion_builder_deactivate() {
	require_once STUDIOFAQ_ACCORDION_BUILDER_PATH . 'includes/class-ai-handler.php';

	$timestamp = wp_next_scheduled( StudioFAQ_Accordion_Builder_Handler::CLEANUP_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, StudioFAQ_Accordion_Builder_Handler::CLEANUP_CRON_HOOK );
	}
}
register_deactivation_hook( __FILE__, 'studiofaq_accordion_builder_deactivate' );

/**
 * Main plugin bootstrap class.
 */
final class StudioFAQ_Accordion_Builder {

	/**
	 * Singleton instance.
	 *
	 * @var StudioFAQ_Accordion_Builder|null
	 */
	private static $instance = null;

	/**
	 * Admin settings handler.
	 *
	 * @var StudioFAQ_Accordion_Builder_Admin_Settings
	 */
	public $admin_settings;

	/**
	 * Meta box handler.
	 *
	 * @var StudioFAQ_Accordion_Builder_Meta_Box
	 */
	public $meta_box;

	/**
	 * AI handler.
	 *
	 * @var StudioFAQ_Accordion_Builder_Handler
	 */
	public $ai_handler;

	/**
	 * Front-end renderer.
	 *
	 * @var StudioFAQ_Accordion_Builder_Renderer
	 */
	public $renderer;

	/**
	 * Gutenberg block handler.
	 *
	 * @var StudioFAQ_Accordion_Builder_Block
	 */
	public $block;

	/**
	 * Get singleton instance.
	 *
	 * @return StudioFAQ_Accordion_Builder
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_classes' ) );
		add_action( 'init', array( $this, 'register_frontend_style' ) );
		add_action( 'init', array( $this, 'register_frontend_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'wp_footer', array( $this, 'print_late_enqueued_frontend_style' ), 1 );
	}

	/**
	 * Require dependency files and initialize core classes.
	 *
	 * Note: This plugin's text domain matches its WordPress.org slug
	 * (studiofaq-accordion-builder), so translations are loaded
	 * automatically by WordPress.org's language packs as of WP 4.6.
	 * A manual load_plugin_textdomain() call is unnecessary and deprecated
	 * in this scenario, so it has intentionally been removed.
	 */
	public function init_classes() {
		require_once STUDIOFAQ_ACCORDION_BUILDER_PATH . 'includes/class-admin-settings.php';
		require_once STUDIOFAQ_ACCORDION_BUILDER_PATH . 'includes/class-faq-meta-box.php';
		require_once STUDIOFAQ_ACCORDION_BUILDER_PATH . 'includes/class-ai-handler.php';
		require_once STUDIOFAQ_ACCORDION_BUILDER_PATH . 'includes/class-faq-renderer.php';
		require_once STUDIOFAQ_ACCORDION_BUILDER_PATH . 'includes/class-faq-block.php';

		$this->admin_settings = new StudioFAQ_Accordion_Builder_Admin_Settings();
		$this->meta_box       = new StudioFAQ_Accordion_Builder_Meta_Box();
		$this->ai_handler     = new StudioFAQ_Accordion_Builder_Handler();
		$this->renderer       = new StudioFAQ_Accordion_Builder_Renderer();
		$this->block          = new StudioFAQ_Accordion_Builder_Block();
	}

	/**
	 * Enqueue admin scripts and styles on relevant screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();

		$is_post_edit_screen = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& $screen
			&& in_array( $screen->post_type, array( 'post', 'page' ), true );

		// Top-level admin menu pages get a "toplevel_page_{slug}" hook
		// suffix (not "settings_page_{slug}", which only applies to pages
		// registered under Settings via add_options_page()). StudioFAQ
		// moved to a top-level menu item, so this must match that.
		$is_settings_screen = ( 'toplevel_page_' . StudioFAQ_Accordion_Builder_Admin_Settings::PAGE_SLUG ) === $hook;

		if ( ! $is_post_edit_screen && ! $is_settings_screen ) {
			return;
		}

		wp_enqueue_style(
			'studiofaq-accordion-builder-admin',
			STUDIOFAQ_ACCORDION_BUILDER_URL . 'assets/css/admin.css',
			array(),
			studiofaq_accordion_builder_asset_version( 'assets/css/admin.css' )
		);

		// The WP Color Picker (and our admin.js, which initializes it) is needed
		// on both the post edit screen (per-FAQ color overrides in the meta box)
		// and the settings screen (global default colors).
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_script(
			'studiofaq-accordion-builder-admin',
			STUDIOFAQ_ACCORDION_BUILDER_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ),
			studiofaq_accordion_builder_asset_version( 'assets/js/admin.js' ),
			true
		);

		// Base localized data — needed on every admin screen this script runs
		// on. Post-edit-only
		// data (ajaxUrl, nonce, postId, generation strings) is merged in
		// below only when we're actually on the post edit screen.
		$localized_data = array(
			'i18n' => array(),
		);

		if ( $is_post_edit_screen ) {
			global $post;
			$post_id = isset( $post->ID ) ? $post->ID : 0;

			// The live preview inside the meta box reuses the real front-end
			// stylesheet so it looks exactly like the eventual output.
			wp_enqueue_style( 'studiofaq-accordion-builder-frontend' );

			$localized_data['ajaxUrl'] = admin_url( 'admin-ajax.php' );
			$localized_data['nonce']   = wp_create_nonce( 'studiofaq_accordion_builder_nonce' );
			$localized_data['postId'] = $post_id;
			$localized_data['i18n']   = array_merge(
				$localized_data['i18n'],
				array(
					'generating'       => __( 'Generating FAQs…', 'studiofaq-accordion-builder' ),
					'generateBtn'      => __( '✨ Generate FAQs with AI', 'studiofaq-accordion-builder' ),
					'error'            => __( 'Something went wrong while generating FAQs. Please try again.', 'studiofaq-accordion-builder' ),
					'noContent'        => __( 'Please add some content to the post before generating FAQs.', 'studiofaq-accordion-builder' ),
					'confirmDelete'    => __( 'Remove this FAQ item?', 'studiofaq-accordion-builder' ),
					'question'         => __( 'Question', 'studiofaq-accordion-builder' ),
					'answer'           => __( 'Answer', 'studiofaq-accordion-builder' ),
					'previewEmpty'     => __( 'Add a question and answer above to see a live preview here.', 'studiofaq-accordion-builder' ),
					/* translators: %d: number of FAQs generated. */
					'faqsGenerated'    => __( '%d FAQs generated successfully.', 'studiofaq-accordion-builder' ),
					'copied'           => __( 'Copied!', 'studiofaq-accordion-builder' ),
				)
			);

			// So the client-side live preview can apply the exact same
			// "per-post override falls back to global default" logic that
			// StudioFAQ_Accordion_Builder_Renderer::get_effective_display_settings()
			// uses on the real front end — without this, the preview would fall
			// back to the plugin's hardcoded CSS defaults instead of this
			// site's actual configured defaults whenever a field is blank.
			$options                           = StudioFAQ_Accordion_Builder_Admin_Settings::get_options();
			$localized_data['globalDefaults'] = array(
				'sectionTitle' => $options['faq_section_title'],
				'headingTag'   => $options['faq_heading_tag'],
				'colors'       => array(
					'faq_header_bg_color'          => $options['faq_header_bg_color'],
					'faq_header_bg_hover_color'    => $options['faq_header_bg_hover_color'],
					'faq_header_text_color'        => $options['faq_header_text_color'],
					'faq_header_text_active_color' => $options['faq_header_text_active_color'],
					'faq_content_text_color'       => $options['faq_content_text_color'],
					'faq_content_bg_color'         => $options['faq_content_bg_color'],
					'faq_border_color'             => $options['faq_border_color'],
					'faq_icon_color'               => $options['faq_icon_color'],
				),
			);
		}

		wp_localize_script( 'studiofaq-accordion-builder-admin', 'StudioFAQAccordionBuilder', $localized_data );
	}

	/**
	 * Register the front-end FAQ stylesheet handle. Hooked to 'init' (fires
	 * on every request, front-end and wp-admin alike) purely so the handle
	 * exists and is ready to be enqueued from either context — actually
	 * enqueuing it happens conditionally elsewhere (see
	 * enqueue_frontend_assets() and StudioFAQ_Accordion_Builder_Block::enqueue_editor_assets()).
	 * Registering alone prints nothing and has no performance cost.
	 */
	public function register_frontend_style() {
		wp_register_style(
			'studiofaq-accordion-builder-frontend',
			STUDIOFAQ_ACCORDION_BUILDER_URL . 'assets/css/frontend.css',
			array(),
			studiofaq_accordion_builder_asset_version( 'assets/css/frontend.css' )
		);
	}

	/**
	 * Register the front-end accordion-toggle script handle. Hooked to
	 * 'init' for the same reason as register_frontend_style() above:
	 * registering alone prints nothing, so it's safe to do unconditionally
	 * here, while the actual wp_enqueue_script() call only happens from
	 * StudioFAQ_Accordion_Builder_Renderer::print_toggle_script() when FAQs
	 * are actually rendered on the page (accordion, cards, or list style all
	 * load it, since cards/list currently share no JS-dependent behavior but
	 * this keeps a single handle simple to reason about).
	 */
	public function register_frontend_script() {
		wp_register_script(
			'studiofaq-accordion-builder-frontend',
			STUDIOFAQ_ACCORDION_BUILDER_URL . 'assets/js/frontend.js',
			array(),
			studiofaq_accordion_builder_asset_version( 'assets/js/frontend.js' ),
			true
		);
	}

	/**
	 * On the front end, enqueue the FAQ stylesheet early enough to land in
	 * <head> only when we can cheaply tell in advance that this page
	 * actually contains a StudioFAQ shortcode or block. This is a
	 * best-effort optimization: the definitive enqueue happens in
	 * StudioFAQ_Accordion_Builder_Renderer::render_faqs() itself (only fires
	 * when FAQs are actually rendered), with a wp_footer fallback below to
	 * print the stylesheet even if it was queued too late to make it into
	 * <head> (e.g. FAQs injected via a widget, template part, or a manual
	 * do_shortcode() call outside post_content).
	 */
	public function enqueue_frontend_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$has_shortcode = has_shortcode( $post->post_content, 'studiofaq-accordion-builder' );
		$has_block     = function_exists( 'has_block' ) && has_block( 'studiofaq-accordion-builder/faq-block', $post );

		if ( $has_shortcode || $has_block ) {
			wp_enqueue_style( 'studiofaq-accordion-builder-frontend' );
		}
	}

	/**
	 * Fallback safety net for the rare case where StudioFAQ output is
	 * injected somewhere enqueue_frontend_assets() couldn't detect in
	 * advance (a widget, a template part, a manual do_shortcode() call,
	 * etc.): if StudioFAQ_Accordion_Builder_Renderer::render_faqs() enqueued
	 * the stylesheet while rendering but it was too late to be picked up by
	 * wp_head's normal wp_print_styles() pass, print it here instead so the
	 * page still gets its styling rather than silently rendering unstyled.
	 */
	public function print_late_enqueued_frontend_style() {
		if ( wp_style_is( 'studiofaq-accordion-builder-frontend', 'enqueued' ) && ! wp_style_is( 'studiofaq-accordion-builder-frontend', 'done' ) ) {
			wp_print_styles( 'studiofaq-accordion-builder-frontend' );
		}
	}
}

StudioFAQ_Accordion_Builder::instance();
