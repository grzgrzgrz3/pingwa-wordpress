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

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub of __(): no translation.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n
		return $text;
	}
}

define( 'PINGWA_VERSION', '0.0.0-test' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * What the WordPress stubs below saw and answer with; tests reset it with pingwa_test_reset().
 */
function pingwa_test_reset() {
	$GLOBALS['pingwa_wp'] = array(
		'options'   => array(),
		'filters'   => array(),
		'actions'   => array(),
		'orders'    => array(),
		'posts'     => array(),
		'multisite' => false,
		'sites'     => array(),
		'blog'      => 1,
		'deleted'   => array(),
	);
}
pingwa_test_reset();

/**
 * Stub of esc_html().
 *
 * @param string $text Text.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * WordPress's wp_pre_kses_less_than(), as in wp-includes/formatting.php.
 *
 * @param string $content Text.
 * @return string
 */
function wp_pre_kses_less_than( $content ) {
	return preg_replace_callback( '%<[^>]*?((?=<)|>|$)%', 'wp_pre_kses_less_than_callback', $content );
}

/**
 * WordPress's wp_pre_kses_less_than_callback().
 *
 * @param array $matches Match.
 * @return string
 */
function wp_pre_kses_less_than_callback( $matches ) {
	if ( false === strpos( $matches[0], '>' ) ) {
		return esc_html( $matches[0] );
	}
	return $matches[0];
}

/**
 * Stub of absint().
 *
 * @param mixed $n Number.
 * @return int
 */
function absint( $n ) {
	return abs( (int) $n );
}

/**
 * Stub of get_option().
 *
 * @param string $name    Option.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['pingwa_wp']['options'] ) ? $GLOBALS['pingwa_wp']['options'][ $name ] : $default;
}

/**
 * Stub of delete_option(): records the blog and the option.
 *
 * @param string $name Option.
 * @return bool
 */
function delete_option( $name ) {
	$GLOBALS['pingwa_wp']['deleted'][] = array( $GLOBALS['pingwa_wp']['blog'], $name );
	return true;
}

/**
 * Stub of delete_site_option(): records "network" and the option.
 *
 * @param string $name Option.
 * @return bool
 */
function delete_site_option( $name ) {
	$GLOBALS['pingwa_wp']['deleted'][] = array( 'network', $name );
	return true;
}

/**
 * Stub of is_multisite().
 *
 * @return bool
 */
function is_multisite() {
	return $GLOBALS['pingwa_wp']['multisite'];
}

/**
 * Stub of get_sites(): the site ids (only `fields => ids` is supported).
 *
 * @param array $args Query.
 * @return array
 */
function get_sites( $args = array() ) {
	if ( ! isset( $args['fields'] ) || 'ids' !== $args['fields'] || ! isset( $args['number'] ) || 0 !== $args['number'] ) {
		throw new InvalidArgumentException( 'get_sites stub: fields=ids, number=0 expected' );
	}
	return $GLOBALS['pingwa_wp']['sites'];
}

/**
 * Stub of switch_to_blog().
 *
 * @param int $id Site id.
 */
function switch_to_blog( $id ) {
	$GLOBALS['pingwa_wp']['stack'][] = $GLOBALS['pingwa_wp']['blog'];
	$GLOBALS['pingwa_wp']['blog']    = (int) $id;
}

/**
 * Stub of restore_current_blog().
 */
function restore_current_blog() {
	$GLOBALS['pingwa_wp']['blog'] = array_pop( $GLOBALS['pingwa_wp']['stack'] );
}

/**
 * Stub of add_action().
 *
 * @param string   $tag      Hook.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 */
function add_action( $tag, $callback, $priority = 10 ) {
	$GLOBALS['pingwa_wp']['actions'][] = array( $tag, $callback, $priority );
}

/**
 * Stub of apply_filters(): a callback set in $GLOBALS['pingwa_wp']['filters'][ $tag ], or
 * the value.
 *
 * @param string $tag   Hook.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	if ( isset( $GLOBALS['pingwa_wp']['filters'][ $tag ] ) ) {
		return call_user_func_array( $GLOBALS['pingwa_wp']['filters'][ $tag ], array_slice( $args, 1 ) );
	}
	return $value;
}

/**
 * Stub of wc_get_order().
 *
 * @param int $id Order id.
 * @return object|false
 */
function wc_get_order( $id ) {
	return isset( $GLOBALS['pingwa_wp']['orders'][ $id ] ) ? $GLOBALS['pingwa_wp']['orders'][ $id ] : false;
}

/**
 * Stub of wp_safe_remote_post(): records the post.
 *
 * @param string $url  URL.
 * @param array  $args Arguments.
 * @return array
 */
function wp_safe_remote_post( $url, $args ) {
	$GLOBALS['pingwa_wp']['posts'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	return array( 'response' => array( 'code' => 200 ) );
}

/**
 * Stub of is_wp_error().
 *
 * @param mixed $thing Thing.
 * @return bool
 */
function is_wp_error( $thing ) {
	return is_object( $thing ) && is_a( $thing, 'WP_Error' );
}

/**
 * Stub of admin_url().
 *
 * @param string $path Path.
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://shop.example/wp-admin/' . ltrim( $path, '/' );
}

/**
 * Stub of get_bloginfo().
 *
 * @param string $what Field.
 * @return string
 */
function get_bloginfo( $what = '' ) {
	return 'Shop';
}

require dirname( __DIR__, 2 ) . '/includes/class-pingwa-hook-url.php';
require dirname( __DIR__, 2 ) . '/includes/class-pingwa-payload.php';
require dirname( __DIR__, 2 ) . '/includes/class-pingwa-fields.php';
require dirname( __DIR__, 2 ) . '/includes/class-pingwa-triggers.php';
require dirname( __DIR__, 2 ) . '/includes/class-pingwa-settings.php';
require dirname( __DIR__, 2 ) . '/includes/class-pingwa-sender.php';
