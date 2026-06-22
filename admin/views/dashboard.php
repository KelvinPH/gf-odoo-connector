<?php
/**
 * Odoo sync dashboard view.
 *
 * @package GF_Odoo_Connector
 *
 * @var array  $counts     Summary metric counts.
 * @var array  $errors     Recent error rows.
 * @var array  $successes  Recent success rows.
 * @var array  $connection Connection card data.
 * @var string $error_log_url Error log URL.
 * @var GF_Odoo_Addon $addon Add-on instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$connection_status_value = (string) ( $connection['status'] ?? '' );
if ( 'connected' === $connection_status_value ) {
	$connection_status_class = 'success';
} elseif ( 'unknown' === $connection_status_value ) {
	$connection_status_class = 'pending';
} else {
	$connection_status_class = 'error';
}
?>
<div class="gf-odoo-metrics">
	<div class="gf-odoo-metric">
		<div class="gf-odoo-metric-label"><?php esc_html_e( 'Synced today', 'gf-odoo-connector' ); ?></div>
		<div class="gf-odoo-metric-value"><?php echo esc_html( (string) ( $counts['synced_today'] ?? 0 ) ); ?></div>
	</div>
	<div class="gf-odoo-metric">
		<div class="gf-odoo-metric-label"><?php esc_html_e( 'Pending / retrying', 'gf-odoo-connector' ); ?></div>
		<div class="gf-odoo-metric-value is-warning"><?php echo esc_html( (string) ( $counts['pending'] ?? 0 ) ); ?></div>
	</div>
	<div class="gf-odoo-metric">
		<div class="gf-odoo-metric-label"><?php esc_html_e( 'Failed', 'gf-odoo-connector' ); ?></div>
		<div class="gf-odoo-metric-value is-danger"><?php echo esc_html( (string) ( $counts['failed'] ?? 0 ) ); ?></div>
	</div>
	<div class="gf-odoo-metric">
		<div class="gf-odoo-metric-label"><?php esc_html_e( 'Total synced', 'gf-odoo-connector' ); ?></div>
		<div class="gf-odoo-metric-value is-success"><?php echo esc_html( (string) ( $counts['total'] ?? 0 ) ); ?></div>
	</div>
</div>

<div class="gf-odoo-dashboard-grid">
	<div class="gf-odoo-card gf-odoo-dashboard-panel--chart">
		<div class="gf-odoo-card-header">
			<h3><?php esc_html_e( 'Sync activity (last 14 days)', 'gf-odoo-connector' ); ?></h3>
		</div>
		<div class="gf-odoo-card-body">
			<div class="gf-odoo-chart-wrap">
				<canvas id="gf-odoo-sync-chart" height="120" aria-label="<?php esc_attr_e( 'Sync activity chart', 'gf-odoo-connector' ); ?>"></canvas>
			</div>
		</div>
	</div>

	<div class="gf-odoo-card">
		<div class="gf-odoo-card-header">
			<h3><?php esc_html_e( 'Connection', 'gf-odoo-connector' ); ?></h3>
		</div>
		<div class="gf-odoo-card-body">
			<div class="gf-odoo-status gf-odoo-status-<?php echo esc_attr( $connection_status_class ); ?>">
				<span class="gf-odoo-status-dot" aria-hidden="true"></span>
				<span><?php echo esc_html( $connection['status_label'] ); ?></span>
			</div>
			<?php if ( '' !== $connection['odoo_url'] ) : ?>
				<p class="gf-odoo-hint gf-odoo-mt-md">
					<strong><?php esc_html_e( 'Odoo URL:', 'gf-odoo-connector' ); ?></strong>
					<?php echo esc_html( $connection['odoo_url'] ); ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $connection['last_success'] ) : ?>
				<p class="gf-odoo-hint">
					<strong><?php esc_html_e( 'Last successful sync:', 'gf-odoo-connector' ); ?></strong>
					<?php echo esc_html( $connection['last_success'] ); ?>
				</p>
			<?php endif; ?>
			<p class="gf-odoo-page-actions gf-odoo-mb-0">
				<button type="button" class="gf-odoo-btn gf-odoo-btn-secondary" id="gf-odoo-test-connection"><?php esc_html_e( 'Test connection', 'gf-odoo-connector' ); ?></button>
				<span id="gf-odoo-test-connection-result" class="gf-odoo-test-result" role="status" aria-live="polite"></span>
			</p>
		</div>
	</div>

	<div class="gf-odoo-card">
		<div class="gf-odoo-card-header">
			<h3><?php esc_html_e( 'Recent errors', 'gf-odoo-connector' ); ?></h3>
		</div>
		<div class="gf-odoo-card-body">
			<?php if ( empty( $errors ) ) : ?>
				<p class="gf-odoo-hint"><?php esc_html_e( 'No unresolved errors.', 'gf-odoo-connector' ); ?></p>
			<?php else : ?>
				<table class="gf-odoo-table gf-odoo-dashboard-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Form', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Entry', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Error', 'gf-odoo-connector' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $errors as $row ) : ?>
							<?php
							$error_id  = (int) ( $row['id'] ?? 0 );
							$form_id   = (int) ( $row['form_id'] ?? 0 );
							$entry_id  = (int) ( $row['entry_id'] ?? 0 );
							$message   = (string) ( $row['error_message'] ?? '' );
							$truncated = strlen( $message ) > 80 ? substr( $message, 0, 77 ) . '...' : $message;
							$entry_url = $form_id > 0 && $entry_id > 0
								? admin_url( sprintf( 'admin.php?page=gf_entries&view=entry&id=%d&lid=%d', $form_id, $entry_id ) )
								: '';
							?>
							<tr>
								<td><?php echo esc_html( human_time_diff( strtotime( (string) ( $row['created_at'] ?? '' ) ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'gf-odoo-connector' ); ?></td>
								<td><?php echo esc_html( $addon->get_form_title_for_dashboard( $form_id ) ); ?></td>
								<td>
									<?php if ( $entry_url ) : ?>
										<a href="<?php echo esc_url( $entry_url ); ?>">#<?php echo esc_html( (string) $entry_id ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td title="<?php echo esc_attr( $message ); ?>"><?php echo esc_html( $truncated ); ?></td>
								<td class="actions">
									<button type="button" class="gf-odoo-btn gf-odoo-btn-sm gf-odoo-btn-ghost gf-odoo-retry-error" data-error-id="<?php echo esc_attr( (string) $error_id ); ?>"><?php esc_html_e( 'Retry', 'gf-odoo-connector' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="gf-odoo-mt-md gf-odoo-mb-0"><a href="<?php echo esc_url( $error_log_url ); ?>"><?php esc_html_e( 'View all errors →', 'gf-odoo-connector' ); ?></a></p>
		</div>
	</div>

	<div class="gf-odoo-card">
		<div class="gf-odoo-card-header">
			<h3><?php esc_html_e( 'Recent successful syncs', 'gf-odoo-connector' ); ?></h3>
		</div>
		<div class="gf-odoo-card-body">
			<?php if ( empty( $successes ) ) : ?>
				<p class="gf-odoo-hint"><?php esc_html_e( 'No successful syncs yet.', 'gf-odoo-connector' ); ?></p>
			<?php else : ?>
				<table class="gf-odoo-table gf-odoo-dashboard-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Form', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Entry', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Module', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Odoo record', 'gf-odoo-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $successes as $row ) : ?>
							<?php
							$form_id   = (int) ( $row['form_id'] ?? 0 );
							$entry_id  = (int) ( $row['entry_id'] ?? 0 );
							$module    = (string) ( $row['module'] ?? '' );
							$lead_id   = (int) ( $row['lead_id'] ?? 0 );
							$ticket_id = (int) ( $row['ticket_id'] ?? 0 );
							$sync_at   = (string) ( $row['sync_at'] ?? '' );
							$entry_url = $form_id > 0 && $entry_id > 0
								? admin_url( sprintf( 'admin.php?page=gf_entries&view=entry&id=%d&lid=%d', $form_id, $entry_id ) )
								: '';
							$odoo_link = $addon->get_dashboard_odoo_record_link( $module, $lead_id, $ticket_id );
							$module_label = 'helpdesk' === $module
								? esc_html__( 'Helpdesk', 'gf-odoo-connector' )
								: ( 'crm' === $module ? esc_html__( 'CRM', 'gf-odoo-connector' ) : esc_html( $module ) );
							$badge_class = 'helpdesk' === $module ? 'badge-helpdesk' : 'badge-crm';
							?>
							<tr>
								<td>
									<?php
									if ( '' !== $sync_at ) {
										echo esc_html( human_time_diff( strtotime( $sync_at ), current_time( 'timestamp' ) ) );
										esc_html_e( ' ago', 'gf-odoo-connector' );
									} else {
										echo '—';
									}
									?>
								</td>
								<td><?php echo esc_html( $addon->get_form_title_for_dashboard( $form_id ) ); ?></td>
								<td>
									<?php if ( $entry_url ) : ?>
										<a href="<?php echo esc_url( $entry_url ); ?>">#<?php echo esc_html( (string) $entry_id ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><span class="gf-odoo-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $module_label ); ?></span></td>
								<td>
									<?php if ( $odoo_link ) : ?>
										<a href="<?php echo esc_url( $odoo_link['url'] ); ?>" target="_blank" rel="noopener noreferrer">#<?php echo esc_html( (string) $odoo_link['id'] ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
