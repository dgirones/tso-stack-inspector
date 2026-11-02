<?php
/**
 * WP-CLI inactive plugins audit command.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit inactive plugins against the site index.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : table, json, csv, count
 * ---
 * default: table
 * ---
 */
class TSOSI_CLI_Audit_Inactive_Command {

	/**
	 * Invoke.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );
		tsosi_cli_ensure_index();
		$result = tsosi_audit_inactive_plugins();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows = array();
		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'plugin'    => isset( $row['name'] ) ? (string) $row['name'] : '',
				'file'      => isset( $row['plugin_file'] ) ? (string) $row['plugin_file'] : '',
				'matches'   => isset( $row['match_count'] ) ? (int) $row['match_count'] : 0,
				'content'   => isset( $row['content'] ) ? (int) $row['content'] : 0,
				'data'      => isset( $row['data'] ) ? (int) $row['data'] : 0,
				'risk'      => isset( $row['risk_level'] ) ? (string) $row['risk_level'] : '',
				'confidence'=> isset( $row['confidence'] ) ? (string) $row['confidence'] : '',
			);
		}

		if ( ! empty( $result['capped'] ) ) {
			WP_CLI::warning( 'Only the first 60 inactive plugins were audited; some were not checked.' );
		}

		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $rows ) );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows ) );
			return;
		}
		if ( 'csv' === $format ) {
			WP_CLI::log( 'plugin,file,matches,content,data,risk,confidence' );
			foreach ( $rows as $row ) {
				WP_CLI::log( implode( ',', array_map( 'tsosi_cli_csv_escape', $row ) ) );
			}
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'plugin', 'file', 'matches', 'content', 'data', 'risk', 'confidence' ) );
	}
}
