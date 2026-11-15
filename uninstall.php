<?php
/**
 * Uninstall: remove the plugin's options (the hook URL and the toggles) from the site, or
 * from every site of a network. The `_pingwa_notified` mark on orders stays; it only
 * says an order was sent once.
 *
 * @package pingwa
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pingwa_options = array( 'pingwa_hook_url', 'pingwa_triggers' );

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $pingwa_site_id ) {
		switch_to_blog( $pingwa_site_id );
		foreach ( $pingwa_options as $pingwa_option ) {
			delete_option( $pingwa_option );
		}
		restore_current_blog();
	}
} else {
	foreach ( $pingwa_options as $pingwa_option ) {
		delete_option( $pingwa_option );
	}
}

foreach ( $pingwa_options as $pingwa_option ) {
	delete_site_option( $pingwa_option );
}
