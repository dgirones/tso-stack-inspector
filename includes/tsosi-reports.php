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
 * CSV column headers for the orphans export (same order as `wp tsosi orphans --format=csv`).
 *
 * @return string[]
 */
function tsosi_reports_orphans_csv_headers() {
	return array(
		__( 'Type', 'tso-stack-inspector' ),
		__( 'Name', 'tso-stack-inspector' ),
		__( 'Matches', 'tso-stack-inspector' ),
		__( 'Samples', 'tso-stack-inspector' ),
	);
}

/**
 * CSV column headers for the inactive-plugins audit export (same order as the CLI).
 *
 * @return string[]
 */
function tsosi_reports_inactive_csv_headers() {
	return array(
		__( 'Plugin', 'tso-stack-inspector' ),
		__( 'File', 'tso-stack-inspector' ),
		__( 'Matches', 'tso-stack-inspector' ),
		__( 'Content', 'tso-stack-inspector' ),
		__( 'Data', 'tso-stack-inspector' ),
		__( 'Risk', 'tso-stack-inspector' ),
		__( 'Risk detail', 'tso-stack-inspector' ),
		__( 'Confidence', 'tso-stack-inspector' ),
	);
}

// Row formatting and report-title text live client-side in assets/js/admin.js
// (exportCsv()/buildReportHtml()), which is the only consumer of CSV/PDF export —
// keep that JS as the single source of truth instead of duplicating it here.
