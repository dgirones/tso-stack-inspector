<?php
/**
 * Extended scan targets: user/term/comment meta, theme profile, builder meta keys.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-builder and layout meta keys / prefixes to inspect for embedded shortcodes and blocks.
 *
 * @return string[]
 */
function tsosi_get_builder_meta_keys() {
	return array(
		'_elementor_data',
		'_fl_builder_data',
		'_fl_builder_draft',
		'_et_pb_old_content',
		'_et_pb_use_builder',
		'_wpb_shortcodes_custom_css',
		'ct_builder_shortcodes',
		'_oxygen_css',
	);
}

/**
 * Meta key prefixes scanned as serialized builder blobs on posts.
 *
 * @return string[]
 */
function tsosi_get_builder_meta_prefixes() {
	return array(
		'_bricks_',
		'_oxygen_',
		'_et_pb_',
		'_fl_builder_',
	);
}

/**
 * Whether a post meta key is a known builder storage key.
 *
 * @param string $meta_key Meta key.
 * @return bool
 */
function tsosi_is_builder_meta_key( $meta_key ) {
	$meta_key = sanitize_key( (string) $meta_key );
	if ( '' === $meta_key ) {
		return false;
	}
	if ( in_array( $meta_key, tsosi_get_builder_meta_keys(), true ) ) {
		return true;
	}
	foreach ( tsosi_get_builder_meta_prefixes() as $prefix ) {
		if ( 0 === strpos( $meta_key, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Collect extra builder blobs for a post beyond the fixed key list.
 *
 * @param int $post_id Post ID.
 * @return string[]
 */
function tsosi_scan_collect_builder_blobs( $post_id ) {
	$post_id = absint( $post_id );
	$blobs   = array();

	foreach ( tsosi_get_builder_meta_keys() as $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );
		if ( is_string( $value ) && '' !== $value && strlen( $value ) <= 500000 ) {
			$blobs[] = $value;
		}
	}

	$custom_keys = get_post_custom_keys( $post_id );
	if ( ! is_array( $custom_keys ) ) {
		return $blobs;
	}

	foreach ( $custom_keys as $meta_key ) {
		$clean = sanitize_key( (string) $meta_key );
		if ( '' === $clean || in_array( $clean, tsosi_get_builder_meta_keys(), true ) ) {
			continue;
		}
		$matched = false;
		foreach ( tsosi_get_builder_meta_prefixes() as $prefix ) {
			if ( 0 === strpos( $clean, $prefix ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			continue;
		}
		$value = get_post_meta( $post_id, $clean, true );
		if ( is_string( $value ) && '' !== $value && strlen( $value ) <= 500000 ) {
			$blobs[] = $value;
		}
	}

	return $blobs;
}

/**
 * Scan user meta.
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_user_meta( $needles ) {
	$prefixes   = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	$keys       = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	$clean_keys = array_map( 'sanitize_key', $keys );
	if ( empty( $prefixes ) && empty( $keys ) ) {
		return array();
	}

	global $wpdb;
	$found = array();
	$ctx   = tsosi_ui_triple_text( 'User meta', 'Meta de usuario', 'Meta d\'usuari' );

	// Memoized per request: the inactive-plugins audit calls this once per plugin, and the
	// rows do not depend on the needles (only the matching below does).
	static $memo_rows = null;
	if ( null === $memo_rows ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin scan of usermeta keys.
		$memo_rows = $wpdb->get_results(
			"SELECT user_id, meta_key FROM {$wpdb->usermeta} ORDER BY umeta_id ASC LIMIT 50000",
			ARRAY_A
		);
	}
	$rows = $memo_rows;
	if ( ! is_array( $rows ) ) {
		return array();
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$meta_key = sanitize_key( (string) ( $row['meta_key'] ?? '' ) );
		$user_id  = absint( $row['user_id'] ?? 0 );
		if ( '' === $meta_key || $user_id <= 0 ) {
			continue;
		}
		$hit = in_array( $meta_key, $clean_keys, true );
		if ( ! $hit ) {
			foreach ( $prefixes as $prefix ) {
				if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
					$hit = true;
					break;
				}
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$user    = get_userdata( $user_id );
		$label   = $user instanceof WP_User ? $user->user_login : (string) $user_id;
		$found[] = array(
			'source_type'    => 'user_meta',
			'object_id'      => $user_id,
			'object_label'   => $label,
			'object_subtype' => 'user',
			'match_type'     => 'meta',
			'match_value'    => $meta_key,
			'context'        => $ctx,
			'edit_url'       => admin_url( 'user-edit.php?user_id=' . $user_id ),
		);
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * Scan term meta.
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_term_meta( $needles ) {
	$prefixes   = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	$keys       = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	$clean_keys = array_map( 'sanitize_key', $keys );
	if ( empty( $prefixes ) && empty( $keys ) ) {
		return array();
	}

	global $wpdb;
	if ( ! isset( $wpdb->termmeta ) ) {
		return array();
	}

	$found = array();
	$ctx   = tsosi_ui_triple_text( 'Term meta', 'Meta de término', 'Meta de terme' );

	// Memoized per request: the inactive-plugins audit calls this once per plugin, and the
	// rows do not depend on the needles (only the matching below does).
	static $memo_rows = null;
	if ( null === $memo_rows ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin scan.
		$memo_rows = $wpdb->get_results(
			"SELECT term_id, meta_key FROM {$wpdb->termmeta} ORDER BY meta_id ASC LIMIT 50000",
			ARRAY_A
		);
	}
	$rows = $memo_rows;
	if ( ! is_array( $rows ) ) {
		return array();
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$meta_key = sanitize_key( (string) ( $row['meta_key'] ?? '' ) );
		$term_id  = absint( $row['term_id'] ?? 0 );
		if ( '' === $meta_key || $term_id <= 0 ) {
			continue;
		}
		$hit = in_array( $meta_key, $clean_keys, true );
		if ( ! $hit ) {
			foreach ( $prefixes as $prefix ) {
				if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
					$hit = true;
					break;
				}
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$term    = get_term( $term_id );
		$label   = ( $term instanceof WP_Term && ! is_wp_error( $term ) ) ? $term->name : (string) $term_id;
		$edit    = ( $term instanceof WP_Term && ! is_wp_error( $term ) )
			? get_edit_term_link( $term, $term->taxonomy )
			: '';
		$found[] = array(
			'source_type'    => 'term_meta',
			'object_id'      => $term_id,
			'object_label'   => $label,
			'object_subtype' => ( $term instanceof WP_Term && ! is_wp_error( $term ) ) ? sanitize_key( $term->taxonomy ) : '',
			'match_type'     => 'meta',
			'match_value'    => $meta_key,
			'context'        => $ctx,
			'edit_url'       => is_string( $edit ) ? $edit : '',
		);
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * Scan comment meta.
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_comment_meta( $needles ) {
	$prefixes   = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	$keys       = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	$clean_keys = array_map( 'sanitize_key', $keys );
	if ( empty( $prefixes ) && empty( $keys ) ) {
		return array();
	}

	global $wpdb;
	if ( ! isset( $wpdb->commentmeta ) ) {
		return array();
	}

	$found = array();
	$ctx   = tsosi_ui_triple_text( 'Comment meta', 'Meta de comentario', 'Meta de comentari' );

	// Memoized per request: the inactive-plugins audit calls this once per plugin, and the
	// rows do not depend on the needles (only the matching below does).
	static $memo_rows = null;
	if ( null === $memo_rows ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin scan.
		$memo_rows = $wpdb->get_results(
			"SELECT comment_id, meta_key FROM {$wpdb->commentmeta} ORDER BY meta_id ASC LIMIT 50000",
			ARRAY_A
		);
	}
	$rows = $memo_rows;
	if ( ! is_array( $rows ) ) {
		return array();
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$meta_key   = sanitize_key( (string) ( $row['meta_key'] ?? '' ) );
		$comment_id = absint( $row['comment_id'] ?? 0 );
		if ( '' === $meta_key || $comment_id <= 0 ) {
			continue;
		}
		$hit = in_array( $meta_key, $clean_keys, true );
		if ( ! $hit ) {
			foreach ( $prefixes as $prefix ) {
				if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
					$hit = true;
					break;
				}
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$comment = get_comment( $comment_id );
		$label   = $comment instanceof WP_Comment ? wp_trim_words( (string) $comment->comment_content, 8, '…' ) : (string) $comment_id;
		$found[] = array(
			'source_type'    => 'comment_meta',
			'object_id'      => $comment_id,
			'object_label'   => $label,
			'object_subtype' => 'comment',
			'match_type'     => 'meta',
			'match_value'    => $meta_key,
			'context'        => $ctx,
			'edit_url'       => admin_url( 'comment.php?action=editcomment&c=' . $comment_id ),
		);
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * Scan theme_mods / customizer options for shortcodes and blocks.
 * Scans installed themes (active and inactive), capped, so leftovers in old
 * Customizer data are found. Not stored in the content index (needle-dependent).
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_theme_mods( $needles ) {
	$stylesheets = array();
	$themes      = wp_get_themes();
	$count       = 0;
	foreach ( $themes as $stylesheet => $theme ) {
		if ( ! $theme instanceof WP_Theme ) {
			continue;
		}
		++$count;
		if ( $count > 30 ) {
			break;
		}
		$stylesheets[] = (string) $stylesheet;
	}

	// Always include any theme_mods_{stylesheet} from needles (e.g. beyond the cap).
	$prefixes = isset( $needles['option_prefixes'] ) && is_array( $needles['option_prefixes'] )
		? $needles['option_prefixes']
		: array();
	foreach ( $prefixes as $prefix ) {
		$prefix = (string) $prefix;
		if ( 0 !== strpos( $prefix, 'theme_mods_' ) ) {
			continue;
		}
		$stylesheet = tsosi_sanitize_theme_stylesheet( substr( $prefix, strlen( 'theme_mods_' ) ) );
		if ( '' !== $stylesheet ) {
			$stylesheets[] = $stylesheet;
		}
	}

	$stylesheets = array_values( array_unique( array_filter( $stylesheets ) ) );
	if ( empty( $stylesheets ) ) {
		return array();
	}

	$found = array();
	$ctx   = tsosi_ui_triple_text( 'Theme mods', 'Ajustes del tema', 'Ajustos del tema' );

	foreach ( $stylesheets as $stylesheet ) {
		$mods = get_option( 'theme_mods_' . $stylesheet );
		if ( ! is_array( $mods ) || empty( $mods ) ) {
			continue;
		}
		$blob = tsosi_scan_value_to_blob( $mods );
		if ( '' === $blob || strlen( $blob ) > 500000 ) {
			continue;
		}
		$theme = wp_get_theme( $stylesheet );
		$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet;
		$found = array_merge(
			$found,
			tsosi_scan_blob_rows(
				$blob,
				$needles,
				$name . ' (' . $stylesheet . ')',
				$ctx,
				admin_url( 'customize.php' ),
				'theme_mod'
			)
		);
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * Validate a theme stylesheet slug.
 *
 * @param string $stylesheet Theme stylesheet slug.
 * @return string Sanitized slug or empty if theme does not exist.
 */
function tsosi_sanitize_theme_stylesheet( $stylesheet ) {
	$stylesheet = sanitize_text_field( (string) $stylesheet );
	if ( '' === $stylesheet ) {
		return '';
	}
	$theme = wp_get_theme( $stylesheet );
	return $theme->exists() ? $stylesheet : '';
}

/**
 * Match shortcodes/blocks inside one theme (or parent) source file.
 *
 * Used when a plugin is rendered from the theme (e.g. do_shortcode( '[gtranslate]' )
 * in header.php) rather than from a sidebar widget or post content — those call sites
 * never appear in wp_options / posts and were previously invisible to the scanner.
 *
 * @param string              $source     File contents (comments already stripped for PHP).
 * @param string              $label      Relative path label (e.g. functions.php).
 * @param array<string,mixed> $needles    Needles.
 * @param string              $edit_url   Theme editor URL when available.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_theme_template_file_rows( $source, $label, $needles, $edit_url ) {
	$source = is_string( $source ) ? $source : '';
	$label  = sanitize_text_field( (string) $label );
	if ( '' === $source || '' === $label ) {
		return array();
	}

	$shortcodes = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks     = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();
	$ctx        = tsosi_ui_triple_text(
		'Active theme template',
		'Plantilla del tema activo',
		'Plantilla del tema actiu'
	);
	$found      = array();

	foreach ( tsosi_scan_blob_for_shortcodes( $source, $shortcodes ) as $tag ) {
		$found[] = array(
			'source_type'        => 'theme_template',
			'object_id'          => 0,
			'object_label'       => $label,
			'match_type'         => 'shortcode',
			'match_value'        => $tag,
			'active_theme_usage' => true,
			'context'            => $ctx,
			'edit_url'           => $edit_url,
		);
	}

	foreach ( tsosi_scan_blob_for_blocks( $source, $blocks ) as $block ) {
		$found[] = array(
			'source_type'        => 'theme_template',
			'object_id'          => 0,
			'object_label'       => $label,
			'match_type'         => 'block',
			'match_value'        => $block,
			'active_theme_usage' => true,
			'context'            => $ctx,
			'edit_url'           => $edit_url,
		);
	}

	return $found;
}

/**
 * Collect PHP/HTML files under a theme directory (capped, skips node_modules/.git).
 *
 * @param string $dir Absolute theme directory.
 * @return string[] Absolute paths.
 */
function tsosi_scan_collect_theme_template_files( $dir ) {
	$dir = wp_normalize_path( (string) $dir );
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return array();
	}

	$candidates = array();
	$iterator   = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			static function ( $current, $key, $inner ) {
				if ( $inner->hasChildren() ) {
					return ! in_array( $current->getFilename(), array( 'node_modules', '.git', 'vendor' ), true );
				}
				return true;
			}
		),
		RecursiveIteratorIterator::LEAVES_ONLY,
		RecursiveIteratorIterator::CATCH_GET_CHILD
	);

	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$path = $file->getPathname();
		if ( ! preg_match( '/\.(php|html)$/i', $path ) ) {
			continue;
		}
		if ( $file->getSize() > TSOSI_PROFILE_MAX_FILE_BYTES ) {
			continue;
		}
		$candidates[] = wp_normalize_path( $path );
	}

	return tsosi_profile_sort_and_cap_files( $candidates, $dir, TSOSI_PROFILE_MAX_FILES );
}

/**
 * Scan the active theme (and parent, if any) for plugin shortcodes/blocks in template PHP/HTML.
 *
 * Needle-dependent and not stored in the content index — same pattern as theme_mods.
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_theme_templates( $needles ) {
	$shortcodes = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks     = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();
	if ( empty( $shortcodes ) && empty( $blocks ) ) {
		return array();
	}

	$theme = wp_get_theme();
	if ( ! $theme->exists() ) {
		return array();
	}

	$roots = array();
	$child = $theme->get_stylesheet_directory();
	if ( is_string( $child ) && is_dir( $child ) ) {
		$roots[ wp_normalize_path( $child ) ] = (string) $theme->get_stylesheet();
	}
	$parent_dir = $theme->get_template_directory();
	if ( is_string( $parent_dir ) && is_dir( $parent_dir ) ) {
		$parent_key = wp_normalize_path( $parent_dir );
		if ( ! isset( $roots[ $parent_key ] ) ) {
			$roots[ $parent_key ] = (string) $theme->get_template();
		}
	}

	$found = array();
	foreach ( $roots as $dir => $stylesheet ) {
		foreach ( tsosi_scan_collect_theme_template_files( $dir ) as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- theme template usage scan.
			$source = file_get_contents( $path );
			if ( ! is_string( $source ) || '' === $source ) {
				continue;
			}
			if ( preg_match( '/\.php$/i', $path ) ) {
				$source = tsosi_profile_strip_php_comments( $source );
			}
			$relative = ltrim( str_replace( $dir, '', wp_normalize_path( $path ) ), '/' );
			if ( '' === $relative ) {
				$relative = basename( $path );
			}
			$edit_url = admin_url(
				'theme-editor.php?file=' . rawurlencode( $relative ) . '&theme=' . rawurlencode( $stylesheet )
			);
			$found    = array_merge(
				$found,
				tsosi_scan_theme_template_file_rows( $source, $relative, $needles, $edit_url )
			);
		}
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * Build a theme signature profile (code scan of active theme).
 *
 * @param string $stylesheet Theme stylesheet slug (empty = active).
 * @return array<string,mixed>
 */
function tsosi_build_theme_profile( $stylesheet = '' ) {
	$theme = '' === $stylesheet ? wp_get_theme() : wp_get_theme( $stylesheet );
	if ( ! $theme->exists() ) {
		return array(
			'name'            => '',
			'stylesheet'      => '',
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array(),
		);
	}

	$dir = $theme->get_stylesheet_directory();
	if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
		return array(
			'name'            => (string) $theme->get( 'Name' ),
			'stylesheet'      => (string) $theme->get_stylesheet(),
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array(),
		);
	}

	$shortcodes = array();
	$blocks     = array();

	// Gather every candidate path first (cheap -- no file reads yet), then sort
	// deterministically and cap, same as tsosi_profile_collect_php_files(). Capping
	// while iterating made the scan depend on filesystem order alone, so a theme
	// with more than TSOSI_PROFILE_MAX_FILES matching files could silently miss
	// add_shortcode()/register_block_type() calls depending only on directory order.
	$candidates = array();
	// Do not descend into node_modules/.git: a themed build tree can hold 100k+ files that
	// were listed (and then discarded by the file cap) on every render of the Theme tab.
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			static function ( $current, $key, $inner ) {
				if ( $inner->hasChildren() ) {
					return ! in_array( $current->getFilename(), array( 'node_modules', '.git' ), true );
				}
				return true;
			}
		),
		RecursiveIteratorIterator::LEAVES_ONLY,
		RecursiveIteratorIterator::CATCH_GET_CHILD
	);
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$path = $file->getPathname();
		if ( ! preg_match( '/\.(php|jsx?|tsx?)$/i', $path ) ) {
			continue;
		}
		if ( $file->getSize() > TSOSI_PROFILE_MAX_FILE_BYTES ) {
			continue;
		}
		$candidates[] = wp_normalize_path( $path );
	}
	$files = tsosi_profile_sort_and_cap_files( $candidates, $dir, TSOSI_PROFILE_MAX_FILES );

	// Blocks registered purely via a block.json manifest (no matching PHP
	// register_block_type() call -- the modern, recommended pattern) would
	// otherwise never surface. Mirrors tsosi_discover_plugin_blocks().
	foreach ( tsosi_profile_collect_block_json_files( $dir ) as $json_path ) {
		$name = tsosi_profile_block_name_from_json_path( $json_path );
		if ( '' !== $name ) {
			$blocks[] = tsosi_sanitize_block_name( $name );
		}
	}

	foreach ( $files as $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- theme code scan.
		$source = file_get_contents( $path );
		if ( ! is_string( $source ) || '' === $source ) {
			continue;
		}
		// Strip comments/docblocks before scanning -- see tsosi_profile_strip_php_comments()
		// in tsosi-plugin-profile.php. Without this, an *example* tag/block name written in
		// a theme file's own comment (e.g. documenting a filter, or a code sample in a
		// starter theme's readme-in-PHP header) is indistinguishable from a real
		// add_shortcode()/register_block_type() call and gets attributed to the theme. A
		// no-op on .js/.jsx/.tsx sources (no "<?php" tag, so PHP's tokenizer treats the
		// whole file as one inline-HTML token and returns it unchanged).
		$source = tsosi_profile_strip_php_comments( $source );
		if ( preg_match_all( '/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $m ) ) {
			foreach ( $m[1] as $tag ) {
				$tag = tsosi_sanitize_shortcode_tag( (string) $tag );
				if ( '' !== $tag ) {
					$shortcodes[] = $tag;
				}
			}
		}
		if ( preg_match_all( '/register_block_type\s*\(\s*[\'"]([a-z0-9\/_-]+)[\'"]/i', $source, $m2 ) ) {
			foreach ( $m2[1] as $block ) {
				$block = tsosi_sanitize_block_name( (string) $block );
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
			}
		}
		// register_block_type() given a path (to a directory or its block.json) rather
		// than a literal block name -- resolve it to the real name instead of treating
		// the path string itself as a block name (the previous, broader regex did this).
		if ( preg_match_all( '/register_block_type\s*\(\s*[^;]*[\'"]([^\'"]*block\.json)[\'"]/i', $source, $json_refs ) ) {
			foreach ( $json_refs[1] as $json_hint ) {
				$json_path = tsosi_profile_resolve_block_json_path( $path, $json_hint );
				$name      = tsosi_profile_block_name_from_json_path( $json_path );
				if ( '' !== $name ) {
					$blocks[] = tsosi_sanitize_block_name( $name );
				}
			}
		}
	}

	$slug = sanitize_key( str_replace( '-', '_', (string) $theme->get_stylesheet() ) );

	return array(
		'name'            => (string) $theme->get( 'Name' ),
		'stylesheet'      => (string) $theme->get_stylesheet(),
		'shortcodes'      => array_values( array_unique( $shortcodes ) ),
		'blocks'          => array_values( array_unique( $blocks ) ),
		'meta_prefixes'   => '' !== $slug ? array( $slug ) : array(),
		'option_prefixes' => array( 'theme_mods_' . $theme->get_stylesheet() ),
	);
}

