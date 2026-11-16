<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Upcoming Renewals" report on karks-crm's front-end Reports screen,
 * contributed via the `kcrm_reports_views` filter alongside
 * KCRM_Pkg_Report: every term package (e.g. yearly hosting) still awaiting
 * a renewal decision -- not renewed, not cancelled -- soonest end date
 * first. CSV export is a separate admin-post handler (handle_csv_export()),
 * same split as KCRM_Pkg_Report.
 *
 * Renewing itself stays in wp-admin (KCRM_Pkg_Admin_Packages::renew()),
 * like package create/edit; this report only lists what's coming up.
 */
class KCRM_Pkg_Renewals_Report {

	const VIEW = 'renewals';

	/**
	 * Listener for karks-crm's `kcrm_reports_views` filter.
	 *
	 * @param array $views      slug => array( 'label', 'icon', 'card_number', 'card_label', 'render' ).
	 * @param int   $company_id The current company's ID.
	 * @return array
	 */
	public function register_view( $views, $company_id ) {
		$views[ self::VIEW ] = array(
			'label'       => __( 'Upcoming Renewals', 'karks-crm-packages' ),
			'icon'        => 'dashicons-update',
			'card_number' => count( $this->rows( $company_id, (string) KCRM_Pkg_Package::RENEWAL_WINDOW_DAYS ) ),
			'card_label'  => __( 'Renewals Due (30 Days)', 'karks-crm-packages' ),
			'render'      => array( $this, 'render' ),
		);
		return $views;
	}

	/** @return array filter_key => label. */
	private function window_filters() {
		return array(
			'30'      => __( 'Next 30 days (incl. overdue)', 'karks-crm-packages' ),
			'60'      => __( 'Next 60 days (incl. overdue)', 'karks-crm-packages' ),
			'90'      => __( 'Next 90 days (incl. overdue)', 'karks-crm-packages' ),
			'overdue' => __( 'Overdue only', 'karks-crm-packages' ),
			'all'     => __( 'All upcoming', 'karks-crm-packages' ),
		);
	}

