<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Migrate — Pulls existing media from another S3-compatible source
 * bucket (e.g. Amazon S3) down to the local server, then hands off to
 * ISXM_Offload to push it up to the currently configured destination.
 *
 * The source bucket is never modified — this only ever GETs objects.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Migrate {

    /**
     * Build a client bound to the source bucket settings.
     *
     * @param array $overrides Optional live form values (endpoint/region/bucket/
     *                         access_key/secret_key/path_style) that take
     *                         priority over the saved settings — used so
     *                         "test connection" can check unsaved edits.
     */
    private static function source_client( array $overrides = [] ) {
        $s = ISXM_Settings::all();
        $defaults = [
            'endpoint'   => $s['source_endpoint'],
            'region'     => $s['source_region'],
            'bucket'     => $s['source_bucket'],
            'access_key' => $s['source_access_key'],
            'secret_key' => $s['source_secret_key'],
            'path_style' => $s['source_path_style'],
        ];
        return new ISXM_Client( array_merge( $defaults, $overrides ) );
    }

    /**
     * Real object count currently in the source bucket (List Objects V2) —
     * a reference number only, independent of the WP-attachment-based
     * pending count that actually drives the migrate batch loop. Resumable
     * via $continuation_token/next_token so the caller can keep paginating
     * across multiple requests until `complete` is true.
     *
     * @param array  $overrides Optional live form values, see source_client().
     * @param string $continuation_token Resume cursor from a previous incomplete call ('' to start).
     * @return array{total:int,next_token:string,complete:bool}|WP_Error
     */
    public static function count_source_objects( array $overrides = [], $continuation_token = '' ) {
        return self::source_client( $overrides )->count_objects( $continuation_token );
    }

    /**
     * Object key prefix on the source bucket for one attachment
     * (mirrors ISXM_Offload's base_key logic, but with the source's own
     * prefix/year-month settings and no object-version folder).
     *
     * @param int $attachment_id Attachment post ID.
     * @return string
     */
    public static function source_key_base( $attachment_id ) {
        $s = ISXM_Settings::all();

        $base_key = $s['source_prefix'] !== '' ? trailingslashit( ltrim( $s['source_prefix'], '/' ) ) : '';

        if ( $s['source_use_year_month'] ) {
            // Normalised, for the same reason ISXM_Offload does it: a stale
            // absolute path in the meta would otherwise become part of the
            // source object key.
            $relative = (string) ISXM_Offload::relative_local_path( $attachment_id );
            $subdir   = $relative === '' ? '' : dirname( $relative );
            if ( $subdir !== '.' && $subdir !== '' ) {
                $base_key .= trailingslashit( $subdir );
            }
        }

        return $base_key;
    }

    /**
     * Additional base directory URLs the DB may reference for this
     * attachment (i.e. where the source publicly served it from), for
     * the permanent URL rewrite.
     *
     * @param int $attachment_id Attachment post ID.
     * @return string[]
     */
    public static function extra_old_dirs( $attachment_id ) {
        $source_base = ISXM_Settings::source_public_base_url();
        if ( $source_base === '' ) {
            return [];
        }
        return [ trailingslashit( $source_base ) . self::source_key_base( $attachment_id ) ];
    }

    /**
     * WordPress' size-suffix pattern (e.g. "-300x200") stripped from a
     * filename to recover the original/canonical filename it was generated
     * from — same regex ISXM_Offload::rewrite_content_urls() already uses.
     */
    private static function canonical_filename( $filename ) {
        return preg_replace( '#-\d+x\d+(\.[a-zA-Z0-9]+)$#', '$1', $filename );
    }

    /**
     * Scan ONE page of the source bucket's real object listing (ground
     * truth via ListObjectsV2) and resolve every key back to a WP
     * attachment via its `_wp_attached_file` meta — grouping the real keys
     * that belong to each attachment (original + every size WordPress
     * generated) so migrate_attachment() fetches exactly what's actually
     * there instead of guessing a key that might not exist (guessing broke
     * down whenever a file's real key had extra path segments this site's
     * own settings don't reproduce, e.g. an object-version folder from a
     * previous offload). Keys outside the configured source prefix, or that
     * don't match any attachment on this site, are silently skipped — they
     * aren't ours to migrate. Every other resolved attachment is included
     * unconditionally — this is a plain "pull what's really on Source, push
     * it to Destination" mirror, not gated on `_isxs_offload` postmeta
     * (which doesn't reliably reflect what's physically in either bucket).
     *
     * Each call scans exactly one listing page (up to 200 keys); the
     * cursor-based callers page through with the returned next_token.
     *
     * @param string $continuation_token Resume token from a previous call ('' to start from the beginning).
     * @return array{items:array<int,array<string,string>>, next_token:string, done:bool, scanned_keys:int}|WP_Error
     *               items: attachment_id => [ real_s3_key => filename ].
     *               scanned_keys: total keys in this page (including ones
     *               skipped for being outside the prefix / unmatched) — lets
     *               the caller report progress in OBJECT units, matching the
     *               source-object-count denominator shown in the UI.
     */
    public static function scan_source_batch( $continuation_token ) {
        global $wpdb;

        $s      = ISXM_Settings::all();
        $prefix = $s['source_prefix'];

        $page = self::source_client()->list_objects_keys_page( $continuation_token, 200 );
        if ( is_wp_error( $page ) ) {
            return $page;
        }

        $scanned_keys = count( $page['keys'] );

        // Resolve the whole page's keys with ONE query instead of one per key:
        // at 200 keys per page that was 200 round trips against a table with
        // millions of rows, and it was the dominant cost of a large migrate.
        // The keys are turned into `_wp_attached_file` candidates first, then
        // looked up together; a second pass over the SAME ordered list builds
        // $items, so the resulting order is byte-for-byte what the per-key
        // loop produced — run_migrate_batch()'s mid-page resume offset counts
        // on that order being stable.
        $resolved = []; // ordered [ candidate, key, filename ]
        foreach ( $page['keys'] as $key ) {
            if ( $prefix !== '' && strpos( $key, $prefix ) !== 0 ) {
                continue; // outside our configured prefix — not ours
            }

            $relative = substr( $key, strlen( $prefix ) );
            $segments = explode( '/', $relative );
            $filename = array_pop( $segments );
            if ( $filename === '' ) {
                continue;
            }

            $year_month = '';
            if ( $s['source_use_year_month'] && count( $segments ) >= 2 ) {
                $year_month = $segments[0] . '/' . $segments[1] . '/';
            }

            $resolved[] = [
                'candidate' => $year_month . self::canonical_filename( $filename ),
                'key'       => $key,
                'filename'  => $filename,
            ];
        }

        $items = []; // attachment_id => [ key => filename ]
        if ( empty( $resolved ) ) {
            return [
                'items'        => $items,
                'next_token'   => $page['next_token'],
                'done'         => $page['next_token'] === '',
                'scanned_keys' => $scanned_keys,
            ];
        }

        $candidates   = array_values( array_unique( wp_list_pluck( $resolved, 'candidate' ) ) );
        $placeholders = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );
        $rows         = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built from a counted array
            $candidates
        ) );

        $by_candidate = [];
        foreach ( $rows as $row ) {
            // First row wins, matching the LIMIT 1 the per-key query used.
            if ( ! isset( $by_candidate[ $row->meta_value ] ) ) {
                $by_candidate[ $row->meta_value ] = (int) $row->post_id;
            }
        }

        foreach ( $resolved as $entry ) {
            if ( empty( $by_candidate[ $entry['candidate'] ] ) ) {
                continue;
            }
            $items[ $by_candidate[ $entry['candidate'] ] ][ $entry['key'] ] = $entry['filename'];
        }

        // run_migrate_batch() resumes an interrupted page by POSITION within
        // this array, so the order has to be reproducible when the same page
        // is scanned again. Insertion order alone isn't: it follows whichever
        // of an attachment's keys the listing happened to return first, so a
        // single new size-variant object appearing on the source between the
        // two scans could reorder items and make the offset skip live work.
        // Sorting by attachment ID makes the position depend only on WHICH
        // attachments the page resolves to.
        ksort( $items, SORT_NUMERIC );

        return [
            'items'        => $items,
            'next_token'   => $page['next_token'],
            'done'         => $page['next_token'] === '',
            'scanned_keys' => $scanned_keys,
        ];
    }

    /**
     * Migrate one attachment: fetch any locally-missing files using their
     * REAL source keys (as resolved by scan_source_batch(), not guessed),
     * then offload them to the configured destination.
     *
     * @param int   $attachment_id Attachment post ID.
     * @param array $key_map       [ real_s3_key => filename ] for this attachment, from scan_source_batch().
     * @param bool  $defer_persist Skip the per-attachment DB URL rewrite —
     *                             bulk callers batch it via
     *                             ISXM_Offload::collect_url_pairs() +
     *                             ISXM_DB_Rewriter::replace_urls_bulk().
     * @return true|WP_Error
     */
    public static function migrate_attachment( $attachment_id, array $key_map, $defer_persist = false ) {
        if ( empty( $key_map ) ) {
            return new WP_Error( 'isxs_no_source_keys', 'ไม่พบไฟล์ของรายการนี้บน source bucket' );
        }

        // Normalised against the current uploads dir: the staging files are
        // written here, and a stale absolute path from another host would
        // put them outside the uploads tree entirely.
        $file = ISXM_Offload::local_path( $attachment_id );
        if ( $file === '' ) {
            return new WP_Error( 'isxs_no_path', 'ไม่ทราบตำแหน่งไฟล์ของ attachment นี้' );
        }

        $local_dir = trailingslashit( dirname( $file ) );
        wp_mkdir_p( $local_dir );

        $client = self::source_client();

        foreach ( $key_map as $key => $filename ) {
            $path = $local_dir . $filename;
            if ( file_exists( $path ) ) {
                continue;
            }

            // Streamed to disk — a large source object held whole in memory
            // is a fatal waiting to happen, and a fatal kills the whole batch.
            $fetched = $client->get_object_to_file( $key, $path );
            if ( is_wp_error( $fetched ) ) {
                return new WP_Error(
                    'isxs_source_fetch_failed',
                    sprintf( 'ดึงไฟล์ %s จาก source ไม่สำเร็จ (key: %s): %s', $filename, $key, $fetched->get_error_message() )
                );
            }
        }

        // Persist is always deferred on the inner offload — this method owns
        // the rewrite so the source's extra old dirs are included.
        $metadata = wp_get_attachment_metadata( $attachment_id );
        $result   = ( new ISXM_Offload() )->offload_attachment( $attachment_id, $metadata, true, 'migrate' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // The DB already has URLs pointing at wherever the source publicly
        // served media from — which is not necessarily this server's local
        // wp-content/uploads. Tell persist_permanent_urls() about it so it
        // can find and replace those too, on top of any local-uploads URLs.
        if ( ! $defer_persist ) {
            ISXM_Offload::persist_permanent_urls( $attachment_id, self::extra_old_dirs( $attachment_id ) );
            // The inner offload always defers, so its "Remove Local Media"
            // deletions are queued too — release them now the rewrite is done.
            ISXM_Offload::flush_local_deletions();
        }

        return true;
    }
}
