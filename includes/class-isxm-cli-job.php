<?php
/**
 * Inspect and control the server-side bulk jobs the admin UI runs.
 *
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * The other CLI commands (`wp isxm offload`, `migrate`, …) each run their own
 * loop in the foreground. That is the right shape for a one-off maintenance
 * run, but it is invisible to — and unsynchronised with — the jobs the admin
 * UI starts. These commands work on the very same job records the UI does,
 * which makes them the right tool when you want to:
 *
 *   - see what a site is doing right now, without opening wp-admin
 *   - stop or resume a run someone started in the browser
 *   - drive a run to completion on a host where loopback requests are
 *     blocked (`wp isxm job run` from a real cron beats leaving a tab open)
 *   - script a nightly offload that respects the single-job rule
 *
 * @since 0.2.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;

class ISXM_CLI_Job {

    /**
     * List every tool and the state of its run.
     *
     * Tools with no record at all are shown as `idle` so the output is a
     * complete picture of the site rather than only what happens to have run.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp isxm job list
     *     wp isxm job list --format=json
     *
     * @subcommand list
     */
    public function list_( $args, $assoc_args ) {
        $jobs = ISXM_Job::all();
        $rows = [];

        foreach ( ISXM_Tools::tools() as $tool => $label ) {
            $job = isset( $jobs[ $tool ] ) ? $jobs[ $tool ] : null;

            if ( ! $job ) {
                $rows[] = [
                    'tool'      => $tool,
                    'label'     => $label,
                    'state'     => 'idle',
                    'progress'  => '',
                    'percent'   => '',
                    'errors'    => '',
                    'pending'   => '',
                    'updated'   => '',
                ];
                continue;
            }

            $p = $job->to_payload();
            // A run whose driver has gone quiet is still recorded as
            // `running`; saying so without the qualifier would be a lie the
            // operator then has to discover the hard way.
            $state = $p['stalled'] ? $p['state'] . ' (stalled)' : $p['state'];

            $rows[] = [
                'tool'     => $tool,
                'label'    => $label,
                'state'    => $state,
                'progress' => $p['total'] > 0
                    ? number_format_i18n( $p['processed'] ) . '/' . number_format_i18n( $p['total'] )
                    : number_format_i18n( $p['processed'] ),
                'percent'  => $p['total'] > 0 ? $p['percent'] . '%' : '',
                'errors'   => $p['error_count'] > 0 ? number_format_i18n( $p['error_count'] ) : '',
                'pending'  => ISXM_Job::signal( $tool ),
                'updated'  => gmdate( 'Y-m-d H:i:s', (int) $job->updated ) . ' UTC',
            ];
        }

        WP_CLI\Utils\format_items(
            $assoc_args['format'] ?? 'table',
            $rows,
            [ 'tool', 'state', 'progress', 'percent', 'errors', 'pending', 'updated' ]
        );
    }

    /**
     * Start a tool through the job system.
     *
     * Obeys exactly the same rules the admin UI does: one job at a time,
     * every precheck (including a live probe of the destination bucket), and
     * a start lock so two callers cannot both claim the slot.
     *
     * ## OPTIONS
     *
     * <tool>
     * : Which tool to run. One of: offload, retry_failed, download, remove,
     *   migrate, wc_downloads, backfill.
     *
     * [--resume]
     * : Continue the existing cursor instead of starting a fresh scan. Only
     *   meaningful for a run that is stopped or errored.
     *
     * [--watch]
     * : Drive the run in the foreground and print progress until it settles,
     *   instead of handing it to the background runner and returning.
     *
     * ## EXAMPLES
     *
     *     wp isxm job start offload
     *     wp isxm job start offload --resume
     *     wp isxm job start migrate --watch
     */
    public function start( $args, $assoc_args ) {
        $tool   = $args[0];
        $resume = ! empty( $assoc_args['resume'] );

        $result = ISXM_Background::start_job( $tool, $resume );
        if ( isset( $result['error'] ) ) {
            WP_CLI::error( $result['error'] );
        }

        WP_CLI::success( sprintf(
            $resume ? __( 'Resumed "%s"', 'insightx-offload' ) : __( 'Started "%s"', 'insightx-offload' ),
            ISXM_Tools::tool_label( $tool )
        ) );

        if ( ! empty( $assoc_args['watch'] ) ) {
            $this->watch( $tool );
            return;
        }

        if ( ! ISXM_Background::loopback_available() ) {
            WP_CLI::warning(
                __( 'This site cannot make loopback requests to itself — the job will not advance in the background. Run `wp isxm job run` (or add it to cron) to drive it to completion', 'insightx-offload' )
            );
        }
    }

    /**
     * Stop a running job, keeping its cursor so it can be resumed exactly.
     *
     * The stop is a request, not an interrupt: the batch already in flight
     * finishes and its work is recorded first, so nothing is lost and nothing
     * is repeated on resume. That means the state may still read `running`
     * for a few seconds after this returns.
     *
     * ## OPTIONS
     *
     * <tool>
     * : Which tool to stop.
     *
     * ## EXAMPLES
     *
     *     wp isxm job pause offload
     */
    public function pause( $args, $assoc_args ) {
        $tool = $args[0];
        $this->assert_tool( $tool );

        if ( ! ISXM_Background::pause_job( $tool ) ) {
            WP_CLI::warning( sprintf( __( '"%s" is not running', 'insightx-offload' ), ISXM_Tools::tool_label( $tool ) ) );
            return;
        }
        WP_CLI::success( sprintf(
            __( 'Stop requested for "%s" — the batch in progress finishes first, then it stops (run `wp isxm job list` to check the status)', 'insightx-offload' ),
            ISXM_Tools::tool_label( $tool )
        ) );
    }

    /**
     * Resume a stopped or errored run from where it left off.
     *
     * ## OPTIONS
     *
     * <tool>
     * : Which tool to resume.
     *
     * [--watch]
     * : Drive it in the foreground and print progress until it settles.
     *
     * ## EXAMPLES
     *
     *     wp isxm job resume offload
     */
    public function resume( $args, $assoc_args ) {
        $tool = $args[0];
        $this->assert_tool( $tool );

        $job = ISXM_Job::get( $tool );
        if ( ! $job || ! $job->is_resumable() ) {
            WP_CLI::error( sprintf(
                __( '"%s" has no checkpoint to resume — use `wp isxm job start %s` to start over', 'insightx-offload' ),
                ISXM_Tools::tool_label( $tool ),
                $tool
            ) );
        }

        $assoc_args['resume'] = true;
        $this->start( $args, $assoc_args );
    }

    /**
     * Discard a run's cursor.
     *
     * Work already finished is never undone — every tool skips items it has
     * already handled — so this only throws away "where we stopped". The next
     * start rescans from the beginning to find the remaining work.
     *
     * ## OPTIONS
     *
     * <tool>
     * : Which tool to cancel.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp isxm job cancel offload
     */
    public function cancel( $args, $assoc_args ) {
        $tool = $args[0];
        $this->assert_tool( $tool );

        $job = ISXM_Job::get( $tool );
        if ( ! $job ) {
            WP_CLI::warning( sprintf( __( '"%s" has no record', 'insightx-offload' ), ISXM_Tools::tool_label( $tool ) ) );
            return;
        }

        if ( $job->processed > 0 ) {
            WP_CLI::confirm( sprintf(
                __( 'Cancel "%s"? %s items processed so far — that work stays intact, but the checkpoint will be deleted', 'insightx-offload' ),
                ISXM_Tools::tool_label( $tool ),
                number_format_i18n( $job->processed )
            ), $assoc_args );
        }

        ISXM_Background::cancel_job( $tool );
        WP_CLI::success( sprintf( __( 'Cancelled "%s"', 'insightx-offload' ), ISXM_Tools::tool_label( $tool ) ) );
    }

    /**
     * Drive the currently running job to completion in the foreground.
     *
     * This is the command for a host that cannot make loopback requests to
     * itself: instead of keeping a browser tab open, put it in a real cron
     * entry. It is safe to run repeatedly and safe to run while the loopback
     * runner is also alive — the runner lock means only one of them processes
     * a batch at a time, and the other simply returns.
     *
     * ## OPTIONS
     *
     * [--once]
     * : Process a single runner slice and return, rather than looping until
     *   the job settles. Useful from a per-minute cron.
     *
     * ## EXAMPLES
     *
     *     wp isxm job run
     *     wp isxm job run --once
     *
     *     # from crontab, every five minutes, keep whatever is running moving:
     *     #   0,5,10,15,20,25,30,35,40,45,50,55 * * * * \
     *     #     cd /var/www && wp isxm job run --once --quiet
     */
    public function run( $args, $assoc_args ) {
        $job = ISXM_Job::running();
        if ( ! $job ) {
            WP_CLI::success( __( 'No job is running — nothing to do', 'insightx-offload' ) );
            return;
        }

        WP_CLI::log( sprintf( __( 'Driving job "%s"…', 'insightx-offload' ), ISXM_Tools::tool_label( $job->tool ) ) );

        if ( ! empty( $assoc_args['once'] ) ) {
            ISXM_Background::run();
            $this->report( $job->tool );
            return;
        }

        $this->watch( $job->tool );
    }

    /**
     * Drop every tool's job record at once.
     *
     * The equivalent of the admin UI's "Reset all in Tools". Use it when
     * the destination bucket changed — a cursor into the previous bucket's
     * scan means nothing against the new one — or to clear a wedged state.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp isxm job reset --yes
     */
    public function reset( $args, $assoc_args ) {
        WP_CLI::confirm( __( 'Delete the records of every job? (work already done is not touched)', 'insightx-offload' ), $assoc_args );

        $dropped = ISXM_Background::reset_jobs();
        WP_CLI::success( sprintf( __( 'Deleted %d records', 'insightx-offload' ), $dropped ) );
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Drive a job until it stops being `running`, printing each change.
     *
     * ISXM_Background::run() returns as soon as its own time budget is spent,
     * so this calls it repeatedly. It also picks up a job the loopback runner
     * is already driving — the lock makes that a cheap no-op — which is why
     * the loop still has to re-read the record rather than trusting its own
     * return value.
     *
     * @param string $tool
     */
    private function watch( $tool ) {
        $last = '';

        while ( true ) {
            $job = ISXM_Job::get( $tool );
            if ( ! $job || $job->state !== ISXM_Job::STATE_RUNNING ) {
                break;
            }

            ISXM_Background::run();

            $job = ISXM_Job::get( $tool );
            if ( ! $job ) {
                break;
            }

            $p    = $job->to_payload();
            $line = $p['total'] > 0
                ? sprintf( '  %s%%  %s/%s', $p['percent'], number_format_i18n( $p['processed'] ), number_format_i18n( $p['total'] ) )
                : sprintf( __( '  %s items', 'insightx-offload' ), number_format_i18n( $p['processed'] ) );
            if ( $p['error_count'] > 0 ) {
                $line .= sprintf( __( '  (%s failed)', 'insightx-offload' ), number_format_i18n( $p['error_count'] ) );
            }
            // Only print when something actually moved — a lock-contended
            // slice can return without having processed anything.
            if ( $line !== $last ) {
                WP_CLI::log( $line );
                $last = $line;
            }

            if ( $job->state !== ISXM_Job::STATE_RUNNING ) {
                break;
            }
            // The runner returned because its budget ran out, not because a
            // batch is pending; a short pause keeps a lock-contended loop
            // from spinning on the database.
            usleep( 200000 );
        }

        $this->report( $tool );
    }

    /**
     * Final word on a run: what state it settled in and why.
     *
     * @param string $tool
     */
    private function report( $tool ) {
        $job = ISXM_Job::get( $tool );
        if ( ! $job ) {
            WP_CLI::success( __( 'The job was cancelled', 'insightx-offload' ) );
            return;
        }

        $p       = $job->to_payload();
        $summary = sprintf(
            __( '%s — %s items processed%s', 'insightx-offload' ),
            ISXM_Tools::tool_label( $tool ),
            number_format_i18n( $p['processed'] ),
            $p['error_count'] > 0 ? sprintf( __( ', %s failed', 'insightx-offload' ), number_format_i18n( $p['error_count'] ) ) : ''
        );

        switch ( $job->state ) {
            case ISXM_Job::STATE_DONE:
                WP_CLI::success( __( 'Done: ', 'insightx-offload' ) . $summary );
                break;
            case ISXM_Job::STATE_PAUSED:
                WP_CLI::log( __( 'Paused: ', 'insightx-offload' ) . $summary );
                WP_CLI::log( sprintf( __( 'Resume with: wp isxm job resume %s', 'insightx-offload' ), $tool ) );
                break;
            case ISXM_Job::STATE_ERROR:
                WP_CLI::warning( __( 'Stopped on error: ', 'insightx-offload' ) . $summary );
                if ( $job->message !== '' ) {
                    WP_CLI::log( '  ' . $job->message );
                }
                WP_CLI::log( sprintf( __( 'Fix it, then resume with: wp isxm job resume %s', 'insightx-offload' ), $tool ) );
                break;
            default:
                WP_CLI::log( __( 'Still running: ', 'insightx-offload' ) . $summary );
        }

        // The per-item failures are the actionable part of a run that
        // "finished" with errors, so show a sample rather than making the
        // operator go find them in wp-admin.
        if ( $p['error_count'] > 0 && ! empty( $p['errors'] ) ) {
            WP_CLI::log( __( 'Sample failures:', 'insightx-offload' ) );
            foreach ( array_slice( $p['errors'], -5 ) as $err ) {
                WP_CLI::log( '  ' . $err );
            }
        }
    }

    /**
     * @param string $tool
     */
    private function assert_tool( $tool ) {
        if ( ! ISXM_Tools::is_known_tool( $tool ) ) {
            WP_CLI::error( sprintf(
                __( 'Unknown tool "%s" — choose from: %s', 'insightx-offload' ),
                $tool,
                implode( ', ', array_keys( ISXM_Tools::tools() ) )
            ) );
        }
    }
}

WP_CLI::add_command( 'isxm job', 'ISXM_CLI_Job' );
