<?php
/**
 * Storage helpers for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_DB_SCHEMA' ) ) {
	define( 'TSOSI_DB_SCHEMA', 1 );
}

if ( ! defined( 'TSOSI_OPTION_DB_SCHEMA' ) ) {
	define( 'TSOSI_OPTION_DB_SCHEMA', 'tso_stack_inspector_db_schema' );
}

if ( ! defined( 'TSOSI_OPTION_SCAN_SETTINGS' ) ) {
	define( 'TSOSI_OPTION_SCAN_SETTINGS', 'tso_stack_inspector_scan_settings' );
}

if ( ! defined( 'TSOSI_TRANSIENT_SCAN_JOB' ) ) {
	define( 'TSOSI_TRANSIENT_SCAN_JOB', 'tso_stack_inspector_scan_job' );
}

if ( ! defined( 'TSOSI_TRANSIENT_SCAN_JOB_PREFIX' ) ) {
	define( 'TSOSI_TRANSIENT_SCAN_JOB_PREFIX', 'tso_stack_inspector_scan_job_' );
}

if ( ! defined( 'TSOSI_TRANSIENT_PLUGIN_PROFILE' ) ) {
	define( 'TSOSI_TRANSIENT_PLUGIN_PROFILE', 'tso_stack_inspector_plugin_profile' );
}

if ( ! defined( 'TSOSI_USER_META_UI_LANG' ) ) {
	define( 'TSOSI_USER_META_UI_LANG', 'tso_stack_inspector_ui_lang' );
}

if ( ! defined( 'TSOSI_ADMIN_POST_ACTION' ) ) {
	define( 'TSOSI_ADMIN_POST_ACTION', 'tsosi_action' );
}

if ( ! defined( 'TSOSI_ADMIN_QUERY_SET_LANG' ) ) {
	define( 'TSOSI_ADMIN_QUERY_SET_LANG', 'tsosi_set_lang' );
}

/**
 * Verify AJAX nonce.
 *
 * @return bool
 */
function tsosi_verify_ajax_nonce() {
	$nonce = isset( $_REQUEST['_ajax_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_ajax_nonce'] ) ) : '';
	if ( '' === $nonce ) {
		return false;
	}
	return (bool) wp_verify_nonce( $nonce, TSOSI_NONCE_AJAX );
}

/**
 * Verify admin form nonce.
 *
 * @param string $query_arg Request key holding the nonce.
 * @return bool
 */
function tsosi_verify_admin_form_nonce( $query_arg = '_wpnonce' ) {
	$query_arg = (string) $query_arg;
	$nonce     = isset( $_REQUEST[ $query_arg ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) ) : '';
	if ( '' === $nonce ) {
		return false;
	}
	return (bool) wp_verify_nonce( $nonce, TSOSI_NONCE_FORM );
}

/**
 * Read sanitized POST text after AJAX nonce verification.
 *
 * @param string $key     POST key.
 * @param string $default Default when missing.
 * @return string
 */
function tsosi_get_ajax_post_text( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsosi_verify_ajax_nonce().
		return $default;
	}
	return sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsosi_verify_ajax_nonce().
}

/**
 * Read sanitized admin query arg.
 *
 * @param string $key     Query arg key.
 * @param string $default Default when missing.
 * @return string
 */
function tsosi_get_admin_query_arg( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter.
		return $default;
	}
	return sanitize_text_field( (string) wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter.
}

/**
 * Read sanitized admin POST text after form nonce verification.
 *
 * @param string $key     POST key.
 * @param string $default Default when missing.
 * @return string
 */
function tsosi_get_admin_post_text( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsosi_verify_admin_form_nonce().
		return $default;
	}
	return sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsosi_verify_admin_form_nonce().
}

/**
 * Sanitize a shortcode tag (preserve hyphens; sanitize_key strips them).
 *
 * @param string $tag Shortcode tag.
 * @return string
 */
function tsosi_sanitize_shortcode_tag( $tag ) {
	$tag = strtolower( trim( (string) $tag ) );
	if ( '' === $tag ) {
		return '';
	}
	$clean = preg_replace( '/[^a-z0-9_-]/', '', $tag );
	return is_string( $clean ) ? $clean : '';
}

/**
 * Sanitize a block name (namespace/block).
 *
 * @param string $name Block name.
 * @return string
 */
function tsosi_sanitize_block_name( $name ) {
	$name = strtolower( trim( (string) $name ) );
	if ( '' === $name ) {
		return '';
	}
	$clean = preg_replace( '/[^a-z0-9\/_-]/', '', $name );
	return is_string( $clean ) ? $clean : '';
}

/**
 * Run storage migrations on activate/admin bootstrap.
 *
 * @return void
 */
function tsosi_migrate_storage() {
	$current = (int) get_option( TSOSI_OPTION_DB_SCHEMA, 0 );
	if ( $current >= TSOSI_DB_SCHEMA ) {
		return;
	}
	update_option( TSOSI_OPTION_DB_SCHEMA, TSOSI_DB_SCHEMA );
}

/**
 * Default scan settings.
 *
 * @return array<string,mixed>
 */
function tsosi_get_default_scan_settings() {
	return array(
		'post_statuses' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'include_reusable_blocks' => true,
		'include_widgets'         => true,
		'include_menus'           => true,
		'include_non_autoload_options' => true,
	);
}

/**
 * @return array<string,mixed>
 */
function tsosi_get_scan_settings() {
	$stored = get_option( TSOSI_OPTION_SCAN_SETTINGS, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return wp_parse_args( $stored, tsosi_get_default_scan_settings() );
}

/**
 * @param array<string,mixed> $settings Settings.
 * @return void
 */
function tsosi_update_scan_settings( $settings ) {
	if ( ! is_array( $settings ) ) {
		return;
	}
	$defaults = tsosi_get_default_scan_settings();
	$clean    = array(
		'post_statuses'           => array_values(
			array_filter(
				array_map(
					'sanitize_key',
					(array) ( $settings['post_statuses'] ?? $defaults['post_statuses'] )
				)
			)
		),
		'include_reusable_blocks' => ! empty( $settings['include_reusable_blocks'] ),
		'include_widgets'         => ! empty( $settings['include_widgets'] ),
		'include_menus'           => ! empty( $settings['include_menus'] ),
		'include_non_autoload_options' => ! empty( $settings['include_non_autoload_options'] ),
	);
	update_option( TSOSI_OPTION_SCAN_SETTINGS, $clean );
}

/**
 * Per-user transient key for in-progress scan jobs (avoids cross-admin races).
 *
 * @return string
 */
function tsosi_scan_job_transient_key() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return TSOSI_TRANSIENT_SCAN_JOB;
	}
	return TSOSI_TRANSIENT_SCAN_JOB_PREFIX . $user_id;
}

/**
 * Uploads subdirectory for this plugin.
 *
 * @return array{path:string,url:string,error:bool}
 */
function tsosi_get_uploads_dir() {
	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return array(
			'path'  => '',
			'url'   => '',
			'error' => true,
		);
	}
	$path = trailingslashit( $upload['basedir'] ) . 'tso-stack-inspector';
	$url  = trailingslashit( $upload['baseurl'] ) . 'tso-stack-inspector';
	if ( ! wp_mkdir_p( $path ) ) {
		return array(
			'path'  => '',
			'url'   => '',
			'error' => true,
		);
	}
	$index = $path . '/index.php';
	if ( ! is_file( $index ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- non-executable stub in uploads.
		file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}
	return array(
		'path'  => $path,
		'url'   => $url,
		'error' => false,
	);
}
