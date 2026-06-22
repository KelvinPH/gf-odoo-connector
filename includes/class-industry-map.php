<?php
/**
 * Odoo Industry ID Map
 * Generated from Industry__res_partner_industry_.xlsx
 * Model: res.partner.industry
 * 9 industries total
 *
 * Usage:
 *   $id = GF_Odoo_Industry_Map::resolve( 'Medical' );          // 33
 *   $id = GF_Odoo_Industry_Map::resolve( 'fitness & sports' ); // 35 (case-insensitive)
 *   $all = GF_Odoo_Industry_Map::get_all();                    // array for GF dropdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_Odoo_Industry_Map {

    /**
     * Industry name (lowercase) => Odoo ID
     */
    private static array $map = [
        'education & research' => 34,
        'enterprise'           => 38,
        'fitness & sports'     => 35,
        'government & public'  => 37,
        'medical'              => 33,
        'odm'                  => 40,
        'online'               => 41,
        'professional sports'  => 36,
        'wellness & beauty'    => 39,
    ];

    /**
     * Resolve a GF form field value to an Odoo industry ID.
     *
     * Case-insensitive match against the static industry map (res.partner.industry).
     * Returns null if no match found — the field will be skipped silently.
     *
     * @param string $input Raw value from the GF entry (e.g. "Medical", "Fitness & Sports").
     *
     * @return int|null Odoo industry ID, or null if unresolved.
     *
     * @example GF_Odoo_Industry_Map::resolve( 'Medical' );          // 33
     * @example GF_Odoo_Industry_Map::resolve( 'fitness & sports' ); // 35
     * @example GF_Odoo_Industry_Map::resolve( 'Unknown' );        // null
     */
    public static function resolve( string $input ): ?int {
        $key = strtolower( trim( $input ) );
        return self::$map[ $key ] ?? null;
    }

    /**
     * Get all industries as a GF-compatible choices array.
     * Use this to build the GF dropdown programmatically if needed.
     *
     * @return array [ ['value' => 'Medical', 'label' => 'Medical'], ... ]
     */
    public static function get_all(): array {
        $choices = [];
        foreach ( self::$map as $name => $id ) {
            // Capitalize words for display
            $label = ucwords( $name );
            $choices[] = [
                'value' => $label,
                'label' => $label,
                'odoo_id' => $id,
            ];
        }
        // Sort alphabetically
        usort( $choices, fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );
        return $choices;
    }

    /**
     * Get the full map for debugging.
     *
     * @return array
     */
    public static function get_map(): array {
        return self::$map;
    }
}
