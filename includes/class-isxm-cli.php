<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_CLI — WP-CLI commands for InsightX Storage.
 *
 * The recommended path for large media libraries: no HTTP/browser overhead,
 * survives disconnects (re-running resumes naturally — the pending scan
 * skips whatever is already offloaded), and reuses the same engine as the
 * admin bulk tools (batched uploads, then ONE one-pass DB URL rewrite at
 * the end instead of a full-table scan per chunk).
 *
 *     wp isxm status
 *     wp isxm offload [--batch=<n>]
 *     wp isxm migrate [--batch=<n>]
 *     wp isxm sync [--apply]
 *     wp isxm backfill
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;

class ISXM_CLI {

    /**
     * Refuse to start a foreground run while the job system is driving one.
     *
     * The commands in this class each walk the media library themselves,
     * outside the job records that `wp isxm job` and the admin UI share. That
     * is fine on its own, but running one beside a live job means two loops
     * uploading the same attachments to the same bucket at once — double
     * work, doubled API cost, and counters that disagree afterwards. The job
     * system enforces one-at-a-time internally; this extends the same rule to
     * the foreground commands.
     *
     * @param string $what Human name of the command being guarded.
     */
    private function assert_no_job_running( $what ) {
        $running = ISXM_Job::running();
        if ( ! $running || $running->is_stalled() ) {
            return;
        }
        WP_CLI::error( sprintf(
            'มีงาน "%s" กำลังทำงานอยู่ (%s รายการ) — %s ตอนนี้จะทำงานทับกัน'
                . "\n" . 'หยุดก่อนด้วย: wp isxm job pause %s   หรือใช้ `wp isxm job run` ไล่ให้จบ',
            ISXM_Tools::tool_label( $running->tool ),
            number_format_i18n( $running->processed ),
            $what,
            $running->tool
        ) );
    }

    /**
     * Refuse to start unless the destination bucket actually answers.
     *
     * ISXM_Settings::is_configured() only proves the fields are filled in.
     * A typo'd or deleted bucket passed that check and the run went on to
     * fail every single item with the same 404 NoSuchBucket — tens of
     * thousands of times — before anyone could tell why. One HEAD request
     * (cached for a few minutes) turns that into a refusal with the reason.
     */
    private function assert_destination_reachable() {
        $ok = ISXM_Tools::destination_reachable();
        if ( is_wp_error( $ok ) ) {
            WP_CLI::error( $ok->get_error_message() );
        }
    }

    /**
     * Show offload progress numbers.
     *
     * ## EXAMPLES
     *
     *     wp isxm status
     */
    public function status() {
        $stats = ISXM_Tools::get_stats( true );
        WP_CLI::line( sprintf(
            'Attachments: %d total, %d on bucket (%d%%), %d partial, %d pending, %d failed',
            $stats['total'],
            // `in_bucket` (every file physically on the current destination,
            // including migrated ones) is the honest figure — `offloaded`
            // would undercount files that arrived via Migrate.
            $stats['in_bucket'],
            $stats['percent'],
            $stats['partial'],
            // Pending is what `wp isxm offload` would actually process, so it
            // uses the same in_bucket-based definition that command does.
            max( $stats['total'] - $stats['in_bucket'], 0 ),
            $stats['failed']
        ) );
        if ( $stats['failed'] > 0 ) {
            WP_CLI::line( 'Retry just the failures with: wp isxm offload --failed-only' );
        }
        if ( ! ISXM_Items::ready() ) {
            WP_CLI::line( sprintf(
                'Tracking ledger: not built yet (%d legacy records to copy) — counts are using the slower postmeta path. Run: wp isxm backfill',
                ISXM_Items::count_legacy_records()
            ) );
        }
    }

