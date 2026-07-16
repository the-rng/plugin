<?php
/**
 * REST API for operator integrations (e.g. competition sites drawing
 * winners server-to-server).
 *
 *   POST /wp-json/the-rng/v1/draws            create + commit a draw
 *   POST /wp-json/the-rng/v1/draws/{key}/resolve  resolve once the round exists
 *   GET  /wp-json/the-rng/v1/draws/{key}      public record (no auth)
 *
 * Authentication: X-TRNG-Key header carrying a per-operator API key.
 * Keys are generated on the user profile (admins only), stored only as
 * SHA-256 hashes, and can be revoked at any time. Draws created via the
 * API are attributed to the key's user: their operator details appear in
 * the public ledger exactly as for manual draws.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_API {

	const KEY_META = 'trng_api_key_hash';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'key_ui' ), 20 );
		add_action( 'edit_user_profile', array( __CLASS__, 'key_ui' ), 20 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_key_ui' ), 20 );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_key_ui' ), 20 );
	}

	public static function routes() {
		register_rest_route(
			'the-rng/v1',
			'/draws',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_draw' ),
				'permission_callback' => array( __CLASS__, 'auth' ),
				'args'                => array(
					'title'        => array( 'type' => 'string', 'required' => true ),
					'url'          => array( 'type' => 'string', 'required' => false, 'default' => '' ),
					'tickets_sold' => array( 'type' => 'integer', 'required' => true ),
					'max_tickets'  => array( 'type' => 'integer', 'required' => true ),
					'num_winners'  => array( 'type' => 'integer', 'required' => false, 'default' => 1 ),
				),
			)
		);

		register_rest_route(
			'the-rng/v1',
			'/draws/(?P<key>[A-Za-z0-9]{6,32})/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'resolve_draw' ),
				'permission_callback' => array( __CLASS__, 'auth' ),
			)
		);

		register_rest_route(
			'the-rng/v1',
			'/draws/(?P<key>[A-Za-z0-9]{6,32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_draw' ),
				'permission_callback' => '__return_true', // Public record.
			)
		);
	}

	/* ------------------------------------------------------------------
	   AUTH
	------------------------------------------------------------------ */

	/**
	 * Resolve the API key header to an approved operator user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function auth( $request ) {
		$key = trim( (string) $request->get_header( 'x-trng-key' ) );
		if ( '' === $key ) {
			return new WP_Error( 'trng_no_key', 'Missing X-TRNG-Key header.', array( 'status' => 401 ) );
		}

		$user_id = self::user_from_key( $key );
		if ( ! $user_id ) {
			return new WP_Error( 'trng_bad_key', 'Invalid API key.', array( 'status' => 401 ) );
		}
		if ( ! TRNG_Registration::is_approved( $user_id ) ) {
			return new WP_Error( 'trng_not_approved', 'This operator account is not approved.', array( 'status' => 403 ) );
		}

		// Simple per-key rate limit: 30 requests/minute.
		$bucket = 'trng_api_rate_' . $user_id;
		$count  = (int) get_transient( $bucket );
		if ( $count >= 30 ) {
			return new WP_Error( 'trng_rate', 'Rate limit exceeded.', array( 'status' => 429 ) );
		}
		set_transient( $bucket, $count + 1, MINUTE_IN_SECONDS );

		$request->set_param( '_trng_user_id', $user_id );
		return true;
	}

	/**
	 * @param string $key Plaintext key.
	 * @return int User ID or 0.
	 */
	public static function user_from_key( $key ) {
		$users = get_users(
			array(
				'meta_key'   => self::KEY_META, // phpcs:ignore
				'meta_value' => hash( 'sha256', $key ), // phpcs:ignore
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $users ? (int) $users[0] : 0;
	}

	/* ------------------------------------------------------------------
	   ENDPOINTS
	------------------------------------------------------------------ */

	public static function create_draw( $request ) {
		$draw = TRNG_Draws::create(
			array(
				'title'        => (string) $request['title'],
				'url'          => (string) $request['url'],
				'tickets_sold' => (int) $request['tickets_sold'],
				'max_tickets'  => (int) $request['max_tickets'],
				'num_winners'  => (int) $request['num_winners'],
				'user_id'      => (int) $request['_trng_user_id'],
			)
		);

		if ( is_wp_error( $draw ) ) {
			$draw->add_data( array( 'status' => 400 ) );
			return $draw;
		}

		return new WP_REST_Response(
			array(
				'draw_key'     => $draw['draw_key'],
				'round_uuid'   => $draw['round_uuid'],
				'client_seed'  => $draw['client_seed'],
				'static_salt'  => $draw['static_salt'],
				'chain_hash'   => $draw['chain_hash'],
				'target_round' => (int) $draw['target_round'],
				'round_time'   => TRNG_Drand::time_of_round( (int) $draw['target_round'] ),
				'server_now'   => time(),
				'status'       => 'pending',
			),
			201
		);
	}

	public static function resolve_draw( $request ) {
		$draw = TRNG_Draws::complete( sanitize_text_field( $request['key'] ) );

		if ( is_wp_error( $draw ) ) {
			if ( 'trng_not_ready' === $draw->get_error_code() ) {
				$data = $draw->get_error_data();
				return new WP_REST_Response(
					array(
						'status'   => 'pending',
						'ready_in' => is_array( $data ) && isset( $data['ready_in'] ) ? (int) $data['ready_in'] : 3,
					),
					202
				);
			}
			$draw->add_data( array( 'status' => 400 ) );
			return $draw;
		}

		return new WP_REST_Response( TRNG_Draws::to_public( $draw ), 200 );
	}

	public static function get_draw( $request ) {
		$draw = TRNG_Draws::get( sanitize_text_field( $request['key'] ) );
		if ( ! $draw ) {
			return new WP_Error( 'trng_not_found', 'Draw not found.', array( 'status' => 404 ) );
		}
		if ( 'pending' === $draw['status'] && time() >= TRNG_Drand::time_of_round( (int) $draw['target_round'] ) ) {
			$completed = TRNG_Draws::complete( $draw['draw_key'] );
			if ( ! is_wp_error( $completed ) ) {
				$draw = $completed;
			}
		}
		return new WP_REST_Response( TRNG_Draws::to_public( $draw ), 200 );
	}

	/* ------------------------------------------------------------------
	   API KEY MANAGEMENT (user profile, admins only)
	------------------------------------------------------------------ */

	public static function key_ui( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$has = (bool) get_user_meta( $user->ID, self::KEY_META, true );
		$new = get_transient( 'trng_new_api_key_' . $user->ID );
		delete_transient( 'trng_new_api_key_' . $user->ID );
		?>
		<h2>The-RNG — API access</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th>API key</th>
				<td>
					<?php if ( $new ) : ?>
						<p><strong>New key (copy now — it will not be shown again):</strong></p>
						<p><code style="font-size:14px;background:#fff8e5;padding:6px 10px;display:inline-block;"><?php echo esc_html( $new ); ?></code></p>
					<?php elseif ( $has ) : ?>
						<p><em>A key is active for this account (stored as a hash).</em></p>
					<?php else : ?>
						<p><em>No API key.</em></p>
					<?php endif; ?>
					<label style="display:block;margin-top:6px;"><input type="checkbox" name="trng_generate_api_key" value="1"> Generate <?php echo $has ? 'a NEW' : 'an'; ?> API key on save<?php echo $has ? ' (replaces the current one)' : ''; ?></label>
					<?php if ( $has ) : ?>
						<label style="display:block;margin-top:4px;"><input type="checkbox" name="trng_revoke_api_key" value="1"> Revoke the current key</label>
					<?php endif; ?>
					<p class="description">Used for server-to-server draws (header <code>X-TRNG-Key</code>). Draws made with this key are attributed to this operator in the public ledger.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_key_ui( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core profile nonce already verified.
		if ( ! empty( $_POST['trng_revoke_api_key'] ) ) {
			delete_user_meta( $user_id, self::KEY_META );
		}
		if ( ! empty( $_POST['trng_generate_api_key'] ) ) {
			$key = 'trng_' . bin2hex( random_bytes( 20 ) );
			update_user_meta( $user_id, self::KEY_META, hash( 'sha256', $key ) );
			set_transient( 'trng_new_api_key_' . $user_id, $key, 120 );
		}
		// phpcs:enable
	}
}
