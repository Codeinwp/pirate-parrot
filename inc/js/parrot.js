(function($, pp){

	$( document ).ready(
		function(){
			init();
		}
	);

	function init() {
		$( document ).on(
			"click", ".ti-parrot-copy", function( e ){
				e.preventDefault();
				var button = this;
				var text   = button.getAttribute( "data-clipboard-text" ) || "";
				copyText( text ).then(
					function(){
						showCopied( button );
					}
				);
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
			function( resolve ){
				var area         = document.createElement( "textarea" );
				area.value       = text;
				area.style.position = "fixed";
				area.style.opacity  = "0";
				document.body.appendChild( area );
				area.focus();
				area.select();
				try {
					document.execCommand( "copy" );
				} catch ( err ) {}
				document.body.removeChild( area );
				resolve();
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
