/* Street Empire frontend: submit action forms via AJAX, refresh stats. */
(function () {
	'use strict';
	var root = document.getElementById('se-game');
	if (!root || typeof SEGame === 'undefined') return;

	var msgEl = document.getElementById('se-msg');
	var statsEl = document.getElementById('se-stats');

	function setMessage(text, ok) {
		msgEl.textContent = text || '';
		msgEl.classList.toggle('is-ok', !!ok);
		msgEl.classList.toggle('is-err', !ok);
	}

	root.querySelectorAll('select[data-se-pref]').forEach(function (sel) {
		sel.addEventListener('change', function () {
			var pref = sel.getAttribute('data-se-pref');
			var val = sel.value;

			// Apply instantly.
			if (pref === 'theme') {
				Array.prototype.slice.call(root.classList).forEach(function (c) {
					if (c.indexOf('se-theme-') === 0) root.classList.remove(c);
				});
				root.classList.add('se-theme-' + val);
			} else if (pref === 'text') {
				root.classList.remove('se-text-lg', 'se-text-xl');
				if (val !== 'std') root.classList.add('se-text-' + val);
			}

			// Persist in the background.
			var data = new FormData();
			data.append('action', 'se_action');
			data.append('game_action', 'display');
			data.append('nonce', SEGame.nonce);
			data.append(pref, val);
			fetch(SEGame.ajax, { method: 'POST', credentials: 'same-origin', body: data }).catch(function () {});
		});
	});

	root.querySelectorAll('form[data-se-action]').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var confirmMsg = form.getAttribute('data-se-confirm');
			if (confirmMsg && !window.confirm(confirmMsg)) return;
			var btn = form.querySelector('button[type="submit"]');
			if (btn) btn.disabled = true;

			var data = new FormData(form);
			data.append('action', 'se_action');
			data.append('game_action', form.getAttribute('data-se-action'));
			data.append('nonce', SEGame.nonce);

			fetch(SEGame.ajax, { method: 'POST', credentials: 'same-origin', body: data })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					var payload = res.data || {};
					setMessage(payload.message || 'Something went wrong.', !!res.success);
					if (payload.stats && statsEl) statsEl.innerHTML = payload.stats;
					var upsell = document.getElementById('se-upsell');
					if (upsell && typeof payload.low !== 'undefined') upsell.hidden = !payload.low;
					var act = form.getAttribute('data-se-action');
					if (res.success && (act === 'attack' || act.indexOf('crew_') === 0)) {
						setTimeout(function () { window.location.reload(); }, 1500);
					}
				})
				.catch(function () { setMessage('Network error — try again.', false); })
				.finally(function () { if (btn) btn.disabled = false; });
		});
	});
})();
