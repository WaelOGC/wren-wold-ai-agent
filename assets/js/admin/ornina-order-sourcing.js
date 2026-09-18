/**
 * Ornina Order Sourcing admin — inline status AJAX + copy-to-clipboard.
 */
(function () {
	'use strict';

	var cfg = window.orninaOrderSourcing || {};

	function showMsg(el, text, isError) {
		if (!el) {
			return;
		}
		el.textContent = text || '';
		el.classList.toggle('is-error', !!isError);
		if (text) {
			window.setTimeout(function () {
				el.textContent = '';
				el.classList.remove('is-error');
			}, 2000);
		}
	}

	function copyText(text) {
		if (!text) {
			return Promise.reject();
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.left = '-9999px';
			document.body.appendChild(ta);
			ta.select();
			try {
				document.execCommand('copy');
				resolve();
			} catch (e) {
				reject(e);
			}
			document.body.removeChild(ta);
		});
	}

	document.addEventListener('click', function (event) {
		var btn = event.target.closest('.ornina-copy-btn');
		if (!btn) {
			return;
		}
		event.preventDefault();
		var text = btn.getAttribute('data-copy') || '';
		var original = btn.textContent;
		copyText(text).then(function () {
			btn.textContent = (cfg.i18n && cfg.i18n.copied) || 'Copied';
			window.setTimeout(function () {
				btn.textContent = original;
			}, 1500);
		}).catch(function () {
			/* ignore */
		});
	});

	document.addEventListener('change', function (event) {
		var select = event.target.closest('.ornina-inline-sourcing-status');
		if (!select) {
			return;
		}

		var orderId = select.getAttribute('data-order-id');
		var status = select.value;
		var msg = select.parentNode.querySelector('.ornina-status-msg');

		if (!orderId || !cfg.ajaxUrl || !cfg.nonce) {
			showMsg(msg, (cfg.i18n && cfg.i18n.error) || 'Error', true);
			return;
		}

		select.disabled = true;

		var body = new FormData();
		body.append('action', 'ornina_update_sourcing_status');
		body.append('nonce', cfg.nonce);
		body.append('order_id', orderId);
		body.append('status', status);

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				select.disabled = false;
				if (data && data.success) {
					showMsg(msg, (cfg.i18n && cfg.i18n.saved) || 'Saved', false);
				} else {
					var err =
						(data && data.data && data.data.message) ||
						(cfg.i18n && cfg.i18n.error) ||
						'Error';
					showMsg(msg, err, true);
				}
			})
			.catch(function () {
				select.disabled = false;
				showMsg(msg, (cfg.i18n && cfg.i18n.error) || 'Error', true);
			});
	});
})();
