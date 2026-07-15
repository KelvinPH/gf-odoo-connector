<?php
/**
 * Odoo CRM operations (contacts and leads).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates CRM records in Odoo.
 */
class CRM_Handler {

	/**
	 * res.partner fields accepted by this handler.
	 */
	public const PARTNER_FIELDS = array(
		'name',
		'email',
		'phone',
		'mobile',
		'street',
		'city',
		'zip',
		'country_id',
		'comment',
		'company_name',
	);

	/**
	 * crm.lead fields accepted by this handler.
	 */
	public const LEAD_FIELDS = array(
		'name',
		'type',
		'contact_name',
		'first_name',
		'last_name',
		'partner_name',
		'partner_id',
		'email_from',
		'phone',
		'description',
		'tag_ids',
		'user_id',
		'team_id',
		'priority',
		'industry_id',
		'sub_industry_id',
		'source_id',
		'sub_lead_source_id',
	);

	/**
	 * @var Odoo_API
	 */
	private $api;

	/**
	 * Cached default sales team for leads (0 = lookup done, none found).
	 *
	 * @var int|null
	 */
	private $default_leads_team_id;

	/**
	 * @param Odoo_API $api Authenticated API client.
	 */
	public function __construct( Odoo_API $api ) {
		$this->api = $api;
	}

	/**
	 * Find a contact by exact email address.
	 *
	 * @param string $email Email address.
	 *
	 * @return int|null Partner ID or null when not found.
	 */
	public function find_partner_by_email( string $email ): ?int {
		$email = sanitize_email( trim( $email ) );

		if ( '' === $email ) {
			return null;
		}

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

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return null;
		}

