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
	define( 'TSOSI_PROFILE_MAX_FILES', 300 );
}

if ( ! defined( 'TSOSI_PROFILE_MAX_BLOCK_JSON' ) ) {
	define( 'TSOSI_PROFILE_MAX_BLOCK_JSON', 200 );
}

if ( ! defined( 'TSOSI_PROFILE_MAX_JS_FILES' ) ) {
	define( 'TSOSI_PROFILE_MAX_JS_FILES', 80 );
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
 * Returns the raw plugin folder name.
 *
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
 * Per-request memo shared by the plugin-profile helpers.
 *
 * "tree" holds the (cheap) file listing per plugin directory for the whole request;
 * "src" holds comment-stripped file contents and is flushed after each plugin profile
 * so that at most one plugin's sources are kept in memory at a time.
 *
 * @return array<string,array<string,mixed>>
 */
function &tsosi_profile_memo_store() {
	static $store = array(
		'tree'  => array(),
		'src'   => array(),
		'bytes' => 0,
	);
	return $store;
}

/**
 * Drop the memoized file contents (call once a plugin profile has been built).
 *
 * @return void
 */
function tsosi_profile_memo_flush_sources() {
	$store          = &tsosi_profile_memo_store();
	$store['src']   = array();
	$store['bytes'] = 0;
}

/**
 * Walk a plugin directory once and collect the PHP files and block.json manifests
 * (sorted deterministically and capped), pruning vendor/node_modules/tests folders
 * instead of walking into them. The result is memoized per request: profiling a plugin
 * used to walk its tree once per signature type (shortcodes, blocks, meta, options,
 * transients, widgets, block.json), which is what made the first load so slow.
 *
 * @param string $plugin_dir Absolute plugin path.
 * @return array{php:string[],json:string[],js:string[]}
 */
function tsosi_profile_scan_plugin_tree( $plugin_dir ) {
	$plugin_dir = untrailingslashit( wp_normalize_path( (string) $plugin_dir ) );
	$store      = &tsosi_profile_memo_store();
	if ( isset( $store['tree'][ $plugin_dir ] ) ) {
		return $store['tree'][ $plugin_dir ];
	}

	$tree = array(
		'php'  => array(),
		'json' => array(),
		'js'   => array(),
	);
	if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
		$store['tree'][ $plugin_dir ] = $tree;
		return $tree;
	}

	$skipped_dirs = array( 'vendor', 'node_modules', 'tests', 'test', 'languages' );

	// Listing paths (without reading contents) is cheap even for large plugins, so we
	// gather every candidate first and only cap after sorting. RecursiveDirectoryIterator
	// order is filesystem-dependent; capping while iterating made the same plugin profile
	// differently across runs/servers and could silently drop the file that matters (e.g.
	// the main plugin file with add_shortcode()) depending on directory order alone.
	try {
		$dir_iter = new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS );
		$filtered = new RecursiveCallbackFilterIterator(
			$dir_iter,
			static function ( $current, $key, $iterator ) use ( $skipped_dirs ) {
				if ( $iterator->hasChildren() ) {
					return ! in_array( $current->getFilename(), $skipped_dirs, true );
				}
				return true;
			}
		);
		// CATCH_GET_CHILD: an unreadable sub-directory is skipped instead of aborting the walk.
		$iter = new RecursiveIteratorIterator( $filtered, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );

		foreach ( $iter as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			if ( 'block.json' === strtolower( $file->getFilename() ) ) {
				$tree['json'][] = $path;
				continue;
			}
			if ( 'js' === strtolower( $file->getExtension() ) ) {
				$tree['js'][] = $path;
				continue;
			}
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			// Relative path (starts with "/") so folders above the plugin never match.
			if ( false !== strpos( substr( $path, strlen( $plugin_dir ) ), '/languages/' ) ) {
				continue;
			}
			$tree['php'][] = $path;
		}
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- an unreadable plugin folder simply yields fewer files.
		unset( $e );
	}

	$tree['php']  = tsosi_profile_sort_and_cap_files( $tree['php'], $plugin_dir, TSOSI_PROFILE_MAX_FILES );
	$tree['json'] = tsosi_profile_sort_and_cap_files( $tree['json'], $plugin_dir, TSOSI_PROFILE_MAX_BLOCK_JSON );
	$tree['js']   = tsosi_profile_sort_and_cap_files( $tree['js'], $plugin_dir, TSOSI_PROFILE_MAX_JS_FILES );

	$store['tree'][ $plugin_dir ] = $tree;
	return $tree;
}

/**
 * Collect PHP file paths under a plugin directory (bounded).
 *
 * @param string $plugin_dir Absolute plugin path.
 * @return string[]
 */
function tsosi_profile_collect_php_files( $plugin_dir ) {
	$tree = tsosi_profile_scan_plugin_tree( $plugin_dir );
	return $tree['php'];
}

/**
 * PHP source files that belong to one plugin.
 *
 * A single-file plugin (e.g. "hello.php" sitting directly in wp-content/plugins) has no
 * folder of its own: its "directory" is the whole plugins folder, so walking it would
 * profile every other plugin's code under this plugin's name (and be very slow).
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_profile_plugin_php_files( $plugin_file ) {
	if ( '' === tsosi_get_plugin_folder_raw( $plugin_file ) ) {
		$path = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . basename( (string) $plugin_file ) );
		return is_file( $path ) ? array( $path ) : array();
	}
	return tsosi_profile_collect_php_files( tsosi_get_plugin_dir( $plugin_file ) );
}

/**
 * Sort collected plugin files deterministically (shallower paths first, then
 * alphabetically) and cap the list, so profiling the same plugin twice gives
 * the same result and root-level files are preferred over deeply nested ones.
 *
 * @param string[] $files      Absolute file paths.
 * @param string   $plugin_dir Plugin root directory.
 * @param int      $cap        Maximum number of files to keep.
 * @return string[]
 */
