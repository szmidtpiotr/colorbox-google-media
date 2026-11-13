/**
 * Block “Google Photos”: opens Google's picker window, waits for the selection, imports the photos one by one into
 * the media library and turns into a Gallery block (one photo → an Image block).
 * The window is opened synchronously in the click (as a blank tab) and pointed at the picker once the session
 * exists — otherwise the browser would block the pop-up.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var registerBlockType = wp.blocks.registerBlockType;
	var createBlock = wp.blocks.createBlock;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Placeholder = wp.components.Placeholder;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var apiFetch = wp.apiFetch;
	var cfg = window.colorboxGoogleMedia || {};
	var ns = '/' + ( cfg.ns || 'colorbox-google-media/v1' );

	function wait( ms ) {
		return new Promise( function ( r ) {
			setTimeout( r, ms );
		} );
	}

	function Edit( props ) {
		var blockProps = useBlockProps();
		var st = useState( { step: 'start' } );
		var s = st[ 0 ];
		var set = st[ 1 ];
		var stop = useRef( false );
		var sessionRef = useRef( null );

		useEffect( function () {
			return function () {
				stop.current = true;
			};
		}, [] );

		function cleanup() {
			if ( sessionRef.current ) {
				apiFetch( { path: ns + '/photos/session/' + sessionRef.current, method: 'DELETE' } ).catch( function () {} );
				sessionRef.current = null;
			}
		}

		function fail( e ) {
			cleanup();
			set( { step: 'error', error: ( e && e.message ) || String( e ) } );
		}

		function start() {
			stop.current = false;
			var win = window.open( '', '_blank' );
			set( { step: 'session' } );
			var session;
			var editor = wp.data.select( 'core/editor' );
			var postId = editor ? editor.getCurrentPostId() : 0;

			apiFetch( { path: ns + '/photos/session', method: 'POST' } )
				.then( function ( res ) {
					session = res;
					sessionRef.current = res.id;
					if ( win ) {
						win.location.href = res.pickerUri;
					}
					set( { step: 'picking', pickerUri: res.pickerUri, blocked: ! win } );
					var deadline = Date.now() + res.timeout * 1000;
					var poll = function () {
						if ( stop.current ) {
							throw new Error( __( 'Cancelled.', 'colorbox-google-media' ) );
						}
						if ( Date.now() > deadline ) {
							throw new Error( __( 'The time for picking photos ran out. Start again.', 'colorbox-google-media' ) );
						}
						return apiFetch( { path: ns + '/photos/session/' + res.id } ).then( function ( r ) {
							return r.done ? r : wait( res.interval * 1000 ).then( poll );
						} );
					};
					return wait( res.interval * 1000 ).then( poll );
				} )
				.then( function ( r ) {
					var photos = r.items.filter( function ( i ) {
						return i.type === 'PHOTO';
					} );
					var skipped = r.items.length - photos.length;
					var ok = [];
					var errors = [];
					var n = 0;
					var next = function () {
						if ( stop.current ) {
							throw new Error( __( 'Cancelled.', 'colorbox-google-media' ) );
						}
						if ( n >= photos.length ) {
							return Promise.resolve();
						}
						var item = photos[ n++ ];
						set( { step: 'importing', done: n - 1, total: photos.length, name: item.name } );
						return apiFetch( {
							path: ns + '/photos/import',
							method: 'POST',
							data: { session: session.id, item: item.id, post: postId || 0 },
						} )
							.then( function ( w ) {
								if ( w && w.id ) {
									ok.push( w );
								}
							} )
							.catch( function ( err ) {
								errors.push( ( err && err.message ) || item.name );
							} )
							.then( next );
					};
					return next().then( function () {
						return { ok: ok, errors: errors, skipped: skipped };
					} );
				} )
				.then( function ( w ) {
					cleanup();
					if ( ! w.ok.length ) {
						set( {
							step: 'error',
							error: w.errors.length
								? w.errors.join( ' ' )
								: w.skipped
								? __( 'Only videos were picked — use the Drive video block for videos.', 'colorbox-google-media' )
								: __( 'No photo was picked.', 'colorbox-google-media' ),
						} );
						return;
					}
					if ( w.errors.length || w.skipped ) {
						var msg = sprintf( __( 'Google Photos: imported %d.', 'colorbox-google-media' ), w.ok.length );
						if ( w.skipped ) {
							msg += ' ' + sprintf( __( 'Videos skipped: %d.', 'colorbox-google-media' ), w.skipped );
						}
						if ( w.errors.length ) {
							msg += ' ' + sprintf( __( 'Errors: %s', 'colorbox-google-media' ), w.errors.join( ' ' ) );
						}
						wp.data.dispatch( 'core/notices' ).createWarningNotice( msg, { type: 'snackbar' } );
					}
					var images = w.ok.map( function ( p ) {
						return createBlock( 'core/image', { id: p.id, url: p.url, alt: '', sizeSlug: 'large', linkDestination: 'none' } );
					} );
					var block = images.length === 1 ? images[ 0 ] : createBlock( 'core/gallery', { linkTo: 'none', sizeSlug: 'large' }, images );
					wp.data.dispatch( 'core/block-editor' ).replaceBlocks( props.clientId, block );
				} )
				.catch( fail );
		}

		var body;
		if ( ! cfg.canUse ) {
			body = el( Notice, { status: 'warning', isDismissible: false }, __( 'Importing from Google Photos is for administrators only.', 'colorbox-google-media' ) );
		} else if ( ! cfg.photos ) {
			body = el(
				'div',
				null,
				el( 'p', null, __( 'The Google connection has no Google Photos access yet.', 'colorbox-google-media' ) ),
				el( Button, { variant: 'secondary', href: cfg.settingsUrl, target: '_blank' }, __( 'Settings → Google Media → “Reconnect”', 'colorbox-google-media' ) )
			);
		} else if ( s.step === 'session' ) {
			body = el( 'p', null, el( Spinner ), ' ', __( 'Opening Google’s picker…', 'colorbox-google-media' ) );
		} else if ( s.step === 'picking' ) {
			body = el(
				'div',
				null,
				el( 'p', null, el( Spinner ), ' ', __( 'Pick photos in the Google tab and click “Done”. This tab is waiting.', 'colorbox-google-media' ) ),
				s.blocked
					? el( 'p', null, __( 'The browser blocked the new tab.', 'colorbox-google-media' ) + ' ', el( 'a', { href: s.pickerUri, target: '_blank', rel: 'noopener' }, __( 'Open the photo picker', 'colorbox-google-media' ) ) )
					: el( 'p', { style: { fontSize: '12px', opacity: 0.8 } }, __( 'Tab closed?', 'colorbox-google-media' ) + ' ', el( 'a', { href: s.pickerUri, target: '_blank', rel: 'noopener' }, __( 'Open the picker again', 'colorbox-google-media' ) ) ),
				el(
					Button,
					{
						variant: 'tertiary',
						onClick: function () {
							stop.current = true;
						},
					},
					__( 'Cancel', 'colorbox-google-media' )
				)
			);
		} else if ( s.step === 'importing' ) {
			body = el( 'p', null, el( Spinner ), ' ', sprintf( __( 'Importing into the media library: %1$d of %2$d (“%3$s”).', 'colorbox-google-media' ), s.done + 1, s.total, s.name ) );
		} else {
			body = el(
				'div',
				null,
				s.step === 'error' ? el( Notice, { status: 'error', isDismissible: false }, s.error ) : null,
				el( Button, { variant: 'primary', onClick: start }, s.step === 'error' ? __( 'Try again', 'colorbox-google-media' ) : __( 'Pick in Google Photos', 'colorbox-google-media' ) ),
				el(
					'p',
					{ style: { fontSize: '12px', opacity: 0.8, marginTop: '8px' } },
					__( 'Photos are copied into the media library (max 2560 px, no GPS location) and inserted here as a gallery. Videos are skipped.', 'colorbox-google-media' )
				)
			);
		}

		return el( 'div', blockProps, el( Placeholder, { icon: 'format-gallery', label: __( 'Google Photos', 'colorbox-google-media' ) }, body ) );
	}

	registerBlockType( 'colorbox-gm/google-photos', {
		apiVersion: 3,
		title: __( 'Google Photos', 'colorbox-google-media' ),
		description: __( 'Imports photos picked in Google Photos into the media library and inserts a gallery.', 'colorbox-google-media' ),
		category: 'media',
		icon: 'format-gallery',
		keywords: [ 'google', 'photos', __( 'gallery', 'colorbox-google-media' ), __( 'album', 'colorbox-google-media' ) ],
		supports: { html: false, reusable: false },
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
