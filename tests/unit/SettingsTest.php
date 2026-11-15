<?php
/**
 * The settings page's "Send test" result: the server's HTTP status and its answer.
 *
 * @package pingwa
 */

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function test_accepted() {
		$r = Pingwa_Settings::test_summary( 200, '{"accepted": true, "id": "m_1"}' );
		$this->assertSame( 'success', $r['type'] );
		$this->assertStringContainsString( 'HTTP 200', $r['message'] );
		$this->assertStringContainsString( 'accepted', $r['message'] );
	}

	public function test_refused_with_the_server_code() {
		$r = Pingwa_Settings::test_summary( 200, '{"accepted": false, "error": "quota_exceeded"}' );
		$this->assertSame( 'warning', $r['type'] );
		$this->assertStringContainsString( 'HTTP 200', $r['message'] );
		$this->assertStringContainsString( 'quota_exceeded', $r['message'] );
	}

	public function test_refusal_code_is_sanitized() {
		$r = Pingwa_Settings::test_summary( 200, '{"accepted": false, "error": "<script>x</script>"}' );
		$this->assertStringNotContainsString( '<', $r['message'] );
	}

	public function test_unknown_hook() {
		$r = Pingwa_Settings::test_summary( 404, '{"detail": "Not Found"}' );
		$this->assertSame( 'error', $r['type'] );
		$this->assertStringContainsString( 'HTTP 404', $r['message'] );
		$this->assertStringContainsString( 'revoked', $r['message'] );
	}

	public function test_other_status() {
		$r = Pingwa_Settings::test_summary( 413, 'too big' );
		$this->assertSame( 'error', $r['type'] );
		$this->assertStringContainsString( 'HTTP 413', $r['message'] );
	}

	public function test_200_without_json() {
		$r = Pingwa_Settings::test_summary( 200, 'ok' );
		$this->assertSame( 'warning', $r['type'] );
		$this->assertStringContainsString( 'HTTP 200', $r['message'] );
	}

	private function set_up_filter( $callback ) {
		$GLOBALS['pingwa_wp']['filters']['pingwa_join_url'] = $callback;
	}

	protected function setUp(): void {
		pingwa_test_reset();
	}

	public function test_join_link_is_the_stable_server_redirect() {
		$this->assertSame( 'https://pingwa.dev/join/wp', Pingwa_Settings::JOIN_URL );
		$this->assertSame( 'https://pingwa.dev/join/wp', Pingwa_Settings::join_url() );
	}

	public function test_join_link_follows_the_filter() {
		$this->set_up_filter(
			function () {
				return 'https://pingwa.example.org/join/wp';
			}
		);
		$this->assertSame( 'https://pingwa.example.org/join/wp', Pingwa_Settings::join_url() );
	}

	/**
	 * @dataProvider bad_filter_values
	 * @param mixed $value What the filter returns.
	 */
	public function test_join_link_ignores_an_unusable_filter_value( $value ) {
		$this->set_up_filter(
			function () use ( $value ) {
				return $value;
			}
		);
		$this->assertSame( Pingwa_Settings::JOIN_URL, Pingwa_Settings::join_url() );
	}

	public function bad_filter_values() {
		return array(
			'empty'      => array( '' ),
			'null'       => array( null ),
			'array'      => array( array( 'https://pingwa.dev/join/wp' ) ),
			'http'       => array( 'http://pingwa.example.org/join/wp' ),
			'javascript' => array( 'javascript:alert(1)' ),
			'no host'    => array( 'https:///join/wp' ),
			'space'      => array( 'https://pingwa.example.org/join wp' ),
		);
	}

	public function test_qr_payload_is_the_join_link() {
		$this->assertSame( 'https://pingwa.dev/join/wp', Pingwa_Settings::join_qr_payload() );
	}

	public function test_qr_is_hidden_when_the_link_is_filtered() {
		$this->set_up_filter(
			function () {
				return 'https://pingwa.example.org/join/wp';
			}
		);
		$this->assertSame( '', Pingwa_Settings::join_qr_payload() );
	}

	public function test_shipped_qr_image_carries_the_join_link() {
		// tests/e2e/check_bodies.py proves the image is segno's QR of exactly this text.
		$svg = file_get_contents( dirname( __DIR__, 2 ) . '/assets/join-wp-qr.svg' );
		$this->assertStringContainsString( '<desc>' . Pingwa_Settings::JOIN_URL . '</desc>', $svg );
		$this->assertSame( Pingwa_Settings::join_qr_payload(), Pingwa_Settings::JOIN_URL );
	}

	public function test_no_whatsapp_number_is_shipped() {
		$root = dirname( __DIR__, 2 );
		$hits = array();
		foreach ( array( 'pingwa.php', 'uninstall.php', 'readme.txt', 'assets/join-wp-qr.svg' ) as $file ) {
			$text = file_get_contents( "$root/$file" );
			if ( preg_match( '#wa\.me/\+?\d#', $text ) ) {
				$hits[] = $file;
			}
		}
		foreach ( glob( "$root/includes/*.php" ) as $file ) {
			if ( preg_match( '#wa\.me/\+?\d#', file_get_contents( $file ) ) ) {
				$hits[] = $file;
			}
		}
		$this->assertSame( array(), $hits );
	}
}
