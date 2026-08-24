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
            // Stop WordPress generating the intermediate image sizes at all,
            // so an upload is one object on the bucket instead of a dozen.
            // Off by default: with no sizes, srcset disappears and every
            // template asking for 'thumbnail' gets the full-size file — fine
            // for a headless site that resizes downstream, a regression for
            // a theme-rendered one. Also lifts big_image_size_threshold, or
            // WordPress would still replace a wide original with a -scaled
            // copy and defeat the point. Applies to new uploads only.
            'disable_thumbnails' => false,
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

            // Folder-by-content-type — group offloaded files by the post
            // type / taxonomy they belong to instead of the flat year/month
            // layout. Off by default so existing installs keep today's paths
            // untouched. base_key is frozen per file in the offload record,
            // so toggling this (or renaming a folder) never moves files that
            // were already offloaded — only future uploads/re-offloads see it.
            'use_type_folder'    => false,
            'product_folder'     => 'products',
            'download_folder'    => 'downloads',
            'post_folder'        => 'posts',
            'promotion_folder'   => 'promotions',
            'category_folder'    => 'categories',
            'brand_folder'       => 'brands',

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
     * The content-type folders, as one list: settings key => label/default.
     *
     * Single source of truth for the admin card, the save sanitizer and the
     * defaults above — the folder names exist in three places otherwise, and
     * they have to agree or a renamed folder silently falls back to default.
     */
    public static function type_folder_fields() {
        return [
            'product_folder'   => [ 'label' => 'โฟลเดอร์สินค้า (รูปภาพ)',     'default' => 'products' ],
            'download_folder'  => [ 'label' => 'โฟลเดอร์ไฟล์ดาวน์โหลดสินค้า', 'default' => 'downloads' ],
            'post_folder'      => [ 'label' => 'โฟลเดอร์บทความ',              'default' => 'posts' ],
            'promotion_folder' => [ 'label' => 'โฟลเดอร์โปรโมชั่น',            'default' => 'promotions' ],
            'category_folder'  => [ 'label' => 'โฟลเดอร์หมวดหมู่สินค้า',       'default' => 'categories' ],
            'brand_folder'     => [ 'label' => 'โฟลเดอร์แบรนด์',              'default' => 'brands' ],
        ];
    }

    /**
     * Content-type path segment for an attachment — e.g. "products/my-item".
     *
     * Empty string means "no rule matched": the caller then builds exactly
     * the key it would have built with the feature off, so an attachment we
     * cannot classify is never worse off than before.
     *
     * Never carries a trailing slash — the caller joins it.
     *
     * @param int $attachment_id
     * @return string
     */
    public static function type_folder_segment( $attachment_id ) {
        $s = self::all();
        if ( empty( $s['use_type_folder'] ) ) {
            return '';
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment ) {
            return '';
        }

        if ( ! empty( $attachment->post_parent ) ) {
            $segment = self::type_folder_from_parent( $attachment, $s );
            if ( $segment !== '' ) {
                return $segment;
            }
        }

        return self::type_folder_from_term( $attachment_id, $s );
    }

    /**
     * Folder for an attachment that hangs off a post: product image vs
     * product download, post, promotion.
     *
     * @param WP_Post $attachment
     * @param array   $s Settings.
     * @return string
     */
    private static function type_folder_from_parent( $attachment, $s ) {
        $parent = get_post( $attachment->post_parent );
        if ( ! $parent ) {
            return '';
        }

        $slug = self::post_slug( $parent );

        switch ( $parent->post_type ) {
            case 'product':
                // Images are gallery/featured images; anything else attached
                // to a product is what the shop hands to a customer.
                $mime   = get_post_mime_type( $attachment->ID );
                $folder = ( is_string( $mime ) && strpos( $mime, 'image/' ) === 0 )
                    ? $s['product_folder']
                    : $s['download_folder'];
                return $slug !== '' ? $folder . '/' . $slug : $folder;

            case 'product_variation':
                // A variation has no meaningful slug of its own — file it
                // under the product it belongs to.
                $grandparent = $parent->post_parent ? get_post( $parent->post_parent ) : null;
                if ( $grandparent && $grandparent->post_type === 'product' ) {
                    $slug = self::post_slug( $grandparent );
                }
                return $slug !== '' ? $s['product_folder'] . '/' . $slug : $s['product_folder'];

            case 'post':
                return $slug !== '' ? $s['post_folder'] . '/' . $slug : $s['post_folder'];

            case 'promotion-carousel':
                // Flat on purpose: carousel slides are one small pool, and
                // per-slide folders would just be noise.
                return $s['promotion_folder'];
        }

        return '';
    }

    /**
     * Folder for an attachment used as a term image (product category /
     * brand), which has no post_parent to go on.
     *
     * Two lookups, because neither alone covers both moments: during a live
     * upload from the term edit screen the term meta does not point at the
     * attachment yet (the term is saved afterwards), and during a bulk
     * offload — or WP-CLI, or the background queue — there is no referer.
     *
     * @param int   $attachment_id
     * @param array $s Settings.
     * @return string
     */
    private static function type_folder_from_term( $attachment_id, $s ) {
        $taxonomies = [
            'product_cat'   => $s['category_folder'],
            'product_brand' => $s['brand_folder'],
        ];

        // 1. Live upload: the term edit screen we were uploading from.
        $referer = wp_get_raw_referer();
        if ( $referer ) {
            $query = [];
            parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );
            $taxonomy = isset( $query['taxonomy'] ) ? (string) $query['taxonomy'] : '';

            if ( isset( $taxonomies[ $taxonomy ] ) && taxonomy_exists( $taxonomy ) ) {
                $folder = $taxonomies[ $taxonomy ];
                if ( ! empty( $query['tag_ID'] ) ) {
                    $term = get_term( (int) $query['tag_ID'], $taxonomy );
                    if ( $term && ! is_wp_error( $term ) && $term->slug !== '' ) {
                        return $folder . '/' . $term->slug;
                    }
                }
                return $folder;
            }
        }

        // 2. Bulk offload / re-offload: the term that already points here.
        global $wpdb;
        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value = %s LIMIT 5",
                (string) $attachment_id
            )
        );
        foreach ( (array) $term_ids as $term_id ) {
            $term = get_term( (int) $term_id );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            if ( isset( $taxonomies[ $term->taxonomy ] ) ) {
                $folder = $taxonomies[ $term->taxonomy ];
                return $term->slug !== '' ? $folder . '/' . $term->slug : $folder;
            }
        }

        return '';
    }

    /**
     * A post's URL slug, falling back to its title — a draft/auto-draft has
     * no post_name yet, and "" would collapse the folder level.
     *
     * @param WP_Post $post
     * @return string
     */
    private static function post_slug( $post ) {
        if ( ! empty( $post->post_name ) ) {
            return $post->post_name;
        }
        return sanitize_title( $post->post_title );
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
