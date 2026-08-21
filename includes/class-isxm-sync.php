<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Sync — Reconcile the `_isxs_offload` tracking meta against what
 * actually exists in the destination bucket.
 *
 * Every "is this attachment on the bucket?" decision in the plugin (the
 * Overview numbers, the Offload/Remove/Download tool denominators, and
 * Migrate's skip logic) reads the `_isxs_offload` postmeta — never a live
 * bucket check. When objects are deleted out-of-band (bucket console, CLI,
 * a cleanup script) those records go stale: the Overview keeps counting the
 * deleted files, and Offload/Migrate skip them as "already uploaded", so
 * they are never re-uploaded. ISXM_Sync lists the REAL bucket, diffs it
 * against the expected keys derived from the meta, and on apply drops the
 * stale records so the normal tools treat those attachments as pending
 * again.
 *
 * Scan outcome classes (per attachment):
 *  - stale    : the PRIMARY file is gone from the bucket — the whole
 *               attachment is missing there, re-upload everything.
 *  - partial  : the primary is still there but some generated sizes are
 *               missing — reported separately so a user can tell "gone"
 *               from "almost", and re-offload when they want.
 *  - complete : every expected key found.
 *
 * It also reports ORPHAN objects — keys under the configured prefix that no
 * attachment's meta accounts for (leftovers from an old version scheme,
 * failed remote deletes, manual uploads) — and can delete them on request.
 * Objects outside the configured prefix are counted separately and never
 * touched (the bucket may be shared with other sites/backups).
 *
 * Memory note: on very large libraries the expected-key map (one entry per
 * object) can reach hundreds of thousands of entries, so the batched AJAX
 * scan spills the map to temp files under uploads/isxs-sync/ instead of
 * round-tripping a giant array through a transient on every request — the
 * transient only ever holds small cursor state.
 *
 * @since 0.1.8
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Sync {

    /** Prefix for the per-run scan-state transient. */
    const STATE_TRANSIENT_PREFIX  = 'isxs_sync_state_';
    /** Prefix for the per-run scan-result transient (stale ids, orphans). */
    const RESULT_TRANSIENT_PREFIX = 'isxs_sync_result_';
    /** Prefix for the per-run orphan-cleanup state transient. */
    const ORPHAN_STATE_TRANSIENT_PREFIX = 'isxs_sync_orphan_';
    /** TTL for scan state/results — long enough to survive a slow run and
     *  the gap between scan and apply, short enough that an abandoned run
     *  (tab closed mid-scan) doesn't leave stale data behind. */
    const STATE_TTL = 6 * HOUR_IN_SECONDS;
    /** Orphan sample cap — the report lists a few keys, not thousands. */
    const ORPHAN_SAMPLE_MAX = 20;
    /** How long abandoned scan temp files may live before cleanup. */
    const FILE_TTL = DAY_IN_SECONDS;
    /** Temp-file directory name under uploads/. */
    const SYNC_DIR_NAME = 'isxs-sync';
    /** Option holding the unix timestamp of the last scan that finished
     *  without error — used to nudge the user back to Sync when it has
     *  gone stale (see last_run() / is_stale()). */
    const LAST_RUN_OPTION = 'isxs_sync_last_run';
    /** Option holding the verdict of that last scan (1 = everything
     *  matched, 0 = differences found) — see last_clean(). */
    const LAST_CLEAN_OPTION = 'isxs_sync_last_clean';
    /** How long since the last clean scan before the status widget nudges
     *  the user to run Sync again. */
    const STALE_AFTER = 7 * DAY_IN_SECONDS;

    /* ---------------------------------------------------------------------
     * Expected keys
     * ------------------------------------------------------------------ */

    /**
     * Every object key an attachment SHOULD have on the bucket, per its
     * tracking meta. base_key already carries prefix/year-month/version, so
     * the expected keys are simply base_key + each recorded filename —
     * exactly the keys offload() uploaded and download/remove read back.
     *
     * @param array $info The `_isxs_offload` meta array.
     * @return string[] Object keys, in the order files were recorded.
     */
    public static function expected_keys( array $info ) {
        if ( empty( $info['base_key'] ) || empty( $info['files'] ) || ! is_array( $info['files'] ) ) {
            return [];
        }
        $keys = [];
        foreach ( $info['files'] as $filename ) {
            $filename = ISXM_Offload::safe_filename( $filename );
            if ( $filename !== '' ) {
                $keys[] = $info['base_key'] . $filename;
            }
        }
        return $keys;
    }

    /**
     * Object key of the attachment's primary (original) file — the file
     * that decides "is this attachment really on the bucket?".
     *
     * @param array $info The `_isxs_offload` meta array.
     * @return string
     */
    public static function primary_key( array $info ) {
        $keys = self::expected_keys( $info );
        return isset( $keys[0] ) ? $keys[0] : '';
    }

    /* ---------------------------------------------------------------------
     * Verification (used by Migrate/Offload skip logic)
     * ------------------------------------------------------------------ */

    /**
     * Whether the attachment's PRIMARY file actually exists in the bucket
     * it was offloaded to — one prefixed ListObjects call (a handful of
     * keys, not a bucket scan). Only ever called for attachments whose meta
     * claims they are on the current destination, so the cost is bounded by
     * the work the run is doing anyway.
     *
     * Returns false when the record is unusable (no base_key/files) and
     * when the listing itself fails — in both cases the caller must treat
     * the attachment as not-verified and re-upload; a re-upload surfaces
     * the real error if the bucket is genuinely unreachable.
     *
     * @param array $info The `_isxs_offload` meta array.
     * @return bool
     */
    public static function primary_exists_on_destination( array $info ) {
        $primary = self::primary_key( $info );
        if ( $primary === '' || empty( $info['base_key'] ) ) {
            return false;
        }

        $client = ISXM_Offload::client_for_info( $info );
        $token  = '';
        do {
            $page = $client->list_objects_keys_page( $token, 1000, $info['base_key'] );
            if ( is_wp_error( $page ) ) {
                return false;
            }
            if ( in_array( $primary, $page['keys'], true ) ) {
                return true;
            }
            $token = $page['next_token'];
        } while ( $token !== '' );

        return false;
    }

    /* ---------------------------------------------------------------------
     * Expected-key scan (windowed)
     * ------------------------------------------------------------------ */

    /**
     * Scan the next window of attachments tracked against the CURRENT
     * destination and return their expected keys as ordered [key, id]
     * pairs. Windowed (bounded by post ID, same as get_offloaded_ids()) so
     * the AJAX tool can accumulate the expected set across requests without
     * one giant query. Keys for one attachment are emitted in
     * `files` order, so the FIRST pair of an attachment is its primary.
     *
     * @param int $limit    Max rows to read in this window.
     * @param int $after_id Resume after this attachment ID.
     * @return array{pairs:array<int,array{0:string,1:int}>,count:int,last_id:int,done:bool}
     */
    public static function scan_expected_window( $limit, $after_id = 0 ) {
        global $wpdb;

        // Ledger path: the destination filter is an indexed WHERE, so this
        // window reads only records that belong to the scan. The postmeta
        // path below reads every tracked attachment in the window and drops
        // the ones pointing elsewhere in PHP, which on a library that has
        // changed destination can discard nearly all of them.
        if ( ISXM_Items::ready() ) {
            $window = ISXM_Items::records_window(
                $limit,
                $after_id,
                (string) ISXM_Settings::get( 'bucket' ),
                (string) ISXM_Settings::get( 'endpoint' )
            );

            $pairs = [];
            $count = 0;
            foreach ( $window['records'] as $id => $info ) {
                foreach ( self::expected_keys( $info ) as $key ) {
                    $pairs[] = [ $key, (int) $id ];
                }
                $count++;
            }

            return [
                'pairs'   => $pairs,
                'count'   => $count,
                'last_id' => $window['last_id'],
                'done'    => $window['done'],
            ];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, m.meta_value FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.ID > %d
             ORDER BY p.ID ASC LIMIT %d",
            ISXM_Offload::META_KEY,
            $after_id,
            $limit
        ) );

        $current_bucket   = ISXM_Settings::get( 'bucket' );
        $current_endpoint = ISXM_Settings::get( 'endpoint' );

        $pairs   = [];
        $count   = 0;
        $last_id = $after_id;

        foreach ( $rows as $row ) {
            $last_id = (int) $row->ID;
            $info    = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $info ) ) {
                continue;
            }
            // Only records pointing at the CURRENT destination are ours to
            // verify — meta from a previous provider/bucket is pending by
            // definition (matches get_offloaded_ids()).
            if ( ( $info['bucket'] ?? '' ) !== $current_bucket ) {
                continue;
            }
            if ( ( $info['endpoint'] ?? '' ) !== $current_endpoint ) {
                continue;
            }
            foreach ( self::expected_keys( $info ) as $key ) {
                $pairs[] = [ $key, $last_id ];
            }
            $count++;
        }

        return [
            'pairs'   => $pairs,
            'count'   => $count,
            'last_id' => $last_id,
            'done'    => count( $rows ) < $limit,
        ];
    }

    /**
     * Build the full expected map + per-attachment file counts + primary
     * keys from every tracked attachment (one DB pass, no network).
     *
     * @return array{map:array<string,int>,counts:array<int,int>,primary:array<int,string>,attach_count:int}
     */
    public static function build_expected_map() {
        $expected = [];
        $counts   = [];
        $primary  = [];
        $attach_count = 0;
        $after_id = 0;

        do {
            $window   = self::scan_expected_window( 200, $after_id );
            $after_id = $window['last_id'];
            $attach_count += $window['count'];
            foreach ( $window['pairs'] as $pair ) {
                list( $key, $id ) = $pair;
                if ( ! isset( $expected[ $key ] ) ) {
                    $expected[ $key ] = $id;
                }
                if ( ! isset( $counts[ $id ] ) ) {
                    $counts[ $id ]  = 1;
                    $primary[ $id ] = $key; // first key per attachment = primary
                } else {
                    $counts[ $id ]++;
                }
            }
        } while ( ! $window['done'] );

        return [
            'map'          => $expected,
            'counts'       => $counts,
            'primary'      => $primary,
            'attach_count' => $attach_count,
        ];
    }

    /* ---------------------------------------------------------------------
     * Temp-file scan state (uploads/isxs-sync/) — the expected-key set and
     * the listed-key stream can be hundreds of thousands of lines on huge
     * libraries, which is too big to round-trip through a transient every
     * request. Cursors and counters stay in a small transient instead.
     * ------------------------------------------------------------------ */

    /**
     * @return string Directory path (created with a deny-all .htaccess).
     */
    public static function state_dir() {
        $uploads = wp_get_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . self::SYNC_DIR_NAME;
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // These files hold object keys — nothing secret, but they are
            // not meant to be servable either.
            @file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
            @file_put_contents( $dir . '/index.php', "<?php // silence is golden\n" );
        }
        return $dir;
    }

    /**
     * @param string $run_id
     * @return string Absolute path of this run's expected-key file.
     */
    public static function expected_file( $run_id ) {
        return trailingslashit( self::state_dir() ) . $run_id . '.expected.jsonl';
    }

    /**
     * @param string $run_id
     * @return string Absolute path of this run's listed-keys file.
     */
    public static function listed_file( $run_id ) {
        return trailingslashit( self::state_dir() ) . $run_id . '.listed.jsonl';
    }

    /**
     * Append [key, id] pairs (json lines) to the run's expected file.
     *
     * @param string   $run_id
     * @param array    $pairs list of [string key, int id].
     */
    public static function append_expected( $run_id, array $pairs ) {
        if ( empty( $pairs ) ) {
            return;
        }
        $lines = '';
        foreach ( $pairs as $pair ) {
            $lines .= wp_json_encode( $pair ) . "\n";
        }
        @file_put_contents( self::expected_file( $run_id ), $lines, FILE_APPEND );
    }

    /**
     * Append keys (json lines) to the run's listed file.
     *
     * @param string   $run_id
     * @param string[] $keys
     */
    public static function append_listed( $run_id, array $keys ) {
        if ( empty( $keys ) ) {
            return;
        }
        $lines = '';
        foreach ( $keys as $key ) {
            $lines .= wp_json_encode( [ $key ] ) . "\n";
        }
        @file_put_contents( self::listed_file( $run_id ), $lines, FILE_APPEND );
    }

    /**
     * Load the run's expected file into memory (one request needs it at a
     * time — the finish phase and the orphan-cleanup delete phase).
     *
     * @param string $run_id
     * @return array{map:array<string,int>,counts:array<int,int>,primary:array<int,string>}|null
     *               null when the file is missing (expired/cleaned run).
     */
    public static function load_expected( $run_id ) {
        $file = self::expected_file( $run_id );
        if ( ! is_file( $file ) ) {
            return null;
        }
        $map     = [];
        $counts  = [];
        $primary = [];
        $fh      = @fopen( $file, 'r' );
        if ( $fh === false ) {
            return null;
        }
        while ( ( $line = fgets( $fh ) ) !== false ) {
            $pair = json_decode( $line, true );
            if ( ! is_array( $pair ) || count( $pair ) !== 2 ) {
                continue;
            }
            $key = (string) $pair[0];
            $id  = (int) $pair[1];
            if ( $key === '' || $id <= 0 ) {
                continue;
            }
            if ( ! isset( $map[ $key ] ) ) {
                $map[ $key ] = $id;
            }
            if ( ! isset( $counts[ $id ] ) ) {
                $counts[ $id ]  = 1;
                $primary[ $id ] = $key; // first key per attachment = primary
            } else {
                $counts[ $id ]++;
            }
        }
        fclose( $fh );
        return [ 'map' => $map, 'counts' => $counts, 'primary' => $primary ];
    }

    /**
     * The expected-key map the finish phase needs, loaded from the run's
     * temp file — or rebuilt from the DB in one pass when the file is
     * missing (deleted out-of-band, or never created because no attachment
     * tracked the current destination, e.g. right after switching
     * provider). Rebuilding never fails, so a scan can't die on a missing
     * file.
     *
     * @param string $run_id
     * @return array{map:array<string,int>,counts:array<int,int>,primary:array<int,string>}
     */
    public static function load_or_build_expected( $run_id ) {
        $e = self::load_expected( $run_id );
        if ( $e !== null ) {
            return $e;
        }
        return self::build_expected_map();
    }

    /**
     * Delete both temp files of one run.
     *
     * @param string $run_id
     */
    public static function cleanup_run_files( $run_id ) {
        foreach ( [ self::expected_file( $run_id ), self::listed_file( $run_id ) ] as $file ) {
            if ( is_file( $file ) ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Remove temp files from abandoned runs (called at scan start) so a
     * closed tab mid-scan can't leave them forever.
     */
    public static function cleanup_old_files() {
        $dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . self::SYNC_DIR_NAME;
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( glob( $dir . '/*.{expected,listed}.jsonl', GLOB_BRACE ) ?: [] as $file ) {
            if ( is_file( $file ) && ( time() - filemtime( $file ) ) > self::FILE_TTL ) {
                @unlink( $file );
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Full synchronous scan (WP-CLI path) + outcome classification
     * ------------------------------------------------------------------ */

    /**
     * Full synchronous scan: build the expected map from every tracked
     * attachment, then list the whole bucket and diff in memory (CLI has no
     * per-request budget, so no temp files needed).
     *
     * @return array{stale_ids:int[],partial_ids:int[],orphan:int,orphan_sample:string[],outside_prefix:int,scanned_objects:int,attach_count:int}|WP_Error
     */
    public static function scan_full() {
        $e = self::build_expected_map();

        $client        = new ISXM_Client();
        $token         = '';
        $orphan        = 0;
        $orphan_sample = [];
        $outside       = 0;
        $scanned       = 0;
        $found         = [];        // attachment id => files found
        $primary_found = [];        // attachment id => primary key seen
        $prefix        = ISXM_Settings::key_prefix();

        do {
            $page = $client->list_objects_keys_page( $token, 1000 );
            if ( is_wp_error( $page ) ) {
                return $page;
            }
            foreach ( $page['keys'] as $key ) {
                if ( isset( $e['map'][ $key ] ) ) {
                    $id = $e['map'][ $key ];
                    unset( $e['map'][ $key ] );
                    $found[ $id ] = ( $found[ $id ] ?? 0 ) + 1;
                    if ( ( $e['primary'][ $id ] ?? '' ) === $key ) {
                        $primary_found[ $id ] = true;
                    }
                } elseif ( $prefix !== '' && strpos( $key, $prefix ) !== 0 ) {
                    $outside++;
                } else {
                    $orphan++;
                    if ( count( $orphan_sample ) < self::ORPHAN_SAMPLE_MAX ) {
                        $orphan_sample[] = $key;
                    }
                }
            }
            $scanned += count( $page['keys'] );
            $token    = $page['next_token'];
        } while ( $token !== '' );

        $result = self::classify( $e, $found, $primary_found, $orphan, $orphan_sample, $outside, $scanned );
        self::mark_run( self::result_is_clean( $result ) );
        return $result;
    }

    /**
     * Turn a diffed expected set into stale / partial / complete buckets.
     *
     * @param array    $e             build_expected_map() output (map already diffed).
     * @param array    $found         attachment id => files found.
     * @param array    $primary_found attachment id => primary key seen.
     * @param int      $orphan        Unmatched keys under the prefix.
     * @param string[] $orphan_sample
     * @param int      $outside       Unmatched keys outside the prefix.
     * @param int      $scanned       Objects listed.
     * @return array
     */
    private static function classify( array $e, array $found, array $primary_found, $orphan, array $orphan_sample, $outside, $scanned ) {
        $stale_ids   = [];
        $partial_ids = [];
        foreach ( $e['counts'] as $id => $expected_n ) {
            if ( empty( $primary_found[ $id ] ) ) {
                $stale_ids[] = $id;          // primary gone — whole attachment missing
            } elseif ( ( $found[ $id ] ?? 0 ) < $expected_n ) {
                $partial_ids[] = $id;        // primary present, some sizes missing
            }
        }

        return [
            'stale_ids'      => $stale_ids,
            'partial_ids'    => $partial_ids,
            'orphan'         => $orphan,
            'orphan_sample'  => $orphan_sample,
            'outside_prefix' => $outside,
            'scanned_objects'=> $scanned,
            'attach_count'   => $e['attach_count'],
        ];
    }

    /* ---------------------------------------------------------------------
     * Apply
     * ------------------------------------------------------------------ */

    /**
     * Drop the offload tracking meta (and any failure record) for
     * attachments whose files are no longer in the bucket, so Offload and
     * Migrate treat them as pending again. Each record is re-checked before
     * deletion — only ones still pointing at the current destination are
     * touched, so a file re-offloaded by another tool since the scan is
     * never un-tracked.
     *
     * Attachments whose LOCAL copy is also gone are flagged with the
     * `_isxs_data_loss` meta — there is nothing left to re-upload from, so
     * the Media Library shows a distinct "ไฟล์หายทั้งสองที่" state instead
     * of silently turning them into plain pending items that will just fail
     * with "ไม่พบไฟล์ต้นฉบับ".
     *
     * @param int[] $ids Attachment IDs found stale by the scan.
     * @return array{cleaned:int,data_loss:int}
     */
    public static function cleanup_stale( array $ids ) {
        $current_bucket   = ISXM_Settings::get( 'bucket' );
        $current_endpoint = ISXM_Settings::get( 'endpoint' );
        $cleaned          = 0;
        $data_loss        = 0;

        ISXM_Tools::prime_caches( $ids );

        foreach ( $ids as $id ) {
            $id   = (int) $id;
            $info = ISXM_Offload::get_record( $id );
            if ( ! is_array( $info ) ) {
                continue;
            }
            if ( ( $info['bucket'] ?? '' ) !== $current_bucket ) {
                continue;
            }
            if ( ( $info['endpoint'] ?? '' ) !== $current_endpoint ) {
                continue;
            }

            if ( ! ISXM_Offload::local_file_exists( $id ) ) {
                update_post_meta( $id, ISXM_Offload::DATA_LOSS_META_KEY, time() );
                $data_loss++;
            } else {
                delete_post_meta( $id, ISXM_Offload::DATA_LOSS_META_KEY );
            }

            ISXM_Offload::delete_record( $id );
            delete_post_meta( $id, ISXM_Offload::ERROR_META_KEY );
            $cleaned++;
        }

        return [ 'cleaned' => $cleaned, 'data_loss' => $data_loss ];
    }

    /* ---------------------------------------------------------------------
     * Orphan cleanup
     * ------------------------------------------------------------------ */

    /**
     * Delete objects in the bucket that no attachment's meta accounts for
     * AND that live under the configured prefix (keys outside the prefix
     * are never touched — the bucket may be shared). Rebuilds the expected
     * map first so uploads that landed since the scan are not misread as
     * orphans. Synchronous (WP-CLI path).
     *
     * @return int|WP_Error Objects deleted.
     */
    public static function cleanup_orphans() {
        $e = self::build_expected_map();

        $client = new ISXM_Client();
        $prefix = ISXM_Settings::key_prefix();
        $deleted = 0;
        $token   = '';

        do {
            $page = $client->list_objects_keys_page( $token, 1000 );
            if ( is_wp_error( $page ) ) {
                return $page;
            }
            foreach ( $page['keys'] as $key ) {
                if ( isset( $e['map'][ $key ] ) ) {
                    continue;
                }
                if ( $prefix !== '' && strpos( $key, $prefix ) !== 0 ) {
                    continue;
                }
                $result = $client->delete_object( $key );
                if ( ! is_wp_error( $result ) ) {
                    $deleted++;
                }
            }
            $token = $page['next_token'];
        } while ( $token !== '' );

        return $deleted;
    }

    /* ---------------------------------------------------------------------
     * Last-run tracking — lets the status widget nudge the user back to
     * Sync when a scan hasn't run in a while (files can go missing from
     * the bucket out-of-band, and nothing else notices).
     * ------------------------------------------------------------------ */

    /**
     * Record that a scan just finished cleanly (no errors). Called from
     * the finish phase of the AJAX scan and the WP-CLI full scan — never
     * from apply, since a scan that reports "all complete" still counts
     * as having checked.
     *
     * @param bool|null $clean Whether that scan found nothing out of sync.
     *                         null (the default) leaves the previous verdict
     *                         alone, for callers that don't have one.
     */
    public static function mark_run( $clean = null ) {
        update_option( self::LAST_RUN_OPTION, time(), false );
        if ( $clean !== null ) {
            update_option( self::LAST_CLEAN_OPTION, $clean ? 1 : 0, false );
        }
    }

    /**
     * @return int|null Unix timestamp of the last clean scan, or null if
     *                   Sync has never completed one.
     */
    public static function last_run() {
        $value = get_option( self::LAST_RUN_OPTION );
        return is_numeric( $value ) ? (int) $value : null;
    }

    /**
     * Verdict of the last finished scan — what the status badge shows
     * (green "everything matches" vs red "found differences") on a fresh
     * page load, when the JS has no scan result of its own yet.
     *
     * @return bool|null true = everything matched, false = differences
     *                   found, null = never scanned (or a scan from a
     *                   version that didn't record a verdict).
     */
    public static function last_clean() {
        $value = get_option( self::LAST_CLEAN_OPTION, null );
        return $value === null || $value === false ? null : (bool) (int) $value;
    }

    /**
     * Whether a scan result counts as "everything matches" — the same
     * condition the JS uses to paint the badge green, kept here so the
     * server-rendered badge and the live one can never disagree.
     *
     * @param array $result A scan result (classify() output).
     * @return bool
     */
    public static function result_is_clean( array $result ) {
        return empty( $result['stale_ids'] )
            && empty( $result['partial_ids'] )
            && empty( $result['orphan'] )
            && empty( $result['outside_prefix'] );
    }

    /**
     * @return bool Whether it's been long enough since the last clean scan
     *              (or there has never been one) to nudge the user.
     */
    public static function is_stale() {
        $last = self::last_run();
        return $last === null || ( time() - $last ) > self::STALE_AFTER;
    }

    /* ---------------------------------------------------------------------
     * Per-run state (shared between the batched AJAX scan and its apply)
     * ------------------------------------------------------------------ */

    private static function state_key( $run_id ) {
        return self::STATE_TRANSIENT_PREFIX . $run_id;
    }

    private static function result_key( $run_id ) {
        return self::RESULT_TRANSIENT_PREFIX . $run_id;
    }

    /**
     * @param string $run_id
     * @return array|null The saved scan state, or null when none exists.
     */
    public static function load_state( $run_id ) {
        $state = get_transient( self::state_key( $run_id ) );
        return is_array( $state ) ? $state : null;
    }

    /**
     * @param string $run_id
     * @param array  $state Scan state (phase, cursors, counts...).
     */
    public static function save_state( $run_id, array $state ) {
        set_transient( self::state_key( $run_id ), $state, self::STATE_TTL );
    }

    /**
     * @param string $run_id
     */
    public static function delete_state( $run_id ) {
        delete_transient( self::state_key( $run_id ) );
    }

    /**
     * @param string $run_id
     * @param array  $result Scan outcome (stale ids, orphan stats...).
     */
    public static function save_result( $run_id, array $result ) {
        set_transient( self::result_key( $run_id ), $result, self::STATE_TTL );
    }

    /**
     * @param string $run_id
     * @return array|null The saved scan result, or null when none exists.
     */
    public static function get_result( $run_id ) {
        $result = get_transient( self::result_key( $run_id ) );
        return is_array( $result ) ? $result : null;
    }

    /**
     * @param string $run_id
     */
    public static function delete_result( $run_id ) {
        delete_transient( self::result_key( $run_id ) );
    }
}
