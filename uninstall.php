<?php
/**
 * Uninstall cleanup for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tso_stack_inspector_db_schema' );
delete_option( 'tso_stack_inspector_scan_settings' );
delete_transient( 'tso_stack_inspector_scan_job' );
delete_transient( 'tso_stack_inspector_plugin_profile' );

global $wpdb;

$tsosi_scan_job_like = '_transient_' . $wpdb->esc_like( 'tso_stack_inspector_scan_job_' ) . '%';
$tsosi_timeout_like  = '_transient_timeout_' . $wpdb->esc_like( 'tso_stack_inspector_scan_job_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: remove per-user scan job transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$tsosi_scan_job_like,
		$tsosi_timeout_like
	)
);

$tsosi_cache_like         = '_transient_' . $wpdb->esc_like( 'tso_stack_inspector_content_cache_' ) . '%';
$tsosi_cache_timeout_like = '_transient_timeout_' . $wpdb->esc_like( 'tso_stack_inspector_content_cache_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: remove per-user content cache transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$tsosi_cache_like,
		$tsosi_cache_timeout_like
	)
);

// Remove UI language preference from all users in one call.
delete_metadata( 'user', 0, 'tso_stack_inspector_ui_lang', '', true );

$tsosi_uploads = wp_upload_dir();
if ( empty( $tsosi_uploads['error'] ) && ! empty( $tsosi_uploads['basedir'] ) ) {
	$tsosi_cache_dir = trailingslashit( $tsosi_uploads['basedir'] ) . 'tso-stack-inspector';
	if ( is_dir( $tsosi_cache_dir ) ) {
		$tsosi_cache_files = glob( $tsosi_cache_dir . '/cache-*.json' );
		if ( is_array( $tsosi_cache_files ) ) {
			foreach ( $tsosi_cache_files as $tsosi_cache_file ) {
				if ( is_string( $tsosi_cache_file ) && is_file( $tsosi_cache_file ) ) {
					wp_delete_file( $tsosi_cache_file );
				}
			}
		}
	}
}
