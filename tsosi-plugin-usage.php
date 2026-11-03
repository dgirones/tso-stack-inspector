<?php
/**
 * "Is this plugin still in use?" signals.
 *
 * The content scan (shortcodes, blocks, meta and option prefixes) only proves that
 * *content* still references a plugin. A plugin can be in full use without a single
 * shortcode in any post: it is simply active, it hooks into the front end, it owns a
 * custom post type with published entries, it created its own tables, the theme or
 * another plugin calls its functions, it is a dependency of another plugin, and so on.
 * This file collects those additional traces so the "safe to uninstall" verdict is never
 * given to a plugin that is running.
 *
 * Every collector returns plain signals (see tsosi_usage_signal()) that are merged into
 * the scan risk by tsosi_usage_apply_risk().
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Weights and confidence of the verdict. Loaded here so this file never runs without them.
require_once TSOSI_PATH . 'includes/tsosi-usage-verdict.php';

if ( ! defined( 'TSOSI_USAGE_CROSS_TIME_BUDGET' ) ) {
	define( 'TSOSI_USAGE_CROSS_TIME_BUDGET', 6.0 );
}

if ( ! defined( 'TSOSI_USAGE_CROSS_MAX_FILE_BYTES' ) ) {
	define( 'TSOSI_USAGE_CROSS_MAX_FILE_BYTES', 300000 );
}

// Public pages read to look for a plugin's footprint, and the total time allowed for it.
if ( ! defined( 'TSOSI_USAGE_FRONTEND_MAX_URLS' ) ) {
	define( 'TSOSI_USAGE_FRONTEND_MAX_URLS', 12 );
}

if ( ! defined( 'TSOSI_USAGE_FRONTEND_TIME_BUDGET' ) ) {
	define( 'TSOSI_USAGE_FRONTEND_TIME_BUDGET', 15.0 );
}

// A non-public content type untouched for this many days is "dormant" (stored data, not live content).
if ( ! defined( 'TSOSI_USAGE_STALE_DAYS' ) ) {
	define( 'TSOSI_USAGE_STALE_DAYS', 730 );
}

/**
 * Build one usage signal.
 *
 * @param string   $id       Stable signal id.
 * @param string   $severity danger|review|info.
 * @param string   $label    Human label (already translated).
 * @param string[] $items    Evidence lines.
 * @param string   $summary  Short reason used in the banner (defaults to the label).
 * @return array<string,mixed>
 */
function tsosi_usage_signal( $id, $severity, $label, $items = array(), $summary = '' ) {
	$items    = array_values( array_unique( array_filter( array_map( 'strval', (array) $items ) ) ) );
	$severity = in_array( $severity, array( 'danger', 'review', 'info' ), true ) ? $severity : 'info';
	$profile  = tsosi_usage_signal_profile( $id, $severity );
	return array(
		'id'       => sanitize_key( $id ),
		'severity' => $severity,
		'weight'   => $profile['weight'],
		'kind'     => $profile['kind'],
		'label'    => $label,
		'summary'  => '' !== $summary ? $summary : $label,
		'items'    => array_slice( $items, 0, 12 ),
		'more'     => max( 0, count( $items ) - 12 ),
	);
}

/**
 * Folder slug (or bare file name for single-file plugins).
 *
 * @param string $plugin_file Plugin basename.
 * @return string
 */
function tsosi_usage_plugin_slug( $plugin_file ) {
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	if ( '' !== $folder ) {
		return $folder;
	}
	return basename( (string) $plugin_file, '.php' );
}

/**
 * Resolve the file that defines a callback (closures, "Class::method" strings,
 * [object|class, method] arrays and invokable objects included).
 *
 * @param mixed $callback Callback.
 * @return string Normalized path, or '' when it cannot be resolved.
 */
function tsosi_usage_callback_file( $callback ) {
	try {
		if ( $callback instanceof Closure ) {
			$ref = new ReflectionFunction( $callback );
			return wp_normalize_path( (string) $ref->getFileName() );
		}
		if ( is_string( $callback ) ) {
			if ( false !== strpos( $callback, '::' ) ) {
				$parts = explode( '::', $callback, 2 );
				if ( class_exists( $parts[0], false ) ) {
					$ref = new ReflectionClass( $parts[0] );
					return wp_normalize_path( (string) $ref->getFileName() );
				}
				return '';
			}
			if ( function_exists( $callback ) ) {
				$ref = new ReflectionFunction( $callback );
				return wp_normalize_path( (string) $ref->getFileName() );
			}
			return '';
		}
		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			if ( is_object( $callback[0] ) ) {
				$ref = new ReflectionClass( $callback[0] );
				return wp_normalize_path( (string) $ref->getFileName() );
			}
			if ( is_string( $callback[0] ) && class_exists( $callback[0], false ) ) {
				$ref = new ReflectionClass( $callback[0] );
				return wp_normalize_path( (string) $ref->getFileName() );
			}
			return '';
		}
		if ( is_object( $callback ) ) {
			$ref = new ReflectionClass( $callback );
			return wp_normalize_path( (string) $ref->getFileName() );
		}
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- an unloadable callback simply cannot be attributed.
		unset( $e );
	}
	return '';
}

/**
 * Whether a path belongs to a bundled third-party library (composer vendor, prefixed
 * vendor copies, node_modules). Those libraries are not the plugin's own API and are
 * often shipped by several plugins at once.
 *
 * @param string $path File path.
 * @return bool
 */
function tsosi_usage_is_vendor_path( $path ) {
	return 1 === preg_match( '~/(?:vendor|vendor-prefixed|vendors|third[-_]party|node_modules)/~i', wp_normalize_path( (string) $path ) );
}

/**
 * Product family of a plugin: "fileorganizer" for both fileorganizer and fileorganizer-pro.
 * Free/Pro editions ship the same functions, so one is not a dependency of the other.
 *
 * @param string $plugin_file Plugin basename.
 * @return string
 */
function tsosi_usage_plugin_family( $plugin_file ) {
	$slug = strtolower( tsosi_usage_plugin_slug( $plugin_file ) );
	$slug = preg_replace( '/[-_](?:pro|premium|lite|free|plus|business|enterprise|core)$/', '', $slug );
	return (string) preg_replace( '/^(?:pro|premium)[-_]/', '', (string) $slug );
}

/**
 * Static facts read from a plugin's own PHP sources (memoized per request).
 *
 * @param string $plugin_file Plugin basename.
 * @return array<string,mixed>
 */
