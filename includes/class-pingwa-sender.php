<?php
/**
 * Posting to the hook URL without ever hurting the site.
 *
 * Triggers queue bodies during the request; at the very end of it (the last `shutdown`
 * callback) the queue is posted: the response is handed to the visitor first where the
 * server allows it (PHP-FPM, LiteSpeed), each post is non-blocking with a 2 second
 * timeout (elsewhere, e.g. mod_php, the visitor can wait up to that per post), and every
 * error is caught. Errors are logged with error_log() only while
 * WP_DEBUG is on, and never with the hook URL in them.
 *
 * @package pingwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue and delivery.
 */
final class Pingwa_Sender {

	/**
	 * Order meta key marking an order as delivered (one message per order).
	 */
	const ORDER_META = '_pingwa_notified';

	/**
	 * Seconds a post from a site request may take at most.
	 */
	const TIMEOUT = 2;

	/**
	 * Seconds the settings page's test waits for the server's answer.
	 */
	const TEST_TIMEOUT = 10;

	/**
	 * Bodies queued in this request.
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * Order ids queued in this request (id => true).
	 *
	 * @var array
	 */
	private static $orders = array();

	/**
	 * Order ids never sent in this request (restored from the trash), id => true.
	 *
	 * @var array
	 */
	private static $skipped = array();

	/**
	 * Order messages one request sends at most (filter `pingwa_max_order_messages`); the
	 * rest go into one "…and N more new orders" message.
	 */
	const MAX_ORDER_MESSAGES = 5;

	/**
	 * Whether the shutdown callback is hooked.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Run $fn, catching anything it throws.
	 *
	 * @param callable $fn Callback.
	 */
	public static function guard( $fn ) {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			self::log( 'trigger failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Queue a body for the end of the request.
	 *
	 * @param array $payload Body.
	 */
	public static function queue( array $payload ) {
		if ( '' === Pingwa_Settings::hook_url() ) {
			return;
		}
		self::$queue[] = $payload;
		self::hook();
	}

	/**
	 * Queue an order for the end of the request.
	 *
	 * @param int $order_id Order id.
	 */
	public static function queue_order( $order_id ) {
		if ( '' === Pingwa_Settings::hook_url() ) {
			return;
		}
		self::$orders[ (int) $order_id ] = true;
		self::hook();
	}

	/**
	 * Never send this order in this request (it was restored from the trash).
	 *
	 * @param int $order_id Order id.
	 */
	public static function skip_order( $order_id ) {
		self::$skipped[ (int) $order_id ] = true;
	}

	/**
	 * Hook flush() as the last shutdown callback.
	 */
	private static function hook() {
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), PHP_INT_MAX );
		}
	}

	/**
	 * Post everything queued. Runs at shutdown.
	 */
	public static function flush() {
		self::guard(
			function () {
				$bodies        = self::$queue;
				$orders        = array_diff_key( self::$orders, self::$skipped );
				self::$queue   = array();
				self::$orders  = array();
				self::$skipped = array();
				$max_age       = (int) apply_filters( 'pingwa_order_max_age', DAY_IN_SECONDS );
				$order_bodies  = array();
				foreach ( array_keys( $orders ) as $order_id ) {
					self::guard(
						function () use ( $order_id, $max_age, &$order_bodies ) {
							$body = self::claim_order( $order_id, $max_age );
							if ( null !== $body ) {
								$order_bodies[] = $body;
							}
						}
					);
				}
				if ( $order_bodies ) {
					$max    = max( 0, (int) apply_filters( 'pingwa_max_order_messages', self::MAX_ORDER_MESSAGES ) );
					$bodies = array_merge( $bodies, self::cap_orders( $order_bodies, $max ) );
				}
				if ( ! $bodies ) {
					return;
				}
				self::finish_response();
				foreach ( $bodies as $body ) {
					self::guard(
						function () use ( $body ) {
							self::post( $body, false );
						}
					);
				}
			}
		);
	}

	/**
	 * At most $max order bodies; the rest become one "…and N more new orders" body.
	 *
	 * @param array $bodies Order bodies.
	 * @param int   $max    Most sent one by one.
	 * @return array
	 */
	private static function cap_orders( array $bodies, $max ) {
		if ( count( $bodies ) <= $max ) {
			return $bodies;
		}
		$more   = count( $bodies ) - $max;
		$bodies = array_slice( $bodies, 0, $max );
		self::guard(
			function () use ( $more, &$bodies ) {
				$bodies[] = Pingwa_Payload::more_orders( $more, Pingwa_Triggers::site(), Pingwa_Triggers::orders_admin_url() );
			}
		);
		return $bodies;
	}

	/**
	 * The body of a new order, once: a shop_order in processing, on-hold or completed that
	 * was never sent. Marks the order when its body is queued, so later requests (status
	 * changes, edits, a second checkout hook) send nothing. Null when there is nothing to
	 * send (a pending or failed order stays unmarked and counts once it is paid).
	 *
	 * Orders created more than $max_age seconds ago (imports, migrations) are never sent.
	 *
	 * @param int $order_id Order id.
	 * @param int $max_age  Seconds.
	 * @return array|null
	 */
	private static function claim_order( $order_id, $max_age ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		// Only a real order: not a subscription, a refund or any other order type.
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_type' ) || 'shop_order' !== $order->get_type() ) {
			return null;
		}
		if ( ! Pingwa_Triggers::is_announced_status( $order->get_status() ) || $order->get_meta( self::ORDER_META ) ) {
			return null;
		}
		$created = $order->get_date_created();
		if ( is_object( $created ) && method_exists( $created, 'getTimestamp' ) && $created->getTimestamp() < time() - $max_age ) {
			return null;
		}
		$order->update_meta_data( self::ORDER_META, gmdate( 'c' ) );
		$order->save_meta_data();
		return Pingwa_Payload::order( Pingwa_Triggers::order_facts( $order ), Pingwa_Triggers::site() );
	}

	/**
	 * Hand the response to the visitor before posting, where PHP allows it.
	 */
	private static function finish_response() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Post a body to the saved hook URL.
	 *
	 * @param array $payload  Body.
	 * @param bool  $blocking Wait for the answer (the settings page's test) or not.
	 * @return array|WP_Error|null The response, an error, or null without a hook URL.
	 */
	public static function post( array $payload, $blocking ) {
		$url = Pingwa_Settings::hook_url();
		if ( '' === $url ) {
			return null;
		}
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => $blocking ? self::TEST_TIMEOUT : self::TIMEOUT,
				'blocking'    => (bool) $blocking,
				'redirection' => 0,
				'user-agent'  => 'pingwa-wp/' . PINGWA_VERSION,
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			self::log( 'post failed: ' . str_replace( $url, Pingwa_Hook_Url::mask( $url ), $response->get_error_message() ) );
		}
		return $response;
	}

	/**
	 * Log a line while WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 */
	public static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'pingwa: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- only while WP_DEBUG is on, as documented.
		}
	}
}