function tsosi_profile_sort_and_cap_files( $files, $plugin_dir, $cap ) {
	$plugin_dir = untrailingslashit( wp_normalize_path( (string) $plugin_dir ) );
	usort(
		$files,
		static function ( $a, $b ) use ( $plugin_dir ) {
			$rel_a   = ltrim( substr( $a, strlen( $plugin_dir ) ), '/' );
			$rel_b   = ltrim( substr( $b, strlen( $plugin_dir ) ), '/' );
			$depth_a = substr_count( $rel_a, '/' );
			$depth_b = substr_count( $rel_b, '/' );
			if ( $depth_a !== $depth_b ) {
				return $depth_a <=> $depth_b;
			}
			return strcmp( $rel_a, $rel_b );
		}
	);
	return array_slice( $files, 0, max( 0, (int) $cap ) );
}

/**
 * Collect block.json manifests under a plugin directory.
 *
 * @param string $plugin_dir Absolute plugin path.
 * @return string[]
 */
function tsosi_profile_collect_block_json_files( $plugin_dir ) {
	$tree = tsosi_profile_scan_plugin_tree( $plugin_dir );
	return $tree['json'];
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
	$base       = wp_normalize_path( dirname( $php_file ) );
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

	// Handles closures, "Class::method" strings, [object, method] arrays and invokables.
	$file = tsosi_usage_callback_file( $callback );
	return '' !== $file && 0 === strpos( $file, $plugin_dir );
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
		$folder_slug = trim( tsosi_get_plugin_folder_raw_from_dir( $plugin_dir ), '/' );
		// Script handle must be the folder slug itself or start with "{slug}-" / "{slug}_"
		// (an empty slug would match every handle on PHP 8).
		if ( '' !== $folder_slug && is_string( $block_type->editor_script ) && '' !== $block_type->editor_script
			&& ( $block_type->editor_script === $folder_slug || 0 === strpos( $block_type->editor_script, $folder_slug . '-' ) || 0 === strpos( $block_type->editor_script, $folder_slug . '_' ) )
		) {
			$names[] = sanitize_text_field( $block_name );
		}
	}

	$names = array_values( array_unique( array_filter( $names ) ) );
	sort( $names );
	return $names;
}

/**
 * Describes the given plugin directory.
 *
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
	// Every signature type reads (and tokenizes) the same files; do it once per plugin.
	$store = &tsosi_profile_memo_store();
	if ( isset( $store['src'][ $path ] ) ) {
		return $store['src'][ $path ];
	}
	if ( ! is_readable( $path ) ) {
		return '';
	}
	$size = filesize( $path );
	if ( false === $size || $size > TSOSI_PROFILE_MAX_FILE_BYTES ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded admin scan.
		$content = (string) file_get_contents( $path, false, null, 0, TSOSI_PROFILE_MAX_FILE_BYTES );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded admin scan.
		$content = (string) file_get_contents( $path );
	}

	$stripped = tsosi_profile_strip_php_comments( $content );
	// Keep at most ~24 MB of stripped sources in memory; bigger plugins are re-read on demand.
	if ( ( (int) $store['bytes'] + strlen( $stripped ) ) <= 24000000 ) {
		$store['src'][ $path ] = $stripped;
		$store['bytes']        = (int) $store['bytes'] + strlen( $stripped );
	}
	return $stripped;
}

/**
 * Strip PHP comments (// # and slash-star ... star-slash, including docblocks) from source
 * before it is regex-scanned for literal shortcode/block/option/meta keys.
 *
 * Without this, an *example* key written inside a comment or docblock -- including, very
 * concretely, this very file's own docblocks documenting the regexes below (e.g.
 * "e.g. get_option( 'fileorganizer_free_installed' )") -- is indistinguishable from a real
 * call and gets attributed to whichever plugin happens to contain that comment. A plain
 * regex comment-stripper is unsafe (it can't tell "//" or a comment-open sequence inside a
 * string literal from a real comment start), so this uses PHP's own tokenizer, which does.
 *
 * @param string $content Raw file contents (may be plain text/JSON, not just PHP).
 * @return string Content with comment token text blanked out (line breaks preserved).
 */
function tsosi_profile_strip_php_comments( $content ) {
	if ( '' === $content || ! function_exists( 'token_get_all' ) ) {
		return $content;
	}

	$tokens = @token_get_all( $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fall back to the original string on any tokenizer failure (e.g. truncated/non-PHP content).
	if ( ! is_array( $tokens ) ) {
		return $content;
	}

	$stripped = '';
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			list( $id, $text ) = $token;
			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				// Keep newlines so anything counting/matching across lines is unaffected.
				$stripped .= str_repeat( "\n", substr_count( $text, "\n" ) );
				continue;
			}
			$stripped .= $text;
		} else {
			$stripped .= $token;
		}
	}

	return $stripped;
}

