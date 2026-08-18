<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_WC_Downloads — WooCommerce downloadable products integration.
 *
 * Verifies and updates the "file" URL on every downloadable product/variation
 * and marks the matching bucket object(s) private. Inert when WooCommerce is
 * not active.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_WC_Downloads {

    const PUBLIC_ACL_WARNING = 'ตั้ง ACL แล้วแต่ไฟล์ยังเข้าถึงแบบ public ได้อยู่ — storage นี้อาจไม่รองรับ object ACL (เช่น MinIO, Cloudflare R2) ต้องปิด public access ที่ระดับ bucket policy เอง';

    /**
     * Product/variation IDs with at least one downloadable file, not yet
     * scanned in this run. Same cursor shape as ISXM_Tools::get_pending_ids()
     * so the AJAX batch loop can page through it the same way.
     *
     * @return array{ids:int[], last_id:int, done:bool}
     */
    public static function get_pending_ids( $limit, $after_id = 0 ) {
        global $wpdb;

        // DISTINCT to match count_pending()'s COUNT(DISTINCT p.ID): a product
        // carrying a duplicate `_downloadable` meta row (imports and older
        // Woo versions both produce them) would otherwise be returned twice
        // here and counted twice against a total that counts it once.
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_downloadable'
             WHERE p.post_type IN ('product','product_variation') AND p.ID > %d AND m.meta_value = 'yes'
             ORDER BY p.ID ASC LIMIT %d",
            $after_id,
            $limit
        ) );

        $ids     = array_map( 'intval', $rows );
        $last_id = ! empty( $ids ) ? end( $ids ) : $after_id;

        return [
            'ids'     => $ids,
            'last_id' => $last_id,
            'done'    => count( $ids ) < $limit,
        ];
    }

    /**
     * Total number of downloadable products/variations the tool will scan —
     * the ETA/progress denominator for the WooCommerce Downloadable Products
     * tool. Matches get_pending_ids()'s selection (same _downloadable = 'yes'
     * filter), just counted instead of paged.
     *
     * @return int
     */
    public static function count_pending() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_downloadable'
             WHERE p.post_type IN ('product','product_variation') AND m.meta_value = 'yes'"
        );
    }

    /**
     * Process one product/variation: mark its offloaded download files
     * private in the bucket, and fix a stale stored URL if needed.
     *
     * @param int $product_id Product or product_variation post ID.
     * @return true|WP_Error True (nothing to report), or WP_Error whose
     *                       message lists skip/failure notes (non-fatal —
     *                       the caller logs it as an item note, not a hard stop).
     */
    public static function process_product( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'isxs_wc_no_product', 'ไม่พบสินค้า' );
        }

        $downloads = $product->get_downloads();
        if ( empty( $downloads ) ) {
            return true;
        }

        $client        = new ISXM_Client();
        $base_url      = ISXM_Settings::public_base_url();
        $current_bucket = ISXM_Settings::get( 'bucket' );
        $notes         = [];
        $changed       = false;

        foreach ( $downloads as $download ) {
            $url = $download->get_file();
            if ( $url === '' ) {
                continue;
            }

            // Require the path separator, not just the prefix: without it
            // "https://bucket.example.com-something/x" would be treated as
            // living in "https://bucket.example.com".
            if ( strpos( $url, untrailingslashit( $base_url ) . '/' ) === 0 ) {
                $key    = self::url_to_key( $url, $base_url );
                $result = $client->set_object_acl( $key, 'private' );
                if ( is_wp_error( $result ) ) {
                    $notes[] = $download->get_name() . ': ' . $result->get_error_message();
                } elseif ( self::remote_publicly_readable( $url ) ) {
                    $notes[] = $download->get_name() . ': ' . self::PUBLIC_ACL_WARNING;
                }
                continue;
            }

            $attachment_id = attachment_url_to_postid( $url );
            if ( ! $attachment_id ) {
                $notes[] = $download->get_name() . ': ไม่ใช่ media attachment ของเว็บนี้ — ข้าม';
                continue;
            }

            $info = ISXM_Offload::get_record( $attachment_id );
            if ( ! is_array( $info ) || empty( $info['files'] ) || $info['bucket'] !== $current_bucket ) {
                $notes[] = $download->get_name() . ': ยังไม่ได้ offload ไปยัง bucket ปัจจุบัน — offload ก่อนแล้วค่อยรันเครื่องมือนี้';
                continue;
            }

            $acl_ok = true;
            foreach ( $info['files'] as $filename ) {
                $key    = $info['base_key'] . $filename;
                $result = $client->set_object_acl( $key, 'private' );
                if ( is_wp_error( $result ) ) {
                    $acl_ok  = false;
                    $notes[] = $download->get_name() . ' (' . $filename . '): ' . $result->get_error_message();
                }
            }

            $filename    = wp_basename( $url );
            $correct_url = ISXM_Offload::build_remote_url( $info, $filename );

            // The provider may accept the ACL request (HTTP 200) yet ignore
            // it entirely — MinIO does exactly that, R2 has no object ACLs.
            // A 200 alone must not be reported as "now private": probe the
            // file anonymously and warn when it's still world-readable.
            if ( $acl_ok && self::remote_publicly_readable( $correct_url ) ) {
                $notes[] = $download->get_name() . ': ' . self::PUBLIC_ACL_WARNING;
            }

            if ( $correct_url !== $url ) {
                $download->set_file( $correct_url );
                $changed = true;
            }
        }

        if ( $changed ) {
            // WooCommerce validates download URLs against its Approved
            // Download Directories list on save and THROWS when the new
            // bucket URL isn't approved (default WC behaviour) — register
            // the bucket base URL first, and never let the exception
            // escape into a fatal mid-batch.
            self::ensure_approved_directory( $base_url );
            try {
                $product->set_downloads( $downloads );
                $product->save();
            } catch ( \Exception $e ) {
                $notes[] = 'อัปเดต URL ของไฟล์ดาวน์โหลดไม่สำเร็จ: ' . wp_strip_all_tags( $e->getMessage() );
            }
        }

        if ( ! empty( $notes ) ) {
            return new WP_Error( 'isxs_wc_notes', implode( '; ', $notes ) );
        }
        return true;
    }

    /**
     * Reverse of ISXM_Offload::build_remote_url() — recover the object key
     * from a public URL that already points at the current bucket.
     */
    private static function url_to_key( $url, $base_url ) {
        $path = ltrim( substr( $url, strlen( untrailingslashit( $base_url ) ) ), '/' );
        return implode( '/', array_map( 'rawurldecode', explode( '/', $path ) ) );
    }

    /**
     * Whether a bucket object is still fetchable without credentials —
     * the ground truth for "is this download actually private now",
     * independent of what the ACL request claimed.
     *
     * The answer is a property of the STORAGE, not of the file: either the
     * provider honours object ACLs or it doesn't (MinIO ignores them, R2 has
     * none at all). So it is probed once per request and reused. Probing per
     * file meant one blocking HTTP round trip per download per product,
     * which on its own could consume the batch's entire 15s time budget.
     *
     * @var bool|null true = still public, false = ACL took effect, null = not probed yet.
     */
    private static $acl_ineffective = null;

    /**
     * @param string $url Public URL of an object whose ACL was just set to private.
     * @return bool True when the object is still world-readable.
     */
    private static function remote_publicly_readable( $url ) {
        if ( self::$acl_ineffective !== null ) {
            return self::$acl_ineffective;
        }

        $response = wp_remote_head( $url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) ) {
            // Unreachable anonymously — treat as not public. Deliberately NOT
            // memoized: a transient network failure must not silently suppress
            // the warning for every remaining file in the run.
            return false;
        }

        self::$acl_ineffective = ( wp_remote_retrieve_response_code( $response ) === 200 );
        return self::$acl_ineffective;
    }

    /**
     * Register the bucket's public base URL in WooCommerce's Approved
     * Download Directories so $product->save() accepts the rewritten file
     * URL. Best effort — enforcement may be disabled, or the internal API
     * may change shape; failing here just means save() reports the problem
     * as an item note instead.
     */
    private static function ensure_approved_directory( $base_url ) {
        if ( ! function_exists( 'wc_get_container' )
            || ! class_exists( '\Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register' ) ) {
            return;
        }
        try {
            $register = wc_get_container()->get( \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class );
            $register->add_approved_directory( trailingslashit( $base_url ), true );
        } catch ( \Exception $e ) {
            isxm_log_error( 'WC approved directory registration failed: ' . $e->getMessage() );
        }
    }
}
