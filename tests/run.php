<?php
/**
 * Unit suite for InsightX Offload.
 *
 * Loads the REAL plugin classes on top of tests/bootstrap.php (WordPress
 * stubs with in-memory options/meta/posts and a recording HTTP fake). The
 * streamed-upload path goes through real cURL to a throwaway `php -S`
 * fake S3 (tests/fake-s3.php) on 127.0.0.1, so no network or bucket is
 * needed. Self-contained, no framework: run with `php tests/run.php`,
 * exits non-zero on any failure. CI runs it on every push/PR.
 */

require __DIR__ . '/bootstrap.php';

// ---------- Fake S3 (php -S) for streamed uploads ----------
$fake_log  = sys_get_temp_dir() . '/isxm_fake_s3_' . getmypid() . '.jsonl';
$fake_port = 18000 + ( getmypid() % 1000 );
@unlink( $fake_log );
$fake_proc = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:$fake_port", __DIR__ . '/fake-s3.php' ),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
	$fake_pipes,
	null,
	array( 'ISXM_FAKE_S3_LOG' => $fake_log )
);
register_shutdown_function( function () use ( $fake_proc, $fake_log ) {
	proc_terminate( $fake_proc );
	@unlink( $fake_log );
} );
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $fake_port ); $i++ ) {
	usleep( 100000 );
}
function fake_s3_requests() {
	global $fake_log;
	$out = array();
	foreach ( is_file( $fake_log ) ? file( $fake_log ) : array() as $line ) {
		$out[] = json_decode( $line, true );
	}
	return $out;
}
function fake_s3_reset() {
	global $fake_log;
	@unlink( $fake_log );
}

/** Recompute an AWS SigV4 signature from what was actually sent (independent of the client). */
function sigv4_matches( $method, $url, array $headers, $secret, $region ) {
	$headers = array_change_key_case( $headers, CASE_LOWER );
	if ( ! preg_match( '#Credential=[^/]+/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([^,]+), Signature=([0-9a-f]{64})#', $headers['authorization'], $m ) ) {
		return 'authorization header malformed';
	}
	list( , $date, $auth_region, $signed, $sig ) = $m;
	if ( $auth_region !== $region ) {
		return "region $auth_region != $region";
	}
	$parts            = parse_url( $url );
	$headers['host']  = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	$canonical_headers = '';
	foreach ( explode( ';', $signed ) as $name ) {
		$canonical_headers .= $name . ':' . trim( $headers[ $name ] ) . "\n";
	}
	$canonical = implode( "\n", array(
		$method,
		$parts['path'],
		isset( $parts['query'] ) ? $parts['query'] : '',
		$canonical_headers,
		$signed,
		$headers['x-amz-content-sha256'],
	) );
	$scope = "$date/$region/s3/aws4_request";
	$sts   = "AWS4-HMAC-SHA256\n{$headers['x-amz-date']}\n$scope\n" . hash( 'sha256', $canonical );
	$k     = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
	$k     = hash_hmac( 'sha256', $region, $k, true );
	$k     = hash_hmac( 'sha256', 's3', $k, true );
	$k     = hash_hmac( 'sha256', 'aws4_request', $k, true );
	return hash_hmac( 'sha256', $sts, $k ) === $sig ? true : 'signature mismatch';
}

const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

// =====================================================================
group( 'Crypto' );

$enc = ISXM_Crypto::encrypt( 'my-secret' );
t( 'C1 round-trips', ISXM_Crypto::decrypt( $enc ) === 'my-secret' );
t( 'C2 writes the authenticated ENC3 format', strpos( base64_decode( $enc ), 'ENC3:' ) === 0 );
t( 'C3 random IV — same input encrypts differently', ISXM_Crypto::encrypt( 'my-secret' ) !== $enc );
t( 'C4 is_encrypted() true for ENC3, false for plain', ISXM_Crypto::is_encrypted( $enc ) && ! ISXM_Crypto::is_encrypted( 'plain-value' ) );
$raw     = base64_decode( $enc );
$raw[40] = chr( ord( $raw[40] ) ^ 1 );
t( 'C5 tampered ciphertext is rejected (empty), not garbage', ISXM_Crypto::decrypt( base64_encode( $raw ) ) === '' );
$GLOBALS['isxm_t']['salt'] = 'a-different-salt';
t( 'C6 changed auth salt cannot decrypt', ISXM_Crypto::decrypt( $enc ) === '' );
unset( $GLOBALS['isxm_t']['salt'] );
$key    = hash( 'sha256', wp_salt( 'auth' ), true );
$iv     = str_repeat( "\1", 16 );
$legacy = base64_encode( 'ENC2:' . $iv . openssl_encrypt( 'old-secret', 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv ) );
t( 'C7 legacy ENC2 values still decrypt', ISXM_Crypto::decrypt( $legacy ) === 'old-secret' );
t( 'C8 unencrypted values pass through unchanged', ISXM_Crypto::decrypt( 'not-encrypted!' ) === 'not-encrypted!' );
t( 'C9 empty in, empty out', ISXM_Crypto::encrypt( '' ) === '' && ISXM_Crypto::decrypt( '' ) === '' );
t( 'C10 truncated ENC3 payload is rejected', ISXM_Crypto::decrypt( base64_encode( 'ENC3:short' ) ) === '' );

// =====================================================================
group( 'DB rewriter: serialized-safe replace' );

$old = 'https://example.com/wp-content/uploads/2025/08/a.jpg';
$new = 'https://cdn.example.test/media/2025/08/a.jpg';
t( 'R1 plain string', ISXM_DB_Rewriter::recursive_replace( "<img src=\"$old\">", $old, $new ) === "<img src=\"$new\">" );

$ser = serialize( array( 'img' => $old, 'nested' => array( 'list' => array( $old, 'x' ) ), 'n' => 5 ) );
$out = ISXM_DB_Rewriter::recursive_replace( $ser, $old, $new );
$un  = @unserialize( $out );
t( 'R2 serialized array stays valid with new lengths', is_array( $un ) && $un['img'] === $new && $un['nested']['list'][0] === $new && $un['n'] === 5 );

$obj      = new stdClass();
$obj->url = $old;
$un       = @unserialize( ISXM_DB_Rewriter::recursive_replace( serialize( $obj ), $old, $new ) );
t( 'R3 serialized stdClass property replaced', is_object( $un ) && $un->url === $new );
t( 'R4 serialized false (b:0;) survives', ISXM_DB_Rewriter::recursive_replace( 'b:0;', $old, $new ) === 'b:0;' );
t( 'R5 non-string values untouched', ISXM_DB_Rewriter::recursive_replace( 42, $old, $new ) === 42 );
$pairs = ISXM_DB_Rewriter::recursive_replace_pairs( "$old and http://example.com/x.png", array( $old => $new, 'http://example.com/x.png' => 'https://cdn/x.png' ) );
t( 'R6 several pairs in one pass', $pairs === "$new and https://cdn/x.png" );

// PHP object injection: a class other than stdClass must never be instantiated.
class ISXM_Test_Gadget {
	public $url;
	public function __wakeup() { $GLOBALS['isxm_gadget_woke'] = true; }
	public function __destruct() { $GLOBALS['isxm_gadget_woke'] = true; }
}
$GLOBALS['isxm_gadget_woke'] = false;
$gadget = 'O:16:"ISXM_Test_Gadget":1:{s:3:"url";s:' . strlen( $old ) . ':"' . $old . '";}';
$out    = ISXM_DB_Rewriter::recursive_replace( $gadget, $old, $new );
t( 'R8 serialized non-stdClass object is never instantiated (no __wakeup/__destruct)', $GLOBALS['isxm_gadget_woke'] === false );
t( 'R9 ...and is written back byte-for-byte', $out === $gadget, $out );
$mixed = serialize( array( 'safe' => $old, 'blob' => 'x' ) );
$mixed = str_replace( 's:4:"blob";s:1:"x";', 's:4:"blob";' . $gadget, $mixed );
$un    = @unserialize( ISXM_DB_Rewriter::recursive_replace( $mixed, $old, $new ), array( 'allowed_classes' => false ) );
t( 'R10 siblings of a skipped object are still rewritten', is_array( $un ) && $un['safe'] === $new && $GLOBALS['isxm_gadget_woke'] === false );

