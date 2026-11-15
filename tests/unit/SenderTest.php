<?php
/**
 * Which orders get a message, and what is posted at the end of the request: the trigger
 * callbacks and Pingwa_Sender::flush() run over stub orders, with wp_safe_remote_post()
 * recorded by the bootstrap stubs.
 *
 * @package pingwa
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * Its presence means "WooCommerce is active".
	 */
	final class WooCommerce {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile
}

/**
 * A WC_Order as the plugin reads it.
 */
class WC_Order { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
	public $id;
	public $status;
	public $created;
	public $meta  = array();
	public $saves = 0;

	public function __construct( $id, $status, $created = null ) {
		$this->id      = $id;
		$this->status  = $status;
		$this->created = null === $created ? new DateTimeImmutable( '@' . time() ) : $created;
	}
	public function get_type() {
		return 'shop_order';
	}
	public function get_id() {
		return $this->id;
	}
	public function get_status() {
		return $this->status;
	}
	public function get_date_created() {
		return $this->created;
	}
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save_meta_data() {
		++$this->saves;
	}
	public function get_items() {
		return array();
	}
	public function get_order_number() {
		return (string) $this->id;
	}
	public function get_total() {
		return '10.00';
	}
	public function get_currency() {
		return 'EUR';
	}
	public function get_billing_first_name() {
		return 'Ada';
	}
	public function get_billing_last_name() {
		return 'Lovelace';
	}
	public function get_payment_method_title() {
		return 'Card';
	}
	public function get_edit_order_url() {
		return 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id;
	}
}

/**
 * WooCommerce Subscriptions' subscription: a WC_Order subclass of another type.
 */
class WC_Subscription extends WC_Order { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
	public function get_type() {
		return 'shop_subscription';
	}
}

/**
 * A refund: a WC_Order subclass in WooCommerce too.
 */
class WC_Order_Refund extends WC_Order { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
	public function get_type() {
		return 'shop_order_refund';
	}
}

final class SenderTest extends TestCase { // phpcs:ignore Generic.Files.OneObjectStructurePerFile

	const HOOK = 'https://pingwa.example/h/hk_PingwaWordPressUnitToken01234567';

	protected function setUp(): void {
		pingwa_test_reset();
		$GLOBALS['pingwa_wp']['options']['pingwa_hook_url'] = self::HOOK;
		foreach ( array( 'queue', 'orders', 'skipped' ) as $prop ) {
			if ( ! property_exists( 'Pingwa_Sender', $prop ) ) {
				continue;
			}
			$r = new ReflectionProperty( 'Pingwa_Sender', $prop );
			$r->setAccessible( true );
			$r->setValue( null, array() );
		}
		$r = new ReflectionProperty( 'Pingwa_Sender', 'hooked' );
		$r->setAccessible( true );
		$r->setValue( null, false );
	}

	private function order( $order ) {
		$GLOBALS['pingwa_wp']['orders'][ $order->id ] = $order;
		return $order;
	}

	/**
	 * The bodies posted by the end of the request.
	 *
	 * @return array
	 */
	private function flush() {
		Pingwa_Sender::flush();
		$out = array();
		foreach ( $GLOBALS['pingwa_wp']['posts'] as $post ) {
			$out[] = json_decode( $post['args']['body'], true );
		}
		$GLOBALS['pingwa_wp']['posts'] = array();
		return $out;
	}

	private function numbers( array $bodies ) {
		return array_map(
			function ( $b ) {
				return isset( $b['number'] ) ? $b['number'] : null;
			},
			$bodies
		);
	}

