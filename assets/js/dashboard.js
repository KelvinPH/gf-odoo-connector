( function ( $ ) {
	'use strict';

	if ( typeof gfOdooDashboard === 'undefined' ) {
		return;
	}

	function loadChart() {
		var $canvas = $( '#gf-odoo-sync-chart' );

		if ( ! $canvas.length || typeof Chart === 'undefined' ) {
			return;
		}

		$.post( gfOdooDashboard.ajaxUrl, {
			action: 'gf_odoo_chart_data',
			nonce: gfOdooDashboard.chartNonce,
		} )
			.done( function ( response ) {
				if ( ! response.success || ! response.data ) {
					return;
				}

				var data = response.data;

				new Chart( $canvas[0], {
					type: 'bar',
					data: {
						labels: data.labels || [],
						datasets: [
							{
								label: gfOdooDashboard.successLabel || 'Success',
								data: data.success || [],
								backgroundColor: 'rgba(0, 128, 0, 0.65)',
							},
							{
								label: gfOdooDashboard.failedLabel || 'Failed',
								data: data.failed || [],
								backgroundColor: 'rgba(214, 54, 56, 0.65)',
							},
						],
					},
					options: {
						responsive: true,
						maintainAspectRatio: true,
						scales: {
							x: {
								stacked: false,
							},
							y: {
								beginAtZero: true,
								ticks: {
									precision: 0,
								},
							},
						},
					},
				} );
			} );
	}

	loadChart();
}( jQuery ) );
