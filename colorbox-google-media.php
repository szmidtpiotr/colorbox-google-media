<?php
/**
 * Plugin Name:       Colorbox Google Media — Drive videos & Google Photos
 * Plugin URI:        https://github.com/szmidtpiotr/colorbox-google-media
 * Description:       Upload videos from the block editor straight to your Google Drive and embed them with a cover frame (the Drive player loads only on click). Import photos from Google Photos into the media library as a gallery. Paste any Drive video link to embed it. Configure under Settings → Google Media.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Piotr Szmidt / Colorbox Studio
 * License:           GPL-2.0-or-later
 * Text Domain:       colorbox-google-media
 * Domain Path:       /languages
 */

namespace Colorbox\GoogleMedia;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;
const DOMAIN  = 'colorbox-google-media';

// The site this plugin was born on ran the same code as a must-use plugin. If it is still there, stay out of the way.
if ( function_exists( 'colorbox_dysk_opcje' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Colorbox Google Media is inactive: the older must-use version (colorbox-drive-wideo) is still installed. Remove it from wp-content/mu-plugins first.', 'colorbox-google-media' ) . '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/includes/google.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/drive.php';
require_once __DIR__ . '/includes/embed.php';
require_once __DIR__ . '/includes/photos.php';

add_action(
	'init',
	static function () {
		load_plugin_textdomain( DOMAIN, false, dirname( plugin_basename( FILE ) ) . '/languages' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( FILE ),
	static function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( settings_url() ) . '">' . esc_html__( 'Settings', 'colorbox-google-media' ) . '</a>' );
		return $links;
	}
);
