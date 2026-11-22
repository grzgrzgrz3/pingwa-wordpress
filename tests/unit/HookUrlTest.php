<?php
/**
 * Hook URL validation: only https URLs whose path is the server's `/h/hk_<32>` format.
 *
 * @package pingwa
 */

use PHPUnit\Framework\TestCase;

final class HookUrlTest extends TestCase {

	const TOKEN = 'hk_AbCdEfGhIjKlMnOpQrStUvWxYz012-_9';

	public function test_token_fixture_is_server_shaped() {
		// hooks_api._TOKEN_RE: ^hk_[A-Za-z0-9_-]{32}$ (hk_ + token_urlsafe(24)).
		$this->assertSame( 35, strlen( self::TOKEN ) );
	}

	/**
	 * @dataProvider valid_urls
	 */
	public function test_accepts_server_hook_urls( $url ) {
		$this->assertTrue( Pingwa_Hook_Url::is_valid( $url ), $url );
	}

	public function valid_urls() {
		return array(
			'production'           => array( 'https://pingwa.dev/h/' . self::TOKEN ),
			'self-hosted'          => array( 'https://alerts.example.com/h/' . self::TOKEN ),
			'port'                 => array( 'https://alerts.example.com:8443/h/' . self::TOKEN ),
			'base url with a path' => array( 'https://example.com/pingwa/h/' . self::TOKEN ),
			'surrounding blanks'   => array( "  https://pingwa.dev/h/" . self::TOKEN . "\n" ),
			'upper-case scheme'    => array( 'HTTPS://pingwa.dev/h/' . self::TOKEN ),
		);
	}

	/**
	 * @dataProvider invalid_urls
	 */
	public function test_refuses_everything_else( $url ) {
		$this->assertFalse( Pingwa_Hook_Url::is_valid( $url ), (string) $url );
	}

	public function invalid_urls() {
		$t = self::TOKEN;
		return array(
			'empty'                 => array( '' ),
			'not a string'          => array( null ),
			'http'                  => array( 'http://pingwa.dev/h/' . $t ),
			'no scheme'             => array( 'pingwa.dev/h/' . $t ),
			'javascript'            => array( 'javascript:alert(1)//h/' . $t ),
			'no host'               => array( 'https:///h/' . $t ),
			'credentials'           => array( 'https://user:pass@pingwa.dev/h/' . $t ),
			'query string'          => array( 'https://pingwa.dev/h/' . $t . '?text=hi' ),
			'fragment'              => array( 'https://pingwa.dev/h/' . $t . '#x' ),
			'trailing slash'        => array( 'https://pingwa.dev/h/' . $t . '/' ),
			'short token'           => array( 'https://pingwa.dev/h/hk_abc' ),
			'long token'            => array( 'https://pingwa.dev/h/' . $t . 'x' ),
			'bad token chars'       => array( 'https://pingwa.dev/h/hk_AbCdEfGhIjKlMnOpQrStUvWxYz012+/9' ),
			'api key instead'       => array( 'https://pingwa.dev/h/pw_AbCdEfGhIjKlMnOpQrStUvWxYz012-_9' ),
			'notify endpoint'       => array( 'https://pingwa.dev/v1/notify' ),
			'not under /h/'         => array( 'https://pingwa.dev/x/' . $t ),
			'encoded path'          => array( 'https://pingwa.dev/h%2F' . $t ),
			'dot segment'           => array( 'https://example.com/../h/' . $t ),
			'whitespace inside'     => array( 'https://pingwa.dev/h/ ' . $t ),
			'newline inside'        => array( "https://pingwa.dev/h/\n" . $t ),
			'overlong'              => array( 'https://' . str_repeat( 'a', 2100 ) . '.com/h/' . $t ),
		);
	}

	public function test_sanitize_trims_valid_and_empties_invalid() {
		$url = 'https://pingwa.dev/h/' . self::TOKEN;
		$this->assertSame( $url, Pingwa_Hook_Url::sanitize( " $url " ) );
		$this->assertSame( '', Pingwa_Hook_Url::sanitize( 'http://pingwa.dev/h/' . self::TOKEN ) );
	}

	public function test_mask_shows_host_and_last_four_only() {
		$url = 'https://pingwa.dev/h/' . self::TOKEN;
		// The panel's url_hint shape: "…/h/hk_…<last 4>".
		$this->assertSame( 'https://pingwa.dev/h/hk_…2-_9', Pingwa_Hook_Url::mask( $url ) );
		$this->assertStringNotContainsString( 'AbCd', Pingwa_Hook_Url::mask( $url ) );
		$this->assertSame( '', Pingwa_Hook_Url::mask( 'nope' ) );
	}
}
