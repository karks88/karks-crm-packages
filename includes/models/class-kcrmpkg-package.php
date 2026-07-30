<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A purchased maintenance-package allotment (e.g. "4 hours for $200"). Hours
 * remaining is always computed live from KCRM_Pkg_Usage::hours_logged(),
 * never stored here -- see that class for why.
 *
 * Extends karks-crm's own KCRM_Model_Base directly (confirmed generic and
 * stateless) rather than duplicating its CRUD helpers.
 */
class KCRM_Pkg_Package extends KCRM_Model_Base {

	const STATUS_ACTIVE    = 'active';
	const STATUS_EXHAUSTED = 'exhausted';
	const STATUS_EXPIRED   = 'expired';
	const STATUS_CANCELLED = 'cancelled';

	public static function table() {
		return KCRM_Pkg_DB::packages();
	}

	public static function statuses() {
		return array(
			self::STATUS_ACTIVE    => __( 'Active', 'karks-crm-packages' ),
			self::STATUS_EXHAUSTED => __( 'Exhausted', 'karks-crm-packages' ),
			self::STATUS_EXPIRED   => __( 'Expired', 'karks-crm-packages' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'karks-crm-packages' ),
		);
	}

	protected static function columns() {
		return array(
			'company_id'     => '%d',
			'customer_id'    => '%d',
			'service_id'     => '%d',
			'allotted_hours' => '%f',
			'price_paid'     => '%f',
			'period_start'   => '%s',
			'period_end'     => '%s',
			'status'         => '%s',
			'notes'          => '%s',
			'created_at'     => '%s',
			'updated_at'     => '%s',
		);
	}

	public static function for_customer( $customer_id, $order_by = 'period_start DESC' ) {
		return self::where( array( 'customer_id' => $customer_id ), $order_by );
	}

	/** Packages across a set of customer ids (a customer plus its Jobs), for the customer-edit summary box. */
	public static function for_customers( array $customer_ids, $order_by = 'period_start DESC' ) {
		global $wpdb;

		$customer_ids = array_filter( array_map( 'absint', $customer_ids ) );
		if ( empty( $customer_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$sql          = 'SELECT * FROM %i WHERE customer_id IN (' . $placeholders . ') ORDER BY ' . self::safe_order_by( $order_by );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is only repeated %d placeholder syntax (its count matches count( $customer_ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
		return $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::table() ), $customer_ids ) ) );
	}

	public static function for_company( $company_id, $order_by = 'period_start DESC' ) {
		return self::where( array( 'company_id' => $company_id ), $order_by );
	}

	public static function create( $data ) {
		$now                = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		if ( empty( $data['status'] ) ) {
			$data['status'] = self::STATUS_ACTIVE;
		}
		return self::insert( $data );
	}

	public static function save( $id, $data ) {
		$data['updated_at'] = current_time( 'mysql' );
		return self::update( $id, $data );
	}
}
