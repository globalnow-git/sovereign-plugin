/* Sovereign Builder v1.0.3 — Admin JS */
/* global sbAdmin, wp */
(function ($) {
    'use strict';

    if (typeof sbAdminContext === 'undefined') { return; }

    /* --- Content consumed beacon --- */
    $(document).ready(function () {
        var startTime = Date.now();
        var beaconFired = false;

        function fireContentBeacon() {
            if (beaconFired) { return; }
            var elapsed = (Date.now() - startTime) / 1000;
            if (elapsed < 5) { return; }
            beaconFired = true;
            $.post(sbAdminContext.restBase + 'sovereign-builder/v1/signal', {
                signal_type: 'content_consumed',
                meta_json: JSON.stringify({
                    post_id: sbAdminContext.postId || 0,
                    elapsed: elapsed
                }),
                _wpnonce: sbAdminContext.nonce
            });
        }

        $(window).on('scroll', function () {
            var scrollPct = ($(window).scrollTop() + $(window).height()) / $(document).height();
            if (scrollPct > 0.6) { fireContentBeacon(); }
        });

        setTimeout(fireContentBeacon, 10000);
    });

    /* --- Game telemetry beacon --- */
    window.sbGameBeacon = function (eventType, meta) {
        $.post(sbAdminContext.restBase + 'sovereign-builder/v1/game/signal', {
            event_type: eventType,
            meta_json: JSON.stringify(meta || {}),
            _wpnonce: sbAdminContext.nonce
        });
    };

    /* --- Factory run progress polling --- */
    function pollFactoryProgress(jobId, $statusEl) {
        if (!jobId || !$statusEl.length) { return; }
        var interval = setInterval(function () {
            $.getJSON(
                sbAdminContext.restBase + 'sovereign-builder/v1/factory-progress/' + jobId,
                { _wpnonce: sbAdminContext.nonce }
            ).done(function (data) {
                if (!data) { return; }
                var pct = data.progress || 0;
                $statusEl.find('.sb-progress-bar').css('width', pct + '%');
                $statusEl.find('.sb-progress-label').text(pct + '%');
                if (data.status === 'complete' || data.status === 'failed' || pct >= 100) {
                    clearInterval(interval);
                    if (data.status === 'complete') {
                        $statusEl.addClass('sb-complete');
                        setTimeout(function () { window.location.reload(); }, 1200);
                    } else {
                        $statusEl.addClass('sb-failed');
                        $statusEl.find('.sb-status-msg').text('Run failed. Check Audit Log for details.');
                    }
                }
            }).fail(function () {
                // R2-033: clear interval on network error to prevent infinite polling
                clearInterval(interval);
                $statusEl.addClass('sb-failed').find('.sb-status-msg').text('Connection error — factory status unknown.');
            });
        }, 3000);
    }

    /* --- Factory run submit --- */
    $(document).on('submit', '#sb-factory-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $form.find('[type=submit]');
        var $status = $('#sb-run-status');

        $btn.prop('disabled', true).text('Running…');
        $status.show().removeClass('sb-complete sb-failed');

        $.ajax({
            method: 'POST',
            // R2-001: nonce as header for WP REST auth
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', sbAdminContext.nonce); },
            url: sbAdminContext.restBase + 'sovereign-builder/v1/run-factory',
            data: JSON.stringify({
                idea_text:   $form.find('#sb-idea-text').val(),
                campaign_id: $form.find('#sb-campaign-id').val(),
                _wpnonce:    sbAdminContext.nonce
            }),
            contentType: 'application/json',
            dataType: 'json'
        }).done(function (res) {
            if (res && res.job_id) {
                pollFactoryProgress(res.job_id, $status);
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Run Factory');
            $status.addClass('sb-failed').find('.sb-status-msg').text(
                (xhr.responseJSON && xhr.responseJSON.message) || 'Request failed.'
            );
        });
    });

    /* --- HITM approval buttons --- */
    $(document).on('click', '.sb-approve-btn, .sb-reject-btn', function (e) {
        e.preventDefault();
        var $btn       = $(this);
        var approvalId = $btn.data('approval-id');
        var action     = $btn.hasClass('sb-approve-btn') ? 'approve' : 'reject';
        var note       = $btn.closest('.sb-approval-row').find('.sb-approval-note').val() || '';

        $btn.prop('disabled', true).text(action === 'approve' ? 'Approving…' : 'Rejecting…');

        $.post({
            url: sbAdminContext.restBase + 'sovereign-builder/v1/approve',
            data: JSON.stringify({ approval_id: approvalId, action: action, note: note, _wpnonce: sbAdminContext.nonce }),
            contentType: 'application/json',
            dataType: 'json'
        }).done(function () {
            $btn.closest('.sb-approval-row').fadeOut(400, function () { $(this).remove(); });
        }).fail(function () {
            $btn.prop('disabled', false).text(action === 'approve' ? 'Approve' : 'Reject');
            alert('Action failed. Please try again.');
        });
    });

    /* --- WP Media picker for settings fields --- */
    $(document).on('click', '.sb-media-select', function (e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var $input   = $('#' + targetId);

        if (typeof wp === 'undefined' || !wp.media) { return; }

        var frame = wp.media({
            title:    'Select Image',
            button:   { text: 'Use this image' },
            multiple: false,
            library:  { type: 'image' }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.url);
            var prevId = targetId.replace('_url', '_id');
            if ($('#' + prevId).length) {
                $('#' + prevId).val(attachment.id);
            }
        });

        frame.open();
    });

    /* --- Test email send --- */
    $(document).on('click', '#sb-send-test-email', function (e) {
        e.preventDefault();
        var $btn         = $(this);
        var templateKey  = $('#sb-template-key').val();
        var previewEmail = $('#sb-preview-email-to').val() || sbAdminContext.adminEmail;

        $btn.prop('disabled', true).text('Sending…');

        $.post({
            url: sbAdminContext.restBase + 'sovereign-builder/v1/preview-email',
            data: JSON.stringify({ template_key: templateKey, send_to: previewEmail, _wpnonce: sbAdminContext.nonce }),
            contentType: 'application/json',
            dataType: 'json'
        }).done(function () {
            $btn.prop('disabled', false).text('Test sent ✓');
            setTimeout(function () { $btn.text('Send test email'); }, 3000);
        }).fail(function () {
            $btn.prop('disabled', false).text('Send test email');
            alert('Test email failed. Check SMTP configuration.');
        });
    });

    /* --- Audit log real-time toggle --- */
    var auditPoller = null;
    $(document).on('change', '#sb-live-log-toggle', function () {
        if ($(this).is(':checked')) {
            auditPoller = setInterval(function () {
                var $log = $('#sb-audit-log-tbody');
                if (!$log.length) { return; }
                $.getJSON(
                    sbAdminContext.restBase + 'sovereign-builder/v1/audit-stream',
                    { since: $log.data('last-id') || 0, _wpnonce: sbAdminContext.nonce }
                ).done(function (rows) {
                    if (!rows || !rows.length) { return; }
                    rows.forEach(function (row) {
                        $log.prepend(
                            '<tr><td>' + row.created_at + '</td><td><span class="sb-log-level sb-level-' +
                            row.log_level + '">' + row.log_level + '</span></td><td>' +
                            row.action + '</td><td>' + row.message + '</td></tr>'
                        );
                    });
                    $log.data('last-id', rows[rows.length - 1].id);
                });
            }, 10000);
        } else {
            if (auditPoller) { clearInterval(auditPoller); auditPoller = null; }
        }
    });

    /* --- Settings save via AJAX --- */
    $(document).on('submit', '#sb-settings-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var data  = {};
        $form.serializeArray().forEach(function (f) { data[f.name] = f.value; });
        data._wpnonce = sbAdminContext.nonce;

        $.post({
            url: sbAdminContext.restBase + 'sovereign-builder/v1/settings',
            data: JSON.stringify(data),
            contentType: 'application/json',
            dataType: 'json'
        }).done(function () {
            var $msg = $('#sb-settings-saved');
            $msg.show().delay(2500).fadeOut();
        });
    });



    /* ── UI Completion Layer ─────────────────────────────────────────── */

    /* Layer output toggle (factory run detail) */
    $(document).on('click', '.sb-layer-toggle', function () {
        var $pre = $(this).siblings('.sb-layer-content');
        if ($pre.is(':hidden')) {
            $pre.slideDown(150);
            $(this).text('[Collapse output]');
        } else {
            $pre.slideUp(150);
            $(this).text('[Expand output]');
        }
    });

    /* Factory run launcher on Factory Runs screen */
    $(document).on('click', '#sb-launch-factory', function () {
        var input    = $('#sb-factory-input').val().trim();
        var pipeline = $('#sb-factory-pipeline').val();
        if (!input) { alert('Please enter an idea or input text.'); return; }

        var $btn     = $(this);
        var $wrapper = $('#sb-factory-progress-wrapper');
        var $output  = $('#sb-factory-output');
        $btn.prop('disabled', true).text('Launching\u2026');
        $wrapper.show();
        $output.hide();

        $.ajax({
            url: sbAdminContext.restBase + 'sovereign-builder/v1/run-factory',
            method: 'POST',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', sbAdminContext.nonce); },
            contentType: 'application/json',
            data: JSON.stringify({ input_text: input, pipeline_slug: pipeline }),
            dataType: 'json'
        }).done(function (res) {
            if (res && (res.job_id || res.run_id)) {
                pollFactoryProgress(res.job_id || res.run_id, { find: function () { return $('#sb-factory-progress-wrapper'); } });
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('\u25B6 Launch Factory Run');
            $wrapper.hide();
            alert('Failed to start factory run. Check API key and try again.');
        });
    });

    /* Media picker wiring for .sb-media-select buttons */
    $(document).on('click', '.sb-media-select', function (e) {
        e.preventDefault();
        var $btn    = $(this);
        var $target = $($btn.data('target') || '#' + $btn.attr('id').replace('-btn', '-url'));
        var $img    = $($btn.data('preview'));
        var frame   = wp.media({
            title: 'Select Media',
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $target.val(attachment.url);
            if ($img.length) { $img.attr('src', attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url).show(); }
        });
        frame.open();
    });

    /* Approval quick-action (approve/reject without page reload) */
    $(document).on('click', '.sb-quick-approve, .sb-quick-reject', function (e) {
        e.preventDefault();
        var $row    = $(this).closest('tr');
        var id      = $(this).data('id');
        var action  = $(this).hasClass('sb-quick-approve') ? 'approved' : 'rejected';
        var note    = $(this).data('note') || '';
        if (!confirm((action === 'approved' ? 'Approve' : 'Reject') + ' this approval?')) { return; }

        $.ajax({
            url: sbAdminContext.restBase + 'sovereign-builder/v1/approve',
            method: 'POST',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', sbAdminContext.nonce); },
            contentType: 'application/json',
            data: JSON.stringify({ approval_id: id, action: action, note: note }),
            dataType: 'json'
        }).done(function (res) {
            if (res && res.success) {
                $row.find('.sb-badge').removeClass().addClass('sb-badge sb-badge-' + action).text(action);
                $row.find('.sb-quick-approve, .sb-quick-reject').remove();
            }
        }).fail(function () { alert('Action failed. Refresh and try again.'); });
    });

    /* Auto-refresh approvals count badge */
    if ($('.toplevel_page_sovereign-builder').length && typeof sbAdminContext !== 'undefined') {
        setInterval(function () {
            $.getJSON(sbAdminContext.restBase + 'sovereign-builder/v1/audit-stream', { since: 0, _wpnonce: sbAdminContext.nonce }).done(function () {
                /* live badge update hook point */
            });
        }, 60000);
    }

    /* Confirm delete links */
    $(document).on('click', 'a[href*="sb_delete_row"]', function (e) {
        if (!confirm('Permanently delete this record?')) { e.preventDefault(); }
    });

}(jQuery));