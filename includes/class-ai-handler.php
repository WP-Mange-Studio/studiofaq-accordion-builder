<?php
/**
 * AI Handler - AJAX endpoint that talks to OpenAI, Google Gemini, Kimi AI, or Groq.
 *
 * @package StudioFAQ_Accordion_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StudioFAQ_Accordion_Builder_Handler
 */
class StudioFAQ_Accordion_Builder_Handler {

	/**
	 * Minimum number of seconds a user must wait between two generate requests.
	 */
	const COOLDOWN_SECONDS = 15;

	/**
	 * Maximum number of AI generation requests a single user may make per day.
	 * Prevents runaway API spend from repeated clicking or a compromised account.
	 */
	const DAILY_REQUEST_LIMIT = 30;

	/**
	 * Cron hook name for the daily stale-row cleanup — scheduled on plugin
	 * activation and unscheduled on deactivation (see studiofaq.php).
	 */
	const CLEANUP_CRON_HOOK = 'studiofaq_accordion_builder_cleanup_rate_limit_rows';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_studiofaq_accordion_builder_generate', array( $this, 'handle_generate_request' ) );
		add_action( self::CLEANUP_CRON_HOOK, array( $this, 'cleanup_stale_rate_limit_rows' ) );
	}

	/**
	 * Daily cron callback: removes expired cooldown rows and previous days'
	 * daily-count rows. These are plain (non-transient) options — see
	 * enforce_rate_limit()'s docblock for why — so they need this explicit
	 * sweep instead of WordPress's automatic transient garbage collection.
	 */
	public function cleanup_stale_rate_limit_rows() {
		global $wpdb;

		// Expired cooldown rows (value is a UNIX timestamp in the past).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk cleanup of this plugin's own rate-limit option rows by LIKE pattern; there is no WP_Query/get_option() equivalent for a pattern-matched bulk DELETE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- These are deliberately non-cached, non-autoloaded raw options (see enforce_rate_limit() docblock above); caching them would risk stale reads racing the atomic INSERT ... ON DUPLICATE KEY UPDATE counters below, breaking the rate limit.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( 'studiofaq_accordion_builder_cd_' ) . '%',
				time()
			)
		);

		// Daily-count rows from any day other than today.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bulk cleanup of this plugin's own rate-limit option rows by LIKE pattern; there is no WP_Query/get_option() equivalent for a pattern-matched bulk DELETE.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- These are deliberately non-cached, non-autoloaded raw options (see enforce_rate_limit() docblock above); caching them would risk stale reads racing the atomic INSERT ... ON DUPLICATE KEY UPDATE counters below, breaking the rate limit.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				$wpdb->esc_like( 'studiofaq_accordion_builder_daily_' ) . '%',
				'%' . $wpdb->esc_like( '_' . gmdate( 'Ymd' ) )
			)
		);
	}

	/**
	 * Handle the AJAX request to generate FAQs from post content.
	 */
	public function handle_generate_request() {
		// Nonce check.
		check_ajax_referer( 'studiofaq_accordion_builder_nonce', 'nonce' );

		// The request must be tied to a real, specific post — this is what makes
		// the capability check below meaningful (edit_posts alone is too broad;
		// a Contributor has edit_posts but should only be able to trigger
		// generation for posts they're actually allowed to edit).
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid or missing post reference.', 'studiofaq-accordion-builder' ) )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to edit this post.', 'studiofaq-accordion-builder' ) )
			);
		}

		$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
		$content = trim( wp_strip_all_tags( $content ) );

		if ( '' === $content ) {
			wp_send_json_error(
				array( 'message' => __( 'No content was provided to generate FAQs from.', 'studiofaq-accordion-builder' ) )
			);
		}

		// Rate limiting happens after the cheap validation above but before any
		// external API call, so invalid requests don't consume a user's quota.
		$rate_limit_error = $this->enforce_rate_limit( get_current_user_id() );
		if ( is_wp_error( $rate_limit_error ) ) {
			wp_send_json_error(
				array( 'message' => $rate_limit_error->get_error_message() )
			);
		}

		// Limit content length to keep prompts reasonable and control token usage.
		if ( function_exists( 'mb_substr' ) ) {
			$content = mb_substr( $content, 0, 8000 );
		} else {
			$content = substr( $content, 0, 8000 );
		}

		$options      = StudioFAQ_Accordion_Builder_Admin_Settings::get_options_decrypted();
		$active_model = $options['active_model'];

		if ( 'gemini' === $active_model ) {
			$result = $this->generate_with_gemini( $content, $options['gemini_api_key'] );
		} elseif ( 'kimi' === $active_model ) {
			$result = $this->generate_with_kimi( $content, $options['kimi_api_key'] );
		} elseif ( 'groq' === $active_model ) {
			$result = $this->generate_with_groq( $content, $options['groq_api_key'] );
		} else {
			$result = $this->generate_with_openai( $content, $options['openai_api_key'] );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() )
			);
		}

		wp_send_json_success( array( 'items' => $result ) );
	}

	/**
	 * Enforce a short per-user cooldown plus a daily cap on AI generation requests.
	 *
	 * Deliberately does NOT use the Transients API here. get_transient() +
	 * set_transient() is a read-then-write from PHP: two truly simultaneous
	 * requests (e.g. a double-click, or two browser tabs) can both read the
	 * same "not yet claimed" / "count so far" value before either one writes
	 * its update, so both can slip through — the cooldown "reserved slot"
	 * comment in earlier versions of this method described the intent but
	 * the actual get-then-set pair still had that race.
	 *
	 * Instead, both checks below use a single `INSERT ... ON DUPLICATE KEY
	 * UPDATE` statement against wp_options directly. That statement is one
	 * atomic operation as far as MySQL/MariaDB is concerned — the storage
	 * engine takes a row lock for its duration, so two concurrent requests
	 * are serialized by the database itself rather than racing in PHP.
	 * $wpdb->rows_affected then tells us, unambiguously, whether *this*
	 * call was the one that claimed the slot:
	 *   - 1 row affected  = a brand new row was inserted (first request
	 *                       today / first request since the last cooldown).
	 *   - 2 rows affected = an existing row's value actually changed (MySQL's
	 *                       documented behavior for ON DUPLICATE KEY UPDATE
	 *                       when the UPDATE clause changes the value) — this
	 *                       call successfully claimed the next slot.
	 *   - 0 rows affected = an existing row's value was left unchanged by
	 *                       the UPDATE clause (our own IF() guard below,
	 *                       used specifically to detect "already at the
	 *                       limit" / "still cooling down") — this call did
	 *                       NOT get a slot.
	 *
	 * These rows are intentionally plain options, not transients, so
	 * WordPress won't offload them to a persistent object cache behind our
	 * back (which would break the atomicity guarantee above — an object
	 * cache's own increment isn't necessarily atomic, and reads/writes could
	 * end up split between the cache and the DB). Because they bypass the
	 * Transients API, they also don't get WordPress's automatic expiry/
	 * garbage-collection, so a small daily cron job (registered in
	 * studiofaq.php, callback below in cleanup_stale_rate_limit_rows())
	 * sweeps up expired cooldown rows and previous days' daily-count rows.
	 *
	 * @param int $user_id Current user ID.
	 * @return true|WP_Error True if the request may proceed, WP_Error otherwise.
	 */
	private function enforce_rate_limit( $user_id ) {
		global $wpdb;

		$now = time();

		// --- Cooldown ---------------------------------------------------
		$cooldown_name    = 'studiofaq_accordion_builder_cd_' . $user_id;
		$cooldown_expires = $now + self::COOLDOWN_SECONDS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic INSERT ... ON DUPLICATE KEY UPDATE is required so concurrent requests from the same user can't both pass the cooldown check; get_option()/update_option() would read-then-write non-atomically and allow a race.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Row is a deliberately non-autoloaded, non-cached option (see docblock above); caching would risk a stale read racing this atomic counter and defeating the rate limit it enforces.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, %d, 'no')
				 ON DUPLICATE KEY UPDATE option_value = IF( CAST(option_value AS UNSIGNED) <= %d, %d, option_value )",
				$cooldown_name,
				$cooldown_expires,
				$now,
				$cooldown_expires
			)
		);

		if ( 0 === $wpdb->rows_affected ) {
			return new WP_Error(
				'studiofaq_accordion_builder_rate_limited',
				__( 'Please wait a few seconds before generating FAQs again.', 'studiofaq-accordion-builder' )
			);
		}

		// --- Daily cap ----------------------------------------------------
		// A fresh key every day (date-suffixed) means no explicit reset
		// logic is needed — yesterday's row simply stops being read/written
		// and is swept up by the daily cleanup cron.
		$daily_name = 'studiofaq_accordion_builder_daily_' . $user_id . '_' . gmdate( 'Ymd' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic INSERT ... ON DUPLICATE KEY UPDATE increment is required so concurrent requests from the same user can't both pass the daily-cap check; get_option()/update_option() would read-then-write non-atomically and allow a race past the limit.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Row is a deliberately non-autoloaded, non-cached option (see docblock above); caching would risk a stale read racing this atomic counter and defeating the daily request limit it enforces.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, 1, 'no')
				 ON DUPLICATE KEY UPDATE option_value = IF( CAST(option_value AS UNSIGNED) < %d, CAST(option_value AS UNSIGNED) + 1, option_value )",
				$daily_name,
				self::DAILY_REQUEST_LIMIT
			)
		);

		if ( 0 === $wpdb->rows_affected ) {
			return new WP_Error(
				'studiofaq_accordion_builder_daily_limit',
				__( 'You have reached today\'s AI FAQ generation limit. Please try again tomorrow, or add FAQs manually.', 'studiofaq-accordion-builder' )
			);
		}

		return true;
	}

	/**
	 * Build the shared prompt instructing the AI to return strict JSON.
	 *
	 * @param string $content Source content to extract FAQs from.
	 * @return string
	 */
	private function build_prompt( $content ) {
		return "You are an assistant that extracts Frequently Asked Questions from article content.\n" .
			"Read the following content and generate between 3 and 5 relevant Question and Answer pairs that would help readers and satisfy search engine FAQ schema requirements.\n" .
			"Respond with ONLY a valid JSON object and nothing else — no markdown formatting, no code fences, no explanation text before or after.\n" .
			"The JSON object must have exactly one key, \"faqs\", whose value is an array of objects.\n" .
			"Each object in the \"faqs\" array must have exactly two keys: \"question\" and \"answer\".\n" .
			"Keep answers concise (1-3 sentences) and factually grounded in the content provided.\n\n" .
			"CONTENT:\n" . $content;
	}

	/**
	 * Call the OpenAI Chat Completions API.
	 *
	 * Note: OpenAI periodically retires model IDs. gpt-4o-mini was replaced
	 * here with gpt-5.6-terra (OpenAI's current cost-balanced "mini"-tier
	 * model, GA July 2026) after conflicting retirement signals for
	 * gpt-4o-mini surfaced. If FAQ generation starts failing with a
	 * "model ... does not exist" error, check
	 * https://platform.openai.com/docs/models for the current model name
	 * and update the model string below.
	 *
	 * @param string $content Source content.
	 * @param string $api_key OpenAI API key (plaintext, already decrypted).
	 * @return array|WP_Error Array of items or WP_Error.
	 */
	private function generate_with_openai( $content, $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_key', __( 'OpenAI API key is not configured. Please add it in StudioFAQ settings.', 'studiofaq-accordion-builder' ) );
		}

		$prompt = $this->build_prompt( $content );

		$body = array(
			'model'           => 'gpt-5.6-terra',
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'You only output valid JSON. Never include markdown code fences or commentary.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature'     => 0.5,
			'max_tokens'      => 1500,
			// Structured output mode: forces the API to always return valid JSON,
			// which removes almost all "response could not be parsed" failures.
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'OpenAI API request failed.', 'studiofaq-accordion-builder' );
			return new WP_Error( 'openai_error', $message );
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'openai_empty', __( 'OpenAI returned an empty response.', 'studiofaq-accordion-builder' ) );
		}

		$raw_text = $data['choices'][0]['message']['content'];

		return $this->parse_faq_json( $raw_text );
	}

	/**
	 * Call the Kimi AI (Moonshot AI) API.
	 *
	 * Moonshot exposes an OpenAI-compatible Chat Completions endpoint, so the
	 * request/response shape mirrors generate_with_openai() closely — only the
	 * base URL, model name, and Authorization header target Moonshot instead.
	 *
	 * @param string $content Source content.
	 * @param string $api_key Kimi (Moonshot) API key (plaintext, already decrypted).
	 * @return array|WP_Error Array of items or WP_Error.
	 */
	private function generate_with_kimi( $content, $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_key', __( 'Kimi AI (Moonshot) API key is not configured. Please add it in StudioFAQ settings.', 'studiofaq-accordion-builder' ) );
		}

		$prompt = $this->build_prompt( $content );

		$body = array(
			'model'           => 'kimi-k3',
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'You only output valid JSON. Never include markdown code fences or commentary.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature'     => 0.5,
			'max_tokens'      => 1500,
			// Structured output mode: forces the API to always return valid JSON,
			// which removes almost all "response could not be parsed" failures.
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = wp_remote_post(
			'https://api.moonshot.ai/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Kimi AI (Moonshot) API request failed.', 'studiofaq-accordion-builder' );
			return new WP_Error( 'kimi_error', $message );
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'kimi_empty', __( 'Kimi AI returned an empty response.', 'studiofaq-accordion-builder' ) );
		}

		$raw_text = $data['choices'][0]['message']['content'];

		return $this->parse_faq_json( $raw_text );
	}

	/**
	 * Call the Groq API.
	 *
	 * GroqCloud hosts open models behind an ultra-fast, OpenAI-compatible
	 * Chat Completions endpoint, so the request/response shape again mirrors
	 * generate_with_openai() — only the base URL, model name, and
	 * Authorization header target Groq instead.
	 *
	 * Note: llama-3.3-70b-versatile was deprecated by Groq (announced
	 * June 17, 2026, shut down August 16, 2026) and has been replaced here
	 * with openai/gpt-oss-120b per Groq's own migration guidance. If FAQ
	 * generation starts failing with a "model has been decommissioned"
	 * error, check https://console.groq.com/docs/models for the current
	 * model name and update the model string below.
	 *
	 * @param string $content Source content.
	 * @param string $api_key Groq API key (plaintext, already decrypted).
	 * @return array|WP_Error Array of items or WP_Error.
	 */
	private function generate_with_groq( $content, $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_key', __( 'Groq API key is not configured. Please add it in StudioFAQ settings.', 'studiofaq-accordion-builder' ) );
		}

		$prompt = $this->build_prompt( $content );

		$body = array(
			'model'           => 'openai/gpt-oss-120b',
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'You only output valid JSON. Never include markdown code fences or commentary.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature'     => 0.5,
			'max_tokens'      => 1500,
			// Structured output mode: forces the API to always return valid JSON,
			// which removes almost all "response could not be parsed" failures.
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = wp_remote_post(
			'https://api.groq.com/openai/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Groq API request failed.', 'studiofaq-accordion-builder' );
			return new WP_Error( 'groq_error', $message );
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'groq_empty', __( 'Groq returned an empty response.', 'studiofaq-accordion-builder' ) );
		}

		$raw_text = $data['choices'][0]['message']['content'];

		return $this->parse_faq_json( $raw_text );
	}

	/**
	 * Call the Google Gemini API.
	 *
	 * Note: Google deprecates/retires Gemini model IDs frequently (sometimes with only
	 * a few weeks' notice). If FAQ generation starts failing with a "model ... is no
	 * longer available" error, check https://ai.google.dev/gemini-api/docs/models
	 * for the current GA model name and update the endpoint below.
	 *
	 * @param string $content Source content.
	 * @param string $api_key Gemini API key (plaintext, already decrypted).
	 * @return array|WP_Error Array of items or WP_Error.
	 */
	private function generate_with_gemini( $content, $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_key', __( 'Google Gemini API key is not configured. Please add it in StudioFAQ settings.', 'studiofaq-accordion-builder' ) );
		}

		$prompt = $this->build_prompt( $content );

		// The API key is sent via the `x-goog-api-key` header rather than as a
		// `?key=...` query-string parameter, so it never ends up in web server
		// access logs, proxy logs, or Referer headers.
		$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

		$faq_schema = array(
			'type'       => 'OBJECT',
			'properties' => array(
				'faqs' => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'question' => array( 'type' => 'STRING' ),
							'answer'   => array( 'type' => 'STRING' ),
						),
						'required'   => array( 'question', 'answer' ),
					),
				),
			),
			'required'   => array( 'faqs' ),
		);

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				// Extraction/classification-style tasks don't need heavy reasoning,
				// and thinking tokens are deducted from maxOutputTokens, so we keep
				// thinking minimal and leave plenty of budget for the actual answer.
				'thinkingConfig'   => array(
					'thinkingLevel' => 'minimal',
				),
				'maxOutputTokens'  => 2048,
				// Structured output mode: guarantees the response matches our schema,
				// which removes almost all "response could not be parsed" failures.
				'responseMimeType' => 'application/json',
				'responseSchema'   => $faq_schema,
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Google Gemini API request failed.', 'studiofaq-accordion-builder' );
			return new WP_Error( 'gemini_error', $message );
		}

		if ( empty( $data['candidates'][0]['content']['parts'] ) || ! is_array( $data['candidates'][0]['content']['parts'] ) ) {
			return new WP_Error( 'gemini_empty', __( 'Google Gemini returned an empty response.', 'studiofaq-accordion-builder' ) );
		}

		// Gemini 3.x models may return multiple parts, some of which are internal
		// "thought" summaries (flagged with "thought": true). Only concatenate the
		// actual answer parts, skipping any thought/reasoning parts.
		$raw_text = '';
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( ! empty( $part['thought'] ) ) {
				continue;
			}
			if ( isset( $part['text'] ) ) {
				$raw_text .= $part['text'];
			}
		}

		if ( '' === trim( $raw_text ) ) {
			return new WP_Error( 'gemini_empty', __( 'Google Gemini returned an empty response.', 'studiofaq-accordion-builder' ) );
		}

		return $this->parse_faq_json( $raw_text );
	}

	/**
	 * Parse the AI's raw text output into a clean array of Q&A items.
	 *
	 * Expects a JSON object of the form {"faqs": [{"question": "...", "answer": "..."}]}
	 * (guaranteed by structured-output mode on both providers), but defensively also
	 * accepts a bare JSON array and strips markdown code fences, in case a provider
	 * ever ignores the response-format setting.
	 *
	 * @param string $raw_text Raw text returned by the AI.
	 * @return array|WP_Error
	 */
	private function parse_faq_json( $raw_text ) {
		$text = trim( $raw_text );

		// Strip markdown code fences if present (```json ... ``` or ``` ... ```).
		if ( preg_match( '/```(?:json)?\s*(.*?)\s*```/is', $text, $matches ) ) {
			$text = trim( $matches[1] );
		}

		$decoded = json_decode( $text, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
			// Last resort: isolate the outermost JSON object or array in case the
			// model added stray text around it despite instructions not to.
			$open_positions  = array_filter( array( strpos( $text, '{' ), strpos( $text, '[' ) ), 'is_int' );
			$close_positions = array_filter( array( strrpos( $text, '}' ), strrpos( $text, ']' ) ), 'is_int' );

			if ( ! empty( $open_positions ) && ! empty( $close_positions ) ) {
				$start = min( $open_positions );
				$end   = max( $close_positions );
				if ( $end > $start ) {
					$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
				}
			}
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'invalid_json', __( 'The AI response could not be parsed. Please try again.', 'studiofaq-accordion-builder' ) );
		}

		if ( isset( $decoded['faqs'] ) && is_array( $decoded['faqs'] ) ) {
			$entries = $decoded['faqs'];
		} elseif ( $this->is_sequential_array( $decoded ) ) {
			// Backward-compatible fallback: a bare array of {question, answer}.
			$entries = $decoded;
		} else {
			return new WP_Error( 'invalid_json', __( 'The AI response could not be parsed. Please try again.', 'studiofaq-accordion-builder' ) );
		}

		$clean = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$question = isset( $entry['question'] ) ? sanitize_text_field( $entry['question'] ) : '';
			$answer   = isset( $entry['answer'] ) ? sanitize_textarea_field( $entry['answer'] ) : '';

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$clean[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		if ( empty( $clean ) ) {
			return new WP_Error( 'no_faqs', __( 'The AI did not return any usable FAQs. Please try again or add FAQs manually.', 'studiofaq-accordion-builder' ) );
		}

		return $clean;
	}

	/**
	 * Check whether an array is a plain sequential (list-style) array,
	 * i.e. not an associative/keyed array. Avoids requiring PHP 8.1's
	 * native array_is_list() since this plugin targets PHP 7.4+.
	 *
	 * @param array $array Array to check.
	 * @return bool
	 */
	private function is_sequential_array( array $array ) {
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}