    /**
     * Build the tracking ledger (wp_isxs_items) from legacy `_isxs_offload`
     * postmeta.
     *
     * Runs automatically in the background after an upgrade — this command
     * is for finishing it immediately on a large library, or on a site whose
     * admin area and WP-Cron are never exercised. Idempotent and resumable:
     * re-running after an interruption picks up where it stopped.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Records per query.
     * ---
     * default: 500
     * ---
     *
     * ## EXAMPLES
     *
     *     wp isxm backfill
     */
    public function backfill( $args, $assoc_args ) {
        $this->assert_no_job_running( 'wp isxm backfill' );

        $batch = max( 1, (int) ( $assoc_args['batch'] ?? 500 ) );

        if ( ISXM_Items::ready() ) {
            WP_CLI::success( 'Ledger is already built — nothing to do.' );
            return;
        }

        $total = ISXM_Items::count_legacy_records();
        if ( $total === 0 ) {
            ISXM_Items::mark_backfilled();
            ISXM_Tools::flush_stats_cache();
            WP_CLI::success( 'No legacy records to copy — ledger enabled.' );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( 'Copying tracking records', $total );
        $after_id = 0;
        $copied   = 0;
        $skipped  = 0;

        do {
            $window   = ISXM_Items::backfill_window( $batch, $after_id );
            $after_id = $window['last_id'];
            $copied  += $window['copied'];
            $skipped += $window['skipped'];
            $progress->tick( $window['copied'] + $window['skipped'] );
            // The per-request cache would otherwise hold every row copied.
            ISXM_Items::flush_cache();
        } while ( ! $window['done'] );

        $progress->finish();

        ISXM_Items::mark_backfilled();
        ISXM_Tools::flush_stats_cache();

        WP_CLI::success( sprintf(
            'Ledger built: %d records copied, %d skipped (no base_key/files — nothing any tool could act on).',
            $copied,
            $skipped
        ) );
    }

    /**
     * Offload all not-yet-offloaded media to the configured destination bucket.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Attachments per chunk. The DB URL rewrite runs once for the whole
     *   run (one broad-probe pass per table) instead of per chunk.
     * ---
     * default: 100
     * ---
     *
     * [--failed-only]
     * : Only retry attachments whose last offload attempt failed, instead of
     * scanning the whole library for anything not on the current bucket.
     *
     * ## EXAMPLES
     *
     *     wp isxm offload
     *     wp isxm offload --batch=250
     *     wp isxm offload --failed-only
     */
    public function offload( $args, $assoc_args ) {
        $this->assert_no_job_running( 'wp isxm offload' );

        $this->assert_destination_reachable();

        $offload = new ISXM_Offload();
        $process = function ( $id ) use ( $offload ) {
            $res = $offload->offload_attachment( $id, null, true );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            $pairs = ISXM_Offload::collect_url_pairs( $id );
            ISXM_DB_Rewriter::update_attachment_guid( $id, $pairs );
            return $pairs;
        };

        if ( ! empty( $assoc_args['failed-only'] ) ) {
            $stats = ISXM_Tools::get_stats( true );
            if ( $stats['failed'] === 0 ) {
                WP_CLI::success( 'No failed offloads to retry.' );
                return;
            }
            $this->run( $assoc_args, 'Retrying failed offloads', $process, [ 'ISXM_Tools', 'get_failed_ids' ], $stats['failed'] );
            return;
        }

        $this->run( $assoc_args, 'Offloading media', $process );
    }

    /**
     * Migrate media from the configured source bucket to the destination bucket.
     *
     * Pulls files missing locally from the source (read-only), offloads them
     * to the destination, and permanently rewrites their URLs in the database.
     * Walks the source listing one page (up to 200 objects) at a time, with
     * one bulk DB URL rewrite per page.
     *
     * ## EXAMPLES
     *
     *     wp isxm migrate
     */
    public function migrate( $args, $assoc_args ) {
        $this->assert_no_job_running( 'wp isxm migrate' );

        $this->assert_destination_reachable();

        $s = ISXM_Settings::all();
        if ( $s['source_bucket'] === '' || $s['source_access_key'] === '' || $s['source_secret_key'] === '' ) {
            WP_CLI::error( 'Migration source is not configured — set source bucket/access key/secret key first.' );
        }

        $this->run_migrate( $assoc_args );
    }

    /**
     * Compare the destination bucket against the plugin's tracking meta and
     * report (or fix) records that claim files are uploaded when the bucket
     * no longer has them — i.e. files deleted out-of-band (console/CLI).
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Delete the stale tracking meta so Offload/Migrate re-upload the
     *   affected files. Without this the command only reports what it
     *   found (dry run — nothing is changed).
     *
     * [--delete-orphans]
     * : Also delete objects in the bucket that no attachment references
     *   (only within the configured prefix — keys outside the prefix are
     *   never touched). Confirm before using.
     *
     * ## EXAMPLES
     *
     *     wp isxm sync
     *     wp isxm sync --apply
     *     wp isxm sync --apply --delete-orphans
     */
    public function sync( $args, $assoc_args ) {
        $this->assert_destination_reachable();

        $apply          = ! empty( $assoc_args['apply'] );
        $delete_orphans = ! empty( $assoc_args['delete-orphans'] );

        WP_CLI::line( 'Listing the bucket and comparing against tracking meta…' );
        $result = ISXM_Sync::scan_full();
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        $stale   = count( $result['stale_ids'] );
        $partial = count( $result['partial_ids'] );
        WP_CLI::log( sprintf(
            'Scanned %d object(s) in the bucket against %d tracked attachment(s).',
            $result['scanned_objects'],
            $result['attach_count']
        ) );
        WP_CLI::log( sprintf( 'Stale records (meta says uploaded, PRIMARY file missing from bucket): %d', $stale ) );
        WP_CLI::log( sprintf( 'Partial (primary present, some sizes missing): %d', $partial ) );
        WP_CLI::log( sprintf( 'Orphan objects under the configured prefix: %d', $result['orphan'] ) );
        if ( ! empty( $result['outside_prefix'] ) ) {
            WP_CLI::log( sprintf( 'Objects outside the configured prefix (not touched): %d', $result['outside_prefix'] ) );
        }

        foreach ( array_slice( $result['orphan_sample'], 0, 5 ) as $key ) {
            WP_CLI::log( '  orphan: ' . $key );
        }
        foreach ( array_slice( $result['stale_ids'], 0, 5 ) as $id ) {
            WP_CLI::log( '  stale attachment #' . $id );
        }

        if ( $delete_orphans && $result['orphan'] > 0 ) {
            WP_CLI::confirm( 'Delete the orphan objects above (in-prefix only)?' );
            $deleted = ISXM_Sync::cleanup_orphans();
            if ( is_wp_error( $deleted ) ) {
                WP_CLI::error( $deleted->get_error_message() );
            }
            WP_CLI::success( sprintf( 'Deleted %d orphan object(s).', $deleted ) );
        }

        if ( ! $apply ) {
            WP_CLI::success( 'Dry run — nothing changed. Re-run with --apply to clean the stale records.' );
            return;
        }

        if ( $stale === 0 ) {
            WP_CLI::success( 'Nothing to clean — meta matches the bucket.' );
            return;
        }

        $out = ISXM_Sync::cleanup_stale( $result['stale_ids'] );
        WP_CLI::success( sprintf(
            'Deleted %d stale record(s) (%d of them with no local copy — data loss, see Media Library). Re-run "wp isxm offload" (or migrate) to re-upload the rest.',
            $out['cleaned'],
            $out['data_loss']
        ) );
    }

    /**
     * Download all offloaded media from the configured bucket back to the local server.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Attachments per chunk.
     * ---
     * default: 100
     * ---
     *
     * ## EXAMPLES
     *
     *     wp isxm download
     *     wp isxm download --batch=250
     */
    public function download( $args, $assoc_args ) {
        $this->assert_no_job_running( 'wp isxm download' );
        $this->assert_destination_reachable();

        $offload = new ISXM_Offload();

        $this->run_offloaded( $assoc_args, 'Downloading media to server', function ( $id ) use ( $offload ) {
            return $offload->download_attachment( $id );
        } );
    }

    /**
     * Remove all offloaded media from the bucket (downloads back first to keep files safe).
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Attachments per chunk.
     * ---
     * default: 100
     * ---
     *
     * ## EXAMPLES
     *
     *     wp isxm remove
     *     wp isxm remove --batch=250
     */
    public function remove( $args, $assoc_args ) {
        $this->assert_no_job_running( 'wp isxm remove' );
        $this->assert_destination_reachable();

        $offload = new ISXM_Offload();

        $this->run_offloaded( $assoc_args, 'Removing media from bucket', function ( $id ) use ( $offload ) {
            // defer_persist: reverse URL pairs are returned and flushed as
            // one bulk DB rewrite per chunk (guid already updated inside).
            return $offload->remove_remote_attachment( $id, true );
        } );
    }

    /**
     * Shared runner for already-offloaded attachments (download / remove).
     *
     * A callback may return URL pairs (remove's reverse rewrite) — they're
     * aggregated and flushed as one bulk DB rewrite per chunk.
     *
     * @param array    $assoc_args CLI assoc args ([--batch=<n>]).
     * @param string   $label      Progress bar label.
     * @param callable $process    fn( int $id ): true|array|WP_Error
     */
    private function run_offloaded( $assoc_args, $label, callable $process ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $chunk = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

        // `in_bucket`, not `offloaded`: get_offloaded_ids() selects on
        // bucket+endpoint only, whereas `offloaded` is additionally filtered
        // to origin='offload' for the Overview ring. Using the latter here
        // ignored every file Migrate put in the bucket — the bar overshot
        // 100%, and a library migrated end-to-end reported "nothing to do".
        $stats     = ISXM_Tools::get_stats( true );
        $offloaded = $stats['in_bucket'];
        if ( $offloaded === 0 ) {
            WP_CLI::success( 'Nothing to process — no media offloaded to the current bucket.' );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( $label, $offloaded );

        $after_id = 0;
        $ok       = 0;
        $errors   = [];
        $done     = false;

        // get_offloaded_ids() returns { ids, last_id, done } (it scans a
        // bounded window and filters by current bucket in PHP), so drive the
        // loop off those keys — the same shape run() uses for get_pending_ids().
        while ( ! $done ) {
            $batch     = ISXM_Tools::get_offloaded_ids( $chunk, $after_id );
            $url_pairs = [];

            ISXM_Tools::prime_caches( $batch['ids'] );

            foreach ( $batch['ids'] as $id ) {
                $result = $process( $id );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '#%d: %s', $id, $result->get_error_message() );
                } else {
                    if ( is_array( $result ) ) {
                        $url_pairs = array_merge( $url_pairs, $result );
                    }
                    $ok++;
                }
                $progress->tick();
            }

            if ( ! empty( $url_pairs ) ) {
                ISXM_DB_Rewriter::replace_urls_bulk( $url_pairs );
            }

            $after_id = $batch['last_id'];
            $done     = $batch['done'];
        }

        $progress->finish();

        foreach ( $errors as $error ) {
            WP_CLI::warning( $error );
        }

        if ( ! empty( $errors ) ) {
            WP_CLI::error( sprintf( '%d processed, %d failed — see warnings above.', $ok, count( $errors ) ) );
        }
        WP_CLI::success( sprintf( '%d attachments processed.', $ok ) );
    }

