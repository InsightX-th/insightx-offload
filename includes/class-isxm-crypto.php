<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Crypto — Encryption/decryption utility for InsightX Storage.
 *
 * New values are written as ENC3 — AES-256-GCM, authenticated encryption:
 * every value carries a 16-byte tag that rejects tampering or a wrong key
 * instead of quietly "decrypting" to garbage.
 *
 * Values written before 0.2.x use ENC2 (AES-256-CBC, no authentication
 * tag) and stay readable. They are re-encrypted on the next save of the
 * connection (a blank secret keeps the stored one as-is, so re-saving the
 * card without touching the secret preserves the ENC2 blob — harmless).
 *
 * Same key derivation as ISXF_Crypto so secrets stay consistent
 * across InsightX plugins.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Crypto {

    /** Legacy: unauthenticated, kept for reading pre-0.2.x values only. */
    private static $cipher_cbc = 'AES-256-CBC';

    /** Current: authenticated (GCM tag verifies integrity + key match). */
    private static $cipher_gcm = 'AES-256-GCM';

    /**
     * Get the encryption key derived from WordPress auth salt.
     */
    private static function get_key() {
        return hash( 'sha256', wp_salt( 'auth' ), true );
    }

    /**
     * Encrypt a plain-text value.
     *
     * Writes ENC3 (AES-256-GCM):
     *   base64( 'ENC3:' . iv_12_bytes . tag_16_bytes . ciphertext )
     *
     * Falls back to the legacy ENC2 (AES-256-CBC, unauthenticated) format
     * only when this PHP/OpenSSL build cannot produce GCM output at all —
     * still encrypted, just without a tamper-proof tag.
     *
     * @param string $plain The plain-text value to encrypt.
     * @return string The encrypted value, or the input on failure.
     */
    public static function encrypt( $plain ) {
        if ( empty( $plain ) ) return '';
        if ( ! function_exists( 'openssl_encrypt' ) ) return $plain;

        $key = self::get_key();

        // GCM: random 12-byte IV, 16-byte auth tag returned by reference.
        $iv        = openssl_random_pseudo_bytes( 12 );
        $tag       = '';
        $encrypted = openssl_encrypt( $plain, self::$cipher_gcm, $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( $encrypted !== false && strlen( $tag ) === 16 ) {
            return base64_encode( 'ENC3:' . $iv . $tag . $encrypted );
        }

        // No GCM on this build — legacy scheme rather than plain text.
        $iv        = openssl_random_pseudo_bytes( 16 );
        $encrypted = openssl_encrypt( $plain, self::$cipher_cbc, $key, OPENSSL_RAW_DATA, $iv );
        if ( $encrypted === false ) return $plain;

        return base64_encode( 'ENC2:' . $iv . $encrypted );
    }

    /**
     * Decrypt a stored value. Falls back to returning the value as-is
     * if it is not encrypted.
     *
     * Accepts ENC3 (AES-256-GCM, authenticated — a tampered tag or a key
     * change from the auth salts fails loudly, returning '') and the
     * legacy ENC2 (AES-256-CBC).
     *
     * @param string $stored The stored (possibly encrypted) value.
     * @return string The decrypted plain-text value, or empty string on failure.
     */
    public static function decrypt( $stored ) {
        if ( empty( $stored ) ) return '';

        $decoded = base64_decode( $stored, true );
        if ( $decoded === false ) return $stored;

        if ( strpos( $decoded, 'ENC3:' ) === 0 ) {
            if ( ! function_exists( 'openssl_decrypt' ) ) return '';
            $payload = substr( $decoded, 5 );
            if ( strlen( $payload ) < 28 ) return '';
            $iv         = substr( $payload, 0, 12 );
            $tag        = substr( $payload, 12, 16 );
            $ciphertext = substr( $payload, 28 );
            // GCM verifies the tag as part of decryption; any tampering or a
            // changed wp_salt('auth') makes this return false, not garbage.
            $decrypted  = openssl_decrypt( $ciphertext, self::$cipher_gcm, self::get_key(), OPENSSL_RAW_DATA, $iv, $tag );
            return ( $decrypted !== false ) ? $decrypted : '';
        }

        if ( strpos( $decoded, 'ENC2:' ) === 0 ) {
            if ( ! function_exists( 'openssl_decrypt' ) ) return '';
            $payload    = substr( $decoded, 5 );
            $iv         = substr( $payload, 0, 16 );
            $ciphertext = substr( $payload, 16 );
            $decrypted  = openssl_decrypt( $ciphertext, self::$cipher_cbc, self::get_key(), OPENSSL_RAW_DATA, $iv );
            return ( $decrypted !== false ) ? $decrypted : '';
        }

        return $stored;
    }

    /**
     * Check if a stored value is already encrypted.
     *
     * @param string $stored The stored value to check.
     * @return bool True if encrypted (either format), false otherwise.
     */
    public static function is_encrypted( $stored ) {
        if ( empty( $stored ) ) return false;
        $decoded = base64_decode( $stored, true );
        if ( $decoded === false ) return false;
        return ( strpos( $decoded, 'ENC2:' ) === 0 || strpos( $decoded, 'ENC3:' ) === 0 );
    }
}
