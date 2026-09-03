<?php
/**
 * Discover shortcodes, blocks, and meta prefixes owned by each plugin.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_PROFILE_MAX_FILE_BYTES' ) ) {
	define( 'TSOSI_PROFILE_MAX_FILE_BYTES', 262144 );
}

if ( ! defined( 'TSOSI_PROFILE_MAX_FILES' ) ) {
	define( 'TSOSI_PROFILE_MAX_FILES', 40 );
}

if ( ! defined( 'TSOSI_PROFILE_MAX_BLOCK_JSON' ) ) {
	define( 'TSOSI_PROFILE_MAX_BLOCK_JSON', 30 );
}

/**
 * Installed plugins keyed by plugin file.
 *
 * @return array<string,array<string,string>>
 */
function tsosi_get_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return get_plugins();
}

/**
 * @param string $plugin_file Plugin basename.
 * @return string Folder slug relative to wp-content/plugins (unsanitized).
 */
function tsosi_get_plugin_folder_raw( $plugin_file ) {
	$folder = dirname( (string) $plugin_file );
	if ( '.' === $folder || '' === $folder ) {
		return '';
	}
	return $folder;
}

/**
 * @param string $plugin_file Plugin basename.
 * @return string Sanitized folder key for maps/logs.
 */
function tsosi_get_plugin_folder( $plugin_file ) {
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	return '' === $folder ? '' : sanitize_key( $folder );
}

/**
 * Absolute path to a plugin directory.
 *
 * @param string $plugin_file Plugin basename.
 * @return string
 */
function tsosi_get_plugin_dir( $plugin_file ) {
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	if ( '' === $folder ) {
		return trailingslashit( WP_PLUGIN_DIR );
	}
	return trailingslashit( WP_PLUGIN_DIR ) . $folder;
}

/**
 * Whether a plugin is active.
 *
 * @param string $plugin_file Plugin basename.
 * @return bool
 */
function tsosi_is_plugin_active_file( $plugin_file ) {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( (string) $plugin_file );
}

/**
 * Validate a plugin basename against installed plugins (blocks path traversal).
 *
 * @param string $plugin_file Plugin basename from request or UI.
 * @return string Sanitized basename, or empty when not an installed plugin.
 */
function tsosi_sanitize_plugin_file( $plugin_file ) {
	$plugin_file = sanitize_text_field( (string) $plugin_file );
	if ( '' === $plugin_file || false !== strpos( $plugin_file, '..' ) ) {
		return '';
	}
	$plugins = tsosi_get_installed_plugins();
	if ( ! isset( $plugins[ $plugin_file ] ) ) {
		return '';
	}
	return $plugin_file;
}

/**
 * Collect PHP file paths under a plugin directory (bounded).
 *
 * @param string $plugin_dir Absolute plugin path.
 * @return string[]
 */
function tsosi_profile_collect_php_files( $plugin_dir ) {
	$plugin_dir = wp_normalize_path( (string) $plugin_dir );
	if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
		return array();
	}

	$files   = array();
	$skipped = array( 'vendor', 'node_modules', 'tests', 'test', 'languages' );
	$iter    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iter as $file ) {
		if ( count( $files ) >= TSOSI_PROFILE_MAX_FILES ) {
			break;
		}
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$path = wp_normalize_path( $file->getPathname() );
		foreach ( $skipped as $part ) {
			if ( false !== strpos( $path, '/' . $part . '/' ) ) {
				continue 2;
			}
		}
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$files[] = $path;
	}

	return $files;
}

/**
 * Collect block.json manifests under a plugin directory.
 *
 * @param string $plugin_dir Absolute plugin path.
 * @return string[]
 */
