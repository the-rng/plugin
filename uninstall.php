<?php
/**
 * Uninstall handler. The draw ledger is an audit trail, so it is only
 * removed when the administrator explicitly opted in via settings.
 *
 * @package The_RNG
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$trng_settings = get_option( 'trng_settings', array() );

if ( is_array( $trng_settings ) && ! empty( $trng_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'trng_draws' ); // phpcs:ignore
	delete_option( 'trng_settings' );
	delete_option( 'trng_db_version' );
}
