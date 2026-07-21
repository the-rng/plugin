<?php
/**
 * Plugin Name:       The-RNG — Verifiably Fair Random Number Generator
 * Plugin URI:        https://the-rng.com
 * Description:       Verifiably fair random number generation for competitions and raffles, powered by the drand distributed randomness beacon (League of Entropy, served via the Cloudflare relay). HMAC-SHA256 seed combination, rejection sampling (no modulo bias), tamper-evident draw ledger and full public verification.
 * Version:           2.7.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            The-RNG
 * Author URI:        https://the-rng.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       the-rng
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TRNG_VERSION', '2.7.1' );
define( 'TRNG_PLUGIN_FILE', __FILE__ );
define( 'TRNG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRNG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TRNG_TABLE', 'trng_draws' );

/*
 * Drand "quicknet" chain constants (League of Entropy).
 * Chain info is public: https://drand.cloudflare.com/{chain-hash}/info
 * These constants are immutable properties of the quicknet chain.
 */
define( 'TRNG_CHAIN_HASH', '52db9ba70e0cc0f6eaf7803dd07447a1f5477735fd3f661792ba94600c84e971' );
define( 'TRNG_CHAIN_GENESIS', 1692803367 ); // Unix time of round 1.
define( 'TRNG_CHAIN_PERIOD', 3 );           // Seconds between rounds.
define( 'TRNG_CHAIN_SCHEME', 'bls-unchained-g1-rfc9380' );
define( 'TRNG_CHAIN_PUBLIC_KEY', '83cf0f2896adee7eb8b5f01fcad3912212c437e0073e911fb90022d3e760183c8c4b450b6a0a6c3ac6a5776a2d1064510d1fec758c921cc22b0e17e63aaf4bcb5ed66304de9cf809bd274ca73bab4af5a6e9c76a4bc09e76eae8991ef5ece45a' );

require_once TRNG_PLUGIN_DIR . 'includes/class-trng-install.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-settings.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-drand.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-engine.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-draws.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-ajax.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-shortcodes.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-admin.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-integrity.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-github.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-registration.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-api.php';
require_once TRNG_PLUGIN_DIR . 'includes/class-trng-woocommerce.php';

register_activation_hook( __FILE__, array( 'TRNG_Install', 'activate' ) );

/**
 * Boot the plugin.
 */
function trng_init() {
	TRNG_Install::maybe_upgrade();
	TRNG_Ajax::register();
	TRNG_Shortcodes::register();
	TRNG_Admin::register();
	TRNG_Integrity::register();
	TRNG_GitHub::register();
	TRNG_Registration::register();
	TRNG_API::register();
	TRNG_WooCommerce::register();
}
add_action( 'plugins_loaded', 'trng_init' );

/**
 * Front-end assets.
 */
function trng_enqueue_assets() {
	wp_enqueue_style( 'trng-css', TRNG_PLUGIN_URL . 'assets/css/trng.css', array(), TRNG_VERSION );
	wp_enqueue_script( 'trng-js', TRNG_PLUGIN_URL . 'assets/js/trng.js', array(), TRNG_VERSION, true );

	wp_localize_script(
		'trng-js',
		'trng_config',
		array(
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'chain_hash'    => TRNG_CHAIN_HASH,
			'chain_genesis' => TRNG_CHAIN_GENESIS,
			'chain_period'  => TRNG_CHAIN_PERIOD,
			'round_offset'  => (int) TRNG_Settings::get( 'round_offset' ),
			'display_timezone' => (string) TRNG_Settings::get( 'display_timezone' ),
			'relays'        => TRNG_Settings::get( 'relays' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'trng_enqueue_assets' );
