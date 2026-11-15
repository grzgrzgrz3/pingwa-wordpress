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
}