// A value serialized twice (a plugin calling update_option( serialize( … ) )).
$inner = serialize( array( 'url' => $old ) );
$outer = serialize( array( 'blob' => $inner ) );
$un    = @unserialize( ISXM_DB_Rewriter::recursive_replace( $outer, $old, $new ) );
$inner_after = is_array( $un ) ? @unserialize( $un['blob'] ) : false;
t( 'R7 double-serialized value keeps its inner serialization valid', is_array( $inner_after ) && $inner_after['url'] === $new, is_array( $un ) ? $un['blob'] : 'outer broken' );

// =====================================================================
group( 'S3 client: URLs, signing, responses' );

isxm_test_configure();
$c = new ISXM_Client();
isxm_test_queue_response( 200 );
t( 'H1 test_connection() 200 → true', $c->test_connection() === true );
$req = end( $GLOBALS['isxm_t']['http'] );
t( 'H2 path-style URL is endpoint/bucket', $req['url'] === 'https://s3.example.test/media' );
t( 'H3 HEAD request', $req['args']['method'] === 'HEAD' );
$sig = sigv4_matches( 'HEAD', $req['url'], $req['args']['headers'], SECRET, 'us-east-1' );
t( 'H4 SigV4 signature verifies independently', $sig === true, (string) $sig );

foreach ( array( 404 => 'isxs_no_bucket', 403 => 'isxs_forbidden', 301 => 'isxs_wrong_region', 500 => 'isxs_http_500' ) as $code => $err ) {
	isxm_test_queue_response( $code );
	$r = $c->test_connection();
	t( "H5 test_connection() $code → $err", is_wp_error( $r ) && $r->get_error_code() === $err );
}
$bad = new ISXM_Client( array( 'bucket' => '' ) );
t( 'H6 missing bucket → not configured, no request', is_wp_error( $bad->test_connection() ) );

isxm_test_configure( array( 'path_style' => false, 'endpoint' => 'https://nyc3.digitaloceanspaces.com' ) );
$c = new ISXM_Client();
$c->delete_object( 'dir/ไฟล์ ภาพ+1.jpg' );
$req = end( $GLOBALS['isxm_t']['http'] );
t( 'H7 virtual-hosted URL + each key segment URI-encoded once', $req['url'] === 'https://media.nyc3.digitaloceanspaces.com/dir/%E0%B9%84%E0%B8%9F%E0%B8%A5%E0%B9%8C%20%E0%B8%A0%E0%B8%B2%E0%B8%9E%2B1.jpg', $req['url'] );
$sig = sigv4_matches( 'DELETE', $req['url'], $req['args']['headers'], SECRET, 'us-east-1' );
t( 'H8 signature valid for a unicode/space/plus key', $sig === true, (string) $sig );

isxm_test_configure( array( 'endpoint' => '', 'region' => 'ap-southeast-1', 'path_style' => true ) );
$c = new ISXM_Client();
$c->delete_object( 'a.jpg' );
$req = end( $GLOBALS['isxm_t']['http'] );
t( 'H9 no endpoint → AWS virtual-hosted host for the region', $req['url'] === 'https://media.s3.ap-southeast-1.amazonaws.com/a.jpg', $req['url'] );

isxm_test_configure( array( 'endpoint' => 'http://minio.local:9000' ) );
$c = new ISXM_Client();
$c->delete_object( 'a.jpg' );
$req = end( $GLOBALS['isxm_t']['http'] );
t( 'H10 http endpoint + port kept', $req['url'] === 'http://minio.local:9000/media/a.jpg', $req['url'] );

isxm_test_queue_response( 404 );
t( 'H11 delete of a missing object counts as success', $c->delete_object( 'gone.jpg' ) === true );
isxm_test_queue_response( 403, '<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Nope</Message></Error>' );
$r = $c->delete_object( 'x.jpg' );
t( 'H12 error body code/message surfaced', is_wp_error( $r ) && $r->get_error_message() === 'HTTP 403 [AccessDenied] — Nope', is_wp_error( $r ) ? $r->get_error_message() : '' );

$list = '<?xml version="1.0"?><ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>tok+/=</NextContinuationToken>'
	. '<Contents><Key>a&amp;b.jpg</Key></Contents><Contents><Key>2025/08/c.jpg</Key></Contents></ListBucketResult>';
isxm_test_queue_response( 200, $list );
$page = $c->list_objects_keys_page( 'prev', 500, 'wp/' );
t( 'H13 keys parsed with entities decoded', is_array( $page ) && $page['keys'] === array( 'a&b.jpg', '2025/08/c.jpg' ) );
t( 'H14 continuation token returned', is_array( $page ) && $page['next_token'] === 'tok+/=' );
$req = end( $GLOBALS['isxm_t']['http'] );
t( 'H15 canonical query sorted and RFC 3986 encoded', strpos( $req['url'], '?continuation-token=prev&list-type=2&max-keys=500&prefix=wp%2F' ) !== false, $req['url'] );
t( 'H16 list request signature valid', sigv4_matches( 'GET', $req['url'], $req['args']['headers'], SECRET, 'us-east-1' ) === true );

isxm_test_queue_response( 200, '<?xml version="1.0"?><ListBucketResult><IsTruncated>true</IsTruncated></ListBucketResult>' );
$page = $c->list_objects_keys_page();
t( 'H17 truncated page without a token is an error, not "done"', is_wp_error( $page ) && $page->get_error_code() === 'isxs_pagination_broken' );
isxm_test_queue_response( 200, '<html>captive portal</html>' );
t( 'H18 non-S3 200 body is an error', is_wp_error( $c->list_objects_keys_page() ) );
isxm_test_queue_response( 200, '<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>x</Key></Contents></ListBucketResult>' );
$cnt = $c->count_objects();
t( 'H19 count_objects() last page → complete', is_array( $cnt ) && $cnt['total'] === 1 && $cnt['complete'] === true );

$xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/hosts">]><ListBucketResult><Contents><Key>&x;</Key></Contents></ListBucketResult>';
isxm_test_queue_response( 200, $xxe );
$page = $c->list_objects_keys_page();
$leak = is_array( $page ) && isset( $page['keys'][0] ) && strpos( $page['keys'][0], 'localhost' ) !== false;
t( 'H20 XXE: external entities are not expanded', ! $leak );

t( 'H21 5xx/429 retryable, 4xx not', isxm_call( 'ISXM_Client', 'is_retryable', array( 'response' => array( 'code' => 503 ), 'body' => '' ) )
	&& isxm_call( 'ISXM_Client', 'is_retryable', array( 'response' => array( 'code' => 429 ), 'body' => '' ) )
	&& ! isxm_call( 'ISXM_Client', 'is_retryable', array( 'response' => array( 'code' => 403 ), 'body' => '' ) ) );
t( 'H22 local file errors never retried', ! isxm_call( 'ISXM_Client', 'is_retryable', new WP_Error( 'isxs_read_failed', 'x' ) )
	&& isxm_call( 'ISXM_Client', 'is_retryable', new WP_Error( 'http_request_failed', 'x' ) ) );

ISXM_Client::set_deadline( microtime( true ) + 0.2 );
$before = microtime( true );
isxm_test_queue_response( 503 );
isxm_test_queue_response( 200 );
$tmpf = tempnam( sys_get_temp_dir(), 'isxm' );
$GLOBALS['isxm_t']['http'] = array();
$r = isxm_call( 'ISXM_Client', 'may_wait', 1 );
t( 'H23 past the batch deadline, retry backoff is skipped', $r === false );
ISXM_Client::set_deadline( 0 );
t( 'H24 without a deadline, backoff is allowed', isxm_call( 'ISXM_Client', 'may_wait', 3 ) === true );
@unlink( $tmpf );

