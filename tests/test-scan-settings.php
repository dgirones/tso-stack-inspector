<?php
/**
 * Scan settings: which keys decide whether the content index must be rebuilt.
 *
 * @package TSO_Stack_Inspector
 */

tsosi_test(
	'ignore list and history size do not change the content scope',
	function () {
		$a = tsosi_get_default_scan_settings();
		$b = $a;
		$b['ignore_tokens'] = array( 'foo' );
		$b['history_max']   = 5;
		tsosi_assert_same( tsosi_scan_content_settings( $a ), tsosi_scan_content_settings( $b ) );
	}
);

tsosi_test(
	'status order is irrelevant but a different status or flag changes the content scope',
	function () {
		$a                  = tsosi_get_default_scan_settings();
		$b                  = $a;
		$b['post_statuses'] = array_reverse( $a['post_statuses'] );
		tsosi_assert_same( tsosi_scan_content_settings( $a ), tsosi_scan_content_settings( $b ) );

		$c                  = $a;
		$c['post_statuses'] = array( 'publish' );
		tsosi_assert_true( tsosi_scan_content_settings( $a ) !== tsosi_scan_content_settings( $c ), 'statuses matter' );

		$d                 = $a;
		$d['include_menus'] = false;
		tsosi_assert_true( tsosi_scan_content_settings( $a ) !== tsosi_scan_content_settings( $d ), 'flags matter' );
	}
);
