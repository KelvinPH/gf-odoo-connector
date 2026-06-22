<?php
/**
 * Dashboard metrics and activity data for GF Odoo Connector.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync dashboard data helpers.
 */
class Dashboard {

	private const COUNTS_TRANSIENT = 'gf_odoo_dashboard_counts';
	private const COUNTS_TTL       = 300; // 5 minutes.

	/**
	 * @return string GF entry meta table name.
	 */
	private static function meta_table(): string {
		global $wpdb;

		if ( class_exists( 'GFFormsModel' ) ) {
			return GFFormsModel::get_entry_meta_table_name();
		}

		return $wpdb->prefix . 'gf_entry_meta';
	}

	/**
	 * Summary counts for metric cards (cached 5 minutes).
	 *
	 * @return array{synced_today: int, pending: int, failed: int, total: int}
	 */
	public static function get_summary_counts(): array {
		$cached = get_transient( self::COUNTS_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts = self::compute_summary_counts();
		set_transient( self::COUNTS_TRANSIENT, $counts, self::COUNTS_TTL );

		return $counts;
	}

	/**
	 * Invalidate cached dashboard counts (after sync success/failure).
	 */
	public static function invalidate_summary_counts_cache(): void {
		delete_transient( self::COUNTS_TRANSIENT );
	}

	/**
	 * Compute summary counts from the database.
	 *
	 * @return array{synced_today: int, pending: int, failed: int, total: int}
	 */
	private static function compute_summary_counts(): array {
		global $wpdb;

		$table = self::meta_table();
		$today = current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_rows = $wpdb->get_results(
			"SELECT meta_value AS status, COUNT(*) AS count
			FROM {$table}
			WHERE meta_key = 'odoo_sync_status'
			GROUP BY meta_value",
			ARRAY_A
		);

		$by_status = array();
		if ( is_array( $status_rows ) ) {
			foreach ( $status_rows as $row ) {
				$by_status[ (string) ( $row['status'] ?? '' ) ] = (int) ( $row['count'] ?? 0 );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$synced_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE meta_key = 'odoo_sync_at'
				AND meta_value >= %s",
				$today . ' 00:00:00'
			)
		);

		return array(
			'synced_today' => $synced_today,
			'pending'      => (int) ( $by_status['pending'] ?? 0 ) + (int) ( $by_status['retrying'] ?? 0 ),
			'failed'       => (int) ( $by_status['failed'] ?? 0 ),
			'total'        => (int) ( $by_status['success'] ?? 0 ),
		);
	}

	/**
	 * Bar chart data for the last N days.
	 *
	 * @param int $days Number of days.
	 *
	 * @return array{labels: array, success: array, failed: array}
	 */
	public static function get_chart_data( int $days = 14 ): array {
		global $wpdb;

		$meta_table  = self::meta_table();
		$error_table = Error_Logger::get_table_name();
		$labels      = array();
		$success     = array();
		$failed      = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date     = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
			$labels[] = wp_date( 'd M', strtotime( $date . ' 12:00:00' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$success[] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT( DISTINCT entry_id )
					FROM {$meta_table}
					WHERE meta_key = 'odoo_sync_at'
					AND DATE( meta_value ) = %s",
					$date
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$failed[] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$error_table}
					WHERE DATE( created_at ) = %s
					AND resolved = 0",
					$date
				)
			);
		}

		return compact( 'labels', 'success', 'failed' );
	}

	/**
	 * @param int $limit Max rows.
	 *
	 * @return array<int, array>
	 */
	public static function get_recent_errors( int $limit = 5 ): array {
		return Error_Logger::get_errors(
			array(
				'resolved' => false,
				'limit'    => $limit,
			)
		);
	}

	/**
	 * Recent successful syncs with entry and Odoo IDs.
	 *
	 * @param int $limit Max rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent_successes( int $limit = 5 ): array {
		global $wpdb;

		$meta_table  = self::meta_table();
		$entry_table = $wpdb->prefix . 'gf_entry';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id AS entry_id, e.form_id,
					sync_at.meta_value AS sync_at,
					mod.meta_value AS module,
					lead_id.meta_value AS lead_id,
					ticket_id.meta_value AS ticket_id
				FROM {$entry_table} e
				INNER JOIN {$meta_table} status
					ON status.entry_id = e.id
					AND status.meta_key = 'odoo_sync_status'
					AND status.meta_value = 'success'
				LEFT JOIN {$meta_table} sync_at
					ON sync_at.entry_id = e.id AND sync_at.meta_key = 'odoo_sync_at'
				LEFT JOIN {$meta_table} mod
					ON mod.entry_id = e.id AND mod.meta_key = 'odoo_module'
				LEFT JOIN {$meta_table} lead_id
					ON lead_id.entry_id = e.id AND lead_id.meta_key = 'odoo_lead_id'
				LEFT JOIN {$meta_table} ticket_id
					ON ticket_id.entry_id = e.id AND ticket_id.meta_key = 'odoo_ticket_id'
				ORDER BY sync_at.meta_value DESC, e.id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
