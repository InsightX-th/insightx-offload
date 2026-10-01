<?php
/**
 * Stages of the offload end-to-end test, run inside a throw-away WordPress
 * by tests/e2e/run.sh:  wp eval-file e2e.php <stage> <endpoint> <work-dir>
 *
 * Talks to a real S3-compatible server (SeaweedFS by default) that verifies
 * SigV4 signatures, so every request the plugin makes is checked for real.
 */

list( $stage, $endpoint, $work ) = $args;

const E2E_KEY    = 'e2eaccess';
const E2E_SECRET = 'e2esecret';

$fails = 0;
$check = function ( $name, $ok, $detail = '' ) use ( &$fails, $stage ) {
	WP_CLI::log( ( $ok ? '  PASS' : '  FAIL' ) . " [$stage] $name" . ( ! $ok && $detail !== '' ? "  -> $detail" : '' ) );
	if ( ! $ok ) {
		$fails++;
	}
};
$finish = function () use ( &$fails, $stage ) {
	if ( $fails > 0 ) {
		WP_CLI::error( "$fails check(s) failed ($stage)" );
	}
	WP_CLI::success( "$stage ok" );
};

/** Raw signed request through the plugin's own client (bucket create etc.). */
function e2e_request( ISXM_Client $c, $method, $key, array $args = array() ) {
	$m = new ReflectionMethod( 'ISXM_Client', 'request' );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invoke( $c, $method, $key, $args );
}
function e2e_keys( ISXM_Client $c, $prefix = '' ) {
	$page = $c->list_objects_keys_page( '', 1000, $prefix );
	return is_wp_error( $page ) ? array() : $page['keys'];
}
function e2e_settings( array $over ) {
	ISXM_Settings::save( array_merge( ISXM_Settings::all(), $over ) );
	ISXM_Settings::flush_cache();
}

