<?php
/**
 * Uninstall risk assessment and inactive-plugin audit helpers.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Match types that mean the plugin is still embedded in visible content.
 *
 * @return string[]
 */
function tsosi_scan_risk_content_match_types() {
	return array( 'shortcode', 'block' );
}

/**
 * Assess uninstall risk from scan result rows.
 *
 * @param array<int,array<string,mixed>> $results Scan rows.
 * @return array{level:string,label:string,help:string,total:int,content:int,data:int}
 */
function tsosi_scan_assess_risk( $results ) {
	$results = is_array( $results ) ? $results : array();
	$content = 0;
	$data    = 0;
	$types   = tsosi_scan_risk_content_match_types();

	foreach ( $results as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$type = isset( $row['match_type'] ) ? sanitize_key( (string) $row['match_type'] ) : '';
		if ( '' === $type && isset( $row['source_type'] ) ) {
			$type = sanitize_key( (string) $row['source_type'] );
		}
		if ( in_array( $type, $types, true ) ) {
			++$content;
		} else {
			++$data;
		}
	}

	$total = $content + $data;

	if ( 0 === $total ) {
		return array(
			'level'   => 'safe',
			'label'   => tsosi_ui_triple_text(
				'Likely safe to uninstall (no usage found in scanned content)',
				'Probablemente seguro desinstalar (sin uso en el contenido escaneado)',
				'Probablement segur desinstal·lar (sense ús al contingut escanejat)'
			),
			'help'    => tsosi_ui_triple_text(
				'Still review the database with Options Cleaner for leftover options/tables.',
				'Aun así revisa la base de datos con Options Cleaner por opciones/tablas sobrantes.',
				'Igualment revisa la base de dades amb Options Cleaner per opcions/taules sobrants.'
			),
			'total'   => 0,
			'content' => 0,
			'data'    => 0,
		);
	}

	if ( $content > 0 ) {
		return array(
			'level'   => 'danger',
			'label'   => tsosi_ui_triple_text(
				'Do not uninstall yet — shortcodes or blocks are still in content',
				'No desinstales aún — aún hay shortcodes o bloques en el contenido',
				'No desinstal·lis encara — encara hi ha shortcodes o blocs al contingut'
			),
			'help'    => sprintf(
				/* translators: 1: content matches, 2: data matches */
				tsosi_ui_triple_text(
					'%1$d content match(es), %2$d data/meta match(es). Edit or replace them first.',
					'%1$d coincidencia(s) de contenido, %2$d de datos/meta. Edítalas o sustitúyelas antes.',
					'%1$d coincidència(es) de contingut, %2$d de dades/meta. Edita-les o substitueix-les abans.'
				),
				$content,
				$data
			),
			'total'   => $total,
			'content' => $content,
			'data'    => $data,
		);
	}

	return array(
		'level'   => 'review',
		'label'   => tsosi_ui_triple_text(
			'Content looks clear — review meta/options before uninstall',
			'El contenido parece limpio — revisa meta/opciones antes de desinstalar',
			'El contingut sembla net — revisa meta/opcions abans de desinstal·lar'
		),
		'help'    => sprintf(
			/* translators: %d: data matches */
			tsosi_ui_triple_text(
				'%d meta/option/widget match(es). Use Options Cleaner after uninstall if needed.',
				'%d coincidencia(s) meta/opción/widget. Usa Options Cleaner tras desinstalar si hace falta.',
				'%d coincidència(es) meta/opció/widget. Usa Options Cleaner després de desinstal·lar si cal.'
			),
			$data
		),
		'total'   => $total,
		'content' => 0,
		'data'    => $data,
	);
}

/**
 * Load the current site content index if fingerprint still matches.
 *
 * @return array<string,mixed>|null Index payload or null.
 */
function tsosi_audit_get_ready_index() {
	return tsosi_scan_ensure_content_index();
}

/**
 * Build needles for a plugin file from its profile (same fallback as scanner).
 *
 * @param string               $plugin_file Plugin basename.
 * @param array<string,mixed>  $profile     Optional profile.
 * @return array<string,mixed>
 */
