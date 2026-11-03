<?php
/**
 * Public-page sampling memory: pages that 404 are not asked again, and a complete read is
 * reused for a couple of minutes unless something that changes the pages has changed.
 *
 * @package TSO_Stack_Inspector
 */

/**
 * Empty the cache file of the shared test uploads folder.
 *
 * @return string Folder path.
 */
function tsosi_test_uploads() {
	$file = TSOSI_TEST_UPLOADS . '/tso-stack-inspector/front-snapshot.json.gz';
	if ( is_file( $file ) ) {
		unlink( $file ); // phpcs:ignore
	}
	return TSOSI_TEST_UPLOADS;
}

/**
 * A small site: home, 2 posts, 2 pages.
 *
 * @param bool $newest_page_404 Whether the newest page serves a 404 (like /404-2/ on a real site).
 * @return void
 */
function tsosi_test_small_site( $newest_page_404 = true ) {
	$GLOBALS['tsosi_test']['posts'] = array(
		'post' => array( 11, 12 ),
		'page' => array( 21, 22 ),
	);
	$ok                             = array(
		'code' => 200,
		'body' => '<html>page</html>',
	);
	$GLOBALS['tsosi_test']['http']  = array(
		'https://x.test/'     => $ok,
		'https://x.test/?p=11' => $ok,
		'https://x.test/?p=12' => $ok,
		'https://x.test/?p=21' => $newest_page_404 ? array(
			'code' => 404,
			'body' => 'not found',
		) : $ok,
		'https://x.test/?p=22' => $ok,
	);
}

tsosi_test(
	'missing URLs are remembered and a recently 404 page is not requested again',
	function () {
		tsosi_test_small_site();
		$dir  = tsosi_test_uploads();
		$read = tsosi_usage_frontend_load();
		tsosi_assert_same( true, $read['complete'], 'a 404 page does not make the read incomplete' );
		tsosi_assert_same( array( 'https://x.test/?p=21' ), tsosi_front_missing_get() );

		// Next scan (cache cleared to force a plan): the 404 page is skipped and the next page takes its place.
		$GLOBALS['tsosi_test']['requested'] = array();
		unlink( $dir . '/tso-stack-inspector/front-snapshot.json.gz' ); // phpcs:ignore
		tsosi_usage_frontend_load();
		tsosi_assert_same( false, in_array( 'https://x.test/?p=21', $GLOBALS['tsosi_test']['requested'], true ), 'not requested again' );
		tsosi_assert_true( in_array( 'https://x.test/?p=22', $GLOBALS['tsosi_test']['requested'], true ), 'the next page took its place' );
	}
);

tsosi_test(
	'the home page is never dropped, even if it once answered 404',
	function () {
		tsosi_assert_same( array( 'https://x.test/', 'https://x.test/b' ), tsosi_usage_drop_urls( array( 'https://x.test/', 'https://x.test/a', 'https://x.test/b' ), array( 'https://x.test/', 'https://x.test/a' ) ) );
		tsosi_front_missing_add( array( 'https://x.test/' ) );
		$GLOBALS['tsosi_test']['posts'] = array();
		$groups                         = tsosi_usage_frontend_candidates();
		tsosi_assert_same( array( 'https://x.test/' ), $groups['home'] );
	}
);

tsosi_test(
	'the list of missing pages keeps at most 50, dropping the oldest',
	function () {
		$urls = array();
		for ( $i = 1; $i <= 55; $i++ ) {
			$urls[] = 'https://x.test/p' . $i;
		}
		tsosi_front_missing_add( $urls );
		tsosi_assert_same( 50, count( tsosi_front_missing_get() ) );
	}
);

tsosi_test(
	'a complete read is reused by the next scan: no request at all',
	function () {
		tsosi_test_small_site( false );
		$dir = tsosi_test_uploads();
		tsosi_usage_frontend_load();
		tsosi_assert_true( count( $GLOBALS['tsosi_test']['requested'] ) > 0, 'first scan reads the pages' );

		$GLOBALS['tsosi_test']['requested'] = array();
		$again                              = tsosi_usage_frontend_load();
		tsosi_assert_same( array(), $GLOBALS['tsosi_test']['requested'], 'second scan reuses the read' );
		tsosi_assert_same( true, $again['complete'] );
		tsosi_assert_true( count( $again['html'] ) > 0, 'with the page HTML' );
	}
);

tsosi_test(
	'the read is not reused after active plugins, widgets or content change',
	function () {
		foreach ( array(
			array( 'active_plugins', array( 'a/a.php' ) ),
			array( 'sidebars_widgets', array( 'sidebar-1' => array( 'x-1' ) ) ),
			array( 'theme_mods_test-theme', array( 'color' => 'red' ) ),
		) as $change ) {
			tsosi_test_reset();
			tsosi_test_small_site( false );
			$dir = tsosi_test_uploads();
			tsosi_usage_frontend_load();
			$GLOBALS['tsosi_test']['options'][ $change[0] ] = $change[1];
			$GLOBALS['tsosi_test']['requested']             = array();
			tsosi_usage_frontend_load();
			tsosi_assert_true( count( $GLOBALS['tsosi_test']['requested'] ) > 0, $change[0] . ' changed: pages are read again' );
			}
	}
);

tsosi_test(
	'a cache older than the time to live is ignored',
	function () {
		$dir = tsosi_test_uploads();
		tsosi_assert_true( tsosi_front_cache_write( 'k', array( 'html' => array(), 'complete' => true ) ) );
		tsosi_assert_true( is_array( tsosi_front_cache_read( 'k', 120 ) ), 'fresh' );
		tsosi_assert_same( null, tsosi_front_cache_read( 'other-key', 120 ), 'different fingerprint' );
		tsosi_assert_same( null, tsosi_front_cache_read( 'k', -1 ), 'expired' );
	}
);

tsosi_test(
	'an incomplete read (server error) is never cached, so the next scan retries',
	function () {
		tsosi_test_small_site( false );
		$GLOBALS['tsosi_test']['http']['https://x.test/?p=12'] = array(
			'code' => 503,
			'body' => 'down',
		);
		$dir  = tsosi_test_uploads();
		$read = tsosi_usage_frontend_load();
		tsosi_assert_same( false, $read['complete'] );
		tsosi_assert_same( false, is_file( $dir . '/tso-stack-inspector/front-snapshot.json.gz' ), 'nothing cached' );
	}
);

tsosi_test(
	'after a 404 page is discovered the plan changes once, then the read is reused',
	function () {
		tsosi_test_small_site();
		tsosi_test_uploads();
		tsosi_usage_frontend_load();
		$GLOBALS['tsosi_test']['requested'] = array();
		tsosi_usage_frontend_load();
		tsosi_assert_true( count( $GLOBALS['tsosi_test']['requested'] ) > 0, 'plan changed (404 page dropped): read once more' );
		$GLOBALS['tsosi_test']['requested'] = array();
		tsosi_usage_frontend_load();
		tsosi_assert_same( array(), $GLOBALS['tsosi_test']['requested'], 'now stable: reused' );
	}
);