function tsosi_usage_static_facts( $plugin_file ) {
	static $memo = array();
	if ( isset( $memo[ $plugin_file ] ) ) {
		return $memo[ $plugin_file ];
	}

	$facts = array(
		'post_types'      => array(),
		'taxonomies'      => array(),
		'roles'           => array(),
		'tables'          => array(),
		'cron_hooks'      => array(),
		'tokens'          => array(),
		'namespaces'      => array(),
		'builder_widgets' => array(),
	);

	$files = tsosi_profile_plugin_php_files( $plugin_file );
	$bytes = 0;
	$funcs = array();
	$cls   = array();
	$const = array();
	$poly  = array();

	foreach ( $files as $path ) {
		if ( tsosi_usage_is_vendor_path( $path ) ) {
			continue;
		}
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		$bytes += strlen( $content );
		if ( $bytes > 16000000 ) {
			break;
		}

		$consts = tsosi_usage_file_consts( $content );

		$facts['post_types'] = array_merge( $facts['post_types'], tsosi_usage_first_arg( $content, 'register_post_type', $consts ) );
		$facts['taxonomies'] = array_merge( $facts['taxonomies'], tsosi_usage_first_arg( $content, 'register_taxonomy', $consts ) );
		$facts['roles']      = array_merge( $facts['roles'], tsosi_usage_first_arg( $content, 'add_role', $consts ) );

		if ( preg_match_all( '/\bwp_(?:next_scheduled|clear_scheduled_hook)\s*\(\s*([\'"])([A-Za-z0-9_\-]+)\1/', $content, $m ) ) {
			$facts['cron_hooks'] = array_merge( $facts['cron_hooks'], $m[2] );
		}
		if ( preg_match_all( '/\bwp_schedule_(?:single_)?event\s*\([^;]{0,160}?([\'"])([A-Za-z0-9_\-]{6,})\1\s*[,)]/', $content, $m ) ) {
			$facts['cron_hooks'] = array_merge( $facts['cron_hooks'], $m[2] );
		}

		$facts['tables'] = array_merge( $facts['tables'], tsosi_usage_tables_from_source( $content ) );

		// Elementor widgets: the widget name is what _elementor_data stores as widgetType.
		if ( false !== strpos( $content, 'Widget_Base' ) && preg_match_all( '/function\s+get_name\s*\(\s*\)\s*(?::\s*\??string\s*)?\{\s*return\s*([\'"])([a-z0-9_-]+)\1/i', $content, $m ) ) {
			$facts['builder_widgets'] = array_merge( $facts['builder_widgets'], $m[2] );
		}

		// Compatibility shims (if ( ! function_exists( 'x' ) ) { function x() {} }) are not this plugin's API.
		if ( preg_match_all( '/!\s*(?:function|class|interface)_exists\s*\(\s*([\'"])([A-Za-z0-9_]+)\1/', $content, $m ) ) {
			$poly = array_merge( $poly, $m[2] );
		}
		// Same for constants that are only defined as a fallback (guarded by defined()).
		if ( preg_match_all( '/!\s*defined\s*\(\s*([\'"])([A-Za-z0-9_]+)\1/', $content, $m ) ) {
			$poly = array_merge( $poly, $m[2] );
		}
		if ( preg_match_all( '/defined\s*\(\s*([\'"])([A-Za-z0-9_]+)\1\s*\)\s*\|\|/', $content, $m ) ) {
			$poly = array_merge( $poly, $m[2] );
		}
		// Public symbols other code could call. Only unambiguous declarations are kept:
		// guarded function_exists()/class_exists() literals, column-0 declarations,
		// namespaces and define()d constants. Methods are deliberately ignored.
		if ( preg_match_all( '/function_exists\s*\(\s*([\'"])([A-Za-z0-9_]{8,})\1/', $content, $m ) ) {
			foreach ( $m[2] as $maybe ) {
				// Only a guarded declaration counts; a bare function_exists() check is a dependency on someone else's code.
				if ( preg_match( '/\bfunction\s+&?\s*' . preg_quote( $maybe, '/' ) . '\s*\(/', $content ) ) {
					$funcs[] = $maybe;
				}
			}
		}
		if ( preg_match_all( '/^function\s+&?\s*([A-Za-z_][A-Za-z0-9_]{7,})\s*\(/m', $content, $m ) ) {
			$funcs = array_merge( $funcs, $m[1] );
		}
		$namespace = '';
		if ( preg_match( '/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $content, $m ) ) {
			$namespace = $m[1];
			if ( false !== strpos( $namespace, '\\' ) || strlen( $namespace ) >= 8 ) {
				$facts['namespaces'][] = rtrim( $namespace, '\\' ) . '\\';
			}
		}
		if ( '' === $namespace ) {
			if ( preg_match_all( '/^(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $m ) ) {
				foreach ( $m[1] as $name ) {
					if ( strlen( $name ) >= 8 || false !== strpos( $name, '_' ) ) {
						$cls[] = $name;
					}
				}
			}
			if ( preg_match_all( '/class_exists\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_]{5,})\1/', $content, $m ) ) {
				foreach ( $m[2] as $maybe ) {
					if ( preg_match( '/\b(?:class|interface|trait)\s+' . preg_quote( $maybe, '/' ) . '\b/', $content ) ) {
						$cls[] = $maybe;
					}
				}
			}
		}
		if ( preg_match_all( '/\bdefine\s*\(\s*([\'"])([A-Z][A-Z0-9_]{7,})\1/', $content, $m ) ) {
			$const = array_merge( $const, $m[2] );
		}
	}

	tsosi_profile_memo_flush_sources();

	foreach ( array( 'post_types', 'taxonomies', 'roles', 'cron_hooks', 'builder_widgets' ) as $key ) {
		$facts[ $key ] = array_values( array_unique( array_filter( $facts[ $key ] ) ) );
	}
	$facts['namespaces'] = array_values( array_unique( array_filter( $facts['namespaces'] ) ) );

	// Polyfills of WordPress/PHP symbols (guarded by function_exists()/class_exists()) are not this plugin's API.
	$funcs = array_diff( $funcs, $poly );
	$cls   = array_diff( $cls, $poly );
	$funcs = array_filter( $funcs, 'tsosi_usage_is_not_core_symbol' );
	$cls   = array_filter( $cls, 'tsosi_usage_is_not_core_symbol' );

	// WordPress/PHP constants (SAVEQUERIES, MB_CASE_UPPER...) are not this plugin's API even when it defines them as a fallback.
	$const = array_diff( $const, $poly );
	$const = array_filter(
		$const,
		static function ( $name ) {
			return 1 !== preg_match( '/^(?:SAVEQUERIES|WPINC|ABSPATH|MB_[A-Z0-9_]+|PHP_[A-Z0-9_]+|JSON_[A-Z0-9_]+|ENT_[A-Z0-9_]+|E_[A-Z0-9_]+|DOING_[A-Z0-9_]+)$/', (string) $name );
		}
	);

	// A token that is just the plugin's own name is not an API (it shows up in name lists and slugs).
	$own_name = preg_replace( '/[^a-z0-9]/', '', strtolower( tsosi_usage_plugin_slug( $plugin_file ) ) );
	$not_own  = static function ( $name ) use ( $own_name ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) ) !== $own_name;
	};
	$funcs    = array_filter( $funcs, $not_own );
	$cls      = array_filter( $cls, $not_own );

	$tokens = array_merge( $funcs, $cls, $const );
	$tokens = array_values( array_unique( array_filter( $tokens ) ) );
	// The shortest generic names are the most likely to collide with unrelated code.
	usort(
		$tokens,
		static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	$facts['tokens'] = array_slice( $tokens, 0, 80 );

	$facts['tables'] = array_values( array_unique( array_filter( $facts['tables'] ) ) );

	$memo[ $plugin_file ] = $facts;
	return $facts;
}

/**
 * Whether a function/class name is NOT defined by WordPress core or PHP itself.
 *
 * @param string $name Function or class name.
 * @return bool
 */
function tsosi_usage_is_not_core_symbol( $name ) {
	$name = (string) $name;
	$file = null;
	try {
		if ( function_exists( $name ) ) {
			$file = ( new ReflectionFunction( $name ) )->getFileName();
		} elseif ( class_exists( $name, false ) || interface_exists( $name, false ) || trait_exists( $name, false ) ) {
			$file = ( new ReflectionClass( $name ) )->getFileName();
		} else {
			return true;
		}
	} catch ( Throwable $e ) {
		return true;
	}
	if ( false === $file || null === $file ) {
		return false;
	}
	$file = wp_normalize_path( $file );
	return 0 !== strpos( $file, wp_normalize_path( ABSPATH . 'wp-includes/' ) ) && 0 !== strpos( $file, wp_normalize_path( ABSPATH . 'wp-admin/' ) );
}

/**
 * Constants with a plain string value declared in a source file (class const / define()).
 *
 * @param string $content Source.
 * @return array<string,string>
 */
function tsosi_usage_file_consts( $content ) {
	$consts = array();
	if ( preg_match_all( '/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*([\'"])([^\'"\\\\]+)\2\s*;/', $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $row ) {
			$consts[ $row[1] ] = $row[3];
		}
	}
	if ( preg_match_all( '/\bdefine\s*\(\s*([\'"])([A-Za-z0-9_]+)\1\s*,\s*([\'"])([^\'"\\\\]*)\3\s*\)/', $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $row ) {
			$consts[ $row[2] ] = $row[4];
		}
	}
	return $consts;
}

/**
 * First argument (literal or class constant) of every call to $func_name.
 *
 * @param string               $content  Source.
 * @param string               $func_name Function name.
 * @param array<string,string> $consts   Known constants.
 * @return string[]
 */
function tsosi_usage_first_arg( $content, $func_name, $consts ) {
	$out     = array();
	$pattern = '/\b' . preg_quote( $func_name, '/' ) . '\s*\(\s*(?:([\'"])([A-Za-z0-9_-]+)\1|(?:[A-Za-z_\\\\]+::)?([A-Z][A-Z0-9_]{2,}))/';
	if ( preg_match_all( $pattern, $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $row ) {
			if ( ! empty( $row[2] ) ) {
				$out[] = $row[2];
			} elseif ( ! empty( $row[3] ) && isset( $consts[ $row[3] ] ) ) {
				$out[] = $consts[ $row[3] ];
			}
		}
	}
	return array_values( array_unique( array_map( 'sanitize_key', $out ) ) );
}

/**
 * Table names (without prefix) created or dropped by a source file.
 *
 * @param string $content Source.
 * @return string[]
 */
function tsosi_usage_tables_from_source( $content ) {
	if ( false === stripos( $content, 'CREATE TABLE' ) && false === stripos( $content, 'DROP TABLE' ) ) {
		return array();
	}

	$vars = array();
	if ( preg_match_all( '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$(?:this->)?wpdb->(?:base_)?prefix\s*\.\s*([\'"])([a-z0-9_]+)\2/i', $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $row ) {
			$vars[ $row[1] ] = strtolower( $row[3] );
		}
	}
	if ( preg_match_all( '/\$(?:this->)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"?\{\$(?:this->)?wpdb->(?:base_)?prefix\}([a-z0-9_]+)/i', $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $row ) {
			$vars[ $row[1] ] = strtolower( $row[2] );
		}
	}

	$names = array();
	if ( preg_match_all( '/(?:CREATE\s+TABLE|DROP\s+TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(?=(.{0,160}))/is', $content, $m ) ) {
		foreach ( $m[1] as $chunk ) {
			// Anchored at the start of the chunk: the next statement may follow within the window.
			if ( preg_match( '/^\s*`?\{?\$(?:this->)?wpdb->(?:base_)?prefix\}?\s*\.?\s*[\'"]?([a-z0-9_]+)/i', $chunk, $t ) ) {
				$names[] = strtolower( $t[1] );
				continue;
			}
			if ( preg_match( '/^\s*`?\{?\$(?:this->)?([A-Za-z_][A-Za-z0-9_]*)\}?/', $chunk, $t ) && isset( $vars[ $t[1] ] ) ) {
				$names[] = $vars[ $t[1] ];
			}
		}
	}

	$clean = array();
	foreach ( $names as $name ) {
		$name = preg_replace( '/[^a-z0-9_]/', '', (string) $name );
		if ( is_string( $name ) && strlen( $name ) >= 3 ) {
			$clean[] = $name;
		}
	}
	return array_values( array_unique( $clean ) );
}

/**
 * Activation / execution state: active on this site, network-active, active on other
 * sites of the network, paused, or loaded by other code (mu-plugin loader, include).
 *
 * @param string $plugin_file Plugin basename.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_activation( $plugin_file ) {
	$signals = array();

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$active  = tsosi_is_plugin_active_file( $plugin_file );
	$network = is_multisite() && is_plugin_active_for_network( $plugin_file );

	if ( $network ) {
		$signals[] = tsosi_usage_signal(
			'active',
			'info',
			tsosi_ui_triple_text(
				'Network-active (it is only activated: it must be deactivated before uninstalling)',
				'Activo en toda la red (solo está activado: hay que desactivarlo antes de desinstalar)',
				'Actiu a tota la xarxa (només està activat: cal desactivar-lo abans de desinstal·lar)'
			),
			array(),
			tsosi_ui_triple_text( 'network-active', 'activo en la red', 'actiu a la xarxa' )
		);
	} elseif ( $active ) {
		$signals[] = tsosi_usage_signal(
			'active',
			'info',
			tsosi_ui_triple_text(
				'The plugin is activated (this alone does not mean the site uses it; deactivate it before uninstalling)',
				'El plugin está activado (esto solo no significa que el sitio lo use; desactívalo antes de desinstalar)',
				'El plugin està activat (només això no vol dir que el lloc l\'usi; desactiva\'l abans de desinstal·lar)'
			),
			array(),
			tsosi_ui_triple_text( 'the plugin is active', 'el plugin está activo', 'el plugin està actiu' )
		);
	}

	if ( is_multisite() && ! $network && function_exists( 'get_sites' ) ) {
		$others = array();
		$sites  = get_sites(
			array(
				'fields' => 'ids',
				'number' => 200,
			)
		);
		foreach ( (array) $sites as $site_id ) {
			$site_id = (int) $site_id;
			if ( get_current_blog_id() === $site_id ) {
				continue;
			}
			$list = get_blog_option( $site_id, 'active_plugins', array() );
			if ( is_array( $list ) && in_array( $plugin_file, $list, true ) ) {
				$name     = (string) get_blog_option( $site_id, 'blogname', '' );
				$others[] = ( '' !== $name ? $name : 'site' ) . ' (#' . $site_id . ')';
			}
		}
		if ( ! empty( $others ) ) {
			$signals[] = tsosi_usage_signal(
				'active_other_sites',
				'danger',
				tsosi_ui_triple_text(
					'Active on other sites of this network',
					'Activo en otros sitios de esta red',
					'Actiu en altres llocs d\'aquesta xarxa'
				),
				$others,
				tsosi_ui_triple_text( 'active on other network sites', 'activo en otros sitios de la red', 'actiu en altres llocs de la xarxa' )
			);
		}
	}

	if ( ! $active && ! $network ) {
		$loaded = tsosi_usage_loaded_files( $plugin_file );
		if ( ! empty( $loaded ) ) {
			$signals[] = tsosi_usage_signal(
				'loaded_by_code',
				'danger',
				tsosi_ui_triple_text(
					'Not activated, but its PHP files are being loaded by other code (mu-plugin, include, loader)',
					'No está activado, pero otro código está cargando sus archivos PHP (mu-plugin, include, cargador)',
					'No està activat, però un altre codi està carregant els seus fitxers PHP (mu-plugin, include, carregador)'
				),
				$loaded,
				tsosi_ui_triple_text( 'its files are loaded by other code', 'otro código carga sus archivos', 'un altre codi en carrega els fitxers' )
			);
		}
	}

	if ( function_exists( 'wp_paused_plugins' ) ) {
		$paused = wp_paused_plugins()->get_all();
		$slug   = tsosi_usage_plugin_slug( $plugin_file );
		if ( isset( $paused[ $slug ] ) || isset( $paused[ $plugin_file ] ) ) {
			$signals[] = tsosi_usage_signal(
				'paused',
				'review',
				tsosi_ui_triple_text(
					'WordPress paused this plugin after a fatal error',
					'WordPress ha pausado este plugin tras un error fatal',
					'WordPress ha pausat aquest plugin després d\'un error fatal'
				)
			);
		}
	}

	return $signals;
}

/**
 * PHP files of the plugin that were included during this request.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[] Relative paths.
 */
function tsosi_usage_loaded_files( $plugin_file ) {
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	$root   = '' === $folder
		? wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . basename( (string) $plugin_file ) )
		: wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $folder . '/' );
	$out    = array();
	foreach ( get_included_files() as $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( 0 === strpos( $path, $root ) ) {
			$out[] = ltrim( substr( $path, strlen( wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) ) ) ), '/' );
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
	}
	return $out;
}

/**
 * Plugins that declare this one as a dependency ("Requires Plugins" header).
 *
 * @param string $plugin_file Plugin basename.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_dependents( $plugin_file ) {
	$slug       = tsosi_usage_plugin_slug( $plugin_file );
	$dependents = array();
	$any_active = false;

	foreach ( tsosi_get_installed_plugins() as $file => $header ) {
		if ( $file === $plugin_file ) {
			continue;
		}
		$requires = isset( $header['RequiresPlugins'] ) ? (string) $header['RequiresPlugins'] : '';
		if ( '' === $requires ) {
			continue;
		}
		$slugs = array_map( 'trim', explode( ',', strtolower( $requires ) ) );
		if ( ! in_array( strtolower( $slug ), $slugs, true ) ) {
			continue;
		}
		$is_active    = tsosi_is_plugin_active_file( $file );
		$any_active   = $any_active || $is_active;
		$name         = isset( $header['Name'] ) ? (string) $header['Name'] : $file;
		$dependents[] = $name . ( $is_active ? '' : ' (' . tsosi_ui_triple_text( 'inactive', 'inactivo', 'inactiu' ) . ')' );
	}

	if ( empty( $dependents ) ) {
		return array();
	}

	return array(
		tsosi_usage_signal(
			'dependents',
			$any_active ? 'danger' : 'review',
			tsosi_ui_triple_text(
				'Other plugins declare it as a required dependency',
				'Otros plugins lo declaran como dependencia obligatoria',
				'Altres plugins el declaren com a dependència obligatòria'
			),
			$dependents,
			tsosi_ui_triple_text( 'another plugin requires it', 'otro plugin lo requiere', 'un altre plugin el requereix' )
		),
	);
}

/**
 * Hooks, shortcodes and REST routes the plugin has registered in this request.
 * Only meaningful while the plugin is active (its code is loaded).
 *
 * @param string $plugin_file Plugin basename.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_runtime( $plugin_file ) {
	global $wp_filter;

	if ( '' === tsosi_get_plugin_folder_raw( $plugin_file ) || ! tsosi_is_plugin_active_file( $plugin_file ) ) {
		return array();
	}

	$dir     = wp_normalize_path( trailingslashit( tsosi_get_plugin_dir( $plugin_file ) ) );
	$hooks   = array();
	$ajax    = array();
	$total   = 0;
	$cron    = tsosi_usage_runtime_cron_hooks( $dir );
	$signals = array();

	if ( is_array( $wp_filter ) ) {
		foreach ( $wp_filter as $hook => $wp_hook ) {
			if ( ! $wp_hook instanceof WP_Hook ) {
				continue;
			}
			foreach ( $wp_hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( empty( $cb['function'] ) ) {
						continue;
					}
					$file = tsosi_usage_callback_file( $cb['function'] );
					if ( '' === $file || 0 !== strpos( $file, $dir ) ) {
						continue;
					}
					++$total;
					$hook           = (string) $hook;
					$hooks[ $hook ] = isset( $hooks[ $hook ] ) ? $hooks[ $hook ] + 1 : 1;
					if ( 0 === strpos( $hook, 'wp_ajax_nopriv_' ) ) {
						$ajax[] = substr( $hook, strlen( 'wp_ajax_nopriv_' ) );
					}
				}
			}
		}
	}

	if ( $total > 0 ) {
		arsort( $hooks );
		$items = array();
		foreach ( $hooks as $hook => $count ) {
			$items[] = $hook . ' ×' . $count;
		}
		$signals[] = tsosi_usage_signal(
			'hooks',
			'info',
			sprintf(
				/* translators: 1: callbacks, 2: hooks */
				tsosi_ui_triple_text(
					'It has %1$d callback(s) attached to %2$d WordPress hook(s) in this request',
					'Tiene %1$d callback(s) enganchados a %2$d hook(s) de WordPress en esta petición',
					'Té %1$d callback(s) enganxats a %2$d hook(s) de WordPress en aquesta petició'
				),
				$total,
				count( $hooks )
			),
			$items
		);
	}

	$output_hooks = array_values( array_intersect( array_keys( $hooks ), array( 'the_content', 'the_excerpt', 'get_the_excerpt', 'wp_footer', 'wp_head', 'wp_body_open', 'wp_enqueue_scripts', 'body_class', 'post_class', 'template_redirect', 'comment_form', 'loop_start', 'loop_end', 'pre_get_posts' ) ) );
	if ( ! empty( $output_hooks ) ) {
		$signals[] = tsosi_usage_signal(
			'frontend_hooks',
			'review',
			tsosi_ui_triple_text(
				'It hooks into the output of public pages, so it probably adds something visible to the site (check the home page)',
				'Se engancha a la salida de las páginas públicas, así que probablemente añade algo visible al sitio (revisa la portada)',
				'S\'enganxa a la sortida de les pàgines públiques, així que probablement afegeix alguna cosa visible al lloc (revisa la portada)'
			),
			$output_hooks,
			tsosi_ui_triple_text( 'it hooks into public page output', 'se engancha a la salida pública', 'S\'enganxa a la sortida pública' )
		);
	}

	if ( ! empty( $ajax ) ) {
		$signals[] = tsosi_usage_signal(
			'ajax_public',
			'info',
			tsosi_ui_triple_text(
				'Public AJAX actions (callable by visitors)',
				'Acciones AJAX públicas (las pueden llamar los visitantes)',
				'Accions AJAX públiques (les poden cridar els visitants)'
			),
			$ajax
		);
	}

	if ( ! empty( $cron ) ) {
		$signals[] = tsosi_usage_signal(
			'cron',
			'info',
			tsosi_ui_triple_text(
				'Scheduled (WP-Cron) tasks handled by this plugin',
				'Tareas programadas (WP-Cron) gestionadas por este plugin',
				'Tasques programades (WP-Cron) gestionades per aquest plugin'
			),
			$cron
		);
	}

	$routes = tsosi_usage_runtime_rest_routes( $dir );
	if ( ! empty( $routes ) ) {
		$signals[] = tsosi_usage_signal(
			'rest',
			'info',
			tsosi_ui_triple_text(
				'REST API routes served by this plugin',
				'Rutas de la API REST servidas por este plugin',
				'Rutes de l\'API REST servides per aquest plugin'
			),
			$routes
		);
	}

	$widgets = tsosi_usage_runtime_widget_classes( $dir );
	if ( ! empty( $widgets ) ) {
		$signals[] = tsosi_usage_signal(
			'widget_classes',
			'info',
			tsosi_ui_triple_text(
				'Registers widgets',
				'Registra widgets',
				'Registra widgets'
			),
			$widgets
		);
	}

	return $signals;
}

