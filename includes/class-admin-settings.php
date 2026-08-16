<?php
/**
 * Admin Settings Page.
 *
 * @package StudioFAQ_Accordion_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StudioFAQ_Accordion_Builder_Admin_Settings
 *
 * Registers the "StudioFAQ" top-level admin menu and settings page.
 */
class StudioFAQ_Accordion_Builder_Admin_Settings {

	const OPTION_GROUP = 'studiofaq_accordion_builder_options_group';
	const OPTION_NAME  = 'studiofaq_accordion_builder_options';
	const PAGE_SLUG    = 'studiofaq-accordion-builder';

	/**
	 * Prefix used to mark option values that have been encrypted by this plugin,
	 * so legacy plaintext values (e.g. from a pre-encryption install) are still
	 * readable and get re-encrypted the next time settings are saved.
	 *
	 * Legacy format (pre-1.2.0): AES-256-CBC, no integrity check — kept only so
	 * a key encrypted by an older version of this plugin still decrypts after
	 * updating, without forcing every site to re-enter their API keys.
	 */
	const ENC_PREFIX = 'enc:';

	/**
	 * Prefix for the current encryption format: AES-256-GCM, an authenticated
	 * cipher — unlike plain CBC, decryption fails loudly (returns '') if the
	 * stored bytes were altered, rather than silently returning corrupted
	 * "plaintext" garbage as an API key. Every new save uses this format;
	 * ENC_PREFIX values are still read for backward compatibility (see
	 * decrypt_value()) but are no longer written.
	 */
	const ENC_PREFIX_V2 = 'enc2:';

	/**
	 * Allowed HTML heading tags for the FAQ section title.
	 *
	 * @return array
	 */
	public static function get_allowed_heading_tags() {
		return array( 'h2', 'h3', 'h4', 'h5', 'h6' );
	}

