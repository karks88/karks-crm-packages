<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A purchased package. Two types:
 *
 * - 'hours': a maintenance allotment (e.g. "4 hours for $200"). Hours
 *   remaining is always computed live from KCRM_Pkg_Usage::hours_logged(),
 *   never stored here -- see that class for why.
 * - 'term': a time-based package (e.g. yearly hosting) defined only by its
 *   period_start/period_end. allotted_hours is always 0, usage entries are
 *   notes with hours = 0, and renew() rolls it into a new term.
 *
 * Extends karks-crm's own KCRM_Model_Base directly (confirmed generic and
 * stateless) rather than duplicating its CRUD helpers.
 */
class KCRM_Pkg_Package extends KCRM_Model_Base {

	const STATUS_ACTIVE    = 'active';
	const STATUS_EXHAUSTED = 'exhausted';
	const STATUS_EXPIRED   = 'expired';
	const STATUS_CANCELLED = 'cancelled';

	const TYPE_HOURS = 'hours';
	const TYPE_TERM  = 'term';

	/** How many days before period_end a term package starts showing "Renews in N days". */
	const RENEWAL_WINDOW_DAYS = 30;

	public static function table() {
		return KCRM_Pkg_DB::packages();
	}

	public static function types() {
		return array(
			self::TYPE_HOURS => __( 'Hours (allotment)', 'karks-crm-packages' ),
			self::TYPE_TERM  => __( 'Term (e.g. yearly hosting)', 'karks-crm-packages' ),
		);
	}

