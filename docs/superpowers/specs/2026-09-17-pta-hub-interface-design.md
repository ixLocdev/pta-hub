# PTA Hub — interface design (Milestone B)

- **Date:** 2026-09-17
- **Status:** Approved in brainstorm; awaiting written-spec review
- **Author:** Lucas Deichl (with Claude)
- **Applies to:** every PTA Hub admin screen in `pta-knowledge-hub`
- **Source material:** Lucas's `PTA_Zero_Training_Interface_Concept.docx`, and the mockups in
  `.superpowers/brainstorm/51232-1789666376/` (`designed-three.html`, `front-desk-palette.html`,
  `stamp-variants.html`, `stamp-nowrap.html`)

---

## 1. What this is for

A brand-new PTA volunteer should log in and know what to do within seconds, with no training and no
knowledge of WordPress. They are not maintaining a website; they are telling a capable PTA assistant
what is happening, what families need to know, and what families need to do.

This document is the standard every Hub screen follows from now on. It replaces ad-hoc styling
decisions made per feature. It is not itself a feature: §9 lists the work that applies it.

**Not in scope:** the public site, the newsletter's own look (that stays as it is — see the newsletter
handoff), and new abilities such as creating calendar events or editing existing pages. The assistant
voice applies now to what the Hub already does; new abilities are later rounds.

---

## 2. The rules, in one page

1. **Ask a question, don't label a field.** "When is it?", never "Event Start Date".
2. **Organize around what the volunteer wants to do,** not around what the system stores.
3. **Never ask what we can work out.** State the default and offer to change it.
4. **One action per screen** in `#356F8A`. Everything else is quiet.
5. **One section open at a time.** A finished section folds shut and says what's in it.
6. **Say what happened, in their words.** "You're all set. Families can read it here."
7. **Nothing goes out until they say so** — say it on the home screen, and mean it everywhere.
8. **Undo, not confirm,** wherever undo is possible.
9. **At most one stamp per screen.**
10. **System words are hidden, not removed.** They come back with "Show all of WordPress".

---

## 3. Simple mode

**The problem:** a volunteer logging into WordPress meets the whole admin — Posts, Media, Plugins,
Appearance, Tools, dozens of plugin menus — and none of it is their job.

**Simple mode** (per person, stored as user meta):

- Logging in lands on the Hub home.
- The admin menu shows Hub tasks only; the rest is hidden, not removed.
- The admin bar keeps the site name, the person's account, and nothing else.
- Screen options, help tabs, plugin notices and dashboard widgets from other plugins are hidden inside
  Hub screens.
- A quiet "Show all of WordPress" link, always present, turns it off for that person; "Back to the
  simple view" turns it on again. The choice sticks.

**Defaults:** on for everyone except site administrators. A site setting sets the default per role;
each person can still switch their own.

**How it hides.** `remove_menu_page()` / `remove_submenu_page()` on `admin_menu` (late priority),
checked per request against the current user — never CSS or JavaScript hiding, which leaves working
URLs behind a blank space and breaks when a menu slug changes. A hidden screen's URL still works if
somebody types it; it is hidden, not forbidden.

**Permissions never change.** Simple mode only hides chrome. Anything a person could not do before,
they still cannot do, and the capability checks on every screen are untouched.

**Multisite.** Simple mode is per person, per site: the same volunteer can have it on at Northeast and
off at the Council. Network Admin (`wp-admin/network/`) is out of scope — it is never trimmed, and the
"My Sites" switcher always stays in the admin bar so a Council admin can move between sites. The
per-role default is a per-site setting, so one school turning it off does not change another.

**People with nothing to do here.** A logged-in member below the Hub's minimum capability
(`edit_posts`) is not sent into `wp-admin` at all: they land on the public Hub page. They would
otherwise arrive at a home screen with none of its six choices available.

---

## 4. Shape of the app

**Home = intentions.** Six, each leading somewhere the Hub can already go:

| The volunteer picks | Goes to |
|---|---|
| Tell families what's happening | Newsletter Builder |
| Answer a question families keep asking | Entry wizard |
| Recommend someone we've used | Vendor directory |
| Explain a PTA word | Glossary |
| Fix something that's wrong | Find-and-change across what this site has written |
| I'm not sure where to start | A short "what are you trying to get done?" picker |

Under those: **one stamp** ("Waiting for you") with a plain sentence when something needs approval, and
two quiet links — "Set up the basics (once)" and "Show all of WordPress".

**Inside a task:** the task fills the screen. A single "← Back to the Hub" at the top, plus a short
"where to next" row at the foot of a finished task ("Tell families about it · Add another · I'm done").
No permanent menu to read while working.

