/**
 * PTA Knowledge Hub — Content Wizard
 *
 * Handles dynamic form behavior: category switching, repeatable steps,
 * image uploads via WP media library, form validation, and autosave.
 */
(function ($) {
    'use strict';

    var stepCounter = 0;
    var timelineCounter = 0;
    var STORAGE_KEY = 'ptk_wizard_autosave';
    var autosaveTimer = null;
    var isRestoring = false;

    /**
     * Initialize wizard when DOM is ready.
     */
    $(document).ready(function () {
        bindCategorySelection();
        bindRepeaters();
        bindImageUploads();
        bindFileUploads();
        bindFormValidation();
        initAutosave();
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

            // Show basics step.
            $('#ptk-step-basics').removeClass('ptk-hidden').hide().slideDown(300);

            // Show the correct category form, hide others.
            $('.ptk-category-form').addClass('ptk-hidden').hide();
            var $form = $('#ptk-form-' + category);
            if ($form.length) {
                $form.removeClass('ptk-hidden').hide().slideDown(300);
            }

            // Show submit step.
            $('#ptk-step-submit').removeClass('ptk-hidden').hide().slideDown(300);

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

            // Scroll to basics (skip during restore).
            if (!isRestoring) {
                $('html, body').animate({
                    scrollTop: $('#ptk-step-basics').offset().top - 50
                }, 400);
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

        // Remove item.
        $(document).on('click', '.ptk-repeater-remove', function () {
            var $item = $(this).closest('.ptk-repeater-item');
            var $repeater = $item.closest('.ptk-repeater');
            var minItems = parseInt($repeater.data('min') || 1, 10);

            if ($repeater.find('.ptk-repeater-item').length > minItems) {
                $item.slideUp(200, function () {
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
                '<textarea name="ptk_step_text[]" class="ptk-field-textarea" rows="3" placeholder="Describe what to do in this step..."></textarea>' +
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
        $item.slideDown(200);
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
        $item.slideDown(200);
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
        $item.slideDown(200);
        if (!isRestoring) {
            $item.find('input').focus();
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

            if (!category) {
                e.preventDefault();
                alert('Please select a category first.');
                $('html, body').animate({ scrollTop: $('#ptk-step-category').offset().top - 50 }, 400);
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
            $('#ptk-wizard-submit-btn').prop('disabled', true).text('Creating...');
        });
    }

    /* ──────────────────────────────────────────
     * Autosave (localStorage)
     * ────────────────────────────────────────── */

    function initAutosave() {
        // Don't restore on the success page.
        if (window.location.search.indexOf('ptk_created') !== -1) {
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
                steps: []
            };
            $('textarea[name="ptk_step_text[]"]').each(function () {
                data.howto.steps.push($(this).val() || '');
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
            $indicator.stop(true).css('opacity', 1).delay(2000).animate({ opacity: 0.4 }, 500);
        }
    }

})(jQuery);