    /**
     * Migrate runner: walks the source bucket's real object listing in
     * chunks (ISXM_Migrate::scan_source_batch()) instead of guessing from
     * WordPress' own tables — same ground-truth approach the admin Migrate
     * tool uses, so a source-bucket item is never skipped or double-counted
     * just because this plugin never tracked it via `_isxs_offload` meta.
     *
     * @param array $assoc_args CLI assoc args (unused).
     */
    private function run_migrate( $assoc_args ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $count_result = ISXM_Migrate::count_source_objects();
        $total        = ( ! is_wp_error( $count_result ) && $count_result['total'] > 0 ) ? $count_result['total'] : 1;

        $progress = \WP_CLI\Utils\make_progress_bar( 'Migrating media', $total );

        $token  = '';
        $ok     = 0;
        $errors = [];
        $done   = false;

        while ( ! $done ) {
            $batch = ISXM_Migrate::scan_source_batch( $token );
            if ( is_wp_error( $batch ) ) {
                $progress->finish();
                WP_CLI::error( $batch->get_error_message() );
            }

            $url_pairs = [];
            ISXM_Tools::prime_caches( array_keys( $batch['items'] ) );
            foreach ( $batch['items'] as $id => $key_map ) {
                $result = ISXM_Migrate::migrate_attachment( $id, $key_map, true );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '#%d %s: %s', $id, wp_basename( (string) get_attached_file( $id ) ), $result->get_error_message() );
                } else {
                    $pairs     = ISXM_Offload::collect_url_pairs( $id, ISXM_Migrate::extra_old_dirs( $id ) );
                    $url_pairs = array_merge( $url_pairs, $pairs );
                    ISXM_DB_Rewriter::update_attachment_guid( $id, $pairs );
                    $ok++;
                }
                $progress->tick();
            }

