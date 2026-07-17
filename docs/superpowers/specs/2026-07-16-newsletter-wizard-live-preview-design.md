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

- Any change to the newsletter's **published output**, the block model, or the
  saved meta. This is an authoring-UX rework only.
  **Two carve-outs, both additive and both inert on the public page:**
  (a) the renderer emits a `data-ptk-block="<type>"` attribute per block wrapper
  so the preview can outline the section being edited (§6); (b) the renderer gains
  a **preview-only** mode that renders placeholder stubs for empty blocks (§6) —
  never used by the save path, so published newsletters are byte-identical to
  today.
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
  drag-only interaction.
- **This is NEW markup, not a move of the existing buttons.** Phase 1 renders
  Move up / Move down / Remove *inside each block section's header*
  (`render_block_section()`), and their JS handlers resolve the target via
  `$(this).closest('.ptk-nl-block')` — which cannot work from a step-4 row that
  lives outside the section. So: **delete** those buttons from
  `render_block_section()` (steps 1–3 must have no reorder controls), and build
  the step-4 arrange rows as new markup whose handlers target a section **by
  block type / id** rather than by DOM ancestry.

### 5a. Load-bearing DOM constraint (do not violate)

`serialize()` reads `$('#ptk-nl-blocks > .ptk-nl-block')` — a **direct-child**
selector — and takes **block order from DOM order**. `updateMoveButtonStates()`
uses the same selector, and the move handlers use `.prev()` / `.next()` sibling
traversal. Therefore:

> **All six block sections MUST remain direct children of a single
> `#ptk-nl-blocks` container. Steps are a presentation layer — show/hide by
> attribute or class, never by wrapping sections in per-step parent elements.**

This works because the four steps map onto contiguous runs of whole blocks in the
default order (header | announcement+events | featured+story_cards | footer). If
a planner nests sections inside step wrappers, `serialize()`, the move handlers,
and the button-state logic all silently break — and §8's "the data layer doesn't
change" stops being true.

Note the consequence for §5's write-then-arrange rule: because DOM order *is*
newsletter order, the step-4 arrange list reorders the **actual sections** in
`#ptk-nl-blocks` (which may be hidden at the time), not a separate model.

## 6. The Live Preview

- **Placement:** a panel to the right of the step fields. It gets the widest
  column that fits; the fields column stays comfortably readable (~420px min).
- **Render it in an `<iframe>`, not injected into the admin DOM (decided).** Two
  reasons, both real: (a) the newsletter's type uses viewport units
  (`clamp(…, 7vw, …)` appears in the header, events, featured and story-card
  renderers) — `vw` resolves against the **admin window**, not against a scaled
  `<div>`, so injected markup would misreport proportions and make "840px design
  width" a lie; an iframe is a real 840px viewport, so `vw` and `clamp()` behave
  exactly as they will for families. (b) It isolates the newsletter from wp-admin's
  stylesheets in both directions. Set the iframe body to 840px and scale the whole
  iframe (`transform: scale()`) to fit the panel.
- **Fidelity:** proportions are then literally honest, though the text is small.
  The preview answers *"what will this look like"*, not *"read this."* The existing
  **Share a preview link** and full WordPress preview remain the way to read it at
  full size.
- **Current-section highlight:** the block being edited is outlined in the preview,
  so the user always knows where what they're typing lands. The renderer emits a
  `data-ptk-block="<type>"` attribute per block wrapper so the JS can find and
  outline the right one.
- **Empty blocks must still be outlinable (the first-run case).** Every optional
  block renderer returns `''` when its fields are blank (`render_announcement`,
  `render_events`, `render_featured`, `render_story_cards`, `render_footer`) — so
  on a brand-new newsletter there is *nothing to outline for the very section the
  user is editing*, which is precisely when they need it most. **Decided:** the
  renderer gains a **preview-only** opt (e.g. `$opts['preview_placeholders']`)
  that, instead of returning `''`, emits a lightweight dashed stub carrying the
  `data-ptk-block` wrapper — *"Your featured story will appear here."* This turns
  the hole into a feature: the volunteer sees where a section will land before
  they've written it. The save path never sets this opt, so **published output is
  unchanged** and Phase 1's "empty blocks render nothing" guarantee still holds
  (its existing tests must keep passing untouched).
- **Narrow screens:** below ~1100px the preview collapses to a "Show preview"
  toggle (or drops beneath the fields) rather than crushing the form. wp-admin on
  a laptop must stay usable.
- **Empty state:** with nothing written yet, the preview shows the real (mostly
  empty) newsletter — not a fake sample. Honesty beats a flattering mock.

## 7. Rendering the Preview — ONE renderer (key decision)

