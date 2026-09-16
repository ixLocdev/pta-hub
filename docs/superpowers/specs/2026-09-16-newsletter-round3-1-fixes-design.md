# Newsletter Builder — Round 3.1 (4.5.0): fixes from live testing

Source: Lucas's live testing of 4.4.0 on 2026-09-16 (handoff §5). Governing rule: easy, quick, no
cognitive overload, plain-English labels, volunteers are non-technical parents.

Decisions made with Lucas: **two steps** (Finish editing / Publish & share); **example draft per site**;
Top story default label stays **"Top story"**.

Hard rules: NO one-sided borders (border-left/right accent bars) anywhere. Zoom is pinch/scroll on the
photo, never a slider. US spelling. House style `/Users/lucas/apps/PTA/HOUSE-STYLE.md`.

## 1. Don't copy the Top story label
"Start from last issue" must NOT copy the Top story section label (it's issue-specific). The new issue
gets the default "Top story". Other section labels (Quick notes, Stories, Coming up) may still copy.
Add/adjust a PHP test.

## 2. Inline photo adjust (no dropdown)
Remove the "Show whole photo / Crop to fit (16:9)" `<select>`. Each photo shows a small inline preview
(Tory Burch hub style — see `docs/reference/tory-focal-point/FocalPointPicker.tsx`) with the focal dot
directly on it, and two visible segmented buttons under it: **Whole photo** · **Crop to fit**. In Crop
mode the preview is 16:9 and the dot + pinch/scroll zoom work on it; in Whole mode the dot is hidden and
a one-line hint says "Showing the whole photo". Keep existing keyboard support and the stored data format
(fit / focal / zoom) so already-saved issues keep working. The Instagram square's photo uses the same
component.

## 3. Live refresh while adjusting
Focal/zoom/fit changes must update the Builder's live preview and the Instagram square preview.
Re-render the live preview on each change (debounced ~150ms); re-request the square image debounced
~500ms after the last change (cancel/ignore stale responses).

## 4. Readable square text over a photo
When the square has a photo: the text sits on a bar/scrim. Default: text white on a navy-ish scrim at
enough opacity for contrast (≥4.5:1 against the scrim color). Add two settings used only for photo squares,
shown next to "Use the top story's photo": **Text color** and **Bar color** (same color-input + hex box
pattern as existing color settings). If the user-chosen pair has contrast below 4.5:1, show a plain
warning ("These colors are hard to read together") — don't block. No-photo squares unchanged.
Note: Playground GD can't draw text; test the color logic in PHP unit tests, verify drawing live.

## 5. Split step 4 into two steps
- **Step 4 "Finish editing"**: list of sections in newsletter order, **drag and drop only** (remove Move
  up / Move down buttons). Each row has an **Edit** button that goes to that section's step, scrolls to
  and focuses that section's first field. Short line at the bottom: "Next: publish and share." Primary
  button: "Next: Publish & share".
- **Step 5 "Publish & share"**: photo consent check, Save draft / Publish (or Update once published),
  preview link, then the share panel. Share channels (Facebook, Instagram, WhatsApp, QR) are collapsible,
  **one open at a time**, first one open by default. One primary action visually per area.
- Update the step indicator/progress to 5 steps and any "step 4" references (JS, PHP, tests, help text).

## 6. Where Save lands
After Save draft / Publish / Update the page must land at the top of step 5 (not "Order of your
newsletter"). Persist the step across the reload (e.g. `&step=5` or hash).

## 7. Visible validation errors
On Save/Publish, validate in JS before submitting (don't rely on browser bubbles, which are invisible
when the field is on a hidden step). For each invalid field: red full outline on the field, the section's
heading marked, and a plain message right below the field saying what's wrong and how to fix it (e.g.
"This link needs to start with https:// — paste the full address."). Jump to the step with the first
error and focus the field. Also show a summary line at the top of step 5 ("2 things need fixing" with
links that jump to each). Server-side validation errors must also come back as visible messages.
Errors clear as soon as the field is fixed.

## 8. First Publish click doesn't work
Reproduce in Playground first (systematic debugging: find the cause before fixing). Suspects: photo
consent gate downgrading to draft, the unsaved-changes guard intercepting submit, invalid hidden fields
blocking native submit. Fix the root cause; add a test if feasible. Report the cause in plain words.

## 9. Example newsletter
On update (version-gated on `admin_init`, since activation hooks don't fire on upload-replace), create
once per site a **draft** newsletter titled "EXAMPLE — a finished newsletter (do not publish)" filled with
realistic content in the #040 style (announcement callout, top story, 2 stories, quick notes, 3 events,
footer; no photos, or a bundled image only if trivial). Store an option flag so it's created only once
and never recreated if deleted. It must never be publishable by accident: block publishing it (keep as
draft and show "This is an example. Start a new newsletter instead." notice). On the Builder start screen,
a small link "See an example newsletter" opens its preview.

## 10. General
Version 4.5.0 (header + `PTK_VERSION`), changelog/readme entry, rebuild zip. All tests pass. Verify each
item in a real browser in Playground (127.0.0.1:9406); list anything only verifiable live (square text
drawing).
