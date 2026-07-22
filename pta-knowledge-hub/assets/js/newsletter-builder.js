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
    var LAST_STEP = 4;

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
        showStep(FIRST_STEP, false);

        // Straight away, not debounced: the preview column must never sit
        // blank while a volunteer wonders whether it's broken.
        refreshPreview();
    });

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
     * row instead of every row sharing (or lacking) one.
     */
    function assignRowIds($row) {
        $row.find('[data-field]').each(function () {
            var $field = $(this);
            uidCounter++;
            var id = 'ptk-nl-dyn-' + uidCounter;
            $field.attr('id', id);

            var $label = $field.closest('.ptk-nl-field-group').find('> label').first();
            if ($label.length) {
                $label.attr('for', id);
            }
        });
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
            $list.append(buildArrangeRow($(this), index === 0, index === $included.length - 1));
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
     * One row of the arrange list: drag handle, name, and the buttons that
     * do the same job from the keyboard.
     *
     * @param {jQuery} $section The section this row stands for.
     * @param {boolean} isFirst Whether it's the topmost included section.
     * @param {boolean} isLast Whether it's the bottommost included section.
     */
    function buildArrangeRow($section, isFirst, isLast) {
        var label = sectionLabel($section);

        var $row = $('<li class="ptk-nl-arrange-row"></li>')
            .attr('data-type', $section.attr('data-type'));

        $row.append($('<span class="ptk-nl-arrange-handle" aria-hidden="true">&#9776;</span>'));
        $row.append($('<span class="ptk-nl-arrange-label"></span>').text(label));

        var $actions = $('<span class="ptk-nl-arrange-actions"></span>');

        // Disabled at the boundaries rather than hidden: a control that
        // vanishes is more confusing than one that's plainly unavailable.
        $actions.append(
            $('<button type="button" class="button button-small ptk-nl-arr-up">Move up</button>')
                .attr('aria-label', 'Move ' + label + ' up')
                .prop('disabled', isFirst)
        );
        $actions.append(
            $('<button type="button" class="button button-small ptk-nl-arr-down">Move down</button>')
                .attr('aria-label', 'Move ' + label + ' down')
                .prop('disabled', isLast)
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

    /**
     * The nearest sibling section a move should swap with: the next one in
     * `dir` that's actually in the newsletter. Excluded sections are skipped
     * (they aren't in the list, so swapping with one would look like the
     * button did nothing), and the pinned header/footer are a hard stop —
     * they always bookend the newsletter.
     *
     * @param {jQuery} $section Section being moved.
     * @param {number} dir -1 for up, 1 for down.
     * @return {jQuery} The neighbour, or an empty set if there isn't one.
     */
    function movableNeighbour($section, dir) {
        var $sibling = dir < 0 ? $section.prev('.ptk-nl-block') : $section.next('.ptk-nl-block');

        while ($sibling.length && $sibling.is('[data-excluded]') && !$sibling.is('[data-pinned]')) {
            $sibling = dir < 0 ? $sibling.prev('.ptk-nl-block') : $sibling.next('.ptk-nl-block');
        }

        if (!$sibling.length || $sibling.is('[data-pinned]')) {
            return $();
        }

        return $sibling;
    }

    /**
     * Move a section one place up or down in the real newsletter, then
     * rebuild the list from the result.
     *
     * @param {string} type Block type to move.
     * @param {number} dir -1 for up, 1 for down.
     */
    function moveSection(type, dir) {
        var $section = sectionByType(type);
        if (!$section.length || $section.is('[data-pinned]') || $section.is('[data-excluded]')) {
            return;
        }

        var $neighbour = movableNeighbour($section, dir);
        if (!$neighbour.length) {
            return;
        }

        var label = sectionLabel($section);

        if (dir < 0) {
            $section.insertBefore($neighbour);
        } else {
            $section.insertAfter($neighbour);
        }

        renderArrangeList();
        serializeAndPreview();

        // The re-render above replaced the button that was just clicked, so
        // put focus back and say what happened — this is the whole keyboard
        // path, and re-tabbing into the list for every single move would
        // make it the worse way to do the same job.
        restoreMoveFocus(type, dir);
        announceMove(type, dir, label);
    }

    /**
     * Put focus back on the moved row's button after the list is rebuilt.
     *
     * If the move landed the row at an end of the list, the button that was
     * pressed is now disabled — and focusing a disabled button drops focus
     * to the body all over again. Fall back to the row's other move button,
     * which is necessarily still enabled, so the user stays where they are.
     *
     * @param {string} type Block type that moved.
     * @param {number} dir -1 for up, 1 for down.
     */
    function restoreMoveFocus(type, dir) {
        var $row = arrangeRowByType(type);
        if (!$row.length) {
            return;
        }

        var pressed = dir < 0 ? '.ptk-nl-arr-up' : '.ptk-nl-arr-down';
        var other = dir < 0 ? '.ptk-nl-arr-down' : '.ptk-nl-arr-up';

        var $button = $row.find(pressed).first();
        if (!$button.length || $button.prop('disabled')) {
            $button = $row.find(other).first();
        }

        $button.focus();
    }

    /**
     * Say where a section ended up, in plain positional English — "Featured
     * story moved down. Now 3 of 4." The status element is aria-live, so a
     * screen reader announces it; everyone else can just read it.
     *
     * @param {string} type Block type that moved.
     * @param {number} dir -1 for up, 1 for down.
     * @param {string} label The section's plain-English name.
     */
    function announceMove(type, dir, label) {
        var $status = $('[data-arrange-status]');
        if (!$status.length) {
            return;
        }

        var $rows = $('[data-arrange] > .ptk-nl-arrange-row');
        var position = $rows.index(arrangeRowByType(type)) + 1;
        if (!position) {
            return;
        }

        $status.text(
            label + ' moved ' + (dir < 0 ? 'up' : 'down') + '. ' +
            'Now ' + position + ' of ' + $rows.length + '.'
        );
    }

    /** The arrange-list row standing for a block type, if it's listed. */
    function arrangeRowByType(type) {
        return $('[data-arrange] > .ptk-nl-arrange-row[data-type="' + type + '"]').first();
    }

    /**
     * Wire the arrange list's buttons. Delegated on document because the
     * rows are rebuilt on every change — and each handler finds its section
     * by TYPE, never by walking up from the row.
     */
    function bindArrangeList() {
        $(document).on('click', '.ptk-nl-arr-up', function (e) {
            e.preventDefault();
            moveSection(rowType(this), -1);
        });

        $(document).on('click', '.ptk-nl-arr-down', function (e) {
            e.preventDefault();
            moveSection(rowType(this), 1);
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
                refreshImageChip($hidden);
                serializeAndPreview();
            }
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
                mediaTargetField.val(attachment.id);
                refreshImageChip(mediaTargetField);
                serializeAndPreview();
            });
        }

        mediaFrame.open();
    }

    /**
     * Show/replace/remove the "Image #{id} selected" chip + Remove control
     * next to an image_id hidden field, based on its current value. A real
     * thumbnail isn't available client-side without an extra AJAX round
     * trip, so a plain-text chip stands in for MVP.
     */
    function refreshImageChip($hidden) {
        var $group = $hidden.closest('.ptk-nl-field-group');
        var id = parseInt($hidden.val(), 10) || 0;

        $group.find('.ptk-nl-image-chip').remove();

        if (id > 0) {
            var $chip = $(
                '<span class="ptk-nl-image-chip">Image #' + id + ' selected ' +
                '<button type="button" class="button button-small ptk-nl-remove-image">Remove image</button>' +
                '</span>'
            );
            $group.append($chip);
        }
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
    }

})(jQuery);
