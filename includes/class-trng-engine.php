<?php
/**
 * Deterministic draw engine.
 *
 * Given identical inputs this engine always produces identical output, so
 * anyone can reproduce a draw independently. The algorithm is intentionally
 * compatible with widely published verifiable-draw tooling:
 *
 *   1. data         = "{clientSeed}:{roundId}:{staticSalt}:{ticketsSold}:{maxTickets}"
 *   2. combinedHash = HMAC-SHA256( message: data, key: serverSeed )
 *      where serverSeed is the drand round's randomness (hex string used
 *      as the ASCII key).
 *   3. Walk the 32-byte hash 4 bytes at a time. Each 4 bytes are read as an
 *      unsigned 32-bit little-endian integer.
 *   4. Rejection sampling: limit = 0xFFFFFFFF - (0xFFFFFFFF % maxTickets).
 *      Accept the first value < limit; winning ticket = (value % maxTickets) + 1.
 *      Rejection sampling guarantees uniform probability for every ticket
 *      (no modulo bias).
 *
 * Additional winners (optional) continue the identical walk; duplicate
 * tickets are skipped. If a hash block is exhausted the walk continues on
 * extension blocks: HMAC-SHA256( data . ":extend:" . n, serverSeed ).
 * Winner #1 of any draw is therefore exactly the value produced by the
 * standard single-winner verification scripts.
 *
 * This class is dependency-free and pure: no database, no network, no
 * WordPress functions — so it can be executed anywhere for verification.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Engine {

	const UINT32_MAX = 0xFFFFFFFF; // 4294967295

	/**
	 * Build the HMAC message string.
	 *
	 * @param string $client_seed  Draw timestamp in milliseconds (string).
	 * @param string $round_uuid   Unique draw UUID.
	 * @param string $static_salt  Published static salt.
	 * @param int    $tickets_sold Tickets sold.
	 * @param int    $max_tickets  Maximum tickets (draw range).
	 * @return string
	 */
	public static function build_data( $client_seed, $round_uuid, $static_salt, $tickets_sold, $max_tickets ) {
		return $client_seed . ':' . $round_uuid . ':' . $static_salt . ':' . (int) $tickets_sold . ':' . (int) $max_tickets;
	}

	/**
	 * Run the draw.
	 *
	 * @param string $server_seed  Drand randomness (64-char hex).
	 * @param string $client_seed  Millisecond timestamp string.
	 * @param string $round_uuid   Draw UUID.
	 * @param string $static_salt  Static salt.
	 * @param int    $tickets_sold Tickets sold (part of the hash input).
	 * @param int    $max_tickets  Draw range: winning numbers are 1..max_tickets.
	 * @param int    $num_winners  Number of distinct winners to draw.
	 * @return array{combined_hash:string,winners:int[],steps:array}
	 * @throws InvalidArgumentException On invalid parameters.
	 */
	public static function draw( $server_seed, $client_seed, $round_uuid, $static_salt, $tickets_sold, $max_tickets, $num_winners = 1 ) {
		$max_tickets = (int) $max_tickets;
		$num_winners = (int) $num_winners;

		if ( $max_tickets < 1 ) {
			throw new InvalidArgumentException( 'max_tickets must be at least 1.' );
		}
		if ( $num_winners < 1 || $num_winners > $max_tickets ) {
			throw new InvalidArgumentException( 'num_winners must be between 1 and max_tickets.' );
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/', strtolower( (string) $server_seed ) ) ) {
			throw new InvalidArgumentException( 'server_seed must be 64 hex characters.' );
		}

		$server_seed   = strtolower( (string) $server_seed );
		$data          = self::build_data( $client_seed, $round_uuid, $static_salt, $tickets_sold, $max_tickets );
		$combined_hash = hash_hmac( 'sha256', $data, $server_seed );
		$limit         = self::UINT32_MAX - ( self::UINT32_MAX % $max_tickets );

		$winners = array();
		$steps   = array();

		$block_index = 0;
		$block_hex   = $combined_hash;
		$offset      = 0;
		$guard       = 0;

		while ( count( $winners ) < $num_winners ) {
			if ( ++$guard > 100000 ) {
				throw new RuntimeException( 'Draw walk exceeded safety guard.' );
			}

			$block_bin = hex2bin( $block_hex );

			if ( $offset + 4 > strlen( $block_bin ) ) {
				// Extend deterministically with a new HMAC block.
				$block_index++;
				$block_hex = hash_hmac( 'sha256', $data . ':extend:' . $block_index, $server_seed );
				$offset    = 0;
				continue;
			}

			// Unsigned 32-bit little-endian integer ('V' = fixed LE, matches
			// DataView.getUint32(offset, true) in JavaScript).
			$value   = unpack( 'V', substr( $block_bin, $offset, 4 ) )[1];
			$offset += 4;

			$step = array(
				'block'  => $block_index,
				'bytes'  => bin2hex( substr( $block_bin, $offset - 4, 4 ) ),
				'value'  => $value,
			);

			if ( $value >= $limit ) {
				$step['outcome'] = 'rejected (>= limit ' . $limit . ')';
				$steps[]         = $step;
				continue;
			}

			$ticket = ( $value % $max_tickets ) + 1;

			if ( in_array( $ticket, $winners, true ) ) {
				$step['outcome'] = 'duplicate ticket ' . $ticket . ' — skipped';
				$steps[]         = $step;
				continue;
			}

			$winners[]       = $ticket;
			$step['outcome'] = 'winner #' . count( $winners ) . ' = ticket ' . $ticket;
			$steps[]         = $step;
		}

		return array(
			'combined_hash' => $combined_hash,
			'winners'       => $winners,
			'steps'         => $steps,
			'limit'         => $limit,
			'data'          => $data,
		);
	}
}
