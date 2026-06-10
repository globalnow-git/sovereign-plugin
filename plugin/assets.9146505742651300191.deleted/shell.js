/**
 * Sovereign Builder — Operator Shell
 *
 * Unified admin UI shell: log panel, object inspector, row click handler,
 * audit log polling, and factory run progress tracking.
 *
 * Depends on: jQuery, sb-admin-js
 * Localized via: sbShellCtx (restBase, nonce, ajaxUrl)
 *
 * @package SovereignBuilder
 * @version 1.1.0
 */
(function ($) {
	'use strict';

	if (typeof sbShellCtx === 'undefined') { return; }

	// ── State ─────────────────────────────────────────────────────────────────

	var lastAuditId   = 0;
	var logOpen       = false;
	var inspectorOpen = false;
	var pollTimer     = null;

	// ── Log panel toggle ──────────────────────────────────────────────────────

	$('#sb-toggle-log').on('click', function () {
		logOpen = !logOpen;
		$('#sb-shell-log').toggle(logOpen);
		if (logOpen) { pollAuditLog(); }
		else if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
	});

	$('#sb-log-close').on('click', function () {
		logOpen = false;
		$('#sb-shell-log').hide();
		if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
	});

	// ── Inspector panel toggle ────────────────────────────────────────────────

	$('#sb-toggle-inspector').on('click', function () {
		inspectorOpen = !inspectorOpen;
		$('#sb-shell-inspector').toggle(inspectorOpen);
	});

	$('#sb-inspector-close').on('click', function () {
		inspectorOpen = false;
		$('#sb-shell-inspector').hide();
	});

	// ── Row click → inspector ─────────────────────────────────────────────────

	$(document).on('click', '.widefat tbody tr', function () {
		var $row   = $(this);
		var id     = $row.find('td:first').text().trim();
		var slug   = $row.find('code:first').text().trim() || id;
		var label  = $row.find('td:nth-child(2)').text().trim();
		var status = $row.find('td:nth-child(4)').text().trim();

		$('#sb-inspector-content').html(
			'<dl style="margin:0;">' +
			'<dt><strong>ID / Slug</strong></dt><dd><code>' + escHtml(slug) + '</code></dd>' +
			'<dt><strong>Label</strong></dt><dd>' + escHtml(label) + '</dd>' +
			'<dt style="margin-top:10px;"><strong>Status</strong></dt><dd>' + escHtml(status) + '</dd>' +
			'</dl>' +
			'<hr>' +
			'<button class="button" style="width:100%;margin-top:8px;" ' +
			'onclick="window.location.href=window.location.href">Refresh</button>'
		);

		if (!inspectorOpen) {
			inspectorOpen = true;
			$('#sb-shell-inspector').show();
		}
	});

	// ── Audit log polling ─────────────────────────────────────────────────────

	function pollAuditLog() {
		if (!logOpen) { return; }

		$.ajax({
			url:     sbShellCtx.restBase + 'audit-stream',
			headers: { 'X-WP-Nonce': sbShellCtx.nonce },
			data:    { since: lastAuditId },
			success: function (data) {
				if (data && data.length) {
					data.forEach(function (e) {
						var color = e.log_level === 'error'   ? '#f48771' :
						            e.log_level === 'warning' ? '#ffd700' : '#9cdcfe';
						$('#sb-log-entries').prepend(
							'<div style="border-bottom:1px solid #333;padding:2px 0;">' +
							'<span style="color:' + color + ';">[' + escHtml(e.log_level.toUpperCase()) + ']</span> ' +
							'<span style="color:#888;">' + escHtml(e.created_at) + '</span> ' +
							'<span>' + escHtml(e.action) + ': ' + escHtml(e.message) + '</span>' +
							'</div>'
						);
						lastAuditId = Math.max(lastAuditId, parseInt(e.id, 10) || 0);
					});

					// Keep last 50 entries
					var entries = $('#sb-log-entries > div');
					if (entries.length > 50) { entries.slice(50).remove(); }
				}
			},
			error: function () {
				// Silent — log panel stays open, retry on next poll
			}
		});

		pollTimer = setTimeout(pollAuditLog, 5000);
	}

	// ── Factory run progress tracker ──────────────────────────────────────────
	// Polls progress endpoint when a factory run is queued.

	$(document).on('sb:factory_run_queued', function (e, runId) {
		trackFactoryRun(runId);
	});

	function trackFactoryRun(runId) {
		if (!runId) { return; }

		var $bar    = $('#sb-factory-progress-bar');
		var $wrap   = $('#sb-factory-progress-wrap');
		var $status = $('#sb-factory-progress-status');

		if (!$wrap.length) { return; }

		$wrap.show();

		function poll() {
			$.ajax({
				url:     sbShellCtx.restBase + 'factory/progress/' + runId,
				headers: { 'X-WP-Nonce': sbShellCtx.nonce },
				success: function (data) {
					var pct    = data.progress || 0;
					var status = data.status   || 'processing';

					$bar.css('width', pct + '%');
					$status.text(status.charAt(0).toUpperCase() + status.slice(1) + ' — ' + pct + '%');

					if (status === 'complete' || status === 'failed') {
						$status.text(status === 'complete' ? 'Complete' : 'Failed');
						setTimeout(function () { $wrap.fadeOut(); }, 3000);
						return;
					}

					setTimeout(poll, 2000);
				},
				error: function () {
					setTimeout(poll, 5000);
				}
			});
		}

		poll();
	}

	// ── Admin list table enhancements ─────────────────────────────────────────

	// Highlight active road rows
	$('.widefat tbody tr').each(function () {
		var status = $(this).find('td:nth-child(4)').text().trim().toLowerCase();
		if (status === 'active') {
			$(this).find('td:nth-child(4)').css({ 'color': '#00a32a', 'font-weight': 'bold' });
		} else if (status === 'failed' || status === 'error') {
			$(this).find('td:nth-child(4)').css({ 'color': '#d63638', 'font-weight': 'bold' });
		}
	});

	// Confirm irreversible actions
	$(document).on('click', '.sb-confirm-action', function (e) {
		var msg = $(this).data('confirm') || 'Are you sure? This cannot be undone.';
		if (!window.confirm(msg)) {
			e.preventDefault();
			return false;
		}
	});

	// ── Blueprint builder prompt trigger ──────────────────────────────────────
	// Listens for factory run queued event from form submission response.

	$(document).on('sb:form_submitted', function (e, data) {
		if (data && data.run_id) {
			$(document).trigger('sb:factory_run_queued', [data.run_id]);
		}
	});

	// ── Utility ───────────────────────────────────────────────────────────────

	function escHtml(str) {
		if (typeof str !== 'string') { return ''; }
		return str
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

})(jQuery);
