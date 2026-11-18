<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Pkg_Admin_Packages extends KCRM_Pkg_Controller_Base {

	public function register_menu() {
		add_submenu_page(
			'karks-crm',
			__( 'Packages', 'karks-crm-packages' ),
			__( 'Packages', 'karks-crm-packages' ),
			KCRM_CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function handle_actions() {
		if ( ! is_admin() || ! current_user_can( KCRM_CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route dispatch; real nonce checks happen in the handler methods below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrmpkg_action'] ) && 'save_package' === $_POST['kcrmpkg_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; add_usage() verifies the nonce itself.
		if ( isset( $_POST['kcrmpkg_action'] ) && 'add_usage' === $_POST['kcrmpkg_action'] ) {
			$this->add_usage();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
			$this->delete( absint( $_GET['id'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete_usage() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['usage_id'] ) && 'delete_usage' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete_usage() verifies the nonce itself.
			$this->delete_usage( absint( $_GET['usage_id'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; renew() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'renew' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; renew() verifies the nonce itself.
			$this->renew( absint( $_GET['id'] ) );
		}
	}

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view     = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
		$customer = $this->title_customer( $view );

		echo '<div class="wrap kcrmpkg-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Packages', 'karks-crm-packages' );
		if ( $customer ) {
			echo ': ' . esc_html( KCRM_Customer::display_name( $customer ) );
		}
		echo '</h1>';
		if ( 'list' === $view ) {
			// On a client-scoped list, carry the client into the add form so it's pre-selected.
			$add_args = $customer ? array( 'view' => 'add', 'customer_id' => $customer->id ) : array( 'view' => 'add' );
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( $this->screen_url( $add_args ) ), $customer ? esc_html__( 'Add Package', 'karks-crm-packages' ) : esc_html__( 'Add New', 'karks-crm-packages' ) );
		}
		echo '<hr class="wp-header-end">';

		$this->render_notice_from_query();

		if ( ! $this->current_company_id() ) {
			echo '<p>' . esc_html__( 'Create a company in Karks CRM first.', 'karks-crm-packages' ) . '</p></div>';
			return;
		}

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * The customer to name in the page title, if the current view is scoped to one:
	 * the `customer_id` list/add filter, or (on edit) the package's own customer.
	 */
	private function title_customer( $view ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display param, no state change.
		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;

		if ( ! $customer_id && 'edit' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display param, no state change.
			$id          = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			$package     = $id ? KCRM_Pkg_Package::find( $id ) : null;
			$customer_id = $package ? (int) $package->customer_id : 0;
		}

		return $customer_id ? KCRM_Customer::find( $customer_id ) : null;
	}

	private function render_list() {
		$company_id = $this->current_company_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;

		$packages = $customer_id ? KCRM_Pkg_Package::for_customer( $customer_id ) : KCRM_Pkg_Package::for_company( $company_id );
		$statuses = KCRM_Pkg_Package::statuses();

		$customers_by_id = array();
		$services_by_id  = array();
		foreach ( $packages as $package ) {
			if ( ! isset( $customers_by_id[ $package->customer_id ] ) ) {
				$customers_by_id[ $package->customer_id ] = KCRM_Customer::find( $package->customer_id );
			}
			if ( $package->service_id && ! isset( $services_by_id[ $package->service_id ] ) ) {
				$services_by_id[ $package->service_id ] = KCRM_Service::find( $package->service_id );
			}
		}

		// Alphabetically by the customer's company name, per-request -- fetched
		// via for_company()/for_customer() above sorted by period_start, so this
		// re-sorts in PHP rather than adding a cross-table SQL join.
		usort(
			$packages,
			static function ( $a, $b ) use ( $customers_by_id ) {
				$name_a = isset( $customers_by_id[ $a->customer_id ] ) ? $customers_by_id[ $a->customer_id ]->company_name : '';
				$name_b = isset( $customers_by_id[ $b->customer_id ] ) ? $customers_by_id[ $b->customer_id ]->company_name : '';
				return strcasecmp( $name_a, $name_b );
			}
		);
		?>
		<style>
			/* Highlight the hovered package row; the whole row opens the package (see script below). */
			.kcrmpkg-list tbody tr.kcrmpkg-row { cursor: pointer; }
			.kcrmpkg-list tbody tr.kcrmpkg-row:hover,
			.kcrmpkg-list tbody tr.kcrmpkg-row:focus-within { background-color: #f0f6fc; }
			.kcrmpkg-list tbody tr.kcrmpkg-row:hover td:first-child,
			.kcrmpkg-list tbody tr.kcrmpkg-row:focus-within td:first-child { box-shadow: inset 4px 0 0 #2271b1; }
		</style>
		<table class="wp-list-table widefat fixed striped kcrmpkg-list">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Customer', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Service', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Allotted', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Used', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Remaining', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $packages ) ) : ?>
					<tr>
						<td colspan="7">
							<?php if ( $customer_id ) : ?>
								<?php esc_html_e( 'No packages yet for this client.', 'karks-crm-packages' ); ?>
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'add', 'customer_id' => $customer_id ) ) ); ?>"><?php esc_html_e( 'Add the first one', 'karks-crm-packages' ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'No packages yet.', 'karks-crm-packages' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $packages as $package ) : ?>
					<?php
					$customer  = $customers_by_id[ $package->customer_id ] ?? null;
					$service   = $package->service_id ? ( $services_by_id[ $package->service_id ] ?? null ) : null;
					$is_term   = KCRM_Pkg_Package::is_term( $package );
					$used      = $is_term ? 0.0 : KCRM_Pkg_Usage::hours_logged( $package->id );
					$remaining = round( (float) $package->allotted_hours - $used, 2 );
					?>
					<tr class="kcrmpkg-row" data-href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->id ) ) ); ?>">
						<td><?php echo $customer ? esc_html( KCRM_Customer::display_name( $customer ) ) : esc_html__( '(unknown)', 'karks-crm-packages' ); ?></td>
						<td><a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->id ) ) ); ?>"><?php echo esc_html( $service ? $service->name : __( '(unknown service)', 'karks-crm-packages' ) ); ?></a></td>
						<?php if ( $is_term ) : ?>
							<td colspan="3">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: term start date, 2: term end date. */
										__( 'Term: %1$s – %2$s', 'karks-crm-packages' ),
										$package->period_start,
										$package->period_end ? $package->period_end : '—'
									)
								);
								echo KCRM_Pkg_Package::renewal_badge_html( $package ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renewal_badge_html().
								?>
							</td>
						<?php else : ?>
							<td><?php echo esc_html( number_format( (float) $package->allotted_hours, 2 ) ); ?></td>
							<td><?php echo esc_html( number_format( $used, 2 ) ); ?></td>
							<td>
								<?php echo esc_html( number_format( $remaining, 2 ) ); ?>
								<?php if ( $remaining < 0 ) : ?>
									<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#b91c1c;color:#fff;font-size:10px;text-transform:uppercase;margin-left:4px;"><?php esc_html_e( 'OVERAGE', 'karks-crm-packages' ); ?></span>
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td><?php echo esc_html( $statuses[ $package->status ] ?? $package->status ); ?></td>
						<td>
							<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm-packages' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $package->id ) ), 'kcrmpkg_delete_package_' . $package->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this package and all of its usage entries?', 'karks-crm-packages' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm-packages' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<script>
			( function () {
				// Clicking anywhere on a package row opens it, unless the click was on a link
				// (Edit/Delete keep their own behavior) or the user is selecting text.
				// Ctrl/Cmd/middle-click opens it in a new tab, like a normal link.
				function open( event, row ) {
					if ( event.target.closest( 'a, button, input, select, textarea' ) ) {
						return;
					}
					if ( window.getSelection && String( window.getSelection() ).length ) {
						return;
					}
					if ( event.ctrlKey || event.metaKey || 1 === event.button ) {
						window.open( row.dataset.href, '_blank' );
					} else {
						window.location.href = row.dataset.href;
					}
				}
				document.querySelectorAll( '.kcrmpkg-list tr.kcrmpkg-row' ).forEach( function ( row ) {
					row.addEventListener( 'click', function ( event ) { open( event, row ); } );
					row.addEventListener( 'auxclick', function ( event ) {
						if ( 1 === event.button ) {
							open( event, row );
						}
					} );
				} );
			} )();
		</script>
		<?php
	}

	private function render_form( $view ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$package = $id ? KCRM_Pkg_Package::find( $id ) : null;

		if ( 'edit' === $view && ! $package ) {
			echo '<p>' . esc_html__( 'Package not found.', 'karks-crm-packages' ) . '</p>';
			return;
		}

		$company_id = $this->current_company_id();

		$v = function ( $field, $default = '' ) use ( $package ) {
			return $package && isset( $package->$field ) && '' !== $package->$field ? $package->$field : $default;
		};

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill param, no state change.
		$prefill_customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;

		$customers = KCRM_Customer::for_company( $company_id );
		$services  = KCRM_Service::for_company( $company_id );
		$statuses  = KCRM_Pkg_Package::statuses();

		$customer         = $package ? KCRM_Customer::find( $package->customer_id ) : null;
		$prefill_customer = ( ! $package && $prefill_customer_id ) ? KCRM_Customer::find( $prefill_customer_id ) : null;
		?>
		<h2><?php echo $id ? esc_html__( 'Edit Package', 'karks-crm-packages' ) : esc_html__( 'Add Package', 'karks-crm-packages' ); ?></h2>
		<?php if ( $prefill_customer ) : ?>
			<p>
				<a href="<?php echo esc_url( $this->screen_url( array( 'customer_id' => $prefill_customer->id ) ) ); ?>">
					&larr;
					<?php
					/* translators: %s: client name. */
					echo esc_html( sprintf( __( 'Packages for %s', 'karks-crm-packages' ), KCRM_Customer::display_name( $prefill_customer ) ) );
					?>
				</a>
			</p>
		<?php endif; ?>
		<?php if ( $customer ) : ?>
			<p>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'karks-crm-customers', 'view' => 'edit', 'id' => $customer->id ), admin_url( 'admin.php' ) ) ); ?>">
					&larr; <?php echo esc_html( KCRM_Customer::display_name( $customer ) ); ?>
				</a>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>">
			<?php wp_nonce_field( 'kcrmpkg_save_package' ); ?>
			<input type="hidden" name="kcrmpkg_action" value="save_package">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="customer_id"><?php esc_html_e( 'Customer', 'karks-crm-packages' ); ?> *</label></th>
					<td>
						<select name="customer_id" id="customer_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'karks-crm-packages' ); ?></option>
							<?php foreach ( $customers as $customer ) : ?>
								<option value="<?php echo esc_attr( $customer->id ); ?>" <?php selected( (int) $v( 'customer_id', $prefill_customer_id ), (int) $customer->id ); ?>>
									<?php echo esc_html( KCRM_Customer::display_name( $customer ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="service_id"><?php esc_html_e( 'Service', 'karks-crm-packages' ); ?> *</label></th>
					<td>
						<select name="service_id" id="service_id" required>
							<option value=""><?php esc_html_e( '— Select —', 'karks-crm-packages' ); ?></option>
							<?php foreach ( $services as $service ) : ?>
								<option value="<?php echo esc_attr( $service->id ); ?>" <?php selected( (int) $v( 'service_id' ), (int) $service->id ); ?>>
									<?php echo esc_html( $service->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The package is labeled by this Service everywhere it\'s shown -- there\'s no separate name field.', 'karks-crm-packages' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="package_type"><?php esc_html_e( 'Package Type', 'karks-crm-packages' ); ?></label></th>
					<td>
						<select name="package_type" id="package_type">
							<?php foreach ( KCRM_Pkg_Package::types() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v( 'package_type', KCRM_Pkg_Package::TYPE_HOURS ), $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Term packages are defined by their start/end dates instead of hours, and can be renewed into the next term.', 'karks-crm-packages' ); ?></p>
					</td>
				</tr>
				<tr class="kcrmpkg-hours-row">
					<th><label for="allotted_hours"><?php esc_html_e( 'Allotted Hours', 'karks-crm-packages' ); ?> *</label></th>
					<td><input type="number" step="0.01" min="0" name="allotted_hours" id="allotted_hours" value="<?php echo esc_attr( $v( 'allotted_hours', '0' ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="price_paid"><?php esc_html_e( 'Price Paid', 'karks-crm-packages' ); ?> *</label></th>
					<td><input type="number" step="0.01" min="0" name="price_paid" id="price_paid" value="<?php echo esc_attr( $v( 'price_paid', '0' ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="period_start"><?php esc_html_e( 'Period Start', 'karks-crm-packages' ); ?> *</label></th>
					<td><input type="date" name="period_start" id="period_start" value="<?php echo esc_attr( $v( 'period_start', gmdate( 'Y-m-d' ) ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="period_end"><?php esc_html_e( 'Period End', 'karks-crm-packages' ); ?> <span class="kcrmpkg-term-only">*</span></label></th>
					<td><input type="date" name="period_end" id="period_end" value="<?php echo esc_attr( $v( 'period_end' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="status"><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></label></th>
					<td>
						<select name="status" id="status">
							<?php foreach ( $statuses as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v( 'status', KCRM_Pkg_Package::STATUS_ACTIVE ), $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="notes"><?php esc_html_e( 'Notes', 'karks-crm-packages' ); ?></label></th>
					<td><textarea class="large-text" rows="3" name="notes" id="notes"><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button( $id ? __( 'Update Package', 'karks-crm-packages' ) : __( 'Add Package', 'karks-crm-packages' ) ); ?>
		</form>
		<script>
			( function () {
				// Term packages have no hours: hide/unrequire Allotted Hours and require Period End instead. save() enforces the same rules server-side.
				var type  = document.getElementById( 'package_type' );
				var hours = document.getElementById( 'allotted_hours' );
				var end   = document.getElementById( 'period_end' );
				function sync() {
					var isTerm = 'term' === type.value;
					document.querySelectorAll( '.kcrmpkg-hours-row' ).forEach( function ( row ) { row.style.display = isTerm ? 'none' : ''; } );
					document.querySelectorAll( '.kcrmpkg-term-only' ).forEach( function ( el ) { el.style.display = isTerm ? '' : 'none'; } );
					hours.required = ! isTerm;
					end.required   = isTerm;
				}
				type.addEventListener( 'change', sync );
				sync();
			} )();
		</script>

		<?php if ( $package ) : ?>
			<?php $this->render_usage_section( $package ); ?>
		<?php endif; ?>
		<?php
	}

	private function render_usage_section( $package ) {
		$is_term   = KCRM_Pkg_Package::is_term( $package );
		$entries   = KCRM_Pkg_Usage::for_package( $package->id );
		$used      = $is_term ? 0.0 : KCRM_Pkg_Usage::hours_logged( $package->id );
		$remaining = round( (float) $package->allotted_hours - $used, 2 );

		if ( $is_term ) {
			$this->render_term_section( $package );
		}
		?>
		<h2><?php echo $is_term ? esc_html__( 'Activity Log', 'karks-crm-packages' ) : esc_html__( 'Usage Log', 'karks-crm-packages' ); ?></h2>

		<?php if ( ! $is_term && $remaining < 0 ) : ?>
			<div class="notice notice-error inline">
				<p>
					<?php
					printf(
						/* translators: %s: number of hours over the allotment. */
						esc_html__( 'This package is over its allotted hours by %s.', 'karks-crm-packages' ),
						esc_html( number_format( abs( $remaining ), 2 ) )
					);
					?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'karks-crm-invoices', 'view' => 'add', 'customer_id' => $package->customer_id ), admin_url( 'admin.php' ) ) ); ?>">
						<?php esc_html_e( 'Create Overage Invoice', 'karks-crm-packages' ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! $is_term ) : ?>
			<div class="kcrm-dashboard-cards">
				<div class="kcrm-card">
					<span class="kcrm-card-number"><?php echo esc_html( number_format( (float) $package->allotted_hours, 2 ) ); ?></span>
					<span class="kcrm-card-label"><?php esc_html_e( 'Hours Allotted', 'karks-crm-packages' ); ?></span>
				</div>
				<div class="kcrm-card">
					<span class="kcrm-card-number"><?php echo esc_html( number_format( $used, 2 ) ); ?></span>
					<span class="kcrm-card-label"><?php esc_html_e( 'Hours Used', 'karks-crm-packages' ); ?></span>
				</div>
				<div class="kcrm-card">
					<span class="kcrm-card-number"><?php echo esc_html( number_format( $remaining, 2 ) ); ?></span>
					<span class="kcrm-card-label"><?php esc_html_e( 'Hours Remaining', 'karks-crm-packages' ); ?></span>
				</div>
			</div>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
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
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->id, 'action' => 'delete_usage', 'usage_id' => $entry->id ) ), 'kcrmpkg_delete_usage_' . $entry->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( $is_term ? __( 'Delete this note?', 'karks-crm-packages' ) : __( 'Delete this usage entry?', 'karks-crm-packages' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm-packages' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php echo $is_term ? esc_html__( 'Add Note', 'karks-crm-packages' ) : esc_html__( 'Log Usage', 'karks-crm-packages' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->id ) ) ); ?>">
			<?php wp_nonce_field( 'kcrmpkg_add_usage' ); ?>
			<input type="hidden" name="kcrmpkg_action" value="add_usage">
			<input type="hidden" name="package_id" value="<?php echo esc_attr( $package->id ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="entry_date"><?php esc_html_e( 'Date', 'karks-crm-packages' ); ?></label></th>
					<td><input type="date" name="entry_date" id="entry_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required></td>
				</tr>
				<?php if ( ! $is_term ) : ?>
					<tr>
						<th><label for="hours"><?php esc_html_e( 'Hours', 'karks-crm-packages' ); ?></label></th>
						<td><input type="number" step="0.01" min="0.01" name="hours" id="hours" required></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><label for="description"><?php echo $is_term ? esc_html__( 'Note', 'karks-crm-packages' ) : esc_html__( 'Description', 'karks-crm-packages' ); ?></label></th>
					<td><input type="text" class="regular-text" name="description" id="description" maxlength="255" required></td>
				</tr>
			</table>
			<?php submit_button( $is_term ? __( 'Add Note', 'karks-crm-packages' ) : __( 'Log Usage', 'karks-crm-packages' ) ); ?>
		</form>

		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrmpkg_download_package_pdf&id=' . $package->id ), 'kcrmpkg_download_package_pdf_' . $package->id ) ); ?>">
				<?php esc_html_e( 'Download PDF Report', 'karks-crm-packages' ); ?>
			</a>
		</p>
		<?php
	}

	/** Term-package cards (term dates, days left) plus the Renew button, or a link to the renewal if one exists. */
	private function render_term_section( $package ) {
		$days = KCRM_Pkg_Package::days_until_end( $package );
		?>
		<h2><?php esc_html_e( 'Term', 'karks-crm-packages' ); ?></h2>
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
				<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->renewed_by_id ) ) ); ?>"><?php esc_html_e( 'View next term', 'karks-crm-packages' ); ?> &rarr;</a>
			<?php elseif ( KCRM_Pkg_Package::is_renewable( $package ) ) : ?>
				<?php echo KCRM_Pkg_Package::renewal_badge_html( $package ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renewal_badge_html(). ?>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'renew', 'id' => $package->id ) ), 'kcrmpkg_renew_package_' . $package->id ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Create the next term for this package?', 'karks-crm-packages' ) ); ?>');">
					<?php esc_html_e( 'Renew', 'karks-crm-packages' ); ?>
				</a>
				<?php if ( KCRM_Pkg_Invoice_Bridge::available() ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'renew', 'id' => $package->id, 'invoice' => 1 ) ), 'kcrmpkg_renew_package_' . $package->id ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Create the next term for this package and an Open invoice for it?', 'karks-crm-packages' ) ); ?>');">
						<?php esc_html_e( 'Renew + Invoice', 'karks-crm-packages' ); ?>
					</a>
				<?php endif; ?>
			<?php endif; ?>
		</p>
		<?php
	}

	private function renew( $id ) {
		check_admin_referer( 'kcrmpkg_renew_package_' . $id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		$package = $id ? KCRM_Pkg_Package::find( $id ) : null;
		$new_id  = $package ? KCRM_Pkg_Package::renew( $package ) : false;

		if ( ! $new_id ) {
			$this->redirect( array( 'view' => 'edit', 'id' => $id, 'kcrmpkg_notice' => 'error' ) );
		}

		// Only reached once renew() has claimed the old term, so a repeat click can't invoice twice.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified at the top of this method.
		if ( ! empty( $_GET['invoice'] ) ) {
			$invoice_id = KCRM_Pkg_Invoice_Bridge::create_for_term( KCRM_Pkg_Package::find( $new_id ) );
			$this->redirect(
				$invoice_id
					? array( 'view' => 'edit', 'id' => $new_id, 'kcrmpkg_notice' => 'renewed_invoiced', 'kcrmpkg_invoice_id' => $invoice_id )
					: array( 'view' => 'edit', 'id' => $new_id, 'kcrmpkg_notice' => 'renewed_no_invoice' )
			);
		}

		$this->redirect( array( 'view' => 'edit', 'id' => $new_id, 'kcrmpkg_notice' => 'renewed' ) );
	}

	private function save() {
		check_admin_referer( 'kcrmpkg_save_package' );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$company_id = $this->current_company_id();

		if ( ! $company_id ) {
			$this->redirect( array( 'kcrmpkg_notice' => 'error' ) );
		}

		// Field validation/sanitizing is shared with the front-end handler (KCRM_Pkg_Front_Packages) so the two can't drift.
		$data = KCRM_Pkg_Package::data_from_form( wp_unslash( $_POST ), $company_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized individually inside data_from_form().
		if ( ! is_array( $data ) ) {
			$back = $id ? array( 'view' => 'edit', 'id' => $id ) : array( 'view' => 'add', 'customer_id' => isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0 );
			$this->redirect( array_merge( $back, array( 'kcrmpkg_notice' => $data ) ) );
		}

		if ( $id ) {
			KCRM_Pkg_Package::save( $id, $data );
		} else {
			$id = KCRM_Pkg_Package::create( $data );
		}

		$this->redirect( array( 'view' => 'edit', 'id' => $id, 'kcrmpkg_notice' => 'saved' ) );
	}

	private function delete( $id ) {
		check_admin_referer( 'kcrmpkg_delete_package_' . $id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		KCRM_Pkg_Usage::delete_for_package( $id );
		KCRM_Pkg_Package::delete( $id );

		$this->redirect( array( 'kcrmpkg_notice' => 'deleted' ) );
	}

	private function add_usage() {
		check_admin_referer( 'kcrmpkg_add_usage' );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		$package_id = isset( $_POST['package_id'] ) ? absint( $_POST['package_id'] ) : 0;
		$package    = $package_id ? KCRM_Pkg_Package::find( $package_id ) : null;

		if ( ! $package ) {
			$this->redirect( array( 'kcrmpkg_notice' => 'error' ) );
		}

		// Term packages log notes only: hours is always 0 and the note itself is required.
		$is_term     = KCRM_Pkg_Package::is_term( $package );
		$hours       = ( ! $is_term && isset( $_POST['hours'] ) ) ? (float) $_POST['hours'] : 0;
		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( $is_term ? '' === $description : $hours <= 0 ) {
			$this->redirect( array( 'view' => 'edit', 'id' => $package_id, 'kcrmpkg_notice' => 'error' ) );
		}

		KCRM_Pkg_Usage::create(
			array(
				'package_id'  => $package_id,
				'entry_date'  => isset( $_POST['entry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_date'] ) ) : gmdate( 'Y-m-d' ),
				'hours'       => $hours,
				'description' => $description,
			)
		);

		if ( $is_term ) {
			$notice = 'noted';
		} else {
			$remaining = round( (float) $package->allotted_hours - KCRM_Pkg_Usage::hours_logged( $package_id ), 2 );
			$notice    = $remaining < 0 ? 'overage' : 'saved';
		}

		$this->redirect( array( 'view' => 'edit', 'id' => $package_id, 'kcrmpkg_notice' => $notice ) );
	}

	private function delete_usage( $usage_id ) {
		check_admin_referer( 'kcrmpkg_delete_usage_' . $usage_id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param used only to redirect back to the right screen; the delete itself is already nonce-verified above.
		$package_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		KCRM_Pkg_Usage::delete( $usage_id );

		$this->redirect( array( 'view' => 'edit', 'id' => $package_id, 'kcrmpkg_notice' => 'deleted' ) );
	}

	public function handle_pdf_download() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'kcrmpkg_download_package_pdf_' . $id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}

		$package = $id ? KCRM_Pkg_Package::find( $id ) : null;
		if ( ! $package ) {
			wp_die( esc_html__( 'Package not found.', 'karks-crm-packages' ) );
		}

		KCRM_Pkg_PDF::stream_package_report( $package );
	}
}
