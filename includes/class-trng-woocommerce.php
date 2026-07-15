<?php
/**
 * Optional WooCommerce integration: "My Draws" tab in My Account.
 * Loads only when WooCommerce is active.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_WooCommerce {

	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_rng-draws_endpoint', array( __CLASS__, 'endpoint_content' ) );
	}

	public static function add_endpoint() {
		add_rewrite_endpoint( 'rng-draws', EP_ROOT | EP_PAGES );
	}

	public static function menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new['rng-draws'] = 'My Draws';
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['rng-draws'] ) ) {
			$new['rng-draws'] = 'My Draws';
		}
		return $new;
	}

	public static function endpoint_content() {
		echo do_shortcode( '[trng_user_history]' );
	}
}
