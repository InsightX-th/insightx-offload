<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Background — Runs bulk jobs server-side instead of from the browser.
 *
 * The old model scheduled every batch from admin.js: closing the tab, a
 * sleeping laptop or an expired nonce ended the run, and the cursor lived
 * only in the page. Here the browser starts a job and then only watches it;
 * the batches are driven by a loopback request that re-dispatches itself
 * until the work is done, with a WP-Cron healthcheck to restart a run whose
 * loopback died mid-flight.
 *
 * Two execution modes, chosen automatically:
 *
 *  - loopback (preferred): the site can POST to its own admin-ajax.php, so
 *    the run continues with the tab closed. Verified once and cached.
 *  - browser-driven (fallback): some environments block self-requests (Local
 *    by Flywheel with certain routers, HTTP auth, firewalled hosts). The UI
 *    then calls `isxs_job_tick` on a timer to drive the very same runner.
 *    The tab must stay open, but the cursor and progress still live in the
 *    database, so stop/resume stays exact either way.
 *
 * Only one job runs at a time. The tools all compete for the same bucket
 * connection and the same attachments, so overlapping runs would produce
 * exactly the double-counting the single-job rule prevents.
 *
 * @since 0.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Background {

    /** Shared secret proving a loopback request came from this site. */
    const TOKEN_OPTION = 'isxs_bg_token';

    /** Only one runner may hold this at a time. */
    const RUNNER_LOCK = 'isxs_bg_lock';

    /**
     * Serialises the "is anything running? then claim the slot" step of
     * ajax_start(). Held only for that check, so its lifetime is a
     * request, not a run.
     */
    const START_LOCK = 'isxs_bg_start_lock';

    /** @see START_LOCK — generous enough for a slow precheck, short enough
     *  that a fatal mid-start doesn't block the next click for long. */
    const START_LOCK_TTL = 30;

    /** Cached loopback capability check. */
    const LOOPBACK_TRANSIENT = 'isxs_bg_loopback';

    /** Cron hook that restarts an abandoned run. */
    const CRON_HOOK = 'isxs_bg_healthcheck';

    /**
     * Seconds one runner request keeps processing batches before handing
     * over to a freshly dispatched one. Each batch is itself bounded by
     * ISXM_Tools::TIME_BUDGET, so this is a ceiling on how far past it a
     * request may go, not a hard cut in the middle of work.
     */
    const RUNNER_BUDGET = 25;

    /**
     * Lock lifetime. A batch checks its time budget between items, so one
     * item is the real worst case — and ISXM_Client gives a single upload
     * or download up to 300s. The lock has to outlive that comfortably, or
     * it expires mid-batch and a second runner starts processing the same
     * cursor. Short enough, still, that a fatal doesn't wedge the queue for
     * long: the healthcheck only needs it free to restart a stalled run.
     */
    const LOCK_TTL = 360;

    /** Stop before PHP's memory limit rather than dying mid-batch. */
    const MEMORY_FACTOR = 0.85;

    public function __construct() {
        // Loopback runner. Registered for logged-out requests too: the
        // dispatch deliberately carries no cookies (a blocking-free request
        // can't wait for auth anyway), so it arrives unauthenticated and is
        // authorised by the token instead.
        add_action( 'wp_ajax_nopriv_isxs_bg_run', [ $this, 'handle_loopback' ] );
        add_action( 'wp_ajax_isxs_bg_run', [ $this, 'handle_loopback' ] );
        add_action( 'wp_ajax_nopriv_isxs_bg_ping', [ $this, 'handle_ping' ] );
        add_action( 'wp_ajax_isxs_bg_ping', [ $this, 'handle_ping' ] );

        // UI endpoints.
        add_action( 'wp_ajax_isxs_job_start', [ $this, 'ajax_start' ] );
        add_action( 'wp_ajax_isxs_job_pause', [ $this, 'ajax_pause' ] );
        add_action( 'wp_ajax_isxs_job_cancel', [ $this, 'ajax_cancel' ] );
        add_action( 'wp_ajax_isxs_job_reset', [ $this, 'ajax_reset' ] );
        add_action( 'wp_ajax_isxs_job_status', [ $this, 'ajax_status' ] );
        add_action( 'wp_ajax_isxs_job_tick', [ $this, 'ajax_tick' ] );

        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'handle_healthcheck' ] );
    }

    /* ---------------------------------------------------------------------
     * Scheduling
     * ------------------------------------------------------------------ */

    /**
     * Healthcheck cadence. One minute: a run whose driver died is picked up
     * promptly instead of after a five-minute silence, which matters more
     * the longer the run is — an 80k-file offload cannot afford to sit idle
     * for five minutes every time the host kills a request.
     */
    const CRON_INTERVAL = 60;

    /**
     * The cron schedule used by the healthcheck.
     */
    public function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['isxs_one_minute'] ) ) {
            $schedules['isxs_one_minute'] = [
                'interval' => self::CRON_INTERVAL,
                'display'  => 'ทุก 1 นาที (InsightX Storage)',
            ];
        }
        return $schedules;
    }

    /**
     * Keep the healthcheck scheduled only while a job actually exists —
     * an always-on cron event on a site that never runs a bulk tool is
     * pure overhead. Re-armed on every dispatch, so a run that goes on for
     * hours can never lose its safety net.
     *
     * Also the upgrade path: an event left over from an older plugin
     * version still ticks on the old cadence (wp_schedule_event keeps the
     * interval it was created with), so an existing event whose interval
     * no longer matches is cleared and re-armed at the current one.
     */
    public static function ensure_healthcheck_scheduled() {
        // wp_get_scheduled_event() (WP 5.1+) lets us read the existing
        // event's interval; on older WP we can only tell whether one exists
        // at all.
        if ( function_exists( 'wp_get_scheduled_event' ) ) {
            $event = wp_get_scheduled_event( self::CRON_HOOK );
            if ( $event && (int) ( $event->interval ?? 0 ) === self::CRON_INTERVAL ) {
                return;
            }
            // Either nothing is scheduled, or a leftover event from an older
            // plugin version still ticks on the old cadence (WP keeps the
            // interval an event was created with) — clear and re-arm at the
            // current interval.
            wp_clear_scheduled_hook( self::CRON_HOOK );
            wp_schedule_event( time() + self::CRON_INTERVAL, 'isxs_one_minute', self::CRON_HOOK );
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + self::CRON_INTERVAL, 'isxs_one_minute', self::CRON_HOOK );
        }
    }

    /**
     * @return void
     */
    public static function unschedule_healthcheck() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Restart a run whose driver disappeared (loopback request killed by
     * the host, PHP fatal, server restart). Does nothing while a runner is
     * demonstrably alive — `is_stalled()` only becomes true after
     * ISXM_Job::STALL_SECONDS without a single batch write.
     */
    public function handle_healthcheck() {
        // Also the safety net for the ledger backfill on a site whose admin
        // area is rarely opened — it is the one job nobody clicks a button
        // to start.
        ISXM_Tools::maybe_start_backfill();

        $job = ISXM_Job::running();

        if ( ! $job ) {
            // Nothing is running, so there is nothing to restart — stop
            // paying for the event until the next job starts (ajax_start()
            // reschedules it). Paused records are left alone: they wait for
            // a person, not for a cron.
            ISXM_Job::prune();
            self::unschedule_healthcheck();
            return;
        }

        if ( ! $job->is_stalled() || self::is_locked() ) {
            return;
        }

        isxm_log_error( sprintf(
            'Background job "%s" stalled at %d items — restarting from cursor.',
            $job->tool,
            $job->processed
        ) );
        self::dispatch();
    }

    /* ---------------------------------------------------------------------
     * Dispatch
     * ------------------------------------------------------------------ */

    /**
     * Fire a non-blocking loopback request that picks up the running job.
     * Silent no-op in browser-driven mode — the UI's tick drives it there.
     *
     * @return bool Whether a runner was dispatched.
     */
    public static function dispatch() {
        // Re-arm the healthcheck on every hand-off. The event is only
        // meant to exist while a job runs, and a long run outlives plenty
        // of things that could have cleared it (plugin update, manual cron
        // prune) — the next dispatch puts it back before anything can go
        // quiet for long.
        self::ensure_healthcheck_scheduled();

        if ( ! self::loopback_available() ) {
            return false;
        }

        $url = admin_url( 'admin-ajax.php' );

        wp_remote_post( $url, [
            'timeout'   => 0.01,
            'blocking'  => false,
            'body'      => [
                'action' => 'isxs_bg_run',
                'token'  => self::token(),
            ],
            // No cookies on purpose: a non-blocking request can't complete a
            // login round trip, and the token is what authorises it.
            'cookies'   => [],
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        ] );

        return true;
    }

    /**
     * Whether this site can POST to its own admin-ajax.php. Cached for a
     * day — the answer is a property of the hosting environment, and the
     * check costs a real HTTP round trip.
     *
     * @param bool $force Re-test instead of reading the cache.
     * @return bool
     */
    public static function loopback_available( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::LOOPBACK_TRANSIENT );
            if ( $cached === 'yes' ) {
                return true;
            }
            if ( $cached === 'no' ) {
                return false;
            }
        }

        $response = wp_remote_post( admin_url( 'admin-ajax.php' ), [
            'timeout'   => 10,
            'blocking'  => true,
            'body'      => [
                'action' => 'isxs_bg_ping',
                'token'  => self::token(),
            ],
            'cookies'   => [],
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        ] );

        $ok = ! is_wp_error( $response )
            && wp_remote_retrieve_response_code( $response ) === 200
            && strpos( wp_remote_retrieve_body( $response ), 'isxs-pong' ) !== false;

        if ( ! $ok ) {
            $reason = is_wp_error( $response )
                ? $response->get_error_message()
                : 'HTTP ' . wp_remote_retrieve_response_code( $response );
            isxm_log_error( 'Loopback request unavailable (' . $reason . ') — bulk tools will run in browser-driven mode.' );
        }

        set_transient( self::LOOPBACK_TRANSIENT, $ok ? 'yes' : 'no', DAY_IN_SECONDS );
        return $ok;
    }

    /**
     * Answer the capability probe. Deliberately the cheapest possible
     * handler — it must succeed on a site under load, or the plugin
     * needlessly falls back to browser-driven mode.
     */
    public function handle_ping() {
        if ( ! $this->verify_token() ) {
            wp_die( '', '', [ 'response' => 403 ] );
        }
        echo 'isxs-pong';
        wp_die( '', '', [ 'response' => 200 ] );
    }

    /* ---------------------------------------------------------------------
     * The runner
     * ------------------------------------------------------------------ */

    /**
     * Entry point of a dispatched loopback request.
     */
    public function handle_loopback() {
        if ( ! $this->verify_token() ) {
            wp_die( '', '', [ 'response' => 403 ] );
        }

        // The client of a non-blocking request is already gone; keep going
        // regardless of what the connection does.
        ignore_user_abort( true );

        $continued = self::run();

        // Still work left and the budget ran out — hand over to a fresh
        // request rather than pushing this one past the host's limits.
        if ( $continued ) {
            self::dispatch();
        }

        wp_die( '', '', [ 'response' => 200 ] );
    }

    /**
     * Process batches of the running job until the budget runs out, the
     * job finishes, or the user pauses/cancels it.
     *
     * @return bool Whether work remains (i.e. another runner is needed).
     */
    public static function run() {
        if ( ! self::lock() ) {
            // Another runner is already on it — that one will re-dispatch.
            return false;
        }

        try {
            $started = microtime( true );

            while ( true ) {
                $job = ISXM_Job::running();
                if ( ! $job ) {
                    return false;
                }

                // A stop the UI asked for while the previous batch was in
                // flight. Checked before spending another batch's worth of
                // work on a run the user has already walked away from.
                if ( self::consume_signal( $job ) ) {
                    return false;
                }

                $batch_started = microtime( true );
                $result        = ISXM_Tools::execute_batch( $job->tool, $job->cursor );

                if ( is_wp_error( $result ) ) {
                    // A run-level failure (storage not configured, source
                    // listing unreachable) — stop as RESUMABLE, never as
                    // done: the cursor is still valid and "ทำต่อ" must be
                    // able to pick up exactly here once it's fixed.
                    $job->finish( ISXM_Job::STATE_ERROR, $result->get_error_message() );
                    return false;
                }

                $job->record_batch(
                    isset( $result['processed'] ) ? (int) $result['processed'] : 0,
                    isset( $result['cursor'] ) ? $result['cursor'] : $job->cursor,
                    isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : [],
                    microtime( true ) - $batch_started
                );

                // Some denominators can only be established while the run is
                // already going (migrate counts the source bucket one listing
                // page at a time), so batches may report it incrementally.
                $delta = isset( $result['total_delta'] ) ? (int) $result['total_delta'] : 0;
                $phase = isset( $result['phase'] ) ? (string) $result['phase'] : '';
                if ( $delta !== 0 || $phase !== $job->phase || isset( $result['total_complete'] ) ) {
                    $job->total = max( $job->total + $delta, 0 );
                    $job->phase = $phase;
                    if ( isset( $result['total_complete'] ) ) {
                        $job->total_complete = (bool) $result['total_complete'];
                    }
                    $job->save();
                }

                if ( ! empty( $result['done'] ) ) {
                    $job->finish( ISXM_Job::STATE_DONE );
                    ISXM_Tools::flush_stats_cache();
                    return false;
                }

                // A stop that arrived DURING this batch. The batch's work is
                // already recorded above, so nothing is lost and nothing is
                // redone on resume.
                if ( self::consume_signal( $job ) ) {
                    return false;
                }

                if ( $job->state !== ISXM_Job::STATE_RUNNING ) {
                    return false;
                }

                if ( microtime( true ) - $started >= self::RUNNER_BUDGET || self::memory_exceeded() ) {
                    return true;
                }
            }
        } finally {
            self::unlock();
        }
    }

    /* ---------------------------------------------------------------------
     * UI endpoints
     * ------------------------------------------------------------------ */

    /**
     * Start a tool, or resume the paused/errored run it already has.
     */
    public function ajax_start() {
        $this->guard();

        $tool   = $this->requested_tool();
        $resume = ! empty( $_POST['resume'] );

        $result = self::start_job( $tool, $resume );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }
        wp_send_json_success( $result['payload'] );
    }

    /**
     * Start (or resume) a tool, whoever is asking — the admin UI, WP-CLI or
     * a future integration. The single-job rule and every precheck live here
     * rather than in the AJAX handler, so a CLI run cannot sidestep the
     * guards the UI obeys.
     *
     * @param string $tool   Tool slug.
     * @param bool   $resume Continue the existing cursor instead of restarting.
     * @return array{error?:string,payload?:array}
     */
    public static function start_job( $tool, $resume = false ) {
        if ( ! ISXM_Tools::is_known_tool( $tool ) ) {
            return [ 'error' => 'ไม่รู้จักเครื่องมือนี้' ];
        }

        // "Is anything running?" and "claim the running slot" have to be one
        // indivisible step. Two starts landing together (a double click, two
        // tabs, a CLI run beside the UI) both used to pass the check and both
        // start, which is how two cards ended up showing "กำลังทำงาน…" at once.
        if ( ! self::claim( self::START_LOCK, self::START_LOCK_TTL ) ) {
            return [ 'error' => 'มีคำสั่งเริ่มงานอื่นกำลังทำอยู่ — รอสักครู่แล้วลองใหม่' ];
        }

        // Note: the lock is released before the caller responds, never in a
        // finally — wp_send_json_*() calls die(), which skips finally blocks.
        // A crash between here and the release is covered by the lock's TTL.
        $result = self::start_locked( $tool, $resume );
        self::release( self::START_LOCK );

        return $result;
    }

    /**
     * The body of ajax_start(), run while holding START_LOCK.
     *
     * Returns rather than responding so the caller can release the lock
     * first — see the note in ajax_start().
     *
     * @param string $tool
     * @param bool   $resume
     * @return array{error?:string,payload?:array}
     */
    private static function start_locked( $tool, $resume ) {
        // A stop asked for on a run nobody is driving would otherwise still
        // be pending here and make the slot look occupied.
        self::apply_orphaned_signals();

        $running = ISXM_Job::running();
        if ( $running && ! $running->is_stalled() ) {
            if ( $running->tool === $tool ) {
                // Already going — just hand back its state instead of
                // starting a second run over the same items.
                return [ 'payload' => self::status_payload() ];
            }
            return [
                'error' => sprintf( 'มีงาน "%s" กำลังทำงานอยู่ — หยุดงานนั้นก่อนเริ่มงานใหม่', ISXM_Tools::tool_label( $running->tool ) ),
            ];
        }
        if ( $running && $running->is_stalled() && $running->tool !== $tool ) {
            // The previous run's driver is demonstrably gone; park it as
            // resumable so this one can take the single running slot.
            $running->finish( ISXM_Job::STATE_PAUSED, 'หยุดเองเพราะไม่มีการตอบสนอง' );
        }

        $precheck = ISXM_Tools::precheck_tool( $tool );
        if ( is_wp_error( $precheck ) ) {
            return [ 'error' => $precheck->get_error_message() ];
        }

        $previous = ISXM_Job::get( $tool );

        if ( ! $resume ) {
            // A brand-new run abandons only THIS tool's previous defer file.
            // It used to delete every defer file on the site, including one
            // belonging to a paused run of another tool that was still going
            // to flush it — those queued local deletions were then lost and
            // the files sat on disk forever, with nothing left listing them.
            if ( $previous ) {
                ISXM_Tools::cleanup_defer_files( $previous->run_id );
            }
        }

        // Any stop left over from the previous run must not carry into this
        // one — the runner would read it and stop again immediately.
        ISXM_Job::clear_signal( $tool );
        if ( $resume && $previous && $previous->is_resumable() ) {
            $previous->state   = ISXM_Job::STATE_RUNNING;
            $previous->message = '';
            // Restart the clock so the ETA reflects this stretch's pace and
            // not an average across an hour of being paused — and clear the
            // per-batch rate samples, since the batch sizes of the previous
            // stretch do not predict this one (otherwise 5,000 carried-over
            // items would still sit in the sliding window).
            $previous->started     = microtime( true );
            $previous->batch_rates = [];
            $previous->save();
            $job = $previous;
        } else {
            $total = ISXM_Tools::tool_total( $tool );
            $job   = ISXM_Job::start( $tool, $total );
        }

        self::ensure_healthcheck_scheduled();

        // Kick the first runner. In browser-driven mode this returns false
        // and the client's tick loop takes over instead.
        self::dispatch();

        return [ 'payload' => self::status_payload( $job ) ];
    }

    /**
     * Act on a stop the UI asked for, if any.
     *
     * Pause and cancel are never written into the job record by the request
     * that handles the click — they land in a separate signal option, and
     * this is the only place that turns one into a state change. That keeps
     * the runner the single writer of the record: its progress saves can no
     * longer overwrite a stop, and a stop can no longer be undone by the
     * next batch landing.
     *
     * @param ISXM_Job $job The run being driven.
     * @return bool Whether the run was stopped and the loop must exit.
     */
    private static function consume_signal( ISXM_Job $job ) {
        $signal = ISXM_Job::signal( $job->tool );
        if ( $signal === '' ) {
            return false;
        }
        ISXM_Job::clear_signal( $job->tool );

        if ( $signal === ISXM_Job::SIGNAL_CANCEL ) {
            $job->delete();
            // A cancelled run will never reach its rewrite phase, so its
            // deferred deletions are abandoned too — the local files stay
            // on disk, which is the safe direction.
            ISXM_Tools::cleanup_defer_files( $job->run_id );
        } else {
            $job->finish( ISXM_Job::STATE_PAUSED );
        }
        return true;
    }

    /**
     * Apply a pending stop to a run whose driver is demonstrably gone
     * (loopback killed, PHP fatal, tab closed in browser-driven mode).
     *
     * Without this a stop asked for on a dead run would sit unconsumed
     * forever and the card would stay stuck on "กำลังหยุด…". Only stalled
     * runs are touched — a live runner consumes its own signal, and racing
     * it here is exactly what the split was meant to prevent.
     */
    private static function apply_orphaned_signals() {
        foreach ( ISXM_Job::all() as $job ) {
            if ( ISXM_Job::signal( $job->tool ) === '' ) {
                continue;
            }
            if ( $job->state === ISXM_Job::STATE_RUNNING && ! $job->is_stalled() ) {
                continue;
            }
            self::consume_signal( $job );
        }
    }

    /**
     * Stop the run, keeping its cursor so "ทำต่อ" is exact.
     *
     * Writes intent only. The runner picks it up between batches and stops
     * itself — the in-flight batch finishes server-side (there is no
     * per-item cancel token) and its work is recorded first, so nothing is
     * lost and nothing is double-counted.
     */
    public function ajax_pause() {
        $this->guard();

        self::pause_job( $this->requested_tool() );
        wp_send_json_success( self::status_payload() );
    }

    /**
     * Ask a running job to stop, keeping its cursor. Shared by the UI and
     * WP-CLI.
     *
     * @param string $tool
     * @return bool Whether there was a running job to stop.
     */
    public static function pause_job( $tool ) {
        $job = ISXM_Job::get( $tool );
        if ( ! $job || $job->state !== ISXM_Job::STATE_RUNNING ) {
            return false;
        }

        ISXM_Job::set_signal( $tool, ISXM_Job::SIGNAL_PAUSE );
        // Nothing is driving a stalled run, so there is nobody to read the
        // signal — settle it here instead of leaving the card stuck.
        if ( $job->is_stalled() ) {
            self::consume_signal( $job );
        }
        return true;
    }

    /**
     * Forget a stopped run's cursor. Never touches the work already done —
     * every tool skips already-processed items on its own, so a later fresh
     * start is still correct, it just rescans from the beginning.
     */
    public function ajax_cancel() {
        $this->guard();

        self::cancel_job( $this->requested_tool() );
        wp_send_json_success( self::status_payload() );
    }

    /**
     * Discard a run's cursor. Shared by the UI and WP-CLI.
     *
     * @param string $tool
     * @return bool Whether there was a record to discard.
     */
    public static function cancel_job( $tool ) {
        $job = ISXM_Job::get( $tool );
        if ( ! $job ) {
            return false;
        }

        if ( $job->state === ISXM_Job::STATE_RUNNING && ! $job->is_stalled() ) {
            // A live runner owns the record; it drops it when it reads the
            // signal, after the in-flight batch is accounted for.
            ISXM_Job::set_signal( $tool, ISXM_Job::SIGNAL_CANCEL );
        } else {
            // Nothing is driving it — settle it here and now.
            ISXM_Job::clear_signal( $tool );
            $job->delete();
            ISXM_Tools::cleanup_defer_files( $job->run_id );
        }
        return true;
    }

    /**
     * Forget every tool's run at once. Used when the destination bucket
     * changes: a cursor into the previous destination's scan is
     * meaningless against the new one, so keeping it would let "ทำต่อ"
     * resume a run that no longer refers to anything.
     *
     * A run still in flight is stopped first — its in-flight batch is
     * already writing to the OLD bucket, and letting it continue after the
     * switch is exactly how half an item ends up in each.
     */
    public function ajax_reset() {
        $this->guard();

        self::reset_jobs();
        wp_send_json_success( self::status_payload() );
    }

    /**
     * Drop every tool's record. Shared by the UI and WP-CLI.
     *
     * @return int How many records were dropped.
     */
    public static function reset_jobs() {
        $dropped = 0;
        foreach ( ISXM_Job::all() as $job ) {
            ISXM_Job::clear_signal( $job->tool );
            $job->delete();
            $dropped++;
        }
        // Every run's defer files go with it — nothing will ever flush them.
        ISXM_Tools::cleanup_defer_files();
        self::unschedule_healthcheck();
        ISXM_Tools::flush_stats_cache();

        return $dropped;
    }

    /**
     * Poll: every tool's job state plus the shared stats.
     */
    public function ajax_status() {
        $this->guard();
        wp_send_json_success( self::status_payload() );
    }

    /**
     * Browser-driven mode: run one budgeted slice and report back. Also
     * used as a safety net in loopback mode — if the client notices a
     * running job that has gone quiet, it can drive it from here rather
     * than waiting for the cron healthcheck.
     */
    public function ajax_tick() {
        $this->guard();
        self::run();
        wp_send_json_success( self::status_payload() );
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Capability + nonce check for the UI endpoints, with a nonce refresh
     * in every response so a page left open overnight keeps working.
     */
    private function guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'ไม่มีสิทธิ์ใช้งาน' ], 403 );
        }
        check_ajax_referer( ISXM_Tools::NONCE_ACTION, 'nonce' );
    }

    /**
     * @return string Validated tool slug.
     */
    private function requested_tool() {
        $tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
        if ( ! ISXM_Tools::is_known_tool( $tool ) ) {
            wp_send_json_error( [ 'message' => 'ไม่รู้จักเครื่องมือนี้' ] );
        }
        return $tool;
    }

    /**
     * The whole picture the UI renders from: one entry per tool that has a
     * record, the live stats, the execution mode, and a fresh nonce.
     *
     * @param ISXM_Job|null $just_started Job to make sure is included even
     *                                    if the option read raced it.
     * @return array
     */
    private static function status_payload( $just_started = null ) {
        ISXM_Job::prune();
        // A stop asked for on a run whose driver has since died would sit
        // unread forever; every poll is a chance to settle it.
        self::apply_orphaned_signals();

        $jobs = [];
        foreach ( ISXM_Job::all() as $tool => $job ) {
            $payload = $job->to_payload();
            // Lets the card say "กำลังหยุด…" for as long as the stop is
            // actually pending, instead of only until the next poll repaints
            // over the optimistic label the click set.
            $payload['signal'] = ISXM_Job::signal( $tool );
            $jobs[ $tool ]     = $payload;
        }
        if ( $just_started instanceof ISXM_Job && ! isset( $jobs[ $just_started->tool ] ) ) {
            $jobs[ $just_started->tool ] = $just_started->to_payload();
        }

        return [
            // Cast so an empty set serializes as {} rather than [] — the
            // client indexes it by tool slug.
            'jobs'      => (object) $jobs,
            'stats'     => ISXM_Tools::get_stats(),
            'loopback'  => self::loopback_available(),
            // Every response re-mints the nonce: a run can outlive the 24h
            // nonce lifetime, and so can an admin page left open overnight.
            'nonce'     => wp_create_nonce( ISXM_Tools::NONCE_ACTION ),
        ];
    }

    /**
     * Compare the dispatch token in constant time.
     *
     * @return bool
     */
    private function verify_token() {
        $sent = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
        return $sent !== '' && hash_equals( self::token(), $sent );
    }

    /**
     * The site's dispatch token, generated on first use. Not a nonce: it
     * has to keep working for a run that outlives any nonce lifetime, and
     * it only ever authorises "continue the job this site already started".
     *
     * @return string
     */
    private static function token() {
        $token = get_option( self::TOKEN_OPTION );
        if ( ! is_string( $token ) || strlen( $token ) < 32 ) {
            $token = wp_generate_password( 48, false, false );
            update_option( self::TOKEN_OPTION, $token, false );
        }
        return $token;
    }

    /**
     * @return bool Whether the lock was acquired.
     */
    /**
     * Whether a runner currently holds the lock. Public for the diagnostic
     * report — everything else goes through lock()/unlock().
     *
     * @return bool
     */
    public static function runner_lock_held() {
        return self::held( self::RUNNER_LOCK, self::LOCK_TTL );
    }

    private static function lock() {
        return self::claim( self::RUNNER_LOCK, self::LOCK_TTL );
    }

    private static function unlock() {
        self::release( self::RUNNER_LOCK );
    }

    /**
     * @return bool
     */
    private static function is_locked() {
        return self::held( self::RUNNER_LOCK, self::LOCK_TTL );
    }

    /* ---------------------------------------------------------------------
     * Locks
     *
     * "Read the option, decide, then write it" is not a lock — both callers
     * read the same free state and both proceed. These use the unique index
     * on wp_options.option_name instead: exactly one concurrent INSERT can
     * win, and that winner is the lock holder. Every lock carries the time
     * it was taken so a request that dies mid-hold (fatal, host timeout)
     * cannot wedge the feature — the next caller past the TTL takes over.
     * ------------------------------------------------------------------ */

    /**
     * Take a lock, or fail immediately if someone else holds a fresh one.
     *
     * @param string $key Option name to use as the lock.
     * @param int    $ttl Seconds after which a held lock is considered dead.
     * @return bool Whether the lock is now held by this request.
     */
    private static function claim( $key, $ttl ) {
        global $wpdb;

        $now = time();

        // The unique index on option_name makes this the atomic step: of N
        // requests racing here, exactly one INSERT affects a row.
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            (string) $now
        ) );
        if ( $inserted ) {
            wp_cache_delete( $key, 'options' );
            return true;
        }

        $held = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $key
        ) );
        if ( null === $held || $now - (int) $held < $ttl ) {
            return false;
        }

        // Aged out. The WHERE on the old value is a compare-and-swap: if
        // another request took it over first, this affects no rows and we
        // correctly report failure rather than both proceeding.
        $swapped = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            (string) $now,
            $key,
            $held
        ) );
        wp_cache_delete( $key, 'options' );
        return (bool) $swapped;
    }

    /**
     * @param string $key
     * @param int    $ttl
     * @return bool Whether a live (non-expired) holder exists.
     */
    private static function held( $key, $ttl ) {
        global $wpdb;

        $held = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $key
        ) );
        return null !== $held && ( time() - (int) $held ) < $ttl;
    }

    /**
     * @param string $key
     */
    private static function release( $key ) {
        global $wpdb;

        $wpdb->delete( $wpdb->options, [ 'option_name' => $key ] );
        wp_cache_delete( $key, 'options' );
    }

    /**
     * Stop the loop before PHP's memory limit turns into a fatal that
     * takes the whole run with it.
     *
     * @return bool
     */
    private static function memory_exceeded() {
        $limit = self::memory_limit();
        if ( $limit <= 0 ) {
            return false;
        }
        return memory_get_usage( true ) >= $limit * self::MEMORY_FACTOR;
    }

    /**
     * @return int Bytes, or 0 when unlimited/unknown.
     */
    private static function memory_limit() {
        $limit = function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : false;
        if ( $limit === false || $limit === '' || $limit === '-1' ) {
            return 0;
        }
        return (int) wp_convert_hr_to_bytes( $limit );
    }
}