/**
 * Extract shortcode tags registered in plugin PHP sources.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_shortcodes( $plugin_file ) {
	$files = tsosi_profile_plugin_php_files( $plugin_file );
	$tags  = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( false !== stripos( $content, 'add_shortcode' ) ) {
			// Literal tag, or a class/global constant that holds the tag.
			$consts = tsosi_usage_file_consts( $content );
			foreach ( tsosi_usage_first_arg( $content, 'add_shortcode', $consts ) as $tag ) {
				$tags[] = tsosi_sanitize_shortcode_tag( (string) $tag );
			}
			// Handles shortcodes registered inside a loop over an array of tags.
			if ( preg_match_all( '/add_shortcode\s*\(\s*\$([A-Za-z_][A-Za-z0-9_]*)/', $content, $vars ) ) {
				foreach ( array_unique( $vars[1] ) as $var ) {
					if ( preg_match_all( '/foreach\s*\(\s*(?:array\s*\(|\[)([^\)\]]{0,600})[\)\]]\s+as\s+(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=>\s*)?\$' . preg_quote( $var, '/' ) . '\b/', $content, $loops ) ) {
						foreach ( $loops[1] as $list ) {
							if ( preg_match_all( '/[\'"]([a-z0-9_-]+)[\'"]/i', $list, $strings ) ) {
								foreach ( $strings[1] as $tag ) {
									$tags[] = tsosi_sanitize_shortcode_tag( (string) $tag );
								}
							}
						}
					}
				}
			}
		}
		// WPBakery: vc_map( array( 'base' => 'tag', ... ) ) registers a shortcode.
		if ( false !== strpos( $content, 'vc_map' ) && preg_match_all( "/['\"]base['\"]\s*=>\s*['\"]([a-z0-9_-]+)['\"]/i", $content, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tags[] = tsosi_sanitize_shortcode_tag( (string) $tag );
			}
		}
		// Divi: modules are stored as shortcodes named after $this->slug.
		if ( false !== strpos( $content, 'ET_Builder_Module' ) && preg_match_all( "/\\\$this->slug\s*=\s*['\"]([a-z0-9_-]+)['\"]/i", $content, $matches ) ) {
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
	$files = tsosi_profile_plugin_php_files( $plugin_file );
	$names = array();

	$json_files = '' === tsosi_get_plugin_folder_raw( $plugin_file )
		? array()
		: tsosi_profile_collect_block_json_files( tsosi_get_plugin_dir( $plugin_file ) );
	foreach ( $json_files as $json_path ) {
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

	// Blocks registered only in JavaScript (registerBlockType( 'ns/name', ... )), including
	// minified build files.
	if ( '' !== tsosi_get_plugin_folder_raw( $plugin_file ) ) {
		$tree = tsosi_profile_scan_plugin_tree( tsosi_get_plugin_dir( $plugin_file ) );
		foreach ( $tree['js'] as $js_path ) {
			if ( ! is_readable( $js_path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded admin scan of plugin assets.
			$js = (string) file_get_contents( $js_path, false, null, 0, 600000 );
			if ( '' === $js || false === strpos( $js, 'registerBlockType' ) ) {
				continue;
			}
			if ( preg_match_all( '/registerBlockType\W{0,8}\(\s*[\'"]([a-z0-9-]+\/[a-z0-9-]+)[\'"]/i', $js, $matches ) ) {
				foreach ( $matches[1] as $name ) {
					$names[] = sanitize_text_field( (string) $name );
				}
			}
		}
	}

	$names = array_values( array_unique( array_filter( $names ) ) );
	sort( $names );
	return $names;
}

/**
 * Lists wp_options keys that must never become plugin prefix needles (WordPress core).
 *
 * @return string[]
 */
function tsosi_get_core_wp_option_key_blocklist() {
	return array(
		// General.
		'active_plugins',
		'admin_email',
		'new_admin_email',
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
		'default_category',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'default_role',
		'gmt_offset',
		'home',
		'html_type',
		'initial_db_version',
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
		'secret',
		'show_on_front',
		'siteurl',
		'start_of_week',
		'stylesheet',
		'stylesheet_root',
		'tag_base',
		'template',
		'template_root',
		'time_format',
		'timezone_string',
		'uninstall_plugins',
		'upload_path',
		'upload_url_path',
		'user_roles',
		'users_can_register',
		'WPLANG',
		'wp_user_roles',
		// Discussion.
		'avatar_default',
		'avatar_rating',
		'close_comments_days_old',
		'close_comments_for_old_posts',
		'comment_max_links',
		'comment_order',
		'comment_whitelist',
		'comments_per_page',
		'default_comments_page',
		'disallowed_keys',
		'moderation_keys',
		'page_comments',
		'require_name_email',
		'show_avatars',
		'thread_comments',
		'thread_comments_depth',
		// Media.
		'image_default_align',
		'image_default_link_type',
		'image_default_size',
		'large_size_h',
		'large_size_w',
		'medium_large_size_h',
		'medium_large_size_w',
		'medium_size_h',
		'medium_size_w',
		'thumbnail_crop',
		'thumbnail_size_h',
		'thumbnail_size_w',
		// Misc core state read-only by convention (never owned by a plugin).
		'blog_public',
		'nav_menu_options',
		'sidebars_widgets',
		'use_balanceTags',
		'use_smilies',
		'use_trackback',
		'widget_categories',
		'wp_page_for_privacy_policy',
	);
}

/**
 * Option key prefixes that belong to a shared third-party vendor/licensing scheme rather
 * than to any single plugin. Some plugin distribution networks (e.g. Softaculous-bundled
 * "nulled"/reseller installers) inject a common licensing option key into many unrelated
 * plugins; a plugin's source code genuinely calling get_option() on one of these keys is
 * not evidence that a *specific other* plugin found on the site is that same plugin.
 *
 * @return string[]
 */