/**
 * Compare signatures of two plugins.
 *
 * @param string $plugin_file_a Plugin A basename.
 * @param string $plugin_file_b Plugin B basename.
 * @return array<string,mixed>
 */
function tsosi_compare_plugin_profiles( $plugin_file_a, $plugin_file_b ) {
	$a = tsosi_build_plugin_profile( $plugin_file_a );
	$b = tsosi_build_plugin_profile( $plugin_file_b );

	$fields = array( 'shortcodes', 'blocks', 'meta_prefixes', 'option_prefixes' );
	$out    = array(
		'plugin_a' => array(
			'file' => $plugin_file_a,
			'name' => isset( $a['name'] ) ? (string) $a['name'] : $plugin_file_a,
		),
		'plugin_b' => array(
			'file' => $plugin_file_b,
			'name' => isset( $b['name'] ) ? (string) $b['name'] : $plugin_file_b,
		),
		'shared'   => array(),
		'only_a'   => array(),
		'only_b'   => array(),
	);

	foreach ( $fields as $field ) {
		$set_a                   = isset( $a[ $field ] ) && is_array( $a[ $field ] ) ? $a[ $field ] : array();
		$set_b                   = isset( $b[ $field ] ) && is_array( $b[ $field ] ) ? $b[ $field ] : array();
		$out['shared'][ $field ] = array_values( array_intersect( $set_a, $set_b ) );
		$out['only_a'][ $field ] = array_values( array_diff( $set_a, $set_b ) );
		$out['only_b'][ $field ] = array_values( array_diff( $set_b, $set_a ) );
	}

	return $out;
}