function tsosi_profile_collect_block_json_files( $plugin_dir ) {
	$plugin_dir = wp_normalize_path( (string) $plugin_dir );
	if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
		return array();
	}

	$files   = array();
	$skipped = array( 'vendor', 'node_modules', 'tests', 'test' );
	$iter    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iter as $file ) {
		if ( count( $files ) >= TSOSI_PROFILE_MAX_BLOCK_JSON ) {
			break;
		}
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		if ( 'block.json' !== strtolower( $file->getFilename() ) ) {
			continue;
		}
		$path = wp_normalize_path( $file->getPathname() );
		foreach ( $skipped as $part ) {
			if ( false !== strpos( $path, '/' . $part . '/' ) ) {
				continue 2;
			}
		}
		$files[] = $path;
	}

	return $files;
}

/**
 * Parse a block name from block.json contents.
 *
 * @param string $path block.json path.
 * @return string
 */
function tsosi_profile_block_name_from_json_path( $path ) {
	$content = tsosi_profile_read_file( $path );
	if ( '' === $content ) {
		return '';
	}
	if ( preg_match( '/"name"\s*:\s*"([a-z0-9\/_-]+)"/i', $content, $match ) ) {
		return sanitize_text_field( (string) $match[1] );
	}
	return '';
}

/**
 * Resolve a block.json path referenced in PHP relative to the source file.
 *
 * @param string $php_file  PHP file path.
 * @param string $json_hint Path fragment from register_block_type().
 * @return string
 */
function tsosi_profile_resolve_block_json_path( $php_file, $json_hint ) {
	$json_hint = wp_normalize_path( (string) $json_hint );
	if ( '' === $json_hint ) {
		return '';
	}
	if ( is_file( $json_hint ) ) {
		return $json_hint;
	}
	$base = wp_normalize_path( dirname( $php_file ) );
	$candidates = array(
		$base . '/' . ltrim( $json_hint, './' ),
		$base . '/' . $json_hint,
	);
	foreach ( $candidates as $candidate ) {
		if ( is_file( $candidate ) ) {
			return wp_normalize_path( $candidate );
		}
	}
	return '';
}

/**
 * Whether a callable is defined inside a plugin directory.
 *
 * @param callable|mixed $callback   Registered callback.
 * @param string         $plugin_dir Absolute plugin directory path.
 * @return bool
 */
function tsosi_profile_callback_in_dir( $callback, $plugin_dir ) {
	$plugin_dir = wp_normalize_path( trailingslashit( (string) $plugin_dir ) );
	if ( '' === $plugin_dir ) {
		return false;
	}

	try {
		if ( is_string( $callback ) && is_callable( $callback ) ) {
			$ref = new ReflectionFunction( $callback );
			return 0 === strpos( wp_normalize_path( $ref->getFileName() ), $plugin_dir );
		}
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			if ( is_object( $callback[0] ) ) {
				$ref = new ReflectionClass( $callback[0] );
			} elseif ( is_string( $callback[0] ) && class_exists( $callback[0] ) ) {
				$ref = new ReflectionClass( $callback[0] );
			} else {
				return false;
			}
			return 0 === strpos( wp_normalize_path( $ref->getFileName() ), $plugin_dir );
		}
	} catch ( ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- ignore unloadable callbacks.
		return false;
	}

	return false;
}

/**
 * Shortcodes registered at runtime by an active plugin.
 *
 * @param string $plugin_dir Plugin directory path.
 * @return string[]
 */
function tsosi_discover_runtime_shortcodes_for_plugin( $plugin_dir ) {
	global $shortcode_tags;
	if ( ! is_array( $shortcode_tags ) ) {
		return array();
	}

	$tags = array();
	foreach ( $shortcode_tags as $tag => $callback ) {
		if ( tsosi_profile_callback_in_dir( $callback, $plugin_dir ) ) {
			$tags[] = tsosi_sanitize_shortcode_tag( (string) $tag );
		}
	}

	$tags = array_values( array_unique( array_filter( $tags ) ) );
	sort( $tags );
	return $tags;
}

/**
 * Blocks registered at runtime by an active plugin.
 *
 * @param string $plugin_dir Plugin directory path.
 * @return string[]
 */
