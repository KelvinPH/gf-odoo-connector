<?php
/**
 * Maps Gravity Forms entry values to Odoo field payloads.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transforms GF entry data using per-feed field mapping configuration.
 */
class Field_Mapper {

	/**
	 * UI / feed_meta keys mapped to res.partner Odoo fields.
	 *
	 * @var array<string, string>
	 */
	private const CONTACT_MAP_KEYS = array(
		'contact_name'  => 'name',
		'contact_email' => 'email',
		'contact_phone' => 'phone',
		'email'         => 'email',
		'phone'         => 'phone',
		'mobile'        => 'mobile',
		'street'        => 'street',
		'city'          => 'city',
		'zip'           => 'zip',
		'comment'       => 'comment',
		'company_name'  => 'company_name',
	);

	/**
	 * UI / feed_meta keys mapped to crm.lead Odoo fields.
	 *
	 * @var array<string, string>
	 */
	private const LEAD_MAP_KEYS = array(
		'lead_name'        => 'name',
		'lead_title'       => 'name',
		'lead_description' => 'description',
		'description'      => 'description',
		'email_from'       => 'email_from',
		'lead_phone'       => 'phone',
	);

	/**
	 * UI / feed_meta keys mapped to helpdesk.ticket Odoo fields.
	 *
	 * @var array<string, string>
	 */
	private const HELPDESK_MAP_KEYS = array(
		'ticket_name'        => 'name',
		'ticket_subject'     => 'name',
		'subject'            => 'name',
		'ticket_description' => 'description',
		'description'        => 'description',
		'message'            => 'description',
		'partner_name'       => 'partner_name',
		'partner_email'      => 'partner_email',
		'partner_phone'      => 'partner_phone',
		'ticket_priority'    => 'priority',
	);

	/**
	 * @var array Feed meta (mapping and feed options).
	 */
	private $feed_meta;

	/**
	 * @var array GF entry.
	 */
	private $entry;

	/**
	 * @var array GF form.
	 */
	private $form;

	/**
	 * Map a Gravity Forms entry to Odoo payloads using per-field mode settings.
	 *
	 * @param array $feed_meta Feed meta from the GF feed (or full feed array with a meta key).
	 * @param array $entry     Gravity Forms entry row.
	 * @param array $form      Gravity Forms form definition.
	 */
	public function __construct( array $feed_meta, array $entry, array $form ) {
		if ( isset( $feed_meta['meta'] ) && is_array( $feed_meta['meta'] ) ) {
			$feed_meta = $feed_meta['meta'];
		}

		$this->feed_meta = $feed_meta;
		$this->entry     = $entry;
		$this->form      = $form;
	}