/**
 * Lists wp_options autoload flags that mean "loaded on every request". WordPress 6.6+ also
 * stores on/auto/auto-on (and off/auto-off for the rest); older versions only yes/no.
 *
 * @return string[]
 */
function tsosi_autoload_on_values() {
	if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
		$values = array_values( array_filter( array_map( 'strval', (array) wp_autoload_values_to_autoload() ) ) );
		if ( ! empty( $values ) ) {
			return $values;
		}
	}
	return array( 'yes' );
}

/**
 * LIKE patterns for the shortcodes/blocks of a needle set.
 *
 * @param array<string,mixed> $needles Needles.
 * @return string[]
 */
function tsosi_scan_db_like_patterns( $needles ) {
	global $wpdb;
	$patterns = array();
	foreach ( array_slice( (array) ( $needles['shortcodes'] ?? array() ), 0, 40 ) as $tag ) {
		$tag = tsosi_sanitize_shortcode_tag( (string) $tag );
		if ( '' !== $tag ) {
			$patterns[] = '%' . $wpdb->esc_like( '[' . $tag ) . '%';
		}
	}
	foreach ( array_slice( (array) ( $needles['blocks'] ?? array() ), 0, 40 ) as $name ) {
		$name = tsosi_sanitize_block_name( (string) $name );
		if ( '' === $name ) {
			continue;
		}
		$patterns[] = '%' . $wpdb->esc_like( 'wp:' . $name ) . '%';
		if ( false !== strpos( $name, '/' ) ) {
			// Page-builder / JSON storage escapes the slash.
			$patterns[] = '%' . $wpdb->esc_like( 'wp:' . str_replace( '/', '\\/', $name ) ) . '%';
		}
	}
	return array_values( array_unique( $patterns ) );
}