function tsosi_get_shared_vendor_option_key_prefix_blocklist() {
	return array(
		'softaculous_',
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
	foreach ( tsosi_get_shared_vendor_option_key_prefix_blocklist() as $vendor_prefix ) {
		if ( 0 === strpos( $key, $vendor_prefix ) ) {
			return true;
		}
	}
	if ( tsosi_is_sensitive_storage_option_name( $key ) ) {
		return true;
	}
	if ( 0 === strpos( $key, '_transient_' ) || 0 === strpos( $key, '_site_transient_' ) ) {
		return true;
	}
	return false;
}

/**
 * Whether an option name must never be read or indexed by Stack Inspector (secrets / API keys).
 *
 * @param string $name Option name.
 * @return bool
 */
function tsosi_is_sensitive_storage_option_name( $name ) {
	$name = strtolower( trim( (string) $name ) );
	if ( '' === $name ) {
		return true;
	}

	// WordPress AI Client / Abilities API connectors (e.g. connectors_ai_*_api_key).
	if ( preg_match( '/^connectors_ai_.+_api_key$/', $name ) ) {
		return true;
	}
	if ( 0 === strpos( $name, 'connectors_ai_' ) && false !== strpos( $name, 'api_key' ) ) {
		return true;
	}

	$fragments = array(
		'api_key',
		'apikey',
		'api-secret',
		'apisecret',
		'client_secret',
		'clientsecret',
		'private_key',
		'privatekey',
		'secret_key',
		'secretkey',
		'auth_token',
		'access_token',
		'refresh_token',
		'bearer_token',
		'password',
		'passwd',
		'mailserver_pass',
		'smtp_pass',
		'smtp_password',
	);

	foreach ( $fragments as $fragment ) {
		if ( false !== strpos( $name, $fragment ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Derive meta/option prefix candidates from a storage key.
 *
 * The single-segment fallback (the bare first "word" of the key, e.g. "widget" out of
 * "widget_text") is only safe when the key itself is the plugin's own folder slug --
 * there it's inherently plugin-specific. When the key instead comes from scanning the
 * plugin's source for get_option()/get_post_meta() calls, that first segment is often a
 * generic English/WordPress word ("widget", "theme", "search", "license"...) that many
 * unrelated plugins and WordPress core itself also use, so a bare match against it causes
 * mass false positives (e.g. every "widget_*" core option "matching" a plugin that merely
 * happens to read one such option). Callers pass $allow_bare_segment = false for anything
 * discovered from source scanning, and leave it true only for the plugin's own folder slug.
 *
 * @param string $key                  Storage key.
 * @param bool   $allow_bare_segment   Whether the bare first segment alone may become a
 *                                     prefix candidate. Default true.
 * @return string[]
 */
function tsosi_profile_prefixes_from_storage_key( $key, $allow_bare_segment = true ) {
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
	if ( $allow_bare_segment && ! empty( $parts[0] ) && strlen( $parts[0] ) >= 5 ) {
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
 * Literal heads of a dynamic (concatenated) storage key that must never be trusted as a
 * plugin-owned prefix on their own, because WordPress core or many unrelated plugins
 * routinely build a dynamic key from this same root to reference something that isn't
 * specific to the plugin doing the reading (e.g. "theme_mods_" . $stylesheet is used by
 * any code reading ANY theme's mods, not just code that owns that data -- this is exactly
 * the pattern that previously caused Stack Inspector itself to false-match an unrelated
 * theme's theme_mods).
 *
 * @return string[]
 */
function tsosi_get_dynamic_prefix_root_blocklist() {
	return array(
		'theme_mods',
		'widget',
		'sidebars_widgets',
		'nav_menu',
		'cron',
		'transient',
		'site_transient',
	);
}

/**
 * Whether a literal head extracted from a concatenated storage key ("head" . $var) is a
 * generic root that must not become a standalone plugin prefix by itself.
 *
 * @param string $head Sanitized head, trailing separator already stripped.
 * @return bool
 */
function tsosi_is_dynamic_prefix_root_blocked( $head ) {
	$head = sanitize_key( (string) $head );
	if ( '' === $head ) {
		return true;
	}
	foreach ( tsosi_get_dynamic_prefix_root_blocklist() as $blocked_root ) {
		if ( $head === $blocked_root || 0 === strpos( $head, $blocked_root . '_' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Transient names owned by WordPress core, even when a plugin calls set_transient() on them.
 *
 * @return string[]
 */
function tsosi_get_core_transient_key_blocklist() {
	return array(
		'settings_errors',
	);
}

/**
 * Discover literal transient / site-transient keys used by a plugin and translate them
 * into the wp_options row-name prefixes WordPress actually stores them under
 * ("_transient_{key}" / "_site_transient_{key}"). A plugin can carry an entire mechanism
 * (e.g. a fake "license"/"update available" nag) purely through transients, with no
 * get_option()/update_option() call anywhere in its source -- without this, such a
 * plugin would have no discoverable option-side footprint at all.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[] Fully-formed wp_options prefixes.
 */
function tsosi_discover_plugin_transient_prefixes( $plugin_file ) {
	$files = tsosi_profile_plugin_php_files( $plugin_file );

	$entries = array();
	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		// Complete literal transient key only -- same reasoning as the option/meta
		// regexes above: a dynamically built key isn't one this plugin can be said to own.
		if ( preg_match_all( "/(?:get|set|delete)_(site_)?transient\s*\(\s*(['\"])([_a-z0-9-]+)\\2\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[3] as $index => $key ) {
				$entries[] = array(
					'key'  => sanitize_key( (string) $key ),
					'site' => isset( $matches[1][ $index ] ) && '' !== $matches[1][ $index ],
				);
			}
		}
	}

	$prefixes = array();
	foreach ( $entries as $entry ) {
		$key = $entry['key'];
		if ( '' === $key || tsosi_is_blocked_option_key_for_prefix_discovery( $key ) ) {
			continue;
		}
		// Settings API transient. Core writes it from options.php, and plugins such as
		// Theme My Login copy the same set_transient( 'settings_errors', ... ) call.
		// The row is not that plugin's data.
		if ( in_array( $key, tsosi_get_core_transient_key_blocklist(), true ) ) {
			continue;
		}
		$prefixes[] = ( $entry['site'] ? '_site_transient_' : '_transient_' ) . $key;
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
 * Literal postmeta keys established as owned by a write-type call (add/update/delete_post_meta
 * or register_post_meta()/register_meta()) somewhere in the plugin's own source.
 *
 * A bare get_post_meta() call on a literal key is not evidence of ownership: plugins
 * routinely read ANOTHER plugin's or service's meta to interoperate with it (e.g. reading
 * Yoast SEO's own "_yoast_wpseo_focuskw" post meta to feed an auto-rename/SEO feature),
 * and that read-only key belongs to the other plugin, not to the one being scanned.
 *
 * @param string[] $files Plugin PHP file paths.
 * @return array<string,bool> Sanitized keys, as a set (map to true).
 */
function tsosi_discover_plugin_meta_write_keys( $files ) {
	$write_keys = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/(?:update|delete|add)_post_meta\s*\([^,]+,\s*(['\"])([_a-z0-9-]+)\\1\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[2] as $key ) {
				$write_keys[ sanitize_key( (string) $key ) ] = true;
			}
		}
		if ( preg_match_all( "/(?:update|delete|add)_post_meta\s*\([^,]+,\s*(['\"])([_a-z0-9-]+)\\1\s*\./i", $content, $head_matches ) ) {
			foreach ( $head_matches[2] as $head ) {
				$write_keys[ sanitize_key( rtrim( (string) $head, '_-' ) ) ] = true;
			}
		}
		if ( preg_match_all( "/register_(?:post_)?meta\s*\(\s*['\"][^'\"]*['\"]\s*,\s*(['\"])([_a-z0-9-]+)\\1/i", $content, $register_matches ) ) {
			foreach ( $register_matches[2] as $key ) {
				$write_keys[ sanitize_key( (string) $key ) ] = true;
			}
		}
	}

	return $write_keys;
}

/**
 * Guess postmeta / option prefixes referenced by a plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_meta_prefixes( $plugin_file ) {
	$files = tsosi_profile_plugin_php_files( $plugin_file );
	$keys  = array();

	$write_keys = tsosi_discover_plugin_meta_write_keys( $files );
	$keys       = array_merge( $keys, array_keys( $write_keys ) );

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		// Require a complete literal key -- immediately closed by the same quote and
		// followed by "," or ")" -- e.g. get_post_meta( $id, 'myplugin_option', true ).
		// A get_post_meta() match only counts when the same key is also established by a
		// write call / register_meta() above -- see tsosi_discover_plugin_meta_write_keys().
		if ( preg_match_all( "/(get|update|delete|add)_post_meta\s*\([^,]+,\s*(['\"])([_a-z0-9-]+)\\2\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[3] as $index => $key ) {
				$key = sanitize_key( (string) $key );
				if ( 'get' === strtolower( $matches[1][ $index ] ) && ! isset( $write_keys[ $key ] ) ) {
					continue;
				}
				$keys[] = $key;
			}
		}
		// Literal *head* of a concatenated key -- e.g.
		// update_post_meta( $id, 'myplugin_field_' . $suffix, $val ). The suffix is
		// dynamic, but the literal head is still a real, plugin-authored fragment, so it
		// is kept as an already-compound prefix candidate -- guarded by
		// tsosi_is_dynamic_prefix_root_blocked() against generic roots (e.g. a plugin
		// that merely reads "theme_mods_" . $stylesheet for some other theme), and, for
		// get_post_meta(), by the same write-evidence requirement as above.
		if ( preg_match_all( "/(get|update|delete|add)_post_meta\s*\([^,]+,\s*(['\"])([_a-z0-9-]+)\\2\s*\./i", $content, $head_matches ) ) {
			foreach ( $head_matches[3] as $index => $head ) {
				$head = sanitize_key( rtrim( (string) $head, '_-' ) );
				if ( 'get' === strtolower( $head_matches[1][ $index ] ) && ! isset( $write_keys[ $head ] ) ) {
					continue;
				}
				if ( strlen( $head ) >= 6 && false !== strpos( $head, '_' )
					&& ! tsosi_is_dynamic_prefix_root_blocked( $head )
				) {
					$keys[] = $head;
				}
			}
		}
	}

	$prefixes = array();
	foreach ( $keys as $key ) {
		if ( tsosi_is_runtime_analytics_storage_key( $key ) ) {
			continue;
		}
		// $allow_bare_segment = false: a bare first word from scanned source ("view" out
		// of "view_count") is too generic to trust as a standalone prefix -- see the
		// tsosi_profile_prefixes_from_storage_key() docblock.
		$prefixes = array_merge( $prefixes, tsosi_profile_prefixes_from_storage_key( $key, false ) );
	}

	$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
	sort( $prefixes );
	return $prefixes;
}

/**
 * Literal wp_options keys established as owned by a write-type call (add/update/delete_option
 * or register_setting()) somewhere in the plugin's own source.
 *
 * A bare get_option() call on a literal key is not evidence of ownership: plugins routinely
 * read ANOTHER plugin's option purely to detect or interoperate with it (e.g. reading
 * "jetpack_active_modules" to decide whether to defer to Jetpack's own sitemap, or reading
 * "meowapps_hide_meowapps" to detect that plugin), and that read-only key belongs to the
 * other plugin, not to the one being scanned. register_setting() is included because the
 * Settings API persists the option via options.php without a literal update_option() call
 * anywhere in the plugin's own code.
 *
 * @param string[] $files Plugin PHP file paths.
 * @return array<string,bool> Sanitized keys, as a set (map to true).
 */
function tsosi_discover_plugin_option_write_keys( $files ) {
	$write_keys = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/(?:update|delete|add)_option\s*\(\s*(['\"])([_a-z0-9-]+)\\1\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[2] as $key ) {
				$write_keys[ sanitize_key( (string) $key ) ] = true;
			}
		}
		if ( preg_match_all( "/(?:update|delete|add)_option\s*\(\s*(['\"])([_a-z0-9-]+)\\1\s*\./i", $content, $head_matches ) ) {
			foreach ( $head_matches[2] as $head ) {
				$write_keys[ sanitize_key( rtrim( (string) $head, '_-' ) ) ] = true;
			}
		}
		if ( preg_match_all( "/register_setting\s*\(\s*['\"][^'\"]*['\"]\s*,\s*(['\"])([_a-z0-9-]+)\\1/i", $content, $register_matches ) ) {
			foreach ( $register_matches[2] as $key ) {
				$write_keys[ sanitize_key( (string) $key ) ] = true;
			}
		}
	}

	return $write_keys;
}

/**
 * Guess wp_options key prefixes referenced by a plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_option_prefixes( $plugin_file ) {
	$files = tsosi_profile_plugin_php_files( $plugin_file );
	$keys  = array();

	$write_keys = tsosi_discover_plugin_option_write_keys( $files );
	$keys       = array_merge( $keys, array_keys( $write_keys ) );

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		// Require a complete literal key -- immediately closed by the same quote and
		// followed by "," or ")" -- e.g. get_option( 'fileorganizer_free_installed' ).
		// A get_option() match only counts when the same key is also established by a
		// write call / register_setting() above -- see
		// tsosi_discover_plugin_option_write_keys() -- otherwise a plugin that merely
		// reads another plugin's option (e.g. to detect it) would be credited with data
		// it does not own.
		if ( preg_match_all( "/(get|update|delete|add)_option\s*\(\s*(['\"])([_a-z0-9-]+)\\2\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[3] as $index => $key ) {
				$key = sanitize_key( (string) $key );
				if ( 'get' === strtolower( $matches[1][ $index ] ) && ! isset( $write_keys[ $key ] ) ) {
					continue;
				}
				$keys[] = $key;
			}
		}
		// Literal *head* of a concatenated key -- e.g.
		// update_option( 'fileorganizer_version_' . ( $pro ? 'pro' : 'free' ) . '_nag', $v ).
		// The suffix is dynamic, but the literal head is still a real, plugin-authored
		// fragment, so it is kept as an already-compound prefix candidate. This must NOT
		// swallow "'theme_mods_' . $stylesheet" (a concatenation this plugin merely
		// builds to READ another theme's data, not a fixed key it OWNS) -- guarded by
		// tsosi_is_dynamic_prefix_root_blocked() against exactly that kind of generic,
		// core-owned dynamic root -- and, for get_option(), by the same write-evidence
		// requirement as above.
		if ( preg_match_all( "/(get|update|delete|add)_option\s*\(\s*(['\"])([_a-z0-9-]+)\\2\s*\./i", $content, $head_matches ) ) {
			foreach ( $head_matches[3] as $index => $head ) {
				$head = sanitize_key( rtrim( (string) $head, '_-' ) );
				if ( 'get' === strtolower( $head_matches[1][ $index ] ) && ! isset( $write_keys[ $head ] ) ) {
					continue;
				}
				if ( strlen( $head ) >= 6 && false !== strpos( $head, '_' )
					&& ! tsosi_is_dynamic_prefix_root_blocked( $head )
				) {
					$keys[] = $head;
				}
			}
		}
	}

	// Transient / site-transient literal keys are stored in wp_options under a
	// "_transient_" / "_site_transient_" prefix, so they need that prefix baked in to
	// ever match a real row -- see tsosi_discover_plugin_transient_prefixes().
	$transient_prefixes = tsosi_discover_plugin_transient_prefixes( $plugin_file );

	$source_key_count = count( $keys );

	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	if ( '' !== $folder ) {
		$keys[] = sanitize_key( str_replace( '-', '_', $folder ) );
	}

	$prefixes = array();
	foreach ( $keys as $index => $key ) {
		if ( tsosi_is_blocked_option_key_for_prefix_discovery( $key ) ) {
			continue;
		}
		// The plugin's own folder slug (appended after $source_key_count) is always
		// plugin-specific, so its bare first segment is safe to trust; a bare first
		// segment merely read from a get_option()/update_option() call elsewhere in the
		// source is not -- see the tsosi_profile_prefixes_from_storage_key() docblock.
		$allow_bare_segment = $index >= $source_key_count;
		$prefixes           = array_merge( $prefixes, tsosi_profile_prefixes_from_storage_key( $key, $allow_bare_segment ) );
	}

	$prefixes = array_merge( $prefixes, $transient_prefixes );

	$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
	sort( $prefixes );
	return $prefixes;
}

/**
 * Discover WP_Widget id_base(s) registered by this plugin: find register_widget() calls,
 * then read the referenced class's own parent::__construct( 'id_base', ... ) call.
 *
 * A native widget's storage key is 'widget_{id_base}' and its sidebar instance ids are
 * '{id_base}-N' -- neither has to share a single word with the plugin's folder slug or
 * any of its get_option()/get_post_meta() calls (a plugin can register_widget() without
 * ever calling get_option() itself). Without this, such a plugin has no discoverable
 * option/meta prefix at all and a scan can never find it in an active sidebar, even
 * though it plainly is one.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[] Sanitized id_base values.
 */
function tsosi_discover_plugin_widget_id_bases( $plugin_file ) {
	$files = tsosi_profile_plugin_php_files( $plugin_file );

	$class_names = array();
	$sources     = array();
	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		$sources[] = $content;
		if ( preg_match_all( "/register_widget\s*\(\s*(?:new\s+)?['\"]?([A-Za-z_][A-Za-z0-9_]*)/", $content, $matches ) ) {
			foreach ( $matches[1] as $class_name ) {
				$class_names[] = $class_name;
			}
		}
	}
	$class_names = array_values( array_unique( array_filter( $class_names ) ) );
	if ( empty( $class_names ) ) {
		return array();
	}

	$id_bases = array();
	foreach ( $sources as $content ) {
		foreach ( $class_names as $class_name ) {
			// Bounded window after the class declaration so an unrelated class defined
			// later in the same file can't be mistaken for this one's constructor.
			if ( preg_match(
				'/class\s+' . preg_quote( $class_name, '/' ) . '\b.{0,8000}?parent::__construct\s*\(\s*[\'"]([a-z0-9_-]+)[\'"]/is',
				$content,
				$class_match
			) ) {
				$id_bases[] = sanitize_key( $class_match[1] );
			}
		}
	}

	return array_values( array_unique( array_filter( $id_bases ) ) );
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
	$plugins    = tsosi_get_installed_plugins();
	$header     = isset( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ] : array();
	$name       = isset( $header['Name'] ) ? (string) $header['Name'] : $plugin_file;
	$plugin_dir = tsosi_get_plugin_dir( $plugin_file );

	$shortcodes = tsosi_discover_plugin_shortcodes( $plugin_file );
	$blocks     = tsosi_discover_plugin_blocks( $plugin_file );
	$prefixes   = tsosi_discover_plugin_meta_prefixes( $plugin_file );
	$opt_prefix = tsosi_discover_plugin_option_prefixes( $plugin_file );

	// A widget's own id_base is exact and plugin-specific (pulled straight from its own
	// constructor call), so it's always safe to add directly -- no genericity concern
	// like the bare-segment fallback above.
	$opt_prefix = array_merge( $opt_prefix, tsosi_discover_plugin_widget_id_bases( $plugin_file ) );

	// The signature sources are only needed while this profile is built.
	tsosi_profile_memo_flush_sources();

	// Runtime discovery matches callbacks by directory; a single-file plugin has no
	// directory of its own (it would match every plugin), so it relies on the static scan.
	if ( '' !== tsosi_get_plugin_folder_raw( $plugin_file ) && tsosi_is_plugin_active_file( $plugin_file ) ) {
		$shortcodes = array_values( array_unique( array_merge( $shortcodes, tsosi_discover_runtime_shortcodes_for_plugin( $plugin_dir ) ) ) );
		$blocks     = array_values( array_unique( array_merge( $blocks, tsosi_discover_runtime_blocks_for_plugin( $plugin_dir ) ) ) );
		// Widgets actually registered in memory (covers widget classes the static scan could not follow).
		$opt_prefix = array_merge( $opt_prefix, tsosi_usage_runtime_widget_classes( wp_normalize_path( trailingslashit( $plugin_dir ) ) ) );
		sort( $shortcodes );
		sort( $blocks );
	}

	$prefixes   = tsosi_refine_prefix_list( $prefixes );
	$opt_prefix = tsosi_refine_prefix_list( $opt_prefix );

	// Built-in signatures for plugins whose footprint is too large or dynamic for the
	// capped static scan (e.g. Elementor keeps its content in _elementor_* post meta).
	// Merged AFTER refining: the refiner drops a short root ("elementor") whenever longer
	// discovered keys ("elementor_oembed_cache") share it, which is exactly what we want to keep.
	$known = tsosi_get_known_plugin_signatures( $plugin_file );
	if ( ! empty( $known ) ) {
		$shortcodes = array_values( array_unique( array_merge( $shortcodes, $known['shortcodes'] ) ) );
		$blocks     = array_values( array_unique( array_merge( $blocks, $known['blocks'] ) ) );
		$prefixes   = array_values( array_unique( array_merge( $prefixes, array_map( 'sanitize_key', $known['meta_prefixes'] ) ) ) );
		$opt_prefix = array_values( array_unique( array_merge( $opt_prefix, array_map( 'sanitize_key', $known['option_prefixes'] ) ) ) );
		sort( $shortcodes );
		sort( $blocks );
	}

	return array(
		'plugin_file'     => $plugin_file,
		'name'            => $name,
		'active'          => tsosi_is_plugin_active_file( $plugin_file ),
		'shortcodes'      => $shortcodes,
		'blocks'          => $blocks,
		'meta_prefixes'   => $prefixes,
		'option_prefixes' => $opt_prefix,
	);
}

/**
 * Hand-maintained signatures for well-known plugins, keyed by plugin folder slug.
 *
 * @param string $plugin_file Plugin basename.
 * @return array<string,string[]> Empty when the plugin is not in the map.
 */
function tsosi_get_known_plugin_signatures( $plugin_file ) {
	$map = array(
		'elementor'                => array(
			'shortcodes'      => array( 'elementor-template' ),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'elementor' ),
			'option_prefixes' => array( 'elementor' ),
		),
		'elementor-pro'            => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'elementor_pro' ),
			'option_prefixes' => array( 'elementor_pro' ),
		),
		'wordpress-seo'            => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'yoast_wpseo' ),
			'option_prefixes' => array( 'wpseo', 'yoast' ),
		),
		'better-wp-security'       => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'itsec' ),
			'option_prefixes' => array( 'itsec' ),
		),
		'add-to-any'               => array(
			'shortcodes'      => array( 'addtoany' ),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'sharing_disabled', 'addtoany' ),
			'option_prefixes' => array( 'addtoany', 'a2a' ),
		),
		'cookie-law-info'          => array(
			'shortcodes'      => array( 'cookie_audit', 'cookie_accept', 'cookie_button', 'cookie_reject', 'cookie_settings', 'delete_cookies', 'webtoffee_powered_by' ),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'cookielawinfo' ),
			'option_prefixes' => array( 'cookielawinfo', 'cli_admin', 'wt_cli' ),
		),
		'google-sitemap-generator' => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'sm_options', 'sm_status', 'sm_cpages' ),
		),
		'media-cleaner'            => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'wpmc' ),
			'option_prefixes' => array( 'wpmc' ),
		),
		'media-file-renamer'       => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( '_require_file_renaming', '_manual_file_renaming' ),
			'option_prefixes' => array( 'mfrh' ),
		),
		'one-user-avatar'          => array(
			'shortcodes'      => array( 'avatar_upload', 'user_avatar', 'user_avatar_upload' ),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'wp_user_avatar', 'wpua' ),
			'option_prefixes' => array( 'wp_user_avatar', 'wpua' ),
		),
		'updraftplus'              => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'updraft' ),
		),
		'litespeed-cache'          => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'litespeed' ),
			'option_prefixes' => array( 'litespeed' ),
		),
		'jetpack'                  => array(
			'shortcodes'      => array(),
			'blocks'          => array( 'jetpack' ),
			'meta_prefixes'   => array( 'jetpack' ),
			'option_prefixes' => array( 'jetpack', 'jpsq' ),
		),
		'jetpack-boost'            => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'jetpack_boost' ),
		),
		'gtranslate'               => array(
			'shortcodes'      => array( 'gtranslate' ),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'gtranslate', 'GTranslate' ),
		),
		'post-views-counter'       => array(
			'shortcodes'      => array( 'post-views' ),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'post_views_counter' ),
		),
		'contact-form-7'           => array(
			'shortcodes'      => array( 'contact-form-7', 'contact-form' ),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'wpcf7' ),
			'option_prefixes' => array( 'wpcf7' ),
		),
		'wpforms-lite'             => array(
			'shortcodes'      => array( 'wpforms' ),
			'blocks'          => array( 'wpforms' ),
			'meta_prefixes'   => array( 'wpforms' ),
			'option_prefixes' => array( 'wpforms' ),
		),
		'woocommerce'              => array(
			'shortcodes'      => array( 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account', 'products', 'product' ),
			'blocks'          => array( 'woocommerce' ),
			'meta_prefixes'   => array( 'woocommerce', 'total_sales' ),
			'option_prefixes' => array( 'woocommerce' ),
		),
		'seo-by-rank-math'         => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'rank_math' ),
			'option_prefixes' => array( 'rank_math', 'rank-math' ),
		),
		'redirection'              => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'redirection' ),
		),
		'akismet'                  => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'akismet' ),
			'option_prefixes' => array( 'akismet', 'wordpress_api_key' ),
		),
		'really-simple-ssl'        => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'rsssl' ),
		),
		'wp-mail-smtp'             => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'wp_mail_smtp' ),
		),
		'mailchimp-for-wp'         => array(
			'shortcodes'      => array( 'mc4wp_form' ),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'mc4wp' ),
		),
		'classic-editor'           => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array( 'classic_editor' ),
			'option_prefixes' => array( 'classic-editor', 'classic_editor' ),
		),
		'regenerate-thumbnails'    => array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array( 'regenerate_thumbnails' ),
		),
	);

	/**
	 * Filter the built-in plugin signature map.
	 *
	 * @param array<string,array<string,string[]>> $map Signatures keyed by plugin folder slug.
	 */
	$map    = (array) apply_filters( 'tsosi_known_plugin_signatures', $map );
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	return ( '' !== $folder && isset( $map[ $folder ] ) && is_array( $map[ $folder ] ) ) ? $map[ $folder ] : array();
}

