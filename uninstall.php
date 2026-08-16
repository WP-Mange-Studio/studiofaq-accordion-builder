<?php
/**
 * Uninstall handler for StudioFAQ Accordion Builder.
 *
 * WordPress only executes this file when a user clicks "Delete" for this
 * plugin on the Plugins screen (never on simple deactivation), and only
 * after confirming. Deletion of stored data is opt-in: it only happens if
 * the "Delete all plugin data when uninstalled" checkbox on the StudioFAQ
 * settings page was checked. Otherwise, settings and saved FAQs are left
 * intact in case the plugin is reinstalled later.
 *
 * @package StudioFAQ_Accordion_Builder
 */

// If this file is called directly, or not via WordPress's uninstall process, bail.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$studiofaq_accordion_builder_options = get_option( 'studiofaq_accordion_builder_options', array() );

$studiofaq_accordion_builder_should_delete = is_array( $studiofaq_accordion_builder_options )
	&& ! empty( $studiofaq_accordion_builder_options['delete_data_on_uninstall'] );

if ( ! $studiofaq_accordion_builder_should_delete ) {
	return;
}

global $wpdb;

// Remove the plugin's settings (API keys, model choice, style, etc).
delete_option( 'studiofaq_accordion_builder_options' );

// Also cover multisite installs where options may be stored per-site.
if ( is_multisite() ) {
	delete_site_option( 'studiofaq_accordion_builder_options' );
}

// Remove all saved FAQ data attached to posts/pages across the site.
// This file only runs during an explicit, user-confirmed "Delete" of the
// plugin (see docblock above) with the opt-in "delete data" setting checked
// — a one-time cleanup, not a per-request query — so the meta_key lookup
// this normally warns about isn't a performance concern here, and it's the
// only way to remove all of this plugin's own postmeta at uninstall.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_studiofaq_accordion_builder_items' ) );

// Remove the per-post title/color override settings saved alongside the
// FAQ items above — same meta box, same lifecycle, so it's deleted here too.
// Same one-time, opt-in uninstall context as above.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_studiofaq_accordion_builder_settings' ) );

// Clean up any leftover rate-limiting counter rows. These are stored as
// plain (non-transient) options — not via set_transient()/get_transient() —
// specifically so the daily-cap counter can be incremented atomically with a
// single INSERT ... ON DUPLICATE KEY UPDATE statement (see
// StudioFAQ_Accordion_Builder_Handler::enforce_rate_limit()); real WP transients
// don't guarantee that under concurrent requests. Since they're not real
// transients, WordPress's own transient garbage collection won't clean them
// up on its own, so they're removed explicitly here. The LIKE patterns are
// scoped to this plugin's own `studiofaq_accordion_builder_cd_` /
// `studiofaq_accordion_builder_daily_` prefixes so they can never match
// option rows belonging to a different (e.g. the older, unrelated StudioFAQ)
// plugin.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		$wpdb->esc_like( 'studiofaq_accordion_builder_cd_' ) . '%',
		$wpdb->esc_like( 'studiofaq_accordion_builder_daily_' ) . '%'
	)
);
