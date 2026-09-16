/**
 * The focal-point-and-zoom picker: say which part of a photo must survive
 * a crop by dragging a dot onto it. No slider anywhere -- drag, pinch, the
 * mouse wheel, or the arrow/+/- keys are the whole surface.
 *
 * Ported from docs/reference/tory-focal-point/FocalPointPicker.tsx into
 * vanilla JS + jQuery (this codebase has no build step and no React).
 * Reads/writes the SAME hidden [data-field] inputs (image_focal_x,
 * image_focal_y, image_zoom) the Builder's existing generic serialize/
 * prefill machinery already picks up -- this file only builds the surface
 * and writes those fields' .val(), then fires a native 'change' event so
 * newsletter-builder.js's existing delegated listener re-serializes and
 * re-previews with no changes to that function.
 *
 * One function, ptkInitFocalPicker($group, options), instantiates the
 * control inside any image field-group; options.aspect ('16:9' or '1:1')
 * only changes the CSS box the photo sits in -- the math is frame-shape
 * agnostic. ptkDestroyFocalPicker($group) tears it down.
 */
(function ($) {
    'use strict';

    var NUDGE = 2;
    var NUDGE_FAST = 10;
    var ZOOM_STEP = 5;
    var ZOOM_STEP_FAST = 25;

    var NUDGES = {
        ArrowLeft: [-1, 0],
        ArrowRight: [1, 0],
        ArrowUp: [0, -1],
        ArrowDown: [0, 1]
    };

    function fields($group) {
        return {
            $x: $group.find('[data-field="image_focal_x"]').first(),
            $y: $group.find('[data-field="image_focal_y"]').first(),
            $zoom: $group.find('[data-field="image_zoom"]').first()
        };
    }

    function readFocal($group) {
        var f = fields($group);
        return {
            x: ptkFocalClampPercent(f.$x.val()),
            y: ptkFocalClampPercent(f.$y.val()),
            zoom: ptkFocalSanitizeZoom(parseInt(f.$zoom.val(), 10) || 0)
        };
    }

    /**
     * Write x/y (and, unless explicitly omitted, the CURRENT zoom) back
     * into the hidden fields, then fire one native 'change' so the
     * Builder's existing delegated listener re-serializes/re-previews.
     * Zoom rides along on every drag/keyboard move on purpose: writing x/y
     * alone would silently reset how far in a volunteer had zoomed.
     */
    function writeFocal($group, x, y, zoom) {
        var f = fields($group);
        f.$x.val(ptkFocalClampPercent(x));
        f.$y.val(ptkFocalClampPercent(y));
        if (typeof zoom !== 'undefined') {
            f.$zoom.val(ptkFocalSanitizeZoom(zoom));
        }
        f.$zoom[0].dispatchEvent(new Event('change', { bubbles: true }));
    }

    function writeZoom($group, nextZoom) {
        var focal = readFocal($group);
        var clamped = Math.min(PTK_FOCAL_ZOOM_MAX, Math.max(PTK_FOCAL_ZOOM_MIN, nextZoom));
        // Dropped, not stored as 100 -- a volunteer who zooms in and back
        // out leaves the photo emitting exactly what it emitted before.
        var sanitized = ptkFocalSanitizeZoom(clamped);
        writeFocal($group, focal.x, focal.y, sanitized);
    }

    function updateSurface($group, state) {
        var focal = readFocal($group);
        var effective = ptkFocalEffectiveZoom(focal.zoom);

        state.$dot.css({ left: focal.x + '%', top: focal.y + '%' });
        state.$dot.attr(
            'aria-label',
            'Focal point, ' + focal.x + '% across and ' + focal.y + '% down' +
            (effective > PTK_FOCAL_ZOOM_MIN ? ', zoomed to ' + Math.round(effective) + ' percent' : '') +
            '. Drag it, or nudge it with the arrow keys. Plus and minus zoom.'
        );

        if (effective > PTK_FOCAL_ZOOM_MIN) {
            state.$img.css({
                transform: 'scale(' + ( effective / 100 ) + ')',
                transformOrigin: focal.x + '% ' + focal.y + '%'
            });
        } else {
            state.$img.css({ transform: '', transformOrigin: '' });
        }

        // Always present, never conditional -- rendering it only while
        // zoomed reflows the row (the reset button visibly shoves around)
        // the moment the wheel is touched.
        state.$readout.text(effective > PTK_FOCAL_ZOOM_MIN ? Math.round(effective) + '%' : '');
    }

    function pinchDistance(pointers) {
        var live = [];
        pointers.forEach(function (p) { live.push(p); });
        if (live.length < 2) {
            return null;
        }
        return Math.hypot(live[0].x - live[1].x, live[0].y - live[1].y);
    }

    /**
     * "Whole photo" vs "Crop to fit" (round 3.1, spec item 2): the picker is
     * now mounted in BOTH fit modes, so a volunteer always sees the photo
     * inline. In "whole" mode the focal dot, zoom readout and reset button
     * are hidden and the drag/pinch/wheel/keyboard handlers are no-ops --
     * there's nothing to frame, the newsletter shows the photo byte for
     * byte. Switching modes calls ptkSetFocalMode() to update an existing
     * picker in place, so toggling Whole/Crop back and forth never tears
     * down and rebuilds the surface (which would refetch the image src).
     */
    window.ptkInitFocalPicker = function ($group, options) {
        options = options || {};
        var aspect = options.aspect || '16:9';
        var src = options.src || '';
        var mode = 'whole' === options.mode ? 'whole' : 'crop';

        window.ptkDestroyFocalPicker($group);

        var boxRatio = ( '1:1' === aspect ) ? '1/1' : '16/9';

        var $wrap = $(
            '<div class="ptk-focal-wrap" data-focal-wrap>' +
                '<div class="ptk-focal-surface" data-focal-surface style="aspect-ratio:' + boxRatio + ';touch-action:none;">' +
                    '<img class="ptk-focal-img" src="' + src + '" alt="" draggable="false">' +
                    '<button type="button" class="ptk-focal-dot" data-focal-dot tabindex="0"></button>' +
                '</div>' +
                '<div class="ptk-focal-meta">' +
                    '<p class="ptk-focal-hint" data-focal-hint>Drag the dot onto what has to stay in view. Pinch or scroll to zoom. Arrow keys nudge it; hold Shift to move further, plus and minus to zoom.</p>' +
                    '<span class="ptk-focal-readout" data-focal-readout></span>' +
                    '<button type="button" class="ptk-focal-reset" data-focal-reset>Reset to center</button>' +
                '</div>' +
            '</div>'
        );

        $group.append($wrap);

        var state = {
            $wrap: $wrap,
            $surface: $wrap.find('[data-focal-surface]'),
            $img: $wrap.find('.ptk-focal-img'),
            $dot: $wrap.find('[data-focal-dot]'),
            $hint: $wrap.find('[data-focal-hint]'),
            $readout: $wrap.find('[data-focal-readout]'),
            $reset: $wrap.find('[data-focal-reset]'),
            pointers: new Map(),
            pinchStart: null,
            dragging: false,
            mode: mode
        };

        var surfaceEl = state.$surface[0];

        var WHOLE_HINT = 'Showing the whole photo.';
        var CROP_HINT = 'Drag the dot onto what has to stay in view. Pinch or scroll to zoom. Arrow keys nudge it; hold Shift to move further, plus and minus to zoom.';

        /** Apply the current state.mode to the DOM: dot/readout/reset visibility and the hint text. */
        function applyMode() {
            var whole = 'whole' === state.mode;
            $wrap.attr('data-focal-mode', state.mode);
            state.$dot.toggle(!whole);
            state.$readout.toggle(!whole);
            state.$reset.toggle(!whole);
            state.$hint.text(whole ? WHOLE_HINT : CROP_HINT);
        }

        function report(clientX, clientY) {
            if ('whole' === state.mode) {
                return;
            }
            var rect = surfaceEl.getBoundingClientRect();
            var next = ptkFocalPointerToFocal(rect, clientX, clientY);
            var focal = readFocal($group);
            var effective = ptkFocalEffectiveZoom(focal.zoom);
            writeFocal($group, next.x, next.y, effective > PTK_FOCAL_ZOOM_MIN ? focal.zoom : undefined);
            updateSurface($group, state);
        }

        function onPointerDown(e) {
            e.preventDefault();
            if (typeof surfaceEl.setPointerCapture === 'function') {
                try {
                    surfaceEl.setPointerCapture(e.pointerId);
                } catch (err) {
                    /* capture is a nicety, never a requirement */
                }
            }
            state.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            var distance = pinchDistance(state.pointers);
            if (distance) {
                // Second finger down: this is a pinch, not a drag --
                // abandon whatever the first finger started so the photo
                // does not lurch to the midpoint.
                var focal = readFocal($group);
                state.pinchStart = { distance: distance, zoom: ptkFocalEffectiveZoom(focal.zoom) };
                state.dragging = false;
                return;
            }
            state.dragging = true;
            report(e.clientX, e.clientY);
        }

        function onPointerMove(e) {
            if (state.pointers.has(e.pointerId)) {
                state.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            }
            if (state.pinchStart) {
                var distance = pinchDistance(state.pointers);
                if (distance && 'whole' !== state.mode) {
                    // Scaled from the zoom the pinch BEGAN at, so letting go
                    // and pinching again continues rather than jumping.
                    writeZoom($group, ( state.pinchStart.zoom * distance ) / state.pinchStart.distance);
                    updateSurface($group, state);
                }
                return;
            }
            if (!state.dragging) {
                return;
            }
            report(e.clientX, e.clientY);
        }

        function onPointerUp(e) {
            state.pointers.delete(e.pointerId);
            if (state.pointers.size < 2) {
                state.pinchStart = null;
            }
            if (!state.dragging) {
                return;
            }
            if (typeof surfaceEl.releasePointerCapture === 'function') {
                try {
                    surfaceEl.releasePointerCapture(e.pointerId);
                } catch (err) {
                    /* see above */
                }
            }
            state.dragging = false;
            report(e.clientX, e.clientY);
        }

        function onPointerCancel(e) {
            state.pointers.delete(e.pointerId);
            if (state.pointers.size < 2) {
                state.pinchStart = null;
            }
            state.dragging = false;
        }

        // Registered by hand with { passive: false }: jQuery's .on('wheel')
        // registers passively, which makes preventDefault() do nothing and
        // the page would scroll out from under the photo while it zooms.
        function onWheel(e) {
            if ('whole' === state.mode) {
                return;
            }
            e.preventDefault();
            var focal = readFocal($group);
            writeZoom($group, ptkFocalEffectiveZoom(focal.zoom) - e.deltaY * 0.25);
            updateSurface($group, state);
        }

        function onKeyDown(e) {
            if ('+' === e.key || '=' === e.key) {
                e.preventDefault();
                var focal1 = readFocal($group);
                writeZoom($group, ptkFocalEffectiveZoom(focal1.zoom) + ( e.shiftKey ? ZOOM_STEP_FAST : ZOOM_STEP ));
                updateSurface($group, state);
                return;
            }
            if ('-' === e.key || '_' === e.key) {
                e.preventDefault();
                var focal2 = readFocal($group);
                writeZoom($group, ptkFocalEffectiveZoom(focal2.zoom) - ( e.shiftKey ? ZOOM_STEP_FAST : ZOOM_STEP ));
                updateSurface($group, state);
                return;
            }
            var direction = NUDGES[e.key];
            if (!direction) {
                return;
            }
            e.preventDefault();
            var step = e.shiftKey ? NUDGE_FAST : NUDGE;
            var focal3 = readFocal($group);
            var effective3 = ptkFocalEffectiveZoom(focal3.zoom);
            writeFocal(
                $group,
                focal3.x + direction[0] * step,
                focal3.y + direction[1] * step,
                effective3 > PTK_FOCAL_ZOOM_MIN ? focal3.zoom : undefined
            );
            updateSurface($group, state);
        }

        function onReset() {
            writeFocal($group, 50, 50, 0);
            updateSurface($group, state);
        }

        surfaceEl.addEventListener('pointerdown', onPointerDown);
        surfaceEl.addEventListener('pointermove', onPointerMove);
        surfaceEl.addEventListener('pointerup', onPointerUp);
        surfaceEl.addEventListener('pointercancel', onPointerCancel);
        surfaceEl.addEventListener('wheel', onWheel, { passive: false });
        state.$dot.on('keydown', onKeyDown);
        state.$reset.on('click', onReset);

        state.cleanup = function () {
            surfaceEl.removeEventListener('pointerdown', onPointerDown);
            surfaceEl.removeEventListener('pointermove', onPointerMove);
            surfaceEl.removeEventListener('pointerup', onPointerUp);
            surfaceEl.removeEventListener('pointercancel', onPointerCancel);
            surfaceEl.removeEventListener('wheel', onWheel);
            state.$dot.off('keydown', onKeyDown);
            state.$reset.off('click', onReset);
        };

        $group.data('ptkFocalPicker', state);
        applyMode();
        updateSurface($group, state);
    };

    /**
     * Switch an already-mounted picker between "whole" and "crop" without
     * rebuilding it (a rebuild would refetch the image src and reset scroll/
     * focus). No-op if no picker is mounted on $group.
     */
    window.ptkSetFocalMode = function ($group, mode) {
        var state = $group.data('ptkFocalPicker');
        if (!state) {
            return;
        }
        state.mode = ( 'whole' === mode ) ? 'whole' : 'crop';
        var whole = 'whole' === state.mode;
        state.$wrap.attr('data-focal-mode', state.mode);
        state.$dot.toggle(!whole);
        state.$readout.toggle(!whole);
        state.$reset.toggle(!whole);
        state.$hint.text(whole
            ? 'Showing the whole photo.'
            : 'Drag the dot onto what has to stay in view. Pinch or scroll to zoom. Arrow keys nudge it; hold Shift to move further, plus and minus to zoom.');
    };

    window.ptkDestroyFocalPicker = function ($group) {
        var state = $group.data('ptkFocalPicker');
        if (state) {
            if (state.cleanup) {
                state.cleanup();
            }
            $group.removeData('ptkFocalPicker');
        }
        $group.find('[data-focal-wrap]').remove();
    };

})(jQuery);
