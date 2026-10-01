<?php
/**
 * Router for `php -S` acting as a tiny fake S3 endpoint for tests/run.php.
 *
 * Appends one JSON line per request (method, uri, headers, body sha256) to
 * the log file named by ISXM_FAKE_S3_LOG and answers 200 — or 403 for any
 * key containing "deny-", so the suite can exercise a failed upload without
 * triggering the client's retry backoff (403 is not retryable).
 */
$body = file_get_contents( 'php://input' );
$line = json_encode( array(
	'method'  => $_SERVER['REQUEST_METHOD'],
	'uri'     => $_SERVER['REQUEST_URI'],
	'headers' => function_exists( 'getallheaders' ) ? array_change_key_case( getallheaders(), CASE_LOWER ) : array(),
	'sha256'  => hash( 'sha256', $body ),
	'size'    => strlen( $body ),
) );
file_put_contents( getenv( 'ISXM_FAKE_S3_LOG' ), $line . "\n", FILE_APPEND | LOCK_EX );

if ( strpos( $_SERVER['REQUEST_URI'], 'deny-' ) !== false ) {
	http_response_code( 403 );
	echo '<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>';
	return true;
}
http_response_code( 200 );
return true;
