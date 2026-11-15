<?php
/**
 * Unit test bootstrap: the plugin's pure classes, with the few WordPress functions they
 * call stubbed to their documented behaviour. No WordPress install is needed.
 *
 * @package pingwa
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stub of wp_parse_url(): parse_url() with the component argument.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub of wp_strip_all_tags() (script/style contents removed, then tags).
	 *
	 * @param string $text          Text.
	 * @param bool   $remove_breaks Collapse whitespace.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( $text );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub of wp_json_encode().
	 *
	 * @param mixed $data  Data.
	 * @param int   $flags Flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

require dirname( __DIR__, 2 ) . '/includes/class-pingwa-hook-url.php';
require dirname( __DIR__, 2 ) . '/includes/class-pingwa-payload.php';
require dirname( __DIR__, 2 ) . '/includes/class-pingwa-fields.php';
