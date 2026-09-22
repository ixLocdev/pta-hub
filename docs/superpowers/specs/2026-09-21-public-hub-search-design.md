# The Hub families see — the search page (design)

- **Date:** 2026-09-21
- **Status:** Awaiting Lucas's review of the mockup
- **Applies to:** `/knowledge-base` (`[pta_search]`) — `templates/search-page.php`,
  `assets/css/search-page.css`, `assets/js/search.js`
- **Mockup:** `docs/superpowers/specs/mockups/2026-09-21-public-hub-search-mockup.html`
- **Follows:** `2026-09-17-pta-hub-interface-design.md` (the admin standard), applied outward

---

## 1. What this is for

A family gets a newsletter, taps a link, and lands here. Today they land on a page built
before the design system existed: Tailwind's default blue `#2563eb`, the system font stack,
seven category colors, and Title Case product labels ("Best Answer", "Recently Added",
"Popular searches"). Measured: **zero** uses of `var(--ptk-…)` across the four public
stylesheets (2,294 lines).

This makes the search page look and read like the rest of the Hub. It is the first of the
four public surfaces, and it sets the vocabulary the other three (single entry, glossary,
vendor directory) will follow.

---

## 2. Decisions already made

| Question | Lucas's call (2026-09-21) |
|---|---|
| Palette | **Admin teal `#356F8A`.** One palette across the whole plugin — admin and public alike. Not the newsletter's navy. |
| Own switch? | **No.** The public Hub is members-only with a handful of users. Build it straight, show him, iterate. `ptk_hub_new_look` does not gate the front end and must not. |
| Where to start | **The search page**, because most people land there. |

---

## 3. The rules, applied to a page families read

The admin standard's ten rules were written for a volunteer doing a job. Six carry straight
over; the rest translate.

1. **Ask a question, don't label a field.** The page's own title becomes a question —
   "What do you need to know?", the family-facing twin of the home screen's "What would you
   like to do?"
2. **One action in `#356F8A`.** On this page that is the search box, and then, once there
   are results, "Read the whole answer" on the best match. Everything else is quiet.
3. **At most one stamp.** `START HERE` on the best answer. The star icon goes.
4. **No WordPress or product jargon on screen.** "Entry", "post", "category", "FAQ",
   "playbook", "resource" are all internal words. Families see what the thing *is*:
   *Questions families ask · How to do something · Running an event · Forms and files ·
   Words we use*.
5. **Say what happened, in their words.** "7 other things mention volunteer", not "7 results".
6. **NEVER one-sided borders.** Emphasis is a full four-sided border (the best answer), a
   background fill, or type weight.

---

## 4. Screen by screen

### 4.1 Before anyone types

- **Title:** "What do you need to know?" (Literata 34/1.2).
- **Lead:** what is in here, in one sentence naming real things — sign-ups, events,
  volunteering, where the money goes.
- **Search box:** full width, teal focus ring. Placeholder shows two real examples rather
  than "Search the PTA Hub…" — an example teaches, a label does not.
- **Browse chips** replace the filter button row. Same behavior, human names (§3.4),
  "Everything" selected.
- **Things families ask about** — today's "Popular searches:".
- **Just added** — today's "Recently Added", four cards, two columns.

### 4.2 After typing

- **Best answer** — the one card with a full teal border and the `START HERE` stamp: title,
  the answer itself in readable Literata, "Read the whole answer" (teal button) and
  "Copy this answer" (quiet). The copy button is kept; it is genuinely used.
- **"7 other things mention volunteer."** — one plain sentence where the result count was.
- **Groups**, in the existing order, headed by the human category name plus a count. Inside a
  group the badge is dropped (the heading already said it), exactly as today.
- **Card link text** stays per-category but in plain English: "Read the answer" ·
  "See the steps" · "Open it". The bare "View →" goes.

### 4.3 When nothing matches

- **"We haven't written that one yet"** replaces "No results found" — true, and it puts the
  gap on the PTA rather than on the family's spelling.
- The search words are quoted back, with a nudge to try fewer.
- **"Ask us to write this"** — a real button, filing the words as a topic suggestion into the
  screen the Council already has, *What families have asked for*
  (`class-asked-for-list.php`). This closes a loop that currently dead-ends, and the
  receiving screen already knows how to answer one ("Answer it" lands on Create Entry,
  prefilled).
- **"Closest we have"** — the existing did-you-mean / hint path, shown as cards rather than
  a line of text.

---

## 5. Category colors

Seven colors (`ptk-badge-howto`, `-event`, `-faq`, `-resource`, `-glossary`, `-checklist`,
`-policy`) become **one**. A badge is small-caps dim text; a group heading is a plain
heading. The category is a *word*, not a color — which is also the only version that works
for anyone who does not see the difference between the seven.

This is the largest visual change on the page and the one most worth a second look in the
mockup.

---

## 6. How it is built

- **A front-end token layer.** `hub.css` declares its eleven tokens on `body.ptk-hub-look`,
  which is admin-only and gated. The public page gets the same tokens declared on
  `.ptk-search-wrap` (and the equivalent wrapper on the other three surfaces) in a new
  `assets/css/public.css`, so nothing depends on the admin switch and nothing can bleed into
  the school's theme.
- **Literata and Karla are already bundled** as variable woff2 in `assets/fonts/`. The public
  sheet reuses them with its own `@font-face` — no Google Fonts request from a family's
  browser.
- **`search-page.css` is rewritten, not patched.** 813 lines of another system's decisions is
  more expensive to reconcile than to replace. The class names stay exactly as they are.
- **`search.js` changes only where it writes words** — link text, "Best Answer", the count
  sentence, the empty state — plus the one new "Ask us to write this" action. The search
  itself, the autocomplete, the filters and the network path are untouched.
- **`search-page.php`** changes copy and swaps the filter row for chips.
- **Scoped under `.ptk-search-wrap`**, every rule, so a school theme's own CSS is never
  touched and never touches this.

---

## 7. What this does not do

- Does not restyle the single entry, the glossary or the vendor directory. Those follow, in
  that order, once the vocabulary here is settled.
- Does not change what is searched, how it is ranked, or who can see it. Members-only stays
  members-only.
- Does not add its own setting.

---

## 8. Checks before it ships

- Tests silent: `for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done`
  and the `.mjs` ones.
- **Verified by clicking and typing like a person** in the Playground, logged in as a member —
  type a query, pick a chip, clear it, follow a card, use the copy button, trigger the empty
  state. Never POST-only.
- No `border-left` / `border-right` anywhere in the new CSS.
- The admin screens are untouched: `hub.css` unchanged, and the look-off proof still passes.
- Phone width: the two-column grid folds to one, nothing scrolls sideways.
