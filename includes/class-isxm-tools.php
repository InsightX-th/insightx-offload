<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Tools — AJAX endpoints for InsightX Storage.
 *
 * Settings save, connection test, offload stats, and the bulk tools
 * (offload / download / remove) processed in small batches so the
 * admin UI can show live progress.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Tools {

    const NONCE_ACTION       = 'isxs_admin_nonce';
    const MAX_BATCH          = 100;   // hard cap of items processed per AJAX request
    const TIME_BUDGET        = 15;    // seconds of processing per AJAX request
    /** Ceiling for set_time_limit() on AJAX requests. The batch loop itself
     *  is bounded by TIME_BUDGET, but the final bulk DB URL rewrite (and a
     *  network call whose own timeout somehow didn't fire) can outlive it.
     *  A finite ceiling protects the web server from a request that never
     *  returns, without capping the legitimately-long rewrite. */
    const MAX_REQUEST_SECONDS = 120;
    const PENDING_SCAN_WINDOW = 200;
    /** Rows per query of the one-time ledger backfill. Much larger than a
     *  transfer batch because it is pure DB work — no network, no image
     *  processing — so the cost per row is a fraction of a millisecond. */
    const BACKFILL_WINDOW     = 500;
    /** Rows per query of the one-pass end-of-run DB URL rewrite (the
     *  rewrite| phase). Also pure DB work, so like the backfill the window
     *  can be larger than a transfer batch — the real cost is the full-table
     *  probe, which runs ONCE per table per run regardless of window size. */
    const REWRITE_WINDOW      = 200;

    /** Short-lived cache of get_stats() so page loads don't re-run the COUNT
     *  + full-table-LIKE queries on every read — they dominate the DB at
     *  100k+ attachments. Anything that changes the answer (a finished run,
     *  a destination switch, any CLI command) asks for a fresh computation
     *  instead; see get_stats(). */
    const STATS_TRANSIENT = 'isxs_stats_cache';
    const STATS_TTL       = 60;

    /** @var array|null Per-request memo for get_stats(). */
    private static $stats_cache = null;

    /** @var ISXM_Job|null Job receiving intra-batch progress, if any. */
    private $progress_job = null;

    /** @var float Throttle stamp for progress_tick(). */
    private $progress_last_write = 0;

    /** @var ISXM_Tools|null Shared instance, so the background runner can
     *  reach the batch methods without re-registering every hook. */
    private static $instance = null;

    /** @var bool Guards against a second construction adding duplicate hooks. */
    private static $hooks_registered = false;

    public function __construct() {
        if ( self::$instance === null ) {
            self::$instance = $this;
        }
        if ( self::$hooks_registered ) {
            return;
        }
        self::$hooks_registered = true;

        add_action( 'wp_ajax_isxs_save_settings', [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_isxs_save_connection', [ $this, 'ajax_save_connection' ] );
        add_action( 'wp_ajax_isxs_stats', [ $this, 'ajax_stats' ] );
        add_action( 'wp_ajax_isxs_offload_single', [ $this, 'ajax_offload_single' ] );
        add_action( 'wp_ajax_isxs_sync_scan', [ $this, 'ajax_sync_scan' ] );
        add_action( 'wp_ajax_isxs_sync_apply', [ $this, 'ajax_sync_apply' ] );
        add_action( 'wp_ajax_isxs_sync_orphan_cleanup', [ $this, 'ajax_sync_orphan_cleanup' ] );
        add_action( 'wp_ajax_isxs_diagnostic', [ $this, 'ajax_diagnostic' ] );
    }

    /**
     * The shared instance. The bulk tools are no longer driven by their own
     * AJAX endpoints — ISXM_Background calls execute_batch() instead, and
     * needs the same object without side effects.
     *
     * @return ISXM_Tools
     */
    public static function instance() {
        if ( self::$instance === null ) {
            new self();
        }
        return self::$instance;
    }

    /**
     * Shared capability + nonce guard for every endpoint.
     */
    private function guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'ไม่มีสิทธิ์ใช้งาน' ], 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    public function ajax_save_settings() {
        $this->guard();

        $current = ISXM_Settings::all();
        $bools   = [ 'offload_enabled', 'remove_local', 'persist_urls', 'use_prefix', 'use_year_month', 'use_object_version', 'deliver_enabled', 'force_https', 'assets_enabled', 'assets_force_https', 'source_use_year_month', 'use_type_folder' ];

        $settings = [];
        foreach ( $bools as $key ) {
            $settings[ $key ] = ! empty( $_POST[ $key ] ) && $_POST[ $key ] !== 'false' && $_POST[ $key ] !== '0';
        }

        $allowed_providers     = array_keys( ISXM_Connections::providers() );
        $provider              = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'custom';
        $settings['provider']  = in_array( $provider, $allowed_providers, true ) ? $provider : 'custom';

        $source_provider              = isset( $_POST['source_provider'] ) ? sanitize_key( wp_unslash( $_POST['source_provider'] ) ) : 'custom';
        $settings['source_provider']  = in_array( $source_provider, $allowed_providers, true ) ? $source_provider : 'custom';

        $settings['cdn_domain'] = isset( $_POST['cdn_domain'] ) ? self::sanitize_cdn_domain( $_POST['cdn_domain'] ) : '';
        $settings['assets_cdn_domain'] = isset( $_POST['assets_cdn_domain'] ) ? self::sanitize_cdn_domain( $_POST['assets_cdn_domain'] ) : '';

        $settings['prefix']        = isset( $_POST['prefix'] ) ? self::sanitize_prefix( $_POST['prefix'] ) : '';
        $settings['source_prefix'] = isset( $_POST['source_prefix'] ) ? self::sanitize_prefix( $_POST['source_prefix'] ) : '';

        // Folder names go straight into an object key, so they get
        // sanitize_title() rather than sanitize_text_field() — that also
        // rules out the traversal and separator characters a key must not
        // contain. An empty name would collapse a path level (and could
        // collide two content types into one folder), so blank falls back
        // to the default instead of being stored.
        foreach ( ISXM_Settings::type_folder_fields() as $folder_key => $folder_meta ) {
            $folder_value = isset( $_POST[ $folder_key ] ) ? sanitize_title( wp_unslash( $_POST[ $folder_key ] ) ) : '';
            $settings[ $folder_key ] = $folder_value !== '' ? $folder_value : $folder_meta['default'];
        }

        $settings['source_public_base_url'] = isset( $_POST['source_public_base_url'] )
            ? esc_url_raw( trim( wp_unslash( $_POST['source_public_base_url'] ) ) )
            : '';
        // Only http(s) base URLs make sense as a DB-search target — a
        // relative or scheme-less value can never match a real URL and
        // would just pollute the rewrite map.
        if ( $settings['source_public_base_url'] !== '' && ! preg_match( '#^https?://#i', $settings['source_public_base_url'] ) ) {
            $settings['source_public_base_url'] = '';
        }

        // Detect a destination switch before saving overwrites $current —
        // the frontend uses this to reset all 4 tools' UI/resume state,
        // since a stale "ทำต่อ" pointing at the previous destination is
        // meaningless once it has changed. Connection details (bucket/
        // endpoint/etc.) now live entirely in ISXM_Connections keyed by
        // provider slug, so a provider change here IS a destination change;
        // an edit to the currently-selected provider's own connection
        // details is signalled separately by ajax_save_connection().
        $destination_changed = ( $current['provider'] !== $settings['provider'] );

        ISXM_Settings::save( $settings );

        wp_send_json_success( [
            'message'              => 'บันทึกการตั้งค่าเรียบร้อย',
            'public_base_url'      => ISXM_Settings::public_base_url(),
            'destination_changed'  => $destination_changed,
            // Refreshed on every response: an admin page left open past the
            // nonce's 24h lifetime otherwise starts failing every save with
            // a bare "เกิดข้อผิดพลาด" and no way to tell why.
            'nonce'                => wp_create_nonce( self::NONCE_ACTION ),
        ] );
    }

    /**
     * Validate a custom delivery domain (CDN) value.
     *
     * cdn_domain is embedded into every rewritten media URL and into the
     * old-URL patterns of the permanent DB rewrite, so it must be a plain
     * hostname (optionally http(s)-prefixed, optionally with a port) —
     * never a path, query string or scheme-relative junk that would
     * corrupt URLs. Invalid values fall back to '' (the provider's own
     * URL is used instead) rather than producing broken links.
     *
     * @param mixed $value Raw user input.
     * @return string Normalized hostname, or '' when invalid.
     */
    private static function sanitize_cdn_domain( $value ) {
        $value = sanitize_text_field( wp_unslash( (string) $value ) );
        $value = untrailingslashit( preg_replace( '#^https?://#i', '', trim( $value ) ) );
        if ( $value === '' ) {
            return '';
        }
        // hostname[:port] — letters, digits, dots, hyphens (underscores
        // allowed for internal LAN hosts), plus an optional numeric port.
        if ( ! preg_match( '#^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(?::\d{1,5})?$#', $value ) ) {
            return '';
        }
        return $value;
    }

    /**
     * Sanitize a bucket path prefix (destination and source).
     *
     * Keeps the existing behaviour (strip '..' and backslashes) and adds
     * control-character removal. The prefix becomes object keys and URL
     * path segments — it is not an injection vector (every consumer either
     * prepares its SQL or URL-encodes path segments), so spaces/unicode
     * stay allowed while the characters that break paths are refused.
     *
     * @param mixed $value Raw user input.
     * @return string Normalized prefix (trailing slash, no leading slash), or ''.
     */
    private static function sanitize_prefix( $value ) {
        $value = sanitize_text_field( wp_unslash( (string) $value ) );
        $value = trim( str_replace( [ '..', '\\' ], '', $value ) );
        $value = preg_replace( '#[\x00-\x1f\x7f]#', '', $value );
        if ( $value === '' ) {
            return '';
        }
        return trailingslashit( ltrim( $value, '/' ) );
    }

    /**
     * Save + test one provider's connection card (Connections tab). Save
     * and test are one action here — there's no separate "test" step,
     * matching the InsightX Backup destinations UX this tab is modelled on.
     */
    public function ajax_save_connection() {
        $this->guard();

        $providers = ISXM_Connections::providers();
        $provider  = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
        if ( ! array_key_exists( $provider, $providers ) ) {
            wp_send_json_error( [ 'message' => 'Provider ไม่ถูกต้อง' ] );
        }

        $current = ISXM_Connections::get( $provider );

        $region = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '';
        if ( $region === '' ) {
            $region = $providers[ $provider ]['region_default'];
        }

        // Blank secret keeps the stored one (same UX as ISX Form SMTP password).
        $secret = isset( $_POST['secret_key'] ) ? trim( wp_unslash( $_POST['secret_key'] ) ) : '';

        $config = [
            'endpoint'        => isset( $_POST['endpoint'] ) ? esc_url_raw( trim( wp_unslash( $_POST['endpoint'] ) ) ) : '',
            'region'          => $region,
            'bucket'          => isset( $_POST['bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['bucket'] ) ) : '',
            'access_key'      => isset( $_POST['access_key'] ) ? sanitize_text_field( wp_unslash( $_POST['access_key'] ) ) : '',
            'secret_key'      => $secret !== '' ? $secret : $current['secret_key'],
            'path_style'      => ! empty( $_POST['path_style'] ) && $_POST['path_style'] !== 'false' && $_POST['path_style'] !== '0',
            'send_public_acl' => ! empty( $_POST['send_public_acl'] ) && $_POST['send_public_acl'] !== 'false' && $_POST['send_public_acl'] !== '0',
        ];

        ISXM_Connections::save_one( $provider, $config );

        $connected = false;
        $message   = 'บันทึกแล้ว — ยังกรอกไม่ครบ';

        if ( ISXM_Connections::is_configured( $provider ) ) {
            $client = new ISXM_Client( ISXM_Connections::get( $provider ) );
            $result = $client->test_connection();
            if ( is_wp_error( $result ) ) {
                $message = $result->get_error_message();
                ISXM_Connections::save_status( $provider, 'error', $message );
            } else {
                $connected = true;
                $message   = 'เชื่อมต่อ bucket สำเร็จ';
                ISXM_Connections::save_status( $provider, 'ok', $message );
            }
        }

        $s = ISXM_Settings::all();

        wp_send_json_success( [
            'message'             => $message,
            'connected'           => $connected,
            'configured'          => ISXM_Connections::is_configured( $provider ),
            'affects_destination' => ( $s['provider'] === $provider ),
            'affects_source'      => ( $s['source_provider'] === $provider ),
            // See ajax_save_settings(): the "บันทึกและเชื่อมต่อ" button was
            // the one place with no nonce refresh at all, so on a page left
            // open overnight it simply stopped connecting.
            'nonce'               => wp_create_nonce( self::NONCE_ACTION ),
        ] );
    }

    /**
     * Real object count in the source bucket (List Objects V2) — reference
     * number for the Migrate tab, independent of the WP-attachment-based
     * pending count that drives the actual batch loop.
     */
    public function ajax_migrate_source_count() {
        $this->guard();

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }

        $token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $result = ISXM_Migrate::count_source_objects( [], $token );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( $result ); // carries total, next_token, complete
    }

    /* ---------------------------------------------------------------------
     * Sync tool — reconcile tracking meta against the real bucket
     * ------------------------------------------------------------------ */

    /**
     * One time-budgeted batch of the Sync scan. Three phases, with the big
     * expected-key set spilled to temp files (uploads/isxs-sync/) so a
     * six-figure library doesn't round-trip a giant array through a
     * transient on every request:
     *
     * 1. "expected" — append every tracked attachment's expected object
     *    keys to the run's .expected.jsonl (bounded DB windows).
     * 2. "list" — paginate the destination bucket's real listing, appending
     *    every listed key to .listed.jsonl (orphan classification happens
     *    in the finish phase, not here).
     * 3. "finish" — load the expected map once, stream .listed.jsonl, and
     *    compute stale (primary missing) / partial (some sizes missing) /
     *    orphan / outside-prefix. The result is saved for the apply step;
     *    the temp files are deleted.
     *
     * The scan never writes the database; it only reports (the client then
     * offers "ล้าง meta ค้าง" which calls isxs_sync_apply).
     */
    public function ajax_sync_scan() {
        $this->guard();

        if ( ! ISXM_Settings::is_configured() ) {
            wp_send_json_error( [ 'message' => 'ยังตั้งค่า storage ไม่ครบ — บันทึกการตั้งค่าก่อน' ] );
        }

        $run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
        if ( $run_id === '' ) {
            wp_send_json_error( [ 'message' => 'run_id หายไป — ลองใหม่อีกครั้ง' ] );
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }
        $started = microtime( true );
        $budget  = self::time_budget();

        $state = ISXM_Sync::load_state( $run_id );
        if ( $state === null ) {
            ISXM_Sync::cleanup_old_files();
            $state = [
                'phase'          => 'expected',
                'after_id'       => 0,
                'attach_count'   => 0,
                'token'          => '',
                'scanned_objects'=> 0,
                'bucket'         => ISXM_Settings::get( 'bucket' ),
                'endpoint'       => ISXM_Settings::get( 'endpoint' ),
            ];
        }

        // A scan started against one destination must not finish against
        // another — the expected keys wouldn't match anything.
        if ( $state['bucket'] !== ISXM_Settings::get( 'bucket' ) || $state['endpoint'] !== ISXM_Settings::get( 'endpoint' ) ) {
            ISXM_Sync::delete_state( $run_id );
            ISXM_Sync::cleanup_run_files( $run_id );
            wp_send_json_error( [ 'message' => 'ปลายทาง (bucket) เปลี่ยนไปตั้งแต่เริ่มสแกน — กดตรวจสอบใหม่อีกครั้ง' ] );
        }

        $processed = 0;
        $errors    = [];

        do {
            if ( $state['phase'] === 'expected' ) {
                $window = ISXM_Sync::scan_expected_window( self::MAX_BATCH, $state['after_id'] );
                $state['after_id']     = $window['last_id'];
                $state['attach_count'] += $window['count'];
                ISXM_Sync::append_expected( $run_id, $window['pairs'] );
                $processed += $window['count'];
                if ( $window['done'] ) {
                    $state['phase'] = 'list';
                    $state['token'] = '';
                }
            } elseif ( $state['phase'] === 'list' ) {
                $page = ( new ISXM_Client() )->list_objects_keys_page( $state['token'], 1000 );
                if ( is_wp_error( $page ) ) {
                    // Page listing failed (network/credentials/timeout) —
                    // not "no more pages". Keep the token so a resumed run
                    // retries this exact page instead of skipping it.
                    $errors[] = $page->get_error_message();
                    break;
                }
                ISXM_Sync::append_listed( $run_id, $page['keys'] );
                $state['scanned_objects'] += count( $page['keys'] );
                $processed               += count( $page['keys'] );
                $state['token']           = $page['next_token'];
                if ( $state['token'] === '' ) {
                    $state['phase'] = 'finish';
                }
            }
            $this->progress_tick( $processed );
        } while ( $state['phase'] !== 'finish' && microtime( true ) - $started < $budget );

        // Finish phase (same request when the listing just completed): load
        // the expected map, stream the listed file, classify. The map load
        // is a single disk read — seconds at worst, which is the whole
        // point of spilling to disk instead of a per-request transient.
        if ( $state['phase'] === 'finish' ) {
            $result = $this->sync_finish_scan( $run_id, $state, $processed, $errors );
            $finish_ok = empty( $result['errors'] );
            wp_send_json_success( [
                'processed' => $result['processed'],
                'last_id'   => '',
                // A failed finish (e.g. the expected-key file expired) is a
                // stall, not a completed scan — the client stops and shows
                // the error instead of rendering an empty "everything ok"
                // report.
                'done'      => $finish_ok,
                'stalled'   => ! $finish_ok,
                'errors'    => $result['errors'],
                'result'    => ISXM_Sync::get_result( $run_id ),
                'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
            ] );
        }

        ISXM_Sync::save_state( $run_id, $state );
        wp_send_json_success( [
            'processed' => $processed,
            'last_id'   => '',
            'done'      => false,
            'stalled'   => ! empty( $errors ),
            'errors'    => $errors,
            'result'    => null,
            'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
        ] );
    }

    /**
     * The "finish" phase of the sync scan: load the run's expected map,
     * stream the listed-keys file, and split the outcome into stale /
     * partial / complete, counting orphans (in-prefix, unmatched) and
     * outside-prefix objects. Saves the result and cleans up temp files.
     *
     * @param string $run_id
     * @param array  $state
     * @param int    $processed
     * @param array  $errors
     * @return array{processed:int,errors:string[]}
     */
    private function sync_finish_scan( $run_id, array $state, $processed, array $errors ) {
        // Load the expected map from the run's temp file, rebuilding from
        // the DB in one pass when the file is missing (deleted out-of-band
        // or never created — e.g. nothing tracks this destination yet).
        // A missing file is a condition to recover from, never an error to
        // show the user.
        $e = ISXM_Sync::load_or_build_expected( $run_id );
        if ( $e === null ) {
            $errors[] = 'สแกนไม่เสร็จสมบูรณ์ — กดลองใหม่อีกครั้ง';
            ISXM_Sync::delete_state( $run_id );
            return [ 'processed' => $processed, 'errors' => $errors ];
        }

        $listed = ISXM_Sync::listed_file( $run_id );
        $found         = [];
        $primary_found = [];
        $orphan        = 0;
        $orphan_sample = [];
        $outside       = 0;
        $prefix        = ISXM_Settings::key_prefix();

        if ( is_file( $listed ) ) {
            $fh = @fopen( $listed, 'r' );
            if ( $fh !== false ) {
                while ( ( $line = fgets( $fh ) ) !== false ) {
                    $entry = json_decode( $line, true );
                    $key   = is_array( $entry ) && isset( $entry[0] ) ? (string) $entry[0] : '';
                    if ( $key === '' ) {
                        continue;
                    }
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
                        if ( count( $orphan_sample ) < ISXM_Sync::ORPHAN_SAMPLE_MAX ) {
                            $orphan_sample[] = $key;
                        }
                    }
                }
                fclose( $fh );
            }
        }

        $stale_ids   = [];
        $partial_ids = [];
        foreach ( $e['counts'] as $id => $expected_n ) {
            if ( empty( $primary_found[ $id ] ) ) {
                $stale_ids[] = $id;
            } elseif ( ( $found[ $id ] ?? 0 ) < $expected_n ) {
                $partial_ids[] = $id;
            }
        }

        // Attachments whose primary is gone AND the local copy is gone too
        // are a data-loss situation worth calling out before anything is
        // cleaned (re-upload would fail with "ไม่พบไฟล์ต้นฉบับ").
        $data_loss_ids = [];
        if ( ! empty( $stale_ids ) ) {
            ISXM_Tools::prime_caches( $stale_ids );
            foreach ( $stale_ids as $id ) {
                // Resolved against the current uploads dir — a stale
                // absolute path in the meta used to make perfectly present
                // files look like data loss.
                if ( ! ISXM_Offload::local_file_exists( (int) $id ) ) {
                    $data_loss_ids[] = (int) $id;
                }
            }
        }

        $result = [
            'stale_ids'      => $stale_ids,
            'partial_ids'    => $partial_ids,
            'data_loss_ids'  => $data_loss_ids,
            'orphan'         => $orphan,
            'orphan_sample'  => $orphan_sample,
            'outside_prefix' => $outside,
            'scanned_objects'=> $state['scanned_objects'],
            'attach_count'   => $state['attach_count'],
            'bucket'         => $state['bucket'],
            'endpoint'       => $state['endpoint'],
        ];

        ISXM_Sync::save_result( $run_id, $result );
        ISXM_Sync::delete_state( $run_id );
        ISXM_Sync::cleanup_run_files( $run_id );
        ISXM_Sync::mark_run( ISXM_Sync::result_is_clean( $result ) );

        return [ 'processed' => $processed, 'errors' => $errors ];
    }

    /**
     * Delete the tracking meta of the attachments the scan found stale
     * (records saying "on the bucket" whose files are actually gone), so
     * Offload/Migrate re-upload them. Only records still pointing at the
     * current destination are touched — each one is re-checked here, not
     * blindly trusted from the scan result.
     */
    public function ajax_sync_apply() {
        $this->guard();

        if ( ! ISXM_Settings::is_configured() ) {
            wp_send_json_error( [ 'message' => 'ยังตั้งค่า storage ไม่ครบ' ] );
        }

        $run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
        if ( $run_id === '' ) {
            wp_send_json_error( [ 'message' => 'run_id หายไป — กดซิงก์กับ bucket ก่อน' ] );
        }
        $result = ISXM_Sync::get_result( $run_id );
        if ( ! is_array( $result ) || empty( $result['stale_ids'] ) ) {
            wp_send_json_error( [ 'message' => 'ไม่มีผลตรวจสอบให้ล้าง — กด “ซิงก์ให้ตรงกับ bucket” ก่อน' ] );
        }
        if ( $result['bucket'] !== ISXM_Settings::get( 'bucket' ) || $result['endpoint'] !== ISXM_Settings::get( 'endpoint' ) ) {
            ISXM_Sync::delete_result( $run_id );
            wp_send_json_error( [ 'message' => 'ปลายทาง (bucket) เปลี่ยนไปตั้งแต่ตรวจสอบ — กดตรวจสอบใหม่อีกครั้งก่อนล้าง' ] );
        }

        $out = ISXM_Sync::cleanup_stale( $result['stale_ids'] );
        ISXM_Sync::delete_result( $run_id );

        wp_send_json_success( [
            'processed' => $out['cleaned'],
            'data_loss' => $out['data_loss'],
            'done'      => true,
            // The numbers changed — recompute (also refreshes the cache).
            'stats'     => self::get_stats( true ),
            'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
        ] );
    }

    /* ---------------------------------------------------------------------
     * Sync tool — orphan cleanup (delete objects no attachment references)
     * ------------------------------------------------------------------ */

    /**
     * One time-budgeted batch of the orphan cleanup. Two phases:
     *
     * 1. "expected" — rebuild the expected-key file (fresh, so uploads that
     *    landed since the scan are protected — they'd be in the new map).
     * 2. "delete" — list pages, and DELETE every key that is (a) not in the
     *    expected map and (b) under the configured prefix. Objects outside
     *    the prefix are never touched — the bucket may be shared.
     *
     * Destructive by nature; the client confirms before starting.
     */
    public function ajax_sync_orphan_cleanup() {
        $this->guard();

        if ( ! ISXM_Settings::is_configured() ) {
            wp_send_json_error( [ 'message' => 'ยังตั้งค่า storage ไม่ครบ' ] );
        }

        $run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
        if ( $run_id === '' ) {
            wp_send_json_error( [ 'message' => 'run_id หายไป — ลองใหม่อีกครั้ง' ] );
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }
        $started = microtime( true );
        $budget  = self::time_budget();

        $state = get_transient( ISXM_Sync::ORPHAN_STATE_TRANSIENT_PREFIX . $run_id );
        if ( ! is_array( $state ) ) {
            ISXM_Sync::cleanup_old_files();
            $state = [
                'phase'    => 'expected',
                'after_id' => 0,
                'token'    => '',
                'deleted'  => 0,
                'scanned'  => 0,
                'bucket'   => ISXM_Settings::get( 'bucket' ),
                'endpoint' => ISXM_Settings::get( 'endpoint' ),
            ];
        }
        if ( $state['bucket'] !== ISXM_Settings::get( 'bucket' ) || $state['endpoint'] !== ISXM_Settings::get( 'endpoint' ) ) {
            delete_transient( ISXM_Sync::ORPHAN_STATE_TRANSIENT_PREFIX . $run_id );
            ISXM_Sync::cleanup_run_files( $run_id );
            wp_send_json_error( [ 'message' => 'ปลายทาง (bucket) เปลี่ยนไปตั้งแต่เริ่ม — กดลองใหม่อีกครั้ง' ] );
        }

        $processed = 0;
        $errors    = [];
        $done      = false;

        do {
            if ( $state['phase'] === 'expected' ) {
                $window = ISXM_Sync::scan_expected_window( self::MAX_BATCH, $state['after_id'] );
                $state['after_id'] = $window['last_id'];
                ISXM_Sync::append_expected( $run_id, $window['pairs'] );
                $processed += $window['count'];
                if ( $window['done'] ) {
                    $state['phase'] = 'delete';
                    $state['token'] = '';
                }
            } elseif ( $state['phase'] === 'delete' ) {
                $e = ISXM_Sync::load_or_build_expected( $run_id );
                if ( $e === null ) {
                    $errors[] = 'สแกนไม่เสร็จสมบูรณ์ — กดลองใหม่อีกครั้ง';
                    break;
                }
                $client = new ISXM_Client();
                $prefix = ISXM_Settings::key_prefix();
                $page   = $client->list_objects_keys_page( $state['token'], 1000 );
                if ( is_wp_error( $page ) ) {
                    $errors[] = $page->get_error_message();
                    break;
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
                        $state['deleted']++;
                    } else {
                        $errors[] = sprintf( 'ลบ %s ไม่สำเร็จ: %s', $key, $result->get_error_message() );
                    }
                }
                $state['scanned'] += count( $page['keys'] );
                $processed       += count( $page['keys'] );
                $state['token']   = $page['next_token'];
                if ( $state['token'] === '' ) {
                    $done = true;
                }
            }
            $this->progress_tick( $processed );
        } while ( ! $done && microtime( true ) - $started < $budget );

        if ( $done ) {
            ISXM_Sync::cleanup_run_files( $run_id );
            delete_transient( ISXM_Sync::ORPHAN_STATE_TRANSIENT_PREFIX . $run_id );
        } else {
            set_transient( ISXM_Sync::ORPHAN_STATE_TRANSIENT_PREFIX . $run_id, $state, ISXM_Sync::STATE_TTL );
        }

        wp_send_json_success( [
            'processed' => $processed,
            'deleted'   => $state['deleted'],
            'last_id'   => '',
            'done'      => $done,
            'stalled'   => ! empty( $errors ),
            'errors'    => $errors,
            'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
        ] );
    }

    /* ---------------------------------------------------------------------
     * Stats
     * ------------------------------------------------------------------ */

    /**
     * Offload progress numbers.
     *
     * Everything here is bucket+endpoint-filtered the same way
     * get_pending_ids()/get_offloaded_ids() are, so progress and ETA never
     * go wrong for any of the four bulk tools (an attachment already offloaded
     * to a *previous* destination must not count as "on this destination"
     * for the currently configured one — same bucket name reused under a
     * different provider/endpoint is a different physical bucket). All
     * four tools (offload/download/remove/migrate) are destination-aware —
     * none of them act on a destination that isn't currently configured.
     *
     * `in_bucket` counts every attachment physically on the current
     * destination regardless of which tool put it there (Offload OR
     * Migrate) — it is what the Overview ring, the CLI status and every
     * tool's denominator use, so a % shown matches what the tools will
     * actually process (`total - in_bucket`). `offloaded` is kept as a
     * secondary figure additionally filtered to the Offload tool's own
     * origin — it is nobody's denominator, but it separates the tool's own
     * work from files that arrived via Migrate.
     *
     * Two implementations. With the ledger built (ISXM_Items::ready()) the
     * destination figures are one indexed pass over an indexed table. Until
     * then — an upgraded site whose backfill hasn't finished — the original
     * path runs, where each filter is a leading-wildcard LIKE against the
     * serialized postmeta that MySQL can only answer with a full scan. Both
     * produce identical numbers; the ledger path is roughly 3x faster on a
     * 17k-attachment library and pulls further ahead as the library grows.
     *
     * Either way the result is memoized for the request and cached in a
     * short transient; callers that must see the effect of work they just
     * did pass $fresh = true.
     *
     * @param bool $fresh Recompute instead of reading the cache.
     * @return array{total:int,offloaded:int,in_bucket:int,partial:int,failed:int,percent:int,wc_total?:int}
     */
    public static function get_stats( $fresh = false ) {
        global $wpdb;

        if ( ! $fresh ) {
            if ( self::$stats_cache !== null ) {
                return self::$stats_cache;
            }
            $cached = get_transient( self::STATS_TRANSIENT );
            if ( is_array( $cached ) ) {
                self::$stats_cache = $cached;
                return $cached;
            }
        }

        // Trash excluded everywhere, matching what the Media Library screen
        // actually shows — trashed attachments are invisible there, so
        // counting them made the ring's `total` (and the tools' pending
        // denominators) disagree with the numbers users could verify.
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash'"
        );

        // Once the ledger is filled, every figure below is an indexed COUNT
        // instead of the leading-wildcard LIKE scans this method used to run
        // four times per call. Same numbers, same definitions — see
        // stats_from_items().
        if ( ISXM_Items::ready() ) {
            return self::finish_stats( self::stats_from_items( $total ) );
        }

        // Which tool put a file on the bucket. Used below to separate the
        // Offload tool's own work from files that arrived via Migrate.
        $origin_needle = 's:6:"origin";s:7:"offload"';

        $current_bucket   = ISXM_Settings::get( 'bucket' );
        $current_endpoint = ISXM_Settings::get( 'endpoint' );
        $offloaded        = 0;
        $in_bucket        = 0;
        $partial          = 0;
        if ( $current_bucket !== '' ) {
            // LIKE-match the serialized 'bucket' and 'endpoint' entries in-SQL
            // instead of unserializing every row in PHP — same technique
            // already used by ISXM_DB_Rewriter::update_table(). Both keys are
            // always written (class-isxm-offload.php), so each is a stable
            // substring of the serialized meta_value. Matching bucket alone
            // would wrongly count files offloaded to a same-named bucket
            // under a different provider/endpoint as "on this destination".
            $bucket_needle   = 's:6:"bucket";s:' . strlen( $current_bucket ) . ':"' . $current_bucket . '"';
            $endpoint_needle = 's:8:"endpoint";s:' . strlen( $current_endpoint ) . ':"' . $current_endpoint . '"';
            $offloaded = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND m.meta_value LIKE %s AND m.meta_value LIKE %s AND m.meta_value LIKE %s",
                ISXM_Offload::META_KEY,
                '%' . $wpdb->esc_like( $bucket_needle ) . '%',
                '%' . $wpdb->esc_like( $endpoint_needle ) . '%',
                '%' . $wpdb->esc_like( $origin_needle ) . '%'
            ) );

            // Same bucket+endpoint match WITHOUT the origin needle — every
            // attachment physically in the current bucket regardless of which
            // tool put it there (Offload OR Migrate). This is the true
            // denominator for Download/Remove, which act on all of them
            // (get_offloaded_ids() keys on bucket/endpoint only). `offloaded`
            // above is origin-filtered, so it undercounts migrated files and
            // made those tools' ETA/progress never appear — hence `in_bucket`
            // is the true denominator (and what the Overview ring shows).
            $in_bucket = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND m.meta_value LIKE %s AND m.meta_value LIKE %s",
                ISXM_Offload::META_KEY,
                '%' . $wpdb->esc_like( $bucket_needle ) . '%',
                '%' . $wpdb->esc_like( $endpoint_needle ) . '%'
            ) );

            // On the current destination but with a `missing` entry — some
            // sizes never made it up (absent locally or rejected). These are
            // counted inside `in_bucket` too, but showing them separately is
            // what lets a user tell "complete" from "almost" at a glance.
            $missing_needle = 's:7:"missing";';
            $partial = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
                 WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND m.meta_value LIKE %s AND m.meta_value LIKE %s AND m.meta_value LIKE %s",
                ISXM_Offload::META_KEY,
                '%' . $wpdb->esc_like( $missing_needle ) . '%',
                '%' . $wpdb->esc_like( $bucket_needle ) . '%',
                '%' . $wpdb->esc_like( $endpoint_needle ) . '%'
            ) );
        }

        // Attachments whose last offload attempt failed — shared with the
        // ledger path, since the presence of the error meta is the whole
        // condition either way.
        $failed = self::count_failed();

        return self::finish_stats( [
            'total'                => $total,
            'offloaded'            => $offloaded,
            'in_bucket'            => $in_bucket,
            'partial'              => $partial,
            'failed'               => $failed,
        ] );
    }

    /**
     * The same figures as the legacy postmeta path, read from the ledger:
     * indexed COUNTs on (bucket, endpoint) instead of four full scans of
     * postmeta matching a serialized substring.
     *
     * Definitions are unchanged on purpose, so switching stores cannot move
     * a number:
     *  - in_bucket  : every attachment physically on the current destination,
     *                 whichever tool put it there (this is what the Overview
     *                 ring shows and what Download/Remove drain).
     *  - offloaded  : in_bucket restricted to origin 'offload' — the
     *                 diagnostics figure that excludes migrated files.
     *  - partial    : on the destination but missing at least one size.
     *
     * @param int $total Attachment count (already computed by the caller).
     * @return array
     */
    private static function stats_from_items( $total ) {
        $bucket   = (string) ISXM_Settings::get( 'bucket' );
        $endpoint = (string) ISXM_Settings::get( 'endpoint' );

        if ( $bucket === '' ) {
            // No destination configured: nothing can be "on it". Matches the
            // legacy path, which skipped these queries entirely.
            return [
                'total'                => $total,
                'offloaded'            => 0,
                'in_bucket'            => 0,
                'partial'              => 0,
                'failed'               => self::count_failed(),
            ];
        }

        // One pass for the three destination figures — see
        // ISXM_Items::destination_counts().
        $counts = ISXM_Items::destination_counts( $bucket, $endpoint );

        return [
            'total'                => $total,
            'offloaded'            => $counts['offloaded'],
            'in_bucket'            => $counts['in_bucket'],
            'partial'              => $counts['partial'],
            'failed'               => self::count_failed(),
        ];
    }

    /**
     * Attachments whose last offload attempt failed. One indexed meta_key
     * count — this one never needed the ledger, since the presence of
     * `_isxs_offload_error` IS the condition.
     *
     * @return int
     */
    private static function count_failed() {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'",
            ISXM_Offload::ERROR_META_KEY
        ) );
    }

    /**
     * Add the derived/optional figures and cache the result. Shared by both
     * the ledger path and the legacy postmeta path so they can't drift.
     *
     * @param array $stats
     * @return array
     */
    private static function finish_stats( array $stats ) {
        $stats['percent'] = $stats['total'] > 0
            ? (int) floor( $stats['in_bucket'] / $stats['total'] * 100 )
            : 0;

        // Denominator for the WooCommerce Downloadable Products tool — count
        // of downloadable products/variations it will scan. Only meaningful
        // (and only queried) when WooCommerce is active.
        if ( class_exists( 'WC_Product' ) ) {
            $stats['wc_total'] = ISXM_WC_Downloads::count_pending();
        }

        set_transient( self::STATS_TRANSIENT, $stats, self::STATS_TTL );
        self::$stats_cache = $stats;

        return $stats;
    }

    public function ajax_stats() {
        $this->guard();
        // The UI only asks for this after something changed the answer
        // (a destination switch, a finished run) — never serve it a cache.
        wp_send_json_success( self::get_stats( true ) );
    }

    /* ---------------------------------------------------------------------
     * Tool registry — the six bulk tools ISXM_Background can run
     * ------------------------------------------------------------------ */

    /**
     * Tool slug => human label. The slug is what the UI, the job records
     * and WP-CLI all use; nothing keys off the old wp_ajax action names
     * any more.
     *
     * @return array<string,string>
     */
    public static function tools() {
        return [
            'offload'      => 'อัปโหลดไฟล์ที่เหลือขึ้น cloud',
            'retry_failed' => 'ลองใหม่เฉพาะรายการที่ไม่ผ่าน',
            'download'     => 'ดาวน์โหลดไฟล์กลับจาก bucket',
            'remove'       => 'ลบไฟล์ออกจาก bucket',
            'migrate'      => 'ย้ายไฟล์จาก source bucket',
            'wc_downloads' => 'ตรวจไฟล์สินค้า WooCommerce',
            // Internal: has no card in the UI. Runs once per site to copy
            // legacy `_isxs_offload` postmeta into the ISXM_Items ledger,
            // and is started automatically (see maybe_start_backfill()).
            'backfill'     => 'ย้ายข้อมูลติดตามไฟล์ไปยังตารางใหม่',
        ];
    }

    /**
     * Tools with a card in the admin UI — everything except the internal
     * backfill.
     *
     * @return array<string,string>
     */
    public static function visible_tools() {
        $tools = self::tools();
        unset( $tools['backfill'] );
        return $tools;
    }

    /**
     * Start the one-time ledger backfill if this site still needs it.
     *
     * Called from the admin page and from the background healthcheck, so
     * it begins on its own after an upgrade instead of waiting for someone
     * to find a button. Until it finishes, ISXM_Items::ready() stays false
     * and every count and scan uses the original postmeta path — the site
     * behaves exactly as it did before, just without the speed-up yet.
     *
     * @return void
     */
    public static function maybe_start_backfill() {
        if ( ISXM_Items::ready() ) {
            return;
        }

        // A site with no legacy records (fresh install) has nothing to
        // copy — switch straight over rather than queueing an empty run.
        if ( ISXM_Items::count_legacy_records() === 0 ) {
            ISXM_Items::mark_backfilled();
            self::flush_stats_cache();
            return;
        }

        $existing = ISXM_Job::get( 'backfill' );
        if ( $existing && ( $existing->state === ISXM_Job::STATE_RUNNING || $existing->is_resumable() ) ) {
            return; // already going, or waiting to be resumed
        }
        // Never elbow a real tool aside: the backfill is not urgent, and
        // only one job may run at a time.
        if ( ISXM_Job::running() ) {
            return;
        }

        ISXM_Job::start( 'backfill', ISXM_Items::count_legacy_records() );
        ISXM_Background::ensure_healthcheck_scheduled();
        ISXM_Background::dispatch();
    }

    /**
     * @param string $tool
     * @return bool
     */
    public static function is_known_tool( $tool ) {
        return array_key_exists( $tool, self::tools() );
    }

    /**
     * @param string $tool
     * @return string
     */
    public static function tool_label( $tool ) {
        $tools = self::tools();
        return isset( $tools[ $tool ] ) ? $tools[ $tool ] : $tool;
    }

    /**
     * Refuse to start a tool that cannot possibly work, with the reason —
     * far better than letting it start and fail on the first item.
     *
     * @param string $tool
     * @return true|WP_Error
     */
    public static function precheck_tool( $tool ) {
        if ( ! self::is_known_tool( $tool ) ) {
            return new WP_Error( 'isxs_unknown_tool', 'ไม่รู้จักเครื่องมือนี้' );
        }
        // Pure database work — it must be able to run on a site whose
        // storage settings are incomplete, or an install that never
        // finished configuring would be stuck on the slow path forever.
        if ( $tool === 'backfill' ) {
            return true;
        }
        if ( ! ISXM_Settings::is_configured() ) {
            return new WP_Error( 'isxs_not_configured', 'ยังตั้งค่า storage ปลายทางไม่ครบ — ตั้งค่าที่แท็บ “การเชื่อมต่อ” ก่อน' );
        }
        if ( $tool === 'migrate' ) {
            $s = ISXM_Settings::all();
            if ( $s['source_bucket'] === '' || $s['source_access_key'] === '' || $s['source_secret_key'] === '' ) {
                return new WP_Error( 'isxs_no_source', 'ยังตั้งค่า source bucket ไม่ครบ — ตั้งค่าที่แท็บ “การเชื่อมต่อ” ก่อน' );
            }
            // Same bucket AND same prefix is a bucket-to-itself copy: every
            // object would be read and written back over itself. Same bucket
            // with a DIFFERENT prefix is legitimate though (moving media to
            // a new key layout), so only the truly circular case is refused.
            if ( $s['source_bucket'] === $s['bucket']
                && $s['source_endpoint'] === $s['endpoint']
                && $s['source_prefix'] === ISXM_Settings::key_prefix()
            ) {
                return new WP_Error( 'isxs_same_bucket', 'source กับ destination ชี้ไปที่ bucket และ prefix เดียวกัน — เลือก provider ต้นทางให้ต่างจากปลายทาง (หรือเปลี่ยน prefix) ก่อน' );
            }
        }
        if ( $tool === 'wc_downloads' && ! class_exists( 'WC_Product' ) ) {
            return new WP_Error( 'isxs_no_woocommerce', 'ไม่พบ WooCommerce บนเว็บนี้' );
        }

        // is_configured() only proves the fields are filled in — it never
        // asks the provider whether the bucket is actually there. A typo'd
        // or deleted bucket therefore passed this check and the run went on
        // to fail every single item with the same 404 NoSuchBucket, tens of
        // thousands of times, before anyone could tell why. One HEAD here
        // turns that into a refusal with the real reason.
        $reachable = self::destination_reachable();
        if ( is_wp_error( $reachable ) ) {
            return $reachable;
        }

        return true;
    }

    /**
     * How long a successful destination probe is trusted. Long enough that
     * starting several tools in a row costs one round trip, short enough
     * that a bucket deleted mid-session is noticed on the next start.
     */
    const DESTINATION_PROBE_TTL = 300;

    /** Cached result of the destination HEAD probe. */
    const DESTINATION_PROBE_TRANSIENT = 'isxs_dest_probe';

    /**
     * HEAD the destination bucket, with a short cache.
     *
     * Only successes are cached: a failure is usually something the user is
     * in the middle of fixing, and making them wait out a TTL to retry
     * would be its own bug.
     *
     * @return true|WP_Error
     */
    public static function destination_reachable() {
        if ( get_transient( self::DESTINATION_PROBE_TRANSIENT ) === 'yes' ) {
            return true;
        }

        $result = ( new ISXM_Client() )->test_connection();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        set_transient( self::DESTINATION_PROBE_TRANSIENT, 'yes', self::DESTINATION_PROBE_TTL );
        return true;
    }

    /**
     * The denominator a fresh run of this tool starts with, i.e. how many
     * items it will drain. Each tool's number matches exactly what its own
     * scan selects — mixing them up is what used to make a run finish at
     * 140% or sit at 3% forever:
     *
     *  - offload      : attachments NOT on the current destination. Uses
     *                   `in_bucket` (origin-agnostic, what get_pending_ids()
     *                   actually skips), not `offloaded` (origin-filtered
     *                   for the Overview ring, which undercounts migrated
     *                   files and would leave the counter short at 100%).
     *  - download/remove : every attachment physically on the destination.
     *  - retry_failed : attachments carrying a failure record.
     *  - wc_downloads : downloadable products/variations.
     *  - migrate      : 0 — counted for real against the source bucket by
     *                   the run's own counting phase (see execute_batch).
     *
     * @param string $tool
     * @return int Item count, or 0 when it is not known up front.
     */
    public static function tool_total( $tool ) {
        $stats = self::get_stats( true );

        switch ( $tool ) {
            case 'offload':
                return max( (int) $stats['total'] - (int) $stats['in_bucket'], 0 );
            case 'download':
            case 'remove':
                return (int) $stats['in_bucket'];
            case 'retry_failed':
                return (int) $stats['failed'];
            case 'wc_downloads':
                return isset( $stats['wc_total'] ) ? (int) $stats['wc_total'] : 0;
            case 'backfill':
                return ISXM_Items::count_legacy_records();
        }
        return 0;
    }

    /**
     * Run ONE time-budgeted batch of a tool and describe what happened.
     * This is the only entry point ISXM_Background uses, so every tool
     * behaves identically with respect to pausing, resuming and counting.
     *
     * @param string     $tool   Tool slug.
     * @param int|string $cursor Resume cursor from the job record.
     * @return array{processed:int,cursor:mixed,done:bool,errors:string[],total_delta?:int,total_complete?:bool,phase?:string}|WP_Error
     */
    public static function execute_batch( $tool, $cursor ) {
        $precheck = self::precheck_tool( $tool );
        if ( is_wp_error( $precheck ) ) {
            return $precheck;
        }

        $instance = self::instance();
        $job      = ISXM_Job::get( $tool );
        $instance->set_progress_job( $job );

        // Retry backoff inside the client must not outlive this batch — see
        // ISXM_Client::set_deadline(). The loops below each start their own
        // clock a moment from now, so this is a hair conservative, which is
        // the right direction: it only ever ends a wait sooner.
        ISXM_Client::set_deadline( microtime( true ) + self::time_budget() );

        try {
            switch ( $tool ) {
                case 'offload':
                    return $instance->batch_offload( $cursor );
                case 'retry_failed':
                    return $instance->batch_retry_failed( $cursor );
                case 'download':
                    return $instance->batch_download( $cursor );
                case 'remove':
                    return $instance->batch_remove( $cursor );
                case 'migrate':
                    return $instance->batch_migrate( $cursor );
                case 'wc_downloads':
                    return $instance->batch_wc_downloads( $cursor );
                case 'backfill':
                    return $instance->batch_backfill( $cursor );
            }
            return new WP_Error( 'isxs_unknown_tool', 'ไม่รู้จักเครื่องมือนี้' );
        } finally {
            $instance->set_progress_job( null );
            ISXM_Client::set_deadline( 0 );
        }
    }

    /**
     * Drop the memoized/transient stats so the next read recomputes. Called
     * when a run finishes, since it just changed the answer.
     */
    public static function flush_stats_cache() {
        self::$stats_cache = null;
        delete_transient( self::STATS_TRANSIENT );
        // The destination probe is about the same configuration these stats
        // describe; a settings change that invalidates one invalidates the
        // other, and a stale "reachable" would let a run start against a
        // bucket that is no longer there.
        delete_transient( self::DESTINATION_PROBE_TRANSIENT );
    }

    /* ---------------------------------------------------------------------
     * Bulk tools (batched)
     * ------------------------------------------------------------------ */

    /**
     * Attachment IDs that have NOT been offloaded to the *current* destination
     * (bucket+endpoint) yet — either no offload meta at all, or meta left
     * over from a previously configured destination (e.g. after switching
     * provider — same bucket name under a different endpoint counts as a
     * different destination).
     *
     * Scans in a bounded window (paged by last ID scanned, not last ID
     * matched) so a long run of already-current-destination attachments
     * can't get rescanned forever or make the batch loop stop early before
     * reaching the true end of the table.
     *
     * @return array{ids:int[], last_id:int, done:bool}
     */
    public static function get_pending_ids( $limit, $after_id = 0 ) {
        global $wpdb;

        // Ledger path: one indexed LEFT JOIN, every row read is a row
        // returned. The postmeta path below has to over-read a window and
        // filter in PHP, because "is this record for the current bucket?"
        // can only be answered by unserializing the value.
        if ( ISXM_Items::ready() ) {
            return ISXM_Items::pending_ids(
                $limit,
                $after_id,
                (string) ISXM_Settings::get( 'bucket' ),
                (string) ISXM_Settings::get( 'endpoint' )
            );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, m.meta_value FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.ID > %d
             ORDER BY p.ID ASC LIMIT %d",
            ISXM_Offload::META_KEY,
            $after_id,
            self::PENDING_SCAN_WINDOW
        ) );

        $current_bucket   = ISXM_Settings::get( 'bucket' );
        $current_endpoint = ISXM_Settings::get( 'endpoint' );
        $ids              = [];
        $last_id          = $after_id;

        foreach ( $rows as $row ) {
            $last_id = (int) $row->ID;
            $info    = $row->meta_value !== null ? maybe_unserialize( $row->meta_value ) : null;
            $on_current_bucket = is_array( $info ) && isset( $info['bucket'] ) && $info['bucket'] === $current_bucket
                && ( $info['endpoint'] ?? '' ) === $current_endpoint;

            if ( ! $on_current_bucket ) {
                $ids[] = (int) $row->ID;
                if ( count( $ids ) >= $limit ) {
                    break;
                }
            }
        }

        // A full batch means the scan stopped early at $limit — more rows may
        // remain even when this window read fewer than PENDING_SCAN_WINDOW rows.
        $hit_limit = count( $ids ) >= $limit;

        return [
            'ids'     => $ids,
            'last_id' => $last_id,
            'done'    => ! $hit_limit && count( $rows ) < self::PENDING_SCAN_WINDOW,
        ];
    }

    /**
     * Attachment IDs that HAVE been offloaded to the *current* destination
     * (bucket+endpoint), optionally paged by last ID scanned. Destination-
     * aware for the same reason get_pending_ids() is — an attachment
     * offloaded to a *previous* destination (e.g. after switching provider,
     * even under the same bucket name) must not be treated as "on this
     * destination" for Download/Remove, or those tools would act on a
     * destination that isn't actually configured anymore.
     *
     * Filtering happens in PHP after unserializing each row's meta (can't
     * filter a serialized array in SQL), so this scans in a bounded window
     * (PENDING_SCAN_WINDOW) paged by last ID *scanned*, same technique as
     * get_pending_ids().
     *
     * @return array{ids:int[], last_id:int, done:bool}
     */
    public static function get_offloaded_ids( $limit, $after_id = 0 ) {
        global $wpdb;

        // See get_pending_ids() — same reasoning, opposite condition.
        if ( ISXM_Items::ready() ) {
            return ISXM_Items::offloaded_ids(
                $limit,
                $after_id,
                (string) ISXM_Settings::get( 'bucket' ),
                (string) ISXM_Settings::get( 'endpoint' )
            );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, m.meta_value FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.ID > %d
             ORDER BY p.ID ASC LIMIT %d",
            ISXM_Offload::META_KEY,
            $after_id,
            self::PENDING_SCAN_WINDOW
        ) );

        $current_bucket   = ISXM_Settings::get( 'bucket' );
        $current_endpoint = ISXM_Settings::get( 'endpoint' );
        $ids              = [];
        $last_id          = $after_id;

        foreach ( $rows as $row ) {
            $last_id = (int) $row->ID;
            $info    = maybe_unserialize( $row->meta_value );
            $on_current_bucket = is_array( $info ) && isset( $info['bucket'] ) && $info['bucket'] === $current_bucket
                && ( $info['endpoint'] ?? '' ) === $current_endpoint;

            if ( $on_current_bucket ) {
                $ids[] = (int) $row->ID;
                if ( count( $ids ) >= $limit ) {
                    break;
                }
            }
        }

        // A full batch means the scan stopped early at $limit — more rows may
        // remain even when this window read fewer than PENDING_SCAN_WINDOW rows.
        $hit_limit = count( $ids ) >= $limit;

        return [
            'ids'     => $ids,
            'last_id' => $last_id,
            'done'    => ! $hit_limit && count( $rows ) < self::PENDING_SCAN_WINDOW,
        ];
    }

    /**
     * Attachment IDs whose last offload attempt failed (`_isxs_offload_error`
     * present). Unlike get_pending_ids()/get_offloaded_ids() this needs no
     * PHP-side filtering — the meta_key's presence IS the condition — so the
     * scan window and the limit are the same thing here.
     *
     * @return array{ids:int[], last_id:int, done:bool}
     */
    public static function get_failed_ids( $limit, $after_id = 0 ) {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.ID > %d
             ORDER BY p.ID ASC LIMIT %d",
            ISXM_Offload::ERROR_META_KEY,
            $after_id,
            $limit
        ) );
        $ids = array_map( 'intval', $ids );

        return [
            'ids'     => $ids,
            'last_id' => empty( $ids ) ? $after_id : end( $ids ),
            'done'    => count( $ids ) < $limit,
        ];
    }

    /**
     * Load the post rows and postmeta of a whole batch in two queries.
     *
     * Each item then costs ~8–12 meta reads (get_attached_file,
     * wp_get_attachment_metadata, the `_isxs_offload` lookups,
     * collect_url_pairs...) — without priming, that is a query apiece, so a
     * 100-item batch fired ~1000 individual round trips while competing with
     * the network I/O the batch actually exists to do.
     *
     * @param int[] $ids Attachment IDs.
     */
    public static function prime_caches( array $ids ) {
        if ( empty( $ids ) ) {
            return;
        }
        _prime_post_caches( $ids, false, true );
        // The tracking records live in their own table now, which
        // _prime_post_caches() knows nothing about — without this every
        // item in the batch costs one extra SELECT.
        if ( ISXM_Items::ready() ) {
            ISXM_Items::prime( $ids );
        }
    }

    /**
     * Seconds of processing allowed in this request — TIME_BUDGET capped
     * under max_execution_time (with headroom for the post-loop bulk URL
     * rewrite and the response itself).
     */
    private static function time_budget() {
        $budget = self::TIME_BUDGET;
        $max    = (int) ini_get( 'max_execution_time' );
        if ( $max > 0 ) {
            $budget = min( $budget, max( 5, $max - 10 ) );
        }
        return $budget;
    }

    /* ---------------------------------------------------------------------
     * Live progress within one batch
     * ------------------------------------------------------------------ */

    /**
     * A batch runs for up to TIME_BUDGET seconds, and its item count is only
     * added to the job when it returns. Without an intra-batch signal the
     * progress bar would freeze for 15 seconds at a time and look stuck, so
     * the loops report here as they go and the job record carries the
     * partial count until the batch lands.
     *
     * @param int $processed_in_batch Items finished so far in this batch.
     */
    private function progress_tick( $processed_in_batch ) {
        if ( $this->progress_job === null ) {
            return;
        }
        // Throttled: at ~4 writes/sec this costs one small option update,
        // regardless of how fast the items themselves go by.
        if ( microtime( true ) - $this->progress_last_write < 0.25 ) {
            return;
        }
        $this->progress_last_write = microtime( true );
        $this->progress_job->set_batch_progress( (int) $processed_in_batch );
    }

    /**
     * Bind the job whose record intra-batch progress is written to. Cleared
     * by execute_batch() when the batch returns.
     *
     * @param ISXM_Job|null $job
     */
    private function set_progress_job( $job ) {
        $this->progress_job        = $job;
        $this->progress_last_write = 0;
    }

    /**
     * Process pending (not-yet-offloaded) attachments until the time budget
     * runs out or the scan reaches the end of the table.
     *
     * @param int           $after_id Resume after this attachment ID.
     * @param callable      $process  fn( int $id ): WP_Error|array — returns the
     *                                item's URL pairs on success (guid already
     *                                updated by the callback).
     * @param callable|null $fetch_ids fn( int $limit, int $after_id ): array{ids,last_id,done}
     *                                — which items to drain. Defaults to the
     *                                not-yet-offloaded set; the retry tool
     *                                passes get_failed_ids() instead so both
     *                                share this loop rather than forking it.
     * @param bool          $defer_rewrite When true (offload/retry runs), the
     *                                bulk DB URL rewrite of this batch is
     *                                SKIPPED — it happens once for the whole
     *                                run in the rewrite| phase instead — and
     *                                local deletions are held back unless
     *                                delivery is on (see the end of this
     *                                method). Per-batch table scans are what
     *                                made bulk offload take hours on large
     *                                libraries, so the defer is the whole
     *                                point of the two-phase run.
     * @return array{processed:int,last_id:int,done:bool,errors:string[]}
     */
    private function run_pending_batch( $after_id, callable $process, ?callable $fetch_ids = null, $defer_rewrite = false ) {
        if ( $fetch_ids === null ) {
            $fetch_ids = [ __CLASS__, 'get_pending_ids' ];
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }
        $started = microtime( true );
        $budget  = self::time_budget();

        $processed = 0;
        $last_id   = $after_id;
        $errors    = [];
        $url_pairs = [];
        $done      = false;

        do {
            $pending       = call_user_func( $fetch_ids, self::MAX_BATCH, $last_id );
            $stopped_early = false;

            self::prime_caches( $pending['ids'] );

            foreach ( $pending['ids'] as $id ) {
                $result = $process( $id );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '#%d %s: %s', $id, wp_basename( (string) get_attached_file( $id ) ), $result->get_error_message() );
                } elseif ( is_array( $result ) && ! $defer_rewrite ) {
                    // With the rewrite deferred the pairs are only needed
                    // per item (guid update) — no point accumulating a
                    // batch-sized map that will be thrown away.
                    $url_pairs = array_merge( $url_pairs, $result );
                }
                $processed++;
                // Resume from the last item actually processed, not the end
                // of the scan window, in case we stop mid-window on time.
                $last_id = $id;
                $this->progress_tick( $processed );

                if ( microtime( true ) - $started >= $budget ) {
                    $stopped_early = true;
                    break;
                }
            }

            if ( $stopped_early ) {
                break;
            }

            $last_id = $pending['last_id'];
            $done    = $pending['done'];
        } while ( ! $done && microtime( true ) - $started < $budget );

        if ( ! empty( $url_pairs ) && ! $defer_rewrite ) {
            ISXM_DB_Rewriter::replace_urls_bulk( $url_pairs );
        }
        // "Remove Local Media": with the permanent DB rewrite deferred to
        // the end of the run, local copies may only go now when delivery is
        // on — the runtime filters still rewrite URLs at render time, so the
        // DB's stale local URLs stay harmless. Otherwise (persist_urls on,
        // delivery off) the queued paths are spilled to the run's defer file
        // and deleted only after the rewrite| phase has actually landed.
        // Deleting first would leave DB URLs pointing at files that no
        // longer exist if the run died before the rewrite ran.
        if ( ISXM_Settings::get( 'deliver_enabled' ) ) {
            ISXM_Offload::flush_local_deletions();
        } elseif ( $defer_rewrite && ISXM_Offload::should_persist_urls() ) {
            self::spill_local_deletions( $this->offload_defer_file() );
        }

        return [
            'processed' => $processed,
            'last_id'   => $last_id,
            'done'      => $done,
            'errors'    => $errors,
        ];
    }

    /**
     * The three tables the one-pass rewrite walks, in order. Columns are
     * this class's own hard-coded names — the probe pattern itself is the
     * only user-derived input, and it is esc_like()'d inside
     * ISXM_DB_Rewriter::rewrite_window().
     *
     * @return array<string,array{table:string,id_col:string,value_col:string,ref_col:string,kind:string}>
     */
    private static function rewrite_tables() {
        global $wpdb;
        return [
            'posts'    => [ 'table' => $wpdb->posts,    'id_col' => 'ID',        'value_col' => 'post_content', 'ref_col' => 'ID',         'kind' => 'posts' ],
            'postmeta' => [ 'table' => $wpdb->postmeta, 'id_col' => 'meta_id',   'value_col' => 'meta_value',   'ref_col' => 'post_id',    'kind' => 'postmeta' ],
            'options'  => [ 'table' => $wpdb->options,  'id_col' => 'option_id', 'value_col' => 'option_value', 'ref_col' => 'option_name', 'kind' => 'options' ],
        ];
    }

    /**
     * One time-budgeted slice of the run's final one-pass DB URL rewrite
     * (the rewrite| phase). Walks posts → postmeta → options, one broad
     * probe per table, paging by row id within the budget. The cursor is
     * carried in $rest as "{table}:{after_id}".
     *
     * Row changes here are NOT added to the job's item counter — the
     * denominator counts attachments, which were all processed in the
     * upload phase — so the bar sits at ~99% while the phase label says
     * 'rewriting'. When every table is done, the deferred local deletions
     * (spilled during upload) are finally flushed: the rewrite has landed,
     * so nothing the database references is being removed.
     *
     * @param string $rest "{table}:{after_id}" portion of the job cursor.
     * @return array{processed:int,last_id:string,done:bool,errors:string[],phase:string}
     */
    private function run_rewrite_batch( $rest ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }
        $started = microtime( true );
        $budget  = self::time_budget();

        $parts  = explode( ':', $rest, 2 );
        $tables = self::rewrite_tables();
        $keys   = array_keys( $tables );
        $idx    = isset( $tables[ $parts[0] ] ) ? array_search( $parts[0], $keys, true ) : 0;
        $after  = isset( $parts[1] ) ? (int) $parts[1] : 0;
        $done   = false;

        while ( microtime( true ) - $started < $budget ) {
            $cfg = $tables[ $keys[ $idx ] ];

            $window = ISXM_DB_Rewriter::rewrite_window(
                $cfg['table'],
                $cfg['id_col'],
                $cfg['value_col'],
                [ ISXM_Offload::class, 'local_url_map' ],
                $after,
                self::REWRITE_WINDOW,
                $cfg['ref_col'],
                $cfg['kind']
            );

            if ( ! $window['done'] ) {
                // More rows left in this table — resume from where we stopped.
                $after = $window['last_id'];
                break;
            }

            // This table is fully rewritten — move to the next one.
            $idx++;
            if ( $idx >= count( $keys ) ) {
                $done = true;
                break;
            }
            $after = 0;
        }

        if ( $done ) {
            // The rewrite has landed — only now may the local copies that
            // were held back during upload (persist_urls on, delivery off)
            // go. This is the whole "rewrite first, then delete" contract
            // flush_local_deletions() used to rely on per batch.
            self::flush_deferred_deletions( $this->offload_defer_file() );
            return [
                'processed' => 0,
                'last_id'   => '',
                'done'      => true,
                'errors'    => [],
                'phase'     => '',
            ];
        }

        return [
            'processed' => 0,
            'last_id'   => $keys[ $idx ] . ':' . $after,
            'done'      => false,
            'errors'    => [],
            'phase'     => 'rewriting',
        ];
    }

    /**
     * Run the one-pass DB URL rewrite to completion in this request (no
     * time budget) — the CLI's equivalent of the background job's rewrite|
     * phase, used by `wp isxm offload` after every chunk has uploaded.
     *
     * @return void
     */
    public static function rewrite_all_urls_once() {
        foreach ( self::rewrite_tables() as $cfg ) {
            $after = 0;
            do {
                $window = ISXM_DB_Rewriter::rewrite_window(
                    $cfg['table'],
                    $cfg['id_col'],
                    $cfg['value_col'],
                    [ ISXM_Offload::class, 'local_url_map' ],
                    $after,
                    self::REWRITE_WINDOW,
                    $cfg['ref_col'],
                    $cfg['kind']
                );
                $after = $window['last_id'];
            } while ( ! $window['done'] );
        }
    }

    /* ---------------------------------------------------------------------
     * Deferred local-file deletion across requests (offload's rewrite| phase)
     * ------------------------------------------------------------------ */

    /**
     * Path of one run's deferred-deletion file, where paths queued during
     * the upload phase wait for the rewrite phase to land. Lives under
     * uploads/isxs-defer/, mirroring ISXM_Sync's temp-file pattern — a
     * six-figure library's worth of paths is far too big for an option.
     *
     * @param string $run_id Job run id.
     * @return string Absolute path.
     */
    public static function defer_file_for_run( $run_id ) {
        $uploads = wp_get_upload_dir();
        return trailingslashit( $uploads['basedir'] ) . 'isxs-defer/defer-' . $run_id . '.paths';
    }

    /**
     * The defer file of the job this request is executing (or a throwaway
     * id when none — only the CLI path reaches that case, and it passes its
     * own run id instead).
     *
     * @return string Absolute path.
     */
    private function offload_defer_file() {
        $run_id = $this->progress_job ? $this->progress_job->run_id : (string) time();
        return self::defer_file_for_run( $run_id );
    }

    /**
     * Append this request's queued local deletions to the run's defer file,
     * so they survive to the rewrite phase — the per-request queue would
     * otherwise die with this request and the files would stay on disk
     * forever despite "Remove Local Media".
     *
     * @param string $file Defer file path.
     * @return void
     */
    public static function spill_local_deletions( $file ) {
        $paths = ISXM_Offload::pending_deletions();
        ISXM_Offload::clear_pending_deletions();
        if ( empty( $paths ) ) {
            return;
        }
        $dir = dirname( $file );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        // Paths are absolute uploads-relative paths of sanitized basenames
        // (safe_filename() rejects control characters), so newline-delimited
        // lines round-trip exactly.
        @file_put_contents( $file, implode( "\n", $paths ) . "\n", FILE_APPEND );
    }

    /**
     * Delete every path the upload phase spilled to $file, then drop the
     * file. Called only after the one-pass DB rewrite has completed, so
     * nothing the database still references is removed.
     *
     * @param string $file Defer file path.
     * @return int Files deleted.
     */
    public static function flush_deferred_deletions( $file ) {
        if ( ! is_file( $file ) ) {
            return 0;
        }
        $deleted = 0;
        $fh      = @fopen( $file, 'r' );
        if ( $fh !== false ) {
            $paths = [];
            while ( ( $line = fgets( $fh ) ) !== false ) {
                $line = trim( $line );
                if ( $line === '' ) {
                    continue;
                }
                $paths[] = $line;
                // Delete in bounded chunks: a six-figure library's worth of
                // paths (~400k lines) must never load into memory at once.
                if ( count( $paths ) >= 1000 ) {
                    $deleted += ISXM_Offload::delete_local_files( $paths );
                    $paths    = [];
                }
            }
            if ( ! empty( $paths ) ) {
                $deleted += ISXM_Offload::delete_local_files( $paths );
            }
            fclose( $fh );
        }
        @unlink( $file );
        return $deleted;
    }

    /**
     * Remove defer files. A finished run already deleted its own; this
     * catches the leftovers of runs that never reached the rewrite phase
     * (cancelled, destination switched, or killed mid-upload). Deleting the
     * file only abandons the pending deletion — the files themselves stay
     * on disk, which is the safe direction to err.
     *
     * @param string|null $run_id Only this run's file, or all when null.
     * @return void
     */
    public static function cleanup_defer_files( $run_id = null ) {
        $uploads = wp_get_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . 'isxs-defer';
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( (array) glob( $dir . '/defer-*.paths' ) as $file ) {
            if ( $run_id === null || strpos( wp_basename( $file ), 'defer-' . $run_id . '.' ) === 0 ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Process already-offloaded attachments (download/remove) until the
     * time budget runs out or none remain. A callback may return URL pairs
     * (remove's reverse rewrite) — they're aggregated and flushed as ONE
     * bulk DB rewrite after the loop, same as run_pending_batch().
     *
     * @param int      $after_id Resume after this attachment ID.
     * @param callable $process  fn( int $id ): true|array|WP_Error
     * @return array{processed:int,last_id:int,done:bool,errors:string[]}
     */
    private function run_offloaded_batch( $after_id, callable $process ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }
        $started = microtime( true );
        $budget  = self::time_budget();

        $processed = 0;
        $last_id   = $after_id;
        $errors    = [];
        $url_pairs = [];
        $done      = false;

        do {
            $offloaded     = self::get_offloaded_ids( self::MAX_BATCH, $last_id );
            $stopped_early = false;

            self::prime_caches( $offloaded['ids'] );

            foreach ( $offloaded['ids'] as $id ) {
                $result = $process( $id );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '#%d: %s', $id, $result->get_error_message() );
                } elseif ( is_array( $result ) ) {
                    $url_pairs = array_merge( $url_pairs, $result );
                }
                $processed++;
                // Resume from the last item actually processed, not the end
                // of the scan window, in case we stop mid-window on time.
                $last_id = $id;
                $this->progress_tick( $processed );

                if ( microtime( true ) - $started >= $budget ) {
                    $stopped_early = true;
                    break;
                }
            }

            if ( $stopped_early ) {
                break;
            }

            $last_id = $offloaded['last_id'];
            $done    = $offloaded['done'];
        } while ( ! $done && microtime( true ) - $started < $budget );

        if ( ! empty( $url_pairs ) ) {
            ISXM_DB_Rewriter::replace_urls_bulk( $url_pairs );
        }

        return [
            'processed' => $processed,
            'last_id'   => $last_id,
            'done'      => $done,
            'errors'    => $errors,
        ];
    }

    /**
     * Drive Migrate off the source bucket's real object listing (ground
     * truth, via ISXM_Migrate::scan_source_batch()) instead of guessing
     * from WordPress' own tables. The cursor round-tripped through the
     * `last_id` response key is "{s3_continuation_token}|{items_done}":
     * the opaque S3 token for the page being scanned, plus how many of
     * that page's resolved attachments have already been fully processed.
     * Without the intra-page offset, stopping on the time budget mid-page
     * used to advance the token to the NEXT page anyway — silently
     * skipping every unprocessed item in the tail of the interrupted page.
     *
     * Progress is counted in OBJECT units (source keys), matching the
     * source-object-count denominator the UI shows — counting attachments
     * against an object total is what made a fully-completed run look like
     * it was cut off at ~14%.
     *
     * @param string $after_token Resume cursor from a previous call ('' to start).
     * @param bool   $verify      Whether to confirm against the destination
     *                            bucket before trusting a skip record (one
     *                            prefixed listing per already-migrated item —
     *                            leave ON for correctness; turn OFF for fast
     *                            re-runs after the Sync tool has reconciled).
     * @return array{processed:int,last_id:string,done:bool,stalled:bool,errors:string[]}
     */
    private function run_migrate_batch( $after_token, $verify = true ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }
        $started = microtime( true );
        $budget  = self::time_budget();

        // Split the cursor into the S3 continuation token + how many items
        // of that page are already done. '|' can't appear in base64-style
        // continuation tokens, so the split is unambiguous.
        $parts      = explode( '|', $after_token, 2 );
        $s3_token   = $parts[0];
        $skip_items = isset( $parts[1] ) ? max( 0, (int) $parts[1] ) : 0;

        $processed      = 0;
        $errors         = [];
        $url_pairs      = [];
        $done           = false;
        $stalled        = false;
        $cursor         = $after_token;
        $current_bucket = ISXM_Settings::get( 'bucket' );

        do {
            $batch = ISXM_Migrate::scan_source_batch( $s3_token );
            if ( is_wp_error( $batch ) ) {
                // The page listing itself failed (network/timeout/credentials) —
                // this is not "no more pages", so don't mark the run done.
                // Leave the cursor untouched so a resumed run retries this page.
                $errors[] = $batch->get_error_message();
                $stalled  = true;
                break;
            }

            $items         = $batch['items'];
            $total_items   = count( $items );
            self::prime_caches( array_keys( $items ) );
            $stopped_early = false;
            $idx           = 0;
            $items_done    = $skip_items;

            foreach ( $items as $id => $key_map ) {
                $idx++;
                if ( $idx <= $skip_items ) {
                    // Finished (and counted) by a previous request that was
                    // interrupted mid-page — don't redo or double-count it.
                    continue;
                }

                $n = count( $key_map );

                // Already offloaded to the current destination bucket — skip
                // the expensive re-download/re-upload. This is what makes
                // re-running Migrate (and re-scanning an interrupted page)
                // fast instead of redoing completed work.
                $info = ISXM_Offload::get_record( $id );
                if ( is_array( $info ) && isset( $info['bucket'] ) && $info['bucket'] === $current_bucket ) {
                    // The meta record can go stale when objects are deleted
                    // out-of-band (bucket console/CLI) — it would otherwise
                    // claim the file is on the destination forever while the
                    // bucket no longer has it, and Migrate would count the
                    // item as done without ever re-uploading it. Verify the
                    // PRIMARY file actually exists there (one cheap prefixed
                    // listing) before trusting the record; a record that
                    // can't be verified falls through to migrate_attachment()
                    // below and re-uploads every file of the attachment.
                    if ( $verify && ! ISXM_Sync::primary_exists_on_destination( $info ) ) {
                        $info = null;
                    }
                }
                if ( is_array( $info ) ) {
                    // Already offloaded — skip the expensive re-download/re-upload,
                    // but still re-check the URL rewrite every time: a
                    // source_public_base_url that was wrong when this file was
                    // first migrated (or a batch that died before its flush)
                    // means simply re-running Migrate should be able to catch
                    // up stale URLs on its own, without a separate repair tool.
                    // Cheap — no network I/O, just string matching against
                    // already-known offload meta. A no-op for anything whose
                    // URL is already correct (old pattern won't be found).
                    $pairs = ISXM_Offload::collect_url_pairs( $id, ISXM_Migrate::extra_old_dirs( $id ) );
                    if ( ! empty( $pairs ) ) {
                        $url_pairs = array_merge( $url_pairs, $pairs );
                        ISXM_DB_Rewriter::update_attachment_guid( $id, $pairs );
                    }
                    $processed  += $n;
                    $items_done  = $idx;
                    $this->progress_tick( $processed );
                    continue;
                }

                $result = ISXM_Migrate::migrate_attachment( $id, $key_map, true );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '#%d %s: %s', $id, wp_basename( (string) get_attached_file( $id ) ), $result->get_error_message() );
                } else {
                    $pairs     = ISXM_Offload::collect_url_pairs( $id, ISXM_Migrate::extra_old_dirs( $id ) );
                    $url_pairs = array_merge( $url_pairs, $pairs );
                    ISXM_DB_Rewriter::update_attachment_guid( $id, $pairs );
                }

                $processed  += $n;
                $items_done  = $idx;
                $this->progress_tick( $processed );

                if ( microtime( true ) - $started >= $budget ) {
                    $stopped_early = true;
                    break;
                }
            }

            $page_complete = ! $stopped_early || $items_done >= $total_items;

            if ( $page_complete ) {
                // Keys that resolved to no attachment (outside the prefix,
                // no matching _wp_attached_file) are still part of the
                // source-object-count denominator — count them exactly once,
                // when the page finishes.
                $scanned         = isset( $batch['scanned_keys'] ) ? (int) $batch['scanned_keys'] : 0;
                $page_item_keys  = 0;
                foreach ( $items as $key_map ) {
                    $page_item_keys += count( $key_map );
                }
                $processed += max( $scanned - $page_item_keys, 0 );
                $this->progress_tick( $processed );

                $s3_token   = $batch['next_token'];
                $skip_items = 0;
                $cursor     = $s3_token;
                $done       = $batch['done'];
            } else {
                // Interrupted mid-page: stay on this page, remember how far
                // we got, and let the next request pick up from there.
                $cursor = $s3_token . '|' . $items_done;
            }

            if ( $stopped_early ) {
                break;
            }
        } while ( ! $done && microtime( true ) - $started < $budget );

        if ( ! empty( $url_pairs ) ) {
            ISXM_DB_Rewriter::replace_urls_bulk( $url_pairs );
        }
        // Same contract as run_pending_batch(): local copies go only after the
        // rewrite has landed.
        ISXM_Offload::flush_local_deletions();

        return [
            'processed' => $processed,
            'last_id'   => $cursor,
            'done'      => $done,
            'stalled'   => $stalled,
            'errors'    => $errors,
        ];
    }

    /**
     * Process downloadable products/variations (WooCommerce) until the time
     * budget runs out or none remain. Same shape as run_offloaded_batch(),
     * but the ID source is products, not attachments.
     *
     * @param int $after_id Resume after this product/variation ID.
     * @return array{processed:int,last_id:int,done:bool,errors:string[]}
     */
    private function run_wc_downloads_batch( $after_id ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }
        $started = microtime( true );
        $budget  = self::time_budget();

        $processed = 0;
        $last_id   = $after_id;
        $errors    = [];
        $done      = false;

        do {
            $pending = ISXM_WC_Downloads::get_pending_ids( self::MAX_BATCH, $last_id );
            if ( empty( $pending['ids'] ) ) {
                $done = $pending['done'];
                if ( $done ) {
                    break;
                }
                $last_id = $pending['last_id'];
                continue;
            }

            $stopped_early = false;
            foreach ( $pending['ids'] as $id ) {
                $result = ISXM_WC_Downloads::process_product( $id );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '#%d: %s', $id, $result->get_error_message() );
                }
                $processed++;
                $last_id = $id;
                $this->progress_tick( $processed );

                if ( microtime( true ) - $started >= $budget ) {
                    $stopped_early = true;
                    break;
                }
            }

            if ( $stopped_early ) {
                break;
            }

            $last_id = $pending['last_id'];
            $done    = $pending['done'];
        } while ( ! $done && microtime( true ) - $started < $budget );

        return [
            'processed' => $processed,
            'last_id'   => $last_id,
            'done'      => $done,
            'errors'    => $errors,
        ];
    }

    /**
     * Run the offload/retry job through its two phases:
     *
     *  - upload|<id> — upload files ONLY. The per-batch DB URL rewrite is
     *    deferred (run_pending_batch(..., $defer_rewrite = true)), so a
     *    100-item batch costs uploads plus a bounded pending scan instead of
     *    the full-table LIKE passes of the old per-batch rewrite. Deletions
     *    are flushed per batch only when delivery is on; otherwise they
     *    spill to the run's defer file.
     *  - rewrite|<table>:<id> — one broad-probe pass over posts/postmeta/
     *    options rewriting every local upload URL to its remote URL, then
     *    the deferred deletions finally land.
     *
     * persist_urls off skips the rewrite phase entirely (the runtime
     * filters handle delivery on their own).
     *
     * @param int|string   $cursor Job cursor.
     * @param callable     $process
     * @param callable|null $fetch_ids
     * @return array
     */
    private function batch_pending_phased( $cursor, callable $process, ?callable $fetch_ids = null ) {
        list( $phase, $rest ) = self::split_cursor( $cursor, 'upload' );

        if ( $phase === 'rewrite' ) {
            $result = $this->run_rewrite_batch( $rest );
            return self::batch_result( $result, [
                'cursor' => 'rewrite|' . $result['last_id'],
                'phase'  => $result['phase'],
            ] );
        }

        $result = $this->run_pending_batch( (int) $rest, $process, $fetch_ids, true );

        if ( ! empty( $result['done'] ) && ISXM_Offload::should_persist_urls() ) {
            // Uploads drained — the run is NOT done yet, it moves into the
            // final one-pass rewrite (done must read false here or the
            // runner would finish the job with local URLs still in the DB).
            return self::batch_result( $result, [
                'cursor' => 'rewrite|posts:0',
                'done'   => false,
                'phase'  => 'rewriting',
            ] );
        }

        return self::batch_result( $result, [
            'cursor' => 'upload|' . $result['last_id'],
        ] );
    }

    /**
     * One time-budgeted batch of "offload remaining media".
     *
     * Deliberately just the pending drain. An earlier version bolted a
     * second pass onto the end of every run that re-listed the bucket once
     * per already-offloaded attachment to catch objects deleted
     * out-of-band. It was wrong twice over: its item count was added to the
     * same counter while the denominator only covered the pending set (so
     * the run reported far past 100%), and it overwrote the pending scan's
     * cursor with its own — two different scans sharing one resume pointer,
     * which made "ทำต่อ" skip real work. Reconciling meta against the real
     * bucket is what the Sync tool does, with ONE bucket listing for the
     * whole library instead of one per attachment.
     *
     * @param int|string $cursor Attachment ID to resume after (upload|<id>
     *                           or rewrite|<table>:<id>).
     * @return array
     */
    private function batch_offload( $cursor ) {
        return $this->batch_pending_phased( $cursor, $this->offload_one_callback() );
    }

    /**
     * One time-budgeted batch of "retry the attachments whose last offload
     * attempt failed". Same work as the Offload tool, different source set:
     * a failed item has no offload meta, so it's already in the pending set —
     * this just goes straight at it instead of rescanning the whole library
     * to find the handful that need another try. Shares the same two-phase
     * run (upload| → rewrite|) as the Offload tool.
     *
     * @param int|string $cursor Attachment ID to resume after.
     * @return array
     */
    private function batch_retry_failed( $cursor ) {
        return $this->batch_pending_phased( $cursor, $this->offload_one_callback(), [ __CLASS__, 'get_failed_ids' ] );
    }

    /**
     * Normalize what the run_*_batch() loops return into the shape
     * ISXM_Background expects.
     *
     * @param array $result  Loop result (processed/last_id/done/errors).
     * @param array $extra   Extra keys to merge (total_delta, phase...).
     * @return array
     */
    private static function batch_result( array $result, array $extra = [] ) {
        return array_merge( [
            'processed' => isset( $result['processed'] ) ? (int) $result['processed'] : 0,
            'cursor'    => isset( $result['last_id'] ) ? $result['last_id'] : 0,
            'done'      => ! empty( $result['done'] ),
            'errors'    => isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : [],
        ], $extra );
    }

    /**
     * Offload one attachment and hand back its URL pairs — the unit of work
     * shared by the Offload tool, the retry tool and the Media Library's
     * single-item action, so all three behave identically.
     *
     * @return callable fn( int $id ): array|WP_Error
     */
    private function offload_one_callback() {
        $offload = new ISXM_Offload();

        return function ( $id ) use ( $offload ) {
            $res = $offload->offload_attachment( $id, null, true );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $pairs = ISXM_Offload::collect_url_pairs( $id );
            ISXM_DB_Rewriter::update_attachment_guid( $id, $pairs );
            return $pairs;
        };
    }

    /**
     * Offload a single attachment — the Media Library row action. Runs the
     * DB URL rewrite immediately (no batch to defer it to).
     */
    public function ajax_offload_single() {
        $this->guard();

        if ( ! ISXM_Settings::is_configured() ) {
            wp_send_json_error( [ 'message' => 'ยังตั้งค่า storage ไม่ครบ — บันทึกการตั้งค่าก่อน' ] );
        }

        $id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
        if ( ! $id || get_post_type( $id ) !== 'attachment' ) {
            wp_send_json_error( [ 'message' => 'ไม่พบไฟล์สื่อนี้' ] );
        }

        $pairs = call_user_func( $this->offload_one_callback(), $id );
        if ( is_wp_error( $pairs ) ) {
            wp_send_json_error( [ 'message' => $pairs->get_error_message() ] );
        }
        if ( ! empty( $pairs ) ) {
            ISXM_DB_Rewriter::replace_urls_bulk( $pairs );
        }
        // offload_one_callback() defers persist, so it also defers the local
        // delete — release it now that the rewrite has run.
        ISXM_Offload::flush_local_deletions();

        $status = ISXM_Offload::status_for( $id );
        wp_send_json_success( [
            'status'  => $status['status'],
            'message' => $status['status'] === 'partial'
                ? 'ขึ้น cloud แล้ว แต่ไม่ครบทุกขนาด'
                : 'ขึ้น cloud แล้ว',
        ] );
    }

    /**
     * One time-budgeted batch of "download all files from bucket to server".
     *
     * @param int|string $cursor Attachment ID to resume after.
     * @return array
     */
    private function batch_download( $cursor ) {
        $offload = new ISXM_Offload();

        $result = $this->run_offloaded_batch( (int) $cursor, function ( $id ) use ( $offload ) {
            return $offload->download_attachment( $id );
        } );

        return self::batch_result( $result );
    }

    /**
     * One time-budgeted batch of "remove all files from bucket".
     *
     * @param int|string $cursor Attachment ID to resume after.
     * @return array
     */
    private function batch_remove( $cursor ) {
        $offload = new ISXM_Offload();

        $result = $this->run_offloaded_batch( (int) $cursor, function ( $id ) use ( $offload ) {
            // defer_persist: reverse URL pairs are returned and flushed as
            // one bulk DB rewrite per batch (guid already updated inside).
            return $offload->remove_remote_attachment( $id, true );
        } );

        return self::batch_result( $result );
    }

    /**
     * One time-budgeted batch of "migrate remaining media from the source
     * bucket", in two phases carried in the cursor:
     *
     *  count|<token>  — page through the source listing counting objects,
     *                   with no transfers, to establish a REAL denominator.
     *                   The old UI did this from JS in parallel with the
     *                   run, so a stop lost the count and the bar jumped
     *                   around; here it is part of the job and survives
     *                   pause/resume like everything else.
     *  run|<token>    — the actual pull-and-push, counted in OBJECT units
     *                   to match that denominator.
     *
     * @param int|string $cursor Job cursor.
     * @return array
     */
    private function batch_migrate( $cursor ) {
        list( $phase, $token ) = self::split_cursor( $cursor, 'count' );

        if ( $phase === 'count' ) {
            $counted = ISXM_Migrate::count_source_objects( [], $token );
            if ( is_wp_error( $counted ) ) {
                return $counted;
            }

            $complete = ! empty( $counted['complete'] );

            return [
                // Counting is not migrating: it must not move the item
                // counter, only the denominator it will be measured against.
                'processed'      => 0,
                'cursor'         => $complete ? 'run|' : 'count|' . $counted['next_token'],
                'done'           => false,
                'errors'         => [],
                'total_delta'    => (int) $counted['total'],
                'total_complete' => $complete,
                'phase'          => $complete ? '' : 'counting',
            ];
        }

        $result = $this->run_migrate_batch( $token, true );

        if ( ! empty( $result['stalled'] ) ) {
            // The source listing failed (network/credentials/timeout) —
            // report it as a run-level error so the job stops as RESUMABLE
            // on the same page instead of claiming it finished.
            $message = ! empty( $result['errors'] )
                ? $result['errors'][0]
                : 'อ่านรายการไฟล์จาก source bucket ไม่สำเร็จ';
            return new WP_Error( 'isxs_source_unreachable', $message );
        }

        return self::batch_result( $result, [
            'cursor' => 'run|' . $result['last_id'],
        ] );
    }

    /**
     * One time-budgeted batch of "verify and update WooCommerce downloadable
     * products" — marks offloaded download files private in the bucket and
     * fixes any stale stored URL.
     *
     * @param int|string $cursor Product/variation ID to resume after.
     * @return array
     */
    private function batch_wc_downloads( $cursor ) {
        $result = $this->run_wc_downloads_batch( (int) $cursor );
        return self::batch_result( $result );
    }

    /**
     * One window of the one-time ledger backfill: copy legacy
     * `_isxs_offload` postmeta into the ISXM_Items table.
     *
     * Pure database work with no network I/O, so the window can be far
     * larger than a transfer batch. Idempotent — put() upserts on
     * (source_type, source_id) — so an interrupted run just resumes.
     *
     * @param int|string $cursor Post ID to resume after.
     * @return array
     */
    private function batch_backfill( $cursor ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::MAX_REQUEST_SECONDS );
        }

        $started   = microtime( true );
        $budget    = self::time_budget();
        $after_id  = (int) $cursor;
        $processed = 0;
        $done      = false;

        do {
            $window     = ISXM_Items::backfill_window( self::BACKFILL_WINDOW, $after_id );
            $processed += $window['copied'] + $window['skipped'];
            $after_id   = $window['last_id'];
            $done       = $window['done'];
            $this->progress_tick( $processed );
            // The per-request record cache would otherwise grow by one entry
            // per row copied, for rows nothing is going to read back.
            ISXM_Items::flush_cache();
        } while ( ! $done && microtime( true ) - $started < $budget );

        if ( $done ) {
            // Only now may every count and scan switch to the table.
            ISXM_Items::mark_backfilled();
            self::flush_stats_cache();
            isxm_log_error( 'Items ledger backfill complete — counts and bulk scans now use the indexed table.' );
        }

        return [
            'processed' => $processed,
            'cursor'    => $after_id,
            'done'      => $done,
            'errors'    => [],
        ];
    }

    /**
     * Split a "phase|rest" cursor. A cursor with no phase marker (a fresh
     * run's 0, or a record written before phases existed) starts at
     * $default_phase with an empty remainder.
     *
     * The separator is '|' because S3 continuation tokens are base64-ish
     * and can never contain it.
     *
     * @param int|string $cursor
     * @param string     $default_phase
     * @return array{0:string,1:string} [ phase, remainder ]
     */
    private static function split_cursor( $cursor, $default_phase ) {
        $cursor = is_string( $cursor ) ? $cursor : (string) $cursor;
        if ( strpos( $cursor, '|' ) === false ) {
            return [ $default_phase, '' ];
        }
        $parts = explode( '|', $cursor, 2 );
        return [ $parts[0], isset( $parts[1] ) ? $parts[1] : '' ];
    }

    /* ---------------------------------------------------------------------
     * Diagnostics
     * ------------------------------------------------------------------ */

    /**
     * Diagnostic report text for the Support tab.
     */
    public static function diagnostic_text() {
        global $wp_version;

        $s     = ISXM_Settings::all();
        $stats = self::get_stats( true );

        $lines = [
            '=== InsightX Offload Diagnostic ===',
            'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
            '',
            'Site URL: ' . site_url(),
            'WordPress: ' . $wp_version,
            'PHP: ' . PHP_VERSION,
            'Plugin: InsightX Offload v' . ISXM_PLUGIN_VERSION,
            'OpenSSL: ' . ( function_exists( 'openssl_encrypt' ) ? 'available' : 'MISSING' ),
            '',
            '--- Settings ---',
            'Provider: ' . $s['provider'],
            'Endpoint: ' . ( $s['endpoint'] !== '' ? $s['endpoint'] : '(AWS default)' ),
            'Region: ' . $s['region'],
            'Bucket: ' . ( $s['bucket'] !== '' ? $s['bucket'] : '(not set)' ),
            'Access Key: ' . ( $s['access_key'] !== '' ? substr( $s['access_key'], 0, 4 ) . '••••' : '(not set)' ),
            'Secret Key: ' . ( $s['secret_key'] !== '' ? '(set, encrypted at rest)' : '(not set)' ),
            'Path-style: ' . ( $s['path_style'] ? 'yes' : 'no' ),
            'Public ACL on upload: ' . ( $s['send_public_acl'] ? 'yes' : 'no' ),
            'Offload enabled: ' . ( $s['offload_enabled'] ? 'yes' : 'no' ),
            'Remove local media: ' . ( $s['remove_local'] ? 'yes' : 'no' ),
            'Persist URLs to DB: ' . ( $s['persist_urls'] ? 'yes' : 'no' ),
            'Prefix: ' . ( $s['use_prefix'] ? $s['prefix'] : '(off)' ),
            'Year/Month in path: ' . ( $s['use_year_month'] ? 'yes' : 'no' ),
            'Object version in path: ' . ( $s['use_object_version'] ? 'yes' : 'no' ),
            'Delivery enabled: ' . ( $s['deliver_enabled'] ? 'yes' : 'no' ),
            'Force HTTPS: ' . ( $s['force_https'] ? 'yes' : 'no' ),
            'CDN domain: ' . ( $s['cdn_domain'] !== '' ? $s['cdn_domain'] : '(none)' ),
            'Public base URL: ' . ISXM_Settings::public_base_url(),
            '',
            '--- Media ---',
            sprintf(
                'Attachments: %d total, %d on bucket (%d%%), %d partial, %d failed',
                $stats['total'],
                $stats['in_bucket'],
                $stats['percent'],
                $stats['partial'],
                $stats['failed']
            ),
            '',
            '--- Background runs ---',
            // Whether the site can drive its own bulk runs is the single
            // most useful thing to know when someone reports "the tool
            // stops when I close the tab" — read from cache so generating
            // a diagnostic never costs an HTTP round trip.
            'Loopback: ' . ( get_transient( 'isxs_bg_loopback' ) === 'no' ? 'unavailable (browser-driven fallback)' : ( get_transient( 'isxs_bg_loopback' ) === 'yes' ? 'available' : '(not checked yet)' ) ),
            'Healthcheck cron: ' . ( wp_next_scheduled( ISXM_Background::CRON_HOOK ) ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( ISXM_Background::CRON_HOOK ) ) . ' UTC' : '(not scheduled)' ),
            'Runner lock: ' . ( ISXM_Background::runner_lock_held() ? 'held' : 'free' ),
            'Tracking ledger: ' . ( ISXM_Items::ready()
                ? 'active (indexed table)'
                : sprintf( 'not built — using postmeta path, %d legacy records to copy (wp isxm backfill)', ISXM_Items::count_legacy_records() ) ),
            'WP-Cron: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'DISABLED (DISABLE_WP_CRON)' : 'enabled' ),
        ];

        foreach ( ISXM_Job::all() as $tool => $job ) {
            $lines[] = sprintf(
                '  %s: %s %d/%s items, cursor %s, updated %s UTC%s',
                $tool,
                $job->state,
                $job->processed,
                $job->total > 0 ? (string) $job->total : '?',
                is_scalar( $job->cursor ) ? (string) $job->cursor : '(complex)',
                gmdate( 'Y-m-d H:i:s', (int) $job->updated ),
                $job->error_count > 0 ? sprintf( ', %d errors', $job->error_count ) : ''
            );
        }
        if ( ! ISXM_Job::all() ) {
            $lines[] = '  (no runs recorded)';
        }

        $lines = array_merge( $lines, [
            '',
            '--- Recent Log ---',
        ] );

        $log = get_option( 'isxs_log', [] );
        if ( empty( $log ) || ! is_array( $log ) ) {
            $lines[] = '(empty) — isxm_log_error() writes here even with WP_DEBUG off';
        } else {
            foreach ( array_slice( $log, -25 ) as $entry ) {
                $count = ! empty( $entry['count'] ) && $entry['count'] > 1 ? sprintf( ' [x%d]', (int) $entry['count'] ) : '';
                $lines[] = sprintf( '%s%s %s', $entry['time'] ?? '?', $count, $entry['message'] ?? '' );
            }
        }

        $lines[] = '';
        $lines[] = '--- Active Plugins ---';

        foreach ( (array) get_option( 'active_plugins', [] ) as $plugin ) {
            $lines[] = $plugin;
        }

        return implode( "\n", $lines );
    }

    public function ajax_diagnostic() {
        $this->guard();
        wp_send_json_success( [ 'text' => self::diagnostic_text() ] );
    }
}