	/**
	 * Color option keys and their built-in fallback values. Shared by the
	 * defaults array, the settings fields, and the sanitize callback so the
	 * list only needs to be maintained in one place.
	 *
	 * @return array<string,string> Map of option key => default hex color.
	 */
	public static function get_color_field_defaults() {
		return array(
			'faq_header_bg_color'          => '#fafafa',
			'faq_header_bg_hover_color'    => '#f2f0fb',
			'faq_header_text_color'        => '#1d2327',
			'faq_header_text_active_color' => '#5b21b6',
			'faq_content_text_color'       => '#3c434a',
			'faq_content_bg_color'         => '#ffffff',
			'faq_border_color'             => '#e2e2e7',
			'faq_icon_color'               => '#6d28d9',
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add a top-level admin menu item (instead of nesting under Settings),
	 * so StudioFAQ shows up directly in the sidebar like other plugins
	 * (Easy Accordion, Simple Sitemap, etc.) rather than being buried
	 * inside Settings → StudioFAQ.
	 */
	public function add_settings_page() {
		add_menu_page(
			__( 'StudioFAQ Settings', 'studiofaq-accordion-builder' ),
			__( 'StudioFAQ', 'studiofaq-accordion-builder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-editor-help',
			58
		);

		// Re-label the auto-created first submenu item from the duplicated
		// top-level title ("StudioFAQ") to the shorter, clearer "Settings" —
		// the same pattern most WordPress.org plugins use for their menus.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'StudioFAQ Settings', 'studiofaq-accordion-builder' ),
			__( 'Settings', 'studiofaq-accordion-builder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Get default option values.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		$defaults = array(
			'openai_api_key'           => '',
			'gemini_api_key'           => '',
			'kimi_api_key'             => '',
			'groq_api_key'             => '',
			'active_model'             => 'openai',
			'default_style'            => 'accordion',
			'delete_data_on_uninstall' => false,
			'faq_section_title'        => '',
			'faq_heading_tag'          => 'h2',
			// Off by default: FAQPage JSON-LD is optional semantic markup, not
			// something every site wants (some sites already have their own
			// FAQ schema via an SEO plugin, and Google no longer shows the
			// visual FAQ rich result for most sites anyway — see the schema
			// section field description for the honest framing).
			'enable_schema'            => false,
		);

		return array_merge( $defaults, self::get_color_field_defaults() );
	}

	/**
	 * Get plugin options merged with defaults. API keys remain encrypted at rest;
	 * use get_options_decrypted() when the plaintext key is actually needed
	 * (i.e. right before calling an external API).
	 *
	 * @return array
	 */
	public static function get_options() {
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_defaults() );
	}

	/**
	 * Get plugin options with API keys decrypted to plaintext.
	 * Only call this immediately before making an outbound API request —
	 * never log or echo the returned keys.
	 *
	 * @return array
	 */
	public static function get_options_decrypted() {
		$options                    = self::get_options();
		$options['openai_api_key'] = self::decrypt_value( $options['openai_api_key'] );
		$options['gemini_api_key'] = self::decrypt_value( $options['gemini_api_key'] );
		$options['kimi_api_key']   = self::decrypt_value( $options['kimi_api_key'] );
		$options['groq_api_key']   = self::decrypt_value( $options['groq_api_key'] );
		return $options;
	}

	/**
	 * Derive a 32-byte encryption key from WordPress's own secret salts, so the
	 * encrypted value is only decryptable on this install (wp-config.php's
	 * AUTH_KEY must be present, which it always is on a real WordPress site).
	 *
	 * @return string Raw 32-byte binary key.
	 */
	private static function get_encryption_key() {
		$secret = ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY )
			? AUTH_KEY
			: wp_salt( 'auth' );

		return hash( 'sha256', $secret, true );
	}

	/**
	 * Encrypt a plaintext value (e.g. an API key) for storage in the options table.
	 *
	 * Returns false — rather than the plaintext value — if AES-256-GCM
	 * encryption isn't available or the encryption operation itself fails.
	 * Storing an API key unencrypted is not an acceptable fallback, so
	 * callers MUST check for `false` and refuse to save in that case (see
	 * sanitize_options(), which surfaces a clear admin error instead of
	 * silently writing plaintext).
	 *
	 * @param string $value Plaintext value.
	 * @return string|false Encrypted value, or false if it could not be encrypted.
	 */
	public static function encrypt_value( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( ! self::gcm_available() ) {
			return false;
		}

		$key        = self::get_encryption_key();
		$iv_length  = openssl_cipher_iv_length( 'aes-256-gcm' );
		$iv         = openssl_random_pseudo_bytes( $iv_length );
		$tag        = '';
		$ciphertext = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return false;
		}

		// iv . tag . ciphertext, all fixed/known-length except the
		// trailing ciphertext, so decrypt_value() can split it back apart
		// unambiguously without storing lengths separately.
		return self::ENC_PREFIX_V2 . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value previously stored via encrypt_value(). Values without
	 * either encryption prefix are treated as legacy plaintext (from before
	 * encryption existed at all) and returned as-is.
	 *
	 * @param string $value Stored value.
	 * @return string Plaintext value, or '' if decryption/verification fails.
	 */
	public static function decrypt_value( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( 0 === strpos( $value, self::ENC_PREFIX_V2 ) ) {
			return self::decrypt_value_v2( $value );
		}

		if ( 0 === strpos( $value, self::ENC_PREFIX ) ) {
			return self::decrypt_value_legacy_cbc( $value );
		}

		// Legacy plaintext value from before encryption was added at all.
		return $value;
	}

	/**
	 * Decrypts the current AES-256-GCM format. A failed/failed-verification
	 * result (tampered ciphertext, wrong key, truncated data) returns ''
	 * rather than any partial output — never hand back unauthenticated bytes
	 * as if they were a trustworthy API key.
	 *
	 * @param string $value Stored value, including the ENC_PREFIX_V2 prefix.
	 * @return string
	 */
	private static function decrypt_value_v2( $value ) {
		if ( ! self::gcm_available() ) {
			return '';
		}

		$raw = base64_decode( substr( $value, strlen( self::ENC_PREFIX_V2 ) ), true );
		if ( false === $raw ) {
			return '';
		}

		$iv_length  = openssl_cipher_iv_length( 'aes-256-gcm' );
		$tag_length = 16;

		if ( strlen( $raw ) <= $iv_length + $tag_length ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_length );
		$tag        = substr( $raw, $iv_length, $tag_length );
		$ciphertext = substr( $raw, $iv_length + $tag_length );
		$key        = self::get_encryption_key();

		$decrypted = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * Decrypts the legacy (pre-1.2.0) AES-256-CBC format — kept read-only so
	 * a key saved by an older version of this plugin still works after
	 * updating. CBC alone has no integrity check, which is exactly why this
	 * format was replaced; nothing new is ever written in this format.
	 *
	 * @param string $value Stored value, including the ENC_PREFIX prefix.
	 * @return string
	 */
	private static function decrypt_value_legacy_cbc( $value ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $value, strlen( self::ENC_PREFIX ) ), true );

		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}

