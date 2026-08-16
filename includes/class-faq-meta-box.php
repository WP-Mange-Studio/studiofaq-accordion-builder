<?php
/**
 * FAQ Meta Box for Posts & Pages.
 *
 * @package StudioFAQ_Accordion_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StudioFAQ_Accordion_Builder_Meta_Box
 */
class StudioFAQ_Accordion_Builder_Meta_Box {

	const META_KEY          = '_studiofaq_accordion_builder_items';
	const SETTINGS_META_KEY = '_studiofaq_accordion_builder_settings';
	const NONCE_KEY         = 'studiofaq_meta_box_nonce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
	}

	/**
	 * Register the meta box on post and page edit screens.
	 */
	public function register_meta_box() {
		$post_types = array( 'post', 'page' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'studiofaq_meta_box',
				__( 'StudioFAQ Builder', 'studiofaq-accordion-builder' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Get stored FAQ items for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_items( $post_id ) {
		$items = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $items ) ) {
			return array();
		}
		return $items;
	}

	/**
	 * Get the display-setting overrides (section title, heading tag, colors)
	 * saved for a given post. Every value is returned as an empty string
	 * when not overridden, so callers (the renderer) know to fall back to
	 * the global default from StudioFAQ_Accordion_Builder_Admin_Settings.
	 *
	 * @param int $post_id Post ID.
	 * @return array {
	 *     @type string $section_title Overridden section title, or ''.
	 *     @type string $heading_tag   Overridden heading tag (h2-h6), or ''.
	 *     @type array  $colors        Map of color field key => hex color, or '' per key.
	 * }
	 */
	public static function get_settings( $post_id ) {
		$saved = get_post_meta( $post_id, self::SETTINGS_META_KEY, true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$defaults = array(
			'section_title' => '',
			'heading_tag'   => '',
			'colors'        => array(),
		);

		$settings = wp_parse_args( $saved, $defaults );

		$color_keys      = array_keys( StudioFAQ_Accordion_Builder_Admin_Settings::get_color_field_defaults() );
		$saved_colors    = is_array( $settings['colors'] ) ? $settings['colors'] : array();
		$settings['colors'] = array();

		foreach ( $color_keys as $color_key ) {
			$settings['colors'][ $color_key ] = isset( $saved_colors[ $color_key ] ) ? $saved_colors[ $color_key ] : '';
		}

		return $settings;
	}

	/**
	 * Render meta box markup.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'studiofaq_save_meta_box', self::NONCE_KEY );

		$items    = self::get_items( $post->ID );
		$settings = self::get_settings( $post->ID );
		?>
		<div class="studiofaq-metabox" id="studiofaq-metabox">

			<?php $this->render_display_settings( $settings ); ?>

			<div class="studiofaq-tabs" role="tablist">
				<button type="button" class="studiofaq-tab studiofaq-tab-active" id="studiofaq-tab-ai" role="tab" aria-selected="true" aria-controls="studiofaq-panel-ai" data-tab="ai">
					<?php esc_html_e( '✨ AI Generate', 'studiofaq-accordion-builder' ); ?>
				</button>
				<button type="button" class="studiofaq-tab" id="studiofaq-tab-manual" role="tab" aria-selected="false" aria-controls="studiofaq-panel-manual" data-tab="manual">
					<?php esc_html_e( '+ Manual FAQ', 'studiofaq-accordion-builder' ); ?>
				</button>
			</div>

			<div class="studiofaq-tab-panel studiofaq-tab-panel-active" id="studiofaq-panel-ai" data-tab-panel="ai" role="tabpanel" aria-labelledby="studiofaq-tab-ai">
				<div class="studiofaq-toolbar">
					<button type="button" class="button button-primary" id="studiofaq-generate-btn">
						<?php esc_html_e( '✨ Generate FAQs with AI', 'studiofaq-accordion-builder' ); ?>
					</button>
					<span class="studiofaq-spinner spinner" id="studiofaq-spinner"></span>
				</div>
				<p class="studiofaq-ai-disclaimer">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php esc_html_e( 'AI-generated FAQs are drafts. Review factual accuracy, pricing, legal, medical, and product claims before publishing.', 'studiofaq-accordion-builder' ); ?>
				</p>
			</div>

			<div class="studiofaq-tab-panel" id="studiofaq-panel-manual" data-tab-panel="manual" role="tabpanel" aria-labelledby="studiofaq-tab-manual" style="display:none;">
				<div class="studiofaq-toolbar">
					<button type="button" class="button" id="studiofaq-add-manual-btn">
						<?php esc_html_e( '+ Add Manual FAQ', 'studiofaq-accordion-builder' ); ?>
					</button>
				</div>
				<p class="description">
					<?php esc_html_e( 'Add a blank row and write your own question and answer below.', 'studiofaq-accordion-builder' ); ?>
				</p>
			</div>

			<div class="studiofaq-notice" id="studiofaq-notice" style="display:none;"></div>

			<ul class="studiofaq-repeater" id="studiofaq-repeater">
				<?php
				if ( ! empty( $items ) ) {
					foreach ( $items as $index => $item ) {
						$this->render_repeater_row( $index, $item );
					}
				}
				?>
			</ul>

			<template id="studiofaq-row-template">
				<?php $this->render_repeater_row( '__INDEX__', array(
					'question' => '',
					'answer'   => '',
				) ); ?>
			</template>

			<div class="studiofaq-live-preview-wrap">
				<h4 class="studiofaq-live-preview-heading">
					<?php esc_html_e( 'Live Preview', 'studiofaq-accordion-builder' ); ?>
					<span class="studiofaq-live-preview-hint"><?php esc_html_e( '(updates as you type — no need to save first)', 'studiofaq-accordion-builder' ); ?></span>
				</h4>
				<div id="studiofaq-live-preview" class="studiofaq-live-preview-frame"></div>
			</div>

			<p class="studiofaq-shortcode-hint">
				<strong><?php esc_html_e( 'Shortcode:', 'studiofaq-accordion-builder' ); ?></strong>
				<code class="studiofaq-shortcode-copy" data-shortcode="[studiofaq-accordion-builder id=&quot;<?php echo esc_attr( $post->ID ); ?>&quot;]">[studiofaq-accordion-builder id="<?php echo esc_html( $post->ID ); ?>"]</code>
				<button type="button" class="button button-small studiofaq-copy-btn"><?php esc_html_e( 'Copy', 'studiofaq-accordion-builder' ); ?></button>
				<br />
				<span class="description"><?php esc_html_e( 'You can also use [studiofaq-accordion-builder] without an ID inside this post/page content.', 'studiofaq-accordion-builder' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the "Display Settings" panel: section title, heading tag, and
	 * per-post color overrides. Every field is optional — leaving it blank
	 * (or set to "Use Global Default" for the heading tag) falls back to
	 * the site-wide default configured on the StudioFAQ settings page.
	 *
	 * @param array $settings Settings array from self::get_settings().
	 */
	private function render_display_settings( $settings ) {
		$allowed_tags   = StudioFAQ_Accordion_Builder_Admin_Settings::get_allowed_heading_tags();
		$global_options = StudioFAQ_Accordion_Builder_Admin_Settings::get_options();
		$color_fields   = array(
			'faq_header_bg_color'          => __( 'Header Background', 'studiofaq-accordion-builder' ),
			'faq_header_bg_hover_color'    => __( 'Header Background (Hover/Active)', 'studiofaq-accordion-builder' ),
			'faq_header_text_color'        => __( 'Header Text', 'studiofaq-accordion-builder' ),
			'faq_header_text_active_color' => __( 'Header Text (Active)', 'studiofaq-accordion-builder' ),
			'faq_content_text_color'       => __( 'Content Text', 'studiofaq-accordion-builder' ),
			'faq_content_bg_color'         => __( 'Content Background', 'studiofaq-accordion-builder' ),
			'faq_border_color'             => __( 'Border', 'studiofaq-accordion-builder' ),
			'faq_icon_color'               => __( 'Icon', 'studiofaq-accordion-builder' ),
		);
		?>
		<details class="studiofaq-display-settings">
			<summary><?php esc_html_e( 'Display Settings (Title, Heading Tag & Colors)', 'studiofaq-accordion-builder' ); ?></summary>

			<div class="studiofaq-display-settings-inner">
				<p class="description"><?php esc_html_e( 'Leave any field blank to use the site-wide default from StudioFAQ → Settings.', 'studiofaq-accordion-builder' ); ?></p>

				<label class="studiofaq-field-label">
					<?php esc_html_e( 'FAQ Section Title (overrides default)', 'studiofaq-accordion-builder' ); ?>
					<input type="text"
						class="widefat"
						name="studiofaq_settings[section_title]"
						value="<?php echo esc_attr( $settings['section_title'] ); ?>"
						placeholder="<?php esc_attr_e( 'Use site default', 'studiofaq-accordion-builder' ); ?>" />
				</label>

				<label class="studiofaq-field-label">
					<?php esc_html_e( 'Title Heading Tag (overrides default)', 'studiofaq-accordion-builder' ); ?>
					<select name="studiofaq_settings[heading_tag]">
						<option value=""><?php esc_html_e( 'Use Site Default', 'studiofaq-accordion-builder' ); ?></option>
						<?php foreach ( $allowed_tags as $tag ) : ?>
							<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $settings['heading_tag'], $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<p class="studiofaq-field-label" style="margin-bottom:6px;">
					<?php esc_html_e( 'Colors (override default)', 'studiofaq-accordion-builder' ); ?>
				</p>
				<div class="studiofaq-color-table">
					<?php foreach ( $color_fields as $field_key => $label ) : ?>
						<div class="studiofaq-color-row">
							<span class="studiofaq-color-name"><?php echo esc_html( $label ); ?></span>
							<input type="text"
								class="studiofaq-color-field"
								name="studiofaq_settings[colors][<?php echo esc_attr( $field_key ); ?>]"
								value="<?php echo esc_attr( $settings['colors'][ $field_key ] ); ?>"
								data-default-color="<?php echo esc_attr( isset( $global_options[ $field_key ] ) ? $global_options[ $field_key ] : '' ); ?>"
								data-color-label="<?php echo esc_attr( $label ); ?>" />
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</details>
		<?php
	}

	/**
	 * Render a single repeater row (used both for saved items and the JS template).
	 *
	 * @param int|string $index Row index or placeholder token.
	 * @param array      $item  Item data with 'question' and 'answer'.
	 */
	private function render_repeater_row( $index, $item ) {
		$question = isset( $item['question'] ) ? $item['question'] : '';
		$answer   = isset( $item['answer'] ) ? $item['answer'] : '';
		?>
		<li class="studiofaq-row" data-index="<?php echo esc_attr( $index ); ?>">
			<span class="studiofaq-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'studiofaq-accordion-builder' ); ?>">&#9776;</span>
			<div class="studiofaq-row-fields">
				<label>
					<?php esc_html_e( 'Question', 'studiofaq-accordion-builder' ); ?>
					<input type="text"
						class="widefat studiofaq-question-input"
						name="studiofaq_items[<?php echo esc_attr( $index ); ?>][question]"
						value="<?php echo esc_attr( $question ); ?>"
						placeholder="<?php esc_attr_e( 'Enter question…', 'studiofaq-accordion-builder' ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'Answer', 'studiofaq-accordion-builder' ); ?>
					<textarea
						class="widefat studiofaq-answer-input"
						name="studiofaq_items[<?php echo esc_attr( $index ); ?>][answer]"
						rows="3"
						placeholder="<?php esc_attr_e( 'Enter answer…', 'studiofaq-accordion-builder' ); ?>"><?php echo esc_textarea( $answer ); ?></textarea>
				</label>
			</div>
			<button type="button" class="studiofaq-remove-row" title="<?php esc_attr_e( 'Remove', 'studiofaq-accordion-builder' ); ?>">&times;</button>
		</li>
		<?php
	}

	/**
	 * Save meta box data on post save.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_box( $post_id ) {
		// Autosave check.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Nonce check.
		if ( ! isset( $_POST[ self::NONCE_KEY ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_KEY ] ) );
		if ( ! wp_verify_nonce( $nonce, 'studiofaq_save_meta_box' ) ) {
			return;
		}

		// Post type check.
		if ( ! in_array( get_post_type( $post_id ), array( 'post', 'page' ), true ) ) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Nonce and capability are verified above (lines 300-317). The
		// studiofaq_items array itself is unslashed in bulk below, and each
		// question/answer pair is individually sanitized inside the loop
		// (sanitize_text_field()/wp_kses_post()) before it is ever stored,
		// so no raw request data reaches update_post_meta().
		if ( ! isset( $_POST['studiofaq_items'] ) || ! is_array( $_POST['studiofaq_items'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			$raw_items   = wp_unslash( $_POST['studiofaq_items'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified above; array is unslashed here and each field sanitized per-item in the loop below.
			$clean_items = array();

			foreach ( $raw_items as $item ) {
				$question = isset( $item['question'] ) ? sanitize_text_field( $item['question'] ) : '';
				$answer   = isset( $item['answer'] ) ? wp_kses_post( $item['answer'] ) : '';

				// Skip completely empty rows.
				if ( '' === trim( $question ) && '' === trim( wp_strip_all_tags( $answer ) ) ) {
					continue;
				}

				$clean_items[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}

			update_post_meta( $post_id, self::META_KEY, $clean_items );
		}

		$this->save_display_settings( $post_id );
	}

	/**
	 * Sanitize and save the per-post display setting overrides (section
	 * title, heading tag, colors).
	 *
	 * This is a private method with a single call site: save_meta_box()
	 * above, which already verifies the studiofaq_save_meta_box nonce
	 * (see wp_verify_nonce() call), confirms the post type, and checks
	 * `current_user_can( 'edit_post', $post_id )` before invoking this
	 * method. Static analysis cannot follow that cross-function
	 * verification, so the $_POST access below is intentionally flagged
	 * with a documented ignore rather than duplicating nonce/capability
	 * checks that have already run for this same request.
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_display_settings( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce (studiofaq_save_meta_box) and current_user_can( 'edit_post' ) are already verified in save_meta_box(), the sole caller of this private method, immediately before this call.
		if ( ! isset( $_POST['studiofaq_settings'] ) || ! is_array( $_POST['studiofaq_settings'] ) ) {
			delete_post_meta( $post_id, self::SETTINGS_META_KEY );
			return;
		}

		$raw = wp_unslash( $_POST['studiofaq_settings'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce/capability verified in save_meta_box() before this call; array is unslashed here and each field validated/sanitized individually below (sanitize_text_field, allow-listed heading tag, sanitize_hex_color).

		$section_title = isset( $raw['section_title'] ) ? sanitize_text_field( $raw['section_title'] ) : '';

		$allowed_tags = StudioFAQ_Accordion_Builder_Admin_Settings::get_allowed_heading_tags();
		$heading_tag  = ( isset( $raw['heading_tag'] ) && in_array( $raw['heading_tag'], $allowed_tags, true ) )
			? $raw['heading_tag']
			: '';

		$colors     = array();
		$raw_colors = isset( $raw['colors'] ) && is_array( $raw['colors'] ) ? $raw['colors'] : array();

		foreach ( array_keys( StudioFAQ_Accordion_Builder_Admin_Settings::get_color_field_defaults() ) as $color_key ) {
			$submitted = isset( $raw_colors[ $color_key ] ) ? sanitize_text_field( $raw_colors[ $color_key ] ) : '';
			// An override may legitimately be left blank (meaning "use the
			// global default"), so unlike the global settings page we don't
			// force a fallback color here — we just validate non-empty input.
			$colors[ $color_key ] = ( '' !== $submitted ) ? (string) sanitize_hex_color( $submitted ) : '';
		}

		// Skip saving entirely if every field is blank, to avoid cluttering
		// postmeta with an all-empty override row.
		$has_override = ( '' !== $section_title ) || ( '' !== $heading_tag ) || count( array_filter( $colors ) ) > 0;

		if ( ! $has_override ) {
			delete_post_meta( $post_id, self::SETTINGS_META_KEY );
			return;
		}

		update_post_meta(
			$post_id,
			self::SETTINGS_META_KEY,
			array(
				'section_title' => $section_title,
				'heading_tag'   => $heading_tag,
				'colors'        => $colors,
			)
		);
	}
}
