/**
 * Client-side <canvas> mirror of the photo square's PHOTO layout
 * (includes/class-share-image.php render_png() + draw_photo_words() +
 * draw_square_photo_from_file()), round 3.1 Problem B.
 *
 * WHY: today, after a focal/zoom/color change, share-panel.js waits, then
 * the server re-renders the PNG with GD, saves an attachment, and the <img>
 * src is swapped -- a multi-second round trip. This module draws the SAME
 * picture directly in the browser so the preview updates the instant a
 * volunteer drags the dot, while the real server render still happens
 * underneath (debounced) and is what actually gets saved/downloaded.
 *
 * Deliberately its own file (see class-share-panel.php's enqueue comment):
 * a throw in here must never take share-panel.js down with it. Every
 * public entry point is wrapped so a failure here silently falls back to
 * "just show the server's last image," which is exactly today's behavior.
 *
 * Not pixel-identical to the GD render -- canvas text rendering and GD's
 * FreeType rasterizer will never match hairline-for-hairline -- but it
 * mirrors the same layout math (PTK_Focal_Point::rect_crop() via this
 * codebase's own ptkFocalCropRect() twin, and draw_photo_words()'s
 * positions/sizes/tracking) closely enough that a volunteer sees, live,
 * where the crop and the words will land.
 */
