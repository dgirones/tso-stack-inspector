<?php
/**
 * Weights, evidence kind and confidence of the "is it still in use?" verdict.
 *
 * Every usage signal has a severity (danger / review / info) and, on top of that, a weight
 * (how much it counts inside the review zone) and a kind (how direct the evidence is).
 * Hard rule: a danger signal always blocks uninstalling, whatever the sum of the weak ones;
 * and a pile of review signals never becomes "danger" by itself, because the leftovers of a
 * plugin nobody uses any more are not proof that it is running. The score only grades the
 * review zone (low / medium / high priority) and orders the reasons.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weight of a signal inside the review zone (1 = minor trace, 5 = heavy trace).
 * Signals whose severity is "info" always weigh 0 and danger ones are not summed.
 *
 * @return array<string,int>
 */
function tsosi_usage_signal_weights() {
	return array(
		'active'              => 0,
		'active_other_sites'  => 5,
		'loaded_by_code'      => 5,
		'paused'              => 1,
		'dependents'          => 3,
		'hooks'               => 0,
		'frontend_hooks'      => 2,
		'ajax_public'         => 0,
		'cron'                => 0,
		'rest'                => 0,
		'widget_classes'      => 0,
		'classic_in_use'      => 5,
		'avatars_in_use'      => 5,
		'backups_scheduled'   => 3,
		'cleaning_scheduled'  => 3,
		'frontend_unavailable'=> 3,
		'frontend_rendered'   => 5,
		'frontend_scripts'    => 3,
		'post_types_live'     => 5,
		'post_types_dormant'  => 2,
		'post_types_data'     => 2,
		'taxonomies'          => 3,
		'widgets_inactive'    => 0,
		'tables_data'         => 4,
		'tables_empty'        => 0,
		'roles'               => 3,
		'builder_widgets'     => 5,
		'cron_leftover'       => 2,
		'scheduled_actions'   => 3,
		'page_templates'      => 5,
		'dropins_mu'          => 5,
		'htaccess'            => 4,
		'data_dirs'           => 0,
		'code_hard'           => 5,
		'code_soft'           => 0,
		'code_partial'        => 0,
		'theme_active'        => 5,
		'theme_parent'        => 5,
		'theme_children'      => 3,
		'theme_templates'     => 5,
		'theme_other_sites'   => 5,
		'theme_fallback'      => 3,
	);
}

/**
 * How direct the evidence behind a signal is.
 *
 * - direct: something visible, stored or declared depends on the plugin right now.
 * - inferred: circumstantial (it registers hooks, a script mentions it...) and may be harmless.
 * - unverified: the check itself could not be done.
 *
 * @return array<string,string>
 */
function tsosi_usage_signal_kinds() {
	$direct     = array( 'active_other_sites', 'loaded_by_code', 'dependents', 'classic_in_use', 'avatars_in_use', 'backups_scheduled', 'cleaning_scheduled', 'frontend_rendered', 'post_types_live', 'post_types_dormant', 'post_types_data', 'taxonomies', 'tables_data', 'roles', 'builder_widgets', 'cron_leftover', 'scheduled_actions', 'page_templates', 'dropins_mu', 'htaccess', 'code_hard', 'theme_active', 'theme_parent', 'theme_children', 'theme_templates', 'theme_other_sites' );
	$inferred   = array( 'active', 'paused', 'hooks', 'frontend_hooks', 'ajax_public', 'cron', 'rest', 'widget_classes', 'frontend_scripts', 'widgets_inactive', 'tables_empty', 'data_dirs', 'code_soft', 'code_partial', 'theme_fallback' );
	$unverified = array( 'frontend_unavailable' );
	$kinds      = array();
	foreach ( $direct as $id ) {
		$kinds[ $id ] = 'direct';
	}
	foreach ( $inferred as $id ) {
		$kinds[ $id ] = 'inferred';
	}
	foreach ( $unverified as $id ) {
		$kinds[ $id ] = 'unverified';
	}
	return $kinds;
}

/**
 * Weight and evidence kind of one signal. Unknown ids get a cautious default.
 *
 * @param string $id       Signal id.
 * @param string $severity danger|review|info.
 * @return array{weight:int,kind:string}
 */
function tsosi_usage_signal_profile( $id, $severity ) {
	$weights = tsosi_usage_signal_weights();
	$kinds   = tsosi_usage_signal_kinds();
	$id      = sanitize_key( (string) $id );
	if ( 'info' === $severity ) {
		$weight = 0;
	} elseif ( 'danger' === $severity ) {
		$weight = 10;
	} else {
		$weight = isset( $weights[ $id ] ) ? (int) $weights[ $id ] : 2;
	}
	return array(
		'weight' => $weight,
		'kind'   => isset( $kinds[ $id ] ) ? $kinds[ $id ] : 'inferred',
	);
}

/**
 * Priority of the review zone from the summed weights.
 *
 * @param int $score Sum of the weights of the review signals.
 * @return string low|medium|high ('' when there is nothing to review).
 */
function tsosi_usage_score_priority( $score ) {
	$score = (int) $score;
	if ( $score <= 0 ) {
		return '';
	}
	if ( $score <= 2 ) {
		return 'low';
	}
	return $score <= 5 ? 'medium' : 'high';
}

