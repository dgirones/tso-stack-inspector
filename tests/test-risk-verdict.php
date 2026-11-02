<?php
/**
 * Verdict: how usage signals raise the uninstall risk.
 *
 * @package TSO_Stack_Inspector
 */

/**
 * A usage signal.
 *
 * @param string $id       Id.
 * @param string $severity Severity.
 * @return array<string,string>
 */
function tsosi_test_signal( $id, $severity ) {
	return array(
		'id'       => $id,
		'severity' => $severity,
		'summary'  => $id . ' summary',
	);
}

/**
 * A "safe" risk as the content scan produces it.
 *
 * @return array<string,mixed>
 */
function tsosi_test_safe_risk() {
	return array(
		'level'   => 'safe',
		'label'   => 'safe',
		'help'    => 'h',
		'total'   => 0,
		'content' => 0,
		'data'    => 0,
	);
}

tsosi_test(
	'a danger signal blocks uninstalling',
	function () {
		$risk = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_signal( 'post_types_live', 'danger' ) ) ) );
		tsosi_assert_same( 'danger', $risk['level'] );
		tsosi_assert_same( array( 'post_types_live summary' ), $risk['reasons'] );
	}
);

tsosi_test(
	'review-only traces turn a safe verdict into review',
	function () {
		$risk = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_signal( 'post_types_dormant', 'review' ) ) ) );
		tsosi_assert_same( 'review', $risk['level'] );
	}
);

tsosi_test(
	'info signals (inactive widgets) never change the verdict',
	function () {
		$risk = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_signal( 'widgets_inactive', 'info' ) ) ) );
		tsosi_assert_same( 'safe', $risk['level'] );
		tsosi_assert_same( array(), $risk['reasons'] );
	}
);

tsosi_test(
	'a merely activated plugin stays safe but says it is only activated',
	function () {
		$risk = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_signal( 'active', 'info' ) ) ) );
		tsosi_assert_same( 'safe', $risk['level'] );
		tsosi_assert_true( 'safe' !== $risk['label'], 'label explains the plugin is only activated' );
	}
);

/**
 * A signal as tsosi_usage_signal() builds it, so weight and kind come from the real tables.
 *
 * @param string $id       Id.
 * @param string $severity Severity.
 * @return array<string,mixed>
 */
function tsosi_test_real_signal( $id, $severity ) {
	return tsosi_usage_signal( $id, $severity, 'label ' . $id, array(), $id . ' summary' );
}

tsosi_test(
	'every signal id emitted by the collectors has a weight and a kind (no drift)',
	function () {
		$source = file_get_contents( __DIR__ . '/../includes/tsosi-plugin-usage.php' );
		preg_match_all( "/tsosi_usage_signal\(\s*'([a-z_]+)'/", $source, $m );
		$ids = array_unique( $m[1] );
		tsosi_assert_true( count( $ids ) > 30, 'found the signal ids in the source' );
		foreach ( $ids as $id ) {
			tsosi_assert_true( array_key_exists( $id, tsosi_usage_signal_weights() ), "weight for $id" );
			tsosi_assert_true( array_key_exists( $id, tsosi_usage_signal_kinds() ), "kind for $id" );
		}
	}
);

tsosi_test(
	'info signals weigh nothing, danger signals outweigh any review pile',
	function () {
		tsosi_assert_same( 0, tsosi_usage_signal_profile( 'tables_data', 'info' )['weight'] );
		tsosi_assert_same( 0, tsosi_test_real_signal( 'widgets_inactive', 'info' )['weight'] );
		tsosi_assert_same( 10, tsosi_usage_signal_profile( 'frontend_rendered', 'danger' )['weight'] );
		tsosi_assert_same( 2, tsosi_usage_signal_profile( 'a_new_unknown_signal', 'review' )['weight'], 'cautious default' );
	}
);

tsosi_test(
	'priority grows with the summed weight',
	function () {
		tsosi_assert_same( '', tsosi_usage_score_priority( 0 ) );
		tsosi_assert_same( 'low', tsosi_usage_score_priority( 2 ) );
		tsosi_assert_same( 'medium', tsosi_usage_score_priority( 5 ) );
		tsosi_assert_same( 'high', tsosi_usage_score_priority( 6 ) );
	}
);

