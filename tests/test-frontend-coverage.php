<?php
/**
 * Front-end sampling: which pages are read and when silence counts as "not used".
 *
 * @package TSO_Stack_Inspector
 */

tsosi_test(
	'plan takes one page of each kind before a second of any',
	function () {
		$plan = tsosi_usage_frontend_plan(
			array(
				'home'   => array( 'h' ),
				'latest' => array( 'l1', 'l2' ),
				'types'  => array( 't1', 't2', 't3' ),
			),
			4
		);
		tsosi_assert_same( array( 'h', 'l1', 't1', 'l2' ), $plan );
	}
);

tsosi_test(
	'plan drops duplicates and respects the cap',
	function () {
		$plan = tsosi_usage_frontend_plan(
			array(
				'home'  => array( 'a' ),
				'latest' => array( 'a', 'b' ),
				'types' => array( 'b', 'c', 'd', 'e' ),
			),
			3
		);
		tsosi_assert_same( array( 'a', 'b', 'c' ), $plan );
	}
);

tsosi_test(
	'reading every planned page is complete',
	function () {
		$GLOBALS['tsosi_test']['http'] = array(
			'https://x.test/'  => array( 'code' => 200, 'body' => '<html>a</html>' ),
			'https://x.test/p' => array( 'code' => 200, 'body' => '<html>b</html>' ),
		);
		$read = tsosi_usage_frontend_read( array( 'https://x.test/', 'https://x.test/p' ), 10.0 );
		tsosi_assert_same( true, $read['complete'] );
		tsosi_assert_same( 2, count( $read['html'] ) );
	}
);

tsosi_test(
	'a page that fails (server error, timeout, access denied) makes the read incomplete',
	function () {
		foreach ( array( 503, 403 ) as $code ) {
			$GLOBALS['tsosi_test']['http'] = array(
				'https://x.test/'  => array( 'code' => 200, 'body' => '<html>a</html>' ),
				'https://x.test/p' => array( 'code' => $code, 'body' => 'nope' ),
			);
			$read = tsosi_usage_frontend_read( array( 'https://x.test/', 'https://x.test/p' ), 10.0 );
			tsosi_assert_same( false, $read['complete'], "HTTP $code" );
			tsosi_assert_same( 1, $read['failed'] );
		}
		$GLOBALS['tsosi_test']['http'] = array( 'https://x.test/' => array( 'code' => 200, 'body' => '<html>a</html>' ) );
		$read = tsosi_usage_frontend_read( array( 'https://x.test/', 'https://x.test/timeout' ), 10.0 );
		tsosi_assert_same( false, $read['complete'], 'transport error' );
	}
);

tsosi_test(
	'a published page that answers 404 does not make the read incomplete (seen on blog2: /404-2/)',
	function () {
		$GLOBALS['tsosi_test']['http'] = array(
			'https://x.test/'      => array( 'code' => 200, 'body' => '<html>a</html>' ),
			'https://x.test/404-2' => array( 'code' => 404, 'body' => 'not found' ),
		);
		$read = tsosi_usage_frontend_read( array( 'https://x.test/', 'https://x.test/404-2' ), 10.0 );
		tsosi_assert_same( true, $read['complete'] );
		tsosi_assert_same( 1, $read['missing'] );
		tsosi_assert_same( 0, $read['failed'] );
		tsosi_assert_same( 1, count( $read['html'] ) );
	}
);

tsosi_test(
	'if every page is missing nothing was read, so the read is not complete',
	function () {
		$GLOBALS['tsosi_test']['http'] = array( 'https://x.test/' => array( 'code' => 404, 'body' => 'x' ) );
		$read = tsosi_usage_frontend_read( array( 'https://x.test/' ), 10.0 );
		tsosi_assert_same( false, $read['complete'] );
	}
);

tsosi_test(
	'an exhausted time budget skips pages and is not complete',
	function () {
		$GLOBALS['tsosi_test']['http'] = array(
			'https://x.test/'  => array( 'code' => 200, 'body' => '<html>a</html>' ),
			'https://x.test/p' => array( 'code' => 200, 'body' => '<html>b</html>' ),
		);
		$read = tsosi_usage_frontend_read( array( 'https://x.test/', 'https://x.test/p' ), 0.0 );
		tsosi_assert_same( 1, count( $read['html'] ) );
		tsosi_assert_same( 1, $read['skipped'] );
		tsosi_assert_same( false, $read['complete'] );
	}
);

tsosi_test(
	'hooks suspicion is relaxed only after a complete read',
	function () {
		$signals = array(
			array( 'id' => 'frontend_hooks', 'severity' => 'review', 'label' => 'L', 'summary' => 'S' ),
		);
		$full    = tsosi_usage_relax_frontend_hooks( $signals, array( 'complete' => true ) );
		tsosi_assert_same( 'info', $full[0]['severity'] );
		$partial = tsosi_usage_relax_frontend_hooks( $signals, array( 'complete' => false ) );
		tsosi_assert_same( 'review', $partial[0]['severity'] );
	}
);

tsosi_test(
	'hooks suspicion stays when the plugin output was seen on the front end',
	function () {
		$signals = array(
			array( 'id' => 'frontend_hooks', 'severity' => 'review', 'label' => 'L', 'summary' => 'S' ),
			array( 'id' => 'frontend_rendered', 'severity' => 'danger', 'label' => 'L', 'summary' => 'S' ),
		);
		$out = tsosi_usage_relax_frontend_hooks( $signals, array( 'complete' => true ) );
		tsosi_assert_same( 'review', $out[0]['severity'] );
	}
);

tsosi_test(
	'markup of the plugin in the HTML is matched, generic prefixes are not',
	function () {
		$pages = array(
			'https://x.test/contact/' => '<div class="myform-wrap"></div><link href="/wp-content/plugins/my-form/css/a.css?ver=1"><div id="widget-1"></div>',
		);
		$hits  = tsosi_usage_frontend_match( $pages, 'my-form', array( 'myform', 'widget' ) );
		tsosi_assert_same( array( '/wp-content/plugins/my-form/css/a.css' ), $hits['assets'] );
		tsosi_assert_same( array( 'myform-wrap' ), $hits['markers'] );
		tsosi_assert_same( array( '/contact/' ), $hits['where']['markers|myform-wrap'] );
	}
);

tsosi_test(
	'candidates take the latest posts and the latest page separately (pages newer than posts)',
	function () {
		$GLOBALS['tsosi_test']['posts']     = array(
			'post' => array( 11, 12, 13 ),
			'page' => array( 21, 22 ),
		);
		$GLOBALS['tsosi_test']['templates'] = array( '31' );
		$groups = tsosi_usage_frontend_candidates();
		tsosi_assert_same( array( 'https://x.test/?p=11', 'https://x.test/?p=12', 'https://x.test/?p=21' ), $groups['latest'] );
		tsosi_assert_same( array( 'https://x.test/?p=31' ), $groups['templates'] );
		tsosi_assert_same( array( 'https://x.test/' ), $groups['home'] );
		$plan = tsosi_usage_frontend_plan( $groups, 12 );
		tsosi_assert_same( 5, count( $plan ) );
		tsosi_assert_same( 'https://x.test/', $plan[0], 'home first' );
	}
);
