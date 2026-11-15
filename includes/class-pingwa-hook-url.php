<?php
/**
 * The pingwa hook URL: validation and the masked form shown in the admin.
 *
 * A hook URL is `<server base URL>/h/hk_<32 characters of A-Z a-z 0-9 _ ->`, the format
 * the pingwa server mints (`POST /v1/hooks`). The URL itself is the credential, so it is
 * never printed in full after it is saved.
 *
 * @package pingwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook URL rules.
 */
final class Pingwa_Hook_Url {

	/**
	 * Longest URL accepted.
	 */
	const MAX_LENGTH = 2048;

	/**
	 * Path of a hook URL: optional base path segments, then /h/hk_ and 32 token characters.
	 */
	const PATH_PATTERN = '#^(?:/[A-Za-z0-9_~-][A-Za-z0-9._~-]*)*/h/hk_[A-Za-z0-9_-]{32}$#';

	/**
	 * Whether $url is an https hook URL in the server's format.
	 *
	 * @param mixed $url Candidate URL.
	 * @return bool
	 */
	public static function is_valid( $url ) {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH || preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( ! isset( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		if ( ! preg_match( '/^[A-Za-z0-9.-]+$/', $parts['host'] ) ) {
			return false;
		}
		return isset( $parts['path'] ) && 1 === preg_match( self::PATH_PATTERN, $parts['path'] );
	}

	/**
	 * The trimmed URL when it is valid, else an empty string.
	 *
	 * @param mixed $url Candidate URL.
	 * @return string
	 */
	public static function sanitize( $url ) {
		return self::is_valid( $url ) ? trim( $url ) : '';
	}

	/**
	 * The URL with its token hidden but for the last four characters, like the pingwa
	 * panel's hint (`…/h/hk_…abcd`). Empty for an invalid URL.
	 *
	 * @param mixed $url Saved URL.
	 * @return string
	 */
	public static function mask( $url ) {
		if ( ! self::is_valid( $url ) ) {
			return '';
		}
		$url = trim( $url );
		$pos = strrpos( $url, '/h/hk_' );
		return substr( $url, 0, $pos ) . '/h/hk_…' . substr( $url, -4 );
	}
}
