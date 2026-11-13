<?php
/**
 * Settings → Google Media: OAuth client, connect / reconnect / disconnect, and the OAuth callback.
 *
 * The callback is a REST route because Google needs an exact redirect URI without query parameters. It is
 * authenticated by a one-time `state` value stored in a transient for the administrator who clicked “Connect”.
 */

namespace Colorbox\GoogleMedia;

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function () {
		add_options_page( __( 'Google Media', 'colorbox-google-media' ), __( 'Google Media', 'colorbox-google-media' ), 'manage_options', 'colorbox-google-media', __NAMESPACE__ . '\\render_settings' );
	}
);

add_action( 'admin_post_colorbox_gm_save', __NAMESPACE__ . '\\handle_save' );
add_action( 'admin_post_colorbox_gm_connect', __NAMESPACE__ . '\\handle_connect' );
add_action( 'admin_post_colorbox_gm_disconnect', __NAMESPACE__ . '\\handle_disconnect' );

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			REST_NS,
			'/oauth-callback',
			array(
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\oauth_callback',
				'permission_callback' => '__return_true', // Authenticated by the one-time `state`.
			)
		);
	}
);

function handle_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'colorbox-google-media' ) );
	}
	check_admin_referer( 'colorbox_gm_save' );
	$o      = settings();
	$id     = trim( sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ) );
	$secret = trim( sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) ) );
	if ( $id !== $o['client_id'] ) {
		// A different OAuth client invalidates the old connection.
		$o['refresh_token'] = '';
		$o['account']       = '';
		$o['scopes']        = '';
		delete_transient( 'colorbox_gm_token' );
	}
	$o['client_id'] = $id;
	if ( '' !== $secret ) {
		$o['client_secret'] = $secret;
	}
	save_settings( $o );
	back( array( 'msg' => 'saved' ) );
}

function handle_connect() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'colorbox-google-media' ) );
	}
	check_admin_referer( 'colorbox_gm_connect' );
	$o = settings();
	if ( '' === $o['client_id'] || '' === $o['client_secret'] ) {
		back( array( 'msg' => 'no-client' ) );
	}
	$state = wp_generate_password( 32, false );
	set_transient( 'colorbox_gm_state_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );
	$url = add_query_arg(
		array(
			'client_id'              => rawurlencode( $o['client_id'] ),
			'redirect_uri'           => rawurlencode( callback_url() ),
			'response_type'          => 'code',
			'scope'                  => rawurlencode( SCOPE_DRIVE . ' ' . SCOPE_PHOTOS . ' openid email' ),
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
		),
		'https://accounts.google.com/o/oauth2/v2/auth'
	);
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- external Google URL.
	exit;
}

function handle_disconnect() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'colorbox-google-media' ) );
	}
	check_admin_referer( 'colorbox_gm_disconnect' );
	$o = settings();
	if ( '' !== $o['refresh_token'] ) {
		wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'body' => array( 'token' => $o['refresh_token'] ), 'timeout' => 15 ) );
	}
	$o['refresh_token'] = '';
	$o['account']       = '';
	$o['scopes']        = '';
	save_settings( $o );
	delete_transient( 'colorbox_gm_token' );
	back( array( 'msg' => 'disconnected' ) );
}

function back( $args ) {
	wp_safe_redirect( settings_url( $args ) );
	exit;
}