/**
 * Cached map of all plugin profiles, or null when the cache is cold. Never builds anything,
 * so it is safe to call while rendering an admin screen.
 *
 * @return array<string,array<string,mixed>>|null
 */
function tsosi_get_cached_plugin_profiles() {
	$cached = get_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE );
	return is_array( $cached ) ? $cached : null;
}

/**
 * Cached map of all plugin profiles.
 *
 * Builds every profile synchronously when the cache is cold: the admin screen uses
 * tsosi_get_cached_plugin_profiles() + tsosi_profiles_warm_step() instead, so it never
 * blocks on this.
 *
 * @param bool $force_refresh Skip cache.
 * @return array<string,array<string,mixed>>
 */
function tsosi_get_all_plugin_profiles( $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = tsosi_get_cached_plugin_profiles();
		if ( null !== $cached ) {
			return $cached;
		}
	}

	$profiles = array();
	foreach ( array_keys( tsosi_get_installed_plugins() ) as $plugin_file ) {
		$profiles[ $plugin_file ] = tsosi_build_plugin_profile( $plugin_file );
	}

	set_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE, $profiles, DAY_IN_SECONDS );
	delete_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE_PARTIAL );
	return $profiles;
}

/**
 * Build the plugin-profile cache in small, time-boxed steps (one AJAX call each), so the
 * Tools screen can open instantly and show progress instead of staying blank until every
 * installed plugin has been analysed.
 *
 * @param float $budget Seconds to spend per call (at least one plugin is always built).
 * @return array{done:bool,processed:int,total:int,progress:int}
 */