function tsosi_audit_needles_for_plugin( $plugin_file, $profile = null ) {
	if ( ! is_array( $profile ) ) {
		$profile = tsosi_build_plugin_profile( $plugin_file );
	}
	$needles = array(
		'shortcodes'      => isset( $profile['shortcodes'] ) ? (array) $profile['shortcodes'] : array(),
		'blocks'          => isset( $profile['blocks'] ) ? (array) $profile['blocks'] : array(),
		'meta_keys'       => array(),
		'meta_prefixes'   => isset( $profile['meta_prefixes'] ) ? (array) $profile['meta_prefixes'] : array(),
		'option_prefixes' => isset( $profile['option_prefixes'] ) ? (array) $profile['option_prefixes'] : array(),
	);
	if ( tsosi_scan_needles_are_empty( $needles ) ) {
		$folder = tsosi_get_plugin_folder_raw( $plugin_file );
		if ( '' !== $folder && strlen( $folder ) >= 3 ) {
			$slug                         = sanitize_key( str_replace( '-', '_', $folder ) );
			$needles['meta_prefixes'][]   = $slug;
			$needles['option_prefixes'][] = $slug;
		}
	}
	$needles['meta_prefixes']   = tsosi_refine_prefix_list( $needles['meta_prefixes'] );
	$needles['option_prefixes'] = tsosi_refine_prefix_list( $needles['option_prefixes'] );
	return $needles;
}

/**
 * Audit inactive plugins against the cached site index (fast, no rebuild).
 *
 * @return array{rows:array<int,array<string,mixed>>,index_ready:bool}|WP_Error
 */
function tsosi_audit_inactive_plugins() {
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return tsosi_scan_index_unavailable_error();
	}

	$index = tsosi_audit_get_ready_index();
	if ( null === $index ) {
		return tsosi_scan_need_index_error();
	}

	$plugins  = tsosi_get_installed_plugins();
	$profiles = tsosi_get_all_plugin_profiles();
	$rows     = array();
	$count    = 0;

	foreach ( $plugins as $file => $header ) {
		if ( tsosi_is_plugin_active_file( $file ) ) {
			continue;
		}
		++$count;
		if ( $count > 60 ) {
			break;
		}

		$profile = isset( $profiles[ $file ] ) && is_array( $profiles[ $file ] )
			? $profiles[ $file ]
			: tsosi_build_plugin_profile( $file );
		$name    = isset( $profile['name'] ) ? (string) $profile['name'] : (string) $file;
		$needles = tsosi_audit_needles_for_plugin( $file, $profile );
		$empty   = tsosi_scan_needles_are_empty( $needles );

		if ( $empty ) {
			$risk = array(
				'level'   => 'safe',
				'label'   => tsosi_ui_triple_text( 'No signatures detected', 'Sin firmas detectadas', 'Sense signatures detectades' ),
				'help'    => '',
				'total'   => 0,
				'content' => 0,
				'data'    => 0,
			);
			$match_count = 0;
		} else {
			$results     = tsosi_scan_filter_ignored_results(
				tsosi_scan_filter_cross_plugin_false_positives(
					tsosi_scan_match_cached_index( $index, $needles ),
					array(
						'mode'        => 'plugin',
						'plugin_file' => $file,
					)
				)
			);
			$risk        = tsosi_scan_assess_risk( $results );
			$match_count = (int) $risk['total'];
		}

		$rows[] = array(
			'plugin_file'  => $file,
			'name'         => $name,
			'match_count'  => $match_count,
			'content'      => (int) $risk['content'],
			'data'         => (int) $risk['data'],
			'risk_level'   => (string) $risk['level'],
			'risk_label'   => (string) $risk['label'],
			'needles_empty'=> $empty,
			'scan_url'     => add_query_arg(
				array(
					'page'        => 'tso-stack-inspector',
					'tab'         => 'plugin',
					'plugin_file' => $file,
				),
				admin_url( 'tools.php' )
			),
		);
	}

	usort(
		$rows,
		function ( $a, $b ) {
			$order = array(
				'danger' => 0,
				'review' => 1,
				'safe'   => 2,
			);
			$la    = isset( $order[ $a['risk_level'] ] ) ? $order[ $a['risk_level'] ] : 9;
			$lb    = isset( $order[ $b['risk_level'] ] ) ? $order[ $b['risk_level'] ] : 9;
			if ( $la !== $lb ) {
				return $la - $lb;
			}
			return (int) $b['match_count'] - (int) $a['match_count'];
		}
	);

	return array(
		'rows'        => $rows,
		'index_ready' => true,
		'capped'      => $count > 60,
	);
}
