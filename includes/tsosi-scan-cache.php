<?php
/**
 * Site content index cache for fast repeat scans (needle-agnostic layer).
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX' ) ) {
	define( 'TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX', 'tso_stack_inspector_content_cache_' );
}

if ( ! defined( 'TSOSI_CONTENT_CACHE_TTL' ) ) {
	define( 'TSOSI_CONTENT_CACHE_TTL', HOUR_IN_SECONDS );
}

/**
 * Per-user transient key for the content index cache.
 *
 * @return string
 */
function tsosi_content_cache_transient_key() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX . '0';
	}
	return TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX . $user_id;
}

/**
 * Delete cached site index (all users on single site: current user only).
 *
 * @return void
 */
function tsosi_flush_content_cache() {
	delete_transient( tsosi_content_cache_transient_key() );
	tsosi_scan_delete_build_index();
	tsosi_scan_delete_user_cache_files();
}

/**
 * Uploads path for cache JSON files (empty when unavailable).
 *
 * @return string
 */
function tsosi_scan_cache_storage_dir() {
	$dir = tsosi_get_uploads_dir();
	if ( ! empty( $dir['error'] ) || '' === $dir['path'] ) {
		return '';
	}
	return untrailingslashit( $dir['path'] );
}

/**
 * @return bool
 */
function tsosi_scan_content_cache_storage_ready() {
	return '' !== tsosi_scan_cache_storage_dir();
}

/**
 * @return string
 */
function tsosi_scan_cache_user_suffix() {
	return 'u' . max( 0, (int) get_current_user_id() );
}

/**
 * @param string $fingerprint Content fingerprint.
 * @return string Basename only.
 */
function tsosi_scan_cache_index_filename( $fingerprint ) {
	$fp = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $fingerprint ) );
	return 'cache-index-' . tsosi_scan_cache_user_suffix() . '-' . $fp . '.json';
}

/**
 * @return string Basename only.
 */
function tsosi_scan_cache_build_filename() {
	return 'cache-build-' . tsosi_scan_cache_user_suffix() . '.json';
}

/**
 * @param string $basename File basename inside plugin uploads dir.
 * @return string Absolute path or empty.
 */
function tsosi_scan_cache_file_path( $basename ) {
	$dir = tsosi_scan_cache_storage_dir();
	if ( '' === $dir ) {
		return '';
	}
	$safe = basename( (string) $basename );
	if ( '' === $safe || ! preg_match( '/^cache-(index|build)-u\d+/', $safe ) ) {
		return '';
	}
	return $dir . '/' . $safe;
}

/**
 * @param string $path Absolute JSON file path.
 * @return array<string,mixed>|null
 */
