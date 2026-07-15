<?php
/**
 * Background Odoo sync scheduling (Action Scheduler + WP-Cron fallback).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants and helpers for async GF → Odoo sync jobs.
 */
class GF_Odoo_Async_Sync {

	public const HOOK           = 'gf_odoo_sync_entry';
	public const GROUP          = 'gf-odoo-connector';
	public const SCHEDULE_DELAY = 5;

	/**
	 * Action Scheduler group (per-site on multisite).
	 *
	 * @return string
	 */
	public static function get_group(): string {
		if ( is_multisite() ) {
			return 'gf-odoo-connector-' . get_current_blog_id();
		}

		return self::GROUP;
	}
	public const MAX_ATTEMPTS   = 4;

	/**
	 * Retry delays after attempt 1, 2, and 3 fail (attempt 4 = give up).
	 *
	 * @return array<int, int>
	 */
	public static function retry_delays(): array {
		$delays = array(
			1 => 5 * MINUTE_IN_SECONDS,
			2 => HOUR_IN_SECONDS,
			3 => DAY_IN_SECONDS,
		);

		/**
		 * Filter retry delays (seconds) keyed by failed attempt number.
		 *
		 * @param array<int, int> $delays Attempt => delay in seconds.
		 */
		return (array) apply_filters( 'gf_odoo_retry_delays', $delays );
	}

	/**
	 * Whether background scheduling is available (Action Scheduler or WP-Cron).
	 */
	public static function is_available(): bool {
		return self::uses_action_scheduler() || function_exists( 'wp_schedule_single_event' );
	}

	/**
	 * Whether Action Scheduler functions are loaded.
	 */
	public static function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * Register the background job hook (idempotent).
	 */
	public static function register_hook(): void {
		if ( ! class_exists( 'GF_Odoo_Addon' ) ) {
			return;
		}

		$addon = GF_Odoo_Addon::get_instance();
		add_action( self::HOOK, array( $addon, 'process_sync_job' ), 10, 1 );
	}

	/**
	 * Schedule a sync job. Uses Action Scheduler when present, otherwise WP-Cron.
	 *
	 * @param int   $timestamp Unix timestamp.
	 * @param array $payload   Job payload.
	 *
	 * @return int Action/event ID, or 0 on failure.
	 */
	public static function schedule( int $timestamp, array $payload ): int {
		if ( ! self::is_available() ) {
			return 0;
		}

		$timestamp = max( time(), $timestamp );

		if ( self::uses_action_scheduler() ) {
			$action_id = (int) as_schedule_single_action(
				$timestamp,
				self::HOOK,
				array( $payload ),
				self::get_group()
			);

			if ( $action_id > 0 ) {
				return $action_id;
			}
		}

		return self::schedule_wp_cron( $timestamp, $payload );
	}

	/**
	 * @param int   $timestamp Unix timestamp.
	 * @param array $payload   Job payload.
	 *
	 * @return int 1 when scheduled, 0 on failure.
	 */
	private static function schedule_wp_cron( int $timestamp, array $payload ): int {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return 0;
		}

		self::unschedule_wp_cron_jobs_for(
			(int) ( $payload['entry_id'] ?? 0 ),
			(int) ( $payload['feed_id'] ?? 0 )
		);

		$scheduled = wp_schedule_single_event( $timestamp, self::HOOK, array( $payload ) );

		if ( false === $scheduled ) {
			return 0;
		}

		self::maybe_spawn_cron();

