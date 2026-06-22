<?php
/**
 * About & License page view.
 *
 * @package GF_Odoo_Connector
 * @var string $logo_url Logo URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="gf-odoo-page-desc">
	<?php esc_html_e( 'Plugin information, requirements, and license.', 'gf-odoo-connector' ); ?>
</p>

<div class="gf-odoo-card gf-odoo-about-intro-card">
	<div class="gf-odoo-card-body gf-odoo-about-intro">
		<?php if ( '' !== $logo_url ) : ?>
			<img class="gf-odoo-about-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="">
		<?php endif; ?>
		<div>
			<p class="gf-odoo-about-lead">
				<?php esc_html_e( 'Syncs Gravity Forms submissions to Odoo—CRM leads and contacts, or Helpdesk tickets—using per-feed field mapping, templates, and background jobs.', 'gf-odoo-connector' ); ?>
			</p>
			<p class="gf-odoo-hint gf-odoo-mb-0">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: plugin version number */
						__( 'Version %s', 'gf-odoo-connector' ),
						GF_ODOO_VERSION
					)
				);
				?>
			</p>
		</div>
	</div>
</div>

<div class="gf-odoo-card">
	<div class="gf-odoo-card-header">
		<h3><?php esc_html_e( 'Details', 'gf-odoo-connector' ); ?></h3>
	</div>
	<div class="gf-odoo-card-body gf-odoo-card-body--flush">
		<table class="gf-odoo-about-table widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Author', 'gf-odoo-connector' ); ?></th>
					<td>
						<a href="https://github.com/KelvinPH/gf-odoo-connector" target="_blank" rel="noopener noreferrer">Kelvin Huurman</a>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Requires', 'gf-odoo-connector' ); ?></th>
					<td><?php esc_html_e( 'Gravity Forms 2.5+, PHP 8.0+, Odoo 16+', 'gf-odoo-connector' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'License', 'gf-odoo-connector' ); ?></th>
					<td>
						<a href="https://www.gnu.org/licenses/gpl-2.0.html" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'GPL v2 or later', 'gf-odoo-connector' ); ?></a>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Source', 'gf-odoo-connector' ); ?></th>
					<td>
						<a href="https://github.com/KelvinPH/gf-odoo-connector" target="_blank" rel="noopener noreferrer">github.com/KelvinPH/gf-odoo-connector</a>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</div>

<div class="gf-odoo-card">
	<div class="gf-odoo-card-header">
		<h3><?php esc_html_e( 'Main features', 'gf-odoo-connector' ); ?></h3>
	</div>
	<div class="gf-odoo-card-body">
		<ul class="gf-odoo-about-list">
			<li><?php esc_html_e( 'CRM and Helpdesk feeds with auto, mapped, fixed, or disabled fields per Odoo attribute', 'gf-odoo-connector' ); ?></li>
			<li><?php esc_html_e( 'Reusable feed templates and optional per-form overrides', 'gf-odoo-connector' ); ?></li>
			<li><?php esc_html_e( 'Queued sync with retries, error log, and admin dashboard', 'gf-odoo-connector' ); ?></li>
			<li><?php esc_html_e( 'Inbound webhook for ticket/lead updates from Odoo', 'gf-odoo-connector' ); ?></li>
			<li><?php esc_html_e( 'Static country and industry maps (no extra Odoo lookups for those)', 'gf-odoo-connector' ); ?></li>
		</ul>
	</div>
</div>

<div class="gf-odoo-card">
	<div class="gf-odoo-card-header">
		<h3><?php esc_html_e( 'License', 'gf-odoo-connector' ); ?></h3>
	</div>
	<div class="gf-odoo-card-body">
		<p class="gf-odoo-about-license">
			<?php esc_html_e( 'This plugin is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or any later version.', 'gf-odoo-connector' ); ?>
		</p>
		<p class="gf-odoo-hint gf-odoo-mb-0">
			<?php esc_html_e( 'Distributed without warranty. See the license for full terms.', 'gf-odoo-connector' ); ?>
		</p>
	</div>
</div>
