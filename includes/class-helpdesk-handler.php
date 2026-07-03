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
	 * @param Odoo_API         $api Authenticated Odoo API client.
	 * @param CRM_Handler|null $crm Optional CRM handler for partner lookup and deduplication.
	 */
	public function __construct( Odoo_API $api, ?CRM_Handler $crm = null ) {
		$this->api = $api;
		$this->crm = $crm;
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
			} elseif ( str_contains( $name_l, 'issue' ) && str_contains( $name_l, 'desc' ) ) {
				$score = 70;
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
		$allowed = Helpdesk_Field_Config::ticket_field_names();

		// Allow Odoo custom fields (always x_ prefixed) so smart routing can
		// target instance-specific fields such as a custom "Issue Description".
		foreach ( array_keys( $data ) as $field_key ) {
			if ( is_string( $field_key ) && 0 === strpos( $field_key, 'x_' ) ) {
				$allowed[] = $field_key;
			}
		}

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

		if ( ! empty( $data['partner_email'] ) && empty( $data['partner_id'] ) ) {
			$partner_id = $this->find_or_create_contact(
				(string) $data['partner_email'],
				(string) ( $data['partner_name'] ?? '' ),
				(string) ( $data['partner_phone'] ?? '' )
			);
			if ( null !== $partner_id ) {
				$data['partner_id'] = $partner_id;
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
			if ( empty( $data[ $category_field ] ) || is_numeric( $data[ $category_field ] ) ) {
				continue;
			}

			$category_id = $this->resolve_ticket_category_id( (string) $data[ $category_field ] );

			if ( $category_id > 0 ) {
				$data['ticket_category_id'] = $category_id;
			} else {
				unset( $data['ticket_category_id'] );
			}

			unset( $data[ $category_field ] );
			break;
		}

		if ( ! empty( $data['customer_id'] ) && ! is_numeric( $data['customer_id'] ) ) {
			$customer_id = $this->find_customer_company_id( (string) $data['customer_id'] );

			if ( $customer_id > 0 ) {
				$data['customer_id'] = $customer_id;
			} else {
				unset( $data['customer_id'] );
			}
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
	 * @param string $candidate Category slug or numeric string.
	 *
	 * @return int
	 */
	private function resolve_ticket_category_candidate( string $candidate ): int {
		$candidate = trim( $candidate );

		if ( '' === $candidate ) {
			return 0;
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

		$xml_names = array(
			'ticket_category_' . $number,
			'category_' . $number,
		);

		foreach ( $xml_names as $xml_name ) {
			foreach ( $this->ticket_category_models() as $model ) {
				try {
					$rows = $this->api->call(
						'ir.model.data',
						'search_read',
						array(
							array(
								array( 'name', '=', $xml_name ),
								array( 'model', '=', $model ),
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

		foreach ( $this->ticket_category_models() as $model ) {
			try {
				$records = $this->api->call(
					$model,
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

				if ( ! empty( $records[0]['id'] ) ) {
					return (int) $records[0]['id'];
				}
			} catch ( Exception $e ) {
				continue;
			}
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