            if ( ! empty( $url_pairs ) ) {
                ISXM_DB_Rewriter::replace_urls_bulk( $url_pairs );
            }
            // "Remove Local Media" deletions are held until the rewrite lands.
            ISXM_Offload::flush_local_deletions();

            $token = $batch['next_token'];
            $done  = $batch['done'];
        }

        $progress->finish();

        foreach ( $errors as $error ) {
            WP_CLI::warning( $error );
        }

        if ( ! empty( $errors ) ) {
            WP_CLI::error( sprintf( '%d processed, %d failed — see warnings above. Re-run to retry the failed items.', $ok, count( $errors ) ) );
        }
        WP_CLI::success( sprintf( '%d attachments processed.', $ok ) );
    }

    /**
     * Shared runner: walk every pending attachment in chunks, process each
     * via $process (returns URL pairs on success), then run ONE final
     * one-pass DB URL rewrite for the whole run (rewrite_all_urls_once)
     * instead of a full-table scan per chunk — same two-phase design as the
     * admin bulk job, so a 100k-file offload pays for the table scans once.
     *
     * @param array         $assoc_args CLI assoc args ([--batch=<n>]).
     * @param string        $label      Progress bar label.
     * @param callable      $process    fn( int $id ): WP_Error|array
     * @param callable|null $fetch_ids  fn( int $limit, int $after_id ): array{ids,last_id,done}
     *                                  — defaults to the pending set.
     * @param int|null      $total      Progress-bar denominator for a custom set.
     */
    private function run( $assoc_args, $label, callable $process, ?callable $fetch_ids = null, $total = null ) {
        // CLI runs are legitimately unbounded (hours on a large library),
        // and a CLI process is not a web-server request — so unlike the
        // AJAX batch handlers (ISXM_Tools::MAX_REQUEST_SECONDS) there is no
        // hang risk worth capping here.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        if ( $fetch_ids === null ) {
            $fetch_ids = [ 'ISXM_Tools', 'get_pending_ids' ];
        }

        $chunk = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 100;

        if ( $total !== null ) {
            $pending = (int) $total;
        } else {
            // Same reasoning as run_offloaded(): the pending set is defined by
            // get_pending_ids() as everything NOT on the current bucket/
            // endpoint, which is origin-agnostic — so the denominator is
            // total - in_bucket. total - offloaded counted already-migrated
            // files as still pending and left the bar short of its own total.
            $stats   = ISXM_Tools::get_stats( true );
            $pending = max( $stats['total'] - $stats['in_bucket'], 0 );
        }
        if ( $pending === 0 ) {
            WP_CLI::success( 'Nothing to process — all media already offloaded to the current bucket.' );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( $label, $pending );

        $after_id = 0;
        $ok       = 0;
        $errors   = [];
        $done     = false;

        $defer_file = null;
        while ( ! $done ) {
            $batch = call_user_func( $fetch_ids, $chunk, $after_id );

            ISXM_Tools::prime_caches( $batch['ids'] );

            foreach ( $batch['ids'] as $id ) {
                $result = $process( $id );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '#%d %s: %s', $id, wp_basename( (string) get_attached_file( $id ) ), $result->get_error_message() );
                } else {
                    $ok++;
                }
                $progress->tick();
            }

            // No per-chunk DB rewrite: the whole run pays for the table
            // scans once, in the final one-pass pass below (same two-phase
            // design as the admin job — per-chunk full-table LIKE scans are
            // what made large offloads take hours). Deletions follow the
            // same rule: delivery on → flush now (runtime filters cover the
            // still-local DB URLs); persist-only → spill to the defer file
            // and flush after the rewrite has landed.
            if ( ISXM_Settings::get( 'deliver_enabled' ) ) {
                ISXM_Offload::flush_local_deletions();
            } elseif ( ISXM_Offload::should_persist_urls() ) {
                if ( $defer_file === null ) {
                    $defer_file = ISXM_Tools::defer_file_for_run( 'cli-' . wp_generate_password( 10, false, false ) );
                }
                ISXM_Tools::spill_local_deletions( $defer_file );
            }

            $after_id = $batch['last_id'];
            $done     = $batch['done'];
        }

        $progress->finish();

        // The one final one-pass rewrite, then the deletions it unlocks.
        if ( ISXM_Offload::should_persist_urls() ) {
            ISXM_Tools::rewrite_all_urls_once();
        }
        if ( $defer_file !== null ) {
            ISXM_Tools::flush_deferred_deletions( $defer_file );
        }

        foreach ( $errors as $error ) {
            WP_CLI::warning( $error );
        }

        if ( ! empty( $errors ) ) {
            WP_CLI::error( sprintf( '%d processed, %d failed — see warnings above. Re-run to retry the failed items.', $ok, count( $errors ) ) );
        }
        WP_CLI::success( sprintf( '%d attachments processed.', $ok ) );
    }
}

WP_CLI::add_command( 'isxm', 'ISXM_CLI' );
