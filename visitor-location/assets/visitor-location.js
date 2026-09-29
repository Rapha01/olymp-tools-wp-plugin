/**
 * Olymp Tools — Visitor Location (front-end)
 *
 * Fills the placeholder <span class="olymp-visitor"> elements a shortcode emits.
 * One AJAX call resolves the visitor's location server-side (local DB lookup),
 * then each placeholder is filled from its requested field(s). If a field is
 * empty, the placeholder keeps its pre-rendered `default` text — and for a
 * multi-field placeholder the separator is only inserted between non-empty
 * parts, so you never get an orphaned "​, Deutschland".
 *
 * Runs client-side on purpose: the value is per-visitor, so it must resolve
 * outside the (possibly full-page-cached) HTML.
 */
(function () {
    'use strict';

    function fill(data) {
        var nodes = document.querySelectorAll('.olymp-visitor');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];

            var fields = (el.getAttribute('data-field') || '')
                .split(',')
                .map(function (s) { return s.trim(); })
                .filter(Boolean);

            var sep = el.getAttribute('data-separator') || '';

            var parts = fields
                .map(function (f) { return data[f]; })
                .filter(function (v) { return typeof v === 'string' && v.length > 0; });

            // Only replace the default when we actually have something to show.
            if (parts.length) {
                el.textContent = parts.join(sep);
            }
        }
    }

    function run() {
        if (!document.querySelector('.olymp-visitor')) {
            return;
        }

        var cfg = window.olympVisitorLocation || {};
        if (!cfg.ajaxUrl || !cfg.action) {
            return;
        }

        var url = cfg.ajaxUrl + '?action=' + encodeURIComponent(cfg.action);

        // Dev/test: forward a ?test_ip= override from the current page URL so the
        // lookup (and the lazy DB download it triggers) can be exercised from a
        // local environment where the real visitor IP is private. Honored
        // server-side for logged-in admins only.
        var testIp = new URLSearchParams(window.location.search).get('test_ip');
        if (testIp) {
            url += '&test_ip=' + encodeURIComponent(testIp);
        }

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp && resp.success && resp.data) {
                    fill(resp.data);
                }
            })
            .catch(function () { /* leave defaults in place */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
