<?php
/**
 * Build a structured HTML overview table for helpdesk Issue Description.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders ticket field values as an HTML table for Odoo html fields.
 */
class GF_Odoo_Helpdesk_Description_Builder {

	/**
	 * Build the issue description body (HTML table).
	 *
	 * @param string                             $subject Ticket subject (name).
	 * @param array<int, array{label:string,value:string}> $rows    Label/value pairs.
	 *
	 * @return string
	 */
	public static function build( string $subject, array $rows ): string {
		$subject = trim( $subject );

		$table_rows = array();

		if ( '' !== $subject ) {
			$table_rows[] = array(
				'label' => esc_html__( 'Subject', 'gf-odoo-connector' ),
				'value' => $subject,
			);
		}

		foreach ( $rows as $row ) {
			$label = trim( (string) ( $row['label'] ?? '' ) );
			$value = trim( (string) ( $row['value'] ?? '' ) );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$table_rows[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		if ( empty( $table_rows ) ) {
			return '';
		}

		$html = '<table style="border-collapse:collapse;width:100%;max-width:720px;" border="1" cellpadding="8" cellspacing="0">';
		$html .= '<tbody>';

		foreach ( $table_rows as $row ) {
			$html .= '<tr>';
			$html .= '<th style="text-align:left;background:#f5f5f5;width:32%;vertical-align:top;">'
				. esc_html( $row['label'] ) . '</th>';
			$html .= '<td style="vertical-align:top;">' . self::format_cell_value( $row['value'] ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * @param string $value Cell content.
	 *
	 * @return string Safe HTML.
	 */
	private static function format_cell_value( string $value ): string {
		if ( $value !== wp_strip_all_tags( $value ) ) {
			return wp_kses_post( $value );
		}

		return nl2br( esc_html( $value ) );
	}
}
