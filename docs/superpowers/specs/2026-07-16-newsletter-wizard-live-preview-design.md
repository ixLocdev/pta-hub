# PTA HUB — Newsletter Builder: Stepped Wizard + Live Preview (Design Spec)

- **Date:** 2026-07-16
- **Status:** Approved design, pre-implementation
- **Author:** Lucas Deichl (with Claude)
- **Component:** Rework of the Newsletter Builder admin screen (`pta-knowledge-hub`)
- **Follows:** `2026-07-16-newsletter-builder-design.md` (Phase 1, shipped & merged)

---

## 1. Goal

Make the newsletter builder genuinely approachable. Phase 1 shipped a working
builder, but it renders **every block and every field on one long page** (seven
stacked cards). That contradicts the parent spec's own guiding principle — *plain
English, low cognitive load, never mental overload* — and the original approved
mockup actually showed a **stepped wizard** ("Step 2 of 4"). Building the
single-page form instead is the gap this spec closes.

Two changes, one project:

1. **A stepped wizard** — one focused step at a time, with a sidebar outline you
   can click to jump anywhere.
2. **A live preview** — the newsletter builds itself beside you as you type, so
   you always see what you're making.

They ship together because they solve the same problem: the preview is a large
part of what makes a nervous volunteer feel safe ("I can see what I'm making").

**Success:** a parent volunteering for the first time can open "Add New" and not
feel daunted; a weekly editor can still change one date in seconds.

## 2. Context

- Phase 1 is merged to `main` (not released). The builder works end-to-end:
  guided form → draft → preview → PII check → publish.
- Lucas's own read of the shipped screen: *"the layout and how it presents itself
  right now is a little daunting."* If it's daunting to the person who designed
  the newsletter it's based on, it will be worse for a first-week volunteer.
- Already auto-filled by Phase 1 (do NOT re-plan): issue number auto-increments
  from the last issue (editable), issue date defaults to today, the masthead
  headline auto-derives to "Week of …", the school name pre-fills from the
  school's own sub-site name.

## 3. Scope

### In scope

1. Replace the single-page form with a **4-step wizard** + sidebar outline.
2. **Live preview** panel, updating as the user types, highlighting the section
   being edited.
3. **Write-then-arrange:** steps 1–3 are purely writing; arranging (drag + a
   keyboard-accessible alternative) and removing sections happen on step 4.
4. Move the photo/PII check, Save draft / Publish, and the share-a-preview panel
   onto step 4.
5. Keep every Phase 1 behaviour working: the block data model, sanitizing,
   rendering, save handler, PII gate, edit round-trip, share-a-preview.

### Out of scope

- Any change to the newsletter's **output** (the renderer, the block model, the
  saved meta). This is an authoring-UX rework only.
- Theme presets / typography, Council push, duplicate-last-issue, the
  carried-over guard, email/Givebacks — all remain later phases.
- Onboarding tours, tooltips-everywhere, or a help system.

## 4. The Four Steps

Sidebar lists exactly these four; clicking any jumps straight to it. A step is
never "locked" — a weekly editor can go straight to step 4.

| Step | Title | Contains |
|------|-------|----------|
| 1 | **The basics** | Issue number, issue date, school name, headline, greeting. |
| 2 | **What's happening** | Key announcement (optional) + Upcoming events (repeatable). |
| 3 | **Stories** | Featured story (optional) + Story cards (repeatable). |
| 4 | **Finish & publish** | Running order (arrange/remove), footer, photo/PII check, Save draft, Share a preview link, Publish. |

- Step 1 is deliberately light: nearly everything in it auto-fills, so the user
  mostly confirms and writes a greeting. **A fast, confident start is a feature,
  not a gap** — do not pad it.
