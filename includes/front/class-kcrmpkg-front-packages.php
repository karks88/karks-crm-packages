<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles "Add/Edit Package", "Renew" and "Renew + Invoice" submissions from the front-end
 * customer screen's Packages tab (KCRM_Pkg_Customer_Section), so staff can
 * manage packages without wp-admin access. Delete stays wp-admin-only, since
 * it also deletes the package's usage log.
 *
 * Field validation is shared with wp-admin via
 * KCRM_Pkg_Package::data_from_form(); the nonce, capability and scope checks
 * and the redirects are this class's own. Like KCRM_Pkg_Front_Usage, it uses
 * kcrmpkg_-prefixed query args so it can't collide with karks-crm's own
 * action/id handling on the same customers-endpoint URL.
 *
 * Scope: a package may only be saved for the profile's client or one of its
 * Jobs, within the current company -- the front end has no company-wide
 * package screen, so nothing here should reach outside the profile being
 * viewed.
 */
class KCRM_Pkg_Front_Packages {

	public function handle_actions() {
		if ( ! KCRM_Front::is_crm_page() || ! is_user_logged_in() || ! current_user_can( KCRM_CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrmpkg_action'] ) && 'save_package' === $_POST['kcrmpkg_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; renew() verifies the nonce itself.
		if ( isset( $_GET['kcrmpkg_action'], $_GET['kcrmpkg_package_id'] ) && 'renew' === $_GET['kcrmpkg_action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; renew() verifies the nonce itself.
			$this->renew( absint( $_GET['kcrmpkg_package_id'] ) );
		}
	}

	private function save() {
		check_admin_referer( 'kcrmpkg_front_save_package' );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		$company_id = KCRM_Context::get_current_company_id();
		$profile    = $this->profile_customer( isset( $_POST['kcrmpkg_profile_id'] ) ? absint( $_POST['kcrmpkg_profile_id'] ) : 0, $company_id );
		if ( ! $profile ) {
			wp_safe_redirect( KCRM_Front::endpoint_url( 'customers' ) );
			exit;
		}

		$id      = isset( $_POST['package_id'] ) ? absint( $_POST['package_id'] ) : 0;
		$package = $id ? KCRM_Pkg_Package::find( $id ) : null;
		if ( $id && ( ! $package || ! $this->in_scope( $package->customer_id, $profile, $company_id ) ) ) {
			$this->redirect( $profile, array( 'kcrmpkg_notice' => 'error' ) );
		}

		$back = $id ? array( 'kcrmpkg_view' => 'edit', 'kcrmpkg_package_id' => $id ) : array( 'kcrmpkg_view' => 'add' );

		$data = KCRM_Pkg_Package::data_from_form( wp_unslash( $_POST ), $company_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized individually inside data_from_form().
		if ( ! is_array( $data ) ) {
			$this->redirect( $profile, array_merge( $back, array( 'kcrmpkg_notice' => $data ) ) );
		}

		if ( ! $this->in_scope( $data['customer_id'], $profile, $company_id ) ) {
			$this->redirect( $profile, array_merge( $back, array( 'kcrmpkg_notice' => 'error' ) ) );
		}

		if ( $id ) {
			KCRM_Pkg_Package::save( $id, $data );
		} else {
			$id = KCRM_Pkg_Package::create( $data );
		}

		$this->redirect(
			$profile,
			array(
				'kcrmpkg_package_id' => $id,
				'kcrmpkg_notice'     => $id ? 'package_saved' : 'error',
			)
		);
	}

	private function renew( $id ) {
		check_admin_referer( 'kcrmpkg_renew_package_' . $id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		$company_id = KCRM_Context::get_current_company_id();
		$package    = $id ? KCRM_Pkg_Package::find( $id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param naming the profile to return to; the renew itself is nonce-verified above and scope-checked below.
		$profile = $this->profile_customer( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0, $company_id );

		if ( ! $package || ! $profile || ! $this->in_scope( $package->customer_id, $profile, $company_id ) ) {
			wp_safe_redirect( KCRM_Front::endpoint_url( 'customers' ) );
			exit;
		}

		$new_id = KCRM_Pkg_Package::renew( $package );

		// Only once renew() has claimed the old term, so a repeat click can't invoice twice.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified at the top of this method.
		if ( $new_id && ! empty( $_GET['kcrmpkg_invoice'] ) ) {
			$invoice_id = KCRM_Pkg_Invoice_Bridge::create_for_term( KCRM_Pkg_Package::find( $new_id ) );
			$this->redirect(
				$profile,
				$invoice_id
					? array( 'kcrmpkg_package_id' => $new_id, 'kcrmpkg_notice' => 'renewed_invoiced', 'kcrmpkg_invoice_id' => $invoice_id )
					: array( 'kcrmpkg_package_id' => $new_id, 'kcrmpkg_notice' => 'renewed_no_invoice' )
			);
		}

		$this->redirect(
			$profile,
			$new_id
				? array( 'kcrmpkg_package_id' => $new_id, 'kcrmpkg_notice' => 'renewed' )
				: array( 'kcrmpkg_package_id' => $id, 'kcrmpkg_notice' => 'error' )
		);
	}

	/** The client whose profile is being viewed, if it exists and belongs to the current company. */
	private function profile_customer( $profile_id, $company_id ) {
		$profile = $profile_id ? KCRM_Customer::find( $profile_id ) : null;
		return ( $profile && $company_id && (int) $profile->company_id === (int) $company_id ) ? $profile : null;
	}

	/** Whether $customer_id is the profile client itself or one of its Jobs, within the current company. */
	private function in_scope( $customer_id, $profile, $company_id ) {
		if ( (int) $customer_id === (int) $profile->id ) {
			return true;
		}
		$customer = KCRM_Customer::find( $customer_id );
		return $customer
			&& (int) $customer->company_id === (int) $company_id
			&& (int) $customer->parent_customer_id === (int) $profile->id;
	}

	/** Back to the profile's Packages tab, plus $args. Ends the request. */
	private function redirect( $profile, array $args ) {
		$url = KCRM_Front::endpoint_url(
			'customers',
			KCRM_Front::nav_nonce_args(
				array_merge(
					array(
						'view' => 'edit',
						'id'   => $profile->id,
						'tab'  => 'packages',
					),
					$args
				)
			)
		);
		wp_safe_redirect( $url . '#kcrmpkg-packages' );
		exit;
	}
}
