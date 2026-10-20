<?php
/**
 * Instant win audit records (v2.8.0).
 *
 * Competition sites running instant wins have to show those prizes were
 * allocated fairly and randomly, verified by an independently audited
 * statement (CAP Code rule 8.25). An operator's site (BC Competitions)
 * writes two short texts per draw:
 *
 *   seal    at the first sale: ticket count, a digest of the frozen
 *           instant prize list, and a SHA-256 commitment to the draw's
 *           shuffle key;
 *   reveal  at close: the key itself, digests of every allocation and
 *           every win, and the head of the operator's resale log.
 *
 * The-RNG records each text exactly as received, with the time it
 * arrived, in an append-only table whose records are chained with
 * SHA-256 (separate from the draw chain), and publishes each one to the
 * public GitHub ledger under audit/YYYY/MM/DD/{key}.json. The Git history
 * then fixes, outside both sites, that the seal existed before the draw's
 * sales and that the reveal's key is the one the seal committed to.
 *
 * The-RNG checks what it can on receipt: the text format, its SHA-256,
 * and for a reveal that it names a seal this operator recorded and that
 * the revealed key hashes to that seal's commitment. A reveal that fails
 * the key check is refused.
 *
 *   POST /wp-json/the-rng/v1/audit        record a seal or reveal (X-TRNG-Key)
 *   GET  /wp-json/the-rng/v1/audit/{key}  public record
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Audit {

	const ZERO     = '0000000000000000000000000000000000000000000000000000000000000000';
	const MAX_TEXT = 4096;

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'trng_audit_push_event', array( __CLASS__, 'push' ), 10, 1 );
		// The hourly ledger sweep also catches audit records that missed their push.
		add_action( 'trng_ledger_sweep_event', array( __CLASS__, 'sweep' ), 20 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'trng_audit';
	}

	/** Created by TRNG_Install::create_table() through dbDelta. */
	public static function schema( $charset ) {
		$table = self::table();
		return "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			record_key VARCHAR(24) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			kind VARCHAR(8) NOT NULL,
			site VARCHAR(190) NOT NULL DEFAULT '',
			product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			competition_title VARCHAR(200) NOT NULL DEFAULT '',
			competition_url VARCHAR(255) NOT NULL DEFAULT '',
			body TEXT NOT NULL,
			body_hash CHAR(64) NOT NULL,
			seal_key VARCHAR(24) NULL,
			prev_hash CHAR(64) NOT NULL,
			record_hash CHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			ledger_pushed TINYINT(1) NOT NULL DEFAULT 0,
			ledger_path VARCHAR(255) NULL,
			ledger_error VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY record_key (record_key),
			KEY body_hash (body_hash),
			KEY seal_key (seal_key),
			KEY user_id (user_id)
		) $charset;";
	}

	public static function routes() {
		register_rest_route(
			'the-rng/v1',
			'/audit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => array( 'TRNG_API', 'auth' ),
				'args'                => array(
					'kind'  => array( 'type' => 'string', 'required' => true, 'enum' => array( 'seal', 'reveal' ) ),
					'text'  => array( 'type' => 'string', 'required' => true ),
					'hash'  => array( 'type' => 'string', 'required' => true ),
					'title' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
					'url'   => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				),
			)
		);
		register_rest_route(
			'the-rng/v1',
			'/audit/(?P<key>[A-Za-z0-9]{6,32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_public' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ------------------------------------------------------------------
	   TEXT FORMAT (identical to the operator's)
	------------------------------------------------------------------ */

	/** "name value" lines to an array. */
	public static function fields( $text ) {
		$out = array();
		foreach ( explode( "\n", (string) $text ) as $line ) {
			$i = strpos( $line, ' ' );
			if ( $i ) {
				$out[ substr( $line, 0, $i ) ] = substr( $line, $i + 1 );
			}
		}
		return $out;
	}

	/** Base58 key, unique across draws and audit records. */
	protected static function generate_key() {
		global $wpdb;
		$alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$key = '';
			for ( $i = 0; $i < 14; $i++ ) {
				$key .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			$taken = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE record_key = %s', $key ) )
				|| $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . TRNG_Draws::table() . ' WHERE draw_key = %s', $key ) );
			if ( ! $taken ) {
				return $key;
			}
		}
		return bin2hex( random_bytes( 12 ) );
	}

	/** Canonical record hash, chained to the previous audit record. */
	public static function record_hash( $r ) {
		return hash(
			'sha256',
			implode(
				'|',
				array(
					$r['record_key'],
					$r['kind'],
					(int) $r['user_id'],
					$r['site'],
					(int) $r['product_id'],
					$r['competition_title'],
					$r['competition_url'],
					$r['body_hash'],
					(string) $r['seal_key'],
					$r['created_at'],
					$r['prev_hash'],
				)
			)
		);
	}

	/* ------------------------------------------------------------------
	   CREATE
	------------------------------------------------------------------ */

	/**
	 * Validate and record one text. Idempotent per operator: the same text
	 * sent again returns the existing record.
	 *
	 * @param array $args kind, text, hash, title, url, user_id.
	 * @return array|WP_Error Raw record.
	 */
	public static function record( $args ) {
		global $wpdb;
		$kind = (string) $args['kind'];
		$text = (string) $args['text'];
		$hash = strtolower( (string) $args['hash'] );
		$user = (int) $args['user_id'];

		if ( ! in_array( $kind, array( 'seal', 'reveal' ), true ) ) {
			return new WP_Error( 'trng_audit_kind', 'Kind must be seal or reveal.' );
		}
		if ( strlen( $text ) > self::MAX_TEXT || 0 !== strpos( $text, 'bc-audit-' . $kind . " 1\n" ) ) {
			return new WP_Error( 'trng_audit_format', 'Not a version 1 ' . $kind . ' text.' );
		}
		if ( ! hash_equals( hash( 'sha256', $text ), $hash ) ) {
			return new WP_Error( 'trng_audit_hash', 'The SHA-256 sent does not match the text.' );
		}

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE body_hash = %s AND user_id = %d LIMIT 1', $hash, $user ), ARRAY_A );
		if ( $existing ) {
			return $existing;
		}

		$f    = self::fields( $text );
		$seal = null;
		if ( 'seal' === $kind ) {
			foreach ( array( 'site', 'product', 'pool', 'capacity', 'prizes', 'key', 'sealed' ) as $need ) {
				if ( ! isset( $f[ $need ] ) ) {
					return new WP_Error( 'trng_audit_format', 'The seal has no ' . $need . ' line.' );
				}
			}
			if ( ! preg_match( '/^[0-9a-f]{64}$/', $f['key'] ) ) {
				return new WP_Error( 'trng_audit_format', 'The seal\'s key commitment is not a SHA-256.' );
			}
		} else {
			if ( empty( $f['seal'] ) || empty( $f['key'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $f['key'] ) ) {
				return new WP_Error( 'trng_audit_format', 'The reveal needs seal and key lines.' );
			}
			$seal = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE body_hash = %s AND user_id = %d AND kind = 'seal' LIMIT 1", $f['seal'], $user ), ARRAY_A );
			if ( ! $seal ) {
				return new WP_Error( 'trng_audit_no_seal', 'No seal with that SHA-256 was recorded for this operator. Record the seal first.' );
			}
			$sf = self::fields( $seal['body'] );
			if ( ! hash_equals( (string) $sf['key'], hash( 'sha256', hex2bin( $f['key'] ) ) ) ) {
				return new WP_Error( 'trng_audit_key', 'The revealed key does not hash to the commitment in its seal.' );
			}
			$f['site']    = isset( $sf['site'] ) ? $sf['site'] : '';
			$f['product'] = isset( $sf['product'] ) ? $sf['product'] : 0;
		}

		// One writer at a time, so every record links to the one before it.
		$lock = 'trng_audit_chain';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new WP_Error( 'trng_audit_busy', 'The audit ledger is busy. Try again.' );
		}
		try {
			$prev = (string) $wpdb->get_var( 'SELECT record_hash FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1' );
			$row  = array(
				'record_key'        => self::generate_key(),
				'user_id'           => $user,
				'kind'              => $kind,
				'site'              => substr( strtolower( (string) $f['site'] ), 0, 190 ),
				'product_id'        => (int) $f['product'],
				'competition_title' => substr( sanitize_text_field( (string) $args['title'] ), 0, 200 ),
				'competition_url'   => substr( esc_url_raw( (string) $args['url'] ), 0, 255 ),
				'body'              => $text,
				'body_hash'         => $hash,
				'seal_key'          => $seal ? $seal['record_key'] : null,
				'prev_hash'         => $prev ? $prev : self::ZERO,
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			);
			$row['record_hash'] = self::record_hash( $row );
			$ok = $wpdb->insert( self::table(), $row );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		if ( ! $ok ) {
			return new WP_Error( 'trng_audit_db', 'Could not store the record.' );
		}

		self::queue( $row['record_key'] );
		return self::get( $row['record_key'] );
	}

	public static function create( $request ) {
		$r = self::record(
			array(
				'kind'    => $request['kind'],
				'text'    => $request['text'],
				'hash'    => $request['hash'],
				'title'   => $request['title'],
				'url'     => $request['url'],
				'user_id' => (int) $request['_trng_user_id'],
			)
		);
		if ( is_wp_error( $r ) ) {
			$r->add_data( array( 'status' => 400 ) );
			return $r;
		}
		return new WP_REST_Response( self::to_public( $r ), 201 );
	}

	/* ------------------------------------------------------------------
	   READ
	------------------------------------------------------------------ */

	public static function get( $key ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE record_key = %s', (string) $key ), ARRAY_A );
		return $row ? $row : null;
	}

	/** The reveal recorded against a seal, if any. */
	public static function reveal_for( $seal_key ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE seal_key = %s AND kind = 'reveal' ORDER BY id LIMIT 1", (string) $seal_key ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function verify_url( $key ) {
		$page = (string) TRNG_Settings::get( 'verify_page_url' );
		return $page ? add_query_arg( 'key', rawurlencode( $key ), $page ) : rest_url( 'the-rng/v1/audit/' . rawurlencode( $key ) );
	}

	public static function ledger_url( $r ) {
		if ( empty( $r['ledger_path'] ) || ! TRNG_GitHub::repo() ) {
			return '';
		}
		return 'https://github.com/' . TRNG_GitHub::repo() . '/blob/' . rawurlencode( TRNG_GitHub::branch() ) . '/' . $r['ledger_path'];
	}

	public static function to_public( $r ) {
		$out = array(
			'record_key'      => $r['record_key'],
			'kind'            => $r['kind'],
			'site'            => $r['site'],
			'product_id'      => (int) $r['product_id'],
			'draw_title'      => $r['competition_title'],
			'competition_url' => $r['competition_url'],
			'text'            => $r['body'],
			'text_sha256'     => $r['body_hash'],
			'seal_record'     => $r['seal_key'],
			'received_at'     => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $r['created_at'] . ' UTC' ) ),
			'prev_hash'       => $r['prev_hash'],
			'record_hash'     => $r['record_hash'],
			'verify_url'      => self::verify_url( $r['record_key'] ),
			'ledger_url'      => self::ledger_url( $r ),
		);
		if ( 'seal' === $r['kind'] ) {
			$rev = self::reveal_for( $r['record_key'] );
			$out['reveal_record'] = $rev ? $rev['record_key'] : null;
		}
		return $out;
	}

	public static function get_public( $request ) {
		$r = self::get( sanitize_text_field( $request['key'] ) );
		if ( ! $r ) {
			return new WP_Error( 'trng_not_found', 'Audit record not found.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::to_public( $r ), 200 );
	}

	/* ------------------------------------------------------------------
	   GITHUB LEDGER
	------------------------------------------------------------------ */

	public static function queue( $key ) {
		if ( ! TRNG_GitHub::enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( 'trng_audit_push_event', array( $key ) ) ) {
			wp_schedule_single_event( time(), 'trng_audit_push_event', array( $key ) );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	public static function payload( $r ) {
		$payload = array(
			'record_id'       => $r['record_key'],
			'record_type'     => 'instant-win-audit-' . $r['kind'],
			'draw_title'      => $r['competition_title'],
			'competition_url' => $r['competition_url'],
			'operator'        => TRNG_GitHub::operator_block( $r ),
			'site'            => $r['site'],
			'product_id'      => (int) $r['product_id'],
			'text'            => $r['body'],
			'text_sha256'     => $r['body_hash'],
		);
		if ( 'reveal' === $r['kind'] ) {
			$payload['seal_record']       = $r['seal_key'];
			$payload['key_matches_seal']  = true; // Refused on receipt otherwise.
		}
		$payload['received_at'] = gmdate( 'Y-m-d\TH:i:s', strtotime( $r['created_at'] . ' UTC' ) ) . '.000000Z';
		$payload['prev_hash']   = $r['prev_hash'];
		$payload['record_hash'] = $r['record_hash'];
		return $payload;
	}

	/** Commit one record to the ledger repository (idempotent). */
	public static function push( $key ) {
		global $wpdb;
		$r = self::get( $key );
		if ( ! $r ) {
			return new WP_Error( 'trng_not_found', 'Audit record not found.' );
		}
		$path   = 'audit/' . gmdate( 'Y/m/d', strtotime( $r['created_at'] . ' UTC' ) ) . '/' . $r['record_key'] . '.json';
		$result = TRNG_GitHub::put_json( $path, self::payload( $r ), 'Audit ' . $r['kind'] . ' ' . $r['record_key'] . ': ' . $r['competition_title'] );
		if ( is_wp_error( $result ) ) {
			$wpdb->update( self::table(), array( 'ledger_error' => substr( $result->get_error_message(), 0, 250 ) ), array( 'record_key' => $key ) );
			return $result;
		}
		$wpdb->update( self::table(), array( 'ledger_pushed' => 1, 'ledger_path' => $path, 'ledger_error' => null ), array( 'record_key' => $key ) );
		return true;
	}

	public static function sweep() {
		global $wpdb;
		if ( ! TRNG_GitHub::enabled() ) {
			return;
		}
		foreach ( (array) $wpdb->get_col( 'SELECT record_key FROM ' . self::table() . ' WHERE ledger_pushed = 0 ORDER BY id LIMIT 20' ) as $key ) {
			self::push( $key );
		}
	}

	/** Whole-chain check for the admin screen. */
	public static function verify_chain() {
		global $wpdb;
		$prev = self::ZERO; $checked = 0; $failures = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id', ARRAY_A ) as $r ) {
			$checked++;
			if ( $r['prev_hash'] !== $prev || ! hash_equals( self::record_hash( $r ), (string) $r['record_hash'] ) || ! hash_equals( hash( 'sha256', $r['body'] ), (string) $r['body_hash'] ) ) {
				$failures[] = $r['record_key'];
			}
			$prev = $r['record_hash'];
		}
		return array( 'checked' => $checked, 'failures' => $failures );
	}

	/* ------------------------------------------------------------------
	   PUBLIC VERIFY VIEW ([trng_verify] with ?key= of an audit record)
	------------------------------------------------------------------ */

	public static function render( $r ) {
		$pub    = self::to_public( $r );
		$f      = self::fields( $r['body'] );
		$seal   = 'reveal' === $r['kind'] ? self::get( $r['seal_key'] ) : $r;
		$reveal = 'seal' === $r['kind'] ? self::reveal_for( $r['record_key'] ) : $r;
		$sf     = $seal ? self::fields( $seal['body'] ) : array();
		$tz     = (string) TRNG_Settings::get( 'display_timezone' );
		$when   = static function( $mysql ) use ( $tz ) {
			try {
				$d = new DateTime( $mysql . ' UTC' );
				$d->setTimezone( new DateTimeZone( $tz ? $tz : 'UTC' ) );
				return $d->format( 'j M Y, H:i:s T' );
			} catch ( Exception $e ) {
				return $mysql . ' UTC';
			}
		};
		$prize_parts = isset( $sf['prizes'] ) ? explode( ' ', $sf['prizes'] ) : array( '', '' );
		ob_start();
		?>
		<div class="trng-wrapper trng-wrapper-wide trng-audit">
			<div class="trng-card">
				<h2 class="trng-title">Instant Win Audit <span class="trng-chip <?php echo 'seal' === $r['kind'] ? 'trng-chip-orange' : 'trng-chip-green'; ?>"><?php echo 'seal' === $r['kind'] ? 'Seal' : 'Reveal'; ?></span></h2>
				<p class="trng-subtitle">
					<?php if ( 'seal' === $r['kind'] ) : ?>
						Recorded when this draw made its first sale. It fixes the number of tickets, the full instant prize list and a commitment to the secret key that decides which ticket each sale receives. The key itself stays secret until the draw closes.
					<?php else : ?>
						Recorded when this draw closed. It reveals the key the seal committed to, and fingerprints every allocation and every instant win, so the operator's full audit pack can be checked against it.
					<?php endif; ?>
				</p>
				<?php if ( $r['competition_title'] ) : ?>
					<h3 class="trng-dd-comp"><?php echo esc_html( $r['competition_title'] ); ?></h3>
				<?php endif; ?>
				<?php if ( $r['competition_url'] ) : ?>
					<p class="trng-small"><a href="<?php echo esc_url( $r['competition_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r['competition_url'] ); ?></a></p>
				<?php endif; ?>
				<table class="trng-table">
					<tbody>
						<tr><th>Operator</th><td><?php $op = TRNG_GitHub::operator_block( $r ); echo esc_html( trim( $op['name'] . ( $op['company_number'] ? ' (company ' . $op['company_number'] . ')' : '' ) ) ); ?></td></tr>
						<tr><th>Site</th><td class="trng-mono"><?php echo esc_html( $r['site'] ); ?></td></tr>
						<tr><th>Draw</th><td class="trng-mono">Product <?php echo (int) $r['product_id']; ?><?php echo isset( $sf['capacity'] ) ? ', ' . esc_html( number_format_i18n( (int) $sf['capacity'] ) ) . ' tickets' : ''; ?><?php echo $prize_parts[0] !== '' ? ', ' . esc_html( number_format_i18n( (int) $prize_parts[0] ) ) . ' instant prizes' : ''; ?></td></tr>
						<tr>
							<th>Sealed</th>
							<td><?php if ( $seal ) : ?><?php echo esc_html( $when( $seal['created_at'] ) ); ?> <?php if ( 'reveal' === $r['kind'] ) : ?>&middot; <a href="<?php echo esc_url( self::verify_url( $seal['record_key'] ) ); ?>">seal record</a><?php endif; ?><?php endif; ?></td>
						</tr>
						<tr>
							<th>Revealed</th>
							<td><?php if ( $reveal ) : ?><?php echo esc_html( $when( $reveal['created_at'] ) ); ?> <?php if ( 'seal' === $r['kind'] ) : ?>&middot; <a href="<?php echo esc_url( self::verify_url( $reveal['record_key'] ) ); ?>">reveal record</a><?php endif; ?><?php else : ?><span class="trng-muted">Not yet: the draw is still open, so its key is still secret.</span><?php endif; ?></td>
						</tr>
						<?php if ( $pub['ledger_url'] ) : ?>
							<tr><th>Public ledger</th><td><a href="<?php echo esc_url( $pub['ledger_url'] ); ?>" target="_blank" rel="noopener noreferrer">GitHub record</a> <span class="trng-muted">(its commit history shows when it was published)</span></td></tr>
						<?php endif; ?>
						<tr><th>Record hash</th><td class="trng-mono" style="word-break:break-all"><?php echo esc_html( $r['record_hash'] ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<div class="trng-card">
				<h3>Checks in your browser</h3>
				<ul class="trng-audit-checks" data-text="<?php echo esc_attr( $r['body'] ); ?>" data-hash="<?php echo esc_attr( $r['body_hash'] ); ?>" data-key="<?php echo esc_attr( 'reveal' === $r['kind'] && isset( $f['key'] ) ? $f['key'] : '' ); ?>" data-commit="<?php echo esc_attr( isset( $sf['key'] ) ? $sf['key'] : '' ); ?>">
					<li data-check="hash">Text below hashes to the recorded SHA-256 &hellip;</li>
					<?php if ( 'reveal' === $r['kind'] ) : ?><li data-check="key">Revealed key hashes to the commitment in the seal &hellip;</li><?php endif; ?>
				</ul>
				<p class="trng-muted trng-small">To check the whole draw (every ticket recomputed from the key, every instant win against the sealed prize list), ask the operator for the draw's audit pack and open it in their verifier. Its seal and reveal must match the records here.</p>
			</div>

			<div class="trng-card">
				<h3>Recorded text</h3>
				<p class="trng-muted trng-small">Exactly as received. SHA-256: <span class="trng-mono" style="word-break:break-all"><?php echo esc_html( $r['body_hash'] ); ?></span></p>
				<pre class="trng-mono" style="white-space:pre-wrap;word-break:break-all;margin:0"><?php echo esc_html( $r['body'] ); ?></pre>
			</div>
		</div>
		<script>
		(function () {
			var ul = document.querySelector('.trng-audit-checks');
			if (!ul || !window.crypto || !crypto.subtle) { return; }
			function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
			function bytes(h) { var a = new Uint8Array(h.length / 2); for (var i = 0; i < a.length; i++) { a[i] = parseInt(h.substr(i * 2, 2), 16); } return a; }
			function mark(name, ok, text) { var li = ul.querySelector('[data-check="' + name + '"]'); if (li) { li.innerHTML = (ok ? '&#10003; ' : '&#10007; ') + text; li.style.color = ok ? 'var(--trng-good)' : 'var(--trng-bad)'; } }
			crypto.subtle.digest('SHA-256', new TextEncoder().encode(ul.dataset.text)).then(function (d) {
				var ok = hex(d) === ul.dataset.hash; mark('hash', ok, ok ? 'The text hashes to the recorded SHA-256.' : 'The text does NOT hash to the recorded SHA-256.');
			});
			if (ul.dataset.key) {
				crypto.subtle.digest('SHA-256', bytes(ul.dataset.key)).then(function (d) {
					var ok = hex(d) === ul.dataset.commit; mark('key', ok, ok ? 'The revealed key hashes to the commitment made in the seal, before any ticket was sold.' : 'The revealed key does NOT match the seal\'s commitment.');
				});
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}
