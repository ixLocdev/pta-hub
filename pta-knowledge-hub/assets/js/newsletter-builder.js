/**
 * PTA Knowledge Hub — Newsletter Builder
 *
 * Makes the guided newsletter form (rendered server-side by
 * class-newsletter-builder.php) interactive:
 *
 *   - Prefills the form from `ptkNlData.blocks` (edit mode; harmless no-op
 *     for a brand-new newsletter, whose blocks are already empty).
 *   - Lets volunteers add/remove repeatable rows (events, story cards,
 *     footer links) and pick images via the WP media library.
 *   - Builds step 4's arrange list from the live sections, so volunteers can
 *     drag (or use Move up/down) to order the newsletter, take a section out
 *     without losing what they typed in it, and add it back — never touching
 *     the pinned header/footer.
 *   - Keeps the hidden #ptk-nl-blocks-json field in sync with the DOM on
 *     every change, and once more on submit, so the PHP save handler
 *     always receives a current, well-formed blocks array. (PHP
 *     re-sanitizes everything server-side — this JS only needs to produce
 *     well-formed structure, not sanitize values.)
 *
 * DOM contract (see class-newsletter-builder.php render_page()):
 *   #ptk-nl-blocks > section.ptk-nl-block[data-type][data-pinned]
 *     - single-instance fields: [data-field] inputs/textareas directly in
 *       the section (not inside a repeatable rows container).
 *     - repeatable collections: div.ptk-nl-rows[data-rows][data-rows-for]
 *       + button.ptk-nl-add + template[data-row-template] (one row's
 *       markup, fields with no static id).
 *   #ptk-nl-blocks-json — hidden input the save handler reads.
 */
