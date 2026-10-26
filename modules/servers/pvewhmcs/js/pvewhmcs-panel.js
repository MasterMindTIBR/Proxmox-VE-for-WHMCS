/* Client area panel: usage charts (rrddata via modop=custom&a=vmRrd) and the root password form. */
(function () {
	'use strict';

	var REFRESH_MS = 60000;
	var COLORS = ['#802DBC', '#2E86DE'];

	function csrf(form) {
		var field = form.querySelector('input[name="token"]');
		// Some themes do not expose {$token} to module templates; the page always defines csrfToken.
		if (field && !field.value && typeof window.csrfToken === 'string') {
			field.value = window.csrfToken;
		}
	}

	function fmtBytes(value, perSecond) {
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = 0;
		while (Math.abs(value) >= 1024 && i < units.length - 1) {
			value /= 1024;
			i++;
		}
		return (value >= 100 || i === 0 ? value.toFixed(0) : value.toFixed(1)).replace('.', ',') + ' ' + units[i] + (perSecond ? '/s' : '');
	}

	function fmtTime(ts, tf) {
		var d = new Date(ts * 1000);
		var hm = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
		return tf === 'week' ? ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + ' ' + hm : hm;
	}

	function svg(tag, attrs) {
		var el = document.createElementNS('http://www.w3.org/2000/svg', tag);
		Object.keys(attrs).forEach(function (k) { el.setAttribute(k, attrs[k]); });
		return el;
	}

	// series: [{label, values}], fmt: value -> text, max: fixed top (e.g. 100 for %) or null.
	function drawChart(box, title, series, points, tf, fmt, max, nodata) {
		box.innerHTML = '';
		var head = document.createElement('div');
		head.className = 'pve-chart-title';
		head.textContent = title;
		box.appendChild(head);

		var W = 300, H = 120, PAD_L = 4, PAD_T = 14, PAD_B = 14;
		var n = points.length;
		var top = max || 0;
		series.forEach(function (s) { s.values.forEach(function (v) { if (v !== null && v > top) { top = v; } }); });
		if (!n || top <= 0 && !max) {
			var empty = document.createElement('div');
			empty.className = 'pve-chart-legend';
			empty.textContent = nodata;
			box.appendChild(empty);
			return;
		}
		top = top || 1;
		var root = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none', role: 'img', 'aria-label': title });
		[0, 0.5, 1].forEach(function (f) {
			var y = PAD_T + (H - PAD_T - PAD_B) * (1 - f);
			root.appendChild(svg('line', { x1: 0, x2: W, y1: y, y2: y, stroke: '#eee', 'stroke-width': 1, 'vector-effect': 'non-scaling-stroke' }));
		});
		var topLabel = svg('text', { x: PAD_L, y: 10, 'font-size': 9, fill: '#888' });
		topLabel.textContent = fmt(top);
		root.appendChild(topLabel);
		[[0, 'start'], [n - 1, 'end']].forEach(function (p) {
			var t = svg('text', { x: p[1] === 'start' ? PAD_L : W - 2, y: H - 2, 'font-size': 9, fill: '#888', 'text-anchor': p[1] });
			t.textContent = fmtTime(points[p[0]].t, tf);
			root.appendChild(t);
		});
		series.forEach(function (s, si) {
			var d = '';
			s.values.forEach(function (v, i) {
				if (v === null) { return; }
				var x = n > 1 ? (W * i) / (n - 1) : W / 2;
				var y = PAD_T + (H - PAD_T - PAD_B) * (1 - v / top);
				d += (d === '' || s.values[i - 1] === null ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1);
			});
			root.appendChild(svg('path', { d: d, fill: 'none', stroke: COLORS[si], 'stroke-width': 1.5, 'vector-effect': 'non-scaling-stroke' }));
		});
		box.appendChild(root);

		var legend = document.createElement('div');
		legend.className = 'pve-chart-legend';
		series.forEach(function (s, si) {
			var last = null;
			for (var i = s.values.length - 1; i >= 0; i--) { if (s.values[i] !== null) { last = s.values[i]; break; } }
			var item = document.createElement('span');
			item.innerHTML = '<b style="color:' + COLORS[si] + '">&#9632;</b> ';
			item.appendChild(document.createTextNode((s.label ? s.label + ': ' : '') + (last === null ? '-' : fmt(last))));
			legend.appendChild(item);
		});
		box.appendChild(legend);
	}

	function initUsage(root) {
		var sid = root.getAttribute('data-service');
		var lang = {};
		try { lang = JSON.parse(root.getAttribute('data-lang') || '{}'); } catch (e) { lang = {}; }
		var foot = root.querySelector('.pve-usage-foot');
		var buttons = root.querySelectorAll('[data-tf]');
		var tf = 'hour';
		var timer = null;

		function col(points, key, scale) {
			return points.map(function (p) { return p[key] === null || p[key] === undefined ? null : p[key] * (scale || 1); });
		}

		function render(data) {
			var pts = data.points || [];
			var box = function (name) { return root.querySelector('[data-chart="' + name + '"]'); };
			var pct = function (v) { return v.toFixed(1).replace('.', ',') + '%'; };
			drawChart(box('cpu'), lang.cpu || 'CPU', [{ label: '', values: col(pts, 'cpu', 100) }], pts, tf, pct, 100, lang.nodata);
			var maxmem = 0;
			pts.forEach(function (p) { if (p.maxmem > maxmem) { maxmem = p.maxmem; } });
			drawChart(box('mem'), (lang.mem || 'Memory') + (maxmem ? ' (' + fmtBytes(maxmem) + ')' : ''), [{ label: '', values: col(pts, 'mem') }], pts, tf, function (v) { return fmtBytes(v); }, maxmem || null, lang.nodata);
			var rate = function (v) { return fmtBytes(v, true); };
			drawChart(box('net'), lang.net || 'Network', [{ label: lang['in'] || 'In', values: col(pts, 'netin') }, { label: lang.out || 'Out', values: col(pts, 'netout') }], pts, tf, rate, null, lang.nodata);
			drawChart(box('disk'), lang.disk || 'Disk', [{ label: lang.read || 'Read', values: col(pts, 'diskread') }, { label: lang.write || 'Write', values: col(pts, 'diskwrite') }], pts, tf, rate, null, lang.nodata);
			var now = new Date();
			foot.textContent = (lang.updated || 'Updated at') + ' ' + ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
		}

		function load() {
			var url = 'clientarea.php?action=productdetails&id=' + encodeURIComponent(sid) + '&modop=custom&a=vmRrd&tf=' + tf;
			if (typeof window.csrfToken === 'string') {
				url += '&token=' + encodeURIComponent(window.csrfToken);
			}
			fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (!data || !data.ok) { throw new Error('rrd'); }
					render(data);
				})
				.catch(function () { foot.textContent = lang.error || 'Could not load the usage data.'; });
		}

		function schedule() {
			clearInterval(timer);
			timer = setInterval(function () {
				if (document.visibilityState === 'visible') { load(); }
			}, REFRESH_MS);
		}

		Array.prototype.forEach.call(buttons, function (b) {
			b.addEventListener('click', function () {
				Array.prototype.forEach.call(buttons, function (x) { x.classList.toggle('active', x === b); });
				tf = b.getAttribute('data-tf');
				load();
				schedule();
			});
		});
		load();
		schedule();
	}

	function initRootPassword(form) {
		var error = form.querySelector('.pve-rootpw-error');
		var open = form.parentNode.querySelector('.pve-rootpw-open');
		var cancel = form.querySelector('.pve-rootpw-cancel');
		function toggle(show) {
			form.hidden = !show;
			open.hidden = show;
			if (show) {
				form.elements.pvewhmcs_rootpw.focus();
			} else {
				form.reset();
				error.textContent = '';
			}
		}
		open.addEventListener('click', function () { toggle(true); });
		cancel.addEventListener('click', function () { toggle(false); });
		form.addEventListener('submit', function (ev) {
			csrf(form);
			var a = form.elements.pvewhmcs_rootpw.value;
			var b = form.elements.pvewhmcs_rootpw2.value;
			var msg = '';
			if (a.length < 12 || !/\p{L}/u.test(a) || !/\d/.test(a)) {
				msg = form.getAttribute('data-rule');
			} else if (a !== b) {
				msg = form.getAttribute('data-mismatch');
			}
			error.textContent = msg;
			var confirmText = form.getAttribute('data-confirm');
			if (msg || (confirmText && !window.confirm(confirmText))) {
				ev.preventDefault();
			}
		});
	}

	function init() {
		var usage = document.getElementById('pvewhmcs-usage');
		if (usage) { initUsage(usage); }
		var form = document.querySelector('.pve-rootpw form');
		if (form) { csrf(form); initRootPassword(form); }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
