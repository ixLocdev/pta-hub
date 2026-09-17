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

**Permissions never change.** Simple mode only hides chrome. Anything a person could not do before,
they still cannot do.

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

**Long screens fold.** A step shows one open section plus one-line summaries of the rest
("Coming up — 3 dates added"), so a step is never taller than one section plus a few rows. Opening a
section closes the previous one. The state is remembered per newsletter.

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
labels, help, buttons, tables. Both bundled with the plugin as the newsletter fonts already are —
no Google Fonts requests from the admin. System fallbacks: Georgia, then serif; system-ui, then
sans-serif.

**Scale.** Page title 28–30px Literata. Section question 20–21px Literata. Card title 15px Karla 700.
Body 14px. Help and meta 12.5px. Stamp 11.5px, `letter-spacing: .16em`, uppercase.

**Shape and space.** 12px radius on cards, 9px on fields and buttons, 4px on stamps. Spacing steps of
4: 8 / 12 / 16 / 22 / 28. Cards carry a full 1px `--ptk-line` border. **Never a left or right accent
bar** — full borders, fills, spacing or a stamp instead.

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
  `page_open()`, `card()`, `section_fold()`, `field()`, `stamp()`, `primary_button()`, `empty_state()`.
- **Scoped to the Hub.** Everything is namespaced `ptk-` and applied under a `ptk-hub` body class, so
  no other plugin's screens change.
- **Simple mode lives in `includes/class-simple-mode.php`** (menu filtering, admin-bar trimming,
  login redirect, the per-person switch).
- **Accessibility is part of the floor:** every field has a real label, focus is always visible in
  `--ptk-primary`, text meets 4.5:1 on its own background (the stamp colors are checked against
  `--ptk-bg` and white), stamps carry their meaning in text as well as color, and nothing relies on
  hover alone.
- **Tests:** pure helpers (contrast pairs, stamp markup, menu-filter decisions, Simple-mode defaults)
  get plain-PHP tests like the rest of the plugin.

---

## 8. What could go wrong

- **Hiding menus hides something someone needed.** Mitigation: "Show all of WordPress" is always
  visible, one click, and remembered.
- **A plugin notice lands inside a Hub screen anyway.** Mitigation: Hub screens print notices in one
  place at the top; anything that escapes is a bug to fix, not a reason to widen the design.
- **Two visual systems while we migrate.** Mitigation: build the shared CSS and helper first, then move
  screens one at a time; each moved screen is finished, not half-styled.
- **The serif looks decorative in dense screens.** Mitigation: Literata is for questions and titles
  only; everything a person types or scans is Karla.

---

## 9. Build order

1. **Foundations** — `hub.css` tokens and parts, `class-hub-ui.php`, bundled fonts, tests.
2. **Simple mode** — per-person setting, menu and admin-bar trimming, login landing, the switch.
3. **Home screen** — the six intentions, the waiting stamp, the quiet links, "I'm not sure where to
   start".
4. **Newsletter Builder** — the screen volunteers use weekly: foldable sections, question-style labels,
   human confirmations, the next-steps row.
5. **Newsletter settings + the entry wizard** — same parts, same words.
6. **Everything else** (vendors, glossary, suggestions, analytics) as it is touched.

Each step ships on its own and is verified in a real browser before the next starts.
