<?php
/**
 * Google Photos import (Google Photos Picker API) into the media library.
 *
 * Flow: the “Google Photos” block asks for a picking session, opens Google's picker window (pickerUri + /autoclose),
 * polls until the user is done, then imports the photos one by one and inserts a gallery.
 *
 * - Picked items' baseUrl needs an Authorization header and lives 60 minutes, so the server downloads each photo
 *   with the site's token, at most 2560 px (`=w2560-h2560`, served as JPEG — also turns iPhone HEIC into JPEG).
 * - Before the file enters the media library its metadata (EXIF with GPS, camera, XMP, IPTC) is removed; only the
 *   colour profile is kept. If that fails the photo is rejected rather than published with a location.
 * - Videos from Google Photos are skipped (use the Drive video block for videos).
 */

namespace Colorbox\GoogleMedia;

defined( 'ABSPATH' ) || exit;

const PHOTOS_API = 'https://photospicker.googleapis.com/v1';

add_action( 'rest_api_init', __NAMESPACE__ . '\\photos_routes' );

function photos_routes() {
	$session = array(
		'validate_callback' => static function ( $v ) {
			return is_string( $v ) && preg_match( '/^[A-Za-z0-9_-]{8,}$/', $v );
		},
	);
	register_rest_route(
		REST_NS,
		'/photos/session',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_photos_new_session',
			'permission_callback' => __NAMESPACE__ . '\\user_can_use',
		)
	);
	register_rest_route(
		REST_NS,
		'/photos/session/(?P<id>[A-Za-z0-9_-]+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\rest_photos_session',
				'permission_callback' => __NAMESPACE__ . '\\user_can_use',
				'args'                => array( 'id' => $session ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => __NAMESPACE__ . '\\rest_photos_delete_session',
				'permission_callback' => __NAMESPACE__ . '\\user_can_use',
				'args'                => array( 'id' => $session ),
			),
		)
	);
	register_rest_route(
		REST_NS,
		'/photos/import',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_photos_import',
			'permission_callback' => __NAMESPACE__ . '\\user_can_use',
			'args'                => array(
				'session' => array_merge( array( 'required' => true ), $session ),
				'item'    => array( 'required' => true, 'type' => 'string' ),
				'post'    => array( 'type' => 'integer', 'default' => 0 ),
			),
		)
	);
}

function rest_photos_new_session() {
	if ( ! has_scope( SCOPE_PHOTOS ) ) {
		return new \WP_Error( 'colorbox_gm_scope', __( 'The Google connection has no Google Photos access. Enable “Photos Picker API” in Google Cloud, then click “Reconnect” under Settings → Google Media.', 'colorbox-google-media' ), array( 'status' => 409 ) );
	}
	$s = api( 'POST', PHOTOS_API . '/sessions', new \stdClass() );
	if ( is_wp_error( $s ) ) {
		return $s;
	}
	set_transient( 'colorbox_gm_photos_' . $s['id'], array( 'items' => null ), 2 * HOUR_IN_SECONDS );
	return array(
		'id'        => $s['id'],
		'pickerUri' => rtrim( $s['pickerUri'], '/' ) . '/autoclose',
		'interval'  => max( 2, (float) ( $s['pollingConfig']['pollInterval'] ?? '3s' ) ),
		'timeout'   => (float) ( $s['pollingConfig']['timeoutIn'] ?? '1800s' ),
	);
}

function rest_photos_session( \WP_REST_Request $r ) {
	$id = $r['id'];
	if ( false === get_transient( 'colorbox_gm_photos_' . $id ) ) {
		return new \WP_Error( 'colorbox_gm_session', __( 'Unknown or expired picking session.', 'colorbox-google-media' ), array( 'status' => 404 ) );
	}
	$s = api( 'GET', PHOTOS_API . '/sessions/' . rawurlencode( $id ) );
	if ( is_wp_error( $s ) ) {
		return $s;
	}
	if ( empty( $s['mediaItemsSet'] ) ) {
		return array( 'done' => false );
	}

	$items = array();
	$page  = '';
	do {
		$l = api( 'GET', PHOTOS_API . '/mediaItems?sessionId=' . rawurlencode( $id ) . '&pageSize=100' . ( $page ? '&pageToken=' . rawurlencode( $page ) : '' ) );
		if ( is_wp_error( $l ) ) {
			return $l;
		}
		foreach ( (array) ( $l['mediaItems'] ?? array() ) as $m ) {
			$file    = $m['mediaFile'] ?? array();
			$items[] = array(
				'id'      => (string) $m['id'],
				'type'    => $m['type'] ?? ( 0 === strpos( (string) ( $file['mimeType'] ?? '' ), 'video/' ) ? 'VIDEO' : 'PHOTO' ),
				'name'    => (string) ( $file['filename'] ?? $m['id'] ),
				'baseUrl' => (string) ( $file['baseUrl'] ?? '' ),
			);
		}
		$page = (string) ( $l['nextPageToken'] ?? '' );
	} while ( '' !== $page );

	set_transient( 'colorbox_gm_photos_' . $id, array( 'items' => $items ), HOUR_IN_SECONDS );
	return array(
		'done'  => true,
		'items' => array_map(
			static function ( $i ) {
				return array( 'id' => $i['id'], 'type' => $i['type'], 'name' => $i['name'] );
			},
			$items
		),
	);
}

