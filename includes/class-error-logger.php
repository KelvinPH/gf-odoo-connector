<?php
/**
 * Persistent error log for failed Odoo syncs.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes rows in wp_gf_odoo_errors.
 */
class Error_Logger {

	/**
	 * @return string Full table name including prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'gf_odoo_errors';
	}

	/**
	 * Create or update the error log table (idempotent via dbDelta).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			entry_id bigint(20) unsigned NOT NULL,
			feed_id bigint(20) unsigned NOT NULL,
			module varchar(32) NOT NULL,
			error_code varchar(64) DEFAULT NULL,
			error_message text NOT NULL,
			payload longtext,
			attempt tinyint(3) unsigned NOT NULL DEFAULT 1,
			next_retry_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			retried_at datetime DEFAULT NULL,
			resolved tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY entry_id (entry_id),
			KEY resolved (resolved),
			KEY created_at (created_at),
			KEY idx_resolved_created (resolved, created_at),
			KEY idx_form_entry (form_id, entry_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );

		self::maybe_repair_created_at_column();
		self::maybe_add_performance_indexes();
	}

	/**
	 * Add composite indexes on existing installs (idempotent).
	 */
	public static function maybe_add_performance_indexes(): void {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names   = array();

		if ( is_array( $indexes ) ) {
			foreach ( $indexes as $row ) {
				$names[ (string) ( $row['Key_name'] ?? '' ) ] = true;
			}
		}

		if ( empty( $names['idx_resolved_created'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD KEY idx_resolved_created (resolved, created_at)" );
		}

		if ( empty( $names['idx_form_entry'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD KEY idx_form_entry (form_id, entry_id)" );
		}
	}

	/**
	 * Ensure created_at has a valid default and backfill invalid legacy rows.
	 */
	public static function maybe_repair_created_at_column(): void {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"ALTER TABLE {$table} MODIFY COLUMN created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP"
		);

		$now_gmt = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET created_at = %s WHERE created_at IS NULL OR created_at = '0000-00-00 00:00:00' OR created_at < '1970-01-02 00:00:00'",
				$now_gmt
			)
		);
	}

	/**
	 * Insert an error row.
	 *
	 * @param array $args form_id, entry_id, feed_id, module, error_message, error_code (optional), payload (optional), attempt (optional).
	 *
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public static function log( array $args ): int {
		global $wpdb;

		$form_id  = isset( $args['form_id'] ) ? absint( $args['form_id'] ) : 0;
		$entry_id = isset( $args['entry_id'] ) ? absint( $args['entry_id'] ) : 0;
		$feed_id  = isset( $args['feed_id'] ) ? absint( $args['feed_id'] ) : 0;
		$module   = isset( $args['module'] ) ? sanitize_key( (string) $args['module'] ) : '';

		$error_message = isset( $args['error_message'] ) ? (string) $args['error_message'] : '';
		$error_code    = isset( $args['error_code'] ) ? sanitize_text_field( (string) $args['error_code'] ) : '';
		$payload       = '';

		if ( isset( $args['payload'] ) ) {
			if ( is_string( $args['payload'] ) ) {
				$payload = $args['payload'];
			} else {
				$payload = wp_json_encode( $args['payload'] );
			}
		}

		if ( '' === $error_message ) {
			$error_message = __( 'Unknown Odoo sync error.', 'gf-odoo-connector' );
		}

		$attempt = isset( $args['attempt'] ) ? max( 1, min( 4, (int) $args['attempt'] ) ) : 1;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'form_id'       => $form_id,
				'entry_id'      => $entry_id,
				'feed_id'       => $feed_id,
				'module'        => $module,
				'error_code'    => '' !== $error_code ? $error_code : null,
				'error_message' => $error_message,
				'payload'       => '' !== $payload ? $payload : null,
				'attempt'       => $attempt,
				'created_at'    => current_time( 'mysql' ),
				'resolved'      => 0,
			),
			array(
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
			)
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a single error row by ID.
	 *
	 * @param int $error_id Row ID.
	 *
	 * @return array|null
	 */
	public static function get_error( int $error_id ): ?array {
		global $wpdb;

		if ( $error_id <= 0 ) {
			return null;
		}

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$error_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * List error rows.
	 *
	 * @param array $filters resolved (bool), form_id (int), limit (int), offset (int).
	 *
	 * @return array<int, array>
	 */
	public static function get_errors( array $filters = array() ): array {
		global $wpdb;

		$table  = self::get_table_name();
		$where  = array( '1=1' );
		$values = array();

		if ( array_key_exists( 'resolved', $filters ) ) {
			$where[]  = 'resolved = %d';
			$values[] = $filters['resolved'] ? 1 : 0;
		}

		if ( ! empty( $filters['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$values[] = absint( $filters['form_id'] );
		}

		if ( ! empty( $filters['entry_id'] ) ) {
			$where[]  = 'entry_id = %d';
			$values[] = absint( $filters['entry_id'] );
		}

		if ( ! empty( $filters['feed_id'] ) ) {
			$where[]  = 'feed_id = %d';
			$values[] = absint( $filters['feed_id'] );
		}

		$limit  = min( max( 1, (int) ( $filters['limit'] ?? 50 ) ), 500 );
		$offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';

		$values[] = $limit;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $values ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows matching filters (for pagination).
	 *
	 * @param array $filters Same keys as get_errors except limit/offset.
	 *
	 * @return int
	 */
	public static function count_errors( array $filters = array() ): int {
		global $wpdb;

		$table  = self::get_table_name();
		$where  = array( '1=1' );
		$values = array();

		if ( array_key_exists( 'resolved', $filters ) ) {
			$where[]  = 'resolved = %d';
			$values[] = $filters['resolved'] ? 1 : 0;
		}

		if ( ! empty( $filters['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$values[] = absint( $filters['form_id'] );
		}

		if ( ! empty( $filters['entry_id'] ) ) {
			$where[]  = 'entry_id = %d';
			$values[] = absint( $filters['entry_id'] );
		}

		if ( ! empty( $filters['feed_id'] ) ) {
			$where[]  = 'feed_id = %d';
			$values[] = absint( $filters['feed_id'] );
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );

		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Mark an error as resolved.
	 *
	 * @param int $error_id Row ID.
	 *
	 * @return bool
	 */
	public static function mark_resolved( int $error_id ): bool {
		global $wpdb;

		if ( $error_id <= 0 ) {
			return false;
		}

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array( 'resolved' => 1 ),
			array( 'id' => $error_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Mark multiple errors as resolved.
	 *
	 * @param array<int> $error_ids Row IDs.
	 *
	 * @return int Number of rows updated.
	 */
	public static function mark_resolved_bulk( array $error_ids ): int {
		global $wpdb;

		$error_ids = array_filter( array_map( 'absint', $error_ids ) );

		if ( empty( $error_ids ) ) {
			return 0;
		}

		$table        = self::get_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $error_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET resolved = 1 WHERE id IN ({$placeholders})",
				$error_ids
			)
		);

		return false === $updated ? 0 : (int) $updated;
	}

	/**
	 * Record that a retry was attempted.
	 *
	 * @param int $error_id Row ID.
	 */
	public static function touch_retried_at( int $error_id ): void {
		global $wpdb;

		if ( $error_id <= 0 ) {
			return;
		}

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'retried_at' => current_time( 'mysql', true ) ),
			array( 'id' => $error_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Count unresolved errors.
	 *
	 * @return int
	 */
	public static function get_unresolved_count(): int {
		return self::count_errors( array( 'resolved' => false ) );
	}

	/**
	 * Mark all error rows for an entry as resolved (after successful sync).
	 *
	 * @param int $entry_id Entry ID.
	 *
	 * @return int Rows updated.
	 */
	public static function resolve_by_entry( int $entry_id ): int {
		global $wpdb;

		if ( $entry_id <= 0 ) {
			return 0;
		}

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array( 'resolved' => 1 ),
			array(
				'entry_id' => $entry_id,
				'resolved' => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false === $updated ? 0 : (int) $updated;
	}

	/**
	 * Decode stored payload JSON.
	 *
	 * @param string|null $payload JSON string.
	 *
	 * @return array|null
	 */
	public static function decode_payload( ?string $payload ): ?array {
		if ( null === $payload || '' === $payload ) {
			return null;
		}

		$data = json_decode( $payload, true );

		return is_array( $data ) ? $data : null;
	}
}
