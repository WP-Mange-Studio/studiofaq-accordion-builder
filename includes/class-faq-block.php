<?php
/**
 * Gutenberg Block - "StudioFAQ" dynamic block.
 *
 * Renders the same FAQ data used by the [studiofaq-accordion-builder] shortcode,
 * with a live preview inside the Block Editor via ServerSideRender.
 *
 * @package StudioFAQ_Accordion_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StudioFAQ_Accordion_Builder_Block
 */
class StudioFAQ_Accordion_Builder_Block {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Register the block type and its attributes/render callback.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'studiofaq-accordion-builder/faq-block',
			array(
				'api_version'     => 2,
				'title'           => __( 'StudioFAQ', 'studiofaq-accordion-builder' ),
				'description'     => __( 'Display AI-generated FAQs for this post or page, with optional semantic FAQ markup.', 'studiofaq-accordion-builder' ),
				'category'        => 'widgets',
				'icon'            => 'editor-help',
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
				'attributes'      => array(
					'style'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'postId'      => array(
						'type'    => 'number',
						'default' => 0,
					),
					'sectionTitle' => array(
						'type'    => 'string',
						'default' => '',
					),
					'headingTag'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				// WordPress core automatically supplies the "postId" context to any
				// block that declares it here — both when the block is being edited
				// (from the currently open post) and when it's rendered on the front
				// end inside The Loop (from the global $post). This is more reliable
				// than relying on get_the_ID() alone inside the render callback,
				// especially for ServerSideRender's REST-context preview requests.
				'uses_context'    => array( 'postId' ),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block inner content (unused — dynamic block).
	 * @param WP_Block $block      The block instance, carrying resolved context.
	 * @return string
	 */
	public function render_block( $attributes, $content, $block = null ) {
		// Resolution order: block context (most reliable, works in editor preview
		// and front-end The Loop rendering alike) → explicit attribute saved on
		// the block (set client-side as a fallback/persistence layer) → the
		// global current post as a last resort.
		$post_id = 0;

		if ( $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) {
			$post_id = absint( $block->context['postId'] );
		} elseif ( ! empty( $attributes['postId'] ) ) {
			$post_id = absint( $attributes['postId'] );
		} else {
			$post_id = absint( get_the_ID() );
		}

		$style = isset( $attributes['style'] ) ? $attributes['style'] : '';

		if ( ! $post_id ) {
			return '';
		}

		// Detect whether this render is happening for the in-editor preview
		// (ServerSideRender calls the REST API) versus a real front-end page load.
		$is_editor_preview = defined( 'REST_REQUEST' ) && REST_REQUEST;

		$allowed_tags = StudioFAQ_Accordion_Builder_Admin_Settings::get_allowed_heading_tags();
		$overrides    = array(
			'section_title' => isset( $attributes['sectionTitle'] ) ? sanitize_text_field( $attributes['sectionTitle'] ) : '',
			'heading_tag'   => ( isset( $attributes['headingTag'] ) && in_array( $attributes['headingTag'], $allowed_tags, true ) ) ? $attributes['headingTag'] : '',
		);

		$renderer = StudioFAQ_Accordion_Builder::instance()->renderer;

		return $renderer->render_faqs( $post_id, $style, $is_editor_preview, $overrides );
	}

	/**
	 * Enqueue the block editor script/style. Only loads in the block editor.
	 */
	public function enqueue_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'studiofaq-accordion-builder-block',
			STUDIOFAQ_ACCORDION_BUILDER_URL . 'assets/js/block.js',
			array(
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-i18n',
				'wp-server-side-render',
				'wp-data',
			),
			studiofaq_accordion_builder_asset_version( 'assets/js/block.js' ),
			true
		);

		wp_enqueue_style(
			'studiofaq-accordion-builder-block-editor',
			STUDIOFAQ_ACCORDION_BUILDER_URL . 'assets/css/block-editor.css',
			array( 'wp-edit-blocks' ),
			studiofaq_accordion_builder_asset_version( 'assets/css/block-editor.css' )
		);

		// The ServerSideRender preview renders the same markup as the front
		// end (accordion/cards/list, colors, etc.), so it needs the same
		// front-end stylesheet loaded in the editor to look right — this is
		// the one place we intentionally load it in wp-admin regardless of
		// whether the current post actually contains a StudioFAQ block yet,
		// since that's exactly what the editor is for.
		wp_enqueue_style( 'studiofaq-accordion-builder-frontend' );

		wp_localize_script(
			'studiofaq-accordion-builder-block',
			'StudioFAQAccordionBuilderBlock',
			array(
				'i18n' => array(
					'blockTitle'       => __( 'StudioFAQ', 'studiofaq-accordion-builder' ),
					'blockDescription' => __( 'Display AI-generated FAQs for this post or page, with optional semantic FAQ markup.', 'studiofaq-accordion-builder' ),
					'styleLabel'       => __( 'FAQ Style', 'studiofaq-accordion-builder' ),
					'styleDefault'     => __( 'Site Default', 'studiofaq-accordion-builder' ),
					'styleAccordion'   => __( 'Accordion', 'studiofaq-accordion-builder' ),
					'styleCards'       => __( 'Minimal Cards', 'studiofaq-accordion-builder' ),
					'styleList'        => __( 'Clean List', 'studiofaq-accordion-builder' ),
					'settingsPanel'    => __( 'FAQ Settings', 'studiofaq-accordion-builder' ),
					'metaBoxHint'      => __( 'FAQ content is managed in the "StudioFAQ Builder" meta box below the editor.', 'studiofaq-accordion-builder' ),
					'titlePanel'       => __( 'Section Title', 'studiofaq-accordion-builder' ),
					'sectionTitleLabel' => __( 'FAQ Section Title', 'studiofaq-accordion-builder' ),
					'sectionTitleHelp' => __( 'Leave blank to use the site default (or the per-post override) from the meta box below.', 'studiofaq-accordion-builder' ),
					'headingTagLabel'  => __( 'Title Heading Tag', 'studiofaq-accordion-builder' ),
					'headingTagDefault' => __( 'Use Default', 'studiofaq-accordion-builder' ),
					'colorsHint'       => __( 'Accordion colors can be customized in the "Display Settings" panel of the StudioFAQ Builder meta box, or set as site-wide defaults on the StudioFAQ → Settings page.', 'studiofaq-accordion-builder' ),
				),
			)
		);
	}
}
