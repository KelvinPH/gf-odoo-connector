<?php
/**
 * Helpdesk per-field mode definitions (single source of truth).
 *
 * Field names below match the InBody Europe helpdesk.ticket form.
 * Run WP_DEBUG → "Debug: Fetch helpdesk.ticket fields" to verify via fields_get.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field row schema for Helpdesk feed settings and mapping.
 */
class Helpdesk_Field_Config {

	/**
	 * All helpdesk ticket field rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows(): array {
		return array(
			// Ticket — visitor-filled.
			array(
				'key'        => 'ticket_subject',
				'label'      => __( 'Ticket subject', 'gf-odoo-connector' ),
				'section'    => 'ticket',
				'odoo_field' => 'name',
				'modes'      => array( 'auto', 'field', 'fixed' ),
				'required'   => true,
				'auto_label' => __( 'Form title', 'gf-odoo-connector' ),
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'ticket_description',
				'label'      => __( 'Issue description', 'gf-odoo-connector' ),
				'section'    => 'ticket',
				'odoo_field' => 'description',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'         => 'ticket_inquiry_category',
				'label'       => __( 'Inquiry category', 'gf-odoo-connector' ),
				'section'     => 'ticket',
				'odoo_field'  => 'ticket_type_id',
				'modes'       => array( 'field', 'fixed', 'off' ),
				'required'    => false,
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_ticket_categories',
				'odoo_model'  => 'helpdesk.ticket.type',
			),
			array(
				'key'         => 'ticket_team',
				'label'       => __( 'Helpdesk team', 'gf-odoo-connector' ),
				'section'     => 'ticket',
				'odoo_field'  => 'team_id',
				'modes'       => array( 'field', 'fixed' ),
				'required'    => true,
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_helpdesk_teams',
				'odoo_model'  => 'helpdesk.team',
			),
			array(
				'key'         => 'ticket_branch',
				'label'       => __( 'Branch', 'gf-odoo-connector' ),
				'section'     => 'ticket',
				'odoo_field'  => 'branch_id',
				'modes'       => array( 'field', 'fixed', 'off' ),
				'required'    => false,
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_branches',
				'odoo_model'  => 'res.branch',
			),
			// Contact — visitor-filled.
			array(
				'key'        => 'contact_name',
				'label'      => __( 'Contact name', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'partner_name',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_email',
				'label'      => __( 'Contact email', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'partner_email',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_phone',
				'label'      => __( 'Phone', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'partner_phone',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'         => 'ticket_state',
				'label'       => __( 'State', 'gf-odoo-connector' ),
				'section'     => 'contact',
				'odoo_field'  => 'state_id',
				'modes'       => array( 'field', 'fixed', 'off' ),
				'required'    => false,
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_states',
				'odoo_model'  => 'res.country.state',
			),
			array(
				'key'         => 'ticket_country',
				'label'       => __( 'Country', 'gf-odoo-connector' ),
				'section'     => 'contact',
				'odoo_field'  => 'country_id',
				'modes'       => array( 'field', 'fixed', 'off' ),
				'required'    => false,
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_countries',
				'odoo_model'  => 'res.country',
				'resolver'    => 'country',
			),
			array(
				'key'        => 'ticket_email_cc',
				'label'      => __( 'Email CC', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'email_cc',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			// Product — visitor-filled.
			array(
				'key'        => 'ticket_serial',
				'label'      => __( 'Serial number', 'gf-odoo-connector' ),
				'section'    => 'product',
				'odoo_field' => 'serial_number',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'ticket_di_number',
				'label'      => __( 'DI number', 'gf-odoo-connector' ),
				'section'    => 'product',
				'odoo_field' => 'di_number',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'ticket_under_warranty',
				'label'      => __( 'Under warranty', 'gf-odoo-connector' ),
				'section'    => 'product',
				'odoo_field' => 'under_warranty',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'boolean',
			),
			array(
				'key'        => 'ticket_installation_date',
				'label'      => __( 'Installation date', 'gf-odoo-connector' ),
				'section'    => 'product',
				'odoo_field' => 'installation_date',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'date',
			),
			array(
				'key'        => 'ticket_manufacturing_date',
				'label'      => __( 'Manufacturing date', 'gf-odoo-connector' ),
				'section'    => 'product',
				'odoo_field' => 'manufacturing_date',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'date',
			),
		);
	}

	/**
	 * Odoo fields allowed on helpdesk.ticket create (derived from rows + partner_id).
	 *
	 * @return array<int, string>
	 */
	public static function ticket_field_names(): array {
		$fields = array( 'partner_id' );

		foreach ( self::rows() as $row ) {
			$fields[] = (string) $row['odoo_field'];
		}

		return array_values( array_unique( $fields ) );
	}

	/**
	 * @return array<string, string>
	 */
	public static function mode_labels(): array {
		return array(
			'auto'  => __( 'Auto', 'gf-odoo-connector' ),
			'field' => __( 'From field', 'gf-odoo-connector' ),
			'fixed' => __( 'Fixed', 'gf-odoo-connector' ),
			'off'   => __( 'Off', 'gf-odoo-connector' ),
		);
	}

	/**
	 * @param array $row Field row definition.
	 *
	 * @return string
	 */
	public static function default_mode( array $row ): string {
		if ( ! empty( $row['required'] ) ) {
			return (string) $row['modes'][0];
		}

		return 'off';
	}

	/**
	 * @param string $key Row key.
	 *
	 * @return array|null
	 */
	public static function get_row( string $key ): ?array {
		foreach ( self::rows() as $row ) {
			if ( $row['key'] === $key ) {
				return $row;
			}
		}

		return null;
	}
}
