<?php
/**
 * First-run setup wizard (full-screen layout).
 *
 * @package GF_Odoo_Connector
 * @var GF_Odoo_Addon $addon Add-on instance.
 * @var int           $step Current step (1–3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'dashicons' );
$logo_url = $addon->get_logo_url();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'GF Odoo Connector Setup', 'gf-odoo-connector' ); ?></title>
	<?php wp_head(); ?>
	<style>
		body { background: #f0f0f1; margin: 0; }
		#wpcontent, #wpbody-content { padding: 0; }
		#adminmenuwrap, #adminmenuback, #wpadminbar { display: none !important; }
		#wpcontent { margin-left: 0 !important; }
		.gf-odoo-wizard { max-width: 560px; margin: 60px auto; padding: 0 20px; }
		.gf-odoo-wizard-brand { text-align: center; margin-bottom: 32px; }
		.gf-odoo-wizard-brand img { width: 60px; height: 60px; object-fit: contain; }
		.gf-odoo-wizard-brand h1 { font-size: 20px; font-weight: 700; color: #1e1e2e; margin: 10px 0 4px; }
		.gf-odoo-wizard-brand p { font-size: 13px; color: #888; margin: 0; }
		.wizard-progress { display: flex; align-items: center; margin-bottom: 28px; }
		.wp-step { display: flex; align-items: center; gap: 7px; flex: 1; }
		.wp-step-dot { width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
		.wp-step-dot.done { background: #f0fdf4; color: #16a34a; border: 1.5px solid #bbf7d0; }
		.wp-step-dot.active { background: var(--wp-admin-theme-color, #2271b1); color: #fff; }
		.wp-step-dot.todo { background: #f5f5f5; color: #bbb; border: 1.5px solid #e5e5e5; }
		.wp-step-name { font-size: 12px; font-weight: 500; color: #888; }
		.wp-step-name.active { color: #1e1e2e; }
		.wp-line { flex: 1; height: 1px; background: #e5e5e5; margin: 0 8px; }
		.wizard-box { background: #fff; border-radius: 12px; border: 1px solid #e8e8e8; padding: 32px; }
		.wizard-box h2 { font-size: 18px; font-weight: 700; color: #1e1e2e; margin: 0 0 8px; }
		.wizard-box .desc { font-size: 13px; color: #666; line-height: 1.6; margin: 0 0 24px; }
		.wz-field { margin-bottom: 18px; }
		.wz-label { font-size: 12.5px; font-weight: 600; color: #333; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
		.wz-req { font-size: 10px; color: #dc2626; background: #fef2f2; padding: 1px 5px; border-radius: 3px; }
		.wz-input { width: 100%; padding: 9px 12px; border: 1px solid #e0e0e0; border-radius: 7px; font-size: 13px; color: #1e1e2e; background: #fafafa; transition: border-color .15s; box-sizing: border-box; }
		.wz-input:focus { outline: none; border-color: var(--wp-admin-theme-color, #2271b1); background: #fff; }
		.wz-hint { font-size: 11.5px; color: #999; margin-top: 4px; line-height: 1.4; }
		.wz-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 28px; padding-top: 24px; border-top: 1px solid #f0f0f0; }
		.wz-btn { padding: 9px 20px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
		.wz-btn-primary { background: var(--wp-admin-theme-color, #2271b1); color: #fff; }
		.wz-btn-primary:hover { opacity: .9; color: #fff; }
		.wz-btn-skip { background: transparent; color: #aaa; font-size: 12px; font-weight: 400; border: none; cursor: pointer; text-decoration: underline; }
		.wz-status { padding: 10px 14px; border-radius: 7px; font-size: 13px; font-weight: 500; margin-top: 12px; display: none; }
		.wz-status-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
		.wz-status-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
		.done-icon { text-align: center; margin-bottom: 20px; }
		.done-icon .dashicons { font-size: 56px; width: 56px; height: 56px; color: #16a34a; }
		.done-links { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 20px; }
		.done-link { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border: 1px solid #e8e8e8; border-radius: 8px; text-decoration: none; color: #1e1e2e; font-size: 13px; font-weight: 500; transition: border-color .15s; }
		.done-link:hover { border-color: var(--wp-admin-theme-color, #2271b1); color: #1e1e2e; }
		.done-link .dashicons { color: #888; font-size: 18px; width: 18px; height: 18px; }
	</style>
</head>
<body class="wp-admin wp-core-ui">
<div class="gf-odoo-wizard">

	<div class="gf-odoo-wizard-brand">
		<?php if ( '' !== $logo_url ) : ?>
			<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'GF Odoo Connector', 'gf-odoo-connector' ); ?>">
		<?php endif; ?>
		<h1><?php esc_html_e( 'GF Odoo Connector', 'gf-odoo-connector' ); ?></h1>
		<p><?php esc_html_e( 'Let\'s get you connected to Odoo in 2 minutes.', 'gf-odoo-connector' ); ?></p>
	</div>

	<?php if ( $step < 3 ) : ?>
	<div class="wizard-progress">
		<?php
		$steps = array(
			1 => __( 'Welcome', 'gf-odoo-connector' ),
			2 => __( 'Connection', 'gf-odoo-connector' ),
		);
		$i = 0;
		foreach ( $steps as $num => $label ) :
			$state = $num < $step ? 'done' : ( $num === $step ? 'active' : 'todo' );
			if ( $i > 0 ) {
				echo '<div class="wp-line"></div>';
			}
			?>
			<div class="wp-step">
				<div class="wp-step-dot <?php echo esc_attr( $state ); ?>">
					<?php if ( 'done' === $state ) : ?>
						<span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span>
					<?php else : ?>
						<?php echo (int) $num; ?>
					<?php endif; ?>
				</div>
				<span class="wp-step-name <?php echo 'active' === $state ? 'active' : ''; ?>"><?php echo esc_html( $label ); ?></span>
			</div>
			<?php
			++$i;
		endforeach;
		?>
	</div>
	<?php endif; ?>

	<div class="wizard-box">
		<?php
		if ( 1 === $step ) {
			require GF_ODOO_PATH . 'admin/views/setup-wizard-step-welcome.php';
		} elseif ( 2 === $step ) {
			require GF_ODOO_PATH . 'admin/views/setup-wizard-step-connection.php';
		} else {
			require GF_ODOO_PATH . 'admin/views/setup-wizard-step-done.php';
		}
		?>
	</div>

</div>
<?php wp_footer(); ?>
</body>
</html>