	public function test_only_real_orders_are_announced() {
		$this->order( new WC_Order( 1, 'processing' ) );
		$this->order( new WC_Subscription( 2, 'processing' ) );
		$this->order( new WC_Order_Refund( 3, 'completed' ) );
		foreach ( array( 1, 2, 3 ) as $id ) {
			Pingwa_Triggers::on_order( $id );
		}
		$this->assertSame( array( '1' ), $this->numbers( $this->flush() ) );
		$this->assertSame( '', $GLOBALS['pingwa_wp']['orders'][2]->get_meta( Pingwa_Sender::ORDER_META ), 'subscription not marked' );
	}
	public function test_an_order_counts_when_it_is_created_paid() {
		foreach ( array( 1 => 'processing', 2 => 'on-hold', 3 => 'completed' ) as $id => $status ) {
			$this->order( new WC_Order( $id, $status ) );
			Pingwa_Triggers::on_order( $id );
		}
		$this->assertSame( array( '1', '2', '3' ), $this->numbers( $this->flush() ) );
	}

	public function test_never_for_pending_failed_cancelled_draft_or_trash() {
		$id = 10;
		foreach ( array( 'pending', 'failed', 'cancelled', 'checkout-draft', 'auto-draft', 'draft', 'trash', 'refunded' ) as $status ) {
			$order = $this->order( new WC_Order( ++$id, $status ) );
			Pingwa_Triggers::on_order( $id );
			$this->assertSame( array(), $this->flush(), $status );
			$this->assertSame( '', $order->get_meta( Pingwa_Sender::ORDER_META ), "$status not marked" );
		}
	}

	public function test_declined_then_paid_retry_gives_one_message_with_the_paid_status() {
		$order = $this->order( new WC_Order( 7, 'pending' ) );
		Pingwa_Triggers::on_order( 7 ); // Checkout: created pending.
		$this->assertSame( array(), $this->flush() );

		$order->status = 'failed'; // Card declined.
		Pingwa_Triggers::on_status_changed( 7, 'pending', 'failed' );
		$this->assertSame( array(), $this->flush() );
		$this->assertSame( '', $order->get_meta( Pingwa_Sender::ORDER_META ) );

		$order->status = 'processing'; // Retry paid.
		Pingwa_Triggers::on_status_changed( 7, 'failed', 'processing' );
		$bodies = $this->flush();
		$this->assertSame( array( '7' ), $this->numbers( $bodies ) );
		$this->assertSame( 'processing', $bodies[0]['status'] );
		$this->assertNotSame( '', $order->get_meta( Pingwa_Sender::ORDER_META ) );

		$order->status = 'completed';
		Pingwa_Triggers::on_status_changed( 7, 'processing', 'completed' );
		Pingwa_Triggers::on_order( 7 );
		$this->assertSame( array(), $this->flush(), 'one message per order' );
	}

	public function test_moves_into_a_paid_status_from_pending_failed_or_draft_count() {
		$id = 20;
		foreach ( array( 'pending', 'failed', 'checkout-draft', 'auto-draft', 'draft' ) as $from ) {
			$this->order( new WC_Order( ++$id, 'on-hold' ) );
			Pingwa_Triggers::on_status_changed( $id, $from, 'on-hold' );
			$this->assertSame( array( (string) $id ), $this->numbers( $this->flush() ), $from );
		}
	}

	public function test_other_moves_never_count() {
		$cases = array(
			array( 'cancelled', 'processing' ),
			array( 'refunded', 'processing' ),
			array( 'on-hold', 'processing' ),
			array( 'processing', 'completed' ),
			array( 'trash', 'processing' ),
			array( 'pending', 'cancelled' ),
			array( 'pending', 'failed' ),
		);
		$id = 30;
		foreach ( $cases as $case ) {
			$this->order( new WC_Order( ++$id, $case[1] ) );
			Pingwa_Triggers::on_status_changed( $id, $case[0], $case[1] );
			$this->assertSame( array(), $this->flush(), implode( ' → ', $case ) );
		}
	}

	public function test_an_abandoned_pending_order_gives_none() {
		$order = $this->order( new WC_Order( 40, 'pending' ) );
		Pingwa_Triggers::on_order( 40 );
		$this->assertSame( array(), $this->flush() );
		$this->assertSame( '', $order->get_meta( Pingwa_Sender::ORDER_META ) );
	}
}
