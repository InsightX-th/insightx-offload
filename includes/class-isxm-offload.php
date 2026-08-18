<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Offload — Core offload engine + URL rewriting for InsightX Storage.
 *
 * Uploads attachments (original + all sizes) to the configured bucket on
 * upload, deletes remote objects when the attachment is deleted, and
 * rewrites media URLs to the bucket/CDN when delivery is enabled.
 *
 * Attachment meta `_isxs_offload`:
 *   [
 *     'bucket'     => 'my-bucket',
 *     'base_key'   => 'wp-content/uploads/2026/07/08114412/',
 *     'version'    => '12345678',
 *     'files'      => [ 'example.jpg', 'example-300x200.jpg', ... ],
 *     'endpoint'   => 'minio.example.com',   // recorded so download/remove
 *     'region'     => 'us-east-1',           // always target the bucket
 *     'path_style' => true,                  // this file actually lives in,
 *     'origin'     => 'offload',             // not whatever is configured now
 *     'missing'    => [ 'example-150x150.jpg' ], // sizes that could NOT be
 *   ]                                        // uploaded (absent locally or
 *                                             // rejected) — the attachment is
 *                                             // on the bucket but INCOMPLETE
 *                                             // 'origin' is 'offload'|'migrate' — which
 *                                             // tool put it here; get_stats() only
 *                                             // counts 'offload' toward the
 *                                             // Overview ring (see class-isxm-tools.php)
 *
 * Attachment meta `_isxs_offload_error` (present only while the last attempt
 * failed; deleted the moment one succeeds):
 *   [ 'code' => 'isxs_upload_failed', 'message' => '…', 'file' => 'x.jpg',
 *     'time' => 1767225600, 'tries' => 2 ]
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Offload {

    const META_KEY = '_isxs_offload';

    /** Set by the Sync tool's cleanup on attachments whose LOCAL copy is
     *  ALSO gone (nothing left to re-upload from) — the Media Library shows
     *  a distinct "ไฟล์หายทั้งสองที่" state for these instead of a plain
     *  pending that would just fail with "ไม่พบไฟล์ต้นฉบับ". Cleared the
     *  moment a real offload succeeds. */
    const DATA_LOSS_META_KEY = '_isxs_data_loss';

    /** Records why the last offload attempt failed, so the failure is visible
     *  after the run ends (the tool UI only shows errors while it's running)
     *  and so "retry only what failed" can find these without a full scan. */
    const ERROR_META_KEY = '_isxs_offload_error';

    /** Keep stored failure messages short — this meta is read for every row
     *  on the Media Library screen. */
    const ERROR_MESSAGE_MAX = 500;

    /* ---------------------------------------------------------------------
     * Tracking record access
     *
     * Since 0.2.0 the record lives in the ISXM_Items table (indexed, so the
     * counts and scans stop being full-table LIKE scans) AND in the
     * original postmeta. Reads prefer the table once its backfill has
     * finished; writes always update both, so the postmeta stays a
     * complete, current copy for a downgrade or for third-party code that
     * reads `_isxs_offload` directly.
     * ------------------------------------------------------------------ */

    /**
     * One attachment's tracking record, or null when it isn't on a bucket.
     *
     * @param int $attachment_id
     * @return array|null
     */
    public static function get_record( $attachment_id ) {
        if ( ISXM_Items::ready() ) {
            $record = ISXM_Items::get( $attachment_id );
            if ( $record !== null ) {
                return $record;
            }
            // Fall through: an attachment offloaded by an older version
            // between the backfill finishing and this read is vanishingly
            // unlikely, but reading the postmeta costs nothing when the
            // table says "no row" and it can only ever add correctness.
        }

        $record = get_post_meta( $attachment_id, self::META_KEY, true );
        return is_array( $record ) ? $record : null;
    }

    /**
     * Persist one attachment's tracking record to both stores.
     *
     * @param int   $attachment_id
     * @param array $record
     */
    public static function set_record( $attachment_id, array $record ) {
        update_post_meta( $attachment_id, self::META_KEY, $record );
        ISXM_Items::put( $attachment_id, $record );
    }

    /**
     * Forget one attachment's tracking record in both stores.
     *
     * @param int $attachment_id
     */
    public static function delete_record( $attachment_id ) {
        delete_post_meta( $attachment_id, self::META_KEY );
        ISXM_Items::delete( $attachment_id );
    }

    /**
     * Build a client pinned to the bucket/endpoint an attachment was
     * actually offloaded to, per its own tracking meta — not whatever the
     * destination settings currently say. Falls back to current settings
     * for the fields a record doesn't have (older records written before
     * `endpoint`/`region`/`path_style` were tracked), which reproduces the
     * previous behaviour for that case exactly.
     *
     * @param array $info The `_isxs_offload` meta array.
     * @return ISXM_Client
     */
    public static function client_for_info( array $info ) {
        $overrides = [];
        // Only pass along fields the record actually has — an empty/missing
        // key must NOT override live settings with '' (ISXM_Client's
        // wp_parse_args() treats a present-but-empty key as an explicit
        // choice, not "unset"), or older records predating this field would
        // silently lose their working endpoint instead of falling back.
        foreach ( [ 'endpoint', 'bucket', 'region', 'path_style' ] as $key ) {
            if ( isset( $info[ $key ] ) && $info[ $key ] !== '' ) {
                $overrides[ $key ] = $info[ $key ];
            }
        }
        return new ISXM_Client( $overrides );
    }

    /** @var array Per-request cache for content URL → attachment id lookups. */
    private static $url_post_cache = [];

    public function __construct() {
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'offload_on_generate' ], 110, 2 );
        add_action( 'delete_attachment', [ $this, 'delete_remote_on_delete' ], 10, 1 );

        add_filter( 'wp_get_attachment_url', [ $this, 'rewrite_attachment_url' ], 99, 2 );
        add_filter( 'wp_calculate_image_srcset_meta', [ $this, 'fix_srcset_meta' ], 99, 4 );
        add_filter( 'wp_calculate_image_srcset', [ $this, 'rewrite_srcset' ], 99, 5 );
        add_filter( 'the_content', [ $this, 'rewrite_content_urls' ], 99 );
    }

    /* ---------------------------------------------------------------------
     * Offload
     * ------------------------------------------------------------------ */

    /**
     * Hook: offload right after WordPress generates attachment metadata.
     */
    public function offload_on_generate( $metadata, $attachment_id ) {
        if ( ISXM_Settings::get( 'offload_enabled' ) && ISXM_Settings::is_configured() ) {
            $result = $this->offload_attachment( $attachment_id, $metadata );
            if ( is_wp_error( $result ) ) {
                isxm_log_error( 'Offload failed for #' . $attachment_id . ': ' . $result->get_error_message() );
            }
        }
        return $metadata;
    }

    /**
     * Offload one attachment (original + sizes) to the bucket.
     *
     * @param int        $attachment_id Attachment post ID.
     * @param array|null $metadata      Attachment metadata (loaded if null).
     * @param bool       $defer_persist Skip the per-attachment DB URL rewrite —
     *                                  bulk callers collect the pairs of a whole
     *                                  batch (collect_url_pairs) and run one
     *                                  ISXM_DB_Rewriter::replace_urls_bulk() instead.
     * @param string     $origin        Which tool caused this offload — 'offload'
     *                                  (default: standalone Offload tool, auto-offload
     *                                  on upload, WP-CLI) or 'migrate' (ISXM_Migrate).
     *                                  Recorded in the meta so get_stats() can count
     *                                  only 'offload'-origin files toward the Overview
     *                                  ring, without changing which files the Offload/
     *                                  Remove tools themselves treat as pending.
     * @return true|WP_Error
     */
    public function offload_attachment( $attachment_id, $metadata = null, $defer_persist = false, $origin = 'offload' ) {
        $result = $this->run_offload( $attachment_id, $metadata, $defer_persist, $origin );

        if ( is_wp_error( $result ) ) {
            self::record_error( $attachment_id, $result );
        } else {
            self::clear_error( $attachment_id );
        }

        return $result;
    }

    /**
     * The actual offload. Every failure path returns a WP_Error, which
     * offload_attachment() turns into the `_isxs_offload_error` record —
     * keeping the bookkeeping in exactly one place.
     *
     * @return true|WP_Error
     */
    private function run_offload( $attachment_id, $metadata, $defer_persist, $origin ) {
        if ( ! ISXM_Settings::is_configured() ) {
            return new WP_Error( 'isxs_not_configured', 'ยังตั้งค่า storage ไม่ครบ' );
        }

        $file = get_attached_file( $attachment_id, true );
        if ( ! $file || ! file_exists( $file ) ) {
            // With "Remove Local Media" on, a file already living in some
            // bucket legitimately has no local copy — say so, because the fix
            // (download it back first) is different from a genuinely lost file.
            if ( self::get_record( $attachment_id ) !== null ) {
                return new WP_Error(
                    'isxs_local_removed',
                    'ไฟล์ถูกลบออกจากเซิร์ฟเวอร์แล้ว (อยู่บน bucket เดิม) — ใช้เครื่องมือ "ดาวน์โหลดไฟล์กลับจาก bucket" ก่อน แล้วค่อย offload ใหม่'
                );
            }
            return new WP_Error( 'isxs_missing_local', 'ไม่พบไฟล์ต้นฉบับบนเซิร์ฟเวอร์' );
        }

        if ( $metadata === null ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );
        }

        $settings  = ISXM_Settings::all();
        $local_dir = trailingslashit( dirname( $file ) );
        $filenames = self::collect_filenames( $file, $metadata );

        // Reuse the existing object version on re-offload so URLs stay stable.
        $existing = self::get_record( $attachment_id );

        $base_key = ISXM_Settings::key_prefix();
        if ( $settings['use_year_month'] ) {
            $relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
            $subdir   = dirname( $relative );
            if ( $subdir !== '.' && $subdir !== '' ) {
                $base_key .= trailingslashit( $subdir );
            }
        }
        if ( $settings['use_object_version'] ) {
            if ( is_array( $existing ) && ! empty( $existing['version'] ) ) {
                $version = $existing['version'];
            } else {
                $version = (string) wp_rand( 10000000, 99999999 );
            }
            $base_key .= $version . '/';
        } else {
            $version = '';
        }

        $client   = new ISXM_Client();
        $uploaded = [];
        $missing  = [];

        // The original file is the one that matters: without it the
        // attachment simply isn't on the bucket, so a failure there fails the
        // whole item. A generated size failing is worth recording but must
        // not throw away the sizes that DID upload — that used to leave the
        // attachment with no meta at all, so every retry started from zero.
        $primary = wp_basename( $file );

        foreach ( $filenames as $filename ) {
            $path = $local_dir . $filename;
            if ( ! file_exists( $path ) ) {
                if ( $filename === $primary ) {
                    return new WP_Error( 'isxs_missing_local', 'ไม่พบไฟล์ต้นฉบับบนเซิร์ฟเวอร์' );
                }
                $missing[] = $filename;
                continue;
            }
            $filetype = wp_check_filetype( $filename );
            $mime     = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

            $result = $client->put_object( $base_key . $filename, $path, $mime, ! empty( $settings['send_public_acl'] ) );
            if ( is_wp_error( $result ) ) {
                if ( $filename === $primary ) {
                    return new WP_Error( 'isxs_upload_failed', sprintf( 'อัปโหลด %s ไม่สำเร็จ: %s', $filename, $result->get_error_message() ) );
                }
                isxm_log_error( sprintf( 'Offload #%d: size %s failed — %s', $attachment_id, $filename, $result->get_error_message() ) );
                $missing[] = $filename;
                continue;
            }
            $uploaded[] = $filename;
        }

        if ( empty( $uploaded ) ) {
            return new WP_Error( 'isxs_nothing_uploaded', 'ไม่มีไฟล์ให้อัปโหลด' );
        }

        $record = [
            'bucket'     => $settings['bucket'],
            'base_key'   => $base_key,
            'version'    => $version,
            'files'      => $uploaded,
            // Appended after the original 4 keys on purpose — get_stats()
            // LIKE-matches the serialized meta assuming 'bucket' is the
            // first key (class-isxm-tools.php); inserting before it would
            // silently break that.
            'endpoint'   => $settings['endpoint'],
            'region'     => $settings['region'],
            'path_style' => $settings['path_style'],
            'origin'     => $origin,
        ];
        if ( ! empty( $missing ) ) {
            // Same reason as above: appended last, never inserted.
            $record['missing'] = $missing;
        }
        self::set_record( $attachment_id, $record );
        // A real upload succeeded — whatever "file missing both places"
        // flag the Sync tool may have left is now wrong.
        delete_post_meta( $attachment_id, self::DATA_LOSS_META_KEY );

        // Rewrite any local upload URLs already hard-coded into the DB
        // (post_content, postmeta, options, guid) to the new remote URL —
        // the runtime rewrite filters (rewrite_attachment_url/srcset/content)
        // stay active as a fallback for anything this pass doesn't reach.
        // No-op when the permanent rewrite is switched off (persist_urls).
        if ( ! $defer_persist ) {
            self::persist_permanent_urls( $attachment_id );
        }

        // Order matters here and is deliberately safe: the permanent DB URL
        // rewrite above (persist_permanent_urls, unless deferred) runs
        // BEFORE any local file is deleted, so a crash between the two
        // leaves the local copies on disk — the worst case is wasted disk,
        // never DB URLs pointing at files that no longer exist. (The
        // reverse order is exactly the failure mode "Remove Local Media"
        // must avoid, which is why bulk runs queue deletions until their
        // batch rewrite has actually landed; see queue_local_deletion().)
        //
        // With the permanent rewrite OFF, the database never gets the remote
        // URLs — so local files may only be removed when the runtime
        // delivery filters will still rewrite them at render time
        // (deliver_enabled). With both off, deleting local copies would
        // leave DB URLs pointing at files that no longer exist, so the
        // files are kept instead (the toggle simply has no effect).
        $urls_stay_resolvable = $settings['persist_urls'] || $settings['deliver_enabled'];
        if ( $settings['remove_local'] && $urls_stay_resolvable ) {
            $paths = [];
            foreach ( $uploaded as $filename ) {
                $paths[] = $local_dir . $filename;
            }
            if ( $defer_persist ) {
                // Bulk callers flush the DB URL rewrite once at the end of the
                // batch, so deleting here would leave a window where the local
                // file is gone but the database still points at it — and if
                // the request dies in that window (504, fatal), the images
                // stay broken for good unless delivery rewriting happens to be
                // switched on. Queue instead; the caller deletes only after
                // the rewrite has actually landed.
                self::queue_local_deletion( $paths );
            } else {
                self::delete_local_files( $paths );
            }
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * Deferred local-file deletion ("Remove Local Media" during bulk runs)
     * ------------------------------------------------------------------ */

    /** @var string[] Local files to delete once the batch's DB rewrite lands. */
    private static $pending_deletions = [];

    /**
     * Hold local files back until the batch's URL rewrite has been written.
     *
     * @param string[] $paths Absolute local paths.
     */
    private static function queue_local_deletion( array $paths ) {
        foreach ( $paths as $path ) {
            self::$pending_deletions[] = $path;
        }
    }

    /**
     * The local files queued so far this request. Bulk callers that must
     * hold deletions until a later phase (the one-pass DB rewrite at the
     * end of an offload run) spill these somewhere durable instead of
     * letting the per-request queue die with the request — see
     * ISXM_Tools::spill_local_deletions().
     *
     * @return string[] Absolute local paths.
     */
    public static function pending_deletions() {
        return self::$pending_deletions;
    }

    /**
     * Drop the per-request deletion queue without deleting anything —
     * called by a caller that has just spilled the paths elsewhere.
     *
     * @return void
     */
    public static function clear_pending_deletions() {
        self::$pending_deletions = [];
    }

    /**
     * Delete every local file queued during this batch. Call AFTER
     * ISXM_DB_Rewriter::replace_urls_bulk() — never before.
     *
     * A batch that dies before reaching this simply leaves the local copies
     * in place. That wastes disk until the tool is run again, which is the
     * right way to fail: the alternative (deleting first) loses the file
     * while the database still points at it.
     *
     * @return int Files deleted.
     */
    public static function flush_local_deletions() {
        $paths                   = array_unique( self::$pending_deletions );
        self::$pending_deletions = [];

        return self::delete_local_files( $paths );
    }

    /**
     * @param string[] $paths Absolute local paths.
     * @return int Files deleted.
     */
    public static function delete_local_files( array $paths ) {
        $deleted = 0;
        // Last gate before any unlink: only delete files that resolve
        // INSIDE the uploads directory. collect_filenames()/safe_filename()
        // already refuse traversal names, but this is the final chance for
        // a name that somehow slipped through — it must not be able to
        // take out an arbitrary file (e.g. wp-config.php) via "Remove
        // Local Media".
        $uploads = wp_get_upload_dir();
        $base    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
        // Resolve the uploads dir through symlinks too, so a site whose
        // uploads path goes through one (e.g. /var/www → /private/var/www)
        // still matches the realpath() of its own files — otherwise the
        // containment check below would silently skip EVERY deletion.
        $real_base = realpath( $base );
        if ( $real_base !== false ) {
            $base = wp_normalize_path( trailingslashit( $real_base ) );
        }
        foreach ( $paths as $path ) {
            $real = realpath( (string) $path );
            // realpath() returns false for a missing file, which also keeps
            // the previous "only count existing files" behaviour.
            if ( $real === false || strpos( wp_normalize_path( $real ), $base ) !== 0 ) {
                continue;
            }
            wp_delete_file( $real );
            $deleted++;
        }
        return $deleted;
    }

    /* ---------------------------------------------------------------------
     * Failure bookkeeping
     * ------------------------------------------------------------------ */

    /**
     * Remember why an attachment could not be offloaded. `tries` accumulates
     * across attempts so a file that keeps failing is distinguishable from
     * one that just hit a blip.
     *
     * @param int      $attachment_id Attachment post ID.
     * @param WP_Error $error         The failure.
     */
    public static function record_error( $attachment_id, $error ) {
        $previous = get_post_meta( $attachment_id, self::ERROR_META_KEY, true );
        $tries    = is_array( $previous ) && isset( $previous['tries'] ) ? (int) $previous['tries'] : 0;

        update_post_meta( $attachment_id, self::ERROR_META_KEY, [
            'code'    => $error->get_error_code(),
            'message' => mb_substr( $error->get_error_message(), 0, self::ERROR_MESSAGE_MAX ),
            'file'    => wp_basename( (string) get_attached_file( $attachment_id ) ),
            'time'    => time(),
            'tries'   => $tries + 1,
        ] );
    }

    /**
     * Drop the failure record — the attachment made it to the bucket.
     */
    public static function clear_error( $attachment_id ) {
        delete_post_meta( $attachment_id, self::ERROR_META_KEY );
    }

    /**
     * Offload status of one attachment, for the Media Library column.
     *
     * @param int        $attachment_id Attachment post ID.
     * @param array|null $info          Pre-fetched `_isxs_offload` meta (avoids a lookup).
     * @param array|null $error         Pre-fetched `_isxs_offload_error` meta.
     * @return array{status:string,info:array|null,error:array|null}
     *         status: offloaded|partial|failed|other_bucket|pending
     */
    public static function status_for( $attachment_id, $info = null, $error = null ) {
        if ( $info === null ) {
            $info = self::get_record( $attachment_id );
        }
        if ( $error === null ) {
            $error = get_post_meta( $attachment_id, self::ERROR_META_KEY, true );
        }
        $info  = is_array( $info ) ? $info : null;
        $error = is_array( $error ) ? $error : null;

        // Destination-aware, exactly like ISXM_Tools::get_pending_ids() —
        // a record pointing at a bucket/endpoint that isn't configured
        // anymore is not "on the cloud" as far as this site is concerned.
        $on_current = $info
            && isset( $info['bucket'] ) && $info['bucket'] === ISXM_Settings::get( 'bucket' )
            && ( $info['endpoint'] ?? '' ) === ISXM_Settings::get( 'endpoint' );

        if ( $on_current ) {
            $status = empty( $info['missing'] ) ? 'offloaded' : 'partial';
        } elseif ( $error ) {
            $status = 'failed';
        } elseif ( $info ) {
            $status = 'other_bucket';
        } else {
            $status = 'pending';
        }

        return [ 'status' => $status, 'info' => $info, 'error' => $error ];
    }

    /**
     * Download an offloaded attachment's files back to the server.
     *
     * @param int $attachment_id Attachment post ID.
     * @return true|WP_Error
     */
    public function download_attachment( $attachment_id ) {
        $info = self::get_record( $attachment_id );
        if ( ! is_array( $info ) || empty( $info['files'] ) ) {
            return new WP_Error( 'isxs_not_offloaded', 'รายการนี้ยังไม่ได้ offload' );
        }

        $file = get_attached_file( $attachment_id, true );
        if ( ! $file ) {
            return new WP_Error( 'isxs_no_path', 'ไม่ทราบตำแหน่งไฟล์ปลายทาง' );
        }
        $local_dir = trailingslashit( dirname( $file ) );
        wp_mkdir_p( $local_dir );

        $client = self::client_for_info( $info );
        foreach ( $info['files'] as $filename ) {
            // Stored meta is the one place a traversal name could have
            // been persisted without going through collect_filenames() —
            // validate before it becomes a write target, and refuse the
            // whole attachment rather than silently skip the file (a
            // skipped file would later look "downloaded" to Remove).
            $filename = self::safe_filename( $filename );
            if ( $filename === '' ) {
                return new WP_Error( 'isxs_unsafe_filename', 'ชื่อไฟล์ไม่ถูกต้องในข้อมูล attachment — ตรวจสอบ meta' );
            }
            $path = $local_dir . $filename;
            if ( file_exists( $path ) ) {
                continue;
            }
            // Streamed to disk rather than returned as a string: a multi-GB
            // download used to be held whole in memory, and blowing
            // memory_limit is a fatal that takes the entire batch with it.
            $fetched = $client->get_object_to_file( $info['base_key'] . $filename, $path );
            if ( is_wp_error( $fetched ) ) {
                // Distinguish "already gone from the bucket" (404) from real
                // failures (auth/network) — remove_remote_attachment() treats
                // the former as safe to proceed with instead of getting stuck.
                $code = ( $fetched->get_error_code() === 'isxs_http_404' ) ? 'isxs_download_missing' : 'isxs_download_failed';
                return new WP_Error( $code, sprintf( 'ดาวน์โหลด %s ไม่สำเร็จ: %s', $filename, $fetched->get_error_message() ) );
            }
        }

        return true;
    }

    /**
     * Remove an attachment's objects from the bucket (downloading any
     * locally-missing file first), then rewrite the remote URLs already
     * persisted into the database back to the local upload URLs, and
     * finally clear the offload meta.
     *
     * Without the reverse rewrite, every URL persist_permanent_urls() wrote
     * (guid, post_content, postmeta, options) keeps pointing at objects
     * that no longer exist — and a later re-offload mints a NEW version
     * folder, so those stale URLs would stay broken forever.
     *
     * @param int  $attachment_id Attachment post ID.
     * @param bool $defer_persist Skip the per-attachment DB URL rewrite —
     *                            bulk callers collect the pairs of a whole
     *                            batch and run one replace_urls_bulk()
     *                            instead (guid is still updated here).
     * @return true|array|WP_Error True, or the reverse URL pairs when deferring.
     */
    public function remove_remote_attachment( $attachment_id, $defer_persist = false ) {
        $info = self::get_record( $attachment_id );
        if ( ! is_array( $info ) || empty( $info['files'] ) ) {
            return new WP_Error( 'isxs_not_offloaded', 'รายการนี้ยังไม่ได้ offload' );
        }

        $restored = $this->download_attachment( $attachment_id );
        if ( is_wp_error( $restored ) && $restored->get_error_code() !== 'isxs_download_missing' ) {
            return $restored;
        }
        // isxs_download_missing means the object is already gone from the
        // bucket too (e.g. deleted out-of-band via CLI) — nothing left to
        // protect by refusing to proceed, so fall through and clear the
        // stale tracking meta instead of getting stuck on this item forever.

        $client = self::client_for_info( $info );
        foreach ( $info['files'] as $filename ) {
            $result = $client->delete_object( $info['base_key'] . $filename );
            if ( is_wp_error( $result ) ) {
                return new WP_Error( 'isxs_delete_failed', sprintf( 'ลบ %s จาก bucket ไม่สำเร็จ: %s', $filename, $result->get_error_message() ) );
            }
        }

        // Reverse-rewrite BEFORE dropping the meta (the pairs need base_key).
        $pairs = self::reverse_url_pairs( $attachment_id, $info );
        if ( ! empty( $pairs ) ) {
            ISXM_DB_Rewriter::update_attachment_guid( $attachment_id, $pairs );
            if ( ! $defer_persist ) {
                ISXM_DB_Rewriter::replace_urls_bulk( $pairs );
            }
        }

        self::delete_record( $attachment_id );
        // The file is deliberately off the bucket now — any stale failure
        // record would otherwise keep it flagged red forever.
        self::clear_error( $attachment_id );
        return $defer_persist ? $pairs : true;
    }

    /**
     * Remote-URL → local-upload-URL pairs for one offloaded attachment,
     * i.e. the exact inverse of collect_url_pairs(), used by Remove to
     * repair URLs that persist_permanent_urls() already wrote into the DB.
     *
     * The persisted URLs were built against the settings at offload time,
     * so the old-URL candidates are reconstructed from the attachment's own
     * tracking meta (endpoint/bucket/path_style, both schemes) in addition
     * to the currently configured public base URL — not from current
     * settings alone.
     *
     * @param int   $attachment_id Attachment post ID.
     * @param array $info          The `_isxs_offload` meta array.
     * @return array List of [ 'old' => string, 'new' => string ].
     */
    public static function reverse_url_pairs( $attachment_id, array $info ) {
        if ( empty( $info['files'] ) || empty( $info['base_key'] ) ) {
            return [];
        }

        $uploads   = wp_get_upload_dir();
        $relative  = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $subdir    = dirname( $relative );
        $local_dir = trailingslashit( $uploads['baseurl'] );
        if ( $subdir !== '.' && $subdir !== '' ) {
            $local_dir .= trailingslashit( $subdir );
        }

        // Every base URL the remote copy may have been persisted under.
        $bases    = [ ISXM_Settings::public_base_url() ];
        $endpoint = isset( $info['endpoint'] ) ? $info['endpoint'] : '';
        $bucket   = isset( $info['bucket'] ) ? $info['bucket'] : '';
        if ( $endpoint !== '' && $bucket !== '' ) {
            $host       = preg_replace( '#^https?://#', '', untrailingslashit( $endpoint ) );
            $path_style = ! empty( $info['path_style'] );
            foreach ( [ 'https', 'http' ] as $scheme ) {
                $bases[] = $path_style
                    ? $scheme . '://' . $host . '/' . $bucket
                    : $scheme . '://' . $bucket . '.' . $host;
            }
        }
        $bases = array_unique( $bases );

        $encoded_base = implode( '/', array_map( 'rawurlencode', explode( '/', untrailingslashit( $info['base_key'] ) ) ) );

        $pairs = [];
        foreach ( $info['files'] as $filename ) {
            $new = $local_dir . $filename;
            foreach ( $bases as $base ) {
                $pairs[] = [ 'old' => $base . '/' . $encoded_base . '/' . rawurlencode( $filename ), 'new' => $new ];
            }
        }
        return $pairs;
    }

    /**
     * Hook: delete remote objects when the attachment is deleted in WP.
     */
    public function delete_remote_on_delete( $attachment_id ) {
        $info = self::get_record( $attachment_id );
        if ( ! is_array( $info ) || empty( $info['files'] ) ) {
            // Still drop any ledger row: WordPress cleans up the postmeta of
            // a deleted attachment on its own, but knows nothing about the
            // items table, so a row here would outlive its attachment
            // forever and keep being counted as "on the bucket".
            ISXM_Items::delete( $attachment_id );
            return;
        }

        if ( ISXM_Settings::is_configured() ) {
            $client = self::client_for_info( $info );
            foreach ( $info['files'] as $filename ) {
                $result = $client->delete_object( $info['base_key'] . $filename );
                if ( is_wp_error( $result ) ) {
                    isxm_log_error( 'Remote delete failed for #' . $attachment_id . ' ' . $filename . ': ' . $result->get_error_message() );
                }
            }
        }

        // Unconditional: whether or not the remote objects could be removed,
        // the attachment itself is going away, so its tracking row must go
        // with it. A failed remote delete is already logged above.
        ISXM_Items::delete( $attachment_id );
    }

    /* ---------------------------------------------------------------------
     * URL rewriting
     * ------------------------------------------------------------------ */

    /**
     * Public remote URL for one file of an offloaded attachment,
     * or false when delivery does not apply.
     *
     * @param int    $attachment_id Attachment post ID.
     * @param string $filename      File name within the attachment's dir.
     * @return string|false
     */
    public function remote_url( $attachment_id, $filename ) {
        if ( ! ISXM_Settings::get( 'deliver_enabled' ) ) {
            return false;
        }
        $info = self::get_record( $attachment_id );
        if ( ! is_array( $info ) || empty( $info['base_key'] ) || empty( $info['files'] ) ) {
            return false;
        }
        // Only files that actually reached the bucket may be rewritten. A
        // size listed in `missing` (absent locally, or rejected by the
        // bucket) has no object to serve, so pointing the browser at one
        // turns a working local image into a 404 — the whole srcset of a
        // partially-uploaded attachment used to break this way.
        if ( ! in_array( $filename, $info['files'], true ) ) {
            return false;
        }
        return self::build_remote_url( $info, $filename );
    }

    /**
     * Build the public remote URL for one file given its offload meta,
     * regardless of the `deliver_enabled` toggle (used when permanently
     * persisting URLs into the database, where the URL must be correct
     * even before delivery is switched on).
     *
     * @param array  $info     The `_isxs_offload` meta array (needs `base_key`).
     * @param string $filename File name within the attachment's directory.
     * @return string
     */
    public static function build_remote_url( $info, $filename ) {
        $segments = array_map( 'rawurlencode', explode( '/', untrailingslashit( $info['base_key'] ) ) );
        // Built against the bucket this file actually lives in (per its own
        // record), not whichever destination happens to be selected now —
        // see ISXM_Settings::public_base_url_for().
        $base = ISXM_Settings::public_base_url_for( is_array( $info ) ? $info : [] );
        return $base . '/' . implode( '/', $segments ) . '/' . rawurlencode( $filename );
    }

    /**
     * Whether the permanent DB URL rewrite is enabled for this site.
     *
     * When off, offloading only uploads files (and may remove local copies
     * when delivery is on) — no full-table LIKE scans of
     * posts/postmeta/options after every batch, which is what dominated
     * bulk-offload time on large libraries. URL rewriting then relies on
     * the runtime filters (rewrite_attachment_url/srcset/content) alone.
     *
     * @return bool
     */
    public static function should_persist_urls() {
        return (bool) ISXM_Settings::get( 'persist_urls' );
    }

    /**
     * Permanently rewrite a just-offloaded attachment's local URLs to its
     * remote URL across the database (post_content, postmeta, options,
     * and its own guid), instead of relying solely on the runtime
     * `wp_get_attachment_url`/`the_content` filters. Those filters stay
     * active as a fallback for anything this pass doesn't reach.
     *
     * Skipped entirely when the permanent rewrite is switched off
     * (persist_urls) — every caller (single offload, auto-offload on
     * upload, bulk callback, CLI, migrate) funnels through here or
     * collect_url_pairs(), so one gate covers them all.
     *
     * @param int      $attachment_id  Attachment post ID.
     * @param string[] $extra_old_dirs Additional base directory URLs (already
     *                                 trailingslashit-ed by the caller is not
     *                                 required) whose files should also be
     *                                 replaced — e.g. the migration source's
     *                                 public URL, which the DB may already
     *                                 reference instead of a local URL.
     * @return array{guid:int,posts:int,postmeta:int,options:int} Row counts changed.
     */
    public static function persist_permanent_urls( $attachment_id, array $extra_old_dirs = [] ) {
        if ( ! self::should_persist_urls() ) {
            return [ 'guid' => 0, 'posts' => 0, 'postmeta' => 0, 'options' => 0 ];
        }

        $pairs = self::collect_url_pairs( $attachment_id, $extra_old_dirs );

        if ( empty( $pairs ) ) {
            return [ 'guid' => 0, 'posts' => 0, 'postmeta' => 0, 'options' => 0 ];
        }

        $stats = ISXM_DB_Rewriter::replace_attachment_urls( $attachment_id, $pairs );
        // Debug-only: this fires once per attachment, and isxm_log_error()
        // writes an option every time — a 17k-item run turned into 17k extra
        // DB writes for a line nobody reads on a healthy site.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            isxm_log_error( sprintf(
                'Persisted permanent URLs for #%d: guid=%d posts=%d postmeta=%d options=%d',
                $attachment_id, $stats['guid'], $stats['posts'], $stats['postmeta'], $stats['options']
            ) );
        }
        return $stats;
    }

    /**
     * Old-URL → new-remote-URL pairs for one offloaded attachment, without
     * executing any DB rewrite. Bulk callers aggregate these across a batch
     * and run ISXM_DB_Rewriter::replace_urls_bulk() once (plus
     * update_attachment_guid() per attachment).
     *
     * Returns [] (and the bulk rewrite becomes a no-op) when the permanent
     * rewrite is switched off (persist_urls) — see should_persist_urls().
     *
     * @param int      $attachment_id  Attachment post ID.
     * @param string[] $extra_old_dirs Additional base directory URLs whose files
     *                                 should also be replaced — e.g. the migration
     *                                 source's public URL.
     * @return array List of [ 'old' => string, 'new' => string ].
     */
    public static function collect_url_pairs( $attachment_id, array $extra_old_dirs = [] ) {
        if ( ! self::should_persist_urls() ) {
            return [];
        }

        $info = self::get_record( $attachment_id );
        if ( ! is_array( $info ) || empty( $info['files'] ) || empty( $info['base_key'] ) ) {
            return [];
        }

        $relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $subdir   = dirname( $relative );
        $uploads  = wp_get_upload_dir();
        $base_url = $uploads['baseurl'];
        $base_alt = ( strpos( $base_url, 'https://' ) === 0 )
            ? 'http://' . substr( $base_url, 8 )
            : 'https://' . substr( $base_url, 7 );

        $old_dirs = [ trailingslashit( $base_url ), trailingslashit( $base_alt ) ];
        if ( $subdir !== '.' && $subdir !== '' ) {
            $old_dirs = array_map( function ( $dir ) use ( $subdir ) {
                return $dir . trailingslashit( $subdir );
            }, $old_dirs );
        }

        foreach ( $extra_old_dirs as $dir ) {
            if ( $dir !== '' ) {
                $old_dirs[] = trailingslashit( $dir );
            }
        }
        $old_dirs = array_unique( $old_dirs );

        $pairs = [];
        foreach ( $info['files'] as $filename ) {
            $remote = self::build_remote_url( $info, $filename );
            foreach ( $old_dirs as $old_dir ) {
                $pairs[] = [ 'old' => $old_dir . $filename, 'new' => $remote ];
            }
        }

        return $pairs;
    }

    /**
     * Filter: wp_get_attachment_url.
     */
    public function rewrite_attachment_url( $url, $attachment_id ) {
        $remote = $this->remote_url( $attachment_id, wp_basename( $url ) );
        return $remote !== false ? $remote : $url;
    }

    /**
     * Filter: wp_calculate_image_srcset_meta.
     *
     * When delivery is on, wp_get_attachment_image_src() has already been
     * rewritten to the remote URL — but core's wp_calculate_image_srcset()
     * only builds a srcset if it can find "{dirname}/{size-file}" inside
     * that src. The remote path differs from the local one in two ways the
     * meta can't know about: the object-version folder, and %-encoding of
     * non-ASCII filenames. Without this filter the match fails and core
     * returns false BEFORE rewrite_srcset() ever runs, silently dropping
     * responsive srcset site-wide.
     *
     * Rebuild the meta paths to mirror the remote URL exactly (base_key +
     * encoded filenames) so the match succeeds; rewrite_srcset() below then
     * maps the resulting source URLs onto the remote base as usual.
     */
    public function fix_srcset_meta( $image_meta, $size_array, $image_src, $attachment_id ) {
        if ( ! is_array( $image_meta ) || empty( $image_meta['file'] ) || ! $attachment_id ) {
            return $image_meta;
        }
        if ( ! ISXM_Settings::get( 'deliver_enabled' ) ) {
            return $image_meta;
        }
        $info = self::get_record( $attachment_id );
        if ( ! is_array( $info ) || empty( $info['base_key'] ) ) {
            return $image_meta;
        }

        // The src may carry the filename %-encoded (full-size URLs from
        // wp_get_attachment_url / the_content rewrite) or raw UTF-8 (sized
        // URLs — image_downsize() splices the raw size filename into the
        // URL). Core matches with a plain strpos, so the rebuilt meta paths
        // must use whichever form THIS src actually uses.
        $use_encoded = ( rawurldecode( $image_src ) !== $image_src );

        $base_for_match = $use_encoded
            ? implode( '/', array_map( 'rawurlencode', explode( '/', untrailingslashit( $info['base_key'] ) ) ) ) . '/'
            : $info['base_key'];

        // Only adjust when the src actually points at the offloaded copy.
        if ( strpos( $image_src, '/' . $base_for_match ) === false ) {
            return $image_meta;
        }

        $basename = wp_basename( $image_meta['file'] );
        $image_meta['file'] = $base_for_match . ( $use_encoded ? rawurlencode( $basename ) : $basename );
        if ( $use_encoded && ! empty( $image_meta['sizes'] ) && is_array( $image_meta['sizes'] ) ) {
            foreach ( $image_meta['sizes'] as $size => $data ) {
                if ( ! empty( $data['file'] ) ) {
                    $image_meta['sizes'][ $size ]['file'] = rawurlencode( $data['file'] );
                }
            }
        }
        return $image_meta;
    }

    /**
     * Filter: wp_calculate_image_srcset.
     */
    public function rewrite_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( ! is_array( $sources ) ) {
            return $sources;
        }
        foreach ( $sources as &$source ) {
            // rawurldecode: fix_srcset_meta() feeds core %-encoded size
            // filenames, so the basename here may arrive encoded. Decoding
            // an unencoded name is a no-op (sanitize_file_name strips '%').
            $remote = $this->remote_url( $attachment_id, rawurldecode( wp_basename( $source['url'] ) ) );
            if ( $remote !== false ) {
                $source['url'] = $remote;
            }
        }
        return $sources;
    }

    /**
     * Filter: the_content — rewrite hard-coded local upload URLs of
     * offloaded attachments to their remote URLs.
     *
     * Shares the exact resolver the permanent one-pass DB rewrite uses
     * (resolve_local_url / local_url_map), so the rendered page and the
     * stored database agree by construction, not by accident. The only
     * difference is the delivery gate here: when delivery is switched off
     * this filter does nothing, while the permanent DB rewrite still runs
     * (its URLs must be correct even before delivery is on).
     */
    public function rewrite_content_urls( $content ) {
        if ( ! ISXM_Settings::get( 'deliver_enabled' ) || strpos( $content, 'wp-content/uploads' ) === false ) {
            return $content;
        }
        $map = self::local_url_map( $content );
        return empty( $map ) ? $content : str_replace( array_keys( $map ), array_values( $map ), $content );
    }

    /**
     * Resolve one local upload URL (either scheme) of an offloaded
     * attachment to its remote URL, or null when the URL does not belong to
     * a file that actually reached the bucket.
     *
     * This is the ONE resolver behind both the render-time `the_content`
     * filter (rewrite_content_urls) and the permanent one-pass DB rewrite
     * (ISXM_Tools' rewrite phase), so the two can never drift apart. Unlike
     * remote_url() it deliberately does NOT gate on `deliver_enabled` — the
     * permanent rewrite must produce correct URLs even while delivery is
     * switched off — but it keeps the same in_array($filename,
     * $info['files']) guard, so a size that failed to upload (recorded in
     * `missing`) is never written into the database as a live URL.
     *
     * @param string $url Full local upload URL, e.g.
     *                    https://site/wp-content/uploads/2025/08/photo-300x200.jpg
     * @return string|null Remote URL, or null when unresolvable.
     */
    public static function resolve_local_url( $url ) {
        if ( ! is_string( $url ) || $url === '' ) {
            return null;
        }

        $uploads  = wp_get_upload_dir();
        $base_url = untrailingslashit( $uploads['baseurl'] );
        $base_alt = ( strpos( $base_url, 'https://' ) === 0 )
            ? 'http://' . substr( $base_url, 8 )
            : 'https://' . substr( $base_url, 7 );

        // Must be one of our two upload bases; strip it to get the relative
        // path (subdirectory + filename) under uploads.
        if ( strpos( $url, $base_url . '/' ) === 0 ) {
            $relative = substr( $url, strlen( $base_url ) + 1 );
        } elseif ( strpos( $url, $base_alt . '/' ) === 0 ) {
            $relative = substr( $url, strlen( $base_alt ) + 1 );
        } else {
            return null;
        }

        $filename = wp_basename( $relative );
        // Map sized files (example-300x200.jpg) back to the original for lookup.
        $lookup = preg_replace( '#-\d+x\d+(\.[a-zA-Z0-9]+)$#', '$1', $relative );

        if ( ! array_key_exists( $lookup, self::$url_post_cache ) ) {
            self::$url_post_cache[ $lookup ] = attachment_url_to_postid( $base_url . '/' . $lookup );
        }
        $attachment_id = self::$url_post_cache[ $lookup ];
        if ( ! $attachment_id ) {
            return null;
        }

        $info = self::get_record( $attachment_id );
        if ( ! is_array( $info ) || empty( $info['base_key'] ) || empty( $info['files'] ) ) {
            return null;
        }
        if ( ! in_array( $filename, $info['files'], true ) ) {
            return null;
        }
        return self::build_remote_url( $info, $filename );
    }

    /**
     * Old→new URL map for every local upload URL found inside a string
     * (post_content / meta_value / option_value / rendered content).
     *
     * Extracts the same URL forms rewrite_content_urls() always handled
     * (both http/https bases, sized and full filenames) and resolves each
     * through resolve_local_url(). Pairs are only added for URLs that
     * actually resolved, so feeding the map through
     * ISXM_DB_Rewriter::recursive_replace_pairs() is safe for serialized
     * values and never touches a URL that isn't an offloaded file.
     *
     * @param mixed $content DB or rendered value (non-strings are a no-op).
     * @return array<string,string> old → new map.
     */
    public static function local_url_map( $content ) {
        $map = [];
        if ( ! is_string( $content ) || strpos( $content, 'wp-content/uploads' ) === false ) {
            return $map;
        }

        $uploads  = wp_get_upload_dir();
        $base_url = untrailingslashit( $uploads['baseurl'] );
        $base_alt = ( strpos( $base_url, 'https://' ) === 0 )
            ? 'http://' . substr( $base_url, 8 )
            : 'https://' . substr( $base_url, 7 );

        $pattern = '#(?:' . preg_quote( $base_url, '#' ) . '|' . preg_quote( $base_alt, '#' ) . ')/([^\s"\'<>\)]+)#';

        if ( ! preg_match_all( $pattern, $content, $matches ) ) {
            return $map;
        }
        foreach ( $matches[0] as $url ) {
            $remote = self::resolve_local_url( $url );
            if ( $remote !== null && $remote !== $url ) {
                $map[ $url ] = $remote;
            }
        }
        return $map;
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * All file names belonging to an attachment (original, scaled original,
     * and every generated size), relative to the attachment's directory.
     *
     * @param string     $file     Absolute path of the attached file.
     * @param array|null $metadata Attachment metadata.
     * @return string[]
     */
    public static function collect_filenames( $file, $metadata ) {
        $filenames = [ wp_basename( $file ) ];

        if ( is_array( $metadata ) ) {
            if ( ! empty( $metadata['original_image'] ) ) {
                $filenames[] = $metadata['original_image'];
            }
            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size ) {
                    if ( ! empty( $size['file'] ) ) {
                        $filenames[] = $size['file'];
                    }
                }
            }
        }

        // Every name below becomes part of a filesystem path
        // ($local_dir . $name) for upload/download/delete, so only plain
        // basenames may pass — see safe_filename(). A traversal name in
        // attachment metadata must not be able to touch files outside the
        // uploads directory.
        $filenames = array_map( [ __CLASS__, 'safe_filename' ], $filenames );

        return array_values( array_unique( array_filter( $filenames ) ) );
    }

    /**
     * Validate one media file name before it becomes part of a filesystem
     * path or object key.
     *
     * Attachment metadata is the only source of these names, and normally
     * only admins can write it — but a compromised admin account, a bad
     * migration/import, or another plugin editing meta can smuggle in a
     * name like "../../wp-config.php". Concatenated straight onto the
     * attachment directory it would let uploads READ it, downloads WRITE
     * outside the uploads dir, and "Remove Local Media" DELETE it.
     *
     * Defense in depth: strip any directory component via wp_basename()
     * (which only treats '/' as a separator on Unix, hence the backslash
     * check too), then reject what's left if it is still suspicious.
     * Returns '' for anything invalid; callers skip or refuse on ''.
     *
     * @param mixed $filename Candidate file name (may be non-string from corrupt meta).
     * @return string Safe basename, or '' when invalid.
     */
    public static function safe_filename( $filename ) {
        if ( ! is_string( $filename ) || $filename === '' ) {
            return '';
        }

        $filename = wp_basename( $filename );
        if ( $filename === '.' || $filename === '..' ) {
            return '';
        }

        // Slashes (both kinds — wp_basename misses '\\' on Unix), control
        // characters (a newline could even forge response headers further
        // downstream), and leading dots (hidden files like .htaccess) are
        // all rejected outright.
        if ( preg_match( '#[\\\\/\x00-\x1f\x7f]|^\\.#', $filename ) ) {
            return '';
        }

        return $filename;
    }
}
