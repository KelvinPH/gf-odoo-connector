<?php
/**
 * Odoo → WordPress webhook receiver (REST + background processing).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles incoming Odoo webhook POSTs and syncs data back to GF entries.
 */
class Webhook_Receiver {

	public const ROUTE_NAMESPACE = 'gf-odoo/v1';
	public const ROUTE_PATH      = '/webhook';
	public const HOOK_PROCESS    = 'gf_odoo_process_webhook';
	public const LOG_TRANSIENT   = 'gf_odoo_webhook_log';
	public const LOG_LIMIT       = 20;

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);
	}

	/**
	 * @return string
	 */
	public static function get_webhook_url(): string {
		return rest_url( self::ROUTE_NAMESPACE . self::ROUTE_PATH );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public function verify_signature( WP_REST_Request $request ) {
		$rate_limit = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$secret = '';

		if ( class_exists( 'GF_Odoo_Addon' ) ) {
			$settings = (array) GF_Odoo_Addon::get_instance()->get_plugin_settings();
			$secret   = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
		}

		if ( '' === $secret ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'GF Odoo Connector: Webhook secret not configured. Accepting all requests.' );
			}
			return true;
		}

		$received = $request->get_header( 'X-Odoo-Signature' );
		$body     = $request->get_body();
		$expected = hash_hmac( 'sha256', $body, $secret );

		if ( ! is_string( $received ) || ! hash_equals( $expected, $received ) ) {
			return new WP_Error(
				'gf_odoo_webhook_unauthorized',
				__( 'Invalid webhook signature.', 'gf-odoo-connector' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Basic per-IP rate limit (60 requests per minute).
	 *
	 * @return true|WP_Error
	 */
	private function check_rate_limit() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'gf_odoo_webhook_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= 60 ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests', 'gf-odoo-connector' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Accept webhook and queue background processing.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) || empty( $payload['model'] ) || empty( $payload['id'] ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Invalid payload' ),
				400
			);
		}

		$log_row = array(
			'time'    => current_time( 'mysql' ),
			'model'   => sanitize_text_field( (string) $payload['model'] ),
			'odoo_id' => absint( $payload['id'] ),
			'event'   => sanitize_text_field( (string) ( $payload['event'] ?? 'write' ) ),
			'entries' => array(),
			'status'  => 'queued',
		);

		self::append_log( $log_row );

		$this->schedule_webhook_job( $payload );

		return new WP_REST_Response(
			array( 'status' => 'accepted' ),
			202
		);
	}

	/**
	 * @param array $payload Odoo webhook JSON.
	 */
	private function schedule_webhook_job( array $payload ): void {
		if ( class_exists( 'GF_Odoo_Async_Sync' ) && GF_Odoo_Async_Sync::uses_action_scheduler() ) {
			as_schedule_single_action(
				time(),
				self::HOOK_PROCESS,
				array( $payload ),
				GF_Odoo_Async_Sync::get_group()
			);
			return;
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), self::HOOK_PROCESS, array( $payload ) );
			if ( class_exists( 'GF_Odoo_Async_Sync' ) ) {
				GF_Odoo_Async_Sync::maybe_spawn_cron();
			}
		}
	}

	/**
	 * Background handler: match GF entries and sync notes/meta.
	 *
	 * @param array $payload Webhook payload.
	 */
	public function process_webhook_job( $payload ): void {
		if ( ! is_array( $payload ) ) {
			return;
		}

		if ( class_exists( 'GF_Odoo_Async_Sync' ) ) {
			$payload = GF_Odoo_Async_Sync::normalize_job_args( $payload );
		} elseif ( isset( $payload[0] ) && is_array( $payload[0] ) ) {
			$payload = $payload[0];
		}

		$model   = sanitize_text_field( (string) ( $payload['model'] ?? '' ) );
		$odoo_id = absint( $payload['id'] ?? 0 );
		$fields  = is_array( $payload['fields'] ?? null ) ? $payload['fields'] : array();
		$event   = sanitize_text_field( (string) ( $payload['event'] ?? 'write' ) );

		$meta_key = match ( $model ) {
			'helpdesk.ticket' => 'odoo_ticket_id',
			'crm.lead'        => 'odoo_lead_id',
			default           => null,
		};

		if ( null === $meta_key || $odoo_id <= 0 ) {
			self::update_latest_log( $model, $odoo_id, array(), 'ignored' );
			return;
		}

		$entries   = $this->find_entries_by_odoo_id( $meta_key, $odoo_id );
		$entry_ids = array();

		foreach ( $entries as $entry ) {
			$entry_id = (int) rgar( $entry, 'id' );
			if ( $entry_id <= 0 ) {
				continue;
			}
			$entry_ids[] = $entry_id;
			$this->sync_back_to_entry( $entry, $model, $fields, $event );
		}

		self::update_latest_log( $model, $odoo_id, $entry_ids, 'processed' );
	}

	/**
	 * @param string $meta_key Meta key.
	 * @param int    $odoo_id  Odoo record ID.
	 *
	 * @return array<int, array>
	 */
	private function find_entries_by_odoo_id( string $meta_key, int $odoo_id ): array {
		global $wpdb;

		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$table = GFFormsModel::get_entry_meta_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT entry_id FROM {$table} WHERE meta_key = %s AND meta_value = %s",
				$meta_key,
				(string) $odoo_id
			)
		);

		$entries = array();

		foreach ( $entry_ids as $entry_id ) {
			$entry = GFAPI::get_entry( (int) $entry_id );
			if ( ! is_wp_error( $entry ) && ! empty( $entry ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @param array  $entry  GF entry.
	 * @param string $model  Odoo model.
	 * @param array  $fields Changed fields.
	 * @param string $event  Event type.
	 */
	private function sync_back_to_entry( array $entry, string $model, array $fields, string $event ): void {
		unset( $event );

		$entry_id   = (int) rgar( $entry, 'id' );
		$note_parts = array();

		foreach ( $fields as $field_name => $value ) {
			$field_name = sanitize_key( (string) $field_name );
			$label      = $this->get_field_label( $model, $field_name );
			$display    = $this->format_field_value_for_display( $value );
			if ( '' !== $display ) {
				$note_parts[] = $label . ': ' . $display;
			}
		}

		if ( ! empty( $note_parts ) && class_exists( 'GF_Odoo_Addon' ) ) {
			GF_Odoo_Addon::get_instance()->add_note(
				$entry_id,
				sprintf(
					/* translators: %s: comma-separated field changes */
					__( 'Odoo update received: %s', 'gf-odoo-connector' ),
					implode( ', ', $note_parts )
				),
				'note'
			);
		}

		if ( isset( $fields['stage_id'] ) ) {
			$stage_name = $this->format_field_value_for_display( $fields['stage_id'] );
			if ( '' !== $stage_name ) {
				gform_update_meta( $entry_id, 'odoo_stage', $stage_name );
			}
		}

		if ( isset( $fields['user_id'] ) ) {
			$user_name = $this->format_field_value_for_display( $fields['user_id'] );
			if ( '' !== $user_name ) {
				gform_update_meta( $entry_id, 'odoo_assigned_to', $user_name );
			}
		}

		gform_update_meta( $entry_id, 'odoo_last_webhook_at', current_time( 'mysql' ) );

		/**
		 * Fires after an Odoo webhook updates a GF entry.
		 *
		 * @param array  $entry  GF entry.
		 * @param string $model  Odoo model name.
		 * @param array  $fields Changed fields from Odoo.
		 */
		do_action( 'gf_odoo_entry_synced_back', $entry, $model, $fields );
	}

	/**
	 * @param mixed $value Field value from Odoo.
	 *
	 * @return string
	 */
	private function format_field_value_for_display( $value ): string {
		if ( is_array( $value ) ) {
			$display = isset( $value[1] ) ? (string) $value[1] : (string) ( $value[0] ?? '' );
			return sanitize_text_field( $display );
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	}

	/**
	 * @param string $model      Odoo model.
	 * @param string $field_name Field technical name.
	 *
	 * @return string
	 */
	private function get_field_label( string $model, string $field_name ): string {
		$labels = array(
			'helpdesk.ticket' => array(
				'stage_id'    => __( 'Status', 'gf-odoo-connector' ),
				'user_id'     => __( 'Assigned to', 'gf-odoo-connector' ),
				'priority'    => __( 'Priority', 'gf-odoo-connector' ),
				'description' => __( 'Resolution', 'gf-odoo-connector' ),
			),
			'crm.lead'        => array(
				'stage_id'    => __( 'Stage', 'gf-odoo-connector' ),
				'user_id'     => __( 'Assigned to', 'gf-odoo-connector' ),
				'probability' => __( 'Probability', 'gf-odoo-connector' ),
			),
		);

		return $labels[ $model ][ $field_name ] ?? sanitize_text_field( $field_name );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_log(): array {
		$log = get_transient( self::LOG_TRANSIENT );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * @param array<string, mixed> $row Log row.
	 */
	public static function append_log( array $row ): void {
		$log = self::get_log();
		array_unshift( $log, $row );
		$log = array_slice( $log, 0, self::LOG_LIMIT );
		set_transient( self::LOG_TRANSIENT, $log, WEEK_IN_SECONDS );
	}

	/**
	 * @param string $model    Model.
	 * @param int    $odoo_id  Odoo ID.
	 * @param array  $entries  Matched entry IDs.
	 * @param string $status   Status label.
	 */
	private static function update_latest_log( string $model, int $odoo_id, array $entries, string $status ): void {
		$log = self::get_log();

		foreach ( $log as $index => $row ) {
			if (
				isset( $row['model'], $row['odoo_id'] )
				&& (string) $row['model'] === $model
				&& (int) $row['odoo_id'] === $odoo_id
				&& ( ! isset( $row['status'] ) || 'queued' === $row['status'] )
			) {
				$log[ $index ]['entries'] = array_map( 'absint', $entries );
				$log[ $index ]['status']  = sanitize_text_field( $status );
				set_transient( self::LOG_TRANSIENT, $log, WEEK_IN_SECONDS );
				return;
			}
		}
	}
}
