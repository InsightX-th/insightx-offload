<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Job — Server-side state for one bulk run.
 *
 * Until this existed, a run's cursor lived only in the browser
 * ($card.data('isxsResume') + localStorage): closing the tab, an expired
 * nonce or a second admin opening the page all lost it, so "หยุด" → "ทำต่อ"
 * could silently restart from zero or skip work. The run itself also died
 * with the tab, because every batch was scheduled by JS.
 *
 * A job record is the single source of truth instead:
 *   - the cursor lives here, so resume is exact no matter what the browser did
 *   - progress/total live here, so every viewer sees the same numbers
 *   - state lives here, so pause/cancel survive a reload
 *
 * All jobs live in one option, keyed by tool. Only one may be `running` at
 * a time (ISXM_Background enforces it); the others keep their paused/
 * finished record so each tool card can render its own last state.
 *
 * @since 0.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Job {

    /**
     * One option per tool — `isxs_job_offload`, `isxs_job_remove`, …
     *
     * Every tool used to share a single `isxs_jobs` array, which meant each
     * write was a read-modify-write of EVERY tool's record. Two overlapping
     * requests (the runner saving progress while the UI paused a job, or two
     * tools started seconds apart) each read the whole array, changed their
     * own key and wrote the whole thing back — last write wins, so the other
     * tool's record was silently resurrected or wiped. Splitting the option
     * removes that class of bug outright: a tool's writes can no longer
     * touch another tool's record.
     */
    const OPTION_PREFIX = 'isxs_job_';

    /**
     * Stop intent lives in its OWN option per tool — `isxs_jobsig_offload`.
     *
     * The runner writes progress into the job record continuously; the UI
     * writes pause/cancel. Keeping those in one record meant they raced and
     * the runner's next save put `running` back. They are separate keys now,
     * so a pause can never be overwritten by progress: the runner reads the
     * signal between batches and acts on it. (Same split WP Offload Media
     * uses — a `_status` option beside the queue.)
     */
    const SIGNAL_PREFIX = 'isxs_jobsig_';

    /** Pre-0.2.1 storage: every tool in one array. Migrated on first read. */
    const LEGACY_OPTION_KEY = 'isxs_jobs';

    /** Job states. */
    const STATE_RUNNING   = 'running';
    const STATE_PAUSED    = 'paused';
    const STATE_CANCELLED = 'cancelled';
    const STATE_DONE      = 'done';
    const STATE_ERROR     = 'error';

    /** Stop intents written by the UI, consumed by the runner. */
    const SIGNAL_PAUSE  = 'pause';
    const SIGNAL_CANCEL = 'cancel';

    /** Keep the error list bounded — it is rendered in full in the UI. */
    const MAX_ERRORS = 50;

    /** How many recent batch rates the ETA is averaged over. */
    const MAX_BATCH_SAMPLES = 10;

    /**
     * How many batches must have been recorded before an ETA is shown.
     * The first batches of a run are its slowest (cold caches, first
     * connections) and a run can be as short as one batch — an ETA from
     * that sample is noise, so it stays hidden until the window has a
     * meaningful size.
     */
    const MIN_BATCH_SAMPLES = 3;

    /**
     * A run is considered abandoned when nothing has updated it for this
     * long. The healthcheck restarts those; the UI shows them as stalled.
     */
    const STALL_SECONDS = 300;

    /** @var string Tool slug, e.g. 'offload'. */
    public $tool;
    /** @var string Unique id for this run (new on every start). */
    public $run_id;
    /** @var string One of the STATE_* constants. */
    public $state;
    /** @var int|string Resume cursor — attachment ID, or migrate's opaque token. */
    public $cursor;
    /** @var int Items processed across every batch of this run. */
    public $processed;
    /** @var int Items finished in the batch currently in flight (see
     *  set_batch_progress) — folded into `processed` when the batch lands. */
    public $batch_progress;
    /** @var string What the run is doing right now, for the UI's status line:
     *  '' (the tool's own work), 'counting' (migrate's denominator pass),
     *  'rewriting' (offload's final one-pass DB URL rewrite) or 'verifying'. */
    public $phase;
    /** @var int `processed` at the moment this stretch of running started.
     *  Kept for record-shape compatibility with runs stored before the ETA
     *  moved to per-batch samples (batch_rates); no longer read for the
     *  rate itself. */
    public $stretch_base;
    /** @var int Denominator captured at start (0 = unknown). */
    public $total;
    /** @var bool Whether `total` is exact or still being counted (migrate). */
    public $total_complete;
    /** @var string[] Item-level failures, newest last, capped. */
    public $errors;
    /** @var int Total failures seen, including ones trimmed from `errors`. */
    public $error_count;
    /** @var float Unix timestamp (float) the run started. */
    public $started;
    /** @var float Unix timestamp (float) of the last write. */
    public $updated;
    /** @var string Run-level message (why it errored, mostly). */
    public $message;
    /** @var float[] Items-per-second of the most recent batches, capped at
     *  MAX_BATCH_SAMPLES. The ETA is a sliding average of these — a single
     *  slow batch (a big upload, a network hiccup) no longer swings the
     *  whole estimate the way the old whole-stretch average did, and the
     *  pace of a resumed run is not contaminated by work done before the
     *  pause (the list is cleared on resume). */
    public $batch_rates;

    private function __construct( array $data ) {
        $this->tool           = isset( $data['tool'] ) ? (string) $data['tool'] : '';
        $this->run_id         = isset( $data['run_id'] ) ? (string) $data['run_id'] : '';
        $this->state          = isset( $data['state'] ) ? (string) $data['state'] : self::STATE_PAUSED;
        $this->cursor         = isset( $data['cursor'] ) ? $data['cursor'] : 0;
        $this->processed      = isset( $data['processed'] ) ? (int) $data['processed'] : 0;
        $this->batch_progress = isset( $data['batch_progress'] ) ? (int) $data['batch_progress'] : 0;
        $this->phase          = isset( $data['phase'] ) ? (string) $data['phase'] : '';
        $this->stretch_base   = isset( $data['stretch_base'] ) ? (int) $data['stretch_base'] : 0;
        $this->total          = isset( $data['total'] ) ? (int) $data['total'] : 0;
        $this->total_complete = isset( $data['total_complete'] ) ? (bool) $data['total_complete'] : true;
        $this->errors         = ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) ? $data['errors'] : [];
        $this->error_count    = isset( $data['error_count'] ) ? (int) $data['error_count'] : count( $this->errors );
        $this->started        = isset( $data['started'] ) ? (float) $data['started'] : microtime( true );
        $this->updated        = isset( $data['updated'] ) ? (float) $data['updated'] : microtime( true );
        $this->message        = isset( $data['message'] ) ? (string) $data['message'] : '';
        // Stored samples are trusted as-is but capped and cleaned of junk
        // (older records, or a corrupted option value).
        $rates             = ( isset( $data['batch_rates'] ) && is_array( $data['batch_rates'] ) )
            ? array_values( array_filter( $data['batch_rates'], 'is_numeric' ) )
            : [];
        $this->batch_rates = array_slice( $rates, -self::MAX_BATCH_SAMPLES );
    }

    /**
     * Start a brand new run for a tool, replacing whatever record it had.
     *
     * @param string     $tool  Tool slug.
     * @param int        $total Denominator (0 when unknown yet).
     * @param int|string $cursor Starting cursor — 0/'' unless resuming.
     * @return ISXM_Job
     */
    public static function start( $tool, $total = 0, $cursor = 0 ) {
        $job = new self( [
            'tool'    => $tool,
            // Lowercase alphanumeric so it survives sanitize_key() on the way
            // back in from the client.
            'run_id'  => 'r' . base_convert( (string) time(), 10, 36 ) . wp_generate_password( 8, false, false ),
            'state'   => self::STATE_RUNNING,
            'cursor'  => $cursor,
            'started' => microtime( true ),
        ] );
        $job->total = (int) $total;
        $job->save();
        return $job;
    }

    /**
     * Load one tool's job record.
     *
     * @param string $tool Tool slug.
     * @return ISXM_Job|null
     */
    public static function get( $tool ) {
        $data = self::read_raw( $tool );
        return $data ? new self( $data ) : null;
    }

    /**
     * Every tool's job record, keyed by tool slug. Walks the known tool
     * list rather than a stored index — the set is fixed and small, and an
     * index would be one more shared thing to keep in sync.
     *
     * @return array<string,ISXM_Job>
     */
    public static function all() {
        self::migrate_legacy();

        $jobs = [];
        foreach ( array_keys( ISXM_Tools::tools() ) as $tool ) {
            $data = self::read_raw( $tool );
            if ( $data ) {
                $jobs[ $tool ] = new self( $data );
            }
        }
        return $jobs;
    }

    /**
     * The one job currently in the running state, if any. Only one may
     * exist — ISXM_Background refuses to start a second.
     *
     * @return ISXM_Job|null
     */
    public static function running() {
        foreach ( self::all() as $job ) {
            if ( $job->state === self::STATE_RUNNING ) {
                return $job;
            }
        }
        return null;
    }

    /**
     * Whether this run may be continued from where it stopped — i.e. it
     * stopped on purpose (or stalled) with work still left.
     *
     * @return bool
     */
    public function is_resumable() {
        return $this->state === self::STATE_PAUSED || $this->state === self::STATE_ERROR;
    }

    /**
     * Whether a running job has gone quiet long enough to be considered
     * abandoned (loopback died, PHP fatal, host killed the request).
     *
     * @return bool
     */
    public function is_stalled() {
        return $this->state === self::STATE_RUNNING
            && ( microtime( true ) - $this->updated ) > self::STALL_SECONDS;
    }

    /**
     * Record one batch's outcome. Kept as one call so `updated` can never
     * be forgotten — the healthcheck uses it to spot dead runs.
     *
     * A batch takes up to TIME_BUDGET seconds and the user can hit
     * "หยุด"/"ยกเลิก" at any point during it, but that no longer touches
     * this record — it writes a stop signal to a separate option, which the
     * runner reads between batches (see ISXM_Background::consume_signal).
     * So this write is free to record what the batch actually did without
     * racing the UI.
     *
     * @param int        $processed_in_batch Items processed this batch.
     * @param int|string $cursor             New resume cursor.
     * @param string[]   $errors             Item failures from this batch.
     * @param float      $batch_seconds      Wall time this batch took; used to
     *                                       feed the ETA's sliding window.
     */
    public function record_batch( $processed_in_batch, $cursor, array $errors = [], $batch_seconds = 0 ) {
        // The batch's own count is now final, so the partial figure the
        // progress ticks were publishing has to go — leaving it would add
        // the same items twice in every payload until the next batch.
        $this->batch_progress = 0;
        $this->processed  += (int) $processed_in_batch;
        $this->cursor      = $cursor;
        $this->error_count += count( $errors );

        // Feed the ETA's sliding window. Only batches that actually moved
        // the counter count as samples — a rewrite-phase batch (processed=0)
        // or an error batch must not dilute the upload pace.
        $processed_in_batch = (int) $processed_in_batch;
        if ( $processed_in_batch > 0 && $batch_seconds > 0 ) {
            $this->batch_rates[] = $processed_in_batch / (float) $batch_seconds;
            $this->batch_rates   = array_slice( $this->batch_rates, -self::MAX_BATCH_SAMPLES );
        }
        if ( ! empty( $errors ) ) {
            $this->errors = array_slice( array_merge( $this->errors, $errors ), -self::MAX_ERRORS );
        }
        $this->save();
    }

    /**
     * Publish how far the in-flight batch has got, without committing it —
     * the batch may still be interrupted, and only record_batch() decides
     * what actually counts. Written straight to the option (not through
     * save()) so a pause the UI wrote mid-batch is never overwritten.
     *
     * @param int $count Items finished so far in the current batch.
     */
    public function set_batch_progress( $count ) {
        $stored = self::read_raw( $this->tool );
        if ( ! $stored || ( $stored['run_id'] ?? '' ) !== $this->run_id ) {
            return;
        }
        $this->batch_progress     = (int) $count;
        $stored['batch_progress'] = (int) $count;
        $stored['updated']        = microtime( true );
        self::write_raw( $this->tool, $stored );
    }

    /**
     * Move the job to a terminal (or paused) state.
     *
     * @param string $state   One of the STATE_* constants.
     * @param string $message Optional run-level message.
     */
    public function finish( $state, $message = '' ) {
        $this->state          = $state;
        $this->message        = (string) $message;
        // Nothing is in flight any more; a leftover partial count would
        // keep inflating the displayed total.
        $this->batch_progress = 0;
        if ( $state === self::STATE_DONE ) {
            $this->phase = '';
        }
        $this->save();
    }

    /**
     * Persist. Always stamps `updated`.
     *
     * Refuses to overwrite a DIFFERENT run's record: a batch of an
     * abandoned run can still be in flight when the user starts the tool
     * again, and letting it land would rewind the new run's cursor and
     * progress to the old one's.
     *
     * @return bool Whether the record was written.
     */
    public function save() {
        $stored = self::read_raw( $this->tool );

        if ( $stored
            && ! empty( $stored['run_id'] )
            && $stored['run_id'] !== $this->run_id
            && (float) ( $stored['started'] ?? 0 ) > $this->started
        ) {
            return false;
        }

        $this->updated = microtime( true );
        self::write_raw( $this->tool, $this->to_array() );
        return true;
    }

    /**
     * Forget this tool's record entirely — the UI's "ยกเลิก" on a paused
     * card. Never touches the underlying work (the tools skip already-done
     * items on their own), only "where we stopped".
     */
    public function delete() {
        $stored = self::read_raw( $this->tool );
        // Only ever drop the run this instance actually represents — a
        // newer run started meanwhile must survive.
        if ( $stored && ! empty( $stored['run_id'] ) && $stored['run_id'] !== $this->run_id ) {
            return;
        }
        delete_option( self::OPTION_PREFIX . $this->tool );
        self::clear_signal( $this->tool );
    }

    /**
     * @return array
     */
    public function to_array() {
        return [
            'tool'           => $this->tool,
            'run_id'         => $this->run_id,
            'state'          => $this->state,
            'cursor'         => $this->cursor,
            'processed'      => $this->processed,
            'batch_progress' => $this->batch_progress,
            'phase'          => $this->phase,
            'stretch_base'   => $this->stretch_base,
            'total'          => $this->total,
            'total_complete' => $this->total_complete,
            'errors'         => $this->errors,
            'error_count'    => $this->error_count,
            'started'        => $this->started,
            'updated'        => $this->updated,
            'message'        => $this->message,
            'batch_rates'    => $this->batch_rates,
        ];
    }

    /**
     * Shape the admin UI polls. Percent/ETA are computed here so every
     * viewer of the same run sees identical numbers — the old client-side
     * estimate drifted per tab.
     *
     * @return array
     */
    public function to_payload() {
        // The batch in flight counts toward what the user sees, but never
        // toward what a resume would replay — that is `processed` alone.
        $live      = $this->processed + ( $this->state === self::STATE_RUNNING ? $this->batch_progress : 0 );
        $remaining = $this->total > 0 ? max( $this->total - $live, 0 ) : 0;
        // Rate is the sliding average of the last batches — see
        // $batch_rates. Hidden until the window has MIN_BATCH_SAMPLES
        // entries: an estimate built from one or two batches (the cold,
        // slow first ones) is noise and misleads more than it helps.
        $rate = 0;
        if ( count( $this->batch_rates ) >= self::MIN_BATCH_SAMPLES ) {
            $rate = array_sum( $this->batch_rates ) / count( $this->batch_rates );
        }

        // Clamp below 100% until the run actually reports done: the
        // denominator is a snapshot and new uploads can land mid-run.
        $percent = 0;
        if ( $this->state === self::STATE_DONE ) {
            $percent = 100;
        } elseif ( $this->total > 0 ) {
            $percent = (int) min( floor( $live / max( $this->total, $live ) * 100 ), 99 );
        }

        $now          = microtime( true );
        $elapsed_end  = ( $this->state === self::STATE_RUNNING ) ? $now : $this->updated;
        $elapsed_secs = ( $this->started > 0 ) ? (int) max( 0, round( $elapsed_end - $this->started ) ) : 0;

        return [
            'tool'            => $this->tool,
            'run_id'          => $this->run_id,
            'state'           => $this->state,
            'phase'           => $this->phase,
            'processed'       => $live,
            // A completed run drained the whole set by definition, so its
            // own count IS the total — a snapshot denominator taken before
            // the run would otherwise print "17,246/17,279" beside a 100% bar.
            // 0 keeps its meaning of "not known yet" (the UI then shows an
            // indeterminate bar) — only a real denominator is clamped up.
            'total'           => $this->state === self::STATE_DONE
                ? $live
                : ( $this->total > 0 ? max( $this->total, $live ) : 0 ),
            'total_complete'  => $this->total_complete,
            'percent'         => $percent,
            'errors'          => $this->errors,
            'error_count'     => $this->error_count,
            'message'         => $this->message,
            'stalled'         => $this->is_stalled(),
            'resumable'       => $this->is_resumable(),
            'started'         => (int) round( $this->started ),
            'elapsed_seconds' => $elapsed_secs,
            'eta_seconds'     => ( $rate > 0 && $remaining > 0 && $this->state === self::STATE_RUNNING )
                ? (int) round( $remaining / $rate )
                : 0,
        ];
    }

    /**
     * How long a finished/cancelled record is kept. Long enough that the
     * result is still on screen when you come back to the tab, short enough
     * that a card doesn't sit at "เสร็จสิ้น ✓" for days. Paused and errored
     * records are NEVER pruned — they hold a cursor someone may still want
     * to resume from.
     */
    const TERMINAL_TTL = 1800;

    /**
     * Drop finished/cancelled records that have aged out.
     *
     * @return void
     */
    public static function prune() {
        $now = microtime( true );

        foreach ( array_keys( ISXM_Tools::tools() ) as $tool ) {
            $data = self::read_raw( $tool );
            if ( ! $data ) {
                continue;
            }
            $state = isset( $data['state'] ) ? $data['state'] : '';
            if ( $state !== self::STATE_DONE && $state !== self::STATE_CANCELLED ) {
                continue;
            }
            if ( $now - (float) ( $data['updated'] ?? 0 ) > self::TERMINAL_TTL ) {
                delete_option( self::OPTION_PREFIX . $tool );
                self::clear_signal( $tool );
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Stop signals
     *
     * Written by the UI, consumed by the runner between batches. A separate
     * option per tool, so a pause/cancel and the runner's progress writes
     * can never overwrite each other.
     * ------------------------------------------------------------------ */

    /**
     * @param string $tool
     * @return string SIGNAL_* constant, or '' when nothing is pending.
     */
    public static function signal( $tool ) {
        $key = self::SIGNAL_PREFIX . $tool;
        wp_cache_delete( $key, 'options' );
        $signal = get_option( $key, '' );
        return ( $signal === self::SIGNAL_PAUSE || $signal === self::SIGNAL_CANCEL ) ? $signal : '';
    }

    /**
     * @param string $tool
     * @param string $signal SIGNAL_PAUSE or SIGNAL_CANCEL.
     */
    public static function set_signal( $tool, $signal ) {
        if ( $signal !== self::SIGNAL_PAUSE && $signal !== self::SIGNAL_CANCEL ) {
            return;
        }
        update_option( self::SIGNAL_PREFIX . $tool, $signal, false );
    }

    /**
     * @param string $tool
     */
    public static function clear_signal( $tool ) {
        delete_option( self::SIGNAL_PREFIX . $tool );
    }

    /* ---------------------------------------------------------------------
     * Storage primitives
     * ------------------------------------------------------------------ */

    /**
     * Read one tool's record straight from the database.
     *
     * The runner is a single request looping for tens of seconds while
     * pause/cancel arrive on other requests. get_option() caches per
     * request, so without dropping the entry the runner would keep reading
     * the state it saw when it started and would never notice a stop.
     *
     * @param string $tool
     * @return array|null
     */
    private static function read_raw( $tool ) {
        self::migrate_legacy();

        $key = self::OPTION_PREFIX . $tool;
        wp_cache_delete( $key, 'options' );
        $data = get_option( $key, null );
        return is_array( $data ) ? $data : null;
    }

    /**
     * @param string $tool
     * @param array  $data
     */
    private static function write_raw( $tool, array $data ) {
        update_option( self::OPTION_PREFIX . $tool, $data, false );
    }

    /**
     * Move a pre-0.2.1 install's single `isxs_jobs` array into one option
     * per tool. Runs at most once — the legacy option is deleted after the
     * copy, and the static flag keeps the check off the hot path within a
     * request.
     */
    private static function migrate_legacy() {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        $legacy = get_option( self::LEGACY_OPTION_KEY, null );
        if ( ! is_array( $legacy ) ) {
            return;
        }
        foreach ( $legacy as $tool => $data ) {
            if ( is_array( $data ) && ISXM_Tools::is_known_tool( $tool ) ) {
                update_option( self::OPTION_PREFIX . $tool, $data, false );
            }
        }
        delete_option( self::LEGACY_OPTION_KEY );
    }
}
