<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Settings — Central settings access for InsightX Storage.
 *
 * All settings live in a single option `isxs_settings`. The secret key is
 * stored encrypted (ISXM_Crypto) and decrypted transparently on read.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Settings {

    const OPTION_KEY = 'isxs_settings';

    /** @var array|null Cached settings for the current request. */
    private static $cache = null;

    /**
     * Default settings.
     */
    public static function defaults() {
        return [
            // Provider selection — the actual endpoint/region/bucket/keys
            // live in ISXM_Connections, keyed by this slug. See all().
            'provider'           => 'custom',      // aws|minio|r2|spaces|gcs|custom

            // Storage behaviour
            'offload_enabled'    => false,
            'remove_local'       => false,
            // Write the new remote URLs straight into post_content /
            // postmeta / options / guid after every offload (permanent
            // rewrite). When off, offloading only uploads files and relies
            // on the runtime rewrite filters for delivery — much faster on
            // large libraries, because the per-batch full-table LIKE scans
            // of the permanent rewrite disappear entirely.
            'persist_urls'       => true,
            'use_prefix'         => true,
            'prefix'             => 'wp-content/uploads/',
            'use_year_month'     => true,
            'use_object_version' => true,

            // Delivery
            'deliver_enabled'    => false,
            'force_https'        => true,
            'cdn_domain'         => '',            // optional custom delivery domain

            // Assets Pull — serve enqueued theme/plugin CSS/JS through a CDN
            // domain in front of the site. The files stay on the server; the
            // CDN only caches/proxies them (bucket URL is NOT used here —
            // theme/plugin files are never uploaded to the bucket).
            'assets_enabled'     => false,
            'assets_cdn_domain'  => '',            // e.g. cdn.example.com
            'assets_force_https' => true,


            // Migration source — another provider slug in ISXM_Connections
            // to pull existing media from.
            'source_provider'        => 'custom',
            'source_prefix'          => 'wp-content/uploads/',
            'source_use_year_month'  => true,
            'source_public_base_url' => '',        // URL ที่ media เดิมถูกเสิร์ฟอยู่จริงตอนนี้ (ที่ปรากฏใน DB) — เว้นว่างเพื่อเดาจาก source_endpoint/bucket
        ];
    }

    /**
     * Get all settings merged with defaults, with the destination/source
     * connection fields (endpoint/region/bucket/keys/path_style) resolved
     * from ISXM_Connections against the selected provider/source_provider.
     * Kept in the returned shape for backward compatibility with every
     * caller that already reads $settings['bucket'], ['endpoint'], etc.
     * (ISXM_Client, ISXM_Offload, ISXM_Migrate, get_stats()...).
     */
    public static function all() {
        if ( self::$cache !== null ) {
            return self::$cache;
        }
        $stored   = get_option( self::OPTION_KEY, [] );
        $settings = wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );

        $dest = ISXM_Connections::get( $settings['provider'] );
        if ( ! $dest ) {
            $dest = ISXM_Connections::get( 'custom' );
        }
        $settings['endpoint']        = $dest['endpoint'];
        $settings['region']          = $dest['region'];
        $settings['bucket']          = $dest['bucket'];
        $settings['access_key']      = $dest['access_key'];
        $settings['secret_key']      = $dest['secret_key'];
        $settings['path_style']      = $dest['path_style'];
        $settings['send_public_acl'] = $dest['send_public_acl'];

        $src = ISXM_Connections::get( $settings['source_provider'] );
        if ( ! $src ) {
            $src = ISXM_Connections::get( 'custom' );
        }
        $settings['source_endpoint']   = $src['endpoint'];
        $settings['source_region']     = $src['region'];
        $settings['source_bucket']     = $src['bucket'];
        $settings['source_access_key'] = $src['access_key'];
        $settings['source_secret_key'] = $src['secret_key'];
        $settings['source_path_style'] = $src['path_style'];

        self::$cache = $settings;
        return $settings;
    }

    /**
     * Get a single setting.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Fallback when key is unknown.
     */
    public static function get( $key, $default = null ) {
        $all = self::all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    /**
     * Persist settings (provider selection + behaviour settings only —
     * connection fields live in ISXM_Connections and are saved separately
     * via ISXM_Connections::save_one()). Resets the request cache.
     *
     * @param array $settings Full settings array.
     */
    public static function save( array $settings ) {
        $settings = wp_parse_args( $settings, self::defaults() );
        unset(
            $settings['endpoint'], $settings['region'], $settings['bucket'], $settings['access_key'], $settings['secret_key'], $settings['path_style'], $settings['send_public_acl'],
            $settings['source_endpoint'], $settings['source_region'], $settings['source_bucket'], $settings['source_access_key'], $settings['source_secret_key'], $settings['source_path_style']
        );
        update_option( self::OPTION_KEY, $settings, false );
        self::$cache = null;
    }

    /**
     * Drop the per-request settings cache.
     *
     * all() folds the selected provider's connection details (endpoint/
     * bucket/keys) into its cached result, so anything that edits a
     * connection must invalidate it too — otherwise the rest of the request
     * keeps talking to the bucket that was configured a moment ago.
     * ISXM_Connections::save_one() calls this.
     */
    public static function flush_cache() {
        self::$cache = null;
    }

    /**
     * Whether the destination connection is complete enough to talk to a bucket.
     */
    public static function is_configured() {
        $s = self::all();
        return ISXM_Connections::is_configured( $s['provider'] );
    }

    /**
     * Normalized object key prefix (with trailing slash, no leading slash).
     */
    public static function key_prefix() {
        $s = self::all();
        if ( ! $s['use_prefix'] || $s['prefix'] === '' ) {
            return '';
        }
        return trailingslashit( ltrim( $s['prefix'], '/' ) );
    }

    /**
     * Base URL used to serve objects publicly (no trailing slash, no key).
     */
    public static function public_base_url() {
        $s      = self::all();
        $scheme = $s['force_https'] ? 'https' : ( is_ssl() ? 'https' : 'http' );

        if ( $s['cdn_domain'] !== '' ) {
            $domain = preg_replace( '#^https?://#', '', untrailingslashit( $s['cdn_domain'] ) );
            return $scheme . '://' . $domain;
        }

        if ( $s['endpoint'] !== '' ) {
            // An endpoint explicitly declared as http:// can't be assumed to
            // serve https — Force HTTPS must not generate delivery URLs the
            // origin can never answer (dev/on-prem Minio is typically http).
            if ( strpos( $s['endpoint'], 'http://' ) === 0 ) {
                $scheme = 'http';
            }
            $host = preg_replace( '#^https?://#', '', untrailingslashit( $s['endpoint'] ) );
            if ( $s['path_style'] ) {
                return $scheme . '://' . $host . '/' . $s['bucket'];
            }
            return $scheme . '://' . $s['bucket'] . '.' . $host;
        }

        // AWS S3 default endpoint (virtual-hosted style)
        return $scheme . '://' . $s['bucket'] . '.s3.' . $s['region'] . '.amazonaws.com';
    }

    /**
     * Public base URL for an attachment that was offloaded to a specific
     * bucket, per its own `_isxs_offload` record.
     *
     * public_base_url() answers for the destination configured RIGHT NOW.
     * That is the wrong answer for a file sitting in a bucket the site used
     * to point at: its object key was minted there, so serving it from the
     * current bucket's host produces a URL that 404s. Delivery URLs and the
     * permanent DB rewrite both go through here so a destination switch
     * can't silently break every already-offloaded file.
     *
     * The custom delivery domain (CDN) only applies to the current
     * destination — a CDN is configured for one origin, and pointing it at
     * an old bucket's keys would be just as broken.
     *
     * @param array $info The `_isxs_offload` meta array.
     * @return string Base URL, no trailing slash.
     */
    public static function public_base_url_for( array $info ) {
        $s        = self::all();
        $bucket   = isset( $info['bucket'] ) ? (string) $info['bucket'] : '';
        $endpoint = isset( $info['endpoint'] ) ? (string) $info['endpoint'] : '';

        // Records written before `endpoint` was tracked, and records that
        // simply point at the live destination, use the configured answer
        // (which is also the only one that may use the CDN domain).
        if ( $bucket === '' || $endpoint === '' || ( $bucket === $s['bucket'] && $endpoint === $s['endpoint'] ) ) {
            return self::public_base_url();
        }

        $scheme = ( strpos( $endpoint, 'http://' ) === 0 ) ? 'http' : ( $s['force_https'] ? 'https' : ( is_ssl() ? 'https' : 'http' ) );
        $host   = preg_replace( '#^https?://#', '', untrailingslashit( $endpoint ) );

        return ! empty( $info['path_style'] )
            ? $scheme . '://' . $host . '/' . $bucket
            : $scheme . '://' . $bucket . '.' . $host;
    }

    /**
     * Public base URL where media currently living on the migration
     * source is actually reachable — i.e. the URL that's already
     * hard-coded into the database and needs replacing during migrate.
     *
     * Uses the explicit override if set, otherwise guesses from the
     * source connection settings (same logic as public_base_url(), but
     * for the source bucket). Returns '' when nothing is configured.
     */
    public static function source_public_base_url() {
        $s = self::all();

        if ( $s['source_public_base_url'] !== '' ) {
            return untrailingslashit( $s['source_public_base_url'] );
        }

        if ( $s['source_bucket'] === '' ) {
            return '';
        }

        if ( $s['source_endpoint'] !== '' ) {
            // Same reasoning as public_base_url(): an explicit http://
            // source endpoint means its public URLs are http too.
            $scheme = ( strpos( $s['source_endpoint'], 'http://' ) === 0 ) ? 'http' : 'https';
            $host   = preg_replace( '#^https?://#', '', untrailingslashit( $s['source_endpoint'] ) );
            if ( $s['source_path_style'] ) {
                return $scheme . '://' . $host . '/' . $s['source_bucket'];
            }
            return $scheme . '://' . $s['source_bucket'] . '.' . $host;
        }

        return 'https://' . $s['source_bucket'] . '.s3.' . $s['source_region'] . '.amazonaws.com';
    }
}
