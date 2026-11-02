<?php
/**
 * Custom post types: volume, recency and the live / dormant / drafts split.
 *
 * @package TSO_Stack_Inspector
 */

/**
 * Rows as the post-type query returns them.
 *
 * @param string $status   Post status.
 * @param int    $total    Number of entries.
 * @param string $modified Last modified (GMT).
 * @return array<string,mixed>
 */
function tsosi_test_row( $status, $total, $modified ) {
	return array(
		'post_status'   => $status,
		'total'         => (string) $total,
		'last_modified' => $modified,
	);
}

$tsosi_test_now = strtotime( '2026-11-02 12:00:00 UTC' );

tsosi_test(
	'live entries and the last edit date are reported',
	function () use ( $tsosi_test_now ) {
		$state = tsosi_usage_post_type_state(
			array( tsosi_test_row( 'publish', 40, '2026-10-01 10:00:00' ), tsosi_test_row( 'private', 2, '2026-10-20 09:00:00' ), tsosi_test_row( 'trash', 5, '2026-10-30 09:00:00' ) ),
			(object) array( 'public' => true ),
			$tsosi_test_now
		);
		tsosi_assert_same( 42, $state['live'] );
		tsosi_assert_same( 47, $state['total'] );
		tsosi_assert_same( '2026-10-20', gmdate( 'Y-m-d', $state['last'] ), 'trash must not count as the last live edit' );
		tsosi_assert_same( false, $state['dormant'] );
		tsosi_assert_same( 'book (42 · 2026-10-20)', tsosi_usage_post_type_line( 'book', $state ) );
	}
);

tsosi_test(
	'old published entries of a public type are never dormant',
	function () use ( $tsosi_test_now ) {
		$state = tsosi_usage_post_type_state( array( tsosi_test_row( 'publish', 1, '2019-01-01 00:00:00' ) ), (object) array( 'public' => true ), $tsosi_test_now );
		tsosi_assert_same( false, $state['dormant'] );
	}
);

tsosi_test(
	'a type that is not registered right now is never dormant',
	function () use ( $tsosi_test_now ) {
		$state = tsosi_usage_post_type_state( array( tsosi_test_row( 'publish', 1, '2019-01-01 00:00:00' ) ), null, $tsosi_test_now );
		tsosi_assert_same( false, $state['dormant'] );
	}
);

tsosi_test(
	'a non-public type untouched for years is dormant, a recently edited one is not',
	function () use ( $tsosi_test_now ) {
		$type  = (object) array( 'public' => false, 'publicly_queryable' => false );
		$old   = tsosi_usage_post_type_state( array( tsosi_test_row( 'publish', 3, '2022-05-01 00:00:00' ) ), $type, $tsosi_test_now );
		$fresh = tsosi_usage_post_type_state( array( tsosi_test_row( 'publish', 3, '2026-09-01 00:00:00' ) ), $type, $tsosi_test_now );
		tsosi_assert_same( true, $old['dormant'] );
		tsosi_assert_same( false, $fresh['dormant'] );
	}
);

tsosi_test(
	'severity: live public = danger, stale non-public = review, drafts only = review',
	function () {
		$GLOBALS['tsosi_test']['types'] = array(
			'book' => array( 'public' => true ),
			'log'  => array( 'public' => false, 'publicly_queryable' => false ),
			'idea' => array( 'public' => true ),
		);
		$GLOBALS['wpdb']->rows          = array(
			'book' => array( tsosi_test_row( 'publish', 4, '2020-01-01 00:00:00' ) ),
			'log'  => array( tsosi_test_row( 'publish', 9, '2020-01-01 00:00:00' ) ),
			'idea' => array( tsosi_test_row( 'draft', 2, '2026-01-01 00:00:00' ) ),
		);
		$only                           = function ( $type ) {
			$signals = tsosi_usage_collect_content_types( 'p/p.php', array( 'post_types' => array( $type ), 'taxonomies' => array() ), 'p' );
			return tsosi_test_signal_map( $signals );
		};
		tsosi_assert_same( array( 'post_types_live' => 'danger' ), $only( 'book' ) );
		tsosi_assert_same( array( 'post_types_dormant' => 'review' ), $only( 'log' ) );
		tsosi_assert_same( array( 'post_types_data' => 'review' ), $only( 'idea' ) );
	}
);
