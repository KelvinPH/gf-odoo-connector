<?php
/**
 * Main Gravity Forms Feed Add-On class.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GF Odoo Connector feed add-on.
 */
class GF_Odoo_Addon extends GFFeedAddOn {

	/**
	 * Singleton instance.
	 *
	 * @var GF_Odoo_Addon|null
	 */
	private static $_instance = null;

	/**
	 * @var string
	 */
	protected $_version = GF_ODOO_VERSION;

	/**
	 * @var string
	 */
	protected $_min_gravityforms_version = '1.9';

	/**
	 * @var string
	 */
	protected $_slug = 'gf-odoo-connector';

	/**
	 * @var string
	 */
	protected $_path = 'gf-odoo-connector/gf-odoo-connector.php';

	/**
	 * @var string
	 */
	protected $_full_path = '';

	/**
	 * @var string
	 */
	protected $_title = 'GF Odoo Connector';

	/**
	 * @var string
	 */
	protected $_short_title = 'GF Odoo Connector';

	/**
	 * wp_options key for the Odoo API key (separate from GF settings to avoid masked password values).
	 */
	private const OPTION_API_KEY = 'gf_odoo_api_key';

	/**
	 * wp_options key for the Smart routing AI API key (stored encrypted, never in GF settings).
	 */
	private const OPTION_AI_API_KEY = 'gf_odoo_ai_api_key';

	/**
	 * Message from the most recent process_sync_job() failure (for manual retry UI).
	 *
	 * @var string
	 */
	private $last_sync_job_error = '';

	/**
	 * Transient key for cached Odoo CRM team choices.
	 */
	private const TRANSIENT_CRM_TEAMS = 'gf_odoo_crm_teams';

	/**
	 * Transient key for cached Odoo salesperson choices.
	 */
	private const TRANSIENT_CRM_USERS = 'gf_odoo_crm_users';

	/**
	 * Transient key for cached Odoo helpdesk teams.
	 */
	private const TRANSIENT_HELPDESK_TEAMS = 'gf_odoo_helpdesk_teams';

	/**
	 * Transient key for last connection test result (success|error|unknown).
	 */
	private const TRANSIENT_CONNECTION_STATUS = 'gf_odoo_connection_status';

	/**
	 * How long a cached connection result is trusted before a live re-check (5 minutes).
	 */
	private const CONNECTION_STATUS_FRESH_TTL = 300;

	/**
	 * Cache TTL for Odoo assignment dropdowns (15 minutes).
	 */
	private const ASSIGNMENT_CACHE_TTL = 900;

	/**
	 * @var string|array
	 */
	protected $_capabilities_settings_page = array( 'gravityforms_edit_settings', 'gform_full_access' );

	/**
	 * @var string|array
	 */
	protected $_capabilities_form_settings = array( 'gravityforms_edit_forms', 'gform_full_access' );

	/**
	 * Templates admin handler.
	 *
	 * @var GF_Odoo_Templates_Admin|null
	 */
	private $templates_admin = null;

	/**
	 * Testing tools admin handler.
	 *
	 * @var GF_Odoo_Testing_Admin|null
	 */
	private $testing_admin = null;

	/**
	 * Force [TEST] prefix on the current sync (test submission tool).
	 *
	 * @var bool
	 */
	private $force_test_mode_for_sync = false;

	/**
	 * Temporary connection overrides for scenario tests.
	 *
	 * @var array<string, mixed>|null
	 */
	private $sync_connection_overrides = null;

	/**
	 * Skip Action Scheduler auto-retry for the current sync job.
	 *
	 * @var bool
	 */
	private $skip_auto_retry_for_sync = false;

