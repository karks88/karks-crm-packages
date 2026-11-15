<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Package Usage" report on karks-crm's front-end Reports screen, contributed
 * via the `kcrm_reports_views` filter: every package for the current company
 * (client, service, period, allotted vs. used hours) plus the average share
 * of allotted hours clients actually use. CSV export is a separate
 * admin-post handler (handle_csv_export()), same split as karks-crm's own
 * reports.
 *
 * Averages leave out cancelled packages (they never ran their course) and
 * packages with 0 allotted hours (no meaningful percentage). Overages aren't
 * capped, so a package at 125% counts as 125%.
 */
class KCRM_Pkg_Report {

	const VIEW = 'packages';

	/**
	 * Listener for karks-crm's `kcrm_reports_views` filter.
	 *
	 * @param array $views      slug => array( 'label', 'icon', 'card_number', 'card_label', 'render' ).
	 * @param int   $company_id The current company's ID.
	 * @return array
	 */
	public function register_view( $views, $company_id ) {
		$summary = $this->summary( $this->rows( $company_id, 'all' ) );

		$views[ self::VIEW ] = array(
			'label'       => __( 'Package Usage', 'karks-crm-packages' ),
			'icon'        => 'dashicons-clock',
			'card_number' => $this->format_pct( $summary['average_pct'] ),
			'card_label'  => __( 'Avg. Package Usage', 'karks-crm-packages' ),
			'render'      => array( $this, 'render' ),
		);
		return $views;
	}

	/** @return array filter_key => label. */
	private function status_filters() {
		return array(
			'all'       => __( 'All', 'karks-crm-packages' ),
			'current'   => __( 'Current (Active/Exhausted)', 'karks-crm-packages' ),
			'completed' => __( 'Completed (Expired)', 'karks-crm-packages' ),
		);
	}

