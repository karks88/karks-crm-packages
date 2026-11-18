<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a karks-crm invoice for a renewed term package ("Renew + Invoice").
 *
 * This is the add-on's only use of karks-crm's invoice layer. Everything else
 * in the add-on stays off KCRM_Invoice/KCRM_Invoice_Item (see CLAUDE.md), so
 * those symbols are confined to this one class. available() hides the feature
 * on a karks-crm version that lacks any of them, rather than fataling.
 *
 * The invoice copies its type and tax rate from the client's most recent
 * non-void invoice for the package's service. With no such invoice, it falls
 * back to "Other" + the service name and the company's default tax rate.
 */
class KCRM_Pkg_Invoice_Bridge {

	/** Days between the invoice's issue date and due date. */
	const DUE_DAYS = 14;

	/** Whether karks-crm exposes everything create_for_term() needs. */
	public static function available() {
		return class_exists( 'KCRM_Invoice' )
			&& class_exists( 'KCRM_Invoice_Item' )
			&& method_exists( 'KCRM_Invoice', 'create' )
			&& method_exists( 'KCRM_Invoice', 'recalculate_totals' )
			&& method_exists( 'KCRM_Invoice_Item', 'insert' )
			&& method_exists( 'KCRM_Company', 'next_invoice_number' );
	}

	/**
	 * Create an Open invoice billing $package's term.
	 *
	 * @param object $package The new term's KCRM_Pkg_Package row.
	 * @return int|false The new invoice id, or false on failure.
	 */
	public static function create_for_term( $package ) {
		if ( ! self::available() || ! $package || ! KCRM_Pkg_Package::is_term( $package ) || empty( $package->period_end ) ) {
			return false;
		}

		try {
			$start = new DateTimeImmutable( $package->period_start );
			$end   = new DateTimeImmutable( $package->period_end );
			$today = new DateTimeImmutable( current_time( 'Y-m-d' ) );
		} catch ( Exception $e ) {
			return false;
		}

		$company_id = (int) $package->company_id;
		$company    = KCRM_Company::find( $company_id );
		$service    = $package->service_id ? KCRM_Service::find( $package->service_id ) : null;
		$template   = self::template_for( $package );

		// Issue the day before the term starts, but never backdate past today.
		$issue = max( $start->modify( '-1 day' ), $today );
		$due   = $issue->modify( '+' . self::DUE_DAYS . ' days' );

		$types        = KCRM_Invoice::types();
		$invoice_type = $template && isset( $types[ $template->invoice_type ] ) ? $template->invoice_type : KCRM_Invoice::TYPE_OTHER;

		$type_other = null;
		if ( KCRM_Invoice::TYPE_OTHER === $invoice_type ) {
			$type_other = $template && ! empty( $template->invoice_type_other ) ? $template->invoice_type_other : ( $service ? $service->name : null );
		}

		$invoice_id = KCRM_Invoice::create(
			array(
				'company_id'         => $company_id,
				'customer_id'        => (int) $package->customer_id,
				'invoice_number'     => KCRM_Company::next_invoice_number( $company_id ),
				'status'             => KCRM_Invoice::STATUS_OPEN,
				'issue_date'         => $issue->format( 'Y-m-d' ),
				'due_date'           => $due->format( 'Y-m-d' ),
				'invoice_type'       => $invoice_type,
				'invoice_type_month' => KCRM_Invoice::TYPE_MONTH_YEAR === $invoice_type ? $start->format( 'Y-m' ) : null,
				'invoice_type_other' => $type_other,
				'notes'              => '',
				'tax_rate'           => $template ? (float) $template->tax_rate : (float) ( $company->default_tax_rate ?? 0 ),
			)
		);
		if ( ! $invoice_id ) {
			return false;
		}

		$rate = round( (float) $package->price_paid, 2 );
		KCRM_Invoice_Item::insert(
			array(
				'invoice_id'  => $invoice_id,
				'service_id'  => $service ? (int) $service->id : null,
				'description' => self::line_description( $template, $service, $start, $end ),
				'type'        => $service && ! empty( $service->type ) ? $service->type : KCRM_Service::TYPE_PROJECT,
				'quantity'    => 1,
				'rate'        => $rate,
				'amount'      => $rate,
				'is_taxable'  => $service ? (int) $service->is_taxable : 0,
				'sort_order'  => 0,
			)
		);
		KCRM_Invoice::recalculate_totals( $invoice_id );

		return (int) $invoice_id;
	}

