<?php
/**
 * UI language helpers for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supported admin UI languages.
 *
 * @return string[]
 */
function tsosi_get_supported_ui_langs() {
	return array( 'en', 'es', 'ca' );
}

/**
 * Current admin UI language for the logged-in user.
 *
 * @return string en|es|ca
 */
function tsosi_get_ui_lang() {
	$uid  = get_current_user_id();
	$lang = $uid ? get_user_meta( $uid, TSOSI_USER_META_UI_LANG, true ) : '';
	$lang = sanitize_key( (string) $lang );
	if ( in_array( $lang, tsosi_get_supported_ui_langs(), true ) ) {
		return $lang;
	}
	return 'en';
}

/**
 * Persist UI language preference.
 *
 * @param string $lang en|es|ca
 * @return void
 */
function tsosi_set_ui_lang( $lang ) {
	$lang = sanitize_key( (string) $lang );
	if ( ! in_array( $lang, tsosi_get_supported_ui_langs(), true ) ) {
		return;
	}
	$uid = get_current_user_id();
	if ( $uid ) {
		update_user_meta( $uid, TSOSI_USER_META_UI_LANG, $lang );
	}
}

/**
 * Load plugin textdomain from shipped MO files.
 *
 * @return void
 */
function tsosi_load_textdomain() {
	$domain = 'tso-stack-inspector';
	$locale = determine_locale();
	$mofile = TSOSI_PATH . 'languages/' . $domain . '-' . $locale . '.mo';
	if ( is_readable( $mofile ) ) {
		load_textdomain( $domain, $mofile );
	}
}

/**
 * Return one of three hard-coded UI strings when MO is missing.
 *
 * @param string $en English.
 * @param string $es Spanish.
 * @param string $ca Catalan.
 * @return string
 */
function tsosi_ui_triple_text( $en, $es, $ca ) {
	$lang = tsosi_get_ui_lang();
	if ( 'es' === $lang ) {
		return $es;
	}
	if ( 'ca' === $lang ) {
		return $ca;
	}
	return $en;
}
