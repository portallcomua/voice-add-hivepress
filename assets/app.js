/* Voice ADD — диктовка з маяками, фото, фон, CSV, друк інструкцій */
(function () {
	'use strict';
	var C = window.VA_CFG;
	if (!C) { return; }
	var S = C.S;

	function $(id) { return document.getElementById(id); }
	function norm(s) { return String(s).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim(); }

	var ta = $('va_ta'), box = $('va_target'), msg = $('va_msg');
	var photos = [], listening = false, rec = null;
	var activeLine = -1, activeDef = null, fresh = false;

	/* ---------- Маяки ---------- */
	var defs = C.fields.slice();
	var aliasList = [], keyMap = {}, defByKey = {};
	defs.forEach(function (d) {
		defByKey[d.key] = d;
		d.aliases.forEach(function (a) {
			aliasList.push({ tokens: a.split(' '), def: d });
			if (!keyMap[a]) { keyMap[a] = d.key; }
		});
	});
	aliasList.sort(function (a, b) { return b.tokens.length - a.tokens.length || b.tokens.join('').length - a.tokens.join('').length; });
	var attrDef = null;
	defs.forEach(function (d) { if (d.kind === 'attr') { attrDef = d; } });
	// Атрибути магазину / HivePress також розпізнаються за назвою («Колір червоний»)
	var attrNameList = (C.attrNames || []).map(function (n) { return { name: n, tokens: norm(n).split(' ') }; })
		.filter(function (a) { return a.tokens[0]; })
		.sort(function (a, b) { return b.tokens.length - a.tokens.length; });
	var VALUE_WORDS = ['значення', 'значение', 'value', 'values'];
	var collecting = false, pendingName = [];

	function lineHead(line) { var p = line.indexOf(':'); return p < 0 ? null : norm(line.slice(0, p)); }
	function lineKey(line) { var h = lineHead(line); return h === null ? null : (keyMap[h] || null); }

	function matchBeacon(N, i) {
		for (var a = 0; a < aliasList.length; a++) {
			var t = aliasList[a].tokens, ok = true;
			if (i + t.length > N.length) { continue; }
			for (var k = 0; k < t.length; k++) {
				if (N[i + k] !== t[k]) { ok = false; break; }
			}
			if (ok) { return { def: aliasList[a].def, len: t.length }; }
		}
		for (var n = 0; n < attrNameList.length; n++) {
			var tk = attrNameList[n].tokens, ok2 = i + tk.length <= N.length;
			for (var j = 0; ok2 && j < tk.length; j++) {
				if (N[i + j] !== tk[j]) { ok2 = false; }
			}
			if (ok2) { return { attrName: attrNameList[n].name, len: tk.length }; }
		}
		return null;
	}

	/** Усередині рядка «Варіація» слова ціна/кількість/артикул — це текст варіації, а не маяки. */
	function inVariation() { return !!(activeDef && activeDef.key === 'variation'); }
	function filterMatch(m) {
		if (m && m.def && inVariation() && ['price', 'stock', 'sku'].indexOf(m.def.key) > -1) { return null; }
		return m;
	}

	/* ---------- Робота з рядками textarea ---------- */
	function setCaretEnd(idx) {
		var lines = ta.value.split('\n'), off = 0;
		for (var i = 0; i < idx && i < lines.length; i++) { off += lines[i].length + 1; }
		off += (lines[idx] || '').length;
		try {
			ta.focus({ preventScroll: true });
			ta.setSelectionRange(off, off);
		} catch (e) { /* ok */ }
		var lh = parseFloat(window.getComputedStyle(ta).lineHeight) || 20;
		ta.scrollTop = Math.max(0, idx * lh - ta.clientHeight / 2);
	}

	function showTarget(label) {
		box.hidden = false;
		box.textContent = '🎯 ' + S.target + ': ' + label;
	}

	/** Курсор одразу стає після «Маяк: » у відповідному рядку. */
	function activate(def) {
		var lines = ta.value.split('\n'), idx = -1;
		if (def.kind !== 'repeat') {
			for (var i = 0; i < lines.length; i++) {
				if (lineKey(lines[i]) === def.key) { idx = i; break; }
			}
		}
		if (idx < 0) {
			while (lines.length && lines[lines.length - 1].trim() === '') { lines.pop(); }
			lines.push(def.label + ': ');
			idx = lines.length - 1;
			fresh = false;
		} else {
			fresh = lines[idx].slice(lines[idx].indexOf(':') + 1).trim() !== '';
		}
		ta.value = lines.join('\n');
		activeLine = idx;
		activeDef = def;
		setCaretEnd(idx);
		showTarget(def.label);
	}

	/* Атрибути: «Атрибут колір значення червоний значення жовтий» */
	function insertAttrLine(label) {
		var lines = ta.value.split('\n'), key = norm(label), ap = norm(attrDef.label), last = -1, i, h;
		for (i = 0; i < lines.length; i++) {
			h = lineHead(lines[i]);
			if (h === key) { return i; }
			if (h !== null && (h === ap || h.indexOf(ap + ' ') === 0)) { last = i; }
		}
		var at = last >= 0 ? last + 1 : lines.length;
		lines.splice(at, 0, label + ': ');
		ta.value = lines.join('\n');
		return at;
	}

	function finishName() {
		var raw = pendingName.join(' ').replace(/[:,.]+$/, '').trim();
		pendingName = []; collecting = false;
		if (!raw) { return false; }
		var label = attrDef.label + ' ' + raw.charAt(0).toUpperCase() + raw.slice(1);
		var idx = insertAttrLine(label);
		activeLine = idx;
		activeDef = { kind: 'list', label: label, key: 'attr-line' };
		fresh = false;
		setCaretEnd(idx);
		showTarget(label);
		return true;
	}

	function enterMatch(m) {
		if (m.attrName) {
			pendingName = [m.attrName]; collecting = true;
			finishName();
		} else {
			enterBeacon(m.def);
		}
	}

	function enterBeacon(def) {
		collecting = false; pendingName = [];
		activate(def);
		if (def.kind === 'attr') {
			collecting = true;
			box.textContent = '🎯 ' + def.label + ' → … (' + (C.lang.indexOf('en') === 0 ? 'name, then “value”' : 'назва, потім «значення»') + ')';
		}
	}

	function writeToActive(words) {
		if (!words.length) { return; }
		if (activeLine < 0 || !activeDef) { activate(defs[0]); }
		var lines = ta.value.split('\n');
		if (activeLine >= lines.length) { activeLine = lines.length - 1; }
		var line = lines[activeLine], p = line.indexOf(':');
		if (p < 0) { return; }
		var head = line.slice(0, p + 1), val = line.slice(p + 1).trim();
		if (fresh) { val = ''; fresh = false; }
		var text;
		if (activeDef.kind === 'list') {
			var items = [], cur = [];
			words.forEach(function (w) {
				if (w === ',') { if (cur.length) { items.push(cur.join(' ')); cur = []; } }
				else { cur.push(w); }
			});
			if (cur.length) { items.push(cur.join(' ')); }
			text = items.join(', ');
			val = val ? val.replace(/[,\s]+$/, '') + ', ' + text : text;
		} else {
			text = words.join(' ').replace(/\s+,/g, ',');
			val = val ? val + ' ' + text : text;
		}
		lines[activeLine] = head + ' ' + val;
		ta.value = lines.join('\n');
		setCaretEnd(activeLine);
	}

	/* Android Chrome у режимі continuous повторює фінальні результати («2», «2 кг», «2 кг»).
	   Відкидаємо вже оброблений початок фрази. */
	var lastFinal = { tokens: [], t: 0 };
	function dedupe(text) {
		var toks = text.split(/\s+/).filter(Boolean), nt = toks.map(norm), prev = lastFinal.tokens, now = Date.now();
		var out = toks;
		if (prev.length && now - lastFinal.t < 6000 && nt.length >= prev.length) {
			var same = true;
			for (var k = 0; k < prev.length; k++) { if (nt[k] !== prev[k]) { same = false; break; } }
			if (same) { out = toks.slice(prev.length); }
		}
		lastFinal = { tokens: nt, t: now };
		return out.join(' ');
	}

	/** skipKey — маяк, який уже активовано за проміжним результатом (щоб не робити двічі). */
	function handleFinal(text, skipKey) {
		var W = text.split(/\s+/).filter(Boolean);
		var N = W.map(function (w) { return norm(w); });
		var buf = [], i = 0;
		function flush() { if (buf.length) { writeToActive(buf); buf = []; } }
		while (i < W.length) {
			var m = filterMatch(matchBeacon(N, i));
			if (m) {
				flush();
				var skip = i === 0 && skipKey && ((m.def && m.def.key === skipKey) || (m.attrName && skipKey === 'attr:' + m.attrName));
				if (!skip) { enterMatch(m); }
				i += m.len;
				continue;
			}
			if (VALUE_WORDS.indexOf(N[i]) > -1 && (collecting || (activeDef && activeDef.key === 'attr-line'))) {
				flush();
				if (collecting) { finishName(); }
				i++;
				continue;
			}
			if (collecting) { pendingName.push(W[i].replace(/[:,]+$/, '')); i++; continue; }
			buf.push(N[i] === 'кома' || N[i] === 'comma' ? ',' : W[i]);
			i++;
		}
		flush();
	}

	/* ---------- Мікрофон ---------- */
	var mic = $('va_mic');
	var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
	var pre = { idx: -1, key: null };

	function micUI(on) {
		mic.textContent = on ? S.micStop : S.mic;
		mic.classList.toggle('is-on', on);
		if (on) { ta.setAttribute('inputmode', 'none'); } else { ta.removeAttribute('inputmode'); }
	}

	/** Маяк на початку фрази активується ще до завершення розпізнавання — курсор з'являється миттєво. */
	function preDetect(i, t) {
		if (pre.idx === i) { return; }
		var N = t.split(/\s+/).filter(Boolean).map(function (w) { return norm(w); });
		var m = filterMatch(matchBeacon(N, 0));
		if (m) { pre.idx = i; pre.key = m.def ? m.def.key : 'attr:' + m.attrName; enterMatch(m); }
	}

	if (!SR) {
		mic.addEventListener('click', function () { alert(S.noSpeech); });
	} else {
		rec = new SR();
		rec.lang = C.lang;
		rec.continuous = true;
		rec.interimResults = true;
		rec.onresult = function (e) {
			var interim = '';
			for (var i = e.resultIndex; i < e.results.length; i++) {
				var r = e.results[i], t = r[0].transcript.trim();
				if (r.isFinal) {
					var clean = dedupe(t);
					if (clean) { handleFinal(clean, pre.idx === i && clean === t ? pre.key : null); }
					if (pre.idx === i) { pre.idx = -1; pre.key = null; }
				} else {
					preDetect(i, t);
					interim += ' ' + t;
				}
			}
			interim = interim.trim();
			if (interim && activeDef) { box.textContent = '🎯 ' + S.target + ': ' + activeDef.label + ' — ' + interim; }
			else if (activeDef && !collecting) { showTarget(activeDef.label); }
		};
		rec.onend = function () {
			pre.idx = -1; pre.key = null; lastFinal = { tokens: [], t: 0 };
			if (listening) { setTimeout(function () { try { rec.start(); } catch (x) { /* вже запущено */ } }, 150); }
		};
		rec.onerror = function (e) {
			if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
				listening = false; micUI(false); alert(S.noSpeech);
			}
		};
		mic.addEventListener('click', function () {
			if (!listening) {
				listening = true; micUI(true);
				box.hidden = false; box.textContent = '🎙️ ' + S.listening;
				try { rec.start(); } catch (x) { /* ok */ }
			} else {
				listening = false; micUI(false);
				try { rec.stop(); } catch (x2) { /* ok */ }
			}
		});
	}
	// Ручний клік у рядок = нова ціль диктовки
	ta.addEventListener('click', syncFromCaret);
	ta.addEventListener('keyup', function (e) { if (e.key && e.key.indexOf('Arrow') === 0) { syncFromCaret(); } });
	function syncFromCaret() {
		var idx = ta.value.slice(0, ta.selectionStart).split('\n').length - 1;
		var line = ta.value.split('\n')[idx] || '';
		var k = lineKey(line), h = lineHead(line);
		collecting = false; pendingName = [];
		if (k && defByKey[k] && defByKey[k].kind !== 'attr') {
			activeLine = idx; activeDef = defByKey[k]; fresh = false; showTarget(activeDef.label);
		} else if (attrDef && h && h.indexOf(norm(attrDef.label) + ' ') === 0) {
			activeLine = idx; activeDef = { kind: 'list', label: line.slice(0, line.indexOf(':')), key: 'attr-line' }; fresh = false; showTarget(activeDef.label);
		}
	}

	/* ---------- Фото ---------- */
	var cam = $('va_cam'), gal = $('va_gal'), bgWrap = $('va_bg_wrap');

	if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
		navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
			.then(function (s) { cam.srcObject = s; })
			.catch(function () { cam.style.display = 'none'; });
	} else { cam.style.display = 'none'; }

	function shrink(source, w, h, cb) {
		var max = 1600, k = Math.min(1, max / Math.max(w, h));
		var c = document.createElement('canvas');
		c.width = Math.round(w * k); c.height = Math.round(h * k);
		c.getContext('2d').drawImage(source, 0, 0, c.width, c.height);
		cb(c.toDataURL('image/jpeg', 0.85));
	}

	function addPhoto(data) {
		var p = { data: data, processed: 'original' };
		var img = document.createElement('img');
		img.src = data; img.title = S.remove; img.className = 'va-thumb';
		img.addEventListener('click', function () {
			photos.splice(photos.indexOf(p), 1); img.remove();
			if (!photos.length && bgWrap) { bgWrap.style.display = 'none'; }
		});
		p.el = img;
		photos.push(p);
		gal.appendChild(img);
		if (bgWrap) { bgWrap.style.display = 'block'; }
	}

	$('va_photo').addEventListener('click', function () {
		if (cam.videoWidth) { shrink(cam, cam.videoWidth, cam.videoHeight, addPhoto); }
		else { $('va_files').click(); }
	});
	$('va_file').addEventListener('click', function () { $('va_files').click(); });
	$('va_files').addEventListener('change', function () {
		Array.prototype.forEach.call(this.files, function (f) {
			var url = URL.createObjectURL(f), im = new Image();
			im.onload = function () { shrink(im, im.naturalWidth, im.naturalHeight, addPhoto); URL.revokeObjectURL(url); };
			im.src = url;
		});
		this.value = '';
	});
	$('va_clr').addEventListener('click', function () {
		photos = []; gal.innerHTML = '';
		if (bgWrap) { bgWrap.style.display = 'none'; }
	});

	function getBg() {
		var r = document.querySelectorAll('input[name="va_bg"]');
		for (var i = 0; i < r.length; i++) { if (r[i].checked) { return r[i].value; } }
		return 'original';
	}

	function post(fd) {
		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}
	function say(el, html, cls) { el.className = 'va-msg ' + (cls || ''); el.innerHTML = html; }
	function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

	/* ---------- Збереження ---------- */
	var btnSave = $('va_save');
	var saveLabel = btnSave.textContent;

	btnSave.addEventListener('click', async function () {
		btnSave.disabled = true; btnSave.textContent = S.saving;
		var warnBg = false;
		try {
			var bg = getBg();
			if (C.hasRbg && bg !== 'original') {
				for (var i = 0; i < photos.length; i++) {
					if (photos[i].processed === bg) { continue; }
					say(msg, '⏳ ' + S.bgWork + ' ' + (i + 1) + '/' + photos.length + '…');
					var fd0 = new FormData();
					fd0.append('action', C.actions.bg); fd0.append('nonce', C.nonce);
					fd0.append('image', photos[i].data); fd0.append('bg', bg);
					try {
						var r0 = await post(fd0);
						if (r0.success && r0.data && r0.data.image) {
							photos[i].data = r0.data.image; photos[i].processed = bg; photos[i].el.src = r0.data.image;
						} else { warnBg = true; }
					} catch (e0) { warnBg = true; }
				}
			}
			var fd = new FormData();
			fd.append('action', C.actions.save); fd.append('nonce', C.nonce);
			fd.append('text', ta.value);
			fd.append('images', JSON.stringify(photos.map(function (p) { return p.data; })));
			var res = await post(fd);
			if (res.success) {
				var d = res.data, html = '✅ ' + S.created + ' <a href="' + esc(d.edit || '#') + '" target="_blank" rel="noopener">' + esc(d.title || '') + ' (ID ' + d.id + ')</a>';
				if (d.variations) { html += ' · ' + d.variations + ' var.'; }
				if (warnBg) { html += '<br>⚠️ ' + S.bgFail; }
				(d.warnings || []).forEach(function (w) { html += '<br>⚠️ ' + esc(w); });
				say(msg, html, 'is-ok');
				photos = []; gal.innerHTML = ''; if (bgWrap) { bgWrap.style.display = 'none'; }
				ta.value = C.tpl; activeLine = -1; activeDef = null; box.hidden = true;
				if (typeof d.left === 'number' && d.left >= 0 && $('va_left')) { $('va_left').textContent = d.left; }
			} else {
				say(msg, '❌ ' + esc(String(res.data || 'Error')), 'is-err');
			}
		} catch (e) {
			say(msg, '❌ ' + S.netErr, 'is-err');
		}
		btnSave.disabled = false; btnSave.textContent = saveLabel;
	});

	/* ---------- CSV ---------- */
	var imp = $('va_import');
	if (imp) {
		imp.addEventListener('click', async function () {
			var f = $('va_csv_file').files[0], out = $('va_csv_msg');
			if (!f) { say(out, S.pickCsv, 'is-err'); return; }
			imp.disabled = true; say(out, '⏳ ' + S.importing);
			var fd = new FormData();
			fd.append('action', C.actions.import); fd.append('nonce', C.nonce); fd.append('file', f);
			try {
				var r = await post(fd);
				if (r.success) {
					var d = r.data, h = '✅ ' + S.importDone + ': ' + d.created + ' ' + S.created_n + ', ' + d.failed + ' ' + S.failed_n;
					if (d.stopped) { h += '<br>⚠️ ' + S.limit; }
					(d.errors || []).forEach(function (e) { h += '<br>• ' + esc(e); });
					say(out, h, d.failed ? 'is-err' : 'is-ok');
					if (typeof d.left === 'number' && d.left >= 0 && $('va_left')) { $('va_left').textContent = d.left; }
				} else { say(out, '❌ ' + esc(String(r.data || 'Error')), 'is-err'); }
			} catch (e) { say(out, '❌ ' + S.netErr, 'is-err'); }
			imp.disabled = false;
		});
	}

	/* ---------- Оплата PRO (попап з доменом) ---------- */
	var pay = $('va_pay');
	if (pay) {
		var P = { url: '', state: '', copied: false, busy: false };
		var pBody = pay.querySelector('[data-va-pbody]'), pMsg = pay.querySelector('[data-va-pmsg]');
		var pCopy = $('va_pay_copy'), pGo = $('va_pay_go');

		var payMsg = function (t, info) { pMsg.textContent = t || ''; pMsg.hidden = !t; pMsg.className = 'va-modal__msg' + (info ? ' is-info' : ''); };
		var closePay = function () { if (P.busy) { return; } pay.hidden = true; document.body.classList.remove('va-lock'); };
		var fbCopy = function (text) {
			try {
				var t = document.createElement('textarea');
				t.value = text; t.setAttribute('readonly', ''); t.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
				document.body.appendChild(t); t.select(); t.setSelectionRange(0, text.length);
				var ok = document.execCommand('copy'); document.body.removeChild(t); return ok;
			} catch (e) { return false; }
		};
		var openPay = function () {
			pay.hidden = false; document.body.classList.add('va-lock');
			pBody.hidden = true; payMsg('…', true);
			P.copied = false; P.busy = false;
			pCopy.textContent = S.copyDomain; pCopy.classList.remove('is-copied');
			pGo.disabled = true; pGo.textContent = S.payGo;
			var fd = new FormData();
			fd.append('action', C.actions.payInfo); fd.append('nonce', C.nonce);
			post(fd).then(function (r) {
				if (!r.success) { payMsg(String(r.data || 'Error')); return; }
				var d = r.data; P.url = d.url; P.state = d.state;
				pay.querySelector('[data-va-pname]').textContent = d.name;
				pay.querySelector('[data-va-pprice]').textContent = d.price || '';
				pay.querySelector('[data-va-domain]').textContent = d.domain;
				if (d.state === 'active' || d.state === 'unlimited') { payMsg(S.alreadyPro, true); return; }
				if (!d.url) { payMsg(S.noPayUrl); return; }
				payMsg(d.state === 'blocked' ? S.blockedMsg : '', true);
				pBody.hidden = false;
			}).catch(function () { payMsg(S.netErr); });
		};
		document.addEventListener('click', function (e) {
			var t = e.target.closest ? e.target.closest('[data-va-buy],[data-va-close]') : null;
			if (!t) { return; }
			e.preventDefault();
			if (t.hasAttribute('data-va-buy')) { openPay(); } else { closePay(); }
		});
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePay(); } });

		pCopy.addEventListener('click', function () {
			var dom = pay.querySelector('[data-va-domain]').textContent;
			var done = function (ok) {
				P.copied = true; pGo.disabled = false;
				if (ok) { pCopy.textContent = S.copied; pCopy.classList.add('is-copied'); }
				else {
					try { var r = document.createRange(); r.selectNodeContents(pay.querySelector('[data-va-domain]')); var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r); } catch (x) { /* ok */ }
					pCopy.textContent = S.copyManual;
				}
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(dom).then(function () { done(true); }, function () { done(fbCopy(dom)); });
			} else { done(fbCopy(dom)); }
		});

		pGo.addEventListener('click', function () {
			if (!P.copied || P.busy || !P.url) { return; }
			if (P.state === 'blocked') { window.open(P.url, '_blank'); return; }
			P.busy = true; pGo.disabled = true; pGo.textContent = S.activating;
			var w = null;
			try { w = window.open('', '_blank'); } catch (e) { w = null; }
			if (w) { try { w.opener = null; w.document.body.innerHTML = '<p style="font-family:sans-serif;padding:20px">…</p>'; } catch (e2) { /* ok */ } }
			var fd = new FormData();
			fd.append('action', C.actions.payGo); fd.append('nonce', C.nonce);
			post(fd).then(function (r) {
				if (r.success) {
					if (w) { w.location.href = r.data.url; setTimeout(function () { window.location.reload(); }, 250); }
					else { window.location.href = r.data.url; }
				} else {
					if (w) { try { w.close(); } catch (e3) { /* ok */ } }
					P.busy = false; pBody.hidden = true; payMsg(String(r.data || 'Error'));
				}
			}).catch(function () {
				if (w) { try { w.close(); } catch (e4) { /* ok */ } }
				P.busy = false; pGo.disabled = false; pGo.textContent = S.payGo; payMsg(S.netErr);
			});
		});
	}

	/* ---------- Інструкції та друк ---------- */
	var go = $('va_go_instr');
	if (go) {
		go.addEventListener('click', function () {
			var el = $('va_instr');
			el.scrollIntoView({ behavior: 'smooth', block: 'start' });
			el.classList.add('is-flash'); setTimeout(function () { el.classList.remove('is-flash'); }, 1600);
		});
	}
	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('.va-print') : null;
		if (!b) { return; }
		var sec = document.getElementById(b.getAttribute('data-target'));
		if (!sec) { return; }
		var clone = sec.cloneNode(true);
		Array.prototype.forEach.call(clone.querySelectorAll('.va-print'), function (n) { n.remove(); });
		var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(S.printTitle) + '</title><style>' +
			'body{font-family:Arial,sans-serif;padding:24px;color:#111;line-height:1.5}h3{margin-top:0}' +
			'table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:6px 8px;text-align:left;vertical-align:top}' +
			'pre{background:#f3f3f3;padding:10px;white-space:pre-wrap}code{background:#f3f3f3;padding:1px 4px}.va-muted{color:#555}</style></head><body>' +
			clone.innerHTML + '</body></html>';
		var f = document.createElement('iframe');
		f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
		document.body.appendChild(f);
		var doc = f.contentWindow.document;
		doc.open(); doc.write(html); doc.close();
		setTimeout(function () {
			f.contentWindow.focus(); f.contentWindow.print();
			setTimeout(function () { f.remove(); }, 1500);
		}, 250);
	});
})();