	/**
	 * The invoice to copy type/tax/line text from: the client's most recent
	 * non-void invoice that has a line item for the package's service. The
	 * returned row also carries that item's description as `item_description`.
	 */
	private static function template_for( $package ) {
		global $wpdb;

		if ( empty( $package->service_id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only lookup across karks-crm's invoice tables; query text and args go through $wpdb->prepare() on this line.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT i.invoice_type, i.invoice_type_other, i.tax_rate, it.description AS item_description FROM %i i INNER JOIN %i it ON it.invoice_id = i.id WHERE i.customer_id = %d AND i.company_id = %d AND it.service_id = %d AND i.status <> %s ORDER BY i.issue_date DESC, i.id DESC, it.sort_order ASC LIMIT 1', KCRM_Invoice::table(), KCRM_Invoice_Item::table(), $package->customer_id, $package->company_id, $package->service_id, KCRM_Invoice::STATUS_VOID ) );
	}

	/**
	 * Line-item text for the term. Starts from the previous invoice's line
	 * (else the service's description, else its name). A trailing
	 * "Expires: <date>" gets the new term's expiry (the day after period_end,
	 * e.g. "Expires: 11/1/28"). Otherwise the term's dates are appended.
	 */
	private static function line_description( $template, $service, DateTimeImmutable $start, DateTimeImmutable $end ) {
		$text = '';
		if ( $template && '' !== trim( (string) $template->item_description ) ) {
			$text = $template->item_description;
		} elseif ( $service ) {
			$text = '' !== trim( (string) $service->description ) ? $service->description : $service->name;
		}
		$text = trim( (string) $text );

		$expires  = $end->modify( '+1 day' )->format( 'n/j/y' );
		$replaced = preg_replace( '/Expires:\s*[\d\/.\-]*\s*$/i', 'Expires: ' . $expires, $text, 1, $count );
		if ( $count ) {
			return $replaced;
		}

		$format = get_option( 'date_format' );
		$dates  = date_i18n( $format, $start->getTimestamp() ) . ' – ' . date_i18n( $format, $end->getTimestamp() );
		return '' === $text ? $dates : $text . ' (' . $dates . ')';
	}

	/**
	 * Edit URL for an invoice: the front-end Invoices screen, or wp-admin's.
	 *
	 * @param int  $invoice_id
	 * @param bool $is_front
	 */
	public static function edit_url( $invoice_id, $is_front ) {
		if ( $is_front ) {
			return KCRM_Front::endpoint_url( 'invoices', KCRM_Front::nav_nonce_args( array( 'view' => 'edit', 'id' => (int) $invoice_id ) ) );
		}
		return admin_url( 'admin.php?page=karks-crm-invoices&view=edit&id=' . (int) $invoice_id );
	}

	/**
	 * Escaped notice HTML for the `renewed_invoiced` redirect, read from the
	 * kcrmpkg_invoice_id query arg. Shows the invoice number and a link only
	 * for an invoice in the current company.
	 *
	 * @param bool $is_front
	 */
	public static function renewed_notice_html( $is_front ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display param, no state change; the invoice is company-checked below.
		$invoice_id = isset( $_GET['kcrmpkg_invoice_id'] ) ? absint( $_GET['kcrmpkg_invoice_id'] ) : 0;
		$invoice    = $invoice_id && self::available() ? KCRM_Invoice::find( $invoice_id ) : null;

		if ( ! $invoice || (int) $invoice->company_id !== (int) KCRM_Context::get_current_company_id() ) {
			return esc_html__( 'Package renewed and invoice created. This is the new term.', 'karks-crm-packages' );
		}

		return sprintf(
			'%s <a href="%s">%s &rarr;</a>',
			esc_html(
				sprintf(
					/* translators: %s: invoice number. */
					__( 'Package renewed and invoice %s created. This is the new term.', 'karks-crm-packages' ),
					$invoice->invoice_number
				)
			),
			esc_url( self::edit_url( $invoice->id, $is_front ) ),
			esc_html__( 'View invoice', 'karks-crm-packages' )
		);
	}
}