/**
 * Scheduled events whose hook starts with one of the given prefixes.
 *
 * @param string[] $prefixes Hook prefixes.
 * @return string[]
 */
function tsosi_usage_scheduled_hooks( $prefixes ) {
	$cron = _get_cron_array();
	$out  = array();
	if ( ! is_array( $cron ) ) {
		return $out;
	}
	foreach ( $cron as $events ) {
		if ( ! is_array( $events ) ) {
			continue;
		}
		foreach ( array_keys( $events ) as $hook ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( (string) $hook, $prefix ) ) {
					$out[] = (string) $hook;
				}
			}
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Real-use checks for plugins whose use is not visible in content, widgets or public
 * pages: it lives in how the plugin is set up (editor choice per post, avatars per user,
 * automatic backup schedules).
 *
 * @param string $plugin_file Plugin basename.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_known( $plugin_file ) {
	global $wpdb;
	if ( ! tsosi_is_plugin_active_file( $plugin_file ) ) {
		return array();
	}
	$slug    = tsosi_usage_plugin_slug( $plugin_file );
	$signals = array();

	if ( 'classic-editor' === $slug ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
		$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'classic-editor-remember' AND pm.meta_value = 'classic-editor' AND p.post_status NOT IN ('auto-draft','trash')" );
		$all   = 'classic' === get_option( 'classic-editor-replace', 'classic' );
		if ( $posts > 0 || $all ) {
			$items = array();
			if ( $posts > 0 ) {
				/* translators: %d: number of posts */
				$items[] = sprintf( tsosi_ui_triple_text( '%d post(s) are saved to be edited with the classic editor', '%d entrada(s) se guardaron para editarse con el editor clásico', '%d entrada(es) es van desar per editar-se amb l\'editor clàssic' ), $posts );
			}
			if ( $all ) {
				$items[] = tsosi_ui_triple_text( 'The classic editor is the default editor for the whole site', 'El editor clásico es el editor por defecto de todo el sitio', 'L\'editor clàssic és l\'editor per defecte de tot el lloc' );
			}
			$signals[] = tsosi_usage_signal(
				'classic_in_use',
				'danger',
				tsosi_ui_triple_text( 'The classic editor is in use here: without the plugin these posts open in the block editor', 'El editor clásico se usa aquí: sin el plugin estas entradas se abren con el editor de bloques', 'L\'editor clàssic s\'usa aquí: sense el plugin aquestes entrades s\'obren amb l\'editor de blocs' ),
				$items,
				tsosi_ui_triple_text( 'posts are written with the classic editor', 'hay entradas escritas con el editor clásico', 'hi ha entrades escrites amb l\'editor clàssic' )
			);
		}
	}

	if ( 'one-user-avatar' === $slug ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
		$users = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key LIKE '%user\\_avatar' AND meta_value <> ''" );
		if ( $users > 0 ) {
			$signals[] = tsosi_usage_signal(
				'avatars_in_use',
				'danger',
				tsosi_ui_triple_text( 'Users have avatars set with this plugin: without it they fall back to the default avatar', 'Hay usuarios con avatar configurado con este plugin: sin él pasan al avatar por defecto', 'Hi ha usuaris amb avatar configurat amb aquest plugin: sense ell passen a l\'avatar per defecte' ),
				array( (string) $users ),
				tsosi_ui_triple_text( 'users have avatars set with it', 'hay usuarios con avatar configurado con él', 'hi ha usuaris amb avatar configurat amb ell' )
			);
		}
	}

	if ( 'updraftplus' === $slug ) {
		$files = (string) get_option( 'updraft_interval', 'manual' );
		$db    = (string) get_option( 'updraft_interval_database', 'manual' );
		$plan  = array();
		if ( '' !== $files && 'manual' !== $files ) {
			$plan[] = tsosi_ui_triple_text( 'files', 'archivos', 'fitxers' ) . ': ' . $files;
		}
		if ( '' !== $db && 'manual' !== $db ) {
			$plan[] = tsosi_ui_triple_text( 'database', 'base de datos', 'base de dades' ) . ': ' . $db;
		}
		if ( ! empty( $plan ) ) {
			$signals[] = tsosi_usage_signal(
				'backups_scheduled',
				'review',
				tsosi_ui_triple_text( 'Automatic backups are scheduled. Do not uninstall it if you still need backups', 'Hay copias de seguridad automáticas programadas. No lo desinstales si aún necesitas copias', 'Hi ha còpies de seguretat automàtiques programades. No el desinstal·lis si encara necessites fer còpies' ),
				$plan,
				tsosi_ui_triple_text( 'automatic backups are scheduled', 'hay copias automáticas programadas', 'hi ha còpies automàtiques programades' )
			);
		}
	}

	if ( 'tso-options-tables-cleaner' === $slug ) {
		$hooks = tsosi_usage_scheduled_hooks( array( 'tsootc_', 'tso_options_tables_cleaner' ) );
		if ( ! empty( $hooks ) ) {
			$signals[] = tsosi_usage_signal(
				'cleaning_scheduled',
				'review',
				tsosi_ui_triple_text( 'Scheduled automatic cleaning is active', 'Hay una limpieza automática programada activa', 'Hi ha una neteja automàtica programada activa' ),
				$hooks,
				tsosi_ui_triple_text( 'scheduled cleaning is active', 'hay limpieza programada activa', 'hi ha neteja programada activa' )
			);
		}
	}

	return $signals;
}

/**
 * Pick the pages to read, taking one from each group in turn so every kind of page
 * (home, latest posts, shop, one entry per content type, archives, page templates) gets
 * represented before the cap is reached.
 *
 * @param array<string,string[]> $groups Group name => URLs, most representative first.
 * @param int                    $max    Maximum number of URLs.
 * @return string[]
 */
function tsosi_usage_frontend_plan( $groups, $max ) {
	$max  = max( 1, (int) $max );
	$plan = array();
	$seen = array();
	$more = true;
	for ( $round = 0; $more && count( $plan ) < $max; $round++ ) {
		$more = false;
		foreach ( (array) $groups as $urls ) {
			$urls = array_values( (array) $urls );
			if ( ! isset( $urls[ $round ] ) ) {
				continue;
			}
			$more = true;
			$url  = (string) $urls[ $round ];
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$plan[]       = $url;
			if ( count( $plan ) >= $max ) {
				break;
			}
		}
	}
	return $plan;
}

/**
 * Candidate public pages grouped by kind. The group order is the priority order.
 *
 * @return array<string,string[]>
 */
function tsosi_usage_frontend_candidates() {
	global $wpdb;

	$groups = array(
		'home'      => array( home_url( '/' ) ),
		'latest'    => array(),
		'shop'      => array(),
		'types'     => array(),
		'archives'  => array(),
		'templates' => array(),
	);

	// The 2 latest posts and the latest page, asked separately: when pages are newer than the
	// posts, one mixed "latest 2" query would leave every blog post out of the sample.
	foreach ( array(
		'post' => 2,
		'page' => 1,
	) as $type => $limit ) {
		$ids = get_posts(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( (array) $ids as $post_id ) {
			$link = get_permalink( (int) $post_id );
			if ( is_string( $link ) && '' !== $link ) {
				$groups['latest'][] = $link;
			}
		}
	}

	// Shop, cart, checkout and account pages hold most of what a store plugin outputs.
	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
			$page_id = (int) wc_get_page_id( $page );
			$link    = $page_id > 0 ? get_permalink( $page_id ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$groups['shop'][] = $link;
			}
		}
	}

	// One entry (and the archive, when it has one) of every public custom content type.
	$types = get_post_types(
		array(
			'public'   => true,
			'_builtin' => false,
		),
		'names'
	);
	foreach ( array_slice( array_values( (array) $types ), 0, 10 ) as $type ) {
		$entry = get_posts(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( empty( $entry ) ) {
			continue;
		}
		$link = get_permalink( (int) $entry[0] );
		if ( is_string( $link ) && '' !== $link ) {
			$groups['types'][] = $link;
		}
		$archive = get_post_type_archive_link( $type );
		if ( is_string( $archive ) && '' !== $archive ) {
			$groups['archives'][] = $archive;
		}
	}

	// One published page for each custom page template in use.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
	$templates = $wpdb->get_col(
		"SELECT MIN(p.ID) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_page_template' AND pm.meta_value NOT IN ('', 'default') AND p.post_type = 'page' AND p.post_status = 'publish' AND p.post_password = '' GROUP BY pm.meta_value LIMIT 10"
	);
	foreach ( (array) $templates as $page_id ) {
		$link = get_permalink( (int) $page_id );
		if ( is_string( $link ) && '' !== $link ) {
			$groups['templates'][] = $link;
		}
	}

	return $groups;
}

/**
 * Public pages to read when looking for a plugin's footprint.
 *
 * @return string[]
 */
function tsosi_usage_frontend_urls() {
	$urls = tsosi_usage_frontend_plan( tsosi_usage_frontend_candidates(), TSOSI_USAGE_FRONTEND_MAX_URLS );

	/**
	 * Filter the public pages read to detect plugin output.
	 *
	 * @param string[] $urls Absolute URLs of this site.
	 */
	$urls = apply_filters( 'tsosi_usage_frontend_urls', $urls );
	return array_values( array_unique( array_filter( array_map( 'strval', (array) $urls ) ) ) );
}

/**
 * Read a list of pages from this same site, one after another, within a time budget.
 *
 * @param string[] $urls   Pages to read (the first one is always attempted).
 * @param float    $budget Seconds allowed for the whole batch.
 * @return array{html:array<string,string>,failed:int,missing:int,planned:int,skipped:int,complete:bool}
 */
function tsosi_usage_frontend_read( $urls, $budget ) {
	$out     = array(
		'html'     => array(),
		'failed'   => 0,
		'missing'  => 0,
		'planned'  => count( (array) $urls ),
		'skipped'  => 0,
		'complete' => false,
	);
	$started = microtime( true );
	foreach ( array_values( (array) $urls ) as $i => $url ) {
		if ( $i > 0 && ( microtime( true ) - $started ) >= $budget ) {
			++$out['skipped'];
			continue;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 6,
				'redirection' => 3,
				'user-agent'  => 'TSO-Stack-Inspector/' . TSOSI_VERSION,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			++$out['failed'];
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		// A published page that answers 404/410 (e.g. a leftover "404" page) is not content a
		// visitor reads: it neither proves nor hides anything, so it does not make the read incomplete.
		if ( 404 === $code || 410 === $code ) {
			++$out['missing'];
			continue;
		}
		if ( 200 !== $code ) {
			++$out['failed'];
			continue;
		}
		$body = substr( (string) wp_remote_retrieve_body( $response ), 0, 400000 );
		if ( '' === $body ) {
			++$out['failed'];
			continue;
		}
		$out['html'][ $url ] = str_replace( '\\/', '/', $body );
	}
	// "Complete" means every planned page that exists was read (no timeouts, errors or access denied,
	// none skipped by the time budget): only then can silence be read as absence.
	$out['complete'] = ! empty( $out['html'] ) && 0 === $out['failed'] && 0 === $out['skipped'];
	return $out;
}

/**
 * HTML of a sample of the public pages as a visitor receives it (requests to this same
 * site only, nothing is sent to third parties). Read once per request.
 *
 * @return array{html:array<string,string>,failed:int,planned:int,skipped:int,complete:bool}
 */
function tsosi_usage_frontend_snapshot() {
	static $memo = null;
	if ( null === $memo ) {
		$memo = tsosi_usage_frontend_read( tsosi_usage_frontend_urls(), TSOSI_USAGE_FRONTEND_TIME_BUDGET );
	}
	return $memo;
}

/**
 * Lower "its hooks run on the front end" from review to info when the public pages were
 * read in full and show nothing of the plugin. A partial read proves nothing, so the
 * suspicion stays.
 *
 * @param array<int,array<string,mixed>> $signals  Usage signals.
 * @param array<string,mixed>            $snapshot Result of tsosi_usage_frontend_snapshot().
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_relax_frontend_hooks( $signals, $snapshot ) {
	if ( empty( $snapshot['complete'] ) ) {
		return $signals;
	}
	$ids = wp_list_pluck( $signals, 'id' );
	if ( in_array( 'frontend_rendered', $ids, true ) || in_array( 'frontend_unavailable', $ids, true ) ) {
		return $signals;
	}
	foreach ( $signals as $key => $signal ) {
		if ( isset( $signal['id'] ) && 'frontend_hooks' === $signal['id'] && 'review' === $signal['severity'] ) {
			$signals[ $key ]['severity'] = 'info';
			$signals[ $key ]['weight']   = 0;
			$signals[ $key ]['summary']  = $signals[ $key ]['label'];
		}
	}
	return $signals;
}

/**
 * Remember on which public page a piece of front-end evidence was seen.
 *
 * @param array<string,mixed> $out      Match result (by reference).
 * @param string              $kind     assets|markers|scripts.
 * @param string              $evidence Evidence text.
 * @param string              $path     Page path.
 * @return void
 */
function tsosi_usage_frontend_note( &$out, $kind, $evidence, $path ) {
	$key = $kind . '|' . $evidence;
	if ( ! isset( $out['where'][ $key ] ) ) {
		$out['where'][ $key ] = array();
	}
	if ( count( $out['where'][ $key ] ) < 3 && ! in_array( $path, $out['where'][ $key ], true ) ) {
		$out['where'][ $key ][] = $path;
	}
}

/**
 * Evidence lines with the pages where each one was found.
 *
 * @param array<string,mixed> $hits Result of tsosi_usage_frontend_match().
 * @param string[]            $kinds Kinds to include.
 * @return string[]
 */
function tsosi_usage_frontend_items( $hits, $kinds ) {
	$items = array();
	foreach ( $kinds as $kind ) {
		foreach ( (array) $hits[ $kind ] as $evidence ) {
			$pages   = isset( $hits['where'][ $kind . '|' . $evidence ] ) ? $hits['where'][ $kind . '|' . $evidence ] : array();
			$items[] = $evidence . ( empty( $pages ) ? '' : '  →  ' . implode( ', ', $pages ) );
		}
	}
	return $items;
}

/**
 * Read one public URL of this same site. Only a successful (200) answer is kept.
 *
 * @param string $url Absolute URL.
 * @return string Lowercase body (capped), '' when it could not be read.
 */
function tsosi_usage_endpoint_fetch( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 5,
			'redirection' => 2,
			'user-agent'  => 'TSO-Stack-Inspector/' . TSOSI_VERSION,
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	return strtolower( substr( (string) wp_remote_retrieve_body( $response ), 0, 200000 ) );
}

/**
 * Sitemap URLs that robots.txt declares ("Sitemap: ..."), on this same site only.
 *
 * @param string $robots Body of robots.txt.
 * @return string[] Absolute URLs (at most 3).
 */
function tsosi_usage_robots_sitemaps( $robots ) {
	$urls = array();
	if ( preg_match_all( '/^\s*sitemap:\s*(\S+)/im', (string) $robots, $m ) ) {
		foreach ( $m[1] as $url ) {
			$parts = wp_parse_url( (string) $url );
			$host  = isset( $parts['host'] ) ? preg_replace( '/^www\./', '', strtolower( (string) $parts['host'] ) ) : '';
			if ( isset( $parts['scheme'] ) && in_array( $parts['scheme'], array( 'http', 'https' ), true ) && tsosi_usage_is_site_host( (string) $host ) ) {
				$urls[] = (string) $url;
			}
		}
	}
	return array_slice( array_values( array_unique( $urls ) ), 0, 3 );
}

/**
 * Read the public endpoints plugins usually generate (robots.txt, sitemaps, feed) from this
 * same site, asking only for URLs that should exist: the sitemaps robots.txt declares, plus
 * /sitemap.xml; /sitemap_index.xml is a last resort when nothing else answered, so a site
 * that has no such file is not filled with 404s in its log.
 *
 * @return array<string,string> Path => lowercase body (successful responses only).
 */
function tsosi_usage_endpoints_read() {
	$out    = array();
	$robots = tsosi_usage_endpoint_fetch( home_url( '/robots.txt' ) );
	if ( '' !== $robots ) {
		$out['/robots.txt'] = $robots;
	}

	$urls     = tsosi_usage_robots_sitemaps( $robots );
	$fallback = home_url( '/sitemap.xml' );
	if ( ! in_array( $fallback, $urls, true ) ) {
		$urls[] = $fallback;
	}
	$found = false;
	foreach ( $urls as $url ) {
		$body = tsosi_usage_endpoint_fetch( $url );
		if ( '' === $body ) {
			continue;
		}
		$found = true;
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$out[ $path . ( '' === $query ? '' : '?' . $query ) ] = $body;
	}
	if ( ! $found ) {
		$body = tsosi_usage_endpoint_fetch( home_url( '/sitemap_index.xml' ) );
		if ( '' !== $body ) {
			$out['/sitemap_index.xml'] = $body;
		}
	}

	$feed = tsosi_usage_endpoint_fetch( home_url( '/feed/' ) );
	if ( '' !== $feed ) {
		$out['/feed/'] = $feed;
	}
	return $out;
}

/**
 * Public endpoints plugins usually generate (sitemaps, robots.txt, feed), read once per request.
 *
 * @return array<string,string> Path => lowercase body.
 */
function tsosi_usage_endpoints_snapshot() {
	static $memo = null;
	if ( null === $memo ) {
		$memo = tsosi_usage_endpoints_read();
	}
	return $memo;
}

/**
 * Whether a text (slug, name, description) suggests a plugin that generates sitemaps,
 * robots.txt rules or feeds. Includes the Spanish and Catalan words for "site map".
 *
 * @param string $text Text to look at.
 * @return bool
 */
function tsosi_usage_endpoint_text_is_relevant( $text ) {
	return 1 === preg_match( '/sitemap|\bseo\b|robots|\bfeeds?\b|\brss\b|\batom\b|syndicat|\bxml\b|\bmapas?\b|\bmapes?\b/i', str_replace( array( '-', '_' ), ' ', (string) $text ) );
}

/**
 * Whether it is worth reading the public endpoints for this plugin. Most plugins cannot
 * appear there, so they cause no request at all.
 *
 * @param string $plugin_file Plugin basename.
 * @return bool
 */
function tsosi_usage_endpoint_plugin_is_relevant( $plugin_file ) {
	$plugins = tsosi_get_installed_plugins();
	$header  = isset( $plugins[ $plugin_file ] ) && is_array( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ] : array();
	$text    = tsosi_usage_plugin_slug( $plugin_file ) . ' ' . ( isset( $header['Name'] ) ? $header['Name'] : '' ) . ' ' . ( isset( $header['Description'] ) ? $header['Description'] : '' );

	/**
	 * Filter whether the public endpoints (robots.txt, sitemaps, feed) are read for a plugin.
	 *
	 * @param bool   $relevant    Whether the plugin looks like a sitemap / SEO / feed plugin.
	 * @param string $plugin_file Plugin basename.
	 */
	return (bool) apply_filters( 'tsosi_usage_endpoint_plugin_is_relevant', tsosi_usage_endpoint_text_is_relevant( $text ), $plugin_file );
}

/**
 * Whether a host is the site's own domain (or a sub/parent domain of it).
 *
 * @param string $host Host name without www.
 * @return bool
 */
function tsosi_usage_is_site_host( $host ) {
	$site = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	if ( '' === $host || '' === $site ) {
		return false;
	}
	return $host === $site || substr( $site, -( strlen( $host ) + 1 ) ) === '.' . $host || substr( $host, -( strlen( $site ) + 1 ) ) === '.' . $site;
}

/**
 * Names that identify a plugin inside the output of an endpoint: the slug, pairs of
 * consecutive slug words (google-sitemap-generator → google-sitemap, sitemap-generator)
 * and the host of its Plugin URI / Author URI.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_usage_identity_needles( $plugin_file ) {
	$needles = array();
	$slug    = strtolower( tsosi_usage_plugin_slug( $plugin_file ) );
	$words   = array_values( array_filter( preg_split( '/[-_]+/', $slug ) ) );
	if ( strlen( $slug ) >= 10 ) {
		$needles[] = $slug;
	}
	$last = count( $words ) - 1;
	for ( $n = 0; $n < $last; $n++ ) {
		$pair = $words[ $n ] . '-' . $words[ $n + 1 ];
		if ( strlen( $pair ) >= 10 ) {
			$needles[] = $pair;
		}
	}
	$installed = tsosi_get_installed_plugins();
	foreach ( array( 'PluginURI', 'AuthorURI' ) as $field ) {
		if ( empty( $installed[ $plugin_file ][ $field ] ) ) {
			continue;
		}
		$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( (string) $installed[ $plugin_file ][ $field ], PHP_URL_HOST ) ) );
		// The site's own domain (a plugin by the site owner) says nothing about usage.
		if ( tsosi_usage_is_site_host( (string) $host ) ) {
			continue;
		}
		if ( strlen( $host ) >= 8 && ! preg_match( '/(?:word[p]ress\.(?:org|com)|github\.com|gitlab\.com|wp\.org|automattic\.com|facebook\.com|twitter\.com|google\.com|youtube\.com)$/', $host ) ) {
			$needles[] = $host;
		}
	}
	return array_values( array_unique( $needles ) );
}

/**
 * Endpoints (sitemap, robots.txt, feed) whose output names this plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[] Evidence lines ("needle  →  /path").
 */
function tsosi_usage_endpoint_hits( $plugin_file ) {
	$items = array();
	if ( ! tsosi_usage_endpoint_plugin_is_relevant( $plugin_file ) ) {
		return $items;
	}
	$needles = tsosi_usage_identity_needles( $plugin_file );
	if ( empty( $needles ) ) {
		return $items;
	}
	foreach ( tsosi_usage_endpoints_snapshot() as $path => $body ) {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $body, $needle ) ) {
				$items[] = $needle . '  →  ' . $path;
				break;
			}
		}
	}
	return $items;
}

