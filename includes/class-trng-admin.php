<?php
/**
 * Admin: draw ledger, settings, ledger export, chain integrity check.
 *
 * Audit note: the plugin deliberately provides NO way to edit or delete a
 * draw record. Records can only be voided (flagged with a public reason).
 * This keeps the ledger append-only and the hash chain intact.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Admin {

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_trng_export_ledger', array( __CLASS__, 'export_ledger' ) );
	}

	public static function menu() {
		add_menu_page( 'The-RNG', 'The-RNG', 'manage_options', 'trng-ledger', array( __CLASS__, 'page_ledger' ), 'dashicons-randomize', 58 );
		add_submenu_page( 'trng-ledger', 'Draw Ledger', 'Draw Ledger', 'manage_options', 'trng-ledger', array( __CLASS__, 'page_ledger' ) );
		add_submenu_page( 'trng-ledger', 'Settings', 'Settings', 'manage_options', 'trng-settings', array( __CLASS__, 'page_settings' ) );
	}

	/* ------------------------------------------------------------------
	   LEDGER LIST + DETAIL
	------------------------------------------------------------------ */

	public static function page_ledger() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Void action.
		if ( isset( $_POST['trng_void_key'] ) && check_admin_referer( 'trng_void' ) ) {
			$reason = isset( $_POST['trng_void_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['trng_void_reason'] ) ) : '';
			if ( '' === trim( $reason ) ) {
				echo '<div class="notice notice-error"><p>A public reason is required to void a draw.</p></div>';
			} else {
				TRNG_Draws::void( sanitize_text_field( wp_unslash( $_POST['trng_void_key'] ) ), $reason );
				echo '<div class="notice notice-success is-dismissible"><p>Draw voided. The record remains permanently in the ledger with the reason shown publicly.</p></div>';
			}
		}

		// Chain check action.
		if ( isset( $_POST['trng_check_chain'] ) && check_admin_referer( 'trng_check_chain' ) ) {
			$check = TRNG_Draws::verify_chain();
			if ( empty( $check['failures'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Ledger intact:</strong> ' . (int) $check['checked'] . ' completed records verified, hash chain unbroken.</p></div>';
			} else {
				echo '<div class="notice notice-error"><p><strong>Ledger integrity problem!</strong></p><ul>';
				foreach ( $check['failures'] as $f ) {
					echo '<li><code>' . esc_html( $f['draw_key'] ) . '</code> — ' . esc_html( $f['problem'] ) . '</li>';
				}
				echo '</ul></div>';
			}
		}

		// Detail view.
		if ( isset( $_GET['draw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_detail( sanitize_text_field( wp_unslash( $_GET['draw'] ) ) ); // phpcs:ignore
			return;
		}

		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore
		$data = TRNG_Draws::list( array( 'per_page' => 25, 'page' => $page ) );
		$total_pages = (int) ceil( $data['total'] / 25 );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">The-RNG — Draw Ledger</h1>

			<form method="post" style="display:inline-block;margin-left:12px;">
				<?php wp_nonce_field( 'trng_check_chain' ); ?>
				<input type="hidden" name="trng_check_chain" value="1">
				<button class="button">Verify ledger integrity</button>
			</form>
			<a class="button" style="margin-left:6px;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=trng_export_ledger' ), 'trng_export' ) ); ?>">Export ledger (JSON)</a>

			<p class="description">Records are append-only and hash-chained. There is intentionally no delete: draws can only be voided with a public reason.</p>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>Key</th><th>Competition</th><th>User</th><th>Sold / Max</th><th>Round</th><th>Winner(s)</th><th>Status</th><th>Completed (UTC)</th><th>GitHub</th><th></th></tr></thead>
				<tbody>
				<?php if ( $data['rows'] ) : ?>
					<?php foreach ( $data['rows'] as $row ) :
						$user    = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false;
						$winners = $row['results'] ? implode( ', ', (array) json_decode( (string) $row['results'], true ) ) : '—';
						$detail  = add_query_arg( array( 'page' => 'trng-ledger', 'draw' => rawurlencode( $row['draw_key'] ) ), admin_url( 'admin.php' ) );
						?>
						<tr>
							<td><code><?php echo esc_html( $row['draw_key'] ); ?></code></td>
							<td><?php echo esc_html( $row['competition_title'] ); ?></td>
							<td><?php echo esc_html( $user ? $user->user_login : '—' ); ?></td>
							<td><?php echo (int) $row['tickets_sold']; ?> / <?php echo (int) $row['max_tickets']; ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['target_round'] ) ); ?></td>
							<td><strong><?php echo esc_html( $winners ); ?></strong></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td><?php echo esc_html( (string) $row['completed_at'] ); ?></td>
							<td><?php
							if ( ! empty( $row['ledger_pushed'] ) && ! empty( $row['ledger_path'] ) && TRNG_GitHub::repo() ) {
								echo '<a href="' . esc_url( 'https://github.com/' . TRNG_GitHub::repo() . '/blob/' . TRNG_GitHub::branch() . '/' . $row['ledger_path'] ) . '" target="_blank" rel="noopener noreferrer">&#10003; pushed</a>';
							} elseif ( ! empty( $row['ledger_error'] ) ) {
								echo '<span style="color:#b32d2e;" title="' . esc_attr( $row['ledger_error'] ) . '">&#10007; error</span>';
							} else {
								echo '&mdash;';
							}
							?></td>
							<td><a href="<?php echo esc_url( $detail ); ?>">Details</a></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="10">No draws yet.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom"><div class="tablenav-pages"><span class="pagination-links">
					<?php
					echo paginate_links( array( // phpcs:ignore
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'total'   => $total_pages,
						'current' => $page,
					) );
					?>
				</span></div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	protected static function render_detail( $key ) {
		$draw = TRNG_Draws::get( $key );
		if ( ! $draw ) {
			echo '<div class="wrap"><h1>Draw not found</h1></div>';
			return;
		}
		$pub = TRNG_Draws::to_public( $draw );
		?>
		<div class="wrap">
			<h1>Draw <code><?php echo esc_html( $key ); ?></code></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=trng-ledger' ) ); ?>">&larr; Back to ledger</a></p>

			<table class="widefat striped" style="max-width:980px;">
				<tbody>
				<?php foreach ( $pub as $field => $value ) : ?>
					<tr>
						<th style="width:220px;"><?php echo esc_html( $field ); ?></th>
						<td><code style="word-break:break-all;"><?php echo esc_html( is_array( $value ) ? wp_json_encode( $value ) : (string) $value ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( 'void' !== $draw['status'] ) : ?>
				<h2>Void this draw</h2>
				<p class="description">Voiding never deletes: the record stays in the ledger, flagged with your reason (shown publicly).</p>
				<form method="post" style="max-width:640px;">
					<?php wp_nonce_field( 'trng_void' ); ?>
					<input type="hidden" name="trng_void_key" value="<?php echo esc_attr( $key ); ?>">
					<input type="text" name="trng_void_reason" class="regular-text" placeholder="Public reason (required)" required>
					<button class="button button-secondary" onclick="return confirm('Void this draw? The record remains public with your reason.');">Void draw</button>
				</form>
			<?php else : ?>
				<p><strong>Voided:</strong> <?php echo esc_html( (string) $draw['void_reason'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	   LEDGER EXPORT (for mirroring to a public GitHub repository)
	------------------------------------------------------------------ */

	public static function export_ledger() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'trng_export' ) ) {
			wp_die( 'Not allowed.' );
		}

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . TRNG_Draws::table() . ' ORDER BY id ASC', ARRAY_A );

		$out = array(
			'ledger'      => TRNG_Settings::get( 'brand_name' ) . ' public draw ledger',
			'generated'   => gmdate( 'c' ),
			'chain_hash'  => TRNG_CHAIN_HASH,
			'ledger_head' => TRNG_Draws::ledger_head(),
			'draws'       => array_map( array( 'TRNG_Draws', 'to_public' ), $rows ? $rows : array() ),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="the-rng-ledger-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/* ------------------------------------------------------------------
	   SETTINGS
	------------------------------------------------------------------ */

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['trng_save_settings'] ) && check_admin_referer( 'trng_settings' ) ) {
			$relays = isset( $_POST['relays'] ) ? array_filter( array_map( 'esc_url_raw', array_map( 'trim', explode( "\n", (string) wp_unslash( $_POST['relays'] ) ) ) ) ) : array();

			TRNG_Settings::update(
				array(
					'round_offset'        => max( 1, min( 100, isset( $_POST['round_offset'] ) ? (int) $_POST['round_offset'] : 3 ) ),
					'relays'              => $relays ? array_values( $relays ) : TRNG_Settings::defaults()['relays'],
					'cross_check'         => empty( $_POST['cross_check'] ) ? 0 : 1,
					'max_tickets_limit'   => max( 1, isset( $_POST['max_tickets_limit'] ) ? (int) $_POST['max_tickets_limit'] : 10000000 ),
					'max_winners'         => max( 1, min( 1000, isset( $_POST['max_winners'] ) ? (int) $_POST['max_winners'] : 25 ) ),
					'enquiry_email'       => sanitize_email( isset( $_POST['enquiry_email'] ) ? wp_unslash( $_POST['enquiry_email'] ) : '' ),
					'display_timezone'    => sanitize_text_field( isset( $_POST['display_timezone'] ) && '' !== trim( (string) wp_unslash( $_POST['display_timezone'] ) ) ? wp_unslash( $_POST['display_timezone'] ) : 'Europe/London' ),
					'ledger_enabled'      => empty( $_POST['ledger_enabled'] ) ? 0 : 1,
					'ledger_repo'         => sanitize_text_field( isset( $_POST['ledger_repo'] ) ? wp_unslash( $_POST['ledger_repo'] ) : '' ),
					'ledger_branch'       => sanitize_text_field( isset( $_POST['ledger_branch'] ) && '' !== trim( (string) wp_unslash( $_POST['ledger_branch'] ) ) ? wp_unslash( $_POST['ledger_branch'] ) : 'main' ),
					'operator_name'       => sanitize_text_field( isset( $_POST['operator_name'] ) ? wp_unslash( $_POST['operator_name'] ) : '' ),
					'operator_website'    => esc_url_raw( isset( $_POST['operator_website'] ) ? wp_unslash( $_POST['operator_website'] ) : '' ),
					'operator_company_number' => sanitize_text_field( isset( $_POST['operator_company_number'] ) ? wp_unslash( $_POST['operator_company_number'] ) : '' ),
					'github_ledger_url'   => esc_url_raw( isset( $_POST['github_ledger_url'] ) ? wp_unslash( $_POST['github_ledger_url'] ) : '' ),
					'github_source_url'   => esc_url_raw( isset( $_POST['github_source_url'] ) ? wp_unslash( $_POST['github_source_url'] ) : '' ),
					'verify_page_url'     => esc_url_raw( isset( $_POST['verify_page_url'] ) ? wp_unslash( $_POST['verify_page_url'] ) : '' ),
					'brand_name'          => sanitize_text_field( isset( $_POST['brand_name'] ) ? wp_unslash( $_POST['brand_name'] ) : 'The-RNG' ),
					'delete_on_uninstall' => empty( $_POST['delete_on_uninstall'] ) ? 0 : 1,
				)
			);
			if ( isset( $_POST['ledger_token'] ) && '' !== trim( (string) wp_unslash( $_POST['ledger_token'] ) ) ) {
				TRNG_Settings::update( array( 'ledger_token' => sanitize_text_field( wp_unslash( $_POST['ledger_token'] ) ) ) );
			}
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}

		if ( isset( $_POST['trng_test_github'] ) && check_admin_referer( 'trng_test_github' ) ) {
			$test = TRNG_GitHub::test_connection();
			if ( is_wp_error( $test ) ) {
				echo '<div class="notice notice-error"><p><strong>GitHub test failed:</strong> ' . esc_html( $test->get_error_message() ) . '</p></div>';
			} elseif ( empty( $test['push'] ) ) {
				echo '<div class="notice notice-error"><p><strong>Token cannot write:</strong> connected to ' . esc_html( $test['repo'] ) . ' but the token lacks Contents write permission.</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p><strong>GitHub connected:</strong> ' . esc_html( $test['repo'] ) . ' (' . ( $test['private'] ? 'private — consider making it public' : 'public' ) . '), token can push.</p></div>';
			}
		}

		if ( isset( $_POST['trng_push_pending'] ) && check_admin_referer( 'trng_push_pending' ) ) {
			$swept = TRNG_GitHub::sweep();
			echo '<div class="notice notice-info is-dismissible"><p>Ledger push: ' . (int) $swept['pushed'] . ' pushed, ' . (int) $swept['failed'] . ' failed.</p></div>';
		}

		$s = TRNG_Settings::all();
		?>
		<div class="wrap">
			<h1>The-RNG — Settings</h1>
			<form method="post">
				<?php wp_nonce_field( 'trng_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label>Static salt (public)</label></th>
						<td><code><?php echo esc_html( (string) $s['static_salt'] ); ?></code>
						<p class="description">Generated once from a CSPRNG on activation and published on the Provably Fair page. It is part of every draw record; it cannot be edited here because changing it would break reproducibility of past draws.</p></td>
					</tr>
					<tr>
						<th><label for="round_offset">Round offset</label></th>
						<td><input name="round_offset" id="round_offset" type="number" min="1" max="100" value="<?php echo (int) $s['round_offset']; ?>">
						<p class="description">Draws commit to live round + offset. Default 3 (= 9 seconds on quicknet).</p></td>
					</tr>
					<tr>
						<th><label for="relays">Drand relays (one per line)</label></th>
						<td><textarea name="relays" id="relays" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $s['relays'] ) ); ?></textarea>
						<p class="description">First relay is primary (default: Cloudflare). Others are fallbacks and cross-checks.</p></td>
					</tr>
					<tr>
						<th>Cross-check beacons</th>
						<td><label><input type="checkbox" name="cross_check" value="1" <?php checked( $s['cross_check'] ); ?>> Require two independent relays to agree before accepting a beacon</label></td>
					</tr>
					<tr>
						<th><label for="max_tickets_limit">Max tickets limit</label></th>
						<td><input name="max_tickets_limit" id="max_tickets_limit" type="number" min="1" value="<?php echo (int) $s['max_tickets_limit']; ?>"></td>
					</tr>
					<tr>
						<th><label for="max_winners">Max winners per draw</label></th>
						<td><input name="max_winners" id="max_winners" type="number" min="1" max="1000" value="<?php echo (int) $s['max_winners']; ?>"></td>
					</tr>
					<tr>
						<th><label for="enquiry_email">Enquiry email</label></th>
						<td><input name="enquiry_email" id="enquiry_email" type="email" class="regular-text" value="<?php echo esc_attr( (string) $s['enquiry_email'] ); ?>">
						<p class="description">Shown on the login wall for registration enquiries.</p></td>
					</tr>
					<tr>
						<th><label for="display_timezone">Display timezone</label></th>
						<td><input name="display_timezone" id="display_timezone" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( isset( $s['display_timezone'] ) ? $s['display_timezone'] : 'Europe/London' ) ); ?>" placeholder="Europe/London">
						<p class="description">IANA timezone for on-screen clocks (client seed ticker). Draw records are always stored in UTC. Default: <code>Europe/London</code>.</p></td>
					</tr>
					<tr>
						<th><label for="verify_page_url">Verify page URL</label></th>
						<td><input name="verify_page_url" id="verify_page_url" type="url" class="regular-text" value="<?php echo esc_attr( (string) ( isset( $s['verify_page_url'] ) ? $s['verify_page_url'] : '' ) ); ?>" placeholder="https://example.com/verify/">
						<p class="description">URL of the page containing <code>[trng_verify]</code>. Used for "View &amp; Verify" links.</p></td>
					</tr>
					<tr>
						<th><label for="github_ledger_url">Public ledger URL (optional)</label></th>
						<td><input name="github_ledger_url" id="github_ledger_url" type="url" class="regular-text" value="<?php echo esc_attr( (string) $s['github_ledger_url'] ); ?>" placeholder="https://github.com/your-org/ledger">
						<p class="description">If you mirror ledger exports to a public GitHub repository, link it here.</p></td>
					</tr>
					<tr>
						<th><label for="github_source_url">Public source URL (optional)</label></th>
						<td><input name="github_source_url" id="github_source_url" type="url" class="regular-text" value="<?php echo esc_attr( (string) $s['github_source_url'] ); ?>" placeholder="https://github.com/your-org/the-rng">
						<p class="description">Public repository of this plugin's code, referenced by the integrity endpoint.</p></td>
					</tr>
					<tr>
						<th><label for="brand_name">Brand name</label></th>
						<td><input name="brand_name" id="brand_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['brand_name'] ); ?>"></td>
					</tr>
					<tr><th colspan="2"><h2 style="margin-bottom:0;">GitHub Public Ledger</h2>
					<p class="description" style="font-weight:normal;">Automatically commits every completed draw to a public repository as <code>YYYY/MM/DD/&lt;round-uuid&gt;.json</code> — an independent, timestamped record that outlives this site.</p></th></tr>
					<tr>
						<th>Enable auto-push</th>
						<td><label><input type="checkbox" name="ledger_enabled" value="1" <?php checked( $s['ledger_enabled'] ); ?>> Push every completed draw to GitHub automatically</label></td>
					</tr>
					<tr>
						<th><label for="ledger_repo">Repository</label></th>
						<td><input name="ledger_repo" id="ledger_repo" type="text" class="regular-text code" value="<?php echo esc_attr( (string) $s['ledger_repo'] ); ?>" placeholder="the-rng/ledger">
						<p class="description">Format: <code>owner/repository</code>. Must already exist on GitHub.</p></td>
					</tr>
					<tr>
						<th><label for="ledger_branch">Branch</label></th>
						<td><input name="ledger_branch" id="ledger_branch" type="text" class="regular-text code" value="<?php echo esc_attr( (string) $s['ledger_branch'] ); ?>" placeholder="main"></td>
					</tr>
					<tr>
						<th><label for="ledger_token">Access token</label></th>
						<td>
						<?php if ( defined( 'TRNG_GITHUB_TOKEN' ) && TRNG_GITHUB_TOKEN ) : ?>
							<em>Set via <code>TRNG_GITHUB_TOKEN</code> in wp-config.php (recommended).</em>
						<?php else : ?>
							<input name="ledger_token" id="ledger_token" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( TRNG_Settings::get( 'ledger_token' ) ? '•••••••• (saved — enter to replace)' : 'github_pat_…' ); ?>">
							<p class="description">Fine-grained personal access token, scoped to this one repository, permission Contents: Read &amp; write. Leave blank to keep the saved token. More secure: define <code>TRNG_GITHUB_TOKEN</code> in wp-config.php.</p>
						<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="operator_name">Default operator name</label></th>
						<td><input name="operator_name" id="operator_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['operator_name'] ); ?>">
						<p class="description">Operator block in each ledger file. Per-user overrides live on the user's profile page.</p></td>
					</tr>
					<tr>
						<th><label for="operator_website">Default operator website</label></th>
						<td><input name="operator_website" id="operator_website" type="url" class="regular-text" value="<?php echo esc_attr( (string) $s['operator_website'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="operator_company_number">Default company number</label></th>
						<td><input name="operator_company_number" id="operator_company_number" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['operator_company_number'] ); ?>"></td>
					</tr>
					<tr>
						<th>Uninstall</th>
						<td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> Delete the draw ledger when the plugin is uninstalled</label>
						<p class="description">Leave off to preserve your audit trail.</p></td>
					</tr>
				</table>
				<p><button class="button button-primary" name="trng_save_settings" value="1">Save settings</button></p>
			</form>

			<h2>GitHub ledger tools</h2>
			<form method="post" style="display:inline-block;">
				<?php wp_nonce_field( 'trng_test_github' ); ?>
				<button class="button" name="trng_test_github" value="1">Test GitHub connection</button>
			</form>
			<form method="post" style="display:inline-block;margin-left:6px;">
				<?php wp_nonce_field( 'trng_push_pending' ); ?>
				<button class="button" name="trng_push_pending" value="1">Push pending draws now</button>
			</form>

			<h2>Integrity endpoint</h2>
			<p>Anyone can fetch <code><?php echo esc_url( home_url( '/?trng_integrity=1' ) ); ?></code> to get SHA-256 hashes of every plugin file plus the current ledger head, for comparison against your published source.</p>
		</div>
		<?php
	}
}
