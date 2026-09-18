(function () {
	'use strict';

	var cfg = window.orninaChatAdmin || {};
	var form = document.getElementById('ornina-chat-form');
	var input = document.getElementById('ornina-chat-input');
	var log = document.getElementById('ornina-chat-log');
	var sendBtn = document.getElementById('ornina-chat-send');

	if (!form || !input || !log || !sendBtn) {
		return;
	}

	function appendMessage(role, text) {
		var el = document.createElement('div');
		el.className = 'ornina-chat-msg ornina-chat-msg--' + role;
		el.textContent = text;
		log.appendChild(el);
		log.scrollTop = log.scrollHeight;
	}

	function setBusy(busy) {
		sendBtn.disabled = busy;
		input.disabled = busy;
		sendBtn.textContent = busy
			? (cfg.i18n && cfg.i18n.sending) || 'Sending…'
			: 'Send';
	}

	form.addEventListener('submit', function (event) {
		event.preventDefault();

		var message = (input.value || '').trim();
		if (!message) {
			appendMessage('error', (cfg.i18n && cfg.i18n.empty) || 'Please enter a message.');
			return;
		}

		appendMessage('user', message);
		input.value = '';
		setBusy(true);

		var body = new FormData();
		body.append('action', 'ornina_chat_send');
		body.append('nonce', cfg.nonce || '');
		body.append('message', message);
		body.append('conversation_id', cfg.conversationId || '');

		fetch(cfg.ajaxUrl || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (res) {
				return res.json().then(function (data) {
					return { ok: res.ok, data: data };
				});
			})
			.then(function (result) {
				var data = result.data || {};
				if (data.success && data.data && data.data.reply) {
					appendMessage('assistant', data.data.reply);
					return;
				}
				var err =
					(data.data && data.data.message) ||
					(cfg.i18n && cfg.i18n.error) ||
					'Chat request failed.';
				appendMessage('error', err);
			})
			.catch(function () {
				appendMessage('error', (cfg.i18n && cfg.i18n.error) || 'Chat request failed.');
			})
			.finally(function () {
				setBusy(false);
				input.focus();
			});
	});
})();
