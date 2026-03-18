/**
 * PTA Knowledge Hub — Front-end Search Engine
 *
 * Handles: debounced AJAX search, rendering results as cards,
 * Best Answer highlighting, category filtering, suggested searches.
 *
 * All DOM construction uses safe methods (createElement, textContent)
 * to prevent XSS. No innerHTML with untrusted content.
 */

(function () {
    "use strict";

    // --- DOM Elements ---
    var input        = document.getElementById("ptk-search-input");
    var clearBtn     = document.getElementById("ptk-search-clear");
    var suggestedEl  = document.getElementById("ptk-suggested");
    var loadingEl    = document.getElementById("ptk-loading");
    var resultsEl    = document.getElementById("ptk-results");
    var bestEl       = document.getElementById("ptk-best-answer");
    var groupsEl     = document.getElementById("ptk-groups");
    var countEl      = document.getElementById("ptk-result-count");
    var emptyEl      = document.getElementById("ptk-empty");
    var filtersEl    = document.getElementById("ptk-category-filters");
    var recentEl     = document.getElementById("ptk-recent-section");

    if (!input) return;

    var debounceTimer = null;
    var activeFilter  = "all";
    var lastQuery     = "";

    var categoryNames = {
        "how-to-guide":   "How-To Guides",
        "event-playbook": "Event Playbooks",
        "faq":            "FAQs",
        "resource":       "Resources",
        "glossary":       "Glossary",
        "checklist":      "Checklists",
        "policy":         "Policies & Rules"
    };
    var categoryOrder = ["how-to-guide", "event-playbook", "faq", "resource", "glossary", "checklist", "policy"];

    // ==========================================
    //  Safe DOM Helpers
    // ==========================================

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function svgIcon(paths, w, h) {
        var ns = "http://www.w3.org/2000/svg";
        var svg = document.createElementNS(ns, "svg");
        svg.setAttribute("width", String(w || 14));
        svg.setAttribute("height", String(h || 14));
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("fill", "none");
        svg.setAttribute("stroke", "currentColor");
        svg.setAttribute("stroke-width", "2");
        svg.setAttribute("aria-hidden", "true");
        paths.forEach(function (d) {
            var path = document.createElementNS(ns, "path");
            path.setAttribute("d", d);
            svg.appendChild(path);
        });
        return svg;
    }

    function copySvg() {
        var ns = "http://www.w3.org/2000/svg";
        var svg = document.createElementNS(ns, "svg");
        svg.setAttribute("width", "14");
        svg.setAttribute("height", "14");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("fill", "none");
        svg.setAttribute("stroke", "currentColor");
        svg.setAttribute("stroke-width", "2");
        svg.setAttribute("aria-hidden", "true");
        var rect = document.createElementNS(ns, "rect");
        rect.setAttribute("x", "9");
        rect.setAttribute("y", "9");
        rect.setAttribute("width", "13");
        rect.setAttribute("height", "13");
        rect.setAttribute("rx", "2");
        svg.appendChild(rect);
        var p = document.createElementNS(ns, "path");
        p.setAttribute("d", "M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1");
        svg.appendChild(p);
        return svg;
    }

    function starSvg() {
        var ns = "http://www.w3.org/2000/svg";
        var svg = document.createElementNS(ns, "svg");
        svg.setAttribute("width", "14");
        svg.setAttribute("height", "14");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("fill", "currentColor");
        svg.setAttribute("aria-hidden", "true");
        var p = document.createElementNS(ns, "path");
        p.setAttribute("d", "M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z");
        svg.appendChild(p);
        return svg;
    }

    // ==========================================
    //  Event Listeners
    // ==========================================

    input.addEventListener("input", function () {
        var q = input.value.trim();
        clearBtn.style.display = q.length > 0 ? "flex" : "none";

        clearTimeout(debounceTimer);
        if (q.length === 0) {
            resetUI();
            return;
        }
        debounceTimer = setTimeout(function () {
            doSearch(q);
        }, 300);
    });

    clearBtn.addEventListener("click", function () {
        input.value = "";
        clearBtn.style.display = "none";
        resetUI();
        input.focus();
    });

    var suggestedTags = document.querySelectorAll(".ptk-suggested-tag");
    suggestedTags.forEach(function (tag) {
        tag.addEventListener("click", function () {
            var q = this.getAttribute("data-query");
            input.value = q;
            clearBtn.style.display = "flex";
            doSearch(q);
        });
    });

    if (filtersEl) {
        filtersEl.addEventListener("click", function (e) {
            var btn = e.target.closest(".ptk-filter-btn");
            if (!btn) return;

            filtersEl.querySelectorAll(".ptk-filter-btn").forEach(function (b) {
                b.classList.remove("ptk-filter-active");
                b.setAttribute("aria-pressed", "false");
            });
            btn.classList.add("ptk-filter-active");
            btn.setAttribute("aria-pressed", "true");
            activeFilter = btn.getAttribute("data-category");

            if (lastQuery) {
                doSearch(lastQuery);
            }
        });
    }

    // ==========================================
    //  Search
    // ==========================================

    var errorEl = document.getElementById("ptk-error");
    var hintEl  = document.getElementById("ptk-hint");

    function doSearch(query) {
        lastQuery = query;
        showLoading();

        var url = ptkSearch.ajaxUrl + "?action=pta_search&q=" + encodeURIComponent(query) + "&_wpnonce=" + encodeURIComponent(ptkSearch.nonce);

        fetch(url)
            .then(function (res) {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then(function (json) {
                hideLoading();
                if (json.success) {
                    renderResults(json.data);
                } else {
                    showEmpty(json.data && json.data.hint ? json.data.hint : null);
                }
            })
            .catch(function () {
                hideLoading();
                showError();
            });
    }

    // ==========================================
    //  Render Results
    // ==========================================

    function renderResults(data) {
        while (groupsEl.firstChild) groupsEl.removeChild(groupsEl.firstChild);
        while (bestEl.firstChild) bestEl.removeChild(bestEl.firstChild);
        bestEl.style.display = "none";

        var groups = data.groups || {};
        if (activeFilter !== "all") {
            var filtered = {};
            if (groups[activeFilter]) {
                filtered[activeFilter] = groups[activeFilter];
            }
            groups = filtered;
            if (data.bestAnswer && data.bestAnswer.category !== activeFilter) {
                data.bestAnswer = null;
            }
        }

        var totalVisible = 0;

        // Best Answer
        if (data.bestAnswer) {
            bestEl.appendChild(buildBestAnswerCard(data.bestAnswer));
            bestEl.style.display = "block";
            totalVisible++;
        }

        // Grouped results in order
        categoryOrder.forEach(function (catSlug) {
            if (!groups[catSlug] || groups[catSlug].length === 0) return;
            totalVisible += groups[catSlug].length;
            groupsEl.appendChild(buildGroup(catSlug, groups[catSlug]));
        });

        // Uncategorized
        Object.keys(groups).forEach(function (catSlug) {
            if (categoryOrder.indexOf(catSlug) !== -1) return;
            if (!groups[catSlug] || groups[catSlug].length === 0) return;
            totalVisible += groups[catSlug].length;
            groupsEl.appendChild(buildGroup(catSlug, groups[catSlug]));
        });

        if (totalVisible === 0) {
            // Show suggestions (all entries) if available.
            if (data.suggestions && data.suggestions.length > 0) {
                showSuggestions(data.suggestions, data.didYouMean || null);
            } else {
                showEmpty(null, data.didYouMean || null);
            }
            logSearch(lastQuery, 0);
            return;
        }

        countEl.textContent = totalVisible + " result" + (totalVisible !== 1 ? "s" : "") + " found";
        suggestedEl.style.display = "none";
        emptyEl.style.display = "none";
        resultsEl.style.display = "block";
        if (recentEl) recentEl.style.display = "none";

        logSearch(lastQuery, totalVisible);
    }

    // ==========================================
    //  DOM Card Builders
    // ==========================================

    function cssSuffix(cat) {
        return cat.replace("how-to-guide", "howto").replace("event-playbook", "event");
    }

    function linkText(cat) {
        if (cat === "how-to-guide") return "Read Steps \u2192";
        if (cat === "event-playbook") return "View Playbook \u2192";
        if (cat === "faq") return "Full Answer \u2192";
        if (cat === "resource") return "View Resource \u2192";
        if (cat === "glossary") return "Read Definition \u2192";
        if (cat === "checklist") return "View Checklist \u2192";
        if (cat === "policy") return "Read Policy \u2192";
        return "View \u2192";
    }

    function buildTagChips(tags, max) {
        if (!tags || tags.length === 0) return null;
        var wrap = el("div", "ptk-card-tags");
        tags.slice(0, max || 3).forEach(function (t) {
            wrap.appendChild(el("span", "ptk-card-tag", t));
        });
        return wrap;
    }

    function buildThumb(result) {
        if (!result.thumbnail) return null;
        var wrap = el("div", "ptk-card-thumb");
        var img = document.createElement("img");
        img.src = result.thumbnail;
        img.alt = result.title;
        img.loading = "lazy";
        wrap.appendChild(img);
        return wrap;
    }

    function buildCard(result, insideGroup) {
        var cat = result.category;
        var suffix = cssSuffix(cat);
        var isFaq = (cat === "faq");

        // FAQ cards use a div (not an anchor) because they have a copy button
        var card;
        if (isFaq) {
            card = el("div", "ptk-card ptk-card-" + suffix);
        } else {
            card = document.createElement("a");
            card.href = result.permalink;
            card.className = "ptk-card ptk-card-" + suffix;
        }

        // Badge — only show when NOT inside a category group (the heading already tells you)
        if (!insideGroup) {
            if (isFaq) {
                var header = el("div", "ptk-card-header");
                header.appendChild(el("span", "ptk-card-badge ptk-badge-" + suffix, result.catName));

                var copyBtn = document.createElement("button");
                copyBtn.className = "ptk-copy-btn";
                copyBtn.setAttribute("data-copy-text", result.excerpt);
                copyBtn.setAttribute("aria-label", "Copy answer");
                copyBtn.appendChild(copySvg());
                copyBtn.appendChild(el("span", "ptk-copy-label", "Copy"));
                header.appendChild(copyBtn);
                card.appendChild(header);
            } else {
                var badgeWrap = el("div", "ptk-card-badge-wrap");
                badgeWrap.appendChild(el("span", "ptk-card-badge ptk-badge-" + suffix, result.catName));
                card.appendChild(badgeWrap);
            }
        } else if (isFaq) {
            // Inside group but FAQ still needs the copy button (without badge)
            var header = el("div", "ptk-card-header");
            var copyBtn = document.createElement("button");
            copyBtn.className = "ptk-copy-btn";
            copyBtn.setAttribute("data-copy-text", result.excerpt);
            copyBtn.setAttribute("aria-label", "Copy answer");
            copyBtn.appendChild(copySvg());
            copyBtn.appendChild(el("span", "ptk-copy-label", "Copy"));
            header.appendChild(copyBtn);
            card.appendChild(header);
        }

        // Thumbnail (after badge, before body)
        var thumb = buildThumb(result);
        if (thumb) card.appendChild(thumb);

        // Body
        var body = el("div", "ptk-card-body");

        if (isFaq) {
            // Title as link
            var titleLink = document.createElement("a");
            titleLink.href = result.permalink;
            titleLink.className = "ptk-card-title-link";
            titleLink.appendChild(el("h3", "ptk-card-title", result.title));
            body.appendChild(titleLink);
        } else {
            body.appendChild(el("h3", "ptk-card-title", result.title));
        }

        body.appendChild(el("p", "ptk-card-excerpt", result.excerpt));

        var tags = buildTagChips(result.tags, 3);
        if (tags) body.appendChild(tags);

        if (isFaq) {
            var faqLink = document.createElement("a");
            faqLink.href = result.permalink;
            faqLink.className = "ptk-card-link ptk-link-" + suffix;
            faqLink.textContent = linkText(cat);
            body.appendChild(faqLink);
        } else {
            body.appendChild(el("span", "ptk-card-link ptk-link-" + suffix, linkText(cat)));
        }

        card.appendChild(body);
        return card;
    }

    function buildBestAnswerCard(result) {
        var card = el("div", "ptk-best-answer-card");

        // Label
        var label = el("div", "ptk-best-answer-label");
        label.appendChild(starSvg());
        label.appendChild(document.createTextNode(" Best Answer"));
        card.appendChild(label);

        // Title
        card.appendChild(el("h3", "ptk-card-title", result.title));

        // Excerpt
        card.appendChild(el("p", "ptk-card-excerpt", result.excerpt));

        // Tags
        var tags = buildTagChips(result.tags, 4);
        if (tags) card.appendChild(tags);

        // CTA link
        var link = document.createElement("a");
        link.href = result.permalink;
        link.className = "ptk-best-answer-link";
        link.textContent = "Read Full Entry \u2192";
        card.appendChild(link);

        return card;
    }

    function buildGroup(catSlug, items) {
        var suffix = cssSuffix(catSlug);
        var groupDiv = el("div", "ptk-group");
        var heading = el("h3", "ptk-group-title ptk-group-title-" + suffix, categoryNames[catSlug] || catSlug);
        groupDiv.appendChild(heading);

        var grid = el("div", "ptk-group-cards");
        items.forEach(function (result) {
            grid.appendChild(buildCard(result, true));
        });
        groupDiv.appendChild(grid);

        return groupDiv;
    }

    // ==========================================
    //  UI State Helpers
    // ==========================================

    function resetUI() {
        lastQuery = "";
        resultsEl.style.display = "none";
        emptyEl.style.display = "none";
        loadingEl.style.display = "none";
        suggestedEl.style.display = "block";
        bestEl.style.display = "none";
        if (recentEl) recentEl.style.display = "block";
        if (filtersEl) filtersEl.style.display = "flex";
        if (errorEl) errorEl.style.display = "none";
        if (hintEl) hintEl.style.display = "none";
        while (groupsEl.firstChild) groupsEl.removeChild(groupsEl.firstChild);
    }

    function showLoading() {
        loadingEl.style.display = "block";
        resultsEl.style.display = "none";
        emptyEl.style.display = "none";
        suggestedEl.style.display = "none";
        if (recentEl) recentEl.style.display = "none";
    }

    function hideLoading() {
        loadingEl.style.display = "none";
    }

    var didYouMeanEl = document.getElementById("ptk-did-you-mean");

    function showEmpty(hint, didYouMean) {
        emptyEl.style.display = "block";
        resultsEl.style.display = "none";
        suggestedEl.style.display = "none";
        if (recentEl) recentEl.style.display = "none";
        if (errorEl) errorEl.style.display = "none";
        if (hintEl) {
            if (hint) {
                hintEl.textContent = hint;
                hintEl.style.display = "block";
            } else {
                hintEl.style.display = "none";
            }
        }
        if (didYouMeanEl) {
            if (didYouMean) {
                while (didYouMeanEl.firstChild) didYouMeanEl.removeChild(didYouMeanEl.firstChild);
                didYouMeanEl.appendChild(document.createTextNode("Did you mean: "));
                var link = document.createElement("button");
                link.className = "ptk-dym-link";
                link.textContent = didYouMean;
                link.addEventListener("click", function () {
                    input.value = didYouMean;
                    clearBtn.style.display = "flex";
                    doSearch(didYouMean);
                });
                didYouMeanEl.appendChild(link);
                didYouMeanEl.appendChild(document.createTextNode("?"));
                didYouMeanEl.style.display = "block";
            } else {
                didYouMeanEl.style.display = "none";
            }
        }
    }

    function showSuggestions(suggestions, didYouMean) {
        // Show the results area with a "no exact matches" message + browseable cards.
        emptyEl.style.display = "none";
        suggestedEl.style.display = "none";
        resultsEl.style.display = "block";
        if (errorEl) errorEl.style.display = "none";

        countEl.textContent = "No exact matches \u2014 browse available entries:";

        while (bestEl.firstChild) bestEl.removeChild(bestEl.firstChild);
        bestEl.style.display = "none";
        while (groupsEl.firstChild) groupsEl.removeChild(groupsEl.firstChild);

        // Did you mean?
        if (didYouMeanEl && didYouMean) {
            while (didYouMeanEl.firstChild) didYouMeanEl.removeChild(didYouMeanEl.firstChild);
            didYouMeanEl.appendChild(document.createTextNode("Did you mean: "));
            var link = document.createElement("button");
            link.className = "ptk-dym-link";
            link.textContent = didYouMean;
            link.addEventListener("click", function () {
                input.value = didYouMean;
                clearBtn.style.display = "flex";
                doSearch(didYouMean);
            });
            didYouMeanEl.appendChild(link);
            didYouMeanEl.appendChild(document.createTextNode("?"));
            didYouMeanEl.style.display = "block";
        }

        // Group suggestions by category.
        var grouped = {};
        suggestions.forEach(function (result) {
            var cat = result.category || "uncategorized";
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(result);
        });

        categoryOrder.forEach(function (catSlug) {
            if (!grouped[catSlug] || grouped[catSlug].length === 0) return;
            groupsEl.appendChild(buildGroup(catSlug, grouped[catSlug]));
        });

        Object.keys(grouped).forEach(function (catSlug) {
            if (categoryOrder.indexOf(catSlug) !== -1) return;
            if (!grouped[catSlug] || grouped[catSlug].length === 0) return;
            groupsEl.appendChild(buildGroup(catSlug, grouped[catSlug]));
        });
    }

    function showError() {
        if (errorEl) errorEl.style.display = "block";
        emptyEl.style.display = "none";
        resultsEl.style.display = "none";
        suggestedEl.style.display = "none";
        if (recentEl) recentEl.style.display = "none";
    }

    /**
     * Fire-and-forget: log search query for analytics.
     * Only logs if the query differs from the last logged query
     * to avoid polluting analytics with intermediate keystrokes.
     */
    var lastLoggedQuery = "";
    function logSearch(query, count) {
        if (!ptkSearch.nonce) return;
        if (query === lastLoggedQuery) return;
        lastLoggedQuery = query;
        var body = new FormData();
        body.append("action", "pta_search_log");
        body.append("_wpnonce", ptkSearch.nonce);
        body.append("query", query);
        body.append("count", String(count));
        fetch(ptkSearch.ajaxUrl, { method: "POST", body: body }).catch(function () {});
    }


})();
