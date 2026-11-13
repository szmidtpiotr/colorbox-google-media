<?php
/**
 * Google Drive videos: REST endpoints for the “Drive video” block.
 *
 * - POST /token     short-lived access token + upload folder; the browser uploads straight to Google
 *                   (resumable upload, so no PHP or proxy upload limits apply).
 * - GET  /videos    videos in the upload folder (only files this plugin created — drive.file scope).
 * - POST /share     sets “Anyone with the link: viewer”, remembers cover + orientation, returns the link to embed.
 *
 * Remembered per Drive file in the option `colorbox_gm_videos`: { cover: attachment ID, vertical: bool }.
 */

namespace Colorbox\GoogleMedia;

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', __NAMESPACE__ . '\\drive_routes' );

function drive_routes() {
	$id = array(
		'required'          => true,
		'validate_callback' => static function ( $v ) {
			return is_string( $v ) && preg_match( '/^[A-Za-z0-9_-]{10,}$/', $v );
		},
	);
	register_rest_route(
		REST_NS,
		'/token',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_token',
			'permission_callback' => __NAMESPACE__ . '\\user_can_use',
		)
	);
	register_rest_route(
		REST_NS,
		'/videos',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_videos',
			'permission_callback' => __NAMESPACE__ . '\\user_can_use',
		)
	);
	register_rest_route(
		REST_NS,
		'/share',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_share',
			'permission_callback' => __NAMESPACE__ . '\\user_can_use',
			'args'                => array(
				'id'       => $id,
				'cover'    => array( 'type' => 'integer', 'default' => -1 ), // -1 = keep the remembered cover.
				'vertical' => array( 'type' => 'boolean' ),
			),
		)
	);
}

function video_map() {
	$m = get_option( OPTION_VIDEOS, array() );
	return is_array( $m ) ? $m : array();
}

function rest_token() {
	if ( ! has_scope( SCOPE_DRIVE ) ) {
		return new \WP_Error( 'colorbox_gm_scope', __( 'The Google connection has no Drive access. Reconnect under Settings → Google Media.', 'colorbox-google-media' ), array( 'status' => 409 ) );
	}
	$token = access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$folder = drive_folder();
	if ( is_wp_error( $folder ) ) {
		return $folder;
	}
	return array(
		'access_token' => $token['access_token'],
		'expires'      => $token['expires'],
		'folder_id'    => $folder,
	);
}

function rest_videos() {
	$folder = drive_folder();
	if ( is_wp_error( $folder ) ) {
		return $folder;
	}
	$q    = rawurlencode( "'" . $folder . "' in parents and trashed = false and mimeType contains 'video/'" );
	$data = api(
		'GET',
		'https://www.googleapis.com/drive/v3/files?q=' . $q . '&orderBy=createdTime%20desc&pageSize=100'
		. '&fields=' . rawurlencode( 'files(id,name,createdTime,size,videoMediaMetadata,thumbnailLink)' )
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$map = video_map();
	$out = array();
	foreach ( (array) ( $data['files'] ?? array() ) as $f ) {
		$saved    = $map[ $f['id'] ] ?? array();
		$cover    = (int) ( $saved['cover'] ?? 0 );
		$cover    = $cover && wp_attachment_is_image( $cover ) ? $cover : 0;
		$meta     = $f['videoMediaMetadata'] ?? array();
		$vertical = isset( $saved['vertical'] ) ? (bool) $saved['vertical'] : ( ! empty( $meta['height'] ) && $meta['height'] > ( $meta['width'] ?? 0 ) );
		$out[]    = array(
			'id'        => $f['id'],
			'name'      => $f['name'],
			'created'   => $f['createdTime'] ?? '',
			'size'      => (int) ( $f['size'] ?? 0 ),
			'seconds'   => isset( $meta['durationMillis'] ) ? (int) round( $meta['durationMillis'] / 1000 ) : 0,
			'vertical'  => $vertical,
			'cover'     => $cover,
			'thumbnail' => $cover ? wp_get_attachment_image_url( $cover, 'medium' ) : ( $f['thumbnailLink'] ?? '' ),
		);
	}
	return $out;
}

function rest_share( \WP_REST_Request $r ) {
	$id = $r->get_param( 'id' );
	$p  = api( 'POST', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $id ) . '/permissions?fields=id', array( 'type' => 'anyone', 'role' => 'reader' ) );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$file = api( 'GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $id ) . '?fields=id,name,mimeType' );
	if ( is_wp_error( $file ) ) {
		return $file;
	}
	$map   = video_map();
	$saved = $map[ $file['id'] ] ?? array( 'cover' => 0, 'vertical' => false );
	if ( (int) $r->get_param( 'cover' ) >= 0 ) {
		$saved['cover'] = (int) $r->get_param( 'cover' );
	}
	if ( null !== $r->get_param( 'vertical' ) ) {
		$saved['vertical'] = (bool) $r->get_param( 'vertical' );
	}
	$map[ $file['id'] ] = $saved;
	update_option( OPTION_VIDEOS, $map, false );

	return array(
		'id'   => $file['id'],
		'name' => $file['name'] ?? '',
		'url'  => drive_link( $file['id'], ! empty( $saved['vertical'] ), (int) $saved['cover'] ),
	);
}

/**
 * The link stored in the post: options after # (joined with &): `vertical`, `cover=<attachment ID>`.
 */
function drive_link( $id, $vertical, $cover ) {
	$opts = array();
	if ( $vertical ) {
		$opts[] = 'vertical';
	}
	if ( $cover ) {
		$opts[] = 'cover=' . (int) $cover;
	}
	return 'https://drive.google.com/file/d/' . $id . '/view' . ( $opts ? '#' . implode( '&', $opts ) : '' );
}

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\editor_assets' );

function editor_assets() {
	$base = plugin_dir_url( FILE ) . 'assets/js/';
	$dir  = plugin_dir_path( FILE ) . 'assets/js/';
	$deps = array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-api-fetch', 'wp-data', 'wp-i18n', 'wp-notices' );

	wp_enqueue_script( 'colorbox-gm-drive', $base . 'drive-video.js', $deps, (string) filemtime( $dir . 'drive-video.js' ), true );
	wp_enqueue_script( 'colorbox-gm-photos', $base . 'google-photos.js', array_merge( $deps, array( 'colorbox-gm-drive' ) ), (string) filemtime( $dir . 'google-photos.js' ), true );
	wp_set_script_translations( 'colorbox-gm-drive', DOMAIN, plugin_dir_path( FILE ) . 'languages' );
	wp_set_script_translations( 'colorbox-gm-photos', DOMAIN, plugin_dir_path( FILE ) . 'languages' );
	wp_add_inline_script(
		'colorbox-gm-drive',
		'window.colorboxGoogleMedia = ' . wp_json_encode(
			array(
				'ns'          => REST_NS,
				'canUse'      => user_can_use(),
				'drive'       => has_scope( SCOPE_DRIVE ),
				'photos'      => has_scope( SCOPE_PHOTOS ),
				'settingsUrl' => settings_url(),
				'folder'      => folder_name(),
			)
		) . ';',
		'before'
	);
}