The preview must be truthful; a preview that drifts from the real output is worse
than no preview.

**Decided: render server-side via AJAX, reusing `PTK_Newsletter_Renderer`.**

- On change (debounced ~400ms), the JS POSTs to a new authenticated
  `wp_ajax_ptk_nl_preview` endpoint (nonce + `edit_posts`).
- **The payload is NOT just `serialize()` output.** Issue number and issue date
  live in `ptk_nl_issue` / `ptk_nl_date` inputs **outside** `#ptk-nl-blocks`, and
  `render()` reads them from `$opts` — not from block data. Posting blocks alone
  would render a masthead with no issue number, no date, and no auto-derived
  "Week of …" headline: the preview would visibly lie about the exact fields step 1
  owns. **The payload must be `{ blocks, issue, date }`.**
- The endpoint runs the blocks through `PTK_Newsletter_Data::sanitize_blocks()`
  and `PTK_Newsletter_Renderer::render()` — **the same path as a real save** — and
  returns the HTML for the iframe.
- **Extract a shared `render_opts()` helper (required).** The opts array is
  currently built inline inside the private `persist_newsletter()` and depends on
  the private `school_name_from_blocks()`. "Same opts as a save" is only true if
  both callers use one helper — e.g.
  `private static function render_opts( array $blocks, $issue, $date, array $extra = array() )`
  returning the issue/date/today/theme/logo_url/school_name/image_url_cb array,
  called by both `persist_newsletter()` and the preview endpoint (the endpoint
  passing `preview_placeholders => true`). Two independent opts constructions would
  drift — which is the precise failure mode this section rejects a JS renderer to
  avoid.

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
  are redistributed across steps **without nesting them** (§5a), so serialize
  keeps working untouched.
- `wp.media` image picking, share-a-preview, edit routing, `validate_edit_id()`.

**What DOES change (do not let §8 read as "no work"):**

- `class-newsletter-renderer.php` — the additive `data-ptk-block` attribute + the
  preview-only placeholder mode (§6). Published output unchanged.
- `class-newsletter-builder.php` — `render_page()` gains the wizard chrome;
  `render_block_section()` **loses** its Move/Remove buttons (§5); a shared
  `render_opts()` helper is extracted out of `persist_newsletter()` (§7); a new
  `wp_ajax_ptk_nl_preview` endpoint is added.
- `newsletter-builder.js` — step navigation, the step-4 arrange list with rewired
  by-type handlers (§5), include/skip + add-back (§8a), and the debounced preview
  fetch.

**Implication:** the *data model, sanitizing, save path, and published output* are
untouched. The work is (a) wizard chrome + step navigation, (b) redistributing
existing field markup into steps (flat, per §5a), (c) the step-4 arrange list,
(d) the preview iframe + AJAX endpoint + the two additive renderer changes.

## 8a. Removing a section must not be a trap (decided)

Phase 1's Remove is `$section.remove()` — it destroys the DOM section and
everything typed into it, and there is no "add a section" concept anywhere. That
was tolerable when every field was visible on one page; in the wizard, Remove on
**step 4 silently deletes prose the user wrote on step 2**, with no way back.

**Decided — include/skip, not destroy:**

- Step 4's arrange list shows **"In your newsletter"** (draggable, each with
  *Remove*) and **"Not included"** (each with *Add back*).
- *Remove* does **not** delete the section — it marks it excluded and hides it
  (its DOM section and typed content stay put), so *Add back* restores the text
  within the session. Removing asks for confirmation that names what's affected.
- `serialize()` **skips excluded sections**, so the saved blocks JSON contains
  only included ones — no data-model change, and `sanitize_blocks()` behaves
  exactly as today.
- Honest limitation to state in the UI copy: once you **save**, an excluded
  section's text is not persisted, so *Add back* after a save returns an empty
  section. Within-session undo is the safety net; permanent undo is not in scope.

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
   Resolve by driving it in the sandbox, not by argument.
2. **Does step 1 feel *too* empty in practice?** Decided to keep it light; revisit
   after driving it in the sandbox.
3. **Drag-and-drop vs buttons-only.** Lucas explicitly asked for drag, so drag is
   in — but note §5 mandates Move up/down as a co-equal accessible path anyway, and
   drag costs a `jquery-ui-sortable` dependency plus touch handling for a list of at
   most four rows. If drag proves fiddly during implementation, shipping
   buttons-only and adding drag as a follow-up is an acceptable de-scope —
   check with Lucas rather than silently dropping it.

*(Previously open, now decided: how empty blocks are outlined → §6 preview
placeholders; whether Remove needs an add-back → §8a include/skip.)*