	/** Whether a package row is time-based rather than an hours allotment. Rows without the column (pre-1.2.0 schema) count as hours. */
	public static function is_term( $package ) {
		return isset( $package->package_type ) && self::TYPE_TERM === $package->package_type;
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
			'package_type'   => '%s',
			'renewed_by_id'  => '%d',
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is only repeated %d placeholder syntax (its count matches count( $customer_ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
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
		if ( empty( $data['package_type'] ) ) {
			$data['package_type'] = self::TYPE_HOURS;
		}
		return self::insert( $data );
	}

	/**
	 * Creates the next term of a term package: it starts the day after this
	 * one ends and runs for the same length. The length is measured as a
	 * DateInterval (start -> end + 1 day) rather than a day count, so
	 * calendar terms stay calendar terms: 2026-01-01..2026-12-31 renews as
	 * 2027-01-01..2027-12-31, and 2026-01-15..2026-02-14 as
	 * 2026-02-15..2026-03-14.
	 *
	 * The old package is left alone -- the daily expire_due() cron archives
	 * it once its period_end passes -- apart from renewed_by_id, which points
	 * at the new package. That column is claimed with a conditional UPDATE so
	 * a double-click can't create two renewals.
	 *
	 * @return int|false The new package's id, or false if this package can't be renewed (not a term package, no end date, or already renewed).
	 */
	public static function renew( $package ) {
		global $wpdb;

		if ( ! self::is_term( $package ) || empty( $package->period_end ) || ! empty( $package->renewed_by_id ) ) {
			return false;
		}

		try {
			$old_start = new DateTimeImmutable( $package->period_start );
			$old_end   = new DateTimeImmutable( $package->period_end );
		} catch ( Exception $e ) {
			return false;
		}
		if ( $old_end < $old_start ) {
			return false;
		}

		$length    = $old_start->diff( $old_end->modify( '+1 day' ) );
		$new_start = $old_end->modify( '+1 day' );
		$new_end   = $new_start->add( $length )->modify( '-1 day' );

		$new_id = self::create(
			array(
				'company_id'     => $package->company_id,
				'customer_id'    => $package->customer_id,
				'service_id'     => $package->service_id,
				'package_type'   => self::TYPE_TERM,
				'allotted_hours' => 0,
				'price_paid'     => $package->price_paid,
				'period_start'   => $new_start->format( 'Y-m-d' ),
				'period_end'     => $new_end->format( 'Y-m-d' ),
				'status'         => self::STATUS_ACTIVE,
				'notes'          => $package->notes,
			)
		);
		if ( ! $new_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional UPDATE so only one renewal can claim this package; query text and args go through $wpdb->prepare() on this line.
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET renewed_by_id = %d, updated_at = %s WHERE id = %d AND renewed_by_id IS NULL', self::table(), $new_id, current_time( 'mysql' ), $package->id ) );

		if ( ! $claimed ) {
			self::delete( $new_id );
			return false;
		}
		return $new_id;
	}

	/**
	 * Days until a package's period_end (negative once it has passed), or
	 * null if it has no end date.
	 */
	public static function days_until_end( $package ) {
		if ( empty( $package->period_end ) ) {
			return null;
		}
		try {
			$today = new DateTimeImmutable( current_time( 'Y-m-d' ) );
			$end   = new DateTimeImmutable( $package->period_end );
		} catch ( Exception $e ) {
			return null;
		}
		return (int) $today->diff( $end )->format( '%r%a' );
	}

	/** A term package that hasn't been renewed or cancelled -- i.e. one whose renewal still needs a decision. */
	public static function is_renewable( $package ) {
		return self::is_term( $package )
			&& ! empty( $package->period_end )
			&& empty( $package->renewed_by_id )
			&& self::STATUS_CANCELLED !== $package->status;
	}

	/**
	 * Small "Renews in N days" / "Due for renewal" badge for term packages
	 * that are within RENEWAL_WINDOW_DAYS of their end or past it, or an
	 * empty string otherwise. Shared by the admin list and the customer
	 * section so the two can't drift.
	 */
	public static function renewal_badge_html( $package ) {
		if ( ! self::is_renewable( $package ) ) {
			return '';
		}

		$days = self::days_until_end( $package );
		if ( null === $days || $days > self::RENEWAL_WINDOW_DAYS ) {
			return '';
		}

		if ( $days < 0 ) {
			$label = __( 'Due for renewal', 'karks-crm-packages' );
			$color = '#b91c1c';
		} else {
			/* translators: %d: number of days until the term ends. */
			$label = sprintf( _n( 'Renews in %d day', 'Renews in %d days', $days, 'karks-crm-packages' ), $days );
			$color = '#b45309';
		}

		return sprintf(
			'<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:%s;color:#fff;font-size:10px;text-transform:uppercase;margin-left:4px;">%s</span>',
			esc_attr( $color ),
			esc_html( $label )
		);
	}

	/**
	 * Term packages for a company still awaiting a renewal decision (not
	 * renewed, not cancelled), soonest end date first. Feeds the Upcoming
	 * Renewals report.
	 */
	public static function renewals_for_company( $company_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query; query text and args go through $wpdb->prepare() on this line.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d AND package_type = %s AND renewed_by_id IS NULL AND status <> %s AND period_end IS NOT NULL ORDER BY period_end ASC, id ASC', self::table(), $company_id, self::TYPE_TERM, self::STATUS_CANCELLED ) );
	}

	/**
	 * Validates and sanitizes a submitted package form. Shared by the
	 * wp-admin (KCRM_Pkg_Admin_Packages::save()) and front-end
	 * (KCRM_Pkg_Front_Packages::save()) handlers, so the field rules can't
	 * drift between them -- each caller still does its own nonce,
	 * capability, scope checks and redirects.
	 *
	 * @param array $input      Unslashed request data ( wp_unslash( $_POST ) ).
	 * @param int   $company_id The current company's ID.
	 * @return array|string Data ready for create()/save(), or a notice key ('error', 'no_end') on failure.
	 */
	public static function data_from_form( array $input, $company_id ) {
		if ( ! $company_id ) {
			return 'error';
		}

		$customer_id = isset( $input['customer_id'] ) ? absint( $input['customer_id'] ) : 0;
		if ( ! $customer_id || ! KCRM_Customer::find( $customer_id ) ) {
			return 'error';
		}

		$service_id = isset( $input['service_id'] ) ? absint( $input['service_id'] ) : 0;
		if ( ! $service_id || ! KCRM_Service::find( $service_id ) ) {
			return 'error';
		}

		$package_type = isset( $input['package_type'] ) ? sanitize_key( $input['package_type'] ) : self::TYPE_HOURS;
		if ( ! array_key_exists( $package_type, self::types() ) ) {
			$package_type = self::TYPE_HOURS;
		}
		$is_term = self::TYPE_TERM === $package_type;

		// Term packages are defined by their end date -- without one there's nothing to renew or expire.
		if ( $is_term && empty( $input['period_end'] ) ) {
			return 'no_end';
		}

		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : self::STATUS_ACTIVE;
		if ( ! array_key_exists( $status, self::statuses() ) ) {
			$status = self::STATUS_ACTIVE;
		}

		return array(
			'company_id'     => $company_id,
			'customer_id'    => $customer_id,
			'service_id'     => $service_id,
			'package_type'   => $package_type,
			'allotted_hours' => ( ! $is_term && isset( $input['allotted_hours'] ) ) ? (float) $input['allotted_hours'] : 0,
			'price_paid'     => isset( $input['price_paid'] ) ? (float) $input['price_paid'] : 0,
			'period_start'   => isset( $input['period_start'] ) ? sanitize_text_field( $input['period_start'] ) : '',
			'period_end'     => ! empty( $input['period_end'] ) ? sanitize_text_field( $input['period_end'] ) : null,
			'status'         => $status,
			'notes'          => isset( $input['notes'] ) ? sanitize_textarea_field( $input['notes'] ) : '',
		);
	}

	public static function save( $id, $data ) {
		$data['updated_at'] = current_time( 'mysql' );
		return self::update( $id, $data );
	}

	/**
	 * Auto-expires 'active' packages whose period_end has passed. Run daily
	 * by the kcrmpkg_expire_packages cron event (scheduled/cleared by
	 * KCRM_Pkg_Activator). A bulk conditional UPDATE rather than
	 * find-then-loop, since KCRM_Model_Base::update() only does single rows
	 * by id. Deliberately scoped to 'active' only -- 'cancelled' and
	 * 'exhausted' are deliberate manual states this shouldn't overwrite.
	 *
	 * @return int Number of packages expired.
	 */
	public static function expire_due() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- bulk conditional UPDATE; query text is built from %i/%s placeholders only, filled in via $wpdb->prepare() on the same line.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE status = %s AND period_end IS NOT NULL AND period_end < %s',
				$table,
				self::STATUS_EXPIRED,
				current_time( 'mysql' ),
				self::STATUS_ACTIVE,
				current_time( 'Y-m-d' )
			)
		);
	}
}
