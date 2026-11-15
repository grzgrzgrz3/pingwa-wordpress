<?php
/**
 * The triggers: a form submission in Contact Form 7, WPForms, Elementor Pro or Gravity
 * Forms, and a new WooCommerce order. Each has its own toggle, on by default while its
 * plugin is active.
 *
 * Every callback only queues a body; Pingwa_Sender posts at the end of the request, so a
 * checkout or a form submit never waits for, or fails on, pingwa.
 *
 * @package pingwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger registration and the callbacks.
 */
final class Pingwa_Triggers {

	/**
	 * Option holding the toggles: trigger key => "1" or "0". A missing key is the default.
	 */
	const OPTION = 'pingwa_triggers';

	/**
	 * Statuses in which an order is announced: the ones WooCommerce's own admin "New
	 * order" e-mail is sent for.
	 */
	const ANNOUNCED_STATUSES = array( 'processing', 'on-hold', 'completed' );

	/**
	 * Statuses an order leaves when it becomes a new order (unpaid, declined, or a block
	 * checkout or wp-admin draft).
	 */
	const UNPAID_STATUSES = array( 'pending', 'failed', 'checkout-draft', 'auto-draft', 'draft' );

	/**
	 * Trigger keys and their names.
	 *
	 * @return array
	 */
	public static function names() {
		return array(
			'cf7'          => __( 'Contact Form 7', 'pingwa' ),
			'wpforms'      => __( 'WPForms', 'pingwa' ),
			'elementor'    => __( 'Elementor Pro forms', 'pingwa' ),
			'gravityforms' => __( 'Gravity Forms', 'pingwa' ),
			'woocommerce'  => __( 'WooCommerce new order', 'pingwa' ),
		);
	}

	/**
	 * Whether the plugin behind a trigger is active.
	 *
	 * @param string $key Trigger key.
	 * @return bool
	 */
	public static function is_active( $key ) {
		switch ( $key ) {
			case 'cf7':
				return defined( 'WPCF7_VERSION' );
			case 'wpforms':
				return function_exists( 'wpforms' );
			case 'elementor':
				return defined( 'ELEMENTOR_PRO_VERSION' );
			case 'gravityforms':
				return class_exists( 'GFForms' );
			case 'woocommerce':
				return class_exists( 'WooCommerce' );
		}
		return false;
	}

	/**
	 * Whether a trigger is on: never while its plugin is inactive; else the saved toggle,
	 * on when nothing is saved.
	 *
	 * @param mixed  $saved  Saved toggles.
	 * @param string $key    Trigger key.
	 * @param bool   $active Whether its plugin is active.
	 * @return bool
	 */
	public static function resolve( $saved, $key, $active ) {
		if ( ! $active ) {
			return false;
		}
		if ( is_array( $saved ) && isset( $saved[ $key ] ) ) {
			return '1' === (string) $saved[ $key ];
		}
		return true;
	}

	/**
	 * Whether a trigger is on now.
	 *
	 * @param string $key Trigger key.
	 * @return bool
	 */
	public static function is_enabled( $key ) {
		return self::resolve( get_option( self::OPTION, array() ), $key, self::is_active( $key ) );
	}

