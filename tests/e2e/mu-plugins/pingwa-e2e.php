<?php
/**
 * E2E only (never shipped): let WordPress reach the local stand-in for the pingwa server.
 * wp_safe_remote_post() refuses private addresses unless the host is declared external,
 * and the stand-in's certificate is signed by the E2E CA, so it is trusted for that
 * host only. TLS verification stays on.
 *
 * @package pingwa
 */

const PINGWA_E2E_HOST = 'hooks.pingwa-e2e.test';

add_filter(
	'http_request_host_is_external',
	function ( $external, $host ) {
		return PINGWA_E2E_HOST === $host ? true : $external;
	},
	10,
	2
);

add_filter(
	'http_request_args',
	function ( $args, $url ) {
		if ( 0 === strpos( $url, 'https://' . PINGWA_E2E_HOST . '/' ) ) {
			$args['sslcertificates'] = '/certs/ca.pem';
		}
		return $args;
	},
	10,
	2
);
