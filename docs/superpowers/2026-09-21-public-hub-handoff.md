# The public Hub — handoff

**Updated 2026-10-02.** All four public surfaces are built: search (4.29.0),
single entry (4.30.0), glossary (4.31.0), vendor directory (4.32.0), plus the
shared "For PTA members" sign-in card (`ptk_members_only_markup()`, 4.32.0).
All on branch `public-hub`, committed, **not uploaded and not pushed**.
Next: Lucas looks at all four, then merge `public-hub` into `main`.

Notes from 4.31/4.32 worth keeping:
- The vendor wrapper carries both `.ptk-vd-wrap` (its own styles) and
  `.ptk-vendors-wrap` (public.css tokens).
- The vendor detail goes two-column on a **container query** (wrap >= 760px),
  not a media query: the default theme holds content to 645px on desktop.
- `ptk_glossary_terms` is one transient shared by the tooltips (unsorted) and
  the glossary page; the page now sorts on every read.

---

*Older notes below.*

---

## 1. Where things stand

**`main` is at 4.28.2** — `single-post` was merged (clean fast-forward, 20 commits)
and is what the live sites run. Not pushed.

**Branch `public-hub`, at 4.29.0** — the search page at `/knowledge-base` now
looks like the rest of the Hub. Committed, zip built at the repo root, **not
uploaded** (Lucas's call) and **not pushed**.

What 4.29.0 did:

- `assets/css/public.css` (new) — the eleven tokens and the two bundled fonts,
  declared on `.ptk-search-wrap` / `.ptk-entry-wrap` / `.ptk-glossary-wrap` /
  `.ptk-vendors-wrap`. `hub.css` puts them on `body.ptk-hub-look`, which is
  admin-only and behind a setting; the front end must not depend on it.
  **The next three surfaces build on this file and declare no color of their own.**
- `assets/css/search-page.css` — rewritten against those tokens, every class
  name kept, 813 lines of Tailwind defaults gone.
- Family-facing category names via `PTK_Shortcode::category_labels()`, mirrored
  into JS through `wp_localize_script`. **Presentation only — no taxonomy term is
  renamed**, and an unknown slug keeps its own name.
- "Ask us to write this" on the empty state, posting to the `ptk_submit_suggestion`
  endpoint `PTK_Suggestions` already owned. Verified end to end: the words arrive
  in *What families have asked for* with "Answer it" ready.
- **A bug older than this work:** `PTK_Glossary_Tooltips::replace_first_in_text()`
  skipped text inside an existing tooltip but not inside an existing **link**, so a
  glossary word within any link produced an anchor inside an anchor. Browsers close
  the outer one early and the markup around it falls apart — it was tearing the
  "Just added" cards into three pieces. Now tracked with `$link_depth`.

**Open question for Lucas, not yet answered:** clicking a "kind of thing" chip
before typing anything sets the filter but changes nothing on screen — the filter
only applies to search results. That was true before this work too. Worth deciding
whether a chip on its own should browse that kind.

**Still to do here:** the single entry, the glossary, then the vendor directory,
in that order, following the vocabulary the search page just set.

The repo still carries **eight of Lucas's own uncommitted edits** (`AGENTS.md`,
five plan docs under `docs/superpowers/plans/2026-07-*`, and
`assets/js/newsletter-relabel.js`). Never commit, stash or revert them; `git add`
only your own paths.

---

## 2. The job

Lucas, 2026-09-21: *"the user viewable hub feels like it was made before we
settled on this design. I would like to update it to make it feel like the rest
of the design."*

And, on shipping it: *"no worries on it being live as it's not live yet. just
one or two people right now."* So **this does not need its own switch.** The
public Hub is behind a members-only wall and has a handful of users; build it
straight, show him, iterate. That is a deliberate departure from
`ptk_hub_new_look`, which exists because the ADMIN screens are used daily by
volunteers at eleven schools. The public pages are not.

---

## 3. What "the public Hub" actually is

Four front-end surfaces, none of which share anything with the design system:

| Screen | Where | Built from |
|---|---|---|
| The Hub / search | `/knowledge-base` (page holding `[pta_search]`) | `templates/search-page.php`, `assets/css/search-page.css` (813 lines), `assets/js/search.js` |
| A single entry | any `pta_knowledge` post | `templates/single-pta_knowledge.php`, `assets/css/single.css` (592) |
| The glossary | `/glossary` (`[pta_glossary]`) | `includes/class-glossary-page.php`, `assets/css/glossary-page.css` (220) |
| The vendor directory | its own page (`[pta_vendors]`) | `includes/class-vendor-directory.php`, `assets/css/vendor-directory.css` (669) |