function oauth_callback( \WP_REST_Request $r ) {
	$state = sanitize_text_field( (string) $r->get_param( 'state' ) );
	$code  = (string) $r->get_param( 'code' );
	$error = sanitize_text_field( (string) $r->get_param( 'error' ) );

	$user = $state ? get_transient( 'colorbox_gm_state_' . $state ) : false;
	if ( $state ) {
		delete_transient( 'colorbox_gm_state_' . $state );
	}
	if ( ! $user || ! user_can( (int) $user, 'manage_options' ) ) {
		back( array( 'msg' => 'bad-state' ) );
	}
	if ( $error || '' === $code ) {
		back( array( 'msg' => 'google-error', 'detail' => $error ? $error : 'no code' ) );
	}

	$o    = settings();
	$res  = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 20,
			'body'    => array(
				'code'          => $code,
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'redirect_uri'  => callback_url(),
				'grant_type'    => 'authorization_code',
			),
		)
	);
	$data = is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $data['access_token'] ) ) {
		back( array( 'msg' => 'google-error', 'detail' => $data['error_description'] ?? $data['error'] ?? 'no token' ) );
	}
	if ( empty( $data['refresh_token'] ) ) {
		back( array( 'msg' => 'no-refresh' ) );
	}
	$account = '';
	if ( ! empty( $data['id_token'] ) ) {
		// Display only; the token came straight from Google over HTTPS.
		$parts   = explode( '.', $data['id_token'] );
		$claims  = isset( $parts[1] ) ? json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$account = sanitize_email( $claims['email'] ?? '' );
	}
	$o['refresh_token'] = $data['refresh_token'];
	$o['account']       = $account;
	$o['scopes']        = sanitize_text_field( (string) ( $data['scope'] ?? '' ) );
	save_settings( $o );
	cache_token( $data );
	back( array( 'msg' => 'connected' ) );
}