/**
 * Shortcodes/blocks stored outside post_content: custom fields (e.g. ACF WYSIWYG),
 * post excerpts, term descriptions and non-autoload options. These are invisible to the
 * content index, yet they are rendered on the site.
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_db_values( $needles ) {
	global $wpdb;

	$shortcodes = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks     = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();
	$patterns   = tsosi_scan_db_like_patterns( $needles );
	if ( empty( $patterns ) ) {
		return array();
	}

	// One LIKE clause per needle pattern; every value is bound through prepare().
	$like_count   = count( $patterns );
	$like_meta    = implode( ' OR ', array_fill( 0, $like_count, 'meta_value LIKE %s' ) );
	$like_excerpt = implode( ' OR ', array_fill( 0, $like_count, 'post_excerpt LIKE %s' ) );
	$like_desc    = implode( ' OR ', array_fill( 0, $like_count, 'description LIKE %s' ) );
	$like_value   = implode( ' OR ', array_fill( 0, $like_count, 'option_value LIKE %s' ) );
	$found        = array();

	// 1. Custom fields (builder meta keys are already covered by the content index).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- bounded admin-only scan; placeholders generated from the fixed pattern list.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, LEFT(meta_value, 200000) AS meta_value FROM {$wpdb->postmeta} WHERE ({$like_meta}) LIMIT 400", ...$patterns ), ARRAY_A );
	foreach ( (array) $rows as $row ) {
		$meta_key = (string) $row['meta_key'];
		if ( tsosi_is_builder_meta_key( $meta_key ) || in_array( $meta_key, array( '_edit_lock', '_edit_last' ), true ) ) {
			continue;
		}
		$post = get_post( (int) $row['post_id'] );
		if ( ! $post instanceof WP_Post || 'revision' === $post->post_type ) {
			continue;
		}
		$blob = tsosi_scan_unescape_json_slashes( (string) $row['meta_value'] );
		$ctx  = tsosi_ui_triple_text( 'Custom field', 'Campo personalizado', 'Camp personalitzat' ) . ': ' . sanitize_key( $meta_key );
		foreach ( tsosi_scan_blob_for_shortcodes( $blob, $shortcodes ) as $tag ) {
			$found[] = tsosi_scan_make_result( $post, 'shortcode', $tag, $ctx );
		}
		foreach ( tsosi_scan_blob_for_blocks( $blob, $blocks ) as $block ) {
			$found[] = tsosi_scan_make_result( $post, 'block', $block, $ctx );
		}
	}

	// 2. Post excerpts.
	$post_types = tsosi_get_scannable_post_types();
	if ( ! empty( $post_types ) ) {
		$type_in = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- bounded admin-only scan; placeholders generated from fixed lists.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_excerpt FROM {$wpdb->posts} WHERE post_type IN ({$type_in}) AND post_status NOT IN ('trash','auto-draft') AND ({$like_excerpt}) LIMIT 300", ...array_merge( $post_types, $patterns ) ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$post = get_post( (int) $row['ID'] );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$ctx = tsosi_ui_triple_text( 'Post excerpt', 'Extracto', 'Extracte' );
			foreach ( tsosi_scan_blob_for_shortcodes( (string) $row['post_excerpt'], $shortcodes ) as $tag ) {
				$found[] = tsosi_scan_make_result( $post, 'shortcode', $tag, $ctx );
			}
			foreach ( tsosi_scan_blob_for_blocks( tsosi_scan_unescape_json_slashes( (string) $row['post_excerpt'] ), $blocks ) as $block ) {
				$found[] = tsosi_scan_make_result( $post, 'block', $block, $ctx );
			}
		}
	}

	// 3. Term descriptions (category/tag/custom taxonomy text can render shortcodes).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- bounded admin-only scan; placeholders generated from the fixed pattern list.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, taxonomy, description FROM {$wpdb->term_taxonomy} WHERE ({$like_desc}) LIMIT 200", ...$patterns ), ARRAY_A );
	foreach ( (array) $rows as $row ) {
		$term = get_term( (int) $row['term_id'], (string) $row['taxonomy'] );
		$link = ( $term instanceof WP_Term ) ? get_edit_term_link( $term->term_id, $term->taxonomy ) : '';
		$name = ( $term instanceof WP_Term ) ? $term->name : '#' . (int) $row['term_id'];
		$hits = array();
		foreach ( tsosi_scan_blob_for_shortcodes( (string) $row['description'], $shortcodes ) as $tag ) {
			$hits[] = array( 'shortcode', $tag );
		}
		foreach ( tsosi_scan_blob_for_blocks( tsosi_scan_unescape_json_slashes( (string) $row['description'] ), $blocks ) as $block ) {
			$hits[] = array( 'block', $block );
		}
		foreach ( $hits as $hit ) {
			$found[] = array(
				'source_type'    => 'term',
				'object_id'      => (int) $row['term_id'],
				'object_label'   => sanitize_text_field( $name ),
				'object_subtype' => sanitize_key( (string) $row['taxonomy'] ),
				'match_type'     => $hit[0],
				'match_value'    => $hit[1],
				'context'        => tsosi_ui_triple_text( 'Term description', 'Descripción del término', 'Descripció del terme' ),
				'edit_url'       => is_string( $link ) ? $link : '',
			);
		}
	}

	// 4. Non-autoloaded options (autoloaded ones are in the content index).
	$autoload_on = tsosi_autoload_on_values();
	$in          = implode( ',', array_fill( 0, count( $autoload_on ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- bounded admin-only scan; placeholders generated from fixed lists.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, LEFT(option_value, 200000) AS option_value FROM {$wpdb->options} WHERE autoload NOT IN ({$in}) AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND ({$like_value}) LIMIT 200", ...array_merge( $autoload_on, array( $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ), $patterns ) ), ARRAY_A );
	$ctx  = tsosi_ui_triple_text( 'wp_options (no autoload)', 'wp_options (sin autoload)', 'wp_options (sense autoload)' );
	foreach ( (array) $rows as $row ) {
		$name = (string) $row['option_name'];
		if ( tsosi_is_sensitive_storage_option_name( $name ) ) {
			continue;
		}
		$found = array_merge(
			$found,
			tsosi_scan_blob_rows( tsosi_scan_unescape_json_slashes( (string) $row['option_value'] ), $needles, sanitize_text_field( $name ), $ctx, '', 'option' )
		);
	}

	return tsosi_scan_dedupe_results( $found );
}
