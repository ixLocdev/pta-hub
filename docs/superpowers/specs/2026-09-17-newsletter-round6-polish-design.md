# Round 6 — Polish (design spec)

2026-09-17. Release 4.9.0. Two independent, small changes to the Newsletter
Builder — a soft length counter and touch/keyboard drag-and-drop. Neither
touches the Builder's overall look; the bigger admin UI redesign is a later
milestone (handoff §6, Milestone B).

## Part 1 — soft length counters

### Why

Several fields render best under a measured character count (the design
clamps type size, so a long headline wraps or shrinks). Volunteers are
non-technical and change every year — a quiet nudge beats a rule they'd have
to learn, and NOTHING here may block typing, saving or publishing. No
`maxlength` attribute is ever added.

### Limits (from the handoff, §6/Round 6)

| Section | Field | Limit |
|---|---|---|
| Announcement | Headline | 30 |
| Announcement | When | 60 |
| Announcement | Text | 90 |
| Coming up (event row) | Title | 40 |
| Coming up (event row) | Description | 60 |
| Top story | Headline | 30 |
| Stories (story row) | Heading | 40 |
| Quick notes (note row) | Headline | 40 |
| Footer (link row) | Link wording | 25 |

Every other field (school name, greeting, button words, link addresses,
story bodies, sign-off, …) has no measured limit and is left alone —
counting a field nobody asked about would be clutter, not help.

### Behavior

