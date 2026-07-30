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
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
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
	}

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="wrap kcrmpkg-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Packages', 'karks-crm-packages' ) . '</h1>';
		if ( 'list' === $view ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm-packages' ) );
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
		<table class="wp-list-table widefat fixed striped">
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
					<tr><td colspan="7"><?php esc_html_e( 'No packages yet.', 'karks-crm-packages' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $packages as $package ) : ?>
					<?php
					$customer  = $customers_by_id[ $package->customer_id ] ?? null;
					$service   = $package->service_id ? ( $services_by_id[ $package->service_id ] ?? null ) : null;
					$used      = KCRM_Pkg_Usage::hours_logged( $package->id );
					$remaining = round( (float) $package->allotted_hours - $used, 2 );
					?>
					<tr>
						<td><?php echo $customer ? esc_html( KCRM_Customer::display_name( $customer ) ) : esc_html__( '(unknown)', 'karks-crm-packages' ); ?></td>
						<td><a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->id ) ) ); ?>"><?php echo esc_html( $service ? $service->name : __( '(unknown service)', 'karks-crm-packages' ) ); ?></a></td>
						<td><?php echo esc_html( number_format( (float) $package->allotted_hours, 2 ) ); ?></td>
						<td><?php echo esc_html( number_format( $used, 2 ) ); ?></td>
						<td>
							<?php echo esc_html( number_format( $remaining, 2 ) ); ?>
							<?php if ( $remaining < 0 ) : ?>
								<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#b91c1c;color:#fff;font-size:10px;text-transform:uppercase;margin-left:4px;"><?php esc_html_e( 'OVERAGE', 'karks-crm-packages' ); ?></span>
							<?php endif; ?>
						</td>
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

		$customer = $package ? KCRM_Customer::find( $package->customer_id ) : null;
		?>
		<h2><?php echo $id ? esc_html__( 'Edit Package', 'karks-crm-packages' ) : esc_html__( 'Add Package', 'karks-crm-packages' ); ?></h2>
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
					<th><label for="period_end"><?php esc_html_e( 'Period End', 'karks-crm-packages' ); ?></label></th>
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

		<?php if ( $package ) : ?>
			<?php $this->render_usage_section( $package ); ?>
		<?php endif; ?>
		<?php
	}

	private function render_usage_section( $package ) {
		$entries   = KCRM_Pkg_Usage::for_package( $package->id );
		$used      = KCRM_Pkg_Usage::hours_logged( $package->id );
		$remaining = round( (float) $package->allotted_hours - $used, 2 );
		?>
		<h2><?php esc_html_e( 'Usage Log', 'karks-crm-packages' ); ?></h2>

		<?php if ( $remaining < 0 ) : ?>
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

		<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
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
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->id, 'action' => 'delete_usage', 'usage_id' => $entry->id ) ), 'kcrmpkg_delete_usage_' . $entry->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this usage entry?', 'karks-crm-packages' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm-packages' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Log Usage', 'karks-crm-packages' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $package->id ) ) ); ?>">
			<?php wp_nonce_field( 'kcrmpkg_add_usage' ); ?>
			<input type="hidden" name="kcrmpkg_action" value="add_usage">
			<input type="hidden" name="package_id" value="<?php echo esc_attr( $package->id ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="entry_date"><?php esc_html_e( 'Date', 'karks-crm-packages' ); ?></label></th>
					<td><input type="date" name="entry_date" id="entry_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="hours"><?php esc_html_e( 'Hours', 'karks-crm-packages' ); ?></label></th>
					<td><input type="number" step="0.01" min="0.01" name="hours" id="hours" required></td>
				</tr>
				<tr>
					<th><label for="description"><?php esc_html_e( 'Description', 'karks-crm-packages' ); ?></label></th>
					<td><input type="text" class="regular-text" name="description" id="description" required></td>
				</tr>
			</table>
			<?php submit_button( __( 'Log Usage', 'karks-crm-packages' ) ); ?>
		</form>

		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrmpkg_download_package_pdf&id=' . $package->id ), 'kcrmpkg_download_package_pdf_' . $package->id ) ); ?>">
				<?php esc_html_e( 'Download PDF Report', 'karks-crm-packages' ); ?>
			</a>
		</p>
		<?php
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

		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		if ( ! $customer_id || ! KCRM_Customer::find( $customer_id ) ) {
			$this->redirect( array( 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrmpkg_notice' => 'error' ) );
		}

		$service_id = isset( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : 0;
		if ( ! $service_id || ! KCRM_Service::find( $service_id ) ) {
			$this->redirect( array( 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrmpkg_notice' => 'error' ) );
		}

		$data = array(
			'company_id'     => $company_id,
			'customer_id'    => $customer_id,
			'service_id'     => $service_id,
			'allotted_hours' => isset( $_POST['allotted_hours'] ) ? (float) $_POST['allotted_hours'] : 0,
			'price_paid'     => isset( $_POST['price_paid'] ) ? (float) $_POST['price_paid'] : 0,
			'period_start'   => isset( $_POST['period_start'] ) ? sanitize_text_field( wp_unslash( $_POST['period_start'] ) ) : '',
			'period_end'     => ! empty( $_POST['period_end'] ) ? sanitize_text_field( wp_unslash( $_POST['period_end'] ) ) : null,
			'status'         => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : KCRM_Pkg_Package::STATUS_ACTIVE,
			'notes'          => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
		);

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

		$hours = isset( $_POST['hours'] ) ? (float) $_POST['hours'] : 0;

		if ( $hours <= 0 ) {
			$this->redirect( array( 'view' => 'edit', 'id' => $package_id, 'kcrmpkg_notice' => 'error' ) );
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