// Streamed upload through real cURL to the local fake S3.
isxm_test_configure( array( 'endpoint' => "http://127.0.0.1:$fake_port" ) );
fake_s3_reset();
$c    = new ISXM_Client();
$file = tempnam( sys_get_temp_dir(), 'isxm' );
file_put_contents( $file, str_repeat( 'x', 70000 ) );
$r    = $c->put_object( 'up/test file.jpg', $file, 'image/jpeg', true );
$reqs = fake_s3_requests();
t( 'U1 put_object() succeeds against the endpoint', $r === true, is_wp_error( $r ) ? $r->get_error_message() : '' );
t( 'U2 body streamed intact', isset( $reqs[0] ) && $reqs[0]['sha256'] === hash_file( 'sha256', $file ) && $reqs[0]['size'] === 70000 );
t( 'U3 key encoded in the path', isset( $reqs[0] ) && $reqs[0]['uri'] === '/media/up/test%20file.jpg', isset( $reqs[0] ) ? $reqs[0]['uri'] : '' );
t( 'U4 content-type + public-read ACL headers sent', isset( $reqs[0] ) && $reqs[0]['headers']['content-type'] === 'image/jpeg' && $reqs[0]['headers']['x-amz-acl'] === 'public-read' );
t( 'U5 plain-http upload signs the real payload hash', isset( $reqs[0] ) && $reqs[0]['headers']['x-amz-content-sha256'] === hash_file( 'sha256', $file ) );
$sig = isset( $reqs[0] ) ? sigv4_matches( 'PUT', "http://127.0.0.1:$fake_port" . $reqs[0]['uri'], $reqs[0]['headers'], SECRET, 'us-east-1' ) : 'no request';
t( 'U6 streamed upload signature verifies', $sig === true, (string) $sig );
$r = $c->put_object( 'up/deny-me.jpg', $file );
t( 'U7 403 on upload → WP_Error with S3 code', is_wp_error( $r ) && strpos( $r->get_error_message(), 'AccessDenied' ) !== false );
t( 'U8 missing local file → isxs_missing_file, nothing sent', is_wp_error( $c->put_object( 'k', '/nope/missing.jpg' ) ) );
@unlink( $file );

// =====================================================================
group( 'Connections + Settings' );

ISXM_Connections::save_one( 'minio', array( 'endpoint' => 'http://minio.local:9000', 'bucket' => 'b1', 'access_key' => 'ak', 'secret_key' => 'sk-1', 'path_style' => 1 ) );
$stored = $GLOBALS['isxm_t']['options']['isxs_connections']['minio'];
t( 'N1 secret stored encrypted, never plain', $stored['secret_key'] !== 'sk-1' && ISXM_Crypto::is_encrypted( $stored['secret_key'] ) );
t( 'N2 get() decrypts it back', ISXM_Connections::get( 'minio' )['secret_key'] === 'sk-1' );
ISXM_Connections::save_one( 'minio', array( 'endpoint' => 'http://minio.local:9000', 'bucket' => 'b2', 'access_key' => 'ak', 'secret_key' => '' ) );
t( 'N3 blank secret on re-save keeps the stored one', ISXM_Connections::get( 'minio' )['secret_key'] === 'sk-1' && ISXM_Connections::get( 'minio' )['bucket'] === 'b2' );
t( 'N4 unknown provider slug refused', ISXM_Connections::save_one( 'evil', array( 'bucket' => 'x' ) ) === false && ISXM_Connections::get( 'evil' ) === null );
t( 'N5 is_configured needs bucket + keys', ISXM_Connections::is_configured( 'minio' ) && ! ISXM_Connections::is_configured( 'aws' ) );
t( 'N6 provider defaults apply (R2 region auto)', ISXM_Connections::get( 'r2' )['region'] === 'auto' );

