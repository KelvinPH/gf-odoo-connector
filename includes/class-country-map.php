<?php
/**
 * Odoo Country ID Map
 * Generated from Country_Master_.xlsx
 * Source columns: Country Name, Country Code, ID
 * 251 countries total
 *
 * Usage:
 *   $id = GF_Odoo_Country_Map::get_id_by_name( 'Netherlands' ); // 165
 *   $id = GF_Odoo_Country_Map::get_id_by_code( 'NL' );          // 165
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_Odoo_Country_Map {

    /**
     * Map of country name (lowercase) => [ 'id' => int, 'code' => string ]
     */
    private static array $map = [
        'afghanistan' => [ 'id' => 3, 'code' => 'AF' ],
        'albania' => [ 'id' => 6, 'code' => 'AL' ],
        'algeria' => [ 'id' => 62, 'code' => 'DZ' ],
        'american samoa' => [ 'id' => 11, 'code' => 'AS' ],
        'andorra' => [ 'id' => 1, 'code' => 'AD' ],
        'angola' => [ 'id' => 8, 'code' => 'AO' ],
        'anguilla' => [ 'id' => 5, 'code' => 'AI' ],
        'antarctica' => [ 'id' => 9, 'code' => 'AQ' ],
        'antigua and barbuda' => [ 'id' => 4, 'code' => 'AG' ],
        'argentina' => [ 'id' => 10, 'code' => 'AR' ],
        'armenia' => [ 'id' => 7, 'code' => 'AM' ],
        'aruba' => [ 'id' => 14, 'code' => 'AW' ],
        'australia' => [ 'id' => 13, 'code' => 'AU' ],
        'austria' => [ 'id' => 12, 'code' => 'AT' ],
        'azerbaijan' => [ 'id' => 16, 'code' => 'AZ' ],
        'bahamas' => [ 'id' => 32, 'code' => 'BS' ],
        'bahrain' => [ 'id' => 23, 'code' => 'BH' ],
        'bangladesh' => [ 'id' => 19, 'code' => 'BD' ],
        'barbados' => [ 'id' => 18, 'code' => 'BB' ],
        'belarus' => [ 'id' => 36, 'code' => 'BY' ],
        'belgium' => [ 'id' => 20, 'code' => 'BE' ],
        'belize' => [ 'id' => 37, 'code' => 'BZ' ],
        'benin' => [ 'id' => 25, 'code' => 'BJ' ],
        'bermuda' => [ 'id' => 27, 'code' => 'BM' ],
        'bhutan' => [ 'id' => 33, 'code' => 'BT' ],
        'bolivia' => [ 'id' => 29, 'code' => 'BO' ],
        'bonaire, sint eustatius and saba' => [ 'id' => 30, 'code' => 'BQ' ],
        'bosnia and herzegovina' => [ 'id' => 17, 'code' => 'BA' ],
        'botswana' => [ 'id' => 35, 'code' => 'BW' ],
        'bouvet island' => [ 'id' => 34, 'code' => 'BV' ],
        'brazil' => [ 'id' => 31, 'code' => 'BR' ],
        'british indian ocean territory' => [ 'id' => 105, 'code' => 'IO' ],
        'brunei darussalam' => [ 'id' => 28, 'code' => 'BN' ],
        'bulgaria' => [ 'id' => 22, 'code' => 'BG' ],
        'burkina faso' => [ 'id' => 21, 'code' => 'BF' ],
        'burundi' => [ 'id' => 24, 'code' => 'BI' ],
        'cambodia' => [ 'id' => 116, 'code' => 'KH' ],
        'cameroon' => [ 'id' => 47, 'code' => 'CM' ],
        'canada' => [ 'id' => 38, 'code' => 'CA' ],
        'cape verde' => [ 'id' => 52, 'code' => 'CV' ],
        'cayman islands' => [ 'id' => 123, 'code' => 'KY' ],
        'central african republic' => [ 'id' => 40, 'code' => 'CF' ],
        'chad' => [ 'id' => 214, 'code' => 'TD' ],
        'chile' => [ 'id' => 46, 'code' => 'CL' ],
        'china' => [ 'id' => 48, 'code' => 'CN' ],
        'christmas island' => [ 'id' => 54, 'code' => 'CX' ],
        'cocos (keeling) islands' => [ 'id' => 39, 'code' => 'CC' ],
        'colombia' => [ 'id' => 49, 'code' => 'CO' ],
        'comoros' => [ 'id' => 118, 'code' => 'KM' ],
        'congo (drc)' => [ 'id' => 41, 'code' => 'CD' ],
        'congo (republic)' => [ 'id' => 42, 'code' => 'CG' ],
        'cook islands' => [ 'id' => 45, 'code' => 'CK' ],
        'costa rica' => [ 'id' => 50, 'code' => 'CR' ],
        'croatia' => [ 'id' => 97, 'code' => 'HR' ],
        'cuba' => [ 'id' => 51, 'code' => 'CU' ],
        'curaçao' => [ 'id' => 53, 'code' => 'CW' ],
        'curacao' => [ 'id' => 53, 'code' => 'CW' ],
        'cyprus' => [ 'id' => 55, 'code' => 'CY' ],
        'czech republic' => [ 'id' => 56, 'code' => 'CZ' ],
        "côte d'ivoire" => [ 'id' => 44, 'code' => 'CI' ],
        "cote d'ivoire" => [ 'id' => 44, 'code' => 'CI' ],
        'denmark' => [ 'id' => 59, 'code' => 'DK' ],
        'djibouti' => [ 'id' => 58, 'code' => 'DJ' ],
        'dominica' => [ 'id' => 60, 'code' => 'DM' ],
        'dominican republic' => [ 'id' => 61, 'code' => 'DO' ],
        'ecuador' => [ 'id' => 63, 'code' => 'EC' ],
        'egypt' => [ 'id' => 65, 'code' => 'EG' ],
        'el salvador' => [ 'id' => 209, 'code' => 'SV' ],
        'equatorial guinea' => [ 'id' => 87, 'code' => 'GQ' ],
        'eritrea' => [ 'id' => 67, 'code' => 'ER' ],
        'estonia' => [ 'id' => 64, 'code' => 'EE' ],
        'eswatini' => [ 'id' => 212, 'code' => 'SZ' ],
        'swaziland' => [ 'id' => 212, 'code' => 'SZ' ],
        'ethiopia' => [ 'id' => 69, 'code' => 'ET' ],
        'falkland islands' => [ 'id' => 72, 'code' => 'FK' ],
        'faroe islands' => [ 'id' => 74, 'code' => 'FO' ],
        'fiji' => [ 'id' => 71, 'code' => 'FJ' ],
        'finland' => [ 'id' => 70, 'code' => 'FI' ],
        'france' => [ 'id' => 75, 'code' => 'FR' ],
        'french guiana' => [ 'id' => 79, 'code' => 'GF' ],
        'french polynesia' => [ 'id' => 174, 'code' => 'PF' ],
        'french southern territories' => [ 'id' => 215, 'code' => 'TF' ],
        'gabon' => [ 'id' => 76, 'code' => 'GA' ],
        'gambia' => [ 'id' => 84, 'code' => 'GM' ],
        'georgia' => [ 'id' => 78, 'code' => 'GE' ],
        'germany' => [ 'id' => 57, 'code' => 'DE' ],
        'ghana' => [ 'id' => 80, 'code' => 'GH' ],
        'gibraltar' => [ 'id' => 81, 'code' => 'GI' ],
        'greece' => [ 'id' => 88, 'code' => 'GR' ],
        'greenland' => [ 'id' => 83, 'code' => 'GL' ],
        'grenada' => [ 'id' => 77, 'code' => 'GD' ],
        'guadeloupe' => [ 'id' => 86, 'code' => 'GP' ],
        'guam' => [ 'id' => 91, 'code' => 'GU' ],
        'guatemala' => [ 'id' => 90, 'code' => 'GT' ],
        'guernsey' => [ 'id' => 82, 'code' => 'GG' ],
        'guinea' => [ 'id' => 85, 'code' => 'GN' ],
        'guinea-bissau' => [ 'id' => 92, 'code' => 'GW' ],
        'guyana' => [ 'id' => 93, 'code' => 'GY' ],
        'haiti' => [ 'id' => 98, 'code' => 'HT' ],
        'heard island and mcdonald islands' => [ 'id' => 95, 'code' => 'HM' ],
        'holy see (vatican city state)' => [ 'id' => 236, 'code' => 'VA' ],
        'vatican' => [ 'id' => 236, 'code' => 'VA' ],
        'honduras' => [ 'id' => 96, 'code' => 'HN' ],
        'hong kong' => [ 'id' => 99, 'code' => 'HK' ],
        'hungary' => [ 'id' => 100, 'code' => 'HU' ],
        'iceland' => [ 'id' => 109, 'code' => 'IS' ],
        'india' => [ 'id' => 104, 'code' => 'IN' ],
        'indonesia' => [ 'id' => 101, 'code' => 'ID' ],
        'iran' => [ 'id' => 103, 'code' => 'IR' ],
        'iraq' => [ 'id' => 102, 'code' => 'IQ' ],
        'ireland' => [ 'id' => 106, 'code' => 'IE' ],
        'isle of man' => [ 'id' => 108, 'code' => 'IM' ],
        'israel' => [ 'id' => 107, 'code' => 'IL' ],
        'italy' => [ 'id' => 110, 'code' => 'IT' ],
        'jamaica' => [ 'id' => 113, 'code' => 'JM' ],
        'japan' => [ 'id' => 114, 'code' => 'JP' ],
        'jersey' => [ 'id' => 112, 'code' => 'JE' ],
        'jordan' => [ 'id' => 111, 'code' => 'JO' ],
        'kazakhstan' => [ 'id' => 117, 'code' => 'KZ' ],
        'kenya' => [ 'id' => 115, 'code' => 'KE' ],
        'kiribati' => [ 'id' => 121, 'code' => 'KI' ],
        "korea (democratic people's republic of)" => [ 'id' => 119, 'code' => 'KP' ],
        'north korea' => [ 'id' => 119, 'code' => 'KP' ],
        'korea (republic of)' => [ 'id' => 120, 'code' => 'KR' ],
        'south korea' => [ 'id' => 120, 'code' => 'KR' ],
        'kuwait' => [ 'id' => 122, 'code' => 'KW' ],
        'kyrgyzstan' => [ 'id' => 124, 'code' => 'KG' ],
        "lao people's democratic republic" => [ 'id' => 125, 'code' => 'LA' ],
        'laos' => [ 'id' => 125, 'code' => 'LA' ],
        'latvia' => [ 'id' => 131, 'code' => 'LV' ],
        'lebanon' => [ 'id' => 126, 'code' => 'LB' ],
        'lesotho' => [ 'id' => 130, 'code' => 'LS' ],
        'liberia' => [ 'id' => 127, 'code' => 'LR' ],
        'libya' => [ 'id' => 132, 'code' => 'LY' ],
        'liechtenstein' => [ 'id' => 128, 'code' => 'LI' ],
        'lithuania' => [ 'id' => 129, 'code' => 'LT' ],
        'luxembourg' => [ 'id' => 133, 'code' => 'LU' ],
        'macao' => [ 'id' => 140, 'code' => 'MO' ],
        'madagascar' => [ 'id' => 137, 'code' => 'MG' ],
        'malawi' => [ 'id' => 148, 'code' => 'MW' ],
        'malaysia' => [ 'id' => 147, 'code' => 'MY' ],
        'maldives' => [ 'id' => 146, 'code' => 'MV' ],
        'mali' => [ 'id' => 141, 'code' => 'ML' ],
        'malta' => [ 'id' => 145, 'code' => 'MT' ],
        'marshall islands' => [ 'id' => 138, 'code' => 'MH' ],
        'martinique' => [ 'id' => 143, 'code' => 'MQ' ],
        'mauritania' => [ 'id' => 144, 'code' => 'MR' ],
        'mauritius' => [ 'id' => 142, 'code' => 'MU' ],
        'mayotte' => [ 'id' => 149, 'code' => 'YT' ],
        'mexico' => [ 'id' => 139, 'code' => 'MX' ],
        'micronesia' => [ 'id' => 73, 'code' => 'FM' ],
        'moldova' => [ 'id' => 136, 'code' => 'MD' ],
        'monaco' => [ 'id' => 134, 'code' => 'MC' ],
        'mongolia' => [ 'id' => 135, 'code' => 'MN' ],
        'montenegro' => [ 'id' => 150, 'code' => 'ME' ],
        'montserrat' => [ 'id' => 151, 'code' => 'MS' ],
        'morocco' => [ 'id' => 152, 'code' => 'MA' ],
        'mozambique' => [ 'id' => 153, 'code' => 'MZ' ],
        'myanmar' => [ 'id' => 154, 'code' => 'MM' ],
        'burma' => [ 'id' => 154, 'code' => 'MM' ],
        'namibia' => [ 'id' => 160, 'code' => 'NA' ],
        'nauru' => [ 'id' => 164, 'code' => 'NR' ],
        'nepal' => [ 'id' => 163, 'code' => 'NP' ],
        'netherlands' => [ 'id' => 165, 'code' => 'NL' ],
        'new caledonia' => [ 'id' => 156, 'code' => 'NC' ],
        'new zealand' => [ 'id' => 167, 'code' => 'NZ' ],
        'nicaragua' => [ 'id' => 161, 'code' => 'NI' ],
        'niger' => [ 'id' => 158, 'code' => 'NE' ],
        'nigeria' => [ 'id' => 159, 'code' => 'NG' ],
        'niue' => [ 'id' => 168, 'code' => 'NU' ],
        'norfolk island' => [ 'id' => 157, 'code' => 'NF' ],
        'north macedonia' => [ 'id' => 134, 'code' => 'MK' ],
        'northern mariana islands' => [ 'id' => 162, 'code' => 'MP' ],
        'norway' => [ 'id' => 166, 'code' => 'NO' ],
        'oman' => [ 'id' => 169, 'code' => 'OM' ],
        'pakistan' => [ 'id' => 172, 'code' => 'PK' ],
        'palau' => [ 'id' => 180, 'code' => 'PW' ],
        'palestine' => [ 'id' => 171, 'code' => 'PS' ],
        'panama' => [ 'id' => 170, 'code' => 'PA' ],
        'papua new guinea' => [ 'id' => 177, 'code' => 'PG' ],
        'paraguay' => [ 'id' => 181, 'code' => 'PY' ],
        'peru' => [ 'id' => 173, 'code' => 'PE' ],
        'philippines' => [ 'id' => 175, 'code' => 'PH' ],
        'pitcairn' => [ 'id' => 176, 'code' => 'PN' ],
        'poland' => [ 'id' => 178, 'code' => 'PL' ],
        'portugal' => [ 'id' => 179, 'code' => 'PT' ],
        'puerto rico' => [ 'id' => 182, 'code' => 'PR' ],
        'qatar' => [ 'id' => 183, 'code' => 'QA' ],
        'romania' => [ 'id' => 185, 'code' => 'RO' ],
        'russia' => [ 'id' => 186, 'code' => 'RU' ],
        'russian federation' => [ 'id' => 186, 'code' => 'RU' ],
        'rwanda' => [ 'id' => 184, 'code' => 'RW' ],
        'réunion' => [ 'id' => 187, 'code' => 'RE' ],
        'reunion' => [ 'id' => 187, 'code' => 'RE' ],
        'saint barthélemy' => [ 'id' => 188, 'code' => 'BL' ],
        'saint helena' => [ 'id' => 193, 'code' => 'SH' ],
        'saint kitts and nevis' => [ 'id' => 195, 'code' => 'KN' ],
        'saint lucia' => [ 'id' => 196, 'code' => 'LC' ],
        'saint martin' => [ 'id' => 197, 'code' => 'MF' ],
        'saint pierre and miquelon' => [ 'id' => 198, 'code' => 'PM' ],
        'saint vincent and the grenadines' => [ 'id' => 241, 'code' => 'VC' ],
        'samoa' => [ 'id' => 246, 'code' => 'WS' ],
        'san marino' => [ 'id' => 199, 'code' => 'SM' ],
        'sao tome and principe' => [ 'id' => 207, 'code' => 'ST' ],
        'saudi arabia' => [ 'id' => 194, 'code' => 'SA' ],
        'senegal' => [ 'id' => 203, 'code' => 'SN' ],
        'serbia' => [ 'id' => 200, 'code' => 'RS' ],
        'seychelles' => [ 'id' => 191, 'code' => 'SC' ],
        'sierra leone' => [ 'id' => 204, 'code' => 'SL' ],
        'singapore' => [ 'id' => 201, 'code' => 'SG' ],
        'sint maarten' => [ 'id' => 202, 'code' => 'SX' ],
        'slovakia' => [ 'id' => 205, 'code' => 'SK' ],
        'slovenia' => [ 'id' => 192, 'code' => 'SI' ],
        'solomon islands' => [ 'id' => 206, 'code' => 'SB' ],
        'somalia' => [ 'id' => 208, 'code' => 'SO' ],
        'south africa' => [ 'id' => 251, 'code' => 'ZA' ],
        'south georgia' => [ 'id' => 89, 'code' => 'GS' ],
        'south sudan' => [ 'id' => 210, 'code' => 'SS' ],
        'spain' => [ 'id' => 68, 'code' => 'ES' ],
        'sri lanka' => [ 'id' => 126, 'code' => 'LK' ],
        'sudan' => [ 'id' => 211, 'code' => 'SD' ],
        'suriname' => [ 'id' => 213, 'code' => 'SR' ],
        'svalbard and jan mayen' => [ 'id' => 216, 'code' => 'SJ' ],
        'sweden' => [ 'id' => 218, 'code' => 'SE' ],
        'switzerland' => [ 'id' => 43, 'code' => 'CH' ],
        'syrian arab republic' => [ 'id' => 219, 'code' => 'SY' ],
        'syria' => [ 'id' => 219, 'code' => 'SY' ],
        'taiwan' => [ 'id' => 220, 'code' => 'TW' ],
        'tajikistan' => [ 'id' => 225, 'code' => 'TJ' ],
        'tanzania' => [ 'id' => 226, 'code' => 'TZ' ],
        'thailand' => [ 'id' => 221, 'code' => 'TH' ],
        'timor-leste' => [ 'id' => 222, 'code' => 'TL' ],
        'togo' => [ 'id' => 223, 'code' => 'TG' ],
        'tokelau' => [ 'id' => 224, 'code' => 'TK' ],
        'tonga' => [ 'id' => 227, 'code' => 'TO' ],
        'trinidad and tobago' => [ 'id' => 228, 'code' => 'TT' ],
        'tunisia' => [ 'id' => 229, 'code' => 'TN' ],
        'turkey' => [ 'id' => 230, 'code' => 'TR' ],
        'türkiye' => [ 'id' => 230, 'code' => 'TR' ],
        'turkmenistan' => [ 'id' => 232, 'code' => 'TM' ],
        'turks and caicos islands' => [ 'id' => 231, 'code' => 'TC' ],
        'tuvalu' => [ 'id' => 233, 'code' => 'TV' ],
        'uganda' => [ 'id' => 235, 'code' => 'UG' ],
        'ukraine' => [ 'id' => 234, 'code' => 'UA' ],
        'united arab emirates' => [ 'id' => 2, 'code' => 'AE' ],
        'uae' => [ 'id' => 2, 'code' => 'AE' ],
        'united kingdom' => [ 'id' => 237, 'code' => 'GB' ],
        'uk' => [ 'id' => 237, 'code' => 'GB' ],
        'great britain' => [ 'id' => 237, 'code' => 'GB' ],
        'united states' => [ 'id' => 238, 'code' => 'US' ],
        'usa' => [ 'id' => 238, 'code' => 'US' ],
        'united states minor outlying islands' => [ 'id' => 239, 'code' => 'UM' ],
        'uruguay' => [ 'id' => 240, 'code' => 'UY' ],
        'uzbekistan' => [ 'id' => 242, 'code' => 'UZ' ],
        'vanuatu' => [ 'id' => 243, 'code' => 'VU' ],
        'venezuela' => [ 'id' => 244, 'code' => 'VE' ],
        'vietnam' => [ 'id' => 245, 'code' => 'VN' ],
        'viet nam' => [ 'id' => 245, 'code' => 'VN' ],
        'virgin islands (british)' => [ 'id' => 31, 'code' => 'VG' ],
        'virgin islands (u.s.)' => [ 'id' => 247, 'code' => 'VI' ],
        'wallis and futuna' => [ 'id' => 244, 'code' => 'WF' ],
        'western sahara' => [ 'id' => 66, 'code' => 'EH' ],
        'yemen' => [ 'id' => 245, 'code' => 'YE' ],
        'zambia' => [ 'id' => 248, 'code' => 'ZM' ],
        'zimbabwe' => [ 'id' => 249, 'code' => 'ZW' ],
        'åland islands' => [ 'id' => 15, 'code' => 'AX' ],
        'aland islands' => [ 'id' => 15, 'code' => 'AX' ],
    ];

    /**
     * ISO 2-letter code => Odoo ID
     */
    private static array $code_map = [];

    /**
     * Get Odoo country ID by country name (case-insensitive).
     * Also accepts common aliases (e.g. "UK", "USA", "Turkey"/"Türkiye").
     *
     * @param string $name Country name as entered in the GF form
     * @return int|null    Odoo country ID, or null if not found
     */
    public static function get_id_by_name( string $name ): ?int {
        $key = strtolower( trim( $name ) );
        return isset( self::$map[ $key ] ) ? self::$map[ $key ]['id'] : null;
    }

    /**
     * Get Odoo country ID by ISO 2-letter country code (case-insensitive).
     *
     * @param string $code ISO 3166-1 alpha-2 code (e.g. "NL", "DE")
     * @return int|null
     */
    public static function get_id_by_code( string $code ): ?int {
        $code = strtoupper( trim( $code ) );
        if ( empty( self::$code_map ) ) {
            self::build_code_map();
        }
        return self::$code_map[ $code ] ?? null;
    }

    /**
     * Resolve a GF form field value to an Odoo country ID.
     *
     * Tries exact name match, ISO code match, then partial name match.
     * Returns null if no match found, so the field is skipped silently.
     *
     * @param string $input Raw value from the GF entry (e.g. "Netherlands", "NL").
     *
     * @return int|null Odoo res.country ID, or null if unresolved.
     *
     * @example GF_Odoo_Country_Map::resolve( 'Netherlands' ); // 165
     * @example GF_Odoo_Country_Map::resolve( 'NL' );          // 165
     * @example GF_Odoo_Country_Map::resolve( 'Unknown' );     // null
     */
    public static function resolve( string $input ): ?int {
        $input = trim( $input );
        if ( empty( $input ) ) return null;

        // 1. Exact name match (case-insensitive)
        $id = self::get_id_by_name( $input );
        if ( $id ) return $id;

        // 2. ISO code match (e.g. "NL", "DE")
        if ( strlen( $input ) === 2 ) {
            $id = self::get_id_by_code( $input );
            if ( $id ) return $id;
        }

        // 3. Partial / fuzzy: check if input is contained in a key
        $lower = strtolower( $input );
        foreach ( self::$map as $key => $data ) {
            if ( str_contains( $key, $lower ) || str_contains( $lower, $key ) ) {
                return $data['id'];
            }
        }

        return null;
    }

    private static function build_code_map(): void {
        foreach ( self::$map as $data ) {
            if ( ! empty( $data['code'] ) ) {
                self::$code_map[ $data['code'] ] = $data['id'];
            }
        }
    }
}