		return (int) $ids[0];
	}

	/**
	 * Create or update a res.partner contact.
	 *
	 * @param array $data Odoo partner field values.
	 *
	 * @return int Partner ID.
	 *
	 * @throws InvalidArgumentException When required fields are missing.
	 * @throws RuntimeException         When the Odoo API call fails.
	 */
	public function create_or_update_contact( array $data ): int {
		$data = $this->filter_fields( $data, self::PARTNER_FIELDS );

		if ( empty( $data['name'] ) ) {
			throw new InvalidArgumentException(
				__( 'Contact name is required to create an Odoo partner.', 'gf-odoo-connector' )
			);
		}

		$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';

		if ( '' !== $email ) {
			$data['email'] = $email;
			$partner_id    = $this->find_partner_by_email( $email );

			if ( null !== $partner_id ) {
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

				return $partner_id;
			}
		}

		return $this->extract_record_id(
			$this->api->call( 'res.partner', 'create', array( $data ) )
		);
	}

	/**
	 * Create a crm.lead linked to an existing res.partner.
	 *
	 * Values are normalized via prepare_lead_values() (type=lead, team, title fallback).
	 *
	 * @param int   $partner_id res.partner ID from create_or_update_contact().
	 * @param array $data       Mapped lead fields (name, description, source_id, etc.).
	 * @param array $context    Optional keys: partner (array), form_title (string), feed_meta (array).
	 *
	 * @return int Created crm.lead ID.
	 *
	 * @throws InvalidArgumentException When required fields are missing.
	 * @throws RuntimeException         When the Odoo API call fails.
	 */
	public function create_lead( int $partner_id, array $data, array $context = array() ): int {
		$data = $this->prepare_lead_values( $partner_id, $data, $context );

		$lead_id = $this->extract_record_id(
			$this->api->call( 'crm.lead', 'create', array( $data ) )
		);

		$this->verify_lead_record( $lead_id, $partner_id );

		return $lead_id;
	}

	/**
	 * Build crm.lead values for Odoo CRM → Leads (type=lead), not Pipeline opportunities.
	 *
	 * @param int   $partner_id res.partner ID.
	 * @param array $data       Mapped lead fields from the feed.
	 * @param array $context    Optional: partner (array), form_title (string).
	 *
	 * @return array
	 */
	public function prepare_lead_values( int $partner_id, array $data, array $context = array() ): array {
		$partner      = (array) rgar( $context, 'partner', array() );
		$form_title   = trim( (string) rgar( $context, 'form_title', '' ) );

		// Smart routing tags (names) are resolved to crm.tag IDs below; not a real lead field.
		$smart_tag_names = array();
		if ( ! empty( $data['smart_tag_names'] ) && is_array( $data['smart_tag_names'] ) ) {
			$smart_tag_names = $data['smart_tag_names'];
		}
		unset( $data['smart_tag_names'] );

		if ( ! empty( $data['source_id'] ) && ! is_numeric( $data['source_id'] ) ) {
			$data['source_id'] = $this->find_or_create_source( (string) $data['source_id'] );
		}

		if ( ! empty( $data['industry_id'] ) && ! is_numeric( $data['industry_id'] ) ) {
			$resolved = $this->find_record_id_by_name( 'res.partner.industry', (string) $data['industry_id'] );
			if ( null !== $resolved ) {
				$data['industry_id'] = $resolved;
			} else {
				unset( $data['industry_id'] );
			}
		}

		$data = $this->filter_fields( $data, self::LEAD_FIELDS );
		$contact_name = trim( (string) ( $data['contact_name'] ?? rgar( $partner, 'name', '' ) ) );

		if ( '' !== $contact_name ) {
			$data['contact_name'] = $contact_name;
		}

		if ( ! empty( $partner['company_name'] ) && empty( $data['partner_name'] ) ) {
			$data['partner_name'] = $partner['company_name'];
		}

		$data['type']  = 'lead';
		$feed_meta     = (array) rgar( $context, 'feed_meta', array() );
		$crm_user_id   = (int) rgar( $feed_meta, 'crm_user_id' );
		$crm_team_id   = (int) rgar( $feed_meta, 'crm_team_id' );

		if ( $crm_user_id > 0 ) {
			$data['user_id'] = $crm_user_id;
		} elseif ( ! array_key_exists( 'user_id', $data ) ) {
			$data['user_id'] = false;
		}

		if ( $crm_team_id > 0 ) {
			$data['team_id'] = $crm_team_id;
		} elseif ( $crm_user_id > 0 ) {
			$user_team_id = $this->get_salesperson_team_id( $crm_user_id );
			if ( null !== $user_team_id ) {
				$data['team_id'] = $user_team_id;
			}
		}

		if ( empty( $data['team_id'] ) ) {
			$team_id = $this->get_default_leads_team_id();
			if ( null !== $team_id ) {
				$data['team_id'] = $team_id;
			}
		}

		$lead_title = trim( (string) rgar( $data, 'name' ) );

		if ( '' === $lead_title || ( '' !== $contact_name && $lead_title === $contact_name ) ) {
			if ( '' !== $form_title && '' !== $contact_name ) {
				$lead_title = sprintf( '%s: %s', $form_title, $contact_name );
			} elseif ( '' !== $form_title ) {
				$lead_title = $form_title;
			} elseif ( '' !== $contact_name ) {
				$lead_title = sprintf(
					/* translators: %s: contact name */
					__( 'Inquiry from %s', 'gf-odoo-connector' ),
					$contact_name
				);
			}
		}

		if ( '' === $lead_title ) {
			throw new InvalidArgumentException(
				__( 'Lead title is required to create an Odoo CRM lead.', 'gf-odoo-connector' )
			);
		}

		$data['name']       = $lead_title;
		$data['partner_id'] = $partner_id;

		if ( ! empty( $smart_tag_names ) ) {
			$tag_ids = array();

			foreach ( $smart_tag_names as $tag_name ) {
				$tag_id = $this->find_or_create_tag( (string) $tag_name );

				if ( $tag_id > 0 ) {
					$tag_ids[] = $tag_id;
				}
			}

			if ( ! empty( $tag_ids ) ) {
				$data['tag_ids'] = array( array( 6, 0, array_values( array_unique( $tag_ids ) ) ) );
			}
		}

		return $data;
	}

	/**
	 * Resolve a CRM tag name to its ID, creating the tag when missing.
	 *
	 * @param string $name Tag name.
	 *
	 * @return int Tag ID, or 0 on failure.
	 */
	public function find_or_create_tag( string $name ): int {
		$name = trim( $name );

		if ( '' === $name ) {
			return 0;
		}

		$cache_key = 'gf_odoo_crmtag_' . md5( strtolower( $name ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		try {
			$ids = $this->api->call(
				'crm.tag',
				'search',
				array(
					array(
						array( 'name', '=', $name ),
					),
				),
				array(
					'limit' => 1,
				)
			);

			if ( ! empty( $ids[0] ) ) {
				$id = (int) $ids[0];
				set_transient( $cache_key, $id, HOUR_IN_SECONDS );
				return $id;
			}

			$id = $this->extract_record_id(
				$this->api->call( 'crm.tag', 'create', array( array( 'name' => $name ) ) )
			);

			if ( $id > 0 ) {
				set_transient( $cache_key, $id, HOUR_IN_SECONDS );
			}

			return $id;
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/**
	 * First sales team with Leads enabled (required for CRM → Leads visibility).
	 *
	 * @return int|null Team ID or null when none configured.
	 */
	public function get_default_leads_team_id(): ?int {
		if ( null !== $this->default_leads_team_id ) {
			return $this->default_leads_team_id > 0 ? $this->default_leads_team_id : null;
		}

		$this->default_leads_team_id = 0;

		try {
			$team_ids = $this->api->call(
				'crm.team',
				'search',
				array(
					array(
						array( 'use_leads', '=', true ),
					),
				),
				array(
					'limit' => 1,
					'order' => 'sequence asc, id asc',
				)
			);
		} catch ( Exception $e ) {
			return null;
		}

		if ( ! empty( $team_ids[0] ) ) {
			$this->default_leads_team_id = (int) $team_ids[0];
			return $this->default_leads_team_id;
		}

		return null;
	}

	/**
	 * Read back the lead to ensure Odoo created a visible CRM lead.
	 *
	 * @param int $lead_id    Lead ID returned by create().
	 * @param int $partner_id Expected linked partner.
	 *
	 * @throws RuntimeException When the record is missing or not linked.
	 */
	private function verify_lead_record( int $lead_id, int $partner_id ): void {
		try {
			$records = $this->api->call(
				'crm.lead',
				'read',
				array(
					array( $lead_id ),
				),
				array(
					// Omit user_id/stage_id; reading them can require res.users / crm.stage access the API user may lack.
					'fields' => array( 'id', 'name', 'type', 'active', 'partner_id', 'team_id' ),
				)
			);
		} catch ( RuntimeException $e ) {
			if ( Odoo_API::is_access_denied_message( $e->getMessage() ) ) {
				// Lead was created; record rules may block the API user from reading it back.
				return;
			}
			throw $e;
		}

		if ( empty( $records[0] ) || ! is_array( $records[0] ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %d: Odoo lead ID */
					__( 'Odoo reported lead #%d but the record could not be loaded.', 'gf-odoo-connector' ),
					$lead_id
				)
			);
		}

		$lead = $records[0];

		if ( empty( $lead['active'] ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %d: Odoo lead ID */
					__( 'Odoo lead #%d was created but is inactive.', 'gf-odoo-connector' ),
					$lead_id
				)
			);
		}

		$fixes = array();

		if ( 'lead' !== (string) rgar( $lead, 'type' ) ) {
			$fixes['type'] = 'lead';
		}

		$linked_partner = is_array( $lead['partner_id'] ) ? (int) ( $lead['partner_id'][0] ?? 0 ) : (int) $lead['partner_id'];

		if ( $partner_id > 0 && $linked_partner !== $partner_id ) {
			$fixes['partner_id'] = $partner_id;
		}

		if ( empty( $lead['team_id'] ) ) {
			$team_id = $this->get_default_leads_team_id();
			if ( null !== $team_id ) {
				$fixes['team_id'] = $team_id;
			}
		}

		if ( ! empty( $fixes ) ) {
			try {
				$this->api->call(
					'crm.lead',
					'write',
					array(
						array( $lead_id ),
						$fixes,
					)
				);
			} catch ( RuntimeException $e ) {
				if ( Odoo_API::is_access_denied_message( $e->getMessage() ) ) {
					return;
				}
				throw $e;
			}
		}
	}

	/**
	 * Fetch a lead summary for logging / entry notes.
	 *
	 * @param int $lead_id Lead ID.
	 *
	 * @return array{name: string, type: string}|null
	 */
	public function get_lead_summary( int $lead_id ): ?array {
		try {
			$records = $this->api->call(
				'crm.lead',
				'read',
				array(
					array( $lead_id ),
				),
				array(
					'fields' => array( 'name', 'type' ),
				)
			);
		} catch ( RuntimeException $e ) {
			if ( Odoo_API::is_access_denied_message( $e->getMessage() ) ) {
				return null;
			}
			throw $e;
		}

		if ( empty( $records[0] ) || ! is_array( $records[0] ) ) {
			return null;
		}

		return array(
			'name' => (string) ( $records[0]['name'] ?? '' ),
			'type' => (string) ( $records[0]['type'] ?? '' ),
		);
	}

	/**
	 * Industry options from res.partner.industry.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_industries(): array {
		try {
			$records = $this->api->call(
				'res.partner.industry',
				'search_read',
				array( array() ),
				array(
					'fields' => array( 'id', 'name' ),
					'limit'  => 500,
					'order'  => 'name asc',
				)
			);

			return $this->format_choice_list( $records );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Sub-industry options from sub.industry (optionally filtered by parent industry).
	 *
	 * @param int $industry_id Parent industry ID (0 = all).
	 *
	 * @return array<int, array{value: int, label: string, parent_ids: array<int>}>
	 */
	public function get_sub_industries( int $industry_id = 0 ): array {
		$domain = array();

		if ( $industry_id > 0 ) {
			$domain = array(
				array(
					array( 'industry_ids', 'in', array( $industry_id ) ),
				),
			);
		}

		try {
			$records = $this->api->call(
				'sub.industry',
				'search_read',
				array( $domain ),
				array(
					'fields' => array( 'id', 'name', 'industry_ids' ),
					'limit'  => 500,
					'order'  => 'name asc',
				)
			);

			return $this->format_choice_list_with_parents( $records, 'industry_ids' );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * UTM source options from utm.source.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_sources(): array {
		try {
			$records = $this->api->call(
				'utm.source',
				'search_read',
				array( array() ),
				array(
					'fields' => array( 'id', 'name' ),
					'limit'  => 500,
					'order'  => 'name asc',
				)
			);

			return $this->format_choice_list( $records );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Sub lead source options from sub.lead.source (optionally filtered by UTM source).
	 *
	 * @param int $source_id Parent utm.source ID (0 = all).
	 *
	 * @return array<int, array{value: int, label: string, parent_ids: array<int>}>
	 */
	public function get_sub_lead_sources( int $source_id = 0 ): array {
		$domain = array();

		if ( $source_id > 0 ) {
			$domain = array(
				array(
					array( 'utm_source_ids', 'in', array( $source_id ) ),
				),
			);
		}

		try {
			$records = $this->api->call(
				'sub.lead.source',
				'search_read',
				array( $domain ),
				array(
					'fields' => array( 'id', 'name', 'utm_source_ids' ),
					'limit'  => 500,
					'order'  => 'name asc',
				)
			);

			return $this->format_choice_list_with_parents( $records, 'utm_source_ids' );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Find or create a utm.source record for a page URL.
	 *
	 * @param string $url Page URL (used as utm.source name).
	 *
	 * @return int utm.source ID.
	 */
	public function find_or_create_source( string $url ): int {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			throw new InvalidArgumentException(
				__( 'Page URL is required to create an Odoo source.', 'gf-odoo-connector' )
			);
		}

		$cache_key = 'gf_odoo_source_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$existing = $this->api->call(
			'utm.source',
			'search_read',
			array(
				array(
					array( 'name', '=', $url ),
				),
			),
			array(
				'fields' => array( 'id' ),
				'limit'  => 1,
			)
		);

		if ( ! empty( $existing[0]['id'] ) ) {
			$id = (int) $existing[0]['id'];
			set_transient( $cache_key, $id, HOUR_IN_SECONDS );
			return $id;
		}

		$id = $this->extract_record_id(
			$this->api->call(
				'utm.source',
				'create',
				array(
					array( 'name' => $url ),
				)
			)
		);

		set_transient( $cache_key, $id, HOUR_IN_SECONDS );

		return $id;
	}

	/**
	 * Resolve a many2one display name or numeric ID to an Odoo record ID.
	 *
	 * @param string $model Odoo model technical name (e.g. res.partner.industry).
	 * @param string $name  Record display name or numeric ID string.
	 *
	 * @return int|null Record ID, or null when not found or on API error.
	 */
	public function find_record_id_by_name( string $model, string $name ): ?int {
		$name = trim( $name );

		if ( '' === $name ) {
			return null;
		}

		if ( is_numeric( $name ) ) {
			return (int) $name;
		}

		try {
			$ids = $this->api->call(
				$model,
				'search',
				array(
					array(
						array( 'name', '=', $name ),
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
	 * Get CRM sales teams that accept leads (for admin dropdowns).
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function get_sales_teams(): array {
		try {
			$teams = $this->api->call(
				'crm.team',
				'search_read',
				array(
					array(
						array( 'use_leads', '=', true ),
					),
				),
				array(
					'fields' => array( 'id', 'name' ),
					'limit'  => 200,
					'order'  => 'sequence asc, name asc',
				)
			);

			$choices = $this->format_choice_list( $teams );

			if ( ! empty( $choices ) ) {
				return $choices;
			}
		} catch ( Exception $e ) {
			// Fall through to unfiltered list.
		}

		try {
			$teams = $this->api->call(
				'crm.team',
				'search_read',
				array( array() ),
				array(
					'fields' => array( 'id', 'name' ),
					'limit'  => 200,
					'order'  => 'sequence asc, name asc',
				)
			);

			return $this->format_choice_list( $teams );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Get internal users (salespeople) for admin dropdowns.
	 *
	 * @return array<int, array{value: int, label: string, team_id: int}>
	 */
	public function get_salespeople(): array {
		$users = $this->api->call(
			'res.users',
			'search_read',
			array(
				array(
					array( 'share', '=', false ),
				),
			),
			array(
				'fields' => array( 'id', 'name', 'sale_team_id' ),
				'limit'  => 200,
				'order'  => 'name asc',
			)
		);

		if ( ! is_array( $users ) ) {
			return array();
		}

		$choices = array();

		foreach ( $users as $user ) {
			if ( ! is_array( $user ) || empty( $user['id'] ) ) {
				continue;
			}

			$team_id = 0;
			if ( ! empty( $user['sale_team_id'] ) ) {
				$team_id = is_array( $user['sale_team_id'] )
					? (int) ( $user['sale_team_id'][0] ?? 0 )
					: (int) $user['sale_team_id'];
			}

			$choices[] = array(
				'value'   => (int) $user['id'],
				'label'   => (string) ( $user['name'] ?? $user['id'] ),
				'team_id' => $team_id,
			);
		}

		return $choices;
	}

	/**
	 * Map salesperson user ID → default crm.team ID (sale_team_id).
	 *
	 * @return array<int, int>
	 */
	public function get_salesperson_team_map(): array {
		$map = array();

		foreach ( $this->get_salespeople() as $user ) {
			if ( ! empty( $user['team_id'] ) ) {
				$map[ (int) $user['value'] ] = (int) $user['team_id'];
			}
		}

		return $map;
	}

	/**
	 * Read a salesperson's default crm.team from res.users.sale_team_id.
	 *
	 * Uses a single-user read to avoid broad res.users search permissions.
	 *
	 * @param int $user_id res.users ID from the feed salesperson setting.
	 *
	 * @return int|null crm.team ID, or null when unavailable or access denied.
	 */
	public function get_salesperson_team_id( int $user_id ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}

		try {
			$users = $this->api->call(
				'res.users',
				'read',
				array(
					array( $user_id ),
				),
				array(
					'fields' => array( 'sale_team_id' ),
				)
			);
		} catch ( RuntimeException $e ) {
			if ( Odoo_API::is_access_denied_message( $e->getMessage() ) ) {
				return null;
			}
			throw $e;
		}

		if ( empty( $users[0] ) || ! is_array( $users[0] ) ) {
			return null;
		}

		$team = $users[0]['sale_team_id'] ?? null;

		if ( is_array( $team ) && ! empty( $team[0] ) ) {
			return (int) $team[0];
		}

		if ( is_numeric( $team ) && (int) $team > 0 ) {
			return (int) $team;
		}

		return null;
	}

	/**
	 * Keep only allowed keys with non-empty scalar values.
	 *
	 * @param array        $data   Input data.
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
	 * Normalize Odoo create() return value to a single record ID.
	 *
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
	 * @param mixed $records search_read result.
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
	 * @param mixed  $records   search_read result.
	 * @param string $parent_key Odoo many2many field linking to parent records.
	 *
	 * @return array<int, array{value: int, label: string, parent_ids: array<int>}>
	 */
	private function format_choice_list_with_parents( $records, string $parent_key ): array {
		if ( ! is_array( $records ) ) {
			return array();
		}

		$choices = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['id'] ) ) {
				continue;
			}

			$parent_ids = array();
			if ( ! empty( $record[ $parent_key ] ) && is_array( $record[ $parent_key ] ) ) {
				$parent_ids = array_map( 'intval', $record[ $parent_key ] );
			}

			$choices[] = array(
				'value'      => (int) $record['id'],
				'label'      => (string) ( $record['name'] ?? $record['id'] ),
				'parent_ids' => $parent_ids,
			);
		}

		return $choices;
	}
}
