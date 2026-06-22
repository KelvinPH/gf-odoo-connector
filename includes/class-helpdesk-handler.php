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
		$row   = Helpdesk_Field_Config::get_row( 'ticket_inquiry_category' );
		$model = (string) rgar( $row, 'odoo_model', 'helpdesk.ticket.type' );

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
		$data    = $this->filter_fields( $data, $allowed );

		if ( empty( $data['name'] ) ) {
			throw new InvalidArgumentException(
				__( 'Ticket subject is required. Configure Ticket subject in the feed settings.', 'gf-odoo-connector' )
			);
		}

		if ( empty( $data['description'] ) ) {
			$data['description'] = '<p></p>';
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

		if ( ! empty( $data['description'] ) && is_string( $data['description'] ) ) {
			$data['description'] = $this->format_html_description( $data['description'] );
		}

		if ( array_key_exists( 'under_warranty', $data ) ) {
			$data['under_warranty'] = (bool) $data['under_warranty'];
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