	/**
	 * Hook the callbacks. Each checks its toggle when it runs.
	 */
	public static function register() {
		add_action( 'wpcf7_submit', array( __CLASS__, 'on_cf7' ), 10, 2 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'on_wpforms' ), 10, 3 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_elementor' ), 10, 1 );
		add_action( 'gform_after_submission', array( __CLASS__, 'on_gravity' ), 10, 2 );
		// An order counts when it first reaches processing, on-hold or completed, like
		// WooCommerce's admin "New order" e-mail: created in one of them (classic or block
		// checkout, REST API, code), or moved into one from pending, failed or a draft
		// (a paid retry, a gateway's later confirmation, an order made in wp-admin).
		// Pingwa_Sender checks the status and keeps a mark on the order: one message each.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 10, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * Whether a CF7 result is a submission that went through (sent, or validated but the
	 * site's mail failed: the lead is real either way). Spam, invalid and aborted are not.
	 *
	 * @param mixed $result wpcf7_submit result.
	 * @return bool
	 */
	public static function is_cf7_delivered( $result ) {
		return is_array( $result ) && isset( $result['status'] )
			&& in_array( $result['status'], array( 'mail_sent', 'mail_failed' ), true );
	}

	/**
	 * Contact Form 7: wpcf7_submit.
	 *
	 * @param object $contact_form WPCF7_ContactForm.
	 * @param array  $result       Submission result.
	 */
	public static function on_cf7( $contact_form, $result = array() ) {
		Pingwa_Sender::guard(
			function () use ( $contact_form, $result ) {
				if ( ! self::is_enabled( 'cf7' ) || ! self::is_cf7_delivered( $result ) || ! class_exists( 'WPCF7_Submission' ) ) {
					return;
				}
				$submission = WPCF7_Submission::get_instance();
				if ( ! $submission || ! is_object( $contact_form ) ) {
					return;
				}
				$fields = Pingwa_Fields::from_cf7( $contact_form->scan_form_tags(), $submission->get_posted_data() );
				Pingwa_Sender::queue( Pingwa_Payload::form( $contact_form->title(), $fields, 'contact-form-7', self::site() ) );
			}
		);
	}

	/**
	 * WPForms: wpforms_process_complete.
	 *
	 * @param array $fields    Fields.
	 * @param array $entry     Entry.
	 * @param array $form_data Form data.
	 */
	public static function on_wpforms( $fields, $entry = array(), $form_data = array() ) {
		Pingwa_Sender::guard(
			function () use ( $fields, $form_data ) {
				if ( ! self::is_enabled( 'wpforms' ) ) {
					return;
				}
				$title = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : '';
				Pingwa_Sender::queue( Pingwa_Payload::form( $title, Pingwa_Fields::from_wpforms( $fields ), 'wpforms', self::site() ) );
			}
		);
	}

	/**
	 * Elementor Pro: elementor_pro/forms/new_record.
	 *
	 * @param object $record Form_Record.
	 */
	public static function on_elementor( $record ) {
		Pingwa_Sender::guard(
			function () use ( $record ) {
				if ( ! self::is_enabled( 'elementor' ) || ! is_object( $record ) ) {
					return;
				}
				$title = $record->get_form_settings( 'form_name' );
				Pingwa_Sender::queue( Pingwa_Payload::form( $title, Pingwa_Fields::from_elementor( $record->get( 'fields' ) ), 'elementor', self::site() ) );
			}
		);
	}

	/**
	 * Gravity Forms: gform_after_submission. Entries marked spam are left out.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public static function on_gravity( $entry, $form ) {
		Pingwa_Sender::guard(
			function () use ( $entry, $form ) {
				if ( ! self::is_enabled( 'gravityforms' ) || ! is_array( $entry ) || ! is_array( $form ) ) {
					return;
				}
				if ( isset( $entry['status'] ) && 'spam' === $entry['status'] ) {
					return;
				}
				$title = isset( $form['title'] ) ? $form['title'] : '';
				Pingwa_Sender::queue( Pingwa_Payload::form( $title, Pingwa_Fields::from_gravity( $form, $entry ), 'gravityforms', self::site() ) );
			}
		);
	}

	/**
	 * WooCommerce: an order was created or checked out. Only queues the id; the order is
	 * read at the end of the request, when its items and totals are saved.
	 *
	 * @param int|object $order Order id or WC_Order.
	 */
	public static function on_order( $order ) {
		Pingwa_Sender::guard(
			function () use ( $order ) {
				if ( ! self::is_enabled( 'woocommerce' ) ) {
					return;
				}
				$id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : absint( $order );
				if ( $id > 0 ) {
					Pingwa_Sender::queue_order( $id );
				}
			}
		);
	}

	/**
	 * WooCommerce: woocommerce_order_status_changed. Only the first move into a paid
	 * status queues the order; any other change sends nothing.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function on_status_changed( $order_id, $from, $to ) {
		if ( self::is_first_paid_transition( $from, $to ) ) {
			self::on_order( $order_id );
		}
	}

	/**
	 * Whether a status change makes an order a new order: from pending, failed or a draft
	 * into processing, on-hold or completed.
	 *
	 * @param string $from Old status, without the wc- prefix.
	 * @param string $to   New status, without the wc- prefix.
	 * @return bool
	 */
	public static function is_first_paid_transition( $from, $to ) {
		return in_array( (string) $from, self::UNPAID_STATUSES, true ) && self::is_announced_status( $to );
	}

	/**
	 * Whether an order in this status is announced.
	 *
	 * @param string $status Status without the wc- prefix.
	 * @return bool
	 */
	public static function is_announced_status( $status ) {
		return in_array( (string) $status, self::ANNOUNCED_STATUSES, true );
	}

	/**
	 * The facts of an order the body may carry. Reads no e-mail, phone or address.
	 *
	 * @param object $order WC_Order.
	 * @return array
	 */
	public static function order_facts( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => self::decimal( $item->get_total() ),
			);
		}
		return array(
			'number'               => (string) $order->get_order_number(),
			'total'                => self::decimal( $order->get_total() ),
			'currency'             => (string) $order->get_currency(),
			'status'               => (string) $order->get_status(),
			'first_name'           => (string) $order->get_billing_first_name(),
			'last_name'            => (string) $order->get_billing_last_name(),
			'payment_method_title' => (string) $order->get_payment_method_title(),
			'edit_url'             => (string) $order->get_edit_order_url(),
			'items'                => $items,
		);
	}

	/**
	 * A money amount as WooCommerce's REST API writes it ("29.35").
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	private static function decimal( $amount ) {
		if ( is_numeric( $amount ) && function_exists( 'wc_format_decimal' ) && function_exists( 'wc_get_price_decimals' ) ) {
			return (string) wc_format_decimal( $amount, wc_get_price_decimals() );
		}
		return (string) $amount;
	}

	/**
	 * The site name.
	 *
	 * @return string
	 */
	public static function site() {
		return get_bloginfo( 'name' );
	}
}