**What counts as a Hub screen.** Exactly the admin pages this plugin registers (its `page_` hook
suffixes) plus its own post-type list and edit screens. Everything else, including core list tables
the Hub links out to, is ordinary WordPress and is left alone. Every rule in §3 and §5 uses that
definition.

**Long screens fold.** A step shows one open section plus one-line summaries of the rest
("Coming up — 3 dates added"), so a step is never taller than one section plus a few rows. Opening a
section closes the previous one. The state is remembered per newsletter.

In the Newsletter Builder this folding happens **inside** a step, to the blocks a step contains
(Announcement, Coming up, Quick notes). The five steps themselves stay exactly as they are, with their
existing step navigation, per-step validation summary and dirty-state guard. The live preview column
is never folded and never gains a step marker, matching the contract already noted in
`class-newsletter-builder.php`.

**On a phone.** Under 782px (WordPress's own admin breakpoint) the home screen's cards become one
column, a task's step navigation becomes a single "Step 2 of 5" line with next/previous, the live
preview moves below the form behind a "Preview" toggle rather than beside it, and tap targets stay at
least 44px. Nothing is hidden on small screens that is available on large ones.

---

## 5. The look

**Palette** (Lucas's, 2026-09-17). Each color has one job; nothing decorative.

| Token | Value | Used for |
|---|---|---|
| `--ptk-bg` | `#F8F9F7` | the page |
| `--ptk-surface` | `#FFFFFF` | cards, fields |
| `--ptk-primary` | `#356F8A` | the one action per screen, links, focus |
| `--ptk-primary-soft` | `#E7F1F5` | the task the person most likely came for |
| `--ptk-text` | `#243039` | body and headings |
| `--ptk-text-dim` | `#68747C` | help text, meta, summaries |
| `--ptk-success` | `#4E8A68` | done, saved, sent |
| `--ptk-warning` | `#C58A39` | waiting for you, over a soft length |
| `--ptk-error` | `#B85C5C` | something is actually wrong — rare |
| `--ptk-line` | `#E2E6E4` | hairlines and card borders |

**Type.** Literata (serif) asks the questions and sets page titles. Karla (sans) runs the interface:
labels, help, buttons, tables. Both are SIL Open Font License faces, bundled with the plugin the way
the newsletter's fonts already are, with their license files — no Google Fonts requests from the admin.
They are deliberately **not** the public house style (Libre Franklin + Newsreader, see HOUSE-STYLE.md):
the place where a volunteer works should not be mistaken for the newsletter families read, and the
Hub's look has to belong to every school rather than to Northeast. System fallbacks: Georgia, then serif; system-ui, then
sans-serif.

**Scale.** Page title 28–30px Literata. Section question 20–21px Literata. Card title 15px Karla 700.
Body 14px. Help and meta 12.5px. Stamp 11.5px, `letter-spacing: .16em`, uppercase.

**Shape and space.** 12px radius on cards, 9px on fields and buttons, 4px on stamps. Spacing steps of
4: 8 / 12 / 16 / 22 / 28. Cards carry a full 1px `--ptk-line` border. **Never a left or right accent
bar** — full borders, fills, spacing or a stamp instead.

**Contrast pairs that must pass.** These exact pairs are asserted in tests (WCAG AA, 4.5:1 for text,
3:1 for a stamp's border):

| Foreground | Background | Minimum |
|---|---|---|
| `--ptk-text` #243039 | `--ptk-bg` #F8F9F7 | 4.5:1 |
| `--ptk-text` #243039 | `--ptk-surface` #FFFFFF | 4.5:1 |
| `--ptk-text-dim` #68747C | `--ptk-bg` #F8F9F7 | 4.5:1 |
| `--ptk-text-dim` #68747C | `--ptk-surface` #FFFFFF | 4.5:1 |
| `--ptk-primary` #356F8A | `--ptk-bg` / `--ptk-surface` | 4.5:1 |
| white | `--ptk-primary` #356F8A (button) | 4.5:1 |
| `--ptk-primary` #356F8A | `--ptk-primary-soft` #E7F1F5 | 4.5:1 |
| `--ptk-success` / `--ptk-warning` / `--ptk-error` | `--ptk-bg` and `--ptk-surface` | 4.5:1 text, 3:1 border |

A pair that fails is darkened until it passes; the palette above is the starting point, not a reason to
ship unreadable text.

**WordPress admin color schemes.** A person's chosen admin color scheme still colors WordPress's own
chrome. Inside Hub screens this palette wins, deliberately, so the Hub looks the same for everyone.

**Stamps.** The Hub says what state something is in with one rotated, letterspaced outline mark
(−3°, 2px border, inset hairline, `white-space: nowrap`):

| Stamp | Color | Meaning |
|---|---|---|
| WAITING FOR YOU | warning | something needs a person; the count lives in the sentence beside it |
| SENT | success | published, families can see it |
| NOT SENT YET | dim | a draft; nobody sees it |
| EXAMPLE | error | a sample to look at, never to send |

One stamp per screen. Never two competing. Never a stamp on a button.

---

## 6. Words

**We ask:** What would you like to do? · What's happening? · When is it? · Where is it? · What should
families know? · Do families need to do anything? · Is there somewhere they should click? · What should
the button say? · Would a picture help? · Who should see this? · When should we stop showing this?

**We say:** Put it on the website · Send it out · Something changed? · Change the details ·
You're all set. Families can read it here. · Nothing goes out until you say so.

**We never say** (in Simple mode): Add New · Edit · Publish · Status · Metadata · Category · Taxonomy ·
Template · Permalink · Slug · Featured Image · Excerpt · Visibility · Author · "Event successfully
published".

**Also hidden in Simple mode:** Draft · Trash · Revision · Custom Fields · Screen Options.

**Translation and right-to-left are deferred.** Strings keep the plugin's text domain so nothing has to
be rewritten later, but no RTL stylesheet ships in this milestone; if one does, the stamp's rotation
mirrors (+3°) and the stamp vocabulary is translated, not transliterated.

**Defaults are stated, not asked:** "We'll stop showing this after November 19. Change that".

**Confirmation ends with a question:** what would you like to do next — with the two or three next
steps that actually make sense, plus "I'm done".

---

## 7. How it's built

- **One stylesheet, `assets/css/hub.css`,** holding the tokens above plus the shared parts: page
  header, card, foldable section, field, button, stamp, "waiting" row, empty state. Existing screen
  stylesheets (`newsletter-builder.css`, `share-panel.css`, `share-settings.css`, `welcome.css`) keep
  only what is genuinely theirs and use the tokens for everything else.
- **One PHP helper, `includes/class-hub-ui.php`,** rendering those parts so screens can't drift:
  `page_open()`, `card()`, `section_fold()`, `field()`, `stamp()`, `waiting_row()`, `primary_button()`,
  `next_steps()`, `empty_state()`.
- **Scoped to the Hub.** Everything is namespaced `ptk-` and applied under a `ptk-hub` body class, so
  no other plugin's screens change.
- **Simple mode lives in `includes/class-simple-mode.php`** (menu filtering, admin-bar trimming,
  login redirect, the per-person switch).
- **Other plugins' notices** are cleared on Hub screens only: on `in_admin_header`, remove every
  callback hooked to `admin_notices`, `all_admin_notices` and `network_admin_notices` except the Hub's
  own, then print the Hub's messages in one place under the page title. WordPress's own update and
  error notices for the current screen are kept.
- **Accessibility is part of the floor:** every field has a real label, focus is always visible in
  `--ptk-primary`, text meets 4.5:1 on its own background (the stamp colors are checked against
  `--ptk-bg` and white), stamps carry their meaning in text as well as color, and nothing relies on
  hover alone.
- **Tests:** plain-PHP tests like the rest of the plugin, covering the contrast table above (computed
  ratios, not eyeballed), the stamp markup (one per screen, `nowrap`, meaning present as text),
  the menu-filter decision (which slugs survive for which capabilities), Simple-mode defaults per role,
  the "below minimum capability" redirect choice, and the "is this a Hub screen" predicate.

---

## 8. What could go wrong

- **Hiding menus hides something someone needed.** Mitigation: "Show all of WordPress" is always
  visible, one click, and remembered.
- **A plugin notice lands inside a Hub screen anyway.** Mitigation: Hub screens print notices in one
  place at the top; anything that escapes is a bug to fix, not a reason to widen the design.
- **A half-migrated screen reaching every site.** One upload updates all 11 sites, so a screen is
  migrated completely or not at all in a given release: no screen ships with some parts restyled.
- **Two visual systems while we migrate.** Mitigation: build the shared CSS and helper first, then move
  screens one at a time; each moved screen is finished, not half-styled.
- **The serif looks decorative in dense screens.** Mitigation: Literata is for questions and titles
  only; everything a person types or scans is Karla.

---

## 9. Build order

1. **Foundations** — `hub.css` tokens and parts, `class-hub-ui.php`, bundled fonts, tests.
2. **Home screen** — the six intentions, the waiting stamp, the quiet links, "I'm not sure where to
   start". (Before Simple mode, because Simple mode's login landing needs somewhere good to land.)
3. **Simple mode** — per-person setting, menu and admin-bar trimming, login landing, the switch.
4. **Newsletter Builder** — the screen volunteers use weekly: foldable sections, question-style labels,
   human confirmations, the next-steps row.
5. **Newsletter settings + the entry wizard** — same parts, same words.
6. **Everything else** (vendors, glossary, suggestions, analytics) as it is touched.

Each step ships on its own and is verified in a real browser before the next starts.
