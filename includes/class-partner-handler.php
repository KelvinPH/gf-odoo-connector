<?php
/**
 * Odoo res.partner Company + Person hierarchy.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates company and person contacts in Odoo.
 */
class Partner_Handler {

	/**
	 * res.partner fields for company records.
	 */
	public const COMPANY_FIELDS = array(
		'name',
		'street',
		'city',
		'zip',
		'country_id',
		'state_id',
		'industry_id',
		'sub_industry_id',
		'source_id',
		'sub_lead_source_id',
		'is_company',
		'company_type',
	);

	/**
	 * res.partner fields for person records.
	 */
	public const PERSON_FIELDS = array(
		'name',
		'first_name',
		'last_name',
		'email',
		'phone',
		'mobile',
		'parent_id',
		'is_company',
		'company_type',
	);

	/**
	 * @var Odoo_API
	 */
	private $api;

	/**
	 * @var CRM_Handler|null
	 */
	private $crm;

	/**
	 * @param Odoo_API         $api Authenticated API client.
	 * @param CRM_Handler|null $crm Optional CRM handler for lookups and resolution.
	 */
	public function __construct( Odoo_API $api, ?CRM_Handler $crm = null ) {
		$this->api = $api;
		$this->crm = $crm;
	}

	/**
	 * Split mapped contact + lead values into company and person payloads.
	 *
	 * @param array $contact Mapped contact block (CRM or Helpdesk shape).
	 * @param array $lead    Mapped CRM lead block (optional).
	 * @param array $extra   Optional extra keys (e.g. lead classification already merged).
	 *
	 * @return array{company: array, person: array, company_name: string}
	 */
	public function split_mapped_contact( array $contact, array $lead = array(), array $extra = array() ): array {
		$normalized = $this->normalize_contact_input( $contact, $lead, $extra );

		$company_name = trim( (string) ( $normalized['company_name'] ?? '' ) );

		$company = array();
		$person  = array();

		if ( '' !== $company_name ) {
			$company['name'] = $company_name;
		}

		foreach ( array( 'street', 'city', 'zip', 'country_id', 'state_id' ) as $field ) {
			if ( ! empty( $normalized[ $field ] ) ) {
				$company[ $field ] = $normalized[ $field ];
			}
		}

		foreach ( array( 'industry_id', 'sub_industry_id', 'source_id', 'sub_lead_source_id' ) as $field ) {
			$value = $normalized[ $field ] ?? $lead[ $field ] ?? $extra[ $field ] ?? null;
			if ( null !== $value && '' !== $value ) {
				$company[ $field ] = $value;
			}
		}

		if ( ! empty( $normalized['name'] ) ) {
			$person['name'] = trim( (string) $normalized['name'] );
		}

		$first_name = ! empty( $lead['first_name'] ) ? trim( (string) $lead['first_name'] ) : '';
		$last_name  = ! empty( $lead['last_name'] ) ? trim( (string) $lead['last_name'] ) : '';

		if ( empty( $first_name ) && ! empty( $normalized['first_name'] ) ) {
			$first_name = trim( (string) $normalized['first_name'] );
		}

		if ( empty( $last_name ) && ! empty( $normalized['last_name'] ) ) {
			$last_name = trim( (string) $normalized['last_name'] );
		}

		if ( empty( $person['name'] ) ) {
			$parts = array_filter(
				array(
					$first_name,
					$last_name,
				)
			);
			if ( ! empty( $parts ) ) {
				$person['name'] = implode( ' ', $parts );
			}
		}

		if ( '' === $first_name && '' === $last_name && ! empty( $person['name'] ) ) {
			$split_name = self::split_display_name( (string) $person['name'] );
			$first_name = $split_name['first'];
			$last_name  = $split_name['last'];
		}

		if ( '' !== $first_name ) {
			$person['_first_name'] = $first_name;
			$person['first_name']  = $first_name;
		}

		if ( '' !== $last_name ) {
			$person['_last_name'] = $last_name;
			$person['last_name']  = $last_name;
		}

		foreach ( array( 'email', 'phone', 'mobile' ) as $field ) {
			if ( ! empty( $normalized[ $field ] ) ) {
				$person[ $field ] = $normalized[ $field ];
			}
		}

		if ( empty( $person['name'] ) && ! empty( $person['email'] ) ) {
			$person['name'] = (string) $person['email'];
		}

		return array(
			'company'      => $company,
			'person'       => $person,
			'company_name' => $company_name,
		);
	}

