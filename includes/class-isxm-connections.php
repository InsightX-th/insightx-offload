<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Connections — Saved S3-compatible connection profiles, one per
 * provider (aws/minio/garage/r2/spaces/gcs/custom). The Storage tab
 * (destination) and Migrate tab (source) each just pick one of these by
 * provider slug instead of holding their own copy of endpoint/bucket/keys.
 *
 * Secrets are stored encrypted via ISXM_Crypto and decrypted transparently
 * on read, same convention as the rest of the plugin.
 *
 * @since 0.1.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Connections {

    const OPTION_KEY        = 'isxs_connections';
    const STATUS_OPTION_KEY = 'isxs_connection_status';
    const MIGRATED_FLAG     = 'isxs_connections_migrated';

    /**
     * Provider metadata: labels, defaults and UI copy — one entry per
     * supported provider. Ported 1:1 from the JS providerPresets that used
     * to drive the old single-form picker.
     */
    public static function providers() {
        return [
            'aws' => [
                'label'                  => 'Amazon S3',
                'region_default'         => 'us-east-1',
                'endpoint_default'       => '',
                'path_style_default'     => false,
                'endpoint_locked'        => true,
                'endpoint_hint'          => 'Amazon S3 คำนวณ endpoint จาก Region ที่ตั้งไว้ให้อัตโนมัติ ไม่ต้องกรอกเอง',
                'region_hint'            => 'เช่น us-east-1, ap-southeast-1 — ต้องตรงกับ region ที่สร้าง bucket',
                'endpoint_placeholder'   => 'ไม่ต้องกรอก (คำนวณจาก Region)',
                'access_key_placeholder' => 'AWS Access Key ID เช่น AKIAIOSFODNN7EXAMPLE',
                'secret_key_placeholder' => 'AWS Secret Access Key',
                'bucket_placeholder'     => 'ชื่อ S3 Bucket เช่น my-app-media',
            ],
            'minio' => [
                'label'                  => 'Minio',
                'region_default'         => 'us-east-1',
                'endpoint_default'       => '',
                'path_style_default'     => true,
                'endpoint_locked'        => false,
                'endpoint_hint'          => 'ใส่ URL ของ Minio server เช่น https://minio.example.com',
                'region_hint'            => 'Minio ใช้ค่าอะไรก็ได้ (default: us-east-1)',
                'endpoint_placeholder'   => 'https://minio.example.com:9000',
                'access_key_placeholder' => 'Minio Access Key',
                'secret_key_placeholder' => 'Minio Secret Key',
                'bucket_placeholder'     => 'ชื่อ Bucket บน Minio',
            ],
            'garage' => [
                'label'                  => 'Garage',
                'region_default'         => 'garage',
                'endpoint_default'       => '',
                'path_style_default'     => true,
                'endpoint_locked'        => false,
                'endpoint_hint'          => 'ใส่ URL ของ Garage cluster เช่น https://garage.example.com',
                'region_hint'            => 'ต้องตรงกับ s3_region ที่ตั้งใน garage.toml (default: garage)',
                'endpoint_placeholder'   => 'https://garage.example.com',
                'access_key_placeholder' => 'Garage Access Key',
                'secret_key_placeholder' => 'Garage Secret Key',
                'bucket_placeholder'     => 'ชื่อ Bucket บน Garage',
            ],
            'r2' => [
                'label'                  => 'Cloudflare R2',
                'region_default'         => 'auto',
                'endpoint_default'       => '',
                'path_style_default'     => false,
                'endpoint_locked'        => false,
                'endpoint_hint'          => 'แทน <ACCOUNT_ID> ด้วย Cloudflare Account ID (ดูได้จาก R2 dashboard)',
                'region_hint'            => 'Cloudflare R2 ใช้ "auto" เสมอ',
                'endpoint_placeholder'   => 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com',
                'access_key_placeholder' => 'Cloudflare R2 Access Key ID',
                'secret_key_placeholder' => 'Cloudflare R2 Secret Access Key',
                'bucket_placeholder'     => 'ชื่อ R2 Bucket',
            ],
            'spaces' => [
                'label'                  => 'DigitalOcean Spaces',
                'region_default'         => 'sgp1',
                'endpoint_default'       => '',
                'path_style_default'     => false,
                'endpoint_locked'        => false,
                'endpoint_hint'          => 'แทน <REGION> ด้วย region ของ Space เช่น https://sgp1.digitaloceanspaces.com',
                'region_hint'            => 'ต้องตรงกับ region ของ Space เช่น sgp1, nyc3, ams3',
                'endpoint_placeholder'   => 'https://<REGION>.digitaloceanspaces.com',
                'access_key_placeholder' => 'DigitalOcean Spaces Access Key',
                'secret_key_placeholder' => 'DigitalOcean Spaces Secret Key',
                'bucket_placeholder'     => 'ชื่อ Space เช่น my-space-name',
            ],
            'gcs' => [
                'label'                  => 'Google Cloud Storage',
                'region_default'         => 'auto',
                'endpoint_default'       => 'https://storage.googleapis.com',
                'path_style_default'     => true,
                'endpoint_locked'        => true,
                'endpoint_hint'          => 'ใช้ endpoint มาตรฐานของ Google Cloud Storage (HMAC / interoperability mode)',
                'region_hint'            => 'GCS ไม่ใช้ค่านี้ในการ route — ใส่ "auto" ได้เลย',
                'endpoint_placeholder'   => 'https://storage.googleapis.com',
                'access_key_placeholder' => 'GCS HMAC Access Key',
                'secret_key_placeholder' => 'GCS HMAC Secret',
                'bucket_placeholder'     => 'ชื่อ Google Cloud Storage Bucket',
            ],
            'custom' => [
                'label'                  => 'Other (S3-compatible)',
                'region_default'         => 'us-east-1',
                'endpoint_default'       => '',
                'path_style_default'     => true,
                'endpoint_locked'        => false,
                'endpoint_hint'          => 'ใส่ endpoint ของ storage provider ที่ใช้ (S3-compatible ใดก็ได้)',
                'region_hint'            => 'ใส่ตามที่ provider กำหนด หรือ us-east-1 ถ้าไม่แน่ใจ',
                'endpoint_placeholder'   => 'ใส่ endpoint ของ storage provider ที่ใช้',
                'access_key_placeholder' => 'Access Key ของ storage provider',
                'secret_key_placeholder' => 'Secret Key ของ storage provider',
                'bucket_placeholder'     => 'ชื่อ Bucket',
            ],
        ];
    }

    /**
     * One provider's connection config, merged with its metadata defaults
     * (endpoint/region/path_style) for whatever hasn't been configured yet.
     * Secret key is decrypted.
     *
     * @param string $slug
     * @return array{endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_style:bool,send_public_acl:bool}|null
     */
    public static function get( $slug ) {
        $providers = self::providers();
        if ( ! isset( $providers[ $slug ] ) ) {
            return null;
        }
        $meta   = $providers[ $slug ];
        $stored = get_option( self::OPTION_KEY, [] );
        $config = ( is_array( $stored ) && isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) ) ? $stored[ $slug ] : [];

        return [
            'endpoint'        => ( isset( $config['endpoint'] ) && $config['endpoint'] !== '' ) ? $config['endpoint'] : $meta['endpoint_default'],
            'region'          => ( isset( $config['region'] ) && $config['region'] !== '' ) ? $config['region'] : $meta['region_default'],
            'bucket'          => isset( $config['bucket'] ) ? $config['bucket'] : '',
            'access_key'      => isset( $config['access_key'] ) ? $config['access_key'] : '',
            'secret_key'      => ISXM_Crypto::decrypt( isset( $config['secret_key'] ) ? $config['secret_key'] : '' ),
            'path_style'      => isset( $config['path_style'] ) ? (bool) $config['path_style'] : $meta['path_style_default'],
            'send_public_acl' => ! empty( $config['send_public_acl'] ),
        ];
    }

    /**
     * All providers' connection configs, keyed by slug.
     */
    public static function all() {
        $out = [];
        foreach ( self::providers() as $slug => $meta ) {
            $out[ $slug ] = self::get( $slug );
        }
        return $out;
    }

    /**
     * @param string $slug
     * @return bool
     */
    public static function is_configured( $slug ) {
        $c = self::get( $slug );
        return $c && $c['bucket'] !== '' && $c['access_key'] !== '' && $c['secret_key'] !== '';
    }

    /**
     * Save one provider's connection config. Expects a plain-text
     * secret_key (already resolved by the caller — blank means "keep
     * whatever's currently stored", same convention the rest of the plugin
     * uses for masked secret fields); encrypts it before persisting.
     *
     * @param string $slug
     * @param array  $config { endpoint, region, bucket, access_key, secret_key, path_style, send_public_acl }
     * @return bool
     */
    public static function save_one( $slug, array $config ) {
        if ( ! array_key_exists( $slug, self::providers() ) ) {
            return false;
        }

        $stored = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        $secret = isset( $config['secret_key'] ) ? trim( (string) $config['secret_key'] ) : '';
        // Blank means "keep what's stored" for every caller in the plugin,
        // but this method used to write '' anyway and wipe the saved secret
        // if a caller ever forgot to resolve it first. Honour the contract
        // here instead of relying on every caller to remember.
        $existing_secret = isset( $stored[ $slug ]['secret_key'] ) ? (string) $stored[ $slug ]['secret_key'] : '';

        $stored[ $slug ] = [
            'endpoint'        => isset( $config['endpoint'] ) ? esc_url_raw( trim( $config['endpoint'] ) ) : '',
            'region'          => isset( $config['region'] ) ? sanitize_text_field( $config['region'] ) : '',
            'bucket'          => isset( $config['bucket'] ) ? sanitize_text_field( $config['bucket'] ) : '',
            'access_key'      => isset( $config['access_key'] ) ? sanitize_text_field( $config['access_key'] ) : '',
            'secret_key'      => $secret !== '' ? ISXM_Crypto::encrypt( $secret ) : $existing_secret,
            'path_style'      => ! empty( $config['path_style'] ),
            'send_public_acl' => ! empty( $config['send_public_acl'] ),
        ];

        update_option( self::OPTION_KEY, $stored, false );
        // ISXM_Settings::all() caches the resolved connection fields for the
        // whole request — without this, everything after this point in the
        // same request (the connection test, a CLI command that saves then
        // works, the response payload) keeps using the pre-save credentials.
        ISXM_Settings::flush_cache();
        // These credentials/bucket are exactly what the destination probe
        // and the stats were computed from — both answers may have just
        // changed.
        ISXM_Tools::flush_stats_cache();
        return true;
    }

    /**
     * Last known connection-test result for one provider.
     *
     * @param string $slug
     * @return array{state:string,message:string}
     */
    public static function status( $slug ) {
        $all = get_option( self::STATUS_OPTION_KEY, [] );
        if ( is_array( $all ) && isset( $all[ $slug ]['state'] ) && $all[ $slug ]['state'] !== '' ) {
            return $all[ $slug ];
        }
        return [
            'state'   => 'unknown',
            'message' => self::is_configured( $slug ) ? 'ยังไม่ได้ทดสอบการเชื่อมต่อ' : 'ยังไม่ได้ตั้งค่า',
        ];
    }

    /**
     * @param string $slug
     * @param string $state   'ok'|'error'.
     * @param string $message
     */
    public static function save_status( $slug, $state, $message ) {
        $all = get_option( self::STATUS_OPTION_KEY, [] );
        if ( ! is_array( $all ) ) {
            $all = [];
        }
        $all[ $slug ] = [ 'state' => $state, 'message' => $message ];
        update_option( self::STATUS_OPTION_KEY, $all, false );
    }

    /**
     * Data for JS localize — never exposes the decrypted secret, only
     * whether one is set (via `configured`).
     */
    public static function js_data() {
        $data = [];
        foreach ( self::providers() as $slug => $meta ) {
            $c      = self::get( $slug );
            $status = self::status( $slug );
            $data[ $slug ] = [
                'label'         => $meta['label'],
                'endpoint'      => $c['endpoint'],
                'region'        => $c['region'],
                'bucket'        => $c['bucket'],
                'pathStyle'     => $c['path_style'],
                'configured'    => self::is_configured( $slug ),
                'status'        => $status['state'],
                'statusMessage' => $status['message'],
            ];
        }
        return $data;
    }

    /**
     * One-time migration from the old single-connection `isxs_settings`
     * fields (endpoint/region/bucket/access_key/secret_key + source_*) into
     * this provider-keyed model, so upgrading doesn't lose an existing
     * destination/source setup. Gated by an option flag so it only ever
     * runs once.
     */
    public static function maybe_migrate_legacy() {
        if ( get_option( self::MIGRATED_FLAG ) ) {
            return;
        }

        $legacy = get_option( ISXM_Settings::OPTION_KEY, [] );
        if ( is_array( $legacy ) ) {
            $providers      = self::providers();
            $dest_provider  = isset( $legacy['provider'] ) ? $legacy['provider'] : '';
            $dest_migrated  = false;

            if ( $dest_provider && array_key_exists( $dest_provider, $providers ) && ! empty( $legacy['bucket'] ) ) {
                self::save_one( $dest_provider, [
                    'endpoint'        => isset( $legacy['endpoint'] ) ? $legacy['endpoint'] : '',
                    'region'          => isset( $legacy['region'] ) ? $legacy['region'] : '',
                    'bucket'          => $legacy['bucket'],
                    'access_key'      => isset( $legacy['access_key'] ) ? $legacy['access_key'] : '',
                    'secret_key'      => ISXM_Crypto::decrypt( isset( $legacy['secret_key'] ) ? $legacy['secret_key'] : '' ),
                    'path_style'      => ! empty( $legacy['path_style'] ),
                    'send_public_acl' => ! empty( $legacy['send_public_acl'] ),
                ] );
                $dest_migrated = true;
            }

            $source_provider = isset( $legacy['source_provider'] ) ? $legacy['source_provider'] : '';
            if ( $source_provider && array_key_exists( $source_provider, $providers ) && ! empty( $legacy['source_bucket'] ) ) {
                // Same provider slug claimed by both roles with different
                // buckets can't be represented (one slug = one connection
                // now) — keep the destination values already saved above
                // and leave source for manual re-entry rather than silently
                // overwriting one with the other.
                $collides = $dest_migrated && $source_provider === $dest_provider && $legacy['source_bucket'] !== $legacy['bucket'];
                if ( $collides ) {
                    isxm_log_error( 'Connections migration: source and destination both used provider "' . $source_provider . '" with different buckets — kept destination values, source needs manual re-entry.' );
                } else {
                    self::save_one( $source_provider, [
                        'endpoint'        => isset( $legacy['source_endpoint'] ) ? $legacy['source_endpoint'] : '',
                        'region'          => isset( $legacy['source_region'] ) ? $legacy['source_region'] : '',
                        'bucket'          => $legacy['source_bucket'],
                        'access_key'      => isset( $legacy['source_access_key'] ) ? $legacy['source_access_key'] : '',
                        'secret_key'      => ISXM_Crypto::decrypt( isset( $legacy['source_secret_key'] ) ? $legacy['source_secret_key'] : '' ),
                        'path_style'      => ! empty( $legacy['source_path_style'] ),
                        // save_one() writes every field, so omitting this
                        // silently turned the flag off on a migrated source.
                        'send_public_acl' => ! empty( $legacy['send_public_acl'] ),
                    ] );
                }
            }
        }

        update_option( self::MIGRATED_FLAG, 1, false );
    }
}