function tsosi_profiles_warm_step( $budget = 3.0 ) {
	$files = array_keys( tsosi_get_installed_plugins() );
	$total = count( $files );

	if ( null !== tsosi_get_cached_plugin_profiles() ) {
		return array(
			'done'      => true,
			'processed' => $total,
			'total'     => $total,
			'progress'  => 100,
		);
	}

	$limit = (int) ini_get( 'max_execution_time' );
	if ( $limit > 0 ) {
		$budget = min( (float) $budget, max( 1.0, $limit * 0.4 ) );
	}

	$partial = get_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE_PARTIAL );
	if ( ! is_array( $partial ) ) {
		$partial = array();
	}

	$start = microtime( true );
	$built = 0;
	foreach ( $files as $plugin_file ) {
		if ( isset( $partial[ $plugin_file ] ) ) {
			continue;
		}
		if ( $built > 0 && ( microtime( true ) - $start ) >= $budget ) {
			break;
		}
		$partial[ $plugin_file ] = tsosi_build_plugin_profile( $plugin_file );
		++$built;
	}

	$profiles = array();
	foreach ( $files as $plugin_file ) {
		if ( isset( $partial[ $plugin_file ] ) ) {
			$profiles[ $plugin_file ] = $partial[ $plugin_file ];
		}
	}
	$processed = count( $profiles );

	if ( $processed >= $total ) {
		set_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE, $profiles, DAY_IN_SECONDS );
		delete_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE_PARTIAL );
		return array(
			'done'      => true,
			'processed' => $total,
			'total'     => $total,
			'progress'  => 100,
		);
	}

	set_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE_PARTIAL, $profiles, HOUR_IN_SECONDS );
	return array(
		'done'      => false,
		'processed' => $processed,
		'total'     => $total,
		'progress'  => $total > 0 ? min( 99, (int) floor( ( $processed / $total ) * 100 ) ) : 100,
	);
}

/**
 * Flush cached plugin profiles when plugins change.
 *
 * @return void
 */
function tsosi_flush_plugin_profile_cache() {
	delete_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE );
	delete_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE_PARTIAL );
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
