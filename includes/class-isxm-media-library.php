<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Media_Library — Offload status inside the WordPress Media Library.
 *
 * Until this existed the only view of offload state was the aggregate ring
 * on the settings page: you could see that N files hadn't made it to the
 * bucket, but never WHICH ones, or why. This adds the per-file view —
 * a status column, a status filter, and per-item/bulk "offload now" actions.
 *
 * @since 0.1.4
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Media_Library {

    /** Query var for the status filter (also linked to from the Tools page). */
    const FILTER_VAR = 'isxs_status';

    /** Bulk action id + the query arg its result notice is passed back in. */
    const BULK_ACTION  = 'isxs_offload';
    const RESULT_OK    = 'isxs_offloaded';
    const RESULT_ERROR = 'isxs_offload_failed';

    public function __construct() {
        add_filter( 'manage_media_columns', [ $this, 'add_column' ] );
        add_action( 'manage_media_custom_column', [ $this, 'render_column' ], 10, 2 );

        add_action( 'restrict_manage_posts', [ $this, 'render_filter' ] );
        add_filter( 'posts_clauses', [ $this, 'filter_clauses' ], 10, 2 );

        add_filter( 'media_row_actions', [ $this, 'add_row_action' ], 10, 2 );
        add_filter( 'bulk_actions-upload', [ $this, 'add_bulk_action' ] );
        add_filter( 'handle_bulk_actions-upload', [ $this, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_notices', [ $this, 'render_bulk_notice' ] );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ---------------------------------------------------------------------
     * Column
     * ------------------------------------------------------------------ */

    public function add_column( $columns ) {
        $columns['isxs_storage'] = 'Storage';
        return $columns;
    }

    /**
     * One cell of the Storage column.
     *
     * Reads both meta keys straight from the meta cache — WP_Query primes it
     * for every attachment on the page in one go, so this costs zero queries
     * per row.
     */
    public function render_column( $column, $post_id ) {
        if ( $column !== 'isxs_storage' ) {
            return;
        }

        $state  = ISXM_Offload::status_for( $post_id );
        $labels = [
            'offloaded'    => [ '☁', 'ขึ้น cloud แล้ว', 'isxs-ml-ok' ],
            'partial'      => [ '◐', 'ขึ้นไม่ครบทุกขนาด', 'isxs-ml-partial' ],
            'failed'       => [ '⚠', 'Offload ไม่ผ่าน', 'isxs-ml-failed' ],
            'other_bucket' => [ '↗', 'อยู่ bucket อื่น', 'isxs-ml-other' ],
            'pending'      => [ '○', 'ยังไม่ขึ้น cloud', 'isxs-ml-pending' ],
        ];
        list( $icon, $label, $class ) = $labels[ $state['status'] ];

        // The Sync tool flags attachments whose LOCAL copy is also gone
        // (set when it cleans a stale record that has nothing left to
        // re-upload from) — show a distinct state instead of a plain
        // pending that would only fail with "ไม่พบไฟล์ต้นฉบับ".
        if ( get_post_meta( $post_id, ISXM_Offload::DATA_LOSS_META_KEY, true ) ) {
            $icon  = '✕';
            $label = 'ไฟล์หายทั้งสองที่';
            $class = 'isxs-ml-dataloss';
            $detail = 'ทั้งไฟล์ local และไฟล์ใน bucket ไม่มีแล้ว — ต้องหาไฟล์ต้นฉบับกลับมาก่อน (Offload/Migrate อัปโหลดใหม่ไม่ได้)';
            printf(
                '<span class="isxs-ml-status %s" title="%s"><span aria-hidden="true">%s</span> %s</span>',
                esc_attr( $class ),
                esc_attr( $detail ),
                esc_html( $icon ),
                esc_html( $label )
            );
            printf( '<span class="isxs-ml-detail">%s</span>', esc_html( $detail ) );
            return;
        }

        // Detail line: the failure message, or which sizes are missing —
        // whichever actually explains the state being shown.
        $detail = '';
        if ( $state['status'] === 'failed' && ! empty( $state['error']['message'] ) ) {
            $detail = $state['error']['message'];
            if ( ! empty( $state['error']['tries'] ) && $state['error']['tries'] > 1 ) {
                $detail .= sprintf( ' (ลองแล้ว %d ครั้ง)', (int) $state['error']['tries'] );
            }
        } elseif ( $state['status'] === 'partial' ) {
            $detail = 'ขาด: ' . implode( ', ', array_slice( $state['info']['missing'], 0, 5 ) );
        } elseif ( $state['status'] === 'other_bucket' && ! empty( $state['info']['bucket'] ) ) {
            $detail = 'bucket: ' . $state['info']['bucket'];
        }

        printf(
            '<span class="isxs-ml-status %s" title="%s"><span aria-hidden="true">%s</span> %s</span>',
            esc_attr( $class ),
            esc_attr( $detail !== '' ? $detail : $label ),
            esc_html( $icon ),
            esc_html( $label )
        );

        if ( $detail !== '' ) {
            printf( '<span class="isxs-ml-detail">%s</span>', esc_html( $detail ) );
        }
    }

    /* ---------------------------------------------------------------------
     * Status filter
     * ------------------------------------------------------------------ */

    public function render_filter( $post_type ) {
        if ( $post_type !== 'attachment' ) {
            return;
        }

        $current = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_VAR ] ) ) : '';
        $options = [
            ''          => 'สถานะ Storage ทั้งหมด',
            'offloaded' => 'ขึ้น cloud แล้ว',
            'pending'   => 'ยังไม่ขึ้น cloud',
            'failed'    => 'Offload ไม่ผ่าน',
        ];

        echo '<select name="' . esc_attr( self::FILTER_VAR ) . '">';
        foreach ( $options as $value => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $value ),
                selected( $current, $value, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    /**
     * Apply the status filter to the Media Library list query.
     *
     * Done with a plain JOIN on postmeta rather than a meta_query, because
     * "offloaded" can't be expressed as a value comparison at all: the meta
     * is a serialized array, so only the row's EXISTENCE is queryable —
     * exactly what a LEFT JOIN + IS NULL / IS NOT NULL tests, without the
     * subquery meta_query would build for it.
     *
     * Destination-aware, matching the Overview ring and the tools'
     * denominators: the serialized bucket/endpoint entries are LIKE-matched
     * in SQL exactly the way ISXM_Tools::get_stats() does it. An earlier
     * version tested only for the meta row's existence, so a library holding
     * records from a previous destination reported one "ขึ้น cloud แล้ว"
     * number here and a different one on the settings page — the same files,
     * counted by two different rules.
     *
     * Putting the value conditions in the JOIN (not the WHERE) is what lets
     * "pending" stay a simple IS NULL test: a row that exists but points at
     * another bucket then never joins, which is precisely "not on this
     * destination".
     */
    public function filter_clauses( $clauses, $query ) {
        global $pagenow, $wpdb;

        if ( ! is_admin() || ! $query->is_main_query() ) {
            return $clauses;
        }
        if ( $pagenow !== 'upload.php' && $pagenow !== 'admin-ajax.php' ) {
            return $clauses;
        }
        if ( $query->get( 'post_type' ) !== 'attachment' ) {
            return $clauses;
        }

        $status = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_VAR ] ) ) : '';
        if ( ! in_array( $status, [ 'offloaded', 'pending', 'failed' ], true ) ) {
            // Nothing to filter — leave the query completely untouched rather
            // than touching every Media Library load.
            return $clauses;
        }
        // Belt and braces: joining the same alias twice is a fatal SQL error,
        // so never add ours on top of itself.
        if ( strpos( $clauses['join'], 'isxs_m' ) !== false ) {
            return $clauses;
        }

        if ( $status === 'failed' ) {
            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->postmeta} isxs_m ON isxs_m.post_id = {$wpdb->posts}.ID AND isxs_m.meta_key = %s ",
                ISXM_Offload::ERROR_META_KEY
            );
        } elseif ( ISXM_Items::ready() ) {
            // Indexed join on the ledger — the same destination test the
            // Overview ring and the tools use, without the LIKE.
            $items = ISXM_Items::table();
            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN {$items} isxs_m ON isxs_m.source_type = 'media-library' AND isxs_m.source_id = {$wpdb->posts}.ID AND isxs_m.bucket = %s AND isxs_m.endpoint = %s ", // phpcs:ignore WordPress.DB.PreparedSQL -- table name from $wpdb->prefix
                (string) ISXM_Settings::get( 'bucket' ),
                (string) ISXM_Settings::get( 'endpoint' )
            );
            $clauses['where'] .= $status === 'pending'
                ? ' AND isxs_m.id IS NULL '
                : ' AND isxs_m.id IS NOT NULL ';

            return $clauses;
        } else {
            $bucket   = (string) ISXM_Settings::get( 'bucket' );
            $endpoint = (string) ISXM_Settings::get( 'endpoint' );

            if ( $bucket === '' ) {
                // No destination configured — nothing can be "on it", so the
                // existence test is both the honest answer and the cheap one.
                $clauses['join'] .= $wpdb->prepare(
                    " LEFT JOIN {$wpdb->postmeta} isxs_m ON isxs_m.post_id = {$wpdb->posts}.ID AND isxs_m.meta_key = %s ",
                    ISXM_Offload::META_KEY
                );
            } else {
                // Same needles get_stats() uses: both keys are always written
                // by ISXM_Offload, so each is a stable substring of the
                // serialized value. Matching bucket alone would count a
                // same-named bucket under another provider as this one.
                $bucket_needle   = 's:6:"bucket";s:' . strlen( $bucket ) . ':"' . $bucket . '"';
                $endpoint_needle = 's:8:"endpoint";s:' . strlen( $endpoint ) . ':"' . $endpoint . '"';

                $clauses['join'] .= $wpdb->prepare(
                    " LEFT JOIN {$wpdb->postmeta} isxs_m ON isxs_m.post_id = {$wpdb->posts}.ID AND isxs_m.meta_key = %s AND isxs_m.meta_value LIKE %s AND isxs_m.meta_value LIKE %s ",
                    ISXM_Offload::META_KEY,
                    '%' . $wpdb->esc_like( $bucket_needle ) . '%',
                    '%' . $wpdb->esc_like( $endpoint_needle ) . '%'
                );
            }
        }
        $clauses['where'] .= $status === 'pending'
            ? ' AND isxs_m.post_id IS NULL '
            : ' AND isxs_m.post_id IS NOT NULL ';

        return $clauses;
    }

    /* ---------------------------------------------------------------------
     * Row + bulk actions
     * ------------------------------------------------------------------ */

    public function add_row_action( $actions, $post ) {
        if ( ! current_user_can( 'manage_options' ) || ! ISXM_Settings::is_configured() ) {
            return $actions;
        }

        $state = ISXM_Offload::status_for( $post->ID );
        if ( $state['status'] === 'offloaded' ) {
            return $actions;
        }

        $actions['isxs_offload'] = sprintf(
            '<a href="#" class="isxs-ml-offload" data-id="%d">%s</a>',
            (int) $post->ID,
            $state['status'] === 'failed' ? 'ลอง Offload ใหม่' : 'Offload ตอนนี้'
        );

        return $actions;
    }

    public function add_bulk_action( $actions ) {
        if ( current_user_can( 'manage_options' ) && ISXM_Settings::is_configured() ) {
            $actions[ self::BULK_ACTION ] = 'Offload ขึ้น cloud';
        }
        return $actions;
    }

    /**
     * Offload the selected attachments, then hand the counts to the notice
     * via the redirect URL — the same shape core's own bulk actions use.
     */
    public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
        if ( $action !== self::BULK_ACTION ) {
            return $redirect_to;
        }
        if ( ! current_user_can( 'manage_options' ) || ! ISXM_Settings::is_configured() ) {
            return $redirect_to;
        }

        $offload = new ISXM_Offload();
        $ok      = 0;
        $failed  = 0;
        $pairs   = [];

        foreach ( $post_ids as $post_id ) {
            $result = $offload->offload_attachment( (int) $post_id, null, true );
            if ( is_wp_error( $result ) ) {
                $failed++;
                continue;
            }
            $ok++;
            $item_pairs = ISXM_Offload::collect_url_pairs( (int) $post_id );
            ISXM_DB_Rewriter::update_attachment_guid( (int) $post_id, $item_pairs );
            $pairs = array_merge( $pairs, $item_pairs );
        }

        // One bulk DB rewrite for the whole selection, like the batch tools do.
        if ( ! empty( $pairs ) ) {
            ISXM_DB_Rewriter::replace_urls_bulk( $pairs );
        }
        // ...and, like them, only drop the local copies afterwards.
        ISXM_Offload::flush_local_deletions();

        return add_query_arg( [
            self::RESULT_OK    => $ok,
            self::RESULT_ERROR => $failed,
        ], $redirect_to );
    }

    public function render_bulk_notice() {
        if ( ! isset( $_GET[ self::RESULT_OK ] ) ) {
            return;
        }

        $ok     = (int) $_GET[ self::RESULT_OK ];
        $failed = isset( $_GET[ self::RESULT_ERROR ] ) ? (int) $_GET[ self::RESULT_ERROR ] : 0;

        $message = sprintf( 'Offload สำเร็จ %d รายการ', $ok );
        if ( $failed > 0 ) {
            $message .= sprintf(
                ' — ไม่ผ่าน %d รายการ (<a href="%s">ดูเฉพาะที่ไม่ผ่าน</a>)',
                $failed,
                esc_url( admin_url( 'upload.php?mode=list&' . self::FILTER_VAR . '=failed' ) )
            );
        }

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $failed > 0 ? 'warning' : 'success',
            wp_kses( $message, [ 'a' => [ 'href' => [] ] ] )
        );
    }

    /* ---------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'upload.php' || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // filemtime() for cache busting, same reasoning as the settings page.
        wp_enqueue_style(
            'isxs-media-library',
            ISXM_PLUGIN_URL . 'assets/css/media-library.css',
            [],
            filemtime( ISXM_PLUGIN_DIR . 'assets/css/media-library.css' )
        );
        wp_enqueue_script(
            'isxs-media-library',
            ISXM_PLUGIN_URL . 'assets/js/media-library.js',
            [ 'jquery' ],
            filemtime( ISXM_PLUGIN_DIR . 'assets/js/media-library.js' ),
            true
        );
        wp_localize_script( 'isxs-media-library', 'isxsMedia', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( ISXM_Tools::NONCE_ACTION ),
            'i18n'    => [
                'working' => 'กำลัง offload…',
                'error'   => 'เกิดข้อผิดพลาด',
            ],
        ] );
    }
}
