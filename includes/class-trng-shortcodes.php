<?php
/**
 * Public shortcodes.
 *
 *  [trng_generator]     Draw tool (logged-in users; public login wall otherwise).
 *  [trng_verify]        Public verification of any draw key (?key= prefills).
 *  [trng_past_draws]    Public ledger of completed draws.
 *  [trng_user_history]  Logged-in user's own draws.
 *  [trng_provably_fair] How-it-works explainer + public parameters.
 *  [trng_drand_info]    Drand chain details with live round.
 *  [trng_how_to_verify] Manual verification guide with PHP/JS scripts.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Shortcodes {

	public static function register() {
		add_shortcode( 'trng_generator', array( __CLASS__, 'sc_generator' ) );
		add_shortcode( 'trng_verify', array( __CLASS__, 'sc_verify' ) );
		add_shortcode( 'trng_past_draws', array( __CLASS__, 'sc_past_draws' ) );
		add_shortcode( 'trng_user_history', array( __CLASS__, 'sc_user_history' ) );
		add_shortcode( 'trng_provably_fair', array( __CLASS__, 'sc_provably_fair' ) );
		add_shortcode( 'trng_drand_info', array( __CLASS__, 'sc_drand_info' ) );
		add_shortcode( 'trng_how_to_verify', array( __CLASS__, 'sc_how_to_verify' ) );
	}

	/**
	 * "Powered by" header strip used on the generator.
	 */
	protected static function badge_header() {
		$offset = (int) TRNG_Settings::get( 'round_offset' );
		$secs   = $offset * TRNG_CHAIN_PERIOD;
		ob_start();
		?>
		<div class="trng-provider">
			<span class="trng-provider-label">Independent Randomness Provider</span>
			<div class="trng-provider-card">
				<span class="trng-radio" aria-hidden="true"></span>
				<svg class="trng-cloud" viewBox="0 0 48 26" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#F6821F" d="M39 22H13.5A7.5 7.5 0 1 1 15 7.15 11 11 0 0 1 36.3 9.6 8.5 8.5 0 0 1 39 22Z"/><path fill="#FBAD41" d="M43.5 22h-6.2a6.2 6.2 0 0 0 .9-9.7 6.5 6.5 0 0 1 5.3 9.7Z"/></svg>
				<span class="trng-provider-text"><strong>Drand Quicknet</strong><span class="trng-muted">Public Beacon Live Round <span id="trng-live-round">&hellip;</span> +<?php echo (int) $offset; ?> (<?php echo (int) $secs; ?>s)</span></span>
			</div>
		</div>
		<div class="trng-badges">
			<a class="trng-badge trng-badge-link" href="https://www.cloudflare.com/leagueofentropy/" target="_blank" rel="noopener noreferrer">Powered by League of Entropy</a>
			<span class="trng-badge trng-badge-live">Verifiably Fair</span>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Login wall shown to logged-out visitors on the generator.
	 *
	 * When registration is open (WordPress "Anyone can register" or the
	 * WooCommerce My Account registration setting) it invites visitors to
	 * create an account; otherwise it falls back to enquiry-only wording.
	 */
	protected static function login_wall() {
		$email = sanitize_email( TRNG_Settings::get( 'enquiry_email' ) );
		$brand = esc_html( TRNG_Settings::get( 'brand_name' ) );

		$woo          = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_permalink' );
		$woo_reg      = $woo && 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
		$can_register = $woo_reg || get_option( 'users_can_register' );

		$login_url    = $woo ? wc_get_page_permalink( 'myaccount' ) : wp_login_url( get_permalink() );
		$register_url = $woo_reg ? wc_get_page_permalink( 'myaccount' ) : wp_registration_url();

		ob_start();
		?>
		<div class="trng-wrapper"><div class="trng-card trng-center">
			<h2 class="trng-title">Login Required</h2>
			<?php if ( $can_register ) : ?>
				<p class="trng-subtitle">You must be logged in to generate random numbers. Create an account or log in to get started.</p>
				<div class="trng-wall-buttons">
					<a class="trng-button trng-button-inline" href="<?php echo esc_url( $register_url ); ?>">Create Account</a>
					<a class="trng-button trng-button-inline trng-button-secondary" href="<?php echo esc_url( $login_url ); ?>">Log In</a>
				</div>
				<p class="trng-muted">Every account is reviewed manually before draws can be generated, and your operator details appear in the public ledger with every draw you run.</p>
			<?php else : ?>
				<p class="trng-subtitle">You must be logged in to generate random numbers with <?php echo $brand; ?>.</p>
				<p>To maintain the integrity of our service, account registration is currently by enquiry only.</p>
				<?php if ( $email ) : ?>
					<p><a class="trng-button trng-button-inline" href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></p>
				<?php endif; ?>
				<p class="trng-muted">Already have an account? <a href="<?php echo esc_url( $login_url ); ?>">Log in here</a>.</p>
			<?php endif; ?>
		</div></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Main generator.
	 */
	public static function sc_generator() {
		if ( ! is_user_logged_in() ) {
			return self::login_wall();
		}
		if ( ! TRNG_Registration::is_approved( get_current_user_id() ) ) {
			return TRNG_Registration::pending_wall();
		}

		$max_limit = (int) TRNG_Settings::get( 'max_tickets_limit' );
		$max_win   = (int) TRNG_Settings::get( 'max_winners' );

		ob_start();
		?>
		<div class="trng-wrapper">
			<div class="trng-card">
				<h2 class="trng-title">Verifiably Fair RNG</h2>
				<p class="trng-subtitle">Generate cryptographically secure random numbers for your competitions.</p>

				<?php echo self::badge_header(); // phpcs:ignore ?>

				<div class="trng-seedpanel">
					<div class="trng-seedpanel-head">
						<span>Client Seed:</span>
						<code id="trng-seed-clock" class="trng-seed-clock">&hellip;</code>
					</div>
					<div id="trng-seed-digits" class="trng-seed-digits" aria-hidden="true"></div>
					<p class="trng-muted trng-seed-note">This timestamp is provided for transparency. The client seed is assigned by the server when you click Generate.</p>
				</div>

				<form id="trng-form" class="trng-form" autocomplete="off">
					<?php wp_nonce_field( 'trng_start', 'trng_nonce' ); ?>
					<div class="trng-row">
						<label for="trng_title">Competition Title</label>
						<input type="text" id="trng_title" name="title" maxlength="200" placeholder="e.g. &pound;1,000 Cash Competition" required>
					</div>
					<div class="trng-row">
						<label for="trng_url">Competition URL <span class="trng-muted">(optional)</span></label>
						<input type="url" id="trng_url" name="url" maxlength="255" placeholder="https://">
					</div>
					<div class="trng-grid-2">
						<div class="trng-row">
							<label for="trng_sold">Tickets Sold</label>
							<input type="number" id="trng_sold" name="tickets_sold" min="0" max="<?php echo esc_attr( $max_limit ); ?>" required>
						</div>
						<div class="trng-row">
							<label for="trng_max">Max Tickets</label>
							<input type="number" id="trng_max" name="max_tickets" min="1" max="<?php echo esc_attr( $max_limit ); ?>" required>
						</div>
					</div>
					<div class="trng-row">
						<label for="trng_winners">Number of Winners</label>
						<input type="number" id="trng_winners" name="num_winners" min="1" max="<?php echo esc_attr( $max_win ); ?>" value="1" required>
					</div>
					<button type="submit" class="trng-button" id="trng-generate-btn">Generate</button>
				</form>

				<div id="trng-status" class="trng-status" aria-live="polite"></div>

				<div id="trng-commit" class="trng-commit trng-hidden">
					<h3>Commitment published</h3>
					<p>This draw is locked to <strong>Drand Round <span id="trng-c-round"></span></strong> — randomness that does not exist yet anywhere in the world. Result in <strong><span id="trng-c-countdown">–</span>s</strong>.</p>
					<div class="trng-kv"><span>Verification key</span><code id="trng-c-key"></code></div>
					<div class="trng-kv"><span>Client seed (timestamp)</span><code id="trng-c-clientseed"></code></div>
					<div class="trng-kv"><span>Round ID (UUID)</span><code id="trng-c-uuid"></code></div>
				</div>

				<div id="trng-animation" class="trng-animation">—</div>

				<div id="trng-result" class="trng-result trng-hidden">
					<h3 class="trng-result-heading">Winning <span id="trng-r-noun">Number</span></h3>
					<div id="trng-r-winners" class="trng-winners"></div>

					<h3>Verifiably Fair Data</h3>
					<div class="trng-result-grid">
						<div><div class="trng-label">Verification key</div><div id="trng-r-key" class="trng-value trng-mono"></div></div>
						<div><div class="trng-label">Drand round</div><div id="trng-r-round" class="trng-value"></div></div>
						<div><div class="trng-label">Server seed (Drand randomness)</div><div id="trng-r-serverseed" class="trng-value trng-mono trng-wrap"></div></div>
						<div><div class="trng-label">Client seed (timestamp)</div><div id="trng-r-clientseed" class="trng-value trng-mono"></div></div>
						<div><div class="trng-label">Round ID (UUID)</div><div id="trng-r-uuid" class="trng-value trng-mono"></div></div>
						<div><div class="trng-label">Static salt</div><div id="trng-r-salt" class="trng-value trng-mono"></div></div>
						<div><div class="trng-label">Combined hash (HMAC-SHA256)</div><div id="trng-r-combined" class="trng-value trng-mono trng-wrap"></div></div>
						<div><div class="trng-label">Beacon signature</div><div id="trng-r-signature" class="trng-value trng-mono trng-wrap"></div></div>
						<div><div class="trng-label">Completed (UTC)</div><div id="trng-r-completed" class="trng-value"></div></div>
					</div>
					<p id="trng-r-links" class="trng-links"></p>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Public verification page.
	 */
	public static function sc_verify() {
		$prefill = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		ob_start();
		?>
		<div class="trng-wrapper">
			<div class="trng-card">
				<h2 class="trng-title">Verify a Draw</h2>
				<p class="trng-subtitle">Enter a verification key to load the full draw record, check it against the public Drand beacon, and recompute the result in your own browser.</p>

				<form id="trng-verify-form" class="trng-form">
					<div class="trng-row">
						<label for="trng_verify_key">Verification key</label>
						<input type="text" id="trng_verify_key" name="key" value="<?php echo esc_attr( $prefill ); ?>" required>
					</div>
					<button type="submit" class="trng-button">Verify</button>
				</form>

				<div id="trng-verify-status" class="trng-status" aria-live="polite"></div>

				<div id="trng-verify-result" class="trng-result trng-hidden">
					<h3>Stored Draw Record</h3>
					<div class="trng-result-grid" id="trng-v-grid"></div>

					<h3>Winner<span id="trng-v-plural"></span></h3>
					<div id="trng-v-winners" class="trng-winners"></div>

					<h3>Step 1 — Check the beacon at the source</h3>
					<p>The server seed must equal the <code>randomness</code> field published by the Drand network for the committed round. Check it on independent relays (we never host these):</p>
					<p id="trng-v-beacon-links" class="trng-links"></p>

					<h3>Step 2 — Recompute the result in your browser</h3>
					<p>This runs entirely in your browser with the Web Crypto API — our server is not involved.</p>
					<button type="button" class="trng-button trng-button-secondary" id="trng-v-recompute">Recompute now</button>
					<div id="trng-v-recompute-out" class="trng-recompute"></div>

					<h3>Step 3 — Verify by hand (optional)</h3>
					<p>Use the scripts on the <em>Manual Verification</em> page with the values above to reproduce the result on any machine.</p>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Draw ledger table. NOT publicly accessible: draws are verifiable
	 * individually by key, but the full log is visible to site admins
	 * only (matching the model where the public ledger, if any, is a
	 * mirrored GitHub repository).
	 */
	public static function sc_past_draws() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$page = isset( $_GET['trng_page'] ) ? max( 1, (int) $_GET['trng_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data = TRNG_Draws::list( array( 'per_page' => 20, 'page' => $page ) );
		$total_pages = (int) ceil( $data['total'] / 20 );
		$relays = TRNG_Drand::relays();
		$primary_relay = $relays ? $relays[0] : 'https://drand.cloudflare.com';
		$ledger_url = esc_url( TRNG_Settings::get( 'github_ledger_url' ) );

		ob_start();
		?>
		<div class="trng-wrapper">
			<div class="trng-card trng-card-wide">
				<h2 class="trng-title">Past Draws — Public Ledger</h2>
				<p class="trng-subtitle">Every draw is recorded permanently and chained with SHA-256 hashes, so history cannot be silently rewritten.
				<?php if ( $ledger_url ) : ?>
					A mirrored copy is published at <a href="<?php echo $ledger_url; ?>" target="_blank" rel="noopener noreferrer">our public ledger repository</a>.
				<?php endif; ?>
				</p>

				<table class="trng-table">
					<thead><tr><th>Date (UTC)</th><th>Competition</th><th>Drand Round</th><th>Winner(s)</th><th>Status</th><th></th></tr></thead>
					<tbody>
					<?php if ( $data['rows'] ) : ?>
						<?php foreach ( $data['rows'] as $row ) :
							$winners = $row['results'] ? implode( ', ', (array) json_decode( (string) $row['results'], true ) ) : '—';
							$round_url = rtrim( $primary_relay, '/' ) . '/' . TRNG_CHAIN_HASH . '/public/' . (int) $row['target_round'];
							?>
							<tr>
								<td><?php echo esc_html( $row['completed_at'] ? $row['completed_at'] : $row['created_at'] ); ?></td>
								<td><?php echo esc_html( $row['competition_title'] ); ?></td>
								<td><a href="<?php echo esc_url( $round_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( number_format_i18n( (int) $row['target_round'] ) ); ?></a></td>
								<td><strong><?php echo esc_html( $winners ); ?></strong></td>
								<td><span class="trng-pill trng-pill-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
								<td><?php echo self::verify_link( $row['draw_key'] ); // phpcs:ignore ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="6">No draws yet.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<p class="trng-pagination">
						<?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'trng_page', $page - 1 ) ); ?>">&laquo; Newer</a><?php endif; ?>
						<span>Page <?php echo (int) $page; ?> of <?php echo (int) $total_pages; ?></span>
						<?php if ( $page < $total_pages ) : ?><a href="<?php echo esc_url( add_query_arg( 'trng_page', $page + 1 ) ); ?>">Older &raquo;</a><?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * "View & Verify" link for a key.
	 */
	protected static function verify_link( $key ) {
		$verify_page = TRNG_Settings::get( 'verify_page_url' );
		if ( $verify_page ) {
			return '<a class="trng-verify-link" href="' . esc_url( add_query_arg( 'key', rawurlencode( $key ), $verify_page ) ) . '">View &amp; Verify</a>';
		}
		return '<code>' . esc_html( $key ) . '</code>';
	}

	/**
	 * Logged-in user's own history.
	 */
	public static function sc_user_history() {
		if ( ! is_user_logged_in() ) {
			return '<p>Log in to view your draw history.</p>';
		}

		$data = TRNG_Draws::list( array( 'user_id' => get_current_user_id(), 'per_page' => 100 ) );

		ob_start();
		?>
		<table class="trng-table">
			<thead><tr><th>Key</th><th>Competition</th><th>Winner(s)</th><th>Round</th><th>Date (UTC)</th><th></th></tr></thead>
			<tbody>
			<?php if ( $data['rows'] ) : ?>
				<?php foreach ( $data['rows'] as $row ) :
					$winners = $row['results'] ? implode( ', ', (array) json_decode( (string) $row['results'], true ) ) : '—';
					?>
					<tr>
						<td><code><?php echo esc_html( $row['draw_key'] ); ?></code></td>
						<td><?php echo esc_html( $row['competition_title'] ); ?></td>
						<td><strong><?php echo esc_html( $winners ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['target_round'] ) ); ?></td>
						<td><?php echo esc_html( $row['completed_at'] ? $row['completed_at'] : $row['created_at'] ); ?></td>
						<td><?php echo self::verify_link( $row['draw_key'] ); // phpcs:ignore ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="6">No draws found.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * Provably fair explainer + public parameters.
	 */
	public static function sc_provably_fair() {
		$s      = TRNG_Settings::all();
		$relays = TRNG_Drand::relays();
		ob_start();
		?>
		<div class="trng-wrapper"><div class="trng-card trng-card-wide trng-prose">
			<h2 class="trng-title">Verifiable Fairness</h2>
			<p class="trng-subtitle">How we guarantee fairness in every draw — and how you can check it yourself.</p>

			<div class="trng-steps">
				<div class="trng-step"><span class="trng-step-num">1</span>
					<h3>Distributed Generation</h3>
					<p>The <strong>League of Entropy</strong>, a global network of independent organisations (including Cloudflare, EPFL and the University of Chile), generates randomness collectively using threshold cryptography. No single party — including us — can control or predict the output.</p>
				</div>
				<div class="trng-step"><span class="trng-step-num">2</span>
					<h3>Public Beacon Commitment</h3>
					<p>When you click Generate, the draw is committed to a <strong>future Drand round</strong> whose randomness does not exist yet. The round number is shown immediately — before the result can be known by anyone on Earth.</p>
				</div>
				<div class="trng-step"><span class="trng-step-num">3</span>
					<h3>Client Seed</h3>
					<p>We force the precise <strong>millisecond timestamp</strong> of your click as the Client Seed, combined with the beacon randomness and all draw parameters using <strong>HMAC-SHA256</strong>. The result is unique to your specific draw and cannot be pre-computed.</p>
				</div>
				<div class="trng-step"><span class="trng-step-num">4</span>
					<h3>Public Verification</h3>
					<p><strong>Rejection sampling</strong> converts the hash into a winning ticket with mathematically uniform probability (no modulo bias). Every input is revealed after the draw, and anyone can reproduce the result with open scripts — no trust in our servers required.</p>
				</div>
			</div>

			<h3>Public Parameters</h3>
			<p>These values are fixed, published, and included in every draw record:</p>
			<table class="trng-table">
				<tbody>
					<tr><th>Randomness source</th><td>Drand Quicknet beacon (League of Entropy), 3-second rounds</td></tr>
					<tr><th>Chain hash</th><td><code class="trng-wrap"><?php echo esc_html( TRNG_CHAIN_HASH ); ?></code></td></tr>
					<tr><th>Scheme</th><td><code><?php echo esc_html( TRNG_CHAIN_SCHEME ); ?></code></td></tr>
					<tr><th>Static salt</th><td><code><?php echo esc_html( $s['static_salt'] ); ?></code></td></tr>
					<tr><th>Combination</th><td><code>HMAC-SHA256("clientSeed:roundId:staticSalt:ticketsSold:maxTickets", key = serverSeed)</code></td></tr>
					<tr><th>Number derivation</th><td>Unsigned 32-bit little-endian reads, rejection sampling, winner = (value % maxTickets) + 1</td></tr>
					<tr><th>Public relays</th><td><?php echo esc_html( implode( '  ·  ', $relays ) ); ?></td></tr>
				</tbody>
			</table>

			<h3>Built on Industry Standards</h3>
			<p><strong>HMAC-SHA256</strong> — an industry-standard keyed hash used by financial institutions worldwide. <strong>Rejection sampling</strong> — eliminates modulo bias so every ticket has exactly the same probability. <strong>User-verifiable</strong> — simple JavaScript and PHP scripts let anyone reproduce any result independently. No proprietary black boxes.</p>

			<h3>Tamper-Evident Ledger</h3>
			<p>Every completed draw record is hashed with SHA-256 and chained to the previous record. Changing or removing any historical draw breaks the chain and is immediately detectable.
			<?php if ( ! empty( $s['github_ledger_url'] ) ) : ?>
				The ledger is mirrored publicly at <a href="<?php echo esc_url( $s['github_ledger_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $s['github_ledger_url'] ); ?></a>.
			<?php endif; ?>
			<?php if ( ! empty( $s['github_source_url'] ) ) : ?>
				Source code: <a href="<?php echo esc_url( $s['github_source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $s['github_source_url'] ); ?></a>.
			<?php endif; ?>
			</p>

			<p class="trng-muted">Learn more: <a href="https://www.cloudflare.com/leagueofentropy/" target="_blank" rel="noopener noreferrer">League of Entropy</a> · <a href="https://drand.love" target="_blank" rel="noopener noreferrer">drand.love</a></p>
		</div></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Drand chain info with live round.
	 */
	public static function sc_drand_info() {
		$relays = TRNG_Drand::relays();
		ob_start();
		?>
		<div class="trng-wrapper"><div class="trng-card trng-card-wide trng-prose">
			<h2 class="trng-title">Drand — The Randomness Beacon</h2>
			<p class="trng-subtitle">Drand is a distributed randomness beacon run by the League of Entropy. It publishes verifiable, unpredictable and unbiased randomness every 3 seconds. We consume it via Cloudflare's public relay — we never generate randomness ourselves.</p>

			<div class="trng-badges"><span class="trng-badge trng-badge-live">Live Round: <span id="trng-live-round">…</span></span><span class="trng-badge">Next round in <span id="trng-next-round">…</span>s</span></div>

			<table class="trng-table">
				<tbody>
					<tr><th>Beacon</th><td>quicknet</td></tr>
					<tr><th>Chain hash</th><td><code class="trng-wrap"><?php echo esc_html( TRNG_CHAIN_HASH ); ?></code></td></tr>
					<tr><th>Genesis time</th><td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', TRNG_CHAIN_GENESIS ) ); ?> UTC (unix <?php echo (int) TRNG_CHAIN_GENESIS; ?>)</td></tr>
					<tr><th>Round period</th><td><?php echo (int) TRNG_CHAIN_PERIOD; ?> seconds</td></tr>
					<tr><th>Scheme</th><td><code><?php echo esc_html( TRNG_CHAIN_SCHEME ); ?></code> (BLS12-381 threshold signatures)</td></tr>
					<tr><th>Group public key</th><td><code class="trng-wrap"><?php echo esc_html( TRNG_CHAIN_PUBLIC_KEY ); ?></code></td></tr>
					<tr><th>Randomness rule</th><td><code>randomness = SHA-256(signature)</code> — checked locally on every draw</td></tr>
				</tbody>
			</table>

			<h3>Round Arithmetic</h3>
			<p>Round numbers are pure arithmetic — anyone can compute which round covers any moment in time:</p>
			<pre class="trng-code">round(t)      = floor((t − genesis_time) / period) + 1
time_of(round) = genesis_time + (round − 1) × period</pre>

			<h3>Public Relays</h3>
			<p>The same beacon is served by independent relays. Any round can be fetched from any of them and must be identical everywhere:</p>
			<ul>
				<?php foreach ( $relays as $relay ) : ?>
					<li><a href="<?php echo esc_url( rtrim( $relay, '/' ) . '/' . TRNG_CHAIN_HASH . '/public/latest' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $relay ); ?></a></li>
				<?php endforeach; ?>
			</ul>

			<h3>League of Entropy</h3>
			<p>A global consortium of independent organisations — including <strong>Cloudflare</strong>, <strong>Protocol Labs</strong>, <strong>EPFL</strong>, the <strong>University of Chile</strong> and <strong>Kudelski Security</strong> — collectively generating randomness with threshold cryptography. Because randomness is produced jointly by distributed nodes, no single entity (including us) can predict or manipulate the outcome.</p>
			<p class="trng-muted"><a href="https://www.cloudflare.com/leagueofentropy/" target="_blank" rel="noopener noreferrer">League of Entropy</a> · <a href="https://blog.cloudflare.com/league-of-entropy/" target="_blank" rel="noopener noreferrer">Cloudflare blog post</a> · <a href="https://drand.love" target="_blank" rel="noopener noreferrer">drand.love</a></p>
		</div></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Manual verification guide with copy-paste scripts.
	 */
	public static function sc_how_to_verify() {
		$php_script = self::php_verification_script();
		$js_script  = self::js_verification_script();
		ob_start();
		?>
		<div class="trng-wrapper"><div class="trng-card trng-card-wide trng-prose">
			<h2 class="trng-title">Manual Verification</h2>
			<p class="trng-subtitle">Total transparency: take the raw data from any draw and reproduce the result yourself, completely independent of our servers.</p>

			<h3>Step 1 — Get the Data</h3>
			<p>Open any completed draw on the verification page. The <em>Verifiably Fair Data</em> section lists: <strong>Server Seed</strong> (the Drand randomness), <strong>Client Seed</strong> (the timestamp), <strong>Round ID</strong> (the draw's UUID), <strong>Static Salt</strong>, <strong>Tickets Sold</strong>, <strong>Max Tickets</strong> and <strong>Number of Winners</strong>.</p>

			<h3>Step 2 — Verify the Drand Source</h3>
			<p>Confirm the Server Seed really came from the Drand network and was not invented by us. Fetch the committed round from Cloudflare's public relay (replace <code>{ROUND_NUMBER}</code>):</p>
			<pre class="trng-code">https://drand.cloudflare.com/<?php echo esc_html( TRNG_CHAIN_HASH ); ?>/public/{ROUND_NUMBER}</pre>
			<p>The <code>randomness</code> field must equal the Server Seed exactly. Cross-check the same URL on <code>api.drand.sh</code>, <code>api2.drand.sh</code> or <code>api3.drand.sh</code> — independent relays must agree.</p>

			<h3>Step 3 — Run the Calculation</h3>
			<p>We use <strong>HMAC-SHA256</strong> to combine the seeds and <strong>rejection sampling</strong> to determine the winner — this guarantees uniform probability with no modulo bias. Run this PHP script in any sandbox (e.g. an online PHP runner):</p>
			<pre class="trng-code"><?php echo esc_html( $php_script ); ?></pre>

			<p>Or run this JavaScript directly in your browser console (Right click → Inspect → Console):</p>
			<pre class="trng-code"><?php echo esc_html( $js_script ); ?></pre>

			<h3>Why can't I use a standard hex converter?</h3>
			<p>Converting the whole hash to one giant number and taking a simple modulo introduces <strong>modulo bias</strong> — some tickets would be slightly more likely to win than others. Rejection sampling reads the hash 4 bytes at a time and discards values above a calculated limit, guaranteeing every ticket exactly the same probability. That is why you must use the scripts above.</p>

			<h3>Multiple winners</h3>
			<p>Winner #1 is always the first accepted value — identical to the single-winner calculation. Additional winners simply continue the same walk through the hash, skipping duplicate tickets. If the 32-byte hash is exhausted, the walk continues on deterministic extension blocks: <code>HMAC-SHA256(data + ":extend:" + n, serverSeed)</code> for n = 1, 2, 3…</p>
		</div></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Reference PHP verification script (multi-winner aware; winner #1
	 * matches the standard single-winner calculation exactly).
	 */
	public static function php_verification_script() {
		return <<<'SCRIPT'
<?php
// 1. INPUT YOUR DATA HERE
$serverSeed  = 'REPLACE_WITH_SERVER_SEED';  // Drand randomness for the committed round
$clientSeed  = 'REPLACE_WITH_CLIENT_SEED';  // Draw timestamp (ms)
$roundId     = 'REPLACE_WITH_ROUND_ID';     // Draw UUID
$staticSalt  = 'REPLACE_WITH_STATIC_SALT';  // Published static salt
$ticketsSold = 100;                         // Actual tickets sold
$maxTickets  = 1000;                        // Max tickets (draw range)
$numWinners  = 1;                           // Number of winners drawn

// 2. COMBINE SEEDS (HMAC-SHA256)
$data = "{$clientSeed}:{$roundId}:{$staticSalt}:{$ticketsSold}:{$maxTickets}";
$combinedHash = hash_hmac('sha256', $data, $serverSeed);
echo "Combined Hash: {$combinedHash}\n";

// 3. GENERATE RESULT (Rejection Sampling, uint32 little-endian)
$limit   = 0xFFFFFFFF - (0xFFFFFFFF % $maxTickets);
$winners = array();
$block   = 0;
$hashBin = hex2bin($combinedHash);
$i       = 0;

while (count($winners) < $numWinners) {
    if ($i + 4 > strlen($hashBin)) {           // Extend deterministically
        $block++;
        $hashBin = hex2bin(hash_hmac('sha256', $data . ':extend:' . $block, $serverSeed));
        $i = 0;
        continue;
    }
    $value = unpack('V', substr($hashBin, $i, 4))[1]; // 'V' = uint32 LE
    $i += 4;

    if ($value >= $limit) continue;            // Rejected: no modulo bias
    $ticket = ($value % $maxTickets) + 1;
    if (in_array($ticket, $winners, true)) continue; // Duplicate: skipped
    $winners[] = $ticket;
}

echo "Winning Ticket(s): " . implode(', ', $winners) . "\n";
SCRIPT;
	}

	/**
	 * Reference JavaScript verification script.
	 */
	public static function js_verification_script() {
		return <<<'SCRIPT'
// 1. INPUT YOUR DATA HERE
const serverSeed  = 'REPLACE_WITH_SERVER_SEED';
const clientSeed  = 'REPLACE_WITH_CLIENT_SEED';
const roundId     = 'REPLACE_WITH_ROUND_ID';
const staticSalt  = 'REPLACE_WITH_STATIC_SALT';
const ticketsSold = 100;
const maxTickets  = 1000;
const numWinners  = 1;

async function hmac(message, key) {
  const enc = new TextEncoder();
  const k = await crypto.subtle.importKey('raw', enc.encode(key),
    { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  return new Uint8Array(await crypto.subtle.sign('HMAC', k, enc.encode(message)));
}

(async () => {
  const data = `${clientSeed}:${roundId}:${staticSalt}:${ticketsSold}:${maxTickets}`;
  let bytes = await hmac(data, serverSeed);
  console.log('Combined Hash:', [...bytes].map(b => b.toString(16).padStart(2, '0')).join(''));

  const limit = 4294967295 - (4294967295 % maxTickets);
  const winners = [];
  let block = 0, i = 0;

  while (winners.length < numWinners) {
    if (i + 4 > bytes.length) {                       // Extend deterministically
      block++;
      bytes = await hmac(`${data}:extend:${block}`, serverSeed);
      i = 0;
      continue;
    }
    const value = new DataView(bytes.buffer, i, 4).getUint32(0, true); // uint32 LE
    i += 4;

    if (value >= limit) continue;                     // Rejected: no modulo bias
    const ticket = (value % maxTickets) + 1;
    if (winners.includes(ticket)) continue;           // Duplicate: skipped
    winners.push(ticket);
  }

  console.log('Winning Ticket(s):', winners.join(', '));
})();
SCRIPT;
	}
}
