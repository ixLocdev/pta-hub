/**
 * PTA Knowledge Hub — Content Wizard
 *
 * Handles dynamic form behavior: category switching, repeatable steps,
 * image uploads via WP media library, form validation, autosave,
 * edit mode pre-filling, inline link insertion, and links repeater.
 */
(function ($) {
    'use strict';

    var stepCounter = 0;
    var timelineCounter = 0;
    var linkItemCounter = 0;
    var STORAGE_KEY = 'ptk_wizard_autosave';
    var autosaveTimer = null;
    var isRestoring = false;
    var activeTextarea = null; // Track which textarea the link popup targets.

    // Respect user's OS-level motion preference. When reduced, skip all
    // jQuery slide/scroll animations and just show/hide instantly.
    var REDUCE_MOTION = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /**
     * Reveal a wizard step. Plain show() — height animation reflows the page
     * on mobile and the user only triggers this once per entry create.
     */
    function revealStep($el) {
        $el.removeClass('ptk-hidden').show();
    }

    /**
     * Reveal a repeater item with a gentle slide on add. Guarded by
     * reduced-motion preference.
     */
    function revealRepeaterItem($item) {
        if (REDUCE_MOTION) {
            $item.show();
        } else {
            $item.hide().slideDown(200);
        }
    }

    /**
     * Collapse a repeater item before removing. Guarded by reduced-motion.
     */
    function collapseRepeaterItem($item, done) {
        if (REDUCE_MOTION) {
            $item.hide();
            if (typeof done === 'function') done();
        } else {
            $item.slideUp(200, done);
        }
    }

    /**
     * Initialize wizard when DOM is ready.
     */
    $(document).ready(function () {
        bindCategorySelection();
        bindRepeaters();
        bindImageUploads();
        bindFileUploads();
        bindFormValidation();
        bindLinkPopup();
        initLinkButtons();
        initAutosave();

        // If in edit mode, pre-fill the form.
        if (typeof ptkWizardData !== 'undefined' && ptkWizardData.editMode && ptkWizardData.editData) {
            restoreEditData(ptkWizardData.editData);
        }
    });

    /* ──────────────────────────────────────────
     * Category Selection (Step 1)
     * ────────────────────────────────────────── */

    function bindCategorySelection() {
        $('.ptk-category-card').on('click', function () {
            var $card = $(this);
            var category = $card.data('category');

            // Visual selection.
            $('.ptk-category-card').removeClass('ptk-card-selected');
            $card.addClass('ptk-card-selected');
            $card.find('input[type="radio"]').prop('checked', true);

            // Show basics step. Instant reveal — slideDown(300) used to
            // reflow the whole page below, especially bad on mobile.
            revealStep($('#ptk-step-basics'));

            // Show the correct category form, hide others.
            $('.ptk-category-form').addClass('ptk-hidden').hide();
            var $form = $('#ptk-form-' + category);
            if ($form.length) {
                revealStep($form);
            }

            // Show links section and submit step.
            revealStep($('#ptk-step-links'));
            revealStep($('#ptk-step-submit'));

            // Add initial repeater items if empty.
            if (category === 'how-to-guide') {
                var $steps = $('#ptk-howto-steps');
                if ($steps.find('.ptk-repeater-item').length === 0) {
                    addStep($steps);
                }
            } else if (category === 'event-playbook') {
                var $timeline = $('#ptk-event-timeline');
                if ($timeline.find('.ptk-repeater-item').length === 0) {
                    addTimelineItem($timeline);
                }
            } else if (category === 'checklist') {
                var $checklist = $('#ptk-checklist-items');
                if ($checklist.find('.ptk-repeater-item').length === 0) {
                    addChecklistItem($checklist);
                }
            }

            // Initialize link buttons on newly visible textareas.
            initLinkButtons();

            // Scroll to basics (skip during restore).
            if (!isRestoring) {
                if (REDUCE_MOTION) {
                    window.scrollTo(0, $('#ptk-step-basics').offset().top - 50);
                } else {
                    $('html, body').animate({
                        scrollTop: $('#ptk-step-basics').offset().top - 50
                    }, 200);
                }
            }
        });
    }

    /* ──────────────────────────────────────────
     * Repeater: Steps (How-To Guide)
     * ────────────────────────────────────────── */

    function bindRepeaters() {
        // Add Step button.
        $(document).on('click', '.ptk-add-step', function () {
            var $repeater = $('#' + $(this).data('repeater'));
            addStep($repeater);
        });

        // Add Timeline Item button.
        $(document).on('click', '.ptk-add-timeline', function () {
            var $repeater = $('#' + $(this).data('repeater'));
            addTimelineItem($repeater);
        });

        // Add Checklist Item button.
        $(document).on('click', '.ptk-add-checklist-item', function () {
            var $repeater = $('#' + $(this).data('repeater'));
            addChecklistItem($repeater);
        });

        // Add Link Item button.
        $(document).on('click', '.ptk-add-link-item', function () {
            var $repeater = $('#' + $(this).data('repeater'));
            addLinkItem($repeater);
        });

        // Remove item.
        $(document).on('click', '.ptk-repeater-remove', function () {
            var $item = $(this).closest('.ptk-repeater-item');
            var $repeater = $item.closest('.ptk-repeater');
            var minItems = parseInt($repeater.data('min') || 1, 10);

            if ($repeater.find('.ptk-repeater-item').length > minItems) {
                collapseRepeaterItem($item, function () {
                    $item.remove();
                    renumberSteps($repeater);
                });
            }
        });

        // Move up.
        $(document).on('click', '.ptk-repeater-up', function () {
            var $item = $(this).closest('.ptk-repeater-item');
            var $prev = $item.prev('.ptk-repeater-item');
            if ($prev.length) {
                $item.insertBefore($prev);
                renumberSteps($item.closest('.ptk-repeater'));
            }
        });

        // Move down.
        $(document).on('click', '.ptk-repeater-down', function () {
            var $item = $(this).closest('.ptk-repeater-item');
            var $next = $item.next('.ptk-repeater-item');
            if ($next.length) {
                $item.insertAfter($next);
                renumberSteps($item.closest('.ptk-repeater'));
            }
        });
    }

    function addStep($repeater) {
        stepCounter++;
        var index = $repeater.find('.ptk-repeater-item').length;
        var num = index + 1;

        var html = '<div class="ptk-repeater-item" data-index="' + index + '">' +
            '<div class="ptk-repeater-header">' +
                '<span class="ptk-repeater-number">Step ' + num + '</span>' +
                '<div class="ptk-repeater-actions">' +
                    '<button type="button" class="ptk-repeater-up" title="Move up"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
                    '<button type="button" class="ptk-repeater-down" title="Move down"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
                    '<button type="button" class="ptk-repeater-remove" title="Remove step"><span class="dashicons dashicons-trash"></span></button>' +
                '</div>' +
            '</div>' +
            '<div class="ptk-repeater-body">' +
                '<div class="ptk-textarea-wrap">' +
                    '<textarea name="ptk_step_text[]" class="ptk-field-textarea ptk-linkable" rows="3" placeholder="Describe what to do in this step..."></textarea>' +
                '</div>' +
                '<div class="ptk-step-link-fields">' +
                    '<label class="ptk-field-label ptk-step-link-label">Link for this step <span class="ptk-field-hint">(optional)</span></label>' +
                    '<div class="ptk-step-link-row">' +
                        '<input type="text" name="ptk_step_link_text[]" class="ptk-field-input ptk-step-link-text" placeholder="Link text (e.g., Go to Givebacks)">' +
                        '<input type="url" name="ptk_step_link_url[]" class="ptk-field-input ptk-step-link-url" placeholder="https://...">' +
                    '</div>' +
                '</div>' +
                '<div class="ptk-step-image">' +
                    '<input type="hidden" name="ptk_step_image[]" class="ptk-step-image-id" value="">' +
                    '<div class="ptk-step-image-preview"></div>' +
                    '<button type="button" class="button button-small ptk-step-upload-btn">' +
                        '<span class="dashicons dashicons-format-image"></span> Add Image' +
                    '</button>' +
                    '<button type="button" class="button button-small ptk-step-remove-image ptk-hidden">Remove Image</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        var $item = $(html).hide();
        $repeater.append($item);
        revealRepeaterItem($item);
        initLinkButtons();
        if (!isRestoring) {
            $item.find('textarea').focus();
        }
    }

    function addTimelineItem($repeater) {
        timelineCounter++;
        var index = $repeater.find('.ptk-repeater-item').length;
        var num = index + 1;

        var html = '<div class="ptk-repeater-item ptk-timeline-item" data-index="' + index + '">' +
            '<div class="ptk-repeater-header">' +
                '<span class="ptk-repeater-number">#' + num + '</span>' +
                '<div class="ptk-repeater-actions">' +
                    '<button type="button" class="ptk-repeater-up" title="Move up"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
                    '<button type="button" class="ptk-repeater-down" title="Move down"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
                    '<button type="button" class="ptk-repeater-remove" title="Remove"><span class="dashicons dashicons-trash"></span></button>' +
                '</div>' +
            '</div>' +
            '<div class="ptk-repeater-body ptk-timeline-fields">' +
                '<input type="text" name="ptk_timeline_when[]" class="ptk-field-input ptk-timeline-when" placeholder="When (e.g., 4 weeks before, Day of event)">' +
                '<input type="text" name="ptk_timeline_what[]" class="ptk-field-input ptk-timeline-what" placeholder="What needs to happen">' +
            '</div>' +
        '</div>';

        var $item = $(html).hide();
        $repeater.append($item);
        revealRepeaterItem($item);
        if (!isRestoring) {
            $item.find('input:first').focus();
        }
    }

    function addChecklistItem($repeater) {
        var index = $repeater.find('.ptk-repeater-item').length;
        var num = index + 1;

        var html = '<div class="ptk-repeater-item" data-index="' + index + '">' +
            '<div class="ptk-repeater-header">' +
                '<span class="ptk-repeater-number">#' + num + '</span>' +
                '<div class="ptk-repeater-actions">' +
                    '<button type="button" class="ptk-repeater-up" title="Move up"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
                    '<button type="button" class="ptk-repeater-down" title="Move down"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
                    '<button type="button" class="ptk-repeater-remove" title="Remove"><span class="dashicons dashicons-trash"></span></button>' +
                '</div>' +
            '</div>' +
            '<div class="ptk-repeater-body">' +
                '<input type="text" name="ptk_checklist_item[]" class="ptk-field-input" placeholder="What needs to be done?">' +
            '</div>' +
        '</div>';

        var $item = $(html).hide();
        $repeater.append($item);
        revealRepeaterItem($item);
        if (!isRestoring) {
            $item.find('input').focus();
        }
    }

    function addLinkItem($repeater) {
        linkItemCounter++;
        var index = $repeater.find('.ptk-repeater-item').length;
        var num = index + 1;

        var html = '<div class="ptk-repeater-item ptk-link-item" data-index="' + index + '">' +
            '<div class="ptk-repeater-header">' +
                '<span class="ptk-repeater-number">#' + num + '</span>' +
                '<div class="ptk-repeater-actions">' +
                    '<button type="button" class="ptk-repeater-up" title="Move up"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
                    '<button type="button" class="ptk-repeater-down" title="Move down"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
                    '<button type="button" class="ptk-repeater-remove" title="Remove"><span class="dashicons dashicons-trash"></span></button>' +
                '</div>' +
            '</div>' +
            '<div class="ptk-repeater-body ptk-link-fields">' +
                '<input type="text" name="ptk_link_text[]" class="ptk-field-input ptk-link-text-input" placeholder="Link text (e.g., PTA Website)">' +
                '<input type="url" name="ptk_link_url[]" class="ptk-field-input ptk-link-url-input" placeholder="https://...">' +
            '</div>' +
        '</div>';

        var $item = $(html).hide();
        $repeater.append($item);
        revealRepeaterItem($item);
        if (!isRestoring) {
            $item.find('input:first').focus();
        }
    }

    function renumberSteps($repeater) {
        $repeater.find('.ptk-repeater-item').each(function (i) {
            var label = $repeater.attr('id') === 'ptk-howto-steps' ? 'Step ' : '#';
            $(this).find('.ptk-repeater-number').text(label + (i + 1));
            $(this).attr('data-index', i);
        });
    }

    /* ──────────────────────────────────────────
     * Inline Link Button + Popup
     * ────────────────────────────────────────── */

    function initLinkButtons() {
        // Add link button above each linkable textarea that doesn't already have one.
        $('.ptk-linkable').each(function () {
            var $textarea = $(this);
            var $wrap = $textarea.closest('.ptk-textarea-wrap');
            if ($wrap.length && !$wrap.find('.ptk-link-btn').length) {
                var $btn = $('<button type="button" class="ptk-link-btn" title="Insert link">' +
                    '<span class="dashicons dashicons-admin-links"></span>' +
                    '</button>');
                $wrap.prepend($btn);
            }
        });
    }

    function bindLinkPopup() {
        // Open popup when link button is clicked.
        $(document).on('click', '.ptk-link-btn', function (e) {
            e.preventDefault();
            activeTextarea = $(this).closest('.ptk-textarea-wrap').find('textarea')[0];
            if (!activeTextarea) return;

            $('#ptk-link-popup-text').val('');
            $('#ptk-link-popup-url').val('');
            $('#ptk-link-popup').removeClass('ptk-hidden');
            $('#ptk-link-popup-text').focus();
        });

        // Insert link.
        $(document).on('click', '#ptk-link-popup-insert', function () {
            var linkText = $('#ptk-link-popup-text').val().trim();
            var linkUrl = $('#ptk-link-popup-url').val().trim();

            if (!linkText || !linkUrl) {
                alert('Please enter both link text and URL.');
                return;
            }

            // Ensure URL has protocol.
            if (linkUrl && !/^https?:\/\//i.test(linkUrl)) {
                linkUrl = 'https://' + linkUrl;
            }

            var markdown = '[' + linkText + '](' + linkUrl + ')';
            insertAtCursor(activeTextarea, markdown);

            $('#ptk-link-popup').addClass('ptk-hidden');
            activeTextarea = null;
        });

        // Cancel / close popup.
        $(document).on('click', '#ptk-link-popup-cancel, .ptk-link-popup-close', function () {
            $('#ptk-link-popup').addClass('ptk-hidden');
            activeTextarea = null;
        });

        // Close popup on Escape key.
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$('#ptk-link-popup').hasClass('ptk-hidden')) {
                $('#ptk-link-popup').addClass('ptk-hidden');
                activeTextarea = null;
            }
        });

        // Allow Enter in URL field to trigger insert.
        $(document).on('keydown', '#ptk-link-popup-url', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#ptk-link-popup-insert').trigger('click');
            }
        });
    }

    function insertAtCursor(textarea, text) {
        if (!textarea) return;
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var before = textarea.value.substring(0, start);
        var after = textarea.value.substring(end);
        textarea.value = before + text + after;
        textarea.selectionStart = textarea.selectionEnd = start + text.length;
        $(textarea).trigger('input');
        textarea.focus();
    }

    /* ──────────────────────────────────────────
     * Image Uploads (Featured Image + Step Images)
     * ────────────────────────────────────────── */

    function bindImageUploads() {
        // Featured image upload.
        $(document).on('click', '.ptk-upload-btn', function (e) {
            e.preventDefault();
            var target = $(this).data('target');
            openMediaPicker(target, 'image');
        });

        // Featured image remove.
        $(document).on('click', '.ptk-remove-image', function (e) {
            e.preventDefault();
            var target = $(this).data('target');
            $('#' + target + '-id').val('');
            $('#' + target + '-preview').html('');
            $(this).addClass('ptk-hidden');
        });

        // Step image upload.
        $(document).on('click', '.ptk-step-upload-btn', function (e) {
            e.preventDefault();
            var $item = $(this).closest('.ptk-step-image');
            openStepMediaPicker($item);
        });

        // Step image remove.
        $(document).on('click', '.ptk-step-remove-image', function (e) {
            e.preventDefault();
            var $item = $(this).closest('.ptk-step-image');
            $item.find('.ptk-step-image-id').val('');
            $item.find('.ptk-step-image-preview').html('');
            $(this).addClass('ptk-hidden');
        });
    }

    function openMediaPicker(target, type) {
        var frame = wp.media({
            title: 'Choose Image',
            button: { text: 'Use This Image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var thumbUrl = attachment.sizes && attachment.sizes.thumbnail
                ? attachment.sizes.thumbnail.url
                : attachment.url;

            $('#' + target + '-id').val(attachment.id);
            $('#' + target + '-preview').html(
                '<img src="' + thumbUrl + '" alt="" style="max-width:150px;max-height:150px;border-radius:4px;">'
            );
            $('[data-target="' + target + '"].ptk-remove-image').removeClass('ptk-hidden');
        });

        frame.open();
    }

    function openStepMediaPicker($item) {
        var frame = wp.media({
            title: 'Choose Step Image',
            button: { text: 'Use This Image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var thumbUrl = attachment.sizes && attachment.sizes.medium
                ? attachment.sizes.medium.url
                : attachment.url;

            $item.find('.ptk-step-image-id').val(attachment.id);
            $item.find('.ptk-step-image-preview').html(
                '<img src="' + thumbUrl + '" alt="" style="max-width:100%;max-height:200px;border-radius:4px;">'
            );
            $item.find('.ptk-step-remove-image').removeClass('ptk-hidden');
        });

        frame.open();
    }

    /* ──────────────────────────────────────────
     * File Uploads (Resource)
     * ────────────────────────────────────────── */

    function bindFileUploads() {
        $(document).on('click', '.ptk-upload-file-btn', function (e) {
            e.preventDefault();
            var target = $(this).data('target');
            openFilePicker(target);
        });

        $(document).on('click', '.ptk-remove-file', function (e) {
            e.preventDefault();
            var target = $(this).data('target');
            $('#' + target + '-id').val('');
            $('#' + target + '-preview').html('');
            $(this).addClass('ptk-hidden');
        });
    }

    function openFilePicker(target) {
        var frame = wp.media({
            title: 'Choose File',
            button: { text: 'Use This File' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var icon = attachment.icon || '';
            var filename = attachment.filename || attachment.title;

            $('#' + target + '-id').val(attachment.id);
            $('#' + target + '-preview').html(
                '<span class="dashicons dashicons-media-default"></span> ' + filename
            );
            $('[data-target="' + target + '"].ptk-remove-file').removeClass('ptk-hidden');
        });

        frame.open();
    }

    /* ──────────────────────────────────────────
     * Form Validation
     * ────────────────────────────────────────── */

    function bindFormValidation() {
        $('#ptk-wizard-form').on('submit', function (e) {
            var category = $('input[name="ptk_category"]:checked').val();
            var title = $('#ptk-title').val().trim();
            var isEdit = $('input[name="ptk_edit_id"]').length > 0;

            if (!category) {
                e.preventDefault();
                alert('Please select a category first.');
                if (REDUCE_MOTION) {
                    window.scrollTo(0, $('#ptk-step-category').offset().top - 50);
                } else {
                    $('html, body').animate({ scrollTop: $('#ptk-step-category').offset().top - 50 }, 200);
                }
                return false;
            }

            if (!title) {
                e.preventDefault();
                alert('Please enter a title.');
                $('#ptk-title').focus();
                return false;
            }

            // Category-specific validation.
            if (category === 'how-to-guide') {
                var hasStep = false;
                $('textarea[name="ptk_step_text[]"]').each(function () {
                    if ($(this).val().trim()) {
                        hasStep = true;
                        return false;
                    }
                });
                if (!hasStep) {
                    e.preventDefault();
                    alert('Please add at least one step to your How-To Guide.');
                    return false;
                }
            } else if (category === 'faq') {
                if (!$('#ptk-faq-short-answer').val().trim()) {
                    e.preventDefault();
                    alert('Please provide a Quick Answer for your FAQ entry.');
                    $('#ptk-faq-short-answer').focus();
                    return false;
                }
            } else if (category === 'resource') {
                if (!$('#ptk-resource-desc').val().trim()) {
                    e.preventDefault();
                    alert('Please provide a Description for your Resource.');
                    $('#ptk-resource-desc').focus();
                    return false;
                }
            } else if (category === 'glossary') {
                if (!$('#ptk-glossary-definition').val().trim()) {
                    e.preventDefault();
                    alert('Please provide a Definition for your Glossary Term.');
                    $('#ptk-glossary-definition').focus();
                    return false;
                }
            } else if (category === 'checklist') {
                var hasItem = false;
                $('input[name="ptk_checklist_item[]"]').each(function () {
                    if ($(this).val().trim()) {
                        hasItem = true;
                        return false;
                    }
                });
                if (!hasItem) {
                    e.preventDefault();
                    alert('Please add at least one checklist item.');
                    return false;
                }
            } else if (category === 'policy') {
                if (!$('#ptk-policy-summary').val().trim()) {
                    e.preventDefault();
                    alert('Please provide a Summary for your Policy entry.');
                    $('#ptk-policy-summary').focus();
                    return false;
                }
            }

            // Clear autosave on successful submit.
            clearAutosave();

            // Disable submit button to prevent double-submit.
            var btnText = isEdit ? 'Updating...' : 'Creating...';
            $('#ptk-wizard-submit-btn').prop('disabled', true).text(btnText);
        });
    }

    /* ──────────────────────────────────────────
     * Edit Mode: Pre-fill form with existing data
     * ────────────────────────────────────────── */

    function restoreEditData(data) {
        isRestoring = true;

        // Don't restore autosave when editing.
        clearAutosave();

        // Select category.
        if (data.category) {
            var $card = $('.ptk-category-card[data-category="' + data.category + '"]');
            if ($card.length) {
                $card.trigger('click');
            }
        }

        // Basic fields.
        if (data.title) { $('#ptk-title').val(data.title); }
        if (data.excerpt) { $('#ptk-excerpt').val(data.excerpt); }
        if (data.tags) { $('#ptk-tags').val(data.tags); }
        if (data.status) {
            $('input[name="ptk_status"][value="' + data.status + '"]').prop('checked', true);
        }

        // Featured image.
        if (data.featured_id && data.featured_url) {
            $('#ptk-featured-image-id').val(data.featured_id);
            $('#ptk-featured-image-preview').html(
                '<img src="' + data.featured_url + '" alt="" style="max-width:150px;max-height:150px;border-radius:4px;">'
            );
            $('[data-target="ptk-featured-image"].ptk-remove-image').removeClass('ptk-hidden');
        }

        // Category-specific fields.
        var fields = data.fields || {};

        if (data.category === 'how-to-guide') {
            if (fields.intro) { $('#ptk-howto-intro').val(fields.intro); }
            if (fields.difficulty) { $('#ptk-howto-difficulty').val(fields.difficulty); }
            if (fields.time) { $('#ptk-howto-time').val(fields.time); }
            if (fields.materials) { $('textarea[name="ptk_howto_materials"]').val(fields.materials); }
            if (fields.tips) { $('textarea[name="ptk_howto_tips"]').val(fields.tips); }

            if (fields.steps && fields.steps.length) {
                var $steps = $('#ptk-howto-steps');
                var $existing = $steps.find('.ptk-repeater-item');
                if ($existing.length === 1 && !$existing.find('textarea').val().trim()) {
                    $existing.remove();
                }
                for (var s = 0; s < fields.steps.length; s++) {
                    if ($steps.find('.ptk-repeater-item').length <= s) {
                        addStep($steps);
                    }
                    $steps.find('textarea[name="ptk_step_text[]"]').eq(s).val(fields.steps[s]);

                    // Restore step links.
                    if (fields.step_links && fields.step_links[s]) {
                        if (fields.step_links[s].text) {
                            $steps.find('input[name="ptk_step_link_text[]"]').eq(s).val(fields.step_links[s].text);
                        }
                        if (fields.step_links[s].url) {
                            $steps.find('input[name="ptk_step_link_url[]"]').eq(s).val(fields.step_links[s].url);
                        }
                    }
                }
            }
        } else if (data.category === 'event-playbook') {
            if (fields.overview) { $('#ptk-event-overview').val(fields.overview); }
            if (fields.date) { $('#ptk-event-date').val(fields.date); }
            if (fields.location) { $('#ptk-event-location').val(fields.location); }
            if (fields.budget) { $('#ptk-event-budget').val(fields.budget); }
            if (fields.supplies) { $('textarea[name="ptk_event_supplies"]').val(fields.supplies); }
            if (fields.contacts) { $('textarea[name="ptk_event_contacts"]').val(fields.contacts); }

            if (fields.timeline && fields.timeline.length) {
                var $timeline = $('#ptk-event-timeline');
                var $existingTl = $timeline.find('.ptk-repeater-item');
                if ($existingTl.length === 1 && !$existingTl.find('input').first().val().trim()) {
                    $existingTl.remove();
                }
                for (var t = 0; t < fields.timeline.length; t++) {
                    if ($timeline.find('.ptk-repeater-item').length <= t) {
                        addTimelineItem($timeline);
                    }
                    $('input[name="ptk_timeline_when[]"]').eq(t).val(fields.timeline[t].when || '');
                    $('input[name="ptk_timeline_what[]"]').eq(t).val(fields.timeline[t].what || '');
                }
            }
        } else if (data.category === 'faq') {
            if (fields.short_answer) { $('#ptk-faq-short-answer').val(fields.short_answer); }
            if (fields.details) { $('#ptk-faq-details').val(fields.details); }
            if (fields.reviewed) { $('#ptk-faq-reviewed').val(fields.reviewed); }
        } else if (data.category === 'resource') {
            if (fields.description) { $('#ptk-resource-desc').val(fields.description); }
            if (fields.url) { $('#ptk-resource-url').val(fields.url); }
            if (fields.file_type) { $('#ptk-resource-type').val(fields.file_type); }
            if (fields.howto) { $('#ptk-resource-howto').val(fields.howto); }
        } else if (data.category === 'glossary') {
            if (fields.definition) { $('#ptk-glossary-definition').val(fields.definition); }
            if (fields.details) { $('#ptk-glossary-details').val(fields.details); }
            if (fields.example) { $('#ptk-glossary-example').val(fields.example); }
        } else if (data.category === 'checklist') {
            if (fields.intro) { $('#ptk-checklist-intro').val(fields.intro); }
            if (fields.notes) { $('#ptk-checklist-notes').val(fields.notes); }

            if (fields.items && fields.items.length) {
                var $checklistR = $('#ptk-checklist-items');
                var $existingCl = $checklistR.find('.ptk-repeater-item');
                if ($existingCl.length === 1 && !$existingCl.find('input').first().val().trim()) {
                    $existingCl.remove();
                }
                for (var cl = 0; cl < fields.items.length; cl++) {
                    if ($checklistR.find('.ptk-repeater-item').length <= cl) {
                        addChecklistItem($checklistR);
                    }
                    $('input[name="ptk_checklist_item[]"]').eq(cl).val(fields.items[cl] || '');
                }
            }
        } else if (data.category === 'policy') {
            if (fields.summary) { $('#ptk-policy-summary').val(fields.summary); }
            if (fields.full_text) { $('#ptk-policy-full-text').val(fields.full_text); }
            if (fields.effective) { $('#ptk-policy-effective').val(fields.effective); }
            if (fields.reviewed) { $('#ptk-policy-reviewed').val(fields.reviewed); }
        }

        // Restore common links.
        if (fields.links && fields.links.length) {
            var $linksR = $('#ptk-links-repeater');
            for (var li = 0; li < fields.links.length; li++) {
                addLinkItem($linksR);
                $('input[name="ptk_link_text[]"]').eq(li).val(fields.links[li].text || '');
                $('input[name="ptk_link_url[]"]').eq(li).val(fields.links[li].url || '');
            }
        }

        isRestoring = false;
    }

    /* ──────────────────────────────────────────
     * Autosave (localStorage)
     * ────────────────────────────────────────── */

    function initAutosave() {
        // Don't restore on the success page or in edit mode.
        if (window.location.search.indexOf('ptk_created') !== -1) {
            clearAutosave();
            return;
        }
        if (window.location.search.indexOf('ptk_edit_id') !== -1) {
            clearAutosave();
            return;
        }

        // Try to restore saved data.
        restoreAutosave();

        // Save every 5 seconds while the form has content.
        autosaveTimer = setInterval(saveAutosave, 5000);

        // Also save on any input change.
        $(document).on('input change', '#ptk-wizard-form input, #ptk-wizard-form textarea, #ptk-wizard-form select', function () {
            clearTimeout(autosaveTimer);
            autosaveTimer = setTimeout(function () {
                saveAutosave();
                autosaveTimer = setInterval(saveAutosave, 5000);
            }, 1000);
        });

        // Save before the user leaves the page.
        $(window).on('beforeunload', function () {
            saveAutosave();
        });

        // Bind the discard button.
        $(document).on('click', '#ptk-autosave-discard', function (e) {
            e.preventDefault();
            clearAutosave();
            window.location.reload();
        });
    }

    function saveAutosave() {
        // Don't autosave in edit mode.
        if (typeof ptkWizardData !== 'undefined' && ptkWizardData.editMode) {
            return;
        }

        var data = {};
        var category = $('input[name="ptk_category"]:checked').val();

        // Only save if there's something meaningful.
        var title = $('#ptk-title').val() || '';
        if (!category && !title.trim()) {
            return;
        }

        data.category = category || '';
        data.title = title;
        data.excerpt = $('#ptk-excerpt').val() || '';
        data.tags = $('#ptk-tags').val() || '';
        data.status = $('input[name="ptk_status"]:checked').val() || 'publish';
        data.savedAt = new Date().toISOString();

        // Category-specific fields.
        if (category === 'how-to-guide') {
            data.howto = {
                intro: $('#ptk-howto-intro').val() || '',
                difficulty: $('#ptk-howto-difficulty').val() || '',
                time: $('#ptk-howto-time').val() || '',
                materials: $('textarea[name="ptk_howto_materials"]').val() || '',
                tips: $('textarea[name="ptk_howto_tips"]').val() || '',
                steps: [],
                stepLinks: []
            };
            $('textarea[name="ptk_step_text[]"]').each(function (i) {
                data.howto.steps.push($(this).val() || '');
                data.howto.stepLinks.push({
                    text: $('input[name="ptk_step_link_text[]"]').eq(i).val() || '',
                    url: $('input[name="ptk_step_link_url[]"]').eq(i).val() || ''
                });
            });
        } else if (category === 'event-playbook') {
            data.event = {
                overview: $('#ptk-event-overview').val() || '',
                date: $('#ptk-event-date').val() || '',
                location: $('#ptk-event-location').val() || '',
                budget: $('#ptk-event-budget').val() || '',
                supplies: $('textarea[name="ptk_event_supplies"]').val() || '',
                contacts: $('textarea[name="ptk_event_contacts"]').val() || '',
                timeline: []
            };
            $('input[name="ptk_timeline_when[]"]').each(function (i) {
                data.event.timeline.push({
                    when: $(this).val() || '',
                    what: $('input[name="ptk_timeline_what[]"]').eq(i).val() || ''
                });
            });
        } else if (category === 'faq') {
            data.faq = {
                shortAnswer: $('#ptk-faq-short-answer').val() || '',
                details: $('#ptk-faq-details').val() || '',
                reviewed: $('#ptk-faq-reviewed').val() || ''
            };
        } else if (category === 'resource') {
            data.resource = {
                description: $('#ptk-resource-desc').val() || '',
                url: $('#ptk-resource-url').val() || '',
                fileType: $('#ptk-resource-type').val() || '',
                howto: $('#ptk-resource-howto').val() || ''
            };
        } else if (category === 'glossary') {
            data.glossary = {
                definition: $('#ptk-glossary-definition').val() || '',
                details: $('#ptk-glossary-details').val() || '',
                example: $('#ptk-glossary-example').val() || ''
            };
        } else if (category === 'checklist') {
            data.checklist = {
                intro: $('#ptk-checklist-intro').val() || '',
                notes: $('#ptk-checklist-notes').val() || '',
                items: []
            };
            $('input[name="ptk_checklist_item[]"]').each(function () {
                data.checklist.items.push($(this).val() || '');
            });
        } else if (category === 'policy') {
            data.policy = {
                summary: $('#ptk-policy-summary').val() || '',
                fullText: $('#ptk-policy-full-text').val() || '',
                effective: $('#ptk-policy-effective').val() || '',
                reviewed: $('#ptk-policy-reviewed').val() || ''
            };
        }

        // Save common links.
        data.links = [];
        $('input[name="ptk_link_text[]"]').each(function (i) {
            data.links.push({
                text: $(this).val() || '',
                url: $('input[name="ptk_link_url[]"]').eq(i).val() || ''
            });
        });

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            updateAutosaveIndicator(true);
        } catch (e) {
            // localStorage full or unavailable — silently ignore.
        }
    }

    function restoreAutosave() {
        var raw;
        try {
            raw = localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return;
        }

        if (!raw) {
            return;
        }

        var data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            clearAutosave();
            return;
        }

        // Must have at least a category or title to be worth restoring.
        if (!data.category && !(data.title && data.title.trim())) {
            clearAutosave();
            return;
        }

        // Show restore banner.
        var savedAt = data.savedAt ? new Date(data.savedAt) : null;
        var timeStr = savedAt ? savedAt.toLocaleString() : 'earlier';
        var banner = '<div class="ptk-autosave-banner" id="ptk-autosave-banner">' +
            '<span class="dashicons dashicons-backup"></span> ' +
            '<strong>Recovered draft</strong> from ' + timeStr + '. ' +
            '<a href="#" id="ptk-autosave-discard">Discard and start fresh</a>' +
            '</div>';
        $('.ptk-wizard-header').after(banner);

        isRestoring = true;

        // Restore category selection.
        if (data.category) {
            var $card = $('.ptk-category-card[data-category="' + data.category + '"]');
            if ($card.length) {
                $card.trigger('click');
            }
        }

        // Restore basic fields.
        if (data.title) { $('#ptk-title').val(data.title); }
        if (data.excerpt) { $('#ptk-excerpt').val(data.excerpt); }
        if (data.tags) { $('#ptk-tags').val(data.tags); }
        if (data.status) {
            $('input[name="ptk_status"][value="' + data.status + '"]').prop('checked', true);
        }

        // Restore category-specific fields.
        if (data.category === 'how-to-guide' && data.howto) {
            $('#ptk-howto-intro').val(data.howto.intro || '');
            $('#ptk-howto-difficulty').val(data.howto.difficulty || '');
            $('#ptk-howto-time').val(data.howto.time || '');
            $('textarea[name="ptk_howto_materials"]').val(data.howto.materials || '');
            $('textarea[name="ptk_howto_tips"]').val(data.howto.tips || '');

            if (data.howto.steps && data.howto.steps.length) {
                var $steps = $('#ptk-howto-steps');
                // Remove the default first step if it's empty.
                var $existing = $steps.find('.ptk-repeater-item');
                if ($existing.length === 1 && !$existing.find('textarea').val().trim()) {
                    $existing.remove();
                }
                // Add steps for each saved entry.
                for (var s = 0; s < data.howto.steps.length; s++) {
                    if ($steps.find('.ptk-repeater-item').length <= s) {
                        addStep($steps);
                    }
                    $steps.find('textarea[name="ptk_step_text[]"]').eq(s).val(data.howto.steps[s]);
                    // Restore step links from autosave.
                    if (data.howto.stepLinks && data.howto.stepLinks[s]) {
                        $steps.find('input[name="ptk_step_link_text[]"]').eq(s).val(data.howto.stepLinks[s].text || '');
                        $steps.find('input[name="ptk_step_link_url[]"]').eq(s).val(data.howto.stepLinks[s].url || '');
                    }
                }
            }
        } else if (data.category === 'event-playbook' && data.event) {
            $('#ptk-event-overview').val(data.event.overview || '');
            $('#ptk-event-date').val(data.event.date || '');
            $('#ptk-event-location').val(data.event.location || '');
            $('#ptk-event-budget').val(data.event.budget || '');
            $('textarea[name="ptk_event_supplies"]').val(data.event.supplies || '');
            $('textarea[name="ptk_event_contacts"]').val(data.event.contacts || '');

            if (data.event.timeline && data.event.timeline.length) {
                var $timeline = $('#ptk-event-timeline');
                var $existingTl = $timeline.find('.ptk-repeater-item');
                if ($existingTl.length === 1 && !$existingTl.find('input').first().val().trim()) {
                    $existingTl.remove();
                }
                for (var t = 0; t < data.event.timeline.length; t++) {
                    if ($timeline.find('.ptk-repeater-item').length <= t) {
                        addTimelineItem($timeline);
                    }
                    $('input[name="ptk_timeline_when[]"]').eq(t).val(data.event.timeline[t].when || '');
                    $('input[name="ptk_timeline_what[]"]').eq(t).val(data.event.timeline[t].what || '');
                }
            }
        } else if (data.category === 'faq' && data.faq) {
            $('#ptk-faq-short-answer').val(data.faq.shortAnswer || '');
            $('#ptk-faq-details').val(data.faq.details || '');
            $('#ptk-faq-reviewed').val(data.faq.reviewed || '');
        } else if (data.category === 'resource' && data.resource) {
            $('#ptk-resource-desc').val(data.resource.description || '');
            $('#ptk-resource-url').val(data.resource.url || '');
            $('#ptk-resource-type').val(data.resource.fileType || '');
            $('#ptk-resource-howto').val(data.resource.howto || '');
        } else if (data.category === 'glossary' && data.glossary) {
            $('#ptk-glossary-definition').val(data.glossary.definition || '');
            $('#ptk-glossary-details').val(data.glossary.details || '');
            $('#ptk-glossary-example').val(data.glossary.example || '');
        } else if (data.category === 'checklist' && data.checklist) {
            $('#ptk-checklist-intro').val(data.checklist.intro || '');
            $('#ptk-checklist-notes').val(data.checklist.notes || '');

            if (data.checklist.items && data.checklist.items.length) {
                var $checklistR = $('#ptk-checklist-items');
                var $existingCl = $checklistR.find('.ptk-repeater-item');
                if ($existingCl.length === 1 && !$existingCl.find('input').first().val().trim()) {
                    $existingCl.remove();
                }
                for (var cl = 0; cl < data.checklist.items.length; cl++) {
                    if ($checklistR.find('.ptk-repeater-item').length <= cl) {
                        addChecklistItem($checklistR);
                    }
                    $('input[name="ptk_checklist_item[]"]').eq(cl).val(data.checklist.items[cl] || '');
                }
            }
        } else if (data.category === 'policy' && data.policy) {
            $('#ptk-policy-summary').val(data.policy.summary || '');
            $('#ptk-policy-full-text').val(data.policy.fullText || '');
            $('#ptk-policy-effective').val(data.policy.effective || '');
            $('#ptk-policy-reviewed').val(data.policy.reviewed || '');
        }

        // Restore common links.
        if (data.links && data.links.length) {
            var $linksR = $('#ptk-links-repeater');
            for (var li = 0; li < data.links.length; li++) {
                addLinkItem($linksR);
                $('input[name="ptk_link_text[]"]').eq(li).val(data.links[li].text || '');
                $('input[name="ptk_link_url[]"]').eq(li).val(data.links[li].url || '');
            }
        }

        isRestoring = false;
    }

    function clearAutosave() {
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // Ignore.
        }
        $('#ptk-autosave-banner').remove();
        updateAutosaveIndicator(false);
    }

    function updateAutosaveIndicator(saved) {
        var $indicator = $('#ptk-autosave-indicator');
        if (saved) {
            if (!$indicator.length) {
                $indicator = $('<span id="ptk-autosave-indicator" class="ptk-autosave-indicator">' +
                    '<span class="dashicons dashicons-saved"></span> Draft saved</span>');
                $('.ptk-wizard-intro').append($indicator);
            }
            // Brief pulse — Emil frequency rule: autosave fires often, don't linger.
            $indicator.stop(true).css('opacity', 1).delay(400).animate({ opacity: 0 }, 600);
        }
    }

})(jQuery);
