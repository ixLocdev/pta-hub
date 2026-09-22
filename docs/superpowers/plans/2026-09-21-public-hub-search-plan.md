# The Hub families see — the search page (plan)

- **Date:** 2026-09-21
- **Spec:** `docs/superpowers/specs/2026-09-21-public-hub-search-design.md`
- **Mockup:** `docs/superpowers/specs/mockups/2026-09-21-public-hub-search-mockup.html`
- **Approved by Lucas:** 2026-09-21 — build it all, including "Ask us to write this".
- **Branch:** `public-hub` off `main` (4.28.2)

---

## The shape of it

Seven tasks. The class names on the page do not change, so the work is mostly one new
stylesheet, one rewritten one, and the words in two files.

| # | What | Files |
|---|---|---|
| 1 | The front-end token layer — the eleven tokens and the two bundled fonts, declared on the public wrappers instead of `body.ptk-hub-look`, so nothing depends on the admin switch. Shared by all four public surfaces. | `assets/css/public.css` (new) |
| 2 | Rewrite the search page's own CSS against those tokens. Every class kept, every rule scoped under `.ptk-search-wrap`, the four stray unscoped selectors brought inside. Seven category colors collapse to one. | `assets/css/search-page.css` (replaced) |
| 3 | Family-facing category names, presentation only. A slug → words map in PHP and the matching values in the JS map already there. **No taxonomy term is renamed** — that is stored data on eleven sites. Unknown slugs fall back to the term's own name. | `includes/class-shortcode.php`, `assets/js/search.js` |
| 4 | The template's words and the chip row: the question title, the example placeholder, "Things families ask about", "Just added". | `templates/search-page.php` |
| 5 | The words the JS writes: link text per category, `START HERE` in place of "Best Answer" and its star, the count sentence, the empty state. | `assets/js/search.js` |
| 6 | "Ask us to write this" — posts the search words to the **existing** `ptk_submit_suggestion` endpoint (nonce, honeypot, 3/hour rate limit already there), which lands them in *What families have asked for*. Needs the nonce localized and one handler. | `includes/class-shortcode.php`, `assets/js/search.js` |
| 7 | Version, changelog, readme. | `pta-knowledge-hub.php`, `CHANGELOG.md`, `readme.txt` |

## Order

1 → 2 → 4 → 3 → 5 → 6 → verify → 7.

## Checks that gate the commit

- `for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done`, and the `.mjs` ones.
- No `border-left` / `border-right` in either stylesheet.
- **Clicked and typed like a person** in the Playground as a member: type, wait for results,
  pick a chip, clear, follow a card, copy an answer, hit the empty state, send a suggestion,
  confirm it arrives in *What families have asked for*.
- `hub.css` untouched and the admin look-off proof still passes — this work must not reach
  an admin screen.
- Phone width: one column, no sideways scroll.

## Deliberately not here

- The single entry, the glossary and the vendor directory — next, in that order.
- Any change to ranking, access or what is searched.
- Its own setting. There isn't one and there shouldn't be.
