<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Crypto — Encryption/decryption utility for InsightX Storage.
 *
 * Uses AES-256-CBC with random IV (ENC2 format). Same scheme as ISXF_Crypto
 * so secrets stay consistent across InsightX plugins.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Crypto {

    private static $cipher = 'AES-256-CBC';

    /**
     * Get the encryption key derived from WordPress auth salt.
     */
    private static function get_key() {
        return hash( 'sha256', wp_salt( 'auth' ), true );
    }

    /**
     * Encrypt a plain-text value using AES-256-CBC with a random IV.
     *
     * Output format: base64( 'ENC2:' . random_iv_16_bytes . ciphertext )
     *
     * @param string $plain The plain-text value to encrypt.
     * @return string The encrypted value, or the input on failure.
     */
    public static function encrypt( $plain ) {
        if ( empty( $plain ) ) return '';
        if ( ! function_exists( 'openssl_encrypt' ) ) return $plain;

        $key = self::get_key();
        $iv  = openssl_random_pseudo_bytes( 16 );

        $encrypted = openssl_encrypt( $plain, self::$cipher, $key, OPENSSL_RAW_DATA, $iv );
        if ( $encrypted === false ) return $plain;

        return base64_encode( 'ENC2:' . $iv . $encrypted );
    }

    /**
     * Decrypt a stored value. Falls back to returning the value as-is
     * if it is not encrypted.
     *
     * @param string $stored The stored (possibly encrypted) value.
     * @return string The decrypted plain-text value, or empty string on failure.
     */
    public static function decrypt( $stored ) {
        if ( empty( $stored ) ) return '';

        $decoded = base64_decode( $stored, true );
        if ( $decoded === false ) return $stored;

        if ( strpos( $decoded, 'ENC2:' ) === 0 ) {
            if ( ! function_exists( 'openssl_decrypt' ) ) return '';
            $payload    = substr( $decoded, 5 );
            $iv         = substr( $payload, 0, 16 );
            $ciphertext = substr( $payload, 16 );
            $key        = self::get_key();
            $decrypted  = openssl_decrypt( $ciphertext, self::$cipher, $key, OPENSSL_RAW_DATA, $iv );
            return ( $decrypted !== false ) ? $decrypted : '';
        }

        return $stored;
    }

    /**
     * Check if a stored value is already encrypted.
     *
     * @param string $stored The stored value to check.
     * @return bool True if encrypted, false otherwise.
     */
    public static function is_encrypted( $stored ) {
        if ( empty( $stored ) ) return false;
        $decoded = base64_decode( $stored, true );
        if ( $decoded === false ) return false;
        return ( strpos( $decoded, 'ENC2:' ) === 0 );
    }
}