- Each step opens with one plain-English line saying what it's for and that
  optional parts can be skipped (e.g. *"The dates and the one big thing families
  shouldn't miss. Skip anything you don't need."*).
- The footer lives on step 4 (it's set-once-ish, not weekly writing).

**Why grouped, not one-step-per-block:** seven steps reads as a form that won't
end, and "announcement" and "events" are one mental task — *what's going on this
week*. Four reads as short.

## 5. Write First, Arrange After (a decided tension)

Grouped steps assume a fixed order, but the parent spec requires the middle
sections to be freely rearrangeable. Two approaches were explored and **rejected**:

- *Reorder inside each step* — a section could then never leave its step, losing
  the "put the announcement at the bottom" flexibility that was deliberately
  specified.
- *A nested draggable sidebar (steps → their sections)* — dragging a section
  across groups re-homes it under a heading that no longer describes it (an
  announcement listed under "Stories"). Prototyped visually and rejected.

**Decided:** steps 1–3 contain **no reorder controls at all** — pure writing.
Step 4 shows the whole running order and is where arranging happens, next to the
live preview so the user can see what they're moving. This matches how people
work (get the words down, then decide order while looking at it) and keeps the
sidebar to four clean, honest labels.

**Arranging UI (step 4):**
- Header and Footer render as pinned, non-draggable rows (🔒 "always first" /
  "always last").
- The four middle sections are drag-reorderable, each with a **Remove**.
- **Accessibility is not optional here:** drag-and-drop MUST have an equal
  keyboard/AT path — visible **Move up / Move down** buttons on each row, not a
  drag-only interaction. (Phase 1 already ships Move up/down; keep them.)

## 6. The Live Preview

- **Placement:** a panel to the right of the step fields. It gets the widest
  column that fits; the fields column stays comfortably readable (~420px min).
- **Fidelity:** the newsletter renders at its true **840px** design width and is
  CSS-scaled (`transform: scale()`) to fit the panel — so proportions are honest
  even though the text is small. It answers *"what will this look like"*, not
  *"read this."* The existing **Share a preview link** and the full WordPress
  preview remain the way to read it at full size.
- **Current-section highlight:** the block being edited is outlined in the
  preview, so the user always knows where what they're typing lands. The renderer
  emits a `data-ptk-block="<type>"` attribute per block wrapper so the JS can find
  and outline the right one. (Small, additive renderer change — the only output
  change this spec allows, and it is inert on the public page.)
- **Narrow screens:** below ~1100px the preview collapses to a "Show preview"
  toggle (or drops beneath the fields) rather than crushing the form. wp-admin on
  a laptop must stay usable.
- **Empty state:** with nothing written yet, the preview shows the real (mostly
  empty) newsletter — not a fake sample. Honesty beats a flattering mock.

## 7. Rendering the Preview — ONE renderer (key decision)

The preview must be truthful; a preview that drifts from the real output is worse
than no preview.

**Decided: render server-side via AJAX, reusing `PTK_Newsletter_Renderer`.**

- On change (debounced ~400ms), the existing JS `serialize()` output is POSTed to
  a new authenticated `wp_ajax_ptk_nl_preview` endpoint (nonce + `edit_posts`).
- The endpoint runs the blocks through `PTK_Newsletter_Data::sanitize_blocks()`
  and `PTK_Newsletter_Renderer::render()` — **the exact same path as a real save**
  — and returns the HTML, which the JS injects into the preview panel.
- The endpoint supplies the same render opts a save does, including the
  `image_url_cb` (so picked images actually appear) and `today`.

**Rejected: porting the renderer to JavaScript.** It would create a second
implementation of the newsletter design that must be kept byte-identical to the
PHP one forever. We already carry exactly one such duplication (the Monday-week
relabel logic in `relabel_for_date` / `ptkRelabelForDate`) and it needs a matching
test suite to stay honest; duplicating the *whole renderer* would be that problem
an order of magnitude larger, and the first drift would make the preview lie.

**Trade-off accepted:** a debounced network round-trip per edit. This is a local
admin request on a site the user is already logged into — fine. Guard with
debounce, ignore out-of-order responses (drop stale replies), and fail quietly
(keep the last good preview + a small "preview couldn't update" note) rather than
blanking the panel.

## 8. What Carries Over Unchanged

This is a re-layout, not a rebuild. Reused as-is:

- The block data model + `sanitize_blocks()` (`class-newsletter-data.php`).
- `PTK_Newsletter_Renderer` (plus the one additive `data-ptk-block` attribute).
- `handle_submission()` / `persist_newsletter()` — the save path, PII gate,
  KSES bypass, `wp_slash` fix, redirects, notices.
- The JS `serialize()` / `prefillFromData()` contract (`[data-field]`,
  `[data-rows-for]`, `<template>` rows, the hidden `ptk_nl_blocks` JSON). Fields
  are redistributed across steps; **the DOM contract does not change**, so
  serialize keeps working.
- `wp.media` image picking, share-a-preview, edit routing, `validate_edit_id()`.

**Implication:** the work is (a) new wizard chrome + step navigation, (b) moving
existing field markup into steps, (c) the arrange list on step 4, (d) the preview
panel + AJAX endpoint. The save/render/data layers should not need to change.

## 9. Accessibility & Plain Language (non-negotiable)

The whole point is approachability, so these are requirements, not polish:

- The sidebar is a `<nav>`; the current step carries `aria-current="step"`.
- Moving between steps manages focus (focus the new step's heading) and announces
  the change to screen readers; steps are headings, not just styled divs.
- Every field keeps its `<label for>` association (Phase 1 already does this for
  static and JS-cloned fields — preserve it).
- Drag has a keyboard equal (§5). Never drag-only.
- The preview is decorative-but-informative: it must not trap focus, and must be
  reachable/skippable sensibly.
- Copy stays plain English, names the thing it refers to, and never blames the
  user.

## 10. Testing

- **Pure logic** keeps its existing plain-PHP tests; the AJAX endpoint's
  sanitize+render path is the already-tested code, so the new test surface is
  small (endpoint returns rendered HTML for valid input; rejects a bad nonce /
  insufficient caps).
- **The wizard is DOM/UX work with no JS DOM harness in this repo** — verification
  is `php -l`, `node --check`, and **driving the real thing in WordPress**
  (WordPress Playground, as used for Phase 1: `npx @wp-playground/cli@latest
  server --auto-mount "<plugin dir>" --login --port 9400`). Phase 1's four
  worst bugs were only findable that way; do the same here rather than trusting
  green unit tests.
- Functional checks to drive: each step navigates and jumps; the preview updates
  as you type and outlines the right section; drag AND Move up/down both reorder;
  Remove works; the PII gate still blocks publish; edit round-trip still
  reconstructs a saved newsletter; nothing regresses on save.

## 11. Open Questions

1. **Preview panel width on a typical school admin's screen** — needs a look at
   real wp-admin widths; the ~1100px collapse threshold is a starting guess.
2. **Does step 1 feel *too* empty in practice?** Decided to keep it light; revisit
   after driving it in the sandbox.
3. Whether the arrange list on step 4 should also allow *adding back* a removed
   section (Phase 1 has no "add block" concept — sections are include/skip). Likely
   yes: a removed section needs a way back, or removal is a trap.
