<?php
/**
 * Public endpoints (robots.txt, sitemaps, feed): only URLs that should exist, and only for
 * plugins that can show up there. A site must not fill its 404 log because of a scan.
 *
 * NOTE: no test here may call tsosi_usage_endpoints_snapshot() (it is memoized per process)
 * before 'a plugin that cannot generate sitemaps causes no request at all'.
 *
 * @package TSO_Stack_Inspector
 */

/**
 * Canned 200 answers for a list of paths on https://x.test.
 *
 * @param array<string,string> $pages Path => body.
 * @return void
 */
function tsosi_test_site_pages( $pages ) {
	$GLOBALS['tsosi_test']['http'] = array();
	foreach ( $pages as $path => $body ) {
		$GLOBALS['tsosi_test']['http'][ 'https://x.test' . $path ] = array(
			'code' => 200,
			'body' => $body,
		);
	}
}

tsosi_test(
	'a plugin that cannot generate sitemaps causes no request at all',
	function () {
		$GLOBALS['tsosi_test']['plugins'] = array(
			'cool-gallery/cool-gallery.php' => array(
				'Name'        => 'Cool Gallery',
				'Description' => 'Beautiful image galleries for your posts.',
			),
		);
		tsosi_assert_same( array(), tsosi_usage_endpoint_hits( 'cool-gallery/cool-gallery.php' ) );
		tsosi_assert_same( array(), $GLOBALS['tsosi_test']['requested'], 'no URL was requested' );
	}
);

tsosi_test(
	'sitemap, SEO, robots and feed plugins are relevant, in three languages',
	function () {
		foreach ( array( 'google-sitemap-generator', 'wordpress-seo', 'Yoast SEO', 'All in One SEO', 'Custom robots.txt editor', 'RSS feed importer', 'Crea mapas del sitio para buscadores', 'Genera mapes del lloc' ) as $text ) {
			tsosi_assert_true( tsosi_usage_endpoint_text_is_relevant( $text ), $text );
		}
		foreach ( array( 'Cool Gallery', 'Contact form builder', 'Post Views Counter', 'Cookie consent banner', 'Media cleaner', 'Disney movie lists' ) as $text ) {
			tsosi_assert_same( false, tsosi_usage_endpoint_text_is_relevant( $text ), $text );
		}
	}
);

tsosi_test(
	'relevance is read from the plugin header and the slug',
	function () {
		$GLOBALS['tsosi_test']['plugins'] = array(
			'a/a.php'               => array(
				'Name'        => 'A',
				'Description' => 'Makes an XML sitemap.',
			),
			'google-sitemap-x/b.php' => array(
				'Name'        => 'B',
				'Description' => 'Something',
			),
			'c/c.php'               => array(
				'Name'        => 'C',
				'Description' => 'Something else',
			),
		);
		tsosi_assert_true( tsosi_usage_endpoint_plugin_is_relevant( 'a/a.php' ), 'description' );
		tsosi_assert_true( tsosi_usage_endpoint_plugin_is_relevant( 'google-sitemap-x/b.php' ), 'folder slug' );
		tsosi_assert_same( false, tsosi_usage_endpoint_plugin_is_relevant( 'c/c.php' ) );
	}
);

tsosi_test(
	'robots.txt sitemaps are used, third-party hosts are ignored',
	function () {
		$robots = "User-agent: *\nDisallow: /wp-admin/\nSitemap: https://x.test/wp-sitemap.xml\nsitemap: https://www.x.test/other.xml\nSitemap: https://evil.test/s.xml\nSitemap: ftp://x.test/s.xml\n";
		tsosi_assert_same( array( 'https://x.test/wp-sitemap.xml', 'https://www.x.test/other.xml' ), tsosi_usage_robots_sitemaps( $robots ) );
		tsosi_assert_same( array(), tsosi_usage_robots_sitemaps( "User-agent: *\n" ) );
	}
);

tsosi_test(
	'sitemap_index.xml is never requested when another sitemap answered (no 404 for Yoast-less sites)',
	function () {
		tsosi_test_site_pages(
			array(
				'/robots.txt'  => "User-agent: *\n",
				'/sitemap.xml' => '<urlset>google-sitemap-generator</urlset>',
				'/feed/'       => '<rss/>',
			)
		);
		$out = tsosi_usage_endpoints_read();
		tsosi_assert_same( array( '/robots.txt', '/sitemap.xml', '/feed/' ), array_keys( $out ) );
		tsosi_assert_same( array(), array_values( array_filter( $GLOBALS['tsosi_test']['requested'], function ( $u ) {
			return false !== strpos( $u, 'sitemap_index' );
		} ) ), 'sitemap_index.xml not requested' );
	}
);

tsosi_test(
	'declared sitemaps are read; sitemap.xml is added; sitemap_index.xml stays unrequested',
	function () {
		tsosi_test_site_pages(
			array(
				'/robots.txt'      => "Sitemap: https://x.test/wp-sitemap.xml\n",
				'/wp-sitemap.xml'  => '<sitemapindex>core</sitemapindex>',
			)
		);
		$out = tsosi_usage_endpoints_read();
		tsosi_assert_true( isset( $out['/wp-sitemap.xml'] ), 'declared sitemap read' );
		tsosi_assert_same( array(), array_values( array_filter( $GLOBALS['tsosi_test']['requested'], function ( $u ) {
			return false !== strpos( $u, 'sitemap_index' );
		} ) ) );
	}
);

tsosi_test(
	'sitemap_index.xml is the last resort, only when nothing else answered',
	function () {
		tsosi_test_site_pages(
			array(
				'/robots.txt'        => "User-agent: *\n",
				'/sitemap_index.xml' => '<sitemapindex>yoast</sitemapindex>',
			)
		);
		$out = tsosi_usage_endpoints_read();
		tsosi_assert_true( isset( $out['/sitemap_index.xml'] ), 'found through the fallback' );
	}
);