		$iv        = substr( $raw, 0, 16 );
		$encrypted = substr( $raw, 16 );
		$key       = self::get_encryption_key();
		$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * @return bool Whether this host's OpenSSL build supports AES-256-GCM
	 *              (authenticated encryption) — true on effectively every
	 *              PHP 7.1+ install, since GCM has shipped in OpenSSL since
	 *              1.0.1 (2012); this only returns false on unusual custom
	 *              OpenSSL builds with AEAD ciphers stripped out.
	 */
	private static function gcm_available() {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_random_pseudo_bytes' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}

	/**
	 * Whether a stored value is legacy plaintext — i.e. non-empty and
	 * carrying neither the current (ENC_PREFIX_V2) nor legacy (ENC_PREFIX)
	 * encryption prefix. Such a value can only be a raw API key saved by a
	 * pre-encryption version of this plugin, since every value this plugin
	 * itself writes is always encrypted (or empty).
	 *
	 * @param string $value Stored option value.
	 * @return bool
	 */
	private static function is_legacy_plaintext( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return false;
		}

		if ( 0 === strpos( $value, self::ENC_PREFIX_V2 ) ) {
			return false;
		}

		if ( 0 === strpos( $value, self::ENC_PREFIX ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Register settings, sections, and fields via the Settings API.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'studiofaq_accordion_builder_main_section',
			__( 'AI Provider & Style Settings', 'studiofaq-accordion-builder' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'studiofaq-accordion-builder' ),
			array( $this, 'render_openai_key_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_main_section'
		);

		add_settings_field(
			'gemini_api_key',
			__( 'Google Gemini API Key', 'studiofaq-accordion-builder' ),
			array( $this, 'render_gemini_key_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_main_section'
		);

		add_settings_field(
			'kimi_api_key',
			__( 'Kimi AI (Moonshot) API Key', 'studiofaq-accordion-builder' ),
			array( $this, 'render_kimi_key_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_main_section'
		);

		add_settings_field(
			'groq_api_key',
			__( 'Groq API Key', 'studiofaq-accordion-builder' ),
			array( $this, 'render_groq_key_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_main_section'
		);

		add_settings_field(
			'active_model',
			__( 'Active AI Model', 'studiofaq-accordion-builder' ),
			array( $this, 'render_active_model_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_main_section'
		);

		add_settings_field(
			'default_style',
			__( 'Default Style Template', 'studiofaq-accordion-builder' ),
			array( $this, 'render_default_style_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_main_section'
		);

		add_settings_field(
			'enable_schema',
			__( 'FAQ Schema (JSON-LD)', 'studiofaq-accordion-builder' ),
			array( $this, 'render_enable_schema_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_main_section'
		);

		add_settings_field(
			'delete_data_on_uninstall',
			__( 'On Uninstall', 'studiofaq-accordion-builder' ),
			array( $this, 'render_delete_data_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_main_section'
		);

		// ------------------------------------------------------------------
		// Style & Branding section — global defaults for section title,
		// heading tag, and accordion colors. Individual posts/pages can
		// override any of these from the "StudioFAQ Builder" meta box.
		// ------------------------------------------------------------------
		add_settings_section(
			'studiofaq_accordion_builder_style_section',
			__( 'Style & Branding Defaults', 'studiofaq-accordion-builder' ),
			array( $this, 'render_style_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'faq_section_title',
			__( 'Default FAQ Section Title', 'studiofaq-accordion-builder' ),
			array( $this, 'render_section_title_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_style_section'
		);

		add_settings_field(
			'faq_heading_tag',
			__( 'Title Heading Tag', 'studiofaq-accordion-builder' ),
			array( $this, 'render_heading_tag_field' ),
			self::PAGE_SLUG,
			'studiofaq_accordion_builder_style_section'
		);

		$color_labels = array(
			'faq_header_bg_color'          => __( 'Header Background Color', 'studiofaq-accordion-builder' ),
			'faq_header_bg_hover_color'    => __( 'Header Background Color (Hover / Active)', 'studiofaq-accordion-builder' ),
			'faq_header_text_color'        => __( 'Header Text Color', 'studiofaq-accordion-builder' ),
			'faq_header_text_active_color' => __( 'Header Text Color (Active)', 'studiofaq-accordion-builder' ),
			'faq_content_text_color'       => __( 'Content Text Color', 'studiofaq-accordion-builder' ),
			'faq_content_bg_color'         => __( 'Content Background Color', 'studiofaq-accordion-builder' ),
			'faq_border_color'             => __( 'Border Color', 'studiofaq-accordion-builder' ),
			'faq_icon_color'               => __( 'Icon Color', 'studiofaq-accordion-builder' ),
		);

		foreach ( $color_labels as $field_key => $label ) {
			add_settings_field(
				$field_key,
				$label,
				array( $this, 'render_color_field' ),
				self::PAGE_SLUG,
				'studiofaq_accordion_builder_style_section',
				array(
					'field_key' => $field_key,
					'label'     => $label,
				)
			);
		}
	}

	/**
	 * Sanitize submitted options.
	 *
	 * @param array $input Raw input array.
	 * @return array Sanitized array.
	 */
	public function sanitize_options( $input ) {
		$output   = self::get_defaults();
		$existing = self::get_options();

		// API key fields are rendered blank on purpose (see render_*_key_field);
		// only overwrite the stored (encrypted) key if the user actually typed
		// a new one. An empty submission means "leave the existing key alone."
		// If this server can't do AES-256-GCM encryption, encrypt_value()
		// returns false and we must NOT fall back to saving the key in
		// plaintext — we keep whatever was already stored and surface a
		// clear error instead (see $encryption_blocked below).
		$encryption_blocked = false;

		$output['openai_api_key'] = $this->sanitize_api_key_field( $input, 'openai_api_key', $existing, $encryption_blocked );
		$output['gemini_api_key'] = $this->sanitize_api_key_field( $input, 'gemini_api_key', $existing, $encryption_blocked );
		$output['kimi_api_key']   = $this->sanitize_api_key_field( $input, 'kimi_api_key', $existing, $encryption_blocked );
		$output['groq_api_key']   = $this->sanitize_api_key_field( $input, 'groq_api_key', $existing, $encryption_blocked );

		if ( $encryption_blocked ) {
			add_settings_error(
				self::OPTION_NAME,
				'studiofaq_accordion_builder_encryption_unavailable',
				__( 'One or more API keys were NOT saved: this server\'s PHP/OpenSSL build does not support AES-256-GCM encryption, and StudioFAQ will not store API keys in plaintext. Ask your host to enable OpenSSL with AES-256-GCM support, then try again. Any previously saved key was left unchanged.', 'studiofaq-accordion-builder' ),
				'error'
			);
		}

		if ( isset( $input['active_model'] ) && in_array( $input['active_model'], array( 'openai', 'gemini', 'kimi', 'groq' ), true ) ) {
			$output['active_model'] = $input['active_model'];
		} else {
			$output['active_model'] = $existing['active_model'];
		}

		$valid_styles = array( 'accordion', 'cards', 'list' );
		if ( isset( $input['default_style'] ) && in_array( $input['default_style'], $valid_styles, true ) ) {
			$output['default_style'] = $input['default_style'];
		} else {
			$output['default_style'] = $existing['default_style'];
		}

		$output['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		// FAQPage JSON-LD schema output — off by default, opt-in only.
		$output['enable_schema'] = ! empty( $input['enable_schema'] );

		// Default FAQ section title (optional — blank means no title is rendered
		// unless a specific post overrides it).
		$output['faq_section_title'] = isset( $input['faq_section_title'] )
			? sanitize_text_field( wp_unslash( $input['faq_section_title'] ) )
			: '';

		// Heading tag must be one of the allowed h2-h6 values.
		$allowed_tags = self::get_allowed_heading_tags();
		$output['faq_heading_tag'] = ( isset( $input['faq_heading_tag'] ) && in_array( $input['faq_heading_tag'], $allowed_tags, true ) )
			? $input['faq_heading_tag']
			: 'h2';

		// Color fields — each must be a valid hex color, otherwise fall back
		// to this plugin's built-in default so the UI never breaks.
		foreach ( self::get_color_field_defaults() as $field_key => $fallback_color ) {
			$submitted = isset( $input[ $field_key ] ) ? sanitize_text_field( wp_unslash( $input[ $field_key ] ) ) : '';
			$valid     = sanitize_hex_color( $submitted );
			$output[ $field_key ] = $valid ? $valid : $fallback_color;
		}

		add_settings_error(
			self::OPTION_NAME,
			'studiofaq_accordion_builder_settings_updated',
			__( 'StudioFAQ settings saved successfully.', 'studiofaq-accordion-builder' ),
			'updated'
		);

		return $output;
	}

	/**
	 * Sanitize + encrypt a single API key field for sanitize_options().
	 * Centralizes the "leave unchanged if blank, refuse to store plaintext
	 * if encryption is unavailable" logic shared by all four provider keys.
	 *
	 * Also handles migrating a legacy plaintext key (saved by a pre-
	 * encryption version of this plugin) to encrypted storage: even when
	 * this field is submitted blank — which normally means "leave the
	 * existing key alone" — a still-plaintext existing value is encrypted
	 * in place if this server supports AES-256-GCM, so a plaintext key
	 * doesn't linger forever just because its field wasn't retyped.
	 *
	 * @param array  $input               Raw $_POST-derived settings input.
	 * @param string $field_key           e.g. 'openai_api_key'.
	 * @param array  $existing            Currently stored (still-encrypted) options.
	 * @param bool   $encryption_blocked  By-reference flag, set true if a new
	 *                                    key — or a legacy plaintext key
	 *                                    being migrated — could not be
	 *                                    safely encrypted.
	 * @return string Encrypted key to store (existing value if unchanged/blocked).
	 */
	private function sanitize_api_key_field( $input, $field_key, $existing, &$encryption_blocked ) {
		$existing_value = isset( $existing[ $field_key ] ) ? (string) $existing[ $field_key ] : '';

		if ( ! isset( $input[ $field_key ] ) || '' === trim( (string) $input[ $field_key ] ) ) {
			// Nothing new was typed into this field. If what's already
			// stored is still legacy plaintext, opportunistically migrate
			// it to encrypted storage now rather than waiting for the
			// user to happen to retype that specific key.
			if ( self::is_legacy_plaintext( $existing_value ) ) {
				$migrated = self::encrypt_value( $existing_value );

				if ( false !== $migrated ) {
					return $migrated;
				}

				// This server can't encrypt right now — never delete or
				// corrupt the existing key over this; leave it exactly as
				// stored and let the caller surface the secure-storage
				// warning so the site owner knows it's still plaintext.
				$encryption_blocked = true;
			}

			return $existing_value;
		}

		$plaintext = sanitize_text_field( wp_unslash( $input[ $field_key ] ) );
		$encrypted = self::encrypt_value( $plaintext );

		if ( false === $encrypted ) {
			$encryption_blocked = true;
			return $existing_value;
		}

		return $encrypted;
	}

	/**
	 * Section intro text.
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Enter your API keys below to enable AI-powered FAQ generation. Keys are encrypted before being stored in the WordPress options table and are never shared.', 'studiofaq-accordion-builder' ) . '</p>';
	}

	/**
	 * Render OpenAI API key field. The field is always rendered blank —
	 * the previously saved key is never echoed back into the page HTML —
	 * with a placeholder indicating whether a key is already on file.
	 */
	public function render_openai_key_field() {
		$options = self::get_options();
		$has_key = ! empty( $options['openai_api_key'] );
		?>
		<input type="password"
			id="studiofaq_accordion_builder_openai_api_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]"
			value=""
			class="regular-text"
			autocomplete="off"
			placeholder="<?php echo $has_key ? esc_attr__( '•••••••••••••••••••• (key saved — leave blank to keep it)', 'studiofaq-accordion-builder' ) : esc_attr__( 'sk-...', 'studiofaq-accordion-builder' ); ?>" />
		<?php if ( $has_key ) : ?>
			<span class="studiofaq-key-status">✓ <?php esc_html_e( 'Key saved', 'studiofaq-accordion-builder' ); ?></span>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to OpenAI platform */
				esc_html__( 'Don\'t have a key? %s', 'studiofaq-accordion-builder' ),
				'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Generate an OpenAI API key', 'studiofaq-accordion-builder' ) . ' &rarr;</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render Gemini API key field. Same blank-by-default behavior as the OpenAI field.
	 */
	public function render_gemini_key_field() {
		$options = self::get_options();
		$has_key = ! empty( $options['gemini_api_key'] );
		?>
		<input type="password"
			id="studiofaq_accordion_builder_gemini_api_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_api_key]"
			value=""
			class="regular-text"
			autocomplete="off"
			placeholder="<?php echo $has_key ? esc_attr__( '•••••••••••••••••••• (key saved — leave blank to keep it)', 'studiofaq-accordion-builder' ) : esc_attr__( 'AIza...', 'studiofaq-accordion-builder' ); ?>" />
		<?php if ( $has_key ) : ?>
			<span class="studiofaq-key-status">✓ <?php esc_html_e( 'Key saved', 'studiofaq-accordion-builder' ); ?></span>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to Google AI Studio */
				esc_html__( 'Don\'t have a key? %s', 'studiofaq-accordion-builder' ),
				'<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Generate a Google Gemini API key', 'studiofaq-accordion-builder' ) . ' &rarr;</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render Kimi AI (Moonshot) API key field. Same blank-by-default behavior
	 * as the OpenAI and Gemini fields.
	 */
	public function render_kimi_key_field() {
		$options = self::get_options();
		$has_key = ! empty( $options['kimi_api_key'] );
		?>
		<input type="password"
			id="studiofaq_accordion_builder_kimi_api_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[kimi_api_key]"
			value=""
			class="regular-text"
			autocomplete="off"
			placeholder="<?php echo $has_key ? esc_attr__( '•••••••••••••••••••• (key saved — leave blank to keep it)', 'studiofaq-accordion-builder' ) : esc_attr__( 'sk-...', 'studiofaq-accordion-builder' ); ?>" />
		<?php if ( $has_key ) : ?>
			<span class="studiofaq-key-status">✓ <?php esc_html_e( 'Key saved', 'studiofaq-accordion-builder' ); ?></span>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to Moonshot AI Kimi platform */
				esc_html__( 'Don\'t have a key? %s', 'studiofaq-accordion-builder' ),
				'<a href="https://platform.moonshot.ai/console/api-keys" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Generate a Kimi AI (Moonshot) API key', 'studiofaq-accordion-builder' ) . ' &rarr;</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render Groq API key field. Same blank-by-default behavior as the other
	 * provider key fields.
	 */
	public function render_groq_key_field() {
		$options = self::get_options();
		$has_key = ! empty( $options['groq_api_key'] );
		?>
		<input type="password"
			id="studiofaq_accordion_builder_groq_api_key"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[groq_api_key]"
			value=""
			class="regular-text"
			autocomplete="off"
			placeholder="<?php echo $has_key ? esc_attr__( '•••••••••••••••••••• (key saved — leave blank to keep it)', 'studiofaq-accordion-builder' ) : esc_attr__( 'gsk_...', 'studiofaq-accordion-builder' ); ?>" />
		<?php if ( $has_key ) : ?>
			<span class="studiofaq-key-status">✓ <?php esc_html_e( 'Key saved', 'studiofaq-accordion-builder' ); ?></span>
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to GroqCloud console */
				esc_html__( 'Don\'t have a key? %s', 'studiofaq-accordion-builder' ),
				'<a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Generate a Groq API key', 'studiofaq-accordion-builder' ) . ' &rarr;</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render active model selector field.
	 */
	public function render_active_model_field() {
		$options = self::get_options();
		?>
		<select id="studiofaq_accordion_builder_active_model" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[active_model]">
			<option value="openai" <?php selected( $options['active_model'], 'openai' ); ?>><?php esc_html_e( 'OpenAI (ChatGPT)', 'studiofaq-accordion-builder' ); ?></option>
			<option value="gemini" <?php selected( $options['active_model'], 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'studiofaq-accordion-builder' ); ?></option>
			<option value="kimi" <?php selected( $options['active_model'], 'kimi' ); ?>><?php esc_html_e( 'Kimi AI (Moonshot)', 'studiofaq-accordion-builder' ); ?></option>
			<option value="groq" <?php selected( $options['active_model'], 'groq' ); ?>><?php esc_html_e( 'Groq', 'studiofaq-accordion-builder' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Choose which AI provider should be used when generating FAQs.', 'studiofaq-accordion-builder' ); ?></p>
		<?php
	}

	/**
	 * Render default style template selector field.
	 */
	public function render_default_style_field() {
		$options = self::get_options();
		$styles  = array(
			'accordion' => __( 'Accordion', 'studiofaq-accordion-builder' ),
			'cards'     => __( 'Minimal Cards', 'studiofaq-accordion-builder' ),
			'list'      => __( 'Clean List', 'studiofaq-accordion-builder' ),
		);
		?>
		<select id="studiofaq_accordion_builder_default_style" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_style]">
			<?php foreach ( $styles as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['default_style'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'This style is used by default when rendering [studiofaq-accordion-builder] unless overridden with a style attribute.', 'studiofaq-accordion-builder' ); ?></p>
		<?php
	}

	/**
	 * Render the "Enable FAQ Schema (JSON-LD)" checkbox. Off by default —
	 * deliberately described as optional semantic markup, not a promise of
	 * any particular Google search appearance, since Google's own FAQ rich
	 * result eligibility has narrowed considerably and isn't guaranteed by
	 * simply adding schema.
	 */
	public function render_enable_schema_field() {
		$options = self::get_options();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_schema]"
				value="1"
				<?php checked( ! empty( $options['enable_schema'] ) ); ?> />
			<?php esc_html_e( 'Output FAQPage structured data (JSON-LD) alongside the FAQ accordion.', 'studiofaq-accordion-builder' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e(
				'This adds optional, standard schema.org markup describing your FAQ content in machine-readable form. It does not guarantee any particular appearance in Google or other search results — search engines decide independently whether and how to use it. If you already use an SEO plugin (Yoast, Rank Math, AIOSEO, etc.) that adds its own FAQ schema, leave this off to avoid duplicate markup on the same page.',
				'studiofaq-accordion-builder'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the "delete data on uninstall" checkbox.
	 */
	public function render_delete_data_field() {
		$options = self::get_options();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_data_on_uninstall]"
				value="1"
				<?php checked( ! empty( $options['delete_data_on_uninstall'] ) ); ?> />
			<?php esc_html_e( 'Permanently delete all StudioFAQ settings and saved FAQ data when this plugin is deleted from the Plugins screen.', 'studiofaq-accordion-builder' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Leave unchecked to keep your FAQ data if you reinstall the plugin later. This only takes effect when the plugin is deleted, not when it is deactivated.', 'studiofaq-accordion-builder' ); ?></p>
		<?php
	}

	/**
	 * Style & Branding section intro text.
	 */
	public function render_style_section_intro() {
		echo '<p>' . esc_html__( 'These are the site-wide defaults used to render the FAQ section title and accordion colors. Any individual post or page can override them from its "StudioFAQ Builder" meta box.', 'studiofaq-accordion-builder' ) . '</p>';
	}

	/**
	 * Render the default FAQ section title text field.
	 */
	public function render_section_title_field() {
		$options = self::get_options();
		?>
		<input type="text"
			id="studiofaq_accordion_builder_faq_section_title"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[faq_section_title]"
			value="<?php echo esc_attr( $options['faq_section_title'] ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'e.g. Frequently Asked Questions', 'studiofaq-accordion-builder' ); ?>" />
		<p class="description"><?php esc_html_e( 'Leave blank to render the FAQ block without a title unless a post overrides this.', 'studiofaq-accordion-builder' ); ?></p>
		<?php
	}

	/**
	 * Render the default heading tag selector for the FAQ section title.
	 */
	public function render_heading_tag_field() {
		$options = self::get_options();
		?>
		<select id="studiofaq_accordion_builder_faq_heading_tag" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[faq_heading_tag]">
			<?php foreach ( self::get_allowed_heading_tags() as $tag ) : ?>
				<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $options['faq_heading_tag'], $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'HTML tag used to wrap the FAQ section title. Choose based on your page\'s existing heading structure for SEO.', 'studiofaq-accordion-builder' ); ?></p>
		<?php
	}

	/**
	 * Render a single color-picker field. Shared callback for all color
	 * settings fields — the specific field key is passed via $args.
	 *
	 * @param array $args {
	 *     @type string $field_key Option key for this color field.
	 *     @type string $label     Human-readable label, used for the color
	 *                             picker's tooltip/aria-label so it's clear
	 *                             which field an open picker belongs to.
	 * }
	 */
	public function render_color_field( $args ) {
		$field_key = isset( $args['field_key'] ) ? $args['field_key'] : '';
		if ( '' === $field_key ) {
			return;
		}

		$label     = isset( $args['label'] ) ? $args['label'] : '';
		$options   = self::get_options();
		$defaults  = self::get_color_field_defaults();
		$value     = isset( $options[ $field_key ] ) ? $options[ $field_key ] : $defaults[ $field_key ];
		$default   = $defaults[ $field_key ];
		?>
		<input type="text"
			id="studiofaq_accordion_builder_<?php echo esc_attr( $field_key ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $field_key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			class="studiofaq-color-field"
			data-default-color="<?php echo esc_attr( $default ); ?>"
			data-color-label="<?php echo esc_attr( $label ); ?>" />
		<?php
	}

	/**
	 * Render the full settings page markup.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap studiofaq-settings-wrap">
			<h1><?php esc_html_e( 'StudioFAQ Settings', 'studiofaq-accordion-builder' ); ?></h1>
			<p><?php esc_html_e( 'Configure your AI providers and default FAQ style below.', 'studiofaq-accordion-builder' ); ?></p>

			<?php if ( ! self::gcm_available() ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Secure API key storage is unavailable on this server.', 'studiofaq-accordion-builder' ); ?></strong><br>
						<?php esc_html_e( 'This server\'s PHP build does not support AES-256-GCM encryption (via the OpenSSL extension). To protect your API keys, StudioFAQ will refuse to save any new API key until this is fixed — it will never store a key in plaintext as a fallback. Please ask your hosting provider to enable the OpenSSL extension with AES-256-GCM cipher support.', 'studiofaq-accordion-builder' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'studiofaq-accordion-builder' ) );
				?>
			</form>

			<div class="studiofaq-usage-box">
				<h2><?php esc_html_e( 'How to Use', 'studiofaq-accordion-builder' ); ?></h2>
				<p><?php esc_html_e( 'Open any Post or Page editor, find the "StudioFAQ Builder" meta box, and click "Generate FAQs with AI" or add FAQs manually. Then insert the shortcode below wherever you want your FAQs displayed:', 'studiofaq-accordion-builder' ); ?></p>
				<code>[studiofaq-accordion-builder]</code> <?php esc_html_e( 'or', 'studiofaq-accordion-builder' ); ?> <code>[studiofaq-accordion-builder id="123"]</code>
			</div>
		</div>
		<?php
	}
}
