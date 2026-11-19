<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds sample packages to Karks CRM's demo company, so the WordPress.org
 * Live Preview (.wordpress-org/blueprints/blueprint.json) doesn't land on an
 * empty Packages screen.
 *
 * Only ever invoked via the 'kcrmpkg_run_demo_seeder' action, which that
 * blueprint's runPHP step fires right after karks-crm's own
 * 'kcrm_run_demo_seeder'. Nothing on a real install fires it.
 *
 * This is the one place the add-on creates a karks-crm row: a "Web Hosting"
 * service for the sample term package.
 */
class KCRM_Pkg_Demo_Seeder {

	/** Fallback for KCRM_Demo_Seeder::DEMO_COMPANY_NAME on karks-crm versions without it. */
	const DEMO_COMPANY_NAME = 'Riverside Web Studio';

	/**
	 * Seeds demo packages once. Does nothing if karks-crm's demo company is
	 * missing or already has packages (e.g. a Playground restart re-running
	 * the blueprint).
	 */
	public static function maybe_seed() {
		$name      = defined( 'KCRM_Demo_Seeder::DEMO_COMPANY_NAME' ) ? KCRM_Demo_Seeder::DEMO_COMPANY_NAME : self::DEMO_COMPANY_NAME;
		$companies = KCRM_Company::where( array( 'name' => $name ), 'id ASC', 1 );
		if ( ! $companies ) {
			return;
		}
		$company_id = (int) $companies[0]->id;

		if ( KCRM_Pkg_Package::for_company( $company_id ) ) {
			return;
		}

		$clients = array_values(
			array_filter(
				KCRM_Customer::where( array( 'company_id' => $company_id ), 'id ASC' ),
				static function ( $customer ) {
					return empty( $customer->parent_customer_id );
				}
			)
		);
		if ( ! $clients ) {
			return;
		}

		$maintenance = self::maintenance_service_id( $company_id );
		$hosting     = KCRM_Service::create(
			array(
				'company_id'  => $company_id,
				'name'        => 'Web Hosting',
				'description' => '1 year of web hosting, with software updates and daily backups. Expires:',
				'type'        => KCRM_Service::TYPE_PROJECT,
				'rate'        => 300,
				'is_taxable'  => 0,
			)
		);

		$first  = (int) $clients[0]->id;
		$second = isset( $clients[1] ) ? (int) $clients[1]->id : $first;
		$today  = new DateTimeImmutable( current_time( 'Y-m-d' ) );

		// A current hours package, partly used.
		$current = KCRM_Pkg_Package::create(
			array(
				'company_id'     => $company_id,
				'customer_id'    => $first,
				'service_id'     => $maintenance,
				'package_type'   => KCRM_Pkg_Package::TYPE_HOURS,
				'allotted_hours' => 10,
				'price_paid'     => 850,
				'period_start'   => $today->modify( '-2 months' )->format( 'Y-m-d' ),
				'period_end'     => $today->modify( '+4 months' )->format( 'Y-m-d' ),
				'status'         => KCRM_Pkg_Package::STATUS_ACTIVE,
			)
		);
		self::log_usage(
			$current,
			array(
				array( '-50 days', 1.5, 'Plugin and theme updates; checked contact form' ),
				array( '-34 days', 2, 'Added new listings page template' ),
				array( '-20 days', 0.75, 'Restored a deleted page from backup' ),
				array( '-6 days', 1.25, 'Speed audit and image optimization' ),
			),
			$today
		);

		// Last year's hours package, now in Package History.
		$expired = KCRM_Pkg_Package::create(
			array(
				'company_id'     => $company_id,
				'customer_id'    => $first,
				'service_id'     => $maintenance,
				'package_type'   => KCRM_Pkg_Package::TYPE_HOURS,
				'allotted_hours' => 8,
				'price_paid'     => 700,
				'period_start'   => $today->modify( '-14 months' )->format( 'Y-m-d' ),
				'period_end'     => $today->modify( '-2 months -1 day' )->format( 'Y-m-d' ),
				'status'         => KCRM_Pkg_Package::STATUS_EXPIRED,
			)
		);
		self::log_usage(
			$expired,
			array(
				array( '-13 months', 3, 'Site migration to new host' ),
				array( '-9 months', 2.5, 'WordPress core and plugin updates' ),
				array( '-4 months', 3.25, 'Accessibility fixes on home page' ),
			),
			$today
		);

		// A yearly hosting term ending soon, so the renewal badge and Renew buttons show.
		if ( $hosting ) {
			$end = $today->modify( '+20 days' );
			KCRM_Pkg_Package::create(
				array(
					'company_id'     => $company_id,
					'customer_id'    => $second,
					'service_id'     => $hosting,
					'package_type'   => KCRM_Pkg_Package::TYPE_TERM,
					'allotted_hours' => 0,
					'price_paid'     => 300,
					'period_start'   => $end->modify( '+1 day -1 year' )->format( 'Y-m-d' ),
					'period_end'     => $end->format( 'Y-m-d' ),
					'status'         => KCRM_Pkg_Package::STATUS_ACTIVE,
				)
			);
		}
	}

	/** The demo company's "Website Maintenance" service, or its first service. */
	private static function maintenance_service_id( $company_id ) {
		$services = KCRM_Service::for_company( $company_id, 'id ASC' );
		foreach ( $services as $service ) {
			if ( 'Website Maintenance' === $service->name ) {
				return (int) $service->id;
			}
		}
		return $services ? (int) $services[0]->id : null;
	}

	/**
	 * @param int               $package_id
	 * @param array             $entries Each array( relative date, hours, description ).
	 * @param DateTimeImmutable $today
	 */
	private static function log_usage( $package_id, array $entries, DateTimeImmutable $today ) {
		if ( ! $package_id ) {
			return;
		}
		foreach ( $entries as $entry ) {
			KCRM_Pkg_Usage::create(
				array(
					'package_id'  => $package_id,
					'entry_date'  => $today->modify( $entry[0] )->format( 'Y-m-d' ),
					'hours'       => $entry[1],
					'description' => $entry[2],
				)
			);
		}
	}
}
