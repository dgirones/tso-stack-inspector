<?php
/**
 * Deep links and helpers for TSO Options & Tables Cleaner integration.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Options Cleaner is available.
 *
 * @return bool
 */
function tsosi_options_cleaner_is_available() {
	return '' !== tsosi_get_options_cleaner_url();
}

/**
 * Admin URL for Options Cleaner with optional plugin / prefix context.
 *
 * @param string          $plugin_file Optional plugin basename.
 * @param string|string[] $prefixes    Optional option prefixes to hint.
 * @return string
 */
function tsosi_get_options_cleaner_scan_url( $plugin_file = '', $prefixes = array() ) {
	$base = tsosi_get_options_cleaner_url();
	if ( '' === $base ) {
		return '';
	}

	$args = array(
		'tsosi_from' => 'stack-inspector',
	);

	$plugin_file = tsosi_sanitize_plugin_file( (string) $plugin_file );
	if ( '' !== $plugin_file ) {
		$args['tsosi_plugin'] = rawurlencode( $plugin_file );
		$folder               = tsosi_get_plugin_folder_raw( $plugin_file );
		if ( '' !== $folder ) {
			$args['tsosi_prefix'] = sanitize_key( str_replace( '-', '_', $folder ) );
		}
	}

	if ( ! is_array( $prefixes ) ) {
		$prefixes = array( $prefixes );
	}
	$clean_prefixes = array();
	foreach ( $prefixes as $prefix ) {
		$prefix = function_exists( 'tsosi_sanitize_option_prefix' )
			? tsosi_sanitize_option_prefix( (string) $prefix )
			: sanitize_key( str_replace( '-', '_', (string) $prefix ) );
		if ( strlen( $prefix ) >= 3 ) {
			$clean_prefixes[] = $prefix;
		}
	}
	$clean_prefixes = array_values( array_unique( $clean_prefixes ) );
	if ( ! empty( $clean_prefixes ) ) {
		$args['tsosi_prefixes'] = implode( ',', array_slice( $clean_prefixes, 0, 8 ) );
		if ( empty( $args['tsosi_prefix'] ) ) {
			$args['tsosi_prefix'] = $clean_prefixes[0];
		}
	}

	return add_query_arg( $args, $base );
}

/**
 * Build Options Cleaner deep link from scan needles + optional plugin.
 *
 * @param array<string,mixed> $needles     Needles.
 * @param string              $plugin_file Plugin file.
 * @return string
 */
function tsosi_get_options_cleaner_url_from_needles( $needles, $plugin_file = '' ) {
	$prefixes = array();
	if ( is_array( $needles ) && ! empty( $needles['option_prefixes'] ) && is_array( $needles['option_prefixes'] ) ) {
		$prefixes = $needles['option_prefixes'];
	}
	return tsosi_get_options_cleaner_scan_url( $plugin_file, $prefixes );
}

/**
 * Stack Inspector URL with plugin preselected.
 *
 * @param string $plugin_file Plugin basename.
 * @return string
 */
function tsosi_get_inspector_plugin_url( $plugin_file = '' ) {
	$url         = tsosi_admin_page_url();
	$plugin_file = tsosi_sanitize_plugin_file( (string) $plugin_file );
	if ( '' === $plugin_file ) {
		return $url;
	}
	return add_query_arg(
		array(
			'tab'         => 'plugin',
			'plugin_file' => rawurlencode( $plugin_file ),
		),
		$url
	);
}