- Nothing shows while comfortably under the limit (below ~80%).
- From ~80% of the limit up to it: "N characters left" (singular "1
  character left"), in a quiet grey, small and unobtrusive.
- Over the limit: "N over — it may wrap onto another line", in a warm amber
  — never red, never phrased as an error, because it isn't one.
- Lives INSIDE the field's existing `<p class="description">` help text, as
  a trailing `<span>` — same line, no new paragraph, no layout shift when
  hidden (a `hidden` span takes no space and never reserves a line).

### Implementation

- `assets/js/newsletter-counter.js` — pure, framework-free: a
  `PTK_NL_COUNTER_LIMITS` map keyed `"<sectionType>.<field>"` (matching each
  section's `data-type` and each input's `data-field`), plus
  `ptkNlCounterMessage(length, limit)` (the message text, or `null`) and
  `ptkNlCounterLimitFor(sectionType, field)`. Same load-as-plain-script +
  `module.exports` pattern as `newsletter-validate.js`.
- `tests/test-newsletter-counter.mjs` — node unit test against the pure
  functions (thresholds, singular/plural wording, the exact "N over — it
  may wrap onto another line" phrasing, the full limits map).
- `newsletter-builder.js`: `updateFieldCounter($field, sectionType)` and
  `fieldCounterEl($field, create)`, called from inside `serialize()`'s
  existing per-field walk (both the single-instance-field loop and the
  repeatable-row loop). `serialize()` already runs on every keystroke
  (`bindSerializeTriggers()`), every row add/remove, every calendar/post
  import, and once at boot right after `prefillFromData()` — so piggybacking
  there covers typing, dynamically added rows, and prefilled values (Start
  from last issue, calendar/post imports) with no extra wiring.
- CSS: `.ptk-nl-field-counter` / `.ptk-nl-field-counter-over` in
  `newsletter-builder.css`, right after the existing `.description` rule.

## Part 2 — drag-and-drop that works for touch and keyboard

### Why

Step 4 "Finish editing"'s section-order list used jQuery UI `sortable()`,
which has no touch support at all — a volunteer on a phone or iPad
literally cannot reorder sections. (Checked: no other repeater — events,
story cards, quick notes, footer links — was ever made sortable, so this
round only touches the one list that was.)

### What changed

- Replaced jQuery UI sortable with a small in-house Pointer Events
  implementation (`bindArrangeDrag()` in `newsletter-builder.js`) — one
  event family covers mouse, touch and pen. `jquery-ui-sortable` is no
  longer a script dependency of the Builder.
- Whole row is the drag surface via its handle: a placeholder `<li>` holds
  the row's old spot while the row follows the pointer (`position: fixed`),
  siblings are compared by vertical center to reposition the placeholder,
  and the page auto-scrolls near the top/bottom edge of the viewport while
  dragging.
- `touch-action: none` is scoped to the handle ONLY (`.ptk-nl-arrange-
  handle`), never the row or the list — the page still scrolls normally by
  touch everywhere else, including the rest of this same panel.
- A pointerdown that never moves past a 4px threshold is treated as a plain
  click, not a drag: no reorder, no re-render, no dirty flag, no "Order
  updated." announcement. This matters because a click is also how a mouse
  user focuses the handle before switching to the keyboard — without this,
  clicking to focus would itself trigger a (no-op) reorder cycle that
  rebuilds the row and steals the focus back out from under the click.
- The handle is now a real, focusable `<button>` (`aria-label="Move
  <section name>"`, e.g. "Move Stories"), not a decorative `<span>`. Arrow
  Up / Arrow Down move that row one place, keep focus on its (re-rendered)
  handle, and announce the result via the existing `[data-arrange-status]`
  aria-live region: "Stories moved to position 3 of 6." Arrow Up on the
  first row / Arrow Down on the last is a silent no-op (nothing moved,
  nothing to announce).
- Header and Footer stay locked exactly as before — they're outside the
  `<ul data-arrange>` the drag/keyboard code touches, in their own pinned
  rows.
- After any move (drag OR keyboard): `applyRowOrderToSections()` (unchanged
  — reads the arrange list's DOM order and re-inserts the real sections in
  that order before the footer) → `renderArrangeList()` → 
  `serializeAndPreview()` (live preview refresh) → the list still fires a
  `sortstop` event, which `bindUnsavedGuard()` already listens for, so the
  dirty flag needed no change.
- No visible Move up/Move down buttons were brought back — Lucas asked for
  those gone in Round 3.1; the keyboard path lives entirely on the existing
  grip handle.

### Implementation

- `assets/js/newsletter-reorder.js` — pure array/text helpers:
  `ptkNlMoveItem(items, from, to)` (immutable array move, clamped),
  `ptkNlMoveUpIndex`/`ptkNlMoveDownIndex` (clamped one-step index math), and
  `ptkNlReorderAnnouncement(label, index, total)` (the aria-live sentence).
  Everything DOM-specific (pointer tracking, placeholder insertion,
  auto-scroll, the real drag) stays in `newsletter-builder.js`, which reads
  the actual row order out of the DOM — that part isn't meaningfully
  unit-testable outside a browser, so only the pure logic is pulled out.
- `tests/test-newsletter-reorder.mjs` — node unit test (moves, clamping,
  no-mutation, the exact "Stories moved to position 3 of 6." wording from
  the spec's own example).
- `class-newsletter-builder.php`: enqueues `newsletter-counter.js` and
  `newsletter-reorder.js` (same pattern as `newsletter-validate.js`) before
  `newsletter-builder.js`; drops `jquery-ui-sortable` from its dependency
  array. The arrange panel's help text now reads "Drag a row (or press its
  handle and use the arrow keys) to change the order."

## Verification

- `tests/test-newsletter-counter.mjs` and `tests/test-newsletter-reorder.mjs`
  pass under node; the full existing suite
  (`tests/test-*.php` + `tests/*.mjs`) still passes unchanged.
- Playground (`pta-hub-round3-1`, port 9406): typed the announcement
  headline past 80% of 30 and past 30 itself — "4 characters left" then "13
  over — it may wrap onto another line" in amber, no typing blocked; added
  an event row and confirmed its own counter renders ("2 characters left"
  on a 38-character title); added a footer link and confirmed its label
  counter fires at 29/25 characters. Dragged "Stories" above "Top story"
  with the mouse — list, real section order, and live preview all updated,
  status said "Order updated." Clicked a handle to focus it (confirmed NO
  spurious reorder/announcement), then pressed Arrow Up / Arrow Down —
  focus stayed on the moved row through the re-render, and the live region
  read "Coming up moved to position 1 of 5." then "...position 3 of 5."
  exactly. Emulated a touch viewport (375×812) and dispatched a real
  `PointerEvent` sequence with `pointerType: 'touch'` on a handle — the
  drag worked identically, `getComputedStyle(handle).touchAction === 'none'`
  while the row/list/body all stayed `'auto'` (page still scrolls by touch
  everywhere except the grip itself). No console errors at any point.

### Honest gaps

- Touch was verified with synthetic `PointerEvent`s (`pointerType:
  'touch'`) dispatched via JS and with the emulated mobile viewport, which
  proves the code path and the `touch-action` scoping — it is not the same
  as a finger on real glass. A real touch device (a bouncy scroll, a
  accidental long-press, iOS Safari's own pointer-event quirks) can only be
  confirmed by Lucas.
- Pointer capture (`setPointerCapture`/`releasePointerCapture`) is wrapped
  in try/catch for browsers that throw on an already-released pointer, but
  its cross-browser behavior (especially older Android WebViews) wasn't
  exhaustively tested — only Chromium (via the Browser pane).
