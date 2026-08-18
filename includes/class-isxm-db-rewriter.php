<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_DB_Rewriter — Serialization-safe search & replace for permanently
 * rewriting offloaded/migrated attachments' local URLs to their new remote
 * URLs across wp_posts.post_content, wp_postmeta.meta_value and
 * wp_options.option_value (plus each attachment's own guid).
 *
 * Mirrors WP-CLI's recursive unserialize/replace/reserialize approach so
 * serialized arrays/objects aren't corrupted when the replacement string
 * has a different byte length than the original.
 *
 * Scale note: every table pass is a full-table LIKE scan, so URL pairs are
 * processed in bounded groups — a whole batch's pairs can run into the
 * hundreds, and one WHERE of that many OR'd leading-wildcard LIKEs is slow
 * to plan and evaluate (and risks the statement-size cap). Each chunk's
 * scan only matches rows still holding one of its URLs, so the extra passes
 * converge instead of reworking. Bulk callers should still aggregate the
 * pairs of a whole batch and call replace_urls_bulk() once per batch.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_DB_Rewriter {

    /**
     * Max old-URL LIKE conditions OR'd into a single table scan.
     *
     * A batch of 100 attachments produces ~600–1000 pairs, and MySQL
     * evaluates every one of those leading-wildcard LIKEs against every row
     * of postmeta. On a large site that single statement overran the AJAX
     * batch's time budget and took the whole request past the web server's
     * timeout (a 504 the JS then treats as a hard stop). Splitting the pairs
     * into bounded slices keeps each statement's cost predictable; the
     * slices run sequentially and the net rewrite is identical, since the
     * map is ordered longest-old-first before it is split.
     */
    const MAX_CONDITIONS_PER_SCAN = 100;

    /**
     * Replace every occurrence of each old→new URL pair for one
     * attachment across the database (guid included).
     *
     * @param int   $attachment_id Attachment post ID (for the guid update).
     * @param array $url_pairs     List of [ 'old' => string, 'new' => string ].
     * @return array{guid:int,posts:int,postmeta:int,options:int} Row counts changed.
     */
    public static function replace_attachment_urls( $attachment_id, array $url_pairs ) {
        $stats         = self::replace_urls_bulk( $url_pairs );
        $stats['guid'] = self::update_attachment_guid( $attachment_id, $url_pairs );
        return $stats;
    }

    /**
     * Replace every occurrence of each old→new URL pair across
     * post_content, postmeta and options — one table scan per table for
     * the whole set of pairs, regardless of how many attachments they
     * came from. Does NOT touch guids; call update_attachment_guid()
     * per attachment for that.
     *
     * @param array $url_pairs List of [ 'old' => string, 'new' => string ].
     * @return array{posts:int,postmeta:int,options:int} Row counts changed.
     */
    public static function replace_urls_bulk( array $url_pairs ) {
        global $wpdb;

        $stats = [ 'posts' => 0, 'postmeta' => 0, 'options' => 0 ];

        $map = self::pairs_to_map( $url_pairs );
        if ( empty( $map ) ) {
            return $stats;
        }

        // The 5th/6th args carry what the cache invalidation needs: which
        // column identifies the cached object, and which cache that is.
        $stats['posts']    = self::update_table( $wpdb->posts, 'ID', 'post_content', $map, 'ID', 'posts' );
        $stats['postmeta'] = self::update_table( $wpdb->postmeta, 'meta_id', 'meta_value', $map, 'post_id', 'postmeta' );
        $stats['options']  = self::update_table( $wpdb->options, 'option_id', 'option_value', $map, 'option_name', 'options' );

        return $stats;
    }

    /**
     * One LIKE pattern that matches every row of a table whose value could
     * hold a local upload URL of THIS site, in either scheme — the broad
     * probe the one-pass rewrite pages through.
     *
     * Built from the real uploads base URL (host + path) rather than a
     * hard-coded 'wp-content/uploads', so a site with a custom
     * upload_path/upload_url_path is probed correctly, and the leading
     * wildcard before '//host…' makes the match scheme-agnostic (http and
     * https are the same string from the '//' onwards). A single condition
     * like this is what the one-pass rewrite pays for: one full-table scan
     * per table per run, instead of the hundreds of OR'd leading-wildcard
     * LIKEs replace_urls_bulk() needed per batch.
     *
     * @return string LIKE pattern (with surrounding '%'s), already esc_like()'d.
     */
    public static function uploads_url_probe() {
        global $wpdb;

        $uploads = wp_get_upload_dir();
        $base    = untrailingslashit( $uploads['baseurl'] );

        $host = '';
        $path = '/wp-content/uploads';
        if ( strpos( $base, '//' ) !== false ) {
            $after_scheme = substr( $base, strpos( $base, '//' ) + 2 );
            $slash        = strpos( $after_scheme, '/' );
            if ( $slash === false ) {
                $host = $after_scheme;
                $path = '';
            } else {
                $host = substr( $after_scheme, 0, $slash );
                $path = substr( $after_scheme, $slash );
            }
        } else {
            // Scheme-less baseurl (rare) — match the path alone; prepending
            // '//' would fabricate a host that real URLs never carry.
            return '%' . $wpdb->esc_like( $base ) . '%';
        }

        return '%' . $wpdb->esc_like( '//' . $host . $path ) . '%';
    }

    /**
     * One window of the one-pass rewrite: scan a table with the broad
     * uploads-URL probe (one LIKE condition), page by row id, and for every
     * row that matches, build that row's old→new URL map through
     * $map_builder and feed it into the same serialization-safe
     * recursive_replace_pairs() the batch rewrite uses.
     *
     * The map is built PER ROW and only for rows the probe already
     * narrowed to, so the per-row work is a regex pass plus URL lookups —
     * no OR'd multi-condition scan and no map assembled for the whole
     * table at once. This is the cheap end-of-run counterpart to
     * replace_urls_bulk() (which is right for a bounded batch but too
     * expensive to repeat hundreds of times on a large library).
     *
     * @param string   $table       Table name.
     * @param string   $id_col      Primary key column.
     * @param string   $value_col   Value column to search/replace.
     * @param callable $map_builder fn( string $value ): array<string,string>
     *                              old→new map for ONE row's value; callers
     *                              pass e.g. [ ISXM_Offload::class, 'local_url_map' ].
     * @param int      $after_id    Resume after this row id.
     * @param int      $limit       Max rows to scan this window.
     * @param string   $ref_col     Optional column identifying the cached object
     *                              the row belongs to (post ID / option name).
     * @param string   $kind        Optional 'posts'|'postmeta'|'options' — which
     *                              cache to purge for rewritten rows.
     * @return array{processed:int,last_id:int,done:bool}
     */
    public static function rewrite_window( $table, $id_col, $value_col, callable $map_builder, $after_id = 0, $limit = 100, $ref_col = null, $kind = null ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT {$id_col} AS id, {$value_col} AS val" . ( $ref_col ? ", {$ref_col} AS ref" : '' ) . "
             FROM {$table} WHERE {$value_col} LIKE %s AND {$id_col} > %d
             ORDER BY {$id_col} ASC LIMIT %d",
            self::uploads_url_probe(),
            (int) $after_id,
            (int) $limit
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- $id_col/$value_col/$ref_col are this class's own hard-coded column names

        $refs      = [];
        $last_id   = (int) $after_id;
        $processed = 0;

        foreach ( $rows as $row ) {
            $last_id = (int) $row->id;
            $processed++;

            // Only rows the probe matched are here, but the probe is broad —
            // the per-row map decides whether anything actually changes. An
            // empty map (no resolvable offloaded URL in this value) means
            // the row is left alone entirely.
            $map = call_user_func( $map_builder, $row->val );
            if ( empty( $map ) ) {
                continue;
            }
            $replaced = self::recursive_replace_pairs( $row->val, $map );
            if ( $replaced !== $row->val ) {
                $wpdb->update( $table, [ $value_col => $replaced ], [ $id_col => $row->id ] );
                if ( $ref_col && isset( $row->ref ) ) {
                    $refs[] = $row->ref;
                }
            }
        }

        if ( ! empty( $refs ) && $kind ) {
            self::purge_cache( $kind, $refs );
        }

        return [
            'processed' => $processed,
            'last_id'   => $last_id,
            'done'      => count( $rows ) < $limit,
        ];
    }

    /**
     * Update one attachment's guid if it currently equals any of the
     * old URLs in the given pairs.
     *
     * @param int   $attachment_id Attachment post ID.
     * @param array $url_pairs     List of [ 'old' => string, 'new' => string ].
     * @return int 1 when the guid was rewritten, 0 otherwise.
     */
    public static function update_attachment_guid( $attachment_id, array $url_pairs ) {
        global $wpdb;

        $map = self::pairs_to_map( $url_pairs );
        if ( empty( $map ) ) {
            return 0;
        }

        $current = $wpdb->get_var( $wpdb->prepare( "SELECT guid FROM {$wpdb->posts} WHERE ID = %d", $attachment_id ) );
        if ( $current === null || ! isset( $map[ $current ] ) ) {
            return 0;
        }
        $wpdb->update( $wpdb->posts, [ 'guid' => $map[ $current ] ], [ 'ID' => $attachment_id ] );
        // Writing straight to the table leaves the cached post object holding
        // the old guid — on a site with a persistent object cache (Redis/
        // Memcached) that stale copy is what everything reads until the cache
        // is flushed by hand, so the rewrite would look like it did nothing.
        clean_post_cache( (int) $attachment_id );
        return 1;
    }

    /**
     * Normalize a list of [ 'old' => ..., 'new' => ... ] pairs into an
     * old→new map: drops empty/no-op pairs, dedupes by old URL, and sorts
     * longest-old-first so no old URL can shadow a longer one during
     * sequential str_replace.
     *
     * @param array $url_pairs List of [ 'old' => string, 'new' => string ].
     * @return array<string,string>
     */
    private static function pairs_to_map( array $url_pairs ) {
        $map = [];
        foreach ( $url_pairs as $pair ) {
            $old = isset( $pair['old'] ) ? $pair['old'] : '';
            $new = isset( $pair['new'] ) ? $pair['new'] : '';
            if ( $old !== '' && $old !== $new ) {
                $map[ $old ] = $new;
            }
        }
        uksort( $map, function ( $a, $b ) {
            return strlen( $b ) - strlen( $a );
        } );
        return $map;
    }

    /**
     * Find rows whose value contains any old URL and rewrite them all in
     * one pass (serialization-safe). One LIKE scan per table no matter
     * how many pairs.
     *
     * @param string               $table     Table name.
     * @param string               $id_col    Primary key column.
     * @param string               $value_col Value column to search/replace.
     * @param array<string,string> $map       old→new URL map.
     * @param string               $ref_col   Column identifying the cached object
     *                                        the row belongs to (post ID / option name).
     * @param string               $kind      'posts'|'postmeta'|'options' — which cache to purge.
     * @return int Rows changed.
     */
    private static function update_table( $table, $id_col, $value_col, array $map, $ref_col, $kind ) {
        global $wpdb;

        $changed = [];
        $refs    = [];

        // array_chunk preserves the longest-old-first ordering pairs_to_map()
        // established, so a later slice can never replace a short URL that an
        // earlier slice's longer URL contains — the same guarantee that
        // ordering provides within one str_replace call.
        foreach ( array_chunk( $map, self::MAX_CONDITIONS_PER_SCAN, true ) as $slice ) {
            $conditions = [];
            // Injection safety of these LIKEs: every $old URL is a VALUE
            // (settings strings + attachment meta) passed through
            // $wpdb->prepare() with a %s placeholder and $wpdb->esc_like()
            // for the LIKE wildcards, so no value can inject SQL — and
            // $value_col is only ever one of this class's own hard-coded
            // column names (post_content, meta_value, option_value), never
            // input. The values that become these patterns are additionally
            // validated at the entry points: settings in
            // ISXM_Tools::sanitize_cdn_domain()/sanitize_prefix()/the
            // http(s) check on source_public_base_url, and attachment
            // filenames in ISXM_Offload::safe_filename().
            foreach ( array_keys( $slice ) as $old ) {
                $conditions[] = $wpdb->prepare( "{$value_col} LIKE %s", '%' . $wpdb->esc_like( $old ) . '%' );
            }
            $where = implode( ' OR ', $conditions );

            $rows = $wpdb->get_results( "SELECT {$id_col} AS id, {$value_col} AS val, {$ref_col} AS ref FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- conditions individually prepared above

            foreach ( $rows as $row ) {
                $replaced = self::recursive_replace_pairs( $row->val, $slice );
                if ( $replaced !== $row->val ) {
                    $wpdb->update( $table, [ $value_col => $replaced ], [ $id_col => $row->id ] );
                    // Keyed by row id: a row matched by two slices is one
                    // changed row, not two.
                    $changed[ $row->id ] = true;
                    $refs[]              = $row->ref;
                }
            }
        }

        if ( ! empty( $refs ) ) {
            self::purge_cache( $kind, $refs );
        }
        return count( $changed );
    }

    /**
     * Drop the object-cache entries for the rows just rewritten.
     *
     * These writes go straight to the table with $wpdb->update(), which does
     * not touch the object cache. On a site with a persistent object cache
     * (Redis/Memcached — the norm on production hosting) the cached copies
     * still carry the OLD urls, so every read keeps serving them and the
     * rewrite appears to have done nothing at all until someone flushes the
     * cache manually.
     *
     * @param string $kind 'posts'|'postmeta'|'options'.
     * @param array  $refs Post IDs, or option names for 'options'.
     */
    private static function purge_cache( $kind, array $refs ) {
        $refs = array_unique( $refs );

        if ( $kind === 'posts' ) {
            // clean_post_cache() also clears the post's meta cache and its
            // parent/term caches — the full invalidation core would have done.
            foreach ( $refs as $post_id ) {
                clean_post_cache( (int) $post_id );
            }
            return;
        }

        if ( $kind === 'postmeta' ) {
            foreach ( $refs as $post_id ) {
                wp_cache_delete( (int) $post_id, 'post_meta' );
            }
            return;
        }

        // Options: each name individually, plus the bulk 'alloptions' blob if
        // any rewritten option is autoloaded — that blob is a single cache
        // entry holding every autoloaded option, so a per-name delete alone
        // leaves the old value readable through it.
        $alloptions = wp_cache_get( 'alloptions', 'options' );
        $bust_all   = false;
        foreach ( $refs as $name ) {
            wp_cache_delete( $name, 'options' );
            if ( is_array( $alloptions ) && isset( $alloptions[ $name ] ) ) {
                $bust_all = true;
            }
        }
        if ( $bust_all ) {
            wp_cache_delete( 'alloptions', 'options' );
        }
    }

    /**
     * Replace $old with $new inside a possibly-serialized DB value without
     * corrupting serialized length prefixes.
     */
    public static function recursive_replace( $value, $old, $new ) {
        return self::recursive_replace_pairs( $value, [ $old => $new ] );
    }

    /**
     * Replace every old→new pair inside a possibly-serialized DB value
     * without corrupting serialized length prefixes.
     *
     * @param mixed                $value DB value (string, possibly serialized).
     * @param array<string,string> $map   old→new map.
     */
    public static function recursive_replace_pairs( $value, array $map ) {
        if ( is_serialized( $value ) ) {
            $data = @unserialize( trim( $value ) );
            if ( $data !== false || trim( $value ) === 'b:0;' ) {
                return serialize( self::recursive_replace_data( $data, $map ) );
            }
        }

        return is_string( $value ) ? str_replace( array_keys( $map ), array_values( $map ), $value ) : $value;
    }

    /**
     * Recursively walk unserialized data replacing every old→new pair in strings.
     */
    private static function recursive_replace_data( $data, array $map ) {
        if ( is_string( $data ) ) {
            return str_replace( array_keys( $map ), array_values( $map ), $data );
        }
        if ( is_array( $data ) ) {
            foreach ( $data as $key => $val ) {
                $data[ $key ] = self::recursive_replace_data( $val, $map );
            }
            return $data;
        }
        if ( is_object( $data ) ) {
            foreach ( $data as $key => $val ) {
                $data->$key = self::recursive_replace_data( $val, $map );
            }
            return $data;
        }
        return $data;
    }
}