ISXM_Connections::save_one( 'minio', array( 'endpoint' => 'gopher://127.0.0.1:6379/_FLUSHALL', 'bucket' => 'b" onerror="alert(1)', 'access_key' => 'ak', 'secret_key' => 'x' ) );
$m = ISXM_Connections::get( 'minio' );
t( 'N7 non-http(s) endpoint rejected on save (SSRF)', $m['endpoint'] === '' || strpos( $m['endpoint'], 'gopher' ) === false, $m['endpoint'] );
t( 'N8 bucket name reduced to DNS-safe characters (no quote/space injection)', $m['bucket'] === 'bonerroralert1', $m['bucket'] );
$legacy = new ISXM_Client( array( 'endpoint' => 'gopher://127.0.0.1:6379', 'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's' ) );
$legacy->delete_object( 'k' );
$req = end( $GLOBALS['isxm_t']['http'] );
t( 'N9 a stored non-http endpoint is never requested with its own scheme', strpos( $req['url'], 'https://' ) === 0, $req['url'] );

isxm_test_configure( array(), array( 'use_prefix' => true, 'prefix' => '/site-a' ) );
t( 'S1 key_prefix normalised (no leading, one trailing slash)', ISXM_Settings::key_prefix() === 'site-a/' );
isxm_test_configure( array(), array( 'use_prefix' => false, 'prefix' => 'site-a/' ) );
t( 'S2 key_prefix off → empty', ISXM_Settings::key_prefix() === '' );

isxm_test_configure( array( 'endpoint' => 'https://s3.example.test', 'path_style' => true ) );
t( 'S3 public base URL, path-style', ISXM_Settings::public_base_url() === 'https://s3.example.test/media' );
isxm_test_configure( array( 'endpoint' => 'https://s3.example.test', 'path_style' => false ) );
t( 'S4 public base URL, virtual-hosted', ISXM_Settings::public_base_url() === 'https://media.s3.example.test' );
isxm_test_configure( array( 'endpoint' => 'http://minio.local:9000' ), array( 'force_https' => true ) );
t( 'S5 Force HTTPS never upgrades an http:// endpoint', ISXM_Settings::public_base_url() === 'http://minio.local:9000/media' );
isxm_test_configure( array(), array( 'cdn_domain' => 'https://cdn.example.com/' ) );
t( 'S6 CDN domain wins, scheme/slash normalised', ISXM_Settings::public_base_url() === 'https://cdn.example.com' );
isxm_test_configure( array( 'endpoint' => '', 'region' => 'eu-west-1' ) );
t( 'S7 AWS default public URL', ISXM_Settings::public_base_url() === 'https://media.s3.eu-west-1.amazonaws.com' );
isxm_test_configure( array( 'endpoint' => 'https://s3.example.test' ), array( 'force_https' => false ) );
$GLOBALS['isxm_t']['options']['home'] = 'https://example.com';
$GLOBALS['isxm_t']['ssl']             = false; // e.g. WP-CLI / cron
$cli = ISXM_Settings::public_base_url();
$GLOBALS['isxm_t']['ssl']             = true;  // admin over https
t( 'S10 Force HTTPS off: scheme follows the Site Address, not the request', $cli === 'https://s3.example.test/media' && ISXM_Settings::public_base_url() === $cli, $cli );
$GLOBALS['isxm_t']['options']['home'] = 'http://example.com';
t( 'S11 ...an http site stays http', ISXM_Settings::public_base_url() === 'http://s3.example.test/media' );
$GLOBALS['isxm_t']['ssl'] = false;
isxm_test_configure();
t( 'S8 record on another bucket → its own base URL', ISXM_Settings::public_base_url_for( array( 'bucket' => 'old', 'endpoint' => 'http://old.local', 'path_style' => true ) ) === 'http://old.local/old' );
t( 'S9 record on the live destination → configured base URL', ISXM_Settings::public_base_url_for( array( 'bucket' => 'media', 'endpoint' => 'https://s3.example.test' ) ) === 'https://s3.example.test/media' );

// =====================================================================
group( 'Offload: records, keys, URLs' );

isxm_test_configure( array(), array( 'deliver_enabled' => true ) );
t( 'O1 bucket-root record (base_key "") is a real record — v0.2.5 regression', ISXM_Offload::has_record( array( 'base_key' => '', 'files' => array( 'a.jpg' ) ) ) );
t( 'O2 record without files is not', ! ISXM_Offload::has_record( array( 'base_key' => 'x/', 'files' => array() ) ) && ! ISXM_Offload::has_record( null ) );
t( 'O3 key_path root → "" (no double slash)', ISXM_Offload::key_path( array( 'base_key' => '' ) ) === '' );
t( 'O4 key_path encodes each segment', ISXM_Offload::key_path( array( 'base_key' => 'wp/2025 08/' ) ) === 'wp/2025%2008/' );
$info = array( 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => '', 'files' => array( 'a b.jpg' ) );
t( 'O5 remote URL for a root object', ISXM_Offload::build_remote_url( $info, 'a b.jpg' ) === 'https://s3.example.test/media/a%20b.jpg' );

isxm_test_add_post( 10, array() );
update_post_meta( 10, ISXM_Offload::META_KEY, array( 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => '2025/08/', 'files' => array( 'a.jpg', 'a-300x200.jpg' ), 'missing' => array( 'a-1024x768.jpg' ) ) );
$o = ( new ReflectionClass( 'ISXM_Offload' ) )->newInstanceWithoutConstructor();
t( 'O6 remote_url for an uploaded size', $o->remote_url( 10, 'a-300x200.jpg' ) === 'https://s3.example.test/media/2025/08/a-300x200.jpg' );
t( 'O7 a size in "missing" is never rewritten (would 404)', $o->remote_url( 10, 'a-1024x768.jpg' ) === false );
isxm_test_configure( array(), array( 'deliver_enabled' => false ) );
t( 'O8 delivery off → no rewrite', $o->remote_url( 10, 'a.jpg' ) === false );

isxm_test_configure();
t( 'O9 status: partial when sizes missing on the live bucket', ISXM_Offload::status_for( 10 )['status'] === 'partial' );
update_post_meta( 11, ISXM_Offload::META_KEY, array( 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => '', 'files' => array( 'b.jpg' ) ) );
t( 'O10 status: offloaded', ISXM_Offload::status_for( 11 )['status'] === 'offloaded' );
update_post_meta( 12, ISXM_Offload::META_KEY, array( 'bucket' => 'old', 'endpoint' => 'https://s3.example.test', 'base_key' => '', 'files' => array( 'c.jpg' ) ) );
t( 'O11 status: other_bucket', ISXM_Offload::status_for( 12 )['status'] === 'other_bucket' );
ISXM_Offload::record_error( 13, new WP_Error( 'x', 'boom' ) );
ISXM_Offload::record_error( 13, new WP_Error( 'x', 'boom again' ) );
t( 'O12 status: failed, tries counted', ISXM_Offload::status_for( 13 )['status'] === 'failed' && get_post_meta( 13, ISXM_Offload::ERROR_META_KEY, true )['tries'] === 2 );
t( 'O13 status: pending', ISXM_Offload::status_for( 14 )['status'] === 'pending' );

foreach ( array( '../../wp-config.php' => 'wp-config.php', '.htaccess' => '', "a\nb.jpg" => '', 'a\\b.jpg' => 'b.jpg', '..' => '', 'ok-300x200.jpg' => 'ok-300x200.jpg', '' => '' ) as $in => $want ) {
	t( 'O14 safe_filename(' . json_encode( $in ) . ')', ISXM_Offload::safe_filename( $in ) === $want, json_encode( ISXM_Offload::safe_filename( $in ) ) );
}
$names = ISXM_Offload::collect_filenames( '/u/2025/08/a.jpg', array(
	'original_image' => 'a-orig.jpg',
	'sizes'          => array( array( 'file' => 'a-300x200.jpg' ), array( 'file' => 'a-300x200.jpg' ), array( 'file' => '../../evil.php' ), array( 'file' => '.htaccess' ) ),
) );
t( 'O15 collect_filenames: primary first, deduped, unsafe dropped', $names === array( 'a.jpg', 'a-orig.jpg', 'a-300x200.jpg', 'evil.php' ), json_encode( $names ) );

$rel = function ( $meta ) {
	update_post_meta( 20, '_wp_attached_file', $meta );
	return ISXM_Offload::relative_local_path( 20 );
};
t( 'O16 relative meta kept', $rel( '2025/08/a.jpg' ) === '2025/08/a.jpg' );
t( 'O17 absolute path under this uploads dir stripped', $rel( ISXM_TEST_UPLOADS . '/2025/08/a.jpg' ) === '2025/08/a.jpg' );
t( 'O18 absolute path from another server normalised', $rel( '/home/old/public_html/wp-content/uploads/2017/08/a.jpg' ) === '2017/08/a.jpg' );
t( 'O19 traversal in the directory part refused', $rel( '2025/../../../etc/passwd' ) === null && $rel( './a.jpg' ) === null );
t( 'O20 empty meta → null', $rel( '' ) === null );

// Content/URL rewrite.
isxm_test_configure( array(), array( 'deliver_enabled' => true, 'persist_urls' => true ) );
update_post_meta( 30, '_wp_attached_file', '2025/08/p.jpg' );
update_post_meta( 30, ISXM_Offload::META_KEY, array( 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => 'site/2025/08/', 'files' => array( 'p.jpg', 'p-300x200.jpg' ) ) );
$GLOBALS['isxm_t']['url_ids']['https://example.com/wp-content/uploads/2025/08/p.jpg'] = 30;
t( 'O21 resolve_local_url maps a sized URL via the original', ISXM_Offload::resolve_local_url( 'http://example.com/wp-content/uploads/2025/08/p-300x200.jpg' ) === 'https://s3.example.test/media/site/2025/08/p-300x200.jpg' );
t( 'O22 foreign URL ignored', ISXM_Offload::resolve_local_url( 'https://other.com/wp-content/uploads/2025/08/p.jpg' ) === null );
$html = '<img src="https://example.com/wp-content/uploads/2025/08/p.jpg"><img src="https://example.com/wp-content/uploads/2025/08/unknown.jpg">';
$out  = $o->rewrite_content_urls( $html );
t( 'O23 content rewrite: known replaced, unknown left alone', $out === '<img src="https://s3.example.test/media/site/2025/08/p.jpg"><img src="https://example.com/wp-content/uploads/2025/08/unknown.jpg">', $out );
$pairs = ISXM_Offload::collect_url_pairs( 30 );
$olds  = array_column( $pairs, 'old' );
$plain = array_filter( $olds, function ( $u ) { return strpos( $u, '\\/' ) === false; } );
t( 'O24 permanent-URL pairs cover http + https for every file', count( $plain ) === 4 && in_array( 'http://example.com/wp-content/uploads/2025/08/p-300x200.jpg', $olds, true ) );

// Escaped-slash JSON (Elementor & co.) is rewritten too.
$pairs = ISXM_Offload::collect_url_pairs( 30 );
$olds  = array_column( $pairs, 'old' );
t( 'O25 permanent-URL pairs include the escaped-slash JSON form', in_array( 'https:\/\/example.com\/wp-content\/uploads\/2025\/08\/p.jpg', $olds, true ), json_encode( array_slice( $olds, -2 ) ) );
$json = wp_json_encode( array( 'image' => array( 'url' => 'https://example.com/wp-content/uploads/2025/08/p-300x200.jpg' ) ) );
$map  = ISXM_Offload::local_url_map( $json );
$out  = ISXM_DB_Rewriter::recursive_replace_pairs( $json, $map );
$dec  = json_decode( $out, true );
t( 'O26 bulk map finds escaped URLs and the JSON stays valid', is_array( $dec ) && $dec['image']['url'] === 'https://s3.example.test/media/site/2025/08/p-300x200.jpg', $out );
t( 'O27 bulk probe also matches escaped rows', ISXM_DB_Rewriter::uploads_url_probe_escaped() === '%https:\\\\/\\\\/example.com\\\\/wp-content\\\\/uploads%' || strpos( ISXM_DB_Rewriter::uploads_url_probe_escaped(), '\\\\/\\\\/example.com' ) !== false, ISXM_DB_Rewriter::uploads_url_probe_escaped() );
$rev = array_column( ISXM_Offload::reverse_url_pairs( 30, get_post_meta( 30, ISXM_Offload::META_KEY, true ) ), 'old' );
t( 'O28 Remove reverses the escaped form too', (bool) array_filter( $rev, function ( $u ) { return strpos( $u, 'https:\/\/s3.example.test\/media\/site' ) === 0; } ), json_encode( $rev ) );

// Custom upload_url_path + non-ASCII file names inside escaped JSON.
$GLOBALS['isxm_t']['baseurl'] = 'https://cdn.example.com/media';
update_post_meta( 31, '_wp_attached_file', '2025/09/ภาพ.jpg' );
update_post_meta( 31, ISXM_Offload::META_KEY, array( 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => 'site/2025/09/', 'files' => array( 'ภาพ.jpg' ) ) );
$GLOBALS['isxm_t']['url_ids']['https://cdn.example.com/media/2025/09/ภาพ.jpg'] = 31;
$json = wp_json_encode( array( 'img' => 'https://cdn.example.com/media/2025/09/ภาพ.jpg' ) ); // ภ… escapes
$out  = ISXM_DB_Rewriter::recursive_replace_pairs( $json, ISXM_Offload::local_url_map( $json ) );
$dec  = json_decode( $out, true );
t( 'O29 custom upload path + Thai name (\\u escapes) in JSON rewritten', is_array( $dec ) && $dec['img'] === 'https://s3.example.test/media/site/2025/09/' . rawurlencode( 'ภาพ.jpg' ), $out );
unset( $GLOBALS['isxm_t']['baseurl'] );

// One LIKE per escaped directory, not one per file (scan count).
$GLOBALS['isxm_t']['sql'] = array();
ISXM_DB_Rewriter::replace_urls_bulk( ISXM_Offload::collect_url_pairs( 30 ) );
$first = isset( $GLOBALS['isxm_t']['sql'][0] ) ? $GLOBALS['isxm_t']['sql'][0] : '';
$likes = substr_count( $first, ' LIKE ' );
t( 'O30 escaped twins add one condition per directory, not per file', count( $GLOBALS['isxm_t']['sql'] ) === 3 && $likes === 4 + 2, "queries=" . count( $GLOBALS['isxm_t']['sql'] ) . " likes=$likes" );

// Content-type folders.
isxm_test_configure( array(), array( 'use_type_folder' => true ) );
isxm_test_add_post( 100, array( 'post_type' => 'product', 'post_name' => 'blue-shirt' ) );
isxm_test_add_post( 101, array( 'post_type' => 'product_variation', 'post_parent' => 100 ) );
isxm_test_add_post( 102, array( 'post_type' => 'post', 'post_name' => 'hello' ) );
isxm_test_add_post( 103, array( 'post_type' => 'promotion-carousel', 'post_name' => 'slide-1' ) );
isxm_test_add_post( 200, array( 'post_parent' => 100 ) );
isxm_test_add_post( 201, array( 'post_parent' => 100, 'post_mime_type' => 'application/zip' ) );
isxm_test_add_post( 202, array( 'post_parent' => 101 ) );
isxm_test_add_post( 203, array( 'post_parent' => 102 ) );
isxm_test_add_post( 204, array( 'post_parent' => 103 ) );
isxm_test_add_post( 205, array() );
t( 'T1 product image → products/<slug>', ISXM_Settings::type_folder_segment( 200 ) === 'products/blue-shirt' );
t( 'T2 product download → downloads/<slug>', ISXM_Settings::type_folder_segment( 201 ) === 'downloads/blue-shirt' );
t( 'T3 variation → its parent product', ISXM_Settings::type_folder_segment( 202 ) === 'products/blue-shirt' );
t( 'T4 post → posts/<slug>', ISXM_Settings::type_folder_segment( 203 ) === 'posts/hello' );
t( 'T5 promotion → flat folder', ISXM_Settings::type_folder_segment( 204 ) === 'promotions' );
t( 'T6 unattached → none', ISXM_Settings::type_folder_segment( 205 ) === '' );
isxm_test_configure( array(), array( 'use_type_folder' => false ) );
t( 'T7 feature off → none', ISXM_Settings::type_folder_segment( 200 ) === '' );

// =====================================================================
group( 'Offload: end-to-end upload to the fake S3' );

$uploads = ISXM_TEST_UPLOADS . '/2025/08';
@mkdir( $uploads, 0777, true );
foreach ( array( 'photo.jpg', 'photo-300x200.jpg' ) as $f ) {
	file_put_contents( "$uploads/$f", "data:$f" );
}
@unlink( "$uploads/photo-1024x768.jpg" );
$meta = array( 'file' => '2025/08/photo.jpg', 'sizes' => array( 'medium' => array( 'file' => 'photo-300x200.jpg' ), 'large' => array( 'file' => 'photo-1024x768.jpg' ) ) );

isxm_test_configure( array( 'endpoint' => "http://127.0.0.1:$fake_port" ), array( 'use_prefix' => true, 'prefix' => 'site', 'use_year_month' => true, 'use_object_version' => true, 'persist_urls' => false ) );
isxm_test_add_post( 50, array() );
update_post_meta( 50, '_wp_attached_file', '2025/08/photo.jpg' );
fake_s3_reset();
$r      = $o->offload_attachment( 50, $meta );
$record = get_post_meta( 50, ISXM_Offload::META_KEY, true );
$uris   = array_column( fake_s3_requests(), 'uri' );
t( 'E1 offload succeeds', $r === true, is_wp_error( $r ) ? $r->get_error_message() : '' );
t( 'E2 key = prefix / year-month / version / file', $record['base_key'] === 'site/2025/08/10000000/', $record['base_key'] ?? '' );
t( 'E3 every existing size uploaded', $uris === array( '/media/site/2025/08/10000000/photo.jpg', '/media/site/2025/08/10000000/photo-300x200.jpg' ), json_encode( $uris ) );
t( 'E4 absent size recorded as missing, not fatal', $record['files'] === array( 'photo.jpg', 'photo-300x200.jpg' ) && $record['missing'] === array( 'photo-1024x768.jpg' ) );
t( 'E5 record pins bucket + endpoint', $record['bucket'] === 'media' && $record['endpoint'] === "http://127.0.0.1:$fake_port" );
t( 'E6 local files kept (Remove Local off)', is_file( "$uploads/photo.jpg" ) );
fake_s3_reset();
$o->offload_attachment( 50, $meta );
t( 'E7 re-offload reuses the object version (stable URLs)', get_post_meta( 50, ISXM_Offload::META_KEY, true )['version'] === '10000000' );

isxm_test_configure( array( 'endpoint' => "http://127.0.0.1:$fake_port" ), array( 'use_prefix' => false, 'use_type_folder' => true, 'use_year_month' => true, 'use_object_version' => true, 'persist_urls' => false ) );
isxm_test_add_post( 100, array( 'post_type' => 'product', 'post_name' => 'blue-shirt' ) );
isxm_test_add_post( 51, array( 'post_parent' => 100 ) );
update_post_meta( 51, '_wp_attached_file', '2025/08/photo.jpg' );
$o->offload_attachment( 51, array( 'file' => '2025/08/photo.jpg' ) );
$r8 = get_post_meta( 51, ISXM_Offload::META_KEY, true );
t( 'E8 content-type folder flattens year/month + version', is_array( $r8 ) && $r8['base_key'] === 'products/blue-shirt/', json_encode( $r8 ) );

file_put_contents( "$uploads/deny-original.jpg", 'x' );
isxm_test_add_post( 52, array() );
update_post_meta( 52, '_wp_attached_file', '2025/08/deny-original.jpg' );
$r = $o->offload_attachment( 52, array() );
t( 'E9 failed original → error, no record written', is_wp_error( $r ) && $r->get_error_code() === 'isxs_upload_failed' && get_post_meta( 52, ISXM_Offload::META_KEY, true ) === '' );

isxm_test_add_post( 53, array() );
update_post_meta( 53, '_wp_attached_file', '2025/08/not-there.jpg' );
$r = $o->offload_attachment( 53, array() );
t( 'E10 missing original → isxs_missing_local', is_wp_error( $r ) && $r->get_error_code() === 'isxs_missing_local' );
update_post_meta( 53, '_wp_attached_file', '../../etc/passwd' );
$r = $o->offload_attachment( 53, array() );
t( 'E11 traversal meta → isxs_bad_meta', is_wp_error( $r ) && $r->get_error_code() === 'isxs_bad_meta' );
$GLOBALS['isxm_t']['options']['isxs_connections'] = array();
ISXM_Settings::flush_cache();
$r = $o->offload_attachment( 50, $meta );
t( 'E12 not configured → isxs_not_configured', is_wp_error( $r ) && $r->get_error_code() === 'isxs_not_configured' );

isxm_test_configure( array( 'endpoint' => "http://127.0.0.1:$fake_port" ), array( 'remove_local' => true, 'persist_urls' => false, 'deliver_enabled' => false, 'use_object_version' => false ) );
$o->offload_attachment( 50, $meta );
t( 'E13 Remove Local refused while nothing keeps URLs resolvable', is_file( "$uploads/photo.jpg" ) );
isxm_test_configure( array( 'endpoint' => "http://127.0.0.1:$fake_port" ), array( 'remove_local' => true, 'persist_urls' => false, 'deliver_enabled' => true, 'use_object_version' => false ) );
$o->offload_attachment( 50, $meta );
t( 'E14 Remove Local deletes once delivery keeps URLs working', ! is_file( "$uploads/photo.jpg" ) && ! is_file( "$uploads/photo-300x200.jpg" ) );

// Remove from Bucket must never delete an object it failed to bring home.
isxm_test_configure( array(), array( 'persist_urls' => false ) );
$dir = ISXM_TEST_UPLOADS . '/2024/01';
@mkdir( $dir, 0777, true );
foreach ( array( 'r.jpg', 'r-300x200.jpg', 'r-1024x768.jpg' ) as $f ) {
	@unlink( "$dir/$f" );
}
update_post_meta( 70, '_wp_attached_file', '2024/01/r.jpg' );
update_post_meta( 70, ISXM_Offload::META_KEY, array( 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => '2024/01/', 'files' => array( 'r.jpg', 'r-300x200.jpg', 'r-1024x768.jpg' ) ) );
$GLOBALS['isxm_t']['http'] = array();
isxm_test_queue_response( 200, 'original' );       // GET r.jpg
isxm_test_queue_response( 404 );                   // GET r-300x200.jpg — gone from the bucket
isxm_test_queue_response( 200, 'large' );          // GET r-1024x768.jpg (if it is ever asked for)
$o->remove_remote_attachment( 70 );
$unsafe = array();
foreach ( $GLOBALS['isxm_t']['http'] as $call ) {
	if ( $call['args']['method'] === 'DELETE' ) {
		$name = rawurldecode( basename( parse_url( $call['url'], PHP_URL_PATH ) ) );
		if ( $name !== 'r-300x200.jpg' && ! is_file( "$dir/$name" ) ) {
			$unsafe[] = $name;
		}
	}
}
t( 'E15 Remove from Bucket never deletes an object that has no local copy', empty( $unsafe ), 'deleted without a local copy: ' . implode( ', ', $unsafe ) );

// Sold files never go to the (public) media bucket.
@mkdir( ISXM_TEST_UPLOADS . '/woocommerce_uploads/2025/08', 0777, true );
file_put_contents( ISXM_TEST_UPLOADS . '/woocommerce_uploads/2025/08/ebook.pdf', 'paid' );
isxm_test_configure( array( 'endpoint' => "http://127.0.0.1:$fake_port" ), array( 'persist_urls' => false ) );
isxm_test_add_post( 80, array( 'post_mime_type' => 'application/pdf' ) );
update_post_meta( 80, '_wp_attached_file', 'woocommerce_uploads/2025/08/ebook.pdf' );
fake_s3_reset();
$r = $o->offload_attachment( 80, array() );
t( 'W1 WooCommerce protected download is never uploaded', is_wp_error( $r ) && $r->get_error_code() === 'isxs_protected_download' && fake_s3_requests() === array() );
t( 'W2 ...and is not flagged as a failure', get_post_meta( 80, ISXM_Offload::ERROR_META_KEY, true ) === '' && ISXM_Offload::status_for( 80 )['status'] === 'pending' );
update_post_meta( 81, '_wp_attached_file', 'edd/2025/08/x.zip' );
t( 'W3 Easy Digital Downloads folder protected too', ISXM_Offload::is_protected_download( 81 ) && ! ISXM_Offload::is_protected_download( 50 ) );

$aws = new ISXM_Client( array( 'endpoint' => 'https://s3.amazonaws.com', 'region' => 'us-east-1', 'bucket' => 'examplebucket', 'access_key' => 'AKIAIOSFODNN7EXAMPLE', 'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'path_style' => false ) );
$u   = $aws->presigned_url( 'test.txt', 86400, gmmktime( 0, 0, 0, 5, 24, 2013 ) );
t( 'W4 presigned URL matches the official AWS SigV4 test vector', substr( $u, -64 ) === 'aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $u );

isxm_test_configure( array( 'endpoint' => 'https://s3.example.test' ), array( 'cdn_domain' => 'cdn.example.com' ) );
$signed = ISXM_WC_Downloads::presign_download_path( 'https://cdn.example.com/downloads/book%20one.pdf' );
t( 'W5 bucket/CDN download URL → presigned S3 link for the same key', strpos( $signed, 'https://s3.example.test/media/downloads/book%20one.pdf?X-Amz-Algorithm=' ) === 0 && strpos( $signed, 'X-Amz-Expires=300' ) !== false, $signed );
update_post_meta( 82, ISXM_Offload::META_KEY, array( 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => 'dl/', 'files' => array( 'guide.pdf' ) ) );
$GLOBALS['isxm_t']['url_ids']['https://example.com/wp-content/uploads/2025/08/guide.pdf'] = 82;
$signed = ISXM_WC_Downloads::presign_download_path( 'https://example.com/wp-content/uploads/2025/08/guide.pdf' );
t( 'W6 local URL of an offloaded file → presigned link', strpos( $signed, 'https://s3.example.test/media/dl/guide.pdf?' ) === 0, $signed );
t( 'W7 anything else passes through untouched', ISXM_WC_Downloads::presign_download_path( 'https://example.com/wp-content/uploads/2025/08/other.pdf' ) === 'https://example.com/wp-content/uploads/2025/08/other.pdf'
	&& ISXM_WC_Downloads::presign_download_path( 'https://elsewhere.test/file.zip' ) === 'https://elsewhere.test/file.zip' );

// Migrate between two providers whose buckets share a name.
$GLOBALS['isxm_t']['options']['isxs_connections'] = array(
	'custom' => array( 'endpoint' => "http://127.0.0.1:$fake_port", 'region' => 'us-east-1', 'bucket' => 'media', 'access_key' => 'AK', 'secret_key' => ISXM_Crypto::encrypt( SECRET ), 'path_style' => true ),
	'minio'  => array( 'endpoint' => 'https://old.test', 'region' => 'us-east-1', 'bucket' => 'media', 'access_key' => 'AK2', 'secret_key' => ISXM_Crypto::encrypt( 'old' ), 'path_style' => true ),
);
$GLOBALS['isxm_t']['options']['isxs_settings'] = array( 'provider' => 'custom', 'source_provider' => 'minio', 'source_prefix' => 'wp-content/uploads/', 'source_use_year_month' => true,
	'use_prefix' => false, 'use_year_month' => true, 'use_object_version' => false, 'persist_urls' => false );
ISXM_Settings::flush_cache();
@mkdir( ISXM_TEST_UPLOADS . '/2023/05', 0777, true );
@unlink( ISXM_TEST_UPLOADS . '/2023/05/m.jpg' );
isxm_test_add_post( 90, array() );
update_post_meta( 90, '_wp_attached_file', '2023/05/m.jpg' );
update_post_meta( 90, ISXM_Offload::META_KEY, array( 'bucket' => 'media', 'endpoint' => 'https://old.test', 'base_key' => 'wp-content/uploads/2023/05/', 'files' => array( 'm.jpg' ) ) );
$GLOBALS['isxm_t']['wpdb_results'] = array( array( (object) array( 'post_id' => 90, 'meta_value' => '2023/05/m.jpg' ) ) );
isxm_test_queue_response( 200, '<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>wp-content/uploads/2023/05/m.jpg</Key></Contents></ListBucketResult>' );
isxm_test_queue_response( 200, 'image-bytes' );
fake_s3_reset();
$mig = new ReflectionMethod( 'ISXM_Tools', 'run_migrate_batch' );
if ( PHP_VERSION_ID < 80100 ) {
	$mig->setAccessible( true );
}
$mig->invoke( ( new ReflectionClass( 'ISXM_Tools' ) )->newInstanceWithoutConstructor(), '', false );
$after = get_post_meta( 90, ISXM_Offload::META_KEY, true );
t( 'E16 Migrate copies an item offloaded to a same-named bucket on another endpoint', in_array( '/media/2023/05/m.jpg', array_column( fake_s3_requests(), 'uri' ), true ) && $after['endpoint'] === "http://127.0.0.1:$fake_port", json_encode( array( array_column( fake_s3_requests(), 'uri' ), $after['endpoint'] ?? null ) ) );

// =====================================================================
group( 'Migrate + Sync + Assets' );

isxm_test_configure( array(), array( 'source_prefix' => 'wp-content/uploads/', 'source_use_year_month' => true, 'source_provider' => 'custom' ) );
update_post_meta( 60, '_wp_attached_file', '2019/03/old.jpg' );
t( 'M1 source key base = prefix + year/month', ISXM_Migrate::source_key_base( 60 ) === 'wp-content/uploads/2019/03/' );
isxm_test_configure( array(), array( 'source_prefix' => '', 'source_use_year_month' => false ) );
t( 'M2 source at bucket root', ISXM_Migrate::source_key_base( 60 ) === '' );
t( 'M3 sized filename → canonical original', isxm_call( 'ISXM_Migrate', 'canonical_filename', 'photo-1024x768.jpg' ) === 'photo.jpg' && isxm_call( 'ISXM_Migrate', 'canonical_filename', 'logo-v2.png' ) === 'logo-v2.png' );

$info = array( 'base_key' => 'wp/2025/', 'files' => array( 'a.jpg', '../x', 'a-300x200.jpg' ) );
$info['files'][] = '.htaccess';
t( 'Y1 expected keys reduce names to safe basenames', ISXM_Sync::expected_keys( $info ) === array( 'wp/2025/a.jpg', 'wp/2025/x', 'wp/2025/a-300x200.jpg' ), json_encode( ISXM_Sync::expected_keys( $info ) ) );
t( 'Y2 primary key = first file', ISXM_Sync::primary_key( $info ) === 'wp/2025/a.jpg' );
$run = 'unittest' . getmypid();
ISXM_Sync::cleanup_run_files( $run );
ISXM_Sync::append_expected( $run, array( array( 'k/a.jpg', 7 ), array( 'k/a-1.jpg', 7 ), array( 'k/b.jpg', 8 ), array( '', 9 ), array( 'bad' ) ) );
$e = ISXM_Sync::load_expected( $run );
t( 'Y3 expected map rebuilt from disk, junk lines skipped', $e['counts'] === array( 7 => 2, 8 => 1 ) && $e['primary'][7] === 'k/a.jpg' && count( $e['map'] ) === 3 );
$res = isxm_call( 'ISXM_Sync', 'classify', array( 'counts' => array( 7 => 2, 8 => 1, 9 => 1 ), 'attach_count' => 3 ), array( 7 => 1, 8 => 1 ), array( 7 => true, 8 => true ), 0, array(), 0, 10 );
t( 'Y4 classify: primary gone = stale, sizes gone = partial', $res['stale_ids'] === array( 9 ) && $res['partial_ids'] === array( 7 ) );
t( 'Y5 verdict clean only with nothing to fix', ! ISXM_Sync::result_is_clean( $res ) && ISXM_Sync::result_is_clean( array( 'stale_ids' => array(), 'partial_ids' => array(), 'orphan' => 0, 'outside_prefix' => 0 ) ) );
ISXM_Sync::mark_run( false );
t( 'Y6 last run + verdict remembered', ISXM_Sync::last_run() !== null && ISXM_Sync::last_clean() === false && ! ISXM_Sync::is_stale() );
ISXM_Sync::cleanup_run_files( $run );
t( 'Y7 run files cleaned up', ! is_file( ISXM_Sync::expected_file( $run ) ) );
t( 'Y8 sync state dir is not web-browsable', is_file( ISXM_Sync::state_dir() . '/.htaccess' ) && is_file( ISXM_Sync::state_dir() . '/index.php' ) );

isxm_test_configure( array(), array( 'use_prefix' => false ) );
$GLOBALS['isxm_t']['http'] = array();
$r = ISXM_Sync::cleanup_orphans();
t( 'Y9 orphan cleanup refused without a prefix (whole bucket), nothing listed or deleted', is_wp_error( $r ) && $r->get_error_code() === 'isxs_orphan_no_prefix' && empty( $GLOBALS['isxm_t']['http'] ) );
isxm_test_configure( array(), array( 'use_prefix' => true, 'prefix' => 'site-a/' ) );
t( 'Y10 orphan cleanup allowed once a prefix scopes it', ISXM_Sync::orphan_cleanup_blocked() === null );

$now  = 1790000000;
$page = array(
	'keys'     => array( 'site-a/kept.jpg', 'site-a/old-orphan.jpg', 'site-a/just-uploaded.jpg', 'site-a/no-date.jpg', 'other/old.jpg' ),
	'modified' => array( 'site-a/kept.jpg' => $now - 9999, 'site-a/old-orphan.jpg' => $now - 9999, 'site-a/just-uploaded.jpg' => $now - 30, 'other/old.jpg' => $now - 9999 ),
);
$cand = ISXM_Sync::orphan_candidates( $page, array( 'map' => array( 'site-a/kept.jpg' => 5 ) ), 'site-a/', $now );
t( 'Y11 only old, unreferenced, in-prefix, dated objects are orphan candidates', $cand === array( 'site-a/old-orphan.jpg' ), json_encode( $cand ) );
t( 'Y12 no tracked media on the destination → cleanup refused', is_wp_error( ISXM_Sync::orphan_map_blocked( array( 'map' => array(), 'counts' => array() ) ) ) && ISXM_Sync::orphan_map_blocked( array( 'counts' => array( 5 => 1 ) ) ) === null );
isxm_test_queue_response( 200, '<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>a.jpg</Key><LastModified>2026-09-01T10:00:00.000Z</LastModified></Contents></ListBucketResult>' );
$lp = ( new ISXM_Client() )->list_objects_keys_page();
t( 'Y13 listing returns LastModified per key', is_array( $lp ) && $lp['modified']['a.jpg'] === gmmktime( 10, 0, 0, 9, 1, 2026 ) );

$a = new ISXM_Assets();
isxm_test_configure( array(), array( 'assets_enabled' => true, 'assets_cdn_domain' => 'https://cdn.example.net/', 'assets_force_https' => true ) );
t( 'A1 same-site asset moved to the CDN, query kept', $a->rewrite_src( 'http://example.com/wp-content/themes/t/style.css?ver=1.2' ) === 'https://cdn.example.net/wp-content/themes/t/style.css?ver=1.2' );
t( 'A2 relative src resolved against the site', $a->rewrite_src( '/wp-includes/js/jquery.js' ) === 'https://cdn.example.net/wp-includes/js/jquery.js' );
t( 'A3 third-party asset untouched', $a->rewrite_src( 'https://fonts.googleapis.com/css?family=x' ) === 'https://fonts.googleapis.com/css?family=x' );
t( 'A4 already on the CDN untouched', $a->rewrite_src( 'https://cdn.example.net/a.js' ) === 'https://cdn.example.net/a.js' );
isxm_test_configure( array(), array( 'assets_enabled' => false, 'assets_cdn_domain' => 'cdn.example.net' ) );
t( 'A5 feature off → untouched', $a->rewrite_src( 'https://example.com/a.js' ) === 'https://example.com/a.js' );

// =====================================================================
group( 'Jobs' );

$job = ISXM_Job::start( 'offload', 200 );
t( 'J1 start() persists a running job', ISXM_Job::get( 'offload' )->state === ISXM_Job::STATE_RUNNING && ISXM_Job::get( 'offload' )->total === 200 );
for ( $i = 0; $i < 3; $i++ ) {
	$job->record_batch( 20, 20 * ( $i + 1 ), $i === 0 ? array_fill( 0, 60, 'err' ) : array(), 2 );
}
$p = $job->to_payload();
t( 'J2 processed/percent/eta from measured batches', $p['processed'] === 60 && $p['percent'] === 30 && $p['eta_seconds'] === 14, json_encode( array( $p['processed'], $p['percent'], $p['eta_seconds'] ) ) );
t( 'J3 error list capped at 50, count kept exact', count( $job->errors ) === 50 && $job->error_count === 60 );
$job->finish( ISXM_Job::STATE_PAUSED );
t( 'J4 paused job is resumable', ISXM_Job::get( 'offload' )->is_resumable() );
$job->finish( ISXM_Job::STATE_DONE );
t( 'J5 done → 100% and total = processed', $job->to_payload()['percent'] === 100 && $job->to_payload()['total'] === 60 );

$old_run = ISXM_Job::start( 'remove' );
usleep( 2000 );
$new_run = ISXM_Job::start( 'remove' );
t( 'J6 a superseded run cannot overwrite the newer one', $old_run->save() === false && ISXM_Job::get( 'remove' )->run_id === $new_run->run_id );
$old_run->delete();
t( 'J7 nor delete it', ISXM_Job::get( 'remove' ) !== null );

ISXM_Job::set_signal( 'migrate', 'pause' );
t( 'J8 pause signal readable', ISXM_Job::signal( 'migrate' ) === 'pause' );
ISXM_Job::set_signal( 'migrate', 'rm -rf' );
t( 'J9 unknown signal ignored', ISXM_Job::signal( 'migrate' ) === 'pause' );
ISXM_Job::clear_signal( 'migrate' );
t( 'J10 signal cleared', ISXM_Job::signal( 'migrate' ) === '' );

$stale = ISXM_Job::start( 'download' );
$stale->updated = microtime( true ) - ISXM_Job::STALL_SECONDS - 5;
t( 'J11 no progress for STALL_SECONDS → stalled', $stale->is_stalled() );
$GLOBALS['isxm_t']['options']['isxs_job_offload']['updated'] = microtime( true ) - ISXM_Job::TERMINAL_TTL - 5;
ISXM_Job::prune();
t( 'J12 old finished jobs pruned, running ones kept', ISXM_Job::get( 'offload' ) === null && ISXM_Job::get( 'download' ) !== null );

$GLOBALS['isxm_t']['options']['home'] = 'http://example.com';
$GLOBALS['isxm_t']['home']            = 'https://example.com'; // home_url() under an https admin request
$GLOBALS['isxm_t']['ssl']             = false;
$t1 = isxm_call( 'ISXM_Background', 'token' );
$GLOBALS['isxm_t']['ssl'] = true;
$t2 = isxm_call( 'ISXM_Background', 'token' );
t( 'J13 loopback token stable across http/https requests (no rotate-and-403 loop)', strlen( $t1 ) >= 32 && $t1 === $t2 );
$GLOBALS['isxm_t']['options']['home'] = 'https://moved.example.com';
t( 'J14 ...but still rotates when the Site Address really changes', isxm_call( 'ISXM_Background', 'token' ) !== $t1 );
$GLOBALS['isxm_t']['ssl'] = false;

// =====================================================================
group( 'Admin input sanitizers' );

$p = function ( $v ) { return isxm_call( 'ISXM_Tools', 'sanitize_prefix', $v ); };
t( 'P1 prefix normalised', $p( '/site-a' ) === 'site-a/' && $p( '' ) === '' );
t( 'P2 ".." and backslashes stripped', $p( '../../etc' ) === '//etc/' || strpos( $p( '../../etc' ), '..' ) === false );
t( 'P3 control characters stripped', strpos( $p( "a\x00b\x1f" ), "\x00" ) === false );
t( 'P4 prefix never keeps a "." path segment that browsers collapse', ! preg_match( '#(^|/)\.{1,2}(/|$)#', $p( '.../x' ) ), json_encode( $p( '.../x' ) ) );
$d = function ( $v ) { return isxm_call( 'ISXM_Tools', 'sanitize_cdn_domain', $v ); };
t( 'P5 CDN domain accepts host[:port], strips scheme/slash', $d( 'https://cdn.example.com/' ) === 'cdn.example.com' && $d( 'minio_lan:9000' ) === 'minio_lan:9000' );
t( 'P6 CDN domain rejects paths, spaces and script', $d( 'cdn.com/evil' ) === '' && $d( 'a b.com' ) === '' && $d( '"><script>' ) === '' );
t( 'P7 tool labels + unknown tool', ISXM_Tools::tool_label( 'sync-x' ) === 'sync-x' && ISXM_Tools::is_known_tool( 'offload' ) && ! ISXM_Tools::is_known_tool( 'nope' ) );

// =====================================================================
group( 'i18n' );

$admin_src = file_get_contents( ISXM_PLUGIN_DIR . 'includes/class-isxm-admin.php' );
$start     = strpos( $admin_src, "'i18n'       => [" );
preg_match_all( "/^\s*'(\w+)'\s*=>/m", substr( $admin_src, $start, strpos( $admin_src, '        ] );', $start ) - $start ), $m );
preg_match_all( '/\bi18n\.(\w+)/', file_get_contents( ISXM_PLUGIN_DIR . 'assets/js/admin.js' ), $used );
$missing = array_diff( array_unique( $used[1] ), $m[1] );
t( 'I1 every i18n.<key> used by admin.js is localized', empty( $missing ), implode( ', ', $missing ) );

$wrong = array();
foreach ( array_merge( glob( ISXM_PLUGIN_DIR . 'includes/*.php' ), array( ISXM_PLUGIN_DIR . 'insightx-offload.php' ) ) as $f ) {
	if ( preg_match_all( "/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(['\"])(?:(?!\\1).|\\\\\\1)*\\1\s*,\s*'([^']+)'/", file_get_contents( $f ), $mm ) ) {
		foreach ( $mm[2] as $domain ) {
			if ( $domain !== 'insightx-offload' ) {
				$wrong[] = basename( $f ) . ":$domain";
			}
		}
	}
}
t( 'I2 every translation call uses the insightx-offload domain', empty( $wrong ), implode( ', ', $wrong ) );
$po = ISXM_PLUGIN_DIR . 'languages/insightx-offload-th.po';
t( 'I3 Thai .po and compiled .mo shipped', is_file( $po ) && is_file( substr( $po, 0, -2 ) . 'mo' ) );
$leftover = array();
foreach ( array_merge( glob( ISXM_PLUGIN_DIR . 'includes/*.php' ), glob( ISXM_PLUGIN_DIR . 'assets/js/*.js' ) ) as $f ) {
	foreach ( file( $f ) as $n => $line ) {
		if ( preg_match( '/\p{Thai}/u', $line ) && ! preg_match( '#^\s*(//|\*|/\*)#', $line ) ) {
			$leftover[] = basename( $f ) . ':' . ( $n + 1 );
		}
	}
}
t( 'I4 no hard-coded Thai left outside comments', empty( $leftover ), implode( ', ', array_slice( $leftover, 0, 5 ) ) );

// =====================================================================
echo "\n========================================\n";
echo "TOTAL: {$GLOBALS['isxm_pass']} passed, {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] === 0 ? 0 : 1 );
