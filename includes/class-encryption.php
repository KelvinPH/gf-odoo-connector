<?php
/**
 * AES-256-CBC encryption for sensitive plugin options (API key).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts and decrypts values using WordPress AUTH_KEY.
 */
class GF_Odoo_Encryption {

	/**
	 * Whether OpenSSL encryption is available.
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Whether a stored value looks like an encrypted payload from encrypt().
	 *
	 * @param string $stored Stored option value.
	 */
	public static function is_encrypted( string $stored ): bool {
		if ( '' === $stored ) {
			return false;
		}

		$decoded = base64_decode( $stored, true );

		return false !== $decoded && str_contains( $decoded, '::' );
	}

	/**
	 * Encrypt a value using AES-256-CBC with WordPress AUTH_KEY as the key.
	 *
	 * @param string $value Plaintext.
	 *
	 * @return string Base64-encoded iv::ciphertext, or empty string on failure.
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! self::is_available() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[GF Odoo Connector] Encryption failed: OpenSSL extension is not available.' );
			return '';
		}

		$key    = self::get_key();
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );

		if ( false === $cipher ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[GF Odoo Connector] Encryption failed' );
			return '';
		}

		return base64_encode( $iv . '::' . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value previously encrypted with encrypt().
	 *
	 * Legacy plaintext values are returned as-is so they can be re-encrypted on save.
	 *
	 * @param string $stored Stored value.
	 *
	 * @return string Plaintext, or empty string on failure.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		if ( ! self::is_encrypted( $stored ) ) {
			return $stored;
		}

		if ( ! self::is_available() ) {
			return '';
		}

		$decoded = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return '';
		}

		$parts = explode( '::', $decoded, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		[ $iv, $cipher ] = $parts;
		$key             = self::get_key();
		$decrypted       = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * @return string 32-byte key derived from AUTH_KEY.
	 */
	private static function get_key(): string {
		$raw = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'fallback-key-change-this';

		return substr( hash( 'sha256', $raw, true ), 0, 32 );
	}
}
