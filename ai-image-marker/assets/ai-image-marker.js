/**
 * Olymp Tools — AI-Image Marker front-end engine
 *
 * Renders a badge on every <img> whose file belongs to a Media Library image
 * marked as AI-generated. Runs entirely on the final DOM, so it works with any
 * theme/page builder and behind full-page caching.
 *
 * Config comes from the inline `olympAiImgMark` variable printed by PHP:
 *   { stems, type, text, tooltip, link, position, size, textColor, bg,
 *     minSize, imageUrl, imageWidth, altSuffix, excludes }
 *
 * Matching: an image URL is normalised to its uploads "stem" (pathname without
 * extension, -{w}x{h} size suffix, or -scaled suffix) and compared against the
 * marked stems, so every generated size variant of a marked image is caught.
 *
 * Without a config variable (e.g. on the tool's admin page) the engine only
 * exposes `window.OlympAiImgMarkEngine.buildBadge` for the settings preview.
 */
(function () {
    'use strict';

    /** Badge distance from the image corner, per size preset (px). */
    var OFFSETS = { small: 6, medium: 8, large: 10 };

    /**
     * Build an (unpositioned) badge element from a config object.
     * Colors are applied inline; positioning is the caller's job.
     */
    function buildBadge(cfg) {
        var isImage = cfg.type === 'image' && cfg.imageUrl;
        var el = document.createElement(cfg.link ? 'a' : 'span');

        el.className = 'olymp-aiimgmark-badge olymp-aiimgmark--' + (cfg.size || 'medium')
            + (isImage ? ' olymp-aiimgmark--image' : ' olymp-aiimgmark--text');

        if (isImage) {
            var im = document.createElement('img');
            im.src = cfg.imageUrl;
            im.alt = cfg.text || '';
            // Never treat the badge's own <img> as a page image to be badged.
            im.setAttribute('data-olymp-aiimgmark', 'skip');
            el.appendChild(im);
            el.style.width = (parseInt(cfg.imageWidth, 10) || 120) + 'px';
        } else {
            el.textContent = cfg.text || 'AI';
            if (cfg.textColor) { el.style.color = cfg.textColor; }
            if (cfg.bg) { el.style.background = cfg.bg; }
        }

        if (cfg.tooltip) { el.title = cfg.tooltip; }
        if (cfg.link) { el.href = cfg.link; }

        return el;
    }

    // Expose the builder for the admin live preview.
    window.OlympAiImgMarkEngine = { buildBadge: buildBadge };

    var cfg = window.olympAiImgMark;
    if (!cfg || !cfg.stems || !cfg.stems.length) {
        return; // no front-end config — builder-only mode (admin preview)
    }

    // Drop invalid exclusion selectors once, so matching never throws later.
    var excludes = (cfg.excludes || []).filter(function (sel) {
        try {
            document.createDocumentFragment().querySelector(sel);
            return true;
        } catch (e) {
            return false;
        }
    });

    /**
     * Normalise an image URL to its path "stem": pathname without extension,
     * WordPress -{w}x{h} size suffix, or -scaled suffix.
     */
    function stemOf(url) {
        var path;
        try {
            path = new URL(url, window.location.href).pathname;
        } catch (e) {
            return '';
        }
        try {
            path = decodeURIComponent(path);
        } catch (e2) {
            // keep the encoded form
        }
        return path
            .replace(/\.[^.\/]+$/, '')
            .replace(/-\d+x\d+$/, '')
            .replace(/-scaled$/, '');
    }

    function isMarked(img) {
        var src = img.currentSrc || img.src;
        if (!src) { return false; }
        var p = stemOf(src);
        if (!p) { return false; }
        for (var i = 0; i < cfg.stems.length; i++) {
            var tail = '/' + cfg.stems[i];
            if (p === cfg.stems[i] || p.slice(-tail.length) === tail) {
                return true;
            }
        }
        return false;
    }

    function isExcluded(img) {
        for (var i = 0; i < excludes.length; i++) {
            try {
                if (img.closest(excludes[i])) { return true; }
            } catch (e) { /* ignore */ }
        }
        return false;
    }

    /**
     * Element the badge is appended to and positioned within: the image's
     * parent, climbing past static <a>/<picture> wrappers so the badge never
     * lands inside a link and img.offsetLeft/Top stay relative to the host.
     */
    function hostFor(img) {
        var host = img.parentElement;
        while (host && host !== document.body
            && (host.tagName === 'A' || host.tagName === 'PICTURE')
            && host.parentElement
            && getComputedStyle(host).position === 'static') {
            host = host.parentElement;
        }
        return host;
    }

    /** Position (or hide) the badge relative to its image. */
    function place(img, badge) {
        var w = img.offsetWidth;
        var h = img.offsetHeight;

        // Hide on images whose smaller side is below the threshold (also
        // covers hidden slides at 0x0 — shown again once they get a size).
        if (Math.min(w, h) < (cfg.minSize || 0)) {
            badge.style.display = 'none';
            return;
        }
        badge.style.display = '';

        var off = OFFSETS[cfg.size] || 8;
        var l = img.offsetLeft;
        var t = img.offsetTop;
        var x, y, tf;

        switch (cfg.position) {
            case 'top-left':
                x = l + off; y = t + off; tf = '';
                break;
            case 'top-right':
                x = l + w - off; y = t + off; tf = 'translateX(-100%)';
                break;
            case 'bottom-left':
                x = l + off; y = t + h - off; tf = 'translateY(-100%)';
                break;
            default: // bottom-right
                x = l + w - off; y = t + h - off; tf = 'translate(-100%,-100%)';
                break;
        }

        badge.style.left = x + 'px';
        badge.style.top = y + 'px';
        badge.style.transform = tf;
    }

    /** Append the disclosure suffix to the image's alt text (once). */
    function applyAltSuffix(img) {
        if (!cfg.altSuffix) { return; }
        var alt = img.getAttribute('alt') || '';
        if (alt.indexOf(cfg.altSuffix) !== -1) { return; }
        img.setAttribute('alt', alt ? alt + ' ' + cfg.altSuffix : cfg.altSuffix);
    }

    /** Create, insert, and keep positioning a badge for one image. */
    function attach(img) {
        var host = hostFor(img);
        if (!host || host === document.body) {
            return false; // no safe positioning context
        }

        if (getComputedStyle(host).position === 'static') {
            host.classList.add('olymp-aiimgmark-host');
        }

        // A linked badge must never nest inside an existing link.
        var badgeCfg = cfg;
        if (cfg.link && img.closest('a')) {
            badgeCfg = {
                type: cfg.type, text: cfg.text, tooltip: cfg.tooltip,
                size: cfg.size, textColor: cfg.textColor, bg: cfg.bg,
                imageUrl: cfg.imageUrl, imageWidth: cfg.imageWidth, link: ''
            };
        }

        var badge = buildBadge(badgeCfg);
        host.appendChild(badge);

        var reposition = function () { place(img, badge); };
        if (window.ResizeObserver) {
            new ResizeObserver(reposition).observe(img);
        } else {
            window.addEventListener('resize', reposition);
        }
        if (!img.complete) {
            img.addEventListener('load', reposition);
        }
        reposition();
        return true;
    }

    /**
     * Decide once per image. States (data-olymp-aiimgmark): 'done' badge attached,
     * 'skip' not marked/excluded — cleared again when src/srcset changes so
     * lazy-loaded images get a fresh decision.
     */
    function process(img) {
        if (img.dataset.olympAiimgmark) { return; }
        if (!(img.currentSrc || img.src)) { return; } // no source yet

        if (!isMarked(img) || isExcluded(img)) {
            img.dataset.olympAiimgmark = 'skip';
            return;
        }

        applyAltSuffix(img);
        img.dataset.olympAiimgmark = attach(img) ? 'done' : 'skip';
    }

    var scanScheduled = false;
    function scheduleScan() {
        if (scanScheduled) { return; }
        scanScheduled = true;
        setTimeout(function () {
            scanScheduled = false;
            scan();
        }, 150);
    }

    function scan() {
        var imgs = document.querySelectorAll('img');
        for (var i = 0; i < imgs.length; i++) {
            process(imgs[i]);
        }
    }

    function init() {
        scan();

        // Catch images added later (sliders, infinite scroll) and lazy-load
        // src/srcset swaps. Our own badge insertions are no-ops on rescan.
        var mo = new MutationObserver(function (mutations) {
            var rescan = false;
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === 'attributes') {
                    if (m.target.dataset && m.target.dataset.olympAiimgmark === 'skip') {
                        delete m.target.dataset.olympAiimgmark;
                    }
                    rescan = true;
                } else if (m.addedNodes && m.addedNodes.length) {
                    rescan = true;
                }
            }
            if (rescan) { scheduleScan(); }
        });
        mo.observe(document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['src', 'srcset']
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
