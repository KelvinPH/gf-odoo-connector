<?php
/**
 * Uninstall GF Odoo Connector — runs when the plugin is deleted from WordPress.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$settings  = get_option( 'gravityformsaddon_gf-odoo-connector_settings', array() );
$keep_data = false;

if ( is_array( $settings ) && ! empty( $settings['keep_data_on_uninstall'] ) ) {
	$keep_data = true;
}

if ( $keep_data ) {
	return;
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_odoo_errors" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_odoo_templates" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_odoo_template_links" );

delete_option( 'gravityformsaddon_gf-odoo-connector_settings' );
delete_option( 'gravityformsaddon_gf-odoo-connector_version' );
delete_option( 'gf_odoo_db_version' );
delete_option( 'gf_odoo_last_success_at' );
delete_option( 'gf_odoo_api_key' );

$meta_keys = array(
	'odoo_lead_id',
	'odoo_partner_id',
	'odoo_ticket_id',
	'odoo_sync_status',
	'odoo_sync_at',
	'odoo_next_retry_at',
	'odoo_module',
	'odoo_stage',
	'odoo_assigned_to',
	'odoo_last_webhook_at',
);

$meta_table = $wpdb->prefix . 'gf_entry_meta';

foreach ( $meta_keys as $key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $meta_table, array( 'meta_key' => $key ), array( '%s' ) );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( null, array(), 'gf-odoo-connector' );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_gf_odoo_%'
	OR option_name LIKE '_transient_timeout_gf_odoo_%'
	OR option_name LIKE '_transient_gf_odoo_webhook_rl_%'
	OR option_name LIKE '_transient_timeout_gf_odoo_webhook_rl_%'"
);
