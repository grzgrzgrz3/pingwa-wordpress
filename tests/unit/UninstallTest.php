<?php
/**
 * uninstall.php: the options go from every site of a network, and from a single site.
 *
 * @package pingwa
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'pingwa/pingwa.php' );
}

final class UninstallTest extends TestCase {

	protected function setUp(): void {
		pingwa_test_reset();
	}

	private function uninstall() {
		require dirname( __DIR__, 2 ) . '/uninstall.php';
		return $GLOBALS['pingwa_wp']['deleted'];
	}

	public function test_single_site() {
		$this->assertEqualsCanonicalizing(
			array(
				array( 1, 'pingwa_hook_url' ),
				array( 1, 'pingwa_triggers' ),
				array( 'network', 'pingwa_hook_url' ),
				array( 'network', 'pingwa_triggers' ),
			),
			$this->uninstall()
		);
	}

	public function test_multisite_removes_the_options_from_every_site() {
		$GLOBALS['pingwa_wp']['multisite'] = true;
		$GLOBALS['pingwa_wp']['sites']     = array( 1, 2, 7 );
		$deleted                           = $this->uninstall();
		foreach ( array( 1, 2, 7 ) as $site ) {
			$this->assertContains( array( $site, 'pingwa_hook_url' ), $deleted, "site $site" );
			$this->assertContains( array( $site, 'pingwa_triggers' ), $deleted, "site $site" );
		}
		$this->assertContains( array( 'network', 'pingwa_hook_url' ), $deleted );
		$this->assertCount( 8, $deleted );
		$this->assertSame( 1, $GLOBALS['pingwa_wp']['blog'], 'back on the current site' );
	}
}
