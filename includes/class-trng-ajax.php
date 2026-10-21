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
		// Generation requires an account. A logged-out request gets a clear "logged out" answer
		// (v2.8.0) instead of WordPress's bare "0", which the page could only show as a network error.
		add_action( 'wp_ajax_nopriv_trng_start', array( __CLASS__, 'start_logged_out' ) );

		// v2.8.0: the generator page checks the session on load, every minute, when the tab comes back
		// into view and before every draw, and picks up a fresh security token each time.
		add_action( 'wp_ajax_trng_session', array( __CLASS__, 'session' ) );
		add_action( 'wp_ajax_nopriv_trng_session', array( __CLASS__, 'session' ) );

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

	/** Where to log in and come straight back to the page that asked. */
	protected static function login_url() {
		$back = isset( $_POST['page'] ) ? esc_url_raw( wp_unslash( $_POST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back = $back && wp_validate_redirect( $back, '' ) ? $back : home_url( '/' );
		return wp_login_url( $back );
	}

	/** Session state for the generator page (no nonce: it only reports whether you are logged in). */
	public static function session() {
		$user = get_current_user_id();
		wp_send_json_success(
			array(
				'logged_in' => (bool) $user,
				'approved'  => $user ? TRNG_Registration::is_approved( $user ) : false,
				'nonce'     => $user ? wp_create_nonce( 'trng_start' ) : '',
				'login_url' => self::login_url(),
			)
		);
	}

	public static function start_logged_out() {
		wp_send_json_error(
			array(
				'code'      => 'logged_out',
				'message'   => 'You have been logged out. Log in again to generate this draw. Your entries are kept on this page.',
				'login_url' => self::login_url(),
			),
			401
		);
	}

	/**
	 * Step 1 — commit. Assigns the client seed (forced server timestamp),
	 * the draw UUID and the target drand round, then stores the commitment
	 * before the round's randomness exists anywhere in the world.
	 */
	public static function start() {
		if ( ! is_user_logged_in() ) {
			self::start_logged_out();
		}
		if ( ! isset( $_POST['trng_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trng_nonce'] ) ), 'trng_start' ) ) {
			// The page fetches a fresh token and tries once more by itself.
			wp_send_json_error( array( 'code' => 'nonce', 'message' => 'Your security token expired. Refresh the page and try again.' ), 400 );
		}
		if ( ! TRNG_Registration::is_approved( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => 'Your account is awaiting manual approval before draws can be generated.' ), 403 );
		}
		// v2.8.0: no cap on draws started. Only logged-in, manually approved operators reach this point,
		// and a draw with few tickets sold can need 20 or more draws before one lands on a sold ticket.

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
		// v2.8.1: never throttled. Resolving is deterministic and finishes a draw once; after that it is a read.

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
		// v2.8.1: never throttled (The-RNG Link reads results through this from the operator's server).

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