	/** Reads ?kcrmpkg_window=, falling back to '30' for anything not in window_filters(). */
	private function requested_window_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter param, whitelisted below; callers that act on it (handle_csv_export()) verify their own nonce first.
		$filter = isset( $_GET['kcrmpkg_window'] ) ? sanitize_key( wp_unslash( $_GET['kcrmpkg_window'] ) ) : '30';
		return array_key_exists( $filter, $this->window_filters() ) ? $filter : '30';
	}

	/**
	 * Term packages awaiting renewal within $window_filter. Shared by
	 * render(), the CSV export and the summary card so they can't drift.
	 *
	 * @param int    $company_id
	 * @param string $window_filter A window_filters() key.
	 * @return array List of [ 'package', 'customer', 'customer_name', 'service_name', 'days_left' ], soonest end date first.
	 */
	private function rows( $company_id, $window_filter ) {
		$packages  = KCRM_Pkg_Package::renewals_for_company( $company_id );
		$customers = KCRM_Customer::find_many( wp_list_pluck( $packages, 'customer_id' ) );
		$services  = KCRM_Service::find_many( wp_list_pluck( $packages, 'service_id' ) );

		$rows = array();
		foreach ( $packages as $package ) {
			$days_left = KCRM_Pkg_Package::days_until_end( $package );
			if ( null === $days_left ) {
				continue;
			}
			if ( 'overdue' === $window_filter && $days_left >= 0 ) {
				continue;
			}
			if ( is_numeric( $window_filter ) && $days_left > (int) $window_filter ) {
				continue;
			}

			$customer = $customers[ (int) $package->customer_id ] ?? null;
			$service  = $services[ (int) $package->service_id ] ?? null;

			$rows[] = array(
				'package'       => $package,
				'customer'      => $customer,
				'customer_name' => $customer ? KCRM_Customer::display_name( $customer ) : __( '(unknown client)', 'karks-crm-packages' ),
				'service_name'  => $service ? $service->name : __( '(unknown service)', 'karks-crm-packages' ),
				'days_left'     => $days_left,
			);
		}
		return $rows;
	}

	/** Human "in N days" / "N days overdue" / "today" label for a days_left value. */
	private function days_label( $days_left ) {
		if ( 0 === $days_left ) {
			return __( 'Today', 'karks-crm-packages' );
		}
		if ( $days_left < 0 ) {
			/* translators: %d: number of days past the term end. */
			return sprintf( _n( '%d day overdue', '%d days overdue', abs( $days_left ), 'karks-crm-packages' ), abs( $days_left ) );
		}
		/* translators: %d: number of days until the term ends. */
		return sprintf( _n( 'in %d day', 'in %d days', $days_left, 'karks-crm-packages' ), $days_left );
	}

	/** Report body, called by karks-crm's Reports screen for ?view=renewals. */
	public function render( $company_id ) {
		$filter   = $this->requested_window_filter();
		$rows     = $this->rows( $company_id, $filter );
		$statuses = KCRM_Pkg_Package::statuses();

		$overdue = 0;
		$value   = 0.0;
		foreach ( $rows as $row ) {
			if ( $row['days_left'] < 0 ) {
				++$overdue;
			}
			$value += (float) $row['package']->price_paid;
		}
		?>
		<form method="get" action="<?php echo esc_url( KCRM_Front::endpoint_url( 'reports' ) ); ?>" class="kcrm-front-form">
			<input type="hidden" name="view" value="<?php echo esc_attr( self::VIEW ); ?>">
			<p>
				<label for="kcrmpkg-renewals-window"><?php esc_html_e( 'Show', 'karks-crm-packages' ); ?></label>
				<select name="kcrmpkg_window" id="kcrmpkg-renewals-window" onchange="this.form.submit();">
					<?php foreach ( $this->window_filters() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="kcrm-button"><?php esc_html_e( 'Filter', 'karks-crm-packages' ); ?></button></noscript>
			</p>
		</form>

		<div class="kcrm-dashboard-cards">
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( count( $rows ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Renewals', 'karks-crm-packages' ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( $overdue ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Overdue', 'karks-crm-packages' ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $value, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Value at Last Price', 'karks-crm-packages' ); ?></span>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Term packages that haven\'t been renewed or cancelled. Renew them from the package screen in wp-admin.', 'karks-crm-packages' ); ?></p>

		<div class="kcrm-button-group">
			<a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( $this->export_url( $filter ) ); ?>">
				<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'karks-crm-packages' ); ?>
			</a>
		</div>

		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Client', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Package', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Term', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Ends', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Price', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No renewals in this window.', 'karks-crm-packages' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php $package = $row['package']; ?>
					<tr>
						<td>
							<?php if ( $row['customer'] ) : ?>
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'customers', KCRM_Front::nav_nonce_args( array( 'view' => 'edit', 'id' => $row['customer']->id, 'tab' => 'packages' ) ) ) ); ?>"><?php echo esc_html( $row['customer_name'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row['customer_name'] ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['service_name'] ); ?></td>
						<td><?php echo esc_html( $package->period_start . ' – ' . $package->period_end ); ?></td>
						<td>
							<?php echo esc_html( $package->period_end ); ?>
							<?php echo KCRM_Pkg_Package::renewal_badge_html( $package ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renewal_badge_html(). ?>
							<?php if ( $row['days_left'] > KCRM_Pkg_Package::RENEWAL_WINDOW_DAYS ) : ?>
								<br><small><?php echo esc_html( $this->days_label( $row['days_left'] ) ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (float) $package->price_paid, 2 ) ); ?></td>
						<td><?php echo esc_html( $statuses[ $package->status ] ?? $package->status ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Nonce-protected admin-post URL to handle_csv_export(). */
	private function export_url( $filter ) {
		$url = add_query_arg(
			array(
				'action'         => 'kcrmpkg_export_renewals_csv',
				'kcrmpkg_window' => $filter,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'kcrmpkg_export_renewals_csv' );
	}

	/**
	 * fputcsv() with every argument spelled out -- see
	 * KCRM_Pkg_Report::put_csv() for why ($escape = '' for PHP 8.4 / RFC 4180).
	 *
	 * @param resource $out
	 * @param array    $fields
	 */
	private function put_csv( $out, array $fields ) {
		fputcsv( $out, $fields, ',', '"', '' );
	}

	/** admin-post handler: streams the Upcoming Renewals report as a CSV download. */
	public function handle_csv_export() {
		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}
		check_admin_referer( 'kcrmpkg_export_renewals_csv' );

		$company = KCRM_Company::find( KCRM_Context::get_current_company_id() );
		if ( ! $company ) {
			wp_die( esc_html__( 'Invalid company.', 'karks-crm-packages' ) );
		}

		$rows     = $this->rows( $company->id, $this->requested_window_filter() );
		$statuses = KCRM_Pkg_Package::statuses();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'upcoming-renewals-' . $company->name . '-' . gmdate( 'Y-m-d' ) ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV download to php://output, not a real file; WP_Filesystem has no equivalent.
		$out = fopen( 'php://output', 'w' );
		$this->put_csv(
			$out,
			array(
				__( 'Client', 'karks-crm-packages' ),
				__( 'Package', 'karks-crm-packages' ),
				__( 'Term Start', 'karks-crm-packages' ),
				__( 'Term End', 'karks-crm-packages' ),
				__( 'Days Left', 'karks-crm-packages' ),
				__( 'Price', 'karks-crm-packages' ),
				__( 'Status', 'karks-crm-packages' ),
			)
		);

		foreach ( $rows as $row ) {
			$package = $row['package'];
			$this->put_csv(
				$out,
				array(
					$row['customer_name'],
					$row['service_name'],
					$package->period_start,
					$package->period_end,
					$row['days_left'],
					number_format( (float) $package->price_paid, 2, '.', '' ),
					$statuses[ $package->status ] ?? $package->status,
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream handle opened above, not a real file.
		exit;
	}
}
