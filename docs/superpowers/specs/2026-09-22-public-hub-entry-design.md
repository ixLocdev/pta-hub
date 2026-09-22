# The Hub families see — one entry (design)

- **Date:** 2026-09-22
- **Status:** Awaiting Lucas's review of the mockup
- **Applies to:** any `pta_knowledge` post — `templates/single-pta_knowledge.php`,
  `assets/css/single.css`
- **Mockup:** `docs/superpowers/specs/mockups/2026-09-22-public-hub-entry-mockup.html`
- **Follows:** `2026-09-21-public-hub-search-design.md` (the vocabulary), which follows
  `2026-09-17-pta-hub-interface-design.md` (the standard)

---

## 1. What this is for

This is the page a newsletter link actually drops somebody on. They did not browse to it; they
tapped a link because they had a question, and this page either answers it in the first ten
seconds or it doesn't.

Measured: `single.css` is 592 lines, `#2563eb` blue and the system font stack, zero uses of
`var(--ptk-…)`. Same story as the search page.

**The biggest single change is not color.** It is that the words get set like something meant to
be read — Literata at 17.5px on a 66-character measure — instead of 15px system sans running the
full width of the container.

---

## 2. What is on the page, and what it becomes

| Today | Becomes | Why |
|---|---|---|
| "Back to Search" | **Back to the Hub** | It goes to the Hub, and the page they came from was often a newsletter, not a search. |
| Category badge in one of seven colors | Small caps, dim, no color | Matches the cards. The category is a word, not a color. |
| "Was this entry helpful?" | **Did this answer it?** | They came with a question. That's the question to ask back. |
| "Related Entries" | **More like this** | "Entry" is our word, not theirs. |
| "Copy Answer" / "Print This" | **Copy this answer** / **Print this** | Sentence case, same as everywhere else. |
| "Can't find what you need? Suggest a topic →" | **Not what you needed? / Ask us to write it** | The same words and the same destination as the search page's empty state, so it is one idea, not two. |
| "Shared by the PTA Council" banner | The page's **one stamp** | It is the only thing on this page that earns one. |
| Breadcrumb "Home / PTA Hub / FAQ" | Same, with the family-facing category name | Uses `PTK_Shortcode::category_label()`, already written. |
| The role-restricted screen, with `#4f46e5` inline | Tokens, and plainer words | It is the only raw hex left in the template. |

Unchanged: reading time, "Updated", tags, the print footer, and who can see what.

---

## 3. Reading typography

The part that matters most, written down so it doesn't drift:

- Body: Literata 17.5px / 1.68, `max-width: 66ch`.
- `h2` inside the content: 22px, 34px of space above, 12px below.
- Lists: 24px indent, 9px between items.
- The first paragraph is a lead at 20px / 1.55, weight 500.
- Phone: 16.5px body, 28px title.

---

## 4. Open question — "Quick Answer"

FAQ entries are written with a **"Quick Answer"** heading above the answer. It is a system word
on a family's screen, and on a page whose entire job is that one answer, the heading earns
nothing — state 1 of the mockup shows it gone, with the answer leading.

**But it lives in the entry's saved words, not in the template.** Removing it means changing
what is already written on eleven sites, which is a different kind of change from a stylesheet.
Three ways to go, and this is Lucas's call:

1. **Leave it.** Style it as a heading like any other. Nothing is touched. (Default if he doesn't say.)
2. **Hide it on screen only** — the template drops a leading `<h2>` whose text is exactly "Quick
   Answer" and promotes the paragraph after it to the lead. Reversible, touches no saved words,
   but it is the template quietly disagreeing with what the entry says.
3. **Change what new entries write**, and leave the existing ones alone. Slowest, cleanest, and
   the two look different until the old ones are edited.

---

## 5. How it is built

- `single.css` rewritten against the tokens in `public.css`, every class name kept.
- `public.css` gains `.ptk-single-wrap` — that is the wrapper the template actually uses. (The
  placeholder `.ptk-entry-wrap` in the token list goes; it was a guess made before this page
  was read.)
- The template changes words, drops the seven-color `$cat_css_map`, and uses
  `PTK_Shortcode::category_label()`.
- Scoped under `.ptk-single-wrap`, so the school's theme is untouched — except that the content
  itself is the theme's HTML (`the_content()`), which is why the content rules target plain
  `h2` / `p` / `ul` / `ol` inside the wrapper.

## 6. Checks before it ships

- Tests silent, both suites.
- Clicked and typed like a person: an FAQ entry, a long entry, a Council entry with the stamp,
  the copy button, "Did this answer it?" actually recording, and a related card.
- No `border-left` / `border-right`.
- Phone width: one column, no sideways scroll.
- The admin is untouched; `hub.css` unchanged.
