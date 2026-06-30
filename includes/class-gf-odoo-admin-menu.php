<?php
/**
 * WordPress admin menu for GF Odoo Connector.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers structured admin pages under a top-level menu.
 */
class GF_Odoo_Admin_Menu {

	public const PARENT_SLUG = 'gf_odoo_dashboard';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook registration.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 97 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_setup_wizard' ) );
		add_action( 'admin_head', array( $this, 'admin_menu_divider_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_menu_styles' ) );
	}

	/**
	 * First activation: redirect to setup wizard once (unless already dismissed).
	 */
	public function maybe_redirect_setup_wizard(): void {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gf_odoo_wizard_skip'] ) && current_user_can( 'manage_options' ) ) {
			update_option( 'gf_odoo_wizard_dismissed', true );
			wp_safe_redirect( self::url( self::PARENT_SLUG ) );
			exit;
		}

		if ( ! get_option( 'gf_odoo_show_wizard' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';

		if ( 'gf_odoo_setup' === $page ) {
			delete_option( 'gf_odoo_show_wizard' );
			return;
		}

		delete_option( 'gf_odoo_show_wizard' );

		if ( get_option( 'gf_odoo_wizard_dismissed' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=gf_odoo_setup' ) );
		exit;
	}

	/**
	 * Submenu styling (all wp-admin screens).
	 */
	public function enqueue_admin_menu_styles(): void {
		wp_enqueue_style(
			'gf_odoo_admin_menu',
			GF_ODOO_URL . 'assets/css/admin-menu.css',
			array(),
			GF_ODOO_VERSION
		);
	}

	/**
	 * Add a divider line above the first item of each menu group.
	 */
	public function admin_menu_divider_script(): void {
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			document.querySelectorAll(
				'#adminmenu #toplevel_page_gf_odoo_dashboard a[href*="page=gf_odoo_settings"],' +
				'#adminmenu #toplevel_page_gf_odoo_dashboard a[href*="page=gf_odoo_history"],' +
				'#adminmenu #toplevel_page_gf_odoo_dashboard a[href*="page=gf_odoo_testing"],' +
				'#adminmenu #toplevel_page_gf_odoo_dashboard a[href*="page=gf_odoo_about"]'
			).forEach(function(a) {
				var li = a.closest('li');
				if (li) {
					li.classList.add('gf-odoo-nav-divider');
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Register top-level menu and subpages.
	 */
	public function register_menu(): void {
		$addon = GF_Odoo_Addon::get_instance();
		$cap   = $addon->get_admin_menu_capability();

		add_menu_page(
			__( 'GF Odoo Connector', 'gf-odoo-connector' ),
			__( 'GF Odoo Connector', 'gf-odoo-connector' ),
			$cap,
			self::PARENT_SLUG,
			array( $addon, 'render_dashboard_page' ),
			'dashicons-update',
			58
		);

		// Primary: everyday work.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Feed templates', 'gf-odoo-connector' ),
			__( 'Feed templates', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_templates',
			array( $addon, 'render_templates_page' )
		);

		// Configuration.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Connection & API', 'gf-odoo-connector' ),
			__( 'Connection & API', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_settings',
			array( $addon, 'render_settings_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Notifications', 'gf-odoo-connector' ),
			__( 'Notifications', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_notifications',
			array( $addon, 'render_notifications_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Webhook receiver', 'gf-odoo-connector' ),
			__( 'Webhook', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_webhook',
			array( $addon, 'render_webhook_settings_page' )
		);

		// Activity / monitoring.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Sync history', 'gf-odoo-connector' ),
			__( 'Sync history', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_history',
			array( $addon, 'render_sync_history_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Error log', 'gf-odoo-connector' ),
			__( 'Error log', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_errors',
			array( $addon, 'render_error_log_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Webhook log', 'gf-odoo-connector' ),
			__( 'Webhook log', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_webhook_log',
			array( $addon, 'render_webhook_log_page' )
		);

		// Tools.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Testing tools', 'gf-odoo-connector' ),
			__( 'Testing tools', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_testing',
			array( $addon, 'render_testing_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Pre-launch checklist', 'gf-odoo-connector' ),
			__( 'Pre-launch checklist', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_checklist',
			array( $addon, 'render_checklist_page' )
		);

		// Reference.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'About', 'gf-odoo-connector' ),
			__( 'About', 'gf-odoo-connector' ),
			$cap,
			'gf_odoo_about',
			array( $addon, 'render_about' )
		);

		add_submenu_page(
			null,
			__( 'Setup', 'gf-odoo-connector' ),
			__( 'Setup', 'gf-odoo-connector' ),
			'manage_options',
			'gf_odoo_setup',
			array( $addon, 'render_setup_wizard' )
		);

		// WordPress duplicates the top-level item as the first submenu entry.
		global $submenu;
		if ( isset( $submenu[ self::PARENT_SLUG ][0][2] ) && self::PARENT_SLUG === $submenu[ self::PARENT_SLUG ][0][2] ) {
			$submenu[ self::PARENT_SLUG ][0][0] = __( 'Dashboard', 'gf-odoo-connector' );
		}
	}

	/**
	 * @param string $page Page slug.
	 *
	 * @return string
	 */
	public static function url( string $page = self::PARENT_SLUG ): string {
		return admin_url( 'admin.php?page=' . rawurlencode( $page ) );
	}

	/**
	 * Whether current screen is a plugin admin page.
	 *
	 * @return bool
	 */
	public static function is_plugin_admin_page(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';

		$slugs = array(
			'gf_odoo_dashboard',
			'gf_odoo_settings',
			'gf_odoo_notifications',
			'gf_odoo_webhook',
			'gf_odoo_testing',
			'gf_odoo_checklist',
			'gf_odoo_templates',
			'gf_odoo_errors',
			'gf_odoo_history',
			'gf_odoo_webhook_log',
			'gf_odoo_about',
		);

		return in_array( $page, $slugs, true );
	}
}
