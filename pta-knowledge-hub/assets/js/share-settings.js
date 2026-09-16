/**
 * Newsletter settings: live preview of the share square's two colors.
 *
 * readableOn() mirrors PTK_Share_Color::readable_pair() step for step
 * (WCAG contrast, 4.5 minimum, walk toward white on a dark background,
 * toward black on a light one, 40 steps of 8% plus a 1-unit nudge), so the
 * preview shows exactly the color the server will save. The server stays
 * the authority; this is only a preview.
 *
 * 4.3.0: two independent picker+hex pairs (background, text), no
 * "own color / council color" choice -- the square no longer has a
 * Council-palette fallback (round-2 amendment). Both fields use the same
 * wiring, generalized into wireColorField() rather than duplicated.
 * No jQuery.
 */
(function () {
    'use strict';

    var MIN = 4.5, STEPS = 40, STEP = 0.08;

    function norm(hex) {
        hex = String(hex || '').trim().replace(/^#/, '').toLowerCase();
        if (/^[0-9a-f]{3}$/.test(hex)) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        return /^[0-9a-f]{6}$/.test(hex) ? '#' + hex : '#1a2f5c';
    }

    /** A color code a person might type: 1a2f5c, #1a2f5c, abc, #abc. */
    function isHex(value) {
        return /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(value || '').trim());
    }

    function rgb(hex) {
        hex = norm(hex).slice(1);
        return [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)];
    }

    function toHex(r, g, b) {
        return '#' + [r, g, b].map(function (n) {
            n = Math.max(0, Math.min(255, Math.round(n)));
            return (n < 16 ? '0' : '') + n.toString(16);
        }).join('');
    }

    function lum(hex) {
        var c = rgb(hex).map(function (v) {
            v = v / 255;
            return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }

    function ratio(a, b) {
        var la = lum(a), lb = lum(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }

    function readableOn(color, ground) {
        color = norm(color);
        ground = norm(ground);
        if (ratio(color, ground) >= MIN) {
            return color;
        }
        var up = lum(ground) < 0.5;
        var c = rgb(color), r = c[0], g = c[1], b = c[2];
        var best = color, bestRatio = ratio(color, ground);
        for (var i = 0; i < STEPS; i++) {
            if (up) {
                r = Math.min(255, r + (255 - r) * STEP + 1);
                g = Math.min(255, g + (255 - g) * STEP + 1);
                b = Math.min(255, b + (255 - b) * STEP + 1);
            } else {
                r = Math.max(0, r * (1 - STEP) - 1);
                g = Math.max(0, g * (1 - STEP) - 1);
                b = Math.max(0, b * (1 - STEP) - 1);
            }
            var cand = toHex(r, g, b), cr = ratio(cand, ground);
            if (cr > bestRatio) {
                best = cand;
                bestRatio = cr;
            }
            if (cr >= MIN) {
                return cand;
            }
        }
        return best;
    }

    /**
     * Wire one picker+hex pair so they stay in sync and always report a
     * normalized hex via getValue(). Returns { getValue, onChange }.
     */
    function wireColorField(form, pickerSel, hexSel, onAnyChange) {
        var picker = form.querySelector(pickerSel);
        var hex = form.querySelector(hexSel);
        if (!picker) {
            return null;
        }

        function fromPicker() {
            if (hex) {
                hex.value = picker.value;
            }
            onAnyChange();
        }
        picker.addEventListener('input', fromPicker);
        picker.addEventListener('change', fromPicker);

        if (hex) {
            hex.addEventListener('input', function () {
                var v = hex.value.trim();
                if (isHex(v)) {
                    picker.value = norm(v);
                    onAnyChange();
                }
            });
            hex.addEventListener('change', function () {
                var v = hex.value.trim();
                if (v === '') {
                    hex.value = picker.value;
                } else if (isHex(v)) {
                    picker.value = norm(v);
                }
                onAnyChange();
            });
        }

        return {
            getValue: function () {
                return norm(picker.value);
            }
        };
    }

    function boot() {
        var form = document.querySelector('[data-ptk-share-settings]');
        if (!form) {
            return;
        }
        var preview = form.querySelector('[data-preview]');
        var note = form.querySelector('[data-preview-note]');
        if (!preview || !note) {
            return;
        }

        function update() {
            var bg = bgField ? bgField.getValue() : '#1a2f5c';
            var text = textField ? textField.getValue() : '#ffffff';
            var drawn = readableOn(text, bg);

            preview.style.setProperty('--ptk-ss-bg', bg);
            preview.style.setProperty('--ptk-ss-accent', drawn);

            if (drawn === text) {
                note.textContent = 'Reads clearly on your square.';
            } else {
                note.textContent = 'Too hard to read on your square. When you save, we’ll adjust it to ' + drawn + ' (shown here).';
            }
        }

        var bgField = wireColorField(form, '[data-ptk-bg-picker]', '[data-ptk-bg-hex]', function () { update(); });
        var textField = wireColorField(form, '[data-ptk-text-picker]', '[data-ptk-text-hex]', function () { update(); });

        update();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
