(function($, pp){

	$( document ).ready(
		function(){
			init();
		}
	);

	function init() {
		injectGeneratedFrom();

		$( document ).on(
			"click", ".ti-parrot-copy", function( e ){
				e.preventDefault();
				var button = this;
				var text   = button.getAttribute( "data-clipboard-text" ) || "";
				copyText( text ).then(
					function(){
						showCopied( button );
					}
				).catch( function(){} );
			}
		);

        $( '#pp-flush' ).on(
			"click", function(e){
				e.preventDefault();
				showSpinner();
				$.ajax(
					{
						url: ajaxurl,
						method: "post",
						data: {
							"action"        : "parrot",
							"_action"       : "flush_logs",
							"nonce"         : pp.nonce,
							"plugin_name"   : $( '#pp_plugin_name' ).val()
						},
						success: function (data, textStatus, jqXHR) {
                            $('#pp-view').trigger('click');
						},
						complete: function () {
							hideSpinner();
						}
					}
				);
			}
		);

		$( "input[name='pp-log-type'], #pp-log-actions label" ).on(
			"click", function(e){
				var radio = $( this ).prop( "tagName" ) == "LABEL" ? $( this ).parent() : $( this );
				var type  = radio.val();
				if (type !== "all") {
					$( "#pp-log-console .pp-log" ).hide();
					$( "#pp-log-console .pp-log-" + type ).show();
				} else {
					$( "#pp-log-console .pp-log" ).show();
				}
			}
		);

		$( "#pp-download" ).on(
			"click", function(e){
				e.preventDefault();
				showSpinner();
				$.ajax(
					{
						url: ajaxurl,
						method: "post",
						data: {
							"action"        : "parrot",
							"_action"       : "download_logs",
							"nonce"         : pp.nonce,
							"plugin_name"   : $( '#pp_plugin_name' ).val()
						},
						success: function (data, textStatus, jqXHR) {
							var a = document.createElement( "a" );
							document.body.appendChild( a );
							a.style    = "display: none";
							var blob   = new Blob( [data.data.csv], {type: "application/csv"} ),
							url        = window.URL.createObjectURL( blob );
							a.href     = url;
							a.download = data.data.name;
							a.click();
							setTimeout(
								function () {
									window.URL.revokeObjectURL( url );
								}, 100
							);
						},
						complete: function () {
							hideSpinner();
						}
					}
				);
			}
		);
	}

	// The environment is read in the browser on purpose: proxies and privacy
	// tools rewrite the HTTP User-Agent header, while what support needs is
	// the browser the customer actually generated these details from.
	function injectGeneratedFrom() {
		var card   = document.querySelector( ".ti-parrot-details" );
		var button = document.querySelector( ".ti-parrot-copy" );
		if ( ! card || ! button ) {
			return;
		}
		detectEnvironment(
			function( env ) {
				if ( ! env ) {
					return;
				}
				var label = ( typeof pp !== "undefined" && pp.generatedFrom ) ? pp.generatedFrom : "Details generated from";
				var row   = document.createElement( "div" );
				var name  = document.createElement( "span" );
				var value = document.createElement( "span" );

				row.className   = "ti-parrot-row";
				name.className  = "ti-parrot-row-label";
				value.className = "ti-parrot-row-value";

				name.textContent  = label;
				value.textContent = env;
				row.appendChild( name );
				row.appendChild( value );

				var rows = card.querySelectorAll( ".ti-parrot-row" );
				if ( rows.length ) {
					var last = rows[ rows.length - 1 ];
					last.parentNode.insertBefore( row, last.nextSibling );
				} else {
					card.appendChild( row );
				}

				var text = button.getAttribute( "data-clipboard-text" ) || "";
				button.setAttribute( "data-clipboard-text", text + "\n" + label + ": " + env );
			}
		);
	}

	function detectEnvironment( callback ) {
		var uad = navigator.userAgentData;
		if ( uad && uad.getHighEntropyValues ) {
			uad.getHighEntropyValues( [ "platformVersion", "fullVersionList" ] ).then(
				function( hints ) {
					callback( formatFromClientHints( uad, hints ) || parseUserAgent( navigator.userAgent ) );
				}
			).catch(
				function() {
					callback( parseUserAgent( navigator.userAgent ) );
				}
			);
			return;
		}
		callback( parseUserAgent( navigator.userAgent ) );
	}

	function formatFromClientHints( uad, hints ) {
		var list  = ( hints && hints.fullVersionList && hints.fullVersionList.length ) ? hints.fullVersionList : ( uad.brands || [] );
		var brand = null;
		for ( var i = 0; i < list.length; i++ ) {
			if ( /not.?a.?brand/i.test( list[ i ].brand ) ) {
				continue;
			}
			if ( ! brand || "Chromium" === brand.brand ) {
				brand = list[ i ];
			}
		}
		if ( ! brand ) {
			return null;
		}

		var browser = brand.brand + " " + String( brand.version ).split( "." )[ 0 ];
		var os      = uad.platform || "";
		var version = String( ( hints && hints.platformVersion ) || "" );
		if ( "Windows" === os && version ) {
			// Chromium encodes Windows in platformVersion majors:
			// 13+ is Windows 11, 1-12 is Windows 10, 0 predates Windows 10.
			var major = parseInt( version.split( "." )[ 0 ], 10 );
			os        = major >= 13 ? "Windows 11" : ( major >= 1 ? "Windows 10" : "Windows" );
		} else if ( version && ( "macOS" === os || "Android" === os || "Chrome OS" === os ) ) {
			os += " " + version.split( "." ).slice( 0, 2 ).join( "." ).replace( /\.0$/, "" );
		}

		return os ? browser + " on " + os : browser;
	}

	function parseUserAgent( ua ) {
		var browser = null;
		var os      = null;
		var m;

		// iPadOS 13+ reports a Mac user agent; the touch screen gives it away.
		var iPad = /iPad/.test( ua ) || ( "MacIntel" === navigator.platform && navigator.maxTouchPoints > 1 );

		if ( iPad || /iPhone|iPod/.test( ua ) ) {
			os = iPad ? "iPadOS" : "iOS";
			m  = ua.match( /OS (\d+)[_.](\d+)/ );
			if ( m ) {
				os += " " + m[ 1 ] + "." + m[ 2 ];
			}
			if ( ( m = ua.match( /CriOS\/(\d+)/ ) ) ) {
				browser = "Chrome " + m[ 1 ];
			} else if ( ( m = ua.match( /FxiOS\/(\d+)/ ) ) ) {
				browser = "Firefox " + m[ 1 ];
			} else if ( ( m = ua.match( /EdgiOS\/(\d+)/ ) ) ) {
				browser = "Edge " + m[ 1 ];
			} else if ( /OPiOS|OPT\//.test( ua ) ) {
				browser = "Opera";
			} else if ( ( m = ua.match( /Version\/(\d+(?:\.\d+)?)/ ) ) ) {
				browser = "Safari " + m[ 1 ];
			} else {
				browser = "Safari";
			}
			return browser + " on " + os;
		}

		if ( ( m = ua.match( /Android (\d+(?:\.\d+)?)/ ) ) ) {
			os = "Android " + m[ 1 ];
		} else if ( ( m = ua.match( /Windows NT (\d+\.\d+)/ ) ) ) {
			// NT 10.0 covers both Windows 10 and 11 — the frozen user agent
			// cannot tell them apart without client hints.
			os = { "10.0": "Windows 10/11", "6.3": "Windows 8.1", "6.2": "Windows 8", "6.1": "Windows 7" }[ m[ 1 ] ] || "Windows";
		} else if ( /CrOS/.test( ua ) ) {
			os = "Chrome OS";
		} else if ( ( m = ua.match( /Mac OS X (\d+[_.]\d+(?:[_.]\d+)?)/ ) ) ) {
			var v = m[ 1 ].replace( /_/g, "." );
			// Browsers freeze the reported macOS version at 10.15.7 — treat it
			// as "recent macOS" rather than reporting a 2019 system.
			os = "10.15.7" === v ? "macOS" : "macOS " + v.split( "." ).slice( 0, 2 ).join( "." );
		} else if ( /Macintosh/.test( ua ) ) {
			os = "macOS";
		} else if ( /Linux/.test( ua ) ) {
			os = "Linux";
		}

		if ( ( m = ua.match( /Edg(?:e|A)?\/(\d+)/ ) ) ) {
			browser = "Edge " + m[ 1 ];
		} else if ( ( m = ua.match( /OPR\/(\d+)/ ) ) ) {
			browser = "Opera " + m[ 1 ];
		} else if ( ( m = ua.match( /SamsungBrowser\/(\d+)/ ) ) ) {
			browser = "Samsung Internet " + m[ 1 ];
		} else if ( ( m = ua.match( /Firefox\/(\d+)/ ) ) ) {
			browser = "Firefox " + m[ 1 ];
		} else if ( ( m = ua.match( /Chrome\/(\d+)/ ) ) ) {
			browser = "Chrome " + m[ 1 ];
		} else if ( /Safari\//.test( ua ) && ( m = ua.match( /Version\/(\d+(?:\.\d+)?)/ ) ) ) {
			browser = "Safari " + m[ 1 ];
		}

		if ( browser && os ) {
			return browser + " on " + os;
		}
		return browser || os;
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text ).catch( function(){
				return legacyCopy( text );
			} );
		}
		return legacyCopy( text );
	}

	function legacyCopy( text ) {
		return new Promise(
			function( resolve, reject ){
				var area            = document.createElement( "textarea" );
				var ok              = false;
				area.value          = text;
				area.style.position = "fixed";
				area.style.opacity  = "0";
				document.body.appendChild( area );
				area.focus();
				area.select();
				try {
					ok = document.execCommand( "copy" );
				} catch ( err ) {}
				document.body.removeChild( area );
				if ( ok ) {
					resolve();
				} else {
					reject( new Error( "copy_failed" ) );
				}
			}
		);
	}

	function showCopied( button ) {
		var $button = $( button );
		var $icon   = $button.find( ".dashicons" );
		var $label  = $button.find( ".ti-parrot-copy-label" );
		var prev    = $label.length ? $label.text() : "";

		$button.addClass( "ti-parrot-copied" );
		$icon.removeClass( "dashicons-clipboard" ).addClass( "dashicons-yes" );
		if ( $label.length ) {
			$label.text( ( typeof pp !== "undefined" && pp.copied ) ? pp.copied : "Copied!" );
		}

		setTimeout(
			function(){
				$button.removeClass( "ti-parrot-copied" );
				$icon.removeClass( "dashicons-yes" ).addClass( "dashicons-clipboard" );
				if ( $label.length ) {
					$label.text( prev );
				}
			}, 1600
		);
	}

	function showSpinner() {
		$( '#pp-spinner' ).css( 'visibility', 'visible' ).attr( 'aria-hidden', 'false' ).show();
	}

	function hideSpinner() {
		$( '#pp-spinner' ).css( 'visibility', 'hidden' ).attr( 'aria-hidden', 'true' ).hide();
	}

})(jQuery, pp);
