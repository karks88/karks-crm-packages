<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single logged usage entry (date + hours + description) against a
 * KCRM_Pkg_Package's allotment.
 */
class KCRM_Pkg_Usage extends KCRM_Model_Base {

	public static function table() {
		return KCRM_Pkg_DB::usage();
	}

	protected static function columns() {
		return array(
			'package_id'  => '%d',
			'entry_date'  => '%s',
			'hours'       => '%f',
			'description' => '%s',
			'created_at'  => '%s',
			'updated_at'  => '%s',
		);
	}

	public static function for_package( $package_id, $order_by = 'entry_date ASC, id ASC' ) {
		return self::where( array( 'package_id' => $package_id ), $order_by );
	}

	/**
	 * Total hours logged against a package so far, computed live (same
	 * pattern as karks-crm's own KCRM_Payment::total_for_invoice()) rather
	 * than cached on the package row -- avoids a drift-on-delete/edit bug
	 * class, and usage-entry volume per package is trivially small.
	 */
	public static function hours_logged( $package_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(hours), 0) FROM %i WHERE package_id = %d', self::table(), $package_id ) );
	}

	/** Deletes every usage entry logged against a package (called when the package itself is deleted, so they don't become orphaned). */
	public static function delete_for_package( $package_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; $wpdb->delete() already escapes values.
		return $wpdb->delete( self::table(), array( 'package_id' => $package_id ), array( '%d' ) );
	}

	public static function create( $data ) {
		$now                = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		return self::insert( $data );
	}
}
