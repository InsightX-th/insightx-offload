<?php
/**
 * Minimal WordPress stand-in for the unit suite (tests/run.php).
 *
 * Loads the REAL plugin classes; stubs only the WordPress functions they
 * call, backed by in-memory stores (options, post meta, posts) that each
 * test resets with isxm_test_reset(). HTTP goes through a fake
 * wp_remote_request() that records every call and replays queued responses,
 * so the S3 client can be tested without a network.
 *
 * No framework and no Composer: `php tests/run.php` is the whole setup.
 */

error_reporting( E_ALL );

define( 'ABSPATH', sys_get_temp_dir() . '/isxm_wp/' );
define( 'ISXM_TEST_UPLOADS', sys_get_temp_dir() . '/isxm_wp/wp-content/uploads' );
define( 'OBJECT', 'OBJECT' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

// ---------- In-memory state ----------
$GLOBALS['isxm_t'] = array();

function isxm_test_reset() {
	$GLOBALS['isxm_t'] = array(
		'options'   => array(),
		'meta'      => array(),
		'posts'     => array(),
		'http'      => array(),   // recorded wp_remote_request() calls
		'responses' => array(),   // queued responses (FIFO)
		'filters'   => array(),   // tag => callable, applied by apply_filters()
		'url_ids'   => array(),   // attachment_url_to_postid() map
		'home'      => 'https://example.com',
		'ssl'       => false,
	);
	if ( class_exists( 'ISXM_Settings' ) ) {
		ISXM_Settings::flush_cache();
	}
	$ref = class_exists( 'ISXM_Offload' ) ? new ReflectionProperty( 'ISXM_Offload', 'url_post_cache' ) : null;
	if ( $ref ) {
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$ref->setValue( null, array() );
	}
}
isxm_test_reset();

// ---------- WP_Error ----------
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

// ---------- i18n / escaping / sanitizing ----------
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return $text; }
function esc_url_raw( $url, $protocols = null ) {
	if ( $url === '' ) return '';
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( $scheme === '' ) { $url = 'http://' . $url; $scheme = 'http'; }
	$allowed = $protocols ? $protocols : array( 'http', 'https', 'ftp', 'gopher', 'mailto' );
	return in_array( $scheme, $allowed, true ) ? $url : '';
}
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }

// ---------- Hooks ----------
function add_action() { return true; }
function add_filter() { return true; }
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	if ( isset( $GLOBALS['isxm_t']['filters'][ $tag ] ) ) {
		return call_user_func_array( $GLOBALS['isxm_t']['filters'][ $tag ], array_slice( $args, 1 ) );
	}
	return $value;
}

// ---------- Utilities ----------
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_basename( $path, $suffix = '' ) { return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) ); }
function wp_normalize_path( $path ) { return preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', (string) $path ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || @mkdir( $dir, 0777, true ); }
function wp_salt( $scheme = 'auth' ) { return isset( $GLOBALS['isxm_t']['salt'] ) ? $GLOBALS['isxm_t']['salt'] : 'unit-test-salt'; }
function wp_generate_password( $length = 12 ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function wp_convert_hr_to_bytes( $value ) { return (int) $value * ( stripos( (string) $value, 'M' ) ? 1048576 : 1 ); }
function wp_check_filetype( $filename ) {
	$types = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', 'zip' => 'application/zip' );
	$ext   = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	return array( 'ext' => $ext, 'type' => isset( $types[ $ext ] ) ? $types[ $ext ] : false );
}
function wp_cache_delete() { return true; }
function _prime_post_caches() {}
function wp_list_pluck( $list, $field ) { return array_map( function ( $row ) use ( $field ) { return is_object( $row ) ? $row->$field : $row[ $field ]; }, $list ); }
function wp_get_attachment_metadata( $id ) { return get_post_meta( $id, '_wp_attachment_metadata', true ); }
function wp_delete_file( $file ) { @unlink( $file ); }
function current_time( $type ) { return $type === 'mysql' ? gmdate( 'Y-m-d H:i:s' ) : time(); }
function is_ssl() { return $GLOBALS['isxm_t']['ssl']; }
function is_admin() { return false; }
function is_feed() { return false; }
function home_url( $path = '' ) { return rtrim( $GLOBALS['isxm_t']['home'], '/' ) . '/' . ltrim( $path, '/' ); }
function wp_get_raw_referer() { return false; }
function taxonomy_exists() { return false; }
function get_term() { return null; }
function wp_get_upload_dir() {
	return array( 'basedir' => ISXM_TEST_UPLOADS, 'baseurl' => 'https://example.com/wp-content/uploads' );
}
function attachment_url_to_postid( $url ) {
	return isset( $GLOBALS['isxm_t']['url_ids'][ $url ] ) ? $GLOBALS['isxm_t']['url_ids'][ $url ] : 0;
}

/** Copy of WordPress core's is_serialized() (strict mode). */
function is_serialized( $data, $strict = true ) {
	if ( ! is_string( $data ) ) return false;
	$data = trim( $data );
	if ( 'N;' === $data ) return true;
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) return false;
	$lastc = substr( $data, -1 );
	if ( ';' !== $lastc && '}' !== $lastc ) return false;
	$token = $data[0];
	switch ( $token ) {
		case 's':
			if ( '"' !== substr( $data, -2, 1 ) ) return false;
			// fall through
		case 'a':
		case 'O':
		case 'E':
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b':
		case 'i':
		case 'd':
			return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$/", $data );
	}
	return false;
}

// ---------- Options / transients ----------
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['isxm_t']['options'] ) ? $GLOBALS['isxm_t']['options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) { $GLOBALS['isxm_t']['options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['isxm_t']['options'][ $key ] ); return true; }
function get_transient( $key ) { return get_option( '_transient_' . $key ); }
function set_transient( $key, $value ) { return update_option( '_transient_' . $key, $value ); }
function delete_transient( $key ) { return delete_option( '_transient_' . $key ); }

