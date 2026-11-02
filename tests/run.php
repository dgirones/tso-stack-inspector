<?php
/**
 * Tiny dependency-free test runner.
 *
 * Usage: php tests/run.php
 * Exits with 1 when any test fails.
 *
 * @package TSO_Stack_Inspector
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['tsosi_test_results'] = array(
	'passed'   => 0,
	'failures' => array(),
);

/**
 * Register and run one test.
 *
 * @param string   $name Test name.
 * @param callable $body Test body; throws on failure.
 * @return void
 */
function tsosi_test( $name, $body ) {
	tsosi_test_reset();
	try {
		$body();
		++$GLOBALS['tsosi_test_results']['passed'];
		echo '  ok   ' . $name . "\n";
	} catch ( Throwable $e ) {
		$GLOBALS['tsosi_test_results']['failures'][] = $name . ': ' . $e->getMessage();
		echo '  FAIL ' . $name . "\n       " . $e->getMessage() . "\n";
	}
}

/**
 * Assert two values are identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Context.
 * @return void
 */
function tsosi_assert_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( trim( $message . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) ); // phpcs:ignore
	}
}

/**
 * Assert a condition is true.
 *
 * @param bool   $condition Condition.
 * @param string $message   Context.
 * @return void
 */
function tsosi_assert_true( $condition, $message = '' ) {
	tsosi_assert_same( true, (bool) $condition, $message );
}

/**
 * Build a usage signal list entry (id + severity) for assertions.
 *
 * @param array<int,array<string,mixed>> $signals Signals.
 * @return array<string,string> id => severity
 */
function tsosi_test_signal_map( $signals ) {
	$map = array();
	foreach ( $signals as $signal ) {
		$map[ $signal['id'] ] = $signal['severity'];
	}
	return $map;
}

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	echo basename( $file ) . "\n";
	require $file;
}

$results = $GLOBALS['tsosi_test_results'];
echo "\n" . $results['passed'] . ' passed, ' . count( $results['failures'] ) . " failed\n";
exit( empty( $results['failures'] ) ? 0 : 1 );