function render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$o   = settings();
	$msg = sanitize_key( $_GET['msg'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$all = array(
		'saved'        => array( 'success', __( 'Saved.', 'colorbox-google-media' ) ),
		'connected'    => array( 'success', __( 'Connected to Google.', 'colorbox-google-media' ) ),
		'disconnected' => array( 'success', __( 'Disconnected. Access has been revoked in your Google account.', 'colorbox-google-media' ) ),
		'no-client'    => array( 'error', __( 'Enter the client ID and secret first, then connect.', 'colorbox-google-media' ) ),
		'bad-state'    => array( 'error', __( 'Returned from Google without a valid session (more than 15 minutes passed or the link was opened twice). Click “Connect” again.', 'colorbox-google-media' ) ),
		/* translators: %s: error detail from Google */
		'google-error' => array( 'error', sprintf( __( 'Google refused: %s', 'colorbox-google-media' ), sanitize_text_field( wp_unslash( $_GET['detail'] ?? '' ) ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
		'no-refresh'   => array( 'error', __( 'Google returned no refresh token. Remove the app at myaccount.google.com/permissions and connect again.', 'colorbox-google-media' ) ),
	);
	$post = esc_url( admin_url( 'admin-post.php' ) );

	echo '<div class="wrap"><h1>' . esc_html__( 'Google Media — Drive videos and Google Photos', 'colorbox-google-media' ) . '</h1>';
	if ( isset( $all[ $msg ] ) ) {
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $all[ $msg ][0] ), esc_html( $all[ $msg ][1] ) );
	}

	echo '<h2>' . esc_html__( '1. OAuth client from Google Cloud', 'colorbox-google-media' ) . '</h2>';
	echo '<p>' . wp_kses_post( __( 'In Google Cloud create an OAuth client of type <strong>Web application</strong> and paste this exact address under “Authorized redirect URIs”:', 'colorbox-google-media' ) ) . '</p>';
	printf( '<p><code style="user-select:all">%s</code></p>', esc_html( callback_url() ) );
	echo '<p><a href="' . esc_url( __( 'https://github.com/szmidtpiotr/colorbox-google-media/blob/HEAD/docs/en/google-cloud-setup.md', 'colorbox-google-media' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Step-by-step Google Cloud setup', 'colorbox-google-media' ) . '</a></p>';
	echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	wp_nonce_field( 'colorbox_gm_save' );
	echo '<input type="hidden" name="action" value="colorbox_gm_save"><table class="form-table">';
	echo '<tr><th><label for="cgm-id">' . esc_html__( 'Client ID', 'colorbox-google-media' ) . '</label></th><td>';
	printf( '<input id="cgm-id" name="client_id" type="text" class="large-text code" value="%s" autocomplete="off">', esc_attr( $o['client_id'] ) );
	echo '</td></tr><tr><th><label for="cgm-secret">' . esc_html__( 'Client secret', 'colorbox-google-media' ) . '</label></th><td>';
	printf(
		'<input id="cgm-secret" name="client_secret" type="password" class="regular-text code" value="" autocomplete="new-password" placeholder="%s">',
		esc_attr( '' !== $o['client_secret'] ? __( 'saved — leave empty to keep', 'colorbox-google-media' ) : '' )
	);
	echo '</td></tr></table>';
	submit_button( __( 'Save', 'colorbox-google-media' ) );
	echo '</form>';

	echo '<h2>' . esc_html__( '2. Connect your Google account', 'colorbox-google-media' ) . '</h2>';
	if ( is_connected() ) {
		if ( $o['account'] ) {
			/* translators: %s: Google account e-mail */
			echo '<p>' . wp_kses_post( sprintf( __( 'Connected to <strong>%s</strong>.', 'colorbox-google-media' ), esc_html( $o['account'] ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Connected.', 'colorbox-google-media' ) . '</p>';
		}
		echo '<ul style="list-style:disc;padding-left:20px">';
		echo '<li>' . ( has_scope( SCOPE_DRIVE ) ? esc_html__( 'Google Drive: access granted.', 'colorbox-google-media' ) : '<strong>' . esc_html__( 'Google Drive: no access — reconnect and tick the Drive checkbox.', 'colorbox-google-media' ) . '</strong>' ) . '</li>';
		echo '<li>' . ( has_scope( SCOPE_PHOTOS ) ? esc_html__( 'Google Photos: access granted.', 'colorbox-google-media' ) : '<strong>' . esc_html__( 'Google Photos: no access — enable “Photos Picker API” in Google Cloud, then reconnect and tick the Photos checkbox.', 'colorbox-google-media' ) . '</strong>' ) . '</li>';
		echo '</ul>';
		echo '<form method="post" action="' . $post . '" style="display:inline-block;margin-right:8px">'; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( 'colorbox_gm_connect' );
		echo '<input type="hidden" name="action" value="colorbox_gm_connect">';
		submit_button( __( 'Reconnect', 'colorbox-google-media' ), 'secondary', 'submit', false );
		echo '</form><form method="post" action="' . $post . '" style="display:inline-block">'; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( 'colorbox_gm_disconnect' );
		echo '<input type="hidden" name="action" value="colorbox_gm_disconnect">';
		submit_button( __( 'Disconnect', 'colorbox-google-media' ), 'secondary', 'submit', false );
		echo '</form>';
	} else {
		echo '<p>' . esc_html__( 'Not connected.', 'colorbox-google-media' ) . '</p>';
		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		wp_nonce_field( 'colorbox_gm_connect' );
		echo '<input type="hidden" name="action" value="colorbox_gm_connect">';
		submit_button( __( 'Connect with Google', 'colorbox-google-media' ), 'primary', 'submit', true, ( '' === $o['client_id'] || '' === $o['client_secret'] ) ? array( 'disabled' => 'disabled' ) : null );
		echo '</form>';
	}

	echo '<h2>' . esc_html__( '3. Use it', 'colorbox-google-media' ) . '</h2><ul style="list-style:disc;padding-left:20px">';
	/* translators: %s: Drive folder name */
	echo '<li>' . wp_kses_post( sprintf( __( 'Block <strong>Drive video</strong>: upload a new video or pick one uploaded before. Videos go to the folder <strong>%s</strong> in your Drive (you may move or rename it).', 'colorbox-google-media' ), esc_html( folder_name() ) ) ) . '</li>';
	echo '<li>' . wp_kses_post( __( 'Block <strong>Google Photos</strong>: pick photos in Google’s window; they are copied into the media library and inserted as a gallery.', 'colorbox-google-media' ) ) . '</li>';
	echo '<li>' . esc_html__( 'A Google Drive link pasted on its own line also becomes a player (the file must be shared as “Anyone with the link”).', 'colorbox-google-media' ) . '</li>';
	echo '</ul></div>';
}
