<?php
/**
 * Registration, operator details and manual account approval.
 *
 * Sign-up (WordPress or WooCommerce forms) requires ALL operator details:
 * operator/company name, website URL and company number. They are stored
 * as user meta — the same keys the GitHub ledger push reads — so every
 * draw a user runs is attributed to their operator block.
 *
 * Every new account starts UNAPPROVED: it can log in but cannot run draws
 * until an administrator manually approves it (Users list row action or
 * the checkbox on the user profile). Administrators always bypass the
 * check. Existing accounts are approved once during upgrade.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Registration {

	const APPROVED_META = 'trng_approved';

	public static function register() {
		// Standard WordPress registration.
		add_action( 'register_form', array( __CLASS__, 'render_fields' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'validate_wp' ), 10, 3 );
		add_action( 'user_register', array( __CLASS__, 'save' ) );

		// WooCommerce registration.
		add_action( 'woocommerce_register_form', array( __CLASS__, 'render_fields_woo' ) );
		add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'validate_woo' ), 10, 3 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'save' ) );

		// Read-only display in My Account → Account details.
		add_action( 'woocommerce_edit_account_form_start', array( __CLASS__, 'render_account_view' ) );

		// Admin: profile fields, users-list column and approve/revoke actions.
		add_action( 'show_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_fields' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
		add_filter( 'user_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_toggle_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/* ------------------------------------------------------------------
	   APPROVAL STATE
	------------------------------------------------------------------ */

	/**
	 * Can this user run draws?
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_approved( $user_id ) {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return '1' === (string) get_user_meta( (int) $user_id, self::APPROVED_META, true );
	}

	/**
	 * Approve or revoke, emailing the user on first approval.
	 *
	 * @param int  $user_id  User ID.
	 * @param bool $approved New state.
	 */
	public static function set_approved( $user_id, $approved ) {
		$was = self::is_approved( $user_id );
		update_user_meta( (int) $user_id, self::APPROVED_META, $approved ? '1' : '0' );

		if ( $approved && ! $was ) {
			$user = get_userdata( (int) $user_id );
			if ( $user && $user->user_email ) {
				$brand = (string) TRNG_Settings::get( 'brand_name' );
				wp_mail(
					$user->user_email,
					sprintf( '%s — your account has been approved', $brand ),
					sprintf(
						"Hello %s,\n\nYour %s account has been approved. You can now log in and generate verifiably fair draws:\n\n%s\n\nEvery draw you run is committed to the public drand beacon and recorded in the public ledger under your operator details.\n\n— %s",
						$user->display_name,
						$brand,
						wp_login_url(),
						$brand
					)
				);
			}
		}
	}

	/* ------------------------------------------------------------------
	   FIELD RENDERING (REGISTRATION)
	------------------------------------------------------------------ */

	protected static function sticky( $key ) {
		return isset( $_POST[ $key ] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	public static function render_fields() {
		?>
		<p>
			<label for="trng_reg_operator_name">Operator / company name<br>
			<input type="text" name="trng_reg_operator_name" id="trng_reg_operator_name" class="input" value="<?php echo self::sticky( 'trng_reg_operator_name' ); // phpcs:ignore ?>" size="25" required></label>
		</p>
		<p>
			<label for="trng_reg_operator_website">Website URL<br>
			<input type="url" name="trng_reg_operator_website" id="trng_reg_operator_website" class="input" value="<?php echo self::sticky( 'trng_reg_operator_website' ); // phpcs:ignore ?>" size="25" placeholder="https://yourcompetitionsite.com" required></label>
		</p>
		<p>
			<label for="trng_reg_company_number">Company number<br>
			<input type="text" name="trng_reg_company_number" id="trng_reg_company_number" class="input" value="<?php echo self::sticky( 'trng_reg_company_number' ); // phpcs:ignore ?>" size="25" required></label>
		</p>
		<p class="description">All accounts are reviewed manually before draws can be generated.</p>
		<?php
	}

	public static function render_fields_woo() {
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="trng_reg_operator_name">Operator / company name&nbsp;<span class="required">*</span></label>
			<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="trng_reg_operator_name" id="trng_reg_operator_name" value="<?php echo self::sticky( 'trng_reg_operator_name' ); // phpcs:ignore ?>" required>
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="trng_reg_operator_website">Website URL&nbsp;<span class="required">*</span></label>
			<input type="url" class="woocommerce-Input woocommerce-Input--text input-text" name="trng_reg_operator_website" id="trng_reg_operator_website" value="<?php echo self::sticky( 'trng_reg_operator_website' ); // phpcs:ignore ?>" placeholder="https://yourcompetitionsite.com" required>
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="trng_reg_company_number">Company number&nbsp;<span class="required">*</span></label>
			<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="trng_reg_company_number" id="trng_reg_company_number" value="<?php echo self::sticky( 'trng_reg_company_number' ); // phpcs:ignore ?>" required>
			<span class="description">Your operator details appear in the public draw ledger for every draw you run. All accounts are reviewed manually before draws can be generated.</span>
		</p>
		<?php
	}

	/* ------------------------------------------------------------------
	   VALIDATION
	------------------------------------------------------------------ */

	/**
	 * Normalise a submitted website URL (adds https:// when missing).
	 *
	 * @param string $raw Raw input.
	 * @return string Normalised URL or '' when invalid.
	 */
	public static function normalise_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$raw = 'https://' . $raw;
		}
		$url = esc_url_raw( $raw );
		return wp_http_validate_url( $url ) ? $url : '';
	}

	protected static function validate( $errors ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- runs inside core/Woo registration handling.
		$name    = isset( $_POST['trng_reg_operator_name'] ) ? sanitize_text_field( wp_unslash( $_POST['trng_reg_operator_name'] ) ) : '';
		$website = isset( $_POST['trng_reg_operator_website'] ) ? wp_unslash( $_POST['trng_reg_operator_website'] ) : '';
		$number  = isset( $_POST['trng_reg_company_number'] ) ? sanitize_text_field( wp_unslash( $_POST['trng_reg_company_number'] ) ) : '';
		// phpcs:enable

		if ( '' === trim( $name ) ) {
			$errors->add( 'trng_operator_name_error', '<strong>Error:</strong> Please enter your operator / company name.' );
		}
		if ( '' === self::normalise_url( $website ) ) {
			$errors->add( 'trng_operator_website_error', '<strong>Error:</strong> Please enter a valid website URL (e.g. https://yoursite.com).' );
		}
		if ( '' === trim( $number ) ) {
			$errors->add( 'trng_company_number_error', '<strong>Error:</strong> Please enter your company number.' );
		}

		return $errors;
	}

	public static function validate_wp( $errors, $sanitized_user_login, $user_email ) {
		return self::validate( $errors );
	}

	public static function validate_woo( $errors, $username, $email ) {
		return self::validate( $errors );
	}

	/* ------------------------------------------------------------------
	   SAVE (REGISTRATION)
	------------------------------------------------------------------ */

	public static function save( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- runs inside core/Woo registration handling.
		$via_form = isset( $_POST['trng_reg_operator_name'] ) || isset( $_POST['trng_reg_operator_website'] );

		if ( $via_form ) {
			$name    = isset( $_POST['trng_reg_operator_name'] ) ? sanitize_text_field( wp_unslash( $_POST['trng_reg_operator_name'] ) ) : '';
			$website = isset( $_POST['trng_reg_operator_website'] ) ? self::normalise_url( wp_unslash( $_POST['trng_reg_operator_website'] ) ) : '';
			$number  = isset( $_POST['trng_reg_company_number'] ) ? sanitize_text_field( wp_unslash( $_POST['trng_reg_company_number'] ) ) : '';

			if ( '' !== $name ) {
				update_user_meta( $user_id, 'trng_operator_name', $name );
			}
			if ( '' !== $website ) {
				update_user_meta( $user_id, 'trng_operator_website', $website );
			}
			if ( '' !== $number ) {
				update_user_meta( $user_id, 'trng_operator_company_number', $number );
			}
		}
		// phpcs:enable

		// Approval: accounts created by an administrator in wp-admin are
		// trusted; self-registrations start unapproved.
		$by_admin = is_admin() && current_user_can( 'create_users' );
		update_user_meta( $user_id, self::APPROVED_META, $by_admin ? '1' : '0' );
	}

	/* ------------------------------------------------------------------
	   MY ACCOUNT (READ-ONLY VIEW + PENDING NOTICE)
	------------------------------------------------------------------ */

	public static function render_account_view() {
		$user_id = get_current_user_id();
		$name    = (string) get_user_meta( $user_id, 'trng_operator_name', true );
		$website = (string) get_user_meta( $user_id, 'trng_operator_website', true );
		$number  = (string) get_user_meta( $user_id, 'trng_operator_company_number', true );

		if ( '' === $name && '' === $website && '' === $number ) {
			return;
		}

		$email    = sanitize_email( TRNG_Settings::get( 'enquiry_email' ) );
		$approved = self::is_approved( $user_id );
		?>
		<fieldset style="margin-bottom:1.5em;">
			<legend>Operator details (public draw ledger)</legend>
			<p style="margin:.25em 0;"><strong>Status:</strong> <?php echo $approved ? 'Approved — you can generate draws' : 'Pending manual review'; ?></p>
			<p style="margin:.25em 0;"><strong>Operator:</strong> <?php echo esc_html( $name ? $name : '—' ); ?></p>
			<p style="margin:.25em 0;"><strong>Website:</strong> <?php echo esc_html( $website ? $website : '—' ); ?></p>
			<p style="margin:.25em 0;"><strong>Company number:</strong> <?php echo esc_html( $number ? $number : '—' ); ?></p>
			<p style="margin:.5em 0 0;"><small>These details are recorded in the public ledger with every draw you run. To change them<?php echo $email ? ', email <a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : ', please contact us'; ?>.</small></p>
		</fieldset>
		<?php
	}

	/**
	 * Card shown on the generator to logged-in but unapproved users.
	 *
	 * @return string
	 */
	public static function pending_wall() {
		$email = sanitize_email( TRNG_Settings::get( 'enquiry_email' ) );
		$brand = esc_html( TRNG_Settings::get( 'brand_name' ) );
		ob_start();
		?>
		<div class="trng-wrapper"><div class="trng-card trng-center">
			<h2 class="trng-title">Account Pending Approval</h2>
			<p class="trng-subtitle">To maintain the integrity of the public ledger, every <?php echo $brand; ?> account is reviewed manually before draws can be generated.</p>
			<p>You'll receive an email as soon as your account is approved.</p>
			<?php if ( $email ) : ?>
				<p class="trng-muted">Questions? <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></p>
			<?php endif; ?>
		</div></div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	   ADMIN: PROFILE FIELDS (admin-only, includes approval)
	------------------------------------------------------------------ */

	public static function user_fields( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$approved = self::is_approved( $user->ID );
		?>
		<h2>The-RNG — Operator details &amp; approval</h2>
		<p class="description">Included in the public GitHub ledger record for every draw this user runs. Falls back to the global defaults in The-RNG &rarr; Settings.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th>Draw approval</th>
				<td><label><input type="checkbox" name="trng_approved" value="1" <?php checked( $approved ); ?> <?php disabled( user_can( $user->ID, 'manage_options' ) ); ?>> Approved to run draws</label>
				<?php if ( user_can( $user->ID, 'manage_options' ) ) : ?><p class="description">Administrators are always approved.</p><?php endif; ?>
				</td>
			</tr>
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
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core profile nonce already verified.
		update_user_meta( $user_id, 'trng_operator_name', sanitize_text_field( isset( $_POST['trng_operator_name'] ) ? wp_unslash( $_POST['trng_operator_name'] ) : '' ) );
		update_user_meta( $user_id, 'trng_operator_website', esc_url_raw( isset( $_POST['trng_operator_website'] ) ? wp_unslash( $_POST['trng_operator_website'] ) : '' ) );
		update_user_meta( $user_id, 'trng_operator_company_number', sanitize_text_field( isset( $_POST['trng_operator_company_number'] ) ? wp_unslash( $_POST['trng_operator_company_number'] ) : '' ) );

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			self::set_approved( $user_id, ! empty( $_POST['trng_approved'] ) );
		}
		// phpcs:enable
	}

	/* ------------------------------------------------------------------
	   ADMIN: USERS LIST COLUMN + APPROVE/REVOKE ROW ACTIONS
	------------------------------------------------------------------ */

	public static function users_column( $columns ) {
		$columns['trng_approval'] = 'The-RNG';
		return $columns;
	}

	public static function users_column_value( $output, $column, $user_id ) {
		if ( 'trng_approval' !== $column ) {
			return $output;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return '<span style="color:#2271b1;">Admin</span>';
		}
		return self::is_approved( $user_id )
			? '<span style="color:#00a32a;font-weight:600;">&#10003; Approved</span>'
			: '<span style="color:#b32d2e;font-weight:600;">Pending</span>';
	}

	public static function row_actions( $actions, $user ) {
		if ( ! current_user_can( 'edit_users' ) || user_can( $user->ID, 'manage_options' ) ) {
			return $actions;
		}

		$approved = self::is_approved( $user->ID );
		$url      = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'trng_toggle_approval',
					'user_id' => $user->ID,
				),
				admin_url( 'users.php' )
			),
			'trng_toggle_approval_' . $user->ID
		);

		$actions['trng_approval'] = $approved
			? '<a href="' . esc_url( $url ) . '" style="color:#b32d2e;">Revoke draws</a>'
			: '<a href="' . esc_url( $url ) . '" style="color:#00a32a;font-weight:600;">Approve draws</a>';

		return $actions;
	}

	public static function handle_toggle_action() {
		if ( ! isset( $_GET['action'] ) || 'trng_toggle_approval' !== $_GET['action'] || ! isset( $_GET['user_id'] ) ) {
			return;
		}
		$user_id = (int) $_GET['user_id'];
		if ( ! current_user_can( 'edit_users' ) || ! check_admin_referer( 'trng_toggle_approval_' . $user_id ) ) {
			wp_die( 'Not allowed.' );
		}

		$new = ! self::is_approved( $user_id );
		self::set_approved( $user_id, $new );

		wp_safe_redirect( add_query_arg( 'trng_approval_changed', $new ? 'approved' : 'revoked', admin_url( 'users.php' ) ) );
		exit;
	}

	public static function admin_notice() {
		if ( empty( $_GET['trng_approval_changed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$state = sanitize_text_field( wp_unslash( $_GET['trng_approval_changed'] ) ); // phpcs:ignore
		echo '<div class="notice notice-success is-dismissible"><p>' .
			( 'approved' === $state ? 'User approved — they have been emailed and can now generate draws.' : 'Draw access revoked for that user.' ) .
			'</p></div>';
	}
}
