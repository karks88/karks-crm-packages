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

	/**
	 * hours_logged() for many packages in one query (for the Package Usage
	 * report, which would otherwise run one query per row). Still computed
	 * live, not cached.
	 *
	 * @param int[] $package_ids
	 * @return array package_id => hours logged (0.0 for packages with no entries).
	 */
	public static function hours_logged_for_packages( array $package_ids ) {
		global $wpdb;

		$package_ids = array_values( array_unique( array_filter( array_map( 'absint', $package_ids ) ) ) );
		if ( empty( $package_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $package_ids ), '%d' ) );
		$sql          = 'SELECT package_id, COALESCE(SUM(hours), 0) AS hours FROM %i WHERE package_id IN (' . $placeholders . ') GROUP BY package_id';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is only repeated %d placeholder syntax (its count matches count( $package_ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::table() ), $package_ids ) ) );

		$hours = array_fill_keys( $package_ids, 0.0 );
		foreach ( $results as $row ) {
			$hours[ (int) $row->package_id ] = (float) $row->hours;
		}
		return $hours;
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
