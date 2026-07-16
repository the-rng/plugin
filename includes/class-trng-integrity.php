<?php
/**
 * Public integrity endpoint.
 *
 * GET /?trng_integrity=1  (also /integrity with pretty permalinks)
 *
 * Returns SHA-256 hashes of every file in this plugin plus a combined
 * manifest hash and the current ledger head, so anyone can compare the
 * running code against the published source.
 *
 * @package The_RNG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TRNG_Integrity {

	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_respond' ) );
	}

	public static function add_endpoint() {
		add_rewrite_endpoint( 'integrity', EP_ROOT );
	}

	public static function maybe_respond() {
		global $wp_query;

		$via_endpoint = isset( $wp_query->query_vars['integrity'] );
		$via_query    = isset( $_GET['trng_integrity'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $via_endpoint && ! $via_query ) {
			return;
		}

		nocache_headers(); // The manifest must never be served stale from a page cache.

		$files    = self::plugin_files();
		$manifest = array();
		foreach ( $files as $relative => $absolute ) {
			$manifest[ $relative ] = hash_file( 'sha256', $absolute );
		}
		ksort( $manifest );

		wp_send_json_success(
			array(
				'plugin'        => 'The-RNG',
				'version'       => TRNG_VERSION,
				'status'        => 'active',
				'files'         => $manifest,
				'manifest_hash' => hash( 'sha256', wp_json_encode( $manifest ) ),
				'ledger_head'   => TRNG_Draws::ledger_head(),
				'source_url'    => (string) TRNG_Settings::get( 'github_source_url' ),
				'message'       => 'Compare these hashes with the published source repository to verify code integrity.',
			)
		);
	}

	/**
	 * All plugin files (php/js/css/txt), relative => absolute.
	 *
	 * @return array
	 */
	protected static function plugin_files() {
		$base  = untrailingslashit( TRNG_PLUGIN_DIR );
		$out   = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			if ( ! in_array( strtolower( $file->getExtension() ), array( 'php', 'js', 'css', 'txt' ), true ) ) {
				continue;
			}
			$relative         = ltrim( str_replace( $base, '', $file->getPathname() ), '/\\' );
			$out[ $relative ] = $file->getPathname();
		}

		return $out;
	}
}
