<?php
/**
 * Google connection: stored settings, OAuth tokens and a small JSON API helper.
 *
 * - One OAuth client (yours, from Google Cloud) and one refresh token per site, kept in the option
 *   `colorbox_gm_settings` (database only — never in files).
 * - Scopes: drive.file (the plugin sees only files it created itself) and photospicker.mediaitems.readonly
 *   (photos the user picks in Google's own picker window).
 * - Access tokens are cached in a transient until shortly before they expire.
 */

namespace Colorbox\GoogleMedia;

defined( 'ABSPATH' ) || exit;

const OPTION        = 'colorbox_gm_settings';
const OPTION_VIDEOS = 'colorbox_gm_videos';
const SCOPE_DRIVE   = 'https://www.googleapis.com/auth/drive.file';
const SCOPE_PHOTOS  = 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly';
const REST_NS       = 'colorbox-google-media/v1';

function settings() {
	$o = get_option( OPTION, null );
	if ( null === $o ) {
		$o = migrate_legacy();
	}
	return wp_parse_args(
		is_array( $o ) ? $o : array(),
		array(
			'client_id'     => '',
			'client_secret' => '',
			'refresh_token' => '',
			'account'       => '',
			'folder_id'     => '',
			'scopes'        => '',
		)
	);
}

function save_settings( $o ) {
	update_option( OPTION, $o, false );
}

/**
 * One-time import from the must-use predecessor (options `colorbox_dysk` and `colorbox_dysk_filmy`), so a site that
 * switches to this plugin stays connected and keeps its remembered video covers.
 */
function migrate_legacy() {
	$old = get_option( 'colorbox_dysk', null );
	if ( ! is_array( $old ) ) {
		return array();
	}
	$new = array(
		'client_id'     => (string) ( $old['client_id'] ?? '' ),
		'client_secret' => (string) ( $old['client_secret'] ?? '' ),
		'refresh_token' => (string) ( $old['refresh_token'] ?? '' ),
		'account'       => (string) ( $old['konto'] ?? '' ),
		'folder_id'     => (string) ( $old['folder_id'] ?? '' ),
		'scopes'        => (string) ( $old['zakresy'] ?? '' ),
	);
	save_settings( $new );
	$videos = array();
	foreach ( (array) get_option( 'colorbox_dysk_filmy', array() ) as $id => $v ) {
		$videos[ $id ] = array(
			'cover'    => (int) ( $v['okladka'] ?? 0 ),
			'vertical' => ! empty( $v['pion'] ),
		);
	}
	if ( $videos ) {
		update_option( OPTION_VIDEOS, $videos, false );
	}
	return $new;
}

function is_connected() {
	$o = settings();
	return '' !== $o['refresh_token'] && '' !== $o['client_id'];
}

function has_scope( $scope ) {
	return is_connected() && false !== strpos( settings()['scopes'], $scope );
}

function callback_url() {
	return rest_url( REST_NS . '/oauth-callback' );
}

function settings_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'colorbox-google-media' ), $args ), admin_url( 'options-general.php' ) );
}

/**
 * Who may use the Google connection. Only administrators by default: the access token reaches the browser during
 * uploads, so editors (including AI-agent accounts) should not get it.
 */
function user_can_use() {
	return current_user_can( apply_filters( 'colorbox_gm_capability', 'manage_options' ) );
}

function access_token() {
	$cached = get_transient( 'colorbox_gm_token' );
	if ( is_array( $cached ) && ! empty( $cached['access_token'] ) ) {
		return $cached;
	}
	if ( ! is_connected() ) {
		return new \WP_Error( 'colorbox_gm_not_connected', __( 'Google is not connected (Settings → Google Media).', 'colorbox-google-media' ), array( 'status' => 409 ) );
	}
	$o   = settings();
	$res = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 20,
			'body'    => array(
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'refresh_token' => $o['refresh_token'],
				'grant_type'    => 'refresh_token',
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		/* translators: %s: error message */
		return new \WP_Error( 'colorbox_gm_network', sprintf( __( 'Cannot reach Google: %s', 'colorbox-google-media' ), $res->get_error_message() ), array( 'status' => 502 ) );
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $data['access_token'] ) ) {
		$err = $data['error'] ?? 'unknown';
		if ( 'invalid_grant' === $err ) {
			// Access revoked, or the app is still in "Testing" (refresh tokens expire after 7 days there).
			$o['refresh_token'] = '';
			save_settings( $o );
		}
		/* translators: %s: Google error code */
		return new \WP_Error( 'colorbox_gm_token', sprintf( __( 'Google did not issue a token (%s). Reconnect under Settings → Google Media.', 'colorbox-google-media' ), $err ), array( 'status' => 502 ) );
	}
	return cache_token( $data );
}

function cache_token( $data ) {
	$token = array(
		'access_token' => $data['access_token'],
		'expires'      => time() + (int) $data['expires_in'],
	);
	set_transient( 'colorbox_gm_token', $token, max( 60, (int) $data['expires_in'] - 120 ) );
	return $token;
}

/**
 * JSON request to a Google API with the site's token. Returns the decoded body or WP_Error.
 */
function api( $method, $url, $body = null ) {
	$token = access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$args = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json; charset=UTF-8';
		$args['body']                    = wp_json_encode( $body );
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		return new \WP_Error( 'colorbox_gm_network', $res->get_error_message(), array( 'status' => 502 ) );
	}
	$code = wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code >= 300 ) {
		$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
		/* translators: %s: error message from Google */
		return new \WP_Error( 'colorbox_gm_api', sprintf( __( 'Google: %s', 'colorbox-google-media' ), $msg ), array( 'status' => 502 ) );
	}
	return is_array( $data ) ? $data : array();
}

function folder_name() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	/* translators: %s: site domain */
	return apply_filters( 'colorbox_gm_folder_name', sprintf( __( '%s — videos', 'colorbox-google-media' ), $host ) );
}

/**
 * The Drive folder for uploads, created on first use. Stored by ID, so the owner may move or rename it in Drive.
 */
function drive_folder() {
	$o = settings();
	if ( '' !== $o['folder_id'] ) {
		$f = api( 'GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $o['folder_id'] ) . '?fields=id,trashed' );
		if ( ! is_wp_error( $f ) && empty( $f['trashed'] ) ) {
			return $o['folder_id'];
		}
	}
	// No stored folder (fresh install, or settings lost): reuse the oldest folder this plugin created before,
	// instead of starting a second one. drive.file lists only the plugin's own files, so this never picks yours.
	$q     = rawurlencode( "mimeType = 'application/vnd.google-apps.folder' and trashed = false" );
	$found = api( 'GET', 'https://www.googleapis.com/drive/v3/files?q=' . $q . '&orderBy=createdTime&pageSize=1&fields=files(id)' );
	if ( ! is_wp_error( $found ) && ! empty( $found['files'][0]['id'] ) ) {
		$o              = settings();
		$o['folder_id'] = $found['files'][0]['id'];
		save_settings( $o );
		return $o['folder_id'];
	}
	$f = api(
		'POST',
		'https://www.googleapis.com/drive/v3/files?fields=id',
		array(
			'name'     => folder_name(),
			'mimeType' => 'application/vnd.google-apps.folder',
		)
	);
	if ( is_wp_error( $f ) ) {
		return $f;
	}
	$o              = settings();
	$o['folder_id'] = $f['id'];
	save_settings( $o );
	return $f['id'];
}
