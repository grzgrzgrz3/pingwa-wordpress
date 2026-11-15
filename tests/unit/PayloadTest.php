<?php
/**
 * Payload building: one JSON body that reads well on a hook without a template (its
 * `text` field) and fills the server's presets (woocommerce_order, form_generic).
 *
 * @package pingwa
 */

use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase {

	private function order( array $over = array() ) {
		return array_merge(
			array(
				'number'               => '727',
				'total'                => '29.35',
				'currency'             => 'USD',
				'status'               => 'processing',
				'first_name'           => 'John',
				'last_name'            => 'Doe',
				'payment_method_title' => 'Direct Bank Transfer',
				'edit_url'             => 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=727',
				'items'                => array(
					array( 'name' => 'Woo Single #1', 'quantity' => 2, 'total' => '20.00' ),
					array( 'name' => 'Ship Your Idea', 'quantity' => 1, 'total' => '9.35' ),
				),
			),
			$over
		);
	}

	public function test_order_fills_woocommerce_order_preset_fields() {
		$p = Pingwa_Payload::order( $this->order(), 'My Shop' );
		// hook_templates.PRESETS["woocommerce_order"] reads exactly these paths.
		$this->assertSame( '727', $p['number'] );
		$this->assertSame( '29.35', $p['total'] );
		$this->assertSame( 'USD', $p['currency'] );
		$this->assertSame( 'processing', $p['status'] );
		$this->assertSame( array( 'first_name' => 'John', 'last_name' => 'Doe' ), $p['billing'] );
		$this->assertSame( 2, $p['line_items'][0]['quantity'] );
		$this->assertSame( 'Woo Single #1', $p['line_items'][0]['name'] );
		$this->assertSame( 'Direct Bank Transfer', $p['payment_method_title'] );
		$this->assertSame( 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=727', $p['edit_url'] );
		$this->assertSame( 'woocommerce', $p['source'] );
	}

	public function test_order_text_is_a_readable_message() {
		$p = Pingwa_Payload::order( $this->order(), 'My Shop' );
		$this->assertSame(
			"🛒 New order #727 on My Shop: 29.35 USD\n"
			. "Status: processing\n"
			. "Items: 2 × Woo Single #1, 1 × Ship Your Idea\n"
			. "Customer: John Doe\n"
			. "Payment: Direct Bank Transfer\n"
			. 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=727',
			$p['text']
		);
	}

	public function test_order_never_carries_contact_details() {
		$o = $this->order(
			array(
				'email'     => 'john@example.com',
				'phone'     => '+15551234567',
				'address_1' => '969 Market',
				'city'      => 'San Francisco',
				'billing'   => array( 'email' => 'john@example.com', 'phone' => '+15551234567' ),
				'shipping'  => array( 'address_1' => '969 Market' ),
			)
		);
		$json = wp_json_encode( Pingwa_Payload::order( $o, 'My Shop' ) );
		$this->assertStringNotContainsString( 'john@example.com', $json );
		$this->assertStringNotContainsString( '5551234567', $json );
		$this->assertStringNotContainsString( '969 Market', $json );
		$this->assertStringNotContainsString( 'San Francisco', $json );
		$this->assertStringNotContainsString( 'shipping', $json );
		$this->assertSame( array( 'first_name', 'last_name' ), array_keys( Pingwa_Payload::order( $o, 'x' )['billing'] ) );
	}

	public function test_order_caps_items_and_lengths() {
		$items = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$items[] = array( 'name' => str_repeat( 'n', 500 ), 'quantity' => 1, 'total' => '1' );
		}
		$p = Pingwa_Payload::order( $this->order( array( 'items' => $items ) ), 'My Shop' );
		$this->assertCount( Pingwa_Payload::MAX_ITEMS, $p['line_items'] );
		$this->assertSame( Pingwa_Payload::MAX_VALUE, mb_strlen( $p['line_items'][0]['name'] ) );
		$this->assertLessThanOrEqual( Pingwa_Payload::MAX_TEXT, mb_strlen( $p['text'] ) );
	}

	public function test_order_without_customer_name_or_items_still_reads() {
		$p = Pingwa_Payload::order(
			$this->order( array( 'first_name' => '', 'last_name' => '', 'items' => array(), 'payment_method_title' => '' ) ),
			'My Shop'
		);
		$this->assertStringNotContainsString( 'Customer:', $p['text'] );
		$this->assertStringNotContainsString( 'Items:', $p['text'] );
		$this->assertStringNotContainsString( 'Payment:', $p['text'] );
		$this->assertSame( array(), $p['line_items'] );
	}

	private function fields() {
		return array(
			array( 'label' => 'Your name', 'value' => 'Ada', 'type' => 'text' ),
			array( 'label' => 'Email', 'value' => 'ada@example.com', 'type' => 'email' ),
			array( 'label' => 'Password', 'value' => 'hunter2', 'type' => 'password' ),
			array( 'label' => 'CV', 'value' => 'cv.pdf', 'type' => 'file' ),
			array( 'label' => 'ref', 'value' => 'secret-ref', 'type' => 'hidden' ),
			array( 'label' => 'Topics', 'value' => array( 'Sales', 'Support' ), 'type' => 'checkbox' ),
			array( 'label' => 'Message', 'value' => "<b>Hello</b>\r\nthere", 'type' => 'textarea' ),
		);
	}

	public function test_form_fills_form_generic_preset_fields() {
		$p = Pingwa_Payload::form( 'Contact form 1', $this->fields(), 'contact-form-7', 'My Shop' );
		// hook_templates.PRESETS["form_generic"]: data.formName, data.fields[n].label/value.
		$this->assertSame( 'Contact form 1', $p['data']['formName'] );
		$this->assertSame(
			array(
				array( 'label' => 'Your name', 'value' => 'Ada' ),
				array( 'label' => 'Email', 'value' => 'ada@example.com' ),
				array( 'label' => 'Topics', 'value' => 'Sales, Support' ),
				array( 'label' => 'Message', 'value' => "Hello\nthere" ),
			),
			$p['data']['fields']
		);
		$this->assertSame( 'contact-form-7', $p['source'] );
	}

	public function test_form_text_is_a_readable_message() {
		$p = Pingwa_Payload::form( 'Contact form 1', $this->fields(), 'contact-form-7', 'My Shop' );
		$this->assertSame(
			"📝 Contact form 1 on My Shop: new response\n"
			. "Your name: Ada\n"
			. "Email: ada@example.com\n"
			. "Topics: Sales, Support\n"
			. "Message: Hello\nthere",
			$p['text']
		);
	}

	public function test_form_skips_secret_and_machine_fields() {
		$fields = array(
			array( 'label' => 'a', 'value' => 'x1', 'type' => 'password' ),
			array( 'label' => 'b', 'value' => 'x2', 'type' => 'file*' ),
			array( 'label' => 'c', 'value' => 'x3', 'type' => 'fileupload' ),
			array( 'label' => 'd', 'value' => 'x4', 'type' => 'file-upload' ),
			array( 'label' => 'e', 'value' => 'x5', 'type' => 'upload' ),
			array( 'label' => 'f', 'value' => 'x6', 'type' => 'hidden' ),
			array( 'label' => 'g', 'value' => 'x7', 'type' => 'honeypot' ),
			array( 'label' => 'h', 'value' => 'x8', 'type' => 'captcha' ),
			array( 'label' => 'i', 'value' => 'x9', 'type' => 'recaptcha' ),
			array( 'label' => 'j', 'value' => 'x10', 'type' => 'recaptcha_v3' ),
			array( 'label' => 'k', 'value' => 'x11', 'type' => 'hcaptcha' ),
			array( 'label' => 'l', 'value' => 'x12', 'type' => 'turnstile' ),
			array( 'label' => 'm', 'value' => 'x13', 'type' => 'captchar' ),
			array( 'label' => 'n', 'value' => 'x14', 'type' => 'quiz' ),
			array( 'label' => 'o', 'value' => 'x15', 'type' => 'submit' ),
			array( 'label' => 'p', 'value' => 'x16', 'type' => 'text', 'name' => 'honeypot_field' ),
			array( 'label' => 'Leave this empty (captcha)', 'value' => 'x17', 'type' => 'text' ),
			array( 'label' => 'q', 'value' => 'x18', 'type' => 'signature' ),
			array( 'label' => 'kept', 'value' => 'ok', 'type' => 'TEXT' ),
			array( 'label' => 'empty', 'value' => '   ', 'type' => 'text' ),
		);
		$p    = Pingwa_Payload::form( 'F', $fields, 'wpforms', 'S' );
		$json = wp_json_encode( $p );
		for ( $i = 1; $i <= 18; $i++ ) {
			$this->assertStringNotContainsString( '"x' . $i . '"', $json );
		}
		$this->assertSame( array( array( 'label' => 'kept', 'value' => 'ok' ) ), $p['data']['fields'] );
	}

	public function test_form_caps_value_label_count_and_text() {
		$fields = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$fields[] = array( 'label' => str_repeat( 'L', 200 ), 'value' => str_repeat( 'v', 5000 ), 'type' => 'text' );
		}
		$p = Pingwa_Payload::form( str_repeat( 'T', 500 ), $fields, 'gravityforms', 'S' );
		$this->assertCount( Pingwa_Payload::MAX_FIELDS, $p['data']['fields'] );
		$this->assertSame( Pingwa_Payload::MAX_VALUE, mb_strlen( $p['data']['fields'][0]['value'] ) );
		$this->assertSame( Pingwa_Payload::MAX_LABEL, mb_strlen( $p['data']['fields'][0]['label'] ) );
		$this->assertSame( Pingwa_Payload::MAX_LABEL, mb_strlen( $p['data']['formName'] ) );
		$this->assertLessThanOrEqual( Pingwa_Payload::MAX_TEXT, mb_strlen( $p['text'] ) );
		$this->assertStringEndsWith( '…', $p['text'] );
	}

	public function test_form_cuts_multibyte_values_on_characters() {
		$p = Pingwa_Payload::form( 'F', array( array( 'label' => 'x', 'value' => str_repeat( 'ż', 400 ), 'type' => 'text' ) ), 'elementor', 'S' );
		$this->assertSame( str_repeat( 'ż', Pingwa_Payload::MAX_VALUE ), $p['data']['fields'][0]['value'] );
		$this->assertNotFalse( wp_json_encode( $p ) );
	}

	public function test_form_values_drop_control_characters_and_nested_arrays_flatten() {
		$p = Pingwa_Payload::form(
			'F',
			array(
				array( 'label' => "a\tb", 'value' => "x\x00y\x07z", 'type' => 'text' ),
				array( 'label' => 'nested', 'value' => array( 'first' => 'Ada', 'last' => array( 'Love', 'lace' ), 'n' => 3, 't' => true ), 'type' => 'name' ),
			),
			'gravityforms',
			'S'
		);
		$this->assertSame( array( 'label' => 'a b', 'value' => 'xyz' ), $p['data']['fields'][0] );
		$this->assertSame( 'Ada, Love, lace, 3, yes', $p['data']['fields'][1]['value'] );
	}

	public function test_form_without_title_or_fields() {
		$p = Pingwa_Payload::form( '', array(), 'wpforms', 'S' );
		$this->assertSame( 'Form', $p['data']['formName'] );
		$this->assertSame( "📝 Form on S: new response", $p['text'] );
		$this->assertSame( array(), $p['data']['fields'] );
	}

	public function test_test_payload_has_text() {
		$p = Pingwa_Payload::test( 'My Shop' );
		$this->assertSame( 'test', $p['source'] );
		$this->assertStringContainsString( 'My Shop', $p['text'] );
	}

	public function test_site_name_is_plain_text() {
		$p = Pingwa_Payload::test( '<b>Shop</b> &amp; Co' );
		$this->assertStringContainsString( 'Shop & Co', $p['text'] );
		$this->assertSame( 'Shop & Co', $p['site'] );
	}
}