	/**
	 * Extract a normalized string value for a Gravity Forms field.
	 *
	 * @param string $gf_field_id GF field ID or entry meta token.
	 *
	 * @return string
	 */
	public function get_field_value( string $gf_field_id ): string {
		$gf_field_id = trim( $gf_field_id );

		if ( '' === $gf_field_id ) {
			return '';
		}

		$value = $this->resolve_field_value( $gf_field_id );

		if ( is_array( $value ) ) {
			$value = $this->flatten_array_value( $value );
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * Build Odoo CRM payloads from per-field mode configuration.
	 *
	 * Applies auto/field/fixed/off modes, static country/industry maps, and lead title fallbacks.
	 *
	 * @return array{contact: array<string, mixed>, partner: array<string, mixed>, lead: array<string, mixed>}
	 */
	public function map_crm_fields(): array {
		$addon = GF_Odoo_Addon::get_instance();
		$rows  = $addon->crm_field_rows();
		$contact = array();
		$lead    = array();

		foreach ( $rows as $row ) {
			$key  = (string) $row['key'];
			$mode = (string) rgar( $this->feed_meta, $key . '_mode', 'off' );

			if ( 'off' === $mode ) {
				continue;
			}

			$value = $this->resolve_crm_field_value( $row, $mode );

			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( 'priority' === $row['odoo_field'] ) {
				$value = $this->normalize_lead_priority( (string) $value );
				if ( '' === $value ) {
					continue;
				}
			}

			$value = $this->normalize_mapped_value_for_odoo( $row, $value, $mode );
			if ( null === $value || ( '' === $value && ! is_bool( $value ) ) ) {
				continue;
			}

			if ( 'contact' === $row['section'] ) {
				$contact[ $row['odoo_field'] ] = $value;
			} else {
				$lead[ $row['odoo_field'] ] = $value;
			}
		}

		if ( ! empty( $contact['email'] ) && empty( $lead['email_from'] ) ) {
			$lead['email_from'] = $contact['email'];
		}

		if ( ! empty( $contact['phone'] ) && empty( $lead['phone'] ) ) {
			$lead['phone'] = $contact['phone'];
		}

		if ( empty( $lead['name'] ) ) {
			$lead['name'] = (string) rgar( $this->form, 'title' );
		}

		if ( '' === trim( (string) $lead['name'] ) ) {
			$lead['name'] = sprintf(
				/* translators: %d: Gravity Forms entry ID */
				__( 'Form submission #%d', 'gf-odoo-connector' ),
				(int) rgar( $this->entry, 'id' )
			);
		}

		return array(
			'contact' => $contact,
			'partner' => $contact,
			'lead'    => $lead,
		);
	}

	/**
	 * @param array  $row  Field row from CRM_Field_Config.
	 * @param string $mode active|field|fixed|off.
	 *
	 * @return mixed|null
	 */
	private function resolve_crm_field_value( array $row, string $mode ) {
		$key = (string) $row['key'];

		switch ( $mode ) {
			case 'auto':
				return $this->resolve_crm_auto_value( $row );

			case 'field':
				return $this->resolve_field_mode_value( $row );

			case 'fixed':
				$raw = rgar( $this->feed_meta, $key . '_value' );
				if ( is_array( $raw ) ) {
					$raw = class_exists( 'GF_Odoo_Addon' )
						? GF_Odoo_Addon::get_fixed_setting_value( $raw )
						: '';
				} else {
					$raw = trim( (string) $raw );
				}
				if ( '' === $raw && 'boolean' !== ( $row['fixed_type'] ?? '' ) ) {
					return null;
				}
				if ( in_array( $row['fixed_type'] ?? '', array( 'odoo_select', 'static_select' ), true ) ) {
					return $raw;
				}
				if ( class_exists( 'GFCommon' ) ) {
					return GFCommon::replace_variables( $raw, $this->form, $this->entry );
				}
				return $raw;

			default:
				return null;
		}
	}

	/**
	 * Resolve a "From field" mapping (legacy string ID or {field_id, field_label} object).
	 *
	 * @param array $row Field row definition.
	 *
	 * @return mixed|null
	 */
	private function resolve_field_mode_value( array $row ) {
		$key = (string) $row['key'];
		$raw = rgar( $this->feed_meta, $key . '_value' );

		if ( ! class_exists( 'GF_Odoo_Addon' ) ) {
			return null;
		}

		$entries = GF_Odoo_Addon::get_field_mapping_entries( $raw );
		if ( empty( $entries ) ) {
			return null;
		}

		$parts = array();
		foreach ( $entries as $entry ) {
			$field_id    = (string) ( $entry['field_id'] ?? '' );
			$field_label = (string) ( $entry['field_label'] ?? '' );

			if ( '' === $field_id ) {
				continue;
			}

			$value = $this->get_field_value( $field_id );

			if ( ( '' === $value || null === $value ) && '' !== $field_label ) {
				$resolved_id = $this->find_field_id_by_label( $field_label );
				if ( null !== $resolved_id ) {
					$value = $this->get_field_value( $resolved_id );
				}
			}

			if ( '' !== trim( (string) $value ) ) {
				$parts[] = trim( (string) $value );
			}
		}

		if ( empty( $parts ) ) {
			return null;
		}

		$value = implode( ' ', $parts );

		$resolver = (string) rgar( $row, 'resolver', '' );
		if ( '' !== $resolver ) {
			return $this->apply_resolver( $resolver, (string) $value );
		}

		return $value;
	}

	/**
	 * Transform a raw GF value before sending to Odoo (e.g. country name → res.country ID).
	 *
	 * @param string $resolver Resolver key from field row.
	 * @param string $raw_value Raw entry value.
	 *
	 * @return mixed|null
	 */
	private function apply_resolver( string $resolver, string $raw_value ) {
		switch ( $resolver ) {
			case 'country':
				if ( ! class_exists( 'GF_Odoo_Country_Map' ) ) {
					return null;
				}

				$id = GF_Odoo_Country_Map::resolve( $raw_value );

				if ( null === $id ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[GF Odoo Connector] Could not resolve country "%s" to an Odoo ID. Entry ID: %s',
							$raw_value,
							$this->entry['id'] ?? 'unknown'
						)
					);
					return null;
				}

				return $id;

			case 'industry':
				if ( ! class_exists( 'GF_Odoo_Industry_Map' ) ) {
					return null;
				}

				$id = GF_Odoo_Industry_Map::resolve( $raw_value );

				if ( null === $id ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[GF Odoo Connector] Could not resolve industry "%s" to an Odoo ID. Entry ID: %s',
							$raw_value,
							$this->entry['id'] ?? 'unknown'
						)
					);
					return null;
				}

				return $id;

			case 'product_tag':
				if ( ! class_exists( 'GF_Odoo_Product_Tag_Map' ) ) {
					$fallback = trim( $raw_value );

					return '' !== $fallback ? $fallback : null;
				}

				$ref = GF_Odoo_Product_Tag_Map::resolve( $raw_value );

				if ( null !== $ref ) {
					return $ref;
				}

				// Backup: pass the device label through for live Odoo name lookup.
				$fallback = trim( $raw_value );