function tsosi_discover_runtime_blocks_for_plugin( $plugin_dir ) {
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return array();
	}

	$registry = WP_Block_Type_Registry::get_instance();
	$blocks   = $registry->get_all_registered();
	if ( ! is_array( $blocks ) ) {
		return array();
	}

	$names = array();
	foreach ( $blocks as $block_name => $block_type ) {
		if ( ! is_string( $block_name ) || ! $block_type instanceof WP_Block_Type ) {
			continue;
		}
		if ( tsosi_profile_callback_in_dir( $block_type->render_callback, $plugin_dir ) ) {
			$names[] = sanitize_text_field( $block_name );
			continue;
		}
		if ( is_string( $block_type->editor_script ) && false !== strpos( $block_type->editor_script, tsosi_get_plugin_folder_raw_from_dir( $plugin_dir ) ) ) {
			$names[] = sanitize_text_field( $block_name );
		}
	}

	$names = array_values( array_unique( array_filter( $names ) ) );
	sort( $names );
	return $names;
}

/**
 * @param string $plugin_dir Absolute plugin directory.
 * @return string
 */
function tsosi_get_plugin_folder_raw_from_dir( $plugin_dir ) {
	$plugin_dir = wp_normalize_path( trailingslashit( (string) $plugin_dir ) );
	$plugins    = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );
	if ( 0 === strpos( $plugin_dir, $plugins ) ) {
		return substr( $plugin_dir, strlen( $plugins ) );
	}
	return basename( rtrim( $plugin_dir, '/' ) );
}

/**
 * Read bounded file contents for static analysis.
 *
 * @param string $path File path.
 * @return string
 */
