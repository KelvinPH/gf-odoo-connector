<?php
/**
 * Odoo ticket.category reference map (InBody Europe).
 *
 * GF dropdown labels resolve to hex refs from your Odoo export (e.g. 3eb468 for Web/app).
 * At sync time the helpdesk handler looks up the matching ticket.category res_id
 * in Odoo via ir.model.data (ticket_category_12_f43eb468, …).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static ticket category hex ref resolver.
 */
class GF_Odoo_Ticket_Category_Map {

	/**
	 * @var array<int, array{key: string, label: string, hex_ref: string, xml_suffix: string}>
	 */
	private static array $categories = array(
		array(
			'key'         => 'web/app',
			'label'       => 'Web/app',
			'hex_ref'     => '3eb468',
			'xml_suffix'  => 'f43eb468',
		),
		array(
			'key'         => 'malfunction',
			'label'       => 'Malfunction',
			'hex_ref'     => 'fed802',
			'xml_suffix'  => '2dfed802',
		),
		array(
			'key'         => 'general enquiry',
			'label'       => 'General Enquiry',
			'hex_ref'     => '68e187',
			'xml_suffix'  => '3e68e187',
		),
		array(
			'key'         => 'integration',
			'label'       => 'Integration',
			'hex_ref'     => '8573ff',
			'xml_suffix'  => 'd58573ff',
		),
		array(
			'key'         => 'results',
			'label'       => 'Results',
			'hex_ref'     => 'e8503a',
			'xml_suffix'  => '74e8503a',
		),
	);

	/**
	 * Resolve a GF value to the Odoo hex ref (what your export uses as id).
	 *
	 * @param string $input Raw value from the GF entry.
	 *
	 * @return string|null Hex ref (e.g. 3eb468), or null when unresolved.
	 */
	public static function resolve( string $input ): ?string {
		$raw = trim( $input );

		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '/^[a-f0-9]{6}$/i', $raw ) ) {
			$hex = strtolower( $raw );

			return self::is_known_hex_ref( $hex ) ? $hex : null;
		}

		if ( preg_match( '/^[a-f0-9]{8}$/i', $raw ) ) {
			$hex = self::hex_ref_for_xml_suffix( strtolower( $raw ) );

			return null !== $hex ? $hex : null;
		}

		$key = strtolower( preg_replace( '/\s+/', ' ', $raw ) );

		foreach ( self::$categories as $row ) {
			if ( $row['key'] === $key || strcasecmp( $row['label'], $raw ) === 0 ) {
				return $row['hex_ref'];
			}
		}

		return null;
	}

	/**
	 * All lookup patterns for ir.model.data (longest / most specific first).
	 *
	 * @param string $hex_ref Short hex ref from export.
	 *
	 * @return array<int, string>
	 */
	public static function xml_lookup_patterns( string $hex_ref ): array {
		$hex_ref = strtolower( trim( $hex_ref ) );
		$patterns = array();

		foreach ( self::$categories as $row ) {
			if ( $row['hex_ref'] !== $hex_ref ) {
				continue;
			}

			$patterns[] = 'ticket_category_%' . $row['xml_suffix'];
			$patterns[] = '%' . $row['xml_suffix'];
			$patterns[] = '%' . $row['hex_ref'];
			break;
		}

		if ( empty( $patterns ) ) {
			$patterns[] = '%' . $hex_ref;
		}

		return array_values( array_unique( $patterns ) );
	}

	/**
	 * @param string $hex_ref Hex ref.
	 *
	 * @return bool
	 */
	public static function is_known_hex_ref( string $hex_ref ): bool {
		$hex_ref = strtolower( trim( $hex_ref ) );

		foreach ( self::$categories as $row ) {
			if ( $row['hex_ref'] === $hex_ref ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * GF-compatible choices for building a category dropdown.
	 *
	 * @return array<int, array{value: string, label: string, category_ref: string}>
	 */
	public static function get_all(): array {
		$choices = array();

		foreach ( self::$categories as $row ) {
			$choices[] = array(
				'value'        => $row['label'],
				'label'        => $row['label'],
				'category_ref' => $row['hex_ref'],
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
	 * @return array<string, string> Label key => hex ref.
	 */
	public static function get_map(): array {
		$map = array();

		foreach ( self::$categories as $row ) {
			$map[ $row['key'] ] = $row['hex_ref'];
		}

		return $map;
	}

	/**
	 * Human label for a hex ref or xml suffix.
	 *
	 * @param string $ref Hex ref, xml suffix, or label key.
	 *
	 * @return string|null
	 */
	public static function label_for_slug( string $ref ): ?string {
		$ref = strtolower( trim( $ref ) );

		foreach ( self::$categories as $row ) {
			if ( $row['hex_ref'] === $ref
				|| $row['xml_suffix'] === $ref
				|| $row['key'] === $ref
				|| strcasecmp( $row['label'], $ref ) === 0 ) {
				return $row['label'];
			}
		}

		return null;
	}

	/**
	 * @param string $suffix Full xml hash suffix (e.g. f43eb468).
	 *
	 * @return string|null Short hex ref.
	 */
	private static function hex_ref_for_xml_suffix( string $suffix ): ?string {
		foreach ( self::$categories as $row ) {
			if ( $row['xml_suffix'] === $suffix ) {
				return $row['hex_ref'];
			}
		}

		return null;
	}
}
