<?php
/**
 * Plugin Name:       GF Odoo Connector
 * Plugin URI:        https://github.com/KelvinPH/gf-odoo-connector
 * Description:       Connect Gravity Forms to Odoo CRM and Helpdesk. Sync form submissions to leads, contacts, and tickets.
 * Version:           1.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Kelvin Huurman
 * Author URI:        https://github.com/KelvinPH
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gf-odoo-connector
 * Domain Path:       /languages
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GF_ODOO_FILE', __FILE__ );
define( 'GF_ODOO_VERSION', '1.1.2' );
define( 'GF_ODOO_PATH', plugin_dir_path( GF_ODOO_FILE ) );
define( 'GF_ODOO_URL', plugin_dir_url( GF_ODOO_FILE ) );
define( 'GF_ODOO_MIN_GF_VERSION', '2.5' );
define( 'GF_ODOO_DB_VERSION', '1.0.0' );

/**
 * Admin notice when PHP is below 8.0.
 */
function gf_odoo_connector_php_version_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>'
		. esc_html__(
			'GF Odoo Connector requires PHP 8.0 or higher. Your server is running PHP ',
			'gf-odoo-connector'
		)
		. esc_html( PHP_VERSION )
		. '.</p></div>';
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action( 'admin_notices', 'gf_odoo_connector_php_version_notice' );
	return;
}

/**
 * Autoload plugin classes from includes/.
 *
 * @param string $class Class name.
 */
function gf_odoo_connector_autoload( $class ) {
	$classes = array(
		'GF_Odoo_Addon'          => 'class-gf-odoo-addon.php',
		'Odoo_API'               => 'class-odoo-api.php',
		'CRM_Handler'            => 'class-crm-handler.php',
		'Helpdesk_Handler'       => 'class-helpdesk-handler.php',
		'Field_Mapper'           => 'class-field-mapper.php',
		'Error_Logger'           => 'class-error-logger.php',
		'CRM_Field_Config'       => 'class-crm-field-config.php',
		'Helpdesk_Field_Config'  => 'class-helpdesk-field-config.php',
		'GF_Odoo_Async_Sync'     => 'class-gf-odoo-async-sync.php',
		'Webhook_Receiver'       => 'class-webhook-receiver.php',
		'Dashboard'              => 'class-dashboard.php',
		'Template_Manager'       => 'class-template-manager.php',
		'GF_Odoo_Admin_Menu'     => 'class-gf-odoo-admin-menu.php',
		'GF_Odoo_Country_Map'    => 'class-country-map.php',
		'GF_Odoo_Industry_Map'   => 'class-industry-map.php',
		'GF_Odoo_Encryption'     => 'class-encryption.php',
	);

	if ( ! isset( $classes[ $class ] ) ) {
		return;
	}

	$file = GF_ODOO_PATH . 'includes/' . $classes[ $class ];

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'gf_odoo_connector_autoload' );

/**
 * @return string Gravity Forms version string or empty.
 */
function gf_odoo_connector_get_gf_version(): string {
	if ( class_exists( 'GFForms' ) && isset( GFForms::$version ) ) {
		return (string) GFForms::$version;
	}

	if ( class_exists( 'GFCommon' ) && is_callable( array( 'GFCommon', 'get_version' ) ) ) {
		return (string) GFCommon::get_version();
	}

	return '';
}

/**
 * Admin notice when GF Feed Add-On framework is missing.
 */
function gf_odoo_connector_missing_gf_framework_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'GF Odoo Connector requires Gravity Forms with Feed Add-On Framework (version 2.5+).',
			'gf-odoo-connector'
		)
	);
}

/**
 * Admin notice when Gravity Forms version is too old.
 */
function gf_odoo_connector_gf_version_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum Gravity Forms version */
				__( 'GF Odoo Connector requires Gravity Forms %s or higher. Please update Gravity Forms.', 'gf-odoo-connector' ),
				GF_ODOO_MIN_GF_VERSION
			)
		)
	);
}

/**
 * Admin notice when Gravity Forms is not active.
 */
function gf_odoo_connector_missing_gf_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'GF Odoo Connector requires Gravity Forms to be installed and active.',
			'gf-odoo-connector'
		)
	);
}

/**
 * Whether the plugin passed all runtime requirements.
 *
 * @return bool
 */
function gf_odoo_connector_requirements_met(): bool {
	return class_exists( 'GF_Odoo_Addon' );
}

/**
 * Register the feed add-on once Gravity Forms has loaded.
 */
function gf_odoo_connector_init(): void {
	static $initialized = false;

	if ( $initialized ) {
		return;
	}

	if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
		add_action( 'admin_notices', 'gf_odoo_connector_missing_gf_framework_notice' );
		return;
	}

	GFForms::include_feed_addon_framework();

	if ( ! class_exists( 'GFAddOn' ) || ! class_exists( 'GFFeedAddOn' ) ) {
		add_action( 'admin_notices', 'gf_odoo_connector_missing_gf_framework_notice' );
		return;
	}

	$gf_version = gf_odoo_connector_get_gf_version();
	if ( '' !== $gf_version && version_compare( $gf_version, GF_ODOO_MIN_GF_VERSION, '<' ) ) {
		add_action( 'admin_notices', 'gf_odoo_connector_gf_version_notice' );
		return;
	}

	GFAddOn::register( 'GF_Odoo_Addon' );
	$initialized = true;

	if ( did_action( 'gform_loaded' ) ) {
		GF_Odoo_Addon::get_instance();
	}
}

/**
 * Bootstrap the plugin after WordPress and other plugins have loaded.
 */
