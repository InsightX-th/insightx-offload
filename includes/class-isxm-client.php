<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Client — Minimal S3-compatible REST client (AWS Signature V4).
 *
 * Plain PHP + wp_remote_request, no SDK. Works with Amazon S3, Minio,
 * Cloudflare R2, DigitalOcean Spaces and any SigV4-compatible endpoint.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Client {

    private $endpoint;   // host without scheme, e.g. minio.example.com or s3.ap-southeast-1.amazonaws.com
    private $scheme;     // http|https used to talk to the API
    private $region;
    private $bucket;
    private $access_key;
    private $secret_key;
    private $path_style;

    public function __construct( array $args = [] ) {
        $s = wp_parse_args( $args, ISXM_Settings::all() );

        $this->region     = $s['region'] !== '' ? $s['region'] : 'us-east-1';
        $this->bucket     = $s['bucket'];
        $this->access_key = $s['access_key'];
        $this->secret_key = $s['secret_key'];
        $this->path_style = ! empty( $s['path_style'] );

        if ( $s['endpoint'] !== '' ) {
            $parsed         = wp_parse_url( $s['endpoint'] );
            $this->scheme   = ! empty( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
            $this->endpoint = ! empty( $parsed['host'] ) ? $parsed['host'] : preg_replace( '#^https?://#', '', untrailingslashit( $s['endpoint'] ) );
            if ( ! empty( $parsed['port'] ) ) {
                $this->endpoint .= ':' . $parsed['port'];
            }
        } else {
            $this->scheme   = 'https';
            $this->endpoint = 's3.' . $this->region . '.amazonaws.com';
            $this->path_style = false;
        }
    }

    /**
     * Upload a local file to the bucket.
     *
     * @param string $key          Object key.
     * @param string $file_path    Absolute path of the local file.
     * @param string $content_type MIME type.
     * @param bool   $public_acl   Send x-amz-acl: public-read.
     * @return true|WP_Error
     */
    public function put_object( $key, $file_path, $content_type = 'application/octet-stream', $public_acl = false ) {
        if ( ! file_exists( $file_path ) ) {
            // basename only — these messages surface in the admin UI and the
            // `_isxs_offload_error` meta, never leak server paths.
            return new WP_Error( 'isxs_missing_file', sprintf( 'Local file not found: %s', wp_basename( $file_path ) ) );
        }

        $headers = [ 'content-type' => $content_type ];
        if ( $public_acl ) {
            $headers['x-amz-acl'] = 'public-read';
        }

        // 'body_file' (not 'body') so the file is streamed from disk instead
        // of read into memory — a large video/zip used to exhaust
        // memory_limit here, and that's a FATAL, which killed the whole
        // bulk batch rather than failing this one attachment.
        $response = $this->request_with_retry( 'PUT', $key, [
            'body_file' => $file_path,
            'headers'   => $headers,
            'timeout'   => 300,
        ] );
        return $this->expect_status( $response, [ 200 ] );
    }

    /**
     * Set a canned ACL on an existing object (PUT Object ACL subresource).
     *
     * @param string $key Object key.
     * @param string $acl Canned ACL value, e.g. 'private' or 'public-read'.
     * @return true|WP_Error
     */
    public function set_object_acl( $key, $acl = 'private' ) {
        $response = $this->request( 'PUT', $key, [
            'query'   => [ 'acl' => '' ],
            'headers' => [ 'x-amz-acl' => $acl ],
        ] );
        return $this->expect_status( $response, [ 200 ] );
    }

    /**
     * Delete an object. Missing objects are treated as success (204/404).
     *
     * @param string $key Object key.
     * @return true|WP_Error
     */
    public function delete_object( $key ) {
        $response = $this->request( 'DELETE', $key );
        return $this->expect_status( $response, [ 200, 204, 404 ] );
    }

    /**
     * Download an object straight to a local file, never holding the body in
     * memory — a multi-GB video or zip read into a string exhausts
     * memory_limit, and that is a FATAL, which kills the entire batch rather
     * than failing one attachment.
     *
     * Retries the failures worth retrying, the same way put_object() does:
     * Download, Remove and Migrate all read through here, so without it a
     * single dropped connection failed the item outright (and in Migrate's
     * case, the whole attachment).
     *
     * Writes to a temporary file next to the destination and renames on
     * success, so a failed or truncated transfer can never leave a partial
     * file where callers expect a complete one.
     *
     * @param string $key         Object key.
     * @param string $destination Absolute local path to write.
     * @return true|WP_Error
     */
    public function get_object_to_file( $key, $destination ) {
        $delays   = [ 1, 3 ];
        $attempts = 3;
        $result   = null;

        for ( $i = 0; $i < $attempts; $i++ ) {
            $result = $this->get_object_to_file_once( $key, $destination );
            if ( ! is_wp_error( $result ) || ! self::is_retryable_download( $result ) ) {
                return $result;
            }
            if ( $i < $attempts - 1 ) {
                sleep( isset( $delays[ $i ] ) ? $delays[ $i ] : end( $delays ) );
            }
        }

        return $result;
    }

    /**
     * One streamed download attempt. See get_object_to_file().
     *
     * @return true|WP_Error
     */
    private function get_object_to_file_once( $key, $destination ) {
        $temp = $destination . '.isxs-part';

        $response = $this->request( 'GET', $key, [
            'timeout'   => 300,
            'stream_to' => $temp,
        ] );

        if ( is_wp_error( $response ) ) {
            self::discard( $temp );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            self::discard( $temp );
            return new WP_Error( 'isxs_http_' . $code, sprintf( 'GET %s returned HTTP %d', $key, $code ) );
        }

        // Same truncation guard get_object() applies: a connection dropped
        // mid-body still arrives with a 200 status line already sent, and a
        // short file must not pass as the complete one — Remove deletes the
        // remote copy once the local "backup" looks successful.
        $content_length = wp_remote_retrieve_header( $response, 'content-length' );
        $written        = @filesize( $temp );
        if ( $written === false ) {
            self::discard( $temp );
            return new WP_Error( 'isxs_write_failed', sprintf( 'เขียนไฟล์ %s ไม่สำเร็จ', wp_basename( $destination ) ) );
        }
        if ( $content_length !== '' && (int) $content_length !== (int) $written ) {
            self::discard( $temp );
            return new WP_Error(
                'isxs_truncated_download',
                sprintf( 'ดาวน์โหลด %s ไม่สมบูรณ์ (ได้ %d จาก %d bytes — อาจขาดการเชื่อมต่อระหว่างดาวน์โหลด)', $key, (int) $written, (int) $content_length )
            );
        }

        if ( ! @rename( $temp, $destination ) ) {
            self::discard( $temp );
            return new WP_Error( 'isxs_write_failed', sprintf( 'เขียนไฟล์ %s ไม่สำเร็จ', wp_basename( $destination ) ) );
        }

        return true;
    }

    /**
     * Remove a partial download so a later attempt starts clean.
     */
    private static function discard( $path ) {
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    /**
     * Whether a failed download is worth another attempt. A 404 or a
     * permission failure answers the same way every time — repeating those
     * just burns the batch's time budget (and remove_remote_attachment()
     * specifically relies on 404 coming back promptly).
     *
     * @param WP_Error $error
     * @return bool
     */
    private static function is_retryable_download( $error ) {
        $code = $error->get_error_code();

        // A truncated body IS a dropped connection, just one that arrived
        // after the 200 status line — exactly the case retrying fixes.
        if ( $code === 'isxs_truncated_download' ) {
            return true;
        }
        if ( preg_match( '#^isxs_http_(\d+)$#', $code, $m ) ) {
            return in_array( (int) $m[1], [ 429, 500, 502, 503, 504 ], true );
        }
        // Anything else is a transport-level WP_Error (timeout, DNS, reset).
        return true;
    }

    /**
     * Test bucket connectivity + credentials (HEAD bucket).
     *
     * @return true|WP_Error
     */
    public function test_connection() {
        if ( $this->bucket === '' || $this->access_key === '' || $this->secret_key === '' ) {
            return new WP_Error( 'isxs_not_configured', 'ยังตั้งค่าไม่ครบ — ต้องมี Bucket, Access Key และ Secret Key' );
        }

        $response = $this->request( 'HEAD', '' );
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) return true;
        if ( $code === 404 ) return new WP_Error( 'isxs_no_bucket', sprintf( 'ไม่พบ bucket "%s" บน endpoint นี้ (HTTP 404)', $this->bucket ) );
        if ( $code === 403 ) return new WP_Error( 'isxs_forbidden', 'Access Key/Secret Key ไม่ถูกต้อง หรือไม่มีสิทธิ์เข้าถึง bucket (HTTP 403)' );
        if ( $code === 301 ) return new WP_Error( 'isxs_wrong_region', 'Region ไม่ตรงกับ bucket (HTTP 301) — ตรวจสอบค่า Region' );
        return new WP_Error( 'isxs_http_' . $code, sprintf( 'เชื่อมต่อไม่สำเร็จ (HTTP %d)', $code ) );
    }

    /**
     * One page of ListObjectsV2 (bucket-root, up to 1000 keys). Parsed
     * with SimpleXML (built into PHP) so keys containing entities, CDATA
     * or attribute oddities decode correctly — see parse_xml().
     *
     * @param string $continuation_token Pass the previous page's next_token to continue.
     * @return array{count:int,next_token:string}|WP_Error
     */
    public function list_objects_page( $continuation_token = '' ) {
        $query = [ 'list-type' => '2', 'max-keys' => '1000' ];
        if ( $continuation_token !== '' ) {
            $query['continuation-token'] = $continuation_token;
        }

        $response = $this->request( 'GET', '', [ 'query' => $query, 'timeout' => 30 ] );
        $ok       = $this->expect_status( $response, [ 200 ] );
        if ( is_wp_error( $ok ) ) return $ok;

        $body = wp_remote_retrieve_body( $response );
        $xml  = self::parse_xml( $body );
        if ( $xml === false || $xml->getName() !== 'ListBucketResult' ) {
            return self::malformed_response_error();
        }
        $count      = count( $xml->Contents );
        $next_token = self::extract_next_token( $body );
        if ( is_wp_error( $next_token ) ) {
            return $next_token;
        }

        return [ 'count' => $count, 'next_token' => $next_token ];
    }

    /**
     * One page of ListObjectsV2 that returns the actual object keys, not
     * just a count — used by Migrate to enumerate what's really in the
     * source bucket (ground truth) instead of guessing keys that might not
     * exist, and by the Sync tool to verify what's really in the
     * destination bucket. Parsed with SimpleXML, same as list_objects_page().
     *
     * @param string $continuation_token Pass the previous page's next_token to continue.
     * @param int    $max_keys           Page size (S3 caps at 1000).
     * @param string $prefix             Only list keys starting with this prefix
     *                                   ('' = whole bucket). One prefixed page is
     *                                   how an attachment's own objects are
     *                                   checked without listing the whole bucket.
     * @return array{keys:string[],next_token:string}|WP_Error
     */
    public function list_objects_keys_page( $continuation_token = '', $max_keys = 1000, $prefix = '' ) {
        $query = [ 'list-type' => '2', 'max-keys' => (string) $max_keys ];
        if ( $prefix !== '' ) {
            $query['prefix'] = $prefix;
        }
        if ( $continuation_token !== '' ) {
            $query['continuation-token'] = $continuation_token;
        }

        $response = $this->request( 'GET', '', [ 'query' => $query, 'timeout' => 30 ] );
        $ok       = $this->expect_status( $response, [ 200 ] );
        if ( is_wp_error( $ok ) ) return $ok;

        $body = wp_remote_retrieve_body( $response );
        $xml  = self::parse_xml( $body );
        if ( $xml === false || $xml->getName() !== 'ListBucketResult' ) {
            return self::malformed_response_error();
        }
        // SimpleXML decodes entities itself, so a key like "a&amp;b.jpg"
        // comes back as the real "a&b.jpg" without a manual unescape step.
        $keys = [];
        foreach ( $xml->Contents as $object ) {
            $key = (string) $object->Key;
            if ( $key !== '' ) {
                $keys[] = $key;
            }
        }

        $next_token = self::extract_next_token( $body );
        if ( is_wp_error( $next_token ) ) {
            return $next_token;
        }

        return [ 'keys' => $keys, 'next_token' => $next_token ];
    }

    /**
     * Pull the pagination token out of a ListObjectsV2 response body.
     *
     * '' means "genuinely the last page". If the response claims it's
     * truncated but carries no usable NextContinuationToken (broken/partial
     * provider response), that's an ERROR — treating it as '' would make
     * callers believe the listing ended and silently drop every remaining
     * page, the same "quietly looks finished" failure class fixed elsewhere.
     *
     * @param string $body Raw XML response body.
     * @return string|WP_Error Unescaped token, '' when not truncated.
     */
    private static function extract_next_token( $body ) {
        $xml = self::parse_xml( $body );
        if ( $xml === false ) {
            return '';
        }
        if ( strtolower( trim( (string) $xml->IsTruncated ) ) !== 'true' ) {
            return '';
        }
        $token = trim( (string) $xml->NextContinuationToken );
        if ( $token === '' ) {
            return new WP_Error(
                'isxs_pagination_broken',
                'Bucket แจ้งว่ายังมีไฟล์หน้าถัดไป แต่ไม่ส่ง continuation token มาด้วย — หยุดไว้ก่อนเพื่อไม่ให้เข้าใจผิดว่ารายการจบแล้ว'
            );
        }
        return $token;
    }

    /**
     * Parse an S3 XML response body with SimpleXML — the XML parser built
     * into PHP, so no dependency to install — with external-entity loading
     * disabled so a hostile body can't trigger XXE.
     *
     * Returns false on any parse failure (callers treat that as a
     * malformed response, the same way the old regex checks did) and when
     * SimpleXML itself is unavailable (rare host configs), so the plugin
     * degrades instead of fatalling.
     *
     * @param string $body Raw XML response body.
     * @return \SimpleXMLElement|false
     */
    private static function parse_xml( $body ) {
        if ( ! function_exists( 'simplexml_load_string' ) ) {
            return false;
        }

        $previous = libxml_use_internal_errors( true );
        // PHP < 8.0 loads external entities by default — disable them.
        // PHP 8.0+ keeps them disabled and the function is a no-op there
        // (that's why the call is guarded rather than relied upon).
        if ( function_exists( 'libxml_disable_entity_loader' ) ) {
            @libxml_disable_entity_loader( true );
        }
        $xml = simplexml_load_string( $body );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $xml;
    }

    /**
     * Total object count in the bucket (paginated List Objects V2), bounded
     * by a time budget rather than a fixed page cap so it degrades
     * gracefully on huge buckets instead of timing out the request — same
     * time-budget philosophy the bulk-tool batch runners already use.
     *
     * Resumable: pass the `next_token` from a previous incomplete response
     * back in as `$continuation_token` to keep counting from where it left
     * off. `total` is only the count accumulated *this call* — callers must
     * accumulate across calls themselves until `complete` is true, same
     * contract as `list_objects_keys_page()`.
     *
     * @param string $continuation_token Resume cursor from a previous incomplete call ('' to start).
     * @param int    $time_limit Seconds to keep paginating before giving up.
     * @return array{total:int,next_token:string,complete:bool}|WP_Error
     */
    public function count_objects( $continuation_token = '', $time_limit = 20 ) {
        $started = microtime( true );
        $total   = 0;
        $token   = $continuation_token;
        do {
            $page = $this->list_objects_page( $token );
            if ( is_wp_error( $page ) ) return $page;
            $total += $page['count'];
            $token  = $page['next_token'];
        } while ( $token !== '' && ( microtime( true ) - $started ) < $time_limit );

        return [ 'total' => $total, 'next_token' => $token, 'complete' => ( $token === '' ) ];
    }

    /* ---------------------------------------------------------------------
     * Internals — AWS Signature Version 4
     * ------------------------------------------------------------------ */

    /**
     * Host header value for the request.
     */
    private function host() {
        return $this->path_style ? $this->endpoint : $this->bucket . '.' . $this->endpoint;
    }

    /**
     * Canonical URI for the object key (each segment URI-encoded per SigV4).
     *
     * @param string $key Object key ('' means the bucket itself).
     */
    private function canonical_uri( $key ) {
        $path = $this->path_style ? '/' . $this->bucket : '';
        if ( $key !== '' ) {
            $segments = array_map( 'rawurlencode', explode( '/', $key ) );
            $path    .= '/' . implode( '/', $segments );
        }
        return $path === '' ? '/' : $path;
    }

    /**
     * Perform a signed request against the bucket.
     *
     * @param string $method HTTP method.
     * @param string $key    Object key ('' for bucket-level).
     * @param array  $args   { body?: string, body_file?: string, stream_to?: string,
     *                        headers?: array, timeout?: int, query?: array }
     *                       body_file streams a request body OUT of a file;
     *                       stream_to streams the response body IN to one.
     * @return array|WP_Error wp_remote_request()-shaped result.
     */
    private function request( $method, $key, array $args = [] ) {
        $body      = isset( $args['body'] ) ? $args['body'] : '';
        $body_file = isset( $args['body_file'] ) ? $args['body_file'] : '';
        $stream_to = isset( $args['stream_to'] ) ? $args['stream_to'] : '';
        $headers   = isset( $args['headers'] ) ? $args['headers'] : [];
        $timeout   = isset( $args['timeout'] ) ? $args['timeout'] : 30;
        $query     = isset( $args['query'] ) ? $args['query'] : [];

        if ( $body_file !== '' && ! self::can_stream() ) {
            // No cURL — fall back to reading the file, but only when it
            // actually fits in memory. Returning a WP_Error keeps the batch
            // alive; letting PHP hit the memory limit would not.
            $fits = self::fits_in_memory( $body_file );
            if ( is_wp_error( $fits ) ) {
                return $fits;
            }
            $body = file_get_contents( $body_file );
            if ( $body === false ) {
                return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot read local file: %s', wp_basename( $body_file ) ) );
            }
            $body_file = '';
        }

        $amz_date   = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        // hash_file() streams the file in chunks, so signing a 2 GB upload
        // costs the same memory as signing an empty one.
        $payload_hash = $body_file !== '' ? hash_file( 'sha256', $body_file ) : hash( 'sha256', $body );
        if ( $payload_hash === false ) {
            return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot read local file: %s', wp_basename( $body_file ) ) );
        }
        $uri = $this->canonical_uri( $key );

        // SigV4 canonical query string: sorted by key, each key/value
        // percent-encoded per RFC 3986 (rawurlencode matches, same as
        // canonical_uri() already relies on for path segments).
        ksort( $query );
        $query_parts = [];
        foreach ( $query as $q_key => $q_value ) {
            $query_parts[] = rawurlencode( $q_key ) . '=' . rawurlencode( $q_value );
        }
        $canonical_query = implode( '&', $query_parts );

        $sign_headers = array_merge( $headers, [
            'host'                 => $this->host(),
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $amz_date,
        ] );
        $sign_headers = array_change_key_case( $sign_headers, CASE_LOWER );
        ksort( $sign_headers );

        $canonical_headers = '';
        $signed_names      = [];
        foreach ( $sign_headers as $name => $value ) {
            $canonical_headers .= $name . ':' . trim( $value ) . "\n";
            $signed_names[]     = $name;
        }
        $signed_headers = implode( ';', $signed_names );

        $canonical_request = implode( "\n", [
            $method,
            $uri,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ] );

        $scope          = $date_stamp . '/' . $this->region . '/s3/aws4_request';
        $string_to_sign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $scope,
            hash( 'sha256', $canonical_request ),
        ] );

        $k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $this->secret_key, true );
        $k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $scope,
            $signed_headers,
            $signature
        );

        $request_headers                  = $sign_headers;
        $request_headers['authorization'] = $authorization;
        unset( $request_headers['host'] ); // WP HTTP API sets Host from the URL

        $url = $this->scheme . '://' . $this->host() . $uri . ( $canonical_query !== '' ? '?' . $canonical_query : '' );

        if ( $body_file !== '' ) {
            // The WP HTTP API can only take a string body, so a streamed
            // upload goes straight to cURL — with the very same signed
            // headers computed above, nothing about SigV4 changes.
            return $this->stream_request( $method, $url, $request_headers, $body_file, $timeout );
        }

        $request_args = [
            'method'  => $method,
            'headers' => $request_headers,
            'body'    => $body !== '' ? $body : null,
            'timeout' => $timeout,
        ];
        // Explicit, matching the cURL path's own https_ssl_verify handling
        // below — the streams transport verifies certificates too, but
        // saying so keeps the two transports consistent if a site ever
        // relaxes verification via the same filter.
        $request_args['sslverify'] = apply_filters( 'https_ssl_verify', true, $url );

        if ( $stream_to !== '' ) {
            // The WP HTTP API writes the response body straight to this file
            // instead of buffering it — supported by both the cURL and the
            // streams transport, so no hand-rolled cURL is needed here the
            // way the upload side required.
            $request_args['stream']   = true;
            $request_args['filename'] = $stream_to;
        }

        return wp_remote_request( $url, $request_args );
    }

    /**
     * PUT a file straight off disk via cURL, returning a
     * wp_remote_request()-shaped array so expect_status() and the
     * wp_remote_retrieve_* helpers work on it unchanged.
     *
     * @param string $method    HTTP method (PUT).
     * @param string $url       Full request URL.
     * @param array  $headers   Signed headers, name => value.
     * @param string $file_path Absolute path of the file to send.
     * @param int    $timeout   Seconds.
     * @return array|WP_Error
     */
    private function stream_request( $method, $url, array $headers, $file_path, $timeout ) {
        $size = @filesize( $file_path );
        if ( $size === false ) {
            return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot read local file: %s', wp_basename( $file_path ) ) );
        }

        $handle = @fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot open local file: %s', wp_basename( $file_path ) ) );
        }

        $header_lines = [];
        foreach ( $headers as $name => $value ) {
            $header_lines[] = $name . ': ' . $value;
        }
        // Suppress cURL's automatic 100-continue: some S3-compatible
        // endpoints never answer it and every upload then eats cURL's 1s
        // wait. Unsigned extra headers don't affect the signature.
        $header_lines[] = 'Expect:';

        // Same SSL policy the WP HTTP API would have applied to this
        // request, so a site that already relaxes verification (e.g. a
        // self-signed Minio behind https) keeps working after the switch.
        $ssl_verify = apply_filters( 'https_ssl_verify', true, $url );

        $ch = curl_init();
        $curl_options = [
            CURLOPT_URL            => $url,
            CURLOPT_UPLOAD         => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => (bool) $ssl_verify,
            CURLOPT_SSL_VERIFYHOST => $ssl_verify ? 2 : 0,
        ];
        // WordPress ships its own CA bundle — point at it when it exists
        // (the standard WP_Http_Curl behaviour); otherwise let cURL fall
        // back to the system bundle instead of referencing a file this
        // install doesn't have.
        $cainfo = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        if ( file_exists( $cainfo ) ) {
            $curl_options[ CURLOPT_CAINFO ] = $cainfo;
        }
        curl_setopt_array( $ch, $curl_options );

        $response_body = curl_exec( $ch );
        $errno         = curl_errno( $ch );
        $error         = curl_error( $ch );
        $code          = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        curl_close( $ch );
        fclose( $handle );

        if ( $errno ) {
            return new WP_Error( 'isxs_curl_' . $errno, sprintf( 'อัปโหลดล้มเหลว (cURL %d): %s', $errno, $error ) );
        }

        return [
            'headers'  => [],
            'body'     => is_string( $response_body ) ? $response_body : '',
            'response' => [ 'code' => $code, 'message' => '' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * Same as request(), but retries the handful of failures that are worth
     * retrying: transport errors (dropped connection, timeout) and the
     * server-side "try again" codes. Credential/permission/not-found
     * responses are returned immediately — repeating those just wastes the
     * batch's time budget and hammers the endpoint.
     *
     * @return array|WP_Error
     */
    private function request_with_retry( $method, $key, array $args = [], $attempts = 3 ) {
        $delays   = [ 1, 3 ]; // seconds between attempts
        $response = null;

        for ( $i = 0; $i < $attempts; $i++ ) {
            $response = $this->request( $method, $key, $args );

            if ( ! self::is_retryable( $response ) ) {
                return $response;
            }
            if ( $i < $attempts - 1 ) {
                sleep( isset( $delays[ $i ] ) ? $delays[ $i ] : end( $delays ) );
            }
        }

        return $response;
    }

    /**
     * Whether a response is worth another attempt.
     *
     * @param array|WP_Error $response
     * @return bool
     */
    private static function is_retryable( $response ) {
        if ( is_wp_error( $response ) ) {
            // Local read/permission problems never fix themselves.
            $code = $response->get_error_code();
            return $code !== 'isxs_read_failed' && $code !== 'isxs_missing_file' && $code !== 'isxs_file_too_large';
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        return in_array( $status, [ 429, 500, 502, 503, 504 ], true );
    }

    /**
     * Whether this PHP can stream a request body from disk.
     */
    private static function can_stream() {
        return function_exists( 'curl_init' ) && function_exists( 'curl_setopt_array' );
    }

    /**
     * Guard for the no-cURL fallback path: refuse a file that can't fit in
     * the remaining memory instead of letting PHP fatal on it.
     *
     * @return true|WP_Error
     */
    private static function fits_in_memory( $file_path ) {
        $size = @filesize( $file_path );
        if ( $size === false ) {
            return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot read local file: %s', wp_basename( $file_path ) ) );
        }

        $limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        if ( $limit <= 0 ) {
            return true; // Unlimited.
        }

        // The body is held twice at peak (file contents + request payload),
        // so budget 2.5× the file size plus what's already in use.
        $needed = (int) ( $size * 2.5 ) + memory_get_usage( true );
        if ( $needed > $limit ) {
            return new WP_Error(
                'isxs_file_too_large',
                sprintf(
                    'ไฟล์ใหญ่เกินกว่าที่หน่วยความจำจะรับไหว (%s, memory_limit %s) และเซิร์ฟเวอร์นี้ไม่มี cURL ให้อัปโหลดแบบสตรีม',
                    size_format( $size ),
                    ini_get( 'memory_limit' )
                )
            );
        }

        return true;
    }

    /**
     * Reduce a response to true|WP_Error against a list of acceptable codes.
     *
     * @param array|WP_Error $response wp_remote_request result.
     * @param int[]          $ok_codes Acceptable HTTP status codes.
     * @return true|WP_Error
     */
    private function expect_status( $response, array $ok_codes ) {
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, $ok_codes, true ) ) return true;

        $body    = wp_remote_retrieve_body( $response );
        $code_tag = '';
        $message  = '';
        if ( $body ) {
            $xml = self::parse_xml( $body );
            if ( $xml !== false ) {
                if ( isset( $xml->Code ) && trim( (string) $xml->Code ) !== '' ) {
                    $code_tag = ' [' . trim( (string) $xml->Code ) . ']';
                }
                if ( isset( $xml->Message ) && trim( (string) $xml->Message ) !== '' ) {
                    $message = ' — ' . trim( (string) $xml->Message );
                }
            }
        }
        return new WP_Error( 'isxs_http_' . $code, sprintf( 'HTTP %d%s%s', $code, $code_tag, $message ) );
    }

    /**
     * A 200 status doesn't guarantee a real ListObjectsV2 response — a
     * connection dropped mid-transfer, or a captive-portal/proxy page, can
     * still arrive as HTTP 200 with a body that isn't S3 XML at all.
     * Without checking for the real root element, an incomplete/garbage
     * body has no `<IsTruncated>true</IsTruncated>` to match, which reads
     * as "no more pages" and silently ends the caller's loop as if the
     * listing were genuinely exhausted.
     *
     * @return WP_Error
     */
    private static function malformed_response_error() {
        return new WP_Error(
            'isxs_malformed_response',
            'การตอบกลับจาก source bucket ไม่ใช่ XML ที่ถูกต้อง (อาจขาดการเชื่อมต่อระหว่างดึงข้อมูล)'
        );
    }
}