/**
 * Look for a plugin's footprint in the HTML of public pages: files loaded from its
 * folder, class/id names that start with one of its prefixes, and identifiers with its
 * prefixes inside inline scripts.
 *
 * @param array<string,string> $pages    HTML by URL.
 * @param string               $folder   Plugin folder.
 * @param string[]             $prefixes Option/meta/slug prefixes of the plugin.
 * @return array{assets:string[],markers:string[],scripts:string[],where:array<string,string[]>}
 */
function tsosi_usage_frontend_match( $pages, $folder, $prefixes ) {
	$out     = array(
		'assets'  => array(),
		'markers' => array(),
		'scripts' => array(),
		'where'   => array(),
	);
	$folder  = trim( (string) $folder, '/' );
	$generic = array( 'tso', 'widget', 'plugin', 'plugins', 'option', 'options', 'setting', 'settings', 'theme', 'admin', 'post', 'posts', 'page', 'pages', 'form', 'forms', 'email', 'user', 'users', 'site', 'custom', 'data', 'cache', 'image', 'images', 'comment', 'comments', 'menu', 'body', 'header', 'footer', 'content', 'button', 'title', 'text', 'link', 'links', 'media', 'search', 'login', 'main', 'sidebar', 'wp', 'jquery', 'block', 'blocks', 'style', 'script' );
	$alts    = array();
	foreach ( (array) $prefixes as $prefix ) {
		$prefix = trim( sanitize_key( (string) $prefix ), '_-' );
		if ( strlen( $prefix ) < 3 || in_array( $prefix, $generic, true ) ) {
			continue;
		}
		$alts[ $prefix ] = preg_replace( '/(?:\\\\-|_)/', '[_-]', preg_quote( $prefix, '~' ) );
	}

	foreach ( (array) $pages as $page_url => $html ) {
		$html = (string) $html;
		$path = (string) wp_parse_url( (string) $page_url, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		if ( '' !== $folder && preg_match_all( '~/wp-content/plugins/' . preg_quote( $folder, '~' ) . '/[^"\'\s<>)]*~i', $html, $m ) ) {
			foreach ( $m[0] as $asset ) {
				$asset = strtok( $asset, '?' );
				tsosi_usage_frontend_note( $out, 'assets', $asset, $path );
				if ( count( $out['assets'] ) < 5 && ! in_array( $asset, $out['assets'], true ) ) {
					$out['assets'][] = $asset;
				}
			}
		}
		if ( empty( $alts ) ) {
			continue;
		}
		$alt = implode( '|', $alts );
		if ( preg_match_all( '~\b(?:class|id)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $html, $attrs ) ) {
			foreach ( array_merge( $attrs[1], $attrs[2] ) as $value ) {
				foreach ( preg_split( '/\s+/', (string) $value ) as $token ) {
					if ( '' !== $token && preg_match( '~^(?:' . $alt . ')(?:[_-]|$)~i', $token ) ) {
						tsosi_usage_frontend_note( $out, 'markers', $token, $path );
					}
					if ( '' !== $token && preg_match( '~^(?:' . $alt . ')(?:[_-]|$)~i', $token ) && count( $out['markers'] ) < 5 && ! in_array( $token, $out['markers'], true ) ) {
						$out['markers'][] = $token;
					}
				}
			}
		}
		if ( preg_match_all( '~<script\b[^>]*>(.*?)</script>~is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $code ) {
				if ( preg_match( '~(?<![a-z0-9])(?:' . $alt . ')_[a-z0-9_]{3,}~i', (string) $code, $hit ) ) {
					tsosi_usage_frontend_note( $out, 'scripts', $hit[0], $path );
				}
				if ( preg_match( '~(?<![a-z0-9])(?:' . $alt . ')_[a-z0-9_]{3,}~i', (string) $code, $hit ) && count( $out['scripts'] ) < 5 && ! in_array( $hit[0], $out['scripts'], true ) ) {
					$out['scripts'][] = $hit[0];
				}
			}
		}
	}
	return $out;
}

/**
 * Front-end signals: does the public site actually load files or markup from this plugin?
 * This is what catches plugins that add their output with filters and no shortcode or
 * block (share buttons, cookie banners, SEO tags, popups...).
 *
 * @param string   $plugin_file Plugin basename.
 * @param string[] $prefixes    Option/meta/slug prefixes.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_frontend( $plugin_file, $prefixes ) {
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	if ( '' === $folder || ! tsosi_is_plugin_active_file( $plugin_file ) ) {
		return array();
	}

	$endpoint_items = tsosi_usage_endpoint_hits( $plugin_file );
	$snapshot       = tsosi_usage_frontend_snapshot();
	if ( empty( $snapshot['html'] ) && empty( $endpoint_items ) ) {
		return array(
			tsosi_usage_signal(
				'frontend_unavailable',
				'review',
				tsosi_ui_triple_text(
					'The public pages could not be read from the server, so front-end usage was NOT verified',
					'No se pudieron leer las páginas públicas desde el servidor, así que el uso en el front NO se ha verificado',
					'No s\'han pogut llegir les pàgines públiques des del servidor, així que l\'ús al front NO s\'ha verificat'
				),
				array(),
				tsosi_ui_triple_text( 'front-end usage not verified', 'uso en el front sin verificar', 'ús al front sense verificar' )
			),
		);
	}

	$hits    = tsosi_usage_frontend_match( $snapshot['html'], $folder, $prefixes );
	$signals = array();
	if ( ! empty( $hits['assets'] ) || ! empty( $hits['markers'] ) || ! empty( $endpoint_items ) ) {
		$signals[] = tsosi_usage_signal(
			'frontend_rendered',
			'danger',
			tsosi_ui_triple_text(
				'The public pages of the site load files or markup from this plugin, or its generated output (sitemap, robots.txt, feed) is served right now (visitors and crawlers see it)',
				'Las páginas públicas del sitio cargan archivos o marcado de este plugin, o se sirve ahora mismo su salida generada (sitemap, robots.txt, feed) (la ven visitantes y buscadores)',
				'Les pàgines públiques del lloc carreguen fitxers o marcat d\'aquest plugin, o se\'n serveix ara mateix la sortida generada (sitemap, robots.txt, feed) (la veuen visitants i cercadors)'
			),
			array_merge( tsosi_usage_frontend_items( $hits, array( 'assets', 'markers' ) ), $endpoint_items ),
			tsosi_ui_triple_text( 'the public pages show its output', 'las páginas públicas muestran su salida', 'les pàgines públiques en mostren la sortida' )
		);
	} elseif ( ! empty( $hits['scripts'] ) ) {
		$signals[] = tsosi_usage_signal(
			'frontend_scripts',
			'review',
			tsosi_ui_triple_text(
				'Inline scripts on the public pages use names of this plugin',
				'Scripts en línea de las páginas públicas usan nombres de este plugin',
				'Scripts en línia de les pàgines públiques fan servir noms d\'aquest plugin'
			),
			tsosi_usage_frontend_items( $hits, array( 'scripts' ) ),
			tsosi_ui_triple_text( 'inline scripts on public pages use it', 'scripts de las páginas públicas lo usan', 'scripts de les pàgines públiques l\'usen' )
		);
	}
	return $signals;
}

/**
 * Scheduled cron hooks whose callbacks live in the plugin directory.
 *
 * @param string $dir Normalized plugin dir with trailing slash.
 * @return string[]
 */
function tsosi_usage_runtime_cron_hooks( $dir ) {
	global $wp_filter;
	$cron = _get_cron_array();
	$out  = array();
	if ( ! is_array( $cron ) ) {
		return $out;
	}
	foreach ( $cron as $events ) {
		if ( ! is_array( $events ) ) {
			continue;
		}
		foreach ( array_keys( $events ) as $hook ) {
			$hook = (string) $hook;
			if ( isset( $out[ $hook ] ) || empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$file = isset( $cb['function'] ) ? tsosi_usage_callback_file( $cb['function'] ) : '';
					if ( '' !== $file && 0 === strpos( $file, $dir ) ) {
						$out[ $hook ] = $hook;
						break 2;
					}
				}
			}
		}
	}
	return array_values( $out );
}

/**
 * REST routes whose callbacks live in the plugin directory.
 *
 * @param string $dir Normalized plugin dir with trailing slash.
 * @return string[]
 */
function tsosi_usage_runtime_rest_routes( $dir ) {
	if ( ! function_exists( 'rest_get_server' ) ) {
		return array();
	}
	$out = array();
	try {
		$routes = rest_get_server()->get_routes();
	} catch ( Exception $e ) {
		return array();
	}
	foreach ( (array) $routes as $route => $endpoints ) {
		foreach ( (array) $endpoints as $endpoint ) {
			$file = is_array( $endpoint ) && isset( $endpoint['callback'] ) ? tsosi_usage_callback_file( $endpoint['callback'] ) : '';
			if ( '' !== $file && 0 === strpos( $file, $dir ) ) {
				$out[] = (string) $route;
				break;
			}
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * WP_Widget classes registered from the plugin directory (id_base).
 *
 * @param string $dir Normalized plugin dir with trailing slash.
 * @return string[]
 */
function tsosi_usage_runtime_widget_classes( $dir ) {
	global $wp_widget_factory;
	$out = array();
	if ( ! is_object( $wp_widget_factory ) || empty( $wp_widget_factory->widgets ) ) {
		return $out;
	}
	foreach ( $wp_widget_factory->widgets as $widget ) {
		if ( ! is_object( $widget ) ) {
			continue;
		}
		$file = tsosi_usage_callback_file( $widget );
		if ( '' !== $file && 0 === strpos( $file, $dir ) && ! empty( $widget->id_base ) ) {
			$out[] = (string) $widget->id_base;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Remember which plugin registered each custom post type / taxonomy (runtime
 * attribution through the call stack). Only enabled in the admin, where the inspector runs.
 *
 * @return void
 */
function tsosi_usage_capture_register() {
	if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	add_action( 'registered_post_type', 'tsosi_usage_capture_post_type', 10, 1 );
	add_action( 'registered_taxonomy', 'tsosi_usage_capture_taxonomy', 10, 1 );
}

/**
 * Records the plugin that registers a post type.
 *
 * @param string $post_type Post type.
 * @return void
 */
function tsosi_usage_capture_post_type( $post_type ) {
	tsosi_usage_capture_owner( 'post_type', (string) $post_type );
}

/**
 * Records the plugin that registers a taxonomy.
 *
 * @param string $taxonomy Taxonomy.
 * @return void
 */
function tsosi_usage_capture_taxonomy( $taxonomy ) {
	tsosi_usage_capture_owner( 'taxonomy', (string) $taxonomy );
}

/**
 * Store (or read) the registration owner map.
 *
 * @param string $kind post_type|taxonomy.
 * @param string $name Registered name; '' to just return the map.
 * @return array<string,string> Registered name => owner key (plugin folder or theme:slug).
 */
function tsosi_usage_capture_owner( $kind, $name = '' ) {
	static $map = array(
		'post_type' => array(),
		'taxonomy'  => array(),
	);
	if ( '' === $name ) {
		return $map[ $kind ];
	}
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- attribution of the registering plugin, never output.
	$frames  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
	$plugins = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );
	$themes  = wp_normalize_path( trailingslashit( get_theme_root() ) );
	foreach ( $frames as $frame ) {
		if ( empty( $frame['file'] ) ) {
			continue;
		}
		$file = wp_normalize_path( (string) $frame['file'] );
		if ( 0 === strpos( $file, wp_normalize_path( TSOSI_PATH ) ) ) {
			// This inspector registers nothing: never attribute a registration to it.
			continue;
		}
		if ( 0 === strpos( $file, $plugins ) ) {
			$rel                   = substr( $file, strlen( $plugins ) );
			$parts                 = explode( '/', $rel );
			$map[ $kind ][ $name ] = count( $parts ) > 1 ? $parts[0] : basename( $rel, '.php' );
			return $map[ $kind ];
		}
		if ( 0 === strpos( $file, $themes ) ) {
			$rel                   = substr( $file, strlen( $themes ) );
			$parts                 = explode( '/', $rel );
			$map[ $kind ][ $name ] = 'theme:' . $parts[0];
			return $map[ $kind ];
		}
	}
	return $map[ $kind ];
}

/**
 * Summarise the rows of one post type (status, count and last edit per status).
 *
 * Entries that are published, private or scheduled are "live". A type is "dormant" only
 * when WordPress says it is not public and nothing in it has been edited for
 * TSOSI_USAGE_STALE_DAYS: published entries of a public type are served at their own URLs
 * however old they are, and a type that is not registered right now cannot be judged.
 *
 * @param array<int,array<string,mixed>> $rows        Rows with post_status, total and last_modified (GMT).
 * @param object|null                    $type_object Post type object, null when not registered.
 * @param int                            $now         Current timestamp.
 * @return array{live:int,total:int,last:int,dormant:bool}
 */
function tsosi_usage_post_type_state( $rows, $type_object, $now ) {
	$live  = 0;
	$total = 0;
	$last  = 0;
	foreach ( (array) $rows as $row ) {
		$count  = (int) $row['total'];
		$total += $count;
		if ( ! in_array( (string) $row['post_status'], array( 'publish', 'private', 'future' ), true ) ) {
			continue;
		}
		$live += $count;
		if ( ! empty( $row['last_modified'] ) ) {
			$time = strtotime( (string) $row['last_modified'] . ' UTC' );
			$last = $time && $time > $last ? $time : $last;
		}
	}
	$non_public = is_object( $type_object ) && empty( $type_object->public ) && empty( $type_object->publicly_queryable );
	$stale      = $last > 0 && ( $now - $last ) > TSOSI_USAGE_STALE_DAYS * DAY_IN_SECONDS;
	return array(
		'live'    => $live,
		'total'   => $total,
		'last'    => $last,
		'dormant' => $live > 0 && $non_public && $stale,
	);
}

/**
 * Evidence line for a post type: name, volume and last edit date.
 *
 * @param string                                          $type  Post type.
 * @param array{live:int,total:int,last:int,dormant:bool} $state State from tsosi_usage_post_type_state().
 * @return string
 */
function tsosi_usage_post_type_line( $type, $state ) {
	$count = $state['live'] > 0 ? $state['live'] : $state['total'];
	$line  = $type . ' (' . $count;
	if ( $state['live'] > 0 && $state['last'] > 0 ) {
		$line .= ' · ' . gmdate( 'Y-m-d', $state['last'] );
	}
	return $line . ')';
}

/**
 * Custom post types and taxonomies owned by the plugin, and how much data they hold.
 *
 * @param string              $plugin_file Plugin basename.
 * @param array<string,mixed> $facts       Static facts.
 * @param string              $owner_key   Registration owner key override (theme:slug).
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_content_types( $plugin_file, $facts, $owner_key = '' ) {
	global $wpdb;

	$slug       = '' !== $owner_key ? $owner_key : tsosi_usage_plugin_slug( $plugin_file );
	$owners     = tsosi_usage_capture_owner( 'post_type' );
	$tax_owners = tsosi_usage_capture_owner( 'taxonomy' );
	$builtin    = get_post_types( array( '_builtin' => true ), 'names' );
	$builtin_tx = get_taxonomies( array( '_builtin' => true ), 'names' );

	$types = (array) $facts['post_types'];
	foreach ( $owners as $type => $owner ) {
		if ( $owner === $slug ) {
			$types[] = $type;
		}
	}
	$taxes = (array) $facts['taxonomies'];
	foreach ( $tax_owners as $tax => $owner ) {
		if ( $owner === $slug ) {
			$taxes[] = $tax;
		}
	}
	$types = array_values( array_diff( array_unique( array_map( 'sanitize_key', $types ) ), (array) $builtin ) );
	$taxes = array_values( array_diff( array_unique( array_map( 'sanitize_key', $taxes ) ), (array) $builtin_tx ) );

	$signals   = array();
	$published = array();
	$dormant   = array();
	$other     = array();
	foreach ( $types as $type ) {
		if ( '' === $type ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_status, COUNT(*) AS total, MAX(post_modified_gmt) AS last_modified FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft') GROUP BY post_status",
				$type
			),
			ARRAY_A
		);
		$state = tsosi_usage_post_type_state( $rows, get_post_type_object( $type ), time() );
		$line  = tsosi_usage_post_type_line( $type, $state );
		if ( $state['live'] > 0 ) {
			if ( $state['dormant'] ) {
				$dormant[] = $line;
			} else {
				$published[] = $line;
			}
		} elseif ( $state['total'] > 0 ) {
			$other[] = $line;
		}
	}
	if ( ! empty( $published ) ) {
		$signals[] = tsosi_usage_signal(
			'post_types_live',
			'danger',
			tsosi_ui_triple_text(
				'Custom post types with published or private entries (public pages/content depend on it)',
				'Tipos de contenido propios con entradas publicadas o privadas (hay contenido que depende de él)',
				'Tipus de contingut propis amb entrades publicades o privades (hi ha contingut que en depèn)'
			),
			$published,
			tsosi_ui_triple_text( 'its custom post types have live entries', 'sus tipos de contenido tienen entradas activas', 'els seus tipus de contingut tenen entrades actives' )
		);
	}
	if ( ! empty( $dormant ) ) {
		$signals[] = tsosi_usage_signal(
			'post_types_dormant',
			'review',
			tsosi_ui_triple_text(
				'Non-public custom post types whose entries have not been edited for a long time (probably leftover data; check before deleting)',
				'Tipos de contenido no públicos cuyas entradas no se editan desde hace mucho (probablemente datos sobrantes; comprueba antes de borrar)',
				'Tipus de contingut no públics amb entrades que fa molt que no s\'editen (probablement dades sobrants; comprova abans d\'esborrar)'
			),
			$dormant,
			tsosi_ui_triple_text( 'its custom post types hold old entries', 'sus tipos de contenido guardan entradas antiguas', 'els seus tipus de contingut guarden entrades antigues' )
		);
	}
	if ( ! empty( $other ) ) {
		$signals[] = tsosi_usage_signal(
			'post_types_data',
			'review',
			tsosi_ui_triple_text(
				'Custom post types with drafts/trashed entries (data would be orphaned)',
				'Tipos de contenido propios con borradores/papelera (los datos quedarían huérfanos)',
				'Tipus de contingut propis amb esborranys/paperera (les dades quedarien òrfenes)'
			),
			$other,
			tsosi_ui_triple_text( 'its custom post types hold data', 'sus tipos de contenido guardan datos', 'els seus tipus de contingut guarden dades' )
		);
	}

	$tax_data = array();
	foreach ( $taxes as $tax ) {
		if ( '' === $tax ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $tax )
		);
		if ( $count > 0 ) {
			$tax_data[] = $tax . ' (' . $count . ')';
		}
	}
	if ( ! empty( $tax_data ) ) {
		$signals[] = tsosi_usage_signal(
			'taxonomies',
			'review',
			tsosi_ui_triple_text(
				'Custom taxonomies with terms',
				'Taxonomías propias con términos',
				'Taxonomies pròpies amb termes'
			),
			$tax_data,
			tsosi_ui_triple_text( 'it owns taxonomies with terms', 'tiene taxonomías con términos', 'té taxonomies amb termes' )
		);
	}

	return $signals;
}

/**
 * Widgets of the plugin parked in an inactive area. They are not shown on the site, so
 * this is only information: the leftovers can be removed before uninstalling.
 *
 * @param string[] $prefixes Option / widget id_base prefixes.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_inactive_widgets( $prefixes ) {
	$prefixes = array_values( array_filter( array_map( 'sanitize_key', (array) $prefixes ) ) );
	if ( empty( $prefixes ) ) {
		return array();
	}
	$instances = tsosi_scan_inactive_widget_instances( $prefixes );
	if ( empty( $instances ) ) {
		return array();
	}
	return array(
		tsosi_usage_signal(
			'widgets_inactive',
			'info',
			tsosi_ui_triple_text(
				'Widgets of this plugin in the inactive area (not shown on the site; remove them from Appearance → Widgets)',
				'Widgets de este plugin en la zona inactiva (no se muestran en el sitio; quítalos desde Apariencia → Widgets)',
				'Widgets d\'aquest plugin a la zona inactiva (no es mostren al lloc; treu-los des d\'Aparença → Ginys)'
			),
			$instances,
			tsosi_ui_triple_text( 'it has widgets in the inactive area', 'tiene widgets en la zona inactiva', 'té widgets a la zona inactiva' )
		),
	);
}

/**
 * Database tables created by the plugin (or named after it) that exist and hold rows.
 *
 * @param string              $plugin_file Plugin basename.
 * @param array<string,mixed> $facts       Static facts.
 * @param string[]            $prefixes    Extra name prefixes (option prefixes, slug).
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_tables( $plugin_file, $facts, $prefixes ) {
	global $wpdb;

	$candidates = array();
	foreach ( (array) $facts['tables'] as $name ) {
		$candidates[ $wpdb->prefix . $name ] = true;
		if ( is_multisite() ) {
			$candidates[ $wpdb->base_prefix . $name ] = true;
		}
	}

	$core = array_map( 'strtolower', (array) $wpdb->tables( 'all', true ) );
	foreach ( array_unique( array_filter( array_map( 'sanitize_key', (array) $prefixes ) ) ) as $prefix ) {
		if ( strlen( $prefix ) < 5 ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $prefix ) . '%' ) );
		foreach ( (array) $found as $table ) {
			if ( ! in_array( strtolower( (string) $table ), $core, true ) ) {
				$candidates[ (string) $table ] = true;
			}
		}
	}

	$with_rows = array();
	$empty     = array();
	foreach ( array_keys( $candidates ) as $table ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table ) || in_array( strtolower( (string) $table ), $core, true ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( null === $exists || strtolower( (string) $exists ) !== strtolower( (string) $table ) ) {
			continue;
		}
		// Table name validated above and confirmed by SHOW TABLES.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bounded admin-only usage check.
		$has_rows = $wpdb->get_var( 'SELECT 1 FROM `' . esc_sql( $table ) . '` LIMIT 1' );
		if ( null !== $has_rows ) {
			$with_rows[] = $table;
		} else {
			$empty[] = $table;
		}
	}

	$signals = array();
	if ( ! empty( $with_rows ) ) {
		$signals[] = tsosi_usage_signal(
			'tables_data',
			'review',
			tsosi_ui_triple_text(
				'Database tables created by this plugin that still contain rows',
				'Tablas de base de datos creadas por este plugin que aún contienen filas',
				'Taules de base de dades creades per aquest plugin que encara contenen files'
			),
			$with_rows,
			tsosi_ui_triple_text( 'its own tables hold data', 'sus tablas propias guardan datos', 'les seves taules pròpies guarden dades' )
		);
	}
	if ( ! empty( $empty ) ) {
		$signals[] = tsosi_usage_signal(
			'tables_empty',
			'info',
			tsosi_ui_triple_text(
				'Database tables created by this plugin (empty)',
				'Tablas de base de datos creadas por este plugin (vacías)',
				'Taules de base de dades creades per aquest plugin (buides)'
			),
			$empty
		);
	}
	return $signals;
}

/**
 * Roles added by the plugin that still have users.
 *
 * @param array<string,mixed> $facts Static facts.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_roles( $facts ) {
	$roles = array_filter( array_map( 'sanitize_key', (array) $facts['roles'] ) );
	if ( empty( $roles ) ) {
		return array();
	}
	$counts = count_users();
	$avail  = isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] ) ? $counts['avail_roles'] : array();
	$items  = array();
	$users  = false;
	foreach ( $roles as $role ) {
		if ( ! get_role( $role ) ) {
			continue;
		}
		$n = isset( $avail[ $role ] ) ? (int) $avail[ $role ] : 0;
		if ( $n > 0 ) {
			$users = true;
		}
		$items[] = $role . ' (' . $n . ')';
	}
	if ( empty( $items ) ) {
		return array();
	}
	return array(
		tsosi_usage_signal(
			'roles',
			$users ? 'review' : 'info',
			tsosi_ui_triple_text(
				'User roles created by the plugin (number of users)',
				'Roles de usuario creados por el plugin (número de usuarios)',
				'Rols d\'usuari creats pel plugin (nombre d\'usuaris)'
			),
			$items,
			tsosi_ui_triple_text( 'users have roles it created', 'hay usuarios con roles que creó él', 'hi ha usuaris amb rols que ha creat ell' )
		),
	);
}

/**
 * Elementor widgets provided by the plugin that are placed in page-builder data.
 *
 * @param array<string,mixed> $facts Static facts.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_builder_widgets( $facts ) {
	global $wpdb;
	$names = array_filter( array_map( 'sanitize_key', (array) $facts['builder_widgets'] ) );
	if ( empty( $names ) ) {
		return array();
	}
	$items = array();
	foreach ( array_slice( array_unique( $names ), 0, 40 ) as $name ) {
		$like = '%' . $wpdb->esc_like( '"widgetType":"' . $name . '"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s LIMIT 5",
				$like
			)
		);
		foreach ( (array) $ids as $post_id ) {
			$title   = get_the_title( (int) $post_id );
			$items[] = $name . ' → ' . ( '' !== $title ? $title : '#' . (int) $post_id ) . ' (#' . (int) $post_id . ')';
		}
	}
	if ( empty( $items ) ) {
		return array();
	}
	return array(
		tsosi_usage_signal(
			'builder_widgets',
			'danger',
			tsosi_ui_triple_text(
				'Elementor widgets from this plugin are placed in pages',
				'Hay widgets de Elementor de este plugin colocados en páginas',
				'Hi ha widgets d\'Elementor d\'aquest plugin col·locats a pàgines'
			),
			$items,
			tsosi_ui_triple_text( 'its builder widgets are used in pages', 'sus widgets de builder se usan en páginas', 'els seus widgets de builder s\'usen en pàgines' )
		),
	);
}

/**
 * Cron events still scheduled for the plugin (static hook names / slug-prefixed hooks).
 * Works for inactive plugins too, where the callbacks are no longer loaded.
 *
 * @param string              $plugin_file Plugin basename.
 * @param array<string,mixed> $facts       Static facts.
 * @param string[]            $prefixes    Slug/option prefixes.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_cron_leftovers( $plugin_file, $facts, $prefixes ) {
	if ( tsosi_is_plugin_active_file( $plugin_file ) ) {
		return array();
	}
	$cron = _get_cron_array();
	if ( ! is_array( $cron ) ) {
		return array();
	}
	$known = array_flip( (array) $facts['cron_hooks'] );
	$heads = array();
	foreach ( (array) $prefixes as $prefix ) {
		$prefix = sanitize_key( $prefix );
		if ( strlen( $prefix ) >= 6 ) {
			$heads[] = $prefix;
		}
	}
	$items = array();
	foreach ( $cron as $events ) {
		if ( ! is_array( $events ) ) {
			continue;
		}
		foreach ( array_keys( $events ) as $hook ) {
			$hook = (string) $hook;
			$hit  = isset( $known[ $hook ] );
			if ( ! $hit ) {
				foreach ( $heads as $head ) {
					if ( 0 === strpos( $hook, $head ) ) {
						$hit = true;
						break;
					}
				}
			}
			if ( $hit ) {
				$items[] = $hook;
			}
		}
	}
	if ( empty( $items ) ) {
		return array();
	}
	return array(
		tsosi_usage_signal(
			'cron_leftover',
			'review',
			tsosi_ui_triple_text(
				'Scheduled WP-Cron events left behind by this plugin',
				'Eventos de WP-Cron programados que dejó este plugin',
				'Esdeveniments de WP-Cron programats que ha deixat aquest plugin'
			),
			array_unique( $items ),
			tsosi_ui_triple_text( 'cron events remain scheduled', 'quedan eventos cron programados', 'queden esdeveniments cron programats' )
		),
	);
}

/**
 * Pending Action Scheduler actions that belong to the plugin.
 *
 * @param string[] $prefixes Hook prefixes.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_scheduled_actions( $prefixes ) {
	global $wpdb;
	$table = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		return array();
	}
	$items = array();
	foreach ( array_unique( array_filter( array_map( 'sanitize_key', (array) $prefixes ) ) ) as $prefix ) {
		if ( strlen( $prefix ) < 6 ) {
			continue;
		}
		// Table name is built from $wpdb->prefix and confirmed to exist above.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
		$count = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb->prefix and was confirmed to exist above.
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . "` WHERE status = 'pending' AND hook LIKE %s", $wpdb->esc_like( $prefix ) . '%' )
		);
		if ( $count > 0 ) {
			$items[] = $prefix . '* (' . $count . ')';
		}
	}
	if ( empty( $items ) ) {
		return array();
	}
	return array(
		tsosi_usage_signal(
			'scheduled_actions',
			'review',
			tsosi_ui_triple_text(
				'Pending Action Scheduler actions',
				'Acciones pendientes de Action Scheduler',
				'Accions pendents d\'Action Scheduler'
			),
			$items,
			tsosi_ui_triple_text( 'it has pending scheduled actions', 'tiene acciones programadas pendientes', 'té accions programades pendents' )
		),
	);
}

/**
 * Pages assigned to a template file shipped by the plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_page_templates( $plugin_file ) {
	global $wpdb;
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	if ( '' === $folder || strlen( $folder ) < 4 ) {
		return array();
	}
	$like = '%' . $wpdb->esc_like( $folder ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only usage check.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_page_template' AND meta_value LIKE %s LIMIT 10",
			$like
		)
	);
	if ( empty( $ids ) ) {
		return array();
	}
	$items = array();
	foreach ( $ids as $post_id ) {
		$title   = get_the_title( (int) $post_id );
		$items[] = ( '' !== $title ? $title : '#' . (int) $post_id ) . ' (#' . (int) $post_id . ')';
	}
	return array(
		tsosi_usage_signal(
			'page_templates',
			'danger',
			tsosi_ui_triple_text(
				'Pages use a page template provided by this plugin',
				'Hay páginas que usan una plantilla de página de este plugin',
				'Hi ha pàgines que usen una plantilla de pàgina d\'aquest plugin'
			),
			$items,
			tsosi_ui_triple_text( 'pages use its template', 'páginas usan su plantilla', 'pàgines usen la seva plantilla' )
		),
	);
}

/**
 * Drop-ins, mu-plugins, root .htaccess and data folders that point at the plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_collect_environment( $plugin_file ) {
	$slug = tsosi_usage_plugin_slug( $plugin_file );
	if ( strlen( $slug ) < 4 ) {
		return array();
	}
	$needles = array_unique( array( strtolower( $slug ), strtolower( str_replace( '-', '_', $slug ) ) ) );
	$segment = strtolower( (string) strtok( $slug, '-_' ) );
	if ( strlen( $segment ) >= 7 && ! in_array( $segment, array( 'wordpress', 'woocommerce', 'simple', 'custom', 'advanced', 'contact' ), true ) ) {
		$needles[] = $segment;
	}
	$quoted = array();
	foreach ( $needles as $needle ) {
		$quoted[] = preg_quote( $needle, '~' );
	}
	$regex = '~(?<![a-z0-9_])(' . implode( '|', $quoted ) . ')(?![a-z0-9_])~i';

	$signals = array();
	$hits    = array();

	if ( ! function_exists( 'get_dropins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$files = array();
	foreach ( array_keys( (array) get_dropins() ) as $dropin ) {
		$files[ 'wp-content/' . $dropin ] = trailingslashit( WP_CONTENT_DIR ) . $dropin;
	}
	if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
		foreach ( (array) glob( trailingslashit( WPMU_PLUGIN_DIR ) . '*.php' ) as $mu_file ) {
			$files[ 'mu-plugins/' . basename( (string) $mu_file ) ] = (string) $mu_file;
		}
	}
	foreach ( $files as $label => $path ) {
		$raw = tsosi_usage_read_raw( $path, 200000 );
		if ( '' !== $raw && preg_match( $regex, $raw ) ) {
			$hits[] = $label;
		}
	}
	if ( ! empty( $hits ) ) {
		$signals[] = tsosi_usage_signal(
			'dropins_mu',
			'review',
			tsosi_ui_triple_text(
				'A drop-in or mu-plugin references it (it keeps working even if the plugin is deactivated)',
				'Un drop-in o mu-plugin lo referencia (puede seguir funcionando aunque el plugin esté desactivado)',
				'Un drop-in o mu-plugin el referencia (pot seguir funcionant encara que el plugin estigui desactivat)'
			),
			$hits,
			tsosi_ui_triple_text( 'a drop-in/mu-plugin references it', 'un drop-in/mu-plugin lo referencia', 'un drop-in/mu-plugin el referencia' )
		);
	}

	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$htaccess = trailingslashit( get_home_path() ) . '.htaccess';
	$raw      = tsosi_usage_read_raw( $htaccess, 300000 );
	if ( '' !== $raw && preg_match( $regex, $raw ) ) {
		$signals[] = tsosi_usage_signal(
			'htaccess',
			'review',
			tsosi_ui_triple_text(
				'The root .htaccess contains rules that mention this plugin',
				'El .htaccess raíz contiene reglas que mencionan este plugin',
				'El .htaccess arrel conté regles que mencionen aquest plugin'
			),
			array( '.htaccess' ),
			tsosi_ui_triple_text( '.htaccess rules mention it', 'reglas de .htaccess lo mencionan', 'regles de .htaccess el mencionen' )
		);
	}

	$uploads = wp_upload_dir( null, false );
	$dirs    = array(
		trailingslashit( (string) $uploads['basedir'] ) . $slug,
		trailingslashit( WP_CONTENT_DIR ) . $slug,
		trailingslashit( WP_CONTENT_DIR ) . 'cache/' . $slug,
	);
	$found   = array();
	foreach ( $dirs as $dir ) {
		if ( is_dir( $dir ) ) {
			$found[] = ltrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $dir ) ), '/' );
		}
	}
	if ( ! empty( $found ) ) {
		$signals[] = tsosi_usage_signal(
			'data_dirs',
			'info',
			tsosi_ui_triple_text(
				'Data/cache folders named after the plugin exist on disk',
				'Existen carpetas de datos/caché con el nombre del plugin en el disco',
				'Existeixen carpetes de dades/memòria cau amb el nom del plugin al disc'
			),
			$found
		);
	}

	return $signals;
}

/**
 * Bounded read of a file (empty string when unreadable or missing).
 *
 * @param string $path  Absolute path.
 * @param int    $bytes Max bytes.
 * @return string
 */
function tsosi_usage_read_raw( $path, $bytes ) {
	if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
		return '';
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local read for detection only.
	$data = file_get_contents( $path, false, null, 0, max( 1, (int) $bytes ) );
	return is_string( $data ) ? $data : '';
}

/**
 * Code that could call into the plugins' public symbols: active theme (and parent),
 * mu-plugins and every other active plugin.
 *
 * @param string[] $exclude_files Plugin basenames to leave out.
 * @return array<int,array<string,string>> Source descriptors (type, label, dir).
 */
function tsosi_usage_corpus_sources( $exclude_files ) {
	$sources = array();

	$seen = array();
	foreach ( array( get_stylesheet(), get_template() ) as $theme_slug ) {
		$theme_slug = (string) $theme_slug;
		if ( '' === $theme_slug || isset( $seen[ $theme_slug ] ) ) {
			continue;
		}
		$seen[ $theme_slug ] = true;
		$theme               = wp_get_theme( $theme_slug );
		$sources[]           = array(
			'type'  => 'theme',
			'label' => tsosi_ui_triple_text( 'Theme', 'Tema', 'Tema' ) . ': ' . ( $theme->exists() ? (string) $theme->get( 'Name' ) : $theme_slug ),
			'dir'   => trailingslashit( get_theme_root( $theme_slug ) ) . $theme_slug,
			'file'  => '',
		);
	}

	if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
		$sources[] = array(
			'type'  => 'mu',
			'label' => 'mu-plugins',
			'dir'   => WPMU_PLUGIN_DIR,
			'file'  => '',
		);
	}

	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	$installed = tsosi_get_installed_plugins();
	foreach ( array_unique( $active ) as $file ) {
		$file = (string) $file;
		if ( in_array( $file, $exclude_files, true ) || ! isset( $installed[ $file ] ) ) {
			continue;
		}
		$folder = tsosi_get_plugin_folder_raw( $file );
		if ( '' === $folder ) {
			continue;
		}
		$sources[] = array(
			'type'  => 'plugin',
			'label' => tsosi_ui_triple_text( 'Plugin', 'Plugin', 'Plugin' ) . ': ' . ( isset( $installed[ $file ]['Name'] ) ? (string) $installed[ $file ]['Name'] : $file ),
			'dir'   => trailingslashit( WP_PLUGIN_DIR ) . $folder,
			'file'  => $file,
		);
	}

	return $sources;
}

/**
 * Search the code of the active theme, mu-plugins and the other active plugins for calls
 * into the target plugins' own functions, classes, namespaces and constants.
 *
 * A "hard" hit is code that uses a symbol directly (it would break or fatal without the
 * plugin). A "soft" hit is a guarded check (function_exists(), defined(), is_plugin_active())
 * or a plain mention of the plugin folder/basename, i.e. an optional integration.
 *
 * @param array<string,array<string,mixed>> $targets Keyed by plugin basename; each has 'facts'.
 * @param float                             $budget  Time budget in seconds.
 * @return array<string,array<string,mixed>> Hits per target: hard[], soft[], partial.
 */
function tsosi_usage_scan_corpus( $targets, $budget = TSOSI_USAGE_CROSS_TIME_BUDGET ) {
	$started = microtime( true );
	$result  = array();
	$regexes = array();

	foreach ( $targets as $file => $target ) {
		$result[ $file ] = array(
			'hard'    => array(),
			'soft'    => array(),
			'partial' => false,
		);
		$facts           = isset( $target['facts'] ) ? $target['facts'] : array();
		$idents          = array();
		$spaces          = array();
		foreach ( (array) ( $facts['tokens'] ?? array() ) as $token ) {
			$idents[] = preg_quote( (string) $token, '~' );
		}
		foreach ( (array) ( $facts['namespaces'] ?? array() ) as $space ) {
			$spaces[] = preg_quote( (string) $space, '~' );
		}
		$slug     = tsosi_usage_plugin_slug( $file );
		$literals = array();
		if ( strlen( $slug ) >= 5 ) {
			$literals[] = preg_quote( $file, '~' );
		}
		$regexes[ $file ] = array(
			'ident'   => empty( $idents ) ? '' : '~(?<![A-Za-z0-9_\\\\$>:])(' . implode( '|', $idents ) . ')(?![A-Za-z0-9_])~',
			'space'   => empty( $spaces ) ? '' : '~(?<![A-Za-z0-9_])(' . implode( '|', $spaces ) . ')~',
			'literal' => empty( $literals ) ? '' : '~[\'"](' . implode( '|', $literals ) . ')[\'"]~',
		);
	}

	$sources = tsosi_usage_corpus_sources( array_keys( $targets ) );
	foreach ( $sources as $source ) {
		$tree = tsosi_profile_scan_plugin_tree( $source['dir'] );
		foreach ( $tree['php'] as $path ) {
			if ( ( microtime( true ) - $started ) > $budget ) {
				foreach ( array_keys( $result ) as $file ) {
					$result[ $file ]['partial'] = true;
				}
				return $result;
			}
			if ( tsosi_usage_is_vendor_path( $path ) ) {
				continue;
			}
			$raw = tsosi_usage_read_raw( $path, TSOSI_USAGE_CROSS_MAX_FILE_BYTES );
			if ( '' === $raw ) {
				continue;
			}
			$rel = ltrim( substr( wp_normalize_path( $path ), strlen( wp_normalize_path( $source['dir'] ) ) ), '/' );
			foreach ( $regexes as $file => $rx ) {
				if ( '' !== $source['file'] && ( $source['file'] === $file || tsosi_usage_plugin_family( $source['file'] ) === tsosi_usage_plugin_family( $file ) ) ) {
					continue;
				}
				tsosi_usage_match_file( $raw, $rx, $source['label'] . ' — ' . $rel, $result[ $file ] );
			}
		}
	}

	return $result;
}

/**
 * Match one source file against a target's regexes.
 *
 * @param string               $raw    File contents.
 * @param array<string,string> $rx     ident/space/literal regexes.
 * @param string               $where  Label for the hit.
 * @param array<string,mixed>  $result Result bucket (modified).
 * @return void
 */
function tsosi_usage_match_file( $raw, $rx, $where, &$result ) {
	$guard = '/(function_exists|class_exists|interface_exists|method_exists|defined|is_plugin_active|is_plugin_active_for_network|get_option|did_action|has_action|has_filter|shortcode_exists)\s*\(/';

	foreach ( array( 'ident', 'space' ) as $kind ) {
		if ( '' === $rx[ $kind ] || ! preg_match_all( $rx[ $kind ], $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		$seen = 0;
		foreach ( $m[1] as $hit ) {
			if ( ++$seen > 20 ) {
				break;
			}
			$offset = (int) $hit[1];
			$start  = strrpos( substr( $raw, 0, $offset ), "\n" );
			$start  = false === $start ? 0 : $start + 1;
			$end    = strpos( $raw, "\n", $offset );
			$line   = substr( $raw, $start, false === $end ? 400 : $end - $start );
			$trim   = ltrim( $line );
			if ( '' === $trim || 0 === strpos( $trim, '//' ) || 0 === strpos( $trim, '#' ) || 0 === strpos( $trim, '*' ) || 0 === strpos( $trim, '/*' ) ) {
				continue;
			}
			// A file that declares the symbol itself bundles its own copy (shared library).
			$token = (string) $hit[0];
			if ( 'space' === $kind ) {
				if ( preg_match( '/namespace\s+' . preg_quote( rtrim( $token, '\\' ), '/' ) . '\b/', $raw ) ) {
					continue;
				}
			} elseif (
				preg_match( '/\b(?:class|interface|trait|function|const)\s+&?\s*' . preg_quote( $token, '/' ) . '\b/', $raw )
				|| preg_match( '/define\s*\(\s*[\'"]' . preg_quote( $token, '/' ) . '[\'"]/', $raw )
			) {
				continue;
			}
			// A name inside quotes is a plain mention (detection maps, option names), not a call.
			$head   = substr( $line, 0, max( 0, $offset - $start ) );
			$quoted = 1 === substr_count( $head, "'" ) % 2 || 1 === substr_count( $head, '"' ) % 2;
			$bucket = ( preg_match( $guard, $line ) || $quoted ) ? 'soft' : 'hard';
			if ( count( $result[ $bucket ] ) < 40 ) {
				$result[ $bucket ][] = $where . ' → ' . $token;
			}
			break;
		}
	}

	if ( '' !== $rx['literal'] && preg_match( $rx['literal'], $raw, $lm ) && count( $result['soft'] ) < 40 ) {
		$result['soft'][] = $where . ' → ' . $lm[1];
	}
}

/**
 * Signals built from corpus hits.
 *
 * @param array<string,mixed> $hits Hits for one plugin (hard, soft, partial).
 * @return array<int,array<string,mixed>>
 */
function tsosi_usage_signals_from_corpus( $hits ) {
	$signals = array();
	if ( ! empty( $hits['hard'] ) ) {
		$signals[] = tsosi_usage_signal(
			'code_hard',
			'danger',
			tsosi_ui_triple_text(
				'The active theme, a mu-plugin or another active plugin calls this plugin\'s functions/classes directly',
				'El tema activo, un mu-plugin u otro plugin activo llama directamente a funciones/clases de este plugin',
				'El tema actiu, un mu-plugin o un altre plugin actiu crida directament funcions/classes d\'aquest plugin'
			),
			$hits['hard'],
			tsosi_ui_triple_text( 'other code calls its functions/classes', 'otro código llama a sus funciones/clases', 'un altre codi crida les seves funcions/classes' )
		);
	}
	if ( ! empty( $hits['soft'] ) ) {
		$signals[] = tsosi_usage_signal(
			'code_soft',
			'info',
			tsosi_ui_triple_text(
				'Other code mentions it in a guarded check or by folder name (usually an optional integration)',
				'Otro código lo menciona en una comprobación protegida o por nombre de carpeta (normalmente una integración opcional)',
				'Un altre codi el menciona en una comprovació protegida o pel nom de carpeta (normalment una integració opcional)'
			),
			$hits['soft']
		);
	}
	if ( ! empty( $hits['partial'] ) ) {
		$signals[] = tsosi_usage_signal(
			'code_partial',
			'info',
			tsosi_ui_triple_text(
				'The code cross-check hit its time limit; some files were not read',
				'La comprobación cruzada de código alcanzó el límite de tiempo; algunos archivos no se leyeron',
				'La comprovació creuada de codi ha arribat al límit de temps; alguns fitxers no s\'han llegit'
			)
		);
	}
	return $signals;
}

/**
 * Collect every usage signal for one plugin.
 *
 * @param string              $plugin_file Plugin basename.
 * @param array<string,mixed> $args        corpus: precomputed corpus hits (null = compute); prefixes: extra name prefixes.
 * @return array<string,mixed> { signals: array, active: bool, checked: string[], title: string }
 */
function tsosi_usage_collect( $plugin_file, $args = array() ) {
	$plugin_file = tsosi_sanitize_plugin_file( $plugin_file );
	$out         = array(
		'signals'  => array(),
		'active'   => false,
		'coverage' => array(),
		'title'    => tsosi_ui_triple_text( 'Usage signals found (red/amber ones block uninstalling; grey ones are informational)', 'Señales de uso encontradas (las rojas/ámbar impiden desinstalar; las grises son informativas)', 'Senyals d\'ús trobats (els vermells/ambre impedeixen desinstal·lar; els grisos són informatius)' ),
		'checked'  => array(),
	);
	if ( '' === $plugin_file ) {
		return $out;
	}

	$facts    = tsosi_usage_static_facts( $plugin_file );
	$prefixes = isset( $args['prefixes'] ) ? (array) $args['prefixes'] : array();
	$slug_us  = sanitize_key( str_replace( '-', '_', tsosi_usage_plugin_slug( $plugin_file ) ) );
	if ( strlen( $slug_us ) >= 5 ) {
		$prefixes[] = $slug_us;
	}

	$signals = array();
	$signals = array_merge( $signals, tsosi_usage_collect_activation( $plugin_file ) );
	$signals = array_merge( $signals, tsosi_usage_collect_runtime( $plugin_file ) );
	$signals = array_merge( $signals, tsosi_usage_collect_known( $plugin_file ) );
	$front   = tsosi_usage_collect_frontend( $plugin_file, $prefixes );
	$signals = array_merge( $signals, $front );
	// Hooking into page output only suggests visible output. When the public pages were
	// read in full and show nothing of this plugin, that suspicion is refuted: keep it as information.
	if ( tsosi_is_plugin_active_file( $plugin_file ) ) {
		$signals = tsosi_usage_relax_frontend_hooks( $signals, tsosi_usage_frontend_snapshot() );
	}
	$signals = array_merge( $signals, tsosi_usage_collect_dependents( $plugin_file ) );
	$signals = array_merge( $signals, tsosi_usage_collect_content_types( $plugin_file, $facts ) );
	$signals = array_merge( $signals, tsosi_usage_collect_tables( $plugin_file, $facts, $prefixes ) );
	$signals = array_merge( $signals, tsosi_usage_collect_roles( $facts ) );
	$signals = array_merge( $signals, tsosi_usage_collect_builder_widgets( $facts ) );
	$signals = array_merge( $signals, tsosi_usage_collect_inactive_widgets( $prefixes ) );
	$signals = array_merge( $signals, tsosi_usage_collect_cron_leftovers( $plugin_file, $facts, $prefixes ) );
	$signals = array_merge( $signals, tsosi_usage_collect_scheduled_actions( $prefixes ) );
	$signals = array_merge( $signals, tsosi_usage_collect_page_templates( $plugin_file ) );
	$signals = array_merge( $signals, tsosi_usage_collect_environment( $plugin_file ) );

	if ( isset( $args['corpus'] ) && is_array( $args['corpus'] ) ) {
		$hits = $args['corpus'];
	} else {
		$scan = tsosi_usage_scan_corpus( array( $plugin_file => array( 'facts' => $facts ) ) );
		$hits = isset( $scan[ $plugin_file ] ) ? $scan[ $plugin_file ] : array();
	}
	$signals = array_merge( $signals, tsosi_usage_signals_from_corpus( $hits ) );

	// What was actually looked at: a clean result is only as good as its coverage.
	$front_cover = 'na';
	if ( tsosi_is_plugin_active_file( $plugin_file ) ) {
		$snapshot    = tsosi_usage_frontend_snapshot();
		$front_cover = ! empty( $snapshot['complete'] ) ? 'complete' : 'partial';
	}
	$out['coverage'] = array(
		'front' => $front_cover,
		'code'  => ! empty( $hits['partial'] ) ? 'partial' : 'complete',
	);

	$order = array(
		'danger' => 0,
		'review' => 1,
		'info'   => 2,
	);
	usort(
		$signals,
		static function ( $a, $b ) use ( $order ) {
			return $order[ $a['severity'] ] - $order[ $b['severity'] ];
		}
	);

	$out['signals'] = $signals;
	$out['active']  = tsosi_is_plugin_active_file( $plugin_file );
	$out['checked'] = array(
		tsosi_ui_triple_text( 'activation state (site, network, other sites, loaded by code)', 'estado de activación (sitio, red, otros sitios, cargado por código)', 'estat d\'activació (lloc, xarxa, altres llocs, carregat per codi)' ),
		tsosi_ui_triple_text( 'hooks, cron, REST routes and widgets in memory', 'hooks, cron, rutas REST y widgets en memoria', 'hooks, cron, rutes REST i widgets en memòria' ),
		tsosi_ui_triple_text( 'files and markup of the plugin in the HTML of a sample of public pages (home, latest posts, shop pages, one entry and archive per content type, one page per template)', 'archivos y marcado del plugin en el HTML de una muestra de páginas públicas (portada, últimas entradas, páginas de tienda, una entrada y archivo por tipo de contenido, una página por plantilla)', 'fitxers i marcat del plugin a l\'HTML d\'una mostra de pàgines públiques (portada, últimes entrades, pàgines de botiga, una entrada i arxiu per tipus de contingut, una pàgina per plantilla)' ),
		tsosi_ui_triple_text( 'dependencies declared by other plugins', 'dependencias declaradas por otros plugins', 'dependències declarades per altres plugins' ),
		tsosi_ui_triple_text( 'custom post types, taxonomies and roles with data', 'tipos de contenido, taxonomías y roles con datos', 'tipus de contingut, taxonomies i rols amb dades' ),
		tsosi_ui_triple_text( 'own database tables and pending scheduled actions', 'tablas propias y acciones programadas pendientes', 'taules pròpies i accions programades pendents' ),
		tsosi_ui_triple_text( 'page templates and builder widgets placed in pages', 'plantillas de página y widgets de builder colocados en páginas', 'plantilles de pàgina i widgets de builder col·locats a pàgines' ),
		tsosi_ui_triple_text( 'drop-ins, mu-plugins, .htaccess and data folders', 'drop-ins, mu-plugins, .htaccess y carpetas de datos', 'drop-ins, mu-plugins, .htaccess i carpetes de dades' ),
		tsosi_ui_triple_text( 'calls from the active theme, mu-plugins and other active plugins', 'llamadas desde el tema activo, mu-plugins y otros plugins activos', 'crides des del tema actiu, mu-plugins i altres plugins actius' ),
	);
	return $out;
}

/**
 * Merge usage signals into a scan risk. A plugin that is running, or that other code or
 * content depends on, is never reported as "safe to uninstall".
 *
 * Danger signals always block. Review signals never escalate to danger by themselves; their
 * summed weight only sets the priority of the review and the order of the reasons. The
 * result also carries how much to trust the verdict (confidence) and why.
 *
 * @param array<string,mixed> $risk  Risk from tsosi_scan_assess_risk().
 * @param array<string,mixed> $usage Result of tsosi_usage_collect().
 * @return array<string,mixed>
 */
function tsosi_usage_apply_risk( $risk, $usage ) {
	$signals    = isset( $usage['signals'] ) && is_array( $usage['signals'] ) ? $usage['signals'] : array();
	$coverage   = isset( $usage['coverage'] ) && is_array( $usage['coverage'] ) ? $usage['coverage'] : array();
	$base_level = isset( $risk['level'] ) ? (string) $risk['level'] : 'safe';
	$danger     = array();
	$review     = array();
	foreach ( $signals as $signal ) {
		if ( 'danger' === $signal['severity'] ) {
			$danger[] = $signal;
		} elseif ( 'review' === $signal['severity'] ) {
			$review[] = $signal;
		}
	}
	$review = tsosi_usage_sort_by_weight( $review );
	$score  = 0;
	foreach ( $review as $signal ) {
		$profile = tsosi_usage_signal_profile( $signal['id'], 'review' );
		$score  += $profile['weight'];
	}

	$risk['usage_danger'] = count( $danger );
	$risk['usage_review'] = count( $review );
	$risk['reasons']      = array_values( array_merge( wp_list_pluck( $danger, 'summary' ), wp_list_pluck( $review, 'summary' ) ) );
	$risk['score']        = $score;
	$risk['priority']     = '';

	$confidence              = tsosi_usage_confidence( $base_level, $danger, $review, $coverage );
	$risk['confidence']      = $confidence['level'];
	$risk['confidence_note'] = $confidence['note'];

	if ( empty( $danger ) && empty( $review ) ) {
		// Activated, but nothing depends on it: say so instead of implying it is in use.
		$is_active = false;
		foreach ( $signals as $signal ) {
			if ( 'active' === $signal['id'] ) {
				$is_active = true;
				break;
			}
		}
		if ( $is_active && 'safe' === $base_level ) {
			$risk['label'] = tsosi_ui_triple_text(
				'No real usage found. The plugin is only activated: deactivate it, check the site and then uninstall',
				'No se encontró uso real. El plugin solo está activado: desactívalo, comprueba el sitio y luego desinstala',
				'No s\'ha trobat ús real. El plugin només està activat: desactiva\'l, comprova el lloc i després desinstal·la'
			);
		}
		return $risk;
	}

	if ( ! empty( $danger ) ) {
		$risk['level'] = 'danger';
		$risk['label'] = tsosi_ui_triple_text(
			'Do not uninstall — this plugin is still in use',
			'No desinstales — este plugin sigue en uso',
			'No desinstal·lis — aquest plugin continua en ús'
		);
		// The reasons are listed right below the verdict; repeating them here only adds noise.
		$risk['help'] = '';
		return $risk;
	}

	// Only "review" level traces.
	$risk['priority'] = tsosi_usage_score_priority( $score );
	if ( 'safe' === $base_level ) {
		$risk['level'] = 'review';
		if ( 'low' === $risk['priority'] ) {
			$risk['label'] = tsosi_ui_triple_text(
				'Only minor traces left — low risk, but review them before uninstalling',
				'Solo quedan huellas menores — riesgo bajo, pero revísalas antes de desinstalar',
				'Només queden empremtes menors — risc baix, però revisa-les abans de desinstal·lar'
			);
		} elseif ( 'high' === $risk['priority'] ) {
			$risk['label'] = tsosi_ui_triple_text(
				'No content found, but the plugin left important traces — check them before uninstalling',
				'No se encontró contenido, pero el plugin dejó huellas importantes — compruébalas antes de desinstalar',
				'No s\'ha trobat contingut, però el plugin ha deixat empremtes importants — comprova-les abans de desinstal·lar'
			);
		} else {
			$risk['label'] = tsosi_ui_triple_text(
				'No content found, but the plugin left other traces — review before uninstalling',
				'No se encontró contenido, pero el plugin dejó otras huellas — revisa antes de desinstalar',
				'No s\'ha trobat contingut, però el plugin ha deixat altres empremtes — revisa abans de desinstal·lar'
			);
		}
	}
	$risk['total'] = (int) ( $risk['total'] ?? 0 ) + count( $review );
	return $risk;
}

add_action( 'plugins_loaded', 'tsosi_usage_capture_register', 1 );

/**
 * Usage signals for a theme (parent/child relations, page templates in use, content
 * types it registers, other network sites, fallback theme).
 *
 * @param string $stylesheet Theme stylesheet slug.
 * @return array<string,mixed> Same shape as tsosi_usage_collect().
 */
function tsosi_usage_collect_theme( $stylesheet ) {
	global $wpdb;

	$out   = array(
		'signals' => array(),
		'active'  => false,
		'title'   => tsosi_ui_triple_text( 'Other signs that the theme is in use', 'Otras señales de que el tema está en uso', 'Altres senyals que el tema està en ús' ),
		'checked' => array(
			tsosi_ui_triple_text( 'active / parent of the active theme / child themes', 'activo / padre del tema activo / temas hijo', 'actiu / pare del tema actiu / temes fill' ),
			tsosi_ui_triple_text( 'pages assigned to its page templates', 'páginas asignadas a sus plantillas de página', 'pàgines assignades a les seves plantilles de pàgina' ),
			tsosi_ui_triple_text( 'custom post types and taxonomies it registers', 'tipos de contenido y taxonomías que registra', 'tipus de contingut i taxonomies que registra' ),
			tsosi_ui_triple_text( 'other network sites and default fallback theme', 'otros sitios de la red y tema por defecto', 'altres llocs de la xarxa i tema per defecte' ),
		),
	);
	$theme = wp_get_theme( $stylesheet );
	if ( ! $theme->exists() ) {
		return $out;
	}
	$slug    = (string) $theme->get_stylesheet();
	$signals = array();

	if ( get_stylesheet() === $slug ) {
		$out['active'] = true;
		$signals[]     = tsosi_usage_signal(
			'theme_active',
			'danger',
			tsosi_ui_triple_text( 'It is the active theme', 'Es el tema activo', 'És el tema actiu' ),
			array(),
			tsosi_ui_triple_text( 'it is the active theme', 'es el tema activo', 'és el tema actiu' )
		);
	} elseif ( get_template() === $slug ) {
		$signals[] = tsosi_usage_signal(
			'theme_parent',
			'danger',
			tsosi_ui_triple_text( 'It is the parent of the active child theme', 'Es el tema padre del tema hijo activo', 'És el tema pare del tema fill actiu' ),
			array( get_stylesheet() ),
			tsosi_ui_triple_text( 'parent of the active child theme', 'padre del tema hijo activo', 'pare del tema fill actiu' )
		);
	}

	$children = array();
	foreach ( wp_get_themes() as $other_slug => $other ) {
		if ( (string) $other_slug !== $slug && (string) $other->get_template() === $slug ) {
			$children[] = (string) $other->get( 'Name' ) . ' (' . $other_slug . ')';
		}
	}
	if ( ! empty( $children ) ) {
		$signals[] = tsosi_usage_signal(
			'theme_children',
			'review',
			tsosi_ui_triple_text( 'Installed child themes depend on it', 'Hay temas hijo instalados que dependen de él', 'Hi ha temes fill instal·lats que en depenen' ),
			$children,
			tsosi_ui_triple_text( 'child themes depend on it', 'temas hijo dependen de él', 'temes fill en depenen' )
		);
	}

	$templates = array_keys( (array) $theme->get_page_templates() );
	if ( ! empty( $templates ) ) {
		$in = implode( ',', array_fill( 0, count( $templates ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- bounded admin-only usage check.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_page_template' AND meta_value IN ({$in}) LIMIT 10", ...$templates ) );
		if ( ! empty( $ids ) ) {
			$items = array();
			foreach ( $ids as $post_id ) {
				$title   = get_the_title( (int) $post_id );
				$items[] = ( '' !== $title ? $title : '#' . (int) $post_id ) . ' (#' . (int) $post_id . ')';
			}
			$signals[] = tsosi_usage_signal(
				'theme_templates',
				'danger',
				tsosi_ui_triple_text( 'Pages are assigned to this theme\'s page templates', 'Hay páginas asignadas a plantillas de este tema', 'Hi ha pàgines assignades a plantilles d\'aquest tema' ),
				$items,
				tsosi_ui_triple_text( 'pages use its page templates', 'páginas usan sus plantillas', 'pàgines usen les seves plantilles' )
			);
		}
	}

	// Content types registered by the theme.
	$facts = array(
		'post_types' => array(),
		'taxonomies' => array(),
	);
	$tree  = tsosi_profile_scan_plugin_tree( (string) $theme->get_stylesheet_directory() );
	foreach ( $tree['php'] as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		$consts              = tsosi_usage_file_consts( $content );
		$facts['post_types'] = array_merge( $facts['post_types'], tsosi_usage_first_arg( $content, 'register_post_type', $consts ) );
		$facts['taxonomies'] = array_merge( $facts['taxonomies'], tsosi_usage_first_arg( $content, 'register_taxonomy', $consts ) );
	}
	tsosi_profile_memo_flush_sources();
	$signals = array_merge( $signals, tsosi_usage_collect_content_types( '', $facts, 'theme:' . $slug ) );

	if ( is_multisite() && function_exists( 'get_sites' ) ) {
		$others = array();
		foreach ( (array) get_sites(
			array(
				'fields' => 'ids',
				'number' => 200,
			)
		) as $site_id ) {
			if ( get_current_blog_id() === (int) $site_id ) {
				continue;
			}
			if ( (string) get_blog_option( (int) $site_id, 'stylesheet', '' ) === $slug || (string) get_blog_option( (int) $site_id, 'template', '' ) === $slug ) {
				$others[] = '#' . (int) $site_id;
			}
		}
		if ( ! empty( $others ) ) {
			$signals[] = tsosi_usage_signal(
				'theme_other_sites',
				'danger',
				tsosi_ui_triple_text( 'Active on other sites of the network', 'Activo en otros sitios de la red', 'Actiu en altres llocs de la xarxa' ),
				$others,
				tsosi_ui_triple_text( 'active on other network sites', 'activo en otros sitios de la red', 'actiu en altres llocs de la xarxa' )
			);
		}
	}

	if ( defined( 'WP_DEFAULT_THEME' ) && WP_DEFAULT_THEME === $slug && get_stylesheet() !== $slug ) {
		$signals[] = tsosi_usage_signal(
			'theme_fallback',
			'review',
			tsosi_ui_triple_text( 'It is the configured default/fallback theme', 'Es el tema por defecto/de respaldo configurado', 'És el tema per defecte/de reserva configurat' ),
			array(),
			tsosi_ui_triple_text( 'it is the fallback theme', 'es el tema de respaldo', 'és el tema de reserva' )
		);
	}

	$out['signals'] = $signals;
	return $out;
}
