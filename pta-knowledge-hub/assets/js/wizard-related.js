/**
 * Wizard related-entries panel (v2.6.1).
 *
 * Watches the Wizard's category selection + title field. When both are
 * filled, queries the existing knowledge base for up to 3 similar
 * entries and renders them in the side panel. Clicking "Insert link"
 * appends a markdown-style link into the closest visible textarea
 * within the active wizard step.
 */
(function () {
    'use strict';

    if (typeof window.ptkWizardRelated === 'undefined') {
        return;
    }
    var cfg = window.ptkWizardRelated;

    document.addEventListener('DOMContentLoaded', function () {
        var panel = document.getElementById('ptk-wizard-related');
        if (!panel) return;

        var emptyEl = panel.querySelector('.ptk-wizard-related-empty');
        var listEl  = panel.querySelector('.ptk-wizard-related-list');
        var titleInput = document.querySelector('#ptk-wizard-form input[name="ptk_title"]');
        var categoryInputs = document.querySelectorAll('#ptk-wizard-form input[name="ptk_category"]');

        if (!titleInput || !categoryInputs.length) return;

        var debounceTimer = null;
        var lastQuery = '';

        function getCategory() {
            for (var i = 0; i < categoryInputs.length; i++) {
                if (categoryInputs[i].checked) return categoryInputs[i].value;
            }
            return '';
        }

        function setLoading() {
            emptyEl.hidden = true;
            listEl.hidden = false;
            // Replace children safely.
            while (listEl.firstChild) listEl.removeChild(listEl.firstChild);
            var p = document.createElement('p');
            p.className = 'ptk-wizard-related-loading';
            p.textContent = 'Looking…';
            listEl.appendChild(p);
        }

        function setEmpty() {
            listEl.hidden = true;
            emptyEl.hidden = false;
        }

        function renderResults(items) {
            while (listEl.firstChild) listEl.removeChild(listEl.firstChild);

            if (!items || !items.length) {
                listEl.hidden = true;
                emptyEl.hidden = false;
                emptyEl.textContent = 'No similar entries found yet — keep typing.';
                return;
            }

            emptyEl.hidden = true;
            listEl.hidden = false;

            items.forEach(function (item) {
                var card = document.createElement('div');
                card.className = 'ptk-wizard-related-item';

                var titleLink = document.createElement('a');
                titleLink.href = item.permalink;
                titleLink.target = '_blank';
                titleLink.rel = 'noopener';
                titleLink.className = 'ptk-wizard-related-itemtitle';
                titleLink.textContent = item.title;
                card.appendChild(titleLink);

                if (item.category) {
                    var cat = document.createElement('span');
                    cat.className = 'ptk-wizard-related-itemcat';
                    cat.textContent = item.category;
                    card.appendChild(cat);
                }

                if (item.excerpt) {
                    var ex = document.createElement('p');
                    ex.className = 'ptk-wizard-related-itemexcerpt';
                    ex.textContent = item.excerpt;
                    card.appendChild(ex);
                }

                var insertBtn = document.createElement('button');
                insertBtn.type = 'button';
                insertBtn.className = 'button button-small ptk-wizard-related-insert';
                insertBtn.textContent = 'Insert link';
                insertBtn.addEventListener('click', function () {
                    insertLink(item.title, item.permalink);
                });
                card.appendChild(insertBtn);

                listEl.appendChild(card);
            });
        }

        function findActiveTextarea() {
            // Prefer a textarea inside a currently-visible wizard step.
            var steps = document.querySelectorAll('.ptk-wizard-step');
            for (var i = 0; i < steps.length; i++) {
                var step = steps[i];
                if (step.classList.contains('ptk-hidden')) continue;
                if (step.offsetParent === null) continue; // hidden
                var ta = step.querySelector('textarea');
                if (ta) return ta;
            }
            // Fallback: first non-hidden textarea anywhere in the form.
            var all = document.querySelectorAll('#ptk-wizard-form textarea');
            for (var j = 0; j < all.length; j++) {
                if (all[j].offsetParent !== null) return all[j];
            }
            return null;
        }

        function insertLink(title, url) {
            var ta = findActiveTextarea();
            if (!ta) {
                window.alert('Click into a description field first, then try again.');
                return;
            }
            var snippet = '[' + title + '](' + url + ')';
            var current = ta.value || '';
            ta.value = current ? current + '\n' + snippet : snippet;
            ta.focus();
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function update() {
            var title = (titleInput.value || '').trim();
            var category = getCategory();

            if (title.length < 3 || !category) {
                setEmpty();
                return;
            }

            var key = category + '|' + title;
            if (key === lastQuery) return;
            lastQuery = key;

            setLoading();

            var data = new FormData();
            data.append('action', 'ptk_wizard_related');
            data.append('_wpnonce', cfg.nonce);
            data.append('title', title);
            data.append('edit_id', cfg.editId || 0);

            fetch(cfg.ajaxUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json && json.success) {
                        renderResults(json.data || []);
                    } else {
                        setEmpty();
                    }
                })
                .catch(function () {
                    setEmpty();
                });
        }

        function debouncedUpdate() {
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(update, 300);
        }

        titleInput.addEventListener('blur', update);
        titleInput.addEventListener('input', debouncedUpdate);
        for (var i = 0; i < categoryInputs.length; i++) {
            categoryInputs[i].addEventListener('change', update);
        }

        // Initial pass in case we're in edit mode and fields are pre-filled.
        update();
    });
})();
