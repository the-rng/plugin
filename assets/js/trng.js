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

	function utcDate(mysql) {
		return new Date(String(mysql).replace(' ', 'T') + 'Z');
	}

	// "2026-07-16 00:52:08" (UTC) -> "2026-07-16 01:52:08 BST" in the display timezone.
	function fmtLocal(mysqlUtc) {
		try {
			var parts = new Intl.DateTimeFormat('en-GB', {
				timeZone: cfg.display_timezone || 'Europe/London',
				year: 'numeric', month: '2-digit', day: '2-digit',
				hour: '2-digit', minute: '2-digit', second: '2-digit',
				hour12: false, timeZoneName: 'short'
			}).formatToParts(utcDate(mysqlUtc));
			var m = {};
			parts.forEach(function (p) { m[p.type] = p.value; });
			return m.year + '-' + m.month + '-' + m.day + ' ' + m.hour + ':' + m.minute + ':' + m.second + (m.timeZoneName ? ' ' + m.timeZoneName : '');
		} catch (e) {
			return mysqlUtc + ' UTC';
		}
	}

	function fmtBoth(mysqlUtc) {
		if (!mysqlUtc) return '\u2014';
		return fmtLocal(mysqlUtc) + '  (' + mysqlUtc + ' UTC)';
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
				setText($('#trng-r-completed'), fmtBoth(d.completed_at_utc));

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
	   VERIFY PAGE — draw details layout with automatic verification
	------------------------------------------------------------------ */

	// Deterministic walk — exact mirror of the server engine (and the
	// published verification scripts). Returns structured steps.
	function runDraw(d) {
		var data = d.client_seed + ':' + d.round_uuid + ':' + d.static_salt + ':' + d.tickets_sold + ':' + d.max_tickets;
		var max = Number(d.max_tickets);
		var need = Number(d.num_winners) || 1;
		var limit = 4294967295 - (4294967295 % max);
		var winners = [];
		var steps = [];
		var attempt = 0;

		function walk(bytes, block) {
			for (var i = 0; i + 4 <= bytes.length && winners.length < need; i += 4) {
				var value = new DataView(bytes.buffer, i, 4).getUint32(0, true);
				var step = { attempt: attempt++, block: block, value: value, ticket: null, outcome: 'rejected', winnerIndex: 0 };
				if (value < limit) {
					var ticket = (value % max) + 1;
					step.ticket = ticket;
					if (winners.indexOf(ticket) !== -1) {
						step.outcome = 'duplicate';
					} else {
						winners.push(ticket);
						step.outcome = 'winner';
						step.winnerIndex = winners.length;
					}
				}
				steps.push(step);
			}
		}

		return hmacSha256(data, d.server_seed).then(function (bytes) {
			var combined = bytesToHex(bytes);
			walk(bytes, 0);

			function extend(n) {
				if (winners.length >= need) return Promise.resolve();
				return hmacSha256(data + ':extend:' + n, d.server_seed).then(function (b2) {
					walk(b2, n);
					return extend(n + 1);
				});
			}

			return extend(1).then(function () {
				return { data: data, combined: combined, winners: winners, steps: steps, limit: limit };
			});
		});
	}

	function chip(el, text, cls) {
		if (!el) return;
		el.textContent = text;
		el.className = 'trng-chip ' + (cls || '');
	}

	function fieldRow(label, value, note) {
		var esc = document.createElement('div');
		esc.textContent = value == null ? '' : String(value);
		var v = esc.innerHTML;
		return '<div class="trng-field"><label>' + label + '</label>' +
			'<div class="trng-field-row"><input type="text" class="trng-mono" readonly value="' + v.replace(/"/g, '&quot;') + '">' +
			'<button type="button" class="trng-copy" data-copy="' + v.replace(/"/g, '&quot;') + '" title="Copy">&#10697;</button></div>' +
			(note ? '<p class="trng-muted trng-small" style="margin:4px 0 0;">' + note + '</p>' : '') +
			'</div>';
	}

	function handleVerify() {
		var form = $('#trng-verify-form');
		if (!form) return;

		var statusEl = $('#trng-verify-status');
		var dd = $('#trng-dd');
		var current = null;

		function verdict(html, cls) {
			var el = $('#trng-dd-verdict');
			if (el) { el.innerHTML = html; el.className = 'trng-dd-verdict ' + (cls || ''); }
		}

		function renderWinnersRows(winners, verified) {
			var box = $('#trng-dd-winners');
			if (!box) return;
			box.innerHTML = '';
			(winners || []).forEach(function (t, i) {
				var row = document.createElement('div');
				row.className = 'trng-dd-winrow';
				row.innerHTML = '<span class="trng-dd-windex">DRAW #' + (i + 1) + '</span>' +
					'<span class="trng-dd-winnum">#' + t + '</span>' +
					'<span class="trng-dd-wincheck">' + (verified ? '&#10003;' : '&hellip;') + '</span>';
				box.appendChild(row);
			});
		}

		function autoVerify(d) {
			if (!d.server_seed) {
				verdict('&#9203; <strong>Pending</strong><br>Committed to round ' + Number(d.target_round).toLocaleString() + '. The result resolves after ' + fmtLocal(d.round_time_utc) + '.', 'trng-dd-verdict-pending');
				return;
			}
			if (!window.crypto || !window.crypto.subtle) {
				verdict('Your browser does not support the Web Crypto API. Use the Manual Verification scripts instead.', 'trng-dd-verdict-pending');
				return;
			}
			verdict('Verifying in your browser&hellip;', '');

			runDraw(d).then(function (res) {
				// Entry-list draws (v2.7+): the engine walk yields a 1-based
				// index into the published ticket_numbers list — map it before
				// comparing against the stored literal winning tickets.
				var list = (d.ticket_numbers && d.ticket_numbers.length) ? d.ticket_numbers : null;
				var recomputed = list ? res.winners.map(function (ix) { return list[ix - 1]; }) : res.winners;
				var stored = (d.winners || []).join(',');
				var mine = recomputed.join(',');
				var hashOk = res.combined === d.combined_hash;
				var ok = hashOk && stored === mine;

				// Steps table.
				var tbody = document.querySelector('#trng-dd-steps tbody');
				if (tbody) {
					tbody.innerHTML = '';
					res.steps.forEach(function (st) {
						var tr = document.createElement('tr');
						var outcome = 'Rejected (&ge; limit)';
						var oc = 'trng-chip';
						if ('winner' === st.outcome) { outcome = 'Winner #' + st.winnerIndex + (list ? ' &rarr; ticket ' + list[st.ticket - 1] : ''); oc = 'trng-chip trng-chip-green'; }
						if ('duplicate' === st.outcome) { outcome = 'Duplicate &mdash; skipped'; }
						tr.innerHTML = '<td>' + st.attempt + (st.block ? ' <span class="trng-muted trng-small">(ext ' + st.block + ')</span>' : '') + '</td>' +
							'<td class="trng-mono">' + st.value + '</td>' +
							'<td>' + (st.ticket ? '<strong>' + st.ticket + '</strong>' : '&mdash;') + '</td>' +
							'<td><span class="' + oc + '">' + outcome + '</span></td>';
						tbody.appendChild(tr);
					});
				}

				renderWinnersRows(d.winners, ok);
				var vb = $('#trng-dd-verifiedby');
				if (vb && ok) show(vb);

				verdict(
					ok
						? '&#10003; <strong>Verified</strong><br>Your browser reproduced the winning number' + (d.winners.length > 1 ? 's' : '') + ' exactly from the beacon randomness and the draw data. Nothing to trust &mdash; you just checked it.'
						: '&#10007; <strong>Mismatch</strong><br>The recomputed result differs from the stored record. Combined hash ' + (hashOk ? 'matches' : 'does NOT match') + '.',
					ok ? 'trng-dd-verdict-ok' : 'trng-dd-verdict-bad'
				);

				var log = $('#trng-v-recompute-out');
				if (log) {
					log.textContent = 'data = "' + res.data + '"\nkey  = ' + d.server_seed + '\nHMAC-SHA256 = ' + res.combined + '\nlimit = ' + res.limit + (list ? '\nraw walk indexes:   ' + res.winners.join(',') + '\nentry-list mapping: winning ticket = ticket_numbers[index - 1]' : '') + '\nrecomputed winners: ' + mine + '\nstored winners:     ' + stored;
				}
			});
		}

		function load(key) {
			hide(dd);
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
				setText(statusEl, '');

				// Header.
				setText($('#trng-dd-roundid'), d.round_uuid);
				var statusMap = { complete: ['Revealed', 'trng-chip-green'], pending: ['Pending', ''], void: ['Voided', 'trng-chip-red'] };
				var sm = statusMap[d.status] || [d.status, ''];
				chip($('#trng-dd-status-chip'), sm[0], sm[1]);
				chip($('#trng-dd-status2'), sm[0] + (d.void_reason ? ' — ' + d.void_reason : ''), sm[1]);

				// Competition card.
				setText($('#trng-dd-title'), d.competition_title);
				var urlEl = $('#trng-dd-url');
				if (urlEl) {
					if (d.competition_url) { urlEl.href = d.competition_url; setText(urlEl, d.competition_url); show($('#trng-dd-urlwrap')); }
					else { hide($('#trng-dd-urlwrap')); }
				}
				setText($('#trng-dd-sold'), Number(d.tickets_sold).toLocaleString());
				setText($('#trng-dd-max'), Number(d.max_tickets).toLocaleString());

				// Winners.
				setText($('#trng-dd-plural'), (d.num_winners > 1) ? 's' : '');
				chip($('#trng-dd-nwin'), d.num_winners + ' winner' + (d.num_winners > 1 ? 's' : ''), 'trng-chip-green');
				renderWinnersRows(d.winners || [], false);
				hide($('#trng-dd-verifiedby'));

				// Verifiably fair data fields.
				var clientSeedNote = 'Draw Timestamp · ' + fmtLocal(new Date(Number(d.client_seed)).toISOString().slice(0, 19).replace('T', ' '));
				var fields = $('#trng-dd-fields');
				if (fields) {
					fields.innerHTML =
						fieldRow('Server Seed (Revealed)', d.server_seed || 'not yet revealed') +
						fieldRow('Drand Signature (Public)', d.drand_signature || '—') +
						fieldRow('Client Seed', d.client_seed, clientSeedNote) +
						fieldRow('Round ID', d.round_uuid) +
						fieldRow('Static Salt', d.static_salt) +
						'<div class="trng-grid-2">' + fieldRow('Tickets Sold', d.tickets_sold) + fieldRow('Max Tickets', d.max_tickets) + '</div>';
				}

				// All together.
				var pre = $('#trng-dd-prehash');
				if (pre) pre.value = d.client_seed + ':' + d.round_uuid + ':' + d.static_salt + ':' + d.tickets_sold + ':' + d.max_tickets;
				var hashBox = $('#trng-dd-hash');
				if (hashBox) hashBox.innerHTML = fieldRow('Hash (HMAC-SHA256)', d.combined_hash || '—');

				// Sidebar.
				setText($('#trng-dd-round'), Number(d.target_round).toLocaleString());
				var links = $('#trng-v-beacon-links');
				if (links) links.innerHTML = beaconLinks(d.target_round);
				setText($('#trng-dd-key'), d.draw_key);
				setText($('#trng-dd-derived'), (d.winners || []).length || d.num_winners);
				setText($('#trng-dd-pool'), Number(d.max_tickets).toLocaleString());
				setText($('#trng-dd-rechash'), d.record_hash || '—');
				setText($('#trng-dd-t-committed'), fmtLocal(d.created_at_utc));
				setText($('#trng-dd-t-round'), fmtLocal(d.round_time_utc));
				setText($('#trng-dd-t-revealed'), d.completed_at_utc ? fmtLocal(d.completed_at_utc) : '—');

				show(dd);
				autoVerify(d);
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
				if (current) autoVerify(current);
			});
		}

		// Copy buttons (delegated).
		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.trng-copy') : null;
			if (!btn || !navigator.clipboard) return;
			navigator.clipboard.writeText(btn.getAttribute('data-copy') || '').then(function () {
				var old = btn.innerHTML;
				btn.innerHTML = '&#10003;';
				setTimeout(function () { btn.innerHTML = old; }, 1200);
			});
		});

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
