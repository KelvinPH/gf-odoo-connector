<?php
/**
 * Setup wizard — step 2 (connection).
 *
 * @package GF_Odoo_Connector
 * @var GF_Odoo_Addon $addon Add-on instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = is_array( $addon->get_plugin_settings() ) ? $addon->get_plugin_settings() : array();
$done_url = admin_url( 'admin.php?page=gf_odoo_setup&step=3' );
$nonce    = wp_create_nonce( 'gf_odoo_nonce' );
$test_nonce = wp_create_nonce( 'gf_odoo_test_connection' );
?>
<h2><?php esc_html_e( 'Connect to Odoo', 'gf-odoo-connector' ); ?></h2>
<p class="desc"><?php esc_html_e( 'Enter your Odoo instance details below. All fields marked required are needed to establish a connection.', 'gf-odoo-connector' ); ?></p>

<div class="wz-field">
	<div class="wz-label"><?php esc_html_e( 'Odoo URL', 'gf-odoo-connector' ); ?> <span class="wz-req"><?php esc_html_e( 'required', 'gf-odoo-connector' ); ?></span></div>
	<input type="url" id="wz-odoo-url" class="wz-input" placeholder="https://yourcompany.odoo.com" value="<?php echo esc_attr( (string) ( $settings['odoo_url'] ?? '' ) ); ?>">
	<div class="wz-hint"><?php esc_html_e( 'The base URL of your Odoo instance — no trailing slash.', 'gf-odoo-connector' ); ?></div>
</div>
<div class="wz-field">
	<div class="wz-label"><?php esc_html_e( 'Database name', 'gf-odoo-connector' ); ?> <span class="wz-req"><?php esc_html_e( 'required', 'gf-odoo-connector' ); ?></span></div>
	<input type="text" id="wz-db-name" class="wz-input" placeholder="your-database" value="<?php echo esc_attr( (string) ( $settings['db_name'] ?? '' ) ); ?>">
	<div class="wz-hint"><?php esc_html_e( 'Visible in Odoo: Settings → Technical → Databases, or in your Odoo URL.', 'gf-odoo-connector' ); ?></div>
</div>
<div class="wz-field">
	<div class="wz-label"><?php esc_html_e( 'Login email', 'gf-odoo-connector' ); ?></div>
	<input type="email" id="wz-login-email" class="wz-input" placeholder="admin@yourcompany.com" value="<?php echo esc_attr( (string) ( $settings['login_email'] ?? '' ) ); ?>">
</div>
<div class="wz-field">
	<div class="wz-label"><?php esc_html_e( 'API Key', 'gf-odoo-connector' ); ?> <span class="wz-req"><?php esc_html_e( 'required', 'gf-odoo-connector' ); ?></span></div>
	<input type="password" id="wz-api-key" class="wz-input" placeholder="••••••••••••••••••••••••" autocomplete="new-password">
	<div class="wz-hint"><?php esc_html_e( 'Generate in Odoo: click your avatar → Preferences → API Keys → New API Key.', 'gf-odoo-connector' ); ?></div>
</div>

<div id="wz-status" class="wz-status" role="status" aria-live="polite"></div>

<div class="wz-footer">
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_odoo_setup&step=1' ) ); ?>" class="wz-btn-skip">← <?php esc_html_e( 'Back', 'gf-odoo-connector' ); ?></a>
	<div style="display:flex;gap:10px;align-items:center">
		<button type="button" id="wz-test-btn" class="wz-btn" style="background:#fff;color:#333;border:1px solid #ddd;">
			<?php esc_html_e( 'Test connection', 'gf-odoo-connector' ); ?>
		</button>
		<button type="button" id="wz-save-btn" class="wz-btn wz-btn-primary">
			<?php esc_html_e( 'Save & finish', 'gf-odoo-connector' ); ?>
			<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
		</button>
	</div>
</div>

<script>
(function() {
	const saveNonce = <?php echo wp_json_encode( $nonce ); ?>;
	const testNonce = <?php echo wp_json_encode( $test_nonce ); ?>;
	const doneUrl   = <?php echo wp_json_encode( $done_url ); ?>;

	function getFields() {
		return {
			odoo_url:    document.getElementById('wz-odoo-url').value.trim(),
			db_name:     document.getElementById('wz-db-name').value.trim(),
			login_email: document.getElementById('wz-login-email').value.trim(),
			api_key:     document.getElementById('wz-api-key').value,
		};
	}

	function showStatus( msg, type ) {
		const el = document.getElementById('wz-status');
		el.textContent = msg;
		el.className   = 'wz-status wz-status-' + type;
		el.style.display = 'block';
	}

	document.getElementById('wz-test-btn').addEventListener('click', function() {
		const f = getFields();
		showStatus( <?php echo wp_json_encode( __( 'Testing connection…', 'gf-odoo-connector' ) ); ?>, 'success' );
		const body = new URLSearchParams({ action: 'gf_odoo_test_connection', nonce: testNonce, ...f });
		fetch( ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
			.then( r => r.json() )
			.then( data => {
				if ( data.success ) {
					showStatus( '✓ ' + ( data.data?.message || <?php echo wp_json_encode( __( 'Connected.', 'gf-odoo-connector' ) ); ?> ), 'success' );
				} else {
					showStatus( '✗ ' + ( data.data?.message || <?php echo wp_json_encode( __( 'Connection failed.', 'gf-odoo-connector' ) ); ?> ), 'error' );
				}
			})
			.catch( () => showStatus( <?php echo wp_json_encode( __( 'Request failed — check your URL.', 'gf-odoo-connector' ) ); ?>, 'error' ) );
	});

	document.getElementById('wz-save-btn').addEventListener('click', function() {
		const f = getFields();
		if ( ! f.odoo_url || ! f.db_name || ! f.api_key ) {
			showStatus( <?php echo wp_json_encode( __( 'Please fill in all required fields.', 'gf-odoo-connector' ) ); ?>, 'error' );
			return;
		}
		const body = new URLSearchParams({ action: 'gf_odoo_wizard_save', nonce: saveNonce, ...f });
		fetch( ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
			.then( r => r.json() )
			.then( data => {
				if ( data.success ) {
					window.location.href = doneUrl;
				} else {
					showStatus( '✗ ' + ( data.data?.message || <?php echo wp_json_encode( __( 'Save failed.', 'gf-odoo-connector' ) ); ?> ), 'error' );
				}
			});
	});
})();
</script>
