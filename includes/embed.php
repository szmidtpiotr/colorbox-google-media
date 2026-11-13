<?php
/**
 * Embedding Google Drive videos.
 *
 * A Drive file link on its own line (or in an Embed block) becomes a click-to-play player: first a cover image with a
 * play button, and only after a click the Drive iframe — so visitors do not connect to Google until they choose to.
 *
 * Accepted links: drive.google.com/file/d/ID/view | /preview | /edit (with any ?query) and drive.google.com/open?id=ID.
 * Options after # (joined with &): `vertical` = 9:16 frame, `cover=ID` = cover image from the media library.
 * The Polish spellings `pion` and `okladka=ID` from the plugin's first version are accepted too.
 */

namespace Colorbox\GoogleMedia;

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		wp_embed_register_handler(
			'colorbox-gm-drive-file',
			'#^https?://drive\.google\.com/file/d/([A-Za-z0-9_-]{10,})(?:/(?:view|preview|edit))?/?(?:[?\#].*)?$#i',
			__NAMESPACE__ . '\\embed_html'
		);
		wp_embed_register_handler(
			'colorbox-gm-drive-open',
			'#^https?://drive\.google\.com/open\?(?:.*&)?id=([A-Za-z0-9_-]{10,})(?:&.*)?$#i',
			__NAMESPACE__ . '\\embed_html'
		);
		wp_register_script( 'colorbox-gm-facade', false, array(), VERSION, true );
		wp_add_inline_script(
			'colorbox-gm-facade',
			"document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.colorbox-gm-video__start');if(!b)return;"
			. "var w=b.parentNode,f=document.createElement('iframe');f.src=w.getAttribute('data-src');f.title=b.getAttribute('data-title');"
			. "f.allow='autoplay; fullscreen';f.allowFullscreen=true;f.referrerPolicy='strict-origin-when-cross-origin';"
			. "f.style.cssText='position:absolute;inset:0;width:100%;height:100%;border:0';w.replaceChild(f,b);});"
		);
	}
);

function link_options( $url ) {
	$opts = array( 'vertical' => false, 'cover' => 0 );
	$frag = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
	foreach ( explode( '&', $frag ) as $part ) {
		$kv  = explode( '=', $part, 2 );
		$key = strtolower( $kv[0] );
		if ( 'vertical' === $key || 'pion' === $key ) {
			$opts['vertical'] = true;
		} elseif ( ( 'cover' === $key || 'okladka' === $key ) && isset( $kv[1] ) ) {
			$opts['cover'] = absint( $kv[1] );
		}
	}
	return $opts;
}

function embed_html( $matches, $attr, $url, $rawattr ) {
	$src   = 'https://drive.google.com/file/d/' . rawurlencode( $matches[1] ) . '/preview';
	$opts  = link_options( $url );
	$ratio = $opts['vertical'] ? '9 / 16' : '16 / 9';
	$width = $opts['vertical'] ? 'max-width:420px;margin-inline:auto;' : '';
	$title = __( 'Video from Google Drive', 'colorbox-google-media' );

	$image = '';
	if ( $opts['cover'] && wp_attachment_is_image( $opts['cover'] ) ) {
		$image = wp_get_attachment_image(
			$opts['cover'],
			'large',
			false,
			array(
				'alt'      => '',
				'loading'  => 'lazy',
				'decoding' => 'async',
				'style'    => 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;margin:0',
			)
		);
	}

	$play = '<span aria-hidden="true" style="position:absolute;left:50%;top:50%;width:72px;height:72px;margin:-36px 0 0 -36px;'
		. 'border-radius:50%;background:rgba(0,0,0,.6);box-shadow:0 2px 12px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center">'
		. '<svg width="30" height="30" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg></span>';

	$iframe = '<iframe src="' . esc_url( $src ) . '" title="' . esc_attr( $title ) . '" style="position:absolute;inset:0;width:100%;height:100%;border:0" '
		. 'allow="autoplay; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';

	$html = '<div class="colorbox-gm-video" data-src="' . esc_url( $src ) . '" style="position:relative;width:100%;aspect-ratio:' . esc_attr( $ratio ) . ';' . esc_attr( $width ) . 'background:#111;overflow:hidden">'
		. '<button type="button" class="colorbox-gm-video__start" data-title="' . esc_attr( $title ) . '" aria-label="' . esc_attr__( 'Play video', 'colorbox-google-media' ) . '" '
		. 'style="all:unset;position:absolute;inset:0;cursor:pointer;display:block">' . $image . $play . '</button>'
		. '<noscript>' . $iframe . '</noscript>'
		. '</div>';

	wp_enqueue_script( 'colorbox-gm-facade' );

	return apply_filters( 'colorbox_gm_embed_html', $html, $matches, $attr, $url, $rawattr );
}

/**
 * If the Colorbox MCP plugin is active, tell its AI agent how to embed Drive videos.
 */
add_filter(
	'colorbox_mcp_instructions',
	static function ( $text ) {
		return $text . "\n\nVideos hosted on Google Drive: put the Drive file link alone in an embed block and the site renders a click-to-play player: "
			. "<!-- wp:embed {\"url\":\"https://drive.google.com/file/d/FILE_ID/view\"} --><figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://drive.google.com/file/d/FILE_ID/view\n</div></figure><!-- /wp:embed -->. "
			. 'Accepted forms: .../file/d/FILE_ID/view, /preview, /edit, ?usp=sharing, and drive.google.com/open?id=FILE_ID. '
			. 'Options go after # in the URL, joined with &: vertical = 9:16 frame, cover=ATTACHMENT_ID = cover image from the media library (e.g. .../view#vertical&cover=123). '
			. "Without a cover the frame is dark with a play button. The Drive file must be shared as 'Anyone with the link'.";
	}
);
