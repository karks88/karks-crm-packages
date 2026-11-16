<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates/updates this add-on's own database tables on activation, and
 * schedules/clears the daily cron event that auto-expires packages
 * (see KCRM_Pkg_Package::expire_due()).
 */
class KCRM_Pkg_Activator {

	const EXPIRE_CRON_HOOK = 'kcrmpkg_expire_packages';

	public static function activate() {
		self::create_tables();
		add_option( 'kcrmpkg_db_version', KCRMPKG_DB_VERSION );
		self::schedule_cron();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::EXPIRE_CRON_HOOK );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'kcrmpkg_db_version' ) !== KCRMPKG_DB_VERSION ) {
			self::create_tables();
			self::drop_label_column_if_exists();
			update_option( 'kcrmpkg_db_version', KCRMPKG_DB_VERSION );
		}

		// Self-heal for installs that were already active before this cron
		// event existed -- activate() only runs on (re)activation, not on
		// a plain plugin-file update, so it can't be relied on alone.
		self::schedule_cron();
	}

	private static function schedule_cron() {
		if ( ! wp_next_scheduled( self::EXPIRE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::EXPIRE_CRON_HOOK );
		}
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$packages = KCRM_Pkg_DB::packages();
		$usage    = KCRM_Pkg_DB::usage();

		$sql = array();

		$sql[] = "CREATE TABLE $packages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NULL,
			package_type VARCHAR(20) NOT NULL DEFAULT 'hours',
			renewed_by_id BIGINT UNSIGNED NULL,
			allotted_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
			price_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
			period_start DATE NOT NULL,
			period_end DATE NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY company_id (company_id),
			KEY customer_id (customer_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $usage (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			package_id BIGINT UNSIGNED NOT NULL,
			entry_date DATE NOT NULL,
			hours DECIMAL(6,2) NOT NULL DEFAULT 0,
			description VARCHAR(255) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY package_id (package_id)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * dbDelta() only ever adds/modifies columns, never drops them, so the
	 * now-unused `label` column (packages are labeled by their related
	 * Service instead) needs an explicit, one-time ALTER TABLE. Checks
	 * information_schema first so this is safe to call on every upgrade,
	 * including for installs that never had the column at all.
	 */
	private static function drop_label_column_if_exists() {
		global $wpdb;
		$table = KCRM_Pkg_DB::packages();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time schema migration, not a runtime query.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'label'",
				$table
			)
		);

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own fixed table-name helper, not user input; ALTER TABLE doesn't support placeholders for identifiers, and this is a one-time schema migration, not a runtime query.
			$wpdb->query( "ALTER TABLE `$table` DROP COLUMN `label`" );
		}
	}
}
