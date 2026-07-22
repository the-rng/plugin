<?php
/**
 * GitHub public ledger integration.
 *
 * Automatically commits every completed draw to a public GitHub
 * repository as /YYYY/MM/DD/{round-uuid}.json via the GitHub Contents
 * API, providing independent persistence: the records exist outside
 * this site's database and their Git history is a timestamped,
 * tamper-evident log.
 *
 * The JSON schema intentionally mirrors the widely used public-ledger
 * format for drand-based draws (round_id, operator block,
 * drand_server_seed, drand_seed_hash = the BLS signature, client_seed,
 * static_salt, ticket parameters, combined_hash, result,
 * external_round_id, processing_status, created_at / revealed_at).
 *
 * Pushes run asynchronously (WP-Cron single events) so draw resolution
 * is never delayed by GitHub; an hourly sweep plus a manual admin
 * button catch anything missed.
 *
 * The token is never printed back to the page. For best security,
 * define TRNG_GITHUB_TOKEN in wp-config.php instead of storing it in
 * the database.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_GitHub {

	const API = 'https://api.github.com';

	public static function register() {
		add_action( 'trng_ledger_push_event', array( __CLASS__, 'push' ), 10, 1 );
		add_action( 'trng_ledger_sweep_event', array( __CLASS__, 'sweep' ) );

		// Hourly safety-net sweep while enabled.
		if ( self::enabled() && ! wp_next_scheduled( 'trng_ledger_sweep_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'trng_ledger_sweep_event' );
		}

		// Per-user operator details (multi-operator support).
		add_action( 'show_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_fields' ) );
	}

	/* ------------------------------------------------------------------
	   CONFIG
	------------------------------------------------------------------ */

	public static function token() {
		if ( defined( 'TRNG_GITHUB_TOKEN' ) && TRNG_GITHUB_TOKEN ) {
			return (string) TRNG_GITHUB_TOKEN;
		}
		return (string) TRNG_Settings::get( 'ledger_token' );
	}

	public static function repo() {
		$repo = trim( (string) TRNG_Settings::get( 'ledger_repo' ), " /\t" );
		return preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ? $repo : '';
	}

	public static function branch() {
		$branch = (string) TRNG_Settings::get( 'ledger_branch' );
		return $branch ? $branch : 'main';
	}

	public static function enabled() {
		return TRNG_Settings::get( 'ledger_enabled' ) && self::repo() && self::token();
	}

	/* ------------------------------------------------------------------
	   QUEUEING
	------------------------------------------------------------------ */

	/**
	 * Queue an async push for a draw (called after completion / void).
	 *
	 * @param string $draw_key Verification key.
	 */
	public static function queue( $draw_key ) {
		if ( ! self::enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( 'trng_ledger_push_event', array( $draw_key ) ) ) {
			wp_schedule_single_event( time(), 'trng_ledger_push_event', array( $draw_key ) );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Push every completed draw not yet on GitHub (max 10 per run).
	 *
	 * @return array{pushed:int,failed:int}
	 */
	public static function sweep() {
		global $wpdb;

		if ( ! self::enabled() ) {
			return array( 'pushed' => 0, 'failed' => 0 );
		}

		$keys = $wpdb->get_col(
			"SELECT draw_key FROM " . TRNG_Draws::table() . "
			 WHERE status IN ('complete','void') AND completed_at IS NOT NULL AND ledger_pushed = 0
			 ORDER BY completed_at ASC LIMIT 10"
		);

		$pushed = 0;
		$failed = 0;
		foreach ( (array) $keys as $key ) {
			$result = self::push( $key );
			if ( is_wp_error( $result ) ) {
				$failed++;
			} else {
				$pushed++;
			}
		}

		return array( 'pushed' => $pushed, 'failed' => $failed );
	}

	/* ------------------------------------------------------------------
	   PAYLOAD (exact public-ledger schema)
	------------------------------------------------------------------ */

	/**
	 * Operator block for a draw: per-user profile fields with global
	 * settings as fallback.
	 *
	 * @param array $draw Raw draw row.
	 * @return array
	 */
	protected static function operator_for( $draw ) {
		$user_id = (int) $draw['user_id'];

		$name    = $user_id ? (string) get_user_meta( $user_id, 'trng_operator_name', true ) : '';
		$website = $user_id ? (string) get_user_meta( $user_id, 'trng_operator_website', true ) : '';
		$number  = $user_id ? (string) get_user_meta( $user_id, 'trng_operator_company_number', true ) : '';

		if ( '' === $name ) {
			$name = (string) TRNG_Settings::get( 'operator_name' );
		}
		if ( '' === $name ) {
			$name = (string) TRNG_Settings::get( 'brand_name' );
		}
		if ( '' === $website ) {
			$website = (string) TRNG_Settings::get( 'operator_website' );
		}
		if ( '' === $website ) {
			$website = home_url( '/' );
		}
		if ( '' === $number ) {
			$number = (string) TRNG_Settings::get( 'operator_company_number' );
		}

		return array(
			'name'           => $name,
			'website'        => $website,
			'company_number' => $number,
		);
	}

	/**
	 * ISO 8601 with microseconds and Z suffix (e.g. 2026-07-15T19:10:42.000000Z).
	 *
	 * @param string $mysql_utc MySQL datetime already in UTC.
	 * @return string
	 */
	protected static function iso( $mysql_utc ) {
		$ts = strtotime( $mysql_utc . ' UTC' );
		return gmdate( 'Y-m-d\TH:i:s', $ts ) . '.000000Z';
	}

	/**
	 * Build the ledger JSON payload for a draw. Key order matters and is
	 * preserved exactly.
	 *
	 * @param array $draw Raw draw row.
	 * @return array
	 */
	public static function build_payload( $draw ) {
		$winners = $draw['results'] ? (array) json_decode( (string) $draw['results'], true ) : array();

		$payload = array(
			'round_id'          => (string) $draw['round_uuid'],
			'draw_title'        => (string) $draw['competition_title'],
			'competition_url'   => (string) $draw['competition_url'],
			'operator'          => self::operator_for( $draw ),
			'drand_server_seed' => (string) $draw['server_seed'],
			'drand_seed_hash'   => (string) $draw['drand_signature'],
			'client_seed'       => (string) $draw['client_seed'],
			'static_salt'       => (string) $draw['static_salt'],
			'tickets_sold'      => (int) $draw['tickets_sold'],
			'max_tickets'       => (int) $draw['max_tickets'],
			'combined_hash'     => (string) $draw['combined_hash'],
			'result'            => implode( ', ', array_map( 'strval', $winners ) ),
			'external_round_id' => (string) (int) $draw['target_round'],
			'processing_status' => ( 'void' === $draw['status'] ) ? 'voided' : 'completed',
			'created_at'        => self::iso( $draw['created_at'] ),
			'revealed_at'       => self::iso( $draw['completed_at'] ),
		);

		// Entry-list draws (v2.7+): the ledger additionally publishes the
		// real number range, the committed entry-list hash, the raw drawn
		// indexes, and (size permitting) the full entry list itself — so the
		// literal winning ticket in `result` is reproducible end to end.
		if ( ! empty( $draw['ticket_numbers'] ) ) {
			$list = array_map( 'intval', (array) json_decode( (string) $draw['ticket_numbers'], true ) );

			$payload['ticket_range_max'] = (int) $draw['range_max'];
			$payload['entry_hash']       = hash( 'sha256', implode( ',', $list ) );
			$payload['result_indexes']   = implode( ', ', array_map( 'strval', (array) json_decode( (string) ( isset( $draw['result_indexes'] ) ? $draw['result_indexes'] : '' ), true ) ) );

			if ( count( $list ) <= (int) apply_filters( 'trng_ledger_entry_list_limit', 50000 ) ) {
				$payload['ticket_numbers'] = $list;
			}
		}

		return $payload;
	}

	/**
	 * Repository path: YYYY/MM/DD/{round-uuid}.json (UTC reveal date).
	 *
	 * @param array $draw Raw draw row.
	 * @return string
	 */
	public static function path_for( $draw ) {
		$ts = strtotime( $draw['completed_at'] . ' UTC' );
		return gmdate( 'Y/m/d', $ts ) . '/' . $draw['round_uuid'] . '.json';
	}

	/* ------------------------------------------------------------------
	   PUSH
	------------------------------------------------------------------ */

	/**
	 * Commit one draw's JSON to the repository. Idempotent: if the file
	 * already exists it is updated in place (e.g. void status updates).
	 *
	 * @param string $draw_key Verification key.
	 * @return true|WP_Error
	 */
	public static function push( $draw_key ) {
		global $wpdb;

		if ( ! self::enabled() ) {
			return new WP_Error( 'trng_gh_disabled', 'GitHub ledger is not configured.' );
		}

		$draw = TRNG_Draws::get( $draw_key );
		if ( ! $draw || empty( $draw['completed_at'] ) || empty( $draw['server_seed'] ) ) {
			return new WP_Error( 'trng_gh_not_ready', 'Draw is not completed yet.' );
		}

		$path    = self::path_for( $draw );
		$payload = self::build_payload( $draw );
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$url     = self::API . '/repos/' . self::repo() . '/contents/' . $path;

		$body = array(
			'message' => 'Draw ' . $payload['round_id'] . ' — ' . $payload['draw_title'],
			'content' => base64_encode( $json ), // phpcs:ignore
			'branch'  => self::branch(),
		);

		// If the file already exists we must supply its blob sha (update).
		$existing = self::request( 'GET', $url . '?ref=' . rawurlencode( self::branch() ) );
		if ( ! is_wp_error( $existing ) && isset( $existing['sha'] ) ) {
			$body['sha'] = $existing['sha'];
		}

		$response = self::request( 'PUT', $url, $body );

		if ( is_wp_error( $response ) ) {
			$wpdb->update(
				TRNG_Draws::table(),
				array( 'ledger_error' => substr( $response->get_error_message(), 0, 250 ) ),
				array( 'draw_key' => $draw_key ),
				array( '%s' ),
				array( '%s' )
			);
			return $response;
		}

		$wpdb->update(
			TRNG_Draws::table(),
			array(
				'ledger_pushed' => 1,
				'ledger_path'   => $path,
				'ledger_error'  => null,
			),
			array( 'draw_key' => $draw_key ),
			array( '%d', '%s', '%s' ),
			array( '%s' )
		);

		return true;
	}

	/**
	 * Authenticated GitHub API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    Full URL.
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error Decoded JSON.
	 */
	protected static function request( $method, $url, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization'        => 'Bearer ' . self::token(),
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'unknown error';
		return new WP_Error( 'trng_gh_http_' . $code, 'GitHub API ' . $code . ': ' . $message );
	}

	/**
	 * Connection test: repo reachable and token has push permission.
	 *
	 * @return array|WP_Error {repo, push:bool}
	 */
	public static function test_connection() {
		if ( ! self::repo() || ! self::token() ) {
			return new WP_Error( 'trng_gh_config', 'Repository and token are required first.' );
		}
		$info = self::request( 'GET', self::API . '/repos/' . self::repo() );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		return array(
			'repo'    => isset( $info['full_name'] ) ? $info['full_name'] : self::repo(),
			'private' => ! empty( $info['private'] ),
			'push'    => ! empty( $info['permissions']['push'] ),
		);
	}

	/* ------------------------------------------------------------------
	   PER-USER OPERATOR PROFILE FIELDS
	------------------------------------------------------------------ */

	public static function user_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		?>
		<h2>The-RNG — Operator details</h2>
		<p class="description">Included in the public GitHub ledger record for every draw this user runs. Falls back to the global defaults in The-RNG &rarr; Settings.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="trng_operator_name">Operator name</label></th>
				<td><input type="text" class="regular-text" name="trng_operator_name" id="trng_operator_name" value="<?php echo esc_attr( get_user_meta( $user->ID, 'trng_operator_name', true ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="trng_operator_website">Operator website</label></th>
				<td><input type="url" class="regular-text" name="trng_operator_website" id="trng_operator_website" value="<?php echo esc_attr( get_user_meta( $user->ID, 'trng_operator_website', true ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="trng_operator_company_number">Company number</label></th>
				<td><input type="text" class="regular-text" name="trng_operator_company_number" id="trng_operator_company_number" value="<?php echo esc_attr( get_user_meta( $user->ID, 'trng_operator_company_number', true ) ); ?>"></td>
			</tr>
		</table>
		<?php
	}

	public static function save_user_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core profile nonce already verified.
		update_user_meta( $user_id, 'trng_operator_name', sanitize_text_field( isset( $_POST['trng_operator_name'] ) ? wp_unslash( $_POST['trng_operator_name'] ) : '' ) );
		update_user_meta( $user_id, 'trng_operator_website', esc_url_raw( isset( $_POST['trng_operator_website'] ) ? wp_unslash( $_POST['trng_operator_website'] ) : '' ) );
		update_user_meta( $user_id, 'trng_operator_company_number', sanitize_text_field( isset( $_POST['trng_operator_company_number'] ) ? wp_unslash( $_POST['trng_operator_company_number'] ) : '' ) );
		// phpcs:enable
	}
}
