<?php
/**
 * Draw records: creation, completion, retrieval, tamper-evident chaining.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Draws {

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . TRNG_TABLE;
	}

	/**
	 * Generate a short, unambiguous verification key (base58 alphabet).
	 *
	 * @return string
	 */
	public static function generate_key() {
		global $wpdb;
		$alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$key = '';
			for ( $i = 0; $i < 14; $i++ ) {
				$key .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE draw_key = %s', $key ) );
			if ( ! $exists ) {
				return $key;
			}
		}

		return bin2hex( random_bytes( 12 ) ); // Practically unreachable.
	}

	/**
	 * Map drawn indexes (1-based) onto an entry list of ticket numbers.
	 * Pure and dependency-free so verifiers can replicate it trivially.
	 *
	 * @param int[] $indexes 1-based indexes from the engine walk.
	 * @param int[] $list    Ascending unique ticket numbers.
	 * @return int[]|WP_Error Literal winning tickets in draw order.
	 */
	public static function map_indexes_to_tickets( $indexes, $list ) {
		$tickets = array();
		$count   = count( $list );
		foreach ( (array) $indexes as $ix ) {
			$ix = (int) $ix;
			if ( $ix < 1 || $ix > $count ) {
				return new WP_Error( 'trng_index_range', 'Drawn index ' . $ix . ' is outside the entry list (1..' . $count . ').' );
			}
			$tickets[] = (int) $list[ $ix - 1 ];
		}
		return $tickets;
	}

	/**
	 * Create a pending draw: commits to a future drand round BEFORE its
	 * randomness exists, together with the forced timestamp client seed.
	 *
	 * @param array $args {title, url, tickets_sold, max_tickets, num_winners, user_id}
	 * @return array|WP_Error Committed draw data.
	 */
	public static function create( $args ) {
		global $wpdb;

		$title   = sanitize_text_field( $args['title'] );
		$url     = esc_url_raw( $args['url'] );
		$sold    = (int) $args['tickets_sold'];
		$max     = (int) $args['max_tickets'];
		$winners = max( 1, (int) $args['num_winners'] );
		$limit   = (int) TRNG_Settings::get( 'max_tickets_limit' );

		// Optional entry list (v2.7+): the ACTUAL sold ticket numbers. When
		// given, the draw domain becomes 1..count(list) — a uniform index over
		// real entries — and the LITERAL winning ticket is what gets published
		// everywhere. $range_max records the competition's real number range
		// for display; the entry list itself is committed into the record (and
		// its hash chain) before the drand round exists.
		$list      = array();
		$range_max = 0;
		if ( ! empty( $args['ticket_numbers'] ) && is_array( $args['ticket_numbers'] ) ) {
			foreach ( $args['ticket_numbers'] as $n ) {
				$n = (int) $n;
				if ( $n > 0 ) {
					$list[ $n ] = $n; // De-duplicates as it goes.
				}
			}
			$list = array_values( $list );
			sort( $list, SORT_NUMERIC );
			if ( empty( $list ) ) {
				return new WP_Error( 'trng_invalid', 'ticket_numbers contained no valid ticket numbers.' );
			}
			if ( count( $list ) > $limit ) {
				return new WP_Error( 'trng_invalid', sprintf( 'Entry list exceeds the %d ticket limit.', $limit ) );
			}
			$range_max = max( isset( $args['range_max'] ) ? (int) $args['range_max'] : 0, (int) end( $list ) );
			$sold      = count( $list ); // Engine domain: 1..count(list).
			$max       = count( $list );
		}

		if ( '' === $title ) {
			return new WP_Error( 'trng_invalid', 'Competition title is required.' );
		}
		if ( $max < 1 || $max > $limit ) {
			return new WP_Error( 'trng_invalid', sprintf( 'Max tickets must be between 1 and %d.', $limit ) );
		}
		if ( $sold < 0 || $sold > $max ) {
			return new WP_Error( 'trng_invalid', 'Tickets sold must be between 0 and max tickets.' );
		}
		if ( $winners > min( (int) TRNG_Settings::get( 'max_winners' ), $max ) ) {
			return new WP_Error( 'trng_invalid', 'Too many winners requested.' );
		}

		// Commit target: live round + offset (default +3 ≈ 9 seconds).
		$offset = max( 1, (int) TRNG_Settings::get( 'round_offset' ) );
		$latest = TRNG_Drand::latest_round();
		$target = $latest + $offset;

		// Forced client seed: precise server timestamp in milliseconds.
		$client_seed = (string) round( microtime( true ) * 1000 );

		$row = array(
			'draw_key'          => self::generate_key(),
			'user_id'           => (int) $args['user_id'],
			'status'            => 'pending',
			'competition_title' => $title,
			'competition_url'   => $url,
			'tickets_sold'      => $sold,
			'max_tickets'       => $max,
			'num_winners'       => $winners,
			'client_seed'       => $client_seed,
			'round_uuid'        => wp_generate_uuid4(),
			'static_salt'       => (string) TRNG_Settings::get( 'static_salt' ),
			'chain_hash'        => TRNG_CHAIN_HASH,
			'target_round'      => $target,
			'round_time'        => gmdate( 'Y-m-d H:i:s', TRNG_Drand::time_of_round( $target ) ),
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

		if ( $list ) {
			$row['ticket_numbers'] = wp_json_encode( $list );
			$row['range_max']      = $range_max;
			$formats[]             = '%s';
			$formats[]             = '%d';
		}

		$inserted = $wpdb->insert( self::table(), $row, $formats );

		if ( ! $inserted ) {
			return new WP_Error( 'trng_db', 'Could not store the draw commitment.' );
		}

		$row['id'] = (int) $wpdb->insert_id;
		return $row;
	}

	/**
	 * Complete a pending draw using the committed drand round. Idempotent
	 * and deterministic: the beacon is public and immutable, so completing
	 * a draw late (or twice) can never change the result.
	 *
	 * @param string $draw_key Verification key.
	 * @return array|WP_Error Completed record.
	 */
	public static function complete( $draw_key ) {
		global $wpdb;

		$draw = self::get( $draw_key );
		if ( ! $draw ) {
			return new WP_Error( 'trng_not_found', 'Draw not found.' );
		}
		if ( 'complete' === $draw['status'] ) {
			return $draw;
		}
		if ( 'void' === $draw['status'] ) {
			return new WP_Error( 'trng_void', 'This draw has been voided: ' . $draw['void_reason'] );
		}

		$round_time = TRNG_Drand::time_of_round( (int) $draw['target_round'] );
		if ( time() < $round_time ) {
			return new WP_Error(
				'trng_not_ready',
				'The committed drand round has not been emitted yet.',
				array( 'ready_in' => $round_time - time() )
			);
		}

		$beacon = TRNG_Drand::get_beacon( (int) $draw['target_round'] );
		if ( is_wp_error( $beacon ) ) {
			return $beacon;
		}

		try {
			$result = TRNG_Engine::draw(
				$beacon['randomness'],
				$draw['client_seed'],
				$draw['round_uuid'],
				$draw['static_salt'],
				(int) $draw['tickets_sold'],
				(int) $draw['max_tickets'],
				(int) $draw['num_winners']
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'trng_engine', $e->getMessage() );
		}

		// Entry-list draws: the engine produced 1-based indexes into the
		// committed list — the record and every display carry the LITERAL
		// winning tickets, with the raw indexes preserved for verification.
		$indexes = null;
		$final   = $result['winners'];
		$list    = ! empty( $draw['ticket_numbers'] ) ? json_decode( (string) $draw['ticket_numbers'], true ) : null;
		if ( is_array( $list ) && $list ) {
			$indexes = $result['winners'];
			$mapped  = self::map_indexes_to_tickets( $indexes, $list );
			if ( is_wp_error( $mapped ) ) {
				return $mapped; // Engine guarantees the range; defensive only.
			}
			$final = $mapped;
		}

		$result_public = array(
			'combined_hash' => $result['combined_hash'],
			'winners'       => $final,
			'indexes'       => $indexes,
		);

		$completed_at = gmdate( 'Y-m-d H:i:s' );
		$prev_hash    = self::ledger_head();
		$record_hash  = self::compute_record_hash( $draw, $beacon, $result_public, $completed_at, $prev_hash );

		// Guard against a concurrent completion of the same draw.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . '
				 SET status = %s, server_seed = %s, drand_signature = %s, combined_hash = %s,
				     results = %s, result_indexes = %s, endpoints_used = %s, prev_hash = %s, record_hash = %s, completed_at = %s
				 WHERE draw_key = %s AND status = %s',
				'complete',
				$beacon['randomness'],
				$beacon['signature'],
				$result_public['combined_hash'],
				wp_json_encode( $result_public['winners'] ),
				$indexes ? wp_json_encode( array_map( 'intval', $indexes ) ) : '',
				$beacon['endpoints_used'],
				$prev_hash,
				$record_hash,
				$completed_at,
				$draw_key,
				'pending'
			)
		);

		if ( false === $updated ) {
			return new WP_Error( 'trng_db', 'Could not store the draw result.' );
		}

		// Mirror the completed record to the public GitHub ledger (async).
		TRNG_GitHub::queue( $draw_key );

		return self::get( $draw_key );
	}

	/**
	 * Canonical record hash: covers every fact needed to reproduce the
	 * draw, chained to the previous completed record.
	 */
	public static function compute_record_hash( $draw, $beacon, $result, $completed_at, $prev_hash ) {
		$canonical = implode(
			'|',
			array(
				$draw['draw_key'],
				$draw['competition_title'],
				$draw['competition_url'],
				(int) $draw['tickets_sold'],
				(int) $draw['max_tickets'],
				(int) $draw['num_winners'],
				$draw['client_seed'],
				$draw['round_uuid'],
				$draw['static_salt'],
				$draw['chain_hash'],
				(int) $draw['target_round'],
				$beacon['randomness'],
				$beacon['signature'],
				$result['combined_hash'],
				wp_json_encode( $result['winners'] ),
				$completed_at,
				(string) $prev_hash,
			)
		);

		// Entry-list draws (v2.7+) additionally bind the exact entry list,
		// the display range and the raw drawn indexes. Conditional, so every
		// pre-2.7 record hash stays byte-identical and the chain verifies.
		if ( ! empty( $draw['ticket_numbers'] ) ) {
			$canonical .= '|' . (string) $draw['ticket_numbers']
				. '|' . (int) ( isset( $draw['range_max'] ) ? $draw['range_max'] : 0 )
				. '|' . wp_json_encode( array_map( 'intval', (array) ( isset( $result['indexes'] ) ? $result['indexes'] : array() ) ) );
		}

		return hash( 'sha256', $canonical );
	}

	/**
	 * Hash of the most recently completed record (ledger head).
	 *
	 * @return string Empty string for the first record.
	 */
	public static function ledger_head() {
		global $wpdb;
		$head = $wpdb->get_var(
			'SELECT record_hash FROM ' . self::table() . " WHERE status = 'complete' AND record_hash IS NOT NULL ORDER BY completed_at DESC, id DESC LIMIT 1"
		);
		return $head ? (string) $head : '';
	}

	/**
	 * Verify the whole hash chain. Returns list of records that fail.
	 *
	 * @return array{checked:int,failures:array}
	 */
	public static function verify_chain() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . self::table() . " WHERE status = 'complete' ORDER BY completed_at ASC, id ASC",
			ARRAY_A
		);

		$prev     = '';
		$failures = array();

		foreach ( $rows as $row ) {
			$beacon = array(
				'randomness' => $row['server_seed'],
				'signature'  => $row['drand_signature'],
			);
			$result = array(
				'combined_hash' => $row['combined_hash'],
				'winners'       => json_decode( (string) $row['results'], true ),
				'indexes'       => ( isset( $row['result_indexes'] ) && $row['result_indexes'] ) ? json_decode( (string) $row['result_indexes'], true ) : array(),
			);

			$expected = self::compute_record_hash( $row, $beacon, $result, $row['completed_at'], $row['prev_hash'] );

			if ( ! hash_equals( $expected, (string) $row['record_hash'] ) ) {
				$failures[] = array( 'draw_key' => $row['draw_key'], 'problem' => 'record hash mismatch' );
			}
			if ( (string) $row['prev_hash'] !== $prev ) {
				$failures[] = array( 'draw_key' => $row['draw_key'], 'problem' => 'broken chain link' );
			}
			$prev = (string) $row['record_hash'];
		}

		return array(
			'checked'  => count( $rows ),
			'failures' => $failures,
		);
	}

	/**
	 * Fetch one draw by key.
	 *
	 * @param string $draw_key Verification key.
	 * @return array|null
	 */
	public static function get( $draw_key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE draw_key = %s', sanitize_text_field( $draw_key ) ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Public representation of a draw (used by AJAX/JSON/verify pages).
	 *
	 * @param array $draw Raw row.
	 * @return array
	 */
	public static function to_public( $draw ) {
		return array(
			'draw_key'          => $draw['draw_key'],
			'status'            => $draw['status'],
			'void_reason'       => $draw['void_reason'],
			'competition_title' => $draw['competition_title'],
			'competition_url'   => $draw['competition_url'],
			'tickets_sold'      => (int) $draw['tickets_sold'],
			'max_tickets'       => (int) $draw['max_tickets'],
			'num_winners'       => (int) $draw['num_winners'],
			'client_seed'       => $draw['client_seed'],
			'round_uuid'        => $draw['round_uuid'],
			'static_salt'       => $draw['static_salt'],
			'chain_hash'        => $draw['chain_hash'],
			'target_round'      => (int) $draw['target_round'],
			'round_time_utc'    => $draw['round_time'],
			'server_seed'       => $draw['server_seed'],
			'drand_signature'   => $draw['drand_signature'],
			'combined_hash'     => $draw['combined_hash'],
			'winners'           => $draw['results'] ? json_decode( (string) $draw['results'], true ) : null,
			'winner_indexes'    => ( isset( $draw['result_indexes'] ) && $draw['result_indexes'] ) ? json_decode( (string) $draw['result_indexes'], true ) : null,
			'ticket_numbers'    => ( isset( $draw['ticket_numbers'] ) && $draw['ticket_numbers'] ) ? json_decode( (string) $draw['ticket_numbers'], true ) : null,
			'range_max'         => ( isset( $draw['range_max'] ) && $draw['range_max'] ) ? (int) $draw['range_max'] : null,
			'entry_hash'        => ( isset( $draw['ticket_numbers'] ) && $draw['ticket_numbers'] ) ? hash( 'sha256', implode( ',', array_map( 'intval', (array) json_decode( (string) $draw['ticket_numbers'], true ) ) ) ) : null,
			'prev_hash'         => $draw['prev_hash'],
			'record_hash'       => $draw['record_hash'],
			'created_at_utc'    => $draw['created_at'],
			'completed_at_utc'  => $draw['completed_at'],
			'algorithm'         => 'HMAC-SHA256(clientSeed:roundId:staticSalt:ticketsSold:maxTickets, key=serverSeed) + rejection sampling (uint32 LE, limit = 0xFFFFFFFF - (0xFFFFFFFF % maxTickets)), winner = (value % maxTickets) + 1'
				. ( ( isset( $draw['ticket_numbers'] ) && $draw['ticket_numbers'] ) ? ' | entry-list draw: maxTickets = count(ticket_numbers); winning ticket = ticket_numbers[winner - 1] (raw walk output in winner_indexes)' : '' ),
		);
	}

	/**
	 * Paginated list of draws.
	 *
	 * @param array $args {status, user_id, per_page, page}
	 * @return array{rows:array,total:int}
	 */
	public static function list( $args = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where_sql;
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$list_sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where_sql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Void a draw (audit-safe: the record is flagged, never deleted).
	 *
	 * @param string $draw_key Verification key.
	 * @param string $reason   Reason shown publicly.
	 * @return bool
	 */
	public static function void( $draw_key, $reason ) {
		global $wpdb;
		$ok = false !== $wpdb->update(
			self::table(),
			array(
				'status'      => 'void',
				'void_reason' => sanitize_text_field( $reason ),
			),
			array( 'draw_key' => sanitize_text_field( $draw_key ) ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		// Update the GitHub ledger record so its status reflects the void.
		if ( $ok ) {
			$draw = self::get( $draw_key );
			if ( $draw && ! empty( $draw['completed_at'] ) && ! empty( $draw['ledger_pushed'] ) ) {
				$wpdb->update( self::table(), array( 'ledger_pushed' => 0 ), array( 'draw_key' => $draw_key ), array( '%d' ), array( '%s' ) );
				TRNG_GitHub::queue( $draw_key );
			}
		}

		return $ok;
	}
}
