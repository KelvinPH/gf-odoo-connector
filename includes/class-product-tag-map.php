<?php
/**
 * Odoo helpdesk.tag reference map for InBody product / device models.
 *
 * GF dropdown labels (e.g. "BPBIO 320") resolve to tag slugs such as tag_24
 * (from export xml ids like helpdesk_tag_24). Those slugs are what we send to
 * Odoo; the helpdesk handler resolves them to helpdesk.tag records for tag_ids.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static product model → helpdesk.tag slug resolver.
 */
class GF_Odoo_Product_Tag_Map {

	/**
	 * Device name (lowercase) => tag slug (e.g. tag_24).
	 *
	 * @var array<string, string>
	 */
	private static array $map = array(
		'bpbio 320'      => 'tag_24',
		'bpbio 750'      => 'tag_25',
		'bsm 170'        => 'tag_26',
		'bsm 370'        => 'tag_27',
		'inbody app'     => 'tag_28',
		'inbody 120'     => 'tag_29',
		'inbody 230'     => 'tag_30',
		'inbody 270'     => 'tag_31',
		'inbody 370'     => 'tag_32',
		'inbody 370s'    => 'tag_33',
		'inbody 380'     => 'tag_34',
		'inbody 570'     => 'tag_35',
		'inbody 580'     => 'tag_36',
		'inbody 720'     => 'tag_37',
		'inbody 770'     => 'tag_38',
		'inbody 970'     => 'tag_39',
		'inbody band2'   => 'tag_40',
		'inbody bwa'     => 'tag_41',
		'inbody dial'    => 'tag_42',
		'inbody s10'     => 'tag_43',
		'lookinbody web' => 'tag_44',
		'lookinbody120'  => 'tag_45',
		'older models'   => 'tag_46',
	);

	/**
	 * Resolve a GF dropdown value to a tag slug (tag_24, tag_25, …).
	 *
	 * Accepts, in order:
	 * - An existing slug: tag_24
	 * - A bare numeric id: 24 → tag_24
	 * - A device label: BPBIO 320 → tag_24
	 *
	 * Returns null when the label is unknown; callers may pass the raw string
	 * through to Odoo for a live helpdesk.tag name lookup.
	 *
	 * @param string $input Raw value from the GF entry.
	 *
	 * @return string|null Tag slug, or null when unresolved.
	 */
	public static function resolve( string $input ): ?string {
		$raw = trim( $input );

		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '/^tag_(\d+)$/i', $raw, $m ) ) {
			return 'tag_' . (int) $m[1];
		}

		if ( is_numeric( $raw ) ) {
			$id = (int) $raw;

			return $id > 0 ? 'tag_' . $id : null;
		}

		$key = strtolower( preg_replace( '/\s+/', ' ', $raw ) );

		return self::$map[ $key ] ?? null;
	}

	/**
	 * GF-compatible choices for building a product-model dropdown.
	 *
	 * @return array<int, array{value: string, label: string, tag_ref: string}>
	 */
	public static function get_all(): array {
		$choices = array();

		foreach ( self::$map as $name => $tag_ref ) {
			$label     = self::display_label( $name );
			$choices[] = array(
				'value'   => $label,
				'label'   => $label,
				'tag_ref' => $tag_ref,
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
	 * Turn a map key into the canonical display label.
	 *
	 * @param string $key Lowercase map key.
	 *
	 * @return string
	 */
	private static function display_label( string $key ): string {
		$special = array(
			'inbody app'     => 'InBody App',
			'inbody band2'   => 'InBody Band2',
			'inbody bwa'     => 'InBody BWA',
			'inbody dial'    => 'InBody Dial',
			'inbody s10'     => 'InBody S10',
			'lookinbody web' => 'LookinBody Web',
			'lookinbody120'  => 'LookinBody120',
			'older models'   => 'Older models',
		);

		if ( isset( $special[ $key ] ) ) {
			return $special[ $key ];
		}

		return ucwords( $key );
	}
}
