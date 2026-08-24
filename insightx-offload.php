<?php
/**
 * Plugin Name: InsightX Offload
 * Plugin URI:  https://insightx.in.th/
 * Version:     0.2.5
 * Author:      InsightX
 * Author URI:  https://www.insightx.in.th
 * Text Domain: insightx-offload
 * Description: Offload media ไปยัง S3-compatible storage (Minio, Amazon S3, Cloudflare R2, DigitalOcean Spaces) พร้อมระบบ URL rewrite, เปลี่ยน/ย้าย provider, Assets Pull (เสิร์ฟ CSS/JS ผ่าน CDN), bulk tools และ diagnostic — โดย InsightX
 * License:     GPLv3 or later
 *
 * Copyright (C) 2026 InsightX. Original work — not derived from any third-party plugin.
 * InsightX Offload is a fork of InsightX Storage (same DB identifiers: options,
 * postmeta, ledger table, cron hooks, AJAX actions), so it can be swapped in
 * for the original plugin without re-offloading anything. Deactivate the
 * original before activating this one — running both would double-hook the
 * same data.
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ISXM_PLUGIN_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'ISXM_PLUGIN_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'ISXM_PLUGIN_VERSION', '0.2.5' );

/*
 * GitHub update checker.
 *
 * The plugin is not on wordpress.org, so nothing would otherwise tell
 * WordPress that a newer version exists — releases were built and tagged,
 * and every site kept sitting on whatever was installed by hand.
 *
 * enableReleaseAssets() points the checker at the zip attached to each
 * GitHub Release (built by .github/workflows/release.yml from the tag),
 * not at a tarball of the branch, so what a site installs is exactly what
 * was released. The repository is public; no authentication is involved.
 */
require_once ISXM_PLUGIN_DIR . 'libs/plugin-update-checker/plugin-update-checker.php';

$isxm_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/InsightX-th/insightx-offload',
    __FILE__,
    'insightx-offload'
);
$isxm_update_checker->getVcsApi()->enableReleaseAssets();

$isxm_files_to_load = [
    'includes/class-isxm-crypto.php',
    'includes/class-isxm-connections.php',
    'includes/class-isxm-settings.php',
    'includes/class-isxm-client.php',
    'includes/class-isxm-db-rewriter.php',
    'includes/class-isxm-offload.php',
    'includes/class-isxm-migrate.php',
    'includes/class-isxm-sync.php',
    'includes/class-isxm-items.php',
    'includes/class-isxm-job.php',
    'includes/class-isxm-background.php',
    'includes/class-isxm-wc-downloads.php',
    'includes/class-isxm-assets.php',
    'includes/class-isxm-tools.php',
    'includes/class-isxm-admin.php',
    'includes/class-isxm-media-library.php',
    'includes/class-isxm-cli.php',
    'includes/class-isxm-cli-connection.php',
    'includes/class-isxm-cli-job.php',
];

foreach ( $isxm_files_to_load as $isxm_file ) {
    if ( file_exists( ISXM_PLUGIN_DIR . $isxm_file ) ) {
        require_once ISXM_PLUGIN_DIR . $isxm_file;
    }
}

/**
 * Custom error logger for InsightX Offload (forked from InsightX Storage's
 * logger — same option sink `isxs_log`, so historical entries carry over).
 *
 * Two sinks: WP_DEBUG's error_log (dev) and a persistent option-based log
 * (always on) so important events — sync mismatches, failed remote deletes,
 * offload errors on libraries too big to scroll — survive even when
 * WP_DEBUG is off. The persistent log is capped, and consecutive identical
 * messages collapse into one entry with a counter instead of flooding it.
 *
 * @param mixed $message The message to log.
 */
if ( ! function_exists( 'isxm_log_error' ) ) {
    function isxm_log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            if ( is_array( $message ) || is_object( $message ) ) {
                error_log( '[InsightX Offload Debug] ' . print_r( $message, true ) );
            } else {
                error_log( '[InsightX Offload Debug] ' . $message );
            }
        }

        $text = ( is_array( $message ) || is_object( $message ) ) ? print_r( $message, true ) : (string) $message;
        if ( $text === '' ) {
            return;
        }

        $log = get_option( 'isxs_log', [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        $now = time();
        $last_index = count( $log ) - 1;
        // Collapse repeats within the same hour into one counted entry.
        if ( $last_index >= 0 && isset( $log[ $last_index ]['message'] ) && $log[ $last_index ]['message'] === $text ) {
            $prev_time = strtotime( (string) $log[ $last_index ]['time'] );
            if ( $prev_time !== false && ( $now - $prev_time ) < 3600 ) {
                $log[ $last_index ]['count'] = (int) ( $log[ $last_index ]['count'] ?? 1 ) + 1;
                $log[ $last_index ]['time']  = gmdate( 'Y-m-d H:i:s', $now );
                update_option( 'isxs_log', $log, false );
                return;
            }
        }
        $log[] = [
            'time'    => gmdate( 'Y-m-d H:i:s', $now ),
            'message' => mb_substr( $text, 0, 1000 ),
            'count'   => 1,
        ];
        $log = array_slice( $log, -200 );
        update_option( 'isxs_log', $log, false );
    }
}

