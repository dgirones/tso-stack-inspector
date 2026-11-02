<?php
/**
 * Minimal test bootstrap: WordPress function stubs so the pure logic of the inspector can
 * run with plain `php tests/run.php` (no WordPress, database or Composer needed).
 *
 * Stubs return canned data from $GLOBALS['tsosi_test'] so each test sets its own scenario.
 * This directory is excluded from the WordPress.org ZIP.
 *
 * @package TSO_Stack_Inspector
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'TSOSI_VERSION', 'test' );
define( 'TSOSI_USER_META_UI_LANG', 'tsosi_ui_lang' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['tsosi_test'] = array();

/**
 * Reset the canned WordPress state between tests.
 *
 * @return void
 */
function tsosi_test_reset() {
	$GLOBALS['tsosi_test']                = array(
		'options' => array(),
		'http'    => array(),
		'types'   => array(),
	);
	$GLOBALS['wp_registered_sidebars']    = array();
	$GLOBALS['wpdb']                      = new Tsosi_Test_Wpdb();
}

/**
 * Fake $wpdb that answers get_results() from canned rows keyed by post type.
 */
class Tsosi_Test_Wpdb {
	/** @var string */
	public $posts = 'wp_posts';
	/** @var string */
	public $term_taxonomy = 'wp_term_taxonomy';
	/** @var array<string,array<int,array<string,mixed>>> */
	public $rows = array();

	/**
	 * Keep the argument so get_results() knows which post type was asked for.
	 *
	 * @param string $query SQL.
	 * @param mixed  $arg   Single argument.
	 * @return string
	 */
	public function prepare( $query, $arg = '' ) {
		return 'type=' . $arg;
	}

	/**
	 * Canned rows for the post type named in the prepared query.
	 *
	 * @param string $query Prepared query.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( $query ) {
		$type = substr( (string) $query, 5 );
		return isset( $this->rows[ $type ] ) ? $this->rows[ $type ] : array();
	}

	/**
	 * Taxonomy counts are not under test here.
	 *
	 * @return int
	 */
	public function get_var() {
		return 0;
	}
}

class WP_Error {}

function add_action() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $item ) use ( $field ) {
			return $item[ $field ];
		},
		$list
	);
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore
}
function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['tsosi_test']['options'] ) ? $GLOBALS['tsosi_test']['options'][ $name ] : $default_value;
}
function get_user_locale() {
	return 'en_US';
}
function get_locale() {
	return 'en_US';
}
function get_current_user_id() {
	return 0;
}
function get_user_meta() {
	return '';
}
function get_post_type_object( $type ) {
	return isset( $GLOBALS['tsosi_test']['types'][ $type ] ) ? (object) $GLOBALS['tsosi_test']['types'][ $type ] : null;
}
function get_post_types() {
	return array( 'post', 'page' );
}
function get_taxonomies() {
	return array( 'category' );
}
function wp_remote_get( $url ) {
	$http = $GLOBALS['tsosi_test']['http'];
	return isset( $http[ $url ] ) ? $http[ $url ] : new WP_Error();
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

require_once __DIR__ . '/../includes/tsosi-i18n.php';
require_once __DIR__ . '/../includes/tsosi-plugin-usage.php';
require_once __DIR__ . '/../includes/tsosi-scanner.php';

tsosi_test_reset();
