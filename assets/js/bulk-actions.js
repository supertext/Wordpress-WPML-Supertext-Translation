( function ( $ ) {
	'use strict';

	var ACTION_AI    = 'supertext_ai_translation';
	var ACTION_HUMAN = 'supertext_human_translation';

	var cfg = window.SupertextBulk || {};
	var quoteXhr = null;
	var quoteTimer = null;

	function isSupertextAction( val ) {
		return val === ACTION_AI || val === ACTION_HUMAN;
	}

	// Returns the selected Supertext action from either bulk-action dropdown, or ''.
	function activeAction() {
		var top = $( '#bulk-action-selector-top' ).val();
		if ( isSupertextAction( top ) ) {
			return top;
		}
		var bottom = $( '#bulk-action-selector-bottom' ).val();
		if ( isSupertextAction( bottom ) ) {
			return bottom;
		}
		return '';
	}

	function updatePickerVisibility() {
		var action = activeAction();
		// Language picker shows for both AI and Human.
		$( '#supertext-lang-picker' ).toggle( isSupertextAction( action ) );
		// Translation type + delivery only apply to Human translation.
		$( '#supertext-service-picker' ).toggle( action === ACTION_HUMAN );
		$( '#supertext-express-picker' ).toggle( action === ACTION_HUMAN );
		$( '#supertext-quote-status' ).toggle( action === ACTION_HUMAN );
		if ( action === ACTION_HUMAN ) {
			scheduleQuote();
		}
	}

	function flag( selector ) {
		$( selector ).css( 'outline', '2px solid #d63638' ).focus();
	}

	// Shows a busy state while the (full-page) bulk submit is in flight: relabels and
	// disables the Apply buttons and drops a reassuring overlay over the screen. The
	// overlay — not `disabled` on the pickers — is what blocks further interaction, so
	// the picker <select> values are still included in the submission.
	function showSubmitting( action ) {
		var human = action === ACTION_HUMAN;

		$( '#doaction, #doaction2' )
			.prop( 'disabled', true )
			.val( human ? cfg.i18n.submittingBtnHuman : cfg.i18n.submittingBtnAi );

		if ( $( '#supertext-submitting-overlay' ).length ) {
			return;
		}

		var $box = $( '<div class="supertext-submitting-box"></div>' )
			.append( '<div class="supertext-submitting-spinner" aria-hidden="true"></div>' )
			.append( $( '<p></p>' ).text( human ? cfg.i18n.submittingMsgHuman : cfg.i18n.submittingMsgAi ) );

		$( '<div id="supertext-submitting-overlay" role="status" aria-live="polite"></div>' )
			.append( $box )
			.appendTo( 'body' );
	}

	function onSubmit( e ) {
		var action = activeAction();
		if ( ! action ) {
			return;
		}
		if ( ! $( '#supertext_target_lang' ).val() ) {
			e.preventDefault();
			$( '#supertext-lang-picker' ).show();
			flag( '#supertext_target_lang' );
			return;
		}
		if ( action === ACTION_HUMAN && ! $( '#supertext_service_id' ).val() ) {
			e.preventDefault();
			$( '#supertext-service-picker' ).show();
			flag( '#supertext_service_id' );
			return;
		}
		if ( action === ACTION_HUMAN && ! $( '#supertext_express' ).val() ) {
			e.preventDefault();
			$( '#supertext-express-picker' ).show();
			flag( '#supertext_express' );
			return;
		}

		// Validation passed — the form is about to navigate. Reassure the user while
		// content is uploaded, screenshots captured, and orders placed server-side.
		showSubmitting( action );
	}

	// --- Live quote -------------------------------------------------------------

	function selectedPostIds() {
		var ids = [];
		$( '#the-list input[name="post[]"]:checked' ).each( function () {
			ids.push( $( this ).val() );
		} );
		return ids;
	}

	function formatPrice( n ) {
		return Number( n ).toFixed( 2 );
	}

	function status( text ) {
		$( '#supertext-quote-status' ).text( text || '' );
	}

	// Collapses the delivery dropdown to a single disabled placeholder. Delivery
	// options are only available once a quote returns (and differ per language pair).
	function deliveryPlaceholder( text ) {
		$( '#supertext_express' )
			.prop( 'disabled', true )
			.empty()
			.append( $( '<option>', { value: '', text: text || cfg.i18n.delivery } ) )
			.val( '' );
	}

	// Rebuilds the delivery dropdown from the quote's delivery options (only the
	// ones offered for this service + language pair), with prices, and enables it.
	function populateDelivery( deliveries, cur ) {
		var $sel = $( '#supertext_express' );
		if ( ! deliveries || ! deliveries.length ) {
			deliveryPlaceholder( cfg.i18n.noOptions );
			return;
		}

		var preferred = $sel.data( 'preferred' );
		$sel.empty().append( $( '<option>', { value: '', text: cfg.i18n.delivery } ) );

		var toSelect = '';
		deliveries.forEach( function ( d ) {
			var $o = $( '<option>', {
				value: d.delivery_id,
				text: d.name + ' — ' + formatPrice( d.price ) + ' ' + cur
			} );
			if ( d.date ) {
				$o.attr( 'title', d.date );
			}
			$sel.append( $o );
			if ( String( preferred ) === String( d.delivery_id ) ) {
				toSelect = String( d.delivery_id ); // Remembered choice wins…
			} else if ( ! toSelect && d.is_default ) {
				toSelect = String( d.delivery_id ); // …otherwise the quote's default.
			}
		} );

		$sel.prop( 'disabled', false ).val( toSelect );
	}

	function renderQuote( data ) {
		var cur = data.currencySymbol || data.currency || '';
		populateDelivery( data.deliveries, cur );
		// Prices show on each delivery option, so no summary line is needed — only
		// surface warnings (e.g. a post skipped because it was the same language).
		status( ( data.warnings && data.warnings.length ) ? data.warnings.join( ' ' ) : '' );
	}

	function fetchQuote() {
		if ( activeAction() !== ACTION_HUMAN ) {
			return;
		}
		var service = $( '#supertext_service_id' ).val();
		var lang    = $( '#supertext_target_lang' ).val();
		var ids     = selectedPostIds();

		if ( ! service || ! lang || ! ids.length ) {
			deliveryPlaceholder();
			status( '' );
			return;
		}

		if ( quoteXhr ) {
			quoteXhr.abort();
		}
		deliveryPlaceholder( cfg.i18n.quoting );
		status( cfg.i18n.quoting );

		quoteXhr = $.post( cfg.ajaxUrl, {
			action: cfg.quoteAction,
			nonce: cfg.quoteNonce,
			target_lang: lang,
			service_id: service,
			post_ids: ids
		} ).done( function ( res ) {
			if ( res && res.success ) {
				renderQuote( res.data );
			} else {
				deliveryPlaceholder();
				status( ( res && res.data && res.data.message ) || cfg.i18n.quoteFail );
			}
		} ).fail( function ( xhr, textStatus ) {
			if ( textStatus === 'abort' ) {
				return;
			}
			deliveryPlaceholder();
			status( cfg.i18n.quoteFail );
		} );
	}

	// Debounce so rapid selection/dropdown changes issue a single request.
	function scheduleQuote() {
		clearTimeout( quoteTimer );
		quoteTimer = setTimeout( fetchQuote, 400 );
	}

	$( function () {
		if ( ! cfg.ajaxUrl ) {
			cfg = window.SupertextBulk || {};
		}

		// Styles for the "submitting" overlay + spinner (injected once).
		$( '<style id="supertext-submitting-css">' +
			'#supertext-submitting-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;display:flex;align-items:center;justify-content:center;}' +
			'.supertext-submitting-box{background:#fff;border-radius:6px;padding:24px 28px;max-width:440px;text-align:center;font-size:14px;line-height:1.5;color:#1d2327;box-shadow:0 6px 30px rgba(0,0,0,.25);}' +
			'.supertext-submitting-box p{margin:0;}' +
			'.supertext-submitting-spinner{width:28px;height:28px;margin:0 auto 14px;border:3px solid #dcdcde;border-top-color:#2271b1;border-radius:50%;animation:supertext-spin .8s linear infinite;}' +
			'@keyframes supertext-spin{to{transform:rotate(360deg);}}' +
			'</style>' ).appendTo( 'head' );

		// Place the pickers between the bulk-action select and the Apply button,
		// in order: language, translation type, delivery, then the price readout.
		$( '#supertext-lang-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );
		$( '#supertext-service-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );
		$( '#supertext-express-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );
		$( '<span id="supertext-quote-status" style="display:none;margin-left:8px;color:#1d2327;font-style:italic;vertical-align:middle;"></span>' ).insertAfter( '#doaction' );

		$( '#bulk-action-selector-top, #bulk-action-selector-bottom' ).on( 'change', updatePickerVisibility );
		$( '#supertext_target_lang, #supertext_service_id' ).on( 'change', scheduleQuote );
		// Selection changes (row checkboxes + the header "select all") re-quote.
		$( document ).on( 'change', '#the-list input[name="post[]"], #cb-select-all-1, #cb-select-all-2', scheduleQuote );
		$( '#posts-filter' ).on( 'submit', onSubmit );

		updatePickerVisibility();
	} );

} )( jQuery );
