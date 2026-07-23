<?php
/**
 * Odoo Helpdesk operations.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates helpdesk tickets in Odoo.
 */
class Helpdesk_Handler {

	/**
	 * @var Odoo_API
	 */
	private $api;

	/**
	 * @var CRM_Handler|null
	 */
	private $crm;

	/**
	 * Last company/person IDs from create_ticket() partner sync.
	 *
	 * @var array{company_id: int, person_id: int}
	 */
	private $last_partner_sync = array(
		'company_id' => 0,
		'person_id'  => 0,
	);

	/**
	 * @param Odoo_API         $api Authenticated Odoo API client.
	 * @param CRM_Handler|null $crm Optional CRM handler for partner lookup and deduplication.
	 */
	public function __construct( Odoo_API $api, ?CRM_Handler $crm = null ) {
		$this->api = $api;
		$this->crm = $crm;
	}

	/**
	 * Company and person IDs from the most recent create_ticket() partner sync.
	 *
	 * @return array{company_id: int, person_id: int}
	 */
	public function get_last_partner_sync(): array {
		return $this->last_partner_sync;
	}

	/**
	 * Fetch helpdesk.ticket field metadata (for WP_DEBUG discovery tool).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_ticket_fields_metadata(): array {
		$result = $this->api->call(
			'helpdesk.ticket',
			'fields_get',
			array(),
			array(
				'attributes' => array( 'string', 'type', 'relation' ),
			)
		);

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Resolve the Odoo technical name for the Issue Description tab.
	 *
	 * Custom InBody forms often show standard "description" under Resolution;
	 * Issue Description is a separate field whose label may not expose a tooltip.
	 * We match fields_get labels (and field names as fallback) instead.
	 *
	 * @param string $explicit Feed/setting override. Empty or "auto" = detect from Odoo.
	 *
	 * @return string Technical field name, or empty when not found.
	 */
	public function resolve_issue_description_field( string $explicit = '' ): string {
		$explicit = strtolower( trim( $explicit ) );

		if ( '' !== $explicit && 'auto' !== $explicit ) {
			return $explicit;
		}

		$cached = get_transient( 'gf_odoo_issue_desc_field' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$fields     = $this->get_ticket_fields_metadata();
		$candidates = array();

		foreach ( $fields as $name => $meta ) {
			$meta  = (array) $meta;
			$type  = (string) rgar( $meta, 'type' );
			$label = strtolower( trim( (string) rgar( $meta, 'string', '' ) ) );
			$name_l = strtolower( (string) $name );

			if ( ! in_array( $type, array( 'text', 'html', 'char' ), true ) ) {
				continue;
			}

			// On customised instances "description" is Resolution, not Issue.
			if ( 'description' === $name_l ) {
				continue;
			}

			$score = 0;

			if ( 'issue description' === $label ) {
				$score = 100;
			} elseif ( str_contains( $label, 'issue description' ) ) {
				$score = 90;
			} elseif ( str_contains( $label, 'issue desc' ) ) {
				$score = 80;
			} elseif ( preg_match( '/\bissue\b/', $label ) && ! str_contains( $label, 'resolution' ) ) {
				$score = 45;
			} elseif ( str_contains( $name_l, 'issue' ) && str_contains( $name_l, 'desc' ) ) {
				$score = 70;
			} elseif ( str_starts_with( $name_l, 'x_' ) && str_contains( $name_l, 'issue' ) ) {
				$score = 65;
			} elseif ( str_contains( $name_l, 'issue' ) && 'html' === $type ) {
				$score = 50;
			}

			if ( $score <= 0 ) {
				continue;
			}

			if ( 'html' === $type ) {
				$score += 5;
			} elseif ( 'text' === $type ) {
				$score += 2;
			}

			$candidates[] = array(
				'name'  => (string) $name,
				'score' => $score,
			);
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		$resolved = isset( $candidates[0]['name'] ) ? (string) $candidates[0]['name'] : '';

		if ( '' === $resolved ) {
			$html_fallbacks = array();

			foreach ( $fields as $name => $meta ) {
				$meta   = (array) $meta;
				$type   = (string) rgar( $meta, 'type' );
				$name_l = strtolower( (string) $name );
				$label  = strtolower( trim( (string) rgar( $meta, 'string', '' ) ) );

				if ( 'html' !== $type || 'description' === $name_l ) {
					continue;
				}

				if ( str_contains( $label, 'resolution' ) ) {
					continue;
				}

				$html_fallbacks[] = (string) $name;
			}

			if ( 1 === count( $html_fallbacks ) ) {
				$resolved = $html_fallbacks[0];
			}
		}

		if ( '' !== $resolved ) {
			set_transient( 'gf_odoo_issue_desc_field', $resolved, DAY_IN_SECONDS );
		}

		return $resolved;
	}

	/**
	 * @return array<int, string> helpdesk.ticket field names with type html.
	 */
	private function get_html_ticket_field_names(): array {
		$names = array();

		foreach ( $this->get_ticket_fields_metadata() as $name => $meta ) {
			if ( 'html' === (string) rgar( (array) $meta, 'type' ) ) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}

	/**
	 * Allow mapped text/html and custom fields through create() field filtering.
	 *
	 * @param array<string, mixed> $data    Payload.
	 * @param array<int, string>   $allowed Base allow list.
	 *
	 * @return array<int, string>
	 */
	private function expand_allowed_with_payload_fields( array $data, array $allowed ): array {
		$meta_fields = $this->get_ticket_fields_metadata();

		foreach ( array_keys( $data ) as $field_key ) {
			if ( ! is_string( $field_key ) ) {
				continue;
			}

			if ( 0 === strpos( $field_key, 'x_' ) ) {
				$allowed[] = $field_key;
				continue;
			}

			$type = (string) rgar( (array) ( $meta_fields[ $field_key ] ?? array() ), 'type' );

			if ( in_array( $type, array( 'html', 'text' ), true ) ) {
				$allowed[] = $field_key;
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Fetch helpdesk teams for admin dropdowns.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_teams(): array {
		$teams = $this->api->call(
			'helpdesk.team',
			'search_read',
			array(
				array(
					array( 'active', '=', true ),
				),
			),
			array(
				'fields' => array( 'id', 'name' ),
				'limit'  => 200,
				'order'  => 'name asc, id asc',
			)
		);

		return $this->dedupe_team_choices( $this->format_choice_list( $teams ) );
	}

	/**
	 * Inquiry / ticket type choices.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_ticket_categories(): array {
		$row   = Helpdesk_Field_Config::get_row( 'ticket_category' );
		$model = (string) rgar( $row, 'odoo_model', 'ticket.category' );

		$fallback_models = array(
			$model,
			'helpdesk.ticket.type',
			'helpdesk.ticket.category',
			'helpdesk.type',
		);

		return $this->search_read_choices( array_unique( $fallback_models ) );
	}

	/**
	 * Branch choices for fixed dropdown.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_branches(): array {
		$row   = Helpdesk_Field_Config::get_row( 'ticket_branch' );
		$model = (string) rgar( $row, 'odoo_model', 'res.branch' );

		$fallback_models = array(
			$model,
			'res.branch',
			'helpdesk.branch',
			'inbody.branch',
		);

		return $this->search_read_choices( array_unique( $fallback_models ) );
	}

	/**
	 * State/province choices from res.country.state for admin fixed-value dropdowns.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_states(): array {
		return $this->search_read_choices( array( 'res.country.state' ) );
	}

	/**
	 * Country choices from res.country for admin fixed-value dropdowns.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_countries(): array {
		return $this->search_read_choices( array( 'res.country' ) );
	}

	/**
	 * Find or create res.partner by email (dedupe by email).
	 *
	 * @param string $email Contact email.
	 * @param string $name  Contact name.
	 * @param string $phone Phone number.
	 *
	 * @return int|null Partner ID or null when email is empty.
	 */
	public function find_or_create_contact( string $email, string $name = '', string $phone = '' ): ?int {
		$email = sanitize_email( trim( $email ) );

		if ( '' === $email ) {
			return null;
		}

		if ( null === $this->crm ) {
			$this->crm = new CRM_Handler( $this->api );
		}

		$partner_id = $this->crm->find_partner_by_email( $email );

		$data = array(
			'name'  => '' !== trim( $name ) ? trim( $name ) : $email,
			'email' => $email,
		);

		if ( '' !== trim( $phone ) ) {
			$data['phone'] = trim( $phone );
		}

		if ( null !== $partner_id ) {
			$this->api->call(
				'res.partner',
				'write',
				array(
					array( $partner_id ),
					$data,
				)
			);

			return $partner_id;
		}

		return $this->crm->create_or_update_contact( $data );
	}

	/**
	 * Fetch members of a helpdesk team.
	 *
	 * @param int $team_id helpdesk.team ID.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_team_members( int $team_id ): array {
		if ( $team_id <= 0 ) {
			return array();
		}

		$teams = $this->api->call(
			'helpdesk.team',
			'read',
			array(
				array( $team_id ),
			),
			array(
				'fields' => array( 'member_ids' ),
			)
		);

		if ( empty( $teams[0]['member_ids'] ) || ! is_array( $teams[0]['member_ids'] ) ) {
			return array();
		}

		$member_ids = array_map( 'intval', $teams[0]['member_ids'] );

		$users = $this->api->call(
			'res.users',
			'read',
			array(
				$member_ids,
			),
			array(
				'fields' => array( 'id', 'name' ),
			)
		);

		return $this->format_choice_list( $users );
	}

	/**
	 * Create a helpdesk.ticket in Odoo.
	 *
	 * @param array $data Ticket field values.
	 *
	 * @return int Odoo ticket ID.
	 *
	 * @throws InvalidArgumentException When required fields are missing.
	 * @throws RuntimeException         When the Odoo API call fails.
	 */
	public function create_ticket( array $data ): int {
		$this->last_partner_sync = array(
			'company_id' => 0,
			'person_id'  => 0,
		);

		$allowed = Helpdesk_Field_Config::ticket_field_names();
		$allowed = $this->expand_allowed_with_payload_fields( $data, $allowed );

		$data = $this->filter_fields( $data, $allowed );

		if ( empty( $data['name'] ) ) {
			throw new InvalidArgumentException(
				__( 'Ticket subject is required. Configure Ticket subject in the feed settings.', 'gf-odoo-connector' )
			);
		}

		if ( empty( $data['description'] ) && array_key_exists( 'description', $data ) ) {
			unset( $data['description'] );
		}

		if ( empty( $data['team_id'] ) ) {
			throw new InvalidArgumentException(
				__( 'Helpdesk Team is required. Set Helpdesk team to Fixed in the feed settings.', 'gf-odoo-connector' )
			);
		}

		$data['team_id'] = (int) $data['team_id'];

		foreach ( $data as $key => $value ) {
			if ( str_ends_with( $key, '_id' ) && is_numeric( $value ) ) {
				$data[ $key ] = (int) $value;
			}
		}

		if (
			empty( $data['partner_id'] )
			&& (
				! empty( $data['partner_email'] )
				|| ! empty( $data['partner_name'] )
				|| ! empty( $data['customer_id'] )
				|| ! empty( $data['company_name'] )
			)
		) {
			if ( null === $this->crm ) {
				$this->crm = new CRM_Handler( $this->api );
			}

			$partner_handler = new Partner_Handler( $this->api, $this->crm );
			$has_person      = ! empty( $data['partner_email'] ) || ! empty( $data['partner_name'] );

			if ( $has_person ) {
				$contact_blob = array();
				foreach ( array( 'partner_name', 'partner_email', 'partner_phone', 'customer_id', 'company_name', 'country_id', 'state_id' ) as $field ) {
					if ( ! empty( $data[ $field ] ) ) {
						$contact_blob[ $field ] = $data[ $field ];
					}
				}

				$lead_names   = array();
				foreach ( array( 'first_name', 'last_name' ) as $field ) {
					if ( ! empty( $data[ $field ] ) ) {
						$lead_names[ $field ] = $data[ $field ];
					}
				}

				$ids = $partner_handler->create_company_and_person( $contact_blob, $lead_names );

				$this->last_partner_sync = array(
					'company_id' => (int) $ids['company_id'],
					'person_id'  => (int) $ids['person_id'],
				);
				$data['partner_id']      = $ids['person_id'];

				if ( $ids['company_id'] > 0 ) {
					$data['customer_id'] = $ids['company_id'];
				}
			} else {
				$company_name = trim( (string) ( $data['company_name'] ?? $data['customer_id'] ?? '' ) );

				if ( '' !== $company_name && ! is_numeric( $company_name ) ) {
					$company_data = array( 'name' => $company_name );
					foreach ( array( 'country_id', 'state_id' ) as $field ) {
						if ( ! empty( $data[ $field ] ) ) {
							$company_data[ $field ] = $data[ $field ];
						}
					}

					$company_id = $partner_handler->create_or_update_company( $company_data );
					$this->last_partner_sync['company_id'] = $company_id;
					$data['customer_id']                   = $company_id;
				}
			}
		}

		if ( ! empty( $data['partner_email'] ) ) {
			$data['partner_email'] = sanitize_email( (string) $data['partner_email'] );
		}

		foreach ( $this->get_html_ticket_field_names() as $html_field ) {
			if ( ! empty( $data[ $html_field ] ) && is_string( $data[ $html_field ] ) ) {
				$data[ $html_field ] = $this->format_html_description( $data[ $html_field ] );
			}
		}

		foreach ( array_keys( $data ) as $field_key ) {
			if ( ! is_string( $field_key ) || ! is_string( $data[ $field_key ] ?? null ) ) {
				continue;
			}

			$type = (string) rgar( (array) ( $this->get_ticket_fields_metadata()[ $field_key ] ?? array() ), 'type' );

			if ( 'text' === $type && str_contains( $data[ $field_key ], '<' ) ) {
				$data[ $field_key ] = $this->format_html_description( $data[ $field_key ] );
			}
		}

		if ( array_key_exists( 'under_warranty', $data ) ) {
			$data['under_warranty'] = (bool) $data['under_warranty'];
		}

		if ( ! empty( $data['tag_ids'] ) && ! is_array( $data['tag_ids'] ) ) {
			$tag_id = $this->resolve_helpdesk_tag_id( (string) $data['tag_ids'] );

			if ( $tag_id > 0 ) {
				$data['tag_ids'] = array( array( 6, 0, array( $tag_id ) ) );
			} else {
				unset( $data['tag_ids'] );
			}
		}

		foreach ( array( 'ticket_category_id', 'category_id' ) as $category_field ) {
			if ( ! array_key_exists( $category_field, $data ) || '' === $data[ $category_field ] || null === $data[ $category_field ] ) {
				continue;
			}

			$raw = $data[ $category_field ];
			unset( $data[ $category_field ] );

			$category_id = $this->normalize_ticket_category_id( $raw );

			if ( $category_id > 0 ) {
				$data['ticket_category_id'] = $category_id;
			}

			break;
		}

		if ( ! empty( $data['serial_id'] ) && ! is_numeric( $data['serial_id'] ) ) {
			$serial_id = $this->find_stock_lot_id( (string) $data['serial_id'] );

			if ( $serial_id > 0 ) {
				$data['serial_id'] = $serial_id;
			} else {
				unset( $data['serial_id'] );
			}
		}

		$id = $this->extract_record_id(
			$this->api->call( 'helpdesk.ticket', 'create', array( $data ) )
		);

		return $id;
	}

	/**
	 * @param array<int, string> $models Models to try in order.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	private function search_read_choices( array $models ): array {
		foreach ( $models as $model ) {
			if ( '' === $model ) {
				continue;
			}

			try {
				$records = $this->api->call(
					$model,
					'search_read',
					array(
						array(),
					),
					array(
						'fields' => array( 'id', 'name' ),
						'limit'  => 500,
						'order'  => 'name asc, id asc',
					)
				);

				$choices = $this->format_choice_list( $records );
				if ( ! empty( $choices ) ) {
					return $choices;
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		return array();
	}

	/**
	 * Wrap plain text for Odoo html fields.
	 *
	 * @param string $text Message body.
	 *
	 * @return string
	 */
	private function format_html_description( string $text ): string {
		if ( false !== strpos( $text, '<' ) ) {
			return $text;
		}

		return '<p>' . nl2br( esc_html( $text ), false ) . '</p>';
	}

	/**
	 * Resolve a product tag value to a helpdesk.tag database ID for tag_ids.
	 *
	 * Accepts tag slugs (tag_24), numeric IDs (24), or device labels (BPBIO 320).
	 * Tries, in order: static map → xml id lookup → id match → live name search.
	 *
	 * @param string $input Raw mapped value.
	 *
	 * @return int helpdesk.tag ID, or 0 when not found.
	 */
	private function resolve_helpdesk_tag_id( string $input ): int {
		$input = trim( $input );

		if ( '' === $input ) {
			return 0;
		}

		$cache_key = 'gf_odoo_hd_tag_' . md5( strtolower( $input ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$candidates = array( $input );

		if ( class_exists( 'GF_Odoo_Product_Tag_Map' ) ) {
			$slug = GF_Odoo_Product_Tag_Map::resolve( $input );

			if ( null !== $slug && $slug !== $input ) {
				$candidates[] = $slug;
			}
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			$tag_id = $this->resolve_helpdesk_tag_candidate( (string) $candidate );

			if ( $tag_id > 0 ) {
				set_transient( $cache_key, $tag_id, HOUR_IN_SECONDS );

				return $tag_id;
			}
		}

		$tag_id = $this->find_helpdesk_tag_by_name( $input );

		if ( $tag_id > 0 ) {
			set_transient( $cache_key, $tag_id, HOUR_IN_SECONDS );
		}

		return $tag_id;
	}

	/**
	 * Resolve one candidate (tag_24, 24, or a slug from the static map).
	 *
	 * @param string $candidate Tag slug or numeric string.
	 *
	 * @return int
	 */
	private function resolve_helpdesk_tag_candidate( string $candidate ): int {
		$candidate = trim( $candidate );

		if ( '' === $candidate ) {
			return 0;
		}

		if ( preg_match( '/^tag_(\d+)$/i', $candidate, $matches ) ) {
			return $this->resolve_helpdesk_tag_by_number( (int) $matches[1], strtolower( $candidate ) );
		}

		if ( is_numeric( $candidate ) ) {
			return $this->resolve_helpdesk_tag_by_number( (int) $candidate, 'tag_' . (int) $candidate );
		}

		return 0;
	}

	/**
	 * Look up helpdesk.tag by export xml id, then by database id.
	 *
	 * @param int    $number   Tag number from tag_24 or bare 24.
	 * @param string $slug_key Cache key suffix (e.g. tag_24).
	 *
	 * @return int
	 */
	private function resolve_helpdesk_tag_by_number( int $number, string $slug_key ): int {
		if ( $number <= 0 ) {
			return 0;
		}

		$cache_key = 'gf_odoo_hd_tag_' . $slug_key;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$xml_names = array(
			'helpdesk_tag_' . $number,
			'tag_' . $number,
		);

		foreach ( $xml_names as $xml_name ) {
			try {
				$rows = $this->api->call(
					'ir.model.data',
					'search_read',
					array(
						array(
							array( 'name', '=', $xml_name ),
							array( 'model', '=', 'helpdesk.tag' ),
						),
					),
					array(
						'fields' => array( 'res_id' ),
						'limit'  => 1,
					)
				);

				if ( ! empty( $rows[0]['res_id'] ) ) {
					$id = (int) $rows[0]['res_id'];
					set_transient( $cache_key, $id, HOUR_IN_SECONDS );

					return $id;
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		try {
			$tags = $this->api->call(
				'helpdesk.tag',
				'search_read',
				array(
					array(
						array( 'id', '=', $number ),
					),
				),
				array(
					'fields' => array( 'id' ),
					'limit'  => 1,
				)
			);

			if ( ! empty( $tags[0]['id'] ) ) {
				$id = (int) $tags[0]['id'];
				set_transient( $cache_key, $id, HOUR_IN_SECONDS );

				return $id;
			}
		} catch ( Exception $e ) {
			return 0;
		}

		return 0;
	}

	/**
	 * Last-resort lookup: match helpdesk.tag by its display name in Odoo.
	 *
	 * @param string $name Device label, e.g. BPBIO 320.
	 *
	 * @return int
	 */
	private function find_helpdesk_tag_by_name( string $name ): int {
		$name = trim( $name );

		if ( '' === $name ) {
			return 0;
		}

		try {
			$tags = $this->api->call(
				'helpdesk.tag',
				'search_read',
				array(
					array(
						array( 'name', '=', $name ),
					),
				),
				array(
					'fields' => array( 'id' ),
					'limit'  => 1,
				)
			);

			if ( ! empty( $tags[0]['id'] ) ) {
				return (int) $tags[0]['id'];
			}
		} catch ( Exception $e ) {
			return 0;
		}

		return 0;
	}

	/**
	 * Resolve a ticket category value to a category_id for helpdesk.ticket.
	 *
	 * Accepts category slugs (category_12), numeric IDs (12), or labels (Malfunction).
	 *
	 * @param string $input Raw mapped value.
	 *
	 * @return int Category record ID, or 0 when not found.
	 */
	private function resolve_ticket_category_id( string $input ): int {
		$input = trim( $input );

		if ( '' === $input ) {
			return 0;
		}

		$cache_key = 'gf_odoo_ticket_cat_' . md5( strtolower( $input ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		if ( class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
			$hex_ref = GF_Odoo_Ticket_Category_Map::resolve( $input );

			if ( null !== $hex_ref ) {
				$category_id = $this->resolve_ticket_category_hex_ref( $hex_ref );

				if ( $category_id > 0 ) {
					set_transient( $cache_key, $category_id, HOUR_IN_SECONDS );

					return $category_id;
				}
			}
		}

		if ( preg_match( '/^[a-f0-9]{6,8}$/i', $input ) ) {
			$category_id = $this->resolve_ticket_category_hex_ref( $input );

			if ( $category_id > 0 ) {
				set_transient( $cache_key, $category_id, HOUR_IN_SECONDS );

				return $category_id;
			}
		}

		// Labels like "Web/app" via live Odoo name search.
		if ( ! preg_match( '/^category_\d+$/i', $input ) && ! is_numeric( $input ) ) {
			$category_id = $this->find_ticket_category_by_name( $input );

			if ( $category_id > 0 ) {
				set_transient( $cache_key, $category_id, HOUR_IN_SECONDS );

				return $category_id;
			}
		}

		$candidates = array( $input );

		if ( class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
			$slug = GF_Odoo_Ticket_Category_Map::resolve( $input );

			if ( null !== $slug && $slug !== $input ) {
				$candidates[] = $slug;
			}
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			$category_id = $this->resolve_ticket_category_candidate( (string) $candidate );

			if ( $category_id > 0 ) {
				set_transient( $cache_key, $category_id, HOUR_IN_SECONDS );

				return $category_id;
			}
		}

		$category_id = $this->find_ticket_category_by_name( $input );

		if ( $category_id > 0 ) {
			set_transient( $cache_key, $category_id, HOUR_IN_SECONDS );
		}

		return $category_id;
	}

	/**
	 * @param string $xml_name ir.model.data name (e.g. ticket_category_12_f43eb468).
	 *
	 * @return int ticket.category res_id.
	 */
	private function resolve_ticket_category_xml_name( string $xml_name ): int {
		$xml_name = strtolower( trim( $xml_name ) );

		if ( '' === $xml_name ) {
			return 0;
		}

		$cache_key = 'gf_odoo_ticket_cat_xml_' . md5( $xml_name );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$res_id = $this->find_ticket_category_xml_res_id( $xml_name, false, '__export__' );

		if ( $res_id <= 0 ) {
			$res_id = $this->find_ticket_category_xml_res_id( $xml_name, false );
		}

		if ( $res_id > 0 ) {
			set_transient( $cache_key, $res_id, HOUR_IN_SECONDS );
		}

		return $res_id;
	}

	/**
	 * @param mixed $raw Mapped category value (slug, label, or numeric id).
	 *
	 * @return int
	 */
	private function normalize_ticket_category_id( $raw ): int {
		if ( is_int( $raw ) || ( is_string( $raw ) && is_numeric( $raw ) ) ) {
			$number = (int) $raw;

			return ( $number > 0 && $this->ticket_category_record_exists( $number ) ) ? $number : 0;
		}

		return $this->resolve_ticket_category_id( (string) $raw );
	}

	/**
	 * Resolve an Odoo export hex ref to a ticket.category res_id.
	 *
	 * @param string $hex_ref Hex from export (e.g. 3eb468).
	 *
	 * @return int
	 */
	private function resolve_ticket_category_hex_ref( string $hex_ref ): int {
		$hex_ref = strtolower( trim( $hex_ref ) );

		if ( '' === $hex_ref ) {
			return 0;
		}

		$cache_key = 'gf_odoo_ticket_cat_hex_' . md5( $hex_ref );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$patterns = class_exists( 'GF_Odoo_Ticket_Category_Map' )
			? GF_Odoo_Ticket_Category_Map::xml_lookup_patterns( $hex_ref )
			: array( '%' . $hex_ref );

		foreach ( $patterns as $pattern ) {
			$res_id = $this->find_ticket_category_xml_res_id( $pattern, true, '__export__' );

			if ( $res_id <= 0 ) {
				$res_id = $this->find_ticket_category_xml_res_id( $pattern, true );
			}

			if ( $res_id > 0 ) {
				set_transient( $cache_key, $res_id, HOUR_IN_SECONDS );

				return $res_id;
			}
		}

		if ( class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
			$label = GF_Odoo_Ticket_Category_Map::label_for_slug( $hex_ref );

			if ( null !== $label ) {
				$by_name = $this->find_ticket_category_by_name( $label );

				if ( $by_name > 0 ) {
					set_transient( $cache_key, $by_name, HOUR_IN_SECONDS );

					return $by_name;
				}
			}
		}

		return 0;
	}

	/**
	 * @param int $record_id Candidate ticket.category ID.
	 *
	 * @return bool
	 */
	private function ticket_category_record_exists( int $record_id ): bool {
		if ( $record_id <= 0 ) {
			return false;
		}

		foreach ( $this->ticket_category_models() as $model ) {
			try {
				$records = $this->api->call(
					$model,
					'search_read',
					array(
						array(
							array( 'id', '=', $record_id ),
						),
					),
					array(
						'fields' => array( 'id' ),
						'limit'  => 1,
					)
				);

				if ( ! empty( $records[0]['id'] ) ) {
					return true;
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		return false;
	}

	/**
	 * @param string $candidate Category slug or numeric string.
	 *
	 * @return int
	 */
	private function resolve_ticket_category_candidate( string $candidate ): int {
		$candidate = trim( $candidate );

		if ( '' === $candidate ) {
			return 0;
		}

		if ( preg_match( '/^[a-f0-9]{6,8}$/i', $candidate ) ) {
			return $this->resolve_ticket_category_hex_ref( $candidate );
		}

		if ( preg_match( '/^ticket_category_\d+_[a-f0-9]+$/i', $candidate ) ) {
			return $this->resolve_ticket_category_xml_name( $candidate );
		}

		if ( preg_match( '/^category_(\d+)$/i', $candidate, $matches ) ) {
			return $this->resolve_ticket_category_by_number( (int) $matches[1], strtolower( $candidate ) );
		}

		if ( is_numeric( $candidate ) ) {
			return $this->resolve_ticket_category_by_number( (int) $candidate, 'category_' . (int) $candidate );
		}

		return 0;
	}

	/**
	 * @param int    $number   Category number from category_12 or bare 12.
	 * @param string $slug_key Cache key suffix.
	 *
	 * @return int
	 */
	private function resolve_ticket_category_by_number( int $number, string $slug_key ): int {
		if ( $number <= 0 ) {
			return 0;
		}

		$cache_key = 'gf_odoo_ticket_cat_' . $slug_key;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$xml_patterns = array(
			'ticket_category_' . $number . '%',
			'category_' . $number . '%',
		);

		$xml_names = array(
			'ticket_category_' . $number,
			'category_' . $number,
		);

		foreach ( $xml_patterns as $pattern ) {
			$res_id = $this->find_ticket_category_xml_res_id( $pattern, true );

			if ( $res_id > 0 ) {
				set_transient( $cache_key, $res_id, HOUR_IN_SECONDS );

				return $res_id;
			}
		}

		foreach ( $xml_names as $xml_name ) {
			$res_id = $this->find_ticket_category_xml_res_id( $xml_name, false );

			if ( $res_id > 0 ) {
				set_transient( $cache_key, $res_id, HOUR_IN_SECONDS );

				return $res_id;
			}
		}

		if ( class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
			$hex_ref = GF_Odoo_Ticket_Category_Map::resolve( $slug_key );

			if ( null !== $hex_ref ) {
				$by_hex = $this->resolve_ticket_category_hex_ref( $hex_ref );

				if ( $by_hex > 0 ) {
					set_transient( $cache_key, $by_hex, HOUR_IN_SECONDS );

					return $by_hex;
				}
			}

			$label = GF_Odoo_Ticket_Category_Map::label_for_slug( $slug_key );

			if ( null !== $label ) {
				$by_name = $this->find_ticket_category_by_name( $label );

				if ( $by_name > 0 ) {
					set_transient( $cache_key, $by_name, HOUR_IN_SECONDS );

					return $by_name;
				}
			}
		}

		foreach ( $this->ticket_category_models() as $model ) {
			try {
				$records = $this->api->call(
					$model,
					'search_read',
					array(
						array(
							array( 'id', '=', $number ),
						),
					),
					array(
						'fields' => array( 'id' ),
						'limit'  => 1,
					)
				);

				if ( ! empty( $records[0]['id'] ) ) {
					$id = (int) $records[0]['id'];
					set_transient( $cache_key, $id, HOUR_IN_SECONDS );

					return $id;
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		return 0;
	}

	/**
	 * @param string $name Category display name.
	 *
	 * @return int
	 */
	private function find_ticket_category_by_name( string $name ): int {
		$name = trim( $name );

		if ( '' === $name ) {
			return 0;
		}

		$searches = array(
			array( 'name', '=', $name ),
			array( 'name', 'ilike', $name ),
		);

		foreach ( $this->ticket_category_models() as $model ) {
			foreach ( $searches as $condition ) {
				try {
					$records = $this->api->call(
						$model,
						'search_read',
						array(
							array( $condition ),
						),
						array(
							'fields' => array( 'id', 'name' ),
							'limit'  => 1,
						)
					);

					if ( ! empty( $records[0]['id'] ) ) {
						return (int) $records[0]['id'];
					}
				} catch ( Exception $e ) {
					continue;
				}
			}
		}

		return 0;
	}

	/**
	 * @param string      $name_pattern Exact xml name or =like pattern (with %).
	 * @param bool        $use_like     When true, use =like operator.
	 * @param string|null $module       Optional ir.model.data module (e.g. __export__).
	 *
	 * @return int res_id from ir.model.data.
	 */
	private function find_ticket_category_xml_res_id( string $name_pattern, bool $use_like, ?string $module = null ): int {
		$operator = $use_like ? '=like' : '=';

		$domain = array(
			array( 'name', $operator, $name_pattern ),
		);

		if ( null !== $module && '' !== $module ) {
			$domain[] = array( 'module', '=', $module );
		}

		foreach ( $this->ticket_category_models() as $model ) {
			try {
				$search_domain = array_merge( array( $domain ), array( array( 'model', '=', $model ) ) );

				$rows = $this->api->call(
					'ir.model.data',
					'search_read',
					array( $search_domain ),
					array(
						'fields' => array( 'res_id' ),
						'limit'  => 1,
					)
				);

				if ( ! empty( $rows[0]['res_id'] ) ) {
					return (int) $rows[0]['res_id'];
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		try {
			$rows = $this->api->call(
				'ir.model.data',
				'search_read',
				array(
					array( $domain ),
				),
				array(
					'fields' => array( 'res_id', 'model' ),
					'limit'  => 5,
				)
			);

			$allowed = $this->ticket_category_models();

			foreach ( (array) $rows as $row ) {
				$model = (string) ( $row['model'] ?? '' );

				if ( in_array( $model, $allowed, true ) && ! empty( $row['res_id'] ) ) {
					return (int) $row['res_id'];
				}
			}
		} catch ( Exception $e ) {
			return 0;
		}

		return 0;
	}

	/**
	 * Odoo models that may store ticket categories.
	 *
	 * @return array<int, string>
	 */
	private function ticket_category_models(): array {
		return array(
			'ticket.category',
			'helpdesk.ticket.category',
			'helpdesk.ticket.type',
		);
	}

	/**
	 * @param string $name Company name.
	 *
	 * @return int res.partner ID or 0.
	 */
	private function find_customer_company_id( string $name ): int {
		$name = trim( $name );

		if ( '' === $name ) {
			return 0;
		}

		try {
			$partners = $this->api->call(
				'res.partner',
				'search_read',
				array(
					array(
						array( 'name', '=', $name ),
						array( 'is_company', '=', true ),
					),
				),
				array(
					'fields' => array( 'id' ),
					'limit'  => 1,
				)
			);

			if ( ! empty( $partners[0]['id'] ) ) {
				return (int) $partners[0]['id'];
			}
		} catch ( Exception $e ) {
			return 0;
		}

		return 0;
	}

	/**
	 * @param string $serial Serial number / lot name.
	 *
	 * @return int stock.lot ID or 0.
	 */
	private function find_stock_lot_id( string $serial ): int {
		$serial = trim( $serial );

		if ( '' === $serial ) {
			return 0;
		}

		foreach ( array( 'name', 'ref' ) as $field ) {
			try {
				$lots = $this->api->call(
					'stock.lot',
					'search_read',
					array(
						array(
							array( $field, '=', $serial ),
						),
					),
					array(
						'fields' => array( 'id' ),
						'limit'  => 1,
					)
				);

				if ( ! empty( $lots[0]['id'] ) ) {
					return (int) $lots[0]['id'];
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		return 0;
	}

	/**
	 * Keep only allowed keys with non-empty scalar values (booleans included).
	 *
	 * @param array             $data    Input data.
	 * @param array<int,string> $allowed Allowed field names.
	 *
	 * @return array
	 */
	private function filter_fields( array $data, array $allowed ): array {
		$filtered = array();

		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}

			$value = $data[ $field ];

			if ( is_bool( $value ) ) {
				$filtered[ $field ] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					$filtered[ $field ] = $value;
				}
				continue;
			}

			if ( null === $value ) {
				continue;
			}

			$value = is_string( $value ) ? trim( $value ) : $value;

			if ( '' === $value && '0' !== $value && 0 !== $value ) {
				continue;
			}

			$filtered[ $field ] = $value;
		}

		return $filtered;
	}

	/**
	 * @param mixed $result API result.
	 *
	 * @return int
	 *
	 * @throws RuntimeException When no ID is returned.
	 */
	private function extract_record_id( $result ): int {
		if ( is_array( $result ) ) {
			if ( isset( $result[0] ) ) {
				return (int) $result[0];
			}

			if ( isset( $result['id'] ) ) {
				return (int) $result['id'];
			}
		}

		if ( is_numeric( $result ) ) {
			return (int) $result;
		}

		throw new RuntimeException(
			__( 'Odoo did not return a record ID.', 'gf-odoo-connector' )
		);
	}

	/**
	 * @param mixed $records search_read / read result.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	private function format_choice_list( $records ): array {
		if ( ! is_array( $records ) ) {
			return array();
		}

		$choices = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['id'] ) ) {
				continue;
			}

			$choices[] = array(
				'value' => (int) $record['id'],
				'label' => (string) ( $record['name'] ?? $record['id'] ),
			);
		}

		return $choices;
	}

	/**
	 * @param array<int, array{value: int, label: string}> $teams Team choices.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	private function dedupe_team_choices( array $teams ): array {
		$by_id    = array();
		$by_label = array();

		foreach ( $teams as $team ) {
			$id = (int) ( $team['value'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$by_id[ $id ] = $team;
		}

		foreach ( $by_id as $team ) {
			$label = (string) $team['label'];
			$by_label[ $label ] = ( $by_label[ $label ] ?? 0 ) + 1;
		}

		$unique = array();

		foreach ( $by_id as $id => $team ) {
			$label = (string) $team['label'];
			if ( $by_label[ $label ] > 1 ) {
				$label = sprintf( '%s (#%d)', $label, $id );
			}
			$unique[] = array(
				'value' => $id,
				'label' => $label,
			);
		}

		return $unique;
	}
}
