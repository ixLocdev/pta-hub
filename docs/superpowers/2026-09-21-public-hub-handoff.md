# The public Hub — handoff (2026-09-21, night)

**Start here.** The next piece of work is the part of the plugin **families**
see, which still looks the way it did before this year's redesign. Everything
below is what a new chat needs to pick it up.

---

## 1. Where things stand

**Branch `single-post`, at 4.28.2.** The "Put one thing on the website" feature
is finished (13 tasks), released, and Lucas has uploaded it to the live network.
Three releases in one evening:

| | |
|---|---|
| 4.28.0 | The feature: write one announcement, Your posts, edit + guard, remove + undo, the home-screen branch, the menu, the look-off proof. |
| 4.28.1 | The picture was printed twice — once as the template's banner, once in the words. |
| 4.28.2 | "Edit this post" in the toolbar; the focal point now aims the banner; "Change something you've already written"; the Start Here links explain themselves and cross to the public site. |

**`main` is still at 4.27.2 and has NOT been merged.** Lucas is running branch
builds on the live sites, so main is now behind what schools actually have.
**Ask about merging `single-post` into main before starting new work** — it is a
clean fast-forward and the longer it waits the more confusing it gets.

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

## 4. The decision to make first (brainstorm before building)

**There are two palettes in this project already, and the public Hub has to
pick one.**

- `assets/css/hub.css` — the admin redesign — uses `--ptk-primary: #356F8A`.
- The house style, the newsletter and `PTK_Post_Renderer` use navy `#1a2f5c`
  (`/Users/lucas/apps/PTA/HOUSE-STYLE.md`, memory "Design taste (PTA)").

Families already know the newsletter's navy from their inbox; volunteers know
the admin teal. My instinct is that the public Hub should be **the newsletter's
voice, not the admin's** — a family arriving from an email should feel they are
in the same place. But that is Lucas's call and it decides everything else, so
**brainstorm it with him before writing CSS** (superpowers:brainstorming), then
write a spec and a mockup the way the single post was done:

- spec → `docs/superpowers/specs/`
- mockup → `docs/superpowers/specs/mockups/` (a standalone HTML file he can open)
- plan → `docs/superpowers/plans/`

Start with the **search page**: it is where most people land, and it sets the
vocabulary the other three follow.

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
