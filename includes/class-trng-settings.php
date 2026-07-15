<?php
/**
 * The-RNG settings wrapper.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Settings {

	const OPTION = 'trng_settings';

	/**
	 * Defaults. Note: no personal or company identifiers anywhere —
	 * branding is "The-RNG" only.
	 */
	public static function defaults() {
		return array(
			'static_salt'         => '', // Generated once on activation, then published.
			'round_offset'        => 3,  // Target = live round + offset (3 rounds = 9 seconds).
			'relays'              => array(
				'https://drand.cloudflare.com',
				'https://api.drand.sh',
				'https://api2.drand.sh',
				'https://api3.drand.sh',
			),
			'cross_check'         => 1,  // Require 2 independent relays to agree on the beacon.
			'max_tickets_limit'   => 10000000,
			'max_winners'         => 25,
			'enquiry_email'       => 'info@the-rng.com',
			'display_timezone'    => 'Europe/London', // IANA zone used for on-screen clocks.
			'github_ledger_url'   => '', // Optional: public GitHub ledger repository URL.
			'verify_page_url'     => '', // Page containing [trng_verify].
			'github_source_url'   => '', // Optional: public source code repository URL.
			'ledger_enabled'      => 0,  // Auto-push completed draws to GitHub.
			'ledger_repo'         => '', // owner/repo, e.g. the-rng/ledger.
			'ledger_branch'       => 'main',
			'ledger_token'        => '', // Fine-grained PAT; prefer TRNG_GITHUB_TOKEN in wp-config.php.
			'operator_name'       => '', // Global operator defaults (per-user profile overrides).
			'operator_website'    => '',
			'operator_company_number' => '',
			'brand_name'          => 'The-RNG',
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Get a single setting (with default fallback).
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Update settings (merged).
	 *
	 * @param array $new New values.
	 */
	public static function update( $new ) {
		$all = array_merge( self::all(), $new );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Ensure the public static salt exists. Generated from a CSPRNG once,
	 * then published on the Provably Fair page and included in every draw
	 * record so results can be reproduced.
	 */
	public static function ensure_salt() {
		$all = self::all();
		if ( empty( $all['static_salt'] ) ) {
			self::update( array( 'static_salt' => bin2hex( random_bytes( 16 ) ) ) );
		}
	}
}