// Deferred to plugins_loaded — this file loads before wp-includes/pluggable.php
// (wp_salt(), used by ISXM_Crypto), so calling anything that decrypts a
// secret here at file-scope would fatal. plugins_loaded also runs after
// isxm_log_error() above is defined, which maybe_migrate_legacy() can call.
add_action( 'plugins_loaded', function () {
    // Drop-in guard: this fork shares every DB identifier with InsightX
    // Storage (options, postmeta, ledger table, cron hooks, AJAX actions).
    // Running both at once would double-hook the same data and double-run
    // background jobs — refuse to boot and tell the admin which plugin to
    // deactivate instead.
    if ( defined( 'ISXS_PLUGIN_VERSION' ) || class_exists( 'ISXS_Offload' ) || class_exists( 'ISXS_Tools' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>InsightX Offload:</strong> ตรวจพบว่า InsightX Storage ยังเปิดอยู่ — ทั้งสองปลั๊กอินใช้ข้อมูลชุดเดียวกัน (options / postmeta / ตาราง ledger) ห้ามรันพร้อมกัน กรุณา <strong>ปิด (Deactivate) InsightX Storage</strong> ก่อน แล้วเปิด InsightX Offload ใหม่ ข้อมูลทั้งหมดจะอ่านต่อได้ทันที ไม่ต้อง offload ใหม่</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static Thai copy, no user input
        } );
        return;
    }

    if ( class_exists( 'ISXM_Connections' ) ) {
        ISXM_Connections::maybe_migrate_legacy();
    }
    // Creates/upgrades the offload ledger table. One option read when the
    // schema is already current, so it is safe on every request — and it
    // has to be here rather than on activation, because an update
    // installed over an active plugin never fires the activation hook.
    if ( class_exists( 'ISXM_Items' ) ) {
        ISXM_Items::maybe_install();
    }
    // v0.1.8 shipped a daily auto-check cron; the feature is gone (the UI
    // keeps only the manual sync button) — clear any leftover event once
    // so it doesn't keep firing as an orphaned no-op.
    if ( get_option( 'isxs_sync_auto_check_cleaned' ) !== '1' ) {
        wp_clear_scheduled_hook( 'isxs_sync_daily_check' );
        update_option( 'isxs_sync_auto_check_cleaned', '1', false );
    }
    // v0.2.0 moved bulk runs onto server-side job records (isxs_jobs); the
    // browser-driven progress option they replaced is dead weight in the
    // options table on every upgraded site.
    if ( get_option( 'isxs_bulk_progress_cleaned' ) !== '1' ) {
        delete_option( 'isxs_bulk_progress' );
        update_option( 'isxs_bulk_progress_cleaned', '1', false );
    }

    // Boot the plugin proper. Needs to live here (not file-scope) so the
    // drop-in guard above can veto it when the original plugin is active.
    if ( class_exists( 'ISXM_Offload' ) ) new ISXM_Offload();
    if ( class_exists( 'ISXM_Tools' ) )   new ISXM_Tools();
    // Must load on every request, not just admin ones: the loopback runner
    // arrives at admin-ajax.php unauthenticated, and WP-Cron fires the
    // healthcheck from the front end.
    if ( class_exists( 'ISXM_Background' ) ) new ISXM_Background();
    if ( class_exists( 'ISXM_Admin' ) )   new ISXM_Admin();
    if ( is_admin() && class_exists( 'ISXM_Media_Library' ) ) new ISXM_Media_Library();
    if ( class_exists( 'ISXM_Assets' ) )  new ISXM_Assets();
} );

register_uninstall_hook( __FILE__, 'isxm_plugin_uninstall' );

function isxm_plugin_uninstall() {
    delete_option( 'isxs_settings' );
    delete_option( 'isxs_connections' );
    delete_option( 'isxs_connection_status' );
    delete_option( 'isxs_connections_migrated' );
    delete_option( 'isxs_conn_status' );
    delete_option( 'isxs_source_conn_status' );
    delete_option( 'isxs_bulk_progress' );
    delete_option( 'isxs_bulk_progress_cleaned' );
    delete_option( 'isxs_jobs' );
    delete_option( 'isxs_items_db_version' );
    delete_option( 'isxs_items_backfilled' );
    delete_option( 'isxs_bg_token' );
    delete_option( 'isxs_log' );
    delete_option( 'isxs_sync_last_check' );
    wp_clear_scheduled_hook( 'isxs_sync_daily_check' );
    delete_transient( 'isxs_stats_cache' );
    delete_transient( 'isxs_bg_lock' );
    delete_transient( 'isxs_bg_loopback' );
    wp_clear_scheduled_hook( 'isxs_bg_healthcheck' );
    delete_metadata( 'post', 0, '_isxs_offload', '', true );
    delete_metadata( 'post', 0, '_isxs_offload_error', '', true );
    // Written by the Sync tool's cleanup; it was the one tracking meta the
    // uninstall left behind on every attachment it had flagged.
    delete_metadata( 'post', 0, '_isxs_data_loss', '', true );

    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}isxs_items" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- table name from $wpdb->prefix, uninstall cleanup
}
