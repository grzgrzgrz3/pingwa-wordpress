<?php
/**
 * Trigger toggles (default on while the form or shop plugin is active) and the facts
 * read from a WooCommerce order.
 *
 * @package pingwa
 */

use PHPUnit\Framework\TestCase;

/**
 * A line item as WC_Order_Item_Product exposes it.
 */
final class Fake_WC_Item {
	private $n;
	private $q;
	private $t;
	public function __construct( $n, $q, $t ) {
		$this->n = $n;
		$this->q = $q;
		$this->t = $t;
	}
	public function get_name() {
		return $this->n;
	}
	public function get_quantity() {
		return $this->q;
	}
	public function get_total() {
		return $this->t;
	}
}

/**
 * The WC_Order getters the plugin calls, plus the contact getters it must not call.
 */
final class Fake_WC_Order {
	public $called = array();
	public function __call( $name, $args ) {
		$this->called[] = $name;
		throw new BadMethodCallException( $name );
	}
	public function get_order_number() {
		return '727';
	}
	public function get_total() {
		return '29.35';
	}
	public function get_currency() {
		return 'USD';
	}
	public function get_status() {
		return 'processing';
	}
	public function get_billing_first_name() {
		return 'John';
	}
	public function get_billing_last_name() {
		return 'Doe';
	}
	public function get_payment_method_title() {
		return 'Cash on delivery';
	}
	public function get_edit_order_url() {
		return 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=727';
	}
	public function get_items() {
		return array( 11 => new Fake_WC_Item( 'Mug', 2, '20.00' ) );
	}
}

final class TriggersTest extends TestCase {

	public function test_toggle_defaults_on_while_the_plugin_is_active() {
		$this->assertTrue( Pingwa_Triggers::resolve( array(), 'cf7', true ) );
		$this->assertFalse( Pingwa_Triggers::resolve( array(), 'cf7', false ) );
	}

	public function test_saved_toggle_wins_but_never_for_an_inactive_plugin() {
		$this->assertFalse( Pingwa_Triggers::resolve( array( 'cf7' => '0' ), 'cf7', true ) );
		$this->assertTrue( Pingwa_Triggers::resolve( array( 'cf7' => '1' ), 'cf7', true ) );
		$this->assertFalse( Pingwa_Triggers::resolve( array( 'cf7' => '1' ), 'cf7', false ) );
		$this->assertTrue( Pingwa_Triggers::resolve( 'garbage', 'cf7', true ) );
	}

	public function test_every_trigger_has_a_name() {
		$this->assertSame(
			array( 'cf7', 'wpforms', 'elementor', 'gravityforms', 'woocommerce' ),
			array_keys( Pingwa_Triggers::names() )
		);
	}

	public function test_order_facts_read_only_the_allowed_getters() {
		$order = new Fake_WC_Order();
		$facts = Pingwa_Triggers::order_facts( $order );
		$this->assertSame(
			array(
				'number'               => '727',
				'total'                => '29.35',
				'currency'             => 'USD',
				'status'               => 'processing',
				'first_name'           => 'John',
				'last_name'            => 'Doe',
				'payment_method_title' => 'Cash on delivery',
				'edit_url'             => 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=727',
				'items'                => array(
					array( 'name' => 'Mug', 'quantity' => 2, 'total' => '20.00' ),
				),
			),
			$facts
		);
		$this->assertSame( array(), $order->called, 'no other getter (e-mail, phone, address) is called' );
	}

	public function test_announced_statuses_are_the_paid_ones() {
		foreach ( array( 'processing', 'on-hold', 'completed' ) as $status ) {
			$this->assertTrue( Pingwa_Triggers::is_announced_status( $status ), $status );
		}
		foreach ( array( 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft', 'auto-draft', 'draft', 'trash', '' ) as $status ) {
			$this->assertFalse( Pingwa_Triggers::is_announced_status( $status ), $status );
		}
	}

	public function test_first_paid_transition_is_from_pending_failed_or_draft() {
		$this->assertTrue( Pingwa_Triggers::is_first_paid_transition( 'pending', 'processing' ) );
		$this->assertTrue( Pingwa_Triggers::is_first_paid_transition( 'failed', 'completed' ) );
		$this->assertTrue( Pingwa_Triggers::is_first_paid_transition( 'checkout-draft', 'on-hold' ) );
		$this->assertTrue( Pingwa_Triggers::is_first_paid_transition( 'auto-draft', 'processing' ) );
		$this->assertTrue( Pingwa_Triggers::is_first_paid_transition( 'draft', 'processing' ) );
		$this->assertTrue( Pingwa_Triggers::is_first_paid_transition( 'cancelled', 'processing' ) );
		$this->assertTrue( Pingwa_Triggers::is_first_paid_transition( 'cancelled', 'on-hold' ) );
		$this->assertTrue( Pingwa_Triggers::is_first_paid_transition( 'cancelled', 'completed' ) );
		$this->assertFalse( Pingwa_Triggers::is_first_paid_transition( 'refunded', 'processing' ) );
		$this->assertFalse( Pingwa_Triggers::is_first_paid_transition( 'on-hold', 'processing' ) );
		$this->assertFalse( Pingwa_Triggers::is_first_paid_transition( 'trash', 'processing' ) );
		$this->assertFalse( Pingwa_Triggers::is_first_paid_transition( 'pending', 'failed' ) );
		$this->assertFalse( Pingwa_Triggers::is_first_paid_transition( 'auto-draft', 'pending' ) );
	}

	public function test_cf7_results_that_count() {
		$this->assertTrue( Pingwa_Triggers::is_cf7_delivered( array( 'status' => 'mail_sent' ) ) );
		$this->assertTrue( Pingwa_Triggers::is_cf7_delivered( array( 'status' => 'mail_failed' ) ) );
		$this->assertFalse( Pingwa_Triggers::is_cf7_delivered( array( 'status' => 'spam' ) ) );
		$this->assertFalse( Pingwa_Triggers::is_cf7_delivered( array( 'status' => 'validation_failed' ) ) );
		$this->assertFalse( Pingwa_Triggers::is_cf7_delivered( array( 'status' => 'aborted' ) ) );
		$this->assertFalse( Pingwa_Triggers::is_cf7_delivered( null ) );
	}
}
