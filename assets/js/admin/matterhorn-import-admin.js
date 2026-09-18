/**
 * Matterhorn admin import UI — selection state + chunked AJAX import.
 *
 * @package Wren_Wold_AI_Agent
 */

( function () {
	'use strict';

	var cfg = window.fashionBrandMatterhornAdmin || {};
	var selected = Object.create( null );
	var touchedCategories = Object.create( null );

	var selectPage = document.getElementById( 'matterhorn-select-page' );
	var importBtn = document.getElementById( 'matterhorn-import-selected' );
	var countEl = document.getElementById( 'matterhorn-selection-count' );
	var progress = document.getElementById( 'matterhorn-progress' );
	var progressFill = document.getElementById( 'matterhorn-progress-fill' );
	var progressStatus = document.getElementById( 'matterhorn-progress-status' );
	var progressLog = document.getElementById( 'matterhorn-progress-log' );
	var progressSummary = document.getElementById( 'matterhorn-progress-summary' );

	if ( ! importBtn ) {
		return;
	}

	function selectedIds() {
		return Object.keys( selected );
	}

	function updateCount() {
		var n = selectedIds().length;
		if ( countEl ) {
			countEl.textContent = n + ' ' + ( cfg.i18n && cfg.i18n.selected ? cfg.i18n.selected : 'selected' );
		}
		importBtn.disabled = n < 1 || importBtn.dataset.busy === '1';
	}

	function syncPageChecks() {
		var boxes = document.querySelectorAll( '.matterhorn-row-check' );
		var allChecked = boxes.length > 0;

		boxes.forEach( function ( box ) {
			box.checked = !! selected[ box.value ];
			if ( ! box.checked ) {
				allChecked = false;
			}
		} );

		if ( selectPage ) {
			selectPage.checked = allChecked;
			selectPage.indeterminate = ! allChecked && Array.prototype.some.call( boxes, function ( box ) {
				return box.checked;
			} );
		}
	}

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;

		if ( target && target.classList && target.classList.contains( 'matterhorn-row-check' ) ) {
			if ( target.checked ) {
				selected[ target.value ] = true;
				if ( target.dataset.category ) {
					touchedCategories[ target.dataset.category ] = true;
				}
			} else {
				delete selected[ target.value ];
			}
			updateCount();
			syncPageChecks();
			return;
		}

		if ( target && target.id === 'matterhorn-select-page' ) {
			document.querySelectorAll( '.matterhorn-row-check' ).forEach( function ( box ) {
				box.checked = target.checked;
				if ( target.checked ) {
					selected[ box.value ] = true;
					if ( box.dataset.category ) {
						touchedCategories[ box.dataset.category ] = true;
					}
				} else {
					delete selected[ box.value ];
				}
			} );
			updateCount();
		}
	} );

	function appendLog( line ) {
		if ( ! progressLog ) {
			return;
		}
		progressLog.textContent += line + '\n';
		progressLog.scrollTop = progressLog.scrollHeight;
	}

	function setProgress( done, total ) {
		var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		if ( progressFill ) {
			progressFill.style.width = pct + '%';
		}
		if ( progressStatus ) {
			progressStatus.textContent =
				( cfg.i18n && cfg.i18n.importing ? cfg.i18n.importing : 'Importing…' ) +
				' ' +
				done +
				' / ' +
				total;
		}
	}

	function postBatch( ids ) {
		var body = new FormData();
		body.append( 'action', 'fashion_brand_matterhorn_import_batch' );
		body.append( 'nonce', cfg.nonce || '' );
		ids.forEach( function ( id ) {
			body.append( 'product_ids[]', id );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	importBtn.addEventListener( 'click', function () {
		var ids = selectedIds();

		if ( ! ids.length ) {
			window.alert( cfg.i18n && cfg.i18n.selectNone ? cfg.i18n.selectNone : 'No products selected.' );
			return;
		}

		var batchSize = parseInt( cfg.batch, 10 ) || 10;
		var queue = ids.slice();
		var total = queue.length;
		var done = 0;
		var totals = {
			created: 0,
			updated: 0,
			skipped_unmapped: 0,
			skipped_other: 0,
			skipped_unrecognized_color: 0,
			errors: 0,
			not_found: 0,
		};

		importBtn.dataset.busy = '1';
		importBtn.disabled = true;

		if ( progress ) {
			progress.classList.add( 'is-active' );
		}
		if ( progressLog ) {
			progressLog.textContent = '';
		}
		if ( progressSummary ) {
			progressSummary.textContent = '';
		}

		setProgress( 0, total );

		function next() {
			if ( ! queue.length ) {
				finish();
				return;
			}

			var batch = queue.splice( 0, batchSize );

			postBatch( batch )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error(
							( json && json.data && json.data.message ) ||
								( cfg.i18n && cfg.i18n.error ) ||
								'Import request failed.'
						);
					}

					var summary = ( json.data && json.data.summary ) || {};
					Object.keys( totals ).forEach( function ( key ) {
						totals[ key ] += summary[ key ] || 0;
					} );

					( ( json.data && json.data.results ) || [] ).forEach( function ( row ) {
						appendLog(
							( row.status || 'unknown' ) +
								' #' +
								( row.product_id || '?' ) +
								( row.message ? ' — ' + row.message : '' )
						);
						delete selected[ row.product_id ];
					} );

					done += batch.length;
					setProgress( Math.min( done, total ), total );
					updateCount();
					syncPageChecks();
					next();
				} )
				.catch( function ( err ) {
					appendLog( String( err && err.message ? err.message : err ) );
					finish( true );
				} );
		}

		function finish( failed ) {
			importBtn.dataset.busy = '0';
			updateCount();

			var cats = Object.keys( touchedCategories );
			var productsUrl = cfg.productsUrl || '';
			var linkHtml = '';

			if ( cats.length && productsUrl ) {
				linkHtml =
					' <a href="' +
					productsUrl +
					'">' +
					'Open WooCommerce → Products' +
					'</a>';
			}

			if ( progressStatus ) {
				progressStatus.textContent = failed
					? ( cfg.i18n && cfg.i18n.error ) || 'Import request failed.'
					: ( cfg.i18n && cfg.i18n.done ) || 'Import complete.';
			}

			if ( progressSummary ) {
				progressSummary.innerHTML =
					'Totals — imported (new): ' +
					totals.created +
					', updated: ' +
					totals.updated +
					', skipped: ' +
					( totals.skipped_unmapped +
						totals.skipped_other +
						totals.skipped_unrecognized_color +
						totals.not_found ) +
					', errors: ' +
					totals.errors +
					'.' +
					linkHtml;
			}
		}

		next();
	} );

	updateCount();
	syncPageChecks();
} )();
