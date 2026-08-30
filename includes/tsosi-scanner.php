<?php
/**
 * Content scanner: shortcodes, blocks, meta, widgets, menus.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types included in scans.
 *
 * @return string[]
 */
function tsosi_get_scannable_post_types() {
	$types = get_post_types( array( 'public' => true ), 'names' );

	// Site Editor templates/parts are not public but often hold blocks and shortcodes.
	$editor_types = array(
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
	);
	foreach ( $editor_types as $type ) {
		if ( post_type_exists( $type ) ) {
			$types[] = $type;
		}
	}

	$types = array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
	sort( $types );
	return $types;
}

/**
 * Create a scan job and store pending object IDs.
 *
 * @param array<string,mixed> $query Scan query (mode, plugin_file, needle, etc.).
 * @return array<string,mixed>|WP_Error
 */
function tsosi_scan_job_start( $query ) {
	$query    = is_array( $query ) ? $query : array();
	$settings = tsosi_get_scan_settings();
	$needles  = tsosi_scan_resolve_needles( $query );

	$post_ids = tsosi_scan_collect_post_ids( $settings );
	$extras   = tsosi_scan_collect_extra_targets( $settings );
	$fingerprint = tsosi_scan_content_fingerprint( $settings, $post_ids, $extras );

	delete_transient( tsosi_scan_job_transient_key() );
	tsosi_scan_delete_build_index();

	$cache = tsosi_scan_get_content_cache( $fingerprint );

	if ( $cache ) {
		$index   = isset( $cache['index'] ) && is_array( $cache['index'] ) ? $cache['index'] : array();
		$results = tsosi_scan_match_cached_index( $index, $needles );
		return tsosi_scan_build_complete_response( $needles, $results, count( $post_ids ), count( $extras ), true );
	}

	$job = array(
		'query'       => $query,
		'needles'     => $needles,
		'post_ids'    => $post_ids,
		'post_offset' => 0,
		'extras'      => $extras,
		'extra_index' => 0,
		'phase'       => 'build',
		'fingerprint' => $fingerprint,
		'use_index'   => tsosi_scan_content_cache_storage_ready(),
		'started_at'  => time(),
	);

	if ( ! empty( $job['use_index'] ) ) {
		tsosi_scan_delete_build_index();
		tsosi_scan_save_build_index(
			array(
				'posts'  => array(),
				'extras' => array(),
			)
		);
	} else {
		$job['results'] = array();
	}

	set_transient( tsosi_scan_job_transient_key(), $job, HOUR_IN_SECONDS );

	return array(
		'total_steps'   => count( $post_ids ) + count( $extras ),
		'total_posts'   => count( $post_ids ),
		'job_id'        => tsosi_scan_job_transient_key(),
		'needles'       => $needles,
		'needles_empty' => tsosi_scan_needles_are_empty( $needles ),
		'cached'        => false,
	);
}

/**
 * @param array<string,mixed> $settings Scan settings.
 * @return int[]
 */