/**
 * Order signals from the heaviest to the lightest, keeping the original order on ties.
 *
 * @param array<int,array<string,mixed>> $signals Signals.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_sort_by_weight( $signals ) {
	$indexed = array();
	foreach ( array_values( $signals ) as $i => $signal ) {
		$profile   = tsosi_usage_signal_profile( isset( $signal['id'] ) ? $signal['id'] : '', isset( $signal['severity'] ) ? $signal['severity'] : 'review' );
		$indexed[] = array( $profile['weight'], $i, $signal );
	}
	usort(
		$indexed,
		static function ( $a, $b ) {
			return $a[0] === $b[0] ? $a[1] - $b[1] : $b[0] - $a[0];
		}
	);
	return array_map(
		static function ( $row ) {
			return $row[2];
		},
		$indexed
	);
}

/**
 * How much to trust a verdict, and why.
 *
 * @param string                         $base_level Level of the content scan before the usage signals (safe|review|danger).
 * @param array<int,array<string,mixed>> $danger     Danger signals.
 * @param array<int,array<string,mixed>> $review     Review signals.
 * @param array<string,string>           $coverage   front: complete|partial|na, code: complete|partial. Empty when unknown.
 * @return array{level:string,note:string}
 */
function tsosi_usage_confidence( $base_level, $danger, $review, $coverage ) {
	$has_direct = static function ( $signals ) {
		foreach ( $signals as $signal ) {
			$profile = tsosi_usage_signal_profile( $signal['id'], $signal['severity'] );
			if ( 'direct' === $profile['kind'] ) {
				return true;
			}
		}
		return false;
	};

	if ( ! empty( $danger ) || 'danger' === $base_level ) {
		if ( 'danger' === $base_level || $has_direct( $danger ) ) {
			return array(
				'level' => 'high',
				'note'  => tsosi_ui_triple_text(
					'Confirmed by direct evidence: something visible, stored or declared depends on it',
					'Confirmado por evidencia directa: algo visible, guardado o declarado depende de él',
					'Confirmat per evidència directa: alguna cosa visible, desada o declarada en depèn'
				),
			);
		}
		return array(
			'level' => 'medium',
			'note'  => tsosi_ui_triple_text( 'Based on indirect signs only', 'Basado solo en indicios indirectos', 'Basat només en indicis indirectes' ),
		);
	}

	if ( ! empty( $review ) ) {
		if ( $has_direct( $review ) ) {
			return array(
				'level' => 'medium',
				'note'  => tsosi_ui_triple_text(
					'Based on data or traces it left behind, not on proof that it is running now',
					'Basado en datos o huellas que dejó, no en una prueba de que se esté usando ahora',
					'Basat en dades o empremtes que ha deixat, no en una prova que s\'estigui usant ara'
				),
			);
		}
		return array(
			'level' => 'low',
			'note'  => tsosi_ui_triple_text(
				'Only indirect hints (hooks, scripts, checks that could not be done); they may be harmless',
				'Solo indicios indirectos (hooks, scripts, comprobaciones que no se pudieron hacer); pueden ser inofensivos',
				'Només indicis indirectes (hooks, scripts, comprovacions que no s\'han pogut fer); poden ser innocus'
			),
		);
	}

	if ( 'review' === $base_level ) {
		return array(
			'level' => 'medium',
			'note'  => tsosi_ui_triple_text(
				'Based on stored data found by the content scan',
				'Basado en datos guardados que encontró el escaneo de contenido',
				'Basat en dades desades que ha trobat l\'escaneig de contingut'
			),
		);
	}

	// Nothing found: how much of the site was actually looked at?
	if ( empty( $coverage ) ) {
		return array(
			'level' => '',
			'note'  => '',
		);
	}
	$front = isset( $coverage['front'] ) ? (string) $coverage['front'] : 'na';
	$code  = isset( $coverage['code'] ) ? (string) $coverage['code'] : 'complete';
	if ( 'partial' === $front || 'partial' === $code ) {
		return array(
			'level' => 'medium',
			'note'  => tsosi_ui_triple_text(
				'Some checks were only partial (public pages or code were not read in full)',
				'Algunas comprobaciones fueron parciales (no se leyeron por completo las páginas públicas o el código)',
				'Algunes comprovacions han estat parcials (no s\'han llegit del tot les pàgines públiques o el codi)'
			),
		);
	}
	return array(
		'level' => 'high',
		'note'  => 'complete' === $front
			? tsosi_ui_triple_text(
				'The public pages were read in full and nothing of this plugin showed up',
				'Las páginas públicas se leyeron por completo y no apareció nada de este plugin',
				'Les pàgines públiques s\'han llegit del tot i no hi ha aparegut res d\'aquest plugin'
			)
			: tsosi_ui_triple_text(
				'Nothing found in content, data or code, and the plugin is not running',
				'No se encontró nada en contenido, datos o código, y el plugin no está en marcha',
				'No s\'ha trobat res en contingut, dades o codi, i el plugin no està en marxa'
			),
	);
}