(function () {
    'use strict';

    // GD/FreeType draws imagettftext() point sizes at 96dpi; canvas
    // ctx.font sizes are CSS px (96dpi already), but "points" in that
    // convention are 72 per inch, so the same numeric size means a
    // physically smaller glyph unless converted: px = pt * 96 / 72.
    var PT_TO_PX = 96 / 72;

    var SIZE = 1080;
    var PHOTO_BAND_TOP = 608;
    var PAD = 96;

    var FONT_FAMILIES = {
        eyebrow: 'PTKShareEyebrow',
        issue: 'PTKShareIssue',
        date: 'PTKShareDate',
        school: 'PTKShareSchool'
    };

    var fontsReadyPromise = null;

    /**
     * Load the three bundled faces once via the FontFace API. Resolves
     * (never rejects) so a font failure degrades to "don't draw the
     * canvas," not an unhandled rejection.
     *
     * @return {Promise<boolean>} True once fonts are usable.
     */
    function loadFonts() {
        if (fontsReadyPromise) {
            return fontsReadyPromise;
        }
        fontsReadyPromise = new Promise(function (resolve) {
            if (typeof FontFace === 'undefined' || typeof ptkShareSquareFonts === 'undefined' || !document.fonts) {
                resolve(false);
                return;
            }
            try {
                var faces = [
                    new FontFace(FONT_FAMILIES.eyebrow, 'url(' + ptkShareSquareFonts.bold + ')'),
                    new FontFace(FONT_FAMILIES.issue, 'url(' + ptkShareSquareFonts.extrabold + ')'),
                    new FontFace(FONT_FAMILIES.date, 'url(' + ptkShareSquareFonts.serif + ')'),
                    new FontFace(FONT_FAMILIES.school, 'url(' + ptkShareSquareFonts.bold + ')')
                ];
                Promise.all(faces.map(function (face) {
                    return face.load().then(function (loaded) {
                        document.fonts.add(loaded);
                        return true;
                    }, function () {
                        return false;
                    });
                })).then(function (results) {
                    resolve(results.every(Boolean));
                });
            } catch (err) {
                resolve(false);
            }
        });
        return fontsReadyPromise;
    }

    /** Width in px of $text set in $family at $pt (GD points, converted). */
    function textWidth(ctx, text, family, pt) {
        if (!text) {
            return 0;
        }
        ctx.font = ( pt * PT_TO_PX ) + 'px ' + family;
        return ctx.measureText(text).width;
    }

    /** The JS twin of PTK_Share_Image::fit_text(): largest pt in [min,max] that fits $limit px. */
    function fitText(ctx, text, family, max, min, limit) {
        if (!text) {
            return max;
        }
        for (var size = max; size > min; size--) {
            if (textWidth(ctx, text, family, size) <= limit) {
                return size;
            }
        }
        return min;
    }

    /**
     * The JS twin of PTK_Share_Image::draw_tracked(): draws one character
     * at a time so a fixed pixel gap ($track) can sit between glyphs (GD
     * has no native letter-tracking). Mirrors the PHP's own space-widening
     * fudge (+ size*0.3, size in POINTS, added directly as px -- an
     * existing quirk of the PHP this is a twin of, kept for visual match).
     */
    function drawTracked(ctx, text, family, pt, x, y, color, track) {
        ctx.font = ( pt * PT_TO_PX ) + 'px ' + family;
        ctx.fillStyle = color;
        ctx.textBaseline = 'alphabetic';
        var chars = text.split('');
        for (var i = 0; i < chars.length; i++) {
            var ch = chars[i];
            ctx.fillText(ch, x, y);
            x += ctx.measureText(ch).width + track;
            if (' ' === ch) {
                x += pt * 0.3;
            }
        }
    }

    /**
     * The JS twin of PTK_Share_Image::draw_photo_words(), drawn straight
     * onto the 1080x1080 canvas below PHOTO_BAND_TOP -- same positions,
     * same sizes, same order.
     */
    function drawPhotoWords(ctx, data) {
        var left = PAD;
        var right = SIZE - PAD;
        var width = right - left;
        var top = PHOTO_BAND_TOP;

        drawTracked(ctx, 'NEWSLETTER', FONT_FAMILIES.eyebrow, 22, left, top + 82, data.textColor, 9);

        var issueBaseline = top + 268;
        var numberRight = left;

        if (data.issue) {
            var issueSize = fitText(ctx, data.issue, FONT_FAMILIES.issue, 150, 70, Math.floor(width * 0.55));
            ctx.font = ( issueSize * PT_TO_PX ) + 'px ' + FONT_FAMILIES.issue;
            ctx.fillStyle = data.textColor;
            ctx.textBaseline = 'alphabetic';
            ctx.fillText(data.issue, left, issueBaseline);
            numberRight = left + textWidth(ctx, data.issue, FONT_FAMILIES.issue, issueSize) + 44;
        }

        if (data.dateline) {
            var room = right - numberRight;
            var dateSize = fitText(ctx, data.dateline, FONT_FAMILIES.date, 44, 22, room);
            ctx.font = ( dateSize * PT_TO_PX ) + 'px ' + FONT_FAMILIES.date;
            ctx.fillStyle = data.textColor;
            ctx.textBaseline = 'alphabetic';
            ctx.fillText(data.dateline, numberRight, issueBaseline - 4);
        }

        ctx.fillStyle = data.hairlineColor;
        ctx.fillRect(left, top + 318, width, 2);

        if (data.school) {
            var schoolSize = fitText(ctx, data.school, FONT_FAMILIES.school, 40, 16, width);
            ctx.font = ( schoolSize * PT_TO_PX ) + 'px ' + FONT_FAMILIES.school;
            ctx.fillStyle = data.textColor;
            ctx.textBaseline = 'alphabetic';
            ctx.fillText(data.school, left, top + 390);
        }
    }

    /**
     * Draw the whole photo square onto $canvas. Silently does nothing if
     * fonts, the photo image, or ptkFocalCropRect (assets/js/focal-point.js)
     * are not ready -- callers keep showing the server's <img> in that case.
     *
     * @param {HTMLCanvasElement} canvas
     * @param {HTMLImageElement}  photoImg Already-loaded (naturalWidth/Height set).
     * @param {object}            data     focalX, focalY, zoom, issue, dateline, school, textColor, barColor, hairlineColor.
     * @return {boolean} True if something was drawn.
     */
    function draw(canvas, photoImg, data) {
        if (!canvas || !photoImg || !photoImg.naturalWidth || !photoImg.naturalHeight) {
            return false;
        }
        if (typeof window.ptkFocalCropRect !== 'function') {
            return false;
        }
        var ctx = canvas.getContext('2d');
        if (!ctx) {
            return false;
        }

        try {
            var rect = window.ptkFocalCropRect(
                photoImg.naturalWidth,
                photoImg.naturalHeight,
                SIZE / PHOTO_BAND_TOP,
                data.focalX,
                data.focalY,
                data.zoom
            );

            ctx.clearRect(0, 0, SIZE, SIZE);
            ctx.drawImage(
                photoImg,
                rect.x, rect.y, rect.w, rect.h,
                0, 0, SIZE, PHOTO_BAND_TOP
            );

            ctx.fillStyle = data.barColor;
            ctx.fillRect(0, PHOTO_BAND_TOP, SIZE, SIZE - PHOTO_BAND_TOP);

            drawPhotoWords(ctx, data);
            return true;
        } catch (err) {
            // Cross-origin taint, a decode error, whatever -- never let the
            // preview take the panel down with it.
            return false;
        }
    }

    /**
     * Load (and cache) the photo as an <img>, resolving once it has a
     * natural size. Cross-origin "tainting" is fine here -- this canvas is
     * never exported/read back, only drawn and displayed.
     *
     * @return {Promise<HTMLImageElement|null>}
     */
    var photoCache = {};
    function loadPhoto(src) {
        if (!src) {
            return Promise.resolve(null);
        }
        if (photoCache[src]) {
            return photoCache[src];
        }
        photoCache[src] = new Promise(function (resolve) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () {
                resolve(img);
            };
            img.onerror = function () {
                resolve(null);
            };
            img.src = src;
        });
        return photoCache[src];
    }

    /**
     * Public entry point: mount a live-updating preview on a
     * [data-share-canvas] element built by class-share-panel.php.
     * Everything after this call is driven by refresh(state) -- see the
     * returned controller.
     *
     * @param {HTMLCanvasElement} canvas
     * @return {{refresh: function(object): Promise<boolean>}|null}
     */
    window.ptkShareSquareCanvas = {
        mount: function (canvas) {
            if (!canvas) {
                return null;
            }
            var photoSrc = canvas.getAttribute('data-canvas-photo-src') || '';
            var photoPromise = loadPhoto(photoSrc);

            function refresh(overrides) {
                return Promise.all([loadFonts(), photoPromise]).then(function (results) {
                    var fontsOk = results[0];
                    var photoImg = results[1];
                    if (!fontsOk || !photoImg) {
                        return false;
                    }
                    var data = {
                        focalX: numAttr(canvas, 'data-canvas-focal-x', 50),
                        focalY: numAttr(canvas, 'data-canvas-focal-y', 50),
                        zoom: numAttr(canvas, 'data-canvas-zoom', 0),
                        issue: canvas.getAttribute('data-canvas-issue') || '',
                        dateline: canvas.getAttribute('data-canvas-dateline') || '',
                        school: canvas.getAttribute('data-canvas-school') || '',
                        textColor: canvas.getAttribute('data-canvas-text-color') || '#ffffff',
                        barColor: canvas.getAttribute('data-canvas-bar-color') || '#1a2f5c',
                        hairlineColor: canvas.getAttribute('data-canvas-hairline') || '#3a4f7c'
                    };
                    if (overrides) {
                        Object.keys(overrides).forEach(function (key) {
                            data[key] = overrides[key];
                        });
                    }
                    return draw(canvas, photoImg, data);
                }, function () {
                    return false;
                });
            }

            return { refresh: refresh };
        }
    };

    function numAttr(el, name, fallback) {
        var v = parseFloat(el.getAttribute(name));
        return isNaN(v) ? fallback : v;
    }

})();
