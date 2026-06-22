<?php
/**
 * CRM per-field mode definitions (single source of truth).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field row schema for CRM feed settings and mapping.
 */
class CRM_Field_Config {

	/**
	 * All CRM contact + lead field rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows(): array {
		return array(
			array(
				'key'        => 'contact_name',
				'label'      => __( 'Contact name', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'name',
				'modes'      => array( 'field', 'fixed' ),
				'required'   => true,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_email',
				'label'      => __( 'Email', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'email',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_phone',
				'label'      => __( 'Phone', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'phone',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_mobile',
				'label'      => __( 'Mobile', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'mobile',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_company',
				'label'      => __( 'Company', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'company_name',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_street',
				'label'      => __( 'Street', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'street',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_city',
				'label'      => __( 'City', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'city',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'contact_zip',
				'label'      => __( 'ZIP', 'gf-odoo-connector' ),
				'section'    => 'contact',
				'odoo_field' => 'zip',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'         => 'contact_country',
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
				'key'        => 'lead_title',
				'label'      => __( 'Lead title', 'gf-odoo-connector' ),
				'section'    => 'lead',
				'odoo_field' => 'name',
				'modes'      => array( 'auto', 'field', 'fixed' ),
				'required'   => true,
				'auto_label' => __( 'Form title', 'gf-odoo-connector' ),
				'fixed_type' => 'text',
			),
			array(
				'key'        => 'lead_description',
				'label'      => __( 'Description', 'gf-odoo-connector' ),
				'section'    => 'lead',
				'odoo_field' => 'description',
				'modes'      => array( 'field', 'fixed', 'off' ),
				'required'   => false,
				'fixed_type' => 'text',
			),
			array(
				'key'         => 'lead_industry',
				'label'       => __( 'Industry', 'gf-odoo-connector' ),
				'section'     => 'lead',
				'odoo_field'  => 'industry_id',
				'modes'       => array( 'field', 'fixed', 'off' ),
				'required'    => false,
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_industries',
				'odoo_model'  => 'res.partner.industry',
				'resolver'    => 'industry',
			),
			array(
				'key'         => 'lead_sub_industry',
				'label'       => __( 'Sub industry', 'gf-odoo-connector' ),
				'section'     => 'lead',
				'odoo_field'  => 'sub_industry_id',
				'modes'       => array( 'field', 'fixed', 'off' ),
				'required'    => false,
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_sub_industries',
				'parent_key'  => 'lead_industry',
				'odoo_model'  => 'sub.industry',
			),
			array(
				'key'         => 'lead_source',
				'label'       => __( 'Source', 'gf-odoo-connector' ),
				'section'     => 'lead',
				'odoo_field'  => 'source_id',
				'modes'       => array( 'auto', 'field', 'fixed', 'off' ),
				'required'    => false,
				'auto_label'  => __( 'Current page URL', 'gf-odoo-connector' ),
				'help'        => __(
					'Auto: The plugin captures the URL of the page where the form was submitted and finds or creates a matching utm.source record in Odoo.',
					'gf-odoo-connector'
				),
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_sources',
				'odoo_model'  => 'utm.source',
			),
			array(
				'key'         => 'lead_sub_source',
				'label'       => __( 'Sub lead source', 'gf-odoo-connector' ),
				'section'     => 'lead',
				'odoo_field'  => 'sub_lead_source_id',
				'modes'       => array( 'field', 'fixed', 'off' ),
				'required'    => false,
				'fixed_type'  => 'odoo_select',
				'ajax_action' => 'gf_odoo_get_sub_lead_sources',
				'parent_key'  => 'lead_source',
				'odoo_model'  => 'sub.lead.source',
			),
			array(
				'key'           => 'lead_priority',
				'label'         => __( 'Priority', 'gf-odoo-connector' ),
				'section'       => 'lead',
				'odoo_field'    => 'priority',
				'modes'         => array( 'field', 'fixed', 'off' ),
				'required'      => false,
				'fixed_type'    => 'static_select',
				'fixed_choices' => array(
					array( 'value' => '0', 'label' => __( 'Low', 'gf-odoo-connector' ) ),
					array( 'value' => '1', 'label' => __( 'Medium', 'gf-odoo-connector' ) ),
					array( 'value' => '2', 'label' => __( 'High', 'gf-odoo-connector' ) ),
					array( 'value' => '3', 'label' => __( 'Very High', 'gf-odoo-connector' ) ),
				),
			),
		);
	}

	/**
	 * Human-readable mode tab labels.
	 *
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
	 * Default mode for a row when nothing is saved yet.
	 *
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
	 * Find a row by key.
	 *
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
