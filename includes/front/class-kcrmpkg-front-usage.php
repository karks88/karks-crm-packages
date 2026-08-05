<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles "Log Usage" / "Delete Usage" submissions posted from the front-end
 * customer screen's Packages section (KCRM_Pkg_Customer_Section::render()),
 * so staff can log usage against a package without needing wp-admin access.
 * Mirrors KCRM_Pkg_Admin_Packages::add_usage()/delete_usage(), but always
 * redirects back to the front-end customer screen instead of the admin
 * package screen. Uses its own kcrmpkg_-prefixed query args (rather than the
 * admin screen's action/id/usage_id) so it can't collide with karks-crm's
 * own action=delete&id= handling on that same customers-endpoint URL.
 */
class KCRM_Pkg_Front_Usage {

	public function handle_actions() {
		if ( ! KCRM_Front::is_crm_page() || ! is_user_logged_in() || ! current_user_can( KCRM_CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; add_usage() verifies the nonce itself.
		if ( isset( $_POST['kcrmpkg_action'] ) && 'add_usage' === $_POST['kcrmpkg_action'] ) {
			$this->add_usage();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete_usage() verifies the nonce itself.
		if ( isset( $_GET['kcrmpkg_action'], $_GET['kcrmpkg_usage_id'] ) && 'delete_usage' === $_GET['kcrmpkg_action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete_usage() verifies the nonce itself.
			$this->delete_usage( absint( $_GET['kcrmpkg_usage_id'] ) );
		}
	}

	private function add_usage() {
		check_admin_referer( 'kcrmpkg_add_usage' );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		$package_id = isset( $_POST['package_id'] ) ? absint( $_POST['package_id'] ) : 0;
		$package    = $package_id ? KCRM_Pkg_Package::find( $package_id ) : null;

		if ( ! $package ) {
			wp_safe_redirect( KCRM_Front::endpoint_url( 'customers' ) );
			exit;
		}

		$hours = isset( $_POST['hours'] ) ? (float) $_POST['hours'] : 0;

		if ( $hours <= 0 ) {
			$this->redirect_to_package( $package, 'error' );
		}

		KCRM_Pkg_Usage::create(
			array(
				'package_id'  => $package_id,
				'entry_date'  => isset( $_POST['entry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_date'] ) ) : gmdate( 'Y-m-d' ),
				'hours'       => $hours,
				'description' => isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '',
			)
		);

		$remaining = round( (float) $package->allotted_hours - KCRM_Pkg_Usage::hours_logged( $package_id ), 2 );
		$notice    = $remaining < 0 ? 'overage' : 'saved';

		$this->redirect_to_package( $package, $notice );
	}

	private function delete_usage( $usage_id ) {
		check_admin_referer( 'kcrmpkg_delete_usage_' . $usage_id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param used only to redirect back to the right package; the delete itself is already nonce-verified above.
		$package_id = isset( $_GET['package_id'] ) ? absint( $_GET['package_id'] ) : 0;
		$package    = $package_id ? KCRM_Pkg_Package::find( $package_id ) : null;

		KCRM_Pkg_Usage::delete( $usage_id );

		$this->redirect_to_package( $package, 'deleted' );
	}

	private function redirect_to_package( $package, $notice ) {
		if ( ! $package ) {
			wp_safe_redirect( KCRM_Front::endpoint_url( 'customers' ) );
			exit;
		}

		wp_safe_redirect(
			KCRM_Front::endpoint_url(
				'customers',
				array(
					'view'               => 'edit',
					'id'                 => $package->customer_id,
					'tab'                => 'packages',
					'kcrmpkg_package_id' => $package->id,
					'kcrmpkg_notice'     => $notice,
				)
			)
		);
		exit;
	}
}