tsosi_test(
	'a lone minor trace is review with low priority, a heavy pile is high',
	function () {
		$minor = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_real_signal( 'cron_leftover', 'review' ) ) ) );
		tsosi_assert_same( 'review', $minor['level'] );
		tsosi_assert_same( 'low', $minor['priority'] );
		$heavy = tsosi_usage_apply_risk(
			tsosi_test_safe_risk(),
			array( 'signals' => array( tsosi_test_real_signal( 'tables_data', 'review' ), tsosi_test_real_signal( 'dropins_mu', 'review' ), tsosi_test_real_signal( 'taxonomies', 'review' ) ) )
		);
		tsosi_assert_same( 'review', $heavy['level'], 'a pile of review signals never becomes danger on its own' );
		tsosi_assert_same( 12, $heavy['score'] );
		tsosi_assert_same( 'high', $heavy['priority'] );
		tsosi_assert_true( $minor['label'] !== $heavy['label'], 'labels differ by priority' );
	}
);

tsosi_test(
	'reasons list danger first, then review from heaviest to lightest',
	function () {
		$risk = tsosi_usage_apply_risk(
			tsosi_test_safe_risk(),
			array(
				'signals' => array(
					tsosi_test_real_signal( 'cron_leftover', 'review' ),
					tsosi_test_real_signal( 'dropins_mu', 'review' ),
					tsosi_test_real_signal( 'post_types_live', 'danger' ),
					tsosi_test_real_signal( 'taxonomies', 'review' ),
				),
			)
		);
		tsosi_assert_same( array( 'post_types_live summary', 'dropins_mu summary', 'taxonomies summary', 'cron_leftover summary' ), $risk['reasons'] );
		tsosi_assert_same( 'danger', $risk['level'] );
	}
);

tsosi_test(
	'a danger signal blocks no matter how much weak evidence says otherwise',
	function () {
		$risk = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_real_signal( 'frontend_rendered', 'danger' ), tsosi_test_real_signal( 'widgets_inactive', 'info' ) ), 'coverage' => array( 'front' => 'complete', 'code' => 'complete' ) ) );
		tsosi_assert_same( 'danger', $risk['level'] );
		tsosi_assert_same( 'high', $risk['confidence'] );
	}
);

tsosi_test(
	'confidence: direct review traces are medium, only indirect hints are low',
	function () {
		$direct   = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_real_signal( 'tables_data', 'review' ) ) ) );
		$indirect = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_real_signal( 'frontend_hooks', 'review' ), tsosi_test_real_signal( 'frontend_unavailable', 'review' ) ) ) );
		tsosi_assert_same( 'medium', $direct['confidence'] );
		tsosi_assert_same( 'low', $indirect['confidence'] );
		tsosi_assert_true( '' !== $indirect['confidence_note'], 'a reason is always given' );
	}
);

tsosi_test(
	'confidence of a clean result depends on how much was read',
	function () {
		$full    = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_real_signal( 'active', 'info' ) ), 'coverage' => array( 'front' => 'complete', 'code' => 'complete' ) ) );
		$partial = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array( tsosi_test_real_signal( 'active', 'info' ) ), 'coverage' => array( 'front' => 'partial', 'code' => 'complete' ) ) );
		$code    = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array(), 'coverage' => array( 'front' => 'na', 'code' => 'partial' ) ) );
		$off     = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array(), 'coverage' => array( 'front' => 'na', 'code' => 'complete' ) ) );
		$unknown = tsosi_usage_apply_risk( tsosi_test_safe_risk(), array( 'signals' => array() ) );
		tsosi_assert_same( 'high', $full['confidence'] );
		tsosi_assert_same( 'medium', $partial['confidence'], 'public pages only partly read' );
		tsosi_assert_same( 'medium', $code['confidence'], 'code scan ran out of time' );
		tsosi_assert_same( 'high', $off['confidence'] );
		tsosi_assert_same( '', $unknown['confidence'], 'no coverage information: no claim' );
		tsosi_assert_same( 'safe', $partial['level'], 'confidence never changes the level' );
	}
);

tsosi_test(
	'a content-scan danger stays high confidence even with no usage signals',
	function () {
		$base          = tsosi_test_safe_risk();
		$base['level'] = 'danger';
		$risk          = tsosi_usage_apply_risk( $base, array( 'signals' => array() ) );
		tsosi_assert_same( 'danger', $risk['level'] );
		tsosi_assert_same( 'high', $risk['confidence'] );
	}
);
