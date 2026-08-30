<?php
/**
 * Admin asset registration for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return string
 */
function tsosi_admin_assets_version() {
	return defined( 'TSOSI_VERSION' ) ? TSOSI_VERSION : '1.0.0';
}

/**
 * @param string $relative_path Path relative to plugin root.
 * @return string
 */
function tsosi_admin_asset_file_version( $relative_path ) {
	$relative_path = ltrim( (string) $relative_path, '/' );
	if ( '' === $relative_path || ! defined( 'TSOSI_PATH' ) ) {
		return tsosi_admin_assets_version();
	}
	$path = TSOSI_PATH . $relative_path;
	return is_readable( $path ) ? (string) filemtime( $path ) : tsosi_admin_assets_version();
}

/**
 * Enqueue admin assets on plugin screen.
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function tsosi_admin_enqueue_assets( $hook_suffix ) {
	if ( 'tools_page_tso-stack-inspector' !== $hook_suffix ) {
		return;
	}

	$base = defined( 'TSOSI_URL' ) ? trailingslashit( TSOSI_URL ) : '';

	wp_enqueue_style(
		'tso-stack-inspector-admin',
		$base . 'assets/css/admin.css',
		array(),
		tsosi_admin_asset_file_version( 'assets/css/admin.css' )
	);

	wp_enqueue_style(
		'tso-stack-inspector-report',
		$base . 'assets/css/report.css',
		array(),
		tsosi_admin_asset_file_version( 'assets/css/report.css' )
	);

	wp_enqueue_script(
		'tso-stack-inspector-admin',
		$base . 'assets/js/admin.js',
		array(),
		tsosi_admin_asset_file_version( 'assets/js/admin.js' ),
		true
	);

	wp_localize_script(
		'tso-stack-inspector-admin',
		'tsosiAdminConfig',
		tsosi_admin_get_js_config()
	);
}
add_action( 'admin_enqueue_scripts', 'tsosi_admin_enqueue_assets' );

/**
 * Inline report CSS for printable popup (avoids external stylesheet on blob: URLs).
 *
 * @return string
 */
function tsosi_admin_get_report_css_inline() {
	if ( ! defined( 'TSOSI_PATH' ) ) {
		return '';
	}
	$path = TSOSI_PATH . 'assets/css/report.css';
	if ( ! is_readable( $path ) ) {
		return '';
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- static plugin asset for print report.
	$css = file_get_contents( $path );
	return is_string( $css ) ? $css : '';
}

/**
 * Config passed to admin.js.
 *
 * @return array<string,mixed>
 */
function tsosi_admin_get_js_config() {
	$lang = tsosi_get_ui_lang();
	$base = defined( 'TSOSI_URL' ) ? trailingslashit( TSOSI_URL ) : '';

	return array(
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'nonce'        => wp_create_nonce( TSOSI_NONCE_AJAX ),
		'lang'         => $lang,
		'batchSize'    => TSOSI_SCAN_BATCH_SIZE,
		'csvHeaders'      => tsosi_reports_csv_headers(),
		'reportCssUrl'    => $base . 'assets/css/report.css',
		'reportCssVer'    => tsosi_admin_asset_file_version( 'assets/css/report.css' ),
		'siteName'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'i18n'         => array(
			'scanning'    => tsosi_ui_triple_text( 'Scanning…', 'Escaneando…', 'Escanejant…' ),
			'scanDone'    => tsosi_ui_triple_text( 'Scan complete.', 'Escaneo completado.', 'Escaneig completat.' ),
			'scanCached'  => tsosi_ui_triple_text(
				'Using cached site index (content unchanged).',
				'Usando índice en caché (contenido sin cambios).',
				'Usant índex en memòria cau (contingut sense canvis).'
			),
			'scanError'   => tsosi_ui_triple_text( 'Scan failed.', 'Error en el escaneo.', 'Error en l\'escaneig.' ),
			'noResults'   => tsosi_ui_triple_text( 'No usage found.', 'No se encontró uso.', 'No s\'ha trobat ús.' ),
			'startScan'   => tsosi_ui_triple_text( 'Start scan', 'Iniciar escaneo', 'Iniciar escaneig' ),
			'progress'    => tsosi_ui_triple_text( 'Progress', 'Progreso', 'Progrés' ),
			'scanSummary' => tsosi_ui_triple_text(
				'Scanned %1$s posts/widgets/options. Needles: %2$s. Matches: %3$s.',
				'Escaneados %1$s posts/widgets/opciones. Firmas: %2$s. Coincidencias: %3$s.',
				'Escanejats %1$s posts/widgets/opcions. Signatures: %2$s. Coincidències: %3$s.'
			),
			'needlesEmpty' => tsosi_ui_triple_text(
				'No shortcodes, blocks, or meta prefixes were detected for this plugin. Try the Shortcode or Block tab.',
				'No se detectaron shortcodes, bloques ni prefijos meta para este plugin. Prueba la pestaña Shortcode o Bloque.',
				'No s\'han detectat shortcodes, blocs ni prefixos meta per a aquest plugin. Prova la pestanya Shortcode o Bloc.'
			),
			'exportCsv'    => tsosi_ui_triple_text( 'Export CSV', 'Exportar CSV', 'Exportar CSV' ),
			'exportPdf'    => tsosi_ui_triple_text( 'Print / PDF', 'Imprimir / PDF', 'Imprimir / PDF' ),
			'exportPdfBlocked' => tsosi_ui_triple_text(
				'Allow pop-ups for this site to open the print report.',
				'Permite las ventanas emergentes de este sitio para abrir el informe.',
				'Permet les finestres emergents d\'aquest lloc per obrir l\'informe.'
			),
			'refreshDone'  => tsosi_ui_triple_text( 'Signatures refreshed. Reloading…', 'Firmas actualizadas. Recargando…', 'Signatures actualitzades. Recarregant…' ),
			'refreshFail'  => tsosi_ui_triple_text( 'Could not refresh signatures.', 'No se pudieron actualizar las firmas.', 'No s\'han pogut actualitzar les signatures.' ),
			'reportTitle'  => tsosi_ui_triple_text( 'Stack Inspector report', 'Informe Stack Inspector', 'Informe Stack Inspector' ),
			'editLabel'    => tsosi_ui_triple_text( 'Edit', 'Editar', 'Editar' ),
		),
	);
}
