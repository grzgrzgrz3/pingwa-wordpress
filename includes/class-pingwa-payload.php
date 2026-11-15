<?php
/**
 * The JSON bodies the plugin posts to the hook URL.
 *
 * One body serves every kind of hook on the server:
 * - a hook without a template sends the body's `text` field as the message;
 * - a hook with the `woocommerce_order` preset reads number, total, currency, status,
 *   billing.first_name / last_name, line_items[n].quantity / name and payment_method_title;
 * - a hook with the `form_generic` preset reads data.formName and data.fields[n].label /
 *   value.
 * Orders never carry the customer's e-mail, phone or address.
 *
 * @package pingwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payload builders. Pure functions over plain arrays.
 */
final class Pingwa_Payload {

	/**
	 * Longest field value, item name or site name kept (characters).
	 */
	const MAX_VALUE = 300;

	/**
	 * Longest field label or form title kept (characters).
	 */
	const MAX_LABEL = 80;

	/**
	 * Most form fields kept.
	 */
	const MAX_FIELDS = 25;

	/**
	 * Most order items kept.
	 */
	const MAX_ITEMS = 20;

	/**
	 * Longest `text` (the server cuts a message at 1,024 characters).
	 */
	const MAX_TEXT = 1000;

	/**
	 * Field types never sent: secrets, uploads, hidden values, spam traps, layout.
	 * The type names of Contact Form 7, WPForms, Elementor Pro and Gravity Forms. Uploads:
	 * CF7 `file`, WPForms `file-upload` and `camera`, Elementor Pro `upload`, Gravity Forms
	 * `fileupload` and `post_image`; any other type with "upload", or a word starting with
	 * "file", in its name is skipped too (is_skipped()).
	 */
	const SKIPPED_TYPES = array(
		'password',
		'file',
		'fileupload',
		'file-upload',
		'upload',
		'post_image',
		'camera',
		'hidden',
		'honeypot',
		'captcha',
		'captchac',
		'captchar',
		'recaptcha',
		'recaptcha_v3',
		'hcaptcha',
		'turnstile',
		'quiz',
		'signature',
		'submit',
		'html',
		'divider',
		'pagebreak',
		'page',
		'section',
		'step',
		'content',
	);

	/**
	 * Largest JSON body posted (bytes; the server takes up to 64 KB).
	 */
	const MAX_BODY = 60000;

	/**
	 * Field types whose value is HTML (rich text editors): their tags are stripped. Any
	 * other value is plain text as the visitor typed it, so `<50k` or `<3` stays.
	 */
	const HTML_TYPES = array( 'richtext', 'post_content', 'post_excerpt' );

