<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Package usage PDF template. Rendered inside KCRM_Pkg_PDF::render(), which
 * defines: $package, $service, $company, $customer, $usage_entries,
 * $hours_used, $hours_remaining, $logo_data, $is_term.
 *
 * Term packages (e.g. yearly hosting) get a "Service Term Summary" instead:
 * notes without an Hours column, and term dates in place of hour totals.
 */

$kcrmpkg_statuses = KCRM_Pkg_Package::statuses();

// Same 4-color scheme as karks-crm's own invoice PDF, reusing the exact
// same public utilities so both documents stay visually consistent.
$kcrmpkg_colors    = KCRM_Colors::get();
$kcrmpkg_hex_or    = function ( $value, $fallback ) {
	return preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $value ) ? $value : $fallback;
};
$kcrmpkg_primary   = $kcrmpkg_hex_or( $kcrmpkg_colors['primary'], '#1e3a5f' );
$kcrmpkg_secondary = $kcrmpkg_hex_or( $kcrmpkg_colors['secondary'], '#1f2937' );
$kcrmpkg_highlight = $kcrmpkg_hex_or( $kcrmpkg_colors['highlight'], '#fff6b3' );
$kcrmpkg_accent    = $kcrmpkg_hex_or( KCRM_Company::pdf_accent_color( $company ), $kcrmpkg_primary );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #222; }
	.header { width: 100%; margin-bottom: 24px; }
	.header table { width: 100%; border-collapse: collapse; }
	.header td { vertical-align: top; }
	.logo img { max-width: 200px; max-height: 110px; }
	.company-name { font-size: 18px; font-weight: bold; }
	.report-title { font-size: 22px; font-weight: bold; text-align: right; color: <?php echo esc_html( $kcrmpkg_accent ); ?>; }
	.report-meta { text-align: right; margin-top: 6px; }
	.addresses { width: 100%; margin: 20px 0; }
	.addresses table { width: 100%; }
	.addresses td { width: 50%; vertical-align: top; }
	.addresses h4 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; color: <?php echo esc_html( $kcrmpkg_accent ); ?>; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
	table.items th { text-align: left; background: <?php echo esc_html( $kcrmpkg_secondary ); ?>; padding: 6px 8px; font-size: 11px; text-transform: uppercase; color: #fff; }
	table.items td { padding: 6px 8px; border-bottom: 1px solid #eee; text-align: left; }
	table.items tbody tr:nth-child(even) td { background: <?php echo esc_html( $kcrmpkg_highlight ); ?>; }
	.text-right { text-align: right; }
	table.totals { width: 260px; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
	table.totals td { padding: 4px 8px; }
	table.totals tr.total-row td { font-weight: bold; border-top: 2px solid <?php echo esc_html( $kcrmpkg_accent ); ?>; color: <?php echo esc_html( $kcrmpkg_accent ); ?>; font-size: 14px; }
	.status-badge { display: inline-block; padding: 3px 10px; border-radius: 3px; background: #eee; font-size: 11px; text-transform: uppercase; }
	.overage-badge { display: inline-block; padding: 3px 10px; border-radius: 3px; background: #b91c1c; color: #fff; font-size: 11px; text-transform: uppercase; }
</style>
</head>
<body>

<div class="header">
	<table>
		<tr>
			<td class="logo">
				<?php if ( $logo_data ) : ?>
					<img src="<?php echo esc_attr( $logo_data ); ?>" alt="<?php echo esc_attr( $company->name ); ?>">
				<?php else : ?>
					<div class="company-name"><?php echo esc_html( $company->name ); ?></div>
				<?php endif; ?>
			</td>
			<td>
				<div class="report-title"><?php echo $is_term ? esc_html__( 'SERVICE TERM SUMMARY', 'karks-crm-packages' ) : esc_html__( 'PACKAGE USAGE REPORT', 'karks-crm-packages' ); ?></div>
				<div class="report-meta">
					<div><strong><?php echo esc_html( $service ? $service->name : __( '(unknown service)', 'karks-crm-packages' ) ); ?></strong></div>
					<div>
						<?php echo esc_html( $package->period_start ); ?>
						<?php if ( $package->period_end ) : ?>
							&ndash; <?php echo esc_html( $package->period_end ); ?>
						<?php endif; ?>
					</div>
					<div><span class="status-badge"><?php echo esc_html( $kcrmpkg_statuses[ $package->status ] ?? $package->status ); ?></span></div>
				</div>
			</td>
		</tr>
	</table>
</div>

<div class="addresses">
	<table>
		<tr>
			<td>
				<h4><?php esc_html_e( 'From', 'karks-crm-packages' ); ?></h4>
				<div><?php echo esc_html( $company->name ); ?></div>
				<?php if ( $company->email ) : ?><div><?php echo esc_html( $company->email ); ?></div><?php endif; ?>
			</td>
			<td>
				<h4><?php esc_html_e( 'Prepared For', 'karks-crm-packages' ); ?></h4>
				<div><?php echo esc_html( $customer->company_name ); ?></div>
				<?php if ( $customer->contact_person ) : ?><div><?php echo esc_html( $customer->contact_person ); ?></div><?php endif; ?>
				<?php if ( $customer->email ) : ?><div><?php echo esc_html( $customer->email ); ?></div><?php endif; ?>
			</td>
		</tr>
	</table>
</div>

<table class="items">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Date', 'karks-crm-packages' ); ?></th>
			<th><?php esc_html_e( 'Description', 'karks-crm-packages' ); ?></th>
			<?php if ( ! $is_term ) : ?>
				<th class="text-right"><?php esc_html_e( 'Hours', 'karks-crm-packages' ); ?></th>
			<?php endif; ?>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $usage_entries ) ) : ?>
			<tr><td colspan="3"><?php echo $is_term ? esc_html__( 'No notes yet.', 'karks-crm-packages' ) : esc_html__( 'No usage logged yet.', 'karks-crm-packages' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $usage_entries as $kcrmpkg_entry ) : ?>
			<tr>
				<td><?php echo esc_html( $kcrmpkg_entry->entry_date ); ?></td>
				<td><?php echo esc_html( $kcrmpkg_entry->description ); ?></td>
				<?php if ( ! $is_term ) : ?>
					<td class="text-right"><?php echo esc_html( number_format( (float) $kcrmpkg_entry->hours, 2 ) ); ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( $is_term ) : ?>
<table class="totals">
	<tr>
		<td><?php esc_html_e( 'Term Start', 'karks-crm-packages' ); ?></td>
		<td class="text-right"><?php echo esc_html( $package->period_start ); ?></td>
	</tr>
	<tr>
		<td><?php esc_html_e( 'Term End', 'karks-crm-packages' ); ?></td>
		<td class="text-right"><?php echo esc_html( $package->period_end ? $package->period_end : '—' ); ?></td>
	</tr>
	<tr class="total-row">
		<td><?php esc_html_e( 'Status', 'karks-crm-packages' ); ?></td>
		<td class="text-right">
			<?php
			echo esc_html(
				! empty( $package->renewed_by_id )
					? __( 'Renewed', 'karks-crm-packages' )
					: ( $kcrmpkg_statuses[ $package->status ] ?? $package->status )
			);
			?>
		</td>
	</tr>
</table>
<?php else : ?>
<table class="totals">
	<tr>
		<td><?php esc_html_e( 'Allotted Hours', 'karks-crm-packages' ); ?></td>
		<td class="text-right"><?php echo esc_html( number_format( (float) $package->allotted_hours, 2 ) ); ?></td>
	</tr>
	<tr>
		<td><?php esc_html_e( 'Hours Used', 'karks-crm-packages' ); ?></td>
		<td class="text-right"><?php echo esc_html( number_format( $hours_used, 2 ) ); ?></td>
	</tr>
	<tr class="total-row">
		<td><?php esc_html_e( 'Hours Remaining', 'karks-crm-packages' ); ?></td>
		<td class="text-right">
			<?php echo esc_html( number_format( $hours_remaining, 2 ) ); ?>
			<?php if ( $hours_remaining < 0 ) : ?>
				<span class="overage-badge"><?php esc_html_e( 'OVERAGE', 'karks-crm-packages' ); ?></span>
			<?php endif; ?>
		</td>
	</tr>
</table>
<?php endif; ?>

</body>
</html>
