/**
 * Sovereign Builder — Front-end Form Handler
 * Handles sb_form shortcode submission via REST API.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.sb-form-submit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var slug    = btn.getAttribute('data-slug');
                var restUrl = (window.sbFormsContext && sbFormsContext.rest) ? sbFormsContext.rest : btn.getAttribute('data-rest');
                var nonce   = (window.sbFormsContext && sbFormsContext.nonce) ? sbFormsContext.nonce : '';
                var wrapper = document.getElementById('sb-form-' + slug);
                var msgDiv  = document.getElementById('sb-form-messages-' + slug);

                if (!wrapper || !restUrl) { return; }

                // Clear previous errors
                wrapper.querySelectorAll('.sb-field-error').forEach(function(el) {
                    el.textContent = '';
                });
                wrapper.querySelectorAll('input, select, textarea').forEach(function(el) {
                    el.style.borderColor = '';
                });

                // Collect field values
                var data = {};
                wrapper.querySelectorAll('input, textarea, select').forEach(function(el) {
                    if (!el.name || el.name === '_sbfnonce' || el.name === 'form_slug') { return; }
                    if (el.type === 'checkbox') {
                        data[el.name] = el.checked ? '1' : '0';
                    } else if (el.type === 'radio') {
                        if (el.checked) { data[el.name] = el.value; }
                    } else {
                        data[el.name] = el.value;
                    }
                });

                // Disable button while submitting
                btn.disabled = true;
                btn.textContent = 'Saving…';
                if (msgDiv) { msgDiv.innerHTML = ''; }

                fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce
                    },
                    body: JSON.stringify({ slug: slug, data: data })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    btn.textContent = 'Save & Continue →';

                    // Determine success vs failure
                    var isSuccess = res.success === true || (res.submission_id && !res.error);
                    var errors    = res.data && res.data.errors ? res.data.errors : (res.errors || {});
                    var hasErrors = Object.keys(errors).length > 0;

                    if (isSuccess) {
                        if (msgDiv) {
                            msgDiv.innerHTML = '<div class="sb-msg-success">✓ ' + (res.message || 'Saved successfully.') + '</div>';
                        }
                        // Fade out form fields after short delay
                        var fields = document.getElementById('sb-form-fields-' + slug);
                        if (fields) {
                            setTimeout(function() {
                                fields.style.transition = 'opacity 0.4s';
                                fields.style.opacity = '0.35';
                                fields.style.pointerEvents = 'none';
                            }, 1000);
                        }
                    } else if (hasErrors) {
                        // Show inline field errors
                        Object.keys(errors).forEach(function(key) {
                            var errEl   = document.getElementById('err_sbf_' + key);
                            var inputEl = document.getElementById('sbf_' + key);
                            if (errEl)   { errEl.textContent = errors[key]; }
                            if (inputEl) { inputEl.style.borderColor = '#dc2626'; }
                        });
                        if (msgDiv) {
                            msgDiv.innerHTML = '<div class="sb-msg-error">Please correct the errors below and try again.</div>';
                        }
                        // Scroll to first error
                        var firstErr = wrapper.querySelector('.sb-field-error:not(:empty)');
                        if (firstErr) { firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                    } else {
                        var errMsg = res.message || res.error || 'Submission failed. Please try again.';
                        if (msgDiv) {
                            msgDiv.innerHTML = '<div class="sb-msg-error">✗ ' + errMsg + '</div>';
                        }
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Save & Continue →';
                    if (msgDiv) {
                        msgDiv.innerHTML = '<div class="sb-msg-error">✗ Connection error. Please check your network and try again.</div>';
                    }
                });
            });
        });
    });
})();
