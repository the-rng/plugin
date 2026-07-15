<?php
/**
 * AJAX endpoints.
 *
 *  - trng_start:       (logged-in) commit a draw to a future drand round.
 *  - trng_complete:    (public) resolve a committed draw once its round exists.
 *  - trng_lookup:      (public) fetch the full verification record for a key.
 *  - trng_server_time: (public) server clock, for transparency display.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Ajax {

	public static function register() {
		add_action( 'wp_ajax_trng_start', array( __CLASS__, 'start' ) );
		// Deliberately no nopriv for start: generation requires an account.

		add_action( 'wp_ajax_trng_complete', array( __CLASS__, 'complete' ) );
		add_action( 'wp_ajax_nopriv_trng_complete', array( __CLASS__, 'complete' ) );

		add_action( 'wp_ajax_trng_lookup', array( __CLASS__, 'lookup' ) );
		add_action( 'wp_ajax_nopriv_trng_lookup', array( __CLASS__, 'lookup' ) );

		add_action( 'wp_ajax_trng_server_time', array( __CLASS__, 'server_time' ) );
		add_action( 'wp_ajax_nopriv_trng_server_time', array( __CLASS__, 'server_time' ) );
	}

	/**
	 * Cheap per-IP throttle.
	 *
	 * @param string $bucket Action bucket.
	 * @param int    $limit  Max hits per minute.
	 * @return bool True when allowed.
	 */
	protected static function throttle( $bucket, $limit ) {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'trng_rate_' . $bucket . '_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Step 1 — commit. Assigns the client seed (forced server timestamp),
	 * the draw UUID and the target drand round, then stores the commitment
	 * before the round's randomness exists anywhere in the world.
	 */
	public static function start() {
		if ( ! isset( $_POST['trng_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trng_nonce'] ) ), 'trng_start' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed. Refresh the page and try again.' ), 400 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'You must be logged in to generate random numbers.' ), 403 );
		}
		if ( ! TRNG_Registration::is_approved( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => 'Your account is awaiting manual approval before draws can be generated.' ), 403 );
		}
		if ( ! self::throttle( 'start', 12 ) ) {
			wp_send_json_error( array( 'message' => 'Too many draws started. Please wait a minute.' ), 429 );
		}

		$draw = TRNG_Draws::create(
			array(
				'title'        => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
				'url'          => isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '',
				'tickets_sold' => isset( $_POST['tickets_sold'] ) ? (int) $_POST['tickets_sold'] : 0,
				'max_tickets'  => isset( $_POST['max_tickets'] ) ? (int) $_POST['max_tickets'] : 0,
				'num_winners'  => isset( $_POST['num_winners'] ) ? (int) $_POST['num_winners'] : 1,
				'user_id'      => get_current_user_id(),
			)
		);

		if ( is_wp_error( $draw ) ) {
			wp_send_json_error( array( 'message' => $draw->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'draw_key'     => $draw['draw_key'],
				'client_seed'  => $draw['client_seed'],
				'round_uuid'   => $draw['round_uuid'],
				'static_salt'  => $draw['static_salt'],
				'chain_hash'   => $draw['chain_hash'],
				'target_round' => (int) $draw['target_round'],
				'round_time'   => TRNG_Drand::time_of_round( (int) $draw['target_round'] ),
				'server_now'   => time(),
			)
		);
	}

	/**
	 * Step 2 — resolve. Safe for anyone to call: the result is fully
	 * determined by the public beacon and the stored commitment.
	 */
	public static function complete() {
		if ( ! self::throttle( 'complete', 60 ) ) {
			wp_send_json_error( array( 'message' => 'Too many requests. Please wait a minute.' ), 429 );
		}

		$key = isset( $_POST['draw_key'] ) ? sanitize_text_field( wp_unslash( $_POST['draw_key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => 'Draw key required.' ), 400 );
		}

		$draw = TRNG_Draws::complete( $key );

		if ( is_wp_error( $draw ) ) {
			$data = $draw->get_error_data();
			if ( 'trng_not_ready' === $draw->get_error_code() ) {
				wp_send_json_error(
					array(
						'message'  => $draw->get_error_message(),
						'retry'    => true,
						'ready_in' => is_array( $data ) && isset( $data['ready_in'] ) ? (int) $data['ready_in'] : 3,
					),
					202
				);
			}
			// Relay hiccups are retryable too.
			$retryable = in_array( $draw->get_error_code(), array( 'trng_all_relays_failed' ), true );
			wp_send_json_error( array( 'message' => $draw->get_error_message(), 'retry' => $retryable ), $retryable ? 202 : 400 );
		}

		wp_send_json_success( TRNG_Draws::to_public( $draw ) );
	}

	/**
	 * Public lookup of a stored draw. Pending draws whose round has passed
	 * are completed on the fly (deterministic, so always safe).
	 */
	public static function lookup() {
		if ( ! self::throttle( 'lookup', 60 ) ) {
			wp_send_json_error( array( 'message' => 'Too many requests. Please wait a minute.' ), 429 );
		}

		$key = isset( $_POST['draw_key'] ) ? sanitize_text_field( wp_unslash( $_POST['draw_key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => 'Verification key required.' ), 400 );
		}

		$draw = TRNG_Draws::get( $key );
		if ( ! $draw ) {
			wp_send_json_error( array( 'message' => 'No draw found for that key.' ), 404 );
		}

		if ( 'pending' === $draw['status'] && time() >= TRNG_Drand::time_of_round( (int) $draw['target_round'] ) ) {
			$completed = TRNG_Draws::complete( $key );
			if ( ! is_wp_error( $completed ) ) {
				$draw = $completed;
			}
		}

		wp_send_json_success( TRNG_Draws::to_public( $draw ) );
	}

	/**
	 * Server clock (UTC + unix), shown on the generator for transparency.
	 */
	public static function server_time() {
		wp_send_json_success(
			array(
				'utc'  => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
				'unix' => time(),
			)
		);
	}
}
