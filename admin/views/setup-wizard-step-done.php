<?php
/**
 * Setup wizard — step 3 (done).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="done-icon">
	<span class="dashicons dashicons-yes-alt"></span>
</div>
<h2 style="text-align:center"><?php esc_html_e( 'You\'re all set!', 'gf-odoo-connector' ); ?></h2>
<p class="desc" style="text-align:center">
	<?php esc_html_e( 'GF Odoo Connector is connected to your Odoo instance. Now add a feed to a Gravity Forms form to start syncing.', 'gf-odoo-connector' ); ?>
</p>
<div class="done-links">
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_odoo_dashboard' ) ); ?>" class="done-link">
		<span class="dashicons dashicons-chart-area"></span>
		<?php esc_html_e( 'View dashboard', 'gf-odoo-connector' ); ?>
	</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_edit_forms' ) ); ?>" class="done-link">
		<span class="dashicons dashicons-feedback"></span>
		<?php esc_html_e( 'Go to Forms', 'gf-odoo-connector' ); ?>
	</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_odoo_settings' ) ); ?>" class="done-link">
		<span class="dashicons dashicons-admin-plugins"></span>
		<?php esc_html_e( 'Connection settings', 'gf-odoo-connector' ); ?>
	</a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_odoo_about' ) ); ?>" class="done-link">
		<span class="dashicons dashicons-info"></span>
		<?php esc_html_e( 'About the plugin', 'gf-odoo-connector' ); ?>
	</a>
</div>