(function ($) {
    'use strict';

    // Unique ids handed out to cloned row fields so their <label for> can
    // point at them (accessibility). Template fields ship with no id to
    // avoid collisions between rows, so this file owns id assignment.
    var uidCounter = 0;

    // One reusable wp.media frame for the whole session (created lazily on
    // first use). Recreating it per click leaks detached frame views, so we
    // keep a single instance and just re-point it at whichever image field
    // was clicked via mediaTargetField.
    var mediaFrame = null;
    var mediaTargetField = null;

    // The step currently shown in the wizard (1-based). Kept in a
    // module-scoped var rather than read back from the DOM so showStep()
    // has a single source of truth for "where we're coming from" (e.g.
    // which sidebar button currently owns aria-current).
    var currentStep = 1;
    var FIRST_STEP = 1;
    // Round 3.1 (spec item 5): the old step 4 "Finish & publish" is now two
    // steps -- 4 "Finish editing" (order + the footer) and 5 "Publish &
    // share" (photo check, Save/Publish/Update, sharing).
    var LAST_STEP = 5;

    // Live preview. The iframe is a REAL 840px-wide viewport — the width the
    // newsletter design is built for — so the design's clamp(..., Nvw, ...)
    // type resolves exactly as it will in a family's inbox/browser. It is
    // then scaled down to whatever width the preview column has. Injecting
    // the HTML into this page instead would resolve vw against the admin
    // window and quietly lie about the type.
    var PREVIEW_WIDTH = 840;
    var PREVIEW_DEBOUNCE = 400;
    var previewTimer = null;
    var resizeTimer = null;

    // Every request gets a number; only the newest one's reply is allowed to
    // touch the iframe. Without this, two edits in quick succession whose
    // replies land out of order would leave the older HTML on screen.
    var previewSeq = 0;

    // Block types currently outlined in the preview (the step you're on, or
    // the field you're in). Re-applied after every refresh because the iframe
    // document is rebuilt from scratch each time.
    var highlightTypes = [];

    // Injected into the preview document, not the newsletter HTML — the
    // outline is a builder affordance and never reaches what's published.
    var PREVIEW_STYLE =
        '.ptk-nl-hi{outline:3px solid #2271b1;outline-offset:-3px;}';

    /* ──────────────────────────────────────────
     * Boot
     * ────────────────────────────────────────── */

    $(function () {
        prefillFromData();
        bindAddRow();
        bindRemoveRow();
        bindImagePicker();
        bindSerializeTriggers();
        bindStepNav();
        bindArrangeList();
        bindArrangeSortable();
        bindPreviewTriggers();

        renderArrangeList();
        serialize();

        // Prefill first so step 1's fields are already populated before
        // anything is shown/hidden. No focus move on boot: focus belongs at
        // the top of the document, where the admin notices are (see
        // showStep()'s moveFocus param).
        // startStep arrives as the STRING "4" (wp_localize_script casts
        // scalars), hence parseInt. Guarded like hasData(): a missing
        // ptkNlData must never throw here and leave the wizard inert.
        showStep((typeof ptkNlData !== 'undefined' && ptkNlData && parseInt(ptkNlData.startStep, 10)) || FIRST_STEP, false);

        // Straight away, not debounced: the preview column must never sit
        // blank while a volunteer wonders whether it's broken.
        refreshPreview();

        // Added in 4.1.1. LAST, and each one fenced off on its own: a throw
        // above this line would leave the whole wizard inert, and a throw in
        // one of these must not take the others down with it.
        safeBoot(bindCopyButtons);
        safeBoot(bindPreviewLinks);
        safeBoot(bindUnsavedGuard);
        safeBoot(focusConsentIfRefused);
        // Round 3.1 (spec items 7 & 8): replaces the old native-validation
        // "reveal the step, then rely on the browser" approach, which is
        // exactly what made the first Publish click silently fail -- see
        // bindValidation()'s docblock.
        safeBoot(bindValidation);
        // Added in 4.2.0, fenced off the same way.
        safeBoot(openFilledDisclosures);
        // Added in 4.4.0 (round 3): an existing "crop" photo shows its
        // picker immediately, with no click required.
        safeBoot(initFocalPickers);
    });

    /**
     * A closed "Add dates" disclosure hiding rows that were saved would look
     * like the dates had vanished. Open any disclosure that has rows in it.
     */
    function openFilledDisclosures() {
        $('[data-disclosure]').each(function () {
            if ($(this).find('[data-rows] > [data-row]').length) {
                this.open = true;
            }
        });
    }

    /* ──────────────────────────────────────────
     * Visible validation (round 3.1, spec items 7 & 8)
     *
     * ROOT CAUSE OF "the first Publish click doesn't work" (item 8,
     * reproduced in Playground before this fix): the form relied on the
     * BROWSER's own constraint validation (the issue number's `required`,
     * and `type="url"` on the featured/story-card/footer link fields). A
     * browser blocks submission when any field fails that check -- but it
     * can only show its native red-outline/tooltip on a field that is
     * VISIBLE, and every step but the current one is `display:none`. So
     * when an invalid field sat on a step the volunteer wasn't looking at
     * (a required issue number back on step 1, or a link typed without
     * https:// on step 3), clicking Publish on the last step:
     *   1. The browser silently refused to submit (no visible error --
     *      it cannot show a tooltip on a hidden element).
     *   2. The OLD `invalid` handler (removed here) switched to that
     *      field's step, but never resubmitted.
     * So the newsletter simply didn't save, with no visible reason, and
     * the volunteer had to click Publish a SECOND time -- now that the
     * field was on a visible step, the browser could finally do its job
     * (or the value turned out fine and nothing had actually been wrong
     * except its visibility). That exactly matches "first click doesn't
     * work, second one does."
     *
     * FIX: the form now carries `novalidate` (see class-newsletter-
     * builder.php), so the browser's own validation never runs at all.
     * This file owns validation completely instead: on submit, check every
     * `[data-validate]` field plus the required issue number, mark each
     * invalid one in place (red outline, inline message, its section's
     * heading flagged), jump to the FIRST invalid field's step and focus
     * it, and show a summary at the top of "Publish & share". Nothing here
     * blocks a valid submission, and a fixed field clears its own error the
     * moment it's fixed.
     * ────────────────────────────────────────── */

    var VALIDATE_MESSAGES = {
        required: 'Please enter the issue number for this newsletter.',
        link: 'This link needs to start with https:// — paste the full address.',
        'link-or-email': 'This needs to be a web address starting with https://, or an email address.'
    };

    /**
     * Whether one field is currently valid. Empty is always valid for an
     * optional (non-required) field -- only a field with something typed
     * into it that doesn't look right is flagged.
     *
     * @param {jQuery} $field
     * @return {boolean}
     */
    function fieldIsValid($field) {
        if ($field.is('[name="ptk_nl_issue"]')) {
            return !$field.prop('required') || '' !== $.trim($field.val());
        }
        var kind = $field.attr('data-validate');
        if (!kind) {
            return true;
        }
        var value = $.trim($field.val());
        if ('' === value) {
            return true; // Every data-validate field here is optional.
        }
        if (typeof window.ptkNlLooksLikeLinkOrEmail !== 'function') {
            return true; // Validator script failed to load -- never block on that.
        }
        return 'link-or-email' === kind
            ? window.ptkNlLooksLikeLinkOrEmail(value)
            : window.ptkNlLooksLikeUrl(value);
    }

    /** The plain-English message for one field's current problem. */
    function fieldMessage($field) {
        if ($field.is('[name="ptk_nl_issue"]')) {
            return VALIDATE_MESSAGES.required;
        }
        var kind = $field.attr('data-validate');
        return VALIDATE_MESSAGES[kind] || 'Please check this field.';
    }

    /** Give a field a stable id if it doesn't have one (for aria-describedby). */
    function ensureId($field) {
        var id = $field.attr('id');
        if (id) {
            return id;
        }
        uidCounter++;
        id = 'ptk-nl-dyn-' + uidCounter;
        $field.attr('id', id);
        return id;
    }

    /** Mark one field invalid: full red outline, inline message, heading flagged. */
    function markFieldInvalid($field) {
        var id = ensureId($field);
        var msgId = id + '-error';

        $field.addClass('ptk-nl-field-invalid').attr('aria-invalid', 'true');
        addDescribedBy($field, msgId);

        var $msg = $field.nextAll('.ptk-nl-field-error-msg').first();
        if (!$msg.length || $msg.attr('id') !== msgId) {
            $msg = $('<p class="ptk-nl-field-error-msg" role="alert"></p>').attr('id', msgId);
            $field.after($msg);
        }
        $msg.text(fieldMessage($field));

        var $section = $field.closest('.ptk-nl-block');
        $section.find('.ptk-nl-block-header h3').first().addClass('ptk-nl-heading-invalid');
    }

    /** Clear one field's invalid state, and its section heading's flag if nothing else in it is invalid. */
    function clearFieldInvalid($field) {
        var id = $field.attr('id');
        $field.removeClass('ptk-nl-field-invalid').removeAttr('aria-invalid');
        if (id) {
            $('#' + id + '-error').remove();
        }

        var $section = $field.closest('.ptk-nl-block');
        if (!$section.find('.ptk-nl-field-invalid').length) {
            $section.find('.ptk-nl-block-header h3').first().removeClass('ptk-nl-heading-invalid');
        }
    }

    /** Every field this page knows how to validate. */
    function validatableFields() {
        var $fields = $('[data-validate]');
        var $issue = $('[name="ptk_nl_issue"]');
        return $issue.length ? $fields.add($issue) : $fields;
    }

    /**
     * Run every validator, mark each invalid field, and return the list of
     * problems found (each: {$field, step, label}), in document order.
     * Fields inside an excluded (left-out) section are skipped -- they
     * aren't part of what gets saved.
     */
    function runValidation() {
        var problems = [];

        validatableFields().each(function () {
            var $field = $(this);
            if ($field.closest('[data-excluded]').length) {
                clearFieldInvalid($field);
                return;
            }
            if (fieldIsValid($field)) {
                clearFieldInvalid($field);
                return;
            }
            markFieldInvalid($field);
            var $section = $field.closest('[data-step]');
            var step = parseInt($section.attr('data-step'), 10);
            problems.push({
                $field: $field,
                step: isNaN(step) ? currentStep : step,
                label: sectionLabel($section.hasClass('ptk-nl-block') ? $section : $field.closest('.ptk-nl-block'))
            });
        });

        return problems;
    }

    /** Build/update/clear the "N things need fixing" summary atop "Publish & share". */
    function renderValidationSummary(problems) {
        var $box = $('#ptk-nl-validation-summary');
        if (!$box.length) {
            return;
        }
        if (!problems.length) {
            $box.attr('hidden', true).empty();
            return;
        }

        var count = problems.length;
        var $list = $('<ul></ul>');
        problems.forEach(function (problem, i) {
            var id = ensureId(problem.$field);
            var $li = $('<li></li>');
            var $link = $('<a href="#"></a>')
                .text(problem.label + ': ' + fieldMessage(problem.$field))
                .on('click', function (e) {
                    e.preventDefault();
                    showStep(problem.step, false);
                    $('#' + id).trigger('focus');
                });
            $li.append($link);
            $list.append($li);
            void i;
        });

        $box.empty()
            .append($('<p></p>').text(count === 1 ? '1 thing needs fixing:' : count + ' things need fixing:'))
            .append($list)
            .removeAttr('hidden');
    }

    /**
     * Own the form's validation completely (see the block comment above):
     * run on submit, block an invalid submission, jump to and focus the
     * first problem, and clear each field's own error the moment it's
     * fixed.
     */
    function bindValidation() {
        var $form = $('#ptk-nl-form');
        if (!$form.length) {
            return;
        }

        $form.on('submit', function (e) {
            serialize();
            var problems = runValidation();
            renderValidationSummary(problems);

            if (!problems.length) {
                return; // Let the real submit through.
            }

            e.preventDefault();
            showStep(problems[0].step, false);
            problems[0].$field.trigger('focus');
        });

        // Errors clear themselves the moment the field is fixed -- no need
        // to press Save/Publish again to find out.
        $(document).on('input change', '[data-validate], [name="ptk_nl_issue"]', function () {
            var $field = $(this);
            if (fieldIsValid($field)) {
                clearFieldInvalid($field);
            }
        });
    }

    /**
     * Publishing was turned into a draft because the photo check wasn't
     * ticked: move focus to that checkbox, where the explanation sits.
     */
    function focusConsentIfRefused() {
        if (typeof ptkNlData === 'undefined' || !ptkNlData || !parseInt(ptkNlData.focusConsent, 10)) {
            return;
        }
        var box = document.getElementById('ptk-nl-pii-ok');
        if (box && !box.closest('[hidden]')) {
            box.focus();
        }
    }

    /**
     * Show the photo check only while the newsletter has a photo in it --
     * the same rule the server uses (PTK_Newsletter_Data::blocks_have_images).
     * Uses the `hidden` attribute, not jQuery .toggle(), and the gate carries
     * no data-step, so showStep() and this never fight.
     */
    function updatePhotoCheck(blocks) {
        var gate = document.querySelector('[data-pii-gate]');
        if (!gate) {
            return;
        }
        var has = false;
        for (var i = 0; i < blocks.length && !has; i++) {
            var data = blocks[i].data || {};
            for (var key in data) {
                if (!Object.prototype.hasOwnProperty.call(data, key)) {
                    continue;
                }
                if (key === 'image_id' && parseInt(data[key], 10) > 0) {
                    has = true;
                } else if (Array.isArray(data[key])) {
                    for (var j = 0; j < data[key].length; j++) {
                        if (data[key][j] && parseInt(data[key][j].image_id, 10) > 0) {
                            has = true;
                        }
                    }
                }
            }
        }
        gate.hidden = !has;
    }

    /** Run one optional boot step; log a failure instead of throwing it. */
    function safeBoot(fn) {
        try {
            fn();
        } catch (err) {
            if (window.console && window.console.error) {
                window.console.error('Newsletter builder: part of the page failed to start:', err);
            }
        }
    }

    /* ──────────────────────────────────────────
     * Copy buttons: <button data-ptk-copy="#selector"> copies that field's
     * value (or, for a link, its href).
     * ────────────────────────────────────────── */

    function bindCopyButtons() {
        $(document).on('click', '[data-ptk-copy]', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $source = $($button.attr('data-ptk-copy')).first();
            if (!$source.length) {
                return;
            }
            var text = $source.is('a') ? $source.attr('href') : $source.val();
            var label = $button.data('ptkCopyLabel') || $button.text();
            $button.data('ptkCopyLabel', label);

            function done(ok) {
                $button.text(ok ? 'Copied!' : 'Press Ctrl+C (or \u2318C) to copy');
                setTimeout(function () {
                    $button.text(label);
                }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    done(true);
                }, function () {
                    done(fallbackCopy($source, text));
                });
            } else {
                done(fallbackCopy($source, text));
            }
        });
    }

    /** Older browsers / non-secure pages: select the text and copy that. */
    function fallbackCopy($source, text) {
        try {
            if ($source.is('input, textarea')) {
                $source.trigger('focus').trigger('select');
                return document.execCommand('copy');
            }
            var $tmp = $('<textarea readonly style="position:absolute;left:-9999px;"></textarea>').val(text).appendTo('body');
            $tmp.trigger('select');
            var ok = document.execCommand('copy');
            $tmp.remove();
            return ok;
        } catch (err) {
            return false;
        }
    }

    /* ──────────────────────────────────────────
     * Preview links, without reloading the page.
     *
     * The panel's Create / Stop sharing controls are real <form>s that post
     * to admin.php (they still work without JavaScript). Here they become
     * AJAX calls that swap the panel's body in place, so nothing typed into
     * the newsletter is lost and the volunteer stays on step 4.
     * ────────────────────────────────────────── */

    function bindPreviewLinks() {
        if (typeof ptkNlData === 'undefined' || !ptkNlData || !ptkNlData.ajaxUrl || !ptkNlData.previewLinkNonce) {
            return; // Leave the plain forms to do their job.
        }

        $(document).on('submit', '[data-preview-link-action]', function (e) {
            var $form = $(this);
            var $panel = $form.closest('[data-preview-link-panel]');
            var postId = parseInt($panel.attr('data-post-id'), 10) || 0;
            if (!postId) {
                return; // Let the form post normally.
            }
            e.preventDefault();

            var mode = $form.attr('data-preview-link-action');
            var $status = $panel.find('[data-preview-link-status]');
            var $button = $form.find('button[type="submit"]');

            $button.prop('disabled', true);
            $status.text(mode === 'generate' ? 'Making a link\u2026' : 'Stopping the link\u2026');

            $.post(ptkNlData.ajaxUrl, {
                action: 'ptk_nl_preview_link',
                nonce: ptkNlData.previewLinkNonce,
                post_id: postId,
                mode: mode
            }).done(function (response) {
                if (!response || !response.success || !response.data || typeof response.data.html !== 'string') {
                    $button.prop('disabled', false);
                    $status.text((response && response.data && response.data.message) || 'That didn\u2019t work. Please try again.');
                    return;
                }
                $panel.find('[data-preview-link-body]').html(response.data.html);
                $status.text(response.data.message || '');

                // Keep keyboard users where the action happened.
                var $focus = $panel.find('#ptk-nl-preview-url');
                if (!$focus.length) {
                    $focus = $panel.find('[data-preview-link-body] button').first();
                }
                $focus.trigger('focus');
            }).fail(function (xhr) {
                $button.prop('disabled', false);
                var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                $status.text(msg || 'That didn\u2019t work \u2014 check your connection and try again. Nothing you typed was lost.');
            });
        });
    }

    /* ──────────────────────────────────────────
     * Unsaved-changes guard.
     *
     * The newsletter is only saved when a Save / Publish / Update button is
     * pressed, so leaving the page any other way throws edits away. Warn
     * first -- but only when something really changed. "Changed" means the
     * newsletter as it would be saved (blocks, issue, date) differs from how
     * it loaded, so typing something and deleting it again doesn't nag.
     * The share panel saves itself and is not part of this.
     * ────────────────────────────────────────── */

    function bindUnsavedGuard() {
        var $form = $('#ptk-nl-form');
        if (!$form.length) {
            return;
        }

        var baseline = formState();
        var submitting = false;
        var dirty = false;

        // A cheap flag on input; the snapshot comparison on leaving is what
        // decides.
        $form.on('input change', function () {
            dirty = true;
        });
        $(document).on('click', '.ptk-nl-add, .ptk-nl-remove-row, .ptk-nl-add-image, .ptk-nl-remove-image, .ptk-nl-arr-up, .ptk-nl-arr-down, .ptk-nl-arr-remove, .ptk-nl-arr-addback', function () {
            dirty = true;
        });
        $(document).on('sortstop', '[data-arrange]', function () {
            dirty = true;
        });

        // A real save is leaving on purpose.
        $form.on('submit', function () {
            submitting = true;
        });

        window.addEventListener('beforeunload', function (e) {
            if (submitting || !dirty || formState() === baseline) {
                return undefined;
            }
            e.preventDefault();
            e.returnValue = '';
            return '';
        });
    }

    /** Everything a save would send, as one comparable string. */
    function formState() {
        serialize();
        return [
            $('#ptk-nl-blocks-json').val(),
            $('[name="ptk_nl_issue"]').val(),
            $('[name="ptk_nl_date"]').val()
        ].join('\u0000');
    }

    /* ──────────────────────────────────────────
     * Wizard step navigation
     * ────────────────────────────────────────── */

    /**
     * Show step `n` of the wizard and hide every other step, everywhere in
     * the wizard (sidebar current-step marker, step heading, block
     * sections, the arrange panel, the finish/publish step, and the
     * preview-link panel). The live preview column (`.ptk-nl-preview`) carries no
     * data-step and is therefore never touched here — it stays visible on
     * every step by construction.
     *
     * Visibility rule: an element is shown iff it is `[data-step="n"]`
     * AND is NOT also `[data-excluded]`. A section that was excluded from
     * this newsletter still carries its type's step (e.g. an excluded
     * Events block keeps data-step="2") so it can be re-included later —
     * without the exclusion check it would reappear, blank, on that step.
     *
     * @param {number} n Step to show (1-based).
     * @param {boolean} moveFocus Whether to pull focus to the new step's
     *   heading. True for real navigation (the volunteer asked to move, so
     *   they should land on what they asked for); false on first load,
     *   where focus belongs at the top of the document — moving it here
     *   scrolls past the WP admin notices, including the one explaining
     *   that an unchecked photo/privacy box downgraded a publish to a
     *   draft.
     */
    function showStep(n, moveFocus) {
        n = Math.min(LAST_STEP, Math.max(FIRST_STEP, n));
        currentStep = n;

        var $wizard = $('.ptk-nl-wizard');

        $wizard.find('[data-step]').each(function () {
            var $el = $(this);
            var isCurrent = parseInt($el.attr('data-step'), 10) === n;
            var isExcluded = $el.is('[data-excluded]');
            $el.toggle(isCurrent && !isExcluded);
        });

        // Sidebar: move aria-current, don't duplicate it.
        var $stepLinks = $wizard.find('[data-goto-step]');
        $stepLinks.removeAttr('aria-current').removeClass('is-active');
        $stepLinks.filter('[data-goto-step="' + n + '"]')
            .attr('aria-current', 'step')
            .addClass('is-active');

        // Back/Next: hide the ends of the road rather than leaving a
        // control that can't do anything.
        $wizard.find('[data-step-nav="prev"]').toggle(n !== FIRST_STEP);
        $wizard.find('[data-step-nav="next"]').toggle(n !== LAST_STEP);

        // Arriving at the finish step: rebuild the arrange list so it shows
        // the order as it stands right now.
        if (n === LAST_STEP) {
            renderArrangeList();
        }

        // Point the preview at what this step is about, so the connection
        // between "the form here" and "the newsletter there" is visible
        // rather than something the volunteer has to work out.
        setHighlight(typesForStep(n));

        // Focus management: land keyboard/screen-reader users on the new
        // step's heading so they know where they ended up. Must stay after
        // the toggle loop above — focusing a hidden element no-ops.
        if (moveFocus) {
            $wizard.find('.ptk-nl-step-head[data-step="' + n + '"] h2').first().focus();
        }
    }

    /**
     * Wire the sidebar "jump to step" buttons and the Back/Next controls.
     * Steps are never locked — a weekly editor must be able to jump
     * straight to step 4 without walking through 2 and 3 first.
     */
    function bindStepNav() {
        $(document).on('click', '[data-goto-step]', function (e) {
            e.preventDefault();
            var target = parseInt($(this).attr('data-goto-step'), 10);
            if (!isNaN(target)) {
                showStep(target, true);
            }
        });

        $(document).on('click', '[data-step-nav]', function (e) {
            e.preventDefault();
            var dir = $(this).attr('data-step-nav') === 'prev' ? -1 : 1;
            showStep(currentStep + dir, true);
        });
    }

    /* ──────────────────────────────────────────
     * Live preview
     *
     * The newsletter builds itself beside the form: every edit re-renders
     * it through the REAL renderer server-side (see
     * PTK_Newsletter_Builder::handle_preview_ajax), so what's on screen is
     * what families will get — not a JS approximation that could disagree
     * with the saved article.
     * ────────────────────────────────────────── */

    /** The preview iframe element, or null if the page has no preview column. */
    function previewFrame() {
        return document.getElementById('ptk-nl-preview-frame');
    }

    /** The preview iframe's document, or null before it's writable. */
    function previewDoc() {
        var frame = previewFrame();
        if (!frame) {
            return null;
        }
        try {
            return frame.contentDocument || (frame.contentWindow && frame.contentWindow.document) || null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Wrap the iframe once in a box we're allowed to size. The iframe itself
     * has to stay a full 840px wide (that's the point — see PREVIEW_WIDTH);
     * `transform: scale()` shrinks how it LOOKS but not the space it claims,
     * so without this wrapper the scaled-down preview would leave a column of
     * dead space the size of the unscaled frame. The wrapper is the one that
     * gets the scaled-down height.
     *
     * Wraps ONCE, and any document reference must be taken AFTER it: .wrap()
     * detaches and re-inserts the iframe, and removing an iframe from the
     * document discards its browsing context — re-inserting it builds a fresh
     * one at about:blank. A `document` captured before the wrap is therefore
     * dead, and writing into it puts the newsletter in a document nobody can
     * see. Callers wrap first, then ask for the document.
     *
     * @return {jQuery} The wrapper, or an empty set if there's no iframe.
     */
    function previewWrap() {
        var frame = previewFrame();
        if (!frame) {
            return $();
        }

        var $frame = $(frame);
        var $wrap = $frame.parent('.ptk-nl-preview-scale');

        if (!$wrap.length) {
            $frame.wrap('<div class="ptk-nl-preview-scale" style="overflow:hidden;"></div>');
            $wrap = $frame.parent('.ptk-nl-preview-scale');
            $frame.css({
                width: PREVIEW_WIDTH + 'px',
                border: '0',
                display: 'block',
                transformOrigin: 'top left'
            });

            // Bound once, here, to the iframe ELEMENT rather than per-write to
            // its contentWindow: the element outlives every document we write,
            // so one handler covers them all instead of accumulating a dead
            // one per refresh (i.e. per keystroke-batch) all session.
            //
            // Fires when each written document finishes loading, which is when
            // its images have landed and made the newsletter taller — re-fit
            // then, or the bottom of the preview stays clipped.
            $frame.on('load', scalePreview);
        }

        return $wrap;
    }

    /**
     * Fit the 840px preview into however much room the column has, and give
     * the wrapper the height the scaled preview actually occupies.
     * Recomputed after every refresh (the content's height changes as the
     * newsletter grows) and whenever the column's width changes.
     */
    function scalePreview() {
        var frame = previewFrame();
        if (!frame) {
            return;
        }

        var $wrap = previewWrap();
        var $panel = $('.ptk-nl-preview');
        var panelWidth = $panel.width();

        if (!panelWidth || panelWidth <= 0) {
            return; // Column not laid out yet (or hidden) — nothing to fit to.
        }

        // Never scale UP past the design's own width: 840px is what the
        // newsletter is drawn for, and stretching it would just blur it.
        var scale = Math.min(1, panelWidth / PREVIEW_WIDTH);
        var height = previewContentHeight();

        frame.style.width = PREVIEW_WIDTH + 'px';
        frame.style.height = height + 'px';
        frame.style.transformOrigin = 'top left';
        frame.style.transform = 'scale(' + scale + ')';

        $wrap.css('height', Math.ceil(height * scale) + 'px');
    }

    /**
     * How tall the rendered newsletter is at 840px wide. The iframe is sized
     * to its whole content rather than scrolled internally, so the preview
     * reads as one continuous newsletter — a scrollbar inside a scaled-down
     * frame is a fiddly target and hides how long the newsletter got.
     */
    function previewContentHeight() {
        var doc = previewDoc();
        if (!doc || !doc.body) {
            return 600;
        }
        return Math.max(doc.body.scrollHeight, doc.body.offsetHeight, 1);
    }

    /**
     * Replace the preview document with freshly rendered newsletter HTML.
     *
     * The body is pinned to 840px so the render is measured against the
     * width the design targets, regardless of how small the column is.
     */
    function writePreview(html) {
        // Wrap FIRST, then read the document — never the other way round. The
        // first write is the one that matters: previewWrap() re-parents the
        // iframe, which throws away whatever document existed before it, so a
        // reference taken any earlier would be stale and this whole render
        // would land somewhere detached and invisible. See previewWrap().
        previewWrap();

        var frame = previewFrame();
        var doc = previewDoc();
        if (!frame || !doc) {
            return;
        }

        doc.open();
        doc.write(
            '<!DOCTYPE html><html><head><meta charset="utf-8">' +
            // The house fonts: without them the preview shows Helvetica.
            '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&display=swap">' +
            '<style>' + PREVIEW_STYLE + '</style></head>' +
            '<body style="margin:0;width:' + PREVIEW_WIDTH + 'px">' + html + '</body></html>'
        );
        doc.close();

        // The document was just rebuilt, so the outline went with it.
        applyHighlight();

        // First fit is from the text alone; the iframe's load handler (bound
        // once in previewWrap()) fits again once images are in.
        scalePreview();
    }

    /** Whether the preview endpoint is available (it's localized by PHP). */
    function previewConfigured() {
        return typeof ptkNlData !== 'undefined' && ptkNlData &&
            ptkNlData.ajaxUrl && ptkNlData.previewNonce && previewFrame();
    }

    /**
     * Re-render the preview from the form as it stands right now.
     *
     * Issue and date live OUTSIDE the blocks JSON (they sit in the header
     * card, but with no [data-field], so serialize() never sees them), so
     * they're posted separately. They're read by NAME (`ptk_nl_issue` /
     * `ptk_nl_date`) — the element ids are hyphenated (`ptk-nl-issue`), so an id selector
     * here would match nothing, post nothing, and let the endpoint's
     * fallbacks render a confident "issue 1, today" preview that lies about
     * the exact two fields step 1 exists to set.
     */
    function refreshPreview() {
        if (!previewConfigured()) {
            return;
        }

        // The hidden field is the request body — make sure it's current
        // before reading it, not one edit behind.
        serialize();

        var seq = ++previewSeq;

        $.post(ptkNlData.ajaxUrl, {
            action: 'ptk_nl_preview',
            nonce: ptkNlData.previewNonce,
            blocks: $('#ptk-nl-blocks-json').val(),
            issue: $('[name="ptk_nl_issue"]').val(),
            date: $('[name="ptk_nl_date"]').val()
        }).done(function (response) {
            if (seq !== previewSeq) {
                return; // A newer edit is already in flight — this reply is stale.
            }
            if (!response || !response.success || !response.data || typeof response.data.html !== 'string') {
                showPreviewNote(true);
                return;
            }
            showPreviewNote(false);
            writePreview(response.data.html);
        }).fail(function () {
            if (seq !== previewSeq) {
                return;
            }
            // Keep the last good preview on screen. Blanking it would read as
            // "your newsletter is gone", which is never what happened.
            showPreviewNote(true);
        });
    }

    /** Queue a refresh, collapsing a burst of typing into one request. */
    function schedulePreview() {
        if (previewTimer) {
            clearTimeout(previewTimer);
        }
        previewTimer = setTimeout(function () {
            previewTimer = null;
            refreshPreview();
        }, PREVIEW_DEBOUNCE);
    }

    /** Keep the hidden field current AND queue a preview refresh. */
    function serializeAndPreview() {
        serialize();
        schedulePreview();
    }

    /**
     * Say — quietly — that the preview is behind, without taking anything
     * away. The note sits beside a still-correct preview of the last render;
     * nothing the volunteer typed is lost, and the next edit retries.
     */
    function showPreviewNote(show) {
        var $panel = $('.ptk-nl-preview');
        if (!$panel.length) {
            return;
        }

        var $note = $panel.find('.ptk-nl-preview-note');

        if (!show) {
            $note.remove();
            return;
        }

        if (!$note.length) {
            $note = $('<p class="ptk-nl-preview-note" role="status"></p>')
                .css({ margin: '0 0 8px', fontSize: '12px' })
                .text('Preview couldn\'t update just now — this is your last version. Keep going; it\'ll catch up.');

            // Below the "Live preview" label, above the preview itself: the
            // note is about the preview, so it reads after the thing it's
            // qualifying and before the stale render it's warning about.
            // Prepending would put it above the label, orphaning it from
            // both. Falls back to prepend if the label ever goes away.
            var $label = $panel.find('.ptk-nl-preview-label').first();

            if ($label.length) {
                $label.after($note);
            } else {
                $panel.prepend($note);
            }
        }
    }

    /* ── Preview highlighting ── */

    /**
     * The block types a step owns, taken from the sections themselves
     * (PHP stamps each one with its step) rather than a second copy of the
     * mapping over here that could drift out of agreement with it. Excluded
     * sections are left out: they aren't in the newsletter, so there's
     * nothing of theirs in the preview to outline.
     */
    function typesForStep(n) {
        var types = [];
        $('#ptk-nl-blocks > .ptk-nl-block[data-step="' + n + '"]').not('[data-excluded]').each(function () {
            types.push($(this).attr('data-type'));
        });
        return types;
    }

    /** Outline these block types in the preview (replacing any previous). */
    function setHighlight(types) {
        highlightTypes = types || [];
        applyHighlight();
    }

    /**
     * Paint the current highlight onto the preview document. Safe to call
     * before the first render — an empty document simply has nothing to
     * match, and writePreview() calls this again on every refresh.
     */
    function applyHighlight() {
        var doc = previewDoc();
        if (!doc || !doc.body) {
            return;
        }

        var all = doc.querySelectorAll('[data-ptk-block]');
        for (var i = 0; i < all.length; i++) {
            var type = all[i].getAttribute('data-ptk-block');
            var on = highlightTypes.indexOf(type) !== -1;
            // classList over a class attribute rewrite: the renderer may put
            // its own classes on a block, and they're not ours to drop.
            all[i].classList.toggle('ptk-nl-hi', on);
        }
    }

    /* ── Preview triggers ── */

    /**
     * Everything that can change what the newsletter looks like refreshes it.
     *
     * The issue/date inputs need their own binding. They sit at the foot of
     * the header card, but they deliberately carry NO [data-field] (they're
     * newsletter-level meta, not part of the header block's JSON), and
     * bindSerializeTriggers() only listens to `#ptk-nl-blocks [data-field]` —
     * so without this, changing the issue number or the date would show a
     * preview that never moves. Matched by NAME, which is the one thing
     * about these two inputs that must never change.
     *
     * Add/remove row, image pick/remove, reorder and include/skip refresh
     * from their own handlers via serializeAndPreview().
     */
    function bindPreviewTriggers() {
        $(document).on('input change', '[name="ptk_nl_issue"], [name="ptk_nl_date"]', function () {
            schedulePreview();
        });

        // Focusing a field points the preview at the matching section — a
        // finer-grained answer to "which bit is this?" than the step alone.
        $(document).on('focusin', '#ptk-nl-blocks [data-field]', function () {
            var type = $(this).closest('.ptk-nl-block').attr('data-type');
            setHighlight(type ? [type] : []);
        });

        // Leaving a field hands the highlight back to the step, so the
        // preview never keeps pointing at a section you've moved on from.
        $(document).on('focusout', '#ptk-nl-blocks [data-field]', function () {
            setHighlight(typesForStep(currentStep));
        });

        $(window).on('resize', function () {
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }
            resizeTimer = setTimeout(function () {
                resizeTimer = null;
                scalePreview();
            }, 150);
        });

        // The column's width can change without the window's doing so (the
        // wizard's own layout, an admin menu collapse). Watch the column
        // itself where the browser lets us; the resize handler above is the
        // floor for browsers that don't.
        if (typeof window.ResizeObserver === 'function') {
            var panel = document.querySelector('.ptk-nl-preview');
            if (panel) {
                var lastWidth = 0;
                new window.ResizeObserver(function () {
                    // Width only: scalePreview() sets the wrapper's HEIGHT,
                    // which changes this element's height, which would call
                    // us straight back. Width is the only thing we react to,
                    // and nothing here changes it.
                    var width = panel.clientWidth;
                    if (width !== lastWidth) {
                        lastWidth = width;
                        scalePreview();
                    }
                }).observe(panel);
            }
        }
    }

    /* ──────────────────────────────────────────
     * Prefill (edit mode)
     * ────────────────────────────────────────── */

    /**
     * Walk the server-rendered sections and fill them in from
     * ptkNlData.blocks (localized by PHP: the saved layout when editing an
     * existing newsletter, or the suggested default layout — already
     * blank — for a new one).
     */
    function prefillFromData() {
        if (typeof ptkNlData === 'undefined' || !ptkNlData || !Array.isArray(ptkNlData.blocks)) {
            return;
        }

        var blocksData = ptkNlData.blocks;

        $('#ptk-nl-blocks > .ptk-nl-block').each(function () {
            var $section = $(this);
            var block = findBlockByType(blocksData, $section.attr('data-type'));
            if (!block || !block.data) {
                return;
            }
            var data = block.data;

            // Single-instance fields (skip anything that lives inside a
            // repeatable rows container — those are handled below).
            $section.find('[data-field]').each(function () {
                var $field = $(this);
                if ($field.closest('[data-rows]').length) {
                    return;
                }
                var field = $field.attr('data-field');
                if (Object.prototype.hasOwnProperty.call(data, field)) {
                    setFieldValue($field, data[field]);
                }
            });

            // Repeatable collections: one row/card per saved item.
            $section.find('[data-rows]').each(function () {
                var $rowsContainer = $(this);
                var key = $rowsContainer.attr('data-rows-for');
                var items = data[key];
                if (!items || !items.length) {
                    return;
                }
                for (var i = 0; i < items.length; i++) {
                    addRow($rowsContainer, items[i]);
                }
            });
        });
    }

    /**
     * Find a block by type in a blocks array (as localized from PHP).
     */
    function findBlockByType(blocksData, type) {
        for (var i = 0; i < blocksData.length; i++) {
            if (blocksData[i] && blocksData[i].type === type) {
                return blocksData[i];
            }
        }
        return null;
    }

    /* ──────────────────────────────────────────
     * Repeatable rows: add / remove
     * ────────────────────────────────────────── */

    function bindAddRow() {
        $(document).on('click', '.ptk-nl-add', function (e) {
            e.preventDefault();
            var $section = $(this).closest('.ptk-nl-block');
            var $rowsContainer = $section.find('[data-rows]').first();
            if (!$rowsContainer.length) {
                return;
            }
            addRow($rowsContainer, null);
            serializeAndPreview();
        });
    }

    function bindRemoveRow() {
        // Delegated: covers every row, including ones added after page
        // load, without needing to wire each clone individually.
        $(document).on('click', '.ptk-nl-remove-row', function (e) {
            e.preventDefault();
            $(this).closest('[data-row]').remove();
            serializeAndPreview();
        });
    }

    /**
     * Clone the section's row template into $rowsContainer, optionally
     * filling it from `values` ({field: value, ...}), assign unique ids +
     * wire labels for accessibility, and return the new row.
     *
     * @param {jQuery} $rowsContainer The .ptk-nl-rows[data-rows] element.
     * @param {Object|null} values Field values to prefill, or null to add
     *   a blank row.
     * @return {jQuery|null} The new row, or null if no template was found.
     */
    function addRow($rowsContainer, values) {
        var $section = $rowsContainer.closest('.ptk-nl-block');
        var templateEl = $section.find('template[data-row-template]')[0];
        if (!templateEl) {
            return null;
        }

        // Clone the <template> content (inert markup, invisible to normal
        // DOM queries) into a real element we can wire up and insert.
        var frag = document.importNode(templateEl.content, true);
        var $row = $(frag.firstElementChild);

        assignRowIds($row);

        if (values) {
            $row.find('[data-field]').each(function () {
                var $field = $(this);
                var field = $field.attr('data-field');
                if (Object.prototype.hasOwnProperty.call(values, field)) {
                    setFieldValue($field, values[field]);
                }
            });
        }

        $rowsContainer.append($row);

        // Image-add/remove buttons and the Remove-row button inside the
        // clone are handled by the delegated handlers bound in
        // bindRemoveRow()/bindImagePicker() — no per-clone wiring needed.
        return $row;
    }

    /**
     * Give every [data-field] in a cloned row a unique id and point its
     * label's `for` at it, so screen readers announce the right label per
     * row instead of every row sharing (or lacking) one. Also gives that
     * field's hint (the `<p class="description">` a sighted volunteer reads
     * under it) a unique id and wires it up with aria-describedby, so a
     * screen-reader user hears the same explanation.
     */
    function assignRowIds($row) {
        $row.find('.ptk-nl-field-group').each(function () {
            var $group = $(this);
            var $field = $group.find('> [data-field]').first();
            if (!$field.length) {
                return;
            }

            uidCounter++;
            var id = 'ptk-nl-dyn-' + uidCounter;
            $field.attr('id', id);

            var $label = $group.find('> label').first();
            if ($label.length) {
                $label.attr('for', id);
            }

            var $hint = $group.find('> p.description').first();
            if (!$hint.length) {
                return;
            }

            var hintId = id + '-hint';
            $hint.attr('id', hintId);

            // The hint usually describes the [data-field] control itself. A
            // hidden image_id input is the one exception — it's never exposed
            // to assistive tech, so the hint belongs on the "Add image"
            // button beside it, the only real control in that group (mirrors
            // the static featured-image field in class-newsletter-builder.php).
            var $describedTarget = ('hidden' === ($field.attr('type') || '').toLowerCase())
                ? $group.find('> button.ptk-nl-add-image').first()
                : $field;

            if ($describedTarget.length) {
                addDescribedBy($describedTarget, hintId);
            }
        });
    }

    /**
     * Add a hint id to an element's aria-describedby, keeping any id that's
     * already there rather than overwriting it.
     */
    function addDescribedBy($el, hintId) {
        var existing = ($el.attr('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (existing.indexOf(hintId) === -1) {
            existing.push(hintId);
        }
        $el.attr('aria-describedby', existing.join(' '));
    }

    /* ──────────────────────────────────────────
     * Step 4: the arrange list (order, remove, add back)
     *
     * The rows here are a VIEW of the real sections in #ptk-nl-blocks —
     * they're rebuilt from that container on every change, so the list can
     * never drift from the order the newsletter will actually save in. A
     * row's only link back to its section is its block type (data-type):
     * the row is nowhere near the section in the DOM, so nothing here may
     * resolve a section by ancestry.
     * ────────────────────────────────────────── */

    /**
     * The section for a block type. `.first()` because a type is
     * single-instance by construction, and because a duplicate would
     * otherwise silently move/exclude two sections at once.
     */
    function sectionByType(type) {
        return $('#ptk-nl-blocks > .ptk-nl-block[data-type="' + type + '"]').first();
    }

    /** The block type a clicked arrange-row control belongs to. */
    function rowType(el) {
        return $(el).closest('[data-type]').attr('data-type');
    }

    /**
     * A section's plain-English name, reusing the heading the volunteer
     * already reads on its own step so the two never disagree.
     */
    function sectionLabel($section) {
        var text = $.trim($section.find('.ptk-nl-block-header h3').first().text());
        return text || $section.attr('data-type');
    }

    /**
     * Rebuild step 4's arrange list and "Not included" list from the live
     * sections in #ptk-nl-blocks, in DOM order (= newsletter order). The
     * pinned header/footer are never listed — they can't move and can't be
     * removed, so PHP renders them as static rows around this list.
     */
    function renderArrangeList() {
        var $list = $('[data-arrange]');
        if (!$list.length) {
            return;
        }

        var $excludedPanel = $('[data-excluded-list]');
        var $excludedList = $excludedPanel.find('ul').first();

        $list.empty();
        $excludedList.empty();

        // Whatever the status line last said is about an order that no
        // longer exists. Clear it here; moveSection() re-fills it straight
        // after this returns, so a move still gets announced.
        $('[data-arrange-status]').empty();

        var $movable = $('#ptk-nl-blocks > .ptk-nl-block').not('[data-pinned]');
        var $included = $movable.not('[data-excluded]');
        var $excluded = $movable.filter('[data-excluded]');

        $included.each(function (index) {
            $list.append(buildArrangeRow($(this)));
        });

        if (!$included.length) {
            $list.append(
                $('<li class="ptk-nl-arrange-empty"></li>')
                    .text('Nothing between the header and footer right now.')
            );
        }

        $excluded.each(function () {
            $excludedList.append(buildExcludedRow($(this)));
        });

        // Nothing left out: don't leave an empty "Not included" heading
        // sitting there implying something is missing. (No data-step on this
        // panel, so toggling it here doesn't fight showStep().)
        $excludedPanel.toggle($excluded.length > 0);
    }

    /**
     * One row of the arrange list: drag handle, name, and the actions that
     * aren't reordering -- Edit (jump straight to the section's own step)
     * and Remove. Round 3.1 (spec item 5): dragging is now the ONLY way to
     * reorder -- the Move up/down buttons are gone, and Edit is the
     * keyboard/no-mouse path to a section instead.
     *
     * @param {jQuery} $section The section this row stands for.
     */
    function buildArrangeRow($section) {
        var label = sectionLabel($section);

        var $row = $('<li class="ptk-nl-arrange-row"></li>')
            .attr('data-type', $section.attr('data-type'));

        $row.append($('<span class="ptk-nl-arrange-handle" aria-hidden="true">&#9776;</span>'));
        $row.append($('<span class="ptk-nl-arrange-label"></span>').text(label));

        var $actions = $('<span class="ptk-nl-arrange-actions"></span>');

        $actions.append(
            $('<button type="button" class="button button-small ptk-nl-arr-edit">Edit</button>')
                .attr('aria-label', 'Edit ' + label)
        );
        $actions.append(
            $('<button type="button" class="button button-small ptk-nl-arr-remove">Remove</button>')
                .attr('aria-label', 'Remove ' + label)
        );

        $row.append($actions);

        return $row;
    }

    /** One row of the "Not included" list: a section's name and a way back. */
    function buildExcludedRow($section) {
        var label = sectionLabel($section);

        var $row = $('<li class="ptk-nl-excluded-row"></li>')
            .attr('data-type', $section.attr('data-type'));

        $row.append($('<span class="ptk-nl-arrange-label"></span>').text(label));
        $row.append(
            $('<button type="button" class="button button-small ptk-nl-arr-addback">Add back</button>')
                .attr('aria-label', 'Add back ' + label)
        );

        return $row;
    }

    /** The arrange-list row standing for a block type, if it's listed. */
    function arrangeRowByType(type) {
        return $('[data-arrange] > .ptk-nl-arrange-row[data-type="' + type + '"]').first();
    }

    /**
     * Round 3.1 (spec item 5): open a section from its "Finish editing" row
     * -- jump to the step that owns it and focus its first real field, the
     * same job the removed Move up/down buttons used to help with from the
     * keyboard, now done by going straight to where the volunteer can type.
     *
     * @param {string} type Block type to edit.
     */
    function editSection(type) {
        var $section = sectionByType(type);
        if (!$section.length) {
            return;
        }
        var step = parseInt($section.attr('data-step'), 10);
        if (isNaN(step)) {
            return;
        }
        showStep(step, false);

        // The section's own heading is a safe fallback focus target if it
        // turns out to have no field (shouldn't happen, but never worse
        // than landing nowhere); prefer the first real field.
        var $target = $section.find('[data-field]').not('[type="hidden"]').first();
        if (!$target.length) {
            $target = $section.find('h3').first();
        }
        $target.trigger('focus');
        if ($target[0] && typeof $target[0].scrollIntoView === 'function') {
            $target[0].scrollIntoView({ block: 'center' });
        }
    }

    /**
     * Wire the arrange list's buttons. Delegated on document because the
     * rows are rebuilt on every change — and each handler finds its section
     * by TYPE, never by walking up from the row.
     */
    function bindArrangeList() {
        $(document).on('click', '.ptk-nl-arr-edit', function (e) {
            e.preventDefault();
            editSection(rowType(this));
        });

        $(document).on('click', '.ptk-nl-arr-remove', function (e) {
            e.preventDefault();

            var $section = sectionByType(rowType(this));
            if (!$section.length || $section.is('[data-pinned]')) {
                return; // Header/footer are always in the newsletter.
            }

            var label = sectionLabel($section);
            if (!window.confirm('Remove the ' + label + '? Anything you typed in it won\'t be published.')) {
                return;
            }

            // Mark it, never remove it: the section keeps everything typed
            // into it so Add back can hand it straight back.
            $section.attr('data-excluded', '1');
            afterInclusionChange();
        });

        $(document).on('click', '.ptk-nl-arr-addback', function (e) {
            e.preventDefault();

            var $section = sectionByType(rowType(this));
            if (!$section.length) {
                return;
            }

            $section.removeAttr('data-excluded');
            afterInclusionChange();
        });
    }

    /**
     * Re-sync everything after a section is removed from or added back to
     * the newsletter.
     *
     * showStep() — not this — owns section visibility. Add back happens on
     * step 4, so showing the section here would drop (say) the
     * announcement's fields into the middle of the finish step; handing the
     * decision back to showStep() puts them where they belong, on step 2.
     */
    function afterInclusionChange() {
        renderArrangeList();
        showStep(currentStep, false);
        serializeAndPreview();
    }

    /**
     * Drag-to-reorder for the arrange list. Additive only: the Move up/down
     * buttons are the equal path for anyone not using a mouse, and they keep
     * working whether or not jQuery UI loaded.
     */
    function bindArrangeSortable() {
        var $list = $('[data-arrange]');
        if (!$list.length || !$.fn.sortable) {
            return;
        }

        // Bound to the <ul>, which survives every re-render (only its rows
        // are replaced), so this never needs re-initialising.
        $list.sortable({
            items: '> .ptk-nl-arrange-row',
            handle: '.ptk-nl-arrange-handle',
            axis: 'y',
            tolerance: 'pointer',
            stop: function () {
                applyRowOrderToSections();
                renderArrangeList();
                serializeAndPreview();
                // renderArrangeList() clears the status line right above
                // this -- a screen reader user dragging a row gets the same
                // "something changed" confirmation a sighted volunteer sees
                // (the row visibly moving), where before there was nothing.
                $('[data-arrange-status]').text('Order updated.');
            }
        });
    }

    /**
     * Push the arrange rows' order onto the real sections after a drag.
     *
     * Each included section is re-inserted immediately before the pinned
     * footer in row order, which keeps the header first and the footer last
     * by construction — the sections never leave #ptk-nl-blocks, and never
     * cross the pinned rows.
     */
    function applyRowOrderToSections() {
        var $blocks = $('#ptk-nl-blocks');
        var $footer = $blocks.children('.ptk-nl-block[data-type="footer"]').first();

        $('[data-arrange] > .ptk-nl-arrange-row').each(function () {
            var $section = sectionByType($(this).attr('data-type'));
            if (!$section.length) {
                return;
            }
            if ($footer.length) {
                $section.insertBefore($footer);
            } else {
                $blocks.append($section);
            }
        });
    }

    /* ──────────────────────────────────────────
     * Image picker (wp.media)
     * ────────────────────────────────────────── */

    function bindImagePicker() {
        $(document).on('click', '.ptk-nl-add-image', function (e) {
            e.preventDefault();
            var $group = $(this).closest('.ptk-nl-field-group');
            var $hidden = $group.find('[data-field="image_id"]').first();
            if ($hidden.length) {
                openImagePicker($hidden);
            }
        });

        $(document).on('click', '.ptk-nl-remove-image', function (e) {
            e.preventDefault();
            var $group = $(this).closest('.ptk-nl-field-group');
            var $hidden = $group.find('[data-field="image_id"]').first();
            if ($hidden.length) {
                $hidden.val(0);
                resetImageCrop($group);
                refreshImageChip($hidden);
                refreshFocalPicker($group);
                serializeAndPreview();
            }
        });

        // Fit-mode changes (fired by the hidden field, either from the
        // segmented buttons below or programmatically): show/hide/(re)build
        // the focal-point picker surface. Delegated alongside the existing
        // bindSerializeTriggers() listener on the same element (it also
        // re-serializes/re-previews on this change; the two listeners
        // don't conflict).
        $(document).on('change', '.ptk-nl-image-fit', function () {
            refreshFocalPicker($(this).closest('.ptk-nl-field-group'));
        });

        // Round 3.1 (spec item 2): the visible "Whole photo" / "Crop to fit"
        // segmented buttons replacing the old <select>. They only flip the
        // hidden image_fit field's value and dispatch 'change' -- the
        // listener above (and refreshFocalPicker()) does the rest, so there
        // is exactly one place that knows how to react to a fit change.
        $(document).on('click', '.ptk-nl-fit-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $group = $btn.closest('.ptk-nl-field-group');
            var value = 'crop' === $btn.attr('data-fit-value') ? 'crop' : 'whole';
            var $fitField = $group.find('[data-field="image_fit"]').first();
            if (!$fitField.length || $fitField.val() === value) {
                return;
            }
            $fitField.val(value);
            $fitField[0].dispatchEvent(new Event('change', { bubbles: true }));
            // Keyboard/screen-reader users: land back on the button they
            // just used to confirm the choice, rather than losing focus
            // when the picker surface updates.
            $btn.trigger('focus');
        });
    }

    /** Reflect `fit` ('whole'|'crop') on a fit-toggle's two buttons. */
    function syncFitButtons($toggle, fit) {
        $toggle.find('.ptk-nl-fit-btn').each(function () {
            var $b = $(this);
            var active = $b.attr('data-fit-value') === fit;
            $b.toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false');
        });
    }

    /**
     * A fresh photo starts at "Show whole" -- it may not even contain the
     * subject the PREVIOUS photo in this slot was framed for, so a crop
     * chosen for the old photo must never carry over silently.
     */
    function resetImageCrop($group) {
        $group.find('[data-field="image_fit"]').val('whole');
        $group.find('[data-field="image_focal_x"]').val(50);
        $group.find('[data-field="image_focal_y"]').val(50);
        $group.find('[data-field="image_zoom"]').val(0);
    }

    /**
     * Round 3.1 (spec item 2): show the "Whole photo" / "Crop to fit"
     * segmented toggle once an image is chosen, and always keep the inline
     * focal-point preview mounted (in both fit modes -- see
     * assets/js/focal-point-picker.js's whole/crop mode). Runs on page load
     * (once per image group, via initFocalPickers()) and after every image
     * pick/remove/fit change.
     */
    function refreshFocalPicker($group) {
        var $idField = $group.find('[data-field="image_id"]').first();
        var $fitField = $group.find('[data-field="image_fit"]').first();
        var $toggle = $group.find('[data-fit-toggle]').first();
        var id = parseInt($idField.val(), 10) || 0;

        if (id <= 0) {
            $toggle.hide();
            $group.removeData('ptkFocalImageId');
            if (window.ptkDestroyFocalPicker) {
                window.ptkDestroyFocalPicker($group);
            }
            return;
        }

        var fit = 'crop' === $fitField.val() ? 'crop' : 'whole';
        $toggle.show();
        syncFitButtons($toggle, fit);

        // The same image is already mounted: just flip its mode in place
        // rather than rebuilding (a rebuild would refetch the src and drop
        // scroll/keyboard focus for no reason).
        if ($group.data('ptkFocalPicker') && $group.data('ptkFocalImageId') === id) {
            if (window.ptkSetFocalMode) {
                window.ptkSetFocalMode($group, fit);
            }
            return;
        }

        if (typeof wp === 'undefined' || !wp.media || !wp.media.attachment) {
            return; // media API unavailable -- nothing to fetch a src from.
        }

        fetchAttachment(id).done(function (attachment) {
            if (!attachment) {
                return;
            }
            var src = ( attachment.sizes && attachment.sizes.large && attachment.sizes.large.url )
                || attachment.url;
            if (src && window.ptkInitFocalPicker) {
                window.ptkInitFocalPicker($group, { aspect: '16:9', src: src, mode: fit });
                $group.data('ptkFocalImageId', id);
            }
        });
    }

    /** Every image group on the page, on load -- an existing "crop" photo
     * shows its picker immediately, with no click required. Called via
     * safeBoot(): a throw here must not take the rest of the wizard down.
     */
    function initFocalPickers() {
        $('[data-image-group]').each(function () {
            refreshFocalPicker($(this));
        });
    }

    /**
     * Open the WP media library for a single image and write the chosen
     * attachment id into the hidden [data-field="image_id"] input that was
     * clicked. The frame is created once and reused across the whole
     * session (recreating it per click leaks detached frame views) — we
     * just re-point mediaTargetField at whichever field triggered it, and
     * the single select handler reads that.
     */
    function openImagePicker($hidden) {
        if (typeof wp === 'undefined' || !wp.media) {
            return; // media-upload script not loaded — nothing we can do.
        }

        mediaTargetField = $hidden;

        if (!mediaFrame) {
            mediaFrame = wp.media({
                title: 'Choose image',
                button: { text: 'Use this image' },
                library: { type: 'image' },
                multiple: false
            });

            mediaFrame.on('select', function () {
                if (!mediaTargetField || !mediaTargetField.length) {
                    return;
                }
                var attachment = mediaFrame.state().get('selection').first().toJSON();
                var $group = mediaTargetField.closest('.ptk-nl-field-group');
                var previousId = parseInt(mediaTargetField.val(), 10) || 0;
                mediaTargetField.val(attachment.id);
                if (attachment.id && attachment.id !== previousId) {
                    resetImageCrop($group);
                }
                refreshImageChip(mediaTargetField);
                refreshFocalPicker($group);
                serializeAndPreview();
            });
        }

        mediaFrame.open();
    }

    // One shared attachment fetch/cache, id -> wp.media attachment JSON (a
    // jQuery-style promise). Used by BOTH refreshImageChip() (thumbnail)
    // and the focal-point picker (photo src), so an image already fetched
    // for one purpose is never fetched twice.
    var attachmentCache = {};
    function fetchAttachment(id) {
        if (attachmentCache[id]) {
            return attachmentCache[id];
        }
        if (typeof wp === 'undefined' || !wp.media || !wp.media.attachment) {
            var $none = $.Deferred();
            attachmentCache[id] = $none.promise();
            return attachmentCache[id];
        }
        var attachment = wp.media.attachment(id);
        attachmentCache[id] = attachment.fetch().then(function () {
            return attachment.toJSON();
        });
        return attachmentCache[id];
    }

    /**
     * Show/replace/remove the image chip next to an image_id hidden field,
     * based on its current value: a placeholder text chip immediately (no
     * flash of nothing), upgraded to a real thumbnail + filename once
     * wp.media resolves the attachment.
     */
    function refreshImageChip($hidden) {
        var $group = $hidden.closest('.ptk-nl-field-group');
        var id = parseInt($hidden.val(), 10) || 0;

        $group.find('.ptk-nl-image-chip').remove();

        if (id <= 0) {
            return;
        }

        var $chip = $(
            '<span class="ptk-nl-image-chip"><span class="ptk-nl-image-thumb"></span>' +
            '<span class="ptk-nl-image-name">Image #' + id + '</span> ' +
            '<button type="button" class="button button-small ptk-nl-remove-image">Remove image</button></span>'
        );
        $group.append($chip);

        fetchAttachment(id).done(function (attachment) {
            if (!attachment) {
                return;
            }
            var thumbUrl = attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url;
            if (thumbUrl) {
                $chip.find('.ptk-nl-image-thumb').html('<img src="' + thumbUrl + '" alt="" width="48" height="48">');
            }
            if (attachment.filename) {
                $chip.find('.ptk-nl-image-name').text(attachment.filename);
            }
        });
    }

    /* ──────────────────────────────────────────
     * Field value get/set (single source of truth for the image_id
     * string-vs-integer distinction).
     * ────────────────────────────────────────── */

    function setFieldValue($field, value) {
        var field = $field.attr('data-field');
        if (field === 'image_id') {
            var id = parseInt(value, 10) || 0;
            $field.val(id);
            refreshImageChip($field);
            return;
        }
        $field.val(value === null || typeof value === 'undefined' ? '' : value);
    }

    function getFieldValue($field) {
        var field = $field.attr('data-field');
        if (field === 'image_id') {
            var id = parseInt($field.val(), 10);
            return isNaN(id) ? 0 : id;
        }
        var val = $field.val();
        return val === null || typeof val === 'undefined' ? '' : val;
    }

    /* ──────────────────────────────────────────
     * Serialize → hidden field
     * ────────────────────────────────────────── */

    /**
     * Re-sync on any field change, and once more right before submit so
     * the hidden field is guaranteed current even if a change event was
     * somehow missed.
     */
    function bindSerializeTriggers() {
        $(document).on('input change', '#ptk-nl-blocks [data-field]', function () {
            serializeAndPreview();
        });

        $('#ptk-nl-form').on('submit', function () {
            serialize();
        });
    }

    /**
     * Walk #ptk-nl-blocks in DOM order and build the blocks array the PHP
     * save handler expects: [{ type, data }, ...], where `data` holds the
     * single-instance fields plus one array per repeatable collection
     * (keyed by data-rows-for, e.g. events -> data.rows, story_cards ->
     * data.cards, footer -> data.links). Writes the result into
     * #ptk-nl-blocks-json as JSON.
     *
     * Sections the volunteer left out ([data-excluded]) are skipped: they
     * stay in the DOM, holding whatever was typed into them so step 4's
     * "Add back" can return it, but they're not part of the newsletter and
     * must not be saved into it.
     */
    function serialize() {
        var blocks = [];

        $('#ptk-nl-blocks > .ptk-nl-block').not('[data-excluded]').each(function () {
            var $section = $(this);
            var data = {};

            // Repeatable collections first, so the "is this field part of
            // a row" exclusion below is well defined.
            $section.find('[data-rows]').each(function () {
                var $rowsContainer = $(this);
                var key = $rowsContainer.attr('data-rows-for');
                var rows = [];

                $rowsContainer.children('[data-row]').each(function () {
                    var rowData = {};
                    $(this).find('[data-field]').each(function () {
                        var $field = $(this);
                        rowData[$field.attr('data-field')] = getFieldValue($field);
                    });
                    rows.push(rowData);
                });

                data[key] = rows;
            });

            // Single-instance fields: everything with [data-field] that
            // isn't nested inside a repeatable rows container.
            $section.find('[data-field]').each(function () {
                var $field = $(this);
                if ($field.closest('[data-rows]').length) {
                    return;
                }
                data[$field.attr('data-field')] = getFieldValue($field);
            });

            blocks.push({
                type: $section.attr('data-type'),
                data: data
            });
        });

        var $hidden = $('#ptk-nl-blocks-json');
        if ($hidden.length) {
            $hidden.val(JSON.stringify(blocks));
        }

        // Fenced off: serialize() runs inside the boot block.
        try {
            updatePhotoCheck(blocks);
        } catch (err) {
            // The server still enforces the photo check; nothing is lost.
        }
    }

})(jQuery);