function tsosi_scan_cache_read_json_file( $path ) {
	if ( '' === $path || ! is_readable( $path ) ) {
		return null;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- non-executable JSON cache in plugin uploads.
	$raw = file_get_contents( $path );
	if ( false === $raw || '' === $raw ) {
		return null;
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : null;
}

/**
 * @param string              $path Absolute JSON file path.
 * @param array<string,mixed> $data Data to encode.
 * @return bool
 */
function tsosi_scan_cache_write_json_file( $path, $data ) {
	if ( '' === $path || ! is_array( $data ) ) {
		return false;
	}
	$json = wp_json_encode( $data );
	if ( ! is_string( $json ) ) {
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- non-executable JSON cache in plugin uploads.
	return false !== file_put_contents( $path, $json, LOCK_EX );
}

/**
 * Remove finished index + in-progress build files for the current user.
 *
 * @return void
 */
function tsosi_scan_delete_user_cache_files() {
	$dir = tsosi_scan_cache_storage_dir();
	if ( '' === $dir ) {
		return;
	}
	$suffix = tsosi_scan_cache_user_suffix();
	$files  = glob( $dir . '/cache-index-' . $suffix . '-*.json' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
	$build = tsosi_scan_cache_file_path( tsosi_scan_cache_build_filename() );
	if ( '' !== $build && is_file( $build ) ) {
		wp_delete_file( $build );
	}
}

/**
 * @return array{posts:array<int,array<string,mixed>>,extras:array<int,array<string,mixed>>}
 */
function tsosi_scan_load_build_index() {
	$empty = array(
		'posts'  => array(),
		'extras' => array(),
	);
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return $empty;
	}
	$path  = tsosi_scan_cache_file_path( tsosi_scan_cache_build_filename() );
	$data  = tsosi_scan_cache_read_json_file( $path );
	if ( ! is_array( $data ) ) {
		return $empty;
	}
	return array(
		'posts'  => isset( $data['posts'] ) && is_array( $data['posts'] ) ? $data['posts'] : array(),
		'extras' => isset( $data['extras'] ) && is_array( $data['extras'] ) ? $data['extras'] : array(),
	);
}

/**
 * @param array{posts:array<int,array<string,mixed>>,extras:array<int,array<string,mixed>>} $index Index payload.
 * @return bool
 */
function tsosi_scan_save_build_index( $index ) {
	if ( ! tsosi_scan_content_cache_storage_ready() || ! is_array( $index ) ) {
		return false;
	}
	$path = tsosi_scan_cache_file_path( tsosi_scan_cache_build_filename() );
	return tsosi_scan_cache_write_json_file( $path, $index );
}

/**
 * @return void
 */
function tsosi_scan_delete_build_index() {
	$path = tsosi_scan_cache_file_path( tsosi_scan_cache_build_filename() );
	if ( '' !== $path && is_file( $path ) ) {
		wp_delete_file( $path );
	}
}

/**
 * @return void
 */
function tsosi_content_cache_register_hooks() {
	add_action( 'save_post', 'tsosi_flush_content_cache' );
	add_action( 'deleted_post', 'tsosi_flush_content_cache' );
	add_action( 'activated_plugin', 'tsosi_flush_content_cache' );
	add_action( 'deactivated_plugin', 'tsosi_flush_content_cache' );
}
add_action( 'init', 'tsosi_content_cache_register_hooks' );

/**
 * Latest modified timestamp for scannable posts (cache invalidation signal).
 *
 * @return string
 */
function tsosi_scan_get_max_post_modified_gmt() {
	global $wpdb;

	$types = tsosi_get_scannable_post_types();
	if ( empty( $types ) ) {
		return '';
	}

	$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
	$sql          = "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders})";

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fingerprint helper; placeholders built from sanitized post types.
	$max = $wpdb->get_var( $wpdb->prepare( $sql, ...$types ) );

	return is_string( $max ) ? $max : '';
}

/**
 * Fingerprint for scan settings + site content scope.
 *
 * @param array<string,mixed> $settings Scan settings.
 * @param int[]               $post_ids Post IDs in scope.
 * @param array<int,array<string,mixed>> $extras Extra targets.
 * @return string
 */
function tsosi_scan_content_fingerprint( $settings, $post_ids, $extras ) {
	$payload = array(
		'v'            => 1,
		'blog_id'      => get_current_blog_id(),
		'settings'     => $settings,
		'post_count'   => count( $post_ids ),
		'extra_count'  => count( $extras ),
		'max_modified' => tsosi_scan_get_max_post_modified_gmt(),
	);

	$json = wp_json_encode( $payload );
	return md5( is_string( $json ) ? $json : serialize( $payload ) );
}

/**
 * @param string $fingerprint Expected fingerprint.
 * @return array<string,mixed>|null
 */
function tsosi_scan_get_content_cache( $fingerprint ) {
	$meta = get_transient( tsosi_content_cache_transient_key() );
	if ( ! is_array( $meta ) ) {
		return null;
	}
	if ( empty( $meta['fingerprint'] ) || (string) $meta['fingerprint'] !== (string) $fingerprint ) {
		return null;
	}

	// Drop legacy transients that stored the full index in the database (too large).
	if ( ! empty( $meta['index'] ) && empty( $meta['file'] ) ) {
		delete_transient( tsosi_content_cache_transient_key() );
		return null;
	}
	if ( empty( $meta['file'] ) ) {
		return null;
	}

	$path  = tsosi_scan_cache_file_path( (string) $meta['file'] );
	$index = tsosi_scan_cache_read_json_file( $path );
	if ( ! is_array( $index ) ) {
		return null;
	}

	return array(
		'fingerprint' => (string) $fingerprint,
		'index'       => $index,
	);
}

/**
 * @param string               $fingerprint Fingerprint.
 * @param array<string,mixed>  $index       Cached index payload.
 * @return void
 */
function tsosi_scan_save_content_cache( $fingerprint, $index ) {
	if ( ! is_array( $index ) || ! tsosi_scan_content_cache_storage_ready() ) {
		return;
	}

	$filename = tsosi_scan_cache_index_filename( $fingerprint );
	$path     = tsosi_scan_cache_file_path( $filename );
	if ( '' === $path || ! tsosi_scan_cache_write_json_file( $path, $index ) ) {
		return;
	}

	tsosi_scan_delete_build_index();

	set_transient(
		tsosi_content_cache_transient_key(),
		array(
			'fingerprint' => (string) $fingerprint,
			'file'        => $filename,
			'built_at'    => time(),
		),
		TSOSI_CONTENT_CACHE_TTL
	);
}

/**
 * Extract searchable data from one post (no needle matching).
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>|null
 */
function tsosi_scan_extract_post_source( $post_id ) {
	$post_id = absint( $post_id );
	$post    = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	$builder_keys  = array( '_elementor_data', '_fl_builder_data', '_et_pb_old_content', '_wpb_shortcodes_custom_css' );
	$builder_blobs = array();
	foreach ( $builder_keys as $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );
		if ( is_string( $value ) && '' !== $value && strlen( $value ) <= 500000 ) {
			$builder_blobs[] = $value;
		}
	}

	$custom_keys = get_post_custom_keys( $post_id );
	$meta_keys   = array();
	if ( is_array( $custom_keys ) ) {
		foreach ( $custom_keys as $meta_key ) {
			$clean = sanitize_key( (string) $meta_key );
			if ( '' !== $clean ) {
				$meta_keys[] = $clean;
			}
		}
	}

	return array(
		'kind'          => 'post',
		'post_id'       => $post_id,
		'content'       => (string) $post->post_content,
		'meta_keys'     => array_values( array_unique( $meta_keys ) ),
		'builder_blobs' => $builder_blobs,
	);
}

/**
 * Match cached post source against needles.
 *
 * @param array<string,mixed> $source  Cached post source.
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_post_source( $source, $needles ) {
	if ( empty( $source['post_id'] ) ) {
		return array();
	}

	$post = get_post( (int) $source['post_id'] );
	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$tags   = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();
	$found  = array();

	$content = isset( $source['content'] ) ? (string) $source['content'] : '';
	$found   = array_merge( $found, tsosi_scan_content_for_shortcodes( $content, $tags, $post ) );
	$found   = array_merge( $found, tsosi_scan_content_for_blocks( $content, $blocks, $post ) );

	$meta_keys = isset( $source['meta_keys'] ) && is_array( $source['meta_keys'] ) ? $source['meta_keys'] : array();
	$found     = array_merge( $found, tsosi_scan_match_meta_keys_list( $meta_keys, $needles, $post ) );

	$builder_blobs = isset( $source['builder_blobs'] ) && is_array( $source['builder_blobs'] ) ? $source['builder_blobs'] : array();
	foreach ( $builder_blobs as $blob ) {
		if ( ! is_string( $blob ) || '' === $blob ) {
			continue;
		}
		$found = array_merge( $found, tsosi_scan_content_for_shortcodes( $blob, $tags, $post ) );
		$found = array_merge( $found, tsosi_scan_content_for_blocks( $blob, $blocks, $post ) );
	}

	return $found;
}

/**
 * Match a list of meta keys against meta needles.
 *
 * @param string[]            $meta_keys Meta keys on a post.
 * @param array<string,mixed> $needles   Needles.
 * @param WP_Post             $post      Post context.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_meta_keys_list( $meta_keys, $needles, $post ) {
	$keys     = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	$prefixes = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	if ( empty( $keys ) && empty( $prefixes ) ) {
		return array();
	}

	$found        = array();
	$matched_keys = array();

	foreach ( $keys as $meta_key ) {
		$meta_key = sanitize_key( (string) $meta_key );
		if ( '' === $meta_key || ! in_array( $meta_key, $meta_keys, true ) ) {
			continue;
		}
		$matched_keys[] = $meta_key;
		$found[]        = tsosi_scan_make_result(
			$post,
			'meta',
			$meta_key,
			tsosi_ui_triple_text( 'Post meta', 'Meta del post', 'Meta de l\'entrada' )
		);
	}

	foreach ( $meta_keys as $meta_key ) {
		$meta_key = sanitize_key( (string) $meta_key );
		if ( '' === $meta_key || in_array( $meta_key, $matched_keys, true ) ) {
			continue;
		}
		foreach ( $prefixes as $prefix ) {
			if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
				$matched_keys[] = $meta_key;
				$found[]        = tsosi_scan_make_result(
					$post,
					'meta',
					$meta_key,
					tsosi_ui_triple_text( 'Post meta', 'Meta del post', 'Meta de l\'entrada' )
				);
				break;
			}
		}
	}

	return $found;
}

/**
 * Extract widget option blobs for caching.
 *
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_extract_widget_sources() {
	$rows    = array();
	$options = wp_load_alloptions();

	foreach ( $options as $option_name => $value ) {
		$name = (string) $option_name;
		if ( 0 !== strpos( $name, 'widget_' ) && 'sidebars_widgets' !== $name ) {
			continue;
		}
		$blob = tsosi_scan_value_to_blob( $value );
		if ( strlen( $blob ) > 500000 ) {
			continue;
		}
		$rows[] = array(
			'kind'     => 'widget',
			'label'    => sanitize_text_field( $name ),
			'blob'     => $blob,
			'edit_url' => admin_url( 'widgets.php' ),
		);
	}

	return $rows;
}

/**
 * Extract autoload wp_options blobs for caching (skips large/core keys).
 *
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_extract_autoload_option_sources() {
	$rows    = array();
	$options = wp_load_alloptions();
	$skip_exact = array(
		'cron',
		'siteurl',
		'home',
		'blog_charset',
		'db_version',
		'user_roles',
		'active_plugins',
		'recently_activated',
	);
	$skip_prefixes = array(
		'_transient_',
		'_site_transient_',
		'widget_',
	);

	foreach ( $options as $option_name => $value ) {
		$name = (string) $option_name;
		if ( in_array( $name, $skip_exact, true ) ) {
			continue;
		}
		foreach ( $skip_prefixes as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				continue 2;
			}
		}
		$blob = tsosi_scan_value_to_blob( $value );
		if ( strlen( $blob ) > 500000 ) {
			continue;
		}
		$rows[] = array(
			'kind'  => 'option',
			'label' => sanitize_text_field( $name ),
			'blob'  => $blob,
		);
	}

	return $rows;
}

/**
 * @param array<string,mixed> $target Menu target descriptor.
 * @return array<string,mixed>|null
 */
function tsosi_scan_extract_menu_source( $target ) {
	$term_id = isset( $target['term_id'] ) ? absint( $target['term_id'] ) : 0;
	if ( $term_id <= 0 ) {
		return null;
	}

	$items = wp_get_nav_menu_items( $term_id );
	if ( ! is_array( $items ) ) {
		return null;
	}

	$rows = array();
	foreach ( $items as $item ) {
		if ( ! $item instanceof WP_Post ) {
			continue;
		}
		$rows[] = (string) $item->post_content . ' ' . (string) $item->post_title;
	}

	return array(
		'kind'    => 'menu',
		'term_id' => $term_id,
		'name'    => isset( $target['name'] ) ? (string) $target['name'] : (string) $term_id,
		'items'   => $rows,
	);
}

/**
 * Extract one extra target into cacheable sources.
 *
 * @param array<string,mixed> $target Extra target.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_extract_extra_sources( $target ) {
	$type = isset( $target['type'] ) ? sanitize_key( (string) $target['type'] ) : '';

	if ( 'widgets' === $type ) {
		return tsosi_scan_extract_widget_sources();
	}
	if ( 'options' === $type ) {
		return tsosi_scan_extract_autoload_option_sources();
	}
	if ( 'menu' === $type ) {
		$menu = tsosi_scan_extract_menu_source( $target );
		return $menu ? array( $menu ) : array();
	}

	return array();
}

/**
 * Match blob rows (widgets / autoload options).
 *
 * @param array<int,array<string,mixed>> $rows    Cached rows.
 * @param array<string,mixed>            $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_blob_sources( $rows, $needles ) {
	$found = array();
	if ( ! is_array( $rows ) ) {
		return $found;
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['blob'] ) || ! is_string( $row['blob'] ) ) {
			continue;
		}
		$kind = isset( $row['kind'] ) ? sanitize_key( (string) $row['kind'] ) : 'option';
		if ( 'widget' === $kind ) {
			$ctx = tsosi_ui_triple_text( 'Widget option', 'Opción de widget', 'Opció de widget' );
			$found = array_merge(
				$found,
				tsosi_scan_blob_rows(
					$row['blob'],
					$needles,
					isset( $row['label'] ) ? (string) $row['label'] : '',
					$ctx,
					isset( $row['edit_url'] ) ? (string) $row['edit_url'] : admin_url( 'widgets.php' ),
					'widget'
				)
			);
			continue;
		}

		$ctx = tsosi_ui_triple_text( 'wp_options', 'wp_options', 'wp_options' );
		$found = array_merge(
			$found,
			tsosi_scan_blob_rows(
				$row['blob'],
				$needles,
				isset( $row['label'] ) ? (string) $row['label'] : '',
				$ctx,
				'',
				'option'
			)
		);
	}

	return $found;
}

/**
 * @param array<string,mixed> $source  Cached menu source.
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_menu_source( $source, $needles ) {
	if ( empty( $source['term_id'] ) ) {
		return array();
	}

	$term_id = absint( $source['term_id'] );
	$name    = isset( $source['name'] ) ? (string) $source['name'] : (string) $term_id;
	$items   = isset( $source['items'] ) && is_array( $source['items'] ) ? $source['items'] : array();
	$tags    = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks  = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();
	$found   = array();
	$stub    = (object) array( 'ID' => 0 );

	foreach ( $items as $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			continue;
		}
		foreach ( tsosi_scan_content_for_shortcodes( $content, $tags, $stub ) as $row ) {
			$row['source_type']  = 'menu';
			$row['object_label'] = $name;
			$row['context']      = tsosi_ui_triple_text( 'Navigation menu', 'Menú de navegación', 'Menú de navegació' );
			$row['edit_url']     = admin_url( 'nav-menus.php?action=edit&menu=' . $term_id );
			$found[]             = $row;
		}
		foreach ( tsosi_scan_content_for_blocks( $content, $blocks, $stub ) as $row ) {
			$row['source_type']  = 'menu';
			$row['object_label'] = $name;
			$row['context']      = tsosi_ui_triple_text( 'Navigation menu', 'Menú de navegación', 'Menú de navegació' );
			$row['edit_url']     = admin_url( 'nav-menus.php?action=edit&menu=' . $term_id );
			$found[]             = $row;
		}
	}

	return $found;
}

/**
 * Match a full cached index against needles.
 *
 * @param array<string,mixed> $index   Cached index (posts + extras).
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_cached_index( $index, $needles ) {
	$results = array();
	if ( ! is_array( $index ) ) {
		return $results;
	}

	$posts = isset( $index['posts'] ) && is_array( $index['posts'] ) ? $index['posts'] : array();
	foreach ( $posts as $source ) {
		if ( is_array( $source ) ) {
			$results = array_merge( $results, tsosi_scan_match_post_source( $source, $needles ) );
		}
	}

	$extras = isset( $index['extras'] ) && is_array( $index['extras'] ) ? $index['extras'] : array();
	foreach ( $extras as $source ) {
		if ( ! is_array( $source ) ) {
			continue;
		}
		$kind = isset( $source['kind'] ) ? sanitize_key( (string) $source['kind'] ) : '';
		if ( 'menu' === $kind ) {
			$results = array_merge( $results, tsosi_scan_match_menu_source( $source, $needles ) );
			continue;
		}
		if ( 'widget' === $kind || 'option' === $kind ) {
			$results = array_merge( $results, tsosi_scan_match_blob_sources( array( $source ), $needles ) );
		}
	}

	if ( ! empty( $needles['option_prefixes'] ) ) {
		$results = array_merge( $results, tsosi_scan_non_autoload_options( $needles ) );
	}

	return tsosi_scan_dedupe_results( $results );
}

/**
 * Build a completed scan response (shared by cache hit and job finish).
 *
 * @param array<string,mixed> $needles Needles.
 * @param array<int,array<string,mixed>> $results Results.
 * @param int                 $post_count Posts scanned/indexed.
 * @param int                 $extra_count Extras scanned/indexed.
 * @param bool                $from_cache Whether index came from cache.
 * @return array<string,mixed>
 */
function tsosi_scan_build_complete_response( $needles, $results, $post_count, $extra_count, $from_cache = false ) {
	$total_steps = $post_count + $extra_count;

	return array(
		'done'            => true,
		'cached'          => $from_cache,
		'progress'        => 100,
		'processed'       => $post_count,
		'processed_steps' => $total_steps,
		'total_steps'     => $total_steps,
		'total_posts'     => $post_count,
		'results'         => $results,
		'result_count'    => count( $results ),
		'needles'         => $needles,
		'needles_empty'   => tsosi_scan_needles_are_empty( $needles ),
	);
}
