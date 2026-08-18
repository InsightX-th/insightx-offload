<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Items — The offload ledger: one indexed row per attachment that is
 * on a bucket, replacing the serialized `_isxs_offload` postmeta as the
 * thing every count and scan reads.
 *
 * Why a table at all. The tracking record is an array (bucket, endpoint,
 * base_key, file list…), and a serialized array can only be queried as a
 * string. So "how many attachments are on the current destination?" became
 * a leading-wildcard LIKE against every `_isxs_offload` row —
 *
 *     meta_value LIKE '%s:6:"bucket";s:12:"my-bucket"%'
 *
 * — which MySQL can only answer with a full scan, and which silently
 * depends on the key order inside the serialized string. The same shape
 * drove the pending/offloaded scans, so each one paged through the whole
 * postmeta table in PHP to decide which rows counted. Here bucket and
 * endpoint are indexed columns, every count is an indexed COUNT, and the
 * scans page by primary key.
 *
 * Compatibility. Existing installs keep their postmeta: the table is
 * filled by a resumable backfill job, and until that finishes `ready()`
 * returns false and every caller uses the original postmeta path
 * unchanged. Writes go to BOTH stores even after the switch, so the
 * postmeta remains a complete, current copy — a downgrade, or any
 * third-party code reading `_isxs_offload`, keeps working.
 *
 * One row per attachment, exactly like the postmeta it mirrors: an
 * attachment lives on one destination at a time.
 *
 * @since 0.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Items {

    /** Bumped when the schema changes; drives maybe_install(). */
    const DB_VERSION = 1;

    const DB_VERSION_OPTION = 'isxs_items_db_version';

    /** Set once the backfill has copied every legacy postmeta record. */
    const BACKFILLED_OPTION = 'isxs_items_backfilled';

    /** @var array<int,array|null> Per-request cache, keyed by attachment ID. */
    private static $cache = [];

    /**
     * @return string Fully-qualified table name for the current site.
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'isxs_items';
    }

    /**
     * Create or upgrade the table. Safe to call on every request — it
     * costs one option read unless the version actually moved.
     */
    public static function maybe_install() {
        if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        // Index notes:
        //  - uidx_source enforces the one-row-per-attachment rule the
        //    postmeta model had implicitly, and is the lookup every
        //    per-attachment read uses.
        //  - idx_destination answers "is this on the current bucket?" for
        //    the counts and both bulk scans. Prefix lengths keep the key
        //    inside InnoDB's limit while staying selective (bucket and
        //    endpoint names differ early, not at character 100).
        //  - idx_scan is what get_pending_ids()/get_offloaded_ids() page on:
        //    destination first, then source_id so the keyset walk is index-only.
        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type VARCHAR(20) NOT NULL DEFAULT 'media-library',
            source_id BIGINT(20) UNSIGNED NOT NULL,
            provider VARCHAR(20) NOT NULL DEFAULT '',
            bucket VARCHAR(255) NOT NULL DEFAULT '',
            endpoint VARCHAR(255) NOT NULL DEFAULT '',
            region VARCHAR(64) NOT NULL DEFAULT '',
            path_style TINYINT(1) NOT NULL DEFAULT 0,
            base_key VARCHAR(512) NOT NULL DEFAULT '',
            object_version VARCHAR(32) NOT NULL DEFAULT '',
            origin VARCHAR(20) NOT NULL DEFAULT 'offload',
            files LONGTEXT NULL,
            missing LONGTEXT NULL,
            is_partial TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY uidx_source (source_type, source_id),
            KEY idx_destination (bucket(100), endpoint(100)),
            KEY idx_scan (bucket(100), endpoint(100), source_id),
            KEY idx_partial (is_partial)
        ) {$collate};";

        dbDelta( $sql );

        // dbDelta() reports nothing useful when the CREATE fails (a DB user
        // without CREATE rights, disk full, a name collision). Recording the
        // version anyway would let ready() flip to true after the backfill
        // and point every count at a table that isn't there. Confirm first;
        // if it's missing, stay on the postmeta path and say why.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
            isxm_log_error( 'Could not create the tracking table ' . $table . ' — continuing on the postmeta path. Check that the database user has CREATE privileges.' );
            return;
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
    }

    /**
     * Whether the ledger may be trusted as the complete picture — i.e. the
     * table exists AND the backfill has finished copying legacy postmeta
     * into it. Until then every caller uses the postmeta path, so an
     * upgrade never shows a library as "nothing offloaded" just because
     * the copy is still running.
     *
     * @return bool
     */
    public static function ready() {
        if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
            return false;
        }
        // Deliberately not a === '1' comparison. get_option() hands back
        // whatever type was stored, and an int 1 (from a CLI helper, a
        // migration script, wp-cli option update) would fail a strict string
        // check — leaving the site permanently and silently on the slow
        // path with no symptom to notice.
        $flag = get_option( self::BACKFILLED_OPTION );
        return $flag === '1' || $flag === 1 || $flag === true;
    }

    /**
     * Mark the backfill complete. Only ISXM_Tools' backfill batch calls this.
     */
    public static function mark_backfilled() {
        update_option( self::BACKFILLED_OPTION, '1', false );
    }

    /* ---------------------------------------------------------------------
     * Per-attachment access
     * ------------------------------------------------------------------ */

    /**
     * The tracking record for one attachment, in the exact array shape the
     * `_isxs_offload` postmeta always had — so every existing consumer
     * (URL building, download, remove, Sync) works unchanged.
     *
     * @param int $attachment_id
     * @return array|null
     */
    public static function get( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        if ( array_key_exists( $attachment_id, self::$cache ) ) {
            return self::$cache[ $attachment_id ];
        }

        global $wpdb;
        $table = self::table();
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_type = %s AND source_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL -- table name is built from $wpdb->prefix
            'media-library',
            $attachment_id
        ), ARRAY_A );

        self::$cache[ $attachment_id ] = $row ? self::row_to_record( $row ) : null;
        return self::$cache[ $attachment_id ];
    }

    /**
     * Load a whole batch's rows in one query, so a 100-item batch costs one
     * round trip instead of a hundred. Mirrors what ISXM_Tools::prime_caches()
     * does for postmeta.
     *
     * @param int[] $ids
     */
    public static function prime( array $ids ) {
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        $ids = array_diff( $ids, array_keys( self::$cache ) );
        if ( empty( $ids ) ) {
            return;
        }

        global $wpdb;
        $table        = self::table();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows         = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_type = 'media-library' AND source_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built from a counted array
            $ids
        ), ARRAY_A );

        // Seed misses as null too, or every attachment without a row would
        // fall through to an individual query anyway.
        foreach ( $ids as $id ) {
            self::$cache[ $id ] = null;
        }
        foreach ( $rows as $row ) {
            self::$cache[ (int) $row['source_id'] ] = self::row_to_record( $row );
        }
    }

    /**
     * Insert or update one attachment's row from a tracking record.
     *
     * @param int   $attachment_id
     * @param array $record The `_isxs_offload` array shape.
     * @return bool
     */
    public static function put( $attachment_id, array $record ) {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        $files         = isset( $record['files'] ) && is_array( $record['files'] ) ? array_values( $record['files'] ) : [];
        $missing       = isset( $record['missing'] ) && is_array( $record['missing'] ) ? array_values( $record['missing'] ) : [];

        $data = [
            'source_type'    => 'media-library',
            'source_id'      => $attachment_id,
            'provider'       => (string) ISXM_Settings::get( 'provider', '' ),
            'bucket'         => isset( $record['bucket'] ) ? (string) $record['bucket'] : '',
            'endpoint'       => isset( $record['endpoint'] ) ? (string) $record['endpoint'] : '',
            'region'         => isset( $record['region'] ) ? (string) $record['region'] : '',
            'path_style'     => ! empty( $record['path_style'] ) ? 1 : 0,
            'base_key'       => isset( $record['base_key'] ) ? (string) $record['base_key'] : '',
            'object_version' => isset( $record['version'] ) ? (string) $record['version'] : '',
            'origin'         => isset( $record['origin'] ) ? (string) $record['origin'] : 'offload',
            'files'          => wp_json_encode( $files ),
            'missing'        => $missing ? wp_json_encode( $missing ) : null,
            // Denormalized so "ขึ้นไม่ครบทุกขนาด" is an indexed count rather
            // than a LIKE for the serialized 'missing' key.
            'is_partial'     => $missing ? 1 : 0,
            'updated_at'     => current_time( 'mysql', true ),
        ];

        self::$cache[ $attachment_id ] = self::normalize_record( $record );

        $table = self::table();
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE source_type = %s AND source_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
            'media-library',
            $attachment_id
        ) );

        if ( $existing_id ) {
            return false !== $wpdb->update( $table, $data, [ 'id' => (int) $existing_id ] );
        }
        return false !== $wpdb->insert( $table, $data );
    }

    /**
     * @param int $attachment_id
     * @return bool
     */
    public static function delete( $attachment_id ) {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        self::$cache[ $attachment_id ] = null;

        return false !== $wpdb->delete(
            self::table(),
            [ 'source_type' => 'media-library', 'source_id' => $attachment_id ],
            [ '%s', '%d' ]
        );
    }

    /**
     * Drop the per-request cache. Long-running batches call this between
     * chunks so the cache can't grow without bound.
     */
    public static function flush_cache() {
        self::$cache = [];
    }

    /* ---------------------------------------------------------------------
     * Counts — the whole point of the table
     * ------------------------------------------------------------------ */

    /**
     * Every destination figure the Overview needs, in ONE pass.
     *
     * Each count has to join back to posts (a ledger row outlives a trashed
     * attachment — see live_attachment_clause()), and running them
     * separately meant paying for that join three times over. Conditional
     * aggregation walks the matching rows once instead, which is most of
     * the difference between this and the postmeta path it replaced.
     *
     * @param string $bucket
     * @param string $endpoint
     * @return array{in_bucket:int,offloaded:int,partial:int}
     */
    public static function destination_counts( $bucket, $endpoint ) {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS in_bucket,
                SUM( CASE WHEN i.origin = 'offload' THEN 1 ELSE 0 END ) AS offloaded,
                SUM( CASE WHEN i.is_partial = 1 THEN 1 ELSE 0 END ) AS partial
             FROM {$table} i
             INNER JOIN {$wpdb->posts} p ON p.ID = i.source_id
             WHERE i.bucket = %s AND i.endpoint = %s" . self::live_attachment_clause(), // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
            $bucket,
            $endpoint
        ), ARRAY_A );

        return [
            'in_bucket' => (int) ( $row['in_bucket'] ?? 0 ),
            'offloaded' => (int) ( $row['offloaded'] ?? 0 ),
            'partial'   => (int) ( $row['partial'] ?? 0 ),
        ];
    }

    /**
     * Attachments physically on one destination, regardless of which tool
     * put them there.
     *
     * @param string $bucket
     * @param string $endpoint
     * @return int
     */
    public static function count_on_destination( $bucket, $endpoint ) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.source_id
             WHERE i.bucket = %s AND i.endpoint = %s" . self::live_attachment_clause(), // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
            $bucket,
            $endpoint
        ) );
    }

    /**
     * The condition every count and scan shares: the row's attachment must
     * still exist and not be in the trash.
     *
     * `total` counts only live attachments (trash is invisible in the Media
     * Library, so counting it made the ring disagree with what a user could
     * verify). A ledger row outlives a trashed attachment, so without this
     * the destination counts would include files `total` does not — and
     * in_bucket could exceed total outright.
     *
     * @return string SQL fragment, leading with AND.
     */
    private static function live_attachment_clause() {
        return " AND p.post_type = 'attachment' AND p.post_status <> 'trash'";
    }

    /**
     * As above, restricted to records a given tool created.
     *
     * @param string $bucket
     * @param string $endpoint
     * @param string $origin 'offload'|'migrate'
     * @return int
     */
    public static function count_by_origin( $bucket, $endpoint, $origin ) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.source_id
             WHERE i.bucket = %s AND i.endpoint = %s AND i.origin = %s" . self::live_attachment_clause(), // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
            $bucket,
            $endpoint,
            $origin
        ) );
    }

    /**
     * Records on this destination that are missing at least one size.
     *
     * @param string $bucket
     * @param string $endpoint
     * @return int
     */
    public static function count_partial( $bucket, $endpoint ) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.source_id
             WHERE i.bucket = %s AND i.endpoint = %s AND i.is_partial = 1" . self::live_attachment_clause(), // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
            $bucket,
            $endpoint
        ) );
    }

    /* ---------------------------------------------------------------------
     * Bulk scans
     * ------------------------------------------------------------------ */

    /**
     * Attachment IDs that are NOT on the given destination — no row at all,
     * or a row pointing somewhere else. A plain LEFT JOIN with the
     * destination test in the ON clause, so "not on this destination"
     * really is one IS NULL check.
     *
     * Unlike the postmeta version this needs no PHP-side filtering, so the
     * scan window and the limit are the same thing: every row it reads is a
     * row it returns.
     *
     * @param int    $limit
     * @param int    $after_id Resume after this attachment ID.
     * @param string $bucket
     * @param string $endpoint
     * @return array{ids:int[], last_id:int, done:bool}
     */
    public static function pending_ids( $limit, $after_id, $bucket, $endpoint ) {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, (int) $limit );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$table} i
                    ON i.source_type = 'media-library' AND i.source_id = p.ID
                   AND i.bucket = %s AND i.endpoint = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
               AND p.ID > %d AND i.id IS NULL
             ORDER BY p.ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
            $bucket,
            $endpoint,
            (int) $after_id,
            $limit
        ) );

        $ids = array_map( 'intval', $ids );

        return [
            'ids'     => $ids,
            'last_id' => empty( $ids ) ? (int) $after_id : (int) end( $ids ),
            'done'    => count( $ids ) < $limit,
        ];
    }

    /**
     * Attachment IDs that ARE on the given destination.
     *
     * @param int    $limit
     * @param int    $after_id
     * @param string $bucket
     * @param string $endpoint
     * @return array{ids:int[], last_id:int, done:bool}
     */
    public static function offloaded_ids( $limit, $after_id, $bucket, $endpoint ) {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, (int) $limit );

        // Joined back to posts so a row left behind for an attachment that
        // was deleted (or trashed) outside the plugin can't be handed to a
        // tool that would then fail on every item.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT i.source_id FROM {$table} i
             INNER JOIN {$wpdb->posts} p ON p.ID = i.source_id
             WHERE i.source_type = 'media-library' AND i.bucket = %s AND i.endpoint = %s
               AND p.post_type = 'attachment' AND p.post_status <> 'trash'
               AND i.source_id > %d
             ORDER BY i.source_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
            $bucket,
            $endpoint,
            (int) $after_id,
            $limit
        ) );

        $ids = array_map( 'intval', $ids );

        return [
            'ids'     => $ids,
            'last_id' => empty( $ids ) ? (int) $after_id : (int) end( $ids ),
            'done'    => count( $ids ) < $limit,
        ];
    }

    /**
     * One window of records for the Sync tool's expected-key pass, paged by
     * attachment ID.
     *
     * @param int    $limit
     * @param int    $after_id
     * @param string $bucket
     * @param string $endpoint
     * @return array{records:array<int,array>, last_id:int, done:bool}
     */
    public static function records_window( $limit, $after_id, $bucket, $endpoint ) {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, (int) $limit );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.* FROM {$table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.source_id
             WHERE i.source_type = 'media-library' AND i.bucket = %s AND i.endpoint = %s AND i.source_id > %d
               AND p.post_type = 'attachment' AND p.post_status <> 'trash'
             ORDER BY i.source_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
            $bucket,
            $endpoint,
            (int) $after_id,
            $limit
        ), ARRAY_A );

        $records = [];
        $last_id = (int) $after_id;
        foreach ( $rows as $row ) {
            $last_id                 = (int) $row['source_id'];
            $records[ $last_id ]     = self::row_to_record( $row );
        }

        return [
            'records' => $records,
            'last_id' => $last_id,
            'done'    => count( $rows ) < $limit,
        ];
    }

    /* ---------------------------------------------------------------------
     * Backfill
     * ------------------------------------------------------------------ */

    /**
     * Copy one window of legacy `_isxs_offload` postmeta into the table.
     * Paged by post_id, idempotent (put() upserts), so an interrupted run
     * simply resumes.
     *
     * @param int $limit
     * @param int $after_id
     * @return array{copied:int, skipped:int, last_id:int, done:bool}
     */
    public static function backfill_window( $limit, $after_id ) {
        global $wpdb;
        $limit = max( 1, (int) $limit );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND post_id > %d
             ORDER BY post_id ASC LIMIT %d",
            ISXM_Offload::META_KEY,
            (int) $after_id,
            $limit
        ) );

        $copied  = 0;
        $skipped = 0;
        $last_id = (int) $after_id;

        foreach ( $rows as $row ) {
            $last_id = (int) $row->post_id;
            $record  = maybe_unserialize( $row->meta_value );
            // A record with no base_key/files can't be acted on by any tool
            // (no keys to read, delete or verify) — copying it would only
            // inflate the counts with rows nothing can use.
            if ( ! is_array( $record ) || empty( $record['base_key'] ) || empty( $record['files'] ) ) {
                $skipped++;
                continue;
            }
            self::put( $last_id, $record );
            $copied++;
        }

        return [
            'copied'  => $copied,
            'skipped' => $skipped,
            'last_id' => $last_id,
            'done'    => count( $rows ) < $limit,
        ];
    }

    /**
     * How many legacy postmeta records exist — the backfill's denominator.
     *
     * @return int
     */
    public static function count_legacy_records() {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            ISXM_Offload::META_KEY
        ) );
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Turn a table row back into the `_isxs_offload` array shape.
     *
     * @param array $row
     * @return array
     */
    private static function row_to_record( array $row ) {
        $files   = json_decode( (string) $row['files'], true );
        $missing = $row['missing'] !== null && $row['missing'] !== '' ? json_decode( (string) $row['missing'], true ) : [];

        $record = [
            'bucket'     => (string) $row['bucket'],
            'base_key'   => (string) $row['base_key'],
            'version'    => (string) $row['object_version'],
            'files'      => is_array( $files ) ? $files : [],
            'endpoint'   => (string) $row['endpoint'],
            'region'     => (string) $row['region'],
            'path_style' => (bool) (int) $row['path_style'],
            'origin'     => (string) $row['origin'],
        ];
        if ( is_array( $missing ) && $missing ) {
            $record['missing'] = $missing;
        }
        return $record;
    }

    /**
     * Normalize a record the same way a round trip through the table would,
     * so the per-request cache can never hand back a shape the database
     * wouldn't have produced.
     *
     * @param array $record
     * @return array
     */
    private static function normalize_record( array $record ) {
        $normalized = [
            'bucket'     => isset( $record['bucket'] ) ? (string) $record['bucket'] : '',
            'base_key'   => isset( $record['base_key'] ) ? (string) $record['base_key'] : '',
            'version'    => isset( $record['version'] ) ? (string) $record['version'] : '',
            'files'      => isset( $record['files'] ) && is_array( $record['files'] ) ? array_values( $record['files'] ) : [],
            'endpoint'   => isset( $record['endpoint'] ) ? (string) $record['endpoint'] : '',
            'region'     => isset( $record['region'] ) ? (string) $record['region'] : '',
            'path_style' => ! empty( $record['path_style'] ),
            'origin'     => isset( $record['origin'] ) ? (string) $record['origin'] : 'offload',
        ];
        if ( ! empty( $record['missing'] ) && is_array( $record['missing'] ) ) {
            $normalized['missing'] = array_values( $record['missing'] );
        }
        return $normalized;
    }
}
