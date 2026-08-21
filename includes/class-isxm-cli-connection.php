<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_CLI_Connection — WP-CLI commands for managing InsightX Storage
 * provider connections (the same data the "การเชื่อมต่อ" admin tab edits).
 *
 *     wp isxm connection list
 *     wp isxm connection get <provider>
 *     wp isxm connection set <provider> [--endpoint=<url>] [--region=<region>] [--bucket=<bucket>] [--access-key=<key>] [--secret-key=<key>] [--path-style=<0|1>] [--public-acl=<0|1>]
 *     wp isxm connection test <provider>
 *     wp isxm connection remove <provider>
 *
 * @since 0.1.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;

class ISXM_CLI_Connection {

    /**
     * List every provider with its configured/tested status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, csv, json, yaml, count.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp isxm connection list
     *
     * @subcommand list
     */
    public function list_( $args, $assoc_args ) {
        $rows = [];
        foreach ( ISXM_Connections::providers() as $slug => $meta ) {
            $configured = ISXM_Connections::is_configured( $slug );
            $status     = ISXM_Connections::status( $slug );
            $c          = ISXM_Connections::get( $slug );
            $rows[] = [
                'provider'   => $slug,
                'label'      => $meta['label'],
                'configured' => $configured ? 'yes' : 'no',
                'status'     => $configured ? $status['state'] : '—',
                'bucket'     => $c['bucket'] !== '' ? $c['bucket'] : '—',
            ];
        }
        WP_CLI\Utils\format_items(
            isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
            $rows,
            [ 'provider', 'label', 'configured', 'status', 'bucket' ]
        );
    }

    /**
     * Show one provider's connection details. The secret key itself is
     * never printed — only whether one is set.
     *
     * ## OPTIONS
     *
     * <provider>
     * : Provider slug — aws, minio, garage, r2, spaces, gcs, or custom.
     *
     * ## EXAMPLES
     *
     *     wp isxm connection get r2
     */
    public function get( $args, $assoc_args ) {
        $slug   = $this->validate_provider( $args[0] );
        $c      = ISXM_Connections::get( $slug );
        $status = ISXM_Connections::status( $slug );

        WP_CLI\Utils\format_items( 'table', [
            [ 'field' => 'endpoint',    'value' => $c['endpoint'] !== '' ? $c['endpoint'] : '(default)' ],
            [ 'field' => 'region',      'value' => $c['region'] ],
            [ 'field' => 'bucket',      'value' => $c['bucket'] !== '' ? $c['bucket'] : '(not set)' ],
            [ 'field' => 'access_key',  'value' => $c['access_key'] !== '' ? substr( $c['access_key'], 0, 4 ) . '••••' : '(not set)' ],
            [ 'field' => 'secret_key',  'value' => $c['secret_key'] !== '' ? '(set)' : '(not set)' ],
            [ 'field' => 'path_style',  'value' => $c['path_style'] ? 'yes' : 'no' ],
            [ 'field' => 'public_acl',  'value' => $c['send_public_acl'] ? 'yes' : 'no' ],
            [ 'field' => 'configured',  'value' => ISXM_Connections::is_configured( $slug ) ? 'yes' : 'no' ],
            [ 'field' => 'last_test',   'value' => $status['state'] . ( $status['message'] !== '' ? ' — ' . $status['message'] : '' ) ],
        ], [ 'field', 'value' ] );
    }