function tsosi_profile_read_file( $path ) {
	if ( ! is_readable( $path ) ) {
		return '';
	}
	$size = filesize( $path );
	if ( false === $size || $size > TSOSI_PROFILE_MAX_FILE_BYTES ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded admin scan.
		return (string) file_get_contents( $path, false, null, 0, TSOSI_PROFILE_MAX_FILE_BYTES );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded admin scan.
	return (string) file_get_contents( $path );
}

/**
 * Extract shortcode tags registered in plugin PHP sources.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_shortcodes( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );
	$tags  = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/add_shortcode\s*\(\s*['\"]([a-z0-9_-]+)['\"]/i", $content, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tags[] = tsosi_sanitize_shortcode_tag( (string) $tag );
			}
		}
	}

	$tags = array_values( array_unique( array_filter( $tags ) ) );
	sort( $tags );
	return $tags;
}

/**
 * Extract block names declared in plugin sources or block.json files.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_blocks( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );
	$names = array();

	foreach ( tsosi_profile_collect_block_json_files( $dir ) as $json_path ) {
		$name = tsosi_profile_block_name_from_json_path( $json_path );
		if ( '' !== $name ) {
			$names[] = $name;
		}
	}

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/register_block_type\s*\(\s*['\"]([a-z0-9\/_-]+)['\"]/i", $content, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$names[] = sanitize_text_field( (string) $name );
			}
		}
		if ( preg_match_all( "/register_block_type\s*\(\s*[^;]*['\"]([^'\"]*block\.json)['\"]/i", $content, $json_refs ) ) {
			foreach ( $json_refs[1] as $json_hint ) {
				$json_path = tsosi_profile_resolve_block_json_path( $path, $json_hint );
				$name      = tsosi_profile_block_name_from_json_path( $json_path );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
		}
	}

	$names = array_values( array_unique( array_filter( $names ) ) );
	sort( $names );
	return $names;
}

/**
 * wp_options keys that must never become plugin prefix needles (WordPress core).
 *
 * @return string[]
 */
function tsosi_get_core_wp_option_key_blocklist() {
	return array(
		'active_plugins',
		'admin_email',
		'blog_charset',
		'blogdescription',
		'blogname',
		'can_compress_scripts',
		'category_base',
		'comment_registration',
		'comments_notify',
		'cron',
		'date_format',
		'db_version',
		'default_comment_status',
		'default_ping_status',
		'default_role',
		'gmt_offset',
		'home',
		'html_type',
		'links_updated_date_format',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'mailserver_url',
		'moderation_notify',
		'page_for_posts',
		'page_on_front',
		'permalink_structure',
		'ping_sites',
		'posts_per_page',
		'recently_activated',
		'rewrite_rules',
		'siteurl',
		'start_of_week',
		'tag_base',
		'template',
		'time_format',
		'timezone_string',
		'uninstall_plugins',
		'upload_path',
		'upload_url_path',
		'user_roles',
		'users_can_register',
		'WPLANG',
		'wp_user_roles',
	);
}

/**
 * Whether an option key should be ignored when guessing plugin prefixes.
 *
 * @param string $key Option key.
 * @return bool
 */
function tsosi_is_blocked_option_key_for_prefix_discovery( $key ) {
	$key = sanitize_key( (string) $key );
	if ( '' === $key ) {
		return true;
	}
	if ( in_array( $key, tsosi_get_core_wp_option_key_blocklist(), true ) ) {
		return true;
	}
	if ( 0 === strpos( $key, '_transient_' ) || 0 === strpos( $key, '_site_transient_' ) ) {
		return true;
	}
	return false;
}

/**
 * Derive meta/option prefix candidates from a storage key.
 *
 * @param string $key Storage key.
 * @return string[]
 */
function tsosi_profile_prefixes_from_storage_key( $key ) {
	$key = sanitize_key( (string) $key );
	if ( '' === $key ) {
		return array();
	}
	if ( 0 === strpos( $key, '_' ) ) {
		$key = substr( $key, 1 );
	}
	if ( strlen( $key ) < 3 ) {
		return array();
	}

	$parts    = preg_split( '/[_-]/', $key );
	$prefixes = array();
	if ( ! empty( $parts[0] ) && strlen( $parts[0] ) >= 5 ) {
		$prefixes[] = sanitize_key( $parts[0] );
	}
	if ( ! empty( $parts[1] ) && strlen( $parts[0] ) >= 2 && strlen( $parts[1] ) >= 2 ) {
		$prefixes[] = sanitize_key( $parts[0] . '_' . $parts[1] );
	}
	if ( ! empty( $parts[2] ) && strlen( $parts[0] ) >= 2 && strlen( $parts[1] ) >= 2 && strlen( $parts[2] ) >= 2 ) {
		$prefixes[] = sanitize_key( $parts[0] . '_' . $parts[1] . '_' . $parts[2] );
	}

	return array_values( array_unique( array_filter( $prefixes ) ) );
}

/**
 * Whether a storage key is runtime analytics (view counts), not plugin configuration.
 *
 * @param string $key Meta or option key.
 * @return bool
 */
function tsosi_is_runtime_analytics_storage_key( $key ) {
	$key = sanitize_key( ltrim( (string) $key, '_' ) );
	if ( '' === $key ) {
		return false;
	}
	return (bool) preg_match( '/(?:^|_)(view_count|page_views|post_views|views|hit_count)$/', $key );
}

/**
 * Guess postmeta / option prefixes referenced by a plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_meta_prefixes( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );
	$keys  = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/(?:get|update|delete|add)_post_meta\s*\([^,]+,\s*['\"]([_a-z0-9-]+)/i", $content, $matches ) ) {
			foreach ( $matches[1] as $key ) {
				$keys[] = sanitize_key( (string) $key );
			}
		}
	}

	$prefixes = array();
	foreach ( $keys as $key ) {
		if ( tsosi_is_runtime_analytics_storage_key( $key ) ) {
			continue;
		}
		$prefixes = array_merge( $prefixes, tsosi_profile_prefixes_from_storage_key( $key ) );
	}

	$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
	sort( $prefixes );
	return $prefixes;
}

/**
 * Guess wp_options key prefixes referenced by a plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_option_prefixes( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );
	$keys  = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/(?:get|update|delete|add)_option\s*\(\s*['\"]([_a-z0-9-]+)/i", $content, $matches ) ) {
			foreach ( $matches[1] as $key ) {
				$keys[] = sanitize_key( (string) $key );
			}
		}
	}

	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	if ( '' !== $folder ) {
		$keys[] = sanitize_key( str_replace( '-', '_', $folder ) );
	}

	$prefixes = array();
	foreach ( $keys as $key ) {
		if ( tsosi_is_blocked_option_key_for_prefix_discovery( $key ) ) {
			continue;
		}
		$prefixes = array_merge( $prefixes, tsosi_profile_prefixes_from_storage_key( $key ) );
	}

	$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
	sort( $prefixes );
	return $prefixes;
}

/**
 * Build a profile for one plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return array<string,mixed>
 */
function tsosi_build_plugin_profile( $plugin_file ) {
	$plugin_file = tsosi_sanitize_plugin_file( $plugin_file );
	if ( '' === $plugin_file ) {
		return array(
			'plugin_file'     => '',
			'name'            => '',
			'active'          => false,
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array(),
		);
	}
	$plugins     = tsosi_get_installed_plugins();
	$header      = isset( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ] : array();
	$name        = isset( $header['Name'] ) ? (string) $header['Name'] : $plugin_file;
	$plugin_dir  = tsosi_get_plugin_dir( $plugin_file );

	$shortcodes = tsosi_discover_plugin_shortcodes( $plugin_file );
	$blocks     = tsosi_discover_plugin_blocks( $plugin_file );
	$prefixes   = tsosi_discover_plugin_meta_prefixes( $plugin_file );
	$opt_prefix = tsosi_discover_plugin_option_prefixes( $plugin_file );

	if ( tsosi_is_plugin_active_file( $plugin_file ) ) {
		$shortcodes = array_values( array_unique( array_merge( $shortcodes, tsosi_discover_runtime_shortcodes_for_plugin( $plugin_dir ) ) ) );
		$blocks     = array_values( array_unique( array_merge( $blocks, tsosi_discover_runtime_blocks_for_plugin( $plugin_dir ) ) ) );
		sort( $shortcodes );
		sort( $blocks );
	}

	return array(
		'plugin_file'     => $plugin_file,
		'name'            => $name,
		'active'          => tsosi_is_plugin_active_file( $plugin_file ),
		'shortcodes'      => $shortcodes,
		'blocks'          => $blocks,
		'meta_prefixes'   => tsosi_refine_prefix_list( $prefixes ),
		'option_prefixes' => tsosi_refine_prefix_list( $opt_prefix ),
	);
}

/**
 * Cached map of all plugin profiles.
 *
 * @param bool $force_refresh Skip cache.
 * @return array<string,array<string,mixed>>
 */
function tsosi_get_all_plugin_profiles( $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = get_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$profiles = array();
	foreach ( array_keys( tsosi_get_installed_plugins() ) as $plugin_file ) {
		$profiles[ $plugin_file ] = tsosi_build_plugin_profile( $plugin_file );
	}

	set_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE, $profiles, DAY_IN_SECONDS );
	return $profiles;
}

/**
 * Flush cached plugin profiles when plugins change.
 *
 * @return void
 */
function tsosi_flush_plugin_profile_cache() {
	delete_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE );
}
add_action( 'activated_plugin', 'tsosi_flush_plugin_profile_cache' );
add_action( 'deactivated_plugin', 'tsosi_flush_plugin_profile_cache' );
add_action( 'deleted_plugin', 'tsosi_flush_plugin_profile_cache' );
add_action( 'upgrader_process_complete', 'tsosi_flush_plugin_profile_cache' );

/**
 * Admin URL for TSO Options & Tables Cleaner when installed.
 *
 * @return string
 */
function tsosi_get_options_cleaner_url() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active( 'tso-options-tables-cleaner/tso-options-tables-cleaner.php' ) ) {
		return '';
	}
	return admin_url( 'tools.php?page=tso-options-tables-cleaner' );
}
