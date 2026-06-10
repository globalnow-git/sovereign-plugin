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

                // Collect field values
                var data = {};
                wrapper.querySelectorAll('input, textarea, select').forEach(function(el) {
                    if (el.name && el.name !== '_sbfnonce' && el.name !== 'form_slug') {
                        if (el.type === 'checkbox') {
                            data[el.name] = el.checked ? '1' : '0';
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
                btn.textContent = 'Submitting...';
                if (msgDiv) { msgDiv.innerHTML = ''; }

                // Submit to REST
                fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ slug: slug, data: data })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    btn.textContent = 'Submit';
                    if (msgDiv) {
                        if (res.success !== false && !res.error) {
                            msgDiv.innerHTML = '<p style="color:green;font-weight:bold;">Submitted successfully. Thank you!</p>';
                            wrapper.querySelector('#sb-form-fields-' + slug).style.display = 'none';
                        } else {
                            msgDiv.innerHTML = '<p style="color:red;">' + (res.error || 'Submission failed. Please try again.') + '</p>';
                        }
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = 'Submit';
                    if (msgDiv) {
                        msgDiv.innerHTML = '<p style="color:red;">Network error. Please try again.</p>';
                    }
                });
            });
        });
    });
})();
