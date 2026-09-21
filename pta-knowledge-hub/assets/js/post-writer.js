/**
 * "Put one thing on the website" -- the chips on the writing screen.
 *
 * Every field on this screen is real and already in the form; this only
 * shows the blocks people asked for, and opens "Which picture?" for the
 * picture chip. Nothing here saves anything.
 *
 * Deliberately its own file rather than a branch inside content-wizard.js:
 * that script carries the entry wizard's type guessing, its related-entry
 * lookups and its wp.media fallbacks, none of which this screen has.
 * It reuses the same class names, so the stylesheet is shared, not copied.
 */
(function ($) {
    'use strict';

    var stepCount = 0;

    function showBlock(name) {
        $('#ptk-post-' + name + '-block').removeAttr('hidden').show();
        $('.ptk-qf-chip[data-block="' + name + '"]').hide();
    }

    function hideBlock(name) {
        $('#ptk-post-' + name + '-block').attr('hidden', 'hidden').hide();
        $('.ptk-qf-chip[data-block="' + name + '"]').show();
    }

    /** One step: a heading, and the words under it. The number is the stylesheet's counter. */
    function addStep() {
        var $steps = $('#ptk-post-steps');
        stepCount++;

        var $item = $('<div>', { 'class': 'ptk-repeater-item' });
        var $header = $('<div>', { 'class': 'ptk-repeater-header' });
        $('<button>', {
            type: 'button',
            'class': 'ptk-repeater-remove ptk-post-step-remove',
            title: 'Remove this step',
            'aria-label': 'Remove this step'
        }).append($('<span>', { 'class': 'dashicons dashicons-trash' })).appendTo($header);
        $header.appendTo($item);

        var $body = $('<div>', { 'class': 'ptk-repeater-body' });
        $('<input>', {
            type: 'text',
            name: 'ptk_post_step_heading[]',
            'class': 'ptk-post-step-heading',
            placeholder: 'What this step is'
        }).appendTo($body);
        $('<textarea>', {
            name: 'ptk_post_step_body[]',
            rows: 2,
            placeholder: 'What a family does.'
        }).appendTo($body);
        $body.appendTo($item);

        $steps.append($item);
        $item.find('input').trigger('focus');
    }

    function clearPicture() {
        $('#ptk-post-image-id, #ptk-post-image-focal-x, #ptk-post-image-focal-y, #ptk-post-image-zoom, #ptk-post-image-fit').val('');
        $('#ptk-post-image-preview').empty();
    }

    function choosePicture() {
        if (!window.ptkPicturePicker || !window.ptkPicturePicker.open) {
            return;
        }
        window.ptkPicturePicker.open({
            frame: true,
            aspect: '16:9',
            onChoose: function (picture) {
                $('#ptk-post-image-id').val(picture.id);
                $('#ptk-post-image-focal-x').val(picture.focalX);
                $('#ptk-post-image-focal-y').val(picture.focalY);
                $('#ptk-post-image-zoom').val(picture.zoom);
                $('#ptk-post-image-fit').val(picture.fit);
                $('#ptk-post-image-preview').empty().append(
                    $('<img>').attr({ src: picture.url, alt: picture.alt || '' })
                );
                showBlock('image');
            }
        });
    }

    $(function () {
        if (!$('#ptk-post-form').length) {
            return;
        }

        $(document).on('click', '#ptk-post-form .ptk-qf-chip', function () {
            var name = $(this).data('block');

            if (name === 'image') {
                choosePicture();
                return;
            }

            showBlock(name);

            if (name === 'steps') {
                if ($('#ptk-post-steps .ptk-repeater-item').length === 0) {
                    addStep();
                } else {
                    $('#ptk-post-steps').find('input').first().trigger('focus');
                }
            } else if (name === 'date') {
                $('#ptk-post-date-label').trigger('focus');
            } else if (name === 'link') {
                $('#ptk-post-link-text').trigger('focus');
            }
        });

        $(document).on('click', '#ptk-post-form .ptk-qf-block-remove', function () {
            var name = $(this).data('block');

            if (name === 'steps') {
                $('#ptk-post-steps').empty();
            } else if (name === 'date') {
                $('#ptk-post-date-label, #ptk-post-date-note').val('');
            } else if (name === 'link') {
                $('#ptk-post-link-text, #ptk-post-link-url').val('');
            } else if (name === 'image') {
                clearPicture();
            }

            hideBlock(name);
        });

        $(document).on('click', '#ptk-post-add-step', function () {
            addStep();
        });

        $(document).on('click', '.ptk-post-step-remove', function () {
            var $item = $(this).closest('.ptk-repeater-item');
            $item.remove();
            if ($('#ptk-post-steps .ptk-repeater-item').length === 0) {
                hideBlock('steps');
            }
        });
    });
}(jQuery));
