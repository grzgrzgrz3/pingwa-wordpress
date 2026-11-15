<?php
/**
 * Uninstall: remove the plugin's options (the hook URL and the toggles) and its notices.
 * The `_pingwa_notified` mark on orders stays; it only says an order was sent once.
 *
 * @package pingwa
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pingwa_hook_url' );
delete_option( 'pingwa_triggers' );
delete_site_option( 'pingwa_hook_url' );
delete_site_option( 'pingwa_triggers' );
