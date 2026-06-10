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
                var restUrl = btn.getAttribute('data-rest');
                var wrapper = document.getElementById('sb-form-' + slug);
                var msgDiv  = document.getElementById('sb-form-messages-' + slug);

                if (!wrapper || !restUrl) { return; }

                // Clear previous errors
                wrapper.querySelectorAll('.sb-field-error').forEach(function(el) {
                    el.textContent = '';
                });
                wrapper.querySelectorAll('.sb-field-wrap input, .sb-field-wrap select, .sb-field-wrap textarea').forEach(function(el) {
                    el.style.borderColor = '';
                });

                // Collect field values
                var data = {};
                wrapper.querySelectorAll('input, textarea, select').forEach(function(el) {
                    if (el.name && el.name !== '_sbfnonce' && el.name !== 'form_slug') {
                        if (el.type === 'checkbox') {
                            data[el.name] = el.checked ? '1' : '0';
                        } else if (el.type === 'radio') {
                            if (el.checked) { data[el.name] = el.value; }
                        } else {
                            data[el.name] = el.value;
                        }
                    }
                });

                // Get nonce
                var nonceEl = wrapper.querySelector('input[name="_sbfnonce"]');
                var nonce   = nonceEl ? nonceEl.value : '';

                // Disable button
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

                    if (res.success !== false && !res.error && !res.data?.errors) {
                        // Success
                        if (msgDiv) {
                            msgDiv.innerHTML = '<div class="sb-msg-success">✓ ' + (res.message || res.data?.message || 'Saved successfully.') + '</div>';
                        }
                        var fields = document.getElementById('sb-form-fields-' + slug);
                        if (fields) {
                            setTimeout(function() {
                                fields.style.opacity = '0.4';
                                fields.style.pointerEvents = 'none';
                            }, 800);
                        }
                    } else {
                        // Validation errors
                        var errors = res.data?.errors || res.errors || {};
                        var generalError = res.message || res.error || 'Please check the fields below.';

                        if (Object.keys(errors).length > 0) {
                            Object.keys(errors).forEach(function(key) {
                                var errEl = document.getElementById('err_sbf_' + key);
                                var inputEl = document.getElementById('sbf_' + key);
                                if (errEl) { errEl.textContent = errors[key]; }
                                if (inputEl) { inputEl.style.borderColor = '#dc2626'; }
                            });
                            if (msgDiv) {
                                msgDiv.innerHTML = '<div class="sb-msg-error">Please correct the errors below.</div>';
                            }
                        } else {
                            if (msgDiv) {
                                msgDiv.innerHTML = '<div class="sb-msg-error">' + generalError + '</div>';
                            }
                        }
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Save & Continue →';
                    if (msgDiv) {
                        msgDiv.innerHTML = '<div class="sb-msg-error">Connection error. Please check your network and try again.</div>';
                    }
                });
            });
        });
    });
})();
