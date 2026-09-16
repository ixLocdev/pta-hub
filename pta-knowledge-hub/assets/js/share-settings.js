/**
 * Newsletters > Sharing settings: live preview of the square's accent color.
 *
 * readableOn() mirrors PTK_Share_Color::readable_pair() step for step
 * (WCAG contrast, 4.5 minimum, walk toward white on a dark ground, 40 steps
 * of 8% plus a 1-unit nudge), so the preview shows exactly the color the
 * server will save. The server stays the authority; this is only a preview.
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

    function boot() {
        var form = document.querySelector('[data-ptk-share-settings]');
        if (!form) {
            return;
        }
        var ground = form.getAttribute('data-ground') || '#1a2f5c';
        var picker = form.querySelector('#ptk-share-color');
        var own = form.querySelector('input[name="ptk_share_color_mode"][value="own"]');
        var council = form.querySelector('input[name="ptk_share_color_mode"][value="council"]');
        var preview = form.querySelector('[data-preview]');
        var note = form.querySelector('[data-preview-note]');
        if (!picker || !own || !council || !preview || !note) {
            return;
        }

        function update() {
            var useOwn = own.checked;
            var chosen = norm(useOwn ? picker.value : council.getAttribute('data-color'));
            var drawn = readableOn(chosen, ground);
            preview.style.setProperty('--ptk-ss-accent', drawn);
            picker.parentNode.classList.toggle('is-off', !useOwn);

            if (drawn === chosen) {
                note.textContent = 'Reads clearly on the navy square.';
            } else if (useOwn) {
                note.textContent = 'Too dark to read on navy. When you save, we’ll lighten it to ' + drawn + ' (shown here).';
            } else {
                note.textContent = 'On the navy square this color is lightened to ' + drawn + ' so it can be read (shown here).';
            }
        }

        picker.addEventListener('input', function () {
            own.checked = true;
            update();
        });
        picker.addEventListener('change', function () {
            own.checked = true;
            update();
        });
        own.addEventListener('change', update);
        council.addEventListener('change', update);
        update();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
