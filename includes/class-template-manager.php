<?php
/**
 * Global feed templates and per-form overrides.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global feed template storage, form linking, and per-feed field overrides.
 *
 * Templates store a full feed_meta snapshot. Linked forms inherit the template
 * unless a field is overridden in gf_odoo_template_links.overrides.
 */
class Template_Manager {

	/** Transient key for admin notice after template save. */
	public const TRANSIENT_UPDATED_NOTICE = 'gf_odoo_template_updated_notice';

	/**
	 * Create or upgrade wp_gf_odoo_templates and wp_gf_odoo_template_links tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$templates       = self::templates_table();
		$links           = self::links_table();

		$sql = "CREATE TABLE {$templates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			module varchar(32) NOT NULL,
			feed_meta longtext NOT NULL,
			sample_form_id bigint(20) unsigned NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY module (module),
			KEY sample_form_id (sample_form_id)
		) {$charset_collate};

		CREATE TABLE {$links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			template_id bigint(20) unsigned NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			feed_id bigint(20) unsigned NOT NULL,
			overrides longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY form_feed (form_id, feed_id),
			KEY template_id (template_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Full table name for feed templates (with $wpdb->prefix).
	 *
	 * @return string
	 */
	public static function templates_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'gf_odoo_templates';
	}

	/**
	 * Full table name for form↔template links (with $wpdb->prefix).
	 *
	 * @return string
	 */
	public static function links_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'gf_odoo_template_links';
	}

	/**
	 * List all templates ordered by name.
	 *
	 * @return array<int, object> Normalized template rows.
	 */
	public static function get_all(): array {
		global $wpdb;

		$table = self::templates_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			array( self::class, 'normalize_template_row' ),
			$rows
		);
	}

	/**
	 * Load a single template by ID.
	 *
	 * @param int $id Template primary key.
	 *
	 * @return object|null Normalized template row, or null when not found.
	 */
	public static function get( int $id ): ?object {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = self::templates_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);

		if ( ! $row ) {
			return null;
		}

		return self::normalize_template_row( $row );
	}

	/**
	 * Decode feed_meta and normalize sample_form_id on a DB row.
	 *
	 * @param object $row DB row.
	 *
	 * @return object
	 */
	private static function normalize_template_row( $row ) {
		$row->feed_meta = json_decode( (string) $row->feed_meta, true );
		if ( ! is_array( $row->feed_meta ) ) {
			$row->feed_meta = array();
		}

		$row->sample_form_id = isset( $row->sample_form_id ) ? (int) $row->sample_form_id : 0;

		return $row;
	}

	/**
	 * Insert or update a feed template.
	 *
	 * @param array $data Keys: id (optional), name, module, feed_meta, sample_form_id.
	 *
	 * @return int Template ID (new or updated).
	 */
	public static function save( array $data ): int {
		global $wpdb;

		$linked_count = 0;
		$template_id  = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

		if ( $template_id > 0 ) {
			$linked_count = self::count_linked_forms( $template_id );
		}

		$sample_form_id = ! empty( $data['sample_form_id'] ) ? (int) $data['sample_form_id'] : null;

		$payload = array(
			'name'           => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'module'         => sanitize_key( (string) ( $data['module'] ?? 'crm' ) ),
			'feed_meta'      => wp_json_encode( is_array( $data['feed_meta'] ?? null ) ? $data['feed_meta'] : array() ),
			'sample_form_id' => $sample_form_id,
		);

		$table = self::templates_table();

		if ( $template_id > 0 ) {
			$formats = array( '%s', '%s', '%s' );
			if ( null !== $sample_form_id ) {
				$formats[] = '%d';
			} else {
				unset( $payload['sample_form_id'] );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $payload, array( 'id' => $template_id ), $formats, array( '%d' ) );

			if ( $linked_count > 0 ) {
				set_transient(
					self::TRANSIENT_UPDATED_NOTICE,
					array(
						'template_id'  => $template_id,
						'linked_count' => $linked_count,
					),
					MINUTE_IN_SECONDS * 5
				);
			}

			return $template_id;
		}

		$formats = array( '%s', '%s', '%s' );
		if ( null !== $sample_form_id ) {
			$formats[] = '%d';
		} else {
			unset( $payload['sample_form_id'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $payload, $formats );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a template and all form links pointing to it.
	 *
	 * @param int $id Template ID.
	 */
	public static function delete( int $id ): void {
		global $wpdb;

		if ( $id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::templates_table(), array( 'id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::links_table(), array( 'template_id' => $id ), array( '%d' ) );
	}

	/**
	 * Clone a template with a "Copy of …" name.
	 *
	 * @param int $id Source template ID.
	 *
	 * @return int New template ID, or 0 when the source does not exist.
	 */
	public static function duplicate( int $id ): int {
		$source = self::get( $id );

		if ( null === $source ) {
			return 0;
		}

		return self::save(
			array(
				'name'           => sprintf(
					/* translators: %s: template name */
					__( 'Copy of %s', 'gf-odoo-connector' ),
					$source->name
				),
				'module'         => $source->module,
				'feed_meta'      => $source->feed_meta,
				'sample_form_id' => (int) ( $source->sample_form_id ?? 0 ),
			)
		);
	}

	/**
	 * Compare template field mappings to a target form and suggest remaps.
	 *
	 * @param int $template_id    Template ID.
	 * @param int $target_form_id Target Gravity Forms form ID.
	 *
	 * @return array{matched: array, unmatched: array, identical: array}
	 */
	public static function compute_remap( int $template_id, int $target_form_id ): array {
		$empty = array(
			'matched'   => array(),
			'unmatched' => array(),
			'identical' => array(),
		);

		$template = self::get( $template_id );
		if ( ! $template || ! class_exists( 'GFAPI' ) ) {
			$empty['fields'] = array();
			return $empty;
		}

		$target_form = GFAPI::get_form( $target_form_id );
		if ( ! is_array( $target_form ) || empty( $target_form['fields'] ) ) {
			$empty['fields'] = array();
			return $empty;
		}

		$target_by_label = array();
		$target_by_id    = array();

		foreach ( $target_form['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}

			$field_id = (string) (int) ( $field->id ?? 0 );
			$label    = strtolower( trim( (string) ( $field->label ?? '' ) ) );

			if ( $label ) {
				$target_by_label[ $label ] = $field_id;
			}
			if ( $field_id ) {
				$target_by_id[ $field_id ] = $label;
			}
		}

		$addon      = GF_Odoo_Addon::get_instance();
		$feed_meta  = (array) $template->feed_meta;
		$field_rows = 'helpdesk' === (string) $template->module
			? $addon->helpdesk_field_rows()
			: $addon->crm_field_rows();

		$matched   = array();
		$unmatched = array();
		$identical = array();

		foreach ( $field_rows as $row ) {
			$key  = (string) $row['key'];
			$mode = (string) ( $feed_meta[ $key . '_mode' ] ?? 'off' );

			if ( 'field' !== $mode ) {
				continue;
			}

			$stored               = $feed_meta[ $key . '_value' ] ?? '';
			$template_field_id    = GF_Odoo_Addon::get_field_mapping_id( $stored );
			$template_field_label = GF_Odoo_Addon::get_field_mapping_label( $stored );
			$label_lower          = strtolower( trim( $template_field_label ) );

			if ( $template_field_id && isset( $target_by_id[ $template_field_id ] ) ) {
				$target_label = $target_by_id[ $template_field_id ];
				if ( $target_label === $label_lower ) {
					$identical[ $key ] = $template_field_id;
				} else {
					$matched[ $key ] = array(
						'field_id'    => $template_field_id,
						'field_label' => $template_field_label,
						'method'      => 'id',
					);
				}
				continue;
			}

			if ( $label_lower && isset( $target_by_label[ $label_lower ] ) ) {
				$matched[ $key ] = array(
					'field_id'    => $target_by_label[ $label_lower ],
					'field_label' => $template_field_label,
					'method'      => 'label',
				);
				continue;
			}

			$unmatched[ $key ] = array(
				'template_field_label'    => $template_field_label ?: ( $template_field_id ? "field {$template_field_id}" : '' ),
				'field_label_in_template' => (string) $row['label'],
			);
		}

		$fields = array();

		foreach ( $field_rows as $row ) {
			$key  = (string) $row['key'];
			$mode = (string) ( $feed_meta[ $key . '_mode' ] ?? 'off' );

			if ( 'field' !== $mode ) {
				continue;
			}

			$stored               = $feed_meta[ $key . '_value' ] ?? '';
			$template_field_id    = GF_Odoo_Addon::get_field_mapping_id( $stored );
			$template_field_label = GF_Odoo_Addon::get_field_mapping_label( $stored );

			$status            = 'unmatched';
			$target_field_id   = '';
			$target_field_label = '';

			if ( isset( $identical[ $key ] ) ) {
				$status          = 'identical';
				$target_field_id = $identical[ $key ];
			} elseif ( isset( $matched[ $key ] ) ) {
				$status            = 'matched';
				$target_field_id   = (string) ( $matched[ $key ]['field_id'] ?? '' );
				$target_field_label = (string) ( $matched[ $key ]['field_label'] ?? '' );
			}

			$fields[ $key ] = array(
				'odoo_label'           => (string) $row['label'],
				'template_field_id'    => $template_field_id,
				'template_field_label' => $template_field_label,
				'status'               => $status,
				'match_method'         => isset( $matched[ $key ] ) ? (string) ( $matched[ $key ]['method'] ?? '' ) : '',
				'target_field_id'      => $target_field_id,
				'target_field_label'   => $target_field_label,
			);
		}

		return array(
			'matched'   => $matched,
			'unmatched' => $unmatched,
			'identical' => $identical,
			'fields'    => $fields,
		);
	}

	/**
	 * Build sparse overrides for linking a feed when field IDs differ from the template sample.
	 *
	 * @param int   $template_id         Template ID.
	 * @param int   $target_form_id      Target form ID.
	 * @param array $manual_field_ids    field_key => target GF field ID.
	 *
	 * @return array
	 */
	public static function build_link_overrides( int $template_id, int $target_form_id, array $field_remaps = array() ): array {
		$template = self::get( $template_id );
		if ( ! $template ) {
			return array();
		}

		$remap       = self::compute_remap( $template_id, $target_form_id );
		$feed_meta   = (array) $template->feed_meta;
		$overrides   = array();
		$target_form = class_exists( 'GFAPI' ) ? GFAPI::get_form( $target_form_id ) : null;
		$choices     = is_array( $target_form ) ? Field_Mapper::get_form_field_choices( $target_form ) : array();
		$labels      = array();

		foreach ( $choices as $choice ) {
			$labels[ (string) rgar( $choice, 'value' ) ] = preg_replace( '/\s*\(field\s+\d+\)\s*$/i', '', (string) rgar( $choice, 'label' ) );
		}

		$addon      = GF_Odoo_Addon::get_instance();
		$field_rows = 'helpdesk' === (string) $template->module
			? $addon->helpdesk_field_rows()
			: $addon->crm_field_rows();

		foreach ( $field_rows as $row ) {
			$key = (string) $row['key'];
			if ( 'field' !== (string) ( $feed_meta[ $key . '_mode' ] ?? 'off' ) ) {
				continue;
			}

			if ( isset( $remap['identical'][ $key ] ) && ! isset( $field_remaps[ $key ] ) ) {
				continue;
			}

			$template_field_id = GF_Odoo_Addon::get_field_mapping_id( $feed_meta[ $key . '_value' ] ?? '' );
			$target_field_id   = '';

			if ( isset( $field_remaps[ $key ] ) ) {
				$target_field_id = (string) absint( $field_remaps[ $key ] );
			} elseif ( isset( $remap['matched'][ $key ] ) && 'label' === ( $remap['matched'][ $key ]['method'] ?? '' ) ) {
				$target_field_id = (string) ( $remap['matched'][ $key ]['field_id'] ?? '' );
			}

			if ( '' === $target_field_id || (string) $target_field_id === (string) $template_field_id ) {
				continue;
			}

			$overrides[ $key . '_mode' ]  = 'field';
			$overrides[ $key . '_value' ] = array(
				'field_id'    => $target_field_id,
				'field_label' => (string) ( $labels[ $target_field_id ] ?? '' ),
			);
		}

		return $overrides;
	}

	/**
	 * Merge link-time field remaps with existing overrides (preserves non-field overrides).
	 *
	 * When $field_remaps is non-empty (modal confirm), field-row override keys from the
	 * template are cleared first so stale remaps do not linger after re-linking.
	 *
	 * @param int   $template_id      Template ID.
	 * @param int   $form_id          Target form ID.
	 * @param int   $feed_id          Feed ID.
	 * @param array $field_remaps     field_key => GF field ID.
	 * @param array $manual_overrides Sanitized sparse overrides from the feed UI.
	 *
	 * @return array Overrides to persist.
	 */
	public static function resolve_link_overrides( int $template_id, int $form_id, int $feed_id, array $field_remaps, array $manual_overrides ): array {
		$link_overrides = self::build_link_overrides( $template_id, $form_id, $field_remaps );
		$existing       = self::get_feed_overrides( $form_id, $feed_id );
		$template       = self::get( $template_id );
		$base           = $template ? (array) $template->feed_meta : array();

		if ( empty( $field_remaps ) ) {
			return self::prune_invalid_overrides( $base, array_merge( $existing, $manual_overrides ) );
		}

		$keep = $existing;

		if ( $template && class_exists( 'GF_Odoo_Addon' ) ) {
			$addon      = GF_Odoo_Addon::get_instance();
			$feed_meta  = (array) $template->feed_meta;
			$field_rows = 'helpdesk' === (string) $template->module
				? $addon->helpdesk_field_rows()
				: $addon->crm_field_rows();

			foreach ( $field_rows as $row ) {
				$key = (string) $row['key'];
				if ( 'field' !== (string) ( $feed_meta[ $key . '_mode' ] ?? 'off' ) ) {
					continue;
				}
				unset( $keep[ $key . '_mode' ], $keep[ $key . '_value' ] );
			}
		}

		return self::prune_invalid_overrides( $base, array_merge( $keep, $link_overrides, $manual_overrides ) );
	}

	/**
	 * Whether sparse overrides include a real per-form change for a field row.
	 *
	 * @param string $key           Field key.
	 * @param array  $template_meta Template feed_meta.
	 * @param array  $overrides     Stored overrides (raw).
	 *
	 * @return bool
	 */
	public static function field_row_has_stored_override( string $key, array $template_meta, array $overrides ): bool {
		$pruned = self::prune_invalid_overrides( $template_meta, $overrides );

		return array_key_exists( $key . '_mode', $pruned ) || array_key_exists( $key . '_value', $pruned );
	}

	/**
	 * Whether overrides change the resolved mapping vs template + label remap for this form.
	 *
	 * @param string $key           Field key.
	 * @param int    $template_id   Template ID.
	 * @param int    $form_id       Target form ID.
	 * @param array  $template_meta Template feed_meta.
	 * @param array  $overrides     Stored overrides.
	 *
	 * @return bool
	 */
	public static function field_row_has_effective_override( string $key, int $template_id, int $form_id, array $template_meta, array $overrides ): bool {
		$pruned = self::prune_invalid_overrides( $template_meta, $overrides );

		if ( ! array_key_exists( $key . '_mode', $pruned ) && ! array_key_exists( $key . '_value', $pruned ) ) {
			return false;
		}

		if ( $template_id <= 0 || $form_id <= 0 ) {
			return true;
		}

		$baseline = self::resolve_feed_meta_for_form( $template_id, $form_id, $template_meta, array() );
		$mode_key = $key . '_mode';
		$val_key  = $key . '_value';

		$eff_mode = array_key_exists( $mode_key, $pruned ) ? (string) $pruned[ $mode_key ] : (string) rgar( $baseline, $mode_key );
		$eff_val  = array_key_exists( $val_key, $pruned ) ? rgar( $pruned, $val_key ) : rgar( $baseline, $val_key );
		$base_mode = (string) rgar( $baseline, $mode_key );
		$base_val  = rgar( $baseline, $val_key );

		return $eff_mode !== $base_mode || ! self::feed_meta_values_equal( $eff_val, $base_val );
	}

	/**
	 * Detect broken template links (template expects field maps but resolved meta is empty).
	 *
	 * @param int $template_id Template ID.
	 * @param int $form_id     Form ID.
	 * @param int $feed_id     Feed ID.
	 *
	 * @return bool
	 */
	public static function feed_template_mappings_need_repair( int $template_id, int $form_id, int $feed_id ): bool {
		$template = self::get( $template_id );

		if ( ! $template || $form_id <= 0 || $feed_id <= 0 ) {
			return false;
		}

		$resolved = self::get_template_for_feed( $form_id, $feed_id );

		if ( ! is_array( $resolved ) ) {
			return true;
		}

		$base  = (array) $template->feed_meta;
		$addon = GF_Odoo_Addon::get_instance();
		$rows  = 'helpdesk' === (string) $template->module
			? $addon->helpdesk_field_rows()
			: $addon->crm_field_rows();

		foreach ( $rows as $row ) {
			$key = (string) $row['key'];

			if ( 'field' !== (string) ( $base[ $key . '_mode' ] ?? 'off' ) ) {
				continue;
			}

			if ( '' === GF_Odoo_Addon::get_field_mapping_id( $base[ $key . '_value' ] ?? '' ) ) {
				continue;
			}

			if ( '' === GF_Odoo_Addon::get_field_mapping_id( rgar( $resolved, $key . '_value' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rebuild sparse field remaps from the template for the target form.
	 *
	 * @param int $form_id Form ID.
	 * @param int $feed_id Feed ID.
	 *
	 * @return bool True when a link was repaired.
	 */
	public static function repair_feed_template_link( int $form_id, int $feed_id ): bool {
		$template_id = self::get_linked_template_id( $form_id, $feed_id );

		if ( $template_id <= 0 ) {
			return false;
		}

		$link_overrides = self::build_link_overrides( $template_id, $form_id, array() );
		self::link_feed_to_template( $template_id, $form_id, $feed_id, $link_overrides );

		return true;
	}

	/**
	 * Drop empty or redundant override keys (e.g. blank values that wiped template mappings).
	 *
	 * @param array $template_meta Template feed_meta.
	 * @param array $overrides     Stored overrides.
	 *
	 * @return array
	 */
	public static function prune_invalid_overrides( array $template_meta, array $overrides ): array {
		if ( empty( $overrides ) ) {
			return array();
		}

		$pruned     = $overrides;
		$field_keys = array();

		foreach ( array_keys( $overrides ) as $meta_key ) {
			if ( str_ends_with( (string) $meta_key, '_mode' ) ) {
				$field_keys[ substr( (string) $meta_key, 0, -5 ) ] = true;
			} elseif ( str_ends_with( (string) $meta_key, '_value' ) ) {
				$field_keys[ substr( (string) $meta_key, 0, -6 ) ] = true;
			}
		}

		foreach ( array_keys( $field_keys ) as $field_key ) {
			$mode_key  = $field_key . '_mode';
			$val_key   = $field_key . '_value';
			$base_mode = (string) rgar( $template_meta, $mode_key );
			$base_val  = rgar( $template_meta, $val_key );
			$has_mode  = array_key_exists( $mode_key, $pruned );
			$has_val   = array_key_exists( $val_key, $pruned );

			if ( ! $has_mode && ! $has_val ) {
				continue;
			}

			$eff_mode = $has_mode ? (string) $pruned[ $mode_key ] : $base_mode;
			$eff_val  = $has_val ? rgar( $pruned, $val_key ) : $base_val;

			if (
				'field' === $eff_mode
				&& '' === GF_Odoo_Addon::get_field_mapping_id( $eff_val )
				&& '' !== GF_Odoo_Addon::get_field_mapping_id( $base_val )
			) {
				unset( $pruned[ $mode_key ], $pruned[ $val_key ] );
				continue;
			}

			if ( $eff_mode === $base_mode && self::feed_meta_values_equal( $eff_val, $base_val ) ) {
				unset( $pruned[ $mode_key ], $pruned[ $val_key ] );
			}
		}

		return $pruned;
	}

	/**
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return bool
	 */
	private static function feed_meta_values_equal( $a, $b ): bool {
		$entries_a = GF_Odoo_Addon::get_field_mapping_entries( $a );
		$entries_b = GF_Odoo_Addon::get_field_mapping_entries( $b );

		if ( ! empty( $entries_a ) || ! empty( $entries_b ) ) {
			if ( count( $entries_a ) !== count( $entries_b ) ) {
				return false;
			}

			foreach ( $entries_a as $index => $entry ) {
				$other = $entries_b[ $index ] ?? array();
				if ( (string) ( $entry['field_id'] ?? '' ) !== (string) ( $other['field_id'] ?? '' ) ) {
					return false;
				}
				if ( (string) ( $entry['field_label'] ?? '' ) !== (string) ( $other['field_label'] ?? '' ) ) {
					return false;
				}
			}

			return true;
		}

		return (string) $a === (string) $b;
	}

	/**
	 * Resolve template feed_meta for a target form (template + overrides + GF field remaps).
	 *
	 * @param int   $template_id   Template ID.
	 * @param int   $form_id       Target Gravity Forms form ID.
	 * @param array $template_meta Template feed_meta.
	 * @param array $overrides     Stored sparse overrides.
	 *
	 * @return array
	 */
	public static function resolve_feed_meta_for_form( int $template_id, int $form_id, array $template_meta, array $overrides ): array {
		$pruned = self::prune_invalid_overrides( $template_meta, $overrides );
		$meta   = array_replace( $template_meta, $pruned );

		if ( $form_id <= 0 || ! class_exists( 'GF_Odoo_Addon' ) || ! class_exists( 'GFAPI' ) ) {
			return $meta;
		}

		$remap = self::compute_remap( $template_id, $form_id );
		$addon = GF_Odoo_Addon::get_instance();
		$template = self::get( $template_id );
		$field_rows = $template && 'helpdesk' === (string) $template->module
			? $addon->helpdesk_field_rows()
			: $addon->crm_field_rows();

		$target_form = GFAPI::get_form( $form_id );
		$labels      = array();

		if ( is_array( $target_form ) && class_exists( 'Field_Mapper' ) ) {
			foreach ( Field_Mapper::get_form_field_choices( $target_form ) as $choice ) {
				$value = (string) rgar( $choice, 'value' );
				if ( '' !== $value ) {
					$labels[ $value ] = preg_replace( '/\s*\(field\s+\d+\)\s*$/i', '', (string) rgar( $choice, 'label' ) );
				}
			}
		}

		$labels_by_label = array();
		foreach ( $labels as $value => $label ) {
			$label_lower = strtolower( trim( (string) $label ) );
			if ( '' !== $label_lower && ! isset( $labels_by_label[ $label_lower ] ) ) {
				$labels_by_label[ $label_lower ] = $value;
			}
		}

		foreach ( $field_rows as $row ) {
			$key = (string) $row['key'];

			if ( self::field_row_has_stored_override( $key, $template_meta, $pruned ) ) {
				continue;
			}

			if ( 'field' !== (string) ( $meta[ $key . '_mode' ] ?? 'off' ) ) {
				continue;
			}

			$stored_entries = GF_Odoo_Addon::get_field_mapping_entries( rgar( $meta, $key . '_value' ) );
			if ( count( $stored_entries ) > 1 ) {
				$new_entries = array();
				foreach ( $stored_entries as $entry ) {
					$label_lower = strtolower( trim( (string) ( $entry['field_label'] ?? '' ) ) );
					if ( $label_lower && isset( $labels_by_label[ $label_lower ] ) ) {
						$new_entries[] = array(
							'field_id'    => (string) $labels_by_label[ $label_lower ],
							'field_label' => (string) ( $entry['field_label'] ?? '' ),
						);
					} else {
						$new_entries[] = $entry;
					}
				}
				$meta[ $key . '_value' ] = array( 'fields' => $new_entries );
				continue;
			}

			$target_id    = '';
			$target_label = '';

			if ( isset( $remap['identical'][ $key ] ) ) {
				$target_id = (string) $remap['identical'][ $key ];
			} elseif ( isset( $remap['matched'][ $key ] ) ) {
				$target_id    = (string) ( $remap['matched'][ $key ]['field_id'] ?? '' );
				$target_label = (string) ( $remap['matched'][ $key ]['field_label'] ?? '' );
			}

			if ( '' === $target_id ) {
				continue;
			}

			if ( '' === $target_label ) {
				$target_label = (string) ( $labels[ $target_id ] ?? '' );
			}
			if ( '' === $target_label ) {
				$target_label = GF_Odoo_Addon::lookup_gf_field_label( $form_id, $target_id );
			}

			$current_id = GF_Odoo_Addon::get_field_mapping_id( rgar( $meta, $key . '_value' ) );

			if ( $target_id !== $current_id ) {
				$meta[ $key . '_value' ] = array(
					'field_id'    => $target_id,
					'field_label' => $target_label,
				);
			}
		}

		return $meta;
	}

	/**
	 * Count "From field" mappings on a template.
	 *
	 * @param int $template_id Template ID.
	 *
	 * @return int
	 */
	public static function count_field_mode_mappings( int $template_id ): int {
		$template = self::get( $template_id );
		if ( ! $template ) {
			return 0;
		}

		$addon      = GF_Odoo_Addon::get_instance();
		$feed_meta  = (array) $template->feed_meta;
		$field_rows = 'helpdesk' === (string) $template->module
			? $addon->helpdesk_field_rows()
			: $addon->crm_field_rows();
		$count      = 0;

		foreach ( $field_rows as $row ) {
			if ( 'field' === (string) ( $feed_meta[ $row['key'] . '_mode' ] ?? 'off' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * List all form/feed links for a template.
	 *
	 * @param int $template_id Template ID.
	 *
	 * @return array<int, array<string, mixed>> Link rows from the database.
	 */
	public static function get_linked_forms( int $template_id ): array {
		global $wpdb;

		if ( $template_id <= 0 ) {
			return array();
		}

		$table = self::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE template_id = %d ORDER BY form_id ASC",
				$template_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Resolved feed_meta: template + sparse overrides (overrides win).
	 *
	 * @param int $form_id Form ID.
	 * @param int $feed_id Feed ID.
	 *
	 * @return array|null Null when not linked to a template.
	 */
	public static function get_template_for_feed( int $form_id, int $feed_id ): ?array {
		global $wpdb;

		if ( $form_id <= 0 || $feed_id <= 0 ) {
			return null;
		}

		$table = self::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$link = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE form_id = %d AND feed_id = %d",
				$form_id,
				$feed_id
			)
		);

		if ( ! $link ) {
			return null;
		}

		$template = self::get( (int) $link->template_id );
		if ( null === $template ) {
			return null;
		}

		$overrides = array();
		if ( ! empty( $link->overrides ) ) {
			$decoded = json_decode( (string) $link->overrides, true );
			if ( is_array( $decoded ) ) {
				$overrides = $decoded;
			}
		}

		return self::resolve_feed_meta_for_form(
			(int) $link->template_id,
			$form_id,
			(array) $template->feed_meta,
			$overrides
		);
	}

	/**
	 * Template ID linked to a form feed, if any.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @param int $feed_id GF Odoo feed ID.
	 *
	 * @return int Template ID, or 0 when not linked.
	 */
	public static function get_linked_template_id( int $form_id, int $feed_id ): int {
		global $wpdb;

		if ( $form_id <= 0 || $feed_id <= 0 ) {
			return 0;
		}

		$table = self::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT template_id FROM {$table} WHERE form_id = %d AND feed_id = %d",
				$form_id,
				$feed_id
			)
		);
	}

	/**
	 * Per-form override keys only (not merged with template feed_meta).
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @param int $feed_id GF Odoo feed ID.
	 *
	 * @return array<string, mixed> Sparse override map.
	 */
	public static function get_feed_overrides( int $form_id, int $feed_id ): array {
		global $wpdb;

		if ( $form_id <= 0 || $feed_id <= 0 ) {
			return array();
		}

		$table = self::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT overrides FROM {$table} WHERE form_id = %d AND feed_id = %d",
				$form_id,
				$feed_id
			)
		);

		if ( empty( $raw ) ) {
			return array();
		}

		$decoded = json_decode( (string) $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Associate a form feed with a template and optional field overrides.
	 *
	 * @param int   $template_id Template ID.
	 * @param int   $form_id     Gravity Forms form ID.
	 * @param int   $feed_id     GF Odoo feed ID.
	 * @param array $overrides   Sparse feed_meta overrides (field_id remaps, etc.).
	 */
	public static function link_feed_to_template( int $template_id, int $form_id, int $feed_id, array $overrides = array() ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->replace(
			self::links_table(),
			array(
				'template_id' => $template_id,
				'form_id'     => $form_id,
				'feed_id'     => $feed_id,
				'overrides'   => wp_json_encode( $overrides ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Remove the template link for a form feed (feed keeps its own settings).
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @param int $feed_id GF Odoo feed ID.
	 */
	public static function unlink_feed( int $form_id, int $feed_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			self::links_table(),
			array(
				'form_id' => $form_id,
				'feed_id' => $feed_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Update per-form override JSON for a linked feed.
	 *
	 * @param int   $form_id   Gravity Forms form ID.
	 * @param int   $feed_id   GF Odoo feed ID.
	 * @param array $overrides Sparse feed_meta overrides.
	 */
	public static function save_overrides( int $form_id, int $feed_id, array $overrides ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::links_table(),
			array( 'overrides' => wp_json_encode( $overrides ) ),
			array(
				'form_id' => $form_id,
				'feed_id' => $feed_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Count form feeds currently linked to a template.
	 *
	 * @param int $template_id Template ID.
	 *
	 * @return int Number of link rows.
	 */
	public static function count_linked_forms( int $template_id ): int {
		global $wpdb;

		if ( $template_id <= 0 ) {
			return 0;
		}

		$table = self::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE template_id = %d",
				$template_id
			)
		);
	}
}
