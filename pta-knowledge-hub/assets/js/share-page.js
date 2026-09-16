/**
 * PTA Knowledge Hub -- the phone page's copy buttons (PTK_Share_Page).
 *
 * Self-contained: no jQuery, no WordPress scripts are loaded on this page.
 *
 * DOM contract:
 *   [data-sp-channel]
 *     [data-sp-text]    the words to copy (plain text, pre-wrap)
 *     [data-sp-copy]    button
 *     [data-sp-status]  role=status line
 *
 * navigator.clipboard.writeText() needs a secure context and a real tap,
 * which a button press gives. Where it is missing or refused, fall back to
 * selecting the words (and trying the old execCommand copy); if even that
 * fails, leave the words selected and tell the volunteer to press and hold.
 */
(function () {
    'use strict';

    var CLEAR_AFTER = 4000;

    function say(status, message) {
        if (!status) {
            return;
        }
        status.textContent = message;
        if (status._ptkTimer) {
            window.clearTimeout(status._ptkTimer);
        }
        if (message) {
            status._ptkTimer = window.setTimeout(function () {
                status.textContent = '';
            }, CLEAR_AFTER);
        }
    }

    function selectNode(node) {
        try {
            var range = document.createRange();
            range.selectNodeContents(node);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            return true;
        } catch (e) {
            return false;
        }
    }

    function fallback(node, status) {
        var copied = false;
        if (selectNode(node)) {
            try {
                copied = document.execCommand && document.execCommand('copy');
            } catch (e) {
                copied = false;
            }
        }
        if (copied) {
            say(status, 'Copied. Now paste it into the app.');
        } else {
            // Leave the words selected so a press and hold finds them.
            if (status) {
                if (status._ptkTimer) {
                    window.clearTimeout(status._ptkTimer);
                }
                status.textContent = 'Press and hold the words above to copy them.';
            }
        }
    }

    function copy(node, status) {
        var text = node.textContent || '';
        if (navigator.clipboard && window.isSecureContext && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                say(status, 'Copied. Now paste it into the app.');
            }, function () {
                fallback(node, status);
            });
            return;
        }
        fallback(node, status);
    }

    function boot() {
        var channels = document.querySelectorAll('[data-sp-channel]');
        Array.prototype.forEach.call(channels, function (channel) {
            var button = channel.querySelector('[data-sp-copy]');
            var text = channel.querySelector('[data-sp-text]');
            var status = channel.querySelector('[data-sp-status]');
            if (!button || !text) {
                return;
            }
            button.addEventListener('click', function () {
                copy(text, status);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
