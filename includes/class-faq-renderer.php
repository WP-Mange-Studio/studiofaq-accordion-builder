<?php
/**
 * Front-end FAQ Renderer & Shortcode.
 *
 * @package StudioFAQ_Accordion_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StudioFAQ_Accordion_Builder_Renderer
 */
class StudioFAQ_Accordion_Builder_Renderer {

	/**
	 * Tracks whether the toggle script has already been enqueued on this page.
	 *
	 * @var bool
	 */
	private $script_printed = false;

	/**
	 * FAQ items collected from every render_faqs() call on the current page
	 * load, keyed by a hash of the question text so the same Q&A appearing
	 * in more than one shortcode/block instance is only counted once.
	 * Printed as a single combined FAQPage schema block from wp_footer,
	 * so a page with several FAQ blocks never gets duplicate/competing
	 * <script type="application/ld+json"> tags.
	 *
	 * @var array
	 */
	private $collected_schema_items = array();

	/**
	 * Whether the wp_footer schema-printing hook has already been registered.
	 *
	 * @var bool
	 */
	private $schema_hook_registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'studiofaq-accordion-builder', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render the [studiofaq-accordion-builder] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$options = StudioFAQ_Accordion_Builder_Admin_Settings::get_options();

		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'style'   => $options['default_style'],
				'title'   => '',
				'heading' => '',
			),
			$atts,
			'studiofaq-accordion-builder'
		);

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		$overrides = array(
			'section_title' => sanitize_text_field( $atts['title'] ),
			'heading_tag'   => in_array( $atts['heading'], StudioFAQ_Accordion_Builder_Admin_Settings::get_allowed_heading_tags(), true ) ? $atts['heading'] : '',
		);

		return $this->render_faqs( $post_id, $atts['style'], false, $overrides );
	}

	/**
	 * Shared rendering logic used by both the shortcode and the Gutenberg block.
	 *
	 * @param int    $post_id        Post ID whose FAQs should be rendered.
	 * @param string $style          Style slug: accordion, cards, or list.
	 * @param bool   $editor_preview Whether this is being rendered as an in-editor block preview.
	 * @param array  $overrides      Optional. Highest-priority overrides for 'section_title'
	 *                               and/or 'heading_tag', e.g. from shortcode attributes or
	 *                               block attributes. Empty values fall through to the
	 *                               per-post meta box settings, then the global defaults.
	 * @return string
	 */
	public function render_faqs( $post_id, $style = '', $editor_preview = false, $overrides = array() ) {
		$options = StudioFAQ_Accordion_Builder_Admin_Settings::get_options();

		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return '';
		}

		if ( empty( $style ) ) {
			$style = $options['default_style'];
		}

		$style = in_array( $style, array( 'accordion', 'cards', 'list' ), true ) ? $style : 'accordion';

		$items = StudioFAQ_Accordion_Builder_Meta_Box::get_items( $post_id );

		if ( empty( $items ) ) {
			if ( $editor_preview ) {
				return '<p class="studiofaq-empty-preview">' .
					esc_html__( 'No FAQs added yet. Open the "StudioFAQ Builder" meta box below the content editor to generate or add FAQs — they will appear here automatically.', 'studiofaq-accordion-builder' ) .
					'</p>';
			}
			return '';
		}

		wp_enqueue_style( 'studiofaq-accordion-builder-frontend' );

		$unique_id = 'studiofaq-' . $post_id . '-' . wp_rand( 1000, 9999 );

		$display = $this->get_effective_display_settings( $post_id, $overrides );

		ob_start();

		if ( '' !== $display['section_title'] ) {
			printf(
				'<%1$s class="studiofaq-main-title">%2$s</%1$s>',
				esc_html( $display['heading_tag'] ),
				esc_html( $display['section_title'] )
			);
		}
		?>
		<div class="studiofaq-wrapper studiofaq-style-<?php echo esc_attr( $style ); ?>" id="<?php echo esc_attr( $unique_id ); ?>" style="<?php echo esc_attr( $display['style_attr'] ); ?>">
			<?php
			switch ( $style ) {
				case 'cards':
					$this->render_cards( $items );
					break;
				case 'list':
					$this->render_list( $items );
					break;
				case 'accordion':
				default:
					$this->render_accordion( $items, $unique_id );
					break;
			}
			?>
		</div>
		<?php
		if ( ! $editor_preview ) {
			$this->queue_schema_items( $items );
		}
		$this->print_toggle_script();

		return ob_get_clean();
	}

	/**
	 * Queue this render's FAQ items for the single combined FAQPage schema
	 * block printed (once) in wp_footer — rather than printing a separate
	 * <script type="application/ld+json"> per shortcode/block instance,
	 * which would create multiple competing FAQPage entries on one URL.
	 *
	 * No-ops entirely unless the "Enable FAQ Schema" setting is on, and can
	 * be disabled site-wide (e.g. by an SEO plugin that already outputs its
	 * own FAQ schema) via the `studiofaq_accordion_builder_enable_schema` filter.
	 *
	 * @param array $items FAQ items from this render call.
	 */
	private function queue_schema_items( $items ) {
		$options = StudioFAQ_Accordion_Builder_Admin_Settings::get_options();

		$enabled = ! empty( $options['enable_schema'] );

		/**
		 * Filters whether StudioFAQ should output FAQPage JSON-LD at all.
		 * Useful for letting another plugin (e.g. an SEO plugin that
		 * already emits its own FAQ schema for the same content) force
		 * this off without the site owner needing to find the setting.
		 *
		 * @param bool $enabled Whether schema output is currently enabled.
		 */
		$enabled = (bool) apply_filters( 'studiofaq_accordion_builder_enable_schema', $enabled );

		if ( ! $enabled || empty( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			$question = trim( wp_strip_all_tags( $item['question'] ) );
			$answer   = trim( wp_strip_all_tags( $item['answer'] ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			// Dedupe by question AND answer text so two intentionally
			// different FAQs that happen to share the same question (but
			// have different answers) aren't collapsed into one entry —
			// only exact question+answer duplicates (e.g. the same FAQ
			// appearing in more than one block/shortcode on the page) are
			// counted once.
			$key = md5( strtolower( $question ) . "\n" . strtolower( $answer ) );
			if ( ! isset( $this->collected_schema_items[ $key ] ) ) {
				$this->collected_schema_items[ $key ] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}

		if ( ! $this->schema_hook_registered && ! empty( $this->collected_schema_items ) ) {
			$this->schema_hook_registered = true;
			// Late priority so every shortcode/block on the page has already
			// run and queued its items before we print the combined schema.
			add_action( 'wp_footer', array( $this, 'print_schema' ), 100 );
		}
	}

	/**
	 * Resolve the effective section title, heading tag, and color CSS
	 * variables for a given post, merging (in priority order):
	 * explicit $overrides > per-post meta box settings > global defaults.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $overrides Optional 'section_title' / 'heading_tag' overrides.
	 * @return array {
	 *     @type string $section_title Resolved title text (may be '').
	 *     @type string $heading_tag   Resolved heading tag, always h2-h6.
	 *     @type string $style_attr    Ready-to-echo inline style attribute value
	 *                                 containing the --faq-* CSS custom properties.
	 * }
	 */
	private function get_effective_display_settings( $post_id, $overrides = array() ) {
		$options       = StudioFAQ_Accordion_Builder_Admin_Settings::get_options();
		$post_settings = StudioFAQ_Accordion_Builder_Meta_Box::get_settings( $post_id );
		$allowed_tags  = StudioFAQ_Accordion_Builder_Admin_Settings::get_allowed_heading_tags();

		// Section title: override > post meta > global default.
		$section_title = '';
		if ( ! empty( $overrides['section_title'] ) ) {
			$section_title = $overrides['section_title'];
		} elseif ( '' !== $post_settings['section_title'] ) {
			$section_title = $post_settings['section_title'];
		} else {
			$section_title = $options['faq_section_title'];
		}

		// Heading tag: override > post meta > global default > 'h2'.
		$heading_tag = '';
		if ( ! empty( $overrides['heading_tag'] ) && in_array( $overrides['heading_tag'], $allowed_tags, true ) ) {
			$heading_tag = $overrides['heading_tag'];
		} elseif ( '' !== $post_settings['heading_tag'] && in_array( $post_settings['heading_tag'], $allowed_tags, true ) ) {
			$heading_tag = $post_settings['heading_tag'];
		} elseif ( in_array( $options['faq_heading_tag'], $allowed_tags, true ) ) {
			$heading_tag = $options['faq_heading_tag'];
		} else {
			$heading_tag = 'h2';
		}

		// Colors: per-post override (if a valid hex color was saved) > global default.
		$css_var_map = array(
			'faq_header_bg_color'          => '--faq-header-bg',
			'faq_header_bg_hover_color'    => '--faq-header-bg-hover',
			'faq_header_text_color'        => '--faq-header-text',
			'faq_header_text_active_color' => '--faq-header-text-active',
			'faq_content_text_color'       => '--faq-content-text',
			'faq_content_bg_color'         => '--faq-content-bg',
			'faq_border_color'             => '--faq-border',
			'faq_icon_color'               => '--faq-icon',
		);

		$style_attr = '';
		foreach ( $css_var_map as $option_key => $css_var ) {
			$post_color = isset( $post_settings['colors'][ $option_key ] ) ? $post_settings['colors'][ $option_key ] : '';
			$color      = ( '' !== $post_color && sanitize_hex_color( $post_color ) ) ? $post_color : $options[ $option_key ];

			if ( $color ) {
				$style_attr .= $css_var . ':' . $color . ';';
			}
		}

		return array(
			'section_title' => $section_title,
			'heading_tag'   => $heading_tag,
			'style_attr'    => $style_attr,
		);
	}

	/**
	 * Render accordion style markup.
	 *
	 * @param array  $items     FAQ items.
	 * @param string $unique_id Unique wrapper ID for this instance.
	 */
	private function render_accordion( $items, $unique_id ) {
		foreach ( $items as $index => $item ) {
			$question   = $item['question'];
			$answer     = $item['answer'];
			$item_id    = $unique_id . '-item-' . $index;
			?>
			<div class="studiofaq-accordion-item">
				<button type="button"
					class="studiofaq-accordion-trigger"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $item_id ); ?>-panel">
					<span class="studiofaq-question-text"><?php echo esc_html( $question ); ?></span>
					<span class="studiofaq-accordion-icon" aria-hidden="true">+</span>
				</button>
				<div class="studiofaq-accordion-panel" id="<?php echo esc_attr( $item_id ); ?>-panel">
					<div class="studiofaq-accordion-panel-inner">
						<?php echo wp_kses_post( wpautop( $answer ) ); ?>
					</div>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Render minimal card style markup.
	 *
	 * @param array $items FAQ items.
	 */
	private function render_cards( $items ) {
		foreach ( $items as $item ) {
			?>
			<div class="studiofaq-card">
				<h3 class="studiofaq-card-question"><?php echo esc_html( $item['question'] ); ?></h3>
				<div class="studiofaq-card-answer"><?php echo wp_kses_post( wpautop( $item['answer'] ) ); ?></div>
			</div>
			<?php
		}
	}

	/**
	 * Render clean list style markup.
	 *
	 * @param array $items FAQ items.
	 */
	private function render_list( $items ) {
		?>
		<dl class="studiofaq-list">
			<?php foreach ( $items as $item ) : ?>
				<dt class="studiofaq-list-question"><?php echo esc_html( $item['question'] ); ?></dt>
				<dd class="studiofaq-list-answer"><?php echo wp_kses_post( wpautop( $item['answer'] ) ); ?></dd>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	/**
	 * Output the combined FAQPage JSON-LD schema for every FAQ item queued
	 * by queue_schema_items() across all shortcode/block instances on this
	 * page. Hooked to wp_footer (see queue_schema_items()) so it only ever
	 * runs once per page, regardless of how many FAQ blocks/shortcodes
	 * appear. Public because it's used as an add_action() callback.
	 */
	public function print_schema() {
		if ( empty( $this->collected_schema_items ) ) {
			return;
		}

		$main_entity = array();

		foreach ( $this->collected_schema_items as $item ) {
			$main_entity[] = array(
				'@type'          => 'Question',
				'name'           => $item['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $item['answer'],
				),
			);
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		);

		/**
		 * Filters the final FAQPage schema array before it's printed.
		 * Return an empty array (or false-y value) to suppress output
		 * entirely — e.g. from a theme/plugin that wants to avoid a
		 * conflict with its own structured data on the same page.
		 *
		 * @param array $schema The FAQPage schema about to be printed.
		 * @param array $items  Raw collected {question, answer} pairs.
		 */
		$schema = apply_filters( 'studiofaq_accordion_builder_faq_schema', $schema, $this->collected_schema_items );

		if ( empty( $schema ) ) {
			return;
		}

		// JSON_HEX_TAG (and friends) hex-escape <, >, &, ', " inside the encoded
		// JSON so a question/answer containing a literal "</script>" (or "<script>")
		// can't terminate this tag early and inject markup/script into the page.
		// JSON_UNESCAPED_SLASHES/JSON_UNESCAPED_UNICODE are deliberately NOT used
		// here: unescaped "/" still allows a literal "</script>" sequence through,
		// which is exactly the breakout vector this is guarding against.
		$json_ld = wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		if ( false === $json_ld ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json_ld . '</script>' . "\n";
	}

	/**
	 * Enqueue the accordion toggle JS once per page load, via the properly
	 * registered 'studiofaq-accordion-builder-frontend' script handle (see
	 * StudioFAQ_Accordion_Builder::register_frontend_script()) rather than echoing a raw
	 * inline <script> tag into the page markup.
	 */
	private function print_toggle_script() {
		if ( $this->script_printed ) {
			return;
		}
		$this->script_printed = true;

		wp_enqueue_script( 'studiofaq-accordion-builder-frontend' );
	}
}
