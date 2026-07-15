<?php
/**
 * Setup wizard, step 1 (welcome).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$next_url = admin_url( 'admin.php?page=gf_odoo_setup&step=2' );
$skip_url = admin_url( 'admin.php?page=gf_odoo_dashboard&gf_odoo_wizard_skip=1' );
?>
<h2><?php esc_html_e( 'Welcome!', 'gf-odoo-connector' ); ?></h2>
<p class="desc">
	<?php esc_html_e( 'GF Odoo Connector syncs your Gravity Forms submissions directly to Odoo CRM and Helpdesk. This quick setup will connect the plugin to your Odoo instance.', 'gf-odoo-connector' ); ?>
</p>
<p class="desc" style="margin-top: -12px;">
	<?php esc_html_e( 'You will need:', 'gf-odoo-connector' ); ?>
</p>
<ul style="margin: 0 0 16px 20px; font-size: 13px; color: #555; line-height: 2;">
	<li><?php esc_html_e( 'Your Odoo instance URL', 'gf-odoo-connector' ); ?></li>
	<li><?php esc_html_e( 'The Odoo database name', 'gf-odoo-connector' ); ?></li>
	<li><?php esc_html_e( 'An administrator login email', 'gf-odoo-connector' ); ?></li>
	<li><?php esc_html_e( 'An Odoo API key (Profile → Preferences → API Keys)', 'gf-odoo-connector' ); ?></li>
</ul>
<div class="wz-footer">
	<a href="<?php echo esc_url( $skip_url ); ?>" class="wz-btn-skip"><?php esc_html_e( 'Skip setup', 'gf-odoo-connector' ); ?></a>
	<a href="<?php echo esc_url( $next_url ); ?>" class="wz-btn wz-btn-primary">
		<?php esc_html_e( 'Get started', 'gf-odoo-connector' ); ?>
		<span class="dashicons dashicons-arrow-right-alt" style="font-size:16px;width:16px;height:16px;"></span>
	</a>
</div>
