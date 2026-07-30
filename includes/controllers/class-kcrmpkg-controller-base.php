<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared helpers for this add-on's own admin screen(s). Deliberately
 * NOT extending karks-crm's KCRM_Controller_Base/KCRM_Admin_Screen_Trait --
 * that layer is more likely to change as karks-crm's front-end evolves, and
 * staying off it means core changes there can't break this add-on.
 */
abstract class KCRM_Pkg_Controller_Base {

	const PAGE = 'karks-crm-packages';

	public function screen_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	protected function redirect( array $args = array() ) {
		wp_safe_redirect( $this->screen_url( $args ) );
		exit;
	}

	protected function current_company_id() {
		return KCRM_Context::get_current_company_id();
	}

	protected function render_notice_from_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		if ( empty( $_GET['kcrmpkg_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		$notice = sanitize_key( wp_unslash( $_GET['kcrmpkg_notice'] ) );

		$messages = array(
			'saved'   => array( 'success', __( 'Saved successfully.', 'karks-crm-packages' ) ),
			'deleted' => array( 'success', __( 'Deleted successfully.', 'karks-crm-packages' ) ),
			'error'   => array( 'error', __( 'Something went wrong. Please try again.', 'karks-crm-packages' ) ),
			'overage' => array( 'warning', __( 'Usage logged. This package is now over its allotted hours.', 'karks-crm-packages' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $message ) = $messages[ $notice ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}
}