function tsosi_scan_collect_post_ids( $settings ) {
	$statuses = isset( $settings['post_statuses'] ) && is_array( $settings['post_statuses'] )
		? array_map( 'sanitize_key', $settings['post_statuses'] )
		: array( 'publish' );

	$post_types = tsosi_get_scannable_post_types();
	if ( empty( $settings['include_reusable_blocks'] ) ) {
		$post_types = array_values( array_diff( $post_types, array( 'wp_block' ) ) );
	}

	$ids = get_posts(
		array(
			'post_type'              => $post_types,
			'post_status'            => $statuses,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	$ids = is_array( $ids ) ? $ids : array();

	// Site Editor templates may use custom statuses; fetch them explicitly.
	$editor_types = array( 'wp_template', 'wp_template_part', 'wp_navigation' );
	if ( empty( $settings['include_reusable_blocks'] ) ) {
		$editor_types = array_diff( $editor_types, array( 'wp_block' ) );
	} else {
		$editor_types[] = 'wp_block';
	}
	$editor_types = array_values( array_intersect( $editor_types, $post_types ) );
	if ( ! empty( $editor_types ) ) {
		$editor_ids = get_posts(
			array(
				'post_type'              => $editor_types,
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private', 'inherit' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( is_array( $editor_ids ) && ! empty( $editor_ids ) ) {
			$ids = array_merge( $ids, $editor_ids );
		}
	}

	return array_values( array_unique( array_map( 'absint', $ids ) ) );
}

/**
 * Non-post scan targets (widgets, menus).
 *
 * @param array<string,mixed> $settings Scan settings.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_collect_extra_targets( $settings ) {
	$targets = array();
	if ( ! empty( $settings['include_widgets'] ) ) {
		$targets[] = array(
			'type' => 'widgets',
		);
		$targets[] = array(
			'type' => 'options',
		);
	}
	if ( ! empty( $settings['include_non_autoload_options'] ) ) {
		$targets[] = array(
			'type' => 'non_autoload_options',
		);
	}
	if ( ! empty( $settings['include_menus'] ) ) {
		$menus = wp_get_nav_menus();
		if ( is_array( $menus ) ) {
			foreach ( $menus as $menu ) {
				if ( ! $menu instanceof WP_Term ) {
					continue;
				}
				$targets[] = array(
					'type'    => 'menu',
					'term_id' => (int) $menu->term_id,
					'name'    => (string) $menu->name,
				);
			}
		}
	}
	return $targets;
}

/**
 * Process the next batch of a scan job.
 *
 * @return array<string,mixed>|WP_Error
 */
function tsosi_scan_job_step() {
	$job = get_transient( tsosi_scan_job_transient_key() );
	if ( ! is_array( $job ) ) {
		return new WP_Error( 'tsosi_no_job', __( 'No scan in progress.', 'tso-stack-inspector' ) );
	}

	// Drop stale jobs from the previous cache format (index stored in the DB transient).
	if ( isset( $job['index'] ) ) {
		delete_transient( tsosi_scan_job_transient_key() );
		tsosi_scan_delete_build_index();
		return new WP_Error( 'tsosi_stale_job', __( 'Scan session expired. Please start again.', 'tso-stack-inspector' ) );
	}

	if ( empty( $job['use_index'] ) ) {
		return tsosi_scan_job_step_legacy( $job );
	}

	$query       = isset( $job['query'] ) && is_array( $job['query'] ) ? $job['query'] : array();
	$needles     = isset( $job['needles'] ) && is_array( $job['needles'] ) ? $job['needles'] : tsosi_scan_resolve_needles( $query );
	$post_ids    = isset( $job['post_ids'] ) && is_array( $job['post_ids'] ) ? $job['post_ids'] : array();
	$offset      = isset( $job['post_offset'] ) ? (int) $job['post_offset'] : 0;
	$extras      = isset( $job['extras'] ) && is_array( $job['extras'] ) ? $job['extras'] : array();
	$extra_index = isset( $job['extra_index'] ) ? (int) $job['extra_index'] : 0;
	$index       = tsosi_scan_load_build_index();

	$batch = array_slice( $post_ids, $offset, TSOSI_SCAN_BATCH_SIZE );
	foreach ( $batch as $post_id ) {
		$source = tsosi_scan_extract_post_source( (int) $post_id );
		if ( is_array( $source ) ) {
			$index['posts'][] = $source;
		}
		$offset++;
	}

	if ( ! tsosi_scan_save_build_index( $index ) ) {
		$job['use_index']   = false;
		$job['post_offset'] = $offset;
		$job['results']     = tsosi_scan_match_cached_index( $index, $needles );
		set_transient( tsosi_scan_job_transient_key(), $job, HOUR_IN_SECONDS );
		return tsosi_scan_job_step_legacy( $job );
	}

	$job['post_offset'] = $offset;
	$job['needles']     = $needles;

	$done_posts = $offset >= count( $post_ids );

	if ( $done_posts && $extra_index < count( $extras ) ) {
		$target = $extras[ $extra_index ];
		if ( is_array( $target ) && isset( $target['type'] ) && 'non_autoload_options' === sanitize_key( (string) $target['type'] ) ) {
			// Needle-dependent; matched only after the index is built.
			++$extra_index;
		} else {
			$index['extras'] = array_merge( $index['extras'], tsosi_scan_extract_extra_sources( $target ) );
			tsosi_scan_save_build_index( $index );
			++$extra_index;
		}
		$job['extra_index'] = $extra_index;
	}

	$extras_done = $done_posts && $extra_index >= count( $extras );
	$complete    = $extras_done;

	if ( $complete ) {
		$fingerprint = isset( $job['fingerprint'] ) ? (string) $job['fingerprint'] : '';
		if ( '' !== $fingerprint ) {
			tsosi_scan_save_content_cache( $fingerprint, $index );
		}
		$results = tsosi_scan_match_cached_index( $index, $needles );
		tsosi_scan_delete_build_index();
		delete_transient( tsosi_scan_job_transient_key() );

		return tsosi_scan_build_complete_response( $needles, $results, count( $post_ids ), count( $extras ), false );
	}

	set_transient( tsosi_scan_job_transient_key(), $job, HOUR_IN_SECONDS );

	$total_steps = count( $post_ids ) + count( $extras );
	$current     = min( $offset, count( $post_ids ) ) + min( $extra_index, count( $extras ) );

	return array(
		'done'            => false,
		'cached'          => false,
		'progress'        => $total_steps > 0 ? min( 100, (int) round( ( $current / $total_steps ) * 100 ) ) : 100,
		'processed'       => $offset,
		'processed_steps' => $current,
		'total_steps'     => $total_steps,
		'total_posts'     => count( $post_ids ),
		'results'         => array(),
		'result_count'    => 0,
		'needles'         => $needles,
		'needles_empty'   => tsosi_scan_needles_are_empty( $needles ),
	);
}

/**
 * Legacy batched scan (needle matching per batch) when file cache is unavailable.
 *
 * @param array<string,mixed> $job Scan job state.
 * @return array<string,mixed>|WP_Error
 */
function tsosi_scan_job_step_legacy( $job ) {
	$query       = isset( $job['query'] ) && is_array( $job['query'] ) ? $job['query'] : array();
	$needles     = isset( $job['needles'] ) && is_array( $job['needles'] ) ? $job['needles'] : tsosi_scan_resolve_needles( $query );
	$post_ids    = isset( $job['post_ids'] ) && is_array( $job['post_ids'] ) ? $job['post_ids'] : array();
	$offset      = isset( $job['post_offset'] ) ? (int) $job['post_offset'] : 0;
	$results     = isset( $job['results'] ) && is_array( $job['results'] ) ? $job['results'] : array();
	$extras      = isset( $job['extras'] ) && is_array( $job['extras'] ) ? $job['extras'] : array();
	$extra_index = isset( $job['extra_index'] ) ? (int) $job['extra_index'] : 0;
	$batch       = array_slice( $post_ids, $offset, TSOSI_SCAN_BATCH_SIZE );

	foreach ( $batch as $post_id ) {
		$found   = tsosi_scan_post( (int) $post_id, $needles );
		$results = array_merge( $results, $found );
		$offset++;
	}

	$job['post_offset'] = $offset;
	$job['results']     = $results;
	$job['needles']     = $needles;
	$job['use_index']   = false;

	$done_posts = $offset >= count( $post_ids );

	if ( $done_posts && $extra_index < count( $extras ) ) {
		$results            = array_merge( $results, tsosi_scan_extra_target( $extras[ $extra_index ], $needles ) );
		++$extra_index;
		$job['extra_index'] = $extra_index;
		$job['results']     = $results;
	}

	$extras_done = $done_posts && $extra_index >= count( $extras );
	$complete    = $extras_done;

	if ( $complete ) {
		$results = tsosi_scan_dedupe_results( $results );
		delete_transient( tsosi_scan_job_transient_key() );

		return tsosi_scan_build_complete_response( $needles, $results, count( $post_ids ), count( $extras ), false );
	}

	set_transient( tsosi_scan_job_transient_key(), $job, HOUR_IN_SECONDS );

	$total_steps = count( $post_ids ) + count( $extras );
	$current     = min( $offset, count( $post_ids ) ) + min( $extra_index, count( $extras ) );

	return array(
		'done'            => false,
		'cached'          => false,
		'progress'        => $total_steps > 0 ? min( 100, (int) round( ( $current / $total_steps ) * 100 ) ) : 100,
		'processed'       => $offset,
		'processed_steps' => $current,
		'total_steps'     => $total_steps,
		'total_posts'     => count( $post_ids ),
		'results'         => $results,
		'result_count'    => count( $results ),
		'needles'         => $needles,
		'needles_empty'   => tsosi_scan_needles_are_empty( $needles ),
	);
}

/**
 * Build match needles from scan query.
 *
 * @param array<string,mixed> $query Query.
 * @return array<string,mixed>
 */
function tsosi_scan_resolve_needles( $query ) {
	$mode = isset( $query['mode'] ) ? sanitize_key( (string) $query['mode'] ) : 'plugin';

	$shortcodes = array();
	$blocks     = array();
	$meta_keys  = array();
	$meta_prefixes = array();
	$option_prefixes = array();

	if ( 'shortcode' === $mode ) {
		$tag = isset( $query['shortcode'] ) ? tsosi_sanitize_shortcode_tag( (string) $query['shortcode'] ) : '';
		if ( '' !== $tag ) {
			$shortcodes[] = $tag;
		}
	} elseif ( 'block' === $mode ) {
		$name = isset( $query['block'] ) ? tsosi_sanitize_block_name( (string) $query['block'] ) : '';
		if ( '' !== $name ) {
			$blocks[] = $name;
		}
	} elseif ( 'plugin' === $mode ) {
		$plugin_file = isset( $query['plugin_file'] ) ? tsosi_sanitize_plugin_file( (string) $query['plugin_file'] ) : '';
		if ( '' !== $plugin_file ) {
			$profile = tsosi_build_plugin_profile( $plugin_file );
			$shortcodes      = isset( $profile['shortcodes'] ) ? (array) $profile['shortcodes'] : array();
			$blocks          = isset( $profile['blocks'] ) ? (array) $profile['blocks'] : array();
			$meta_prefixes   = isset( $profile['meta_prefixes'] ) ? (array) $profile['meta_prefixes'] : array();
			$option_prefixes = isset( $profile['option_prefixes'] ) ? (array) $profile['option_prefixes'] : array();
			if ( empty( $shortcodes ) && empty( $blocks ) && empty( $meta_prefixes ) && empty( $option_prefixes ) ) {
				$folder = tsosi_get_plugin_folder_raw( $plugin_file );
				if ( '' !== $folder && strlen( $folder ) >= 3 ) {
					$slug_prefix       = sanitize_key( str_replace( '-', '_', $folder ) );
					$meta_prefixes[]   = $slug_prefix;
					$option_prefixes[] = $slug_prefix;
				}
			}
		}
	}

	return array(
		'shortcodes'      => array_values( array_unique( array_filter( $shortcodes ) ) ),
		'blocks'          => array_values( array_unique( array_filter( $blocks ) ) ),
		'meta_keys'       => array_values( array_unique( array_filter( $meta_keys ) ) ),
		'meta_prefixes'   => array_values( array_unique( array_filter( $meta_prefixes ) ) ),
		'option_prefixes' => array_values( array_unique( array_filter( $option_prefixes ) ) ),
	);
}

/**
 * @param array<string,mixed> $needles Needle set.
 * @return bool
 */
function tsosi_scan_needles_are_empty( $needles ) {
	if ( ! is_array( $needles ) ) {
		return true;
	}
	foreach ( array( 'shortcodes', 'blocks', 'meta_keys', 'meta_prefixes', 'option_prefixes' ) as $key ) {
		if ( ! empty( $needles[ $key ] ) && is_array( $needles[ $key ] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Remove duplicate scan rows (same location, type, and match).
 *
 * @param array<int,array<string,mixed>> $results Result rows.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_dedupe_results( $results ) {
	if ( ! is_array( $results ) || empty( $results ) ) {
		return array();
	}

	$seen = array();
	$out  = array();

	foreach ( $results as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$key = implode(
			'|',
			array(
				isset( $row['source_type'] ) ? (string) $row['source_type'] : '',
				isset( $row['object_label'] ) ? (string) $row['object_label'] : '',
				isset( $row['match_type'] ) ? (string) $row['match_type'] : '',
				isset( $row['match_value'] ) ? (string) $row['match_value'] : '',
				isset( $row['context'] ) ? (string) $row['context'] : '',
			)
		);
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[]        = $row;
	}

	return $out;
}

/**
 * Scan one post for matches.
 *
 * @param int                 $post_id Post ID.
 * @param array<string,mixed> $needles Resolved needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_post( $post_id, $needles ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	if ( ! is_array( $needles ) ) {
		$needles = array(
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_keys'       => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array(),
		);
	}

	$found   = array();
	$content = (string) $post->post_content;
	$tags    = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks  = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();

	$found = array_merge( $found, tsosi_scan_content_for_shortcodes( $content, $tags, $post ) );
	$found = array_merge( $found, tsosi_scan_content_for_blocks( $content, $blocks, $post ) );

	if ( ! empty( $needles['meta_prefixes'] ) || ! empty( $needles['meta_keys'] ) ) {
		$found = array_merge( $found, tsosi_scan_post_meta( $post_id, $needles, $post ) );
	}

	$found = array_merge( $found, tsosi_scan_post_builder_meta( $post_id, $needles, $post ) );

	return $found;
}

/**
 * @param string              $content    Content.
 * @param string[]            $shortcodes Tags to find.
 * @param WP_Post             $post       Post context.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_content_for_shortcodes( $content, $shortcodes, $post ) {
	if ( empty( $shortcodes ) || '' === $content ) {
		return array();
	}

	$found = array();
	if ( ! preg_match_all( '/\[([a-z0-9_-]+)/i', $content, $matches ) ) {
		return array();
	}

	$present = array_unique( array_map( 'tsosi_sanitize_shortcode_tag', $matches[1] ) );
	foreach ( $shortcodes as $tag ) {
		$tag = tsosi_sanitize_shortcode_tag( (string) $tag );
		if ( '' !== $tag && in_array( $tag, $present, true ) ) {
			$found[] = tsosi_scan_make_result(
				$post,
				'shortcode',
				$tag,
				tsosi_ui_triple_text( 'Post content', 'Contenido del post', 'Contingut de l\'entrada' )
			);
		}
	}
	return $found;
}

/**
 * @param string   $content Content.
 * @param string[] $blocks  Block names.
 * @param WP_Post  $post    Post context.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_content_for_blocks( $content, $blocks, $post ) {
	if ( empty( $blocks ) || '' === $content ) {
		return array();
	}

	$found = array();
	if ( ! preg_match_all( '/<!--\s*wp:([a-z0-9\/-]+)/i', $content, $matches ) ) {
		return array();
	}

	$present = array_unique( array_map( 'tsosi_sanitize_block_name', $matches[1] ) );
	foreach ( $blocks as $block_name ) {
		$block_name = tsosi_sanitize_block_name( (string) $block_name );
		$block_root = strstr( $block_name, '/', true );
		if ( false === $block_root ) {
			$block_root = $block_name;
		}
		foreach ( $present as $candidate ) {
			if (
				$candidate === $block_name
				|| 0 === strpos( $candidate, $block_name . '/' )
				|| 0 === strpos( $candidate, $block_name . ' ' )
				|| ( '' !== $block_root && 0 === strpos( $candidate, $block_root . '/' ) )
			) {
				$found[] = tsosi_scan_make_result(
					$post,
					'block',
					$candidate,
					tsosi_ui_triple_text( 'Block editor', 'Editor de bloques', 'Editor de blocs' )
				);
				break;
			}
		}
	}
	return $found;
}

/**
 * @param int                 $post_id Post ID.
 * @param array<string,mixed> $needles Needles.
 * @param WP_Post             $post    Post.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_post_meta( $post_id, $needles, $post ) {
	$post_id = absint( $post_id );
	if ( $post_id <= 0 ) {
		return array();
	}

	$keys     = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	$prefixes = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	if ( empty( $keys ) && empty( $prefixes ) ) {
		return array();
	}

	$found       = array();
	$matched_keys = array();

	foreach ( $keys as $meta_key ) {
		$meta_key = sanitize_key( (string) $meta_key );
		if ( '' === $meta_key || in_array( $meta_key, $matched_keys, true ) ) {
			continue;
		}
		if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
			$matched_keys[] = $meta_key;
			$found[]        = tsosi_scan_make_result(
				$post,
				'meta',
				$meta_key,
				tsosi_ui_triple_text( 'Post meta', 'Meta del post', 'Meta de l\'entrada' )
			);
		}
	}

	if ( empty( $prefixes ) ) {
		return $found;
	}

	$custom_keys = get_post_custom_keys( $post_id );
	if ( ! is_array( $custom_keys ) || empty( $custom_keys ) ) {
		return $found;
	}

	foreach ( $custom_keys as $meta_key ) {
		$meta_key = sanitize_key( (string) $meta_key );
		if ( '' === $meta_key || in_array( $meta_key, $matched_keys, true ) ) {
			continue;
		}
		$match = false;
		foreach ( $prefixes as $prefix ) {
			if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
				$match = true;
				break;
			}
		}
		if ( $match ) {
			$matched_keys[] = $meta_key;
			$found[]        = tsosi_scan_make_result(
				$post,
				'meta',
				$meta_key,
				tsosi_ui_triple_text( 'Post meta', 'Meta del post', 'Meta de l\'entrada' )
			);
		}
	}

	return $found;
}

/**
 * Scan known page-builder meta blobs.
 *
 * @param int                 $post_id Post ID.
 * @param array<string,mixed> $needles Needles.
 * @param WP_Post             $post    Post.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_post_builder_meta( $post_id, $needles, $post ) {
	$builder_keys = array( '_elementor_data', '_fl_builder_data', '_et_pb_old_content', '_wpb_shortcodes_custom_css' );
	$found        = array();

	foreach ( $builder_keys as $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );
		if ( ! is_string( $value ) || '' === $value ) {
			continue;
		}
		$found = array_merge(
			$found,
			tsosi_scan_content_for_shortcodes(
				$value,
				isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array(),
				$post
			),
			tsosi_scan_content_for_blocks(
				$value,
				isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array(),
				$post
			)
		);
	}

	return $found;
}

/**
 * Scan widgets, menus, or wp_options blobs.
 *
 * @param array<string,mixed> $target  Target descriptor.
 * @param array<string,mixed> $needles Resolved needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_extra_target( $target, $needles ) {
	$type = isset( $target['type'] ) ? sanitize_key( (string) $target['type'] ) : '';
	if ( 'widgets' === $type ) {
		return tsosi_scan_widgets( $needles );
	}
	if ( 'options' === $type ) {
		return tsosi_scan_options( $needles );
	}
	if ( 'non_autoload_options' === $type ) {
		return tsosi_scan_non_autoload_options( $needles );
	}
	if ( 'menu' === $type ) {
		return tsosi_scan_menu( $target, $needles );
	}
	return array();
}

/**
 * Convert an option value to searchable text.
 *
 * @param mixed $value Option value.
 * @return string
 */
function tsosi_scan_value_to_blob( $value ) {
	if ( is_string( $value ) ) {
		return $value;
	}
	if ( is_scalar( $value ) ) {
		return (string) $value;
	}
	$encoded = wp_json_encode( $value );
	return is_string( $encoded ) ? $encoded : maybe_serialize( $value );
}

/**
 * @param array<string,mixed> $needles Needles.
 * @param string              $label   Object label.
 * @param string              $context Context label.
 * @param string              $edit_url Edit URL.
 * @param string              $source_type Source type slug.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_blob_rows( $blob, $needles, $label, $context, $edit_url, $source_type ) {
	$found = array();
	if ( ! is_string( $blob ) || '' === $blob ) {
		return $found;
	}

	$shortcodes = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks     = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();

	foreach ( tsosi_scan_blob_for_shortcodes( $blob, $shortcodes ) as $tag ) {
		$found[] = array(
			'source_type'  => $source_type,
			'object_id'    => 0,
			'object_label' => $label,
			'match_type'   => 'shortcode',
			'match_value'  => $tag,
			'context'      => $context,
			'edit_url'     => $edit_url,
		);
	}

	foreach ( tsosi_scan_blob_for_blocks( $blob, $blocks ) as $block ) {
		$found[] = array(
			'source_type'  => $source_type,
			'object_id'    => 0,
			'object_label' => $label,
			'match_type'   => 'block',
			'match_value'  => $block,
			'context'      => $context,
			'edit_url'     => $edit_url,
		);
	}

	return $found;
}

/**
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_widgets( $needles ) {
	$found   = array();
	$options = wp_load_alloptions();
	$ctx     = tsosi_ui_triple_text( 'Widget option', 'Opción de widget', 'Opció de widget' );

	foreach ( $options as $option_name => $value ) {
		$name = (string) $option_name;
		if ( 0 !== strpos( $name, 'widget_' ) && 'sidebars_widgets' !== $name ) {
			continue;
		}
		$blob = tsosi_scan_value_to_blob( $value );
		$found = array_merge(
			$found,
			tsosi_scan_blob_rows(
				$blob,
				$needles,
				sanitize_text_field( $name ),
				$ctx,
				admin_url( 'widgets.php' ),
				'widget'
			)
		);
	}

	return $found;
}

/**
 * Scan autoloaded wp_options for shortcodes/blocks (plugin settings, theme mods, etc.).
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_options( $needles ) {
	if ( tsosi_scan_needles_are_empty( $needles ) ) {
		return array();
	}

	$found   = array();
	$options = wp_load_alloptions();
	$ctx     = tsosi_ui_triple_text( 'wp_options', 'wp_options', 'wp_options' );
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

		$found = array_merge(
			$found,
			tsosi_scan_blob_rows(
				$blob,
				$needles,
				sanitize_text_field( $name ),
				$ctx,
				'',
				'option'
			)
		);
	}

	return $found;
}

/**
 * Scan non-autoload wp_options rows that match plugin option prefixes.
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_non_autoload_options( $needles ) {
	$prefixes = isset( $needles['option_prefixes'] ) && is_array( $needles['option_prefixes'] )
		? array_values( array_filter( $needles['option_prefixes'] ) )
		: array();
	if ( empty( $prefixes ) ) {
		return array();
	}

	global $wpdb;

	$found  = array();
	$ctx    = tsosi_ui_triple_text( 'wp_options (no autoload)', 'wp_options (sin autoload)', 'wp_options (sense autoload)' );
	$seen   = array();

	foreach ( $prefixes as $prefix ) {
		$prefix = sanitize_key( str_replace( '-', '_', (string) $prefix ) );
		if ( strlen( $prefix ) < 3 ) {
			continue;
		}
		$like = $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin-only scan of option names.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE autoload = 'no' AND option_name LIKE %s LIMIT 200",
				$like
			)
		);

		if ( ! is_array( $rows ) ) {
			continue;
		}
		foreach ( $rows as $option_name ) {
			$option_name = sanitize_key( (string) $option_name );
			if ( '' === $option_name || isset( $seen[ $option_name ] ) ) {
				continue;
			}
			$seen[ $option_name ] = true;
			$found[] = array(
				'source_type'    => 'option',
				'object_id'      => 0,
				'object_label'   => $option_name,
				'object_subtype' => '',
				'match_type'     => 'option',
				'match_value'    => $option_name,
				'context'        => $ctx,
				'edit_url'       => '',
			);
			$value = get_option( $option_name );
			$blob  = tsosi_scan_value_to_blob( $value );
			if ( strlen( $blob ) > 500000 ) {
				continue;
			}
			$found = array_merge(
				$found,
				tsosi_scan_blob_rows(
					$blob,
					$needles,
					$option_name,
					$ctx,
					'',
					'option'
				)
			);
		}
	}

	return $found;
}

/**
 * @param array<string,mixed> $target Menu target.
 * @param array<string,mixed> $query  Query.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_menu( $target, $needles ) {
	$term_id = isset( $target['term_id'] ) ? absint( $target['term_id'] ) : 0;
	if ( $term_id <= 0 ) {
		return array();
	}

	$items = wp_get_nav_menu_items( $term_id );
	$found = array();
	$name  = isset( $target['name'] ) ? (string) $target['name'] : (string) $term_id;

	if ( ! is_array( $items ) ) {
		return array();
	}

	foreach ( $items as $item ) {
		if ( ! $item instanceof WP_Post ) {
			continue;
		}
		$content = (string) $item->post_content . ' ' . (string) $item->post_title;
		foreach ( tsosi_scan_content_for_shortcodes( $content, $needles['shortcodes'], $item ) as $row ) {
			$row['source_type']  = 'menu';
			$row['object_label'] = $name;
			$row['context']      = tsosi_ui_triple_text( 'Navigation menu', 'Menú de navegación', 'Menú de navegació' );
			$row['edit_url']     = admin_url( 'nav-menus.php?action=edit&menu=' . $term_id );
			$found[]             = $row;
		}
		foreach ( tsosi_scan_content_for_blocks( $content, $needles['blocks'], $item ) as $row ) {
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
 * @param string   $blob       Serialized content.
 * @param string[] $shortcodes Tags.
 * @return string[]
 */
function tsosi_scan_blob_for_shortcodes( $blob, $shortcodes ) {
	if ( empty( $shortcodes ) || '' === $blob ) {
		return array();
	}
	$hits = array();
	if ( ! preg_match_all( '/\[([a-z0-9_-]+)/i', $blob, $matches ) ) {
		return array();
	}
	$present = array_unique( array_map( 'tsosi_sanitize_shortcode_tag', $matches[1] ) );
	foreach ( $shortcodes as $tag ) {
		$tag = tsosi_sanitize_shortcode_tag( (string) $tag );
		if ( '' !== $tag && in_array( $tag, $present, true ) ) {
			$hits[] = $tag;
		}
	}
	return $hits;
}

/**
 * @param string   $blob   Content blob.
 * @param string[] $blocks Block names.
 * @return string[]
 */
function tsosi_scan_blob_for_blocks( $blob, $blocks ) {
	if ( empty( $blocks ) || '' === $blob ) {
		return array();
	}
	$hits = array();
	if ( ! preg_match_all( '/<!--\s*wp:([a-z0-9\/-]+)/i', $blob, $matches ) ) {
		return array();
	}
	$present = array_unique( array_map( 'tsosi_sanitize_block_name', $matches[1] ) );
	foreach ( $blocks as $block_name ) {
		$block_name = tsosi_sanitize_block_name( (string) $block_name );
		$block_root = strstr( $block_name, '/', true );
		if ( false === $block_root ) {
			$block_root = $block_name;
		}
		foreach ( $present as $candidate ) {
			if (
				$candidate === $block_name
				|| 0 === strpos( $candidate, $block_name . '/' )
				|| ( '' !== $block_root && 0 === strpos( $candidate, $block_root . '/' ) )
			) {
				$hits[] = $candidate;
				break;
			}
		}
	}
	return $hits;
}

/**
 * Whether a post meta key belongs to a plugin prefix.
 *
 * @param string $meta_key Meta key.
 * @param string $prefix   Prefix to match.
 * @return bool
 */
function tsosi_scan_meta_key_matches_prefix( $meta_key, $prefix ) {
	$meta_key = sanitize_key( (string) $meta_key );
	$prefix   = sanitize_key( (string) $prefix );
	if ( '' === $meta_key || '' === $prefix ) {
		return false;
	}

	$normalized = ltrim( $meta_key, '_' );
	if ( 0 !== strpos( $normalized, $prefix ) ) {
		return false;
	}

	$next = strlen( $prefix );
	if ( strlen( $normalized ) === $next ) {
		return true;
	}

	$separator = $normalized[ $next ];
	return '_' === $separator || '-' === $separator;
}

/**
 * Resolve an admin edit URL for a scanned post-like object.
 *
 * @param WP_Post $post Post object.
 * @return string
 */
function tsosi_scan_get_edit_url( $post ) {
	if ( ! $post instanceof WP_Post ) {
		return '';
	}

	$link = get_edit_post_link( $post, 'raw' );
	if ( is_string( $link ) && '' !== $link ) {
		return $link;
	}

	if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block' ), true ) ) {
		return admin_url( 'site-editor.php' );
	}

	return '';
}

/**
 * Normalize one scan hit row.
 *
 * @param WP_Post $post       Post object.
 * @param string  $match_type shortcode|block|meta.
 * @param string  $match_value Value.
 * @param string  $context    Human context.
 * @return array<string,mixed>
 */
function tsosi_scan_make_result( $post, $match_type, $match_value, $context ) {
	$post_id   = $post instanceof WP_Post ? (int) $post->ID : 0;
	$title     = $post instanceof WP_Post ? get_the_title( $post ) : '';
	$type      = $post instanceof WP_Post ? (string) $post->post_type : '';
	$edit_link = tsosi_scan_get_edit_url( $post );

	return array(
		'source_type'  => 'post',
		'object_id'    => $post_id,
		'object_label' => '' !== $title ? $title : '#' . $post_id,
		'object_subtype' => sanitize_key( $type ),
		'match_type'   => sanitize_key( $match_type ),
		'match_value'  => sanitize_text_field( (string) $match_value ),
		'context'      => sanitize_text_field( (string) $context ),
		'edit_url'     => $edit_link,
	);
}

/**
 * List registered shortcodes with zero scan (inventory only).
 *
 * @return array<int,array<string,mixed>>
 */
function tsosi_get_registered_shortcode_inventory() {
	$inventory = tsosi_get_shortcode_inventory();
	$rows      = array();

	foreach ( $inventory['registered_tags'] as $tag ) {
		$rows[] = array(
			'tag'        => $tag,
			'registered' => true,
		);
	}

	return $rows;
}

/**
 * Shortcode tags for the finder dropdown (registered vs code discovery).
 *
 * @return array{registered_tags:string[],plugin_code_tags:string[],all_tags:string[]}
 */
function tsosi_get_shortcode_inventory() {
	$registered_map = array();
	$plugin_map     = array();

	global $shortcode_tags;
	if ( is_array( $shortcode_tags ) ) {
		foreach ( array_keys( $shortcode_tags ) as $tag ) {
			$clean = tsosi_sanitize_shortcode_tag( (string) $tag );
			if ( '' !== $clean ) {
				$registered_map[ $clean ] = true;
			}
		}
	}

	foreach ( tsosi_get_all_plugin_profiles() as $profile ) {
		if ( ! is_array( $profile ) || empty( $profile['shortcodes'] ) ) {
			continue;
		}
		foreach ( (array) $profile['shortcodes'] as $tag ) {
			$clean = tsosi_sanitize_shortcode_tag( (string) $tag );
			if ( '' !== $clean ) {
				$plugin_map[ $clean ] = true;
			}
		}
	}

	$registered_tags  = array_keys( $registered_map );
	$plugin_code_tags = array_values( array_diff( array_keys( $plugin_map ), $registered_tags ) );
	$all_tags         = array_values( array_unique( array_merge( $registered_tags, $plugin_code_tags ) ) );
	sort( $registered_tags );
	sort( $plugin_code_tags );
	sort( $all_tags );

	return array(
		'registered_tags'  => $registered_tags,
		'plugin_code_tags' => $plugin_code_tags,
		'all_tags'         => $all_tags,
	);
}

/**
 * Block names for the block finder dropdown (plugin signatures + registered blocks).
 *
 * @return array{plugin_blocks:string[],registered_blocks:string[]}
 */
function tsosi_get_block_inventory() {
	$registered_map = array();
	$plugin_map     = array();

	if ( class_exists( 'WP_Block_Type_Registry' ) ) {
		$registry = WP_Block_Type_Registry::get_instance();
		$blocks   = $registry->get_all_registered();
		if ( is_array( $blocks ) ) {
			foreach ( array_keys( $blocks ) as $block_name ) {
				$clean = tsosi_sanitize_block_name( (string) $block_name );
				if ( '' !== $clean ) {
					$registered_map[ $clean ] = true;
				}
			}
		}
	}

	foreach ( tsosi_get_all_plugin_profiles() as $profile ) {
		if ( ! is_array( $profile ) || empty( $profile['blocks'] ) ) {
			continue;
		}
		foreach ( (array) $profile['blocks'] as $block_name ) {
			$clean = tsosi_sanitize_block_name( (string) $block_name );
			if ( '' !== $clean ) {
				$plugin_map[ $clean ] = true;
				$registered_map[ $clean ] = true;
			}
		}
	}

	$plugin_blocks = array_keys( $plugin_map );
	$all_blocks    = array_keys( $registered_map );
	sort( $plugin_blocks );
	sort( $all_blocks );

	return array(
		'plugin_blocks'     => $plugin_blocks,
		'registered_blocks' => $all_blocks,
	);
}
