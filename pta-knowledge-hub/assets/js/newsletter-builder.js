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
 *   - Lets volunteers move or remove whole middle sections, without ever
 *     touching the pinned header/footer.
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

    /* ──────────────────────────────────────────
     * Boot
     * ────────────────────────────────────────── */

    $(function () {
        prefillFromData();
        bindAddRow();
        bindRemoveRow();
        bindMoveAndRemoveBlock();
        bindImagePicker();
        bindSerializeTriggers();

        updateMoveButtonStates();
        serialize();
    });

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
            serialize();
        });
    }

    function bindRemoveRow() {
        // Delegated: covers every row, including ones added after page
        // load, without needing to wire each clone individually.
        $(document).on('click', '.ptk-nl-remove-row', function (e) {
            e.preventDefault();
            $(this).closest('[data-row]').remove();
            serialize();
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
     * Middle blocks: move up / move down / remove
     * ────────────────────────────────────────── */

    function bindMoveAndRemoveBlock() {
        $(document).on('click', '.ptk-nl-move-up', function (e) {
            e.preventDefault();
            var $section = $(this).closest('.ptk-nl-block');
            if ($section.is('[data-pinned]')) {
                return;
            }
            var $prev = $section.prev('.ptk-nl-block');
            // Guard against crossing the pinned header boundary.
            if ($prev.length && !$prev.is('[data-pinned]')) {
                $section.insertBefore($prev);
                updateMoveButtonStates();
                serialize();
            }
        });

        $(document).on('click', '.ptk-nl-move-down', function (e) {
            e.preventDefault();
            var $section = $(this).closest('.ptk-nl-block');
            if ($section.is('[data-pinned]')) {
                return;
            }
            var $next = $section.next('.ptk-nl-block');
            // Guard against crossing the pinned footer boundary.
            if ($next.length && !$next.is('[data-pinned]')) {
                $section.insertAfter($next);
                updateMoveButtonStates();
                serialize();
            }
        });

        $(document).on('click', '.ptk-nl-remove-block', function (e) {
            e.preventDefault();
            var $section = $(this).closest('.ptk-nl-block');
            if ($section.is('[data-pinned]')) {
                return; // Header/footer are never removable.
            }
            if (window.confirm('Remove this section from the newsletter?')) {
                $section.remove();
                updateMoveButtonStates();
                serialize();
            }
        });
    }

    /**
     * Disable Move up on the topmost movable (non-pinned) section and
     * Move down on the bottommost movable section, so volunteers never see
     * a control that can't actually do anything.
     */
    function updateMoveButtonStates() {
        var $movable = $('#ptk-nl-blocks > .ptk-nl-block').not('[data-pinned]');
        $movable.each(function (index) {
            var $section = $(this);
            $section.find('.ptk-nl-move-up').prop('disabled', index === 0);
            $section.find('.ptk-nl-move-down').prop('disabled', index === $movable.length - 1);
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
                serialize();
            }
        });
    }

    /**
     * Open the WP media library for a single image and write the chosen
     * attachment id into the sibling hidden [data-field="image_id"] input.
     */
    function openImagePicker($hidden) {
        if (typeof wp === 'undefined' || !wp.media) {
            return; // media-upload script not loaded — nothing we can do.
        }

        var frame = wp.media({
            title: 'Choose image',
            button: { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $hidden.val(attachment.id);
            refreshImageChip($hidden);
            serialize();
        });

        frame.open();
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
            serialize();
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
     */
    function serialize() {
        var blocks = [];

        $('#ptk-nl-blocks > .ptk-nl-block').each(function () {
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