    /**
     * Set one provider's connection fields. Omitted flags keep their
     * current value; pass an empty string to clear a field.
     *
     * ## OPTIONS
     *
     * <provider>
     * : Provider slug — aws, minio, garage, r2, spaces, gcs, or custom.
     *
     * [--endpoint=<url>]
     * : e.g. https://minio.example.com — leave unset for AWS S3's computed default.
     *
     * [--region=<region>]
     *
     * [--bucket=<bucket>]
     *
     * [--access-key=<key>]
     *
     * [--secret-key=<key>]
     * : Stored encrypted (AES-256-GCM, authenticated tag).
     *
     * [--path-style=<0|1>]
     *
     * [--public-acl=<0|1>]
     * : Send x-amz-acl: public-read on upload.
     *
     * ## EXAMPLES
     *
     *     wp isxm connection set r2 --endpoint=https://ACCOUNTID.r2.cloudflarestorage.com --region=auto --bucket=my-bucket --access-key=AKID --secret-key=SECRET --path-style=1
     *     wp isxm connection set aws --bucket=my-app-media --access-key=AKID --secret-key=SECRET
     */
    public function set( $args, $assoc_args ) {
        $slug    = $this->validate_provider( $args[0] );
        $current = ISXM_Connections::get( $slug );

        $config = [
            'endpoint'        => array_key_exists( 'endpoint', $assoc_args ) ? esc_url_raw( trim( $assoc_args['endpoint'] ) ) : $current['endpoint'],
            'region'          => array_key_exists( 'region', $assoc_args ) ? sanitize_text_field( $assoc_args['region'] ) : $current['region'],
            'bucket'          => array_key_exists( 'bucket', $assoc_args ) ? sanitize_text_field( $assoc_args['bucket'] ) : $current['bucket'],
            'access_key'      => array_key_exists( 'access-key', $assoc_args ) ? sanitize_text_field( $assoc_args['access-key'] ) : $current['access_key'],
            'secret_key'      => array_key_exists( 'secret-key', $assoc_args ) ? trim( $assoc_args['secret-key'] ) : $current['secret_key'],
            'path_style'      => array_key_exists( 'path-style', $assoc_args ) ? (bool) (int) $assoc_args['path-style'] : $current['path_style'],
            'send_public_acl' => array_key_exists( 'public-acl', $assoc_args ) ? (bool) (int) $assoc_args['public-acl'] : $current['send_public_acl'],
        ];

        ISXM_Connections::save_one( $slug, $config );
        WP_CLI::success( sprintf( 'Connection "%s" saved.', $slug ) );
    }

    /**
     * Test a provider's currently stored connection (same check the
     * Connections tab's "บันทึก" button runs, without changing any fields).
     *
     * ## OPTIONS
     *
     * <provider>
     * : Provider slug — aws, minio, garage, r2, spaces, gcs, or custom.
     *
     * ## EXAMPLES
     *
     *     wp isxm connection test r2
     */
    public function test( $args, $assoc_args ) {
        $slug = $this->validate_provider( $args[0] );

        if ( ! ISXM_Connections::is_configured( $slug ) ) {
            WP_CLI::error( 'Not configured yet — set bucket/access key/secret key first (wp isxm connection set).' );
        }

        $client = new ISXM_Client( ISXM_Connections::get( $slug ) );
        $result = $client->test_connection();

        if ( is_wp_error( $result ) ) {
            ISXM_Connections::save_status( $slug, 'error', $result->get_error_message() );
            WP_CLI::error( $result->get_error_message() );
        }

        ISXM_Connections::save_status( $slug, 'ok', 'เชื่อมต่อ bucket สำเร็จ' );
        WP_CLI::success( 'Connected successfully.' );
    }

    /**
     * Clear a provider's connection (endpoint/region/bucket/keys reset back
     * to empty/defaults). Does not affect other providers, and doesn't
     * change which provider is currently selected as destination/source —
     * do that from the Storage/Migrate tabs (or re-run `set` here) after
     * clearing, if needed.
     *
     * ## OPTIONS
     *
     * <provider>
     * : Provider slug — aws, minio, garage, r2, spaces, gcs, or custom.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp isxm connection remove r2 --yes
     */
    public function remove( $args, $assoc_args ) {
        $slug = $this->validate_provider( $args[0] );
        WP_CLI::confirm( sprintf( 'Clear the "%s" connection?', $slug ), $assoc_args );

        ISXM_Connections::save_one( $slug, [
            'endpoint'        => '',
            'region'          => '',
            'bucket'          => '',
            'access_key'      => '',
            'secret_key'      => '',
            'path_style'      => false,
            'send_public_acl' => false,
        ] );
        WP_CLI::success( sprintf( 'Connection "%s" cleared.', $slug ) );
    }

    /**
     * @param string $slug
     * @return string
     */
    private function validate_provider( $slug ) {
        $slug = sanitize_key( $slug );
        if ( ! array_key_exists( $slug, ISXM_Connections::providers() ) ) {
            WP_CLI::error( sprintf( 'Unknown provider "%s". Valid: %s', $slug, implode( ', ', array_keys( ISXM_Connections::providers() ) ) ) );
        }
        return $slug;
    }
}

WP_CLI::add_command( 'isxm connection', 'ISXM_CLI_Connection' );