		return 1;
	}

	/**
	 * Trigger WP-Cron processing after scheduling (helps local/dev sites).
	 */
	public static function maybe_spawn_cron(): void {
		if ( self::uses_action_scheduler() || defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 *
	 * @return bool
	 */
	public static function has_pending_job( int $entry_id, int $feed_id ): bool {
		if ( $entry_id <= 0 || $feed_id <= 0 ) {
			return false;
		}

		if ( self::uses_action_scheduler() && self::has_pending_action_scheduler_job( $entry_id, $feed_id ) ) {
			return true;
		}

		return self::has_pending_wp_cron_job( $entry_id, $feed_id );
	}

	/**
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 *
	 * @return bool
	 */
	private static function has_pending_action_scheduler_job( int $entry_id, int $feed_id ): bool {
		$status = class_exists( 'ActionScheduler_Store' )
			? ActionScheduler_Store::STATUS_PENDING
			: 'pending';

		$ids = as_get_scheduled_actions(
			array(
				'hook'   => self::HOOK,
				'status' => $status,
				'group'  => self::get_group(),
			),
			'ids'
		);

		if ( empty( $ids ) || ! class_exists( 'ActionScheduler' ) ) {
			return false;
		}

		$store = ActionScheduler::store();

		foreach ( $ids as $action_id ) {
			$job = self::extract_job_from_action( $store->fetch_action( $action_id ) );

			if (
				(int) ( $job['entry_id'] ?? 0 ) === $entry_id
				&& (int) ( $job['feed_id'] ?? 0 ) === $feed_id
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 *
	 * @return bool
	 */
	private static function has_pending_wp_cron_job( int $entry_id, int $feed_id ): bool {
		return null !== self::get_wp_cron_run_time( $entry_id, $feed_id );
	}

	/**
	 * Next scheduled run time for an entry+feed (pending jobs only).
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 *
	 * @return int|null Unix timestamp.
	 */
	public static function get_next_run_timestamp( int $entry_id, int $feed_id ): ?int {
		if ( $entry_id <= 0 ) {
			return null;
		}

		$from_meta = self::get_retry_timestamp_from_entry_meta( $entry_id );
		if ( null !== $from_meta ) {
			return $from_meta;
		}

		$times = array();

		if ( self::uses_action_scheduler() ) {
			$as_time = self::get_action_scheduler_run_time( $entry_id, $feed_id );
			if ( null !== $as_time ) {
				$times[] = $as_time;
			}
		}

		$cron_time = self::get_wp_cron_run_time( $entry_id, $feed_id );
		if ( null !== $cron_time ) {
			$times[] = $cron_time;
		}

		if ( empty( $times ) ) {
			return null;
		}

		return min( $times );
	}

	/**
	 * @param int $entry_id Entry ID.
	 *
	 * @return int|null
	 */
	public static function get_retry_timestamp_from_entry_meta( int $entry_id ): ?int {
		if ( ! function_exists( 'gform_get_meta' ) || $entry_id <= 0 ) {
			return null;
		}

		$raw = gform_get_meta( $entry_id, 'odoo_next_retry_at' );

		if ( '' === $raw || null === $raw ) {
			return null;
		}

		if ( is_numeric( $raw ) ) {
			$ts = (int) $raw;
			return $ts > 0 ? $ts : null;
		}

		$ts = strtotime( (string) $raw );

		return false !== $ts ? $ts : null;
	}

	/**
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 *
	 * @return int|null
	 */
	private static function get_action_scheduler_run_time( int $entry_id, int $feed_id ): ?int {
		if ( ! self::uses_action_scheduler() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		$status = class_exists( 'ActionScheduler_Store' )
			? ActionScheduler_Store::STATUS_PENDING
			: 'pending';

		$ids = as_get_scheduled_actions(
			array(
				'hook'   => self::HOOK,
				'status' => $status,
				'group'  => self::get_group(),
			),
			'ids'
		);

		if ( empty( $ids ) || ! class_exists( 'ActionScheduler' ) ) {
			return null;
		}

		$store    = ActionScheduler::store();
		$earliest = null;

		foreach ( $ids as $action_id ) {
			$action = $store->fetch_action( $action_id );
			$job    = self::extract_job_from_action( $action );

			if (
				(int) ( $job['entry_id'] ?? 0 ) !== $entry_id
				|| (int) ( $job['feed_id'] ?? 0 ) !== $feed_id
			) {
				continue;
			}

			$scheduled = null;

			if ( is_object( $action ) && method_exists( $action, 'get_schedule' ) ) {
				$schedule = $action->get_schedule();
				if ( is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ) {
					$date = $schedule->get_date();
					if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
						$scheduled = (int) $date->getTimestamp();
					}
				}
			}

			if ( null === $scheduled && is_object( $action ) && method_exists( $store, 'get_date' ) ) {
				$scheduled = (int) $store->get_date( $action_id );
			}

			if ( null === $scheduled || $scheduled <= 0 ) {
				continue;
			}

			if ( null === $earliest || $scheduled < $earliest ) {
				$earliest = $scheduled;
			}
		}

		return $earliest;
	}

	/**
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 *
	 * @return int|null
	 */
	private static function get_wp_cron_run_time( int $entry_id, int $feed_id ): ?int {
		$crons = _get_cron_array();

		if ( ! is_array( $crons ) ) {
			return null;
		}

		$earliest = null;

		foreach ( $crons as $timestamp => $hooks ) {
			if ( empty( $hooks[ self::HOOK ] ) || ! is_array( $hooks[ self::HOOK ] ) ) {
				continue;
			}

			foreach ( $hooks[ self::HOOK ] as $event ) {
				$args = isset( $event['args'][0] ) ? $event['args'][0] : null;
				$job  = self::normalize_job_args( $args );

				if (
					(int) ( $job['entry_id'] ?? 0 ) !== $entry_id
					|| ( $feed_id > 0 && (int) ( $job['feed_id'] ?? 0 ) !== $feed_id )
				) {
					continue;
				}

				$run_at = (int) $timestamp;

				if ( null === $earliest || $run_at < $earliest ) {
					$earliest = $run_at;
				}
			}
		}

		return $earliest;
	}

	/**
	 * Cancel pending auto-retry / queued sync jobs for an entry+feed (e.g. before manual retry).
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 */
	public static function cancel_pending_jobs( int $entry_id, int $feed_id ): void {
		if ( $entry_id <= 0 ) {
			return;
		}

		self::unschedule_wp_cron_jobs_for( $entry_id, $feed_id );
		self::cancel_action_scheduler_jobs_for( $entry_id, $feed_id );

		if ( function_exists( 'gform_delete_meta' ) ) {
			gform_delete_meta( $entry_id, 'odoo_next_retry_at' );
		}
	}

	/**
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 */
	private static function cancel_action_scheduler_jobs_for( int $entry_id, int $feed_id ): void {
		if ( ! self::uses_action_scheduler() || ! class_exists( 'ActionScheduler' ) ) {
			return;
		}

		$status = class_exists( 'ActionScheduler_Store' )
			? ActionScheduler_Store::STATUS_PENDING
			: 'pending';

		$ids = as_get_scheduled_actions(
			array(
				'hook'   => self::HOOK,
				'status' => $status,
				'group'  => self::get_group(),
			),
			'ids'
		);

		if ( empty( $ids ) ) {
			return;
		}

		$store = ActionScheduler::store();

		foreach ( $ids as $action_id ) {
			$action = $store->fetch_action( $action_id );
			$job    = self::extract_job_from_action( $action );

			if (
				(int) ( $job['entry_id'] ?? 0 ) !== $entry_id
				|| ( $feed_id > 0 && (int) ( $job['feed_id'] ?? 0 ) !== $feed_id )
			) {
				continue;
			}

			if ( method_exists( $store, 'cancel_action' ) ) {
				$store->cancel_action( (int) $action_id );
			} elseif ( function_exists( 'as_unschedule_action' ) && is_object( $action ) && method_exists( $action, 'get_args' ) ) {
				as_unschedule_action( self::HOOK, $action->get_args(), self::get_group() );
			}
		}
	}

	/**
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 */
	private static function unschedule_wp_cron_jobs_for( int $entry_id, int $feed_id ): void {
		$crons = _get_cron_array();

		if ( ! is_array( $crons ) ) {
			return;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			if ( empty( $hooks[ self::HOOK ] ) || ! is_array( $hooks[ self::HOOK ] ) ) {
				continue;
			}

			foreach ( $hooks[ self::HOOK ] as $key => $event ) {
				$args = isset( $event['args'][0] ) ? $event['args'][0] : null;
				$job  = self::normalize_job_args( $args );

				if (
					(int) ( $job['entry_id'] ?? 0 ) !== $entry_id
					|| ( $feed_id > 0 && (int) ( $job['feed_id'] ?? 0 ) !== $feed_id )
				) {
					continue;
				}

				wp_unschedule_event( (int) $timestamp, self::HOOK, array( $args ) );
				unset( $crons[ $timestamp ][ self::HOOK ][ $key ] );
			}
		}
	}

	/**
	 * @param mixed $action Action Scheduler action object.
	 *
	 * @return array
	 */
	private static function extract_job_from_action( $action ): array {
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
			return array();
		}

		return self::normalize_job_args( $action->get_args() );
	}

	/**
	 * Normalize hook/cron arguments to a job array.
	 *
	 * @param mixed $args Raw args.
	 *
	 * @return array
	 */
	public static function normalize_job_args( $args ): array {
		if ( ! is_array( $args ) ) {
			return array();
		}

		if ( isset( $args['entry_id'] ) || isset( $args['sync_payload'] ) ) {
			return $args;
		}

		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			return $args[0];
		}

		return $args;
	}
}
