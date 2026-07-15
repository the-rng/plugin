/**
 * The-RNG frontend.
 *
 * Generator: commit -> countdown to the committed drand round -> resolve -> reveal.
 * Verify: load any draw record, link to independent beacon relays, and
 * recompute the entire result in the visitor's own browser (Web Crypto).
 */
(function () {
	'use strict';

	var cfg = window.trng_config || {};

	function $(sel) { return document.querySelector(sel); }
	function show(el) { if (el) el.classList.remove('trng-hidden'); }
	function hide(el) { if (el) el.classList.add('trng-hidden'); }
	function setText(el, text) { if (el) el.textContent = text == null ? '' : String(text); }

	function post(action, fields) {
		var fd = new FormData();
		fd.append('action', action);
		Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
		return fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (res) { return res.json(); });
	}

	function bytesToHex(bytes) {
		return Array.prototype.map.call(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
	}

	function hmacSha256(message, key) {
		var enc = new TextEncoder();
		return crypto.subtle.importKey('raw', enc.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
			.then(function (k) { return crypto.subtle.sign('HMAC', k, enc.encode(message)); })
			.then(function (sig) { return new Uint8Array(sig); });
	}

	/* ------------------------------------------------------------------
	   LIVE ROUND PILL (pure arithmetic from public chain constants)
	------------------------------------------------------------------ */

	function startLiveRound() {
		var roundEl = document.getElementById('trng-live-round');
		var nextEl = document.getElementById('trng-next-round');
		if (!roundEl && !nextEl) return;

		function tick() {
			var now = Date.now() / 1000;
			var round = Math.floor((now - cfg.chain_genesis) / cfg.chain_period) + 1;
			if (roundEl) setText(roundEl, round.toLocaleString());
			if (nextEl) {
				var toNext = cfg.chain_period - ((now - cfg.chain_genesis) % cfg.chain_period);
				setText(nextEl, toNext.toFixed(1));
			}
		}
		tick();
		setInterval(tick, 250);
	}

	/* ------------------------------------------------------------------
	   SERVER-SYNCED CLOCK + LIVE CLIENT SEED TICKER
	   (display timezone is configurable; records stay UTC)
	------------------------------------------------------------------ */

	var serverOffsetMs = 0;

	function syncServerOffset() {
		post('trng_server_time', {}).then(function (data) {
			if (data && data.success && data.data && data.data.unix) {
				serverOffsetMs = (data.data.unix * 1000) - Date.now();
			}
		}).catch(function () { /* cosmetic */ });
	}

	function formatClock(d) {
		var ms = String(d.getTime() % 1000);
		while (ms.length < 3) ms = '0' + ms;
		try {
			var hms = new Intl.DateTimeFormat('en-GB', {
				timeZone: cfg.display_timezone || 'Europe/London',
				hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
			}).format(d);
			return hms + '.' + ms;
		} catch (e) {
			return d.toISOString().slice(11, 23);
		}
	}

	function renderDigits(container, str) {
		var cells = container.children;
		if (cells.length !== str.length) {
			container.innerHTML = '';
			for (var i = 0; i < str.length; i++) {
				var span = document.createElement('span');
				span.className = 'trng-seed-digit';
				span.textContent = str.charAt(i);
				container.appendChild(span);
			}
			return;
		}
		for (var j = 0; j < str.length; j++) {
			if (cells[j].textContent !== str.charAt(j)) {
				cells[j].textContent = str.charAt(j);
			}
		}
	}

	function startServerClock() {
		var clockEl = document.getElementById('trng-server-clock');
		var seedClockEl = document.getElementById('trng-seed-clock');
		var digitsEl = document.getElementById('trng-seed-digits');
		if (!clockEl && !seedClockEl && !digitsEl) return;

		syncServerOffset();
		setInterval(syncServerOffset, 30000);

		function render() {
			var d = new Date(Date.now() + serverOffsetMs);
			if (clockEl) setText(clockEl, d.toISOString().replace('T', ' ').slice(0, 19) + ' UTC');
			if (seedClockEl) setText(seedClockEl, formatClock(d));
			if (digitsEl) renderDigits(digitsEl, String(d.getTime()));
		}

		render();
		setInterval(render, 66);
	}

	/* ------------------------------------------------------------------
	   ROLLING NUMBER ANIMATION
	------------------------------------------------------------------ */

	function startRolling(el, max) {
		if (!el) return null;
		return setInterval(function () {
			el.textContent = String(Math.floor(Math.random() * max) + 1);
		}, 70);
	}

	/* ------------------------------------------------------------------
	   GENERATOR
	------------------------------------------------------------------ */

	function beaconLinks(round) {
		var relays = (cfg.relays || []).slice(0, 3);
		return relays.map(function (relay) {
			var url = relay.replace(/\/+$/, '') + '/' + cfg.chain_hash + '/public/' + round;
			var host = relay.replace(/^https?:\/\//, '');
			return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">Beacon on ' + host + '</a>';
		}).join(' ');
	}

	function renderWinners(container, winners) {
		container.innerHTML = '';
		(winners || []).forEach(function (ticket, i) {
			var div = document.createElement('div');
			div.className = 'trng-winner';
			div.style.animationDelay = (i * 0.18) + 's';
			var small = document.createElement('small');
			small.textContent = (winners.length > 1) ? ('Winner #' + (i + 1)) : 'Winning Ticket';
			div.appendChild(small);
			div.appendChild(document.createTextNode(String(ticket)));
			container.appendChild(div);
		});
	}

	function handleGenerator() {
		var form = $('#trng-form');
		if (!form) return;

		var statusEl = $('#trng-status');
		var commitEl = $('#trng-commit');
		var animEl = $('#trng-animation');
		var resultEl = $('#trng-result');
		var btn = $('#trng-generate-btn');
		var rolling = null;
		var countdownTimer = null;

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			hide(resultEl); hide(commitEl);
			statusEl.classList.remove('trng-status-error');
			setText(statusEl, 'Committing draw to a future drand round…');
			btn.disabled = true;

			var fields = {
				title: form.querySelector('[name="title"]').value,
				url: form.querySelector('[name="url"]').value,
				tickets_sold: form.querySelector('[name="tickets_sold"]').value,
				max_tickets: form.querySelector('[name="max_tickets"]').value,
				num_winners: form.querySelector('[name="num_winners"]').value,
				trng_nonce: form.querySelector('[name="trng_nonce"]').value
			};

			var maxTickets = parseInt(fields.max_tickets, 10) || 100;

			post('trng_start', fields).then(function (data) {
				if (!data || !data.success) {
					fail((data && data.data && data.data.message) || 'Could not start the draw.');
					return;
				}

				var c = data.data;
				setText($('#trng-c-round'), Number(c.target_round).toLocaleString());
				setText($('#trng-c-key'), c.draw_key);
				setText($('#trng-c-clientseed'), c.client_seed);
				setText($('#trng-c-uuid'), c.round_uuid);
				show(commitEl);

				setText(statusEl, 'Commitment locked. Waiting for the League of Entropy to emit round ' + Number(c.target_round).toLocaleString() + '…');
				rolling = startRolling(animEl, maxTickets);

				// Countdown based on server clock (avoids local clock skew).
				var remaining = Math.max(0, (c.round_time - c.server_now)) + 0.6;
				var endAt = Date.now() + remaining * 1000;
				var cdEl = $('#trng-c-countdown');

				countdownTimer = setInterval(function () {
					var left = (endAt - Date.now()) / 1000;
					setText(cdEl, left > 0 ? left.toFixed(1) : '0.0');
					if (left <= 0) {
						clearInterval(countdownTimer);
						attemptComplete(c.draw_key, 0);
					}
				}, 100);
			}).catch(function () { fail('Network error. Please try again.'); });

			function attemptComplete(drawKey, tries) {
				if (tries > 20) { fail('The drand relay is not responding. The draw is committed and can be resolved from the Verify page at any time using key ' + drawKey + '.'); return; }

				post('trng_complete', { draw_key: drawKey }).then(function (data) {
					if (data && data.success) { reveal(data.data); return; }

					var payload = (data && data.data) || {};
					if (payload.retry) {
						var waitS = Math.min(3, Math.max(1, payload.ready_in || 1.5));
						setTimeout(function () { attemptComplete(drawKey, tries + 1); }, waitS * 1000);
						return;
					}
					fail(payload.message || 'Could not resolve the draw.');
				}).catch(function () {
					setTimeout(function () { attemptComplete(drawKey, tries + 1); }, 1500);
				});
			}

			function reveal(d) {
				if (rolling) clearInterval(rolling);
				var winners = d.winners || [];
				setText(animEl, winners.join('  ·  '));

				setText($('#trng-r-noun'), winners.length > 1 ? 'Numbers' : 'Number');
				renderWinners($('#trng-r-winners'), winners);

				setText($('#trng-r-key'), d.draw_key);
				setText($('#trng-r-round'), Number(d.target_round).toLocaleString() + ' (quicknet)');
				setText($('#trng-r-serverseed'), d.server_seed);
				setText($('#trng-r-clientseed'), d.client_seed);
				setText($('#trng-r-uuid'), d.round_uuid);
				setText($('#trng-r-salt'), d.static_salt);
				setText($('#trng-r-combined'), d.combined_hash);
				setText($('#trng-r-signature'), d.drand_signature);
				setText($('#trng-r-completed'), d.completed_at_utc + ' UTC');

				var links = $('#trng-r-links');
				if (links) links.innerHTML = beaconLinks(d.target_round);

				show(resultEl);
				setText(statusEl, 'Draw complete. All inputs are now public — verify the result on any device.');
				btn.disabled = false;
			}

			function fail(message) {
				if (rolling) clearInterval(rolling);
				if (countdownTimer) clearInterval(countdownTimer);
				setText(animEl, '—');
				setText(statusEl, message);
				statusEl.classList.add('trng-status-error');
				btn.disabled = false;
			}
		});
	}

	/* ------------------------------------------------------------------
	   VERIFY PAGE
	------------------------------------------------------------------ */

	// Exact mirror of the server algorithm (and of the published scripts).
	function recompute(d) {
		var data = d.client_seed + ':' + d.round_uuid + ':' + d.static_salt + ':' + d.tickets_sold + ':' + d.max_tickets;
		var max = Number(d.max_tickets);
		var need = Number(d.num_winners) || 1;
		var limit = 4294967295 - (4294967295 % max);
		var log = [];

		log.push('data          = "' + data + '"');
		log.push('key           = serverSeed = ' + d.server_seed);
		log.push('limit         = 4294967295 - (4294967295 % ' + max + ') = ' + limit);

		function walk(bytes, block, winners) {
			for (var i = 0; i + 4 <= bytes.length && winners.length < need; i += 4) {
				var value = new DataView(bytes.buffer, i, 4).getUint32(0, true);
				var line = 'block ' + block + ' bytes[' + i + '..' + (i + 3) + '] = ' + bytesToHex(bytes.slice(i, i + 4)) + ' -> uint32 LE ' + value;

				if (value >= limit) { log.push(line + '  REJECTED (>= limit)'); continue; }
				var ticket = (value % max) + 1;
				if (winners.indexOf(ticket) !== -1) { log.push(line + '  duplicate ticket ' + ticket + ' — skipped'); continue; }
				winners.push(ticket);
				log.push(line + '  ACCEPTED -> (value % ' + max + ') + 1 = ticket ' + ticket + '  (winner #' + winners.length + ')');
			}
			return winners;
		}

		return hmacSha256(data, d.server_seed).then(function (bytes) {
			var combined = bytesToHex(bytes);
			log.push('HMAC-SHA256   = ' + combined);
			log.push(combined === d.combined_hash ? 'combined hash MATCHES stored value ✓' : 'combined hash DOES NOT MATCH stored value ✗');

			var winners = walk(bytes, 0, []);

			function extend(block) {
				if (winners.length >= need) return Promise.resolve();
				return hmacSha256(data + ':extend:' + block, d.server_seed).then(function (b2) {
					log.push('extension block ' + block + ' = HMAC-SHA256(data + ":extend:' + block + '")');
					walk(b2, block, winners);
					return extend(block + 1);
				});
			}

			return extend(1).then(function () {
				var stored = (d.winners || []).join(',');
				var mine = winners.join(',');
				var ok = combined === d.combined_hash && stored === mine;
				log.push('');
				log.push('recomputed winners: ' + mine);
				log.push('stored winners:     ' + stored);
				return { ok: ok, log: log.join('\n') };
			});
		});
	}

	function handleVerify() {
		var form = $('#trng-verify-form');
		if (!form) return;

		var statusEl = $('#trng-verify-status');
		var resultEl = $('#trng-verify-result');
		var gridEl = $('#trng-v-grid');
		var current = null;

		function row(label, value, mono) {
			var wrap = document.createElement('div');
			var l = document.createElement('div');
			l.className = 'trng-label';
			l.textContent = label;
			var v = document.createElement('div');
			v.className = 'trng-value trng-wrap' + (mono ? ' trng-mono' : '');
			v.textContent = value == null ? '—' : String(value);
			wrap.appendChild(l); wrap.appendChild(v);
			gridEl.appendChild(wrap);
		}

		function load(key) {
			hide(resultEl);
			statusEl.classList.remove('trng-status-error');
			setText(statusEl, 'Loading draw record…');

			post('trng_lookup', { draw_key: key }).then(function (data) {
				if (!data || !data.success) {
					setText(statusEl, (data && data.data && data.data.message) || 'No draw found for that key.');
					statusEl.classList.add('trng-status-error');
					return;
				}

				var d = data.data;
				current = d;
				gridEl.innerHTML = '';

				row('Status', d.status + (d.void_reason ? ' — ' + d.void_reason : ''));
				row('Competition', d.competition_title);
				row('Competition URL', d.competition_url || '—');
				row('Tickets sold', d.tickets_sold);
				row('Max tickets (draw range)', d.max_tickets);
				row('Number of winners', d.num_winners);
				row('Client seed (timestamp, ms)', d.client_seed, true);
				row('Round ID (UUID)', d.round_uuid, true);
				row('Static salt', d.static_salt, true);
				row('Drand chain hash', d.chain_hash, true);
				row('Committed drand round', Number(d.target_round).toLocaleString());
				row('Round time (UTC)', d.round_time_utc);
				row('Server seed (drand randomness)', d.server_seed || 'not yet emitted', true);
				row('Beacon signature', d.drand_signature || '—', true);
				row('Combined hash (HMAC-SHA256)', d.combined_hash || '—', true);
				row('Record hash (ledger chain)', d.record_hash || '—', true);
				row('Previous record hash', d.prev_hash || '(first record)', true);
				row('Committed at (UTC)', d.created_at_utc);
				row('Completed at (UTC)', d.completed_at_utc || '—');
				row('Algorithm', d.algorithm);

				setText($('#trng-v-plural'), (d.num_winners > 1) ? 's' : '');
				renderWinners($('#trng-v-winners'), d.winners || []);

				var links = $('#trng-v-beacon-links');
				if (links) links.innerHTML = beaconLinks(d.target_round);

				show(resultEl);

				if (d.status === 'pending') {
					setText(statusEl, 'This draw is committed to round ' + Number(d.target_round).toLocaleString() + ' and has not been resolved yet. Reload after ' + d.round_time_utc + ' UTC.');
				} else {
					setText(statusEl, 'Record loaded. Follow the steps below to verify it independently.');
				}
			}).catch(function () {
				setText(statusEl, 'Network error while looking up the key.');
				statusEl.classList.add('trng-status-error');
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			load(form.querySelector('[name="key"]').value.trim());
		});

		var recomputeBtn = $('#trng-v-recompute');
		if (recomputeBtn) {
			recomputeBtn.addEventListener('click', function () {
				if (!current || !current.server_seed) {
					setText($('#trng-v-recompute-out'), 'This draw has no beacon yet (still pending).');
					return;
				}
				if (!window.crypto || !window.crypto.subtle) {
					setText($('#trng-v-recompute-out'), 'Your browser does not support the Web Crypto API. Use the Manual Verification scripts instead.');
					return;
				}
				recompute(current).then(function (res) {
					var out = $('#trng-v-recompute-out');
					out.innerHTML = '';
					var verdict = document.createElement('div');
					verdict.className = res.ok ? 'ok' : 'fail';
					verdict.textContent = res.ok
						? '✓ VERIFIED — your browser reproduced the stored result exactly.'
						: '✗ MISMATCH — the recomputed result differs from the stored record.';
					var pre = document.createElement('div');
					pre.textContent = res.log;
					out.appendChild(verdict);
					out.appendChild(pre);
				});
			});
		}

		// Auto-load when ?key= present.
		var prefill = form.querySelector('[name="key"]').value.trim();
		if (prefill) load(prefill);
	}

	document.addEventListener('DOMContentLoaded', function () {
		startLiveRound();
		startServerClock();
		handleGenerator();
		handleVerify();
	});
})();