function rest_photos_delete_session( \WP_REST_Request $r ) {
	delete_transient( 'colorbox_gm_photos_' . $r['id'] );
	api( 'DELETE', PHOTOS_API . '/sessions/' . rawurlencode( $r['id'] ) );
	return array( 'deleted' => true );
}

function rest_photos_import( \WP_REST_Request $r ) {
	$state = get_transient( 'colorbox_gm_photos_' . $r['session'] );
	if ( ! is_array( $state ) || empty( $state['items'] ) ) {
		return new \WP_Error( 'colorbox_gm_session', __( 'The picking session has expired (photo links work for 60 minutes). Pick the photos again.', 'colorbox-google-media' ), array( 'status' => 410 ) );
	}
	$item = null;
	foreach ( $state['items'] as $i ) {
		if ( $i['id'] === $r['item'] ) {
			$item = $i;
			break;
		}
	}
	if ( ! $item ) {
		return new \WP_Error( 'colorbox_gm_item', __( 'This photo is not part of the picking session.', 'colorbox-google-media' ), array( 'status' => 404 ) );
	}
	if ( 'PHOTO' !== $item['type'] ) {
		return array( 'skipped' => true );
	}
	if ( 0 !== strpos( $item['baseUrl'], 'https://' ) ) {
		return new \WP_Error( 'colorbox_gm_url', __( 'Google gave no address for this photo.', 'colorbox-google-media' ), array( 'status' => 502 ) );
	}
	$token = access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = wp_tempnam( 'colorbox-gm-photo' );
	$res = wp_remote_get(
		$item['baseUrl'] . '=w2560-h2560',
		array(
			'timeout'  => 60,
			'stream'   => true,
			'filename' => $tmp,
			'headers'  => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
		)
	);
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		wp_delete_file( $tmp );
		$why = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
		/* translators: 1: file name, 2: error */
		return new \WP_Error( 'colorbox_gm_download', sprintf( __( 'Could not download “%1$s” from Google Photos: %2$s', 'colorbox-google-media' ), $item['name'], $why ), array( 'status' => 502 ) );
	}

	$mime = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
	$exts = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
	$ext  = 'jpg';
	foreach ( $exts as $m => $e ) {
		if ( 0 === strpos( $mime, $m ) ) {
			$ext = $e;
		}
	}

	if ( ! strip_metadata( $tmp, 'jpg' === $ext ? 'image/jpeg' : 'image/' . $ext ) ) {
		wp_delete_file( $tmp );
		/* translators: %s: file name */
		return new \WP_Error( 'colorbox_gm_metadata', sprintf( __( 'Could not remove metadata (location) from “%s”, so it was not imported.', 'colorbox-google-media' ), $item['name'] ), array( 'status' => 500 ) );
	}

	$base = sanitize_file_name( preg_replace( '/\.[^.]+$/', '', $item['name'] ) );
	$file = array(
		'name'     => ( $base ? $base : 'photo' ) . '.' . $ext,
		'tmp_name' => $tmp,
	);
	$post = (int) $r['post'];
	if ( $post && ! current_user_can( 'edit_post', $post ) ) {
		$post = 0;
	}
	$att = media_handle_sideload( $file, $post );
	if ( is_wp_error( $att ) ) {
		wp_delete_file( $tmp );
		/* translators: 1: file name, 2: error */
		return new \WP_Error( 'colorbox_gm_import', sprintf( __( 'WordPress did not accept “%1$s”: %2$s', 'colorbox-google-media' ), $item['name'], $att->get_error_message() ), array( 'status' => 500 ) );
	}
	return array(
		'id'   => $att,
		'url'  => wp_get_attachment_image_url( $att, 'large' ),
		'name' => $item['name'],
	);
}

/**
 * Removes EXIF (GPS, camera, dates), XMP, IPTC and comments; keeps the ICC colour profile. EXIF rotation is applied
 * to the pixels first, so the photo does not end up sideways. Returns false if nothing could clean the file.
 */
function strip_metadata( $path, $mime ) {
	if ( 'image/gif' === $mime ) {
		return true; // GIF carries no EXIF; re-saving would break animation.
	}
	if ( extension_loaded( 'imagick' ) ) {
		try {
			$img = new \Imagick( $path );
			if ( $img->getNumberImages() <= 1 ) {
				$o = $img->getImageOrientation();
				if ( \Imagick::ORIENTATION_TOPLEFT !== $o && \Imagick::ORIENTATION_UNDEFINED !== $o ) {
					$img->autoOrient();
				}
				$icc = $img->getImageProfiles( 'icc', true );
				$img->stripImage();
				if ( ! empty( $icc['icc'] ) ) {
					$img->profileImage( 'icc', $icc['icc'] );
				}
				$img->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
				$ok = $img->writeImage( $path );
				$img->clear();
				if ( $ok ) {
					return true;
				}
			}
		} catch ( \Exception $e ) {
			// Fall through to GD.
		}
	}
	if ( 'image/jpeg' === $mime && function_exists( 'imagecreatefromjpeg' ) ) {
		$img = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $img ) {
			$exif = function_exists( 'exif_read_data' ) ? @exif_read_data( $path ) : array(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$rot  = array( 3 => 180, 6 => -90, 8 => 90 );
			if ( ! empty( $exif['Orientation'] ) && isset( $rot[ $exif['Orientation'] ] ) ) {
				$img = imagerotate( $img, $rot[ $exif['Orientation'] ], 0 );
			}
			$ok = imagejpeg( $img, $path, 90 ); // GD writes no metadata at all.
			imagedestroy( $img );
			return $ok;
		}
	}
	return false;
}
