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
