<?php
/**
 * WP-CLI orphans command.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find orphan shortcodes and blocks.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : table, json, csv, count
 * ---
 * default: table
 * ---
 */
class TSOSI_CLI_Orphans_Command {

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
		$result = tsosi_orphans_find();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows = array();
		foreach ( (array) ( $result['shortcodes'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'type'  => 'shortcode',
				'tag'   => isset( $row['tag'] ) ? (string) $row['tag'] : '',
				'count' => isset( $row['count'] ) ? (int) $row['count'] : 0,
			);
		}
		foreach ( (array) ( $result['blocks'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'type'  => 'block',
				'tag'   => isset( $row['name'] ) ? (string) $row['name'] : '',
				'count' => isset( $row['count'] ) ? (int) $row['count'] : 0,
			);
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
			WP_CLI::log( 'type,tag,count' );
			foreach ( $rows as $row ) {
				WP_CLI::log( implode( ',', array_map( 'tsosi_cli_csv_escape', $row ) ) );
			}
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'tag', 'count' ) );
	}
}
