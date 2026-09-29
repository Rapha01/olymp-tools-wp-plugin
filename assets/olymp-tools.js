/**
 * Olymp Tools — shared admin JS
 *
 * Handles AJAX form saving (.olymp-tools-form) and copy-to-clipboard
 * (.olymp-tools-copy) for every Olymp Tools page. Relies on the localized
 * `olympTools` object (ajaxUrl, nonce, strings).
 */
(function ($) {
    'use strict';

    $(function () {
        // ── AJAX save ──────────────────────────────────────────────────────────
        $(document).on('submit', '.olymp-tools-form', function (e) {
            e.preventDefault();

            var $form   = $(this);
            var $btn    = $form.find('.olymp-tools-save-button');
            var $status = $form.find('.olymp-tools-save-status');

            var payload = $form.serializeArray();
            payload.push({ name: 'action', value: 'olymp_tools_save' });
            payload.push({ name: 'tool',   value: $form.data('tool') });
            payload.push({ name: 'nonce',  value: olympTools.nonce });

            $btn.prop('disabled', true);
            $status.removeClass('is-success is-error').text(olympTools.saving);

            $.post(olympTools.ajaxUrl, payload)
                .done(function (resp) {
                    if (resp && resp.success) {
                        $status.addClass('is-success').text((resp.data && resp.data.message) || olympTools.saved);
                        // Reload so server-rendered panels (e.g. "Current values") reflect the saved settings.
                        setTimeout(function () { window.location.reload(); }, 600);
                    } else {
                        $status.addClass('is-error').text((resp && resp.data && resp.data.message) || olympTools.error);
                    }
                })
                .fail(function () {
                    $status.addClass('is-error').text(olympTools.error);
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });

        // ── Copy to clipboard ──────────────────────────────────────────────────
        $(document).on('click', '.olymp-tools-copy', function () {
            var $btn = $(this);
            var text = $btn.attr('data-clipboard') || '';

            var flash = function () {
                var orig = $btn.data('orig') || $btn.text();
                $btn.data('orig', orig).text(olympTools.copied);
                setTimeout(function () { $btn.text(orig); }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(flash).catch(function () { fallbackCopy(text); flash(); });
            } else {
                fallbackCopy(text);
                flash();
            }
        });

        function fallbackCopy(text) {
            var $tmp = $('<textarea>').val(text).css({ position: 'fixed', left: '-9999px' }).appendTo('body');
            $tmp[0].select();
            try { document.execCommand('copy'); } catch (err) {}
            $tmp.remove();
        }
    });
})(jQuery);
