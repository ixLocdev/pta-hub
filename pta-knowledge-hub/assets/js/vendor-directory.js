/**
 * PTA Knowledge Hub — Vendor Directory front-end.
 *
 * Client search filter + category tiles, star/thumbs rating inputs,
 * live character counters, and AJAX submits for the review and
 * suggest-a-vendor forms.
 *
 * Uses safe DOM methods only (createElement, textContent).
 */

(function () {
    "use strict";

    var wrap = document.querySelector(".ptk-vd-wrap");
    if (!wrap) return;

    function debounce(fn, ms) {
        var timer;
        return function () {
            var self = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    function toArray(list) {
        return Array.prototype.slice.call(list);
    }

    /* ============================================
       Directory: client search filter + category tiles
       ============================================ */

    var searchInput = wrap.querySelector(".ptk-vd-search");
    var cards = toArray(wrap.querySelectorAll(".ptk-vd-card"));
    var tiles = toArray(wrap.querySelectorAll(".ptk-vd-tile"));
    var emptySearch = wrap.querySelector(".ptk-vd-empty-search");
    var activeCategory = "";

    function applyFilter() {
        var query = searchInput ? searchInput.value.trim().toLowerCase() : "";
        var visible = 0;

        cards.forEach(function (card) {
            var haystack = card.getAttribute("data-search") || "";
            var matchesText = !query || haystack.indexOf(query) !== -1;
            var matchesCategory = !activeCategory || card.getAttribute("data-category") === activeCategory;
            var show = matchesText && matchesCategory;
            card.hidden = !show;
            if (show) visible++;
        });

        if (emptySearch) {
            emptySearch.hidden = visible > 0 || cards.length === 0;
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", debounce(applyFilter, 150));
    }

    tiles.forEach(function (tile) {
        tile.addEventListener("click", function () {
            var slug = tile.getAttribute("data-category");
            activeCategory = (activeCategory === slug) ? "" : slug;
            tiles.forEach(function (t) {
                t.setAttribute("aria-pressed", t.getAttribute("data-category") === activeCategory ? "true" : "false");
            });
            applyFilter();
        });
    });

    var clearBtn = wrap.querySelector(".ptk-vd-clear-search");
    if (clearBtn) {
        clearBtn.addEventListener("click", function () {
            if (searchInput) searchInput.value = "";
            activeCategory = "";
            tiles.forEach(function (t) { t.setAttribute("aria-pressed", "false"); });
            applyFilter();
            if (searchInput) searchInput.focus();
        });
    }

    /* ============================================
       Reveal buttons (Write a review / Suggest a vendor)
       ============================================ */

    toArray(wrap.querySelectorAll(".ptk-vd-reveal")).forEach(function (btn) {
        btn.addEventListener("click", function () {
            var target = document.getElementById(btn.getAttribute("data-target"));
            if (!target) return;
            target.hidden = false;
            btn.hidden = true;
            var first = target.querySelector("input:not([type=hidden]):not([tabindex='-1']), select, textarea, button");
            if (first) first.focus();
        });
    });

    /* ============================================
       Star widgets — buttons behind a hidden input
       ============================================ */

    toArray(wrap.querySelectorAll(".ptk-vd-stars")).forEach(function (group) {
        var input = group.querySelector("input[type=hidden]");
        var label = group.getAttribute("data-label") || "Rating";
        var stars = toArray(group.querySelectorAll(".ptk-vd-star"));
        if (!input || !stars.length) return;

        function paint(value) {
            stars.forEach(function (star, i) {
                var starValue = i + 1;
                star.classList.toggle("ptk-vd-star-on", starValue <= value);
                star.setAttribute("aria-pressed", starValue === value ? "true" : "false");
            });
            group.setAttribute(
                "aria-label",
                value ? label + ": " + value + " of 5 stars" : label + ": not rated yet"
            );
        }

        stars.forEach(function (star) {
            star.addEventListener("click", function () {
                input.value = star.getAttribute("data-value");
                paint(parseInt(input.value, 10) || 0);
            });
        });
    });

    /* ============================================
       Thumbs toggles — exclusive selection
       ============================================ */

    toArray(wrap.querySelectorAll(".ptk-vd-thumbs")).forEach(function (group) {
        var input = group.querySelector("input[type=hidden]");
        var buttons = toArray(group.querySelectorAll(".ptk-vd-thumb"));
        if (!input || !buttons.length) return;

        buttons.forEach(function (btn) {
            btn.addEventListener("click", function () {
                input.value = btn.getAttribute("data-value");
                buttons.forEach(function (b) {
                    b.setAttribute("aria-pressed", b === btn ? "true" : "false");
                });
            });
        });
    });

    /* ============================================
       Live character counters (suggestions-form pattern)
       ============================================ */

    toArray(wrap.querySelectorAll(".ptk-vd-count")).forEach(function (span) {
        var field = document.getElementById(span.getAttribute("data-target"));
        var max = parseInt(span.getAttribute("data-max"), 10);
        if (!field) return;
        function tick() {
            var len = field.value.length;
            span.textContent = len + " / " + max;
            span.classList.toggle("ptk-vd-count-warn", len > max - 20);
        }
        field.addEventListener("input", tick);
        tick();
    });

    /* ============================================
       Form submits (review + suggest)
       ============================================ */

    function fieldValue(form, name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value : "";
    }

    // Client-side pre-validation mirrors the server rules.
    function validateReviewFields(form) {
        var recommend = fieldValue(form, "ptk_recommend");
        if (recommend !== "1" && recommend !== "0") {
            return "Please choose whether you'd use them again.";
        }
        var price = parseInt(fieldValue(form, "ptk_price"), 10);
        if (!(price >= 1 && price <= 5)) {
            return "Please rate Price from 1 to 5 stars.";
        }
        var quality = parseInt(fieldValue(form, "ptk_quality"), 10);
        if (!(quality >= 1 && quality <= 5)) {
            return "Please rate Quality from 1 to 5 stars.";
        }
        var comment = fieldValue(form, "ptk_comment").trim();
        if (!comment) {
            return "Please share a few words about your experience.";
        }
        if (comment.length > 2000) {
            return "Your comment is a little long — please keep it under 2,000 characters.";
        }
        return "";
    }

    function validateSuggestFields(form) {
        var name = fieldValue(form, "vendor_name").trim();
        if (!name) {
            return "Please enter the vendor's name.";
        }
        if (name.length > 120) {
            return "The vendor name is a little long — please keep it under 120 characters.";
        }
        if (!fieldValue(form, "vendor_category")) {
            return "Please pick a category.";
        }
        var hasContact = fieldValue(form, "vendor_phone").trim() ||
            fieldValue(form, "vendor_email").trim() ||
            fieldValue(form, "vendor_website").trim();
        if (!hasContact) {
            return "Please add at least one way to contact them — a phone number, email, or website.";
        }
        return validateReviewFields(form);
    }

    toArray(wrap.querySelectorAll("form.ptk-vd-form")).forEach(function (form) {
        var errorBox = form.querySelector(".ptk-vd-form-error");
        var submit = form.querySelector(".ptk-vd-submit");
        var submitLabel = submit ? submit.textContent : "";
        var isSuggest = form.getAttribute("data-action") === "ptk_suggest_vendor";

        function showError(message) {
            if (errorBox) {
                errorBox.textContent = message;
                errorBox.hidden = false;
            }
            if (submit) {
                submit.disabled = false;
                submit.textContent = submitLabel;
            }
        }

        function showSuccess(message) {
            var done = document.createElement("p");
            done.className = "ptk-vd-form-success";
            done.setAttribute("role", "status");
            done.textContent = message;
            form.parentNode.replaceChild(done, form);
        }

        form.addEventListener("submit", function (e) {
            e.preventDefault();

            var problem = isSuggest ? validateSuggestFields(form) : validateReviewFields(form);
            if (problem) {
                showError(problem);
                return;
            }

            if (errorBox) errorBox.hidden = true;
            if (submit) {
                submit.disabled = true;
                submit.textContent = "Sending…";
            }

            var data = new FormData(form);
            data.append("action", form.getAttribute("data-action"));
            data.append("_wpnonce", window.ptkVendors ? window.ptkVendors.nonce : "");

            fetch(window.ptkVendors ? window.ptkVendors.ajaxUrl : "", {
                method: "POST",
                body: data,
                credentials: "same-origin"
            })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (json && json.success) {
                        showSuccess(json.data && json.data.message
                            ? json.data.message
                            : "Thanks! Your submission was sent to the PTA Council for approval.");
                    } else {
                        showError(json && json.data && json.data.message
                            ? json.data.message
                            : "Something went wrong. Please try again.");
                    }
                })
                .catch(function () {
                    showError("We couldn't send your review — check your internet connection and try again.");
                });
        });
    });
})();
