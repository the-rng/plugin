<?php
/**
 * Activation, database schema and upgrades.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Install {

	const DB_VERSION = '2.4.0';

	/**
	 * Activation: create the draws ledger table, salt and endpoints.
	 */
	public static function activate() {
		self::create_table();
		TRNG_Settings::ensure_salt();
		add_option( 'trng_db_version', self::DB_VERSION );

		// Rewrite endpoints (integrity + account tab).
		TRNG_Integrity::add_endpoint();
		TRNG_WooCommerce::add_endpoint();
		flush_rewrite_rules();
	}

	/**
	 * Upgrade path for future versions.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'trng_db_version' ) !== self::DB_VERSION ) {
			self::create_table();
			TRNG_Settings::ensure_salt();
			self::backfill_approvals();
			update_option( 'trng_db_version', self::DB_VERSION );
		}
	}

	/**
	 * One-time: accounts that existed before the manual-approval feature
	 * are grandfathered in as approved (add_user_meta with $unique = true
	 * never overwrites an existing value).
	 */
	protected static function backfill_approvals() {
		if ( get_option( 'trng_approval_backfilled' ) ) {
			return;
		}
		$ids = get_users( array( 'fields' => 'ID', 'number' => 5000 ) );
		foreach ( (array) $ids as $id ) {
			add_user_meta( (int) $id, 'trng_approved', '1', true );
		}
		update_option( 'trng_approval_backfilled', 1, false );
	}

	/**
	 * The draw ledger. Records are append-only: completed rows are never
	 * edited or deleted by the plugin (voiding sets a flag + reason but
	 * preserves the record). record_hash chains each completed record to
	 * the previous one, making the ledger tamper-evident.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = $wpdb->prefix . TRNG_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			draw_key VARCHAR(24) NOT NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			status VARCHAR(12) NOT NULL DEFAULT 'pending',
			void_reason VARCHAR(255) NULL,
			competition_title VARCHAR(200) NOT NULL DEFAULT '',
			competition_url VARCHAR(255) NOT NULL DEFAULT '',
			tickets_sold INT UNSIGNED NOT NULL DEFAULT 0,
			max_tickets INT UNSIGNED NOT NULL DEFAULT 0,
			num_winners SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			client_seed VARCHAR(32) NOT NULL DEFAULT '',
			round_uuid CHAR(36) NOT NULL DEFAULT '',
			static_salt VARCHAR(64) NOT NULL DEFAULT '',
			chain_hash CHAR(64) NOT NULL DEFAULT '',
			target_round BIGINT UNSIGNED NOT NULL DEFAULT 0,
			round_time DATETIME NULL,
			server_seed CHAR(64) NULL,
			drand_signature VARCHAR(256) NULL,
			combined_hash CHAR(64) NULL,
			results TEXT NULL,
			endpoints_used VARCHAR(500) NULL,
			ledger_pushed TINYINT(1) NOT NULL DEFAULT 0,
			ledger_path VARCHAR(255) NULL,
			ledger_error VARCHAR(255) NULL,
			prev_hash CHAR(64) NULL,
			record_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY draw_key (draw_key),
			KEY user_id (user_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
