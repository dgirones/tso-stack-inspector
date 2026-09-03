<?php
/**
 * Multisite: network admin links to per-site inspector screens.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register network admin menu when multisite is enabled.
 *
 * @return void
 */
function tsosi_multisite_register_menu() {
	if ( ! is_multisite() || ! is_network_admin() ) {
		return;
	}
	add_submenu_page(
		'settings.php',
		__( 'TSO Stack Inspector', 'tso-stack-inspector' ),
		__( 'TSO Stack Inspector', 'tso-stack-inspector' ),
		'manage_network_options',
		'tso-stack-inspector-network',
		'tsosi_multisite_render_page'
	);
}
add_action( 'network_admin_menu', 'tsosi_multisite_register_menu' );

/**
 * @return void
 */
function tsosi_multisite_render_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return;
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html( tsosi_ui_triple_text(
		'TSO Stack Inspector — Multisite',
		'TSO Stack Inspector — Multisitio',
		'TSO Stack Inspector — Multilloc'
	) ) . '</h1>';
	echo '<p>' . esc_html( tsosi_ui_triple_text(
		'Scans run on each site separately. Open the inspector on the site you want to check.',
		'Los escaneos se ejecutan en cada sitio por separado. Abre el inspector en el sitio que quieras revisar.',
		'L\'escaneig s\'executa a cada lloc per separat. Obre l\'inspector al lloc que vulguis revisar.'
	) ) . '</p>';

	$sites = get_sites(
		array(
			'number'  => 200,
			'orderby' => 'domain',
		)
	);

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Site', 'Sitio', 'Lloc' ) ) . '</th>';
	echo '<th>' . esc_html( tsosi_ui_triple_text( 'Inspector', 'Inspector', 'Inspector' ) ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $sites as $site ) {
		if ( ! $site instanceof WP_Site ) {
			continue;
		}
		$url = get_admin_url( (int) $site->blog_id, 'tools.php?page=tso-stack-inspector' );
		echo '<tr>';
		$details = get_blog_details( (int) $site->blog_id );
		$name    = ( $details && ! empty( $details->blogname ) ) ? (string) $details->blogname : (string) $site->domain;
		echo '<td>' . esc_html( $name ) . ' <code>' . esc_html( (string) $site->domain . $site->path ) . '</code></td>';
		echo '<td><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html( tsosi_ui_triple_text( 'Open', 'Abrir', 'Obrir' ) ) . '</a></td>';
		echo '</tr>';
	}

	echo '</tbody></table></div>';
}
