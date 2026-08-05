<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders "Packages" for a customer into karks-crm's customer-edit screen,
 * via two different karks-crm extension points depending on context:
 *
 * - wp-admin: `kcrm_customer_edit_after_sections` (the customer screen
 *   there has no tabs) -- a read-only summary + a link to the full admin
 *   screen. render() is a no-op on the front end now that it has its own
 *   tab below, so this hook still fires there (karks-crm fires it in both
 *   contexts) without double-rendering anything.
 * - front end: `kcrm_customer_profile_tabs`, contributing a "Packages" tab
 *   that additionally renders the usage log and "Log Usage" form inline,
 *   so staff can log hours without needing wp-admin access -- full package
 *   create/edit (allotted hours, price, billing period) stays
 *   wp-admin-only via the "Manage Packages" link in both places.
 *   Submissions are handled by KCRM_Pkg_Front_Usage.
 */
class KCRM_Pkg_Customer_Section {

	/**
	 * Listener for `kcrm_customer_edit_after_sections`. wp-admin only --
	 * the front end gets the same information via its own tab instead
	 * (see register_tab()).
	 *
	 * @param object $customer   The KCRM_Customer row being viewed.
	 * @param array  $rollup_ids The customer's own id plus any Job ids rolled up under it.
	 */
	public function render( $customer, $rollup_ids ) {
		if ( ! is_admin() ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Packages', 'karks-crm-packages' ) . '</h2>';
		$this->render_summary( KCRM_Pkg_Package::for_customers( $rollup_ids ), $customer, false );
	}

	/**
	 * Listener for karks-crm's `kcrm_customer_profile_tabs` filter -- adds
	 * a "Packages" tab to the front-end customer profile screen.
	 *
	 * @param array  $tabs       tab_slug => array( 'label', 'badge', 'render' ).
	 * @param object $customer   The KCRM_Customer row being viewed.
	 * @param array  $rollup_ids The customer's own id plus any Job ids rolled up under it.
	 * @return array
	 */
	public function register_tab( $tabs, $customer, $rollup_ids ) {
		$tabs['packages'] = array(
			'label'  => __( 'Packages', 'karks-crm-packages' ),
			'render' => function () use ( $customer, $rollup_ids ) {
				$this->render_front_tab( $customer, $rollup_ids );
			},
		);
		return $tabs;
	}

	/** Front-end "Packages" tab content: the summary, plus (if any packages exist) the usage log + "Log Usage" form. */
	private function render_front_tab( $customer, $rollup_ids ) {
		$packages = KCRM_Pkg_Package::for_customers( $rollup_ids );

		echo '<h3>' . esc_html__( 'Packages', 'karks-crm-packages' ) . '</h3>';

		$this->render_front_notice();
		$this->render_summary( $packages, $customer, true );

		if ( ! empty( $packages ) ) {
			$this->render_front_usage_section( $customer, $packages );
		}
	}

	/**
	 * The summary cards + packages table + "Manage Packages" link -- shared
	 * by the wp-admin summary and the front-end tab, which differ only in
	 * whether the table gets a "Log Usage" action column.
	 *
	 * @param object[] $packages KCRM_Pkg_Package rows.
	 * @param object   $customer The KCRM_Customer row being viewed.
	 * @param bool     $is_front Whether this is rendering into the front-end tab (adds the per-row "Log Usage" link/column).
	 */
	private function render_summary( array $packages, $customer, $is_front ) {
		if ( empty( $packages ) ) {
			echo '<p>' . esc_html__( 'No maintenance packages yet.', 'karks-crm-packages' ) . '</p>';
		} else {
			$total_allotted = 0;
			$total_used     = 0;
			foreach ( $packages as $package ) {
				$total_allotted += (float) $package->allotted_hours;
				$total_used     += KCRM_Pkg_Usage::hours_logged( $package->id );
			}
			$total_remaining = round( $total_allotted - $total_used, 2 );
			?>
			<div class="kcrm-dashboard-cards">
				<div class="kcrm-card">
					<span class="kcrm-card-number"><?php echo esc_html( number_format( $total_allotted, 2 ) ); ?></span>
					<span class="kcrm-card-label"><?php esc_html_e( 'Hours Allotted', 'karks-crm-packages' ); ?></span>
				</div>
				<div class="kcrm-card">
					<span class="kcrm-card-number"><?php echo esc_html( number_format( $total_used, 2 ) ); ?></span>
					<span class="kcrm-card-label"><?php esc_html_e( 'Hours Used', 'karks-crm-packages' ); ?></span>
				</div>
				<div class="kcrm-card">
					<span class="kcrm-card-number"><?php echo esc_html( number_format( $total_remaining, 2 ) ); ?></span>
					<span class="kcrm-card-label"><?php esc_html_e( 'Hours Remaining', 'karks-crm-packages' ); ?></span>
				</div>
			</div>
			<table class="<?php echo esc_attr( $is_front ? 'kcrm-front-table' : 'wp-list-table widefat fixed striped' ); ?>" <?php echo $is_front ? '' : 'style="max-width:700px;"'; ?>>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'karks-crm-packages' ); ?></th>
						<th><?php esc_html_e( 'Remaining', 'karks-crm-packages' ); ?></th>
						<th><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></th>
						<?php if ( $is_front ) : ?>
							<th></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php $statuses = KCRM_Pkg_Package::statuses(); ?>
					<?php foreach ( $packages as $package ) : ?>
						<?php
						$remaining = round( (float) $package->allotted_hours - KCRM_Pkg_Usage::hours_logged( $package->id ), 2 );
						$service   = $package->service_id ? KCRM_Service::find( $package->service_id ) : null;
						?>
						<tr>
							<td><?php echo esc_html( $service ? $service->name : __( '(unknown service)', 'karks-crm-packages' ) ); ?></td>
							<td>
								<?php echo esc_html( number_format( $remaining, 2 ) ); ?>
								<?php if ( $remaining < 0 ) : ?>
									<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#b91c1c;color:#fff;font-size:10px;text-transform:uppercase;margin-left:4px;"><?php esc_html_e( 'OVERAGE', 'karks-crm-packages' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $statuses[ $package->status ] ?? $package->status ); ?></td>
							<?php if ( $is_front ) : ?>
								<td><a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'customers', array( 'view' => 'edit', 'id' => $customer->id, 'tab' => 'packages', 'kcrmpkg_package_id' => $package->id ) ) ); ?>#kcrmpkg-usage"><?php esc_html_e( 'Log Usage', 'karks-crm-packages' ); ?></a></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( add_query_arg( array( 'page' => 'karks-crm-packages', 'customer_id' => $customer->id ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Manage Packages', 'karks-crm-packages' )
		);
	}

	/** Shows a notice for the redirect-back kcrmpkg_notice query arg set by KCRM_Pkg_Front_Usage. */
	private function render_front_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		if ( empty( $_GET['kcrmpkg_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		$notice = sanitize_key( wp_unslash( $_GET['kcrmpkg_notice'] ) );

		$messages = array(
			'saved'   => array( 'success', __( 'Usage logged successfully.', 'karks-crm-packages' ) ),
			'deleted' => array( 'success', __( 'Usage entry deleted.', 'karks-crm-packages' ) ),
			'error'   => array( 'error', __( 'Something went wrong. Please try again.', 'karks-crm-packages' ) ),
			'overage' => array( 'warning', __( 'Usage logged. This package is now over its allotted hours.', 'karks-crm-packages' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $message ) = $messages[ $notice ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}

	/**
	 * Front-end-only: the usage log + "Log Usage" form for one of the
	 * customer's packages, selected via ?kcrmpkg_package_id= (defaulting
	 * to the first package). Submissions post back to this same
	 * front-end customer URL and are handled by KCRM_Pkg_Front_Usage.
	 *
	 * @param array $packages KCRM_Pkg_Package rows, already known non-empty by the caller.
	 */
	private function render_front_usage_section( $customer, $packages ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-selection param, no state change.
		$selected_id = isset( $_GET['kcrmpkg_package_id'] ) ? absint( $_GET['kcrmpkg_package_id'] ) : 0;

		$package = null;
		foreach ( $packages as $candidate ) {
			if ( (int) $candidate->id === $selected_id ) {
				$package = $candidate;
				break;
			}
		}
		if ( ! $package ) {
			$package = $packages[0];
		}

		$screen_url = KCRM_Front::endpoint_url( 'customers', array( 'view' => 'edit', 'id' => $customer->id, 'tab' => 'packages' ) );
		$entries    = KCRM_Pkg_Usage::for_package( $package->id );
		$service    = $package->service_id ? KCRM_Service::find( $package->service_id ) : null;
		?>
		<h3 id="kcrmpkg-usage"><?php esc_html_e( 'Usage Log', 'karks-crm-packages' ); ?></h3>

		<?php if ( count( $packages ) > 1 ) : ?>
			<p>
				<label for="kcrmpkg-package-select"><?php esc_html_e( 'Package:', 'karks-crm-packages' ); ?></label>
				<select id="kcrmpkg-package-select" onchange="if (this.value) { window.location.href = this.value; }">
					<?php foreach ( $packages as $candidate ) : ?>
						<?php $candidate_service = $candidate->service_id ? KCRM_Service::find( $candidate->service_id ) : null; ?>
						<option value="<?php echo esc_url( add_query_arg( 'kcrmpkg_package_id', $candidate->id, $screen_url ) . '#kcrmpkg-usage' ); ?>" <?php selected( (int) $candidate->id, (int) $package->id ); ?>>
							<?php echo esc_html( $candidate_service ? $candidate_service->name : __( '(unknown service)', 'karks-crm-packages' ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php elseif ( $service ) : ?>
			<p><?php echo esc_html( $service->name ); ?></p>
		<?php endif; ?>

		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Hours', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Description', 'karks-crm-packages' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $entries ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No usage logged yet.', 'karks-crm-packages' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry->entry_date ); ?></td>
						<td><?php echo esc_html( number_format( (float) $entry->hours, 2 ) ); ?></td>
						<td><?php echo esc_html( $entry->description ); ?></td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'kcrmpkg_action' => 'delete_usage', 'kcrmpkg_usage_id' => $entry->id, 'package_id' => $package->id ), $screen_url ), 'kcrmpkg_delete_usage_' . $entry->id ) ); ?>#kcrmpkg-usage"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this usage entry?', 'karks-crm-packages' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm-packages' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h4><?php esc_html_e( 'Log Usage', 'karks-crm-packages' ); ?></h4>
		<form method="post" action="<?php echo esc_url( $screen_url . '#kcrmpkg-usage' ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrmpkg_add_usage' ); ?>
			<input type="hidden" name="kcrmpkg_action" value="add_usage">
			<input type="hidden" name="package_id" value="<?php echo esc_attr( $package->id ); ?>">
			<p>
				<label for="kcrmpkg_entry_date"><?php esc_html_e( 'Date', 'karks-crm-packages' ); ?></label>
				<input type="date" name="entry_date" id="kcrmpkg_entry_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
			</p>
			<p>
				<label for="kcrmpkg_hours"><?php esc_html_e( 'Hours', 'karks-crm-packages' ); ?></label>
				<input type="number" step="0.01" min="0.01" name="hours" id="kcrmpkg_hours" required>
			</p>
			<p>
				<label for="kcrmpkg_description"><?php esc_html_e( 'Description', 'karks-crm-packages' ); ?></label>
				<input type="text" name="description" id="kcrmpkg_description" required>
			</p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Log Usage', 'karks-crm-packages' ); ?></button></p>
		</form>
		<?php
	}
}
