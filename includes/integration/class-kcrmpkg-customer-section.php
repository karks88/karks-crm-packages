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
 *   plus Add/Edit Package forms (?kcrmpkg_view=add|edit) and Renew for
 *   term packages, so staff don't need wp-admin access. Deleting a package
 *   stays wp-admin-only via the "Manage Packages" link. Usage submissions
 *   are handled by KCRM_Pkg_Front_Usage, package save/renew by
 *   KCRM_Pkg_Front_Packages.
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
		list( $current ) = $this->split_by_archive_status( KCRM_Pkg_Package::for_customers( $rollup_ids ) );

		$tabs['packages'] = array(
			'label'  => __( 'Packages', 'karks-crm-packages' ),
			'badge'  => count( $current ) ?: null,
			'render' => function () use ( $customer, $rollup_ids ) {
				$this->render_front_tab( $customer, $rollup_ids );
			},
		);
		return $tabs;
	}

	/**
	 * Splits a customer's packages into "current" (active/exhausted -- still
	 * relevant to today) and "archived" (expired/cancelled -- history), so
	 * the front-end tab can show them as two distinct sections instead of
	 * mixing past packages in with the client's current one.
	 *
	 * @param object[] $packages KCRM_Pkg_Package rows.
	 * @return array{0: object[], 1: object[]} array( $current, $archived ).
	 */
	private function split_by_archive_status( array $packages ) {
		$archived_statuses = array( KCRM_Pkg_Package::STATUS_EXPIRED, KCRM_Pkg_Package::STATUS_CANCELLED );

		$current  = array();
		$archived = array();
		foreach ( $packages as $package ) {
			if ( in_array( $package->status, $archived_statuses, true ) ) {
				$archived[] = $package;
			} else {
				$current[] = $package;
			}
		}
		return array( $current, $archived );
	}

	/**
	 * Front-end "Packages" tab content: current packages + usage log, and a
	 * collapsed package history for expired/cancelled ones -- or, with
	 * ?kcrmpkg_view=add|edit, the Add/Edit Package form instead.
	 */
	private function render_front_tab( $customer, $rollup_ids ) {
		$packages = KCRM_Pkg_Package::for_customers( $rollup_ids );

		echo '<h3 id="kcrmpkg-packages">' . esc_html__( 'Packages', 'karks-crm-packages' ) . '</h3>';

		$this->render_front_notice();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-selection param, no state change.
		$view = isset( $_GET['kcrmpkg_view'] ) ? sanitize_key( wp_unslash( $_GET['kcrmpkg_view'] ) ) : '';

		if ( 'add' === $view ) {
			$this->render_front_package_form( $customer, null );
			return;
		}

		if ( 'edit' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-selection param, no state change.
			$edit_id = isset( $_GET['kcrmpkg_package_id'] ) ? absint( $_GET['kcrmpkg_package_id'] ) : 0;
			// Only this client's own (or its Jobs') packages are editable from here.
			foreach ( $packages as $candidate ) {
				if ( (int) $candidate->id === $edit_id ) {
					$this->render_front_package_form( $customer, $candidate );
					return;
				}
			}
		}

		list( $current, $archived ) = $this->split_by_archive_status( $packages );

		printf(
			'<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s"><span class="dashicons dashicons-plus-alt2"></span> %s</a></div>',
			esc_url( $this->front_url( $customer, array( 'kcrmpkg_view' => 'add' ) ) . '#kcrmpkg-packages' ),
			esc_html__( 'Add Package', 'karks-crm-packages' )
		);

		if ( empty( $current ) && ! empty( $archived ) ) {
			echo '<p>' . esc_html__( 'No active packages. See package history below.', 'karks-crm-packages' ) . '</p>';
		} else {
			$this->render_summary( $current, $customer, true );
		}

		if ( ! empty( $current ) ) {
			$this->render_front_usage_section( $customer, $current );
		}

		if ( ! empty( $archived ) ) {
			$this->render_archive_section( $customer, $archived );
		}
	}

	/** Front-end URL back into this client's Packages tab, plus $args. */
	private function front_url( $customer, array $args = array() ) {
		return KCRM_Front::endpoint_url(
			'customers',
			KCRM_Front::nav_nonce_args(
				array_merge(
					array(
						'view' => 'edit',
						'id'   => $customer->id,
						'tab'  => 'packages',
					),
					$args
				)
			)
		);
	}

	/**
	 * Nonce-protected front-end Renew URL for a term package, handled by
	 * KCRM_Pkg_Front_Packages::renew(). With $invoice, it also creates an
	 * invoice for the new term.
	 */
	private function front_renew_url( $customer, $package, $invoice = false ) {
		$args = array(
			'kcrmpkg_action'     => 'renew',
			'kcrmpkg_package_id' => $package->id,
		);
		if ( $invoice ) {
			$args['kcrmpkg_invoice'] = 1;
		}
		return wp_nonce_url( $this->front_url( $customer, $args ), 'kcrmpkg_renew_package_' . $package->id );
	}

	/** Renew + "Renew + Invoice" buttons for a renewable term package. */
	private function render_front_renew_buttons( $customer, $package ) {
		?>
		<a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( $this->front_renew_url( $customer, $package ) ); ?>"
			onclick="return confirm('<?php echo esc_js( __( 'Create the next term for this package?', 'karks-crm-packages' ) ); ?>');">
			<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Renew', 'karks-crm-packages' ); ?>
		</a>
		<?php if ( KCRM_Pkg_Invoice_Bridge::available() ) : ?>
			<a class="kcrm-button" href="<?php echo esc_url( $this->front_renew_url( $customer, $package, true ) ); ?>"
				onclick="return confirm('<?php echo esc_js( __( 'Create the next term for this package and an Open invoice for it?', 'karks-crm-packages' ) ); ?>');">
				<span class="dashicons dashicons-media-text"></span> <?php esc_html_e( 'Renew + Invoice', 'karks-crm-packages' ); ?>
			</a>
		<?php endif; ?>
		<?php
	}

	/**
	 * Front-end Add/Edit Package form, posted to KCRM_Pkg_Front_Packages.
	 * Mirrors the wp-admin form's fields; validation is shared via
	 * KCRM_Pkg_Package::data_from_form(). The Customer select only appears
	 * when the client has Jobs -- otherwise the package can only be for the
	 * client itself.
	 *
	 * @param object      $customer The profile's KCRM_Customer row.
	 * @param object|null $package  The package being edited, or null to add.
	 */
	private function render_front_package_form( $customer, $package ) {
		$v = function ( $field, $default = '' ) use ( $package ) {
			return $package && isset( $package->$field ) && '' !== $package->$field ? $package->$field : $default;
		};

		$jobs     = KCRM_Customer::jobs_for( $customer->id );
		$services = KCRM_Service::for_company( KCRM_Context::get_current_company_id() );
		$cancel   = $this->front_url( $customer, $package ? array( 'kcrmpkg_package_id' => $package->id ) : array() ) . '#kcrmpkg-packages';
		?>
		<h4><?php echo $package ? esc_html__( 'Edit Package', 'karks-crm-packages' ) : esc_html__( 'Add Package', 'karks-crm-packages' ); ?></h4>
		<form method="post" action="<?php echo esc_url( $this->front_url( $customer ) ); ?>" class="kcrm-front-form" id="kcrmpkg-package-form" data-kcrmpkg-package-form>
			<?php wp_nonce_field( 'kcrmpkg_front_save_package' ); ?>
			<input type="hidden" name="kcrmpkg_action" value="save_package">
			<input type="hidden" name="kcrmpkg_profile_id" value="<?php echo esc_attr( $customer->id ); ?>">
			<input type="hidden" name="package_id" value="<?php echo esc_attr( $package ? $package->id : 0 ); ?>">

			<?php if ( $jobs ) : ?>
				<p>
					<label for="kcrmpkg_customer_id"><?php esc_html_e( 'Client / Job', 'karks-crm-packages' ); ?></label>
					<select name="customer_id" id="kcrmpkg_customer_id" required>
						<option value="<?php echo esc_attr( $customer->id ); ?>" <?php selected( (int) $v( 'customer_id', $customer->id ), (int) $customer->id ); ?>><?php echo esc_html( KCRM_Customer::display_name( $customer ) ); ?></option>
						<?php foreach ( $jobs as $job ) : ?>
							<option value="<?php echo esc_attr( $job->id ); ?>" <?php selected( (int) $v( 'customer_id', $customer->id ), (int) $job->id ); ?>>&nbsp;&nbsp;&ndash; <?php echo esc_html( KCRM_Customer::display_name( $job ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php else : ?>
				<input type="hidden" name="customer_id" value="<?php echo esc_attr( $v( 'customer_id', $customer->id ) ); ?>">
			<?php endif; ?>

			<p>
				<label for="kcrmpkg_service_id"><?php esc_html_e( 'Service', 'karks-crm-packages' ); ?></label>
				<select name="service_id" id="kcrmpkg_service_id" required>
					<option value=""><?php esc_html_e( '— Select —', 'karks-crm-packages' ); ?></option>
					<?php foreach ( $services as $service ) : ?>
						<option value="<?php echo esc_attr( $service->id ); ?>" <?php selected( (int) $v( 'service_id' ), (int) $service->id ); ?>><?php echo esc_html( $service->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="kcrmpkg_package_type"><?php esc_html_e( 'Package Type', 'karks-crm-packages' ); ?></label>
				<select name="package_type" id="kcrmpkg_package_type">
					<?php foreach ( KCRM_Pkg_Package::types() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v( 'package_type', KCRM_Pkg_Package::TYPE_HOURS ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="kcrmpkg-hours-row">
				<label for="kcrmpkg_allotted_hours"><?php esc_html_e( 'Allotted Hours', 'karks-crm-packages' ); ?></label>
				<input type="number" step="0.01" min="0" name="allotted_hours" id="kcrmpkg_allotted_hours" value="<?php echo esc_attr( $v( 'allotted_hours', '0' ) ); ?>" required>
			</p>
			<p>
				<label for="kcrmpkg_price_paid"><?php esc_html_e( 'Price Paid', 'karks-crm-packages' ); ?></label>
				<input type="number" step="0.01" min="0" name="price_paid" id="kcrmpkg_price_paid" value="<?php echo esc_attr( $v( 'price_paid', '0' ) ); ?>" required>
			</p>
			<p>
				<label for="kcrmpkg_period_start"><?php esc_html_e( 'Period Start', 'karks-crm-packages' ); ?></label>
				<input type="date" name="period_start" id="kcrmpkg_period_start" value="<?php echo esc_attr( $v( 'period_start', current_time( 'Y-m-d' ) ) ); ?>" required>
			</p>
			<p>
				<label for="kcrmpkg_period_end"><?php esc_html_e( 'Period End', 'karks-crm-packages' ); ?> <span class="kcrmpkg-term-only">*</span></label>
				<input type="date" name="period_end" id="kcrmpkg_period_end" value="<?php echo esc_attr( $v( 'period_end' ) ); ?>">
			</p>
			<p>
				<label for="kcrmpkg_status"><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></label>
				<select name="status" id="kcrmpkg_status">
					<?php foreach ( KCRM_Pkg_Package::statuses() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v( 'status', KCRM_Pkg_Package::STATUS_ACTIVE ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="kcrmpkg_notes"><?php esc_html_e( 'Notes', 'karks-crm-packages' ); ?></label>
				<textarea name="notes" id="kcrmpkg_notes" rows="3"><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea>
			</p>
			<p>
				<button type="submit" class="kcrm-button kcrm-button-primary"><?php echo $package ? esc_html__( 'Update Package', 'karks-crm-packages' ) : esc_html__( 'Add Package', 'karks-crm-packages' ); ?></button>
				<a class="kcrm-button" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'karks-crm-packages' ); ?></a>
			</p>
		</form>
		<?php
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
			// Hours cards total hour-based packages only; term packages have no hours to count.
			$hours_packages = 0;
			$total_allotted = 0;
			$total_used     = 0;
			foreach ( $packages as $package ) {
				if ( KCRM_Pkg_Package::is_term( $package ) ) {
					continue;
				}
				++$hours_packages;
				$total_allotted += (float) $package->allotted_hours;
				$total_used     += KCRM_Pkg_Usage::hours_logged( $package->id );
			}
			$total_remaining = round( $total_allotted - $total_used, 2 );
			?>
			<?php if ( $hours_packages ) : ?>
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
			<?php endif; ?>
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
						$is_term   = KCRM_Pkg_Package::is_term( $package );
						$remaining = $is_term ? 0.0 : round( (float) $package->allotted_hours - KCRM_Pkg_Usage::hours_logged( $package->id ), 2 );
						$service   = $package->service_id ? KCRM_Service::find( $package->service_id ) : null;
						?>
						<tr>
							<td><?php echo esc_html( $service ? $service->name : __( '(unknown service)', 'karks-crm-packages' ) ); ?></td>
							<td>
								<?php if ( $is_term ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: term end date. */
											__( 'Term ends %s', 'karks-crm-packages' ),
											$package->period_end ? $package->period_end : '—'
										)
									);
									echo KCRM_Pkg_Package::renewal_badge_html( $package ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renewal_badge_html().
									?>
								<?php else : ?>
									<?php echo esc_html( number_format( $remaining, 2 ) ); ?>
									<?php if ( $remaining < 0 ) : ?>
										<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#b91c1c;color:#fff;font-size:10px;text-transform:uppercase;margin-left:4px;"><?php esc_html_e( 'OVERAGE', 'karks-crm-packages' ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $statuses[ $package->status ] ?? $package->status ); ?></td>
							<?php if ( $is_front ) : ?>
								<td>
									<a href="<?php echo esc_url( $this->front_url( $customer, array( 'kcrmpkg_package_id' => $package->id ) ) ); ?>#kcrmpkg-usage"><?php echo $is_term ? esc_html__( 'Add Note', 'karks-crm-packages' ) : esc_html__( 'Log Usage', 'karks-crm-packages' ); ?></a>
									|
									<a href="<?php echo esc_url( $this->front_url( $customer, array( 'kcrmpkg_view' => 'edit', 'kcrmpkg_package_id' => $package->id ) ) ); ?>#kcrmpkg-packages"><?php esc_html_e( 'Edit', 'karks-crm-packages' ); ?></a>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		$manage_url = add_query_arg( array( 'page' => 'karks-crm-packages', 'customer_id' => $customer->id ), admin_url( 'admin.php' ) );
		if ( $is_front ) {
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $manage_url ), esc_html__( 'Manage Packages', 'karks-crm-packages' ) );
		} else {
			printf(
				'<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
				esc_url( add_query_arg( 'view', 'add', $manage_url ) ),
				esc_html__( 'Add Package', 'karks-crm-packages' ),
				esc_url( $manage_url ),
				esc_html__( 'Manage Packages', 'karks-crm-packages' )
			);
		}
	}

	/** Shows a notice for the redirect-back kcrmpkg_notice query arg set by KCRM_Pkg_Front_Usage / KCRM_Pkg_Front_Packages. */
	private function render_front_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		if ( empty( $_GET['kcrmpkg_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		$notice = sanitize_key( wp_unslash( $_GET['kcrmpkg_notice'] ) );

		$messages = array(
			'saved'         => array( 'success', __( 'Usage logged successfully.', 'karks-crm-packages' ) ),
			'deleted'       => array( 'success', __( 'Usage entry deleted.', 'karks-crm-packages' ) ),
			'error'         => array( 'error', __( 'Something went wrong. Please try again.', 'karks-crm-packages' ) ),
			'overage'       => array( 'warning', __( 'Usage logged. This package is now over its allotted hours.', 'karks-crm-packages' ) ),
			'noted'         => array( 'success', __( 'Note added.', 'karks-crm-packages' ) ),
			'package_saved' => array( 'success', __( 'Package saved.', 'karks-crm-packages' ) ),
			'renewed'       => array( 'success', __( 'Package renewed. This is the new term.', 'karks-crm-packages' ) ),
			'no_end'        => array( 'error', __( 'Term packages need a Period End date.', 'karks-crm-packages' ) ),
			'renewed_no_invoice' => array( 'warning', __( 'Package renewed, but the invoice could not be created. Please create it by hand.', 'karks-crm-packages' ) ),
		);

		if ( 'renewed_invoiced' === $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', KCRM_Pkg_Invoice_Bridge::renewed_notice_html( true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renewed_notice_html().
		} elseif ( isset( $messages[ $notice ] ) ) {
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

		$screen_url = KCRM_Front::endpoint_url( 'customers', KCRM_Front::nav_nonce_args( array( 'view' => 'edit', 'id' => $customer->id, 'tab' => 'packages' ) ) );
		$entries    = KCRM_Pkg_Usage::for_package( $package->id );
		$service    = $package->service_id ? KCRM_Service::find( $package->service_id ) : null;
		$is_term    = KCRM_Pkg_Package::is_term( $package );
		?>
		<h3 id="kcrmpkg-usage"><?php echo $is_term ? esc_html__( 'Activity Log', 'karks-crm-packages' ) : esc_html__( 'Usage Log', 'karks-crm-packages' ); ?></h3>

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

		<?php
		if ( $is_term ) {
			$this->render_front_term_section( $customer, $package );
		}
		?>

		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'karks-crm-packages' ); ?></th>
					<?php if ( ! $is_term ) : ?>
						<th><?php esc_html_e( 'Hours', 'karks-crm-packages' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Description', 'karks-crm-packages' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $entries ) ) : ?>
					<tr><td colspan="4"><?php echo $is_term ? esc_html__( 'No notes yet.', 'karks-crm-packages' ) : esc_html__( 'No usage logged yet.', 'karks-crm-packages' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry->entry_date ); ?></td>
						<?php if ( ! $is_term ) : ?>
							<td><?php echo esc_html( number_format( (float) $entry->hours, 2 ) ); ?></td>
						<?php endif; ?>
						<td><?php echo esc_html( $entry->description ); ?></td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'kcrmpkg_action' => 'delete_usage', 'kcrmpkg_usage_id' => $entry->id, 'package_id' => $package->id ), $screen_url ), 'kcrmpkg_delete_usage_' . $entry->id ) ); ?>#kcrmpkg-usage"
								onclick="return confirm('<?php echo esc_js( $is_term ? __( 'Delete this note?', 'karks-crm-packages' ) : __( 'Delete this usage entry?', 'karks-crm-packages' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm-packages' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h4><?php echo $is_term ? esc_html__( 'Add Note', 'karks-crm-packages' ) : esc_html__( 'Log Usage', 'karks-crm-packages' ); ?></h4>
		<form method="post" action="<?php echo esc_url( $screen_url . '#kcrmpkg-usage' ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrmpkg_add_usage' ); ?>
			<input type="hidden" name="kcrmpkg_action" value="add_usage">
			<input type="hidden" name="package_id" value="<?php echo esc_attr( $package->id ); ?>">
			<p>
				<label for="kcrmpkg_entry_date"><?php esc_html_e( 'Date', 'karks-crm-packages' ); ?></label>
				<input type="date" name="entry_date" id="kcrmpkg_entry_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
			</p>
			<?php if ( ! $is_term ) : ?>
				<p>
					<label for="kcrmpkg_hours"><?php esc_html_e( 'Hours', 'karks-crm-packages' ); ?></label>
					<input type="number" step="0.01" min="0.01" name="hours" id="kcrmpkg_hours" required>
				</p>
			<?php endif; ?>
			<p>
				<label for="kcrmpkg_description"><?php echo $is_term ? esc_html__( 'Note', 'karks-crm-packages' ) : esc_html__( 'Description', 'karks-crm-packages' ); ?></label>
				<input type="text" name="description" id="kcrmpkg_description" maxlength="255" required>
			</p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php echo $is_term ? esc_html__( 'Add Note', 'karks-crm-packages' ) : esc_html__( 'Log Usage', 'karks-crm-packages' ); ?></button></p>
		</form>

		<p>
			<a class="kcrm-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrmpkg_download_package_pdf&id=' . $package->id ), 'kcrmpkg_download_package_pdf_' . $package->id ) ); ?>">
				<?php esc_html_e( 'Download PDF Report', 'karks-crm-packages' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Front-end term-package cards (term dates, days left) plus Renew, or a
	 * link to the renewal if one exists. Mirrors
	 * KCRM_Pkg_Admin_Packages::render_term_section() with front-end markup
	 * and URLs; renewing is handled by KCRM_Pkg_Front_Packages::renew().
	 */
	private function render_front_term_section( $customer, $package ) {
		$days = KCRM_Pkg_Package::days_until_end( $package );
		?>
		<div class="kcrm-dashboard-cards">
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( $package->period_start . ' – ' . ( $package->period_end ? $package->period_end : '—' ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Term', 'karks-crm-packages' ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( null === $days ? '—' : number_format_i18n( max( 0, $days ) ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Days Remaining', 'karks-crm-packages' ); ?></span>
			</div>
		</div>
		<p>
			<?php if ( ! empty( $package->renewed_by_id ) ) : ?>
				<?php esc_html_e( 'This term has been renewed.', 'karks-crm-packages' ); ?>
				<a href="<?php echo esc_url( $this->front_url( $customer, array( 'kcrmpkg_package_id' => $package->renewed_by_id ) ) ); ?>#kcrmpkg-usage"><?php esc_html_e( 'View next term', 'karks-crm-packages' ); ?> &rarr;</a>
			<?php elseif ( KCRM_Pkg_Package::is_renewable( $package ) ) : ?>
				<?php echo KCRM_Pkg_Package::renewal_badge_html( $package ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renewal_badge_html(). ?>
				<?php $this->render_front_renew_buttons( $customer, $package ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Front-end-only: a collapsed "Package History" list of expired/cancelled
	 * packages, kept out of the current-packages summary above. No "Log
	 * Usage" action here -- archived packages aren't logged against anymore
	 * -- but each row still links to its PDF report so past years stay
	 * accessible, and can be edited (e.g. to correct or reactivate it).
	 * Term packages that expired without being renewed also get Renew here,
	 * since the daily expiry cron moves overdue terms into this list.
	 *
	 * @param object   $customer The profile's KCRM_Customer row.
	 * @param object[] $archived KCRM_Pkg_Package rows, already known non-empty by the caller.
	 */
	private function render_archive_section( $customer, array $archived ) {
		$statuses = KCRM_Pkg_Package::statuses();
		?>
		<details class="kcrmpkg-archive" style="margin-top:48px;">
			<summary style="cursor:pointer;"><?php esc_html_e( 'Package History', 'karks-crm-packages' ); ?> (<?php echo esc_html( count( $archived ) ); ?>)</summary>
			<table class="kcrm-front-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'karks-crm-packages' ); ?></th>
						<th><?php esc_html_e( 'Period', 'karks-crm-packages' ); ?></th>
						<th><?php esc_html_e( 'Remaining', 'karks-crm-packages' ); ?></th>
						<th><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $archived as $package ) : ?>
						<?php
						$is_term   = KCRM_Pkg_Package::is_term( $package );
						$remaining = $is_term ? 0.0 : round( (float) $package->allotted_hours - KCRM_Pkg_Usage::hours_logged( $package->id ), 2 );
						$service   = $package->service_id ? KCRM_Service::find( $package->service_id ) : null;
						?>
						<tr>
							<td><?php echo esc_html( $service ? $service->name : __( '(unknown service)', 'karks-crm-packages' ) ); ?></td>
							<td>
								<?php echo esc_html( $package->period_start ); ?>
								<?php if ( $package->period_end ) : ?>
									&ndash; <?php echo esc_html( $package->period_end ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $is_term ? '—' : number_format( $remaining, 2 ) ); ?></td>
							<td>
								<?php echo esc_html( $statuses[ $package->status ] ?? $package->status ); ?>
								<?php echo KCRM_Pkg_Package::renewal_badge_html( $package ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renewal_badge_html(). ?>
							</td>
							<td>
								<?php if ( KCRM_Pkg_Package::is_renewable( $package ) ) : ?>
									<?php $this->render_front_renew_buttons( $customer, $package ); ?>
								<?php endif; ?>
								<a class="kcrm-button" href="<?php echo esc_url( $this->front_url( $customer, array( 'kcrmpkg_view' => 'edit', 'kcrmpkg_package_id' => $package->id ) ) ); ?>#kcrmpkg-packages">
									<?php esc_html_e( 'Edit', 'karks-crm-packages' ); ?>
								</a>
								<a class="kcrm-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrmpkg_download_package_pdf&id=' . $package->id ), 'kcrmpkg_download_package_pdf_' . $package->id ) ); ?>">
									<?php esc_html_e( 'Download PDF', 'karks-crm-packages' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}
}