	/**
	 * The body for a new WooCommerce order.
	 *
	 * @param array  $order Order facts: number, total, currency, status, first_name,
	 *                      last_name, payment_method_title, edit_url, items (name,
	 *                      quantity, total). Other keys are ignored.
	 * @param string $site  Site name.
	 * @return array
	 */
	public static function order( array $order, $site ) {
		$site  = self::site( $site );
		$items = array();
		$parts = array();
		$list  = isset( $order['items'] ) && is_array( $order['items'] ) ? $order['items'] : array();
		foreach ( array_slice( $list, 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name     = self::clip( self::scalar( isset( $item['name'] ) ? $item['name'] : '' ), self::MAX_VALUE );
			$quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
			$items[]  = array(
				'name'     => $name,
				'quantity' => $quantity,
				'total'    => self::clip( self::scalar( isset( $item['total'] ) ? $item['total'] : '' ), 40 ),
			);
			$parts[]  = $quantity . ' × ' . $name;
		}

		$get = function ( $key, $max = self::MAX_VALUE ) use ( $order ) {
			return self::clip( self::scalar( isset( $order[ $key ] ) ? $order[ $key ] : '' ), $max );
		};

		$body = array(
			'number'               => $get( 'number', 40 ),
			'total'                => $get( 'total', 40 ),
			'currency'             => $get( 'currency', 10 ),
			'status'               => $get( 'status', 40 ),
			'billing'              => array(
				'first_name' => $get( 'first_name', self::MAX_LABEL ),
				'last_name'  => $get( 'last_name', self::MAX_LABEL ),
			),
			'line_items'           => $items,
			'payment_method_title' => $get( 'payment_method_title', self::MAX_LABEL ),
			'edit_url'             => self::url( isset( $order['edit_url'] ) ? $order['edit_url'] : '' ),
			'site'                 => $site,
			'source'               => 'woocommerce',
		);

		$lines    = array();
		$lines[]  = sprintf( '🛒 New order #%s on %s: %s %s', $body['number'], $site, $body['total'], $body['currency'] );
		$lines[]  = 'Status: ' . $body['status'];
		$customer = trim( $body['billing']['first_name'] . ' ' . $body['billing']['last_name'] );
		if ( $parts ) {
			$lines[] = 'Items: ' . implode( ', ', $parts );
		}
		if ( '' !== $customer ) {
			$lines[] = 'Customer: ' . $customer;
		}
		if ( '' !== $body['payment_method_title'] ) {
			$lines[] = 'Payment: ' . $body['payment_method_title'];
		}
		if ( '' !== $body['edit_url'] ) {
			$lines[] = $body['edit_url'];
		}

		return array( 'text' => self::cut_text( implode( "\n", $lines ) ) ) + $body;
	}

	/**
	 * The body standing in for the orders of one request past the cap: "…and N more new
	 * orders" with the orders list in wp-admin. `number` carries the same words for a hook
	 * with the woocommerce_order preset.
	 *
	 * @param int    $count Orders not sent one by one.
	 * @param string $site  Site name.
	 * @param string $url   wp-admin orders list.
	 * @return array
	 */
	public static function more_orders( $count, $site, $url ) {
		$count = max( 1, (int) $count );
		$site  = self::site( $site );
		$url   = self::url( $url );
		$words = sprintf( '…and %d more new %s', $count, 1 === $count ? 'order' : 'orders' );
		$text  = $words . ' on ' . $site . ( '' === $url ? '' : "\n" . $url );
		return array(
			'text'        => self::cut_text( $text ),
			'number'      => sprintf( '…and %d more', $count ),
			'more_orders' => $count,
			'edit_url'    => $url,
			'site'        => $site,
			'source'      => 'woocommerce',
		);
	}

	/**
	 * The body for a form submission.
	 *
	 * @param string $title  Form title.
	 * @param array  $fields Fields: label, value (scalar or array), type, optional name.
	 * @param string $source Form plugin (contact-form-7, wpforms, elementor, gravityforms).
	 * @param string $site   Site name.
	 * @return array
	 */
	public static function form( $title, array $fields, $source, $site ) {
		$site  = self::site( $site );
		$title = self::clip( self::scalar( $title ), self::MAX_LABEL );
		if ( '' === $title ) {
			$title = 'Form';
		}
		$kept  = array();
		$lines = array( sprintf( '📝 %s on %s: new response', $title, $site ) );
		foreach ( $fields as $field ) {
			if ( count( $kept ) >= self::MAX_FIELDS ) {
				break;
			}
			if ( ! is_array( $field ) || self::is_skipped( $field ) ) {
				continue;
			}
			$value = self::clip( self::value( isset( $field['value'] ) ? $field['value'] : '', self::is_html( $field ) ), self::MAX_VALUE );
			if ( '' === $value ) {
				continue;
			}
			$label = self::clip( self::one_line( self::scalar( isset( $field['label'] ) ? $field['label'] : '' ) ), self::MAX_LABEL );
			if ( '' === $label ) {
				$label = 'Field';
			}
			$kept[]  = array(
				'label' => $label,
				'value' => $value,
			);
			$lines[] = $label . ': ' . $value;
		}

		return array(
			'text'   => self::cut_text( implode( "\n", $lines ) ),
			'data'   => array(
				'formName' => $title,
				'fields'   => $kept,
			),
			'site'   => $site,
			'source' => self::clip( self::scalar( $source ), 40 ),
		);
	}

	/**
	 * The body of the settings page's "Send test".
	 *
	 * @param string $site Site name.
	 * @return array
	 */
	public static function test( $site ) {
		$site = self::site( $site );
		return array(
			'text'   => sprintf( '✅ pingwa test from %s: WordPress can reach this hook URL.', $site ),
			'site'   => $site,
			'source' => 'test',
		);
	}

	/**
	 * A body as the JSON posted: UTF-8 and slashes unescaped, at most MAX_BODY bytes. A
	 * larger body has its longest values cut (ending in "…") until it fits.
	 *
	 * @param array $payload Body.
	 * @return string|false JSON, or false when it cannot be encoded.
	 */
	public static function encode( array $payload ) {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		$json  = wp_json_encode( $payload, $flags );
		for ( $i = 0; false !== $json && strlen( $json ) > self::MAX_BODY && $i < 200; $i++ ) {
			$payload = self::cut_longest( $payload, strlen( $json ) - self::MAX_BODY );
			if ( null === $payload ) {
				return false;
			}
			$json = wp_json_encode( $payload, $flags );
		}
		return false !== $json && strlen( $json ) <= self::MAX_BODY ? $json : false;
	}

	/**
	 * $data with its longest string (the first, on a tie) cut by about $excess bytes, or
	 * null when nothing is left to cut.
	 *
	 * @param array $data   Body.
	 * @param int   $excess Bytes over the limit.
	 * @return array|null
	 */
	private static function cut_longest( array $data, $excess ) {
		$max = 0;
		array_walk_recursive(
			$data,
			function ( $v ) use ( &$max ) {
				if ( is_string( $v ) && strlen( $v ) > $max ) {
					$max = strlen( $v );
				}
			}
		);
		if ( $max <= 8 ) {
			return null;
		}
		$done = false;
		$walk = function ( $node ) use ( &$walk, &$done, $max, $excess ) {
			if ( is_array( $node ) ) {
				foreach ( $node as $key => $value ) {
					$node[ $key ] = $walk( $value );
				}
				return $node;
			}
			if ( ! $done && is_string( $node ) && strlen( $node ) === $max ) {
				$done = true;
				return self::shorten( $node, $excess );
			}
			return $node;
		};
		return $walk( $data );
	}

	/**
	 * Text shorter by at least $excess characters (at most by half), ending in "…".
	 *
	 * @param string $text   Text.
	 * @param int    $excess Characters to drop.
	 * @return string
	 */
	private static function shorten( $text, $excess ) {
		if ( '…' === mb_substr( $text, -1, 1, 'UTF-8' ) ) {
			$text = mb_substr( $text, 0, -1, 'UTF-8' );
		}
		$chars = mb_strlen( $text, 'UTF-8' );
		$keep  = max( intdiv( $chars, 2 ), $chars - max( 1, (int) $excess ) );
		return rtrim( mb_substr( $text, 0, $keep, 'UTF-8' ) ) . '…';
	}

	/**
	 * Whether a field must not be sent (by type, or a spam trap by name or label).
	 *
	 * @param array $field Field.
	 * @return bool
	 */
	public static function is_skipped( array $field ) {
		$type = strtolower( rtrim( self::scalar( isset( $field['type'] ) ? $field['type'] : '' ), '*' ) );
		if ( in_array( $type, self::SKIPPED_TYPES, true ) || 1 === preg_match( '/(?:^|[^a-z])file|upload/', $type ) ) {
			return true;
		}
		$names = strtolower(
			self::scalar( isset( $field['name'] ) ? $field['name'] : '' ) . ' '
			. self::scalar( isset( $field['label'] ) ? $field['label'] : '' ) . ' ' . $type
		);
		return 1 === preg_match( '/honeypot|captcha|turnstile/', $names );
	}

	/**
	 * Whether a field's value is HTML.
	 *
	 * @param array $field Field.
	 * @return bool
	 */
	private static function is_html( array $field ) {
		$type = isset( $field['type'] ) && is_scalar( $field['type'] ) ? strtolower( (string) $field['type'] ) : '';
		return in_array( $type, self::HTML_TYPES, true );
	}

	/**
	 * A field value as text: arrays flattened (scalars joined with ", "), booleans yes/no.
	 *
	 * @param mixed $value Value.
	 * @param bool  $html  Whether the value is HTML (tags stripped) or plain text (kept).
	 * @return string
	 */
	private static function value( $value, $html ) {
		if ( is_array( $value ) ) {
			$flat = array();
			array_walk_recursive(
				$value,
				function ( $v ) use ( &$flat, $html ) {
					$t = self::value( $v, $html );
					if ( '' !== $t ) {
						$flat[] = $t;
					}
				}
			);
			return implode( ', ', $flat );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		return self::scalar( $value, $html );
	}

	/**
	 * A scalar as plain text: tags stripped (HTML only; a bare `<` or `>` is kept),
	 * entities decoded, control characters (but newlines) removed, CRLF made LF, trimmed.
	 * Anything else is "".
	 *
	 * @param mixed $value Value.
	 * @param bool  $html  Whether $value is HTML. Titles, labels, item and site names are;
	 *                     a form value only for a rich text field.
	 * @return string
	 */
	private static function scalar( $value, $html = true ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = str_replace( array( "\r\n", "\r", "\t" ), array( "\n", "\n", ' ' ), (string) $value );
		if ( $html ) {
			$text = wp_strip_all_tags( wp_pre_kses_less_than( $text ) );
		}
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text );
		if ( null === $text ) { // Invalid UTF-8: keep the bytes that form valid characters.
			$text = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/', '', (string) mb_convert_encoding( (string) $value, 'UTF-8', 'UTF-8' ) );
		}
		return trim( $text );
	}

	/**
	 * An http(s) URL as given, or "" (no decoding: `&` in a query stays as is).
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	private static function url( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || ! preg_match( '#^https?://[^\s\x00-\x1f\x7f]+$#i', $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Site name as plain text on one line.
	 *
	 * @param mixed $site Site name.
	 * @return string
	 */
	private static function site( $site ) {
		$site = self::clip( self::one_line( self::scalar( $site ) ), self::MAX_LABEL );
		return '' === $site ? 'WordPress' : $site;
	}

	/**
	 * Text on one line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Text cut to $max characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		return mb_strlen( $text, 'UTF-8' ) > $max ? rtrim( mb_substr( $text, 0, $max, 'UTF-8' ) ) : $text;
	}

	/**
	 * The message text cut to MAX_TEXT characters, ending in "…" when cut.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function cut_text( $text ) {
		if ( mb_strlen( $text, 'UTF-8' ) <= self::MAX_TEXT ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, self::MAX_TEXT - 1, 'UTF-8' ) ) . '…';
	}
}