// ---------- Posts / meta ----------
function isxm_test_add_post( $id, array $fields ) {
	$GLOBALS['isxm_t']['posts'][ $id ] = (object) array_merge(
		array( 'ID' => $id, 'post_parent' => 0, 'post_type' => 'attachment', 'post_name' => '', 'post_title' => '', 'post_mime_type' => 'image/jpeg' ),
		$fields
	);
}
function get_post( $id ) { return isset( $GLOBALS['isxm_t']['posts'][ $id ] ) ? $GLOBALS['isxm_t']['posts'][ $id ] : null; }
function get_post_mime_type( $id ) { $p = get_post( $id ); return $p ? $p->post_mime_type : false; }
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['isxm_t']['meta'][ $id ][ $key ] ) ? $GLOBALS['isxm_t']['meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['isxm_t']['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['isxm_t']['meta'][ $id ][ $key ] ); return true; }
function get_attached_file( $id ) { return ISXM_TEST_UPLOADS . '/' . get_post_meta( $id, '_wp_attached_file', true ); }

// ---------- HTTP ----------
/** Queue the next wp_remote_request() response: status code + body. */
function isxm_test_queue_response( $code, $body = '' ) {
	$GLOBALS['isxm_t']['responses'][] = array( 'response' => array( 'code' => $code ), 'body' => $body );
}
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['isxm_t']['http'][] = array( 'url' => $url, 'args' => $args );
	$response = empty( $GLOBALS['isxm_t']['responses'] )
		? array( 'response' => array( 'code' => 200 ), 'body' => '' )
		: array_shift( $GLOBALS['isxm_t']['responses'] );
	// stream => true writes the body to 'filename', like the real HTTP API.
	if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
		file_put_contents( $args['filename'], $response['body'] );
	}
	return $response;
}
function wp_remote_retrieve_header( $r, $name ) { return ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) $r['body'] : ''; }

// ---------- $wpdb (only what loading/record writes touch) ----------
class ISXM_Test_WPDB {
	public $prefix   = 'wp_';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options  = 'wp_options';
	public $termmeta = 'wp_termmeta';
	public function prepare( $query ) { return $query; }
	public function get_col() { return array(); }
	public function get_results() { return empty( $GLOBALS['isxm_t']['wpdb_results'] ) ? array() : array_shift( $GLOBALS['isxm_t']['wpdb_results'] ); }
	public function __call( $name, $args ) { return null; }
}
$GLOBALS['wpdb'] = new ISXM_Test_WPDB();

// ---------- Plugin classes ----------
define( 'ISXM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function isxm_log_error( $message ) {}
foreach ( array(
	'crypto', 'connections', 'settings', 'client', 'db-rewriter', 'offload', 'migrate',
	'sync', 'items', 'job', 'tools', 'assets', 'wc-downloads', 'background',
) as $isxm_class ) {
	require_once ISXM_PLUGIN_DIR . 'includes/class-isxm-' . $isxm_class . '.php';
}

// ---------- Tiny assertion runner ----------
$GLOBALS['isxm_pass'] = 0;
$GLOBALS['isxm_fail'] = 0;
$GLOBALS['isxm_group'] = '';

function group( $name ) {
	$GLOBALS['isxm_group'] = $name;
	echo "\n=== $name ===\n";
	isxm_test_reset();
}
function t( $name, $cond, $extra = '' ) {
	if ( $cond ) {
		$GLOBALS['isxm_pass']++;
		echo "  PASS $name\n";
	} else {
		$GLOBALS['isxm_fail']++;
		echo "  FAIL [{$GLOBALS['isxm_group']}] $name" . ( $extra !== '' ? "  -> $extra" : '' ) . "\n";
	}
}
/** Call a private/protected static method. */
function isxm_call( $class, $method, ...$args ) {
	$m = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( null, $args );
}
/** Configure the destination connection + settings in one go. */
function isxm_test_configure( array $conn = array(), array $settings = array() ) {
	$GLOBALS['isxm_t']['options']['isxs_connections'] = array(
		'custom' => array_merge(
			array(
				'endpoint'   => 'https://s3.example.test',
				'region'     => 'us-east-1',
				'bucket'     => 'media',
				'access_key' => 'AKIDEXAMPLE',
				'secret_key' => ISXM_Crypto::encrypt( 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY' ),
				'path_style' => true,
			),
			$conn
		),
	);
	$GLOBALS['isxm_t']['options']['isxs_settings'] = array_merge( array( 'provider' => 'custom' ), $settings );
	ISXM_Settings::flush_cache();
}
