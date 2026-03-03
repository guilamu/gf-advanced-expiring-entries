<?php
/**
 * Uninstall handler for GF Advanced Expiring Entries.
 *
 * Removes all plugin data when the plugin is deleted via the WordPress admin.
 *
 * @package GF_Advanced_Expiring_Entries
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── 1. Remove custom database tables ──────────────────────────────────────
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_aee_expiry_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_aee_deleted_entries" );

// ── 2. Remove plugin settings (GF addon option) ──────────────────────────
delete_option( 'gravityformsaddon_gf-advanced-expiring-entries_settings' );

// ── 3. Remove entry meta created by the plugin ───────────────────────────
$meta_keys = array(
    '_gf_aee_expiry_ts',
    '_gf_aee_feed_id',
    '_gf_aee_status',
    '_gf_aee_override_ts',
    '_gf_aee_notified',
    '_gf_aee_action_log',
);

$meta_table = $wpdb->prefix . 'gf_entry_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) === $meta_table ) {
    foreach ( $meta_keys as $key ) {
        $wpdb->delete( $meta_table, array( 'meta_key' => $key ), array( '%s' ) );
    }
}

// ── 4. Clear scheduled cron events ───────────────────────────────────────
wp_clear_scheduled_hook( 'gf_aee_run_expiry_check' );

// ── 5. Remove transients ─────────────────────────────────────────────────
delete_transient( 'gf_aee_expiry_lock' );
delete_transient( 'gf_aee_dashboard_data' );
delete_transient( 'gf_aee_github_release' );

// ── 6. Remove GF feed data ──────────────────────────────────────────────
// GF stores feeds in the gf_addon_feed table; remove rows for our addon slug.
$feed_table = $wpdb->prefix . 'gf_addon_feed';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $feed_table ) ) === $feed_table ) {
    $wpdb->delete( $feed_table, array( 'addon_slug' => 'gf-advanced-expiring-entries' ), array( '%s' ) );
}