switch ( $stage ) {

	case 'configure':
		foreach ( array( 'custom' => 'e2e-media', 'minio' => 'e2e-dest' ) as $slug => $bucket ) {
			ISXM_Connections::save_one( $slug, array( 'endpoint' => $endpoint, 'region' => 'us-east-1', 'bucket' => $bucket, 'access_key' => E2E_KEY, 'secret_key' => E2E_SECRET, 'path_style' => 1 ) );
			$c = new ISXM_Client( ISXM_Connections::get( $slug ) );
			e2e_request( $c, 'PUT', '' ); // CreateBucket (existing is fine)
			$check( "connection to $bucket works (real SigV4)", $c->test_connection() === true, wp_json_encode( $c->test_connection() ) );
		}
		$bad = new ISXM_Client( array( 'endpoint' => $endpoint, 'bucket' => 'e2e-media', 'access_key' => E2E_KEY, 'secret_key' => 'wrong', 'path_style' => true ) );
		$check( 'a wrong secret is rejected by the server', is_wp_error( $bad->test_connection() ) );
		e2e_settings( array(
			'provider'           => 'custom',
			'offload_enabled'    => false, // seeded first, offloaded in bulk
			'persist_urls'       => true,
			'deliver_enabled'    => true,
			'force_https'        => false,
			'use_prefix'         => true,
			'prefix'             => 'wp-content/uploads/',
			'use_year_month'     => true,
			'use_object_version' => false,
			'remove_local'       => false,
		) );
		$finish();
		break;

	case 'seed':
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids = array();
		foreach ( array( 'alpha', 'beta' ) as $i => $name ) {
			$img = imagecreatetruecolor( 1600, 1000 );
			imagefill( $img, 0, 0, imagecolorallocate( $img, 40 + 80 * $i, 120, 200 ) );
			$up   = wp_upload_dir();
			$path = $up['path'] . "/$name.jpg";
			imagejpeg( $img, $path, 85 );
			$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $path );
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
			$ids[] = $id;
		}
		$url  = wp_get_attachment_url( $ids[0] );
		$post = wp_insert_post( array( 'post_title' => 'E2E', 'post_status' => 'publish', 'post_content' => "<img src=\"$url\"> look-alike: " . str_replace( '/wp-content/uploads/', '/wp-content/uploads-old/', $url ) ) );
		update_post_meta( $post, '_elementor_data', wp_slash( wp_json_encode( array( array( 'settings' => array( 'image' => array( 'url' => $url ) ) ) ) ) ) );
		file_put_contents( "$work/ids.json", wp_json_encode( array( 'attachments' => $ids, 'post' => $post ) ) );
		$check( 'two images with generated sizes', count( wp_get_attachment_metadata( $ids[0] )['sizes'] ) >= 2 );
		$finish();
		break;

	case 'offloaded':
		$ids = json_decode( file_get_contents( "$work/ids.json" ), true );
		$c   = new ISXM_Client();
		$all = e2e_keys( $c );
		foreach ( $ids['attachments'] as $id ) {
			$info = ISXM_Offload::get_record( $id );
			$check( "#$id has a record on the destination", ISXM_Offload::has_record( $info ) && $info['bucket'] === 'e2e-media' );
			$dir  = dirname( get_attached_file( $id ) );
			foreach ( (array) ( $info['files'] ?? array() ) as $file ) {
				$key = $info['base_key'] . $file;
				$tmp = wp_tempnam( $file );
				$ok  = in_array( $key, $all, true ) && $c->get_object_to_file( $key, $tmp ) === true && hash_file( 'sha256', $tmp ) === hash_file( 'sha256', "$dir/$file" );
				@unlink( $tmp );
				$check( "object $key matches the local file byte-for-byte", $ok );
			}
			$check( "#$id status is offloaded", ISXM_Offload::status_for( $id )['status'] === 'offloaded' );
		}
		$first   = ISXM_Offload::get_record( $ids['attachments'][0] );
		$remote  = ISXM_Offload::build_remote_url( $first, $first['files'][0] );
		$content = get_post( $ids['post'] )->post_content;
		$check( 'post_content URL rewritten permanently', strpos( $content, $remote ) !== false, $content );
		$check( 'a look-alike path is left alone', strpos( $content, '/wp-content/uploads-old/' ) !== false, $content );
		$el = json_decode( (string) get_post_meta( $ids['post'], '_elementor_data', true ), true );
		$check( 'Elementor JSON (escaped slashes) rewritten and still valid', is_array( $el ) && $el[0]['settings']['image']['url'] === $remote, wp_json_encode( $el ) );
		$finish();
		break;

	case 'remove':
		// One size vanished from the bucket out-of-band; the local copies are
		// gone too (as with Remove Local Media). Removing the attachment from
		// the bucket must bring every other size home before deleting.
		$ids  = json_decode( file_get_contents( "$work/ids.json" ), true );
		$id   = $ids['attachments'][0];
		$info = ISXM_Offload::get_record( $id );
		$dir  = dirname( get_attached_file( $id ) );
		$c    = new ISXM_Client();
		$gone = $info['files'][1];
		$c->delete_object( $info['base_key'] . $gone );
		foreach ( $info['files'] as $file ) {
			@unlink( "$dir/$file" );
		}
		$r = ( new ISXM_Offload() )->remove_remote_attachment( $id );
		$check( 'remove completes despite the missing size', ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
		foreach ( $info['files'] as $file ) {
			if ( $file !== $gone ) {
				$check( "size $file brought home before its object was deleted", is_file( "$dir/$file" ) && filesize( "$dir/$file" ) > 0 );
			}
		}
		$left = array_filter( e2e_keys( $c ), function ( $k ) use ( $info ) { return strpos( $k, $info['base_key'] . pathinfo( $info['files'][0], PATHINFO_FILENAME ) ) === 0; } );
		$check( 'its objects are gone from the bucket', empty( $left ), wp_json_encode( array_values( $left ) ) );
		$check( 'record cleared, status pending', ISXM_Offload::get_record( $id ) === null && ISXM_Offload::status_for( $id )['status'] === 'pending' );
		$check( 'post_content URL reverted to the local one', strpos( get_post( $ids['post'] )->post_content, wp_get_attachment_url( $id ) ) !== false );
		$finish();
		break;

	case 'orphans':
		$c = new ISXM_Client();
		$tmp = wp_tempnam( 'orphan' );
		file_put_contents( $tmp, 'orphan' );
		$c->put_object( 'wp-content/uploads/stray-just-uploaded.txt', $tmp );
		@unlink( $tmp );
		$deleted = ISXM_Sync::cleanup_orphans();
		$check( 'cleanup ran', ! is_wp_error( $deleted ), is_wp_error( $deleted ) ? $deleted->get_error_message() : '' );
		$check( 'an object uploaded moments ago is never deleted as an orphan', in_array( 'wp-content/uploads/stray-just-uploaded.txt', e2e_keys( $c ), true ) );
		$ids = json_decode( file_get_contents( "$work/ids.json" ), true );
		$keep = ISXM_Offload::get_record( $ids['attachments'][1] );
		$check( 'tracked media untouched by the cleanup', in_array( $keep['base_key'] . $keep['files'][0], e2e_keys( $c ), true ) );
		e2e_settings( array( 'use_prefix' => false ) );
		$r = ISXM_Sync::cleanup_orphans();
		$check( 'without a prefix the cleanup is refused', is_wp_error( $r ) && $r->get_error_code() === 'isxs_orphan_no_prefix' );
		e2e_settings( array( 'use_prefix' => true ) );
		$finish();
		break;

	case 'migrated':
		$ids  = json_decode( file_get_contents( "$work/ids.json" ), true );
		$dest = new ISXM_Client( ISXM_Connections::get( 'minio' ) );
		$keys = e2e_keys( $dest );
		$info = ISXM_Offload::get_record( $ids['attachments'][1] );
		$check( 'item already offloaded to the OLD bucket was migrated (not skipped)', is_array( $info ) && $info['bucket'] === 'e2e-dest', wp_json_encode( $info ) );
		$check( 'its files now exist in the destination bucket', is_array( $info ) && in_array( $info['base_key'] . $info['files'][0], $keys, true ), wp_json_encode( $keys ) );
		$finish();
		break;
}
