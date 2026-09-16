/**
 * PTA Knowledge Hub — "Share this newsletter" panel (Builder step 4).
 *
 * Deliberately its OWN file, never part of newsletter-builder.js: a throw in
 * the Builder's boot block leaves the whole wizard inert with no visible
 * error. Nothing here is called from the Builder, and everything here is
 * wrapped so a failure stays inside the panel.
 *
 * DOM contract (see PTK_Share_Panel::render()):
 *   [data-share-panel][data-post-id]
 *     details[data-share-channel][data-dirty?] -- collapsible, round 3.1
 *       [data-share-stale] [data-share-text] [data-share-copy]
 *       [data-share-reset] [data-share-status] [data-share-whatsapp?]
 *       [data-share-square] (instagram only; re-rendered by the server)
 *
 * The dirty rule: a channel saves only once it is dirty. It becomes dirty on
 * its first `input`, OR loads dirty (data-dirty="1" from PHP) when stored
 * text exists — otherwise the first reload after an edit would show the
 * stored text and then silently stop saving further edits. Reset clears it.
 *
 * Copy is self-contained: copy-button.js is a front-end asset and is not
 * enqueued on this admin screen.
 */
(function ($) {
    'use strict';

    var SAVE_DEBOUNCE = 800;
    var STATUS_CLEAR = 2500;

    $(function () {
        try {
            boot();
        } catch (err) {
            if (window.console && window.console.error) {
                window.console.error('Share panel failed to start:', err);
            }
        }
    });

    function boot() {
        if (typeof ptkNlShare === 'undefined' || !ptkNlShare || !ptkNlShare.ajaxUrl) {
            return;
        }

        var $panel = $('[data-share-panel]').first();
        if (!$panel.length) {
            return;
        }

        var postId = parseInt($panel.attr('data-post-id'), 10) || 0;
        if (!postId) {
            return;
        }

        $panel.find('[data-share-channel]').each(function () {
            bindChannel($(this), postId);
        });

        bindChannelDisclosures($panel);
        bindSquare($panel, postId);

        // Leaving the page (including via the Builder's own Publish/Save
        // submit) must not drop the last few keystrokes still waiting on the
        // debounce.
        $(window).on('pagehide', function () {
            $panel.find('[data-share-channel]').each(function () {
                var flush = $(this).data('ptkShareFlush');
                if (flush) {
                    flush();
                }
            });
        });
    }

    /**
     * Round 3.1 (spec item 5): "one open at a time" for the channel
     * <details> -- the browser gives collapse/expand for free, this just
     * closes every OTHER channel the moment one is opened, so the panel
     * never shows three full post-texts stacked at once.
     *
     * Bound directly to each <details> (not delegated on $panel): the
     * native `toggle` event historically does not bubble in every browser,
     * so a `$panel.on('toggle', '[data-share-channel]', ...)` delegation
     * would silently miss it in some of them. The channels are all
     * server-rendered and never added later, so direct binding costs
     * nothing.
     *
     * @param {jQuery} $panel [data-share-panel]
     */
    function bindChannelDisclosures($panel) {
        var $channels = $panel.find('[data-share-channel]');
        $channels.each(function () {
            var el = this;
            el.addEventListener('toggle', function () {
                if (!el.open) {
                    return;
                }
                $channels.each(function () {
                    if (this !== el && this.open) {
                        this.open = false;
                    }
                });
            });
        });
    }

    /* ──────────────────────────────────────────
     * Captions
     * ────────────────────────────────────────── */

    function bindChannel($section, postId) {
        var channel = $section.attr('data-share-channel');
        var $text = $section.find('[data-share-text]');
        var $status = $section.find('[data-share-status]');
        var $stale = $section.find('[data-share-stale]');
        var $whatsapp = $section.find('[data-share-whatsapp]');

        var timer = null;
        var pending = false;
        var inflight = null;
        // Bumped by every save and every reset: only the newest request's
        // reply may touch the status line.
        var seq = 0;
        var statusTimer = null;

        function isDirty() {
            return $section.attr('data-dirty') === '1';
        }

        function setStatus(msg, sticky) {
            clearTimeout(statusTimer);
            $status.text(msg);
            if (msg && !sticky) {
                statusTimer = setTimeout(function () {
                    $status.text('');
                }, STATUS_CLEAR);
            }
        }

        function updateWhatsapp(value) {
            if ($whatsapp.length) {
                $whatsapp.attr('href', 'https://wa.me/?text=' + encodeURIComponent(value));
            }
        }

        function payload(action) {
            return {
                action: action,
                nonce: ptkNlShare.nonce,
                post_id: postId,
                channel: channel
            };
        }

        function save() {
            timer = null;
            if (!isDirty()) {
                return;
            }
            pending = false;
            var mine = ++seq;
            var data = payload('ptk_nl_share_save');
            data.text = $text.val();

            setStatus('Saving…', true);
            inflight = $.post(ptkNlShare.ajaxUrl, data)
                .done(function (res) {
                    if (mine !== seq) {
                        return;
                    }
                    if (res && res.success) {
                        // Saved against today's newsletter, so it is no
                        // longer out of date — same as a reload would show.
                        $stale.prop('hidden', true);
                        setStatus('Saved');
                    } else {
                        setStatus(errorText(res, 'Not saved. Try typing again.'), true);
                    }
                })
                .fail(function (xhr) {
                    if (mine === seq) {
                        setStatus(errorText(xhr && xhr.responseJSON, 'Not saved — check your connection.'), true);
                    }
                })
                .always(function () {
                    inflight = null;
                });
        }

        $text.on('input', function () {
            $section.attr('data-dirty', '1');
            updateWhatsapp($text.val());
            pending = true;
            clearTimeout(timer);
            timer = setTimeout(save, SAVE_DEBOUNCE);
        });

        // Best-effort save on the way out. sendBeacon carries the admin
        // cookies and survives the page unloading; $.post would not.
        $section.data('ptkShareFlush', function () {
            if (!pending || !isDirty() || !navigator.sendBeacon || typeof FormData === 'undefined') {
                return;
            }
            clearTimeout(timer);
            pending = false;
            var data = payload('ptk_nl_share_save');
            data.text = $text.val();
            var form = new FormData();
            $.each(data, function (k, v) {
                form.append(k, v);
            });
            navigator.sendBeacon(ptkNlShare.ajaxUrl, form);
        });

        $section.on('click', '[data-share-copy]', function (e) {
            e.preventDefault();
            copyText($text.val(), $text).then(function () {
                setStatus('Copied');
            }, function () {
                $text.trigger('focus').trigger('select');
                setStatus('Press Ctrl+C (or ⌘+C) to copy', true);
            });
        });

        $section.on('click', '[data-share-reset]', function (e) {
            e.preventDefault();
            if (isDirty() && !window.confirm('Replace your edited text with freshly written text from the newsletter?')) {
                return;
            }

            // Cancel the waiting save, and invalidate any reply still on its
            // way, so an old save can never land after the reset.
            clearTimeout(timer);
            timer = null;
            pending = false;
            var mine = ++seq;
            var $button = $(this).prop('disabled', true);
            setStatus('Resetting…', true);

            // A save already sent must reach the server BEFORE the reset,
            // or it would re-store the text we are about to throw away.
            $.when(inflight).always(function () {
                $.post(ptkNlShare.ajaxUrl, payload('ptk_nl_share_reset'))
                    .done(function (res) {
                        if (mine !== seq) {
                            return;
                        }
                        if (res && res.success && res.data) {
                            $text.val(res.data.text);
                            $section.removeAttr('data-dirty');
                            $stale.prop('hidden', true);
                            updateWhatsapp(res.data.text);
                            setStatus('Back to the generated text');
                        } else {
                            setStatus(errorText(res, 'Could not reset. Try again.'), true);
                        }
                    })
                    .fail(function (xhr) {
                        if (mine === seq) {
                            setStatus(errorText(xhr && xhr.responseJSON, 'Could not reset — check your connection.'), true);
                        }
                    })
                    .always(function () {
                        $button.prop('disabled', false);
                    });
            });
        });
    }

    /**
     * Copy to the clipboard. Returns a jQuery promise.
     */
    function copyText(value, $source) {
        var d = $.Deferred();

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(function () {
                d.resolve();
            }, function () {
                legacyCopy($source) ? d.resolve() : d.reject();
            });
        } else {
            legacyCopy($source) ? d.resolve() : d.reject();
        }

        return d.promise();
    }

    function legacyCopy($source) {
        try {
            var el = $source.get(0);
            el.focus();
            el.select();
            return document.execCommand('copy');
        } catch (err) {
            return false;
        }
    }

    function errorText(res, fallback) {
        if (res && res.data && res.data.message) {
            return res.data.message;
        }
        return fallback;
    }

    /* ──────────────────────────────────────────
     * Instagram square
     * ────────────────────────────────────────── */

    function bindSquare($panel, postId) {
        var $area = $panel.find('[data-share-square]');
        if (!$area.length) {
            return;
        }

        var $status = $area.closest('[data-share-channel]').find('[data-share-status]');
        var frame = null;

        function send(data, busyText) {
            data.action = 'ptk_nl_share_square';
            data.nonce = ptkNlShare.nonce;
            data.post_id = postId;

            $area.addClass('is-busy').attr('aria-busy', 'true');
            $status.text(busyText);

            $.post(ptkNlShare.ajaxUrl, data)
                .done(function (res) {
                    if (res && res.success && res.data && typeof res.data.html === 'string') {
                        // Server-rendered, escaped markup: the same fragment
                        // the page load renders.
                        $area.html(res.data.html);
                        initPhotoPicker($area);
                        $status.text('Picture updated');
                    } else {
                        $status.text(errorText(res, 'The picture could not be changed.'));
                    }
                })
                .fail(function (xhr) {
                    $status.text(errorText(xhr && xhr.responseJSON, 'The picture could not be changed — check your connection.'));
                })
                .always(function () {
                    $area.removeClass('is-busy').removeAttr('aria-busy');
                });
        }

        // Delegated: the buttons are replaced whenever the area re-renders.
        $area.on('click', '[data-share-upload]', function (e) {
            e.preventDefault();

            if (typeof wp === 'undefined' || !wp.media) {
                $status.text('The media library is not available on this page. Reload and try again.');
                return;
            }

            // Our own frame, never the Builder's shared one: that one writes
            // its selection into a newsletter image field.
            if (!frame) {
                frame = wp.media({
                    title: 'Choose a square picture for Instagram',
                    button: { text: 'Use this picture' },
                    library: { type: 'image' },
                    multiple: false
                });

                frame.on('select', function () {
                    var picked = frame.state().get('selection').first();
                    if (!picked) {
                        return;
                    }
                    send({ mode: 'custom', attachment_id: picked.get('id') }, 'Changing the picture…');
                });
            }

            frame.open();
        });

        $area.on('click', '[data-share-generated]', function (e) {
            e.preventDefault();
            send({ mode: 'generated' }, 'Making the square…');
        });

        /* ──────────────────────────────────────────
         * "Use a photo behind the words" (round 3)
         * ────────────────────────────────────────── */

        var photoFrame = null;

        function piiOk($root) {
            var $checkbox = $root.find('[data-share-photo-pii-ok]');
            return $checkbox.length ? !!$checkbox.prop('checked') : true;
        }

        // A refusal (409, "confirm the photos first") surfaces as an
        // inline message next to the consent checkbox rather than the
        // channel's shared status line -- mirrors data-share-stale's own
        // inline-in-context placement.
        function handlePhotoRefusal(res, $root) {
            if (res && res.data && res.data.needsPii) {
                var $consent = $root.find('[data-share-photo-consent]');
                if ($consent.length && !$consent.find('.ptk-nl-share-photo-refused').length) {
                    $consent.append('<p class="ptk-nl-share-photo-refused" role="alert">' + $('<div>').text(res.data.message).html() + '</p>');
                }
                return true;
            }
            return false;
        }

        /**
         * @param {boolean} [quiet] True for a reframe save (drag/wheel/
         *   keyboard, mode=photo_reframe): the picker itself already shows
         *   the new framing locally, so re-rendering $area's whole HTML on
         *   every single change would tear down the very picker the
         *   volunteer is mid-drag on and steal keyboard focus off the dot.
         *   A quiet save skips the DOM replace and the busy/status churn --
         *   only a real photo CHANGE (new photo, removed, switched to
         *   custom) needs the picture area to visibly re-render.
         */
        // Every reframe save gets a number; only the newest one's reply may
        // touch the square's <img> -- two quick drags in a row must not let
        // an older reply's stale picture land after a newer one.
        var reframeSeq = 0;

        function sendPhoto($root, data, busyText, quiet) {
            if (!piiOk($root)) {
                data.pii_ok = 0;
            } else {
                data.pii_ok = 1;
            }
            data.action = 'ptk_nl_share_square';
            data.nonce = ptkNlShare.nonce;
            data.post_id = postId;

            if (!quiet) {
                $area.addClass('is-busy').attr('aria-busy', 'true');
                $status.text(busyText);
            }

            var mySeq = quiet ? ++reframeSeq : 0;

            $.post(ptkNlShare.ajaxUrl, data)
                .done(function (res) {
                    if (quiet && mySeq !== reframeSeq) {
                        return; // A newer reframe is already in flight or landed.
                    }
                    if (res && res.success && res.data && typeof res.data.html === 'string') {
                        if (quiet) {
                            // Saved silently; the picker's own DOM already reflects the
                            // NEW framing (that's what the volunteer is looking at), but
                            // the rendered square picture itself (round 3.1, spec item 3)
                            // still needs to catch up -- swap just the <img src>, never
                            // the whole area (that would tear down the picker mid-drag
                            // and steal keyboard focus off the dot).
                            updateSquareImage($area, res.data.html);
                            return;
                        }
                        $area.html(res.data.html);
                        initPhotoPicker($area);
                        $status.text('Picture updated');
                        return;
                    }
                    if (!handlePhotoRefusal(res, $root)) {
                        $status.text(errorText(res, 'The picture could not be changed.'));
                    }
                })
                .fail(function (xhr) {
                    if (!handlePhotoRefusal(xhr && xhr.responseJSON, $root)) {
                        $status.text(errorText(xhr && xhr.responseJSON, 'The picture could not be changed — check your connection.'));
                    }
                })
                .always(function () {
                    if (!quiet) {
                        $area.removeClass('is-busy').removeAttr('aria-busy');
                    }
                });
        }

        // The choice buttons start disabled (server-rendered) until the
        // consent checkbox is ticked -- otherwise a volunteer could click
        // straight through to a 409 refusal with no visible reason why.
        $area.on('change', '[data-share-photo-pii-ok]', function () {
            var $root = $(this).closest('[data-share-photo]');
            var checked = $(this).prop('checked');
            $root.find('[data-share-photo-featured], [data-share-photo-choose]').prop('disabled', !checked);
        });

        $area.on('click', '[data-share-photo-featured]', function (e) {
            e.preventDefault();
            var $root = $(this).closest('[data-share-photo]');
            sendPhoto($root, { mode: 'photo_from_featured' }, 'Using the top story’s photo…');
        });

        $area.on('click', '[data-share-photo-choose]', function (e) {
            e.preventDefault();
            var $root = $(this).closest('[data-share-photo]');

            if (typeof wp === 'undefined' || !wp.media) {
                $status.text('The media library is not available on this page. Reload and try again.');
                return;
            }

            if (!photoFrame) {
                photoFrame = wp.media({
                    title: 'Choose a photo for behind the words',
                    button: { text: 'Use this photo' },
                    library: { type: 'image' },
                    multiple: false
                });

                photoFrame.on('select', function () {
                    var picked = photoFrame.state().get('selection').first();
                    if (!picked) {
                        return;
                    }
                    sendPhoto($root, { mode: 'photo', attachment_id: picked.get('id'), focal_x: 50, focal_y: 50, zoom: 0 }, 'Adding the photo…');
                });
            }

            photoFrame.open();
        });

        $area.on('click', '[data-share-photo-remove]', function (e) {
            e.preventDefault();
            var $root = $(this).closest('[data-share-photo]');
            sendPhoto($root, { mode: 'no_photo' }, 'Removing the photo…');
        });

        // The focal-point-and-zoom picker itself, mounted once per render
        // when a photo is chosen -- reuses the SAME control the Builder's
        // image fields use (assets/js/focal-point-picker.js), at a 1:1
        // frame. Reframing/rezooming saves via mode=photo_reframe, which
        // needs no fresh consent (the photo itself isn't changing).
        var reframeTimer = null;
        $area.on('change', '[data-share-photo-picker-mount] [data-field]', function () {
            var $mount = $(this).closest('[data-share-photo-picker-mount]');
            var $root = $mount.closest('[data-share-photo]');
            window.clearTimeout(reframeTimer);
            // ~500ms after the LAST change (spec item 3) -- long enough that a
            // drag or a burst of scroll-wheel zoom ticks collapses into one
            // request instead of flooding the server with GD renders.
            reframeTimer = window.setTimeout(function () {
                sendPhoto($root, {
                    mode: 'photo_reframe',
                    focal_x: $mount.find('[data-field="image_focal_x"]').val(),
                    focal_y: $mount.find('[data-field="image_focal_y"]').val(),
                    zoom: $mount.find('[data-field="image_zoom"]').val()
                }, 'Saving the framing…', true);
            }, 500);
        });

        /* ──────────────────────────────────────────
         * "Text color" / "Bar color" for photo squares (round 3.1, item 4)
         * ────────────────────────────────────────── */

        var colorTimer = null;

        function syncPhotoHex($root, kind, value) {
            $root.find('[data-photo-color-hex="' + kind + '"]').val(value);
            $root.find('[data-photo-color-picker="' + kind + '"]').val(value);
        }

        $area.on('input change', '[data-photo-color-picker], [data-photo-color-hex]', function () {
            var $input = $(this);
            var kind = $input.attr('data-photo-color-picker') || $input.attr('data-photo-color-hex');
            var $colors = $input.closest('[data-photo-colors]');
            var value = $input.val();

            // A typed hex box and its matching native picker stay mirrored,
            // whichever one changed -- same pattern as Newsletter settings.
            if (/^#?[0-9a-fA-F]{6}$/.test(value)) {
                syncPhotoHex($colors, kind, value.charAt(0) === '#' ? value : '#' + value);
            }

            window.clearTimeout(colorTimer);
            colorTimer = window.setTimeout(function () {
                var textVal = $colors.find('[data-photo-color-hex="text"]').val();
                var barVal = $colors.find('[data-photo-color-hex="bar"]').val();
                $.post(ptkNlShare.ajaxUrl, {
                    action: 'ptk_nl_share_photo_colors',
                    nonce: ptkNlShare.nonce,
                    post_id: postId,
                    text_color: textVal,
                    bar_color: barVal
                }).done(function (res) {
                    if (res && res.success && res.data) {
                        if (typeof res.data.html === 'string') {
                            $area.html(res.data.html);
                            initPhotoPicker($area);
                        }
                    }
                });
            }, 500);
        });

        initPhotoPicker($area);
    }

    /**
     * Round 3.1 (spec item 3): after a quiet reframe save, swap just the
     * rendered square's <img src> for the freshly generated picture --
     * never the whole $area (that would tear down the picker the volunteer
     * is mid-drag on and steal keyboard focus off the dot). The new HTML
     * came back from the SAME server render the non-quiet path would use;
     * only the DOM update is different. Cache-busted with a timestamp: the
     * attachment id (and so its URL) stays the same on every reframe, so
     * without this the browser would keep showing the OLD picture from
     * cache.
     *
     * @param {jQuery} $area The [data-share-square] area.
     * @param {string} html  The server-rendered square markup.
     */
    function updateSquareImage($area, html) {
        var src = $('<div>').html(html).find('.ptk-nl-share-figure img').attr('src');
        if (!src) {
            return;
        }
        var busted = src + (src.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
        $area.find('.ptk-nl-share-figure img').attr('src', busted);
    }

    /**
     * Fetch the chosen photo's src and build the focal-point picker inside
     * [data-share-photo-picker-mount], if the current square render has
     * one. Called on boot and after every square re-render (send()'s done
     * handler replaces $area's HTML, which drops any picker DOM along with
     * it -- this rebuilds it fresh).
     */
    function initPhotoPicker($area) {
        var $mount = $area.find('[data-share-photo-picker-mount]');
        if (!$mount.length || typeof window.ptkInitFocalPicker !== 'function') {
            return;
        }
        var photoId = parseInt($mount.attr('data-photo-id'), 10) || 0;
        if (!photoId || typeof wp === 'undefined' || !wp.media || !wp.media.attachment) {
            return;
        }
        wp.media.attachment(photoId).fetch().done(function () {
            var attachment = wp.media.attachment(photoId).toJSON();
            var src = (attachment.sizes && attachment.sizes.large && attachment.sizes.large.url) || attachment.url;
            if (src) {
                window.ptkInitFocalPicker($mount, { aspect: '1:1', src: src });
            }
        });
    }

})(jQuery);
