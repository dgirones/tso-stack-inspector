<?php
/**
 * Export helpers for scan results (CSV headers, printable report meta).
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV column headers for scan exports.
 *
 * @return string[]
 */
function tsosi_reports_csv_headers() {
	return array(
		tsosi_ui_triple_text( 'Location', 'Ubicación', 'Ubicació' ),
		tsosi_ui_triple_text( 'Type', 'Tipo', 'Tipus' ),
		tsosi_ui_triple_text( 'Match', 'Coincidencia', 'Coincidència' ),
		tsosi_ui_triple_text( 'Context', 'Contexto', 'Context' ),
		tsosi_ui_triple_text( 'Edit URL', 'URL de edición', 'URL d\'edició' ),
	);
}

/**
 * Normalize one result row for export.
 *
 * @param array<string,mixed> $row Scan row.
 * @return string[]
 */
function tsosi_reports_row_to_csv( $row ) {
	$row = is_array( $row ) ? $row : array();
	$label = isset( $row['object_label'] ) ? (string) $row['object_label'] : '';
	if ( ! empty( $row['object_subtype'] ) ) {
		$label .= ' (' . (string) $row['object_subtype'] . ')';
	}
	if ( '' === $label && ! empty( $row['source_type'] ) ) {
		$label = (string) $row['source_type'];
	}

	return array(
		$label,
		isset( $row['match_type'] ) ? (string) $row['match_type'] : '',
		isset( $row['match_value'] ) ? (string) $row['match_value'] : '',
		isset( $row['context'] ) ? (string) $row['context'] : '',
		isset( $row['edit_url'] ) ? (string) $row['edit_url'] : '',
	);
}

/**
 * Build a short report title from scan meta.
 *
 * @param array<string,mixed> $meta Scan meta (needles, total_posts, etc.).
 * @return string
 */
function tsosi_reports_build_title( $meta ) {
	$meta  = is_array( $meta ) ? $meta : array();
	$count = isset( $meta['result_count'] ) ? (int) $meta['result_count'] : 0;
	$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	return sprintf(
		/* translators: 1: site name, 2: number of matches */
		tsosi_ui_triple_text(
			'%1$s — Stack Inspector report (%2$d matches)',
			'%1$s — Informe Stack Inspector (%2$d coincidencias)',
			'%1$s — Informe Stack Inspector (%2$d coincidències)'
		),
		$site,
		$count
	);
}
