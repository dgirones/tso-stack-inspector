<?php
/**
 * Widgets: active vs inactive placement.
 *
 * @package TSO_Stack_Inspector
 */

/**
 * Set a widget layout: two registered sidebars and the stored assignments.
 *
 * @return void
 */
function tsosi_test_widget_layout() {
	$GLOBALS['wp_registered_sidebars']           = array(
		'sidebar-1' => array( 'name' => 'Main sidebar' ),
		'footer-1'  => array( 'name' => 'Footer' ),
	);
	$GLOBALS['tsosi_test']['options']['sidebars_widgets'] = array(
		'array_version'       => 3,
		'wp_inactive_widgets' => array( 'myplugin_box-2', 'search-3', 'other_thing-1' ),
		'sidebar-1'           => array( 'myplugin_box-5', 'text-2' ),
		'footer-1'            => array(),
		'old_theme_sidebar'   => array( 'myplugin_box-9' ),
	);
}

tsosi_test(
	'inactive map covers the inactive area and sidebars the theme no longer registers',
	function () {
		tsosi_test_widget_layout();
		$map = tsosi_scan_get_inactive_widget_map();
		tsosi_assert_same( 'wp_inactive_widgets', $map['myplugin_box-2'] );
		tsosi_assert_same( 'old_theme_sidebar', $map['myplugin_box-9'] );
		tsosi_assert_true( ! isset( $map['myplugin_box-5'] ), 'a widget in a live sidebar is not inactive' );
	}
);

tsosi_test(
	'active map ignores inactive and unregistered placements (unchanged behaviour)',
	function () {
		tsosi_test_widget_layout();
		$map = tsosi_scan_get_widget_sidebar_map();
		tsosi_assert_same( array( 'myplugin_box-5' => 'sidebar-1', 'text-2' => 'sidebar-1' ), $map );
	}
);

tsosi_test(
	'inactive instances are matched by prefix and core widgets are never attributed',
	function () {
		tsosi_test_widget_layout();
		tsosi_assert_same( array( 'myplugin_box-2', 'myplugin_box-9' ), tsosi_scan_inactive_widget_instances( array( 'myplugin_box' ) ) );
		tsosi_assert_same( array(), tsosi_scan_inactive_widget_instances( array( 'search' ) ), 'core search widget' );
	}
);

tsosi_test(
	'inactive widgets are informational only',
	function () {
		tsosi_test_widget_layout();
		$signals = tsosi_usage_collect_inactive_widgets( array( 'myplugin_box' ) );
		tsosi_assert_same( array( 'widgets_inactive' => 'info' ), tsosi_test_signal_map( $signals ) );
		tsosi_assert_same( array(), tsosi_usage_collect_inactive_widgets( array( 'nothing_here' ) ) );
	}
);
