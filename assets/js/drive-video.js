/**
 * Block “Drive video”.
 * - New video: pick a file, scrub the preview to the frame you want as the cover; the browser uploads the video
 *   straight to Google (resumable upload, one PUT with a progress bar) and the frame goes to the media library as JPEG.
 * - Video uploaded before: a list from the Drive folder; a remembered cover is reused at once, “Other frame”
 *   downloads the video into the browser to pick a frame again.
 * Finally the server shares the file (“Anyone with the link”), remembers cover and orientation, and the block turns
 * into a plain Embed block with the Drive link, which the plugin renders as a click-to-play player.
 * No build step: plain JS with wp.element.createElement.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var registerBlockType = wp.blocks.registerBlockType;
	var createBlock = wp.blocks.createBlock;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Placeholder = wp.components.Placeholder;
	var FormFileUpload = wp.components.FormFileUpload;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var apiFetch = wp.apiFetch;
	var cfg = window.colorboxGoogleMedia || {};
	var ns = '/' + ( cfg.ns || 'colorbox-google-media/v1' );

	function mb( b ) {
		return sprintf( __( '%s MB', 'colorbox-google-media' ), ( b / 1048576 ).toFixed( 1 ) );
	}

	function duration( sec ) {
		if ( ! sec ) {
			return '';
		}
		var m = Math.floor( sec / 60 );
		var s = sec % 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	function date( iso ) {
		try {
			return new Date( iso ).toLocaleDateString();
		} catch ( e ) {
			return '';
		}
	}

	function xhr( method, url, headers, body, onProgress, xhrRef, responseType ) {
		return new Promise( function ( resolve, reject ) {
			var r = new XMLHttpRequest();
			if ( xhrRef ) {
				xhrRef.current = r;
			}
			r.open( method, url );
			if ( responseType ) {
				r.responseType = responseType;
			}
			Object.keys( headers ).forEach( function ( k ) {
				r.setRequestHeader( k, headers[ k ] );
			} );
			if ( onProgress ) {
				var target = method === 'GET' ? r : r.upload;
				if ( target ) {
					target.onprogress = function ( e ) {
						if ( e.lengthComputable ) {
							onProgress( e.loaded, e.total );
						}
					};
				}
			}
			r.onload = function () {
				if ( r.status >= 200 && r.status < 300 ) {
					resolve( r );
				} else {
					var msg = 'HTTP ' + r.status;
					try {
						msg = JSON.parse( r.responseText ).error.message || msg;
					} catch ( e ) {}
					reject( new Error( sprintf( __( 'Google: %s', 'colorbox-google-media' ), msg ) ) );
				}
			};
			r.onerror = function () {
				reject( new Error( __( 'The connection to Google was interrupted.', 'colorbox-google-media' ) ) );
			};
			r.onabort = function () {
				reject( new Error( __( 'Cancelled.', 'colorbox-google-media' ) ) );
			};
			r.send( body );
		} );
	}

	function Progress( props ) {
		var pct = props.total ? Math.floor( ( 100 * props.done ) / props.total ) : 0;
		return el(
			'div',
			{ style: { width: '100%' } },
			el( 'p', null, sprintf( __( '%1$s: %2$d%% (%3$s of %4$s). Keep this tab open.', 'colorbox-google-media' ), props.label, pct, mb( props.done ), mb( props.total ) ) ),
			el(
				'div',
				{ style: { height: '8px', background: '#ddd', borderRadius: '4px', overflow: 'hidden', margin: '8px 0' } },
				el( 'div', { style: { height: '100%', width: pct + '%', background: '#3858e9', transition: 'width .3s' } } )
			),
			el( Button, { variant: 'tertiary', onClick: props.onCancel }, __( 'Cancel', 'colorbox-google-media' ) )
		);
	}

	function Edit( props ) {
		var blockProps = useBlockProps();
		var st = useState( { step: 'start' } );
		var s = st[ 0 ];
		var set = st[ 1 ];
		var xhrRef = useRef( null );
		var videoRef = useRef( null );

		function fail( e ) {
			set( { step: 'error', error: ( e && e.message ) || String( e ) } );
		}

		function cancel() {
			if ( xhrRef.current ) {
				xhrRef.current.abort();
			}
		}

		function insert( url ) {
			if ( s.objectUrl ) {
				URL.revokeObjectURL( s.objectUrl );
			}
			wp.data.dispatch( 'core/block-editor' ).replaceBlocks( props.clientId, createBlock( 'core/embed', { url: url } ) );
		}

		function pickFile( file ) {
			if ( ! file ) {
				return;
			}
			if ( file.type && file.type.indexOf( 'video/' ) !== 0 ) {
				set( { step: 'error', error: sprintf( __( 'This is not a video file (%s).', 'colorbox-google-media' ), file.type ) } );
				return;
			}
			set( { step: 'preview', file: file, name: file.name, objectUrl: URL.createObjectURL( file ) } );
		}

		// Current preview frame → JPEG (max 1280 px wide) → media library. Resolves to the attachment ID or 0.
		function uploadCover( videoName ) {
			var v = videoRef.current;
			if ( ! v || ! v.videoWidth ) {
				return Promise.resolve( 0 );
			}
			return new Promise( function ( resolve ) {
				try {
					var scale = Math.min( 1, 1280 / v.videoWidth );
					var c = document.createElement( 'canvas' );
					c.width = Math.round( v.videoWidth * scale );
					c.height = Math.round( v.videoHeight * scale );
					c.getContext( '2d' ).drawImage( v, 0, 0, c.width, c.height );
					c.toBlob(
						function ( blob ) {
							if ( ! blob ) {
								resolve( 0 );
								return;
							}
							var fd = new FormData();
							var name = 'cover-' + videoName.replace( /\.[^.]+$/, '' ).replace( /[^A-Za-z0-9_-]+/g, '-' ).slice( 0, 60 ) + '.jpg';
							fd.append( 'file', blob, name );
							fd.append( 'title', sprintf( __( 'Video cover: %s', 'colorbox-google-media' ), videoName ) );
							apiFetch( { path: '/wp/v2/media', method: 'POST', body: fd } )
								.then( function ( m ) {
									resolve( m && m.id ? m.id : 0 );
								} )
								.catch( function () {
									resolve( 0 );
								} );
						},
						'image/jpeg',
						0.85
					);
				} catch ( e ) {
					resolve( 0 );
				}
			} );
		}

		function isVertical() {
			var v = videoRef.current;
			return !! ( v && v.videoWidth && v.videoHeight > v.videoWidth );
		}

		function share( id, cover, vertical ) {
			set( { step: 'sharing', name: s.name } );
			var data = { id: id };
			if ( cover !== null ) {
				data.cover = cover;
			}
			if ( vertical !== null ) {
				data.vertical = vertical;
			}
			return apiFetch( { path: ns + '/share', method: 'POST', data: data } ).then( function ( w ) {
				insert( w.url );
			} );
		}

		// From the preview: a new file is uploaded to Drive; a video already in Drive only gets its cover.
		function confirm( withCover ) {
			var vertical = isVertical();
			var cover = withCover ? uploadCover( s.name ) : Promise.resolve( 0 );

			if ( s.driveId ) {
				cover
					.then( function ( id ) {
						return share( s.driveId, id, vertical );
					} )
					.catch( fail );
				return;
			}

			var file = s.file;
			var type = file.type || 'video/mp4';
			var coverId = 0;
			cover
				.then( function ( id ) {
					coverId = id;
					set( { step: 'uploading', name: file.name, done: 0, total: file.size, objectUrl: s.objectUrl } );
					return apiFetch( { path: ns + '/token', method: 'POST' } );
				} )
				.then( function ( t ) {
					return xhr(
						'POST',
						'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id',
						{
							Authorization: 'Bearer ' + t.access_token,
							'Content-Type': 'application/json; charset=UTF-8',
							'X-Upload-Content-Type': type,
						},
						JSON.stringify( { name: file.name, parents: [ t.folder_id ] } ),
						null,
						xhrRef
					);
				} )
				.then( function ( r ) {
					// Google does not expose the Location header to browsers, but it does expose X-GUploader-UploadID,
					// from which the same session URL can be built.
					var session = r.getResponseHeader( 'Location' );
					var uploadId = r.getResponseHeader( 'X-GUploader-UploadID' );
					if ( ! session && uploadId ) {
						session = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id&upload_id=' + encodeURIComponent( uploadId );
					}
					if ( ! session ) {
						throw new Error( __( 'Google returned no upload session address.', 'colorbox-google-media' ) );
					}
					return xhr(
						'PUT',
						session,
						{ 'Content-Type': type },
						file,
						function ( done, total ) {
							set( { step: 'uploading', name: file.name, done: done, total: total, objectUrl: s.objectUrl } );
						},
						xhrRef
					);
				} )
				.then( function ( r ) {
					return share( JSON.parse( r.responseText ).id, coverId, vertical );
				} )
				.catch( fail );
		}

		function showList() {
			set( { step: 'list-loading' } );
			apiFetch( { path: ns + '/videos' } )
				.then( function ( videos ) {
					set( { step: 'list', videos: videos } );
				} )
				.catch( fail );
		}

		function insertFromDrive( v ) {
			set( { step: 'sharing', name: v.name } );
			apiFetch( { path: ns + '/share', method: 'POST', data: { id: v.id, vertical: v.vertical } } )
				.then( function ( w ) {
					insert( w.url );
				} )
				.catch( fail );
		}

		function downloadFromDrive( v ) {
			set( { step: 'downloading', name: v.name, done: 0, total: v.size } );
			apiFetch( { path: ns + '/token', method: 'POST' } )
				.then( function ( t ) {
					return xhr(
						'GET',
						'https://www.googleapis.com/drive/v3/files/' + encodeURIComponent( v.id ) + '?alt=media',
						{ Authorization: 'Bearer ' + t.access_token },
						null,
						function ( done, total ) {
							set( { step: 'downloading', name: v.name, done: done, total: total } );
						},
						xhrRef,
						'blob'
					);
				} )
				.then( function ( r ) {
					set( { step: 'preview', driveId: v.id, name: v.name, objectUrl: URL.createObjectURL( r.response ) } );
				} )
				.catch( fail );
		}

		var body;
		if ( ! cfg.canUse ) {
			body = el( Notice, { status: 'warning', isDismissible: false }, __( 'Only administrators can upload to Google Drive. Paste a Drive link on its own line instead — it becomes a player too.', 'colorbox-google-media' ) );
		} else if ( ! cfg.drive ) {
			body = el(
				'div',
				null,
				el( 'p', null, __( 'Google Drive is not connected yet.', 'colorbox-google-media' ) ),
				el( Button, { variant: 'secondary', href: cfg.settingsUrl, target: '_blank' }, __( 'Open Settings → Google Media', 'colorbox-google-media' ) )
			);
		} else if ( s.step === 'preview' ) {
			body = el(
				'div',
				{ style: { width: '100%' } },
				el( 'p', null, __( 'Scrub the video to the frame you want as the cover.', 'colorbox-google-media' ) ),
				el( 'video', {
					ref: videoRef,
					src: s.objectUrl,
					controls: true,
					muted: true,
					playsInline: true,
					preload: 'auto',
					style: { width: '100%', maxHeight: '420px', background: '#000', display: 'block' },
				} ),
				el(
					'div',
					{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginTop: '10px' } },
					el(
						Button,
						{
							variant: 'primary',
							onClick: function () {
								confirm( true );
							},
						},
						s.driveId ? __( 'Insert — this frame is the cover', 'colorbox-google-media' ) : __( 'Upload — this frame is the cover', 'colorbox-google-media' )
					),
					el(
						Button,
						{
							variant: 'secondary',
							onClick: function () {
								confirm( false );
							},
						},
						s.driveId ? __( 'Insert without a cover', 'colorbox-google-media' ) : __( 'Upload without a cover', 'colorbox-google-media' )
					),
					el(
						Button,
						{
							variant: 'tertiary',
							onClick: function () {
								URL.revokeObjectURL( s.objectUrl );
								set( { step: 'start' } );
							},
						},
						__( 'Back', 'colorbox-google-media' )
					)
				)
			);
		} else if ( s.step === 'uploading' ) {
			body = el( Progress, { label: sprintf( __( 'Uploading “%s” to Drive', 'colorbox-google-media' ), s.name ), done: s.done, total: s.total, onCancel: cancel } );
		} else if ( s.step === 'downloading' ) {
			body = el( Progress, { label: sprintf( __( 'Downloading “%s” from Drive to pick a frame', 'colorbox-google-media' ), s.name ), done: s.done, total: s.total, onCancel: cancel } );
		} else if ( s.step === 'sharing' ) {
			body = el( 'p', null, sprintf( __( '“%s”: setting “Anyone with the link” and inserting the player…', 'colorbox-google-media' ), s.name ) );
		} else if ( s.step === 'list-loading' ) {
			body = el( 'p', null, el( Spinner ), ' ', __( 'Loading videos from Drive…', 'colorbox-google-media' ) );
		} else if ( s.step === 'list' ) {
			body = el(
				'div',
				{ style: { width: '100%' } },
				s.videos.length
					? el(
							'ul',
							{ style: { listStyle: 'none', margin: 0, padding: 0, maxHeight: '420px', overflowY: 'auto' } },
							s.videos.map( function ( v ) {
								return el(
									'li',
									{ key: v.id, style: { display: 'flex', gap: '10px', alignItems: 'center', padding: '6px 0', borderBottom: '1px solid #e0e0e0' } },
									el(
										'div',
										{ style: { width: '96px', height: '54px', flex: '0 0 96px', background: '#111', borderRadius: '2px', overflow: 'hidden' } },
										v.thumbnail
											? el( 'img', { src: v.thumbnail, alt: '', referrerPolicy: 'no-referrer', style: { width: '100%', height: '100%', objectFit: 'cover', display: 'block' } } )
											: null
									),
									el(
										'div',
										{ style: { flex: '1 1 auto', minWidth: 0 } },
										el( 'div', { style: { fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, v.name ),
										el(
											'div',
											{ style: { fontSize: '12px', opacity: 0.75 } },
											[ date( v.created ), duration( v.seconds ), mb( v.size ), v.cover ? __( 'with cover', 'colorbox-google-media' ) : __( 'no cover', 'colorbox-google-media' ) ].filter( Boolean ).join( ' · ' )
										)
									),
									el(
										'div',
										{ style: { display: 'flex', gap: '4px', flexWrap: 'wrap', justifyContent: 'flex-end' } },
										el(
											Button,
											{
												variant: 'primary',
												size: 'small',
												onClick: function () {
													if ( v.cover ) {
														insertFromDrive( v );
													} else {
														downloadFromDrive( v );
													}
												},
											},
											v.cover ? __( 'Insert', 'colorbox-google-media' ) : __( 'Pick a frame', 'colorbox-google-media' )
										),
										v.cover
											? el(
													Button,
													{
														variant: 'tertiary',
														size: 'small',
														onClick: function () {
															downloadFromDrive( v );
														},
													},
													__( 'Other frame', 'colorbox-google-media' )
											  )
											: null
									)
								);
							} )
					  )
					: el( 'p', null, __( 'The Drive folder has no videos uploaded from this site yet.', 'colorbox-google-media' ) ),
				el(
					Button,
					{
						variant: 'tertiary',
						onClick: function () {
							set( { step: 'start' } );
						},
					},
					__( 'Back', 'colorbox-google-media' )
				)
			);
		} else {
			body = el(
				'div',
				null,
				s.step === 'error' ? el( Notice, { status: 'error', isDismissible: false }, s.error ) : null,
				el(
					'div',
					{ style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
					el( FormFileUpload, {
						accept: 'video/*',
						onChange: function ( e ) {
							pickFile( e.target.files && e.target.files[ 0 ] );
						},
						render: function ( o ) {
							return el( Button, { variant: 'primary', onClick: o.openFileDialog }, __( 'Upload a new video', 'colorbox-google-media' ) );
						},
					} ),
					el( Button, { variant: 'secondary', onClick: showList }, __( 'Pick a video from Drive', 'colorbox-google-media' ) )
				),
				el(
					'p',
					{ style: { fontSize: '12px', opacity: 0.8, marginTop: '8px' } },
					sprintf( __( 'Videos live in the folder “%s” in your Google Drive (you may move or rename it). Access: anyone with the link.', 'colorbox-google-media' ), cfg.folder || '' )
				)
			);
		}

		return el( 'div', blockProps, el( Placeholder, { icon: 'video-alt3', label: __( 'Drive video', 'colorbox-google-media' ) }, body ) );
	}

	registerBlockType( 'colorbox-gm/drive-video', {
		apiVersion: 3,
		title: __( 'Drive video', 'colorbox-google-media' ),
		description: __( 'Uploads a video to Google Drive or picks one uploaded before, and inserts a player with a cover.', 'colorbox-google-media' ),
		category: 'media',
		icon: 'video-alt3',
		keywords: [ 'drive', 'google', 'video', __( 'video', 'colorbox-google-media' ), __( 'film', 'colorbox-google-media' ) ],
		supports: { html: false, reusable: false },
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
