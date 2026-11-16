<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks this add-on's packages and their usage/notes into karks-crm core's
 * own company export/import (`KCRM_Company_Transfer`), which both its
 * manual "Export Company"/"Import Company" feature and the separate
 * karks-crm-backups add-on are built on -- see `kcrm_company_export_data`/
 * `kcrm_company_import_data` in karks-crm's wiki/Hooks-and-Filters.md.
 * Same approach as karks-crm-customer-notes' KCRM_Notes_Backup_Bridge.
 *
 * Export adds a `kcrmpkg` key: every package for the company (keyed by the
 * original customer/service ids already in $data['customers']/['services'])
 * with its usage entries nested inside.
 *
 * Import, when the file carries that key, recreates the packages against
 * the new customer/service ids from $customer_id_map/$service_id_map,
 * keeping created/updated timestamps and relinking renewal chains
 * (renewed_by_id) to the new package ids. On an in-place restore
 * ($target_company_id), the company's existing packages are replaced.
 *
 * When the file does NOT carry the key (made before this bridge existed, or
 * while this add-on was inactive), existing packages are never deleted. On
 * an in-place restore, core has just deleted and recreated every customer
 * and service with new ids, which would leave existing packages pointing at
 * ids that no longer exist -- so relink_existing() remaps them through the
 * same id maps instead. That's safe because a restore is same-site: the
 * file's old ids are this site's own (never reused) auto-increment ids.
 */
class KCRM_Pkg_Backup_Bridge {

	const KEY = 'kcrmpkg';

	/**
	 * Listener for `kcrm_company_export_data`.
	 *
	 * @param array $data       The export array built by KCRM_Company_Transfer::export().
	 * @param int   $company_id The company being exported.
	 * @return array
	 */
	public static function export_packages( array $data, $company_id ) {
		$packages = array();

		foreach ( KCRM_Pkg_Package::for_company( $company_id, 'id ASC' ) as $package ) {
			$usage = array();
			foreach ( KCRM_Pkg_Usage::for_package( $package->id ) as $entry ) {
				$usage[] = array(
					'entry_date'  => $entry->entry_date,
					'hours'       => (float) $entry->hours,
					'description' => $entry->description,
					'created_at'  => $entry->created_at,
					'updated_at'  => $entry->updated_at,
				);
			}

			$packages[] = array(
				'id'             => (int) $package->id,
				'customer_id'    => (int) $package->customer_id,
				'service_id'     => $package->service_id ? (int) $package->service_id : null,
				'package_type'   => $package->package_type ?? KCRM_Pkg_Package::TYPE_HOURS,
				'renewed_by_id'  => empty( $package->renewed_by_id ) ? null : (int) $package->renewed_by_id,
				'allotted_hours' => (float) $package->allotted_hours,
				'price_paid'     => (float) $package->price_paid,
				'period_start'   => $package->period_start,
				'period_end'     => $package->period_end,
				'status'         => $package->status,
				'notes'          => $package->notes,
				'created_at'     => $package->created_at,
				'updated_at'     => $package->updated_at,
				'usage'          => $usage,
			);
		}

		$data[ self::KEY ] = array(
			'db_version' => KCRMPKG_DB_VERSION,
			'packages'   => $packages,
		);
		return $data;
	}