	/**
	 * Create or update company + person; returns both Odoo IDs.
	 *
	 * @param array $contact Mapped contact block.
	 * @param array $lead    Mapped CRM lead block (optional).
	 * @param array $extra   Optional extra field values.
	 *
	 * @return array{company_id: int, person_id: int, company_name: string}
	 *
	 * @throws InvalidArgumentException When person name is missing.
	 * @throws RuntimeException         When the Odoo API call fails.
	 */
	public function create_company_and_person( array $contact, array $lead = array(), array $extra = array() ): array {
		$split = $this->split_mapped_contact( $contact, $lead, $extra );

		$company_id   = 0;
		$company_name = $split['company_name'];

		if ( ! empty( $split['company']['name'] ) ) {
			$company_data = $this->resolve_company_fields( $split['company'] );
			$company_id   = $this->create_or_update_company( $company_data );
			$company_name = (string) $company_data['name'];
		}

		$person_data = $split['person'];

		if ( empty( $person_data['name'] ) ) {
			throw new InvalidArgumentException(
				__( 'Contact name is required to create an Odoo partner.', 'gf-odoo-connector' )
			);
		}

		$person_id = $this->create_or_update_person( $person_data, $company_id > 0 ? $company_id : null );

		return array(
			'company_id'   => $company_id,
			'person_id'    => $person_id,
			'company_name' => $company_name,
		);
	}

	/**
	 * Find a company partner by exact name.
	 *
	 * @param string $name Company name.
	 *
	 * @return int|null Partner ID or null when not found.
	 */
	public function find_company_by_name( string $name ): ?int {
		$name = trim( $name );

		if ( '' === $name ) {
			return null;
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
			return null;
		}

		return null;
	}