	/** Reads ?kcrmpkg_status=, falling back to 'all' for anything not in status_filters(). */
	private function requested_status_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter param, whitelisted below; callers that act on it (handle_csv_export()) verify their own nonce first.
		$filter = isset( $_GET['kcrmpkg_status'] ) ? sanitize_key( wp_unslash( $_GET['kcrmpkg_status'] ) ) : 'all';
		return array_key_exists( $filter, $this->status_filters() ) ? $filter : 'all';
	}

	/**
	 * Every package for the company matching $status_filter, with its hours
	 * used. Shared by render() and the CSV export so the two can't drift.
	 *
	 * @param int    $company_id
	 * @param string $status_filter A status_filters() key.
	 * @return array List of [ 'package', 'customer', 'customer_name', 'service_name', 'allotted', 'used', 'pct' ], newest period first. 'pct' is null when allotted is 0.
	 */
	private function rows( $company_id, $status_filter ) {
		$groups = array(
			'current'   => array( KCRM_Pkg_Package::STATUS_ACTIVE, KCRM_Pkg_Package::STATUS_EXHAUSTED ),
			'completed' => array( KCRM_Pkg_Package::STATUS_EXPIRED ),
		);

		$packages = KCRM_Pkg_Package::for_company( $company_id, 'period_start DESC' );
		if ( isset( $groups[ $status_filter ] ) ) {
			$packages = array_values(
				array_filter(
					$packages,
					static function ( $package ) use ( $groups, $status_filter ) {
						return in_array( $package->status, $groups[ $status_filter ], true );
					}
				)
			);
		}

		$hours     = KCRM_Pkg_Usage::hours_logged_for_packages( wp_list_pluck( $packages, 'id' ) );
		$customers = KCRM_Customer::find_many( wp_list_pluck( $packages, 'customer_id' ) );
		$services  = KCRM_Service::find_many( wp_list_pluck( $packages, 'service_id' ) );

		$rows = array();
		foreach ( $packages as $package ) {
			$customer = $customers[ (int) $package->customer_id ] ?? null;
			$service  = $services[ (int) $package->service_id ] ?? null;
			$allotted = (float) $package->allotted_hours;
			$used     = $hours[ (int) $package->id ] ?? 0.0;

			$rows[] = array(
				'package'       => $package,
				'customer'      => $customer,
				'customer_name' => $customer ? KCRM_Customer::display_name( $customer ) : __( '(unknown client)', 'karks-crm-packages' ),
				'service_name'  => $service ? $service->name : __( '(unknown service)', 'karks-crm-packages' ),
				'allotted'      => $allotted,
				'used'          => $used,
				'pct'           => $allotted > 0 ? ( $used / $allotted ) * 100 : null,
			);
		}
		return $rows;
	}

	/**
	 * @param array $rows From rows().
	 * @return array [ 'average_pct' => float|null (mean of each package's %), 'overall_pct' => float|null (total used / total allotted), 'counted' => int, 'allotted' => float, 'used' => float ] -- all over non-cancelled packages with allotted hours.
	 */
	private function summary( array $rows ) {
		$pcts     = array();
		$allotted = 0.0;
		$used     = 0.0;

		foreach ( $rows as $row ) {
			if ( KCRM_Pkg_Package::STATUS_CANCELLED === $row['package']->status || null === $row['pct'] ) {
				continue;
			}
			$pcts[]    = $row['pct'];
			$allotted += $row['allotted'];
			$used     += $row['used'];
		}

		return array(
			'average_pct' => $pcts ? array_sum( $pcts ) / count( $pcts ) : null,
			'overall_pct' => $allotted > 0 ? ( $used / $allotted ) * 100 : null,
			'counted'     => count( $pcts ),
			'allotted'    => $allotted,
			'used'        => $used,
		);
	}

	/** @param float|null $pct */
	private function format_pct( $pct ) {
		return null === $pct ? '—' : number_format_i18n( $pct, 0 ) . '%';
	}

	/** Report body, called by karks-crm's Reports screen for ?view=packages. */
	public function render( $company_id ) {
		$filter   = $this->requested_status_filter();
		$rows     = $this->rows( $company_id, $filter );
		$summary  = $this->summary( $rows );
		$statuses = KCRM_Pkg_Package::statuses();
		?>
		<form method="get" action="<?php echo esc_url( KCRM_Front::endpoint_url( 'reports' ) ); ?>" class="kcrm-front-form">
			<input type="hidden" name="view" value="<?php echo esc_attr( self::VIEW ); ?>">
			<p>
				<label for="kcrmpkg-report-status"><?php esc_html_e( 'Packages', 'karks-crm-packages' ); ?></label>
				<select name="kcrmpkg_status" id="kcrmpkg-report-status" onchange="this.form.submit();">
					<?php foreach ( $this->status_filters() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="kcrm-button"><?php esc_html_e( 'Filter', 'karks-crm-packages' ); ?></button></noscript>
			</p>
		</form>

		<div class="kcrm-dashboard-cards">
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( $this->format_pct( $summary['average_pct'] ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Average Usage per Package', 'karks-crm-packages' ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( $this->format_pct( $summary['overall_pct'] ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Overall Usage (All Hours)', 'karks-crm-packages' ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $summary['used'], 2 ) . ' / ' . number_format_i18n( $summary['allotted'], 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Hours Used / Allotted', 'karks-crm-packages' ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( count( $rows ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Packages', 'karks-crm-packages' ); ?></span>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Averages leave out cancelled packages and packages with no allotted hours.', 'karks-crm-packages' ); ?></p>

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
					<th><?php esc_html_e( 'Start', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'End', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Allotted', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Used', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( '% Used', 'karks-crm-packages' ); ?></th>
					<th><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No packages found.', 'karks-crm-packages' ); ?></td></tr>
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
						<td><?php echo esc_html( $package->period_start ); ?></td>
						<td><?php echo esc_html( $package->period_end ? $package->period_end : '—' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['allotted'], 2 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['used'], 2 ) ); ?></td>
						<td>
							<?php echo esc_html( $this->format_pct( $row['pct'] ) ); ?>
							<?php if ( null !== $row['pct'] && $row['pct'] > 100 ) : ?>
								<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#b91c1c;color:#fff;font-size:10px;text-transform:uppercase;margin-left:4px;"><?php esc_html_e( 'OVERAGE', 'karks-crm-packages' ); ?></span>
							<?php endif; ?>
						</td>
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
				'action'         => 'kcrmpkg_export_report_csv',
				'kcrmpkg_status' => $filter,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'kcrmpkg_export_report_csv' );
	}

	/** admin-post handler: streams the Package Usage report as a CSV download. */
	public function handle_csv_export() {
		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm-packages' ) );
		}
		check_admin_referer( 'kcrmpkg_export_report_csv' );

		$company = KCRM_Company::find( KCRM_Context::get_current_company_id() );
		if ( ! $company ) {
			wp_die( esc_html__( 'Invalid company.', 'karks-crm-packages' ) );
		}

		$rows     = $this->rows( $company->id, $this->requested_status_filter() );
		$summary  = $this->summary( $rows );
		$statuses = KCRM_Pkg_Package::statuses();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'package-usage-report-' . $company->name . '-' . gmdate( 'Y-m-d' ) ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV download to php://output, not a real file; WP_Filesystem has no equivalent.
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				__( 'Client', 'karks-crm-packages' ),
				__( 'Package', 'karks-crm-packages' ),
				__( 'Start', 'karks-crm-packages' ),
				__( 'End', 'karks-crm-packages' ),
				__( 'Allotted Hours', 'karks-crm-packages' ),
				__( 'Hours Used', 'karks-crm-packages' ),
				__( '% Used', 'karks-crm-packages' ),
				__( 'Status', 'karks-crm-packages' ),
			)
		);

		foreach ( $rows as $row ) {
			$package = $row['package'];
			fputcsv(
				$out,
				array(
					$row['customer_name'],
					$row['service_name'],
					$package->period_start,
					$package->period_end,
					number_format( $row['allotted'], 2, '.', '' ),
					number_format( $row['used'], 2, '.', '' ),
					null === $row['pct'] ? '' : number_format( $row['pct'], 1, '.', '' ),
					$statuses[ $package->status ] ?? $package->status,
				)
			);
		}

		fputcsv( $out, array() );
		fputcsv( $out, array( __( 'Average Usage per Package (%)', 'karks-crm-packages' ), null === $summary['average_pct'] ? '' : number_format( $summary['average_pct'], 1, '.', '' ) ) );
		fputcsv( $out, array( __( 'Overall Usage (%)', 'karks-crm-packages' ), null === $summary['overall_pct'] ? '' : number_format( $summary['overall_pct'], 1, '.', '' ) ) );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream handle opened above, not a real file.
		exit;
	}
}
