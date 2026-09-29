/**
 * Olymp Tools — AI-Image Marker settings page
 *
 * Wires the badge-image media picker, shows/hides the text-vs-image option
 * rows, and renders the live preview using the badge builder exposed by the
 * front-end engine (assets/ai-image-marker.js), so the preview exercises the
 * same code that runs on the website.
 */
(function ($) {
    'use strict';

    /** Mirrors OFFSETS in ai-image-marker.js. */
    var OFFSETS = { small: 6, medium: 8, large: 10 };

    function field(name) {
        return $('.olymp-tools-form[data-tool="ai-image-marker"] [name="' + name + '"]');
    }

    function rgba(hex, opacity) {
        hex = (hex || '').replace('#', '');
        if (hex.length === 3) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        if (!/^[0-9a-fA-F]{6}$/.test(hex)) {
            hex = '000000';
        }
        var o = isNaN(opacity) ? 0.65 : Math.max(0, Math.min(100, opacity)) / 100;
        return 'rgba(' + parseInt(hex.substr(0, 2), 16) + ',' + parseInt(hex.substr(2, 2), 16)
            + ',' + parseInt(hex.substr(4, 2), 16) + ',' + o + ')';
    }

    function imageUrl() {
        var $thumb = $('#olymp-aiimgmark-image-thumb');
        return ($thumb.length && $thumb.attr('src') && $thumb.is(':visible')) ? $thumb.attr('src') : '';
    }

    function toggleTypeRows() {
        var isImage = field('badge_type').val() === 'image';
        $('.olymp-aiimgmark-type-text').toggle(!isImage);
        $('.olymp-aiimgmark-type-image').toggle(isImage);
    }

    function renderPreview() {
        var $box = $('#olymp-aiimgmark-preview');
        if (!$box.length || !window.OlympAiImgMarkEngine) {
            return;
        }

        $box.find('.olymp-aiimgmark-badge').remove();

        var badge = window.OlympAiImgMarkEngine.buildBadge({
            type:       (field('badge_type').val() === 'image' && imageUrl()) ? 'image' : 'text',
            text:       field('text').val() || $box.data('default-text') || 'AI-generated',
            tooltip:    field('tooltip').val(),
            link:       '', // never navigate away from the settings page
            size:       field('size').val() || 'medium',
            textColor:  field('text_color').val(),
            bg:         rgba(field('bg_color').val(), parseInt(field('bg_opacity').val(), 10)),
            imageUrl:   imageUrl(),
            imageWidth: parseInt(field('image_width').val(), 10) || 120
        });

        // The preview container is the image box itself, so plain corner
        // offsets are enough here (the front end computes real positions).
        var off = (OFFSETS[field('size').val()] || 8) + 'px';
        var pos = field('position').val() || 'bottom-right';
        badge.style[pos.indexOf('top') === 0 ? 'top' : 'bottom'] = off;
        badge.style[pos.indexOf('left') !== -1 ? 'left' : 'right'] = off;

        $box.append(badge);

        // Apply the Custom CSS field to the preview too.
        $('#olymp-aiimgmark-preview-css').remove();
        var css = field('custom_css').val();
        if (css) {
            $('<style id="olymp-aiimgmark-preview-css">').text(css).appendTo(document.head);
        }
    }

    $(function () {
        var $form = $('.olymp-tools-form[data-tool="ai-image-marker"]');
        if (!$form.length) {
            return;
        }

        // ── Badge image media picker ───────────────────────────────────────
        var frame = null;
        $('#olymp-aiimgmark-image-choose').on('click', function (e) {
            e.preventDefault();
            if (!frame) {
                frame = wp.media({
                    title: $(this).data('title') || 'Select badge image',
                    library: { type: 'image' },
                    multiple: false
                });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    var url = (att.sizes && att.sizes.medium && att.sizes.medium.url) || att.url;
                    field('image_id').val(att.id);
                    $('#olymp-aiimgmark-image-thumb').attr('src', url).show();
                    $('#olymp-aiimgmark-image-remove').show();
                    renderPreview();
                });
            }
            frame.open();
        });

        $('#olymp-aiimgmark-image-remove').on('click', function (e) {
            e.preventDefault();
            field('image_id').val('0');
            $('#olymp-aiimgmark-image-thumb').attr('src', '').hide();
            $(this).hide();
            renderPreview();
        });

        // ── Live preview ───────────────────────────────────────────────────
        $form.on('input change', 'input, select, textarea', function () {
            toggleTypeRows();
            renderPreview();
        });

        toggleTypeRows();
        renderPreview();
    });
})(jQuery);
