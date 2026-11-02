<?php
/**
 * WP-CLI commands for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Load the command classes (one class per file).
 */
require_once TSOSI_PATH . 'includes/class-tsosi-cli-scan-command.php';
require_once TSOSI_PATH . 'includes/class-tsosi-cli-orphans-command.php';
require_once TSOSI_PATH . 'includes/class-tsosi-cli-audit-inactive-command.php';

/**
 * Cli csv escape.
 *
 * @param string $value Cell value.
 * @return string
 */
function tsosi_cli_csv_escape( $value ) {
	$value = (string) $value;
	// Neutralise spreadsheet formulas (CSV injection); plain numbers are left alone.
	if ( preg_match( '/^[=+\-@\t\r]/', $value ) && ! is_numeric( $value ) ) {
		$value = "'" . $value;
	}
	if ( preg_match( '/[",\n\r]/', $value ) ) {
		return '"' . str_replace( '"', '""', $value ) . '"';
	}
	return $value;
}

WP_CLI::add_command( 'tsosi scan', 'TSOSI_CLI_Scan_Command' );

/**
 * Build the site index in CLI (batched).
 *
 * @return void
 */
function tsosi_cli_ensure_index() {
	$start = tsosi_index_job_start( false );
	if ( is_wp_error( $start ) ) {
		WP_CLI::error( $start->get_error_message() );
	}
	if ( ! empty( $start['done'] ) ) {
		return;
	}

	$guard = 0;
	do {
		$step = tsosi_index_job_step();
		if ( is_wp_error( $step ) ) {
			WP_CLI::error( $step->get_error_message() );
		}
		if ( ! empty( $step['done'] ) ) {
			return;
		}
		++$guard;
	} while ( $guard < 5000 );

	WP_CLI::error( 'Index build did not finish in time.' );
}

WP_CLI::add_command( 'tsosi orphans', 'TSOSI_CLI_Orphans_Command' );
WP_CLI::add_command( 'tsosi audit-inactive', 'TSOSI_CLI_Audit_Inactive_Command' );
