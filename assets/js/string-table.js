/**
 * Supertext string table:
 *  - "select all" checkbox,
 *  - reveal the human-order pickers (target language / type / delivery) when the
 *    bulk-action dropdown is set to "Supertext Human Translation",
 *  - and — exactly like the Posts/Pages bulk workflow — fetch a live price quote so
 *    the Delivery dropdown lists the real delivery options with their prices.
 *
 * @package Supertext_Wpml
 */
( function () {
	'use strict';

	var cfg = window.SupertextStringTable || {};
	var i18n = cfg.i18n || {};
	var quoteXhr = null;
	var quoteTimer = null;

	function bulkSelect() {
		return document.querySelector( '.st-bulk-action' );
	}

	function isHuman() {
		var sel = bulkSelect();
		return !! sel && sel.value === 'human';
	}

	// Relabels the action button to match the selected bulk action: "Order" for a
	// human order, "Translate" for AI, and the neutral "Apply" when nothing is picked.
	function syncButton() {
		var btn = document.querySelector( '.st-apply' );
		if ( ! btn ) {
			return;
		}
		var sel = bulkSelect();
		var value = sel ? sel.value : '';
		if ( 'human' === value ) {
			btn.textContent = i18n.order || 'Order';
		} else if ( 'ai' === value ) {
			btn.textContent = i18n.translate || 'Translate';
		} else {
			btn.textContent = i18n.apply || 'Apply';
		}
	}

	// Shows/hides the human pickers (and the quote status line) to match the action.
	function syncPickers() {
		var human = isHuman();
		document.querySelectorAll( '.st-picker-human' ).forEach( function ( el ) {
			// Explicit value, not '': the base .st-picker-human rule is display:none.
			el.style.display = human ? 'inline-flex' : 'none';
		} );
		syncButton();
		if ( human ) {
			scheduleQuote();
		}
	}

	// The checked rows' string ids (each checkbox's value is its WPML string id, also
	// in the matching hidden input name="src[<id>]"); the quote endpoint looks them up.
	function selectedSources() {
		var out = [];
		document.querySelectorAll( '.st-row-check:checked' ).forEach( function ( box ) {
			var input = document.querySelector( 'input[name="src[' + box.value + ']"]' );
			if ( input && input.value ) {
				out.push( input.value );
			}
		} );
		return out;
	}

	function status( text ) {
		var el = document.querySelector( '.st-quote-status' );
		if ( el ) {
			el.textContent = text || '';
		}
	}

	function formatPrice( n ) {
		return Number( n ).toFixed( 2 );
	}

	// Collapses Delivery to a single placeholder (options depend on the quote).
	function deliveryPlaceholder( text ) {
		var sel = document.getElementById( 'st-express' );
		if ( ! sel ) {
			return;
		}
		sel.innerHTML = '';
		var opt = document.createElement( 'option' );
		opt.value = '';
		opt.textContent = text || i18n.delivery || 'Delivery';
		sel.appendChild( opt );
		sel.value = '';
	}

	// Rebuilds Delivery from the quote's options (name — price currency) and enables it.
	function populateDelivery( deliveries, cur ) {
		var sel = document.getElementById( 'st-express' );
		if ( ! sel ) {
			return;
		}
		if ( ! deliveries || ! deliveries.length ) {
			deliveryPlaceholder( i18n.noOptions || 'No delivery options' );
			return;
		}

		sel.innerHTML = '';
		var placeholder = document.createElement( 'option' );
		placeholder.value = '';
		placeholder.textContent = i18n.delivery || 'Delivery';
		sel.appendChild( placeholder );

		var toSelect = '';
		deliveries.forEach( function ( d ) {
			var opt = document.createElement( 'option' );
			opt.value = d.delivery_id;
			opt.textContent = d.name + ' — ' + formatPrice( d.price ) + ' ' + cur;
			if ( d.date ) {
				opt.title = d.date;
			}
			sel.appendChild( opt );
			if ( ! toSelect && d.is_default ) {
				toSelect = String( d.delivery_id );
			}
		} );

		sel.value = toSelect;
	}

	function fetchQuote() {
		if ( ! isHuman() ) {
			return;
		}
		var service = document.getElementById( 'st-service' );
		var lang = document.getElementById( 'st-lang' );
		var sources = selectedSources();

		// No rows ticked: the quote (and therefore the Delivery options) can't be
		// built, so explain why the Delivery dropdown is empty.
		if ( ! sources.length ) {
			deliveryPlaceholder();
			status( i18n.needRowsPrice || 'Tick at least one row to see delivery options and the price.' );
			return;
		}
		if ( ! service || ! service.value || ! lang || ! lang.value ) {
			deliveryPlaceholder();
			status( '' );
			return;
		}

		if ( quoteXhr ) {
			quoteXhr.abort();
		}
		deliveryPlaceholder( i18n.quoting || 'Getting price…' );
		status( i18n.quoting || 'Getting price…' );

		var body = new FormData();
		body.append( 'action', cfg.quoteAction );
		body.append( 'nonce', cfg.quoteNonce );
		body.append( 'target_lang', lang.value );
		body.append( 'service_id', service.value );
		sources.forEach( function ( s ) {
			body.append( 'sources[]', s );
		} );

		quoteXhr = new XMLHttpRequest();
		quoteXhr.open( 'POST', cfg.ajaxUrl );
		quoteXhr.onload = function () {
			quoteXhr = null;
			var res = null;
			try {
				res = JSON.parse( this.responseText );
			} catch ( e ) {
				res = null;
			}
			if ( res && res.success ) {
				var cur = res.data.currencySymbol || res.data.currency || '';
				populateDelivery( res.data.deliveries, cur );
				status( '' );
			} else {
				deliveryPlaceholder();
				status( ( res && res.data && res.data.message ) || i18n.quoteFail || 'Could not get a price.' );
			}
		};
		quoteXhr.onerror = function () {
			quoteXhr = null;
			deliveryPlaceholder();
			status( i18n.quoteFail || 'Could not get a price.' );
		};
		quoteXhr.send( body );
	}

	// Debounce so rapid selection/dropdown changes issue a single request.
	function scheduleQuote() {
		clearTimeout( quoteTimer );
		quoteTimer = setTimeout( fetchQuote, 400 );
	}

	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( ! t.closest ) {
			return;
		}
		if ( t.closest( '.st-check-all' ) ) {
			document.querySelectorAll( '.st-row-check' ).forEach( function ( box ) {
				box.checked = t.checked;
			} );
			scheduleQuote();
			return;
		}
		if ( t.closest( '.st-row-check' ) ) {
			scheduleQuote();
			return;
		}
		if ( t.closest( '.st-bulk-action' ) ) {
			syncPickers();
			return;
		}
		if ( t.id === 'st-lang' || t.id === 'st-service' ) {
			scheduleQuote();
		}
	} );

	// Puts the action button into a loading state for the synchronous POST. We do
	// NOT set `disabled`: a disabled submit button is left out of the form data, so
	// its name/value (st_apply) — which the handler keys on — would go missing.
	function setSubmitting( btn, action ) {
		if ( btn.dataset.stBusy ) {
			return;
		}
		btn.dataset.stBusy = '1';
		btn.classList.add( 'st-apply--busy' );
		var label = ( 'human' === action ) ? ( i18n.ordering || 'Ordering…' ) : ( i18n.translating || 'Translating…' );
		btn.innerHTML = '<span class="st-spinner" aria-hidden="true"></span>' + label;
	}

	// Validate + confirm before Apply submits, then show a spinner while the
	// synchronous order/translation POST is in flight (the page reloads on return).
	document.addEventListener( 'click', function ( e ) {
		var apply = e.target.closest ? e.target.closest( '.st-apply' ) : null;
		if ( ! apply ) {
			return;
		}
		var sel = bulkSelect();
		var action = sel ? sel.value : '';

		if ( 'human' === action ) {
			var lang = document.getElementById( 'st-lang' );
			var service = document.getElementById( 'st-service' );
			var express = document.getElementById( 'st-express' );

			if ( ! selectedSources().length ) {
				e.preventDefault();
				status( i18n.needRows || 'Select at least one row.' );
				return;
			}
			if ( ! lang || ! lang.value ) {
				e.preventDefault();
				status( i18n.needLang || 'Select a target language.' );
				return;
			}
			if ( ! service || ! service.value ) {
				e.preventDefault();
				status( i18n.needService || 'Select a translation type.' );
				return;
			}
			if ( ! express || ! express.value ) {
				e.preventDefault();
				status( i18n.needDelivery || 'Select a delivery option.' );
				return;
			}
			if ( ! window.confirm( apply.getAttribute( 'data-confirm' ) || 'Confirm?' ) ) {
				e.preventDefault();
				return;
			}
		} else if ( 'ai' === action ) {
			// No rows: let the server report it — no point spinning for a no-op.
			if ( ! selectedSources().length ) {
				return;
			}
		} else {
			return;
		}

		setSubmitting( apply, action );
	} );

	document.addEventListener( 'DOMContentLoaded', syncPickers );
} )();