function gf_odoo_connector_bootstrap(): void {
	if ( ! class_exists( 'GFForms' ) ) {
		add_action( 'admin_notices', 'gf_odoo_connector_missing_gf_notice' );
		return;
	}

	if ( did_action( 'gform_loaded' ) ) {
		gf_odoo_connector_init();
		return;
	}

	add_action( 'gform_loaded', 'gf_odoo_connector_init', 5 );
}

add_action( 'plugins_loaded', 'gf_odoo_connector_bootstrap', 5 );

/**
 * Create or upgrade plugin database tables (safe during activation — no GF add-on class).
 */
function gf_odoo_connector_create_db_tables(): void {
	require_once GF_ODOO_PATH . 'includes/class-error-logger.php';
	require_once GF_ODOO_PATH . 'includes/class-template-manager.php';

	Error_Logger::create_table();
	Template_Manager::create_tables();
}

/**
 * Plugin activation: database tables and requirement checks.
 */
function gf_odoo_connector_activate(): void {
	if ( ! extension_loaded( 'openssl' ) ) {
		deactivate_plugins( plugin_basename( GF_ODOO_FILE ) );
		wp_die(
			esc_html__(
				'GF Odoo Connector requires the PHP OpenSSL extension. Please enable it on your server.',
				'gf-odoo-connector'
			),
			esc_html__( 'Plugin activation error', 'gf-odoo-connector' ),
			array( 'back_link' => true )
		);
	}

	// Do not autoload GF_Odoo_Addon here — GFFeedAddOn may not be loaded yet during activation.
	gf_odoo_connector_create_db_tables();

	update_option( 'gf_odoo_db_version', GF_ODOO_DB_VERSION );

	if ( ! get_option( 'gf_odoo_wizard_dismissed' ) ) {
		update_option( 'gf_odoo_show_wizard', true );
	}
}

register_activation_hook( GF_ODOO_FILE, 'gf_odoo_connector_activate' );

/**
 * Plugin deactivation: cancel scheduled jobs, clear transients (no data deletion).
 */
function gf_odoo_connector_deactivate(): void {
	if ( class_exists( 'GF_Odoo_Addon' ) ) {
		GF_Odoo_Addon::on_deactivation();
	}
}

register_deactivation_hook( GF_ODOO_FILE, 'gf_odoo_connector_deactivate' );

/**
 * Whether OpenSSL is available for API key encryption.
 */
function gf_odoo_connector_openssl_available(): bool {
	return class_exists( 'GF_Odoo_Encryption' ) && GF_Odoo_Encryption::is_available();
}

/**
 * Admin notice when OpenSSL is missing.
 */
function gf_odoo_connector_openssl_admin_notice(): void {
	if ( gf_odoo_connector_openssl_available() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'GF Odoo Connector requires the PHP OpenSSL extension to encrypt the Odoo API key. Enable OpenSSL on this server.',
			'gf-odoo-connector'
		)
	);
}

add_action( 'admin_notices', 'gf_odoo_connector_openssl_admin_notice' );

/**
 * Ensure the add-on is bootstrapped on admin/AJAX requests (needed for retry AJAX).
 */
function gf_odoo_connector_ensure_addon_loaded(): void {
	if ( ! class_exists( 'GFForms' ) || ! gf_odoo_connector_requirements_met() ) {
		return;
	}

	if ( did_action( 'gform_loaded' ) ) {
		gf_odoo_connector_init();
	}

	if ( class_exists( 'GF_Odoo_Addon' ) ) {
		GF_Odoo_Addon::get_instance();
	}
}

add_action( 'plugins_loaded', 'gf_odoo_connector_ensure_addon_loaded', 20 );

/**
 * Register structured admin menu.
 */
function gf_odoo_connector_register_admin_menu(): void {
	if ( ! gf_odoo_connector_requirements_met() || ! class_exists( 'GF_Odoo_Admin_Menu' ) ) {
		return;
	}

	GF_Odoo_Admin_Menu::instance()->init();
}

add_action( 'plugins_loaded', 'gf_odoo_connector_register_admin_menu', 22 );

/**
 * Load plugin translations.
 */
function gf_odoo_connector_load_textdomain(): void {
	load_plugin_textdomain(
		'gf-odoo-connector',
		false,
		dirname( plugin_basename( GF_ODOO_FILE ) ) . '/languages'
	);
}

add_action( 'init', 'gf_odoo_connector_load_textdomain' );

/**
 * Register Action Scheduler hook for background Odoo sync.
 */
function gf_odoo_connector_register_async_sync(): void {
	if ( ! gf_odoo_connector_requirements_met() || ! class_exists( 'GF_Odoo_Async_Sync' ) ) {
		return;
	}

	GF_Odoo_Async_Sync::register_hook();
}

add_action( 'init', 'gf_odoo_connector_register_async_sync', 20 );
add_action( 'plugins_loaded', 'gf_odoo_connector_register_async_sync', 25 );

/**
 * Register Odoo webhook REST route and background processor.
 */
function gf_odoo_connector_register_webhook(): void {
	if ( ! class_exists( 'Webhook_Receiver' ) ) {
		return;
	}

	$receiver = Webhook_Receiver::instance();
	add_action( 'rest_api_init', array( $receiver, 'register_routes' ) );
	add_action( Webhook_Receiver::HOOK_PROCESS, array( $receiver, 'process_webhook_job' ), 10, 1 );
}

add_action( 'init', 'gf_odoo_connector_register_webhook', 20 );
add_action( 'plugins_loaded', 'gf_odoo_connector_register_webhook', 25 );
