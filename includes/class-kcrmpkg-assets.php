<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues this add-on's script (and wp-admin stylesheet) only where its
 * markup appears: the wp-admin Packages screen and the front-end /crm/ pages.
 * assets/js/kcrmpkg.js guards each behavior on its own markup, so loading it
 * on a CRM page without a Packages tab is harmless.
 */
class KCRM_Pkg_Assets {

	public static function enqueue_admin() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( KCRM_Pkg_Controller_Base::PAGE !== $page ) {
			return;
		}

		wp_enqueue_style( 'kcrmpkg-admin', KCRMPKG_PLUGIN_URL . 'assets/css/admin.css', array(), KCRMPKG_VERSION );
		self::enqueue_script();
	}

	public static function enqueue_front() {
		if ( ! KCRM_Front::is_crm_page() ) {
			return;
		}
		self::enqueue_script();
	}

	private static function enqueue_script() {
		wp_enqueue_script( 'kcrmpkg', KCRMPKG_PLUGIN_URL . 'assets/js/kcrmpkg.js', array(), KCRMPKG_VERSION, true );
	}
}
