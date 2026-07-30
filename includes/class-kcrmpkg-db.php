<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for this add-on's table names. Deliberately its own
 * kcrmpkg_-prefixed tables, not karks-crm's own karkscrm_* registry --
 * these are a separate plugin's data, not an extension of core's schema.
 */
class KCRM_Pkg_DB {

	public static function packages() {
		global $wpdb;
		return $wpdb->prefix . 'kcrmpkg_packages';
	}

	public static function usage() {
		global $wpdb;
		return $wpdb->prefix . 'kcrmpkg_package_usage';
	}
}
