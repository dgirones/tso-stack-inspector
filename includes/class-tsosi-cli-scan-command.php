<?php
/**
 * WP-CLI scan command.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan site content from the command line.
 */
class TSOSI_CLI_Scan_Command {

	/**
	 * Scan for plugin usage.
	 *
	 * ## OPTIONS
	 *
	 * [--plugin=<file>]
	 * : Plugin basename (e.g. contact-form-7/wp-contact-form-7.php)
	 *
	 * [--shortcode=<tag>]
	 * : Shortcode tag without brackets
	 *
	 * [--block=<name>]
	 * : Block name namespace/block
	 *
	 * [--theme[=<stylesheet>]]
	 * : Scan theme signatures. Pass a stylesheet slug to scan a specific (including inactive)
	 * theme, e.g. --theme=twentytwentyfour; bare --theme scans the active theme.
	 *
	 * [--format=<format>]
	 * : table, json, csv, count
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$query = array( 'mode' => 'plugin' );
		if ( ! empty( $assoc_args['shortcode'] ) ) {
			$query = array(
				'mode'      => 'shortcode',
				'shortcode' => tsosi_sanitize_shortcode_tag( (string) $assoc_args['shortcode'] ),
			);
		} elseif ( ! empty( $assoc_args['block'] ) ) {
			$query = array(
				'mode'  => 'block',
				'block' => tsosi_sanitize_block_name( (string) $assoc_args['block'] ),
			);
		} elseif ( isset( $assoc_args['theme'] ) ) {
			$stylesheet = is_string( $assoc_args['theme'] ) ? $assoc_args['theme'] : '';
			if ( '' === $stylesheet ) {
				$stylesheet = (string) wp_get_theme()->get_stylesheet();
			}
			$query = array(
				'mode'  => 'theme',
				'theme' => tsosi_sanitize_theme_stylesheet( $stylesheet ),
			);
		} elseif ( ! empty( $assoc_args['plugin'] ) ) {
			$query = array(
				'mode'        => 'plugin',
				'plugin_file' => tsosi_sanitize_plugin_file( (string) $assoc_args['plugin'] ),
			);
		} else {
			WP_CLI::error( 'Provide --plugin, --shortcode, --block, or --theme.' );
		}

		$start = tsosi_scan_job_start( $query );
		if ( is_wp_error( $start ) ) {
			WP_CLI::error( $start->get_error_message() );
		}
		if ( ! empty( $start['done'] ) ) {
			$results = isset( $start['results'] ) && is_array( $start['results'] ) ? $start['results'] : array();
			$this->output_results( $results, $assoc_args );
			return;
		}

		$guard = 0;
		do {
			$step = tsosi_scan_job_step();
			if ( is_wp_error( $step ) ) {
				WP_CLI::error( $step->get_error_message() );
			}
			if ( ! empty( $step['done'] ) ) {
				$results = isset( $step['results'] ) && is_array( $step['results'] ) ? $step['results'] : array();
				$this->output_results( $results, $assoc_args );
				return;
			}
			++$guard;
		} while ( $guard < 5000 );

		WP_CLI::error( 'Scan did not finish in time.' );
	}

	/**
	 * Output results.
	 *
	 * @param array<int,array<string,mixed>> $results Results.
	 * @param array<string,string>           $assoc_args Flags.
	 * @return void
	 */
	private function output_results( $results, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $results ) );
			return;
		}

		$rows = array();
		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// Same label as the CSV/PDF export: object label (or source type) plus subtype.
			$location = isset( $row['object_label'] ) && '' !== (string) $row['object_label'] ? (string) $row['object_label'] : ( isset( $row['source_type'] ) ? (string) $row['source_type'] : '' );
			if ( ! empty( $row['object_subtype'] ) ) {
				$location .= ' (' . (string) $row['object_subtype'] . ')';
			}
			$rows[] = array(
				'location' => $location,
				'type'     => isset( $row['match_type'] ) ? (string) $row['match_type'] : '',
				'match'    => isset( $row['match_value'] ) ? (string) $row['match_value'] : '',
				'context'  => isset( $row['context'] ) ? (string) $row['context'] : '',
				'edit'     => isset( $row['edit_url'] ) ? (string) $row['edit_url'] : '',
			);
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $rows ) );
			return;
		}

		if ( 'csv' === $format ) {
			$headers = array( 'location', 'type', 'match', 'context', 'edit' );
			WP_CLI::log( implode( ',', $headers ) );
			foreach ( $rows as $row ) {
				WP_CLI::log( implode( ',', array_map( 'tsosi_cli_csv_escape', $row ) ) );
			}
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'location', 'type', 'match', 'context', 'edit' ) );
	}
}