				if ( '' !== $fallback ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[GF Odoo Connector] Product model "%s" not in static map; trying Odoo tag name lookup. Entry ID: %s',
							$fallback,
							$this->entry['id'] ?? 'unknown'
						)
					);

					return $fallback;
				}

				return null;

			case 'ticket_category':
				if ( ! class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
					$fallback = trim( $raw_value );

					return '' !== $fallback ? $fallback : null;
				}

				$hex_ref = GF_Odoo_Ticket_Category_Map::resolve( $raw_value );

				if ( null !== $hex_ref ) {
					return $hex_ref;
				}

				$fallback = trim( $raw_value );

				if ( '' !== $fallback ) {
					return $fallback;
				}

				return null;

			case 'customer_company':
			case 'serial_lot':
				// Resolved in Helpdesk_Handler at create time (needs Odoo API).
				return trim( $raw_value );

			default:
				return $raw_value;
		}
	}

	/**
	 * Raw GF entry value for a mapped field key (after resolving field_id from feed meta).
	 *
	 * @param string $key Field row key (e.g. contact_country).
	 *
	 * @return string
	 */
	public function get_raw_field_value( string $key ): string {
		$stored = rgar( $this->feed_meta, $key . '_value' );
		$field_id = is_array( $stored )
			? (string) ( $stored['field_id'] ?? '' )
			: trim( (string) $stored );

		return $this->get_field_value( $field_id );
	}

	/**
	 * Whether a country mapping is active but did not resolve to an Odoo ID.
	 *
	 * @param string $meta_key Field row key.
	 * @param array  $data     Mapped contact/ticket payload section.
	 *
	 * @return bool
	 */
	public function has_unresolved_country_mapping( string $meta_key, array $data ): bool {
		$mode = (string) rgar( $this->feed_meta, $meta_key . '_mode', 'off' );

		if ( 'off' === $mode ) {
			return false;
		}

		$country_raw = $this->get_raw_field_value( $meta_key );

		if ( '' === $country_raw ) {
			return false;
		}

		return empty( $data['country_id'] );
	}

	/**
	 * Whether ticket category was submitted but not resolved to an Odoo ID yet.
	 *
	 * @param array<string, mixed> $ticket Mapped ticket payload.
	 *
	 * @return bool
	 */
	public function has_unresolved_ticket_category_mapping( array $ticket ): bool {
		$mode = (string) rgar( $this->feed_meta, 'ticket_category_mode', 'off' );

		if ( 'off' === $mode ) {
			return false;
		}

		$raw = trim( $this->get_raw_field_value( 'ticket_category' ) );

		if ( '' === $raw ) {
			return false;
		}

		if ( ! array_key_exists( 'ticket_category_id', $ticket ) ) {
			return true;
		}

		$mapped = $ticket['ticket_category_id'];

		if ( null === $mapped || '' === $mapped ) {
			return true;
		}

		if ( is_numeric( $mapped ) && (int) $mapped > 0 ) {
			return false;
		}

		// Hex refs and mapped labels are resolved to an Odoo ID in create_ticket — not an error.
		if ( is_string( $mapped ) && class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
			if ( GF_Odoo_Ticket_Category_Map::is_known_hex_ref( $mapped ) ) {
				return false;
			}

			if ( null !== GF_Odoo_Ticket_Category_Map::resolve( $mapped ) ) {
				return false;
			}
		}

		if ( is_string( $mapped ) && (
			preg_match( '/^category_/i', $mapped )
			|| preg_match( '/^ticket_category_\d+_/i', $mapped )
			|| preg_match( '/^[a-f0-9]{6,8}$/i', $mapped )
		) ) {
			return true;
		}

		return false;
	}

	/**
	 * Find a GF field ID on the current form by label (case-insensitive).
	 *
	 * @param string $label Field label.
	 *
	 * @return string|null
	 */
	private function find_field_id_by_label( string $label ): ?string {
		$label_lower = strtolower( trim( $label ) );

		if ( '' === $label_lower || empty( $this->form['fields'] ) || ! is_array( $this->form['fields'] ) ) {
			return null;
		}

		foreach ( $this->form['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}

			if ( strtolower( trim( (string) ( $field->label ?? '' ) ) ) === $label_lower ) {
				return (string) (int) ( $field->id ?? 0 );
			}
		}

		return null;
	}

	/**
	 * @param array $row Field row definition.
	 *
	 * @return mixed|null
	 */
	private function resolve_crm_auto_value( array $row ) {
		switch ( $row['key'] ) {
			case 'lead_title':
				return (string) rgar( $this->form, 'title' );

			case 'lead_source':
				$field_id = (string) rgar( $this->feed_meta, 'source_hidden_field_id' );
				if ( '' === $field_id ) {
					$field_id = $this->find_source_hidden_field_id();
				}
				if ( '' === $field_id ) {
					return null;
				}
				return $this->get_field_value( $field_id );
		}

		return null;
	}

	/**
	 * Locate the plugin source URL hidden field on the form.
	 *
	 * @return string GF field ID.
	 */
	private function find_source_hidden_field_id(): string {
		if ( empty( $this->form['fields'] ) || ! is_array( $this->form['fields'] ) ) {
			return '';
		}

		foreach ( $this->form['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			if ( 'hidden' !== $field->type ) {
				continue;
			}
			if ( 'gf_odoo_source_url' === $field->inputName || 'gf_odoo_source_url' === ( $field->cssClass ?? '' ) ) {
				return (string) $field->id;
			}
		}

		return '';
	}

	/**
	 * Normalize a mapped value before sending to Odoo.
	 *
	 * @param array  $row   Field row definition.
	 * @param mixed  $value Resolved value.
	 * @param string $mode  field|fixed|auto.
	 *
	 * @return mixed|null
	 */
	private function normalize_mapped_value_for_odoo( array $row, $value, string $mode ) {
		if ( null === $value ) {
			return null;
		}

		$fixed_type = (string) rgar( $row, 'fixed_type', '' );

		if ( 'boolean' === $fixed_type ) {
			return (bool) $value;
		}

		if ( 'date' === $fixed_type ) {
			return $this->normalize_date_value( is_scalar( $value ) ? (string) $value : '' );
		}

		if ( 'odoo_select' === $fixed_type && in_array( $mode, array( 'field', 'fixed' ), true ) ) {
			return $this->normalize_odoo_many2one_value( $row, $value );
		}

		if ( 'static_select' === $fixed_type && in_array( $mode, array( 'field', 'fixed' ), true ) ) {
			$resolver = (string) rgar( $row, 'resolver', '' );

			if ( '' !== $resolver ) {
				return $this->apply_resolver( $resolver, is_scalar( $value ) ? (string) $value : '' );
			}

			if ( is_numeric( $value ) ) {
				$int_value = (int) $value;
				return $int_value > 0 ? $int_value : null;
			}
			return $value;
		}

		return $value;
	}

	/**
	 * @param string $raw Date string from a form field.
	 *
	 * @return string|null Y-m-d or null when invalid.
	 */
	private function normalize_date_value( string $raw ): ?string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return $raw;
		}

		$timestamp = strtotime( $raw );

		if ( false === $timestamp ) {
			if ( class_exists( 'GF_Odoo_Addon' ) ) {
				GF_Odoo_Addon::get_instance()->log_error(
					sprintf(
						'GF Odoo Connector: skipped invalid date "%s".',
						$raw
					)
				);
			}
			return null;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Resolve many2one field values from numeric IDs or display names.
	 *
	 * @param array $row   Field row with odoo_model.
	 * @param mixed $value Raw mapped value.
	 *
	 * @return mixed|null Integer ID, raw string for special cases, or null.
	 */
	private function normalize_odoo_many2one_value( array $row, $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return null;
		}

		if ( is_numeric( $raw ) ) {
			$int_value = (int) $raw;
			return $int_value > 0 ? $int_value : null;
		}

		if ( 'country' === (string) rgar( $row, 'resolver', '' ) && class_exists( 'GF_Odoo_Country_Map' ) ) {
			return GF_Odoo_Country_Map::resolve( $raw );
		}

		if ( 'industry' === (string) rgar( $row, 'resolver', '' ) && class_exists( 'GF_Odoo_Industry_Map' ) ) {
			return GF_Odoo_Industry_Map::resolve( $raw );
		}

		if ( 'product_tag' === (string) rgar( $row, 'resolver', '' ) && class_exists( 'GF_Odoo_Product_Tag_Map' ) ) {
			return GF_Odoo_Product_Tag_Map::resolve( $raw );
		}

		if ( 'ticket_category' === (string) rgar( $row, 'resolver', '' ) && class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
			$hex_ref = GF_Odoo_Ticket_Category_Map::resolve( is_scalar( $value ) ? (string) $value : '' );

			return null !== $hex_ref ? $hex_ref : null;
		}

		$model = (string) rgar( $row, 'odoo_model', '' );

		if ( '' === $model || ! class_exists( 'GF_Odoo_Addon' ) ) {
			return null;
		}

		$api = GF_Odoo_Addon::get_instance()->get_odoo_api();

		if ( null === $api ) {
			return null;
		}

		$crm    = new CRM_Handler( $api );
		$found  = $crm->find_record_id_by_name( $model, $raw );
		$odoo_field = (string) rgar( $row, 'odoo_field', '' );

		// source_id may be a page URL when mapped from a form field.
		if ( null !== $found ) {
			return $found;
		}

		if ( 'source_id' === $odoo_field ) {
			return $raw;
		}

		return null;
	}

	/**
	 * Map text or numeric values to Odoo crm.lead priority selection keys.
	 *
	 * @param string $raw Raw GF field value.
	 *
	 * @return string
	 */
	private function normalize_lead_priority( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( is_numeric( $raw ) ) {
			return (string) (int) $raw;
		}

		$labels = array(
			'low'       => '0',
			'medium'    => '1',
			'high'      => '2',
			'very high' => '3',
			'very_high' => '3',
		);

		$key = strtolower( $raw );

		return $labels[ $key ] ?? $raw;
	}

	/**
	 * Build Odoo Helpdesk payloads from per-field mode configuration.
	 *
	 * Returns contact strings, ticket fields for helpdesk.ticket, and product metadata.
	 *
	 * @return array{contact: array<string, string>, ticket: array<string, mixed>, product: array<string, mixed>}
	 */
	public function map_helpdesk_fields(): array {
		$addon   = GF_Odoo_Addon::get_instance();
		$rows    = $addon->helpdesk_field_rows();
		$contact = array();
		$ticket  = array();
		$product = array();

		foreach ( $rows as $row ) {
			$key  = (string) $row['key'];
			$mode = (string) rgar( $this->feed_meta, $key . '_mode', 'off' );

			if ( 'off' === $mode ) {
				continue;
			}

			$value = $this->resolve_helpdesk_field_value( $row, $mode );

			if ( null === $value ) {
				continue;
			}

			if ( ! empty( $row['table_only'] ) ) {
				continue;
			}

			$value = $this->normalize_mapped_value_for_odoo( $row, $value, $mode );
			if ( null === $value || ( '' === $value && ! is_bool( $value ) ) ) {
				continue;
			}

			if ( 'boolean' === ( $row['fixed_type'] ?? '' ) ) {
				if ( 'product' === $row['section'] ) {
					$product[ $row['odoo_field'] ] = (bool) $value;
				} else {
					$ticket[ $row['odoo_field'] ] = (bool) $value;
				}
				continue;
			}

			$odoo_field = (string) ( $row['odoo_field'] ?? '' );

			if ( '' === $odoo_field ) {
				continue;
			}

			if ( 'contact' === $row['section'] ) {
				$contact[ $odoo_field ] = is_scalar( $value ) ? (string) $value : '';
				if ( 'customer_id' === $odoo_field && '' !== $contact[ $odoo_field ] ) {
					$contact['company_name'] = $contact[ $odoo_field ];
				}
			} elseif ( 'product' === $row['section'] ) {
				$product[ $odoo_field ] = $value;
			} else {
				$ticket[ $odoo_field ] = $value;
			}
		}

		if ( empty( $ticket['name'] ) ) {
			$ticket['name'] = (string) rgar( $this->form, 'title' );
		}

		if ( '' === trim( (string) $ticket['name'] ) ) {
			$ticket['name'] = sprintf(
				/* translators: %d: Gravity Forms entry ID */
				__( 'Form submission #%d', 'gf-odoo-connector' ),
				(int) rgar( $this->entry, 'id' )
			);
		}

		return array(
			'contact' => $contact,
			'ticket'  => $ticket,
			'product' => $product,
		);
	}

	/**
	 * Human-readable rows for the Issue Description HTML overview table.
	 *
	 * Includes every mapped feed field (mode not "off"), even when the visitor left it empty.
	 *
	 * @param array<string, mixed> $ticket Resolved ticket payload (for subject fallback).
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	public function get_helpdesk_table_rows( array $ticket = array() ): array {
		$addon = GF_Odoo_Addon::get_instance();
		$rows  = $addon->helpdesk_field_rows();
		$out   = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['in_table'] ) ) {
				continue;
			}

			$key  = (string) $row['key'];
			$mode = (string) rgar( $this->feed_meta, $key . '_mode', 'off' );

			if ( 'off' === $mode ) {
				continue;
			}

			$out[] = array(
				'label' => $this->get_helpdesk_table_row_label( $row, $mode ),
				'value' => $this->get_helpdesk_table_display_value( $row, $mode, $ticket ),
			);
		}

		return $out;
	}

	/**
	 * @param array  $row  Field row.
	 * @param string $mode Mapping mode.
	 *
	 * @return string
	 */
	private function get_helpdesk_table_row_label( array $row, string $mode ): string {
		if ( 'field' === $mode ) {
			$gf_label = $this->get_mapped_gf_field_label( (string) $row['key'] );

			if ( '' !== $gf_label ) {
				return $gf_label;
			}
		}

		return (string) $row['label'];
	}

	/**
	 * @param string $key Field row key.
	 *
	 * @return string
	 */
	private function get_mapped_gf_field_label( string $key ): string {
		$stored   = rgar( $this->feed_meta, $key . '_value' );
		$field_id = is_array( $stored )
			? (string) ( $stored['field_id'] ?? '' )
			: trim( (string) $stored );

		if ( '' === $field_id || empty( $this->form['fields'] ) || ! is_array( $this->form['fields'] ) ) {
			return '';
		}

		foreach ( $this->form['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}

			if ( (string) (int) ( $field->id ?? 0 ) === $field_id ) {
				return trim( (string) ( $field->label ?? '' ) );
			}
		}

		return '';
	}

	/**
	 * @param array                $row    Field row.
	 * @param string               $mode   Mapping mode.
	 * @param array<string, mixed> $ticket Resolved ticket payload.
	 *
	 * @return string
	 */
	private function get_helpdesk_table_display_value( array $row, string $mode, array $ticket = array() ): string {
		if ( 'boolean' === ( $row['fixed_type'] ?? '' ) ) {
			$raw = $this->resolve_helpdesk_field_value( $row, $mode );

			if ( null === $raw ) {
				return '';
			}

			return ! empty( $raw )
				? esc_html__( 'Yes', 'gf-odoo-connector' )
				: esc_html__( 'No', 'gf-odoo-connector' );
		}

		if ( 'auto' === $mode && 'ticket_subject' === (string) ( $row['key'] ?? '' ) ) {
			$subject = trim( (string) ( $ticket['name'] ?? '' ) );

			if ( '' !== $subject ) {
				return $subject;
			}

			return trim( (string) rgar( $this->form, 'title' ) );
		}

		if ( 'field' === $mode ) {
			return trim( $this->get_raw_field_value( (string) $row['key'] ) );
		}

		$value = $this->resolve_helpdesk_field_value( $row, $mode );

		if ( null === $value || ( ! is_scalar( $value ) && ! is_bool( $value ) ) ) {
			return '';
		}

		$display = is_bool( $value )
			? ( $value ? esc_html__( 'Yes', 'gf-odoo-connector' ) : esc_html__( 'No', 'gf-odoo-connector' ) )
			: trim( (string) $value );

		return $this->resolve_helpdesk_table_choice_label( $row, $display );
	}

	/**
	 * Turn Odoo IDs / internal slugs into human labels for the overview table.
	 *
	 * @param array  $row   Field row.
	 * @param string $value Raw display value.
	 *
	 * @return string
	 */
	private function resolve_helpdesk_table_choice_label( array $row, string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		foreach ( (array) rgar( $row, 'fixed_choices', array() ) as $choice ) {
			$choice_value = (string) rgar( $choice, 'value', '' );
			$choice_label = (string) rgar( $choice, 'label', '' );

			if ( (string) $value === $choice_value || strcasecmp( $value, $choice_label ) === 0 ) {
				return $choice_label;
			}

			if ( 'ticket_category' === (string) ( $row['key'] ?? '' )
				&& class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
				$label = GF_Odoo_Ticket_Category_Map::label_for_slug( $value );

				if ( null !== $label ) {
					return $label;
				}
			}
		}

		if ( 'odoo_select' === ( $row['fixed_type'] ?? '' ) && is_numeric( $value ) ) {
			$label = $this->lookup_odoo_select_label( (string) $row['key'], (int) $value );

			if ( '' !== $label ) {
				return $label;
			}
		}

		return $value;
	}

	/**
	 * @param string $row_key Field row key.
	 * @param int    $id      Odoo record ID.
	 *
	 * @return string
	 */
	private function lookup_odoo_select_label( string $row_key, int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}

		$transient = array(
			'ticket_team'    => 'gf_odoo_helpdesk_teams',
			'ticket_branch'  => 'gf_odoo_options_branches',
			'ticket_state'   => 'gf_odoo_options_states',
			'ticket_country' => 'gf_odoo_options_countries',
		);

		if ( ! isset( $transient[ $row_key ] ) ) {
			return '';
		}

		$cached = get_transient( $transient[ $row_key ] );

		if ( ! is_array( $cached ) ) {
			return '';
		}

		foreach ( $cached as $choice ) {
			if ( (int) rgar( $choice, 'value', 0 ) === $id ) {
				return (string) rgar( $choice, 'label', '' );
			}
		}

		return '';
	}

	/**
	 * @param array  $row  Field row from Helpdesk_Field_Config.
	 * @param string $mode active|field|fixed|off.
	 *
	 * @return mixed|null
	 */
	private function resolve_helpdesk_field_value( array $row, string $mode ) {
		$key = (string) $row['key'];

		switch ( $mode ) {
			case 'auto':
				if ( 'ticket_subject' === $key ) {
					return (string) rgar( $this->form, 'title' );
				}
				return null;

			case 'field':
				$raw = $this->resolve_field_mode_value( $row );
				if ( null === $raw || '' === $raw ) {
					return null;
				}
				if ( 'boolean' === ( $row['fixed_type'] ?? '' ) ) {
					return $this->normalize_boolean_value( (string) $raw );
				}
				return $raw;

			case 'fixed':
				$raw = rgar( $this->feed_meta, $key . '_value' );
				if ( is_array( $raw ) ) {
					$raw = class_exists( 'GF_Odoo_Addon' )
						? GF_Odoo_Addon::get_fixed_setting_value( $raw )
						: '';
				} else {
					$raw = trim( (string) $raw );
				}
				if ( '' === $raw && 'boolean' !== ( $row['fixed_type'] ?? '' ) ) {
					return null;
				}
				if ( 'boolean' === ( $row['fixed_type'] ?? '' ) ) {
					return '1' === $raw || 'true' === strtolower( $raw );
				}
				if ( in_array( $row['fixed_type'] ?? '', array( 'odoo_select', 'static_select' ), true ) ) {
					return $raw;
				}
				if ( class_exists( 'GFCommon' ) ) {
					return GFCommon::replace_variables( $raw, $this->form, $this->entry );
				}
				return $raw;

			default:
				return null;
		}
	}

	/**
	 * @param string $raw Raw GF field value.
	 *
	 * @return bool
	 */
	private function normalize_boolean_value( string $raw ): bool {
		$raw = strtolower( trim( $raw ) );

		return in_array( $raw, array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * @param string $gf_field_id GF field ID.
	 *
	 * @return mixed
	 */
	private function resolve_field_value( string $gf_field_id ) {
		if ( preg_match( '/^\d+\.\d+$/', $gf_field_id ) ) {
			$direct = rgar( $this->entry, $gf_field_id );
			if ( is_scalar( $direct ) && '' !== trim( (string) $direct ) ) {
				return trim( (string) $direct );
			}
		}

		$token = strtolower( $gf_field_id );

		switch ( $token ) {
			case 'form_title':
				return rgar( $this->form, 'title' );
			case 'date_created':
				return rgar( $this->entry, 'date_created' ) ?: gmdate( 'Y-m-d H:i:s' );
			case 'ip':
			case 'source_url':
			case 'id':
				return rgar( $this->entry, $token );
		}

		if ( ! class_exists( 'GFFormsModel' ) ) {
			return rgar( $this->entry, $gf_field_id );
		}

		$field = GFFormsModel::get_field( $this->form, $gf_field_id );

		if ( ! is_object( $field ) ) {
			return rgar( $this->entry, $gf_field_id );
		}

		$input_type = $field->get_input_type();

		if ( 'name' === $input_type ) {
			return $this->get_name_field_value( $field );
		}

		if ( 'address' === $input_type ) {
			return $this->get_address_field_value( $field );
		}

		if ( in_array( $input_type, array( 'checkbox', 'multiselect' ), true ) ) {
			return $this->get_multi_value_field( $field );
		}

		if ( class_exists( 'GFAddOn' ) && class_exists( 'GF_Odoo_Addon' ) ) {
			$addon_value = GF_Odoo_Addon::get_instance()->get_field_value( $this->form, $this->entry, $gf_field_id );
			if ( is_scalar( $addon_value ) && '' !== trim( (string) $addon_value ) ) {
				return $addon_value;
			}
		}

		if ( method_exists( $field, 'get_value_export' ) ) {
			return $field->get_value_export( $this->entry, $gf_field_id );
		}

		return rgar( $this->entry, $gf_field_id );
	}

	/**
	 * @param object $field GF field.
	 *
	 * @return string
	 */
	private function get_name_field_value( $field ): string {
		$field_id = (string) $field->id;
		$parts    = array();

		if ( method_exists( $field, 'get_value_export' ) ) {
			$exported = trim( (string) $field->get_value_export( $this->entry, $field_id ) );
			if ( '' !== $exported ) {
				return $exported;
			}
		}

		foreach ( array( '.2', '.3', '.4', '.6', '.8' ) as $suffix ) {
			$part = trim( (string) rgar( $this->entry, $field_id . $suffix ) );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		return trim( implode( ' ', $parts ) );
	}

	/**
	 * @param object $field GF field.
	 *
	 * @return string
	 */
	private function get_address_field_value( $field ): string {
		$field_id = (string) $field->id;
		$parts    = array();

		if ( class_exists( 'GFAddOn' ) ) {
			$addon = GFAddOn::get_instance();
			if ( $addon && method_exists( $addon, 'get_full_address' ) ) {
				$full = trim( (string) $addon->get_full_address( $this->entry, $field_id ) );
				if ( '' !== $full ) {
					return $full;
				}
			}
		}

		foreach ( array( '.1', '.2', '.3', '.4', '.5', '.6' ) as $suffix ) {
			$part = trim( (string) rgar( $this->entry, $field_id . $suffix ) );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		return trim( implode( ', ', $parts ) );
	}

	/**
	 * @param object $field GF field.
	 *
	 * @return string
	 */
	private function get_multi_value_field( $field ): string {
		$values = array();

		if ( method_exists( $field, 'get_value_export' ) ) {
			$exported = $field->get_value_export( $this->entry, (string) $field->id );
			if ( is_string( $exported ) && '' !== trim( $exported ) ) {
				return trim( str_replace( ', ', ',', $exported ) );
			}
		}

		if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
			foreach ( $field->inputs as $input ) {
				$input_id = (string) rgar( $input, 'id' );
				$label    = (string) rgar( $input, 'label' );
				$raw      = rgar( $this->entry, $input_id );

				if ( is_array( $raw ) ) {
					$raw = $this->flatten_array_value( $raw );
				}

				$raw = trim( (string) $raw );

				if ( '' === $raw ) {
					continue;
				}

				$values[] = '' !== $label ? $label . ': ' . $raw : $raw;
			}
		} else {
			$raw = rgar( $this->entry, (string) $field->id );
			if ( is_array( $raw ) ) {
				$values = array_filter( array_map( 'trim', $raw ) );
			} elseif ( '' !== trim( (string) $raw ) ) {
				$values[] = trim( (string) $raw );
			}
		}

		return implode( ', ', array_unique( array_filter( $values ) ) );
	}

	/**
	 * @param array $value Nested GF value.
	 *
	 * @return string
	 */
	private function flatten_array_value( array $value ): string {
		$flat = array();

		array_walk_recursive(
			$value,
			static function ( $item ) use ( &$flat ) {
				if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
					$flat[] = trim( (string) $item );
				}
			}
		);

		return implode( ', ', array_unique( $flat ) );
	}

	/**
	 * Read field_map_* keys from feed meta (supports legacy map names).
	 *
	 * @param string $primary   Preferred GF field_map setting name.
	 * @param string $legacy    Legacy setting name from earlier phases.
	 *
	 * @return array<string, string>
	 */
	private function resolve_field_map( string $primary, string $legacy ): array {
		$map = $this->get_field_map_from_meta( $primary );

		if ( $this->field_map_has_selections( $map ) ) {
			return $map;
		}

		return $this->get_field_map_from_meta( $legacy );
	}

	/**
	 * @param string $field_map_name GF field_map setting name.
	 *
	 * @return array<string, string>
	 */
	private function get_field_map_from_meta( string $field_map_name ): array {
		$feed_wrapper = array( 'meta' => $this->feed_meta );

		if ( class_exists( 'GFAddOn' ) ) {
			return GFAddOn::get_field_map_fields( $feed_wrapper, $field_map_name );
		}

		$fields = array();
		$prefix = $field_map_name . '_';

		foreach ( $this->feed_meta as $name => $value ) {
			if ( 0 === strpos( (string) $name, $prefix ) ) {
				$key            = substr( (string) $name, strlen( $prefix ) );
				$fields[ $key ] = is_scalar( $value ) ? (string) $value : '';
			}
		}

		return $fields;
	}

	/**
	 * @param array<string, string> $map Field map.
	 *
	 * @return bool
	 */
	private function field_map_has_selections( array $map ): bool {
		foreach ( $map as $gf_field_id ) {
			if ( '' !== (string) $gf_field_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $value Priority value from a form field.
	 *
	 * @return bool
	 */
	private function is_valid_helpdesk_priority( string $value ): bool {
		return in_array( (string) $value, array( '0', '1', '2', '3' ), true );
	}

	/**
	 * Guess common GF fields when no CRM field map was saved.
	 *
	 * @return array<string, string>
	 */
	private function detect_crm_fallback_field_map(): array {
		$map = array(
			'contact_name'  => '',
			'contact_email' => '',
			'contact_phone' => '',
			'lead_description' => '',
		);

		if ( empty( $this->form['fields'] ) || ! is_array( $this->form['fields'] ) ) {
			return $map;
		}

		foreach ( $this->form['fields'] as $field ) {
			if ( ! is_object( $field ) || ! method_exists( $field, 'get_input_type' ) ) {
				continue;
			}

			$type        = $field->get_input_type();
			$field_id    = (string) $field->id;
			$label       = strtolower( (string) $field->label );
			$admin_label = strtolower( (string) $field->adminLabel );

			if ( 'email' === $type && '' === $map['contact_email'] ) {
				$map['contact_email'] = $field_id;
				continue;
			}

			if ( in_array( $type, array( 'phone' ), true ) && '' === $map['contact_phone'] ) {
				$map['contact_phone'] = $field_id;
				continue;
			}

			if ( in_array( $type, array( 'textarea', 'post_content' ), true ) && '' === $map['lead_description'] ) {
				$map['lead_description'] = $field_id;
				continue;
			}

			if ( 'name' === $type && '' === $map['contact_name'] ) {
				$map['contact_name'] = $field_id;
				continue;
			}

			if ( in_array( $type, array( 'text', 'hidden' ), true ) && '' === $map['contact_name'] ) {
				if ( false !== strpos( $label, 'name' ) || false !== strpos( $admin_label, 'name' ) ) {
					$map['contact_name'] = $field_id;
				}
			}
		}

		if ( '' === $map['contact_name'] ) {
			foreach ( $this->form['fields'] as $field ) {
				if ( ! is_object( $field ) || ! method_exists( $field, 'get_input_type' ) ) {
					continue;
				}

				if ( in_array( $field->get_input_type(), array( 'text', 'hidden' ), true ) ) {
					$map['contact_name'] = (string) $field->id;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Guess common GF fields when no Helpdesk field map was saved.
	 *
	 * @return array<string, string>
	 */
	private function detect_helpdesk_fallback_field_map(): array {
		$map = array(
			'ticket_name'        => '',
			'partner_email'      => '',
			'partner_phone'      => '',
			'partner_name'       => '',
			'ticket_description' => '',
		);

		if ( empty( $this->form['fields'] ) || ! is_array( $this->form['fields'] ) ) {
			return $map;
		}

		foreach ( $this->form['fields'] as $field ) {
			if ( ! is_object( $field ) || ! method_exists( $field, 'get_input_type' ) ) {
				continue;
			}

			$type        = $field->get_input_type();
			$field_id    = (string) $field->id;
			$label       = strtolower( (string) $field->label );
			$admin_label = strtolower( (string) $field->adminLabel );

			if ( 'email' === $type && '' === $map['partner_email'] ) {
				$map['partner_email'] = $field_id;
				continue;
			}

			if ( in_array( $type, array( 'phone' ), true ) && '' === $map['partner_phone'] ) {
				$map['partner_phone'] = $field_id;
				continue;
			}

			if ( in_array( $type, array( 'textarea', 'post_content' ), true ) && '' === $map['ticket_description'] ) {
				$map['ticket_description'] = $field_id;
				continue;
			}

			if ( 'name' === $type && '' === $map['partner_name'] ) {
				$map['partner_name'] = $field_id;
				continue;
			}

			if ( in_array( $type, array( 'text', 'hidden' ), true ) && '' === $map['ticket_name'] ) {
				if ( false !== strpos( $label, 'subject' ) || false !== strpos( $admin_label, 'subject' ) ) {
					$map['ticket_name'] = $field_id;
					continue;
				}
			}

			if ( in_array( $type, array( 'text', 'hidden' ), true ) && '' === $map['partner_name'] ) {
				if ( false !== strpos( $label, 'name' ) || false !== strpos( $admin_label, 'name' ) ) {
					$map['partner_name'] = $field_id;
				}
			}
		}

		if ( '' === $map['ticket_name'] ) {
			foreach ( $this->form['fields'] as $field ) {
				if ( ! is_object( $field ) || ! method_exists( $field, 'get_input_type' ) ) {
					continue;
				}

				if ( in_array( $field->get_input_type(), array( 'text', 'hidden' ), true ) ) {
					$map['ticket_name'] = (string) $field->id;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * GF field choices for override dropdowns (label + field ID).
	 *
	 * @param array $form GF form array.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function get_form_field_choices( array $form ): array {
		$choices = array();
		$skip    = array( 'html', 'section', 'page', 'captcha' );

		$form_id = (int) rgar( $form, 'id' );
		if ( $form_id > 0 && class_exists( 'GFFormsModel' ) ) {
			$meta = GFFormsModel::get_form_meta( $form_id );
			if ( is_array( $meta ) && ! empty( $meta['fields'] ) && is_array( $meta['fields'] ) ) {
				$form = $meta;
			}
		}

		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $choices;
		}

		foreach ( $form['fields'] as $field ) {
			if ( is_array( $field ) && class_exists( 'GF_Fields' ) ) {
				$field = GF_Fields::create( $field );
			}

			if ( ! is_object( $field ) ) {
				continue;
			}

			$type = method_exists( $field, 'get_input_type' ) ? $field->get_input_type() : (string) ( $field->type ?? '' );

			if ( in_array( $type, $skip, true ) ) {
				continue;
			}

			$field_id = (int) ( $field->id ?? 0 );
			if ( $field_id <= 0 ) {
				continue;
			}

			$label = trim( (string) ( $field->label ?? '' ) );
			if ( '' === $label ) {
				$label = sprintf(
					/* translators: %d: field ID */
					__( 'Field %d', 'gf-odoo-connector' ),
					$field_id
				);
			}

			if ( 'address' === $type ) {
				$sub_fields = array(
					'1' => __( 'Street', 'gf-odoo-connector' ),
					'2' => __( 'Street 2', 'gf-odoo-connector' ),
					'3' => __( 'City', 'gf-odoo-connector' ),
					'4' => __( 'State', 'gf-odoo-connector' ),
					'5' => __( 'ZIP', 'gf-odoo-connector' ),
					'6' => __( 'Country', 'gf-odoo-connector' ),
				);

				foreach ( $sub_fields as $suffix => $sub_label ) {
					$choices[] = array(
						'value' => $field_id . '.' . $suffix,
						'label' => sprintf(
							/* translators: 1: parent field label, 2: address sub-field label, 3: field ID, 4: input suffix */
							__( '%1$s → %2$s (field %3$s.%4$s)', 'gf-odoo-connector' ),
							$label,
							$sub_label,
							(string) $field_id,
							$suffix
						),
					);
				}
				continue;
			}

			if ( 'name' === $type ) {
				$sub_fields = array(
					'2' => __( 'Prefix', 'gf-odoo-connector' ),
					'3' => __( 'First', 'gf-odoo-connector' ),
					'4' => __( 'Middle', 'gf-odoo-connector' ),
					'6' => __( 'Last', 'gf-odoo-connector' ),
					'8' => __( 'Suffix', 'gf-odoo-connector' ),
				);

				foreach ( $sub_fields as $suffix => $sub_label ) {
					$choices[] = array(
						'value' => $field_id . '.' . $suffix,
						'label' => sprintf(
							/* translators: 1: parent field label, 2: name sub-field label, 3: field ID, 4: input suffix */
							__( '%1$s → %2$s (field %3$s.%4$s)', 'gf-odoo-connector' ),
							$label,
							$sub_label,
							(string) $field_id,
							$suffix
						),
					);
				}
				continue;
			}

			$choices[] = array(
				'value' => (string) $field_id,
				'label' => sprintf( '%s (field %d)', $label, $field_id ),
			);
		}

		usort(
			$choices,
			static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $choices;
	}
}