	/**
	 * Find a person partner by exact email (non-company records only).
	 *
	 * @param string $email Email address.
	 *
	 * @return int|null Partner ID or null when not found.
	 */
	public function find_person_by_email( string $email ): ?int {
		$email = sanitize_email( trim( $email ) );

		if ( '' === $email ) {
			return null;
		}

		try {
			$ids = $this->api->call(
				'res.partner',
				'search',
				array(
					array(
						array( 'email', '=', $email ),
						array( 'is_company', '=', false ),
					),
				),
				array(
					'limit' => 1,
				)
			);

			if ( ! empty( $ids[0] ) ) {
				return (int) $ids[0];
			}
		} catch ( Exception $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Create or update a company res.partner.
	 *
	 * @param array $data Company field values.
	 *
	 * @return int Company partner ID.
	 *
	 * @throws InvalidArgumentException When company name is missing.
	 * @throws RuntimeException         When the Odoo API call fails.
	 */
	public function create_or_update_company( array $data ): int {
		$data = $this->filter_fields( $data, self::COMPANY_FIELDS );

		if ( empty( $data['name'] ) ) {
			throw new InvalidArgumentException(
				__( 'Company name is required to create an Odoo company contact.', 'gf-odoo-connector' )
			);
		}

		$data['is_company']   = true;
		$data['company_type'] = 'company';

		$company_id = $this->find_company_by_name( (string) $data['name'] );

		if ( null !== $company_id ) {
			$this->write_partner( $company_id, $data );

			return $company_id;
		}

		return $this->extract_record_id(
			$this->api->call( 'res.partner', 'create', array( $data ) )
		);
	}

	/**
	 * Create or update a person res.partner, optionally linked to a company.
	 *
	 * @param array    $data       Person field values.
	 * @param int|null $company_id Parent company partner ID.
	 *
	 * @return int Person partner ID.
	 *
	 * @throws InvalidArgumentException When person name is missing.
	 * @throws RuntimeException         When the Odoo API call fails.
	 */
	public function create_or_update_person( array $data, ?int $company_id = null ): int {
		$first_name = trim( (string) ( $data['_first_name'] ?? $data['first_name'] ?? '' ) );
		$last_name  = trim( (string) ( $data['_last_name'] ?? $data['last_name'] ?? '' ) );
		unset( $data['_first_name'], $data['_last_name'] );

		$data = $this->filter_fields( $data, self::PERSON_FIELDS );

		if ( empty( $data['name'] ) ) {
			$parts = array_filter( array( $first_name, $last_name ) );
			if ( ! empty( $parts ) ) {
				$data['name'] = implode( ' ', $parts );
			}
		}

		if ( empty( $data['name'] ) ) {
			throw new InvalidArgumentException(
				__( 'Contact name is required to create an Odoo partner.', 'gf-odoo-connector' )
			);
		}

		// Always force person type — never rely on Odoo defaults.
		$data['is_company']   = false;
		$data['company_type'] = 'person';

		if ( '' !== $first_name ) {
			$data['first_name'] = $first_name;
		}

		if ( '' !== $last_name ) {
			$data['last_name'] = $last_name;
		}

		if ( null !== $company_id && $company_id > 0 ) {
			$data['parent_id'] = $company_id;
		}

		$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';

		if ( '' !== $email ) {
			$data['email'] = $email;
			$partner_id    = $this->find_person_by_email( $email );

			// Also reclaim a same-email contact that was incorrectly stored as a company.
			if ( null === $partner_id ) {
				$partner_id = $this->find_partner_by_email_any( $email );
			}

			if ( null !== $partner_id ) {
				$this->write_partner( $partner_id, $data );
				$this->ensure_person_name_parts( $partner_id, $first_name, $last_name );

				return $partner_id;
			}
		}

		$partner_id = $this->extract_record_id(
			$this->api->call( 'res.partner', 'create', array( $data ) )
		);

		$this->ensure_person_name_parts( $partner_id, $first_name, $last_name );

		return $partner_id;
	}

	/**
	 * Find any res.partner by email (person or company).
	 *
	 * @param string $email Email address.
	 *
	 * @return int|null
	 */
	public function find_partner_by_email_any( string $email ): ?int {
		$email = sanitize_email( trim( $email ) );

		if ( '' === $email ) {
			return null;
		}

		try {
			$ids = $this->api->call(
				'res.partner',
				'search',
				array(
					array(
						array( 'email', '=', $email ),
					),
				),
				array(
					'limit' => 1,
				)
			);

			if ( ! empty( $ids[0] ) ) {
				return (int) $ids[0];
			}
		} catch ( Exception $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Ensure first/last name (and person type) are actually stored after create/update.
	 *
	 * @param int    $partner_id Person ID.
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 */
	private function ensure_person_name_parts( int $partner_id, string $first_name, string $last_name ): void {
		if ( $partner_id <= 0 ) {
			return;
		}

		$first_name = trim( $first_name );
		$last_name  = trim( $last_name );

		try {
			$records = $this->api->call(
				'res.partner',
				'read',
				array( array( $partner_id ) ),
				array(
					'fields' => array( 'first_name', 'last_name', 'is_company', 'company_type', 'name' ),
				)
			);
		} catch ( Exception $e ) {
			$this->write_person_name_parts( $partner_id, $first_name, $last_name );
			return;
		}

		$record = (array) ( $records[0] ?? array() );
		$fixes  = array();

		if ( ! empty( $record['is_company'] ) || 'company' === (string) ( $record['company_type'] ?? '' ) ) {
			$fixes['is_company']   = false;
			$fixes['company_type'] = 'person';
		}

		$stored_first = trim( (string) ( $record['first_name'] ?? '' ) );
		$stored_last  = trim( (string) ( $record['last_name'] ?? '' ) );

		if ( '' !== $first_name && $stored_first !== $first_name ) {
			$fixes['first_name'] = $first_name;
		}

		if ( '' !== $last_name && $stored_last !== $last_name ) {
			$fixes['last_name'] = $last_name;
		}

		if ( empty( $fixes ) ) {
			return;
		}

		try {
			$this->api->call(
				'res.partner',
				'write',
				array(
					array( $partner_id ),
					$fixes,
				)
			);
		} catch ( RuntimeException $e ) {
			if ( preg_match( "/Invalid field ['\"]([^'\"]+)['\"]/", $e->getMessage() ) ) {
				$this->write_person_name_parts( $partner_id, $first_name, $last_name );
				return;
			}
			throw $e;
		}
	}

	/**
	 * Write first/last name onto a person using discovered (or probed) field names.
	 *
	 * Kept separate from create/update so invalid name-part fields never break contact creation.
	 *
	 * @param int    $partner_id Person partner ID.
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 */
	public function write_person_name_parts( int $partner_id, string $first_name, string $last_name ): void {
		$first_name = trim( $first_name );
		$last_name  = trim( $last_name );

		if ( $partner_id <= 0 || ( '' === $first_name && '' === $last_name ) ) {
			return;
		}

		$fields = $this->resolve_partner_name_fields();
		$payload = array();

		if ( '' !== $first_name && ! empty( $fields['first'] ) ) {
			$payload[ $fields['first'] ] = $first_name;
		}

		if ( '' !== $last_name && ! empty( $fields['last'] ) ) {
			$payload[ $fields['last'] ] = $last_name;
		}

		if ( ! empty( $payload ) ) {
			try {
				$this->api->call(
					'res.partner',
					'write',
					array(
						array( $partner_id ),
						$payload,
					)
				);
				return;
			} catch ( RuntimeException $e ) {
				if ( ! preg_match( "/Invalid field ['\"]([^'\"]+)['\"]/", $e->getMessage(), $m ) ) {
					throw $e;
				}

				delete_transient( 'gf_odoo_partner_name_fields' );
			}
		}

		$this->probe_and_write_person_name_parts( $partner_id, $first_name, $last_name );
	}

	/**
	 * Try common / discovered first+last field pairs until one write succeeds.
	 *
	 * @param int    $partner_id Person ID.
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 */
	private function probe_and_write_person_name_parts( int $partner_id, string $first_name, string $last_name ): void {
		$pairs = $this->get_partner_name_field_candidate_pairs();

		foreach ( $pairs as $pair ) {
			$payload = array();

			if ( '' !== $first_name && ! empty( $pair['first'] ) ) {
				$payload[ $pair['first'] ] = $first_name;
			}

			if ( '' !== $last_name && ! empty( $pair['last'] ) ) {
				$payload[ $pair['last'] ] = $last_name;
			}

			if ( empty( $payload ) ) {
				continue;
			}

			try {
				$this->api->call(
					'res.partner',
					'write',
					array(
						array( $partner_id ),
						$payload,
					)
				);

				set_transient(
					'gf_odoo_partner_name_fields',
					array(
						'first' => $pair['first'] ?? null,
						'last'  => $pair['last'] ?? null,
					),
					WEEK_IN_SECONDS
				);

				return;
			} catch ( RuntimeException $e ) {
				if ( preg_match( "/Invalid field ['\"]([^'\"]+)['\"]/", $e->getMessage() ) ) {
					continue;
				}
				throw $e;
			}
		}
	}

	/**
	 * Candidate first/last field name pairs for this Odoo instance.
	 *
	 * @return array<int, array{first?: string, last?: string}>
	 */
	private function get_partner_name_field_candidate_pairs(): array {
		$pairs = array();

		$discovered = $this->resolve_partner_name_fields( false );
		if ( ! empty( $discovered['first'] ) || ! empty( $discovered['last'] ) ) {
			$pairs[] = array(
				'first' => $discovered['first'] ?? null,
				'last'  => $discovered['last'] ?? null,
			);
		}

		$static = array(
			array( 'first' => 'first_name', 'last' => 'last_name' ),
			array( 'first' => 'x_first_name', 'last' => 'x_last_name' ),
			array( 'first' => 'x_studio_first_name', 'last' => 'x_studio_last_name' ),
			array( 'first' => 'x_studio_firstname', 'last' => 'x_studio_lastname' ),
			array( 'first' => 'contact_first_name', 'last' => 'contact_last_name' ),
			array( 'first' => 'partner_first_name', 'last' => 'partner_last_name' ),
			array( 'first' => 'firstname', 'last' => 'lastname' ),
		);

		return array_merge( $pairs, $static );
	}

	/**
	 * Resolve res.partner field names for first/last name via fields_get (cached).
	 *
	 * @param bool $use_cache Whether to return a cached result (including empty).
	 *
	 * @return array{first: string|null, last: string|null}
	 */
	public function resolve_partner_name_fields( bool $use_cache = true ): array {
		$cached = get_transient( 'gf_odoo_partner_name_fields' );

		if ( $use_cache && is_array( $cached ) ) {
			$first = isset( $cached['first'] ) ? ( $cached['first'] ?: null ) : null;
			$last  = isset( $cached['last'] ) ? ( $cached['last'] ?: null ) : null;

			// Never trust a long-lived empty cache — fields may exist under custom names.
			if ( null !== $first || null !== $last ) {
				return array(
					'first' => $first,
					'last'  => $last,
				);
			}
		}

		$result = array(
			'first' => null,
			'last'  => null,
		);

		try {
			$fields = $this->api->call(
				'res.partner',
				'fields_get',
				array(),
				array(
					'attributes' => array( 'string', 'type' ),
				)
			);
		} catch ( Exception $e ) {
			return $result;
		}

		if ( ! is_array( $fields ) ) {
			return $result;
		}

		$first_candidates = array();
		$last_candidates  = array();

		foreach ( $fields as $name => $meta ) {
			$meta   = (array) $meta;
			$type   = (string) ( $meta['type'] ?? '' );
			$label  = strtolower( trim( (string) ( $meta['string'] ?? '' ) ) );
			$name_l = strtolower( (string) $name );

			if ( ! in_array( $type, array( 'char', 'text' ), true ) ) {
				continue;
			}

			if ( 'name' === $name_l || 'display_name' === $name_l ) {
				continue;
			}

			$first_score = self::score_partner_name_field( $label, $name_l, 'first' );
			$last_score  = self::score_partner_name_field( $label, $name_l, 'last' );

			if ( $first_score > 0 ) {
				$first_candidates[] = array(
					'name'  => (string) $name,
					'score' => $first_score,
				);
			}

			if ( $last_score > 0 ) {
				$last_candidates[] = array(
					'name'  => (string) $name,
					'score' => $last_score,
				);
			}
		}

		usort(
			$first_candidates,
			static function ( $a, $b ) {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);
		usort(
			$last_candidates,
			static function ( $a, $b ) {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		if ( ! empty( $first_candidates[0]['name'] ) ) {
			$result['first'] = (string) $first_candidates[0]['name'];
		}

		if ( ! empty( $last_candidates[0]['name'] ) ) {
			$result['last'] = (string) $last_candidates[0]['name'];
		}

		if ( null === $result['first'] ) {
			foreach ( array( 'first_name', 'x_first_name', 'x_studio_first_name', 'x_studio_firstname', 'contact_firstname', 'contact_first_name', 'firstname' ) as $candidate ) {
				if ( isset( $fields[ $candidate ] ) && in_array( (string) ( $fields[ $candidate ]['type'] ?? '' ), array( 'char', 'text' ), true ) ) {
					$result['first'] = $candidate;
					break;
				}
			}
		}

		if ( null === $result['last'] ) {
			foreach ( array( 'last_name', 'x_last_name', 'x_studio_last_name', 'x_studio_lastname', 'contact_lastname', 'contact_last_name', 'lastname' ) as $candidate ) {
				if ( isset( $fields[ $candidate ] ) && in_array( (string) ( $fields[ $candidate ]['type'] ?? '' ), array( 'char', 'text' ), true ) ) {
					$result['last'] = $candidate;
					break;
				}
			}
		}

		if ( null !== $result['first'] || null !== $result['last'] ) {
			set_transient( 'gf_odoo_partner_name_fields', $result, WEEK_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Split a display name into first and last parts.
	 *
	 * @param string $name Full contact name.
	 *
	 * @return array{first: string, last: string}
	 */
	public static function split_display_name( string $name ): array {
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );

		if ( '' === $name || str_contains( $name, '@' ) ) {
			return array(
				'first' => '',
				'last'  => '',
			);
		}

		$parts = explode( ' ', $name, 2 );

		return array(
			'first' => trim( (string) ( $parts[0] ?? '' ) ),
			'last'  => trim( (string) ( $parts[1] ?? '' ) ),
		);
	}

	/**
	 * @param string $label  Odoo field label.
	 * @param string $name_l Lowercase technical field name.
	 * @param string $part   first|last.
	 *
	 * @return int
	 */
	private static function score_partner_name_field( string $label, string $name_l, string $part ): int {
		$score = 0;

		if ( 'first' === $part ) {
			if ( 'first name' === $label ) {
				$score = 100;
			} elseif ( str_contains( $label, 'first name' ) ) {
				$score = 90;
			} elseif ( str_ends_with( $name_l, 'first_name' ) || str_ends_with( $name_l, '_firstname' ) ) {
				$score = 80;
			} elseif ( str_contains( $name_l, 'first_name' ) || str_contains( $name_l, 'firstname' ) ) {
				$score = 70;
			} elseif ( str_contains( $label, 'first' ) && ! str_contains( $label, 'last' ) ) {
				$score = 50;
			}
		} else {
			if ( 'last name' === $label ) {
				$score = 100;
			} elseif ( str_contains( $label, 'last name' ) ) {
				$score = 90;
			} elseif ( str_ends_with( $name_l, 'last_name' ) || str_ends_with( $name_l, '_lastname' ) ) {
				$score = 80;
			} elseif ( str_contains( $name_l, 'last_name' ) || str_contains( $name_l, 'lastname' ) ) {
				$score = 70;
			} elseif ( str_contains( $label, 'last' ) || str_contains( $label, 'surname' ) ) {
				$score = 50;
			}
		}

		return $score;
	}

	/**
	 * Normalize CRM and Helpdesk contact shapes into one array.
	 *
	 * @param array $contact Contact block.
	 * @param array $lead    Lead block.
	 * @param array $extra   Extra values.
	 *
	 * @return array<string, mixed>
	 */
	private function normalize_contact_input( array $contact, array $lead, array $extra ): array {
		$out = array_merge( $extra, $contact );

		if ( empty( $out['name'] ) && ! empty( $out['partner_name'] ) ) {
			$out['name'] = $out['partner_name'];
		}

		if ( empty( $out['email'] ) && ! empty( $out['partner_email'] ) ) {
			$out['email'] = $out['partner_email'];
		}

		if ( empty( $out['phone'] ) && ! empty( $out['partner_phone'] ) ) {
			$out['phone'] = $out['partner_phone'];
		}

		if ( empty( $out['company_name'] ) ) {
			$customer = $out['customer_id'] ?? '';

			if ( is_string( $customer ) && '' !== trim( $customer ) && ! is_numeric( $customer ) ) {
				$out['company_name'] = trim( $customer );
			}
		}

		if ( ! empty( $out['email'] ) ) {
			$out['email'] = sanitize_email( (string) $out['email'] );
		}

		return $out;
	}

	/**
	 * Resolve industry/source string values to Odoo IDs for company records.
	 *
	 * @param array $data Company data.
	 *
	 * @return array
	 */
	private function resolve_company_fields( array $data ): array {
		$crm = $this->get_crm();

		if ( ! empty( $data['source_id'] ) && ! is_numeric( $data['source_id'] ) ) {
			$data['source_id'] = $crm->find_or_create_source( (string) $data['source_id'] );
		}

		if ( ! empty( $data['industry_id'] ) && ! is_numeric( $data['industry_id'] ) ) {
			$resolved = $crm->find_record_id_by_name( 'res.partner.industry', (string) $data['industry_id'] );
			if ( null !== $resolved ) {
				$data['industry_id'] = $resolved;
			} else {
				unset( $data['industry_id'] );
			}
		}

		return $data;
	}

	/**
	 * @return CRM_Handler
	 */
	private function get_crm(): CRM_Handler {
		if ( null === $this->crm ) {
			$this->crm = new CRM_Handler( $this->api );
		}

		return $this->crm;
	}

	/**
	 * @param int   $partner_id Partner ID.
	 * @param array $data       Field values.
	 *
	 * @throws RuntimeException When write fails.
	 */
	private function write_partner( int $partner_id, array $data ): void {
		try {
			$this->api->call(
				'res.partner',
				'write',
				array(
					array( $partner_id ),
					$data,
				)
			);
		} catch ( RuntimeException $e ) {
			if ( Odoo_API::is_access_denied_message( $e->getMessage() ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: 1: partner ID, 2: Odoo error */
						__(
							'Odoo denied access when updating existing contact #%1$d. The API user needs write access on Contacts, or use a contact email that is not already owned by another team. Original error: %2$s',
							'gf-odoo-connector'
						),
						$partner_id,
						$e->getMessage()
					),
					0,
					$e
				);
			}
			throw $e;
		}
	}

	/**
	 * Keep only allowed keys with non-empty scalar values.
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
}
