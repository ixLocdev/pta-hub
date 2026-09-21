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
        bindFolding();
        bindQuestionFirstToggles();
        bindQuietTypeLine();
        bindQfChips();
        bindQfSubmit();
        bindQfAnswerAutogrow();
        bindQfSimilar();

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

            // Scroll to basics (skip during restore). Task 2's
            // question-first screen reuses this same .ptk-category-card
            // click handler for its "Change that" picker but has no
            // #ptk-step-basics at all -- guard for that rather than
            // letting .offset() on an empty selection throw.
            var $basicsStep = $('#ptk-step-basics');
            if (!isRestoring && $basicsStep.length) {
                if (REDUCE_MOTION) {
                    window.scrollTo(0, $basicsStep.offset().top - 50);
                } else {
                    $('html, body').animate({
                        scrollTop: $basicsStep.offset().top - 50
                    }, 200);
                }
            }

            // Phase 4, task 2: category and basics/fields just changed
            // visibility -- recompute which step is "current" right away
            // rather than waiting for the next scroll.
            updateFolds();
        });
    }

    /* ──────────────────────────────────────────
     * Task 2: the question-first screen's follow-up toggles
     *
     * The reveal itself is pure CSS (see .ptk-qf-toggle-input:checked in
     * content-wizard.css) -- this only adds a convenience on top, same
     * spirit as bindCategorySelection() adding the first empty step/
     * timeline/checklist item above: when "Are there steps to follow?" is
     * ticked and the steps repeater is still empty, add the first step so
     * there's somewhere to type right away. A no-op if the question-first
     * markup isn't on the page (the old category-first screen has no
     * #ptk-qf-has-steps at all).
     * ────────────────────────────────────────── */

    function bindQuestionFirstToggles() {
        var $stepsToggle = $('#ptk-qf-has-steps');
        if (!$stepsToggle.length) {
            return;
        }
        $stepsToggle.on('change', function () {
            if (!this.checked) {
                return;
            }
            var $steps = $('#ptk-howto-steps');
            if ($steps.find('.ptk-repeater-item').length === 0) {
                addStep($steps);
            }
        });
    }

    /* ──────────────────────────────────────────
     * Task 3: the quiet type line
     *
     * Lives under the answer field on the question-first screen (and, in
     * edit mode, replaces the raw category grid in the old screen too --
     * see render_step1_locked_type()). Recomputes PTK_Entry_Type::guess()
     * (its JS port, entry-type.js) on every input/change and updates the
     * line's text live, UNLESS #ptk-type-locked is "1" -- set the moment
     * the volunteer picks a card from "Change that", and never cleared
     * again for this page load, so an explicit choice always wins exactly
     * as the plan requires. A no-op if the page has no #ptk-qf-type-line
     * at all (nothing to bind).
     * ────────────────────────────────────────── */

    function questionFirstSignals() {
        var stepCount = 0;
        $('textarea[name="ptk_step_text[]"]').each(function () {
            if ($.trim($(this).val() || '') !== '') {
                stepCount++;
            }
        });

        var $hasDate = $('#ptk-qf-has-date');
        var hasDate = $hasDate.length ? $hasDate.is(':checked') : false;

        var $hasLink = $('#ptk-qf-has-link');
        var hasFileOrLink = $hasLink.length ? $hasLink.is(':checked') : false;

        var cameFromInput = $('input[name="ptk_came_from"]').val();

        return {
            came_from: cameFromInput || '',
            step_count: stepCount,
            has_date: hasDate,
            has_file_or_link: hasFileOrLink,
            question: $('#ptk-title').val() || '',
            answer: $('#ptk-answer').val() || ''
        };
    }

    function setTypeLine(slug) {
        if (typeof ptkEntryType === 'undefined') {
            return;
        }
        $('#ptk-qf-type-name').text(ptkEntryType.ptkEntryTypeName(slug));
        $('#ptk-qf-type-why').text(ptkEntryType.ptkEntryTypeExplain(slug));
        $('input[name="ptk_category"][value="' + slug + '"]').prop('checked', true);
    }

    function bindQuietTypeLine() {
        var $line = $('#ptk-qf-type-line');
        if (!$line.length || typeof ptkEntryType === 'undefined') {
            return;
        }

        var $locked = $('#ptk-type-locked');
        var $picker = $('#ptk-qf-type-picker');
        var $changeBtn = $('#ptk-qf-change-type');

        function recompute() {
            if ($locked.val() === '1') {
                return;
            }
            setTypeLine(ptkEntryType.ptkEntryTypeGuess(questionFirstSignals()));
        }

        // Only the fields this screen actually has feed the live guess --
        // harmless to bind selectors that don't exist on a given page.
        $(document).on('input', '#ptk-title, #ptk-answer', recompute);
        $(document).on('change',
            '#ptk-qf-has-steps, #ptk-qf-has-date, #ptk-qf-has-link, ' +
            'textarea[name="ptk_step_text[]"]',
            recompute
        );
        // New steps are added asynchronously (addStep()); catch typing in
        // ones that don't exist yet at bind time via delegation, and also
        // recompute right after "Add Step" in case two-steps-or-more just
        // became true from adding an empty one after a filled one.
        $(document).on('input', 'textarea[name="ptk_step_text[]"]', recompute);
        $(document).on('click', '.ptk-add-step', function () {
            setTimeout(recompute, 0);
        });

        if ($changeBtn.length && $picker.length) {
            $changeBtn.on('click', function () {
                // 4.17.1: the picker is hidden until asked for, so reveal it
                // as well as opening it.
                $picker.addClass('is-open').prop('open', true);
                var $firstRadio = $picker.find('input[type="radio"]').first();
                if ($firstRadio.length) {
                    $firstRadio.trigger('focus');
                }
            });
        }

        // Picking a card is the explicit, never-overridden choice (plan
        // Task 3): lock it in and reflect it in the line immediately. Bound
        // to both the radio's own 'change' (a real user click on the
        // label) and a 'click' on the card itself, since
        // bindCategorySelection() above sets the radio's checked state
        // with .prop() -- which does not always dispatch a 'change' event
        // -- and runs first (registered earlier), so by the time either of
        // these fires the radio is already the one the person picked.
        function lockToCard($card) {
            var slug = $card.find('input[type="radio"]').val();
            if (!slug) {
                return;
            }
            $locked.val('1');
            setTypeLine(slug);
        }
        $(document).on('change', '.ptk-qf-type-picker input[name="ptk_category"]', function () {
            lockToCard($(this).closest('.ptk-category-card'));
        });
        $(document).on('click', '.ptk-qf-type-picker .ptk-category-card', function () {
            lockToCard($(this));
        });
    }

    /* ──────────────────────────────────────────
     * 2026-09-18 spec "you write the answer itself": the four chips
     *
     * Each chip reveals its block *inside the card* and keeps one of the
     * hidden signal checkboxes (#ptk-qf-has-steps/-date/-link) in sync, so
     * the existing live-guess/validation code above (questionFirstSignals(),
     * bindQuietTypeLine()) keeps working completely unchanged -- it only
     * ever asked "is this checked?", never how the checkbox got there.
     * The picture chip is different: it opens the media library directly
     * (openMediaPicker(), unchanged) rather than revealing an empty block
     * first, since there's nothing to type -- picking a photo IS filling
     * in the block.
     * ────────────────────────────────────────── */

    function qfShowBlock(name) {
        $('#ptk-qf-' + name + '-block').removeAttr('hidden').show();
        $('.ptk-qf-chip[data-block="' + name + '"]').hide();
    }

    function qfHideBlock(name) {
        $('#ptk-qf-' + name + '-block').attr('hidden', 'hidden').hide();
        $('.ptk-qf-chip[data-block="' + name + '"]').show();
    }

    function bindQfChips() {
        if (!$('.ptk-qf-card').length) {
            return;
        }

        $(document).on('click', '.ptk-qf-chip', function () {
            var name = $(this).data('block');

            if (name === 'image') {
                openMediaPicker('ptk-featured-image', 'image');
                return;
            }

            qfShowBlock(name);

            if (name === 'steps') {
                $('#ptk-qf-has-steps').prop('checked', true).trigger('change');
                var $steps = $('#ptk-howto-steps');
                if ($steps.find('.ptk-repeater-item').length === 0) {
                    addStep($steps);
                } else {
                    $steps.find('textarea').first().trigger('focus');
                }
            } else if (name === 'date') {
                $('#ptk-qf-has-date').prop('checked', true).trigger('change');
                $('#ptk-event-date').trigger('focus');
            } else if (name === 'link') {
                $('#ptk-qf-has-link').prop('checked', true).trigger('change');
                $('#ptk-resource-url').trigger('focus');
            }
        });

        $(document).on('click', '.ptk-qf-block-remove', function () {
            var name = $(this).data('block');

            if (name === 'steps') {
                $('#ptk-howto-steps').empty();
                $('#ptk-qf-has-steps').prop('checked', false).trigger('change');
            } else if (name === 'date') {
                $('#ptk-event-date').val('');
                $('#ptk-qf-has-date').prop('checked', false).trigger('change');
            } else if (name === 'link') {
                $('#ptk-resource-url').val('');
                $('#ptk-resource-file-id').val('');
                $('#ptk-resource-file-preview').html('');
                $('#ptk-qf-has-link').prop('checked', false).trigger('change');
            } else if (name === 'image') {
                $('#ptk-featured-image-id').val('');
                $('#ptk-featured-image-preview').html('');
            }

            qfHideBlock(name);
        });

    }

    /* ──────────────────────────────────────────
     * The two submit buttons ("Put it on the Hub" / "Keep it to myself for
     * now") replace the old screen's "Save as:" radios -- each is a real
     * <button type="submit" name="ptk_status" value="...">, so a no-JS
     * visitor gets a working choice with no script at all. This only adds
     * the same "are you sure?" pause bindFormValidation() already gives
     * Publish on the old screen, keyed off which button was actually
     * clicked instead of a checked radio.
     * ────────────────────────────────────────── */

    var qfClickedButton = null;

    function bindQfSubmit() {
        $(document).on('click', '#ptk-qf-submit-publish, #ptk-qf-submit-draft', function () {
            qfClickedButton = this;
        });
    }

    /* ──────────────────────────────────────────
     * The answer textarea grows as you type instead of scrolling.
     * ────────────────────────────────────────── */

    function qfAutogrow($el) {
        $el.css('height', 'auto');
        $el.css('height', $el[0].scrollHeight + 'px');
    }

    function bindQfAnswerAutogrow() {
        var $answer = $('#ptk-answer');
        if (!$answer.length || !$answer.hasClass('ptk-qf-answer-input')) {
            return;
        }
        $answer.on('input', function () { qfAutogrow($(this)); });
        qfAutogrow($answer);
    }

    /* ──────────────────────────────────────────
     * The one quiet line: "Families can already read: X — open it
     * instead?" Same ptk_wizard_related AJAX action and localized
     * ptkWizardRelated data as the old screen's related-entries panel
     * (wizard-related.js), but that script targets #ptk-wizard-related --
     * a floating box the new card intentionally has no room for -- so this
     * is a small, separate binding that shows only the single best match
     * as one line under the card, never a list.
     * ────────────────────────────────────────── */

    function bindQfSimilar() {
        var $line = $('#ptk-qf-similar');
        if (!$line.length || typeof ptkWizardRelated === 'undefined') {
            return;
        }
        var $title = $('#ptk-title');
        var timer = null;
        var lastQuery = '';

        function check() {
            var title = $.trim($title.val() || '');
            var category = $('input[name="ptk_category"]:checked').val() || '';
            if (title.length < 3 || !category) {
                $line.hide();
                return;
            }
            if (title === lastQuery) {
                return;
            }
            lastQuery = title;

            var data = new FormData();
            data.append('action', 'ptk_wizard_related');
            data.append('_wpnonce', ptkWizardRelated.nonce);
            data.append('title', title);
            data.append('edit_id', 0);

            fetch(ptkWizardRelated.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    var items = (json && json.success) ? (json.data || []) : [];
                    if (!items.length) {
                        $line.hide();
                        return;
                    }
                    var item = items[0];
                    $line.empty();
                    $line.append(document.createTextNode('Families can already read: '));
                    $line.append($('<a>', { href: item.permalink, target: '_blank', rel: 'noopener' }).text(item.title));
                    $line.append(document.createTextNode(' — open it instead?'));
                    $line.show();
                })
                .catch(function () { $line.hide(); });
        }

        $title.on('blur', check);
        $title.on('input', function () {
            clearTimeout(timer);
            timer = setTimeout(check, 400);
        });
    }

    /* ──────────────────────────────────────────
     * Phase 4, task 2: folding steps in the Hub's look
     *
     * PTK_Content_Wizard::fold_class() adds .ptk-wizard-fold to each of the
     * wizard's four steps (server-side, new look only). Here we toggle
     * .is-collapsed on every one of them except the "current" step -- the
     * one nearest the top of the viewport -- and fill in its one-line
     * summary. This never touches which step is actually visible
     * (.ptk-hidden, controlled by bindCategorySelection() above,
     * unchanged); it only decides how much of an already-visible step
     * shows. Runs only when body carries .ptk-hub-look -- with the new
     * look off these functions are still defined but every entry point
     * below returns immediately, so nothing about the legacy reveal-all
     * behavior changes.
     * ────────────────────────────────────────── */

    function hubLookActive() {
        return document.body.className.indexOf('ptk-hub-look') !== -1;
    }

    /**
     * The one-line summary a collapsed step shows. Purely a readout of the
     * form's current values -- the wizard never posts back between steps,
     * so this has to be computed client-side from whatever the volunteer
     * has typed so far.
     */
    function foldSummaryFor($fold) {
        var id = $fold.attr('id');

        if (id === 'ptk-step-category') {
            var $name = $('.ptk-category-card.ptk-card-selected .ptk-card-name');
            return $name.length ? 'Category: ' + $.trim($name.text()) : 'Nothing yet';
        }
        if (id === 'ptk-step-basics') {
            var title = $.trim($('#ptk-title').val() || '');
            return title ? 'Title: ' + title : 'Nothing yet';
        }
        if (id === 'ptk-step-links') {
            var linkCount = $fold.find('.ptk-repeater-item').length;
            return linkCount > 0 ? (linkCount === 1 ? '1 link added' : linkCount + ' links added') : 'Nothing yet';
        }

        // The category's own fields step (one of the seven ptk-form-*
        // sections): count how many of its own fields have something in
        // them. Generic on purpose -- it works the same for every category
        // without a per-category rule to keep in sync.
        var filled = 0;
        $fold.find('input[type="text"], input[type="url"], input[type="date"], textarea, select').each(function () {
            var v = $(this).val();
            if (v && $.trim(String(v)) !== '') {
                filled++;
            }
        });
        return filled > 0 ? (filled === 1 ? '1 field filled in' : filled + ' fields filled in') : 'Nothing yet';
    }

    /**
     * Recompute which visible fold is "current" (open) and collapse the
     * rest, with a fresh summary on each one that just closed.
     */
    function updateFolds() {
        if (!hubLookActive()) {
            return;
        }

        var $folds = $([]).filter(function () {
            return !$(this).hasClass('ptk-hidden');
        });
        if ($folds.length === 0) {
            return;
        }

        // The step nearest the top of the viewport (within a small
        // threshold) is the one the volunteer is looking at right now;
        // everything above it folds shut.
        var threshold = 140;
        var scrollTop = $(window).scrollTop();
        var $current = $folds.first();
        $folds.each(function () {
            var top = $(this).offset().top - scrollTop;
            if (top <= threshold) {
                $current = $(this);
            }
        });

        $folds.each(function () {
            var $f = $(this);
            var isCurrent = $f.is($current);
            $f.toggleClass('is-collapsed', !isCurrent);
            if (!isCurrent) {
                $f.find('.ptk-wizard-fold-meta').first().text(foldSummaryFor($f));
            }
        });
    }

    var foldScrollTimer = null;

    function bindFolding() {
        if (!hubLookActive()) {
            return;
        }

        $(window).on('scroll', function () {
            if (foldScrollTimer) {
                return;
            }
            foldScrollTimer = setTimeout(function () {
                foldScrollTimer = null;
                updateFolds();
            }, 100);
        });

        // A collapsed step's heading is clickable: scroll it back into
        // view, where the scroll handler above reopens it.
        $(document).on('click', '.ptk-wizard-fold.is-collapsed > .ptk-wizard-section-title', function () {
            var top = $(this).closest('.ptk-wizard-fold').offset().top - 90;
            if (REDUCE_MOTION) {
                window.scrollTo(0, top);
            } else {
                $('html, body').animate({ scrollTop: top }, 200);
            }
        });

        // Re-check on every keystroke in the title field and blur of any
        // wizard field, so a folded step's summary stays current even
        // without a scroll.
        $(document).on('blur', '.ptk-wizard-fold input, .ptk-wizard-fold textarea, .ptk-wizard-fold select', function () {
            updateFolds();
        });

        updateFolds();
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

            // The "you write the answer itself" card (2026-09-18 spec)
            // shows the picture the way a parent will see it, not a small
            // upload-widget thumbnail -- its own CSS
            // (.ptk-qf-block-image .ptk-image-preview img) sizes it, so
            // skip the fixed inline size here and reveal the block + hide
            // the "+ a picture" chip. Everywhere else (the old screen,
            // both looks) keeps its usual small inline-sized thumbnail,
            // unchanged.
            var isQfImage = target === 'ptk-featured-image' && $('#ptk-qf-image-block').length > 0;

            $('#' + target + '-id').val(attachment.id);
            if (isQfImage) {
                $('#' + target + '-preview').html(
                    '<img src="' + thumbUrl + '" alt="">'
                );
                qfShowBlock('image');
            } else {
                $('#' + target + '-preview').html(
                    '<img src="' + thumbUrl + '" alt="" style="max-width:150px;max-height:150px;border-radius:4px;">'
                );
            }
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
                // The question-first screen (Task 2) has no
                // #ptk-faq-short-answer field -- it has one generic
                // #ptk-answer field instead, mapped onto the right
                // category-specific field server-side. Fall back to it
                // when the category-specific field isn't on the page.
                var $faqField = $('#ptk-faq-short-answer').length ? $('#ptk-faq-short-answer') : $('#ptk-answer');
                if (!$faqField.val().trim()) {
                    e.preventDefault();
                    alert('Please provide a Quick Answer for your FAQ entry.');
                    $faqField.focus();
                    return false;
                }
            } else if (category === 'resource') {
                var $resField = $('#ptk-resource-desc').length ? $('#ptk-resource-desc') : $('#ptk-answer');
                if (!$resField.val().trim()) {
                    e.preventDefault();
                    alert('Please provide a Description for your Resource.');
                    $resField.focus();
                    return false;
                }
            } else if (category === 'glossary') {
                var $glField = $('#ptk-glossary-definition').length ? $('#ptk-glossary-definition') : $('#ptk-answer');
                if (!$glField.val().trim()) {
                    e.preventDefault();
                    alert('Please provide a Definition for your Glossary Term.');
                    $glField.focus();
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
                // The question-first screen derives checklist items from
                // dash-prefixed lines in #ptk-answer server-side (no
                // ptk_checklist_item[] inputs on the page at all) -- accept
                // any non-empty answer there too.
                if (!hasItem && $('input[name="ptk_checklist_item[]"]').length === 0 && $('#ptk-answer').val() && $('#ptk-answer').val().trim()) {
                    hasItem = true;
                }
                if (!hasItem) {
                    e.preventDefault();
                    alert('Please add at least one checklist item.');
                    return false;
                }
            } else if (category === 'policy') {
                var $polField = $('#ptk-policy-summary').length ? $('#ptk-policy-summary') : $('#ptk-answer');
                if (!$polField.val().trim()) {
                    e.preventDefault();
                    alert('Please provide a Summary for your Policy entry.');
                    $polField.focus();
                    return false;
                }
            }

            // Publishing is immediate and site-wide — give one plain-language
            // moment of pause (Draft, the default, needs none). The "you
            // write the answer itself" card (2026-09-18 spec) has two real
            // submit buttons instead of a "Save as:" radio -- qfClickedButton
            // (set by bindQfSubmit()) says which one was actually clicked;
            // falls back to the old radio for the category-first screen.
            var chosenStatus = qfClickedButton ? $(qfClickedButton).val() : $('input[name="ptk_status"]:checked').val();
            // 4.17.1: no confirm dialog on the new-look card. The screen
            // already says "Nobody sees it until you do", the button says
            // exactly what it does, and the confirmation afterwards offers a
            // way back -- per the design rules, undo beats "are you sure?".
            if (chosenStatus === 'publish' && !$('body').hasClass('ptk-hub-look')) {
                var publishMsg = isEdit
                    ? 'Publish these changes now? They will be visible to everyone right away.'
                    : 'Put this on the Hub now? Families will be able to see it right away. Choose Cancel to go back (you can pick "Keep it to myself for now" instead).';
                if (!window.confirm(publishMsg)) {
                    e.preventDefault();
                    return false;
                }
            }

            // Disable submit button(s) to prevent double-submit -- deferred
            // to the next tick. A disabled form control is not "successful"
            // (HTML forms spec), so disabling the just-clicked button
            // SYNCHRONOUSLY, inside this submit handler, drops its own
            // name=ptk_status/value=... pair from the very submission it
            // just triggered -- $_POST['ptk_status'] then arrives empty and
            // handle_submission() silently falls back to 'draft' regardless
            // of which button was clicked. setTimeout(..., 0) disables
            // (and relabels) the button only after the browser has already
            // captured the form's data for this submission.
            if (qfClickedButton) {
                var $clicked = $(qfClickedButton);
                var clickedLabel = chosenStatus === 'publish' ? 'Putting it on the Hub…' : 'Saving…';
                setTimeout(function () {
                    $('#ptk-qf-submit-publish, #ptk-qf-submit-draft').prop('disabled', true);
                    $clicked.text(clickedLabel);
                }, 0);
            } else {
                var btnText = isEdit ? 'Updating...' : 'Creating...';
                setTimeout(function () {
                    $('#ptk-wizard-submit-btn').prop('disabled', true).text(btnText);
                }, 0);
            }
        });
    }

    /* ──────────────────────────────────────────
     * Edit Mode: Pre-fill form with existing data
     * ────────────────────────────────────────── */

    /**
     * Open the form for an entry that is already filled in (editing one, or
     * restoring an autosave).
     *
     * Clicking a .ptk-category-card is what reveals every field below it.
     * An entry saved with no category at all -- made straight in WordPress,
     * imported, or converted from something -- has no category to click,
     * so the whole form stayed display:none and the volunteer met their own
     * title sitting in an input of zero height.
     *
     * In the Hub's look the screen has already decided what to call such an
     * entry ("This is filed as a FAQ") and checked that radio, so fall back
     * to whatever is checked and open the form on that. With the look off
     * nothing is checked for an uncategorized entry, so this finds nothing
     * and the screen behaves exactly as it always has.
     */
    function openFormFor(category) {
        if (!category) {
            category = $('input[name="ptk_category"]:checked').val() || '';
        }
        if (!category) {
            return;
        }

        var $card = $('.ptk-category-card[data-category="' + category + '"]');
        if ($card.length) {
            $card.trigger('click');
            return;
        }

        // No card to click (they are behind "Change that"): do what the
        // click handler would have done.
        $('input[name="ptk_category"][value="' + category + '"]').prop('checked', true);
        revealStep($('#ptk-step-basics'));
        $('.ptk-category-form').addClass('ptk-hidden').hide();
        revealStep($('#ptk-form-' + category));
        revealStep($('#ptk-step-links'));
        revealStep($('#ptk-step-submit'));
    }

    function restoreEditData(data) {
        isRestoring = true;

        // Don't restore autosave when editing.
        clearAutosave();

        // Select category.
        openFormFor(data.category);

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
        openFormFor(data.category);

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
