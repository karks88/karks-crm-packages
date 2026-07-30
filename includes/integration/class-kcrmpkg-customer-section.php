<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a compact "Packages" summary box on the customer edit screen --
 * both wp-admin and the front-end /crm/ screen, since karks-crm fires
 * kcrm_customer_edit_after_sections in both. This is the only file in this
 * add-on that touches core's hook.
 */
class KCRM_Pkg_Customer_Section {

	/**
	 * @param object $customer   The KCRM_Customer row being viewed.
	 * @param array  $rollup_ids The customer's own id plus any Job ids rolled up under it.
	 */
	public function render( $customer, $rollup_ids ) {
		$packages = KCRM_Pkg_Package::for_customers( $rollup_ids );

		echo '<h2>' . esc_html__( 'Packages', 'karks-crm-packages' ) . '</h2>';

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
			<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'karks-crm-packages' ); ?></th>
						<th><?php esc_html_e( 'Remaining', 'karks-crm-packages' ); ?></th>
						<th><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></th>
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
}
