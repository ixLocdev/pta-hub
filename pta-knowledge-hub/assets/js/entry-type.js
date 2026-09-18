/**
 * JS port of includes/class-entry-type.php's PTK_Entry_Type -- kept in sync
 * by hand (tests/test-entry-type-js.mjs asserts the SAME cases as
 * tests/test-entry-type.php, the same convention as focal-point.js /
 * test-focal-point-js.mjs). Pure functions, no DOM, no jQuery, so they can
 * be unit-tested with plain node and used from content-wizard.js for the
 * quiet type line's live updates (Task 3) without duplicating the ordered
 * table in two places that could drift silently.
 */
(function (root) {
    'use strict';

    var FAQ_OPENERS = ['can i', 'do i', 'is', 'are', 'when', 'where', 'how much'];
    var POLICY_WORDS = ['rules', 'rule', 'policy', 'bylaws', 'allowed', 'must'];

    function looksLikeFaqQuestion(question) {
        var q = String(question || '').replace(/^\s+/, '');
        if (!q) {
            return false;
        }
        for (var i = 0; i < FAQ_OPENERS.length; i++) {
            var opener = FAQ_OPENERS[i];
            var candidate = q.slice(0, opener.length).toLowerCase();
            if (candidate === opener) {
                var next = q.charAt(opener.length);
                if (next === '' || " \t\r\n,.?!'".indexOf(next) !== -1) {
                    return true;
                }
            }
        }
        return false;
    }

    function looksLikeChecklist(answer) {
        var a = String(answer || '');
        if (!a.trim()) {
            return false;
        }
        var lines = a.split(/\r\n|\r|\n/);
        var dashCount = 0;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].replace(/^\s+/, '');
            if (!line) {
                continue;
            }
            if (line.charAt(0) === '-') {
                dashCount++;
            }
        }
        return dashCount >= 2;
    }

    function mentionsPolicy(question) {
        var q = String(question || '').toLowerCase();
        if (!q.trim()) {
            return false;
        }
        for (var i = 0; i < POLICY_WORDS.length; i++) {
            if (q.indexOf(POLICY_WORDS[i]) !== -1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Same ordered table as PTK_Entry_Type::guess() (class-entry-type.php),
     * row for row. See that file's docblock for the signals' meaning.
     */
    function ptkEntryTypeGuess(signals) {
        signals = signals || {};
        var cameFrom = signals.came_from || '';
        var stepCount = signals.step_count || 0;
        var hasDate = !!signals.has_date;
        var hasFileOrLink = !!signals.has_file_or_link;
        var question = signals.question || '';
        var answer = signals.answer || '';

        if (cameFrom === 'word') {
            return 'glossary';
        }
        if (stepCount >= 2) {
            return 'how-to-guide';
        }
        if (hasDate && stepCount >= 1) {
            return 'event-playbook';
        }
        if (hasFileOrLink && stepCount === 0 && !hasDate) {
            return 'resource';
        }
        if (looksLikeFaqQuestion(question)) {
            return 'faq';
        }
        if (looksLikeChecklist(answer)) {
            return 'checklist';
        }
        if (mentionsPolicy(question)) {
            return 'policy';
        }
        return 'faq';
    }

    var EXPLAIN = {
        'how-to-guide': 'so families see the steps as a numbered list',
        'faq': 'so families see a short, copy-friendly answer',
        'glossary': 'so the word gets a plain-English definition and shows up as a tooltip',
        'checklist': 'so families see it as a list of things to tick off',
        'event-playbook': "so families see the date, the timeline, and what's needed",
        'policy': 'so families see exactly what the rule says',
        'resource': 'so families can find and open the file or page'
    };

    function ptkEntryTypeExplain(slug) {
        return EXPLAIN.hasOwnProperty(slug) ? EXPLAIN[slug] : '';
    }

    var NAMES = {
        'how-to-guide': 'how-to guide',
        'faq': 'FAQ',
        'glossary': 'glossary term',
        'checklist': 'checklist',
        'event-playbook': 'event playbook',
        'policy': 'policy',
        'resource': 'resource'
    };

    function ptkEntryTypeName(slug) {
        return NAMES.hasOwnProperty(slug) ? NAMES[slug] : slug;
    }

    var api = {
        ptkEntryTypeGuess: ptkEntryTypeGuess,
        ptkEntryTypeExplain: ptkEntryTypeExplain,
        ptkEntryTypeName: ptkEntryTypeName
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.ptkEntryType = api;
    }
})(typeof window !== 'undefined' ? window : this);