	/**
	 * Listener for `kcrm_company_import_data`. Fires after core has imported
	 * the company's customers/services/invoices.
	 *
	 * @param array $data              The decoded import payload.
	 * @param int   $company_id        The resolved company id (new or restored-into).
	 * @param array $customer_id_map   Old customer id => new customer id.
	 * @param array $service_id_map    Old service id => new service id.
	 * @param int   $target_company_id 0 for "create new company", else the restored-into company id.
	 */
	public static function import_packages( array $data, $company_id, array $customer_id_map, array $service_id_map, $target_company_id ) {
		if ( ! isset( $data[ self::KEY ]['packages'] ) || ! is_array( $data[ self::KEY ]['packages'] ) ) {
			if ( $target_company_id ) {
				self::relink_existing( $target_company_id, $customer_id_map, $service_id_map );
			}
			return;
		}

		if ( $target_company_id ) {
			foreach ( KCRM_Pkg_Package::for_company( $target_company_id ) as $existing ) {
				KCRM_Pkg_Usage::delete_for_package( $existing->id );
				KCRM_Pkg_Package::delete( $existing->id );
			}
		}

		$types    = array_keys( KCRM_Pkg_Package::types() );
		$statuses = array_keys( KCRM_Pkg_Package::statuses() );
		$id_map   = array(); // Old package id => new package id, for relinking renewed_by_id below.
		$renewals = array(); // Old package id => old renewed_by_id.

		foreach ( $data[ self::KEY ]['packages'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// A package whose client isn't in the file (orphaned or hand-edited) is skipped rather than imported unlinked.
			$new_customer_id = $customer_id_map[ (int) ( $row['customer_id'] ?? 0 ) ] ?? 0;
			$period_start    = self::sanitize_import_date( $row['period_start'] ?? '' );
			if ( ! $new_customer_id || ! $period_start ) {
				continue;
			}

			$old_service_id = (int) ( $row['service_id'] ?? 0 );
			$type           = in_array( $row['package_type'] ?? '', $types, true ) ? $row['package_type'] : KCRM_Pkg_Package::TYPE_HOURS;
			$is_term        = KCRM_Pkg_Package::TYPE_TERM === $type;
			$now            = current_time( 'mysql' );

			$new_id = KCRM_Pkg_Package::insert(
				array(
					'company_id'     => $company_id,
					'customer_id'    => $new_customer_id,
					'service_id'     => $old_service_id ? ( $service_id_map[ $old_service_id ] ?? null ) : null,
					'package_type'   => $type,
					'renewed_by_id'  => null,
					'allotted_hours' => $is_term ? 0 : (float) ( $row['allotted_hours'] ?? 0 ),
					'price_paid'     => (float) ( $row['price_paid'] ?? 0 ),
					'period_start'   => $period_start,
					'period_end'     => self::sanitize_import_date( $row['period_end'] ?? '' ),
					'status'         => in_array( $row['status'] ?? '', $statuses, true ) ? $row['status'] : KCRM_Pkg_Package::STATUS_ACTIVE,
					'notes'          => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
					'created_at'     => self::sanitize_import_datetime( $row['created_at'] ?? '' ) ?: $now,
					'updated_at'     => self::sanitize_import_datetime( $row['updated_at'] ?? '' ) ?: $now,
				)
			);
			if ( ! $new_id ) {
				continue;
			}

			$old_id = (int) ( $row['id'] ?? 0 );
			if ( $old_id ) {
				$id_map[ $old_id ] = $new_id;
				if ( ! empty( $row['renewed_by_id'] ) ) {
					$renewals[ $old_id ] = (int) $row['renewed_by_id'];
				}
			}

			foreach ( (array) ( $row['usage'] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				KCRM_Pkg_Usage::insert(
					array(
						'package_id'  => $new_id,
						'entry_date'  => self::sanitize_import_date( $entry['entry_date'] ?? '' ) ?: $period_start,
						'hours'       => $is_term ? 0 : (float) ( $entry['hours'] ?? 0 ),
						'description' => sanitize_text_field( (string) ( $entry['description'] ?? '' ) ),
						'created_at'  => self::sanitize_import_datetime( $entry['created_at'] ?? '' ) ?: $now,
						'updated_at'  => self::sanitize_import_datetime( $entry['updated_at'] ?? '' ) ?: $now,
					)
				);
			}
		}

		// Second pass: renewed_by_id points at another package, which may have been imported after this one.
		foreach ( $renewals as $old_id => $old_renewed_by ) {
			if ( isset( $id_map[ $old_renewed_by ] ) ) {
				KCRM_Pkg_Package::update( $id_map[ $old_id ], array( 'renewed_by_id' => $id_map[ $old_renewed_by ] ) );
			}
		}
	}

	/**
	 * In-place restore from a file without package data: point the company's
	 * existing packages at the customers/services core just recreated. A
	 * package whose client didn't exist when the backup was made (so the
	 * restore removed it) can't be relinked and is left as it is.
	 */
	private static function relink_existing( $company_id, array $customer_id_map, array $service_id_map ) {
		foreach ( KCRM_Pkg_Package::for_company( $company_id ) as $package ) {
			$changes = array();

			if ( isset( $customer_id_map[ (int) $package->customer_id ] ) ) {
				$changes['customer_id'] = $customer_id_map[ (int) $package->customer_id ];
			}
			if ( $package->service_id && isset( $service_id_map[ (int) $package->service_id ] ) ) {
				$changes['service_id'] = $service_id_map[ (int) $package->service_id ];
			}

			if ( $changes ) {
				KCRM_Pkg_Package::update( $package->id, $changes );
			}
		}
	}

	/** @return string|null A strict 'Y-m-d' date, or null -- mirrors KCRM_Company_Transfer::sanitize_import_date(). */
	private static function sanitize_import_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	/** @return string|null A strict 'Y-m-d H:i:s' datetime, or null. */
	private static function sanitize_import_datetime( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : null;
	}
}