The page slugs come from the `ptk_hub_slug` / `ptk_glossary_slug` options and
are created per site by `PTK_Network_Provisioning::ensure_content_pages()`;
`ptk_hub_url()` / `ptk_glossary_url()` resolve them.

**Measured, not guessed:** those four stylesheets contain **zero** uses of
`var(--ptk-…)`. 2,294 lines with their own colors, type and spacing, written
before `hub.css` existed.

**Everything is members-only.** Logged out, `/knowledge-base` shows a padlock and
"Members Only". You cannot review these pages without signing in as a member —
use the Playground (below) rather than asking Lucas for a login.

---

## 4. The palette — settled 2026-09-21

**Lucas chose the admin teal `#356F8A`** — one palette across the whole plugin,
admin and public alike, not the newsletter's navy. The tokens in `public.css` are
the same eleven as `hub.css`. The remaining three surfaces use them; don't reopen
this.

Spec, mockup and plan for the search page, as the pattern for the next three:

- spec → `docs/superpowers/specs/2026-09-21-public-hub-search-design.md`
- mockup → `docs/superpowers/specs/mockups/2026-09-21-public-hub-search-mockup.html`
- plan → `docs/superpowers/plans/2026-09-21-public-hub-search-plan.md`

---

## 5. How to work on this (Lucas's standing rules)

- **Models:** Opus orchestrates; implementers sonnet, haiku for mechanical work.
  **No Fable.** Small edits: do them yourself.
- **Verify by clicking and typing like a person, never POST-only.**
- **NEVER one-sided borders** (no `border-left` / `border-right` accent bars).
  Plain English, US spelling, no `__()` wrappers, at most one stamp per screen,
  no WordPress jargon on screen.
- Tests, silent, before every commit:
  `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done && for f in tests/*.mjs; do node "$f" >/dev/null || echo FAIL $f; done`
- **Playground:** `preview_start` name `pta-hub-main`, always `http://127.0.0.1:9406`,
  never `localhost`. Log in with a throwaway `zz-login.php`
  (`require '/wordpress/wp-load.php'; wp_set_auth_cookie(1,true);`) and **delete
  every `zz-*` file before committing**. Another chat may already be holding the
  port; `preview_start` will say so, and navigating to the URL still works.
- Build the zip from the repo root:
  `zip -rq pta-knowledge-hub.zip pta-knowledge-hub -x "pta-knowledge-hub/tests/*" -x "*.DS_Store" -x "*/node_modules/*" -x "pta-knowledge-hub/.omc/*" -x "pta-knowledge-hub/.impeccable/*" -x "*/zz-*" -x "pta-knowledge-hub/docs/*"`
  then check nothing from `docs/`, `tests/` or `zz-*` is inside.
- Hand files over with `SendUserFile`; `file://` links do nothing for Lucas.
- Uploads are Lucas's call. **Do not push** unless he asks.

---

## 6. Traps this project has already paid for

- A `require_once` for a file a later task creates fatals every page on all
  eleven sites.
- `wp_insert_post()` runs content through `wp_kses_post` for anyone without
  `unfiltered_html` — on multisite, every school admin — which strips inline
  styles. Bypass with `kses_remove_filters()` / `kses_init_filters()` in a
  try/finally for trusted writes only, and **verify as a contributor**, not as
  yourself.
- Simple mode's menu trim used to make any Hub screen outside the menu return
  403; fixed in 4.28.2 by registering the fallback hook name. If a new screen
  404s or 403s for a Simple-mode user, that is the first thing to check.
- `content: " · "` collapses its spaces; put separators in the text.
- `text-wrap: pretty` is not reliable; use `PTK_Hub_UI::no_widow()`.
- PHP's alternate `if/endif` indentation leaks into output — prefer separate
  methods. So does the whitespace in front of a `<?php echo ?>` tag.
- The front end is **not** gated by `ptk_hub_new_look`, and should not be.

---

## 7. Also open, smaller

- **Merge `single-post` into `main`** (see §1). Probably first.
- **Milestone A — newsletter themes** (Editorial + a Fun one). Needs a brainstorm.
- **Milestone C — Google Workspace in the Hub.** Needs a brainstorm.
- **"I'm not sure where to start"** still routes the newsletter intention to the
  Builder; it matches an intention, not a button, so it cannot yet offer "Just
  one thing". Worth revisiting once posts are in real use.
- **Flipping `ptk_hub_new_look` on by default**, when Lucas is happy.
