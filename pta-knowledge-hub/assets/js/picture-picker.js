/**
 * "Which picture?" -- our own picture picker. Replaces wp.media on the
 * screens that opt in (Create Entry's "+ a picture" chip, this pass).
 *
 * Public API, so a later screen (the Newsletter Builder) can reuse it
 * without a rewrite:
 *
 *   window.ptkPicturePicker.open({ onChoose: function (picture) {...} })
 *
 *   picture is { id, url, alt } -- the same shape the AJAX endpoints
 *   return. onChoose fires once, right before the modal closes.
 *
 * Everything here reads from `ptkPicturePicker` (ajaxUrl, nonce, perPage,
 * copy), localized by PTK_Picture_Picker::enqueue() -- only present on a
 * screen where the look is on and the picker is wired up.
 */
(function ($) {
    'use strict';

    if (typeof window.ptkPicturePicker === 'undefined' || !window.ptkPicturePicker) {
        return;
    }

    var cfg = window.ptkPicturePicker;
    var copy = cfg.copy || {};

    var $modal, $panel, $chooseStep, $altStep, $grid, $search, $loadMore,
        $drop, $fileInput, $progress, $altPreview, $altInput, $useBtn, $backBtn;

    var onChooseCallback = null;
    var lastFocused = null;
    var searchTimer = null;
    var currentPage = 1;
    var currentSearch = '';
    var loading = false;
    var selected = null; // { id, url, alt }

    function init() {
        $modal = $('#ptk-pic-modal');
        if (!$modal.length) {
            return;
        }
        $panel = $modal.find('.ptk-pic-modal-panel');
        $chooseStep = $('#ptk-pic-choose-step');
        $altStep = $('#ptk-pic-alt-step');
        $grid = $('#ptk-pic-grid');
        $search = $('#ptk-pic-search');
        $loadMore = $('#ptk-pic-load-more');
        $drop = $('#ptk-pic-drop');
        $fileInput = $('#ptk-pic-file-input');
        $progress = $('#ptk-pic-progress');
        $altPreview = $('#ptk-pic-alt-preview');
        $altInput = $('#ptk-pic-alt-input');
        $useBtn = $('#ptk-pic-use-btn');
        $backBtn = $('#ptk-pic-back');

        $modal.on('click', '[data-ptk-pic-close]', function (e) {
            e.preventDefault();
            close();
        });

        $modal.on('keydown', onModalKeydown);

        $search.on('input', function () {
            var val = $search.val();
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                currentSearch = val;
                currentPage = 1;
                loadGrid(true);
            }, 300);
        });

        $loadMore.on('click', function () {
            currentPage += 1;
            loadGrid(false);
        });

        $grid.on('click', '.ptk-pic-item', function () {
            var $btn = $(this);
            chooseExisting({
                id: $btn.data('id'),
                url: $btn.data('url'),
                alt: $btn.data('alt') || ''
            });
        });

        $fileInput.on('change', function () {
            var file = this.files && this.files[0];
            if (file) {
                uploadFile(file);
            }
            $fileInput.val('');
        });

        // Drag and drop, desktop only -- the file input already covers
        // phones (it opens the camera roll there).
        $drop.on('dragover', function (e) {
            e.preventDefault();
            $drop.addClass('ptk-pic-drop--active');
        });
        $drop.on('dragleave', function () {
            $drop.removeClass('ptk-pic-drop--active');
        });
        $drop.on('drop', function (e) {
            e.preventDefault();
            $drop.removeClass('ptk-pic-drop--active');
            var files = e.originalEvent && e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
            if (files && files[0]) {
                uploadFile(files[0]);
            }
        });

        $backBtn.on('click', function () {
            showChooseStep();
        });

        $useBtn.on('click', function () {
            saveAltAndFinish();
        });
    }

    function onModalKeydown(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            e.preventDefault();
            close();
            return;
        }
        if (e.key !== 'Tab' && e.keyCode !== 9) {
            return;
        }
        var $focusable = $panel.find('a, button, input, textarea, select')
            .filter(':visible')
            .not('[disabled]');
        if (!$focusable.length) {
            return;
        }
        var first = $focusable.get(0);
        var last = $focusable.get($focusable.length - 1);
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    function open(opts) {
        opts = opts || {};
        onChooseCallback = typeof opts.onChoose === 'function' ? opts.onChoose : null;
        lastFocused = document.activeElement;

        showChooseStep();
        $search.val('');
        currentSearch = '';
        currentPage = 1;
        $grid.empty();
        loadGrid(true);

        $modal.removeAttr('hidden');
        $('body').addClass('ptk-pic-modal-open');
        window.setTimeout(function () {
            $modal.find('.ptk-pic-modal-close').trigger('focus');
        }, 0);
    }

    function close() {
        $modal.attr('hidden', 'hidden');
        $('body').removeClass('ptk-pic-modal-open');
        onChooseCallback = null;
        selected = null;
        if (lastFocused && lastFocused.focus) {
            lastFocused.focus();
        }
    }

    function showChooseStep() {
        $chooseStep.removeAttr('hidden');
        $altStep.attr('hidden', 'hidden');
    }

    function showAltStep(picture) {
        selected = picture;
        $altPreview.empty().append(
            $('<img>').attr({ src: picture.url, alt: '' })
        );
        $altInput.val(picture.alt || '');
        $chooseStep.attr('hidden', 'hidden');
        $altStep.removeAttr('hidden');
        window.setTimeout(function () {
            $altInput.trigger('focus');
        }, 0);
    }

    function chooseExisting(picture) {
        showAltStep(picture);
    }

    function renderItems(items, append) {
        if (!append) {
            $grid.empty();
        }
        if (!append && !items.length) {
            var empty = currentSearch ? copy.empty_search : copy.empty_none;
            $grid.append($('<p>', { 'class': 'ptk-help ptk-pic-grid-empty', text: empty }));
            return;
        }
        items.forEach(function (item) {
            var label = item.alt && item.alt.length ? item.alt : (copy.picture_name || 'Picture');
            var $btn = $('<button>', {
                type: 'button',
                'class': 'ptk-pic-item',
                'aria-label': label
            }).data({ id: item.id, url: item.url, alt: item.alt || '' });
            $btn.append($('<img>').attr({ src: item.url, alt: '' }));
            $grid.append($btn);
        });
    }

    function loadGrid(replace) {
        if (loading) {
            return;
        }
        loading = true;
        $.post(cfg.ajaxUrl, {
            action: 'ptk_picture_list',
            nonce: cfg.nonce,
            search: currentSearch,
            page: currentPage
        }).done(function (res) {
            if (res && res.success) {
                renderItems(res.data.items || [], !replace);
                $loadMore.toggle(!!res.data.hasMore);
            }
        }).always(function () {
            loading = false;
        });
    }

    function uploadFile(file) {
        var data = new FormData();
        data.append('action', 'ptk_picture_save');
        data.append('nonce', cfg.nonce);
        data.append('file', file);

        $progress.text(copy.adding || '').removeAttr('hidden');
        $drop.find('.ptk-pic-pick-btn, #ptk-pic-file-input').prop('disabled', true);

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: data,
            processData: false,
            contentType: false
        }).done(function (res) {
            if (res && res.success) {
                showAltStep(res.data);
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : (copy.add_failed || '');
                $progress.text(msg);
                window.setTimeout(function () {
                    $progress.attr('hidden', 'hidden');
                }, 4000);
            }
        }).fail(function () {
            $progress.text(copy.add_failed || '');
        }).always(function () {
            $drop.find('.ptk-pic-pick-btn, #ptk-pic-file-input').prop('disabled', false);
            if ($progress.text() === (copy.adding || '')) {
                $progress.attr('hidden', 'hidden');
            }
        });
    }

    function saveAltAndFinish() {
        if (!selected) {
            return;
        }
        var alt = $altInput.val();
        $useBtn.prop('disabled', true);

        $.post(cfg.ajaxUrl, {
            action: 'ptk_picture_save',
            nonce: cfg.nonce,
            attachment_id: selected.id,
            alt: alt
        }).done(function (res) {
            if (res && res.success) {
                var picture = res.data;
                var callback = onChooseCallback;
                close();
                if (callback) {
                    callback(picture);
                }
            }
        }).always(function () {
            $useBtn.prop('disabled', false);
        });
    }

    $(function () {
        init();
    });

    window.ptkPicturePicker = window.ptkPicturePicker || {};
    window.ptkPicturePicker.open = open;
    window.ptkPicturePicker.close = close;
}(jQuery));
