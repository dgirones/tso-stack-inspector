(function () {
	'use strict';

	var cfg = window.tsosiAdminConfig || {};
	var i18n = cfg.i18n || {};

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	function qsa(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		}).then(function (response) {
			return response.json();
		});
	}

	function showPanel() {
		var panel = qs('#tsosi-scan-panel');
		if (panel) {
			panel.hidden = false;
		}
	}

	function setProgress(text) {
		var el = qs('#tsosi-scan-progress');
		if (el) {
			el.textContent = text;
		}
	}

	function renderResults(rows) {
		var tbody = qs('#tsosi-results-body');
		if (!tbody) {
			return;
		}
		tbody.innerHTML = '';
		if (!rows || !rows.length) {
			var tr = document.createElement('tr');
			var td = document.createElement('td');
			td.colSpan = 5;
			td.textContent = i18n.noResults || 'No usage found.';
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		rows.forEach(function (row) {
			var tr = document.createElement('tr');
			var location = row.object_label || row.source_type || '';
			if (row.object_subtype) {
				location += ' (' + row.object_subtype + ')';
			}
			tr.innerHTML =
				'<td>' + escapeHtml(location) + '</td>' +
				'<td>' + escapeHtml(row.match_type || '') + '</td>' +
				'<td><code>' + escapeHtml(row.match_value || '') + '</code></td>' +
				'<td>' + escapeHtml(row.context || '') + '</td>' +
				'<td>' + (row.edit_url ? '<a class="button button-small" href="' + escapeAttr(row.edit_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(i18n.editLabel || 'Edit') + '</a>' : '—') + '</td>';
			tbody.appendChild(tr);
		});
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escapeAttr(str) {
		return escapeHtml(str).replace(/'/g, '&#39;');
	}

	function collectQuery(mode) {
		if (mode === 'plugin') {
			return {
				mode: mode,
				plugin_file: (qs('#tsosi-plugin-file') || {}).value || '',
			};
		}
		if (mode === 'shortcode') {
			var shortcodeInput = qs('#tsosi-shortcode');
			var shortcodeSelect = qs('#tsosi-shortcode-select');
			return {
				mode: mode,
				shortcode: (shortcodeInput && shortcodeInput.value) || (shortcodeSelect && shortcodeSelect.value) || '',
			};
		}
		if (mode === 'block') {
			var blockInput = qs('#tsosi-block');
			var blockSelect = qs('#tsosi-block-select');
			return {
				mode: mode,
				block: (blockInput && blockInput.value) || (blockSelect && blockSelect.value) || '',
			};
		}
		return { mode: mode };
	}

	function formatNeedles(needles) {
		if (!needles) {
			return '—';
		}
		var parts = [];
		if (needles.shortcodes && needles.shortcodes.length) {
			parts.push('shortcodes: ' + needles.shortcodes.join(', '));
		}
		if (needles.blocks && needles.blocks.length) {
			parts.push('blocks: ' + needles.blocks.join(', '));
		}
		if (needles.meta_prefixes && needles.meta_prefixes.length) {
			parts.push('meta: ' + needles.meta_prefixes.join(', '));
		}
		if (needles.option_prefixes && needles.option_prefixes.length) {
			parts.push('options: ' + needles.option_prefixes.join(', '));
		}
		return parts.length ? parts.join(' | ') : '—';
	}

	var lastScanResults = [];
	var lastScanMeta = {};

	function showScanActions(show) {
		var actions = qs('#tsosi-scan-actions');
		if (actions) {
			actions.hidden = !show;
		}
	}

	function csvEscape(value) {
		var str = String(value == null ? '' : value);
		if (/[",\n\r]/.test(str)) {
			return '"' + str.replace(/"/g, '""') + '"';
		}
		return str;
	}

	function exportCsv() {
		var headers = cfg.csvHeaders || ['Location', 'Type', 'Match', 'Context', 'Edit URL'];
		var lines = [headers.map(csvEscape).join(',')];
		lastScanResults.forEach(function (row) {
			var location = row.object_label || row.source_type || '';
			if (row.object_subtype) {
				location += ' (' + row.object_subtype + ')';
			}
			lines.push([
				location,
				row.match_type || '',
				row.match_value || '',
				row.context || '',
				row.edit_url || '',
			].map(csvEscape).join(','));
		});
		var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
		var url = URL.createObjectURL(blob);
		var link = document.createElement('a');
		var stamp = new Date().toISOString().slice(0, 10);
		link.href = url;
		link.download = 'stack-inspector-' + stamp + '.csv';
		link.click();
		URL.revokeObjectURL(url);
	}

	function reportStylesheetHref() {
		var url = String(cfg.reportCssUrl || '');
		var ver = String(cfg.reportCssVer || '');
		if (!url) {
			return '';
		}
		return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'ver=' + encodeURIComponent(ver);
	}

	function buildReportHtml() {
		var headers = cfg.csvHeaders || ['Location', 'Type', 'Match', 'Context', 'Edit URL'];
		var title = (cfg.siteName || '') + ' — ' + (i18n.reportTitle || 'Stack Inspector report');
		var summary = qs('#tsosi-scan-progress');
		var summaryText = summary ? summary.textContent : '';
		var rowsHtml = '';
		var cssHref = reportStylesheetHref();
		var criticalCss =
			'body{margin:0;padding:24px 32px;font-family:system-ui,sans-serif;color:#1d2327;line-height:1.5;}' +
			'table{width:100%;border-collapse:collapse;font-size:13px;}' +
			'th,td{border:1px solid #dcdcde;padding:8px 10px;text-align:left;vertical-align:top;}' +
			'th{background:#f6f7f7;font-weight:600;}' +
			'@media print{body{color:#000;padding:12px 16px;}th,td{color:#000;border-color:#666;}}';

		if (!lastScanResults.length) {
			rowsHtml = '<tr><td colspan="5">' + escapeHtml(i18n.noResults || 'No usage found.') + '</td></tr>';
		} else {
			lastScanResults.forEach(function (row) {
				var location = row.object_label || row.source_type || '';
				if (row.object_subtype) {
					location += ' (' + row.object_subtype + ')';
				}
				var editCell = row.edit_url
					? '<a href="' + escapeAttr(row.edit_url) + '">' + escapeHtml(i18n.editLabel || 'Edit') + '</a>'
					: '—';
				rowsHtml +=
					'<tr>' +
					'<td>' + escapeHtml(location) + '</td>' +
					'<td>' + escapeHtml(row.match_type || '') + '</td>' +
					'<td><code>' + escapeHtml(row.match_value || '') + '</code></td>' +
					'<td>' + escapeHtml(row.context || '') + '</td>' +
					'<td>' + editCell + '</td>' +
					'</tr>';
			});
		}

		var headCells = headers.map(function (h) {
			return '<th>' + escapeHtml(h) + '</th>';
		}).join('');

		var headLinks = '<style>' + criticalCss + '</style>';
		if (cssHref) {
			headLinks += '<link rel="stylesheet" href="' + escapeAttr(cssHref) + '">';
		}

		return '<!DOCTYPE html><html lang="' + escapeAttr(cfg.lang || 'en') + '"><head><meta charset="utf-8">' +
			'<meta name="viewport" content="width=device-width, initial-scale=1">' +
			'<title>' + escapeHtml(title) + '</title>' +
			headLinks +
			'</head><body class="tsosi-report">' +
			'<div class="tsosi-report-header"><h1>' + escapeHtml(title) + '</h1>' +
			'<p>' + escapeHtml(summaryText) + '</p></div>' +
			'<div class="tsosi-report-actions no-print">' +
			'<button type="button" class="tsosi-report-print" onclick="window.print()">' +
			escapeHtml(i18n.exportPdf || 'Print / PDF') + '</button></div>' +
			'<table class="tsosi-report-table"><thead><tr>' + headCells + '</tr></thead><tbody>' +
			rowsHtml + '</tbody></table>' +
			'</body></html>';
	}

	function exportPdf() {
		var html = buildReportHtml();
		var reportWindow = window.open('', '_blank');
		if (!reportWindow) {
			window.alert(i18n.exportPdfBlocked || 'Allow pop-ups to open the print report.');
			return;
		}
		reportWindow.document.open();
		reportWindow.document.write(html);
		reportWindow.document.close();
		reportWindow.focus();
	}

	function refreshProfiles() {
		return post('tsosi_refresh_profiles', {}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error((payload && payload.data && payload.data.message) || i18n.refreshFail);
			}
			setProgress((payload.data && payload.data.message) || i18n.refreshDone);
			window.setTimeout(function () {
				window.location.reload();
			}, 600);
		}).catch(function (err) {
			window.alert(err.message || i18n.refreshFail);
		});
	}

	function finishProgress(data) {
		var summary = i18n.scanSummary || 'Scanned %1$s posts. Needles: %2$s. Matches: %3$s.';
		var total = data.total_posts || 0;
		var count = data.result_count || 0;
		var needlesText = formatNeedles(data.needles);
		var text = summary
			.replace('%1$s', total)
			.replace('%2$s', needlesText)
			.replace('%3$s', count);
		if (data.needles_empty) {
			text += ' ' + (i18n.needlesEmpty || '');
		}
		if (data.cached) {
			text = (i18n.scanCached || 'Used cached site index.') + ' ' + text;
		}
		setProgress(text);
		lastScanMeta = {
			total_posts: total,
			result_count: count,
			needles: data.needles || {},
			needles_empty: !!data.needles_empty,
		};
		showScanActions(true);
	}

	function runSteps() {
		return post('tsosi_scan_step', {}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error((payload && payload.data && payload.data.message) || i18n.scanError);
			}
			var data = payload.data || {};
			var label = (i18n.progress || 'Progress') + ': ' + (data.progress || 0) + '%';
			if (data.total_steps) {
				label += ' (' + (data.processed_steps || 0) + '/' + data.total_steps + ')';
			} else if (data.total_posts) {
				label += ' (' + (data.processed || 0) + '/' + data.total_posts + ')';
			}
			setProgress(label);
			lastScanResults = data.results || [];
			renderResults(lastScanResults);
			if (!data.done) {
				return runSteps();
			}
			finishProgress(data);
		});
	}

	function startScan(mode) {
		showPanel();
		showScanActions(false);
		lastScanResults = [];
		lastScanMeta = {};
		setProgress(i18n.scanning || 'Scanning…');
		renderResults([]);
		return post('tsosi_scan_start', collectQuery(mode))
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error((payload && payload.data && payload.data.message) || i18n.scanError);
				}
				var data = payload.data || {};
				if (data.cached && data.done) {
					lastScanResults = data.results || [];
					renderResults(lastScanResults);
					finishProgress(data);
					return;
				}
				return runSteps();
			})
			.catch(function (err) {
				setProgress(err.message || i18n.scanError);
			});
	}

	function bind() {
		qsa('.tsosi-start-scan').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var mode = btn.getAttribute('data-mode') || 'plugin';
				startScan(mode);
			});
		});

		var langSelect = qs('#tsosi-set-lang');
		if (langSelect && langSelect.form) {
			langSelect.addEventListener('change', function () {
				langSelect.form.submit();
			});
		}

		var blockSelect = qs('#tsosi-block-select');
		var blockInput = qs('#tsosi-block');
		if (blockSelect && blockInput) {
			blockSelect.addEventListener('change', function () {
				if (blockSelect.value) {
					blockInput.value = blockSelect.value;
				}
			});
			blockInput.addEventListener('input', function () {
				if (blockSelect.value && blockInput.value !== blockSelect.value) {
					blockSelect.value = '';
				}
			});
		}

		var shortcodeSelect = qs('#tsosi-shortcode-select');
		var shortcodeInput = qs('#tsosi-shortcode');
		if (shortcodeSelect && shortcodeInput) {
			shortcodeSelect.addEventListener('change', function () {
				if (shortcodeSelect.value) {
					shortcodeInput.value = shortcodeSelect.value;
				}
			});
			shortcodeInput.addEventListener('input', function () {
				if (shortcodeSelect.value && shortcodeInput.value !== shortcodeSelect.value) {
					shortcodeSelect.value = '';
				}
			});
		}

		qsa('.tsosi-refresh-profiles').forEach(function (btn) {
			btn.addEventListener('click', refreshProfiles);
		});

		var csvBtn = qs('#tsosi-export-csv');
		if (csvBtn) {
			csvBtn.addEventListener('click', exportCsv);
		}

		var pdfBtn = qs('#tsosi-export-pdf');
		if (pdfBtn) {
			pdfBtn.addEventListener('click', exportPdf);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}
})();