	/**
	 * When set, plugin_settings_fields() returns only that section group.
	 *
	 * @var string|null connection|notifications|webhook
	 */
	private $admin_settings_page_filter = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return GF_Odoo_Addon
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->_full_path = defined( 'GF_ODOO_FILE' ) ? GF_ODOO_FILE : GF_ODOO_PATH . 'gf-odoo-connector.php';
		parent::__construct();
	}

	/**
	 * Create or update all plugin database tables (idempotent via dbDelta).
	 */
	public static function create_db_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$queries = array(
			"CREATE TABLE {$wpdb->prefix}gf_odoo_errors (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				entry_id bigint(20) unsigned NOT NULL,
				feed_id bigint(20) unsigned NOT NULL,
				module varchar(32) NOT NULL,
				error_code varchar(64) DEFAULT NULL,
				error_message text NOT NULL,
				payload longtext,
				attempt tinyint(3) unsigned NOT NULL DEFAULT 1,
				next_retry_at datetime DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				retried_at datetime DEFAULT NULL,
				resolved tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY form_id (form_id),
				KEY entry_id (entry_id),
				KEY resolved (resolved),
				KEY created_at (created_at),
				KEY idx_resolved_created (resolved, created_at),
				KEY idx_form_entry (form_id, entry_id)
			) {$charset};",
			"CREATE TABLE {$wpdb->prefix}gf_odoo_templates (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				module varchar(32) NOT NULL,
				feed_meta longtext NOT NULL,
				sample_form_id bigint(20) unsigned NULL DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY module (module),
				KEY sample_form_id (sample_form_id)
			) {$charset};",
			"CREATE TABLE {$wpdb->prefix}gf_odoo_template_links (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				template_id bigint(20) unsigned NOT NULL,
				form_id bigint(20) unsigned NOT NULL,
				feed_id bigint(20) unsigned NOT NULL,
				overrides longtext,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY form_feed (form_id, feed_id),
				KEY template_id (template_id)
			) {$charset};",
		);

		foreach ( $queries as $sql ) {
			dbDelta( $sql );
		}

		Error_Logger::maybe_repair_created_at_column();
		Error_Logger::maybe_add_performance_indexes();
	}

	/**
	 * Create or update the error log database table (idempotent).
	 */
	public static function create_error_table(): void {
		self::create_db_tables();
	}

	/**
	 * Run database migrations when GF_ODOO_DB_VERSION increases.
	 */
	public function maybe_upgrade_db(): void {
		if ( ! is_admin() ) {
			return;
		}

		$installed_version = (string) get_option( 'gf_odoo_db_version', '0.0.0' );

		if ( GF_ODOO_DB_VERSION === $installed_version ) {
			return;
		}

		// Legacy installs stored the plugin release version (e.g. 1.5.0) before GF_ODOO_DB_VERSION existed.
		$migrate_from = $installed_version;
		if ( preg_match( '/^1\.[0-9]+\.[0-9]+$/', $installed_version ) && version_compare( $installed_version, '2.0.0', '<' ) ) {
			$migrate_from = '0.0.0';
		}

		$this->run_db_migrations( $migrate_from );

		update_option( 'gf_odoo_db_version', GF_ODOO_DB_VERSION );
	}

	/**
	 * @param string $from_version Previously installed DB schema version.
	 */
	private function run_db_migrations( string $from_version ): void {
		self::create_db_tables();

		if ( version_compare( $from_version, '1.0.0', '<' ) ) {
			$this->migrate_to_1_0_0();
		}
	}

	/**
	 * Initial schema migrations (error log columns, encrypted API key).
	 */
	private function migrate_to_1_0_0(): void {
		global $wpdb;

		$table = Error_Logger::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );

		if ( ! is_array( $columns ) ) {
			$columns = array();
		}

		if ( ! in_array( 'attempt', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN attempt TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER payload" );
		}

		if ( ! in_array( 'next_retry_at', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN next_retry_at DATETIME NULL DEFAULT NULL AFTER attempt" );
		}

		$this->maybe_migrate_api_key_encryption();
	}

	/**
	 * Cancel background jobs and clear volatile state when the plugin is deactivated.
	 */
	public static function on_deactivation(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			$as_group = class_exists( 'GF_Odoo_Async_Sync' ) ? GF_Odoo_Async_Sync::get_group() : 'gf-odoo-connector';
			as_unschedule_all_actions( 'gf_odoo_sync_entry', array(), $as_group );
			as_unschedule_all_actions( 'gf_odoo_process_webhook', array(), $as_group );
		}

		delete_transient( 'gf_odoo_session' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_gf_odoo_webhook_rl_%'
			OR option_name LIKE '_transient_timeout_gf_odoo_webhook_rl_%'"
		);
	}

	/**
	 * Register hooks that run on front-end and admin requests.
	 */
	public function init() {
		parent::init();

		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'register_entry_detail_meta_boxes' ), 10, 3 );
		add_filter( 'gform_pre_render', array( $this, 'maybe_inject_source_url_field' ), 10, 1 );
		add_filter( 'gform_pre_validation', array( $this, 'maybe_inject_source_url_field' ), 10, 1 );

		add_shortcode( 'odoo_ticket_status', array( $this, 'render_ticket_status_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_styles' ) );

		add_filter( 'gform_entry_list_columns', array( $this, 'add_entry_list_odoo_column' ), 10, 2 );
		add_filter( 'gform_entries_column_filter', array( $this, 'render_entry_list_odoo_column' ), 10, 5 );
	}

	/**
	 * Admin-only hooks.
	 */
	public function init_admin() {
		parent::init_admin();

		$this->init_templates_admin();
		$this->init_testing_admin();

		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_admin_pages' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_odoo_admin_page_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_sync_failure_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_openssl_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_test_mode_notice' ) );
		add_action( 'wp_ajax_gf_odoo_export_csv', array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_gf_odoo_export_all_data', array( $this, 'ajax_export_all_data' ) );
	}

	/**
	 * Enqueue plugin admin styles on GF Odoo Connector pages only.
	 */
	public function enqueue_odoo_admin_page_assets(): void {
		if ( ! $this->is_plugin_admin_page_request() ) {
			return;
		}

		wp_dequeue_style( 'gform_admin' );
		wp_dequeue_style( 'gform_settings' );
		wp_dequeue_style( 'gform_admin_components' );
		wp_dequeue_style( 'gform_admin_css_utilities' );

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'gf_odoo_admin',
			GF_ODOO_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			GF_ODOO_VERSION
		);

		wp_enqueue_script(
			'gf_odoo_admin',
			GF_ODOO_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			GF_ODOO_VERSION,
			true
		);
		$this->localize_admin_scripts();

		if ( $this->is_templates_admin_page() ) {
			wp_enqueue_script(
				'gf_odoo_templates',
				GF_ODOO_URL . 'assets/js/templates.js',
				array( 'jquery', 'gf_odoo_admin' ),
				GF_ODOO_VERSION,
				true
			);
		}
	}

	/**
	 * Whether the current request is a plugin admin page (gf_odoo_*).
	 *
	 * @return bool
	 */
	public function is_plugin_admin_page_request(): bool {
		return class_exists( 'GF_Odoo_Admin_Menu' ) && GF_Odoo_Admin_Menu::is_plugin_admin_page();
	}

	/**
	 * Load templates admin and AJAX handlers.
	 */
	private function init_templates_admin(): void {
		if ( null !== $this->templates_admin ) {
			return;
		}

		require_once GF_ODOO_PATH . 'admin/class-gf-odoo-templates-admin.php';

		$this->templates_admin = new GF_Odoo_Templates_Admin( $this );
		$this->templates_admin->register_ajax();
	}

	/**
	 * Load testing admin and AJAX handlers.
	 */
	private function init_testing_admin(): void {
		if ( null !== $this->testing_admin ) {
			return;
		}

		require_once GF_ODOO_PATH . 'admin/class-gf-odoo-testing-admin.php';

		$this->testing_admin = new GF_Odoo_Testing_Admin( $this );
		$this->testing_admin->register_ajax();
	}

	/**
	 * Whether test mode is enabled in plugin settings (or forced for a test run).
	 *
	 * @return bool
	 */
	public function is_test_mode_enabled(): bool {
		if ( $this->force_test_mode_for_sync ) {
			return true;
		}

		$settings = $this->get_connection_settings();
		$test     = rgar( $settings, 'test_mode' );

		if ( is_array( $test ) ) {
			$test = rgar( $test, 'test_mode' );
		}

		return ! empty( $test );
	}

	/**
	 * Persistent admin notice when test mode is active (all admin screens).
	 */
	public function maybe_render_test_mode_notice(): void {
		if ( ! is_admin() || ! $this->is_test_mode_enabled() ) {
			return;
		}

		if ( ! $this->current_user_can_manage_plugin() && ! current_user_can( 'gravityforms_edit_forms' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. '<strong>' . esc_html__( 'GF Odoo Connector: Test mode is active.', 'gf-odoo-connector' ) . '</strong> '
			. esc_html__(
				'All form submissions are being sent to Odoo with a [TEST] prefix. Disable test mode before going live.',
				'gf-odoo-connector'
			)
			. '</p></div>';
	}

	/**
	 * Prefix lead/ticket names when test mode is on.
	 *
	 * @param array $job Normalized sync job.
	 */
	private function apply_test_mode_to_job( array &$job ): void {
		if ( ! $this->is_test_mode_enabled() || empty( $job['sync_payload'] ) || ! is_array( $job['sync_payload'] ) ) {
			return;
		}

		$payload = &$job['sync_payload'];
		$prefix  = '[TEST] ';

		if ( isset( $payload['lead']['name'] ) ) {
			$name = (string) $payload['lead']['name'];
			if ( ! str_starts_with( $name, $prefix ) ) {
				$payload['lead']['name'] = $prefix . $name;
			}
		}

		if ( isset( $payload['ticket']['name'] ) ) {
			$name = (string) $payload['ticket']['name'];
			if ( ! str_starts_with( $name, $prefix ) ) {
				$payload['ticket']['name'] = $prefix . $name;
			}
		}
	}

	/**
	 * Register entry meta so Odoo IDs appear on the entry detail screen.
	 *
	 * @param array $entry_meta Existing entry meta.
	 * @param int   $form_id    Form ID.
	 *
	 * @return array
	 */
	public function get_entry_meta( $entry_meta, $form_id ) {
		$entry_meta['odoo_partner_id'] = array(
			'label'             => esc_html__( 'Odoo Partner ID', 'gf-odoo-connector' ),
			'is_numeric'        => true,
			'is_default_column' => false,
		);

		$entry_meta['odoo_lead_id'] = array(
			'label'             => esc_html__( 'Odoo Lead ID', 'gf-odoo-connector' ),
			'is_numeric'        => true,
			'is_default_column' => false,
		);

		$entry_meta['odoo_ticket_id'] = array(
			'label'             => esc_html__( 'Odoo Ticket ID', 'gf-odoo-connector' ),
			'is_numeric'        => true,
			'is_default_column' => false,
		);

		$entry_meta['odoo_sync_status'] = array(
			'label'             => esc_html__( 'Odoo Sync Status', 'gf-odoo-connector' ),
			'is_numeric'        => false,
			'is_default_column' => false,
		);

		$entry_meta['odoo_sync_at'] = array(
			'label'             => esc_html__( 'Odoo Sync At', 'gf-odoo-connector' ),
			'is_numeric'        => false,
			'is_default_column' => false,
		);

		$entry_meta['odoo_next_retry_at'] = array(
			'label'             => esc_html__( 'Odoo Next Retry At', 'gf-odoo-connector' ),
			'is_numeric'        => false,
			'is_default_column' => false,
		);

		$entry_meta['odoo_stage'] = array(
			'label'             => esc_html__( 'Odoo Stage', 'gf-odoo-connector' ),
			'is_numeric'        => false,
			'is_default_column' => false,
		);

		$entry_meta['odoo_assigned_to'] = array(
			'label'             => esc_html__( 'Odoo Assigned To', 'gf-odoo-connector' ),
			'is_numeric'        => false,
			'is_default_column' => false,
		);

		$entry_meta['odoo_module'] = array(
			'label'             => esc_html__( 'Odoo Module', 'gf-odoo-connector' ),
			'is_numeric'        => false,
			'is_default_column' => false,
		);

		return $entry_meta;
	}

	/**
	 * Enable Gravity Forms logging for this add-on.
	 *
	 * @param array $plugins Supported plugins.
	 *
	 * @return array
	 */
	public function set_logging_supported( $plugins ) {
		$plugins[ $this->_slug ] = $this->_title;

		return $plugins;
	}

	/**
	 * Register AJAX handlers.
	 */
	public function init_ajax() {
		parent::init_ajax();

		// Template AJAX is registered in init_admin() on normal admin pages; admin-ajax.php only runs init_ajax().
		$this->init_templates_admin();
		$this->init_testing_admin();

		add_action( 'wp_ajax_gf_odoo_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_gf_odoo_test_ai', array( $this, 'ajax_test_ai' ) );
		add_action( 'wp_ajax_gf_odoo_get_teams', array( $this, 'ajax_get_teams' ) );
		add_action( 'wp_ajax_gf_odoo_get_industries', array( $this, 'ajax_get_industries' ) );
		add_action( 'wp_ajax_gf_odoo_get_sub_industries', array( $this, 'ajax_get_sub_industries' ) );
		add_action( 'wp_ajax_gf_odoo_get_sources', array( $this, 'ajax_get_sources' ) );
		add_action( 'wp_ajax_gf_odoo_get_sub_lead_sources', array( $this, 'ajax_get_sub_lead_sources' ) );
		add_action( 'wp_ajax_gf_odoo_get_helpdesk_teams', array( $this, 'ajax_get_helpdesk_teams' ) );
		add_action( 'wp_ajax_gf_odoo_get_ticket_categories', array( $this, 'ajax_get_ticket_categories' ) );
		add_action( 'wp_ajax_gf_odoo_get_branches', array( $this, 'ajax_get_branches' ) );
		add_action( 'wp_ajax_gf_odoo_get_states', array( $this, 'ajax_get_states' ) );
		add_action( 'wp_ajax_gf_odoo_get_countries', array( $this, 'ajax_get_countries' ) );
		add_action( 'wp_ajax_gf_odoo_debug_helpdesk_fields', array( $this, 'ajax_debug_helpdesk_fields' ) );
		add_action( 'wp_ajax_gf_odoo_debug_country_resolve', array( $this, 'ajax_debug_country_resolve' ) );
		add_action( 'wp_ajax_gf_odoo_debug_industry_resolve', array( $this, 'ajax_debug_industry_resolve' ) );
		add_action( 'wp_ajax_gf_odoo_debug_odoo_model_fields', array( $this, 'ajax_debug_odoo_model_fields' ) );
		add_action( 'wp_ajax_gf_odoo_retry', array( $this, 'ajax_retry_feed' ) );
		add_action( 'wp_ajax_gf_odoo_mark_resolved', array( $this, 'ajax_mark_error_resolved' ) );
		add_action( 'wp_ajax_gf_odoo_entry_sync_now', array( $this, 'ajax_entry_sync_now' ) );
		add_action( 'wp_ajax_gf_odoo_chart_data', array( $this, 'ajax_chart_data' ) );
		add_action( 'wp_ajax_gf_odoo_clear_cache', array( $this, 'ajax_clear_cache' ) );
		add_action( 'wp_ajax_gf_odoo_reset_settings', array( $this, 'ajax_reset_plugin_settings' ) );
		add_action( 'wp_ajax_gf_odoo_refresh_template_mappings', array( $this, 'ajax_refresh_template_mappings' ) );
		add_action( 'wp_ajax_gf_odoo_wizard_save', array( $this, 'ajax_wizard_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_settings_reset_notice' ) );
		add_action( 'wp_ajax_gf_odoo_get_crm_assignment', array( $this, 'ajax_get_crm_assignment' ) );
	}

	/**
	 * Enqueue admin scripts on the plugin settings page.
	 *
	 * @return array
	 */
	public function scripts() {
		$scripts = parent::scripts();

		if ( ! $this->should_enqueue_odoo_admin_assets() ) {
			return $scripts;
		}

		$scripts[] = array(
			'handle'    => 'gf_odoo_admin',
			'src'       => GF_ODOO_URL . 'assets/js/admin.js',
			'version'   => GF_ODOO_VERSION,
			'deps'      => array( 'jquery' ),
			'in_footer' => true,
			'enqueue'   => array(
				array(
					'admin_page' => array( 'plugin_settings', 'form_settings' ),
				),
				array(
					'callback' => array( $this, 'is_error_log_page' ),
				),
				array(
					'callback' => array( $this, 'is_entry_detail_page' ),
				),
				array(
					'callback' => array( $this, 'is_dashboard_page' ),
				),
				array(
					'callback' => array( $this, 'is_templates_admin_page' ),
				),
				array(
					'callback' => array( $this, 'is_plugin_admin_page' ),
				),
			),
			'callback'  => array( $this, 'localize_admin_scripts' ),
		);

		$scripts[] = array(
			'handle'    => 'gf_odoo_templates',
			'src'       => GF_ODOO_URL . 'assets/js/templates.js',
			'version'   => GF_ODOO_VERSION,
			'deps'      => array( 'jquery', 'gf_odoo_admin' ),
			'in_footer' => true,
			'enqueue'   => array(
				array(
					'callback' => array( $this, 'is_templates_admin_page' ),
				),
				array(
					'admin_page' => array( 'form_settings' ),
				),
			),
		);

		$scripts[] = array(
			'handle'    => 'gf_odoo_dashboard',
			'src'       => GF_ODOO_URL . 'assets/js/dashboard.js',
			'version'   => GF_ODOO_VERSION,
			'deps'      => array( 'jquery', 'gf_odoo_chart' ),
			'in_footer' => true,
			'enqueue'   => array(
				array(
					'callback' => array( $this, 'is_dashboard_page' ),
				),
			),
			'callback'  => array( $this, 'localize_dashboard_scripts' ),
		);

		$scripts[] = array(
			'handle'    => 'gf_odoo_chart',
			'src'       => 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
			'version'   => '4.4.1',
			'deps'      => array(),
			'in_footer' => true,
			'enqueue'   => array(
				array(
					'callback' => array( $this, 'is_dashboard_page' ),
				),
			),
		);

		return $scripts;
	}

	/**
	 * Admin styles for connection status and error log actions.
	 *
	 * @return array
	 */
	public function styles() {
		$styles = parent::styles();

		if ( ! $this->should_enqueue_odoo_admin_assets() ) {
			return $styles;
		}

		$styles[] = array(
			'handle'  => 'gf_odoo_admin',
			'src'     => GF_ODOO_URL . 'assets/css/admin.css',
			'version' => GF_ODOO_VERSION,
			'enqueue' => array(
				array(
					'admin_page' => array( 'plugin_settings', 'form_settings' ),
				),
				array(
					'callback' => array( $this, 'is_error_log_page' ),
				),
				array(
					'callback' => array( $this, 'is_entry_detail_page' ),
				),
				array(
					'callback' => array( $this, 'is_dashboard_page' ),
				),
				array(
					'callback' => array( $this, 'is_templates_admin_page' ),
				),
				array(
					'callback' => array( $this, 'is_plugin_admin_page' ),
				),
			),
		);

		return $styles;
	}

	/**
	 * Pass data to the admin settings script.
	 */
	public function localize_admin_scripts() {
		$data = array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'gf_odoo_test_connection' ),
			'aiTestNonce'          => wp_create_nonce( 'gf_odoo_test_ai' ),
			'aiTesting'            => esc_html__( 'Testing AI…', 'gf-odoo-connector' ),
			'odooNonce'            => wp_create_nonce( 'gf_odoo_nonce' ),
			'teamsNonce'           => wp_create_nonce( 'gf_odoo_get_teams' ),
			'retryNonce'           => wp_create_nonce( 'gf_odoo_retry' ),
			'resolveNonce'         => wp_create_nonce( 'gf_odoo_mark_resolved' ),
			'testing'              => esc_html__( 'Testing connection…', 'gf-odoo-connector' ),
			'unknownError'         => esc_html__( 'Connection test failed.', 'gf-odoo-connector' ),
			'requestFailed'        => esc_html__( 'Request failed. Please try again.', 'gf-odoo-connector' ),
			'loadingTeams'         => esc_html__( 'Loading helpdesk teams…', 'gf-odoo-connector' ),
			'teamsLoadError'       => esc_html__( 'Could not load helpdesk teams from Odoo.', 'gf-odoo-connector' ),
			'selectHelpdeskTeam'   => esc_html__( 'Select a helpdesk team', 'gf-odoo-connector' ),
			'selectNone'           => esc_html__( 'None', 'gf-odoo-connector' ),
			'loadingCrmOptions'    => esc_html__( 'Loading options from Odoo…', 'gf-odoo-connector' ),
			'crmOptionsLoadError'  => esc_html__( 'Could not load options from Odoo.', 'gf-odoo-connector' ),
			'loadingCrmAssignment' => esc_html__( 'Loading salespeople and teams…', 'gf-odoo-connector' ),
			'crmAssignmentLoaded'  => esc_html__( 'Salespeople and teams loaded from Odoo.', 'gf-odoo-connector' ),
			'crmAssignmentError'   => esc_html__( 'Could not load salespeople or teams from Odoo.', 'gf-odoo-connector' ),
			'selectSalesTeam'      => esc_html__( 'Use global default', 'gf-odoo-connector' ),
			'selectSalesperson'    => esc_html__( 'Use global default', 'gf-odoo-connector' ),
			'templateLinkSaved'    => esc_html__( 'Template linked successfully.', 'gf-odoo-connector' ),
			'templateLinkPending'  => esc_html__(
				'Template link queued. Click “Update Settings” below to save this feed and the template will be linked automatically.',
				'gf-odoo-connector'
			),
			'templateLinkFailed'   => esc_html__( 'Could not link the template.', 'gf-odoo-connector' ),
			'selectField'          => esc_html__( 'Select a field', 'gf-odoo-connector' ),
			'modeLabels'           => CRM_Field_Config::mode_labels(),
			'crmFieldRows'         => array(),
			'helpdeskFieldRows'    => array(),
			'retrying'             => esc_html__( 'Retrying sync…', 'gf-odoo-connector' ),
			'retrySuccess'         => esc_html__( 'Sync succeeded. Reloading…', 'gf-odoo-connector' ),
			'retryFailed'          => esc_html__( 'Retry failed.', 'gf-odoo-connector' ),
			'retryDueNow'          => esc_html__( 'Retry due now, waiting for cron…', 'gf-odoo-connector' ),
			'retryingIn'           => esc_html__( 'Retrying in %s', 'gf-odoo-connector' ),
			'entrySyncNonce'       => wp_create_nonce( 'gf_odoo_entry_sync' ),
			'entrySyncing'         => esc_html__( 'Syncing to Odoo…', 'gf-odoo-connector' ),
			'entrySyncSuccess'     => esc_html__( 'Synced successfully.', 'gf-odoo-connector' ),
			'entrySyncFailed'      => esc_html__( 'Sync failed.', 'gf-odoo-connector' ),
			'webhookUrlCopied'     => esc_html__( 'Webhook URL copied.', 'gf-odoo-connector' ),
			'webhookCopyFailed'    => esc_html__( 'Could not copy URL.', 'gf-odoo-connector' ),
			'resetSettingsConfirm' => esc_html__(
				'Reset all GF Odoo Connector settings to their defaults?\n\nThis clears the Odoo URL, database, login, API key, notifications, webhook secret, and all plugin caches.\n\nForm feeds, templates, sync history, and the error log are not deleted.',
				'gf-odoo-connector'
			),
			'resetSettingsDone'    => esc_html__( 'Plugin settings reset to defaults.', 'gf-odoo-connector' ),
			'resolving'            => esc_html__( 'Marking resolved…', 'gf-odoo-connector' ),
			'resolveSuccess'       => esc_html__( 'Marked resolved. Reloading…', 'gf-odoo-connector' ),
			'overrideLabel'        => esc_html__( 'Override', 'gf-odoo-connector' ),
			'removeOverrideLabel'  => esc_html__( 'Remove override', 'gf-odoo-connector' ),
			'deleteTemplateConfirm' => esc_html__( 'Delete this template?', 'gf-odoo-connector' ),
			'deleteTemplateLinked'  => esc_html__(
				'This template is linked to %d form(s). Deleting unlinks those forms but does not remove their overrides.',
				'gf-odoo-connector'
			),
			'testSending'           => esc_html__( 'Sending test submission…', 'gf-odoo-connector' ),
			'testSelectFormFeed'    => esc_html__( 'Select a form and feed first.', 'gf-odoo-connector' ),
			'testSuccess'           => esc_html__( 'Test submission successful. Check Odoo for the [TEST] record.', 'gf-odoo-connector' ),
			'remapTitle'            => esc_html__( 'Link template to form', 'gf-odoo-connector' ),
			'remapIntro'            => esc_html__( 'Linking', 'gf-odoo-connector' ),
			'remapTo'               => esc_html__( 'to template', 'gf-odoo-connector' ),
			'remapFieldCount'       => esc_html__( 'This template has %d "From field" mappings.', 'gf-odoo-connector' ),
			'remapHow'              => esc_html__( 'How would you like to map the fields?', 'gf-odoo-connector' ),
			'remapAuto'             => esc_html__( 'Auto-match by label', 'gf-odoo-connector' ),
			'remapManual'           => esc_html__( 'Map manually', 'gf-odoo-connector' ),
			'remapCancel'           => esc_html__( 'Cancel', 'gf-odoo-connector' ),
			'remapConfirm'          => esc_html__( 'Confirm & link', 'gf-odoo-connector' ),
			'remapAutoTitle'        => esc_html__( 'Automatic field matching', 'gf-odoo-connector' ),
			'remapMatchedHeading'   => esc_html__( 'Automatically matched (%d):', 'gf-odoo-connector' ),
			'remapUnmatchedHeading' => esc_html__( 'Could not match:', 'gf-odoo-connector' ),
			'remapNotFound'         => esc_html__( 'not found in this form', 'gf-odoo-connector' ),
			'remapSameId'           => esc_html__( 'same ID', 'gf-odoo-connector' ),
			'remapByLabel'          => esc_html__( 'matched by label', 'gf-odoo-connector' ),
			'remapManualTitle'      => esc_html__( 'Manual field mapping', 'gf-odoo-connector' ),
			'remapColOdoo'          => esc_html__( 'Odoo field', 'gf-odoo-connector' ),
			'remapColTemplate'      => esc_html__( 'Template maps to', 'gf-odoo-connector' ),
			'remapColForm'          => esc_html__( 'This form', 'gf-odoo-connector' ),
			'remapResolveAll'       => esc_html__( 'Please select a field for each unmatched mapping.', 'gf-odoo-connector' ),
			'refreshTemplateMappingsConfirm' => esc_html__(
				'Refresh all field mappings from the template? Manual per-field overrides for this form will be replaced with automatic label matching.',
				'gf-odoo-connector'
			),
			'userTeams'            => array(),
			'savedHelpdeskTeamId'  => '',
		);

		if ( $this->is_detail_page() ) {
			$assignment = $this->get_odoo_assignment_data();
			$user_teams = array();

			foreach ( $assignment['users'] as $user ) {
				if ( ! empty( $user['team_id'] ) ) {
					$user_teams[ (string) $user['value'] ] = (int) $user['team_id'];
				}
			}

			$data['userTeams'] = $user_teams;

			$feed = $this->get_current_feed();
			// New feeds: get_current_feed() returns false, not null.
			$feed = is_array( $feed ) ? $feed : null;

			if ( null !== $feed ) {
				$data['savedHelpdeskTeamId'] = (string) rgars( $feed, 'meta/helpdesk_team_id' );
			}

			$data['crmFieldRows']      = $this->get_crm_field_rows_for_js( $feed );
			$data['helpdeskFieldRows'] = $this->get_helpdesk_field_rows_for_js( $feed );
		}

		wp_localize_script( 'gf_odoo_admin', 'gfOdooAdmin', $data );
	}

	/**
	 * Build an Odoo API client from plugin settings.
	 *
	 * @param array|null $overrides Optional setting overrides (e.g. unsaved form values).
	 *
	 * @return Odoo_API|null Null when required settings are missing.
	 */
	public function get_odoo_api( ?array $overrides = null ) {
		$this->ensure_stored_api_key();

		if ( null !== $this->sync_connection_overrides ) {
			$overrides = wp_parse_args(
				(array) $overrides,
				$this->sync_connection_overrides
			);
		}

		$settings = wp_parse_args(
			(array) $overrides,
			$this->get_connection_settings()
		);

		$base_url = trim( (string) rgar( $settings, 'odoo_url' ) );
		$db       = trim( (string) rgar( $settings, 'db_name' ) );
		$login    = trim( (string) rgar( $settings, 'login_email' ) );
		$api_key  = $this->resolve_api_key( $settings );

		if ( '' === $base_url || '' === $api_key || '' === $db ) {
			return null;
		}

		return new Odoo_API(
			$base_url,
			$api_key,
			$db,
			$login
		);
	}

	/**
	 * Resolve the API key from overrides, dedicated storage, or GF plugin settings.
	 *
	 * Password fields in GF settings often save/display masked placeholders (e.g. asterisks).
	 * The real key is stored in a dedicated option when settings are saved or connection is tested.
	 *
	 * @param array $settings Plugin settings (may include overrides).
	 *
	 * @return string
	 */
	/**
	 * Decrypted Odoo API key from secure storage.
	 *
	 * @return string
	 */
	public function get_api_key(): string {
		$stored = (string) get_option( self::OPTION_API_KEY, '' );

		if ( '' === $stored ) {
			return '';
		}

		if ( ! class_exists( 'GF_Odoo_Encryption' ) ) {
			return $this->normalize_api_key( $stored );
		}

		return $this->normalize_api_key( GF_Odoo_Encryption::decrypt( $stored ) );
	}

	/**
	 * Masked placeholder for the settings form (never the real key).
	 *
	 * @return string
	 */
	private function get_masked_api_key(): string {
		$key = $this->get_api_key();

		if ( '' === $key ) {
			return '';
		}

		return str_repeat( '•', min( strlen( $key ), 40 ) );
	}

	/**
	 * Whether a submitted API key value is the masked placeholder (unchanged).
	 *
	 * @param string $value Raw input.
	 */
	private function is_masked_api_key( string $value ): bool {
		$value = trim( $value );

		if ( '' === $value ) {
			return false;
		}

		if ( preg_match( '/^[\*•·\.]+$/u', $value ) ) {
			return true;
		}

		$masked = $this->get_masked_api_key();

		return '' !== $masked && $value === $masked;
	}

	/**
	 * Resolve API key from POST overrides or encrypted storage.
	 *
	 * @param array $settings Plugin settings (may include overrides).
	 *
	 * @return string
	 */
	private function resolve_api_key( array $settings ): string {
		$raw = (string) rgar( $settings, 'api_key' );

		if ( '' !== $raw && ! $this->is_masked_api_key( $raw ) ) {
			$key = $this->normalize_api_key( $raw );
			if ( '' !== $key ) {
				return $key;
			}
		}

		return $this->get_api_key();
	}

	/**
	 * Plugin settings for the admin UI (API key masked).
	 *
	 * @return array<string, mixed>
	 */
	private function get_plugin_settings_for_display(): array {
		$settings = (array) $this->get_plugin_settings();

		if ( '' !== $this->get_api_key() ) {
			$settings['api_key'] = $this->get_masked_api_key();
		}

		if ( '' !== $this->get_ai_key() ) {
			$settings['smart_routing_ai_key'] = $this->get_masked_ai_key();
		}

		return $settings;
	}

	/**
	 * Decrypted Smart routing AI API key.
	 *
	 * @return string
	 */
	public function get_ai_key(): string {
		$stored = (string) get_option( self::OPTION_AI_API_KEY, '' );

		if ( '' === $stored ) {
			return '';
		}

		if ( ! class_exists( 'GF_Odoo_Encryption' ) ) {
			return trim( $stored );
		}

		return trim( GF_Odoo_Encryption::decrypt( $stored ) );
	}

	/**
	 * Store the AI API key, encrypted when possible.
	 *
	 * @param string $key Raw key.
	 */
	private function store_ai_key( string $key ): void {
		$key = trim( $key );

		if ( '' === $key ) {
			return;
		}

		$to_store = $key;

		if ( class_exists( 'GF_Odoo_Encryption' ) && GF_Odoo_Encryption::is_available() ) {
			$encrypted = GF_Odoo_Encryption::encrypt( $key );
			if ( '' !== $encrypted ) {
				$to_store = $encrypted;
			}
		}

		update_option( self::OPTION_AI_API_KEY, $to_store, false );
	}

	/**
	 * Masked placeholder for the AI key field.
	 *
	 * @return string
	 */
	private function get_masked_ai_key(): string {
		$key = $this->get_ai_key();

		if ( '' === $key ) {
			return '';
		}

		return str_repeat( '•', min( strlen( $key ), 40 ) );
	}

	/**
	 * Whether a submitted AI key value is the masked placeholder (unchanged).
	 *
	 * @param string $value Raw input.
	 *
	 * @return bool
	 */
	private function is_masked_ai_key( string $value ): bool {
		$value = trim( $value );

		if ( '' === $value ) {
			return false;
		}

		if ( preg_match( '/^[\*•·\.]+$/u', $value ) ) {
			return true;
		}

		$masked = $this->get_masked_ai_key();

		return '' !== $masked && $value === $masked;
	}

	/**
	 * Load plugin settings reliably (GF may return false before first save).
	 *
	 * @return array<string, mixed>
	 */
	private function get_connection_settings(): array {
		$settings = $this->get_plugin_settings();

		if ( is_array( $settings ) ) {
			return $settings;
		}

		$raw = get_option( 'gravityformsaddon_' . $this->get_slug() . '_settings' );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( is_string( $raw ) && '' !== $raw ) {
			$decrypted = $this->get_encryptor()->decrypt( $raw );
			if ( is_array( $decrypted ) ) {
				return $decrypted;
			}
		}

		return array();
	}

	/**
	 * Persist a valid API key for later use (feeds, retry, AJAX without POST body).
	 *
	 * @param string $api_key Raw API key.
	 */
	private function store_api_key( string $api_key ): void {
		$api_key = $this->normalize_api_key( $api_key );

		if ( '' === $api_key ) {
			return;
		}

		$to_store = $api_key;

		if ( class_exists( 'GF_Odoo_Encryption' ) && GF_Odoo_Encryption::is_available() ) {
			$encrypted = GF_Odoo_Encryption::encrypt( $api_key );
			if ( '' !== $encrypted ) {
				$to_store = $encrypted;
			}
		}

		update_option( self::OPTION_API_KEY, $to_store, true );
	}

	/**
	 * Copy a usable API key into dedicated storage (feeds, cron, retry AJAX).
	 *
	 * @param array|null $settings Connection settings or overrides.
	 */
	private function persist_connection_api_key( ?array $settings = null ): void {
		$settings = is_array( $settings ) ? $settings : $this->get_connection_settings();
		$api_key  = $this->resolve_api_key( $settings );

		if ( '' !== $api_key ) {
			$this->store_api_key( $api_key );
		}
	}

	/**
	 * Ensure background sync can authenticate when the GF password field only shows placeholders.
	 */
	private function ensure_stored_api_key(): void {
		if ( '' !== $this->get_api_key() ) {
			return;
		}

		$this->persist_connection_api_key();
	}

	/**
	 * One-time migration: encrypt plaintext API keys in wp_options.
	 */
	public function maybe_migrate_api_key_encryption(): void {
		if ( ! is_admin() || ! class_exists( 'GF_Odoo_Encryption' ) || ! GF_Odoo_Encryption::is_available() ) {
			return;
		}

		if ( get_transient( 'gf_odoo_api_key_encrypted_v1' ) ) {
			return;
		}

		$stored = (string) get_option( self::OPTION_API_KEY, '' );

		if ( '' !== $stored && ! GF_Odoo_Encryption::is_encrypted( $stored ) ) {
			$this->store_api_key( $stored );
		}

		set_transient( 'gf_odoo_api_key_encrypted_v1', 1, YEAR_IN_SECONDS );
	}

	/**
	 * Admin notice when OpenSSL is unavailable.
	 */
	public function maybe_render_openssl_notice(): void {
		if ( ! $this->is_plugin_admin_page_request() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( class_exists( 'GF_Odoo_Encryption' ) && GF_Odoo_Encryption::is_available() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__(
				'GF Odoo Connector cannot encrypt the Odoo API key until the PHP OpenSSL extension is enabled on this server.',
				'gf-odoo-connector'
			)
		);
	}

	/**
	 * Trim the API key and ignore masked placeholder values from password fields.
	 *
	 * @param string $api_key Raw API key value.
	 *
	 * @return string
	 */
	private function normalize_api_key( string $api_key ): string {
		$api_key = trim( $api_key );

		if ( '' === $api_key ) {
			return '';
		}

		if ( preg_match( '/^[\*•·\.]+$/u', $api_key ) ) {
			return '';
		}

		return $api_key;
	}

	/**
	 * AJAX handler for the Test Connection button.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'gf_odoo_test_connection', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ),
				),
				403
			);
		}

		$saved     = (array) $this->get_plugin_settings();
		$overrides = array(
			'odoo_url'    => $this->get_test_connection_setting( 'odoo_url', 'sanitize_text_field', $saved ),
			'db_name'     => $this->get_test_connection_setting( 'db_name', 'sanitize_text_field', $saved ),
			'login_email' => $this->get_test_connection_setting( 'login_email', 'sanitize_email', $saved ),
			'api_key'     => $this->get_test_connection_setting( 'api_key', 'sanitize_text_field', $saved ),
		);

		$api = $this->get_odoo_api( $overrides );

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please fill in all Odoo connection fields.', 'gf-odoo-connector' ),
				)
			);
		}

		$result = $api->test_connection();

		if ( $result['success'] ) {
			$this->persist_connection_api_key( $overrides );
			$this->clear_assignment_cache();
			$this->set_connection_status( 'success', (string) $result['message'] );
			wp_send_json_success( $result );
		}

		$this->set_connection_status( 'error', (string) $result['message'] );
		wp_send_json_error( $result );
	}

	/**
	 * AJAX handler: send a sample message to the configured AI provider and
	 * report whether classification works (Smart routing Beta "Test AI" button).
	 */
	public function ajax_test_ai() {
		check_ajax_referer( 'gf_odoo_test_ai', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ) ),
				403
			);
		}

		$config = $this->get_smart_routing_config();

		// The Test button should work regardless of the live engine setting, as
		// long as a key/endpoint exist; force the AI path on for the probe.
		$config['ai_enabled'] = true;

		$default_sample = esc_html__( 'Hello, we offer professional SEO and link building services and would like to discuss a partnership to improve your website ranking.', 'gf-odoo-connector' );
		$sample         = isset( $_POST['sample'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['sample'] ) ) : '';
		$sample         = '' !== trim( $sample ) ? $sample : $default_sample;

		$result = GF_Odoo_AI_Classifier::diagnose( $sample, $config );

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX handler: fetch helpdesk.team list for feed settings.
	 */
	public function ajax_get_teams() {
		check_ajax_referer( 'gf_odoo_get_teams', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ),
				),
				403
			);
		}

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$helpdesk = new Helpdesk_Handler( $api );
			$teams    = $this->get_cached_odoo_options(
				'helpdesk_teams',
				static function () use ( $helpdesk ) {
					return $helpdesk->get_teams();
				}
			);

			if ( ! empty( $teams ) ) {
				set_transient( self::TRANSIENT_HELPDESK_TEAMS, $teams, self::ASSIGNMENT_CACHE_TTL );
			}

			wp_send_json_success( $teams );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX: fetch industries and sub-industries for CRM feed settings.
	 */
	public function ajax_get_industries(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$crm  = new CRM_Handler( $api );
			$data = $this->get_cached_odoo_options(
				'industries',
				static function () use ( $crm ) {
					return $crm->get_industries();
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX: fetch sub-industries (optionally filtered by parent industry).
	 */
	public function ajax_get_sub_industries(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		$industry_id = isset( $_POST['industry_id'] ) ? absint( $_POST['industry_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			$crm  = new CRM_Handler( $api );
			$data = $this->get_cached_odoo_options(
				'sub_industries_' . $industry_id,
				static function () use ( $crm, $industry_id ) {
					return $crm->get_sub_industries( $industry_id );
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX: fetch UTM sources for CRM feed settings.
	 */
	public function ajax_get_sources(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$crm  = new CRM_Handler( $api );
			$data = $this->get_cached_odoo_options(
				'sources',
				static function () use ( $crm ) {
					return $crm->get_sources();
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX: fetch sub lead sources (optionally filtered by parent source).
	 */
	public function ajax_get_sub_lead_sources(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			$crm  = new CRM_Handler( $api );
			$data = $this->get_cached_odoo_options(
				'sub_lead_sources_' . $source_id,
				static function () use ( $crm, $source_id ) {
					return $crm->get_sub_lead_sources( $source_id );
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Verify nonce and capability for CRM feed AJAX handlers.
	 */
	private function verify_crm_ajax_request(): void {
		$this->verify_ajax_request( 'gf_odoo_nonce' );
	}

	/**
	 * Whether GF Odoo admin scripts/styles should load on the current screen.
	 *
	 * @return bool
	 */
	public function should_enqueue_odoo_admin_assets(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		if ( $this->is_plugin_admin_page()
			|| $this->is_error_log_page()
			|| $this->is_entry_detail_page()
			|| $this->is_dashboard_page()
			|| $this->is_templates_admin_page()
		) {
			return true;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}

		$id = (string) $screen->id;

		return str_contains( $id, 'gf_edit_forms' )
			|| str_contains( $id, 'forms_page_' )
			|| str_contains( $id, 'gf-odoo' )
			|| str_contains( $id, 'gf_odoo' );
	}

	/**
	 * Cache Odoo dropdown/API option lists for admin feed settings (1 hour).
	 *
	 * @param string   $cache_key Cache segment (e.g. industries, helpdesk_teams).
	 * @param callable $fetcher   Returns option array; may throw.
	 *
	 * @return array
	 */
	private function get_cached_odoo_options( string $cache_key, callable $fetcher ): array {
		$transient_key = 'gf_odoo_options_' . sanitize_key( $cache_key );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		try {
			$data = $fetcher();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			set_transient( $transient_key, $data, HOUR_IN_SECONDS );
			return $data;
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * AJAX: clear cached Odoo option transients and dashboard counts.
	 */
	public function ajax_clear_cache(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() && ! current_user_can( 'gravityforms_edit_forms' ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ) ),
				403
			);
		}

		$this->clear_odoo_options_cache();

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Cache cleared.', 'gf-odoo-connector' ),
			)
		);
	}

	/**
	 * Default global plugin settings (fresh install state).
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_plugin_settings(): array {
		return array(
			'odoo_url'                   => '',
			'db_name'                    => '',
			'login_email'                => '',
			'test_mode'                  => '',
			'notify_on_error'            => '',
			'notify_email'               => '',
			'log_payload_in_entry_notes' => '',
			'keep_data_on_uninstall'     => '',
			'webhook_secret'             => '',
			'default_crm_user_id'        => '',
			'default_crm_team_id'        => '',
			'force_crm_assignment'       => '',
			'smart_routing_enabled'      => '',
			'smart_routing_mode'         => 'log',
			'smart_routing_engine'       => 'hybrid',
			'smart_routing_spam_keywords'    => implode( "\n", GF_Odoo_Lead_Classifier::default_spam_keywords() ),
			'smart_routing_sales_keywords'   => implode( "\n", GF_Odoo_Lead_Classifier::default_sales_keywords() ),
			'smart_routing_support_keywords' => implode( "\n", GF_Odoo_Lead_Classifier::default_support_keywords() ),
			'smart_routing_blocked_domains'  => implode( "\n", GF_Odoo_Lead_Classifier::default_blocked_domains() ),
			'smart_routing_max_links'        => '3',
			'smart_routing_spam_threshold'   => '2',
			'smart_routing_confidence_threshold' => '2',
			'smart_routing_helpdesk_team_id' => '',
			'smart_routing_helpdesk_desc_field' => 'description',
			'smart_routing_review_tag'       => 'Needs review',
			'smart_routing_web_lead_tag'     => '',
			'smart_routing_ai_provider'      => 'mistral',
			'smart_routing_ai_model'         => 'mistral-small-latest',
			'smart_routing_ai_base_url'      => '',
		);
	}

	/**
	 * Restore global plugin settings, API key, and volatile caches to defaults.
	 */
	public function reset_plugin_settings_to_defaults(): void {
		delete_option( 'gravityformsaddon_' . $this->get_slug() . '_settings' );
		delete_option( self::OPTION_API_KEY );
		delete_option( self::OPTION_AI_API_KEY );

		$this->clear_all_plugin_transients();
		$this->clear_assignment_cache();
		Odoo_API::clear_cached_session();
		$this->invalidate_connection_status();

		parent::update_plugin_settings( $this->get_default_plugin_settings() );
	}

	/**
	 * AJAX: reset plugin settings to defaults.
	 */
	public function ajax_reset_plugin_settings(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ) ),
				403
			);
		}

		$this->reset_plugin_settings_to_defaults();

		set_transient( 'gf_odoo_settings_reset_notice', 1, MINUTE_IN_SECONDS );

		wp_send_json_success(
			array(
				'message'  => esc_html__( 'Plugin settings reset to defaults.', 'gf-odoo-connector' ),
				'redirect' => admin_url( 'admin.php?page=gf_odoo_settings' ),
			)
		);
	}

	/**
	 * One-time notice after settings reset.
	 */
	public function maybe_render_settings_reset_notice(): void {
		if ( ! $this->current_user_can_manage_plugin() || ! get_transient( 'gf_odoo_settings_reset_notice' ) ) {
			return;
		}

		delete_transient( 'gf_odoo_settings_reset_notice' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'gf_odoo_settings' !== sanitize_key( (string) $_GET['page'] ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__(
				'GF Odoo Connector settings were reset to their defaults. Re-enter your Odoo connection details to sync again.',
				'gf-odoo-connector'
			)
			. '</p></div>';
	}

	/**
	 * Delete all plugin transients (broader than dropdown cache only).
	 */
	public function clear_all_plugin_transients(): void {
		global $wpdb;

		$this->clear_odoo_options_cache();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_gf_odoo_%'
			OR option_name LIKE '_transient_timeout_gf_odoo_%'"
		);

		delete_transient( 'gf_odoo_session' );
		delete_transient( 'gf_odoo_api_key_encrypted_v1' );
		delete_transient( 'gf_odoo_helpdesk_fields_debug' );
	}

	/**
	 * AJAX: load CRM sales teams and salespeople for feed assignment dropdowns.
	 */
	public function ajax_get_crm_assignment(): void {
		$this->verify_ajax_request( 'gf_odoo_nonce' );

		delete_transient( self::TRANSIENT_CRM_TEAMS );
		delete_transient( self::TRANSIENT_CRM_USERS );
		delete_transient( 'gf_odoo_options_sales_teams' );
		delete_transient( 'gf_odoo_options_salespeople' );

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		$assignment = $this->get_odoo_assignment_data();
		$user_teams = array();

		foreach ( $assignment['users'] as $user ) {
			if ( ! empty( $user['team_id'] ) ) {
				$user_teams[ (string) $user['value'] ] = (int) $user['team_id'];
			}
		}

		if ( empty( $assignment['teams'] ) && empty( $assignment['users'] ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__(
						'No sales teams or users were returned from Odoo. Check API permissions for crm.team and res.users.',
						'gf-odoo-connector'
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'teams'     => $assignment['teams'],
				'users'     => $assignment['users'],
				'userTeams' => $user_teams,
			)
		);
	}

	/**
	 * Delete all gf_odoo_options_* transients and related admin caches.
	 */
	public function clear_odoo_options_cache(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_gf_odoo_options_%'
			OR option_name LIKE '_transient_timeout_gf_odoo_options_%'"
		);

		delete_transient( self::TRANSIENT_CRM_TEAMS );
		delete_transient( self::TRANSIENT_CRM_USERS );
		delete_transient( self::TRANSIENT_HELPDESK_TEAMS );

		if ( class_exists( 'Dashboard' ) ) {
			Dashboard::invalidate_summary_counts_cache();
		}

		Odoo_API::clear_session();
	}

	/**
	 * AJAX: helpdesk teams for per-field feed settings.
	 */
	public function ajax_get_helpdesk_teams(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$helpdesk = new Helpdesk_Handler( $api );
			$data     = $this->get_cached_odoo_options(
				'helpdesk_teams',
				static function () use ( $helpdesk ) {
					return $helpdesk->get_teams();
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: inquiry / ticket categories.
	 */
	public function ajax_get_ticket_categories(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$helpdesk = new Helpdesk_Handler( $api );
			$data     = $this->get_cached_odoo_options(
				'ticket_categories',
				static function () use ( $helpdesk ) {
					return $helpdesk->get_ticket_categories();
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: branch list.
	 */
	public function ajax_get_branches(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$helpdesk = new Helpdesk_Handler( $api );
			$data     = $this->get_cached_odoo_options(
				'branches',
				static function () use ( $helpdesk ) {
					return $helpdesk->get_branches();
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: country states.
	 */
	public function ajax_get_states(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$helpdesk = new Helpdesk_Handler( $api );
			$data     = $this->get_cached_odoo_options(
				'states',
				static function () use ( $helpdesk ) {
					return $helpdesk->get_states();
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: countries.
	 */
	public function ajax_get_countries(): void {
		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$helpdesk = new Helpdesk_Handler( $api );
			$data     = $this->get_cached_odoo_options(
				'countries',
				static function () use ( $helpdesk ) {
					return $helpdesk->get_countries();
				}
			);
			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: test country name → Odoo ID resolver (WP_DEBUG only).
	 */
	public function ajax_debug_country_resolve(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			wp_die( esc_html__( 'Debug mode not enabled', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$this->verify_crm_ajax_request();

		$input = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['country'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! class_exists( 'GF_Odoo_Country_Map' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Country map class not loaded.', 'gf-odoo-connector' ) ) );
		}

		$id = GF_Odoo_Country_Map::resolve( $input );

		wp_send_json_success(
			array(
				'input'    => $input,
				'resolved' => $id,
				'message'  => null !== $id
					? sprintf(
						/* translators: 1: country input, 2: Odoo ID */
						__( 'Resolved "%1$s" → Odoo country ID: %2$d', 'gf-odoo-connector' ),
						$input,
						$id
					)
					: sprintf(
						/* translators: %s: country input */
						__( 'Could not resolve "%s": not found in country map', 'gf-odoo-connector' ),
						$input
					),
			)
		);
	}

	/**
	 * AJAX: test industry name → Odoo ID resolver (WP_DEBUG only).
	 */
	public function ajax_debug_industry_resolve(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			wp_die( esc_html__( 'Debug mode not enabled', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$this->verify_crm_ajax_request();

		$input = isset( $_POST['industry'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['industry'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! class_exists( 'GF_Odoo_Industry_Map' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Industry map class not loaded.', 'gf-odoo-connector' ) ) );
		}

		$id = GF_Odoo_Industry_Map::resolve( $input );

		if ( null !== $id ) {
			wp_send_json_success(
				array(
					'input'    => $input,
					'resolved' => $id,
					'message'  => sprintf(
						/* translators: 1: industry input, 2: Odoo ID */
						__( 'Resolved "%1$s" → Odoo industry ID: %2$d', 'gf-odoo-connector' ),
						$input,
						$id
					),
				)
			);
		}

		$available = array_keys( GF_Odoo_Industry_Map::get_map() );

		wp_send_json_success(
			array(
				'input'    => $input,
				'resolved' => null,
				'message'  => sprintf(
					/* translators: 1: input, 2: comma-separated industry keys */
					__( 'Could not resolve "%1$s". Available (lowercase keys): %2$s', 'gf-odoo-connector' ),
					$input,
					implode( ', ', $available )
				),
			)
		);
	}

	/**
	 * AJAX: fields_get on an Odoo model (WP_DEBUG only).
	 */
	public function ajax_debug_odoo_model_fields(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Debug tools are disabled.', 'gf-odoo-connector' ),
				)
			);
		}

		$this->verify_crm_ajax_request();

		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $model || ! preg_match( '/^[a-z][a-z0-9_.]*$/', $model ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid model name.', 'gf-odoo-connector' ) ) );
		}

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$fields = $api->call(
				$model,
				'fields_get',
				array(),
				array(
					'attributes' => array( 'string', 'type', 'relation' ),
				)
			);

			if ( ! is_array( $fields ) ) {
				$fields = array();
			}

			ksort( $fields );

			wp_send_json_success(
				array(
					'model'   => $model,
					'fields'  => $fields,
					'message' => sprintf(
						/* translators: 1: model name, 2: field count */
						__( '%1$s: %2$d fields', 'gf-odoo-connector' ),
						$model,
						count( $fields )
					),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: fields_get on helpdesk.ticket (WP_DEBUG only).
	 */
	public function ajax_debug_helpdesk_fields(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Debug tools are disabled.', 'gf-odoo-connector' ),
				)
			);
		}

		$this->verify_crm_ajax_request();

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please configure the Odoo connection first.', 'gf-odoo-connector' ),
				)
			);
		}

		try {
			$helpdesk = new Helpdesk_Handler( $api );
			$fields   = $helpdesk->get_ticket_fields_metadata();
			$lines    = $this->format_helpdesk_fields_debug_list( $fields );

			set_transient( 'gf_odoo_helpdesk_fields_debug', $fields, HOUR_IN_SECONDS );

			wp_send_json_success(
				array(
					'html'  => $this->render_helpdesk_fields_debug_html( $lines ),
					'count' => count( $lines ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Resolve a setting from the AJAX request or saved plugin settings.
	 *
	 * @param string               $key         Setting key.
	 * @param callable-string|null $sanitize    Sanitize callback.
	 * @param array                $saved       Saved plugin settings.
	 *
	 * @return string
	 */
	private function get_test_connection_setting( string $key, ?string $sanitize, array $saved ): string {
		$raw = $this->get_posted_setting_value( $key );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$value = is_callable( $sanitize ) ? call_user_func( $sanitize, $raw ) : $raw;

			if ( 'api_key' === $key ) {
				$normalized = $this->normalize_api_key( (string) $value );
				if ( '' !== $normalized && ! $this->is_masked_api_key( (string) $value ) ) {
					return $normalized;
				}
				return $this->get_api_key();
			}

			return (string) $value;
		}

		$saved_value = (string) rgar( $saved, $key );

		if ( 'api_key' === $key ) {
			return $this->get_api_key();
		}

		return $saved_value;
	}

	/**
	 * Read a setting value from the GF settings form POST (prefixed field names).
	 *
	 * @param string $key Setting key without prefix.
	 *
	 * @return string
	 */
	private function get_posted_setting_value( string $key ): string {
		$prefixes = array(
			'_gform_setting_',
			'_gaddon_setting_',
		);

		foreach ( $prefixes as $prefix ) {
			$raw = rgpost( $prefix . $key );

			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				return (string) wp_unslash( $raw );
			}
		}

		$raw = rgpost( $key );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			return (string) wp_unslash( $raw );
		}

		return '';
	}

	/**
	 * Human-readable plugin name.
	 *
	 * @return string
	 */
	public function plugin_name() {
		return $this->_title;
	}

	/**
	 * Plugin slug used in URLs and settings keys.
	 *
	 * @return string
	 */
	public function plugin_slug() {
		return $this->_slug;
	}

	/**
	 * Plugin version string.
	 *
	 * @return string
	 */
	public function plugin_version() {
		return $this->_version;
	}

	/**
	 * Global plugin settings field definitions (Forms > Settings > GF Odoo Connector).
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		$sections = $this->get_all_plugin_settings_sections();

		if ( null === $this->admin_settings_page_filter ) {
			return $sections;
		}

		$section_count = count( $sections );

		$map = array(
			'connection'    => array( 0, 3 ),
			'notifications' => array( 1 ),
			'webhook'       => array( 2 ),
			// Smart routing is split across several cards appended after the
			// four core sections (indexes 0-3).
			'smart_routing' => $section_count > 4 ? range( 4, $section_count - 1 ) : array(),
		);

		$indices = $map[ $this->admin_settings_page_filter ] ?? array();
		$filtered = array();

		foreach ( $indices as $index ) {
			if ( isset( $sections[ $index ] ) ) {
				$filtered[] = $sections[ $index ];
			}
		}

		return $filtered;
	}

	/**
	 * All global plugin settings sections.
	 *
	 * @return array
	 */
	private function get_all_plugin_settings_sections(): array {
		$connection_fields = array(
					array(
						'name'     => 'odoo_url',
						'label'    => esc_html__( 'Odoo URL', 'gf-odoo-connector' ),
						'type'     => 'text',
						'class'    => 'large',
						'required' => true,
						'description'  => esc_html__(
							'Your Odoo site root URL (the same address you use to open the shop or login page), without /web/login. Example: https://inbody-europe.closyss.com',
							'gf-odoo-connector'
						),
					),
					array(
						'name'     => 'db_name',
						'label'    => esc_html__( 'Database name', 'gf-odoo-connector' ),
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
						'description'  => esc_html__(
							'The Odoo database name (shown on the login screen or in your Odoo URL).',
							'gf-odoo-connector'
						),
					),
					array(
						'name'    => 'login_email',
						'label'   => esc_html__( 'Login email', 'gf-odoo-connector' ),
						'type'    => 'text',
						'class'   => 'medium',
						'description' => esc_html__(
							'The exact login shown on your Odoo user profile (often your email). Required for legacy JSON-RPC fallback; optional when using Odoo 19 API keys only.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'          => 'api_key',
						'label'         => esc_html__( 'API Key', 'gf-odoo-connector' ),
						'type'          => 'text',
						'input_type'    => 'password',
						'class'         => 'large',
						'default_value' => $this->get_masked_api_key(),
						'description'   => esc_html__(
							'Your Odoo administrator API key. Stored encrypted. Generate in Odoo: Preferences → Account Security → New API Key.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'  => 'api_key_change',
						'label' => '',
						'type'  => 'html',
						'html'  => sprintf(
							'<p><button type="button" class="button button-small" id="gf-odoo-change-api-key">%s</button></p>',
							esc_html__( 'Change API key', 'gf-odoo-connector' )
						),
					),
					array(
						'name'  => 'connection_status',
						'label' => esc_html__( 'Connection status', 'gf-odoo-connector' ),
						'type'  => 'html',
						'html'  => $this->get_connection_status_markup(),
					),
					array(
						'name'  => 'test_connection',
						'label' => esc_html__( 'Connection', 'gf-odoo-connector' ),
						'type'  => 'html',
						'html'  => sprintf(
							'<button type="button" class="button" id="gf-odoo-test-connection">%1$s</button>'
							. '<span id="gf-odoo-test-connection-result" class="gf-odoo-test-result" style="margin-left:8px;" role="status" aria-live="polite"></span>',
							esc_html__( 'Test Connection', 'gf-odoo-connector' )
						),
					),
					array(
						'name'    => 'test_mode',
						'label'   => esc_html__( 'Test mode', 'gf-odoo-connector' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'  => 'test_mode',
								'label' => esc_html__(
									'Tag all created Odoo records as test data (adds "[TEST]" prefix to lead/ticket name)',
									'gf-odoo-connector'
								),
							),
						),
					),
					array(
						'name'  => 'error_log_link',
						'label' => esc_html__( 'Error log', 'gf-odoo-connector' ),
						'type'  => 'html',
						'html'  => sprintf(
							'<p><a href="%1$s" class="button button-secondary">%2$s</a></p>',
							esc_url( $this->get_error_log_admin_url() ),
							esc_html__( 'View error log', 'gf-odoo-connector' )
						),
					),
					array(
						'name'  => 'clear_odoo_cache',
						'label' => esc_html__( 'Odoo cache', 'gf-odoo-connector' ),
						'type'  => 'html',
						'html'  => sprintf(
							'<p><button type="button" class="button button-secondary" id="gf-odoo-clear-cache">%1$s</button>'
							. '<span id="gf-odoo-clear-cache-result" class="gf-odoo-test-result" style="margin-left:8px;" role="status" aria-live="polite"></span></p>'
							. '<p class="description">%2$s</p>',
							esc_html__( 'Clear cache', 'gf-odoo-connector' ),
							esc_html__(
								'Clears cached Odoo dropdown options (teams, industries, countries, etc.) and dashboard counts. Use after changing data in Odoo.',
								'gf-odoo-connector'
							)
						),
					),
					array(
						'name'  => 'reset_plugin_settings',
						'label' => esc_html__( 'Reset settings', 'gf-odoo-connector' ),
						'type'  => 'html',
						'html'  => sprintf(
							'<p><button type="button" class="button button-secondary gf-odoo-reset-settings-btn" id="gf-odoo-reset-settings">%1$s</button>'
							. '<span id="gf-odoo-reset-settings-result" class="gf-odoo-test-result" style="margin-left:8px;" role="status" aria-live="polite"></span></p>'
							. '<p class="description">%2$s</p>',
							esc_html__( 'Reset to defaults', 'gf-odoo-connector' ),
							esc_html__(
								'Restores all plugin settings to their defaults and removes the stored API key and caches. Does not delete form feeds, feed templates, sync history, or the error log.',
								'gf-odoo-connector'
							)
						),
					),
		);

		$debug_markup = $this->get_helpdesk_fields_debug_markup();
		if ( '' !== $debug_markup ) {
			$connection_fields[] = array(
				'name'  => 'helpdesk_fields_debug',
				'label' => esc_html__( 'Developer tools', 'gf-odoo-connector' ),
				'type'  => 'html',
				'html'  => $debug_markup,
			);
		}

		$sections = array(
			array(
				'title'       => esc_html__( 'Odoo Connection', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Enter your Odoo instance URL, database, login email, and API key. These settings apply to all feeds.',
					'gf-odoo-connector'
				),
				'fields'      => $connection_fields,
			),
			array(
				'title'       => esc_html__( 'Error notifications', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Optionally receive an email when a form submission fails to sync to Odoo.',
					'gf-odoo-connector'
				),
				'fields'      => array(
					array(
						'name'    => 'notify_on_error',
						'label'   => esc_html__( 'Notify on error', 'gf-odoo-connector' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'label' => esc_html__( 'Send an email when a sync fails', 'gf-odoo-connector' ),
								'name'  => 'notify_on_error',
							),
						),
					),
					array(
						'name'          => 'notify_email',
						'label'         => esc_html__( 'Notification email', 'gf-odoo-connector' ),
						'type'          => 'text',
						'class'         => 'medium',
						'default_value' => get_option( 'admin_email' ),
						'description'       => esc_html__(
							'Leave empty to use the site admin email.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'    => 'log_payload_in_entry_notes',
						'label'   => esc_html__( 'Payload in entry notes', 'gf-odoo-connector' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'label' => esc_html__(
									'Add a note listing all field values sent to Odoo after each successful sync',
									'gf-odoo-connector'
								),
								'name'  => 'log_payload_in_entry_notes',
							),
						),
						'description' => esc_html__(
							'Useful for troubleshooting mappings (e.g. country_id). Disable on production if notes should stay minimal.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'    => 'keep_data_on_uninstall',
						'label'   => esc_html__( 'Keep data on uninstall', 'gf-odoo-connector' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'  => 'keep_data_on_uninstall',
								'label' => esc_html__(
									'Keep sync logs, templates, and settings when the plugin is deleted',
									'gf-odoo-connector'
								),
							),
						),
						'description' => esc_html__(
							'If unchecked, all plugin data will be permanently deleted when you remove the plugin from WordPress.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'  => 'export_all_data',
						'label' => esc_html__( 'GDPR data export', 'gf-odoo-connector' ),
						'type'  => 'html',
						'html'  => $this->get_gdpr_export_markup(),
					),
				),
			),
			array(
				'title'       => esc_html__( 'Webhook receiver (Odoo → WordPress)', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Configure Odoo to send updates when tickets or leads change. In Odoo: Settings → Technical → Automation → Webhooks.',
					'gf-odoo-connector'
				),
				'fields'      => array(
					array(
						'name'  => 'webhook_url',
						'label' => esc_html__( 'Webhook URL', 'gf-odoo-connector' ),
						'type'  => 'html',
						'html'  => $this->get_webhook_url_markup(),
					),
					array(
						'name'       => 'webhook_secret',
						'label'      => esc_html__( 'Webhook secret', 'gf-odoo-connector' ),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'large',
						'description'    => esc_html__(
							'Odoo must send this value in the X-Odoo-Signature header as HMAC-SHA256 of the raw request body. Leave empty to accept all requests (not recommended for production).',
							'gf-odoo-connector'
						),
					),
					array(
						'name'  => 'webhook_log',
						'label' => esc_html__( 'Webhook log', 'gf-odoo-connector' ),
						'type'  => 'html',
						'html'  => $this->get_webhook_log_markup(),
					),
				),
			),
			array(
				'title'       => esc_html__( 'CRM assignment (all forms)', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Set the default salesperson and sales team applied to new leads from every CRM form. Individual feeds can still override this in their own settings.',
					'gf-odoo-connector'
				),
				'fields'      => array(
					array(
						'name'  => 'crm_assignment_refresh_global',
						'label' => '',
						'type'  => 'html',
						'html'  => sprintf(
							'<p class="description">%1$s <button type="button" class="button button-small" id="gf-odoo-refresh-global-crm-assignment">%2$s</button> <span id="gf-odoo-global-crm-assignment-status" class="gf-odoo-test-result" role="status"></span></p>',
							esc_html__(
								'Salespeople and teams are loaded from Odoo. If the lists are empty, run Test Connection above, then refresh.',
								'gf-odoo-connector'
							),
							esc_html__( 'Refresh from Odoo', 'gf-odoo-connector' )
						),
					),
					array(
						'name'        => 'default_crm_user_id',
						'label'       => esc_html__( 'Default salesperson', 'gf-odoo-connector' ),
						'type'        => 'select',
						'choices'     => $this->get_crm_user_field_choices(),
						'class'       => 'gf-odoo-global-crm-user-select medium',
						'description' => esc_html__(
							'Assigned to new leads from all CRM forms unless a specific feed overrides it.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'        => 'default_crm_team_id',
						'label'       => esc_html__( 'Default sales team', 'gf-odoo-connector' ),
						'type'        => 'select',
						'choices'     => $this->get_crm_team_field_choices(),
						'class'       => 'gf-odoo-global-crm-team-select medium',
						'description' => esc_html__(
							'Sales team for new leads from all CRM forms unless a feed overrides it. Leave empty to use the salesperson’s default team.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'    => 'force_crm_assignment',
						'label'   => esc_html__( 'Force on all forms', 'gf-odoo-connector' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'  => 'force_crm_assignment',
								'label' => esc_html__(
									'Always use the default salesperson and sales team above, ignoring per-form CRM assignment',
									'gf-odoo-connector'
								),
							),
						),
						'description' => esc_html__(
							'When enabled, every CRM form uses the global salesperson and sales team set above and any per-form override is ignored.',
							'gf-odoo-connector'
						),
					),
				),
			),
		);

		return array_merge( $sections, $this->get_smart_routing_settings_sections() );
	}

	/**
	 * Smart routing (Beta) settings, split into focused cards.
	 *
	 * @return array<int, array>
	 */
	private function get_smart_routing_settings_sections(): array {
		$defaults = $this->get_default_plugin_settings();

		$overview_section = array(
			'title'       => esc_html__( 'Smart routing (Beta)', 'gf-odoo-connector' ),
			'description' => esc_html__(
				'Automatically read each contact-form submission and send it to the right place: a CRM lead (sales), a Helpdesk ticket (support), or skip it (vendor/spam).',
				'gf-odoo-connector'
			),
			'fields'      => array(
				array(
					'name'  => 'smart_routing_beta_notice',
					'label' => '',
					'type'  => 'html',
					'html'  => $this->get_smart_routing_beta_notice_markup(),
				),
				array(
					'name'  => 'smart_routing_how_it_works',
					'label' => '',
					'type'  => 'html',
					'html'  => $this->get_smart_routing_how_it_works_markup(),
				),
				array(
					'name'    => 'smart_routing_enabled',
					'label'   => esc_html__( 'Enable Smart Routing (Beta)', 'gf-odoo-connector' ),
					'type'    => 'checkbox',
					'tooltip' => esc_html__( 'Master switch for the whole site. When off, every feed behaves exactly as before. After turning this on, you also tick "Enable smart routing" on each form\'s Odoo feed.', 'gf-odoo-connector' ),
					'choices' => array(
						array(
							'name'  => 'smart_routing_enabled',
							'label' => esc_html__( 'Turn the smart routing feature on for this site', 'gf-odoo-connector' ),
						),
					),
					'description' => esc_html__(
						'Master switch. While off, all feeds behave exactly as before. You still need to enable smart routing on individual feeds.',
						'gf-odoo-connector'
					),
				),
				array(
					'name'          => 'smart_routing_mode',
					'label'         => esc_html__( 'Mode', 'gf-odoo-connector' ),
					'type'          => 'select',
					'default_value' => 'log',
					'tooltip'       => esc_html__( 'Start with "Log only" to watch the decisions in entry notes without changing anything. Switch to "Enforce" once you trust the results.', 'gf-odoo-connector' ),
					'choices'       => array(
						array(
							'label' => esc_html__( 'Off', 'gf-odoo-connector' ),
							'value' => 'off',
						),
						array(
							'label' => esc_html__( 'Log only (recommended first)', 'gf-odoo-connector' ),
							'value' => 'log',
						),
						array(
							'label' => esc_html__( 'Enforce (reroute submissions)', 'gf-odoo-connector' ),
							'value' => 'enforce',
						),
					),
					'description'   => esc_html__(
						'Log only records the decision in an entry note without changing routing. Enforce applies the decision (skip spam, create CRM lead or Helpdesk ticket).',
						'gf-odoo-connector'
					),
				),
				array(
					'name'          => 'smart_routing_engine',
					'label'         => esc_html__( 'Engine', 'gf-odoo-connector' ),
					'type'          => 'select',
					'default_value' => 'hybrid',
					'tooltip'       => esc_html__( 'Keyword only is free and instant. Hybrid additionally asks an AI to judge the messages keywords can\'t decide, for higher accuracy. The AI runs in the background, never slowing the form.', 'gf-odoo-connector' ),
					'choices'       => array(
						array(
							'label' => esc_html__( 'Keyword only', 'gf-odoo-connector' ),
							'value' => 'keyword',
						),
						array(
							'label' => esc_html__( 'Hybrid (keyword + AI)', 'gf-odoo-connector' ),
							'value' => 'hybrid',
						),
					),
					'description'   => esc_html__(
						'Keywords decide the obvious cases instantly. In Hybrid mode, uncertain cases are sent to the AI provider below (off the submission request). Keyword fallback always applies if the AI is unavailable.',
						'gf-odoo-connector'
					),
				),
			),
		);

		$keywords_section = array(
			'title'       => esc_html__( 'Classification keywords', 'gf-odoo-connector' ),
			'description' => esc_html__(
				'These word lists drive the instant keyword decision. One term or phrase per line; matching is case-insensitive. Multiple languages are fine on the same list.',
				'gf-odoo-connector'
			),
			'fields'      => array(
				array(
					'name'          => 'smart_routing_spam_keywords',
					'label'         => esc_html__( 'Spam / vendor keywords', 'gf-odoo-connector' ),
					'type'          => 'textarea',
					'class'         => 'large',
					'default_value' => $defaults['smart_routing_spam_keywords'],
					'tooltip'       => esc_html__( 'Phrases typical of unsolicited sales pitches (SEO offers, link building, “partnership” spam). Strong matches let routing skip the submission entirely.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'One term or phrase per line. Matched submissions can be skipped (not synced to Odoo).', 'gf-odoo-connector' ),
				),
				array(
					'name'          => 'smart_routing_sales_keywords',
					'label'         => esc_html__( 'Sales keywords', 'gf-odoo-connector' ),
					'type'          => 'textarea',
					'class'         => 'large',
					'default_value' => $defaults['smart_routing_sales_keywords'],
					'tooltip'       => esc_html__( 'Words that signal buying intent (price, quote, demo, distributor). Matches create a CRM lead.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'One term or phrase per line. Matched submissions create a CRM lead.', 'gf-odoo-connector' ),
				),
				array(
					'name'          => 'smart_routing_support_keywords',
					'label'         => esc_html__( 'Support keywords', 'gf-odoo-connector' ),
					'type'          => 'textarea',
					'class'         => 'large',
					'default_value' => $defaults['smart_routing_support_keywords'],
					'tooltip'       => esc_html__( 'Words that signal an existing customer needs help (broken, repair, warranty, error). Matches create a Helpdesk ticket when a default team is set below.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'One term or phrase per line. Matched submissions create a Helpdesk ticket (needs a default team below).', 'gf-odoo-connector' ),
				),
			),
		);

		$spam_section = array(
			'title'       => esc_html__( 'Spam protection', 'gf-odoo-connector' ),
			'description' => esc_html__(
				'Extra signals that push a submission toward "spam". Each spam keyword, blocked domain, or excess link adds to a score; when the score reaches the threshold, the message is treated as spam.',
				'gf-odoo-connector'
			),
			'fields'      => array(
				array(
					'name'          => 'smart_routing_blocked_domains',
					'label'         => esc_html__( 'Blocked email domains', 'gf-odoo-connector' ),
					'type'          => 'textarea',
					'class'         => 'medium',
					'default_value' => $defaults['smart_routing_blocked_domains'],
					'tooltip'       => esc_html__( 'One domain per line. A sender from any of these domains gets a strong spam score boost.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'One domain per line (e.g. mailinator.com). Submissions from these domains count strongly toward spam.', 'gf-odoo-connector' ),
				),
				array(
					'name'          => 'smart_routing_max_links',
					'label'         => esc_html__( 'Max links before spam', 'gf-odoo-connector' ),
					'type'          => 'text',
					'input_type'    => 'number',
					'class'         => 'small',
					'default_value' => '3',
					'tooltip'       => esc_html__( 'Genuine enquiries rarely contain many URLs. Messages with more links than this gain spam score.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'Messages with more links than this are nudged toward spam.', 'gf-odoo-connector' ),
				),
				array(
					'name'          => 'smart_routing_spam_threshold',
					'label'         => esc_html__( 'Spam threshold', 'gf-odoo-connector' ),
					'type'          => 'text',
					'input_type'    => 'number',
					'class'         => 'small',
					'default_value' => '2',
					'tooltip'       => esc_html__( 'The spam score needed to classify a message as spam. Lower = stricter (catches more, risks false positives); higher = more lenient.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'Minimum spam score to treat a submission as spam.', 'gf-odoo-connector' ),
				),
			),
		);

		$routing_section = array(
			'title'       => esc_html__( 'Routing targets & tags', 'gf-odoo-connector' ),
			'description' => esc_html__(
				'Where confident decisions go in Odoo, and how uncertain ones are flagged for a human to review.',
				'gf-odoo-connector'
			),
			'fields'      => array(
				array(
					'name'          => 'smart_routing_confidence_threshold',
					'label'         => esc_html__( 'Confidence threshold', 'gf-odoo-connector' ),
					'type'          => 'text',
					'input_type'    => 'number',
					'class'         => 'small',
					'default_value' => '2',
					'tooltip'       => esc_html__( 'How many keyword hits are needed before routing trusts a Sales/Support guess. Below this, Hybrid mode asks the AI; otherwise the lead is created and tagged for review.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'Minimum keyword hits to confidently pick Sales or Support. Below this, Hybrid mode asks the AI; otherwise it routes to a CRM lead flagged for review.', 'gf-odoo-connector' ),
				),
				array(
					'name'        => 'smart_routing_helpdesk_team_id',
					'label'       => esc_html__( 'Default Helpdesk team', 'gf-odoo-connector' ),
					'type'        => 'select',
					'choices'     => $this->get_helpdesk_team_field_choices(),
					'class'       => 'medium',
					'tooltip'     => esc_html__( 'Support tickets created by routing are assigned to this team. If left empty, support messages fall back to a CRM lead flagged for review.', 'gf-odoo-connector' ),
					'description' => esc_html__( 'Tickets created by smart routing are assigned to this team. Without a team, support messages become CRM leads flagged for review.', 'gf-odoo-connector' ),
				),
				array(
					'name'          => 'smart_routing_helpdesk_desc_field',
					'label'         => esc_html__( 'Ticket body field', 'gf-odoo-connector' ),
					'type'          => 'select',
					'default_value' => 'description',
					'choices'       => $this->get_helpdesk_text_field_choices(),
					'class'         => 'medium',
					'tooltip'       => esc_html__( 'The list is read live from your Odoo. Pick the field that shows as "Issue Description" on the ticket form so the visitor\'s message lands in the right tab.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'Which Odoo ticket field receives the visitor\'s message. Pick the one shown as "Issue Description" on your helpdesk form (the standard "description" field may be used for Resolution on customised instances).', 'gf-odoo-connector' ),
				),
				array(
					'name'          => 'smart_routing_review_tag',
					'label'         => esc_html__( 'Needs-review tag', 'gf-odoo-connector' ),
					'type'          => 'text',
					'class'         => 'medium',
					'default_value' => 'Needs review',
					'tooltip'       => esc_html__( 'CRM tag added to uncertain leads so your team can double-check the routing. Created in Odoo automatically if it doesn\'t exist.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'CRM tag added to uncertain leads so your team can double-check routing. Created in Odoo if missing.', 'gf-odoo-connector' ),
				),
				array(
					'name'        => 'smart_routing_web_lead_tag',
					'label'       => esc_html__( 'Web lead tag (optional)', 'gf-odoo-connector' ),
					'type'        => 'text',
					'class'       => 'medium',
					'tooltip'     => esc_html__( 'Optional CRM tag added to every lead routing creates, so you can filter web-sourced leads in Odoo. Leave empty to skip.', 'gf-odoo-connector' ),
					'description' => esc_html__( 'Optional CRM tag added to every lead created by smart routing. Leave empty to skip.', 'gf-odoo-connector' ),
				),
			),
		);

		$ai_section = array(
			'title'       => esc_html__( 'AI provider (Hybrid mode)', 'gf-odoo-connector' ),
			'description' => esc_html__(
				'Only used when Engine is set to Hybrid. The AI is asked to classify only the messages keywords couldn\'t decide, and runs in the background after the form is submitted. EU-based Mistral is the default.',
				'gf-odoo-connector'
			),
			'fields'      => array(
				array(
					'name'  => 'smart_routing_ai_privacy',
					'label' => '',
					'type'  => 'html',
					'html'  => $this->get_smart_routing_ai_privacy_markup(),
				),
				array(
					'name'          => 'smart_routing_ai_provider',
					'label'         => esc_html__( 'AI provider', 'gf-odoo-connector' ),
					'type'          => 'select',
					'default_value' => 'mistral',
					'tooltip'       => esc_html__( 'Mistral is hosted in the EU (GDPR-friendly). Choose "Custom" to point at any OpenAI-compatible endpoint, e.g. Azure OpenAI in an EU region.', 'gf-odoo-connector' ),
					'choices'       => array(
						array(
							'label' => esc_html__( 'Mistral (EU)', 'gf-odoo-connector' ),
							'value' => 'mistral',
						),
						array(
							'label' => esc_html__( 'Custom OpenAI-compatible endpoint', 'gf-odoo-connector' ),
							'value' => 'custom',
						),
					),
					'description'   => esc_html__( 'Mistral is EU-based. For a custom endpoint (e.g. Azure OpenAI in an EU region), set the base URL below.', 'gf-odoo-connector' ),
				),
				array(
					'name'          => 'smart_routing_ai_key',
					'label'         => esc_html__( 'AI API key', 'gf-odoo-connector' ),
					'type'          => 'text',
					'input_type'    => 'password',
					'class'         => 'large',
					'default_value' => $this->get_masked_ai_key(),
					'tooltip'       => esc_html__( 'Your provider API key. Stored encrypted and shown masked after saving. Required for Hybrid mode.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'Stored encrypted. Required for Hybrid mode.', 'gf-odoo-connector' ),
				),
				array(
					'name'          => 'smart_routing_ai_model',
					'label'         => esc_html__( 'AI model', 'gf-odoo-connector' ),
					'type'          => 'text',
					'class'         => 'medium',
					'default_value' => 'mistral-small-latest',
					'tooltip'       => esc_html__( 'The model name to call. For Mistral, mistral-small-latest is a good, low-cost default.', 'gf-odoo-connector' ),
					'description'   => esc_html__( 'Model name, e.g. mistral-small-latest.', 'gf-odoo-connector' ),
				),
				array(
					'name'        => 'smart_routing_ai_base_url',
					'label'       => esc_html__( 'AI base URL (custom)', 'gf-odoo-connector' ),
					'type'        => 'text',
					'class'       => 'large',
					'tooltip'     => esc_html__( 'Only needed for the Custom provider. Paste the full chat completions URL, or a base URL and the plugin appends /v1/chat/completions.', 'gf-odoo-connector' ),
					'description' => esc_html__( 'Only for the custom provider. Full chat completions URL, or a base URL (the plugin appends /v1/chat/completions).', 'gf-odoo-connector' ),
				),
				array(
					'name'        => 'smart_routing_test_ai',
					'label'       => esc_html__( 'Test AI', 'gf-odoo-connector' ),
					'type'        => 'html',
					'tooltip'     => esc_html__( 'Sends one sample message to your provider right now and shows the result or the exact error, so you can confirm the key, model, and endpoint work.', 'gf-odoo-connector' ),
					'html'        => sprintf(
						'<p class="description" style="margin:0 0 6px;">%1$s</p>'
						. '<textarea id="gf-odoo-ai-test-sample" rows="3" class="large-text" placeholder="%2$s"></textarea>'
						. '<p style="margin:6px 0 0;"><button type="button" class="button button-secondary" id="gf-odoo-test-ai">%3$s</button>'
						. '<span id="gf-odoo-test-ai-result" class="gf-odoo-test-result" style="margin-left:8px;" role="status" aria-live="polite"></span></p>',
						esc_html__( 'Save the API key first, then send a sample message to confirm the key, model, and endpoint work. Leave blank to use a built-in spam sample.', 'gf-odoo-connector' ),
						esc_attr__( 'Optional: paste a sample message to classify…', 'gf-odoo-connector' ),
						esc_html__( 'Test AI', 'gf-odoo-connector' )
					),
				),
			),
		);

		return array(
			$overview_section,
			$keywords_section,
			$spam_section,
			$routing_section,
			$ai_section,
		);
	}

	/**
	 * Beta banner shown at the top of the Smart routing settings page.
	 *
	 * @return string
	 */
	private function get_smart_routing_beta_notice_markup(): string {
		return sprintf(
			'<div class="gf-odoo-beta-notice"><strong>%1$s</strong> %2$s</div>',
			esc_html__( 'Beta feature.', 'gf-odoo-connector' ),
			esc_html__(
				'Smart routing is experimental. Start with Mode = Log only, review the routing decisions in entry notes for a few days, tune the keyword lists, then switch to Enforce. It never deletes entries; vendor/spam is simply not synced to Odoo.',
				'gf-odoo-connector'
			)
		);
	}

	/**
	 * GDPR / privacy note for the AI subsection.
	 *
	 * @return string
	 */
	private function get_smart_routing_ai_privacy_markup(): string {
		return sprintf(
			'<div class="gf-odoo-note gf-odoo-note--info"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><div><strong>%1$s</strong> %2$s</div></div>',
			esc_html__( 'Privacy:', 'gf-odoo-connector' ),
			esc_html__(
				'Only uncertain submissions are sent, and only the message text plus the sender\'s email domain. Because messages can contain personal data, use an EU region, sign the provider\'s data-processing agreement, and disable training on your data.',
				'gf-odoo-connector'
			)
		);
	}

	/**
	 * Visual "how it works" panel for the Smart routing overview card.
	 *
	 * @return string
	 */
	private function get_smart_routing_how_it_works_markup(): string {
		$steps = array(
			array(
				'icon'  => 'dashicons-forms',
				'title' => esc_html__( 'Form submitted', 'gf-odoo-connector' ),
				'text'  => esc_html__( 'A visitor sends a generic contact form.', 'gf-odoo-connector' ),
			),
			array(
				'icon'  => 'dashicons-search',
				'title' => esc_html__( 'Keyword scan', 'gf-odoo-connector' ),
				'text'  => esc_html__( 'Instant, offline check classifies the obvious cases.', 'gf-odoo-connector' ),
			),
			array(
				'icon'  => 'dashicons-superhero',
				'title' => esc_html__( 'AI (if unsure)', 'gf-odoo-connector' ),
				'text'  => esc_html__( 'Hybrid mode asks the AI in the background for tricky messages.', 'gf-odoo-connector' ),
			),
			array(
				'icon'  => 'dashicons-randomize',
				'title' => esc_html__( 'Routed', 'gf-odoo-connector' ),
				'text'  => esc_html__( 'Sales → CRM lead · Support → Helpdesk ticket · Spam → skipped.', 'gf-odoo-connector' ),
			),
		);

		$items = '';

		foreach ( $steps as $i => $step ) {
			if ( $i > 0 ) {
				$items .= '<span class="gf-odoo-flow__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>';
			}

			$items .= sprintf(
				'<div class="gf-odoo-flow__step"><span class="gf-odoo-flow__icon dashicons %1$s" aria-hidden="true"></span><span class="gf-odoo-flow__title">%2$s</span><span class="gf-odoo-flow__text">%3$s</span></div>',
				esc_attr( $step['icon'] ),
				esc_html( $step['title'] ),
				esc_html( $step['text'] )
			);
		}

		return '<div class="gf-odoo-flow">' . $items . '</div>';
	}

	/**
	 * Per-feed settings field definitions.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		return array(
			array(
				'title'  => esc_html__( 'Template', 'gf-odoo-connector' ),
				'fields' => array(
					array(
						'name'  => 'gf_odoo_template_block',
						'label' => '',
						'type'  => 'html',
						'html'  => $this->render_feed_template_selector_html(),
					),
				),
			),
			array(
				'title'  => esc_html__( 'Feed Settings', 'gf-odoo-connector' ),
				'fields' => array(
					array(
						'name'     => 'feedName',
						'label'    => esc_html__( 'Feed name', 'gf-odoo-connector' ),
						'type'     => 'text',
						'class'    => 'medium',
						'required' => true,
						'description'  => esc_html__(
							'A unique name to identify this feed.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'          => 'module',
						'label'         => esc_html__( 'Module', 'gf-odoo-connector' ),
						'type'          => 'select',
						'default_value' => 'crm',
						'onchange'      => 'jQuery(this).parents("form").submit();',
						'choices'       => array(
							array(
								'label' => esc_html__( 'CRM', 'gf-odoo-connector' ),
								'value' => 'crm',
							),
							array(
								'label' => esc_html__( 'Helpdesk', 'gf-odoo-connector' ),
								'value' => 'helpdesk',
							),
						),
						'description' => esc_html__(
							'Choose whether submissions create CRM leads or Helpdesk tickets.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'    => 'smart_routing_enabled',
						'label'   => esc_html__( 'Smart routing (Beta)', 'gf-odoo-connector' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'  => 'smart_routing_enabled',
								'label' => esc_html__( 'Classify submissions from this form and route them automatically', 'gf-odoo-connector' ),
							),
						),
						'description' => esc_html__(
							'For generic contact forms. Inspects the message and routes it to CRM (sales), Helpdesk (support), or skips vendor/spam. Configure keywords and the AI under Forms → Settings → GF Odoo Connector → Smart routing (Beta). Requires the global master switch to be on.',
							'gf-odoo-connector'
						),
					),
				),
			),
			array(
				'title'       => esc_html__( 'Thank-you page', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Show ticket status to visitors after they submit the form.',
					'gf-odoo-connector'
				),
				'fields'      => array(
					array(
						'name' => 'confirmation_shortcode_help',
						'type' => 'html',
						'html' => sprintf(
							'<p class="description">%1$s</p><p><code>[odoo_ticket_status]</code></p><p class="description">%2$s</p>',
							esc_html__(
								'Add this shortcode to your Gravity Forms confirmation message (or page). Gravity Forms can pass the entry ID with {entry_id} in the confirmation redirect URL.',
								'gf-odoo-connector'
							),
							esc_html__(
								'Example confirmation redirect: thank-you/?entry_id={entry_id}. The shortcode reads entry_id from the URL automatically.',
								'gf-odoo-connector'
							)
						),
					),
				),
			),
			array(
				'title'       => esc_html__( 'CRM assignment (this form only)', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Optional override for this form. Leave both set to “Use global default” to apply the salesperson and sales team configured under Forms → Settings → GF Odoo Connector.',
					'gf-odoo-connector'
				),
				'dependency'  => array(
					'field'  => 'module',
					'values' => array( 'crm' ),
				),
				'fields'      => array(
					array(
						'name'  => 'crm_assignment_refresh',
						'label' => '',
						'type'  => 'html',
						'html'  => sprintf(
							'<p class="description">%1$s <button type="button" class="button button-small" id="gf-odoo-refresh-crm-assignment">%2$s</button> <span id="gf-odoo-crm-assignment-status" class="gf-odoo-test-result" role="status"></span></p>',
							esc_html__(
								'Salespeople and teams are loaded from Odoo. If the lists are empty, check your connection and API user permissions, then refresh.',
								'gf-odoo-connector'
							),
							esc_html__( 'Refresh from Odoo', 'gf-odoo-connector' )
						),
					),
					array(
						'name'     => 'crm_user_id',
						'label'    => esc_html__( 'Salesperson', 'gf-odoo-connector' ),
						'type'     => 'select',
						'choices'  => $this->get_crm_user_field_choices( esc_html__( 'Use global default', 'gf-odoo-connector' ) ),
						'class'    => 'gf-odoo-crm-user-select medium',
						'description'  => esc_html__(
							'Override the global default salesperson for this form. Choosing a salesperson also sets their default sales team (you can change the team below). Leave on “Use global default” to inherit the site-wide setting.',
							'gf-odoo-connector'
						),
					),
					array(
						'name'     => 'crm_team_id',
						'label'    => esc_html__( 'Sales Team', 'gf-odoo-connector' ),
						'type'     => 'select',
						'choices'  => $this->get_crm_team_field_choices( esc_html__( 'Use global default', 'gf-odoo-connector' ) ),
						'class'    => 'gf-odoo-crm-team-select medium',
						'description'  => esc_html__(
							'Override the global default sales team for this form. Leave on “Use global default” to inherit the site-wide setting.',
							'gf-odoo-connector'
						),
					),
				),
			),
			array(
				'title'       => esc_html__( 'Contact fields', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Contact fields are stored on the res.partner record in Odoo and shared across CRM and Helpdesk. Configure how each field is filled. Required fields cannot be turned off.',
					'gf-odoo-connector'
				),
				'dependency'  => array(
					'field'  => 'module',
					'values' => array( 'crm' ),
				),
				'fields'      => $this->crm_section_settings_fields( 'contact' ),
			),
			array(
				'title'       => esc_html__( 'Lead fields', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Lead fields are stored directly on the crm.lead record. Use Auto for form title or page URL where available.',
					'gf-odoo-connector'
				),
				'dependency'  => array(
					'field'  => 'module',
					'values' => array( 'crm' ),
				),
				'fields'      => $this->crm_section_settings_fields( 'lead' ),
			),
			array(
				'title'       => esc_html__( 'Ticket fields', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Subject, description, inquiry category, and helpdesk team.',
					'gf-odoo-connector'
				),
				'dependency'  => array(
					'field'  => 'module',
					'values' => array( 'helpdesk' ),
				),
				'fields'      => $this->helpdesk_section_settings_fields( 'ticket' ),
			),
			array(
				'title'       => esc_html__( 'Contact fields', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Contact details and location fields for the ticket.',
					'gf-odoo-connector'
				),
				'dependency'  => array(
					'field'  => 'module',
					'values' => array( 'helpdesk' ),
				),
				'fields'      => $this->helpdesk_section_settings_fields( 'contact' ),
			),
			array(
				'title'       => esc_html__( 'Product details', 'gf-odoo-connector' ),
				'description' => esc_html__(
					'Serial number, warranty, and product dates.',
					'gf-odoo-connector'
				),
				'dependency'  => array(
					'field'  => 'module',
					'values' => array( 'helpdesk' ),
				),
				'fields'      => $this->helpdesk_section_settings_fields( 'product' ),
			),
		);
	}

	/**
	 * Columns for the feeds list table (Form → Settings → GF Odoo Connector).
	 *
	 * @return array<string, string>
	 */
	public function feed_list_columns() {
		return array(
			'feedName' => esc_html__( 'Feed name', 'gravityforms' ),
			'module'   => esc_html__( 'Module', 'gf-odoo-connector' ),
			'status'   => esc_html__( 'Status', 'gravityforms' ),
		);
	}

	/**
	 * @param array $feed Feed object.
	 *
	 * @return string
	 */
	public function get_column_value_feedName( $feed ) {
		$name = $this->get_feed_name( $feed );

		if ( '' === $name ) {
			return esc_html__( '(Unnamed feed)', 'gf-odoo-connector' );
		}

		return esc_html( $name );
	}

	/**
	 * @param array $feed Feed object.
	 *
	 * @return string
	 */
	public function get_column_value_module( $feed ) {
		$module = $this->get_feed_module( $feed );

		if ( 'crm' === $module ) {
			return esc_html__( 'Odoo CRM', 'gf-odoo-connector' );
		}

		if ( 'helpdesk' === $module ) {
			return esc_html__( 'Odoo Helpdesk', 'gf-odoo-connector' );
		}

		return $module ? esc_html( (string) $module ) : '-';
	}

	/**
	 * @param array $feed Feed object.
	 *
	 * @return string
	 */
	public function get_column_value_status( $feed ) {
		if ( rgar( $feed, 'is_active' ) ) {
			return sprintf(
				'<span class="gform-status--active"><span class="gform-status-indicator"></span> %s</span>',
				esc_html__( 'Active', 'gravityforms' )
			);
		}

		return sprintf(
			'<span class="gform-status--inactive"><span class="gform-status-indicator"></span> %s</span>',
			esc_html__( 'Inactive', 'gravityforms' )
		);
	}

	/**
	 * Allow duplicating feeds from the list table.
	 *
	 * @param int|array $id Feed ID or feed object.
	 *
	 * @return bool
	 */
	public function can_duplicate_feed( $id ) {
		return true;
	}

	/**
	 * Load sales teams and salespeople from Odoo (with short-lived cache).
	 *
	 * @return array{teams: array<int, array{value: int, label: string}>, users: array<int, array{value: int, label: string, team_id: int}>}
	 */
	private function get_odoo_assignment_data(): array {
		$cached_teams = get_transient( self::TRANSIENT_CRM_TEAMS );
		$cached_users = get_transient( self::TRANSIENT_CRM_USERS );

		if (
			is_array( $cached_teams ) && ! empty( $cached_teams )
			&& is_array( $cached_users ) && ! empty( $cached_users )
		) {
			return array(
				'teams' => $cached_teams,
				'users' => $cached_users,
			);
		}

		$empty = array(
			'teams' => array(),
			'users' => array(),
		);

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			return $empty;
		}

		try {
			$crm    = new CRM_Handler( $api );
			$teams  = $this->get_cached_odoo_options(
				'sales_teams',
				static function () use ( $crm ) {
					return $crm->get_sales_teams();
				}
			);
			$users  = $this->get_cached_odoo_options(
				'salespeople',
				static function () use ( $crm ) {
					return $crm->get_salespeople();
				}
			);
			$result = array(
				'teams' => $teams,
				'users' => $users,
			);

			if ( ! empty( $teams ) ) {
				set_transient( self::TRANSIENT_CRM_TEAMS, $teams, self::ASSIGNMENT_CACHE_TTL );
			}

			if ( ! empty( $users ) ) {
				set_transient( self::TRANSIENT_CRM_USERS, $users, self::ASSIGNMENT_CACHE_TTL );
			}

			return $result;
		} catch ( Exception $e ) {
			$this->log_error( 'GF Odoo Connector: could not load CRM assignment lists: ' . $e->getMessage() );
			return $empty;
		}
	}

	/**
	 * Clear cached Odoo team / user dropdown data.
	 */
	public function clear_assignment_cache(): void {
		delete_transient( self::TRANSIENT_CRM_TEAMS );
		delete_transient( self::TRANSIENT_CRM_USERS );
		delete_transient( self::TRANSIENT_HELPDESK_TEAMS );
		$this->clear_odoo_options_cache();
	}

	/**
	 * Gravity Forms select choices for the Sales Team setting.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function get_crm_team_field_choices( string $empty_label = '' ): array {
		$choices = array(
			array(
				'label' => '' !== $empty_label ? $empty_label : esc_html__( 'Select a sales team', 'gf-odoo-connector' ),
				'value' => '',
			),
		);

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			$choices[0]['label'] = esc_html__( 'Configure Odoo connection in Forms → Settings first', 'gf-odoo-connector' );
			return $choices;
		}

		$teams = $this->get_odoo_assignment_data()['teams'];

		if ( empty( $teams ) ) {
			$choices[0]['label'] = esc_html__( 'No sales teams with Leads enabled were found in Odoo', 'gf-odoo-connector' );
			return $choices;
		}

		foreach ( $teams as $team ) {
			$choices[] = array(
				'label' => $team['label'],
				'value' => (string) $team['value'],
			);
		}

		return $choices;
	}

	/**
	 * Gravity Forms select choices for the Salesperson setting.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function get_crm_user_field_choices( string $empty_label = '' ): array {
		$choices = array(
			array(
				'label' => '' !== $empty_label ? $empty_label : esc_html__( 'Unassigned', 'gf-odoo-connector' ),
				'value' => '',
			),
		);

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			return $choices;
		}

		$users = $this->get_odoo_assignment_data()['users'];

		foreach ( $users as $user ) {
			$choices[] = array(
				'label' => $user['label'],
				'value' => (string) $user['value'],
			);
		}

		return $choices;
	}

	/**
	 * Helpdesk team select choices (placeholder until AJAX refresh).
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function get_helpdesk_team_field_choices(): array {
		$choices = array(
			array(
				'label' => esc_html__( 'Select a helpdesk team', 'gf-odoo-connector' ),
				'value' => '',
			),
		);

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			$choices[0]['label'] = esc_html__( 'Configure Odoo connection in Forms → Settings first', 'gf-odoo-connector' );
			return $choices;
		}

		$teams = $this->get_helpdesk_teams_cached();

		if ( empty( $teams ) ) {
			$choices[0]['label'] = esc_html__( 'No helpdesk teams found in Odoo', 'gf-odoo-connector' );
			return $choices;
		}

		foreach ( $teams as $team ) {
			$choices[] = array(
				'label' => $team['label'],
				'value' => (string) $team['value'],
			);
		}

		return $choices;
	}

	/**
	 * Text/HTML ticket field choices for the smart routing "ticket body" select.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private function get_helpdesk_text_field_choices(): array {
		$choices = array(
			array(
				'label' => esc_html__( 'Description (standard)', 'gf-odoo-connector' ),
				'value' => 'description',
			),
		);

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			return $choices;
		}

		$cached = get_transient( 'gf_odoo_helpdesk_text_fields' );

		if ( ! is_array( $cached ) ) {
			try {
				$helpdesk = new Helpdesk_Handler( $api );
				$fields   = $helpdesk->get_ticket_fields_metadata();
			} catch ( Exception $e ) {
				$fields = array();
			}

			$cached = array();

			foreach ( (array) $fields as $name => $meta ) {
				$type = (string) rgar( (array) $meta, 'type' );

				if ( ! in_array( $type, array( 'text', 'html', 'char' ), true ) ) {
					continue;
				}

				$label = (string) rgar( (array) $meta, 'string' );
				$cached[ (string) $name ] = '' !== $label ? $label : (string) $name;
			}

			if ( ! empty( $cached ) ) {
				asort( $cached );
				set_transient( 'gf_odoo_helpdesk_text_fields', $cached, self::ASSIGNMENT_CACHE_TTL );
			}
		}

		foreach ( (array) $cached as $name => $label ) {
			if ( 'description' === $name ) {
				continue;
			}

			$choices[] = array(
				'label' => sprintf( '%1$s (%2$s)', $label, $name ),
				'value' => (string) $name,
			);
		}

		return $choices;
	}

	/**
	 * Cached helpdesk.team list for settings UI and validation.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	private function get_helpdesk_teams_cached(): array {
		$cached = get_transient( self::TRANSIENT_HELPDESK_TEAMS );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			return array();
		}

		try {
			$helpdesk = new Helpdesk_Handler( $api );
			$teams    = $helpdesk->get_teams();

			if ( ! empty( $teams ) ) {
				set_transient( self::TRANSIENT_HELPDESK_TEAMS, $teams, self::ASSIGNMENT_CACHE_TTL );
			}

			return $teams;
		} catch ( Exception $e ) {
			$this->log_error( 'GF Odoo Connector: could not load helpdesk teams: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * Default priority choices for helpdesk tickets.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function get_helpdesk_priority_choices(): array {
		return array(
			array(
				'label' => esc_html__( 'Low', 'gf-odoo-connector' ),
				'value' => '0',
			),
			array(
				'label' => esc_html__( 'Normal', 'gf-odoo-connector' ),
				'value' => '1',
			),
			array(
				'label' => esc_html__( 'High', 'gf-odoo-connector' ),
				'value' => '2',
			),
			array(
				'label' => esc_html__( 'Urgent', 'gf-odoo-connector' ),
				'value' => '3',
			),
		);
	}

	/**
	 * CRM field row definitions (single source of truth).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function crm_field_rows(): array {
		return CRM_Field_Config::rows();
	}

	/**
	 * Extract GF field ID from a field mapping value (string or array).
	 *
	 * @param mixed $raw Stored _value.
	 *
	 * @return string
	 */
	public static function get_field_mapping_id( $raw ): string {
		if ( is_array( $raw ) ) {
			return trim( (string) ( $raw['field_id'] ?? '' ) );
		}

		return trim( (string) $raw );
	}

	/**
	 * Extract GF field label from a field mapping value.
	 *
	 * @param mixed $raw Stored _value.
	 *
	 * @return string
	 */
	public static function get_field_mapping_label( $raw ): string {
		if ( is_array( $raw ) ) {
			return trim( (string) ( $raw['field_label'] ?? '' ) );
		}

		return '';
	}

	/**
	 * Scalar value for fixed-mode controls (Odoo ID, text, date, etc.).
	 *
	 * @param mixed $raw Stored _value.
	 *
	 * @return string
	 */
	public static function get_fixed_setting_value( $raw ): string {
		if ( is_array( $raw ) ) {
			return '';
		}

		if ( is_bool( $raw ) ) {
			return $raw ? '1' : '0';
		}

		return trim( (string) $raw );
	}

	/**
	 * Look up a GF field label by field ID on a form.
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $field_id Field ID.
	 *
	 * @return string
	 */
	public static function lookup_gf_field_label( int $form_id, string $field_id ): string {
		if ( $form_id <= 0 || '' === $field_id || ! class_exists( 'GFAPI' ) ) {
			return '';
		}

		$form = GFAPI::get_form( $form_id );
		if ( ! is_array( $form ) || empty( $form['fields'] ) ) {
			return '';
		}

		$target_id = (string) absint( $field_id );

		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}

			if ( (string) (int) ( $field->id ?? 0 ) === $target_id ) {
				return trim( (string) ( $field->label ?? '' ) );
			}
		}

		return '';
	}

	/**
	 * GF settings fields for one CRM section (contact or lead).
	 *
	 * @param string $section contact|lead.
	 *
	 * @return array
	 */
	private function crm_section_settings_fields( string $section ): array {
		return array(
			array(
				'name'  => 'crm_fields_' . $section,
				'label' => '',
				'type'  => 'html',
				'html'  => $this->render_crm_fields_section_html( $section ),
			),
		);
	}

	/**
	 * @param string $section contact|lead.
	 *
	 * @return string
	 */
	private function render_crm_fields_section_html( string $section ): string {
		$ctx       = $this->get_feed_template_ui_context();
		$feed_meta = (array) $ctx['display_meta'];
		$form_id   = (int) $ctx['form_id'];
		$rows      = array_filter(
			$this->crm_field_rows(),
			static function ( $row ) use ( $section ) {
				return $row['section'] === $section;
			}
		);

		$fields_class = 'gf-odoo-crm-fields';
		if ( ! empty( $ctx['is_linked'] ) ) {
			$fields_class .= ' gf-odoo-crm-fields--template-linked';
		}

		$html = '<div class="' . esc_attr( $fields_class ) . '" data-section="' . esc_attr( $section ) . '">';

		foreach ( $rows as $row ) {
			$html .= $this->render_crm_field_row_html( $row, $feed_meta, $form_id, $ctx );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Feed meta for the feed settings screen (with legacy migration applied).
	 *
	 * @return array<string, mixed>
	 */
	private function get_crm_feed_meta_for_settings(): array {
		$feed = $this->get_current_feed();
		$meta = is_array( $feed ) ? (array) rgar( $feed, 'meta', array() ) : array();

		$this->migrate_feed_meta( $meta );

		return $meta;
	}

	/**
	 * @param array $row       Field row from CRM_Field_Config.
	 * @param array $feed_meta Feed meta.
	 * @param int   $form_id   GF form ID.
	 *
	 * @return string
	 */
	private function render_crm_field_row_html( array $row, array $feed_meta, int $form_id, array $ctx = array() ): string {
		$key         = (string) $row['key'];
		$mode_key    = $key . '_mode';
		$value_key   = $key . '_value';
		$saved_mode  = (string) rgar( $feed_meta, $mode_key );
		$saved_raw = rgar( $feed_meta, $value_key );
		$mode      = '' !== $saved_mode ? $saved_mode : CRM_Field_Config::default_mode( $row );
		$sample_form_id = (int) ( $ctx['sample_form_id'] ?? 0 );
		$mode_labels = CRM_Field_Config::mode_labels();
		$is_template = ! empty( $ctx['is_template'] );
		$is_linked   = ! empty( $ctx['is_linked'] );
		$has_override = $is_linked && $this->field_has_template_override(
			$key,
			(array) ( $ctx['overrides'] ?? array() ),
			(array) ( $ctx['template_meta'] ?? array() ),
			(int) ( $ctx['template_id'] ?? 0 ),
			(int) ( $ctx['form_id'] ?? 0 )
		);
		$is_readonly = $is_linked && ! $has_override;

		$row_classes = array( 'gf-odoo-crm-field-row' );
		if ( 'off' === $mode ) {
			$row_classes[] = 'is-off';
		}
		if ( $is_readonly ) {
			$row_classes[] = 'gf-odoo-field--readonly';
		}
		if ( $has_override ) {
			$row_classes[] = 'gf-odoo-field--override';
		}
		if ( $is_template ) {
			$row_classes[] = 'gf-odoo-field--template-editor';
		}

		$tabs = '';
		foreach ( $row['modes'] as $mode_id ) {
			$active = $mode === $mode_id ? ' is-active' : '';
			$tabs  .= sprintf(
				'<button type="button" class="gf-odoo-mode-tab%s" data-mode="%s" data-key="%s"%4$s>%5$s</button>',
				esc_attr( $active ),
				esc_attr( $mode_id ),
				esc_attr( $key ),
				$is_readonly ? ' disabled' : '',
				esc_html( $mode_labels[ $mode_id ] ?? $mode_id )
			);
		}

		$field_label = self::get_field_mapping_label( $saved_raw );
		$map_summary = $is_linked ? $this->format_linked_field_mapping_label( $mode, $saved_raw, $form_id ) : '';
		$value_html  = $this->render_crm_field_value_inputs( $row, $mode, $saved_raw, $form_id, $is_template, $is_readonly, $sample_form_id, $field_label );

		if ( '' !== $map_summary && in_array( $mode, array( 'field', 'fixed' ), true ) ) {
			$value_html = '<p class="gf-odoo-field-map-summary description"><strong>'
				. esc_html( $map_summary )
				. '</strong></p>'
				. $value_html;
		}

		if ( $is_readonly ) {
			$value_html .= $this->render_readonly_field_hiddens( $value_key, $saved_raw, $mode );
		}

		$controls = '';
		if ( $is_linked ) {
			$map_label = $this->format_linked_field_mapping_label( $mode, $saved_raw, $form_id );
			if ( '' !== $map_label ) {
				$controls .= '<p class="gf-odoo-linked-map-label description">'
					. esc_html(
						sprintf(
							/* translators: %s: Gravity Forms field label or fixed value */
							__( 'Mapped to: %s', 'gf-odoo-connector' ),
							$map_label
						)
					)
					. '</p>';
			}

			if ( $has_override ) {
				$controls .= '<span class="gf-odoo-override-badge">' . esc_html__( 'Override', 'gf-odoo-connector' ) . '</span> '
					. '<button type="button" class="button-link gf-odoo-remove-override" data-meta-key="' . esc_attr( $value_key ) . '">'
					. esc_html__( '× Remove override', 'gf-odoo-connector' ) . '</button>';
			} else {
				$controls .= '<button type="button" class="button button-small gf-odoo-override-field" data-field-key="' . esc_attr( $key ) . '">'
					. esc_html__( 'Override', 'gf-odoo-connector' ) . '</button>';
			}
		}

		$help_html = '';
		$row_help  = (string) rgar( $row, 'help', '' );
		if ( '' !== $row_help ) {
			$help_html = '<p class="gf-odoo-field-help description">' . esc_html( $row_help ) . '</p>';
		}

		return sprintf(
			'<div class="%1$s" data-key="%2$s" data-modes="%3$s" data-fixed-type="%4$s" data-ajax-action="%5$s" data-parent-key="%6$s">'
			. '<div class="gf-odoo-crm-field-row__label">%7$s</div>'
			. '<div class="gf-odoo-crm-field-row__modes">%8$s</div>'
			. '<div class="gf-odoo-crm-field-row__value">%9$s</div>'
			. '<div class="gf-odoo-crm-field-row__template">%12$s</div>'
			. '%13$s'
			. '<input type="hidden" name="_gform_setting_%10$s" class="gf-odoo-mode-input" value="%11$s" />'
			. '</div>',
			esc_attr( implode( ' ', $row_classes ) ),
			esc_attr( $key ),
			esc_attr( wp_json_encode( $row['modes'] ) ),
			esc_attr( (string) rgar( $row, 'fixed_type', '' ) ),
			esc_attr( (string) rgar( $row, 'ajax_action', '' ) ),
			esc_attr( (string) rgar( $row, 'parent_key', '' ) ),
			esc_html( (string) $row['label'] ),
			$tabs,
			$value_html,
			esc_attr( $mode_key ),
			esc_attr( $mode ),
			$controls,
			$help_html
		);
	}

	/**
	 * Human-readable mapping label for template-linked feed rows.
	 *
	 * @param string $mode       Active mode.
	 * @param mixed  $saved_raw  Stored value.
	 * @param int    $form_id    Target form ID.
	 *
	 * @return string
	 */
	private function format_linked_field_mapping_label( string $mode, $saved_raw, int $form_id ): string {
		if ( in_array( $mode, array( 'off', 'auto' ), true ) ) {
			return '';
		}

		if ( 'field' === $mode ) {
			$field_id = self::get_field_mapping_id( $saved_raw );
			if ( '' === $field_id ) {
				return '';
			}

			$label = self::get_field_mapping_label( $saved_raw );
			if ( '' === $label && $form_id > 0 ) {
				$label = self::lookup_gf_field_label( $form_id, $field_id );
			}

			return '' !== $label ? $label : sprintf(
				/* translators: %s: Gravity Forms field ID */
				__( 'Field #%s', 'gf-odoo-connector' ),
				$field_id
			);
		}

		if ( 'fixed' === $mode ) {
			return self::get_fixed_setting_value( $saved_raw );
		}

		return '';
	}

	/**
	 * @param array  $row         Field row.
	 * @param string $mode        Active mode.
	 * @param mixed  $saved_value  Saved value (string ID or mapping array).
	 * @param int    $form_id      Form ID.
	 * @param int    $sample_form_id Sample form for template editor.
	 *
	 * @return string
	 */
	private function render_crm_field_value_inputs( array $row, string $mode, $saved_value, int $form_id, bool $is_template = false, bool $is_readonly = false, int $sample_form_id = 0, string $stored_field_label = '' ): string {
		$field_id    = self::get_field_mapping_id( $saved_value );
		$fixed_value = self::get_fixed_setting_value( $saved_value );
		$key       = (string) $row['key'];
		$value_key = $key . '_value';
		$name_attr = '_gform_setting_' . $value_key;
		$parts     = array();

		$field_posts = ! $is_readonly && 'field' === $mode;
		$fixed_posts = ! $is_readonly && 'fixed' === $mode;

		$auto_label = (string) rgar( $row, 'auto_label', '' );
		$parts[]    = sprintf(
			'<span class="gf-odoo-value-auto%1$s" data-mode-panel="auto">%2$s</span>',
			'auto' === $mode ? ' is-visible' : '',
			esc_html( $auto_label )
		);

		$parts[] = sprintf(
			'<div class="gf-odoo-value-field%1$s" data-mode-panel="field">%2$s</div>',
			'field' === $mode ? ' is-visible' : '',
			$this->render_crm_gf_field_select( $name_attr, $field_id, $form_id, $is_template, $is_readonly, $sample_form_id, $field_posts, $stored_field_label )
		);

		$fixed_type = (string) rgar( $row, 'fixed_type', 'text' );
		if ( 'static_select' === $fixed_type ) {
			$parts[] = sprintf(
				'<div class="gf-odoo-value-fixed%1$s" data-mode-panel="fixed">%2$s</div>',
				'fixed' === $mode ? ' is-visible' : '',
				$this->render_crm_static_select( $name_attr, $row, $fixed_value, $fixed_posts )
			);
		} elseif ( 'odoo_select' === $fixed_type ) {
			$parts[] = sprintf(
				'<div class="gf-odoo-value-fixed%1$s" data-mode-panel="fixed">%2$s</div>',
				'fixed' === $mode ? ' is-visible' : '',
				$this->render_crm_odoo_select( $name_attr, $row, $fixed_value, $fixed_posts )
			);
		} elseif ( 'boolean' === $fixed_type ) {
			$checked = '1' === $fixed_value || 'true' === strtolower( $fixed_value );
			$parts[] = sprintf(
				'<div class="gf-odoo-value-fixed%1$s" data-mode-panel="fixed">'
				. '<label><input type="checkbox"%2$s class="gf-odoo-fixed-boolean" value="1" %3$s /> %4$s</label></div>',
				'fixed' === $mode ? ' is-visible' : '',
				$this->render_setting_control_name( $name_attr, $fixed_posts ),
				checked( $checked, true, false ),
				esc_html__( 'Yes', 'gf-odoo-connector' )
			);
		} elseif ( 'date' === $fixed_type ) {
			$parts[] = sprintf(
				'<div class="gf-odoo-value-fixed%1$s" data-mode-panel="fixed">'
				. '<input type="date"%2$s class="gf-odoo-fixed-date" value="%3$s" /></div>',
				'fixed' === $mode ? ' is-visible' : '',
				$this->render_setting_control_name( $name_attr, $fixed_posts ),
				esc_attr( $fixed_value )
			);
		} else {
			$parts[] = sprintf(
				'<div class="gf-odoo-value-fixed%1$s" data-mode-panel="fixed"><input type="text"%2$s class="large gf-odoo-fixed-text" value="%3$s" placeholder="%4$s" /></div>',
				'fixed' === $mode ? ' is-visible' : '',
				$this->render_setting_control_name( $name_attr, $fixed_posts ),
				esc_attr( $fixed_value ),
				esc_attr__( 'Supports merge tags', 'gf-odoo-connector' )
			);
		}

		return implode( '', $parts );
	}

	/**
	 * Name attribute for a value control (only the active mode panel should post).
	 *
	 * @param string $name_attr       Full input name (e.g. _gform_setting_email_value).
	 * @param bool   $include_in_post Whether this control is submitted.
	 *
	 * @return string HTML attribute fragment (leading space).
	 */
	private function render_setting_control_name( string $name_attr, bool $include_in_post ): string {
		if ( $include_in_post ) {
			return ' name="' . esc_attr( $name_attr ) . '"';
		}

		return ' data-setting-name="' . esc_attr( $name_attr ) . '"';
	}

	/**
	 * @param string $name_attr   Input name.
	 * @param string $selected    Selected field ID.
	 * @param int    $form_id     Form ID.
	 *
	 * @return string
	 */
	private function render_crm_gf_field_select( string $name_attr, string $selected, int $form_id, bool $is_template = false, bool $is_readonly = false, int $sample_form_id = 0, bool $include_in_post = true, string $stored_field_label = '' ): string {
		$name_fragment = $this->render_setting_control_name( $name_attr, $include_in_post && ! $is_readonly );
		$effective_form_id = $form_id > 0 ? $form_id : $sample_form_id;

		if ( $is_template && $effective_form_id <= 0 ) {
			$disabled = $is_readonly ? ' disabled' : '';
			$html     = sprintf(
				'<select%1$s class="gf-odoo-gf-field-select gf-odoo-select medium gf-odoo-gf-field-select--needs-sample%2$s"%3$s>',
				$name_fragment,
				$disabled ? ' is-disabled' : '',
				$disabled
			);
			$html    .= sprintf(
				'<option value="">%s</option>',
				esc_html__( 'Select a sample form above', 'gf-odoo-connector' )
			);
			if ( '' !== $selected ) {
				$html .= sprintf(
					'<option value="%1$s" selected="selected">%2$s</option>',
					esc_attr( $selected ),
					esc_html(
						sprintf(
							/* translators: %s: Gravity Forms field ID */
							__( 'Field ID %s (choose sample form to list fields)', 'gf-odoo-connector' ),
							$selected
						)
					)
				);
			}
			$html .= '</select>';
			$html .= '<p class="gf-odoo-hint">' . esc_html__( 'Choose a sample form in Template details to populate this dropdown.', 'gf-odoo-connector' ) . '</p>';

			return $html;
		}

		if ( ! $is_template && $form_id <= 0 ) {
			return '<p class="description gf-odoo-per-form-placeholder">' . esc_html__( 'Set per form when this template is linked.', 'gf-odoo-connector' ) . '</p>';
		}

		$choices = array();
		if ( $effective_form_id > 0 ) {
			$choices = array_values(
				array_filter(
					self::get_field_map_choices( $effective_form_id ),
					static function ( $choice ) {
						return '' !== (string) rgar( $choice, 'value' );
					}
				)
			);
		}

		if ( empty( $choices ) && class_exists( 'GFAPI' ) && $effective_form_id > 0 ) {
			$form = GFAPI::get_form( $effective_form_id );
			if ( ! is_wp_error( $form ) && is_array( $form ) && class_exists( 'Field_Mapper' ) ) {
				$choices = Field_Mapper::get_form_field_choices( $form );
			}
		}

		$disabled = $is_readonly ? ' disabled' : '';

		$html = sprintf(
			'<select%1$s class="gf-odoo-gf-field-select gf-odoo-select medium"%2$s>',
			$name_fragment,
			$disabled
		);

		$html .= sprintf(
			'<option value="">%s</option>',
			esc_html__( 'Select a field', 'gf-odoo-connector' )
		);

		$selected_in_choices = false;

		foreach ( $choices as $choice ) {
			$value = (string) rgar( $choice, 'value' );
			if ( '' === $value ) {
				continue;
			}
			if ( $selected === $value ) {
				$selected_in_choices = true;
			}
			$label_text = preg_replace( '/\s*\(field\s+\d+\)\s*$/i', '', (string) rgar( $choice, 'label' ) );
			$html      .= sprintf(
				'<option value="%1$s" data-field-label="%2$s" %3$s>%4$s</option>',
				esc_attr( $value ),
				esc_attr( $label_text ),
				selected( $selected, $value, false ),
				esc_html( (string) rgar( $choice, 'label' ) )
			);
		}

		if ( '' !== $selected && ! $selected_in_choices ) {
			$orphan_label = '' !== $stored_field_label
				? $stored_field_label
				: self::lookup_gf_field_label( $effective_form_id, $selected );
			if ( '' === $orphan_label ) {
				$orphan_label = sprintf(
					/* translators: %s: Gravity Forms field ID */
					__( 'Field ID %s', 'gf-odoo-connector' ),
					$selected
				);
			}
			$html .= sprintf(
				'<option value="%1$s" data-field-label="%2$s" selected="selected">%3$s</option>',
				esc_attr( $selected ),
				esc_attr( $orphan_label ),
				esc_html( $orphan_label )
			);
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * Hidden inputs so disabled (template-linked) fields still submit on save.
	 *
	 * @param string $value_key  Meta value key (e.g. email_value).
	 * @param mixed  $saved_raw  Stored value.
	 * @param string $mode       Active mode.
	 *
	 * @return string
	 */
	private function render_readonly_field_hiddens( string $value_key, $saved_raw, string $mode ): string {
		if ( in_array( $mode, array( 'off', 'auto' ), true ) ) {
			return '';
		}

		$name = '_gform_setting_' . $value_key;

		if ( is_array( $saved_raw ) ) {
			$value = wp_json_encode( $saved_raw );
		} else {
			$value = is_scalar( $saved_raw ) ? (string) $saved_raw : '';
		}

		return sprintf(
			'<input type="hidden" name="%1$s" value="%2$s" class="gf-odoo-readonly-value-hidden" />',
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	/**
	 * @param string $name_attr   Input name.
	 * @param array  $row         Field row.
	 * @param string $selected    Selected value.
	 *
	 * @return string
	 */
	private function render_crm_static_select( string $name_attr, array $row, string $selected, bool $include_in_post = true ): string {
		$html = sprintf(
			'<select%1$s class="gf-odoo-static-select medium">',
			$this->render_setting_control_name( $name_attr, $include_in_post )
		);
		$html .= sprintf(
			'<option value="">%s</option>',
			esc_html__( 'None', 'gf-odoo-connector' )
		);

		foreach ( (array) rgar( $row, 'fixed_choices', array() ) as $choice ) {
			$value = (string) rgar( $choice, 'value' );
			$html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( (string) rgar( $choice, 'label' ) )
			);
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * @param string $name_attr Input name.
	 * @param array  $row       Field row.
	 * @param string $selected  Selected Odoo ID.
	 *
	 * @return string
	 */
	private function render_crm_odoo_select( string $name_attr, array $row, string $selected, bool $include_in_post = true ): string {
		return sprintf(
			'<select%1$s class="gf-odoo-odoo-select medium" data-ajax-action="%2$s" data-parent-key="%3$s">'
			. '<option value="">%4$s</option>'
			. '%5$s'
			. '</select><span class="gf-odoo-select-spinner spinner" style="float:none;"></span>',
			$this->render_setting_control_name( $name_attr, $include_in_post ),
			esc_attr( (string) rgar( $row, 'ajax_action', '' ) ),
			esc_attr( (string) rgar( $row, 'parent_key', '' ) ),
			esc_html__( 'None', 'gf-odoo-connector' ),
			$selected > 0 || '' !== $selected
				? sprintf( '<option value="%1$s" selected="selected">#%1$s</option>', esc_attr( $selected ) )
				: ''
		);
	}

	/**
	 * Row config for admin.js (saved mode/value per field).
	 *
	 * @param array|null $feed Current feed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_crm_field_rows_for_js( ?array $feed ): array {
		$meta    = is_array( $feed ) ? (array) rgar( $feed, 'meta', array() ) : array();
		$form_id = is_array( $feed ) ? (int) rgar( $feed, 'form_id' ) : 0;
		$feed_id = is_array( $feed ) ? (int) rgar( $feed, 'id' ) : 0;

		if ( $form_id <= 0 ) {
			$form_id = (int) rgget( 'id' );
		}
		if ( $feed_id <= 0 ) {
			$feed_id = (int) rgget( 'fid' );
		}

		if ( $form_id > 0 && $feed_id > 0 && class_exists( 'Template_Manager' ) ) {
			$resolved = Template_Manager::get_template_for_feed( $form_id, $feed_id );
			if ( is_array( $resolved ) ) {
				$meta = $resolved;
			}
		}

		$this->migrate_feed_meta( $meta );

		$out = array();

		foreach ( $this->crm_field_rows() as $row ) {
			$key = (string) $row['key'];
			$out[] = array(
				'key'         => $key,
				'modes'       => $row['modes'],
				'fixed_type'  => (string) rgar( $row, 'fixed_type', '' ),
				'ajax_action' => (string) rgar( $row, 'ajax_action', '' ),
				'parent_key'  => (string) rgar( $row, 'parent_key', '' ),
				'mode'        => '' !== (string) rgar( $meta, $key . '_mode' )
					? (string) rgar( $meta, $key . '_mode' )
					: CRM_Field_Config::default_mode( $row ),
				'value'       => self::get_field_mapping_id( rgar( $meta, $key . '_value' ) ),
			);
		}

		return $out;
	}

	/**
	 * Migrate legacy field_map and lead default keys to {key}_mode / {key}_value.
	 *
	 * @param array $meta Feed meta (by reference).
	 *
	 * @return bool True when meta was changed.
	 */
	public function migrate_feed_meta( array &$meta ): bool {
		if ( ! empty( $meta['gf_odoo_crm_config_v2'] ) ) {
			return false;
		}

		$changed = false;
		$legacy  = $this->get_legacy_crm_field_map( $meta );

		$legacy_to_row = array(
			'contact_name'     => 'contact_name',
			'contact_email'    => 'contact_email',
			'contact_phone'    => 'contact_phone',
			'mobile'           => 'contact_mobile',
			'company_name'     => 'contact_company',
			'lead_description' => 'lead_description',
			'lead_name'        => 'lead_title',
		);

		foreach ( $legacy_to_row as $map_key => $row_key ) {
			if ( empty( $legacy[ $map_key ] ) || isset( $meta[ $row_key . '_mode' ] ) ) {
				continue;
			}

			$meta[ $row_key . '_mode' ]  = 'field';
			$meta[ $row_key . '_value' ] = (string) $legacy[ $map_key ];
			$changed                     = true;
		}

		$title_mode = (string) rgar( $meta, 'lead_title_mode' );

		if ( '' !== $title_mode && ! in_array( $title_mode, array( 'auto', 'off' ), true ) ) {
			$new_mode = 'form_title' === $title_mode ? 'auto' : $title_mode;
			if ( in_array( $new_mode, array( 'auto', 'field', 'fixed' ), true ) ) {
				$meta['lead_title_mode'] = $new_mode;
				if ( 'field' === $new_mode && ! empty( $meta['lead_title_field'] ) ) {
					$meta['lead_title_value'] = (string) $meta['lead_title_field'];
				}
				if ( 'fixed' === $new_mode && ! empty( $meta['lead_title_fixed'] ) ) {
					$meta['lead_title_value'] = (string) $meta['lead_title_fixed'];
				}
				$changed = true;
			}
		}

		$fixed_defaults = array(
			'lead_industry_id'        => 'lead_industry',
			'lead_sub_industry_id'    => 'lead_sub_industry',
			'lead_source_id'          => 'lead_source',
			'lead_sub_lead_source_id' => 'lead_sub_source',
		);

		foreach ( $fixed_defaults as $legacy_key => $row_key ) {
			if ( empty( $meta[ $legacy_key ] ) || isset( $meta[ $row_key . '_mode' ] ) ) {
				continue;
			}
			$meta[ $row_key . '_mode' ]  = 'fixed';
			$meta[ $row_key . '_value' ] = (string) absint( $meta[ $legacy_key ] );
			$changed                     = true;
		}

		$priority_mode = (string) rgar( $meta, 'lead_priority_mode', '' );
		if ( in_array( $priority_mode, array( 'fixed', 'field' ), true ) && ! isset( $meta['lead_priority_value'] ) ) {
			if ( 'field' === $priority_mode && ! empty( $meta['lead_priority_field'] ) ) {
				$meta['lead_priority_value'] = (string) $meta['lead_priority_field'];
				$changed                     = true;
			} elseif ( 'fixed' === $priority_mode && isset( $meta['lead_priority'] ) && '' !== (string) $meta['lead_priority'] ) {
				$meta['lead_priority_value'] = (string) $meta['lead_priority'];
				$changed                     = true;
			}
		}

		if ( $changed || ! empty( $legacy ) || '' !== $title_mode || ! empty( $meta['lead_industry_id'] ) ) {
			$meta['gf_odoo_crm_config_v2'] = '1';
			return true;
		}

		return false;
	}

	/**
	 * @param array $meta Feed meta.
	 *
	 * @return array<string, string>
	 */
	private function get_legacy_crm_field_map( array $meta ): array {
		$feed_wrapper = array( 'meta' => $meta );

		if ( class_exists( 'GFAddOn' ) ) {
			return GFAddOn::get_field_map_fields( $feed_wrapper, 'field_map_crm' );
		}

		return array();
	}

	/**
	 * Inject a hidden field that stores the page URL for Source → Auto mode.
	 *
	 * @param array $form GF form.
	 *
	 * @return array
	 */
	public function maybe_inject_source_url_field( $form ) {
		if ( empty( $form['id'] ) || ! $this->form_needs_source_url_field( (int) $form['id'] ) ) {
			return $form;
		}

		if ( $this->form_has_source_url_field( $form ) ) {
			return $form;
		}

		if ( ! class_exists( 'GF_Fields' ) ) {
			return $form;
		}

		$max_id = 0;
		foreach ( (array) rgar( $form, 'fields', array() ) as $field ) {
			if ( is_object( $field ) && isset( $field->id ) ) {
				$max_id = max( $max_id, (int) $field->id );
			}
		}

		$field = GF_Fields::create(
			array(
				'type'              => 'hidden',
				'label'             => 'Odoo Source URL',
				'inputName'         => 'gf_odoo_source_url',
				'allowsPrepopulate' => true,
				'defaultValue'      => '{embed_url}',
				'id'                => $max_id + 1,
			)
		);

		if ( ! $field ) {
			return $form;
		}

		$form['fields'][] = $field;

		return $form;
	}

	/**
	 * @param int $form_id Form ID.
	 *
	 * @return bool
	 */
	private function form_needs_source_url_field( int $form_id ): bool {
		if ( ! class_exists( 'GFAPI' ) ) {
			return false;
		}

		$feeds = GFAPI::get_feeds( null, $form_id, $this->_slug );

		if ( is_wp_error( $feeds ) || empty( $feeds ) ) {
			return false;
		}

		foreach ( $feeds as $feed ) {
			if ( empty( $feed['is_active'] ) ) {
				continue;
			}

			if ( 'crm' !== $this->get_feed_module( $feed ) ) {
				continue;
			}

			$meta = (array) rgar( $feed, 'meta', array() );
			$this->migrate_feed_meta( $meta );

			if ( 'auto' === (string) rgar( $meta, 'lead_source_mode' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array $form GF form.
	 *
	 * @return bool
	 */
	private function form_has_source_url_field( array $form ): bool {
		foreach ( (array) rgar( $form, 'fields', array() ) as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			if ( 'hidden' !== $field->type ) {
				continue;
			}
			if ( 'gf_odoo_source_url' === $field->inputName ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Persist the hidden source URL field ID on the feed after save.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $meta    Feed meta (by reference).
	 */
	private function sync_source_hidden_field_id( int $form_id, array &$meta ): void {
		if ( 'auto' !== (string) rgar( $meta, 'lead_source_mode' ) || $form_id <= 0 || ! class_exists( 'GFFormsModel' ) ) {
			return;
		}

		$form = GFFormsModel::get_form_meta( $form_id );

		foreach ( (array) rgar( $form, 'fields', array() ) as $field ) {
			if ( is_object( $field ) && 'hidden' === $field->type && 'gf_odoo_source_url' === $field->inputName ) {
				$meta['source_hidden_field_id'] = (string) $field->id;
				return;
			}
		}
	}

	/**
	 * Helpdesk field row definitions (single source of truth).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function helpdesk_field_rows(): array {
		$rows = Helpdesk_Field_Config::rows();

		foreach ( $rows as $index => $row ) {
			$key = (string) ( $row['key'] ?? '' );

			if ( 'ticket_product_model' === $key && class_exists( 'GF_Odoo_Product_Tag_Map' ) ) {
				$choices = array();

				foreach ( GF_Odoo_Product_Tag_Map::get_all() as $choice ) {
					$choices[] = array(
						'value' => (string) $choice['tag_ref'],
						'label' => (string) $choice['label'],
					);
				}

				$row['fixed_choices'] = $choices;
				$rows[ $index ]       = $row;
				continue;
			}

			if ( 'ticket_category' === $key && class_exists( 'GF_Odoo_Ticket_Category_Map' ) ) {
				$choices = array();

				foreach ( GF_Odoo_Ticket_Category_Map::get_all() as $choice ) {
					$choices[] = array(
						'value' => (string) $choice['category_ref'],
						'label' => (string) $choice['label'],
					);
				}

				$row['fixed_choices'] = $choices;
				$rows[ $index ]       = $row;
			}
		}

		return $rows;
	}

	/**
	 * GF settings fields for one Helpdesk section.
	 *
	 * @param string $section ticket|contact|product.
	 *
	 * @return array
	 */
	private function helpdesk_section_settings_fields( string $section ): array {
		return array(
			array(
				'name'  => 'helpdesk_fields_' . $section,
				'label' => '',
				'type'  => 'html',
				'html'  => $this->render_helpdesk_fields_section_html( $section ),
			),
		);
	}

	/**
	 * @param string $section ticket|contact|product.
	 *
	 * @return string
	 */
	private function render_helpdesk_fields_section_html( string $section ): string {
		$ctx       = $this->get_feed_template_ui_context();
		$feed_meta = (array) $ctx['display_meta'];
		$form_id   = (int) $ctx['form_id'];
		$rows      = array_filter(
			$this->helpdesk_field_rows(),
			static function ( $row ) use ( $section ) {
				return $row['section'] === $section;
			}
		);

		$fields_class = 'gf-odoo-helpdesk-fields gf-odoo-crm-fields';
		if ( ! empty( $ctx['is_linked'] ) ) {
			$fields_class .= ' gf-odoo-crm-fields--template-linked';
		}

		$html = '<div class="' . esc_attr( $fields_class ) . '" data-section="' . esc_attr( $section ) . '">';

		foreach ( $rows as $row ) {
			$html .= $this->render_helpdesk_field_row_html( $row, $feed_meta, $form_id, $ctx );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_helpdesk_feed_meta_for_settings(): array {
		$feed = $this->get_current_feed();
		$meta = is_array( $feed ) ? (array) rgar( $feed, 'meta', array() ) : array();

		$this->migrate_helpdesk_feed_meta( $meta );

		return $meta;
	}

	/**
	 * @param array $row       Field row.
	 * @param array $feed_meta Feed meta.
	 * @param int   $form_id   Form ID.
	 *
	 * @return string
	 */
	private function render_helpdesk_field_row_html( array $row, array $feed_meta, int $form_id, array $ctx = array() ): string {
		return $this->render_crm_field_row_html( $row, $feed_meta, $form_id, $ctx );
	}

	/**
	 * @param array|null $feed Current feed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_helpdesk_field_rows_for_js( ?array $feed ): array {
		$meta    = is_array( $feed ) ? (array) rgar( $feed, 'meta', array() ) : array();
		$form_id = is_array( $feed ) ? (int) rgar( $feed, 'form_id' ) : 0;
		$feed_id = is_array( $feed ) ? (int) rgar( $feed, 'id' ) : 0;

		if ( $form_id <= 0 ) {
			$form_id = (int) rgget( 'id' );
		}
		if ( $feed_id <= 0 ) {
			$feed_id = (int) rgget( 'fid' );
		}

		if ( $form_id > 0 && $feed_id > 0 && class_exists( 'Template_Manager' ) ) {
			$resolved = Template_Manager::get_template_for_feed( $form_id, $feed_id );
			if ( is_array( $resolved ) ) {
				$meta = $resolved;
			}
		}

		$this->migrate_helpdesk_feed_meta( $meta );

		$out = array();

		foreach ( $this->helpdesk_field_rows() as $row ) {
			$key   = (string) $row['key'];
			$out[] = array(
				'key'         => $key,
				'modes'       => $row['modes'],
				'fixed_type'  => (string) rgar( $row, 'fixed_type', '' ),
				'ajax_action' => (string) rgar( $row, 'ajax_action', '' ),
				'parent_key'  => (string) rgar( $row, 'parent_key', '' ),
				'mode'        => '' !== (string) rgar( $meta, $key . '_mode' )
					? (string) rgar( $meta, $key . '_mode' )
					: Helpdesk_Field_Config::default_mode( $row ),
				'value'       => self::get_field_mapping_id( rgar( $meta, $key . '_value' ) ),
			);
		}

		return $out;
	}

	/**
	 * Migrate legacy helpdesk field_map keys to per-field modes.
	 *
	 * @param array $meta Feed meta (by reference).
	 *
	 * @return bool
	 */
	public function migrate_helpdesk_feed_meta( array &$meta ): bool {
		if ( ! empty( $meta['gf_odoo_helpdesk_config_v2'] ) ) {
			return false;
		}

		$changed = false;
		$legacy  = $this->get_legacy_helpdesk_field_map( $meta );

		$legacy_to_row = array(
			'ticket_name'        => 'ticket_subject',
			'ticket_subject'     => 'ticket_subject',
			'subject'            => 'ticket_subject',
			'ticket_description' => 'ticket_description',
			'description'        => 'ticket_description',
			'message'            => 'ticket_description',
			'partner_name'       => 'contact_name',
			'partner_email'      => 'contact_email',
			'partner_phone'      => 'contact_phone',
		);

		foreach ( $legacy_to_row as $map_key => $row_key ) {
			if ( empty( $legacy[ $map_key ] ) || isset( $meta[ $row_key . '_mode' ] ) ) {
				continue;
			}

			$meta[ $row_key . '_mode' ]  = 'field';
			$meta[ $row_key . '_value' ] = (string) $legacy[ $map_key ];
			$changed                     = true;
		}

		$team_id = (int) rgar( $meta, 'helpdesk_team_id' );
		if ( $team_id > 0 && empty( $meta['ticket_team_mode'] ) ) {
			$meta['ticket_team_mode']  = 'fixed';
			$meta['ticket_team_value'] = (string) $team_id;
			$changed                   = true;
		}

		if ( $changed || ! empty( $legacy ) || $team_id > 0 ) {
			$meta['gf_odoo_helpdesk_config_v2'] = '1';
			return true;
		}

		return false;
	}

	/**
	 * @param array $meta Feed meta.
	 *
	 * @return array<string, string>
	 */
	private function get_legacy_helpdesk_field_map( array $meta ): array {
		$feed_wrapper = array( 'meta' => $meta );

		if ( class_exists( 'GFAddOn' ) ) {
			return GFAddOn::get_field_map_fields( $feed_wrapper, 'field_map_helpdesk' );
		}

		return array();
	}

	/**
	 * WP_DEBUG-only markup for helpdesk.ticket fields_get.
	 *
	 * @return string
	 */
	private function get_helpdesk_fields_debug_markup(): string {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return '';
		}

		return sprintf(
			'<p><button type="button" class="button button-secondary" id="gf-odoo-debug-helpdesk-fields">%1$s</button>'
			. ' <span id="gf-odoo-helpdesk-fields-debug-status" class="description" style="margin-left:8px;"></span></p>'
			. '<div id="gf-odoo-helpdesk-fields-debug-result" class="gf-odoo-fields-debug-result" style="max-height:420px;overflow:auto;margin-top:8px;"></div>',
			esc_html__( 'Debug: Fetch helpdesk.ticket fields', 'gf-odoo-connector' )
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $fields fields_get result.
	 *
	 * @return array<int, array{field: string, label: string, type: string, relation: string}>
	 */
	private function format_helpdesk_fields_debug_list( array $fields ): array {
		$lines = array();

		ksort( $fields );

		foreach ( $fields as $field_name => $info ) {
			if ( ! is_array( $info ) ) {
				continue;
			}

			$lines[] = array(
				'field'    => (string) $field_name,
				'label'    => (string) ( $info['string'] ?? $field_name ),
				'type'     => (string) ( $info['type'] ?? '' ),
				'relation' => (string) ( $info['relation'] ?? '' ),
			);
		}

		return $lines;
	}

	/**
	 * @param array<int, array{field: string, label: string, type: string, relation: string}> $lines Formatted rows.
	 *
	 * @return string
	 */
	private function render_helpdesk_fields_debug_html( array $lines ): string {
		if ( empty( $lines ) ) {
			return '<p>' . esc_html__( 'No fields returned.', 'gf-odoo-connector' ) . '</p>';
		}

		$html = '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Field', 'gf-odoo-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Label', 'gf-odoo-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Type', 'gf-odoo-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Relation', 'gf-odoo-connector' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $lines as $line ) {
			$html .= sprintf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $line['field'] ),
				esc_html( $line['label'] ),
				esc_html( $line['type'] ),
				esc_html( $line['relation'] )
			);
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Sanitize one per-field row from feed settings POST.
	 *
	 * Sanitize a "From field" mapping (string legacy or {field_id, field_label}).
	 *
	 * @param mixed $raw            Posted value.
	 * @param int   $sample_form_id Sample form for label lookup (templates).
	 *
	 * @return string|array
	 */
	private function sanitize_field_mapping_value( $raw, int $sample_form_id = 0 ) {
		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );
			if ( str_starts_with( $trimmed, '{' ) ) {
				$decoded = json_decode( $trimmed, true );
				if ( is_array( $decoded ) ) {
					$raw = $decoded;
				}
			}
		}

		if ( is_array( $raw ) ) {
			$field_id    = sanitize_text_field( (string) ( $raw['field_id'] ?? '' ) );
			$field_label = sanitize_text_field( (string) ( $raw['field_label'] ?? '' ) );
		} else {
			$field_id    = sanitize_text_field( trim( (string) $raw ) );
			$field_label = '';
		}

		if ( '' === $field_id ) {
			return '';
		}

		if ( '' === $field_label && $sample_form_id > 0 ) {
			$field_label = self::lookup_gf_field_label( $sample_form_id, $field_id );
		}

		if ( $sample_form_id > 0 || '' !== $field_label ) {
			return array(
				'field_id'    => $field_id,
				'field_label' => $field_label,
			);
		}

		return $field_id;
	}

	/**
	 * @param array<string, mixed> $settings Settings (by reference).
	 * @param array<string, mixed> $row      Field row definition.
	 * @param callable             $default_mode Callable returning default mode string.
	 * @param int                  $sample_form_id Sample form ID for template field labels.
	 */
	private function sanitize_feed_field_row_settings( array &$settings, array $row, callable $default_mode, int $sample_form_id = 0 ): void {
		$key      = (string) $row['key'];
		$mode_key = $key . '_mode';
		$val_key  = $key . '_value';
		$modes    = (array) $row['modes'];

		$mode = isset( $settings[ $mode_key ] ) ? sanitize_key( (string) $settings[ $mode_key ] ) : $default_mode( $row );

		if ( ! in_array( $mode, $modes, true ) ) {
			$mode = $default_mode( $row );
		}

		$settings[ $mode_key ] = $mode;

		if ( 'off' === $mode || 'auto' === $mode ) {
			$settings[ $val_key ] = '';
			return;
		}

		if ( 'field' === $mode ) {
			$settings[ $val_key ] = $this->sanitize_field_mapping_value(
				$settings[ $val_key ] ?? '',
				$sample_form_id
			);
			return;
		}

		if ( 'fixed' !== $mode ) {
			return;
		}

		$fixed_type = (string) rgar( $row, 'fixed_type', 'text' );

		if ( 'boolean' === $fixed_type ) {
			$settings[ $val_key ] = ! empty( $settings[ $val_key ] ) ? '1' : '0';
			return;
		}

		if ( 'date' === $fixed_type ) {
			$raw = isset( $settings[ $val_key ] ) ? sanitize_text_field( (string) $settings[ $val_key ] ) : '';
			$settings[ $val_key ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
			return;
		}

		if ( in_array( $fixed_type, array( 'odoo_select', 'static_select' ), true ) ) {
			$settings[ $val_key ] = ! empty( $settings[ $val_key ] ) ? (string) absint( $settings[ $val_key ] ) : '';
			return;
		}

		$settings[ $val_key ] = isset( $settings[ $val_key ] ) ? sanitize_text_field( (string) $settings[ $val_key ] ) : '';
	}

	/**
	 * Process a feed after form submission.
	 *
	 * @param array $feed  Feed object.
	 * @param array $entry Entry object.
	 * @param array $form  Form object.
	 *
	 * @return bool|array
	 */
	public function save_feed_settings( $feed_id, $form_id, $settings ) {
		$incoming = (array) $settings;
		$existing = array();

		if ( $feed_id ) {
			$feed = $this->get_feed( (int) $feed_id );
			if ( is_array( $feed ) ) {
				$existing = (array) rgar( $feed, 'meta', array() );
				if ( 'helpdesk' === rgar( $incoming, 'module', rgar( $existing, 'module', 'crm' ) ) ) {
					$this->migrate_helpdesk_feed_meta( $existing );
				} else {
					$this->migrate_feed_meta( $existing );
				}
			}
		}

		$settings = array_merge( $existing, $incoming );

		if ( empty( $settings['module'] ) ) {
			$settings['module'] = 'crm';
		}

		$module = (string) $settings['module'];

		$settings['crm_user_id'] = ! empty( $settings['crm_user_id'] ) ? (string) absint( $settings['crm_user_id'] ) : '';
		$settings['crm_team_id'] = ! empty( $settings['crm_team_id'] ) ? (string) absint( $settings['crm_team_id'] ) : '';

		$label_form_id = (int) $form_id;

		foreach ( $this->crm_field_rows() as $row ) {
			$this->sanitize_feed_field_row_settings(
				$settings,
				$row,
				array( CRM_Field_Config::class, 'default_mode' ),
				$label_form_id
			);
		}

		foreach ( $this->helpdesk_field_rows() as $row ) {
			$this->sanitize_feed_field_row_settings(
				$settings,
				$row,
				array( Helpdesk_Field_Config::class, 'default_mode' ),
				$label_form_id
			);
		}

		$settings['gf_odoo_crm_config_v2']       = '1';
		$settings['gf_odoo_helpdesk_config_v2'] = '1';
		$this->sync_source_hidden_field_id( (int) $form_id, $settings );

		$form_id = (int) $form_id;
		$feed_id = (int) $feed_id;

		if ( $form_id > 0 && $feed_id > 0 && class_exists( 'Template_Manager' ) ) {
			$this->persist_template_overrides_on_feed_save( $form_id, $feed_id, $settings, $module );
			$this->maybe_apply_pending_template_link( $form_id, $feed_id, $module );
		}

		return parent::save_feed_settings( $feed_id, $form_id, $settings );
	}

	/**
	 * Transient key for a template link queued before the feed has been saved.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return string
	 */
	private function get_pending_template_link_transient_key( int $form_id ): string {
		return 'gf_odoo_pending_tpl_' . get_current_user_id() . '_' . $form_id;
	}

	/**
	 * Apply a template link that was confirmed before the feed had an ID.
	 *
	 * @param int    $form_id Form ID.
	 * @param int    $feed_id Feed ID.
	 * @param string $module  crm|helpdesk.
	 */
	private function maybe_apply_pending_template_link( int $form_id, int $feed_id, string $module ): void {
		if ( ! class_exists( 'Template_Manager' ) ) {
			return;
		}

		$key     = $this->get_pending_template_link_transient_key( $form_id );
		$pending = get_transient( $key );

		if ( ! is_array( $pending ) ) {
			return;
		}

		delete_transient( $key );

		$template_id = (int) ( $pending['template_id'] ?? 0 );

		if ( $template_id <= 0 ) {
			return;
		}

		$field_remaps = isset( $pending['field_remaps'] ) && is_array( $pending['field_remaps'] )
			? $pending['field_remaps']
			: array();
		$overrides = isset( $pending['overrides'] ) && is_array( $pending['overrides'] )
			? $this->sanitize_feed_meta_array( (array) $pending['overrides'], $module )
			: array();

		$clean = Template_Manager::resolve_link_overrides( $template_id, $form_id, $feed_id, $field_remaps, $overrides );

		Template_Manager::link_feed_to_template( $template_id, $form_id, $feed_id, $clean );
	}

	/**
	 * Store per-form overrides when a feed is linked to a template (diff vs template defaults).
	 *
	 * @param int    $form_id  Form ID.
	 * @param int    $feed_id  Feed ID.
	 * @param array  $settings Sanitized feed meta about to be saved.
	 * @param string $module   crm|helpdesk.
	 */
	private function persist_template_overrides_on_feed_save( int $form_id, int $feed_id, array $settings, string $module ): void {
		$template_id = Template_Manager::get_linked_template_id( $form_id, $feed_id );

		if ( $template_id <= 0 ) {
			Template_Manager::unlink_feed( $form_id, $feed_id );
			return;
		}

		$template = Template_Manager::get( $template_id );

		if ( ! $template ) {
			return;
		}

		$base       = (array) $template->feed_meta;
		$template_id = (int) $template->id;
		$baseline   = Template_Manager::resolve_feed_meta_for_form( $template_id, $form_id, $base, array() );
		$rows       = 'helpdesk' === $module ? $this->helpdesk_field_rows() : $this->crm_field_rows();
		$overrides  = Template_Manager::get_feed_overrides( $form_id, $feed_id );

		foreach ( $rows as $row ) {
			$key      = (string) $row['key'];
			$mode_key = $key . '_mode';
			$val_key  = $key . '_value';

			if ( ! array_key_exists( $mode_key, $settings ) ) {
				continue;
			}

			$base_mode = (string) rgar( $baseline, $mode_key );
			$base_val  = rgar( $baseline, $val_key );
			$new_mode  = (string) $settings[ $mode_key ];
			$new_val   = rgar( $settings, $val_key );

			if (
				'field' === $new_mode
				&& '' === self::get_field_mapping_id( $new_val )
				&& '' !== self::get_field_mapping_id( $base_val )
				&& $new_mode === $base_mode
			) {
				unset( $overrides[ $mode_key ], $overrides[ $val_key ] );
				continue;
			}

			if ( $new_mode !== $base_mode || ! $this->feed_meta_values_equal( $new_val, $base_val ) ) {
				$overrides[ $mode_key ] = $new_mode;
				$overrides[ $val_key ]  = $new_val;
			} else {
				unset( $overrides[ $mode_key ], $overrides[ $val_key ] );
			}
		}

		$overrides = Template_Manager::prune_invalid_overrides( $base, $overrides );

		Template_Manager::link_feed_to_template( $template_id, $form_id, $feed_id, $overrides );
	}

	/**
	 * Compare feed meta values for template override diffing.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return bool
	 */
	private function feed_meta_values_equal( $a, $b ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			return self::get_field_mapping_id( $a ) === self::get_field_mapping_id( $b )
				&& self::get_field_mapping_label( $a ) === self::get_field_mapping_label( $b );
		}

		return (string) $a === (string) $b;
	}

	/**
	 * Refresh Odoo assignment dropdown cache when global connection settings are saved.
	 *
	 * Connection, Notifications, and Webhook are separate admin pages; each form only
	 * posts its own fields. Merge with existing settings so one page cannot wipe another.
	 *
	 * @param array $settings Posted settings from the current page.
	 */
	public function update_plugin_settings( $settings ) {
		$incoming = (array) $settings;
		$existing = $this->get_connection_settings();
		$merged   = array_merge( $existing, $incoming );

		// HTML-only fields are not real settings, so never persist them.
		$ui_only_keys = array(
			'connection_status',
			'test_connection',
			'error_log_link',
			'helpdesk_fields_debug',
			'webhook_url',
			'webhook_log',
			'api_key_change',
			'export_all_data',
			'clear_odoo_cache',
			'reset_plugin_settings',
			'crm_assignment_refresh_global',
			'smart_routing_beta_notice',
			'smart_routing_ai_heading',
		);
		foreach ( $ui_only_keys as $key ) {
			unset( $merged[ $key ] );
		}

		if ( array_key_exists( 'smart_routing_ai_key', $incoming ) ) {
			$raw_ai_key = (string) $incoming['smart_routing_ai_key'];

			if ( ! $this->is_masked_ai_key( $raw_ai_key ) && '' !== trim( $raw_ai_key ) ) {
				$this->store_ai_key( trim( $raw_ai_key ) );
			}

			// Never persist the raw AI key in GF settings.
			unset( $merged['smart_routing_ai_key'] );
		}

		foreach ( array( 'smart_routing_max_links', 'smart_routing_spam_threshold', 'smart_routing_confidence_threshold' ) as $int_key ) {
			if ( array_key_exists( $int_key, $incoming ) ) {
				$merged[ $int_key ] = (string) max( 0, (int) $incoming[ $int_key ] );
			}
		}

		if ( array_key_exists( 'smart_routing_helpdesk_team_id', $incoming ) ) {
			$merged['smart_routing_helpdesk_team_id'] = '' !== trim( (string) $incoming['smart_routing_helpdesk_team_id'] )
				? (string) absint( $incoming['smart_routing_helpdesk_team_id'] )
				: '';
		}

		if ( array_key_exists( 'smart_routing_helpdesk_desc_field', $incoming ) ) {
			$field_name = strtolower( trim( (string) $incoming['smart_routing_helpdesk_desc_field'] ) );
			$field_name = preg_replace( '/[^a-z0-9_]/', '', $field_name );
			$merged['smart_routing_helpdesk_desc_field'] = '' !== $field_name ? $field_name : 'description';
		}

		if ( array_key_exists( 'default_crm_user_id', $incoming ) ) {
			$merged['default_crm_user_id'] = ! empty( $incoming['default_crm_user_id'] ) ? (string) absint( $incoming['default_crm_user_id'] ) : '';
		}

		if ( array_key_exists( 'default_crm_team_id', $incoming ) ) {
			$merged['default_crm_team_id'] = ! empty( $incoming['default_crm_team_id'] ) ? (string) absint( $incoming['default_crm_team_id'] ) : '';
		}

		if ( array_key_exists( 'api_key', $incoming ) ) {
			$raw_incoming = (string) $incoming['api_key'];

			if ( ! $this->is_masked_api_key( $raw_incoming ) ) {
				$incoming_key = $this->normalize_api_key( $raw_incoming );

				if ( '' !== $incoming_key ) {
					$this->store_api_key( $incoming_key );
				}
			}

			// Never persist the raw key in GF settings (encrypted copy lives in gf_odoo_api_key).
			unset( $merged['api_key'] );
		}

		if ( array_key_exists( 'webhook_secret', $incoming ) ) {
			$incoming_webhook_secret = trim( (string) $incoming['webhook_secret'] );

			if ( '' !== $incoming_webhook_secret ) {
				$merged['webhook_secret'] = $incoming_webhook_secret;
			} else {
				$preserved_secret = trim( (string) rgar( $existing, 'webhook_secret' ) );
				if ( '' !== $preserved_secret ) {
					$merged['webhook_secret'] = $preserved_secret;
				}
			}
		}

		$this->clear_assignment_cache();
		Odoo_API::clear_cached_session();
		$this->invalidate_connection_status();

		$this->persist_connection_api_key( $merged );

		parent::update_plugin_settings( $merged );
	}

	/**
	 * Resolved Odoo module for a feed (crm or helpdesk).
	 *
	 * @param array $feed Feed object.
	 *
	 * @return string
	 */
	public function get_feed_module( $feed ) {
		$module = (string) rgars( $feed, 'meta/module' );

		if ( '' === $module ) {
			return 'crm';
		}

		return $module;
	}

	public function process_feed( $feed, $entry, $form ) {
		$module   = $this->get_feed_module( $feed );
		$entry_id = (int) rgar( $entry, 'id' );
		$feed_id  = (int) rgar( $feed, 'id' );
		$form_id  = (int) rgar( $form, 'id' );

		$this->log_debug(
			sprintf(
				'Queueing feed #%d for entry #%d (module: %s).',
				$feed_id,
				$entry_id,
				$module ? $module : '(not set)'
			)
		);

		if ( empty( rgar( $feed, 'is_active' ) ) ) {
			return;
		}

		if ( ! in_array( $module, array( 'crm', 'helpdesk' ), true ) ) {
			$this->add_note(
				$entry_id,
				sprintf(
					/* translators: %s: module value from feed settings */
					esc_html__( 'Odoo feed skipped: unknown module "%s". Edit the feed and set Module to CRM or Helpdesk.', 'gf-odoo-connector' ),
					$module ? $module : ''
				),
				'error'
			);

			return false;
		}

		if ( $entry_id <= 0 ) {
			return false;
		}

		// Smart routing (Beta): may skip spam, defer to AI, or reroute the module.
		$smart = null;
		try {
			$smart = $this->maybe_smart_route( $feed, $entry, $form );
		} catch ( Throwable $e ) {
			$this->log_error( 'Smart routing error (continuing with normal routing): ' . $e->getMessage() );
			$smart = null;
		}

		if ( is_array( $smart ) && ! empty( $smart['skip'] ) ) {
			gform_update_meta( $entry_id, 'odoo_sync_status', 'skipped', $form_id );
			gform_delete_meta( $entry_id, 'odoo_next_retry_at', $form_id );

			if ( ! empty( $smart['note'] ) ) {
				$this->add_note( $entry_id, $smart['note'], 'note' );
			}

			return true;
		}

		if ( GF_Odoo_Async_Sync::has_pending_job( $entry_id, $feed_id ) ) {
			$this->log_debug(
				sprintf(
					'Skipped duplicate sync job for entry #%d feed #%d.',
					$entry_id,
					$feed_id
				)
			);

			return true;
		}

		try {
			if ( is_array( $smart ) && ! empty( $smart['job'] ) ) {
				$job    = $smart['job'];
				$module = (string) rgar( $job, 'module', $module );
			} else {
				$job = $this->build_async_job_payload( $feed, $entry, $form, 1 );
			}
		} catch ( Exception $e ) {
			$this->log_sync_failure( $feed, $entry, $form, $module, $e, null );
			gform_update_meta( $entry_id, 'odoo_sync_status', 'failed', $form_id );

			return false;
		}

		if ( is_array( $smart ) && ! empty( $smart['note'] ) ) {
			$this->add_note( $entry_id, $smart['note'], 'note' );
		}

		gform_update_meta( $entry_id, 'odoo_sync_status', 'pending', $form_id );
		gform_delete_meta( $entry_id, 'odoo_next_retry_at', $form_id );

		$scheduled = GF_Odoo_Async_Sync::schedule( time() + GF_Odoo_Async_Sync::SCHEDULE_DELAY, $job );

		if ( $scheduled > 0 ) {
			$this->add_note(
				$entry_id,
				esc_html__( 'Odoo sync scheduled.', 'gf-odoo-connector' ),
				'note'
			);

			return true;
		}

		$this->log_error(
			__( 'Could not schedule background Odoo sync; running synchronously.', 'gf-odoo-connector' )
		);

		$this->add_note(
			$entry_id,
			esc_html__( 'Odoo sync started immediately (scheduler unavailable).', 'gf-odoo-connector' ),
			'note'
		);

		return $this->process_sync_job( $job );
	}

	/**
	 * Background (or fallback sync) handler for Odoo API calls.
	 *
	 * @param array $payload Job payload (snapshot; not re-read from GF entry).
	 *
	 * @return bool True when sync succeeded.
	 */
	public function process_sync_job( $payload ): bool {
		$this->last_sync_job_error = '';

		if ( ! is_array( $payload ) ) {
			return false;
		}

		$payload = GF_Odoo_Async_Sync::normalize_job_args( $payload );

		// Deferred Smart routing job: resolve module/payload (and AI) before syncing.
		if ( ! empty( $payload['smart_routing'] ) && empty( $payload['sync_payload'] ) ) {
			try {
				$resolved = $this->resolve_smart_routing_job( $payload );
			} catch ( Throwable $e ) {
				$this->last_sync_job_error = $e->getMessage();

				$fail_entry_id = (int) rgar( $payload, 'entry_id', 0 );
				$fail_form_id  = (int) rgar( $payload, 'form_id', 0 );

				$this->log_error( 'Smart routing job could not be built: ' . $e->getMessage() );

				if ( $fail_entry_id > 0 ) {
					gform_update_meta( $fail_entry_id, 'odoo_sync_status', 'failed', $fail_form_id );
					$this->add_note(
						$fail_entry_id,
						sprintf(
							/* translators: %s: error message */
							esc_html__( 'Smart routing could not build the Odoo payload: %s', 'gf-odoo-connector' ),
							$e->getMessage()
						),
						'error'
					);
				}

				return false;
			}

			if ( 'skip' === $resolved ) {
				$skip_entry_id = (int) rgar( $payload, 'entry_id', 0 );
				$skip_form_id  = (int) rgar( $payload, 'form_id', 0 );

				if ( $skip_entry_id > 0 ) {
					gform_update_meta( $skip_entry_id, 'odoo_sync_status', 'skipped', $skip_form_id );
					gform_delete_meta( $skip_entry_id, 'odoo_next_retry_at', $skip_form_id );
				}

				return true;
			}

			if ( ! is_array( $resolved ) ) {
				$this->last_sync_job_error = 'Smart routing could not be resolved (entry, form, or feed missing).';

				return false;
			}

			$payload = $resolved;
		}

		$job = $this->normalize_job_payload( $payload );

		$entry_id = (int) $job['entry_id'];
		$form_id  = (int) $job['form_id'];
		$module   = (string) $job['module'];

		if ( $entry_id <= 0 || '' === $module ) {
			return false;
		}

		try {
			$api = $this->get_odoo_api();

			if ( null === $api ) {
				throw new RuntimeException( $this->get_incomplete_connection_message() );
			}

			$this->apply_test_mode_to_job( $job );

			if ( 'crm' === $module ) {
				$this->execute_crm_sync( $api, $job );
			} elseif ( 'helpdesk' === $module ) {
				$this->execute_helpdesk_sync( $api, $job );
			} else {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: module key */
						__( 'Unknown module "%s" in sync job.', 'gf-odoo-connector' ),
						$module
					)
				);
			}

			gform_update_meta( $entry_id, 'odoo_sync_status', 'success', $form_id );
			gform_update_meta( $entry_id, 'odoo_sync_at', current_time( 'mysql' ), $form_id );
			gform_update_meta( $entry_id, 'odoo_module', $module, $form_id );
			gform_delete_meta( $entry_id, 'odoo_next_retry_at', $form_id );

			update_option( 'gf_odoo_last_success_at', current_time( 'mysql' ), false );

			Error_Logger::resolve_by_entry( $entry_id );

			if ( class_exists( 'Dashboard' ) ) {
				Dashboard::invalidate_summary_counts_cache();
			}

			return true;
		} catch ( Throwable $e ) {
			$this->last_sync_job_error = $e->getMessage();
			$this->handle_sync_failure( $job, $e );

			if ( class_exists( 'Dashboard' ) ) {
				Dashboard::invalidate_summary_counts_cache();
			}

			return false;
		}
	}

	/**
	 * Error message from the last process_sync_job() call in this request.
	 *
	 * @return string
	 */
	public function get_last_sync_job_error(): string {
		return $this->last_sync_job_error;
	}

	/**
	 * @param array     $payload Job payload.
	 * @param Throwable $e       Failure.
	 */
	private function handle_sync_failure( array $payload, Throwable $e ): void {
		$job      = $this->normalize_job_payload( $payload );
		$attempt  = max( 1, (int) ( $job['attempt'] ?? 1 ) );
		$entry_id = (int) $job['entry_id'];
		$form_id  = (int) $job['form_id'];
		$feed_id  = (int) $job['feed_id'];
		$module   = (string) $job['module'];
		$is_manual = ! empty( $job['is_manual'] );

		$retry_delays = GF_Odoo_Async_Sync::retry_delays();

		$error_id = Error_Logger::log(
			array(
				'form_id'       => $form_id,
				'entry_id'      => $entry_id,
				'feed_id'       => $feed_id,
				'module'        => $module,
				'error_message' => $this->format_sync_error_message( $e ),
				'error_code'    => get_class( $e ),
				'payload'       => $job,
				'attempt'       => $attempt,
			)
		);

		$job['error_id'] = $error_id;

		$this->log_error(
			sprintf(
				'GF Odoo Connector %1$s (feed #%2$d, entry #%3$d, attempt %4$d): %5$s',
				$module,
				$feed_id,
				$entry_id,
				$attempt,
				$e->getMessage()
			)
		);

		if ( $entry_id > 0 ) {
			$this->add_note(
				$entry_id,
				sprintf(
					/* translators: 1: attempt number, 2: error message */
					__( 'Odoo sync failed (attempt %1$d): %2$s', 'gf-odoo-connector' ),
					$attempt,
					$e->getMessage()
				),
				'error'
			);
		}

		$skip_retry = $this->skip_auto_retry_for_sync || ! empty( $job['skip_auto_retry'] );

		$can_auto_retry = ! $is_manual
			&& ! $skip_retry
			&& isset( $retry_delays[ $attempt ] )
			&& GF_Odoo_Async_Sync::is_available();

		if ( $can_auto_retry ) {
			$next_attempt = $attempt + 1;
			$delay        = (int) $retry_delays[ $attempt ];
			$retry_at     = time() + $delay;

			$next_job            = $job;
			$next_job['attempt'] = $next_attempt;

			$scheduled_id = GF_Odoo_Async_Sync::schedule( $retry_at, $next_job );

			if ( $scheduled_id <= 0 ) {
				gform_update_meta( $entry_id, 'odoo_sync_status', 'failed', $form_id );
				gform_delete_meta( $entry_id, 'odoo_next_retry_at', $form_id );

				if ( $entry_id > 0 ) {
					$this->add_note(
						$entry_id,
						esc_html__(
							'Automatic retry could not be scheduled. Use Retry now in the error log after fixing the connection.',
							'gf-odoo-connector'
						),
						'error'
					);
				}

				return;
			}

			gform_update_meta( $entry_id, 'odoo_sync_status', 'retrying', $form_id );
			gform_update_meta( $entry_id, 'odoo_next_retry_at', $retry_at, $form_id );

			if ( $entry_id > 0 ) {
				$this->add_note(
					$entry_id,
					sprintf(
						/* translators: 1: human-readable delay, 2: local date/time */
						__( 'Retry scheduled in %1$s (at %2$s).', 'gf-odoo-connector' ),
						human_time_diff( time(), $retry_at ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $retry_at )
					),
					'note'
				);
			}

			return;
		}

		gform_update_meta( $entry_id, 'odoo_sync_status', 'failed', $form_id );
		gform_delete_meta( $entry_id, 'odoo_next_retry_at', $form_id );

		if ( $entry_id > 0 ) {
			$this->add_note(
				$entry_id,
				esc_html__( 'All retry attempts exhausted. Manual intervention required.', 'gf-odoo-connector' ),
				'error'
			);
		}

		if ( ! $is_manual && $attempt >= GF_Odoo_Async_Sync::MAX_ATTEMPTS ) {
			$this->send_failure_notification( $job, $e );
		}
	}

	/**
	 * Email admins when all automatic retries are exhausted.
	 *
	 * @param array     $payload Job payload.
	 * @param Throwable $e       Last error.
	 */
	private function send_failure_notification( array $payload, Throwable $e ): void {
		$settings = (array) $this->get_plugin_settings();

		$notify = rgar( $settings, 'notify_on_error' );
		if ( is_array( $notify ) ) {
			$notify = rgar( $notify, 'notify_on_error' );
		}

		if ( empty( $notify ) ) {
			return;
		}

		$email = isset( $settings['notify_email'] ) ? sanitize_email( (string) $settings['notify_email'] ) : '';

		if ( '' === $email ) {
			$email = sanitize_email( (string) get_option( 'admin_email' ) );
		}

		if ( '' === $email ) {
			return;
		}

		$form_title = (string) ( $payload['form_title'] ?? '' );
		$entry_id   = (int) ( $payload['entry_id'] ?? 0 );
		$admin_url  = $this->get_error_log_admin_url();

		wp_mail(
			$email,
			sprintf(
				/* translators: 1: form title, 2: entry ID */
				'[GF Odoo Connector] Sync failed: %1$s (entry #%2$d)',
				'' !== $form_title ? $form_title : __( 'Unknown form', 'gf-odoo-connector' ),
				$entry_id
			),
			sprintf(
				"The Odoo sync for entry #%1\$d from form \"%2\$s\" failed after all retry attempts.\n\nError: %3\$s\n\nView error log: %4\$s",
				$entry_id,
				$form_title,
				$e->getMessage(),
				$admin_url
			)
		);
	}

	/**
	 * Build immutable job payload at submission time.
	 *
	 * @param array $feed    Feed.
	 * @param array $entry   Entry.
	 * @param array $form    Form.
	 * @param int   $attempt Attempt number (1–4).
	 *
	 * @return array
	 *
	 * @throws Exception When mapping fails.
	 */
	private function build_async_job_payload( $feed, $entry, $form, int $attempt = 1 ): array {
		$module = $this->get_feed_module( $feed );

		if ( 'crm' === $module ) {
			$sync_payload = $this->build_crm_sync_payload( $feed, $entry, $form );
		} elseif ( 'helpdesk' === $module ) {
			$sync_payload = $this->build_helpdesk_sync_payload( $feed, $entry, $form );
		} else {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: module key */
					__( 'Unknown module "%s".', 'gf-odoo-connector' ),
					$module
				)
			);
		}

		return array(
			'feed_id'      => (int) rgar( $feed, 'id' ),
			'entry_id'     => (int) rgar( $entry, 'id' ),
			'form_id'      => (int) rgar( $form, 'id' ),
			'module'       => $module,
			'attempt'      => max( 1, min( GF_Odoo_Async_Sync::MAX_ATTEMPTS, $attempt ) ),
			'sync_payload' => $sync_payload,
			'form_title'   => (string) rgar( $form, 'title' ),
		);
	}

	/**
	 * Normalize stored error payloads and full job payloads.
	 *
	 * @param array $payload Raw payload from queue or error log.
	 *
	 * @return array
	 */
	private function normalize_job_payload( array $payload ): array {
		if ( isset( $payload['sync_payload'] ) && is_array( $payload['sync_payload'] ) ) {
			return wp_parse_args(
				$payload,
				array(
					'feed_id'    => 0,
					'entry_id'   => 0,
					'form_id'    => 0,
					'module'     => '',
					'attempt'    => 1,
					'form_title' => '',
				)
			);
		}

		$module = (string) rgar( $payload, 'module', '' );

		if ( '' === $module ) {
			if ( isset( $payload['lead'] ) || isset( $payload['partner'] ) ) {
				$module = 'crm';
			} elseif ( isset( $payload['ticket'] ) ) {
				$module = 'helpdesk';
			}
		}

		return array(
			'feed_id'      => (int) rgar( $payload, 'feed_id', 0 ),
			'entry_id'     => (int) rgar( $payload, 'entry_id', 0 ),
			'form_id'      => (int) rgar( $payload, 'form_id', 0 ),
			'module'       => $module,
			'attempt'      => max( 1, (int) rgar( $payload, 'attempt', 1 ) ),
			'sync_payload' => $payload,
			'form_title'   => (string) rgar( $payload, 'form_title', '' ),
			'is_manual'    => ! empty( $payload['is_manual'] ),
		);
	}

	/**
	 * Build a deep link to an Odoo record in the web client.
	 *
	 * @param string $model    Odoo model technical name.
	 * @param int    $record_id Record ID.
	 *
	 * @return string
	 */
	private function get_odoo_record_url( string $model, int $record_id ): string {
		$base_url = untrailingslashit( (string) rgar( $this->get_connection_settings(), 'odoo_url' ) );

		if ( '' === $base_url || $record_id <= 0 ) {
			return '';
		}

		$frontend_paths = array(
			'crm.lead'         => 'crm',
			'helpdesk.ticket'  => 'helpdesk',
			'res.partner'      => 'contacts',
		);

		if ( isset( $frontend_paths[ $model ] ) ) {
			return sprintf(
				'%s/odoo/%s/%d',
				$base_url,
				$frontend_paths[ $model ],
				$record_id
			);
		}

		return sprintf(
			'%s/web#id=%d&model=%s&view_type=form',
			$base_url,
			$record_id,
			rawurlencode( $model )
		);
	}

	/**
	 * Run CRM sync from a queued job payload.
	 *
	 * @param Odoo_API $api API client.
	 * @param array    $job Job payload.
	 *
	 * @throws Exception On API failure.
	 */
	private function execute_crm_sync( Odoo_API $api, array $job ): void {
		$entry_id = (int) $job['entry_id'];
		$form_id  = (int) $job['form_id'];
		$feed_id  = (int) $job['feed_id'];
		$payload  = (array) $job['sync_payload'];

		$contact_data = ! empty( $payload['contact'] ) ? (array) $payload['contact'] : (array) rgar( $payload, 'partner', array() );
		$lead_data    = (array) rgar( $payload, 'lead', array() );

		$this->log_debug(
			'CRM payload, partner: ' . wp_json_encode( $contact_data ) . ' lead: ' . wp_json_encode( $lead_data )
		);

		$crm        = new CRM_Handler( $api );
		$partner_id = $crm->create_or_update_contact( $contact_data );
		$lead_id    = $crm->create_lead(
			$partner_id,
			$lead_data,
			array(
				'partner'    => $contact_data,
				'form_title' => (string) rgar( $payload, 'form_title', rgar( $job, 'form_title', '' ) ),
				'feed_meta'  => (array) rgar( $payload, 'feed_meta', array() ),
			)
		);

		gform_update_meta( $entry_id, 'odoo_lead_id', $lead_id, $form_id );
		gform_update_meta( $entry_id, 'odoo_partner_id', $partner_id, $form_id );

		$lead_summary = $crm->get_lead_summary( $lead_id );
		$lead_label   = $lead_summary && '' !== $lead_summary['name']
			? $lead_summary['name']
			: sprintf( '#%d', $lead_id );

		$note = sprintf(
			/* translators: 1: partner ID, 2: lead ID, 3: lead title */
			__( 'Odoo CRM: contact #%1$d, lead #%2$d (%3$s) created.', 'gf-odoo-connector' ),
			$partner_id,
			$lead_id,
			$lead_label
		);

		$lead_url = $this->get_odoo_record_url( 'crm.lead', $lead_id );
		if ( '' !== $lead_url ) {
			$note .= ' ' . sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $lead_url ),
				esc_html__( 'Open in Odoo', 'gf-odoo-connector' )
			);
		}

		$this->add_note( $entry_id, $note, 'success' );
		$this->maybe_add_odoo_payload_entry_note( $entry_id, $payload, 'crm' );

		$this->log_debug(
			sprintf(
				'CRM feed #%d processed for entry #%d: partner #%d, lead #%d.',
				$feed_id,
				$entry_id,
				$partner_id,
				$lead_id
			)
		);
	}

	/**
	 * Run Helpdesk sync from a queued job payload.
	 *
	 * @param Odoo_API $api API client.
	 * @param array    $job Job payload.
	 *
	 * @throws Exception On API failure.
	 */
	private function execute_helpdesk_sync( Odoo_API $api, array $job ): void {
		$entry_id = (int) $job['entry_id'];
		$form_id  = (int) $job['form_id'];
		$feed_id  = (int) $job['feed_id'];
		$payload  = (array) $job['sync_payload'];

		$ticket_data = ! empty( $payload['ticket'] ) ? (array) $payload['ticket'] : $payload;

		$this->log_debug( 'Helpdesk payload: ' . wp_json_encode( $ticket_data ) );

		$helpdesk  = new Helpdesk_Handler( $api, new CRM_Handler( $api ) );
		$ticket_id = $helpdesk->create_ticket( $ticket_data );

		gform_update_meta( $entry_id, 'odoo_ticket_id', $ticket_id, $form_id );

		$subject = ! empty( $ticket_data['name'] ) ? $ticket_data['name'] : sprintf( '#%d', $ticket_id );

		$note = sprintf(
			/* translators: 1: ticket ID, 2: ticket subject */
			__( 'Odoo Helpdesk: ticket #%1$d (%2$s) created.', 'gf-odoo-connector' ),
			$ticket_id,
			$subject
		);

		$ticket_url = $this->get_odoo_record_url( 'helpdesk.ticket', $ticket_id );
		if ( '' !== $ticket_url ) {
			$note .= ' ' . sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $ticket_url ),
				esc_html__( 'Open in Odoo', 'gf-odoo-connector' )
			);
		}

		$this->add_note( $entry_id, $note, 'success' );
		$this->maybe_add_odoo_payload_entry_note( $entry_id, array( 'ticket' => $ticket_data ), 'helpdesk' );

		$this->log_debug(
			sprintf(
				'Helpdesk feed #%d processed for entry #%d: ticket #%d.',
				$feed_id,
				$entry_id,
				$ticket_id
			)
		);
	}

	/**
	 * Whether plugin settings request Odoo payload notes on entries.
	 *
	 * @return bool
	 */
	private function is_payload_entry_notes_enabled(): bool {
		$settings = $this->get_connection_settings();
		$flag     = rgar( $settings, 'log_payload_in_entry_notes' );

		if ( is_array( $flag ) ) {
			$flag = rgar( $flag, 'log_payload_in_entry_notes' );
		}

		return ! empty( $flag );
	}

	/**
	 * Add an entry note with Odoo API field values when the setting is enabled.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param array  $payload  Sync job payload (contact/lead or ticket).
	 * @param string $module   crm|helpdesk.
	 */
	private function maybe_add_odoo_payload_entry_note( int $entry_id, array $payload, string $module ): void {
		if ( $entry_id <= 0 || ! $this->is_payload_entry_notes_enabled() ) {
			return;
		}

		$body = $this->format_odoo_payload_entry_note( $payload, $module );

		if ( '' === $body ) {
			return;
		}

		$this->add_note( $entry_id, $body, 'note' );
	}

	/**
	 * Human-readable summary of data sent to Odoo (for GF entry notes).
	 *
	 * @param array  $payload Sync payload.
	 * @param string $module  crm|helpdesk.
	 *
	 * @return string
	 */
	private function format_odoo_payload_entry_note( array $payload, string $module ): string {
		$sections = array();

		if ( 'crm' === $module ) {
			$contact = ! empty( $payload['contact'] )
				? (array) $payload['contact']
				: (array) rgar( $payload, 'partner', array() );
			$lead    = (array) rgar( $payload, 'lead', array() );

			if ( ! empty( $contact ) ) {
				$sections[ __( 'Contact (res.partner)', 'gf-odoo-connector' ) ] = $contact;
			}
			if ( ! empty( $lead ) ) {
				$sections[ __( 'Lead (crm.lead)', 'gf-odoo-connector' ) ] = $lead;
			}
		} else {
			$ticket = ! empty( $payload['ticket'] )
				? (array) $payload['ticket']
				: $payload;

			if ( ! empty( $ticket ) ) {
				$sections[ __( 'Ticket (helpdesk.ticket)', 'gf-odoo-connector' ) ] = $ticket;
			}
		}

		if ( empty( $sections ) ) {
			return '';
		}

		$lines = array(
			__( 'Odoo sync, field values sent:', 'gf-odoo-connector' ),
		);

		foreach ( $sections as $title => $fields ) {
			$lines[] = '';
			$lines[] = $title . ':';

			ksort( $fields );

			foreach ( $fields as $key => $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = wp_json_encode( $value );
				} elseif ( is_bool( $value ) ) {
					$value = $value ? 'true' : 'false';
				} else {
					$value = (string) $value;
				}

				$lines[] = '  ' . $key . ': ' . $value;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param Throwable $e Caught exception.
	 *
	 * @return string
	 */
	private function format_sync_error_message( Throwable $e ): string {
		$message = $e->getMessage();
		if ( class_exists( 'Odoo_API' ) && Odoo_API::is_access_denied_message( $message ) ) {
			$hint = __( '(Check CRM/Contacts permissions for the API user in Odoo.)', 'gf-odoo-connector' );
			if ( stripos( $message, 'res.users' ) !== false ) {
				$hint = __( '(In Odoo, grant the API user read access on Users, or set an explicit Sales team on the feed and avoid assigning a salesperson the user cannot read.)', 'gf-odoo-connector' );
			}

			return __( 'Odoo access denied:', 'gf-odoo-connector' ) . ' ' . $message . ' ' . $hint;
		}

		return $message;
	}

	/**
	 * Log a failed sync, notify admins, and add an entry note.
	 *
	 * @param array           $feed    Feed object.
	 * @param array           $entry   Entry object.
	 * @param array           $form    Form object.
	 * @param string          $module  crm|helpdesk.
	 * @param Exception       $e       Caught exception.
	 * @param array|null      $payload Data that was (or would be) sent to Odoo.
	 */
	private function log_sync_failure( $feed, $entry, $form, string $module, Exception $e, ?array $payload ): void {
		$entry_id = (int) rgar( $entry, 'id' );
		$form_id  = (int) rgar( $form, 'id' );
		$feed_id  = (int) rgar( $feed, 'id' );

		$error_id = Error_Logger::log(
			array(
				'form_id'       => $form_id,
				'entry_id'      => $entry_id,
				'feed_id'       => $feed_id,
				'module'        => $module,
				'error_message' => $this->format_sync_error_message( $e ),
				'error_code'    => get_class( $e ),
				'payload'       => $payload,
				'attempt'       => 1,
			)
		);

		$message = sprintf(
			'GF Odoo Connector %1$s (feed #%2$d, entry #%3$d): %4$s',
			$module,
			$feed_id,
			$entry_id,
			$e->getMessage()
		);

		$this->log_error( $message );

		$this->maybe_send_error_notification( $form, $entry, $e, $error_id );

		if ( $entry_id > 0 ) {
			$note_label = 'crm' === $module
				? __( 'Odoo CRM sync failed: %s', 'gf-odoo-connector' )
				: __( 'Odoo Helpdesk sync failed: %s', 'gf-odoo-connector' );

			$this->add_note(
				$entry_id,
				sprintf(
					/* translators: %s: error message */
					$note_label,
					$e->getMessage()
				),
				'error'
			);
		}
	}

	/**
	 * Send optional email when a sync fails.
	 *
	 * @param array     $form     Form object.
	 * @param array     $entry    Entry object.
	 * @param Exception $e        Exception.
	 * @param int       $error_id Log row ID (for link).
	 */
	private function maybe_send_error_notification( $form, $entry, Exception $e, int $error_id ): void {
		$settings = (array) $this->get_plugin_settings();

		$notify = rgar( $settings, 'notify_on_error' );
		if ( is_array( $notify ) ) {
			$notify = rgar( $notify, 'notify_on_error' );
		}

		if ( empty( $notify ) ) {
			return;
		}

		$email = isset( $settings['notify_email'] ) ? sanitize_email( (string) $settings['notify_email'] ) : '';

		if ( '' === $email ) {
			$email = sanitize_email( (string) get_option( 'admin_email' ) );
		}

		if ( '' === $email ) {
			return;
		}

		$form_title = (string) rgar( $form, 'title' );
		$entry_id   = (int) rgar( $entry, 'id' );

		$subject = sprintf(
			/* translators: %s: form title */
			__( '[GF Odoo Connector] Sync failed for form %s', 'gf-odoo-connector' ),
			'' !== $form_title ? $form_title : '#' . (int) rgar( $form, 'id' )
		);

		$body = sprintf(
			"%1$s\n\n%2$s: %3$d\n%4$s: %5$s\n\n%6$s:\n%7$s",
			sprintf(
				/* translators: %s: form title */
				__( 'A Gravity Forms submission failed to sync to Odoo for form "%s".', 'gf-odoo-connector' ),
				$form_title
			),
			__( 'Entry ID', 'gf-odoo-connector' ),
			$entry_id,
			__( 'Error', 'gf-odoo-connector' ),
			$e->getMessage(),
			__( 'View error log', 'gf-odoo-connector' ),
			$this->get_error_log_admin_url()
		);

		wp_mail( $email, $subject, $body );
	}

	/**
	 * Admin URL for the error log screen.
	 *
	 * @return string
	 */
	public function get_error_log_admin_url(): string {
		return class_exists( 'GF_Odoo_Admin_Menu' )
			? GF_Odoo_Admin_Menu::url( 'gf_odoo_errors' )
			: admin_url( 'admin.php?page=gf_odoo_errors' );
	}

	/**
	 * Settings markup: webhook URL + copy button.
	 *
	 * @return string
	 */
	private function get_webhook_url_markup(): string {
		$url = class_exists( 'Webhook_Receiver' ) ? Webhook_Receiver::get_webhook_url() : '';

		return sprintf(
			'<p><input type="text" class="large-text code" id="gf-odoo-webhook-url" readonly value="%1$s" /></p>'
			. '<p><button type="button" class="button" id="gf-odoo-copy-webhook-url">%2$s</button>'
			. ' <span id="gf-odoo-copy-webhook-result" class="description" role="status" aria-live="polite"></span></p>',
			esc_attr( $url ),
			esc_html__( 'Copy URL', 'gf-odoo-connector' )
		);
	}

	/**
	 * Settings markup: last received webhooks.
	 *
	 * @return string
	 */
	private function get_webhook_log_markup(): string {
		if ( ! class_exists( 'Webhook_Receiver' ) ) {
			return '';
		}

		$log = Webhook_Receiver::get_log();

		if ( empty( $log ) ) {
			return '<p class="description">' . esc_html__( 'No webhooks received yet.', 'gf-odoo-connector' ) . '</p>';
		}

		$rows = '';

		foreach ( $log as $row ) {
			$entries = array();
			if ( ! empty( $row['entries'] ) && is_array( $row['entries'] ) ) {
				foreach ( $row['entries'] as $entry_id ) {
					$entries[] = '#' . absint( $entry_id );
				}
			}

			$rows .= sprintf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$d</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) ( $row['time'] ?? '' ) ),
				esc_html( (string) ( $row['model'] ?? '' ) ),
				absint( $row['odoo_id'] ?? 0 ),
				esc_html( ! empty( $entries ) ? implode( ', ', $entries ) : '-' ),
				esc_html( (string) ( $row['status'] ?? '' ) )
			);
		}

		return sprintf(
			'<table class="gf-odoo-table gf-odoo-webhook-log"><thead><tr>'
			. '<th>%1$s</th><th>%2$s</th><th>%3$s</th><th>%4$s</th><th>%5$s</th>'
			. '</tr></thead><tbody>%6$s</tbody></table>',
			esc_html__( 'Time', 'gf-odoo-connector' ),
			esc_html__( 'Model', 'gf-odoo-connector' ),
			esc_html__( 'Odoo ID', 'gf-odoo-connector' ),
			esc_html__( 'GF entries', 'gf-odoo-connector' ),
			esc_html__( 'Status', 'gf-odoo-connector' ),
			$rows
		);
	}

	/**
	 * Register front-end styles for the ticket status shortcode.
	 */
	public function register_frontend_styles(): void {
		wp_register_style(
			'gf-odoo-frontend',
			GF_ODOO_URL . 'assets/css/frontend.css',
			array(),
			GF_ODOO_VERSION
		);
	}

	/**
	 * Shortcode: [odoo_ticket_status] on thank-you / confirmation pages.
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render_ticket_status_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'entry_id' => '',
				'label'    => __( 'Your support ticket', 'gf-odoo-connector' ),
			),
			$atts,
			'odoo_ticket_status'
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$entry_id = (int) ( $atts['entry_id'] ?: absint( $_GET['entry_id'] ?? 0 ) );

		if ( $entry_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return '';
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry ) ) {
			return '';
		}

		wp_enqueue_style( 'gf-odoo-frontend' );

		$ticket_id   = (int) gform_get_meta( $entry_id, 'odoo_ticket_id' );
		$sync_status = (string) gform_get_meta( $entry_id, 'odoo_sync_status' );
		$stage       = (string) gform_get_meta( $entry_id, 'odoo_stage' );
		$assigned_to = (string) gform_get_meta( $entry_id, 'odoo_assigned_to' );

		ob_start();
		?>
		<div class="gf-odoo-ticket-status">
			<p class="gf-odoo-ticket-label"><?php echo esc_html( $atts['label'] ); ?></p>

			<?php if ( 'success' === $sync_status && $ticket_id > 0 ) : ?>
				<p class="gf-odoo-ticket-id">
					<?php
					printf(
						esc_html__( 'Ticket #%d', 'gf-odoo-connector' ),
						$ticket_id
					);
					?>
				</p>
				<?php if ( '' !== $stage ) : ?>
					<p class="gf-odoo-ticket-stage"><?php echo esc_html( $stage ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $assigned_to ) : ?>
					<p class="gf-odoo-ticket-agent">
						<?php
						printf(
							esc_html__( 'Assigned to: %s', 'gf-odoo-connector' ),
							esc_html( $assigned_to )
						);
						?>
					</p>
				<?php endif; ?>
			<?php elseif ( in_array( $sync_status, array( 'retrying', 'pending' ), true ) || ( '' === $sync_status && $ticket_id <= 0 ) ) : ?>
				<p class="gf-odoo-ticket-pending">
					<?php esc_html_e( 'Your ticket is being processed.', 'gf-odoo-connector' ); ?>
				</p>
			<?php else : ?>
				<p class="gf-odoo-ticket-error">
					<?php esc_html_e( 'We could not create your ticket. Our team has been notified.', 'gf-odoo-connector' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX: push entry data to Odoo immediately from entry detail.
	 */
	public function ajax_entry_sync_now(): void {
		check_ajax_referer( 'gf_odoo_entry_sync', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ),
				),
				403
			);
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;

		if ( $entry_id <= 0 ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Invalid entry ID.', 'gf-odoo-connector' ) )
			);
		}

		$result = $this->sync_entry_to_odoo_now( $entry_id );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error(
			array(
				'message' => isset( $result['message'] ) ? $result['message'] : esc_html__( 'Sync failed.', 'gf-odoo-connector' ),
			)
		);
	}

	/**
	 * Run all active Odoo feeds for an entry (manual sync from admin).
	 *
	 * @param int $entry_id Entry ID.
	 *
	 * @return array{success: bool, message?: string}
	 */
	public function sync_entry_to_odoo_now( int $entry_id ): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Gravity Forms is not available.', 'gf-odoo-connector' ),
			);
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Entry not found.', 'gf-odoo-connector' ),
			);
		}

		$form_id = (int) rgar( $entry, 'form_id' );
		$form    = GFAPI::get_form( $form_id );
		if ( is_wp_error( $form ) || empty( $form ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Form not found.', 'gf-odoo-connector' ),
			);
		}

		$feeds = $this->get_feeds( $form_id );
		$ran   = false;
		$ok    = false;

		foreach ( $feeds as $feed ) {
			if ( empty( $feed['is_active'] ) ) {
				continue;
			}

			$module = $this->get_feed_module( $feed );
			if ( ! in_array( $module, array( 'crm', 'helpdesk' ), true ) ) {
				continue;
			}

			$ran = true;

			try {
				$job = $this->build_async_job_payload( $feed, $entry, $form, 1 );
			} catch ( Exception $e ) {
				return array(
					'success' => false,
					'message' => $e->getMessage(),
				);
			}

			GF_Odoo_Async_Sync::cancel_pending_jobs( $entry_id, (int) rgar( $feed, 'id' ) );

			if ( $this->process_sync_job( $job ) ) {
				$ok = true;
			}
		}

		if ( ! $ran ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'No active Odoo feed found for this form.', 'gf-odoo-connector' ),
			);
		}

		if ( $ok ) {
			return array( 'success' => true );
		}

		return array(
			'success' => false,
			'message' => esc_html__( 'Odoo sync failed. Check entry notes or the error log.', 'gf-odoo-connector' ),
		);
	}

	/**
	 * Whether the current request is the error log admin page.
	 *
	 * @return bool
	 */
	public function is_error_log_page(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';

		return in_array( $page, array( 'gf_odoo_errors', 'gf_odoo_error_log' ), true );
	}

	/**
	 * Whether the current request is a GF entry detail screen.
	 *
	 * @return bool
	 */
	public function is_entry_detail_page(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'], $_GET['view'] )
			&& 'gf_entries' === $_GET['page']
			&& 'entry' === $_GET['view'];
	}

	/**
	 * Whether the current user may manage plugin pages and sync tools (GF Editor+).
	 *
	 * @return bool
	 */
	public function current_user_can_manage_plugin(): bool {
		// WordPress administrators (manage_options) always have full plugin access.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( current_user_can( 'gravityforms_edit_forms' ) ) {
			return true;
		}

		if ( class_exists( 'GFCommon' ) && GFCommon::current_user_can_any( array( 'gform_full_access', 'gravityforms_edit_settings' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the current user may export the error log CSV (WP administrators only).
	 *
	 * @return bool
	 */
	public function current_user_can_export_csv(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user may view or manage plugin admin screens.
	 *
	 * @return bool
	 */
	public function current_user_can_access_error_log(): bool {
		return $this->current_user_can_manage_plugin();
	}

	/**
	 * Capability for admin menu registration (must match a cap the current user has).
	 *
	 * @return string
	 */
	public function get_admin_menu_capability(): string {
		if ( class_exists( 'GFCommon' ) && GFCommon::current_user_can_any( array( 'gform_full_access' ) ) ) {
			return 'gform_full_access';
		}

		if ( current_user_can( 'manage_options' ) ) {
			return 'manage_options';
		}

		if ( current_user_can( 'gravityforms_edit_settings' ) ) {
			return 'gravityforms_edit_settings';
		}

		if ( current_user_can( 'gravityforms_view_settings' ) ) {
			return 'gravityforms_view_settings';
		}

		if ( current_user_can( 'gravityforms_edit_forms' ) ) {
			return 'gravityforms_edit_forms';
		}

		return 'manage_options';
	}

	/**
	 * Verify AJAX nonce and plugin management capability.
	 *
	 * @param string $nonce_action Nonce action name.
	 */
	private function verify_ajax_request( string $nonce_action = 'gf_odoo_nonce' ): void {
		check_ajax_referer( $nonce_action, 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ),
				),
				403
			);
		}
	}

	/**
	 * Plugin settings landing page: dashboard by default, settings via ?odoo_tab=settings.
	 */
	public function plugin_settings() {
		if ( class_exists( 'GF_Odoo_Admin_Menu' ) ) {
			wp_safe_redirect( GF_Odoo_Admin_Menu::url( 'gf_odoo_dashboard' ) );
			exit;
		}

		$this->render_dashboard_page();
	}

	/**
	 * Plugins list link → dashboard.
	 *
	 * @param string[] $links Plugin action links.
	 * @param string   $file  Plugin basename.
	 *
	 * @return string[]
	 */
	public function plugin_settings_link( $links, $file ) {
		if ( $file !== $this->get_path() ) {
			return $links;
		}

		array_unshift(
			$links,
			'<a href="' . esc_url( $this->get_dashboard_admin_url() ) . '">' . esc_html__( 'Dashboard', 'gf-odoo-connector' ) . '</a>'
		);

		return $links;
	}

	/**
	 * @return string
	 */
	public function get_dashboard_admin_url(): string {
		return admin_url( 'admin.php?page=gf_odoo_dashboard' );
	}

	/**
	 * @return string
	 */
	public function get_plugin_settings_admin_url(): string {
		return class_exists( 'GF_Odoo_Admin_Menu' )
			? GF_Odoo_Admin_Menu::url( 'gf_odoo_settings' )
			: admin_url( 'admin.php?page=gf_odoo_settings' );
	}

	/**
	 * Whether the current screen is the Odoo dashboard.
	 *
	 * @return bool
	 */
	public function is_dashboard_page(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && 'gf_odoo_dashboard' === sanitize_key( (string) $_GET['page'] );
	}

	/**
	 * Whether the current request is a plugin admin page (new menu).
	 *
	 * @return bool
	 */
	public function is_plugin_admin_page(): bool {
		return class_exists( 'GF_Odoo_Admin_Menu' ) && GF_Odoo_Admin_Menu::is_plugin_admin_page();
	}

	/**
	 * Whether the current request is the feed templates admin page.
	 *
	 * @return bool
	 */
	public function is_templates_admin_page(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && 'gf_odoo_templates' === sanitize_key( (string) $_GET['page'] );
	}

	/**
	 * Redirect legacy admin slugs to the new menu.
	 */
	public function maybe_redirect_legacy_admin_pages(): void {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';

		if ( 'gf_odoo_error_log' === $page && class_exists( 'GF_Odoo_Admin_Menu' ) ) {
			wp_safe_redirect( GF_Odoo_Admin_Menu::url( 'gf_odoo_errors' ) );
			exit;
		}
	}

	/**
	 * Open a plugin admin page wrapper.
	 *
	 * @param string $title Page heading (optional).
	 */
	public function render_admin_page_open( string $title = '', string $extra_class = '' ): void {
		$classes = trim( 'gf-odoo-page ' . $extra_class );
		echo '<div class="wrap ' . esc_attr( $classes ) . '">';

		if ( '' !== $title ) {
			echo '<h1 class="gf-odoo-page-title">' . esc_html( $title ) . '</h1>';
		}
	}

	/**
	 * Close the plugin admin page wrapper.
	 */
	public function render_admin_page_close(): void {
		echo '</div>';
	}

	/**
	 * Logo URL for About page and setup wizard (transparent PNG generated once).
	 *
	 * @return string
	 */
	public function get_logo_url(): string {
		$src         = GF_ODOO_PATH . 'assets/images/logo.png';
		$transparent = GF_ODOO_PATH . 'assets/images/logo-transparent.png';

		if ( file_exists( $src ) && ! file_exists( $transparent ) ) {
			$this->make_logo_transparent( $src, $transparent );
		}

		if ( file_exists( $transparent ) ) {
			return GF_ODOO_URL . 'assets/images/logo-transparent.png';
		}

		if ( file_exists( $src ) ) {
			return GF_ODOO_URL . 'assets/images/logo.png';
		}

		return '';
	}

	/**
	 * Strip near-black background from logo PNG (cached on disk).
	 *
	 * @param string $src  Source PNG path.
	 * @param string $dest Destination PNG path.
	 */
	private function make_logo_transparent( string $src, string $dest ): void {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return;
		}

		$img = imagecreatefrompng( $src );
		if ( ! $img ) {
			return;
		}

		imagealphablending( $img, false );
		imagesavealpha( $img, true );

		$w = imagesx( $img );
		$h = imagesy( $img );

		for ( $x = 0; $x < $w; $x++ ) {
			for ( $y = 0; $y < $h; $y++ ) {
				$rgb = imagecolorat( $img, $x, $y );
				$r   = ( $rgb >> 16 ) & 0xFF;
				$g   = ( $rgb >> 8 ) & 0xFF;
				$b   = $rgb & 0xFF;

				if ( $r < 30 && $g < 30 && $b < 30 ) {
					imagesetpixel( $img, $x, $y, imagecolorallocatealpha( $img, 0, 0, 0, 127 ) );
				}
			}
		}

		imagepng( $img, $dest );
		imagedestroy( $img );
	}

	/**
	 * Mark setup wizard as completed/skipped so it is not shown again on reactivation.
	 */
	public function dismiss_setup_wizard(): void {
		update_option( 'gf_odoo_wizard_dismissed', true );
		delete_option( 'gf_odoo_show_wizard' );
	}

	/**
	 * About & License admin page.
	 */
	public function render_about(): void {
		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_die( esc_html__( 'Access denied.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$logo_url = $this->get_logo_url();
		$this->render_admin_page_open( __( 'About', 'gf-odoo-connector' ) );
		require GF_ODOO_PATH . 'admin/views/about.php';
		$this->render_admin_page_close();
	}

	/**
	 * First-run setup wizard (full-screen).
	 */
	public function render_setup_wizard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = max( 1, min( 3, (int) ( $_GET['step'] ?? 1 ) ) );

		if ( 3 === $step ) {
			$this->dismiss_setup_wizard();
		}

		$addon = $this;
		require GF_ODOO_PATH . 'admin/views/setup-wizard.php';
		exit;
	}

	/**
	 * AJAX: save connection settings from setup wizard step 2.
	 */
	public function ajax_wizard_save(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ) ),
				403
			);
		}

		$settings = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['odoo_url'] ) ) {
			$settings['odoo_url'] = esc_url_raw( wp_unslash( (string) $_POST['odoo_url'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['db_name'] ) ) {
			$settings['db_name'] = sanitize_text_field( wp_unslash( (string) $_POST['db_name'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['login_email'] ) ) {
			$settings['login_email'] = sanitize_email( wp_unslash( (string) $_POST['login_email'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['api_key'] ) ) {
			$this->store_api_key( sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) );
		}

		$this->update_plugin_settings( $settings );
		$this->dismiss_setup_wizard();

		wp_send_json_success(
			array(
				'message' => __( 'Settings saved.', 'gf-odoo-connector' ),
			)
		);
	}

	/**
	 * Render the sync dashboard.
	 */
	public function render_dashboard_page(): void {
		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		if ( ! class_exists( 'Dashboard' ) ) {
			wp_die( esc_html__( 'Dashboard is unavailable.', 'gf-odoo-connector' ) );
		}

		$settings     = $this->get_connection_settings();
		$status_info  = $this->resolve_connection_status();
		$last_success = get_option( 'gf_odoo_last_success_at', '' );

		$status_map = array(
			'success' => array( 'connected', __( 'Connected', 'gf-odoo-connector' ) ),
			'unknown' => array( 'unknown', __( 'Not configured', 'gf-odoo-connector' ) ),
			'error'   => array( 'error', __( 'Not reachable', 'gf-odoo-connector' ) ),
		);
		$mapped = $status_map[ $status_info['status'] ] ?? $status_map['error'];

		$connection = array(
			'odoo_url'     => (string) rgar( $settings, 'odoo_url' ),
			'status'       => $mapped[0],
			'status_label' => $mapped[1],
			'last_success' => is_string( $last_success ) ? $last_success : '',
		);

		$counts        = Dashboard::get_summary_counts();
		$errors        = Dashboard::get_recent_errors( 5 );
		$successes     = Dashboard::get_recent_successes( 5 );
		$addon         = $this;
		$error_log_url = $this->get_error_log_admin_url();

		$this->render_admin_page_open( __( 'Dashboard', 'gf-odoo-connector' ) );
		require GF_ODOO_PATH . 'admin/views/dashboard.php';
		$this->render_admin_page_close();
	}

	/**
	 * @param int $form_id Form ID.
	 *
	 * @return string
	 */
	public function get_form_title_for_dashboard( int $form_id ): string {
		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return '-';
		}

		$form = GFAPI::get_form( $form_id );
		if ( is_wp_error( $form ) || empty( $form ) ) {
			return '#' . $form_id;
		}

		$title = (string) rgar( $form, 'title' );

		return '' !== $title ? $title : '#' . $form_id;
	}

	/**
	 * @param string $module    crm|helpdesk.
	 * @param int    $lead_id   Lead ID.
	 * @param int    $ticket_id Ticket ID.
	 *
	 * @return array{url: string, id: int}|null
	 */
	public function get_dashboard_odoo_record_link( string $module, int $lead_id, int $ticket_id ): ?array {
		$settings = $this->get_connection_settings();
		$base     = untrailingslashit( (string) rgar( $settings, 'odoo_url' ) );

		if ( '' === $base ) {
			return null;
		}

		if ( $lead_id > 0 ) {
			return array(
				'url' => $base . '/odoo/crm/' . $lead_id,
				'id'  => $lead_id,
			);
		}

		if ( $ticket_id > 0 ) {
			return array(
				'url' => $base . '/odoo/helpdesk/' . $ticket_id,
				'id'  => $ticket_id,
			);
		}

		unset( $module );

		return null;
	}

	/**
	 * Localize dashboard chart script.
	 */
	public function localize_dashboard_scripts(): void {
		wp_localize_script(
			'gf_odoo_dashboard',
			'gfOdooDashboard',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'chartNonce'   => wp_create_nonce( 'gf_odoo_chart_data' ),
				'successLabel' => esc_html__( 'Success', 'gf-odoo-connector' ),
				'failedLabel'  => esc_html__( 'Failed', 'gf-odoo-connector' ),
			)
		);
	}

	/**
	 * AJAX: chart data for dashboard.
	 */
	public function ajax_chart_data(): void {
		check_ajax_referer( 'gf_odoo_chart_data', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() || ! class_exists( 'Dashboard' ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ),
				),
				403
			);
		}

		wp_send_json_success( Dashboard::get_chart_data( 14 ) );
	}

	/**
	 * Markup for GDPR full data export button (Notifications settings).
	 *
	 * @return string
	 */
	private function get_gdpr_export_markup(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p class="description">' . esc_html__(
				'Only site administrators can export plugin data.',
				'gf-odoo-connector'
			) . '</p>';
		}

		return sprintf(
			'<p><button type="button" class="button button-secondary" id="gf-odoo-export-all-data">%1$s</button></p>'
			. '<p class="description">%2$s</p>',
			esc_html__( 'Export all sync data (JSON)', 'gf-odoo-connector' ),
			esc_html__(
				'Downloads error log entries, feed templates, and Odoo-related entry meta as one JSON file.',
				'gf-odoo-connector'
			)
		);
	}

	/**
	 * AJAX: export all plugin data for GDPR compliance (JSON download).
	 */
	public function ajax_export_all_data(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$errors = class_exists( 'Error_Logger' )
			? Error_Logger::get_errors( array( 'limit' => 99999 ) )
			: array();

		$templates = class_exists( 'Template_Manager' )
			? Template_Manager::get_all()
			: array();

		$entry_meta = $this->get_odoo_entry_meta_for_export();

		$timestamp = gmdate( 'Y-m-d-H-i-s' );
		$payload   = array(
			'exported_at'  => gmdate( 'c' ),
			'plugin'       => 'gf-odoo-connector',
			'version'      => defined( 'GF_ODOO_VERSION' ) ? GF_ODOO_VERSION : '',
			'db_version'   => (string) get_option( 'gf_odoo_db_version', '' ),
			'error_count'  => count( $errors ),
			'errors'       => $errors,
			'templates'    => $templates,
			'entry_meta'   => $entry_meta,
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header(
			'Content-Disposition: attachment; filename="gf-odoo-export-' . $timestamp . '.json"'
		);

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * All Gravity Forms entry meta rows written by this plugin.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_odoo_entry_meta_for_export(): array {
		global $wpdb;

		if ( class_exists( 'GFFormsModel' ) ) {
			$table = GFFormsModel::get_entry_meta_table_name();
		} else {
			$table = $wpdb->prefix . 'gf_entry_meta';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT entry_id, form_id, meta_key, meta_value
			FROM {$table}
			WHERE meta_key LIKE 'odoo\\_%'
			ORDER BY entry_id ASC, meta_key ASC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Stream error log as CSV download.
	 */
	public function ajax_export_csv(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->current_user_can_export_csv() ) {
			wp_die( esc_html__( 'Forbidden', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$errors = Error_Logger::get_errors( array( 'limit' => 10000 ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header(
			'Content-Disposition: attachment; filename="gf-odoo-sync-log-' . gmdate( 'Y-m-d' ) . '.csv"'
		);

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		fputcsv(
			$output,
			array( 'ID', 'Date', 'Form ID', 'Entry ID', 'Module', 'Error code', 'Error message', 'Attempt', 'Resolved' )
		);

		foreach ( $errors as $row ) {
			fputcsv(
				$output,
				array(
					$row['id'] ?? '',
					$row['created_at'] ?? '',
					$row['form_id'] ?? '',
					$row['entry_id'] ?? '',
					$row['module'] ?? '',
					$row['error_code'] ?? '',
					$row['error_message'] ?? '',
					$row['attempt'] ?? 1,
					! empty( $row['resolved'] ) ? 'Yes' : 'No',
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * @param array $columns  Entry list columns.
	 * @param int   $form_id  Form ID.
	 *
	 * @return array
	 */
	public function add_entry_list_odoo_column( $columns, $form_id ) {
		unset( $form_id );
		$columns['odoo_sync'] = esc_html__( 'Odoo', 'gf-odoo-connector' );

		return $columns;
	}

	/**
	 * @param string $value        Column value.
	 * @param int    $form_id      Form ID.
	 * @param string $field_id     Column ID.
	 * @param array  $entry        Entry.
	 * @param string $query_string Query string.
	 *
	 * @return string
	 */
	public function render_entry_list_odoo_column( $value, $form_id, $field_id, $entry, $query_string ) {
		unset( $form_id, $query_string );

		if ( 'odoo_sync' !== $field_id ) {
			return $value;
		}

		$entry_id = (int) rgar( $entry, 'id' );
		$status   = (string) gform_get_meta( $entry_id, 'odoo_sync_status' );
		$lead_id  = (int) gform_get_meta( $entry_id, 'odoo_lead_id' );
		$ticket_id = (int) gform_get_meta( $entry_id, 'odoo_ticket_id' );
		$odoo_url = untrailingslashit( (string) rgar( $this->get_connection_settings(), 'odoo_url' ) );

		if ( '' === $status ) {
			return '<span style="color:#aaa">-</span>';
		}

		$icons = array(
			'success'  => '<span style="color:green" title="' . esc_attr__( 'Synced', 'gf-odoo-connector' ) . '">●</span>',
			'retrying' => '<span style="color:orange" title="' . esc_attr__( 'Retrying', 'gf-odoo-connector' ) . '">↺</span>',
			'pending'  => '<span style="color:#aaa" title="' . esc_attr__( 'Pending', 'gf-odoo-connector' ) . '">◌</span>',
			'failed'   => '<span style="color:red" title="' . esc_attr__( 'Failed', 'gf-odoo-connector' ) . '">✕</span>',
		);

		$icon = $icons[ $status ] ?? '';

		if ( 'success' === $status ) {
			$record_id = $lead_id > 0 ? $lead_id : $ticket_id;
			$path      = $lead_id > 0 ? 'odoo/crm' : 'odoo/helpdesk';

			if ( $record_id > 0 && '' !== $odoo_url ) {
				$link = sprintf(
					'<a href="%1$s/%2$s/%3$d" target="_blank" rel="noopener noreferrer" title="%4$s">#%3$d</a>',
					esc_url( $odoo_url ),
					esc_attr( $path ),
					$record_id,
					esc_attr__( 'View in Odoo', 'gf-odoo-connector' )
				);

				return $icon . ' ' . $link;
			}
		}

		return $icon . ' ' . esc_html( ucfirst( $status ) );
	}

	/**
	 * Admin notice when unresolved sync errors exist (Gravity Forms screens only).
	 */
	public function maybe_render_sync_failure_notice(): void {
		if ( ! current_user_can( 'gravityforms_edit_forms' ) ) {
			return;
		}

		if ( ! $this->is_gravity_forms_admin_screen() ) {
			return;
		}

		$count = Error_Logger::get_unresolved_count();

		if ( $count <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: 1: number of failures, 2: error log URL */
					_n(
						'GF Odoo Connector: %1$d submission failed to sync. <a href="%2$s">View error log</a> →',
						'GF Odoo Connector: %1$d submissions failed to sync. <a href="%2$s">View error log</a> →',
						$count,
						'gf-odoo-connector'
					),
					number_format_i18n( $count ),
					esc_url( $this->get_error_log_admin_url() )
				),
				array(
					'a' => array(
						'href' => array(),
					),
				)
			)
		);
	}

	/**
	 * @return bool
	 */
	private function is_gravity_forms_admin_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( '' !== $page && 0 === strpos( $page, 'gf_' ) ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && ! empty( $screen->parent_base ) && 'forms' === $screen->parent_base ) {
			return true;
		}

		return false;
	}

	/**
	 * AJAX: retry a failed sync using the stored payload.
	 */
	public function ajax_retry_feed(): void {
		check_ajax_referer( 'gf_odoo_retry', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ),
				),
				403
			);
		}

		$error_id = isset( $_POST['error_id'] ) ? absint( $_POST['error_id'] ) : 0;

		if ( $error_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Invalid error ID.', 'gf-odoo-connector' ),
				)
			);
		}

		$result = $this->retry_feed( $error_id );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success();
		}

		wp_send_json_error(
			array(
				'message' => isset( $result['message'] ) ? $result['message'] : esc_html__( 'Retry failed.', 'gf-odoo-connector' ),
			)
		);
	}

	/**
	 * AJAX: mark an error row as resolved.
	 */
	public function ajax_mark_error_resolved(): void {
		check_ajax_referer( 'gf_odoo_mark_resolved', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Forbidden', 'gf-odoo-connector' ),
				),
				403
			);
		}

		$error_id = isset( $_POST['error_id'] ) ? absint( $_POST['error_id'] ) : 0;

		if ( $error_id <= 0 || ! Error_Logger::mark_resolved( $error_id ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Could not mark the error as resolved.', 'gf-odoo-connector' ),
				)
			);
		}

		wp_send_json_success();
	}

	/**
	 * Re-attempt an Odoo sync from a stored error payload.
	 *
	 * @param int $error_id Error log row ID.
	 *
	 * @return array{success: bool, message?: string}
	 */
	public function retry_feed( int $error_id ): array {
		$row = Error_Logger::get_error( $error_id );

		if ( null === $row || ! empty( $row['resolved'] ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Error not found or already resolved.', 'gf-odoo-connector' ),
			);
		}

		$payload = Error_Logger::decode_payload( isset( $row['payload'] ) ? (string) $row['payload'] : null );

		if ( empty( $payload ) ) {
			$legacy = $this->rebuild_retry_payload_from_entry( $row );

			if ( empty( $legacy ) ) {
				return array(
					'success' => false,
					'message' => esc_html__(
						'No sync data is stored for this error and the entry could not be re-mapped. Submit the form again after fixing settings.',
						'gf-odoo-connector'
					),
				);
			}

			$payload = array(
				'feed_id'      => (int) $row['feed_id'],
				'entry_id'     => (int) $row['entry_id'],
				'form_id'      => (int) $row['form_id'],
				'module'       => (string) $row['module'],
				'attempt'      => 1,
				'sync_payload' => $legacy,
				'form_title'   => '',
			);
		}

		Error_Logger::touch_retried_at( $error_id );

		$job = $this->normalize_job_payload( is_array( $payload ) ? $payload : array() );

		if ( (int) $job['entry_id'] <= 0 ) {
			$job['entry_id'] = (int) $row['entry_id'];
		}

		if ( (int) $job['form_id'] <= 0 ) {
			$job['form_id'] = (int) $row['form_id'];
		}

		if ( (int) $job['feed_id'] <= 0 ) {
			$job['feed_id'] = (int) $row['feed_id'];
		}

		if ( '' === (string) $job['module'] ) {
			$job['module'] = (string) $row['module'];
		}

		$job['attempt']   = 1;
		$job['is_manual'] = true;

		GF_Odoo_Async_Sync::cancel_pending_jobs( (int) $job['entry_id'], (int) $job['feed_id'] );

		gform_update_meta( (int) $job['entry_id'], 'odoo_sync_status', 'pending', (int) $job['form_id'] );

		$success = $this->process_sync_job( $job );

		if ( $success ) {
			Error_Logger::mark_resolved( $error_id );

			return array( 'success' => true );
		}

		$message = $this->get_last_sync_job_error();

		if ( '' === $message ) {
			$latest = Error_Logger::get_errors(
				array(
					'entry_id' => (int) $job['entry_id'],
					'feed_id'  => (int) $job['feed_id'],
					'limit'    => 1,
				)
			);

			$message = ! empty( $latest[0]['error_message'] )
				? (string) $latest[0]['error_message']
				: esc_html__( 'Odoo sync failed.', 'gf-odoo-connector' );
		}

		return array(
			'success' => false,
			'message' => $message,
		);
	}

	/**
	 * WP_DEBUG-only debug tools for Connection settings.
	 *
	 * @return string
	 */
	private function get_country_debug_tools_markup(): string {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return '';
		}

		ob_start();
		?>
		<div class="gf-odoo-card" style="margin-top:16px">
			<div class="gf-odoo-card-header">
				<h3><?php esc_html_e( 'Debug tools', 'gf-odoo-connector' ); ?></h3>
				<p><?php esc_html_e( 'Only visible when WP_DEBUG is true.', 'gf-odoo-connector' ); ?></p>
			</div>
			<div class="gf-odoo-card-body">
				<div class="gf-odoo-field">
					<label class="gf-odoo-label" for="gf-odoo-debug-country"><?php esc_html_e( 'Test country resolver', 'gf-odoo-connector' ); ?></label>
					<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
						<input type="text" id="gf-odoo-debug-country" class="gf-odoo-input" style="max-width:200px" placeholder="Netherlands" />
						<button type="button" class="gf-odoo-btn gf-odoo-btn-secondary" id="gf-odoo-debug-country-btn"><?php esc_html_e( 'Test resolver', 'gf-odoo-connector' ); ?></button>
						<span id="gf-odoo-debug-country-result" style="font-size:13px;color:var(--gf-odoo-text-muted, #646970)"></span>
					</div>
				</div>
				<div class="gf-odoo-field" style="margin-top:12px">
					<label class="gf-odoo-label" for="gf-odoo-debug-industry"><?php esc_html_e( 'Test industry resolver', 'gf-odoo-connector' ); ?></label>
					<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
						<input type="text" id="gf-odoo-debug-industry" class="gf-odoo-input" style="max-width:200px" placeholder="Medical" />
						<button type="button" class="gf-odoo-btn gf-odoo-btn-secondary" id="gf-odoo-debug-industry-btn"><?php esc_html_e( 'Test resolver', 'gf-odoo-connector' ); ?></button>
						<span id="gf-odoo-debug-industry-result" style="font-size:13px;color:var(--gf-odoo-text-muted, #646970)"></span>
					</div>
				</div>
				<div class="gf-odoo-field" style="margin-top:12px">
					<label class="gf-odoo-label" for="gf-odoo-debug-model"><?php esc_html_e( 'Fetch Odoo model fields', 'gf-odoo-connector' ); ?></label>
					<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
						<input type="text" id="gf-odoo-debug-model" class="gf-odoo-input" style="max-width:200px" placeholder="helpdesk.ticket" value="res.partner" />
						<button type="button" class="gf-odoo-btn gf-odoo-btn-secondary" id="gf-odoo-debug-model-btn"><?php esc_html_e( 'Fetch fields_get', 'gf-odoo-connector' ); ?></button>
						<span id="gf-odoo-debug-model-status" style="font-size:13px;color:var(--gf-odoo-text-muted, #646970)"></span>
					</div>
					<pre id="gf-odoo-debug-model-result" style="margin-top:8px;font-size:11px;max-height:300px;overflow-y:auto;background:#f5f5f5;padding:8px;border-radius:4px;display:none"></pre>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function maybe_note_unresolved_country( Field_Mapper $mapper, string $meta_key, array $data, array $entry ): void {
		if ( ! $mapper->has_unresolved_country_mapping( $meta_key, $data ) ) {
			return;
		}

		$entry_id = (int) rgar( $entry, 'id' );
		if ( $entry_id <= 0 ) {
			return;
		}

		$country_raw = $mapper->get_raw_field_value( $meta_key );

		$this->add_note(
			$entry_id,
			sprintf(
				/* translators: %s: country name or code from the form submission */
				__( 'Warning: Country "%s" could not be matched to an Odoo country ID. The record was synced without a country.', 'gf-odoo-connector' ),
				$country_raw
			),
			'note'
		);
	}

	/**
	 * @param array $feed  Feed object.
	 * @param array $entry Entry object.
	 * @param array $form  Form object.
	 *
	 * @return array{contact: array, lead: array, feed_meta: array, form_title: string}
	 */
	/**
	 * Apply the global salesperson/sales team defaults to a feed.
	 *
	 * Normally a feed value of 0/empty means "use global default", so the
	 * site-wide assignment is only inherited when the feed has not set its own.
	 * When "Force on all forms" is enabled, the global values override any
	 * per-feed assignment.
	 *
	 * @param array $meta Feed meta (modified in place).
	 */
	private function apply_default_crm_assignment( array &$meta ): void {
		$settings = $this->get_connection_settings();
		$force    = '1' === (string) rgar( $settings, 'force_crm_assignment' );

		$default_user = (int) rgar( $settings, 'default_crm_user_id' );
		$default_team = (int) rgar( $settings, 'default_crm_team_id' );

		if ( $default_user > 0 && ( $force || (int) rgar( $meta, 'crm_user_id' ) <= 0 ) ) {
			$meta['crm_user_id'] = (string) $default_user;
		}

		if ( $default_team > 0 && ( $force || (int) rgar( $meta, 'crm_team_id' ) <= 0 ) ) {
			$meta['crm_team_id'] = (string) $default_team;
		}
	}

	private function build_crm_sync_payload( $feed, $entry, $form ): array {
		$this->apply_resolved_feed_meta( $feed, (int) rgar( $form, 'id' ) );

		$meta = (array) rgar( $feed, 'meta', array() );
		$this->migrate_feed_meta( $meta );
		$this->apply_default_crm_assignment( $meta );
		$feed['meta'] = $meta;

		$mapper = new Field_Mapper( $meta, $entry, $form );
		$mapped = $mapper->map_crm_fields();

		$contact_data = ! empty( $mapped['contact'] ) ? $mapped['contact'] : $mapped['partner'];
		$lead_data    = $mapped['lead'];

		$this->maybe_note_unresolved_country( $mapper, 'contact_country', $contact_data, $entry );

		if ( empty( $contact_data['name'] ) ) {
			throw new InvalidArgumentException(
				__( 'No contact name was mapped. Open the feed settings, map "Contact name" to your name field, and save the feed.', 'gf-odoo-connector' )
			);
		}

		if ( empty( $lead_data['name'] ) ) {
			$lead_data['name'] = (string) rgar( $form, 'title' );
		}

		return array(
			'contact'    => $contact_data,
			'lead'       => $lead_data,
			'feed_meta'  => rgar( $feed, 'meta', array() ),
			'form_title' => (string) rgar( $form, 'title' ),
		);
	}

	/**
	 * Build Helpdesk payload for sync / error logging (before calling Odoo).
	 *
	 * @param array $feed  Feed object.
	 * @param array $entry Entry object.
	 * @param array $form  Form object.
	 *
	 * @return array{ticket: array}
	 */
	private function build_helpdesk_sync_payload( $feed, $entry, $form ): array {
		$this->apply_resolved_feed_meta( $feed, (int) rgar( $form, 'id' ) );

		$meta = (array) rgar( $feed, 'meta', array() );
		$this->migrate_helpdesk_feed_meta( $meta );

		$mapper = new Field_Mapper( $meta, $entry, $form );
		$mapped = $mapper->map_helpdesk_fields();

		$ticket  = (array) $mapped['ticket'];
		$contact = (array) $mapped['contact'];
		$product = (array) $mapped['product'];

		$this->maybe_note_unresolved_country(
			$mapper,
			'ticket_country',
			array_merge( $ticket, $contact ),
			$entry
		);

		foreach ( $contact as $field => $value ) {
			if ( '' !== (string) $value ) {
				$ticket[ $field ] = $value;
			}
		}

		foreach ( $product as $field => $value ) {
			$ticket[ $field ] = $value;
		}

		if ( empty( $ticket['name'] ) ) {
			throw new InvalidArgumentException(
				__( 'No ticket subject was configured. Set Ticket subject to Auto, From field, or Fixed.', 'gf-odoo-connector' )
			);
		}

		return array(
			'ticket'  => $ticket,
			'contact' => $contact,
		);
	}

	/**
	 * Resolved Smart routing configuration from plugin settings.
	 *
	 * @return array
	 */
	private function get_smart_routing_config(): array {
		$settings = $this->get_connection_settings();

		$value = function ( $key, $default = '' ) use ( $settings ) {
			$raw = rgar( $settings, $key );

			if ( is_array( $raw ) ) {
				$raw = rgar( $raw, $key );
			}

			return ( '' === $raw || null === $raw ) ? $default : $raw;
		};

		$checkbox = function ( $key ) use ( $settings ) {
			$raw = rgar( $settings, $key );

			if ( is_array( $raw ) ) {
				$raw = rgar( $raw, $key );
			}

			return ! empty( $raw );
		};

		$engine   = (string) $value( 'smart_routing_engine', 'hybrid' );
		$provider = (string) $value( 'smart_routing_ai_provider', 'mistral' );
		$base_url = (string) $value( 'smart_routing_ai_base_url', '' );
		$model    = (string) $value( 'smart_routing_ai_model', 'mistral-small-latest' );

		if ( '' === trim( $model ) ) {
			$model = 'mistral-small-latest';
		}

		$endpoint = 'custom' === $provider
			? $this->normalize_ai_endpoint( $base_url )
			: 'https://api.mistral.ai/v1/chat/completions';

		return array(
			'enabled'              => $checkbox( 'smart_routing_enabled' ),
			'mode'                 => (string) $value( 'smart_routing_mode', 'log' ),
			'engine'               => $engine,
			'spam_keywords'        => GF_Odoo_Lead_Classifier::lines_to_list( (string) $value( 'smart_routing_spam_keywords', implode( "\n", GF_Odoo_Lead_Classifier::default_spam_keywords() ) ) ),
			'sales_keywords'       => GF_Odoo_Lead_Classifier::lines_to_list( (string) $value( 'smart_routing_sales_keywords', implode( "\n", GF_Odoo_Lead_Classifier::default_sales_keywords() ) ) ),
			'support_keywords'     => GF_Odoo_Lead_Classifier::lines_to_list( (string) $value( 'smart_routing_support_keywords', implode( "\n", GF_Odoo_Lead_Classifier::default_support_keywords() ) ) ),
			'blocked_domains'      => GF_Odoo_Lead_Classifier::lines_to_list( (string) $value( 'smart_routing_blocked_domains', implode( "\n", GF_Odoo_Lead_Classifier::default_blocked_domains() ) ) ),
			'max_links'            => (int) $value( 'smart_routing_max_links', 3 ),
			'spam_threshold'       => max( 1, (int) $value( 'smart_routing_spam_threshold', 2 ) ),
			'confidence_threshold' => max( 1, (int) $value( 'smart_routing_confidence_threshold', 2 ) ),
			'helpdesk_team_id'     => (int) $value( 'smart_routing_helpdesk_team_id', 0 ),
			'helpdesk_desc_field'  => (string) $value( 'smart_routing_helpdesk_desc_field', 'description' ),
			'review_tag'           => (string) $value( 'smart_routing_review_tag', 'Needs review' ),
			'web_lead_tag'         => (string) $value( 'smart_routing_web_lead_tag', '' ),
			'ai_enabled'           => 'hybrid' === $engine,
			'ai_provider'          => $provider,
			'ai_endpoint'          => $endpoint,
			'ai_model'             => $model,
			'ai_key'               => $this->get_ai_key(),
			'ai_timeout'           => 10,
		);
	}

	/**
	 * Build a chat-completions endpoint from a custom base URL.
	 *
	 * @param string $base_url Configured base URL.
	 *
	 * @return string
	 */
	private function normalize_ai_endpoint( string $base_url ): string {
		$base_url = trim( $base_url );

		if ( '' === $base_url ) {
			return '';
		}

		if ( false !== strpos( $base_url, 'chat/completions' ) ) {
			return $base_url;
		}

		return rtrim( $base_url, '/' ) . '/v1/chat/completions';
	}

	/**
	 * Whether smart routing should act on this feed at submission time.
	 *
	 * @param array $feed Feed object.
	 * @param array $config Resolved config.
	 *
	 * @return bool
	 */
	private function smart_routing_applies( array $feed, array $config ): bool {
		if ( empty( $config['enabled'] ) || 'off' === $config['mode'] ) {
			return false;
		}

		return ! empty( rgars( $feed, 'meta/smart_routing_enabled' ) );
	}

	/**
	 * Run the keyword pre-pass at submission time and decide what to queue.
	 *
	 * @param array $feed  Feed object.
	 * @param array $entry Entry object.
	 * @param array $form  Form object.
	 *
	 * @return array|null Null to continue normally; otherwise skip/job instructions.
	 */
	private function maybe_smart_route( array $feed, array $entry, array $form ): ?array {
		$config = $this->get_smart_routing_config();

		if ( ! $this->smart_routing_applies( $feed, $config ) ) {
			return null;
		}

		$entry_id = (int) rgar( $entry, 'id' );
		$result   = GF_Odoo_Lead_Classifier::classify( $entry, $form, $config );

		// Log only: record the would-be decision, change nothing.
		if ( 'enforce' !== $config['mode'] ) {
			if ( $entry_id > 0 ) {
				$this->add_note(
					$entry_id,
					$this->format_smart_routing_note( $result['bucket'], 'keyword', true, '', $result ),
					'note'
				);
			}

			return null;
		}

		// Enforce, confident keyword decision: act now.
		if ( ! empty( $result['confident'] ) ) {
			return $this->build_smart_action( $result['bucket'], $feed, $entry, $form, $config, 'keyword', '', $result );
		}

		// Uncertain + Hybrid + AI configured: defer the decision (and the AI call) to the worker.
		if ( 'hybrid' === $config['engine'] && GF_Odoo_AI_Classifier::is_configured( $config ) ) {
			if ( $entry_id > 0 ) {
				$this->add_note(
					$entry_id,
					esc_html__( 'Smart routing: uncertain by keywords, deferring to AI review.', 'gf-odoo-connector' ),
					'note'
				);
			}

			return array( 'job' => $this->build_smart_deferred_job( $feed, $entry, $form, $result ) );
		}

		// Uncertain, keyword-only: never lose a lead, route to CRM flagged for review.
		return $this->build_smart_action( GF_Odoo_Lead_Classifier::UNSURE, $feed, $entry, $form, $config, 'keyword', '', $result );
	}

	/**
	 * Turn a decided bucket into a skip instruction or a full sync job.
	 *
	 * @param string $bucket Decided bucket.
	 * @param array  $feed   Feed object.
	 * @param array  $entry  Entry object.
	 * @param array  $form   Form object.
	 * @param array  $config Config.
	 * @param string $engine 'keyword' or 'AI'.
	 * @param string $reason AI reason (optional).
	 * @param array  $kw     Keyword classification result (for the note).
	 *
	 * @return array
	 */
	private function build_smart_action( string $bucket, array $feed, array $entry, array $form, array $config, string $engine, string $reason, array $kw ): array {
		$entry_id = (int) rgar( $entry, 'id' );
		$target   = $this->resolve_smart_target( $bucket, $config );

		if ( 'skip' === $target['action'] ) {
			return array(
				'skip' => true,
				'note' => $this->format_smart_routing_note( 'spam', $engine, false, $reason, $kw ),
			);
		}

		$built = $this->build_smart_job_payload( $target['module'], $feed, $entry, $form, $target['tags'], $target['team_id'], (string) rgar( $config, 'helpdesk_desc_field', 'description' ) );

		$job = array(
			'feed_id'      => (int) rgar( $feed, 'id' ),
			'entry_id'     => $entry_id,
			'form_id'      => (int) rgar( $form, 'id' ),
			'module'       => $built['module'],
			'attempt'      => 1,
			'sync_payload' => $built['sync_payload'],
			'form_title'   => (string) rgar( $form, 'title' ),
		);

		return array(
			'job'    => $job,
			'module' => $built['module'],
			'note'   => $this->format_smart_routing_note( $target['effective_bucket'], $engine, false, $reason, $kw ),
		);
	}

	/**
	 * Map a bucket to a routing target (module, tags, team) or a skip.
	 *
	 * @param string $bucket Decided bucket.
	 * @param array  $config Config.
	 *
	 * @return array{action:string,module:string,tags:array,team_id:int,effective_bucket:string}
	 */
	private function resolve_smart_target( string $bucket, array $config ): array {
		$web_lead = trim( (string) $config['web_lead_tag'] );
		$review   = trim( (string) $config['review_tag'] );
		$team_id  = (int) $config['helpdesk_team_id'];

		$base_tags = array();
		if ( '' !== $web_lead ) {
			$base_tags[] = $web_lead;
		}

		if ( GF_Odoo_Lead_Classifier::SPAM === $bucket ) {
			return array(
				'action'           => 'skip',
				'module'           => '',
				'tags'             => array(),
				'team_id'          => 0,
				'effective_bucket' => 'spam',
			);
		}

		if ( GF_Odoo_Lead_Classifier::SUPPORT === $bucket ) {
			if ( $team_id > 0 ) {
				return array(
					'action'           => 'route',
					'module'           => 'helpdesk',
					'tags'             => array(),
					'team_id'          => $team_id,
					'effective_bucket' => 'support',
				);
			}

			// No default team: keep it as a CRM lead flagged for review.
			$tags = $base_tags;
			if ( '' !== $review ) {
				$tags[] = $review;
			}

			return array(
				'action'           => 'route',
				'module'           => 'crm',
				'tags'             => $tags,
				'team_id'          => 0,
				'effective_bucket' => 'support-review',
			);
		}

		if ( GF_Odoo_Lead_Classifier::UNSURE === $bucket ) {
			$tags = $base_tags;
			if ( '' !== $review ) {
				$tags[] = $review;
			}

			return array(
				'action'           => 'route',
				'module'           => 'crm',
				'tags'             => $tags,
				'team_id'          => 0,
				'effective_bucket' => 'unsure',
			);
		}

		// Sales (default).
		return array(
			'action'           => 'route',
			'module'           => 'crm',
			'tags'             => $base_tags,
			'team_id'          => 0,
			'effective_bucket' => 'sales',
		);
	}

	/**
	 * Build the module-specific sync payload for a smart-routed entry.
	 *
	 * Reuses the native payload for the feed's own module, and synthesises the
	 * other module's payload from the mapped basics when routing across modules.
	 *
	 * @param string $target_module crm|helpdesk.
	 * @param array  $feed          Feed object.
	 * @param array  $entry         Entry object.
	 * @param array  $form          Form object.
	 * @param array  $tags          CRM tag names to apply (lead only).
	 * @param int    $team_id       Helpdesk team ID (helpdesk only).
	 *
	 * @return array{module:string,sync_payload:array}
	 *
	 * @throws Exception When mapping fails.
	 */
	private function build_smart_job_payload( string $target_module, array $feed, array $entry, array $form, array $tags, int $team_id, string $issue_field = 'description' ): array {
		$feed_module = $this->get_feed_module( $feed );

		$native = 'helpdesk' === $feed_module
			? $this->build_helpdesk_sync_payload( $feed, $entry, $form )
			: $this->build_crm_sync_payload( $feed, $entry, $form );

		$basics = $this->smart_extract_basics( $feed_module, $native, $form );

		// The visitor's actual message, used as a body fallback when the feed
		// mapping does not populate a description (common on contact forms).
		$basics['message'] = $this->smart_get_message_text( $entry, $form );

		if ( 'helpdesk' === $target_module ) {
			$payload = 'helpdesk' === $feed_module
				? $native
				: $this->synth_helpdesk_payload_from_basics( $basics, $team_id, $issue_field );

			// Native helpdesk feeds map the body to "description"; honour the
			// configured ticket body field so it lands in the right tab.
			if ( 'helpdesk' === $feed_module && 'description' !== $issue_field
				&& isset( $payload['ticket']['description'] ) && '' !== (string) $payload['ticket']['description'] ) {
				$payload['ticket'][ $issue_field ] = $this->smart_text_to_html( (string) $payload['ticket']['description'] );
				unset( $payload['ticket']['description'] );
			}

			if ( $team_id > 0 ) {
				$payload['ticket']['team_id'] = $team_id;
			}

			return array(
				'module'       => 'helpdesk',
				'sync_payload' => $payload,
			);
		}

		$payload = 'crm' === $feed_module
			? $native
			: $this->synth_crm_payload_from_basics( $basics, $feed, $form );

		if ( ! empty( $tags ) ) {
			if ( ! isset( $payload['lead'] ) || ! is_array( $payload['lead'] ) ) {
				$payload['lead'] = array();
			}
			$payload['lead']['smart_tag_names'] = array_values( $tags );
		}

		return array(
			'module'       => 'crm',
			'sync_payload' => $payload,
		);
	}

	/**
	 * Extract contact/message basics from a built sync payload.
	 *
	 * @param string $feed_module crm|helpdesk.
	 * @param array  $payload     Native sync payload.
	 * @param array  $form        Form object.
	 *
	 * @return array{name:string,email:string,phone:string,company:string,subject:string,description:string}
	 */
	private function smart_extract_basics( string $feed_module, array $payload, array $form ): array {
		if ( 'helpdesk' === $feed_module ) {
			$ticket  = (array) rgar( $payload, 'ticket', array() );
			$contact = (array) rgar( $payload, 'contact', array() );

			return array(
				'name'        => (string) ( $ticket['partner_name'] ?? $contact['name'] ?? '' ),
				'email'       => (string) ( $ticket['partner_email'] ?? $contact['email'] ?? '' ),
				'phone'       => (string) ( $ticket['partner_phone'] ?? $contact['phone'] ?? '' ),
				'company'     => (string) ( $contact['company_name'] ?? '' ),
				'subject'     => (string) ( $ticket['name'] ?? '' ),
				'description' => (string) ( $ticket['description'] ?? '' ),
			);
		}

		$contact = ! empty( $payload['contact'] ) ? (array) $payload['contact'] : (array) rgar( $payload, 'partner', array() );
		$lead    = (array) rgar( $payload, 'lead', array() );

		return array(
			'name'        => (string) ( $contact['name'] ?? '' ),
			'email'       => (string) ( $contact['email'] ?? '' ),
			'phone'       => (string) ( $contact['phone'] ?? $contact['mobile'] ?? '' ),
			'company'     => (string) ( $contact['company_name'] ?? '' ),
			'subject'     => (string) ( $lead['name'] ?? '' ),
			'description' => (string) ( $lead['description'] ?? $contact['comment'] ?? '' ),
		);
	}

	/**
	 * Best-effort message body from an entry: the longest field value, which on
	 * a contact form is almost always the message/comment textarea.
	 *
	 * @param array $entry Entry object.
	 * @param array $form  Form object.
	 *
	 * @return string
	 */
	/**
	 * Wrap a plain-text message in light HTML so line breaks survive in Odoo
	 * HTML fields (custom "Issue Description" fields are usually HTML).
	 *
	 * @param string $text Plain text body.
	 *
	 * @return string
	 */
	private function smart_text_to_html( string $text ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		// Already HTML? Leave it as-is.
		if ( $text !== wp_strip_all_tags( $text ) ) {
			return $text;
		}

		return wpautop( esc_html( $text ) );
	}

	private function smart_get_message_text( array $entry, array $form ): string {
		$best     = '';
		$best_len = 0;

		foreach ( $entry as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			if ( ! preg_match( '/^\d+(\.\d+)?$/', (string) $key ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' === $value || is_email( $value ) ) {
				continue;
			}

			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

			if ( $len > $best_len ) {
				$best     = $value;
				$best_len = $len;
			}
		}

		return $best;
	}

	/**
	 * Synthesise a CRM payload from basics (when routing a non-CRM feed to CRM).
	 *
	 * @param array $basics Extracted basics.
	 * @param array $feed   Feed object.
	 * @param array $form   Form object.
	 *
	 * @return array
	 */
	private function synth_crm_payload_from_basics( array $basics, array $feed, array $form ): array {
		$name = trim( $basics['name'] );

		if ( '' === $name ) {
			$name = '' !== $basics['email']
				? (string) strstr( $basics['email'], '@', true )
				: esc_html__( 'Website visitor', 'gf-odoo-connector' );
		}

		$contact = array( 'name' => $name );

		if ( '' !== $basics['email'] ) {
			$contact['email'] = $basics['email'];
		}
		if ( '' !== $basics['phone'] ) {
			$contact['phone'] = $basics['phone'];
		}
		if ( '' !== $basics['company'] ) {
			$contact['company_name'] = $basics['company'];
		}

		$lead = array(
			'name' => '' !== trim( $basics['subject'] ) ? trim( $basics['subject'] ) : (string) rgar( $form, 'title' ),
		);

		$description = '' !== trim( (string) $basics['description'] )
			? (string) $basics['description']
			: (string) ( $basics['message'] ?? '' );

		if ( '' !== trim( $description ) ) {
			$lead['description'] = $description;
		}

		$meta = (array) rgar( $feed, 'meta', array() );
		$this->migrate_feed_meta( $meta );
		$this->apply_default_crm_assignment( $meta );

		return array(
			'contact'    => $contact,
			'lead'       => $lead,
			'feed_meta'  => $meta,
			'form_title' => (string) rgar( $form, 'title' ),
		);
	}

	/**
	 * Synthesise a Helpdesk payload from basics (when routing a non-Helpdesk feed to Helpdesk).
	 *
	 * @param array $basics  Extracted basics.
	 * @param int   $team_id Helpdesk team ID.
	 *
	 * @return array
	 */
	private function synth_helpdesk_payload_from_basics( array $basics, int $team_id, string $issue_field = 'description' ): array {
		$subject = trim( $basics['subject'] );

		if ( '' === $subject ) {
			$who     = '' !== $basics['name'] ? $basics['name'] : ( '' !== $basics['email'] ? $basics['email'] : esc_html__( 'website', 'gf-odoo-connector' ) );
			$subject = sprintf(
				/* translators: %s: sender name or email */
				esc_html__( 'Support request from %s', 'gf-odoo-connector' ),
				$who
			);
		}

		$ticket = array( 'name' => $subject );

		$description = '' !== trim( (string) $basics['description'] )
			? (string) $basics['description']
			: (string) ( $basics['message'] ?? '' );

		if ( '' !== trim( $description ) ) {
			$field = '' !== trim( $issue_field ) ? trim( $issue_field ) : 'description';

			if ( 'description' === $field ) {
				$ticket['description'] = $description;
			} else {
				// Custom HTML fields keep their formatting only when wrapped.
				$ticket[ $field ] = $this->smart_text_to_html( $description );
			}
		}
		if ( '' !== $basics['name'] ) {
			$ticket['partner_name'] = $basics['name'];
		}
		if ( '' !== $basics['email'] ) {
			$ticket['partner_email'] = $basics['email'];
		}
		if ( '' !== $basics['phone'] ) {
			$ticket['partner_phone'] = $basics['phone'];
		}
		if ( $team_id > 0 ) {
			$ticket['team_id'] = $team_id;
		}

		return array( 'ticket' => $ticket );
	}

	/**
	 * Build a deferred "smart" job that the worker resolves with AI.
	 *
	 * @param array $feed   Feed object.
	 * @param array $entry  Entry object.
	 * @param array $form   Form object.
	 * @param array $result Keyword classification result.
	 *
	 * @return array
	 */
	private function build_smart_deferred_job( array $feed, array $entry, array $form, array $result ): array {
		return array(
			'feed_id'       => (int) rgar( $feed, 'id' ),
			'entry_id'      => (int) rgar( $entry, 'id' ),
			'form_id'       => (int) rgar( $form, 'id' ),
			'form_title'    => (string) rgar( $form, 'title' ),
			'attempt'       => 1,
			'smart_routing' => true,
			'ai_text'       => (string) ( $result['text'] ?? '' ),
			'ai_domain'     => (string) ( $result['domain'] ?? '' ),
			'kw_bucket'     => (string) ( $result['bucket'] ?? GF_Odoo_Lead_Classifier::UNSURE ),
			'kw_matched'    => (array) ( $result['matched'] ?? array() ),
			'kw_scores'     => (array) ( $result['scores'] ?? array() ),
		);
	}

	/**
	 * Resolve a deferred "smart" job in the worker: run AI, decide, build payload.
	 *
	 * @param array $payload Deferred job payload.
	 *
	 * @return array|string Full sync job, or the string 'skip', or null on fatal error.
	 */
	private function resolve_smart_routing_job( array $payload ) {
		$entry_id = (int) rgar( $payload, 'entry_id', 0 );
		$form_id  = (int) rgar( $payload, 'form_id', 0 );
		$feed_id  = (int) rgar( $payload, 'feed_id', 0 );

		if ( $entry_id <= 0 || $form_id <= 0 || $feed_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return null;
		}

		$feed  = GFAPI::get_feed( $feed_id );
		$entry = GFAPI::get_entry( $entry_id );
		$form  = GFAPI::get_form( $form_id );

		if ( is_wp_error( $feed ) || empty( $feed ) || is_wp_error( $entry ) || empty( $entry ) || is_wp_error( $form ) || empty( $form ) ) {
			return null;
		}

		$config    = $this->get_smart_routing_config();
		$kw        = array(
			'bucket'  => (string) rgar( $payload, 'kw_bucket', GF_Odoo_Lead_Classifier::UNSURE ),
			'matched' => (array) rgar( $payload, 'kw_matched', array() ),
			'scores'  => (array) rgar( $payload, 'kw_scores', array() ),
		);
		$kw_bucket = $kw['bucket'];

		$engine = 'keyword';
		$reason = '';
		$bucket = $kw_bucket;

		if ( 'hybrid' === $config['engine'] && GF_Odoo_AI_Classifier::is_configured( $config ) ) {
			$ai = GF_Odoo_AI_Classifier::classify(
				(string) rgar( $payload, 'ai_text', '' ),
				array( 'domain' => (string) rgar( $payload, 'ai_domain', '' ) ),
				$config
			);

			if ( is_array( $ai ) ) {
				$engine     = 'AI';
				$reason     = (string) $ai['reason'];
				$ai_bucket  = $this->map_ai_category_to_bucket( (string) $ai['category'] );
				$bucket     = $ai_bucket;
			}
		}

		$target = $this->resolve_smart_target( $bucket, $config );

		if ( 'skip' === $target['action'] ) {
			if ( $entry_id > 0 ) {
				$this->add_note(
					$entry_id,
					$this->format_smart_routing_note( 'spam', $engine, false, $reason, $kw ),
					'note'
				);
			}

			return 'skip';
		}

		$built = $this->build_smart_job_payload( $target['module'], $feed, $entry, $form, $target['tags'], $target['team_id'], (string) rgar( $config, 'helpdesk_desc_field', 'description' ) );

		if ( $entry_id > 0 ) {
			$this->add_note(
				$entry_id,
				$this->format_smart_routing_note( $target['effective_bucket'], $engine, false, $reason, $kw ),
				'note'
			);
		}

		return array(
			'feed_id'      => $feed_id,
			'entry_id'     => $entry_id,
			'form_id'      => $form_id,
			'module'       => $built['module'],
			'attempt'      => max( 1, (int) rgar( $payload, 'attempt', 1 ) ),
			'sync_payload' => $built['sync_payload'],
			'form_title'   => (string) rgar( $form, 'title' ),
		);
	}

	/**
	 * Map an AI category string to an internal bucket.
	 *
	 * @param string $category spam_vendor|sales_lead|support.
	 *
	 * @return string
	 */
	private function map_ai_category_to_bucket( string $category ): string {
		switch ( $category ) {
			case 'spam_vendor':
				return GF_Odoo_Lead_Classifier::SPAM;
			case 'support':
				return GF_Odoo_Lead_Classifier::SUPPORT;
			case 'sales_lead':
				return GF_Odoo_Lead_Classifier::SALES;
			default:
				return GF_Odoo_Lead_Classifier::SALES;
		}
	}

	/**
	 * Build a human-readable entry note describing a routing decision.
	 *
	 * @param string $bucket Effective bucket (sales|support|support-review|unsure|spam).
	 * @param string $engine 'keyword' or 'AI'.
	 * @param bool   $log_only Whether this is a log-only (would-be) note.
	 * @param string $reason AI reason (optional).
	 * @param array  $kw     Keyword result (for matched terms).
	 *
	 * @return string
	 */
	private function format_smart_routing_note( string $bucket, string $engine, bool $log_only, string $reason, array $kw ): string {
		$labels = array(
			'sales'          => esc_html__( 'SALES (CRM lead)', 'gf-odoo-connector' ),
			'support'        => esc_html__( 'SUPPORT (Helpdesk ticket)', 'gf-odoo-connector' ),
			'support-review' => esc_html__( 'SUPPORT (no default team, routed to CRM lead flagged for review)', 'gf-odoo-connector' ),
			'unsure'         => esc_html__( 'UNSURE (CRM lead flagged for review)', 'gf-odoo-connector' ),
			'spam'           => esc_html__( 'SPAM / VENDOR (not synced to Odoo)', 'gf-odoo-connector' ),
		);

		$label  = $labels[ $bucket ] ?? strtoupper( $bucket );
		$engine_label = 'AI' === $engine ? esc_html__( 'AI', 'gf-odoo-connector' ) : esc_html__( 'keyword', 'gf-odoo-connector' );

		$prefix = $log_only
			? esc_html__( 'Smart routing (log only, %1$s): would route to %2$s.', 'gf-odoo-connector' )
			: esc_html__( 'Smart routing (%1$s): %2$s.', 'gf-odoo-connector' );

		$note = sprintf( $prefix, $engine_label, $label );

		if ( 'AI' === $engine && '' !== trim( $reason ) ) {
			$note .= ' ' . sprintf(
				/* translators: %s: short AI reason */
				esc_html__( 'Reason: %s', 'gf-odoo-connector' ),
				$reason
			);
		}

		if ( 'keyword' === $engine ) {
			$matched = array();
			foreach ( (array) ( $kw['matched'] ?? array() ) as $terms ) {
				foreach ( (array) $terms as $term ) {
					$matched[] = $term;
				}
			}
			$matched = array_slice( array_values( array_unique( $matched ) ), 0, 8 );

			if ( ! empty( $matched ) ) {
				$note .= ' ' . sprintf(
					/* translators: %s: comma-separated matched keywords */
					esc_html__( 'Matched: %s', 'gf-odoo-connector' ),
					implode( ', ', $matched )
				);
			}
		}

		return $note;
	}

	/**
	 * Rebuild payload from GF entry when older errors have no stored JSON (e.g. connection failed before mapping).
	 *
	 * @param array $row Error log row.
	 *
	 * @return array|null
	 */
	private function rebuild_retry_payload_from_entry( array $row ): ?array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return null;
		}

		$feed_id  = (int) $row['feed_id'];
		$form_id  = (int) $row['form_id'];
		$entry_id = (int) $row['entry_id'];
		$module   = (string) $row['module'];

		if ( $feed_id <= 0 || $form_id <= 0 || $entry_id <= 0 ) {
			return null;
		}

		$feed  = GFAPI::get_feed( $feed_id );
		$entry = GFAPI::get_entry( $entry_id );
		$form  = GFAPI::get_form( $form_id );

		if ( is_wp_error( $feed ) || empty( $feed ) || is_wp_error( $entry ) || empty( $entry ) || is_wp_error( $form ) || empty( $form ) ) {
			return null;
		}

		try {
			if ( 'crm' === $module ) {
				return $this->build_crm_sync_payload( $feed, $entry, $form );
			}

			if ( 'helpdesk' === $module ) {
				return $this->build_helpdesk_sync_payload( $feed, $entry, $form );
			}
		} catch ( Exception $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Explain which connection fields are missing (for logs and retry failures).
	 *
	 * @return string
	 */
	private function get_incomplete_connection_message(): string {
		$settings = $this->get_connection_settings();
		$missing  = array();

		if ( '' === trim( (string) rgar( $settings, 'odoo_url' ) ) ) {
			$missing[] = __( 'Odoo URL', 'gf-odoo-connector' );
		}

		if ( '' === trim( (string) rgar( $settings, 'db_name' ) ) ) {
			$missing[] = __( 'Database name', 'gf-odoo-connector' );
		}

		if ( '' === $this->get_api_key() ) {
			$missing[] = __( 'API key: open Connection & API, paste the key, click Test Connection, then Save Settings', 'gf-odoo-connector' );
		}

		if ( empty( $missing ) ) {
			return __( 'Odoo connection settings are incomplete. Open Connection & API, run Test Connection, then Save Settings.', 'gf-odoo-connector' );
		}

		return sprintf(
			/* translators: %s: comma-separated list of missing fields */
			__( 'Odoo connection settings are incomplete. Missing: %s.', 'gf-odoo-connector' ),
			implode( ', ', $missing )
		);
	}

	/**
	 * Add Odoo links to the Gravity Forms entry detail sidebar.
	 *
	 * @param array $meta_boxes Registered meta boxes.
	 * @param array $entry      Entry object.
	 * @param array $form       Form object.
	 *
	 * @return array
	 */
	public function register_entry_detail_meta_boxes( $meta_boxes, $entry, $form ) {
		$addon = $this;

		$meta_boxes['gf_odoo_sync_status'] = array(
			'title'    => esc_html__( 'Odoo Sync', 'gf-odoo-connector' ),
			'callback' => function () use ( $addon, $entry, $form ) {
				$addon->render_entry_sync_status_meta_box( $entry, $form );
			},
			'context'  => 'side',
		);

		return $meta_boxes;
	}

	/**
	 * Entry detail sidebar: sync status, record IDs, retry timing.
	 *
	 * @param array $entry GF entry.
	 * @param array $form  GF form.
	 */
	public function render_entry_sync_status_meta_box( array $entry, array $form ): void {
		$entry_id = (int) rgar( $entry, 'id' );
		$form_id  = (int) rgar( $form, 'id' );

		if ( $entry_id <= 0 ) {
			echo '<p class="description">' . esc_html__( 'No entry data.', 'gf-odoo-connector' ) . '</p>';
			return;
		}

		$status       = (string) gform_get_meta( $entry_id, 'odoo_sync_status' );
		$sync_at      = (string) gform_get_meta( $entry_id, 'odoo_sync_at' );
		$webhook_at   = (string) gform_get_meta( $entry_id, 'odoo_last_webhook_at' );
		$stage        = (string) gform_get_meta( $entry_id, 'odoo_stage' );
		$assigned_to  = (string) gform_get_meta( $entry_id, 'odoo_assigned_to' );
		$lead_id      = (int) gform_get_meta( $entry_id, 'odoo_lead_id' );
		$partner_id   = (int) gform_get_meta( $entry_id, 'odoo_partner_id' );
		$ticket_id    = (int) gform_get_meta( $entry_id, 'odoo_ticket_id' );

		if ( '' === $status ) {
			$status = 'pending';
		}

		printf(
			'<p><span class="gf-odoo-sync-badge gf-odoo-sync-badge--%1$s">%2$s</span></p>',
			esc_attr( $status ),
			esc_html( $this->get_sync_status_label( $status ) )
		);

		if ( '' !== $stage ) {
			printf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Odoo stage:', 'gf-odoo-connector' ),
				esc_html( $stage )
			);
		}

		if ( '' !== $assigned_to ) {
			printf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Assigned to:', 'gf-odoo-connector' ),
				esc_html( $assigned_to )
			);
		}

		if ( $lead_id > 0 ) {
			$lead_url = $this->get_odoo_record_url( 'crm.lead', $lead_id );
			printf(
				'<p><strong>%1$s</strong> ',
				esc_html__( 'Lead:', 'gf-odoo-connector' )
			);
			if ( '' !== $lead_url ) {
				printf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">#%2$d</a>',
					esc_url( $lead_url ),
					$lead_id
				);
			} else {
				echo '#' . esc_html( (string) $lead_id );
			}
			echo '</p>';
		}

		if ( $ticket_id > 0 ) {
			$ticket_url = $this->get_odoo_record_url( 'helpdesk.ticket', $ticket_id );
			printf(
				'<p><strong>%1$s</strong> ',
				esc_html__( 'Ticket:', 'gf-odoo-connector' )
			);
			if ( '' !== $ticket_url ) {
				printf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">#%2$d</a>',
					esc_url( $ticket_url ),
					$ticket_id
				);
			} else {
				echo '#' . esc_html( (string) $ticket_id );
			}
			echo '</p>';
		}

		if ( $partner_id > 0 ) {
			printf(
				'<p><strong>%1$s</strong> %2$d</p>',
				esc_html__( 'Partner ID:', 'gf-odoo-connector' ),
				$partner_id
			);
		}

		$links = $this->get_entry_odoo_links( $entry );
		if ( ! empty( $links ) ) {
			echo '<ul class="gf-odoo-entry-links">';
			foreach ( $links as $link ) {
				printf(
					'<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
					esc_url( $link['url'] ),
					esc_html( $link['label'] )
				);
			}
			echo '</ul>';
		}

		if ( '' !== $sync_at ) {
			printf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Last WP→Odoo sync:', 'gf-odoo-connector' ),
				esc_html( $sync_at )
			);
		}

		if ( '' !== $webhook_at ) {
			printf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Last Odoo update:', 'gf-odoo-connector' ),
				esc_html( $webhook_at )
			);
		}

		if ( 'retrying' === $status ) {
			$retry_ts = GF_Odoo_Async_Sync::get_next_run_timestamp( $entry_id, 0 );
			if ( null === $retry_ts ) {
				$retry_ts = GF_Odoo_Async_Sync::get_retry_timestamp_from_entry_meta( $entry_id );
			}
			if ( null !== $retry_ts ) {
				printf(
					'<p><strong>%1$s</strong> %2$s<br /><span class="gf-odoo-retry-countdown" data-retry-at="%3$d">%4$s</span></p>',
					esc_html__( 'Next retry:', 'gf-odoo-connector' ),
					esc_html(
						wp_date(
							get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
							$retry_ts
						)
					),
					(int) $retry_ts,
					esc_html( human_time_diff( time(), $retry_ts ) )
				);
			}
		}

		printf(
			'<p><button type="button" class="button button-secondary gf-odoo-entry-sync-now" data-entry-id="%1$d">%2$s</button>'
			. ' <span class="gf-odoo-entry-sync-result description" role="status" aria-live="polite"></span></p>',
			$entry_id,
			esc_html__( 'Sync now', 'gf-odoo-connector' )
		);

		unset( $form_id );
	}

	/**
	 * Human-readable sync status for entry UI.
	 *
	 * @param string $status Meta value.
	 *
	 * @return string
	 */
	private function get_sync_status_label( string $status ): string {
		$labels = array(
			'pending'  => __( 'Pending', 'gf-odoo-connector' ),
			'retrying' => __( 'Retrying', 'gf-odoo-connector' ),
			'success'  => __( 'Success', 'gf-odoo-connector' ),
			'failed'   => __( 'Failed', 'gf-odoo-connector' ),
			'skipped'  => __( 'Skipped (smart routing)', 'gf-odoo-connector' ),
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * Build Odoo deep links for an entry's synced records.
	 *
	 * @param array $entry GF entry.
	 *
	 * @return array<int, array{label: string, url: string}>
	 */
	private function get_entry_odoo_links( array $entry ): array {
		$entry_id = (int) rgar( $entry, 'id' );

		if ( $entry_id <= 0 ) {
			return array();
		}

		$links      = array();
		$partner_id = (int) gform_get_meta( $entry_id, 'odoo_partner_id' );
		$lead_id    = (int) gform_get_meta( $entry_id, 'odoo_lead_id' );
		$ticket_id  = (int) gform_get_meta( $entry_id, 'odoo_ticket_id' );

		if ( $partner_id > 0 ) {
			$url = $this->get_odoo_record_url( 'res.partner', $partner_id );
			if ( '' !== $url ) {
				$links[] = array(
					'label' => esc_html__( 'View contact in Odoo', 'gf-odoo-connector' ),
					'url'   => $url,
				);
			}
		}

		if ( $lead_id > 0 ) {
			$url = $this->get_odoo_record_url( 'crm.lead', $lead_id );
			if ( '' !== $url ) {
				$links[] = array(
					'label' => esc_html__( 'View lead in Odoo', 'gf-odoo-connector' ),
					'url'   => $url,
				);
			}
		}

		if ( $ticket_id > 0 ) {
			$url = $this->get_odoo_record_url( 'helpdesk.ticket', $ticket_id );
			if ( '' !== $url ) {
				$links[] = array(
					'label' => esc_html__( 'View ticket in Odoo', 'gf-odoo-connector' ),
					'url'   => $url,
				);
			}
		}

		return $links;
	}

	/**
	 * Store the result of the last connection test.
	 *
	 * @param string $status  success|error|unknown.
	 * @param string $message Human-readable message.
	 */
	private function set_connection_status( string $status, string $message ): void {
		set_transient(
			self::TRANSIENT_CONNECTION_STATUS,
			array(
				'status'    => $status,
				'message'   => $message,
				'tested_at' => time(),
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Mark connection status unknown after settings change.
	 */
	private function invalidate_connection_status(): void {
		$this->set_connection_status(
			'unknown',
			__( 'Settings saved. Run Test Connection to verify.', 'gf-odoo-connector' )
		);
	}

	/**
	 * Resolve the current Odoo connection status for display.
	 *
	 * Trusts a recent definitive test result; otherwise performs a lightweight
	 * live check (reusing the cached Odoo session) so the indicator reflects
	 * reality after a page reload or once the cached result goes stale, instead
	 * of falling back to "Not reachable".
	 *
	 * @param bool $force_live Skip the cache and re-check immediately.
	 *
	 * @return array{status: string, message: string, tested_at: int}
	 */
	private function resolve_connection_status( bool $force_live = false ): array {
		$cached = get_transient( self::TRANSIENT_CONNECTION_STATUS );

		if ( ! $force_live && is_array( $cached ) ) {
			$status    = (string) ( $cached['status'] ?? 'unknown' );
			$tested_at = (int) ( $cached['tested_at'] ?? 0 );

			if (
				in_array( $status, array( 'success', 'error' ), true )
				&& ( time() - $tested_at ) < self::CONNECTION_STATUS_FRESH_TTL
			) {
				return array(
					'status'    => $status,
					'message'   => (string) ( $cached['message'] ?? '' ),
					'tested_at' => $tested_at,
				);
			}
		}

		$api = $this->get_odoo_api();

		if ( null === $api ) {
			return array(
				'status'    => 'unknown',
				'message'   => __( 'Odoo connection is not configured yet. Enter your details and run Test Connection.', 'gf-odoo-connector' ),
				'tested_at' => 0,
			);
		}

		if ( $api->authenticate() ) {
			$message = __( 'Connection verified.', 'gf-odoo-connector' );
			$this->set_connection_status( 'success', $message );

			return array(
				'status'    => 'success',
				'message'   => $message,
				'tested_at' => time(),
			);
		}

		$message = $api->get_last_error();
		if ( '' === $message ) {
			$message = __( 'Could not reach Odoo. Check your connection settings.', 'gf-odoo-connector' );
		}
		$this->set_connection_status( 'error', $message );

		return array(
			'status'    => 'error',
			'message'   => $message,
			'tested_at' => time(),
		);
	}

	/**
	 * HTML for the connection status indicator on the settings page.
	 *
	 * @return string
	 */
	private function get_connection_status_markup(): string {
		$data    = $this->resolve_connection_status();
		$status  = (string) ( $data['status'] ?? 'unknown' );
		$message = (string) ( $data['message'] ?? '' );
		$tested  = (int) ( $data['tested_at'] ?? 0 );

		if ( '' === $message ) {
			$message = esc_html__( 'Not tested yet. Click Test Connection.', 'gf-odoo-connector' );
		} else {
			$message = esc_html( $message );
		}

		$time_label = '';
		if ( $tested > 0 ) {
			$time_label = sprintf(
				' <span class="gf-odoo-hint">(%s)</span>',
				esc_html(
					wp_date(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						$tested
					)
				)
			);
		}

		$status_class = 'success' === $status ? 'success' : ( 'error' === $status ? 'error' : 'pending' );

		return sprintf(
			'<div id="gf-odoo-connection-status" class="gf-odoo-status gf-odoo-connection-status gf-odoo-status-%4$s gf-odoo-connection-status--%1$s" data-status="%1$s">'
			. '<span class="gf-odoo-status-dot gf-odoo-connection-status__dot" aria-hidden="true"></span>'
			. '<span class="gf-odoo-connection-status__text">%2$s%3$s</span></div>',
			esc_attr( $status ),
			$message,
			$time_label,
			esc_attr( $status_class )
		);
	}

	/**
	 * Merge template + overrides into feed meta before sync (single resolution point).
	 *
	 * @param array $feed    Feed object (passed by reference).
	 * @param int   $form_id Form ID.
	 */
	private function apply_resolved_feed_meta( array &$feed, int $form_id ): void {
		if ( $form_id <= 0 || ! class_exists( 'Template_Manager' ) ) {
			return;
		}

		$feed_id = (int) rgar( $feed, 'id' );
		if ( $feed_id <= 0 ) {
			return;
		}

		$resolved = Template_Manager::get_template_for_feed( $form_id, $feed_id );
		if ( null !== $resolved ) {
			// Merge so per-feed settings (salesperson, team, module, etc.) are kept.
			$existing     = (array) rgar( $feed, 'meta', array() );
			$feed['meta'] = array_merge( $existing, $resolved );
		}
	}

	/**
	 * Sanitize feed meta array (templates / overrides).
	 *
	 * @param array  $raw    Raw meta.
	 * @param string $module crm|helpdesk.
	 *
	 * @return array
	 */
	public function sanitize_feed_meta_array( array $raw, string $module, int $sample_form_id = 0 ): array {
		$settings = $raw;

		if ( 'helpdesk' === $module ) {
			foreach ( $this->helpdesk_field_rows() as $row ) {
				$this->sanitize_feed_field_row_settings(
					$settings,
					$row,
					array( Helpdesk_Field_Config::class, 'default_mode' ),
					$sample_form_id
				);
			}
		} else {
			foreach ( $this->crm_field_rows() as $row ) {
				$this->sanitize_feed_field_row_settings(
					$settings,
					$row,
					array( CRM_Field_Config::class, 'default_mode' ),
					$sample_form_id
				);
			}
		}

		return $settings;
	}

	/**
	 * CRM field editor for template admin (all sections).
	 *
	 * @param array $feed        Feed-like array with meta.
	 * @param int   $form_id     Form ID (0 for templates).
	 * @param bool  $is_template Template editor mode.
	 *
	 * @return string
	 */
	public function render_crm_fields_editor_html( $feed, int $form_id, bool $is_template = false, int $sample_form_id = 0 ): string {
		$meta = is_array( $feed ) ? (array) rgar( $feed, 'meta', array() ) : array();
		$this->migrate_feed_meta( $meta );

		$ctx = array(
			'form_id'        => $form_id,
			'feed_id'        => 0,
			'template_id'    => 0,
			'sample_form_id' => $sample_form_id,
			'overrides'      => array(),
			'display_meta'   => $meta,
			'is_linked'      => false,
			'is_template'    => $is_template,
		);

		$html = '';
		foreach ( array( 'contact', 'lead' ) as $section ) {
			$rows = array_filter(
				$this->crm_field_rows(),
				static function ( $row ) use ( $section ) {
					return $row['section'] === $section;
				}
			);

			$html .= '<h3>' . esc_html( 'contact' === $section ? __( 'Contact fields', 'gf-odoo-connector' ) : __( 'Lead fields', 'gf-odoo-connector' ) ) . '</h3>';
			$html .= '<div class="gf-odoo-crm-fields" data-section="' . esc_attr( $section ) . '">';

			foreach ( $rows as $row ) {
				$html .= $this->render_crm_field_row_html( $row, $meta, $form_id, $ctx );
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Helpdesk field editor for template admin (all sections).
	 *
	 * @param array $feed        Feed-like array with meta.
	 * @param int   $form_id     Form ID (0 for templates).
	 * @param bool  $is_template Template editor mode.
	 *
	 * @return string
	 */
	public function render_helpdesk_fields_editor_html( $feed, int $form_id, bool $is_template = false, int $sample_form_id = 0 ): string {
		$meta = is_array( $feed ) ? (array) rgar( $feed, 'meta', array() ) : array();
		$this->migrate_helpdesk_feed_meta( $meta );

		$ctx = array(
			'form_id'        => $form_id,
			'feed_id'        => 0,
			'template_id'    => 0,
			'sample_form_id' => $sample_form_id,
			'overrides'      => array(),
			'display_meta'   => $meta,
			'is_linked'      => false,
			'is_template'    => $is_template,
		);

		$html = '';
		$labels = array(
			'ticket'  => __( 'Ticket fields', 'gf-odoo-connector' ),
			'contact' => __( 'Contact fields', 'gf-odoo-connector' ),
			'product' => __( 'Product details', 'gf-odoo-connector' ),
		);

		foreach ( array_keys( $labels ) as $section ) {
			$rows = array_filter(
				$this->helpdesk_field_rows(),
				static function ( $row ) use ( $section ) {
					return $row['section'] === $section;
				}
			);

			$html .= '<h3>' . esc_html( $labels[ $section ] ) . '</h3>';
			$html .= '<div class="gf-odoo-helpdesk-fields gf-odoo-crm-fields" data-section="' . esc_attr( $section ) . '">';

			foreach ( $rows as $row ) {
				$html .= $this->render_crm_field_row_html( $row, $meta, $form_id, $ctx );
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Template selector block on per-form feed settings.
	 *
	 * @return string
	 */
	private function render_feed_template_selector_html(): string {
		$feed    = $this->get_current_feed();
		$form_id = (int) rgget( 'id' );
		$feed_id = is_array( $feed ) ? (int) rgar( $feed, 'id' ) : (int) rgget( 'fid' );
		$module  = $this->get_feed_module( is_array( $feed ) ? $feed : array() );

		$linked_id = ( $form_id > 0 && $feed_id > 0 && class_exists( 'Template_Manager' ) )
			? Template_Manager::get_linked_template_id( $form_id, $feed_id )
			: 0;

		$templates = class_exists( 'Template_Manager' ) ? Template_Manager::get_all() : array();
		$options   = '<option value="0">' . esc_html__( 'No template', 'gf-odoo-connector' ) . '</option>';

		foreach ( $templates as $template ) {
			if ( (string) $template->module !== $module ) {
				continue;
			}
			$options .= sprintf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $template->id,
				selected( $linked_id, (int) $template->id, false ),
				esc_html( (string) $template->name )
			);
		}

		$use_template = $linked_id > 0;
		$form_title   = $this->get_form_title_for_dashboard( $form_id );

		$template_names = array();
		foreach ( $templates as $template ) {
			$template_names[ (int) $template->id ] = (string) $template->name;
		}

		return sprintf(
			'<div id="gf-odoo-feed-template" class="gf-odoo-feed-template" data-form-id="%1$d" data-feed-id="%2$d" data-module="%3$s" data-form-title="%11$s" data-template-names="%12$s">'
			. '<p><label><input type="radio" name="gf_odoo_template_mode" value="none" %4$s /> %5$s</label></p>'
			. '<p><label><input type="radio" name="gf_odoo_template_mode" value="template" %6$s /> %7$s</label>'
			. ' <select id="gf-odoo-template-select" class="medium"%8$s>%9$s</select></p>'
			. '<p class="description">%10$s</p>'
			. '%13$s'
			. '<div id="gf-odoo-template-remap-modal" class="gf-odoo-remap-modal" hidden aria-hidden="true">'
			. '<div class="gf-odoo-remap-modal__backdrop"></div>'
			. '<div class="gf-odoo-remap-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gf-odoo-remap-title">'
			. '<div class="gf-odoo-remap-modal__body"></div></div></div></div>',
			$form_id,
			$feed_id,
			esc_attr( $module ),
			checked( ! $use_template, true, false ),
			esc_html__( 'No template (own configuration)', 'gf-odoo-connector' ),
			checked( $use_template, true, false ),
			esc_html__( 'Use template:', 'gf-odoo-connector' ),
			$use_template ? '' : ' disabled',
			$options,
			esc_html__( 'Linked templates inherit settings; override individual fields per form.', 'gf-odoo-connector' ),
			esc_attr( $form_title ),
			esc_attr( wp_json_encode( $template_names ) ),
			$use_template && $feed_id > 0
				? '<p><button type="button" class="button button-secondary" id="gf-odoo-refresh-template-mappings">'
					. esc_html__( 'Refresh field mappings from template', 'gf-odoo-connector' )
					. '</button></p>'
				: ''
		);
	}

	/**
	 * Context for template-linked feed field UI.
	 *
	 * @return array<string, mixed>
	 */
	private function get_feed_template_ui_context(): array {
		$feed    = $this->get_current_feed();
		$form_id = (int) rgget( 'id' );
		$feed_id = is_array( $feed ) ? (int) rgar( $feed, 'id' ) : (int) rgget( 'fid' );
		$module  = $this->get_feed_module( is_array( $feed ) ? $feed : array() );

		$meta = is_array( $feed ) ? (array) rgar( $feed, 'meta', array() ) : array();
		if ( 'helpdesk' === $module ) {
			$this->migrate_helpdesk_feed_meta( $meta );
		} else {
			$this->migrate_feed_meta( $meta );
		}

		$template_id = 0;
		$overrides   = array();
		$is_linked   = false;

		$template_meta = array();

		if ( $form_id > 0 && $feed_id > 0 && class_exists( 'Template_Manager' ) ) {
			$template_id = Template_Manager::get_linked_template_id( $form_id, $feed_id );
			if ( $template_id > 0 ) {
				$is_linked = true;
				$template  = Template_Manager::get( $template_id );
				if ( $template ) {
					$template_meta = (array) $template->feed_meta;
				}

				$raw_overrides = Template_Manager::get_feed_overrides( $form_id, $feed_id );
				$overrides     = Template_Manager::prune_invalid_overrides( $template_meta, $raw_overrides );

				if ( $overrides !== $raw_overrides ) {
					Template_Manager::save_overrides( $form_id, $feed_id, $overrides );
				}

				if ( Template_Manager::feed_template_mappings_need_repair( $template_id, $form_id, $feed_id ) ) {
					Template_Manager::repair_feed_template_link( $form_id, $feed_id );
					$overrides = Template_Manager::get_feed_overrides( $form_id, $feed_id );
				}

				$resolved = Template_Manager::get_template_for_feed( $form_id, $feed_id );
				if ( is_array( $resolved ) ) {
					$meta = $resolved;
				}
			}
		}

		return array(
			'form_id'       => $form_id,
			'feed_id'       => $feed_id,
			'template_id'   => $template_id,
			'template_meta' => $template_meta,
			'overrides'     => $overrides,
			'display_meta'  => $meta,
			'is_linked'     => $is_linked,
			'is_template'   => false,
		);
	}

	/**
	 * Whether a field key has a sparse template override.
	 *
	 * @param string $key       Field key.
	 * @param array  $overrides Overrides array.
	 *
	 * @return bool
	 */
	private function field_has_template_override( string $key, array $overrides, array $template_meta = array(), int $template_id = 0, int $form_id = 0 ): bool {
		if ( empty( $template_meta ) ) {
			return array_key_exists( $key . '_mode', $overrides ) || array_key_exists( $key . '_value', $overrides );
		}

		return Template_Manager::field_row_has_effective_override( $key, $template_id, $form_id, $template_meta, $overrides );
	}

	/**
	 * AJAX: rebuild template field remaps for the current feed.
	 */
	public function ajax_refresh_template_mappings(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$feed_id = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;

		if ( $form_id <= 0 || $feed_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing form or feed.', 'gf-odoo-connector' ) ) );
		}

		if ( ! Template_Manager::repair_feed_template_link( $form_id, $feed_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This feed is not linked to a template.', 'gf-odoo-connector' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Field mappings refreshed from the template.', 'gf-odoo-connector' ),
			)
		);
	}

	/**
	 * Build GF Settings renderer for standalone admin pages (not gf_settings subview).
	 *
	 * @param string $filter connection|notifications|webhook.
	 *
	 * @return \Gravity_Forms\Gravity_Forms\Settings\Settings|null
	 */
	private function get_standalone_settings_renderer( string $filter ) {
		if ( ! class_exists( '\Gravity_Forms\Gravity_Forms\Settings\Settings' ) ) {
			return null;
		}

		$this->admin_settings_page_filter = $filter;

		$sections = $this->plugin_settings_fields();
		$sections = $this->prepare_settings_sections( $sections, 'plugin_settings' );

		$renderer = new \Gravity_Forms\Gravity_Forms\Settings\Settings(
			array(
				'capability'                => array( 'manage_options', 'gravityforms_edit_forms', 'gform_full_access' ),
				'fields'                    => $sections,
				'initial_values'            => $this->get_plugin_settings_for_display(),
				'save_callback'             => array( $this, 'update_plugin_settings' ),
				'field_encryption_disabled' => true,
			)
		);

		$this->set_settings_renderer( $renderer );

		return $renderer;
	}

	/**
	 * Enqueue Gravity Forms settings UI assets on plugin admin pages.
	 */
	private function enqueue_standalone_settings_assets(): void {
		$this->enqueue_odoo_admin_page_assets();
	}

	/**
	 * Render a subset of plugin settings on a standalone admin page.
	 *
	 * @param string $filter  connection|notifications|webhook.
	 * @param string $title   Page title.
	 */
	private function render_plugin_settings_subset( string $filter, string $title ): void {
		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$this->enqueue_standalone_settings_assets();

		$renderer = $this->get_standalone_settings_renderer( $filter );

		if ( ! is_object( $renderer ) || ! method_exists( $renderer, 'render' ) ) {
			$this->admin_settings_page_filter = null;
			wp_die( esc_html__( 'Could not load settings. Ensure Gravity Forms is active and up to date.', 'gf-odoo-connector' ) );
		}

		$browser_class = class_exists( 'GFCommon' ) ? GFCommon::get_browser_class() : '';

		$this->render_admin_page_open( $title );
		echo '<div class="gform-admin ' . esc_attr( $browser_class ) . '">';
		$renderer->render();
		echo '</div>';

		if ( 'connection' === $filter ) {
			echo $this->get_country_debug_tools_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$this->render_admin_page_close();

		$this->admin_settings_page_filter = null;
	}

	/**
	 * Connection & API settings page.
	 */
	public function render_settings_page(): void {
		$this->render_plugin_settings_subset( 'connection', __( 'Connection & API', 'gf-odoo-connector' ) );
	}

	/**
	 * Error notification settings page.
	 */
	public function render_notifications_page(): void {
		$this->render_plugin_settings_subset( 'notifications', __( 'Notifications', 'gf-odoo-connector' ) );
	}

	/**
	 * Webhook receiver settings page.
	 */
	public function render_webhook_settings_page(): void {
		$this->render_plugin_settings_subset( 'webhook', __( 'Webhook receiver', 'gf-odoo-connector' ) );
	}

	/**
	 * Smart routing (Beta) settings page.
	 */
	public function render_smart_routing_page(): void {
		$this->render_plugin_settings_subset( 'smart_routing', __( 'Smart routing (Beta)', 'gf-odoo-connector' ) );
	}

	/**
	 * Testing tools page (test submission + scenario results).
	 */
	public function render_testing_page(): void {
		$this->init_testing_admin();
		$this->enqueue_standalone_settings_assets();
		$this->testing_admin->render_testing_page();
	}

	/**
	 * Pre-launch checklist page.
	 */
	public function render_checklist_page(): void {
		$this->init_testing_admin();
		$this->enqueue_standalone_settings_assets();
		$this->testing_admin->render_checklist_page();
	}

	/**
	 * Run a synchronous test submission through process_sync_job().
	 *
	 * @param int    $form_id  Form ID.
	 * @param int    $feed_id  Feed ID.
	 * @param string $scenario normal|bad_url|bad_api_key|missing_required|duplicate.
	 *
	 * @return array<string, mixed>
	 */
	public function run_test_submission( int $form_id, int $feed_id, string $scenario = 'normal' ): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Gravity Forms is not available.', 'gf-odoo-connector' ),
			);
		}

		$form = GFAPI::get_form( $form_id );
		$feed = GFAPI::get_feed( $feed_id );

		if ( is_wp_error( $form ) || empty( $form ) || is_wp_error( $feed ) || empty( $feed ) ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Form or feed not found.', 'gf-odoo-connector' ),
			);
		}

		if ( (int) rgar( $feed, 'form_id' ) !== $form_id ) {
			return array(
				'success' => false,
				'message' => esc_html__( 'Feed does not belong to this form.', 'gf-odoo-connector' ),
			);
		}

		$allowed = array( 'normal', 'bad_url', 'bad_api_key', 'missing_required', 'duplicate' );
		if ( ! in_array( $scenario, $allowed, true ) ) {
			$scenario = 'normal';
		}

		$this->force_test_mode_for_sync  = true;
		$this->skip_auto_retry_for_sync  = true;
		$this->sync_connection_overrides = null;

		$errors_before = $this->count_error_log_rows_for_entry( 0 );

		try {
			if ( 'bad_url' === $scenario ) {
				$this->sync_connection_overrides = array(
					'odoo_url' => 'https://invalid.gf-odoo-test.invalid/',
				);
			} elseif ( 'bad_api_key' === $scenario ) {
				$this->sync_connection_overrides = array(
					'api_key' => 'invalid-test-api-key-' . time(),
				);
			}

			if ( 'duplicate' === $scenario ) {
				return $this->run_duplicate_test_submission( $feed, $form );
			}

			$entry = $this->build_dummy_entry_for_form( $form );
			$entry_id = GFAPI::add_entry( $entry );

			if ( is_wp_error( $entry_id ) ) {
				return array(
					'success' => false,
					'message' => $entry_id->get_error_message(),
				);
			}

			$entry['id'] = (int) $entry_id;

			try {
				$job = $this->build_async_job_payload( $feed, $entry, $form, 1 );
			} catch ( Exception $e ) {
				GFAPI::delete_entry( (int) $entry_id );

				return array(
					'success'      => false,
					'message'      => $e->getMessage(),
					'error_logged' => false,
				);
			}

			if ( 'missing_required' === $scenario && isset( $job['sync_payload'] ) && is_array( $job['sync_payload'] ) ) {
				if ( isset( $job['sync_payload']['contact'] ) && is_array( $job['sync_payload']['contact'] ) ) {
					$job['sync_payload']['contact']['name'] = '';
				}
				if ( isset( $job['sync_payload']['lead'] ) && is_array( $job['sync_payload']['lead'] ) ) {
					$job['sync_payload']['lead']['name'] = '';
				}
			}

			$job['is_manual']       = true;
			$job['skip_auto_retry'] = true;

			$ok = $this->process_sync_job( $job );

			$error_logged = $this->count_error_log_rows_for_entry( (int) $entry_id ) > 0
				|| $this->count_error_log_rows_for_entry( 0 ) > $errors_before;

			$result = $this->build_test_submission_result( $ok, (int) $entry_id, $form_id, $feed, $error_logged );

			if ( in_array( $scenario, array( 'bad_url', 'bad_api_key', 'missing_required' ), true ) ) {
				$result['success']      = false;
				$result['error_logged'] = $error_logged;
				if ( ! $error_logged && '' === $result['message'] ) {
					$result['message'] = $this->get_last_sync_job_error() ?: esc_html__( 'Sync failed but no error was logged.', 'gf-odoo-connector' );
				}
			}

			return $result;
		} catch ( Throwable $e ) {
			return array(
				'success'      => false,
				'message'      => $e->getMessage(),
				'error_logged' => true,
			);
		} finally {
			$this->force_test_mode_for_sync  = false;
			$this->skip_auto_retry_for_sync  = false;
			$this->sync_connection_overrides = null;
		}
	}

	/**
	 * Duplicate-email scenario: two submissions, same partner expected.
	 *
	 * @param array $feed Feed.
	 * @param array $form Form.
	 *
	 * @return array<string, mixed>
	 */
	private function run_duplicate_test_submission( array $feed, array $form ): array {
		$email     = 'gf-odoo-dup-test-' . time() . '@example.com';
		$partner_1 = 0;
		$partner_2 = 0;
		$entry_ids = array();
		$form_id   = (int) rgar( $form, 'id' );

		for ( $i = 0; $i < 2; $i++ ) {
			$entry    = $this->build_dummy_entry_for_form( $form, $email );
			$entry_id = GFAPI::add_entry( $entry );

			if ( is_wp_error( $entry_id ) ) {
				foreach ( $entry_ids as $eid ) {
					GFAPI::delete_entry( $eid );
				}

				return array(
					'success' => false,
					'message' => $entry_id->get_error_message(),
				);
			}

			$entry_id            = (int) $entry_id;
			$entry_ids[]         = $entry_id;
			$entry['id']         = $entry_id;
			$entry['form_id']    = $form_id;

			try {
				$job = $this->build_async_job_payload( $feed, $entry, $form, 1 );
			} catch ( Exception $e ) {
				foreach ( $entry_ids as $eid ) {
					GFAPI::delete_entry( $eid );
				}

				return array(
					'success' => false,
					'message' => $e->getMessage(),
				);
			}

			$this->apply_duplicate_test_payload_overrides( $job, $email );

			$job['is_manual']       = true;
			$job['skip_auto_retry'] = true;

			if ( ! $this->process_sync_job( $job ) ) {
				$msg = $this->get_last_sync_job_error() ?: esc_html__( 'Sync failed.', 'gf-odoo-connector' );

				foreach ( $entry_ids as $eid ) {
					GFAPI::delete_entry( $eid );
				}

				return array(
					'success' => false,
					'message' => $msg,
				);
			}

			$pid = $this->resolve_test_partner_id( $entry_id, $form_id, $email );
			if ( 0 === $i ) {
				$partner_1 = $pid;
			} else {
				$partner_2 = $pid;
			}
		}

		$duplicate_ok = $partner_1 > 0 && $partner_1 === $partner_2;

		if ( $duplicate_ok ) {
			$message = sprintf(
				/* translators: %d: Odoo partner ID */
				__( 'Both submissions used the same Odoo contact (#%d).', 'gf-odoo-connector' ),
				$partner_1
			);
		} elseif ( $partner_1 <= 0 || $partner_2 <= 0 ) {
			$message = __( 'Could not resolve Odoo contact ID after sync. Check that the API user can read Contacts.', 'gf-odoo-connector' );
		} else {
			$message = sprintf(
				/* translators: 1: first partner ID, 2: second partner ID */
				__( 'Submissions created different Odoo contacts (#%1$d vs #%2$d). Duplicate handling may need review.', 'gf-odoo-connector' ),
				$partner_1,
				$partner_2
			);
		}

		return array(
			'success'       => $duplicate_ok,
			'duplicate_ok'  => $duplicate_ok,
			'message'       => $message,
			'record_id'     => $partner_1,
			'record_model'  => 'res.partner',
			'record_url'    => $this->get_odoo_record_url( 'res.partner', $partner_1 ),
			'entry_id'      => $entry_ids[1] ?? 0,
			'entry_url'     => isset( $entry_ids[1] ) ? admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) rgar( $form, 'id' ) . '&lid=' . $entry_ids[1] ) : '',
		);
	}

	/**
	 * Ensure duplicate-test payloads include the same contact email (independent of feed field mapping).
	 *
	 * @param array  $job   Sync job.
	 * @param string $email Shared test email.
	 */
	private function apply_duplicate_test_payload_overrides( array &$job, string $email ): void {
		if ( empty( $job['sync_payload'] ) || ! is_array( $job['sync_payload'] ) ) {
			return;
		}

		$contact_name = __( 'GF Odoo Duplicate Test', 'gf-odoo-connector' );
		$module       = (string) rgar( $job, 'module', '' );
		$payload      = &$job['sync_payload'];

		if ( 'crm' === $module ) {
			if ( ! isset( $payload['contact'] ) || ! is_array( $payload['contact'] ) ) {
				$payload['contact'] = array();
			}

			$payload['contact']['email'] = $email;
			if ( empty( $payload['contact']['name'] ) ) {
				$payload['contact']['name'] = $contact_name;
			}

			if ( ! isset( $payload['lead'] ) || ! is_array( $payload['lead'] ) ) {
				$payload['lead'] = array();
			}

			if ( empty( $payload['lead']['email_from'] ) ) {
				$payload['lead']['email_from'] = $email;
			}

			return;
		}

		if ( 'helpdesk' === $module ) {
			$ticket_key = isset( $payload['ticket'] ) && is_array( $payload['ticket'] ) ? 'ticket' : null;

			if ( null !== $ticket_key ) {
				$payload['ticket']['partner_email'] = $email;
				if ( empty( $payload['ticket']['partner_name'] ) ) {
					$payload['ticket']['partner_name'] = $contact_name;
				}
			} else {
				$payload['partner_email'] = $email;
				if ( empty( $payload['partner_name'] ) ) {
					$payload['partner_name'] = $contact_name;
				}
			}
		}
	}

	/**
	 * Resolve Odoo partner ID after a test sync (entry meta, then email lookup).
	 *
	 * @param int    $entry_id Entry ID.
	 * @param int    $form_id  Form ID.
	 * @param string $email    Contact email used for the test.
	 *
	 * @return int
	 */
	private function resolve_test_partner_id( int $entry_id, int $form_id, string $email ): int {
		$partner_id = (int) gform_get_meta( $entry_id, 'odoo_partner_id', $form_id );

		if ( $partner_id > 0 ) {
			return $partner_id;
		}

		$api = $this->get_odoo_api();
		if ( null === $api ) {
			return 0;
		}

		$crm    = new CRM_Handler( $api );
		$found  = $crm->find_partner_by_email( $email );

		return null !== $found ? (int) $found : 0;
	}

	/**
	 * Build placeholder GF entry field values for a form.
	 *
	 * @param array       $form  Form.
	 * @param string|null $email Fixed email for duplicate tests.
	 *
	 * @return array<string, mixed>
	 */
	private function build_dummy_entry_for_form( array $form, ?string $email = null ): array {
		$form_id = (int) rgar( $form, 'id' );
		$stamp   = (string) time();

		$entry = array(
			'form_id' => $form_id,
			'status'  => 'active',
		);

		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();

		foreach ( $fields as $field ) {
			if ( is_object( $field ) ) {
				$field_id    = (string) $field->id;
				$field_type  = (string) $field->type;
				$field_label = (string) $field->label;
				$choices     = isset( $field->choices ) ? $field->choices : array();
			} else {
				$field_id    = (string) rgar( $field, 'id' );
				$field_type  = (string) rgar( $field, 'type' );
				$field_label = (string) rgar( $field, 'label' );
				$choices     = rgar( $field, 'choices', array() );
			}

			switch ( $field_type ) {
				case 'text':
				case 'textarea':
					$entry[ $field_id ] = 'Test value (' . $field_label . ')';
					break;
				case 'email':
					$entry[ $field_id ] = null !== $email ? $email : 'test-' . $stamp . '@example.com';
					break;
				case 'phone':
					$entry[ $field_id ] = '+31 6 00000000';
					break;
				case 'address':
					$entry[ $field_id . '.1' ] = 'Test Street 1';
					$entry[ $field_id . '.3' ] = 'Amsterdam';
					$entry[ $field_id . '.5' ] = '1234 AB';
					$entry[ $field_id . '.6' ] = 'Netherlands';
					break;
				case 'select':
				case 'radio':
					$first = is_array( $choices ) && ! empty( $choices ) ? reset( $choices ) : null;
					$entry[ $field_id ] = is_array( $first ) ? (string) rgar( $first, 'value', 'test' ) : 'test';
					break;
				case 'name':
					$entry[ $field_id . '.3' ] = 'Test';
					$entry[ $field_id . '.6' ] = 'User';
					break;
				default:
					if ( ! isset( $entry[ $field_id ] ) ) {
						$entry[ $field_id ] = 'Test';
					}
					break;
			}
		}

		return $entry;
	}

	/**
	 * @param bool  $ok           Sync succeeded.
	 * @param int   $entry_id     Entry ID.
	 * @param int   $form_id      Form ID.
	 * @param array $feed         Feed.
	 * @param bool  $error_logged Whether error log has a row for this entry.
	 *
	 * @return array<string, mixed>
	 */
	private function build_test_submission_result( bool $ok, int $entry_id, int $form_id, array $feed, bool $error_logged ): array {
		if ( ! $ok ) {
			return array(
				'success'      => false,
				'message'      => $this->get_last_sync_job_error() ?: esc_html__( 'Odoo sync failed.', 'gf-odoo-connector' ),
				'error_logged' => $error_logged,
				'entry_id'     => $entry_id,
				'entry_url'    => admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry_id ),
			);
		}

		$module = $this->get_feed_module( $feed );
		$record_id   = 0;
		$record_model = '';

		if ( 'crm' === $module ) {
			$record_id    = (int) gform_get_meta( $entry_id, 'odoo_lead_id', $form_id );
			$record_model = 'crm.lead';
		} elseif ( 'helpdesk' === $module ) {
			$record_id    = (int) gform_get_meta( $entry_id, 'odoo_ticket_id', $form_id );
			$record_model = 'helpdesk.ticket';
		}

		$record_url = $record_id > 0 ? $this->get_odoo_record_url( $record_model, $record_id ) : '';

		$message = esc_html__( 'Test submission successful. Check Odoo for the [TEST] record.', 'gf-odoo-connector' );
		if ( $record_id > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: Odoo record ID */
				__( 'Record #%d created.', 'gf-odoo-connector' ),
				$record_id
			);
		}

		return array(
			'success'      => true,
			'message'      => $message,
			'record_id'    => $record_id,
			'record_model' => $record_model,
			'record_url'   => $record_url,
			'entry_id'     => $entry_id,
			'entry_url'    => admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry_id ),
			'error_logged' => false,
		);
	}

	/**
	 * Count error log rows for an entry (0 = count latest row regardless, for before/after diff).
	 *
	 * @param int $entry_id Entry ID.
	 *
	 * @return int
	 */
	private function count_error_log_rows_for_entry( int $entry_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'gf_odoo_errors';

		if ( $entry_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE entry_id = %d",
					$entry_id
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Feed templates admin page.
	 */
	public function render_templates_page(): void {
		$this->init_templates_admin();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'list';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$title   = 'edit' === $action
			? ( $edit_id > 0 ? __( 'Edit template', 'gf-odoo-connector' ) : __( 'New template', 'gf-odoo-connector' ) )
			: __( 'Feed templates', 'gf-odoo-connector' );

		$this->render_admin_page_open( $title );
		$this->templates_admin->render_page();
		$this->render_admin_page_close();
	}

	/**
	 * Error log page.
	 */
	public function render_error_log_page(): void {
		$this->render_error_log_screen();
	}

	/**
	 * Sync history page.
	 */
	public function render_sync_history_page(): void {
		if ( ! $this->current_user_can_manage_plugin() || ! class_exists( 'Dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$successes = Dashboard::get_recent_successes( 50 );

		$this->render_admin_page_open( __( 'Sync history', 'gf-odoo-connector' ) );
		echo '<p class="gf-odoo-page-desc">' . esc_html__( 'Recent successful Odoo syncs.', 'gf-odoo-connector' ) . '</p>';

		if ( empty( $successes ) ) {
			echo '<div class="gf-odoo-card"><div class="gf-odoo-card-body"><p class="gf-odoo-hint">' . esc_html__( 'No successful syncs yet.', 'gf-odoo-connector' ) . '</p></div></div>';
			$this->render_admin_page_close();
			return;
		}

		echo '<div class="gf-odoo-card"><div class="gf-odoo-card-body gf-odoo-card-body--flush">';
		echo '<table class="gf-odoo-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Form', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Entry', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Module', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Odoo record', 'gf-odoo-connector' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $successes as $row ) {
			$form_id  = (int) ( $row['form_id'] ?? 0 );
			$entry_id = (int) ( $row['entry_id'] ?? 0 );
			$module    = (string) ( $row['module'] ?? '' );
			$lead_id   = (int) ( $row['lead_id'] ?? 0 );
			$ticket_id = (int) ( $row['ticket_id'] ?? 0 );
			$link      = $this->get_dashboard_odoo_record_link( $module, $lead_id, $ticket_id );
			$record    = $link
				? sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">#%2$d</a>', esc_url( $link['url'] ), (int) $link['id'] )
				: '-';
			$badge     = 'helpdesk' === $module ? 'badge-helpdesk' : 'badge-crm';
			$mod_label = 'helpdesk' === $module
				? esc_html__( 'Helpdesk', 'gf-odoo-connector' )
				: ( 'crm' === $module ? esc_html__( 'CRM', 'gf-odoo-connector' ) : esc_html( $module ) );

			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td><a href="%3$s">#%4$d</a></td><td><span class="gf-odoo-badge %7$s">%5$s</span></td><td>%6$s</td></tr>',
				esc_html( (string) ( $row['sync_at'] ?? '' ) ),
				esc_html( $this->get_form_title_for_dashboard( $form_id ) ),
				esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry_id ) ),
				$entry_id,
				$mod_label,
				wp_kses_post( $record ),
				esc_attr( $badge )
			);
		}

		echo '</tbody></table></div></div>';
		$this->render_admin_page_close();
	}

	/**
	 * Webhook log page (settings subset webhook section log only).
	 */
	public function render_webhook_log_page(): void {
		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$this->render_admin_page_open( __( 'Webhook log', 'gf-odoo-connector' ) );
		echo '<div class="gf-odoo-card"><div class="gf-odoo-card-body gf-odoo-card-body--flush">';
		echo $this->get_webhook_log_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div>';
		$this->render_admin_page_close();
	}

	/**
	 * Shared error log screen markup.
	 */
	private function render_error_log_screen(): void {
		if ( ! $this->current_user_can_manage_plugin() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		require_once GF_ODOO_PATH . 'admin/class-gf-odoo-errors-list-table.php';

		$list_table = new GF_Odoo_Errors_List_Table();

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['error_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$action = '-1' === $action && isset( $_POST['action2'] ) ? sanitize_text_field( wp_unslash( $_POST['action2'] ) ) : $action; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( 'mark_resolved' === $action ) {
				check_admin_referer( 'bulk-gf_odoo_errors' );

				$ids   = array_map( 'absint', (array) wp_unslash( $_POST['error_ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$count = Error_Logger::mark_resolved_bulk( $ids );

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'    => 'gf_odoo_errors',
							'updated' => $count,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		$list_table->prepare_items();

		$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$export_url = wp_nonce_url(
			admin_url( 'admin-ajax.php?action=gf_odoo_export_csv' ),
			'gf_odoo_nonce',
			'nonce'
		);

		$this->render_admin_page_open( __( 'Error log', 'gf-odoo-connector' ), 'gf-odoo-page--wide' );
		?>
		<p class="gf-odoo-page-desc">
			<?php esc_html_e( 'Failed Odoo syncs are listed here. Use Retry to resend the stored payload without re-reading the entry.', 'gf-odoo-connector' ); ?>
		</p>
		<div class="gf-odoo-page-actions">
			<a href="<?php echo esc_url( $export_url ); ?>" class="gf-odoo-btn gf-odoo-btn-secondary"><?php esc_html_e( 'Export CSV', 'gf-odoo-connector' ); ?></a>
		</div>
		<?php if ( $updated > 0 ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of errors */
						_n( '%d error marked resolved.', '%d errors marked resolved.', $updated, 'gf-odoo-connector' ),
						$updated
					)
				);
				?>
			</p></div>
		<?php endif; ?>
		<div class="gf-odoo-card">
			<div class="gf-odoo-card-body gf-odoo-card-body--flush">
				<form method="post">
					<?php $list_table->display(); ?>
				</form>
			</div>
		</div>
		<?php
		$this->render_admin_page_close();
	}
}
