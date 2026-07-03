<?php
/**
 * Odoo ticket category reference map.
 *
 * GF dropdown labels (e.g. "Malfunction") resolve to category slugs such as
 * category_13 (from export xml ids like ticket_category_13).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static ticket category slug resolver.
 */
class GF_Odoo_Ticket_Category_Map {

	/**
	 * Category name (lowercase) => slug (e.g. category_12).
	 *
	 * @var array<string, string>
	 */
	private static array $map = array(
		'web/app'          => 'category_12',
		'malfunction'      => 'category_13',
		'general enquiry'  => 'category_14',
		'integration'      => 'category_15',
		'results'          => 'category_16',
	);

	/**
	 * Resolve a GF dropdown value to a category slug (category_12, …).
	 *
	 * Accepts:
	 * - Slug: category_12
	 * - Numeric id: 12 → category_12
	 * - Label: Malfunction → category_13
	 *
	 * @param string $input Raw value from the GF entry.
	 *
	 * @return string|null Category slug, or null when unresolved.
	 */
	public static function resolve( string $input ): ?string {
		$raw = trim( $input );

		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '/^category_(\d+)$/i', $raw, $m ) ) {
			return 'category_' . (int) $m[1];
		}

		if ( is_numeric( $raw ) ) {
			$id = (int) $raw;

			return $id > 0 ? 'category_' . $id : null;
		}

		$key = strtolower( preg_replace( '/\s+/', ' ', $raw ) );

		return self::$map[ $key ] ?? null;
	}

	/**
	 * GF-compatible choices for building a category dropdown.
	 *
	 * @return array<int, array{value: string, label: string, category_ref: string}>
	 */
	public static function get_all(): array {
		$choices = array();

		foreach ( self::$map as $name => $category_ref ) {
			$label     = self::display_label( $name );
			$choices[] = array(
				'value'        => $label,
				'label'        => $label,
				'category_ref' => $category_ref,
			);
		}

		usort(
			$choices,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);

		return $choices;
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_map(): array {
		return self::$map;
	}

	/**
	 * @param string $key Lowercase map key.
	 *
	 * @return string
	 */
	private static function display_label( string $key ): string {
		$labels = array(
			'web/app'         => 'Web/app',
			'malfunction'     => 'Malfunction',
			'general enquiry' => 'General Enquiry',
			'integration'     => 'Integration',
			'results'         => 'Results',
		);

		return $labels[ $key ] ?? ucwords( $key );
	}
}
