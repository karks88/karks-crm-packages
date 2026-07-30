<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a package's usage log to a client-shareable PDF, mirroring
 * karks-crm's own KCRM_PDF::stream_invoice(). Reuses \Dompdf\Dompdf via
 * karks-crm's already-loaded Composer autoloader rather than vendoring a
 * second copy of the library.
 */
class KCRM_Pkg_PDF {

	/** Render a package usage report to HTML, convert with Dompdf, and stream it as a PDF download. Ends the request. */
	public static function stream_package_report( $package ) {
		self::require_dompdf_or_die();

		$service  = $package->service_id ? KCRM_Service::find( $package->service_id ) : null;
		$dompdf   = self::render( $package, $service );
		$filename = sanitize_file_name( $service ? $service->name : 'package-' . $package->id );
		$dompdf->stream( $filename . '-usage-report.pdf', array( 'Attachment' => true ) );
		exit;
	}

	private static function require_dompdf_or_die() {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die(
				esc_html__( 'PDF export is not available: the Dompdf library is missing from the Karks CRM plugin. Run "composer install" inside the Karks CRM plugin folder.', 'karks-crm-packages' )
			);
		}
	}

	private static function render( $package, $service ) {
		$company         = KCRM_Company::find( $package->company_id );
		$customer        = KCRM_Customer::find( $package->customer_id );
		$usage_entries   = KCRM_Pkg_Usage::for_package( $package->id );
		$hours_used      = KCRM_Pkg_Usage::hours_logged( $package->id );
		$hours_remaining = round( (float) $package->allotted_hours - $hours_used, 2 );
		$logo_data       = KCRM_PDF::logo_data_uri( $company );

		ob_start();
		include KCRMPKG_PLUGIN_DIR . 'templates/package-usage-pdf.php';
		$html = ob_get_clean();

		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'Helvetica' );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'letter' );
		$dompdf->render();

		return $dompdf;
	}
}
