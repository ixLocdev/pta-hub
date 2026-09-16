# Newsletter Round 3: Photos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** every newsletter photo (the top story's `featured.image_id`, each story card's
`image_id`) can stay "Show whole" (today's exact rendering, default) or switch to "Crop to fit,"
framed with a focal point and zoom dragged/pinched/scrolled directly on the photo — no slider.
The same control, at a square frame, lets the Instagram share square show a real photo behind its
(now smaller) text over a dark scrim instead of only a flat color. A quick win in the same UI
swaps the "Image #12 selected" text chip for a real thumbnail + filename. Version 4.4.0.

**Architecture:** two new pure, unit-tested maths modules (`PTK_Focal_Point` in PHP,
`focal-point.js` in JS) hold every percentage-clamp, zoom-sanitize, and crop-rectangle
calculation, ported one-for-one from `docs/reference/tory-focal-point/focal-point.ts`. Four new
fields (`image_fit`, `image_focal_x`, `image_focal_y`, `image_zoom`) sit next to every
`image_id` in the data model, sanitized by one shared helper. A new vanilla-JS picker
(`focal-point-picker.js`) — pointer drag, two-finger pinch, non-passive wheel, keyboard — writes
those fields as plain hidden `[data-field]` inputs, which the Builder's EXISTING generic
serialize/prefill/unsaved-guard machinery already picks up with no changes (verified in the spec,
fact 3). The renderer's `maybe_image()` grows a crop branch (16:9, `object-fit:cover` +
`transform:scale`, clipped). The share square's `render_png()` grows an optional photo-plus-scrim
layer, cropped with the same maths at a 1:1 frame via `imagecopyresampled()`. A consent gap the
spec identifies (the square's background photo can be set via AJAX outside the main
photo-privacy gate) is closed by reusing the existing `ptk_nl_pii_confirmed` meta from a second
call site.

**Tech Stack:** PHP 7.4-compatible WordPress plugin, no build step. Plain-php tests plus Node
scripts (`tests/test-*.php` run with `php`, JS maths tested with `node`, mirroring
`tests/test-relabel-js.mjs`). WordPress Playground for the Builder, live preview, and the share
panel. The Browser pane for visual checks.

**Spec:** `docs/superpowers/specs/2026-09-16-newsletter-round3-photos-design.md`. Read it first —
every line reference, field name, and formula is verified there, including the two math
derivations (the maths module port and the square's crop-rectangle formula) with their own
`php -r` verification transcripts. Where this plan and the spec disagree, the spec wins — say so
rather than guessing.

**Where:** the git worktree
`/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round3`, branch `newsletter-round3`,
built on 4.3.0. Run everything from there. Do not `cd` to the parent repo, do not touch other
branches, never use bare `git stash`.

---

## Before you start

**Test command, run after every task:**

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round3/pta-knowledge-hub" && for f in tests/test-*.php; do echo "== $f"; php "$f" || exit 1; done && node tests/test-relabel-js.mjs
```

(Task 2 adds `node tests/test-focal-point-js.mjs` to this command permanently — add it once that
file exists.)

All existing tests pass at the start (verified 2026-09-16). Tests are plain PHP scripts using
`ptk_test_ok()` / `ptk_test_done()` from `tests/bootstrap.php` — match the style of
`tests/test-newsletter-data.php` and `tests/test-share-image.php`.

**PHP 7.4:** your CLI may be newer; the sites are not. No `match`, `str_contains`,
`str_starts_with`, arrow-typed properties, `readonly`, named arguments, nullsafe `?->`. `??` is
fine.

**Playground:**

```bash
npx --yes @wp-playground/cli@latest server --auto-mount "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round3/pta-knowledge-hub" --login --port 9405
```

Open **`http://127.0.0.1:9405`**, never `localhost:9405`. Auto-logs-in via cookie + 302; probe
with `curl -s -L -c /tmp/r3.txt -b /tmp/r3.txt http://127.0.0.1:9405/wp-admin/...`. Delete
throwaway probe files/posts before committing.

**Remember:** WordPress Playground's GD claims FreeType support but cannot actually draw text
(`class-share-image.php`'s `capabilities()` docblock explains why it measures instead of
trusting `function_exists`). The square's TEXT cannot be visually verified in Playground; its
PHOTO half can — see Task 7's verification notes and Task 8.

**Commit rule:** every task ends in a commit whose message says why, not what, and ends with
`Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`. Tests pass at every commit.

## File structure

| File | Change |
|---|---|
| `pta-knowledge-hub/includes/class-focal-point.php` | **New.** Pure PHP maths, ported from `focal-point.ts` |
| `pta-knowledge-hub/tests/test-focal-point.php` | **New.** Tests for the above |
| `pta-knowledge-hub/assets/js/focal-point.js` | **New.** Pure JS mirror |
| `pta-knowledge-hub/tests/test-focal-point-js.mjs` | **New.** Node tests, styled like `test-relabel-js.mjs` |
| `pta-knowledge-hub/includes/class-newsletter-data.php` | `sanitize_image_crop()`; four new fields on featured + each card; `default_blocks()` |
| `pta-knowledge-hub/tests/test-newsletter-data.php` | New assertions |
| `pta-knowledge-hub/includes/class-newsletter-renderer.php` | `maybe_image()` crop branch; both call sites pass `$data` |
| `pta-knowledge-hub/tests/test-newsletter-renderer.php` | New assertions, incl. one-sided-border grep |
| `pta-knowledge-hub/assets/js/focal-point-picker.js` | **New.** The interactive control |
| `pta-knowledge-hub/assets/js/newsletter-builder.js` | Wire the picker into image groups; extend `refreshImageChip()`; `pta_knowledge-hub.php` bootstrap enqueue |
| `pta-knowledge-hub/includes/class-newsletter-builder.php` | Featured markup gains 4 hidden fields + fit `<select>`; story-card `<template>` same; `enqueue_assets()` enqueues the two new JS files + CSS |
| `pta-knowledge-hub/assets/css/newsletter-builder.css` | `.ptk-nl-image-thumb`, focal-picker surface/dot/readout styles |
| `pta-knowledge-hub/includes/class-share-data.php` | `square_inputs_hash()` grows 4 params; `get_square_photo()`/`save_square_photo()`/`clear_square_photo()`/`square_has_custom_photo()` |
| `pta-knowledge-hub/tests/test-share-data.php` | New assertions |
| `pta-knowledge-hub/includes/class-share-image.php` | `draw_square_photo()`; `render_png()`/`ensure_square()` take photo args |
| `pta-knowledge-hub/tests/test-share-image.php` | New assertions |
| `pta-knowledge-hub/includes/class-share-panel.php` | "Use a photo behind the words" UI; `ajax_square()` new modes; consent gate |
| `pta-knowledge-hub/includes/class-newsletter-builder.php` | `blocks_have_images()` call sites OR in `square_has_custom_photo()` |
| `pta-knowledge-hub/assets/css/share-panel.css` | Photo-mode toggle + picker styles |
| `pta-knowledge-hub/pta-knowledge-hub.php`, `update-info.json`, `pta-knowledge-hub.zip` | 4.4.0 |

---

### Task 1: The maths — pure PHP, ported and tested first

**Files:**
- New: `pta-knowledge-hub/includes/class-focal-point.php`
- New: `pta-knowledge-hub/tests/test-focal-point.php`
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php` (require the new file, alongside the other
  `includes/class-*.php` requires)

- [ ] **Step 1: Write the failing tests.** Create `tests/test-focal-point.php` (bootstrap the
  same way every other test file does — `require __DIR__ . '/bootstrap.php';` then `require
  dirname(__DIR__) . '/includes/class-focal-point.php';`). Assert, per the spec's Part B:
  ```php
  $F = 'PTK_Focal_Point';
  ptk_test_ok( $F::clamp_percent( 37 ) === 37, 'clamp_percent: in range passes through' );
  ptk_test_ok( $F::clamp_percent( -5 ) === 0, 'clamp_percent: clamps below 0' );
  ptk_test_ok( $F::clamp_percent( 140 ) === 100, 'clamp_percent: clamps above 100' );
  ptk_test_ok( $F::clamp_percent( 'nope' ) === 50, 'clamp_percent: non-numeric -> center' );
  ptk_test_ok( $F::clamp_percent( null ) === 50, 'clamp_percent: null -> center' );

  ptk_test_ok( $F::sanitize_zoom( 100 ) === 0, 'sanitize_zoom: exactly the floor drops to 0' );
  ptk_test_ok( $F::sanitize_zoom( 50 ) === 0, 'sanitize_zoom: below the floor drops to 0' );
  ptk_test_ok( $F::sanitize_zoom( 175 ) === 175, 'sanitize_zoom: in range passes through' );
  ptk_test_ok( $F::sanitize_zoom( 999 ) === 250, 'sanitize_zoom: clamps to the ceiling' );
  ptk_test_ok( $F::sanitize_zoom( 'nope' ) === 0, 'sanitize_zoom: non-numeric drops to 0' );
  ptk_test_ok( $F::sanitize_zoom( null ) === 0, 'sanitize_zoom: absent drops to 0' );

  ptk_test_ok( $F::effective_zoom( 0 ) === 100, 'effective_zoom: sentinel reads as 100' );
  ptk_test_ok( $F::effective_zoom( 175 ) === 175, 'effective_zoom: stored value passes through' );

  ptk_test_ok( $F::object_position( 37, 62 ) === '37% 62%', 'object_position: formats both parts' );
  ptk_test_ok( $F::object_position( -5, 140 ) === '0% 100%', 'object_position: clamps both parts' );

  ptk_test_ok( $F::css_zoom_style( 50, 50, 0 ) === '', 'css_zoom_style: unzoomed emits nothing' );
  $zs = $F::css_zoom_style( 30, 25, 175 );
  ptk_test_ok( false !== strpos( $zs, 'scale(1.75)' ), 'css_zoom_style: scale from zoom/100' );
  ptk_test_ok( false !== strpos( $zs, 'transform-origin:30% 25%' ), 'css_zoom_style: origin follows the focal point' );

  // square_crop_rect() -- the exact four cases verified in the spec's php -r transcript.
  list( $x, $y, $s ) = $F::square_crop_rect( 2000, 1000, 50, 50, 0 );
  ptk_test_ok( 500.0 === round( $x, 2 ) && 0.0 === round( $y, 2 ) && 1000.0 === round( $s, 2 ), 'square_crop_rect: centered, unzoomed' );
  list( $x, $y, $s ) = $F::square_crop_rect( 2000, 1000, 0, 50, 0 );
  ptk_test_ok( 0.0 === round( $x, 2 ), 'square_crop_rect: focal far left -> crop at x=0' );
  list( $x, $y, $s ) = $F::square_crop_rect( 2000, 1000, 100, 50, 0 );
  ptk_test_ok( 1000.0 === round( $x, 2 ), 'square_crop_rect: focal far right -> crop at x=avail' );
  list( $x, $y, $s ) = $F::square_crop_rect( 2000, 1000, 50, 50, 200 );
  ptk_test_ok( 750.0 === round( $x, 2 ) && 250.0 === round( $y, 2 ) && 500.0 === round( $s, 2 ), 'square_crop_rect: 200% zoom halves the window and re-centers it' );
  ```
- [ ] **Step 2: Run, confirm failure** (class doesn't exist yet).
- [ ] **Step 3: Implement `PTK_Focal_Point`** exactly as the spec's Part B code block, in
  `includes/class-focal-point.php` (same file header style as `class-newsletter-data.php`:
  `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard, WordPress-free body).
- [ ] **Step 4: Require it from the bootstrap.** In `pta-knowledge-hub.php`, add
  `require_once PTK_PLUGIN_DIR . 'includes/class-focal-point.php';` alongside the other
  `class-*.php` requires — place it BEFORE `class-newsletter-data.php`'s require (Task 3 makes
  `PTK_Newsletter_Data` call into it) and before `class-share-image.php`'s (Task 6 does the
  same).
- [ ] **Step 5: Run tests, confirm green.**
  `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done`.
- [ ] **Step 6: Commit.** `git add -A -- pta-knowledge-hub/includes/class-focal-point.php pta-knowledge-hub/tests/test-focal-point.php pta-knowledge-hub/pta-knowledge-hub.php`

---

### Task 2: The maths — pure JS mirror, ported and tested

**Files:**
- New: `pta-knowledge-hub/assets/js/focal-point.js`
- New: `pta-knowledge-hub/tests/test-focal-point-js.mjs`

- [ ] **Step 1: Write the failing node test.** Mirror `tests/test-relabel-js.mjs`'s shape exactly
  (`createRequire`, `require()` the JS file, plain `ok()`/console output, `process.exitCode = 1`
  on any failure — read `test-relabel-js.mjs` in full before writing this so the harness pattern
  matches). Assert the SAME cases as Task 1's PHP tests (clamp/sanitize/effective/object-position/
  css-zoom-style), plus the reference-ported interaction cases that only make sense in the
  picker's own vocabulary:
  ```js
  // Pinch: zoom 120, fingers 80px apart -> 160px apart (2x) -> 240.
  ok( ptkFocalSanitizeZoom( 120 * ( 160 / 80 ) ) === 240, 'pinch: doubling the spread doubles the zoom, clamped/rounded the same as the picker will do it' );
  // Pinch back in: zoom 200, halved spread -> 100 -> dropped to the sentinel.
  ok( ptkFocalSanitizeZoom( 200 * ( 50 / 100 ) ) === 0, 'pinch: halving 200 lands exactly on the floor, which drops rather than stores' );
  // pointerToFocal: 200x100 rect at the origin; one pixel across is 0.5%, one pixel down is 1%.
  var rect = { left: 0, top: 0, width: 200, height: 100 };
  var f = ptkFocalPointerToFocal( rect, 150, 50 );
  ok( f.x === 75 && f.y === 50, 'pointerToFocal: reports the percent the pointer landed on' );
  var clamped = ptkFocalPointerToFocal( rect, -40, 400 );
  ok( clamped.x === 0 && clamped.y === 100, 'pointerToFocal: clamps a point that runs off the photo' );
  var zeroRect = ptkFocalPointerToFocal( { left: 0, top: 0, width: 0, height: 0 }, 10, 10 );
  ok( zeroRect.x === 50 && zeroRect.y === 50, 'pointerToFocal: a zero-size (unlaid-out) rect reports center' );
  ```
- [ ] **Step 2: Run, confirm failure** (module doesn't exist).
- [ ] **Step 3: Implement `assets/js/focal-point.js`** per the spec's Part B code block —
  top-level `function` declarations (no ES module syntax; matches `newsletter-relabel.js`'s
  pattern so both load as plain enqueued scripts in the browser AND `require()` under node), the
  `module.exports` guard at the bottom.
- [ ] **Step 4: Run, confirm green.** `node tests/test-focal-point-js.mjs`.
- [ ] **Step 5: Add the node test to the standing test command** — from here on, every later
  task's "run tests" step means:
  ```bash
  cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done && node tests/test-relabel-js.mjs && node tests/test-focal-point-js.mjs
  ```
- [ ] **Step 6: Commit.** `git add -A -- pta-knowledge-hub/assets/js/focal-point.js pta-knowledge-hub/tests/test-focal-point-js.mjs`

---

### Task 3: Data model — four fields, next to every `image_id`

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-data.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-data.php`

- [ ] **Step 1: Write the failing tests.** Append to `tests/test-newsletter-data.php`:
  ```php
  $d = 'PTK_Newsletter_Data';

  // A saved newsletter with no crop fields at all (pre-4.4.0 data) sanitizes
  // to safe defaults -- "existing data is untouched."
  $legacy = $d::sanitize_blocks( array(
      array( 'type' => 'featured', 'data' => array( 'image_id' => 12 ) ),
  ) );
  $feat = $legacy[ array_search( 'featured', array_column( $legacy, 'type' ), true ) ]['data'];
  ptk_test_ok( $feat['image_id'] === 12, 'legacy image_id survives untouched' );
  ptk_test_ok( $feat['image_fit'] === 'whole', 'legacy data defaults to Show whole' );
  ptk_test_ok( $feat['image_focal_x'] === 50 && $feat['image_focal_y'] === 50, 'legacy data defaults to centered' );
  ptk_test_ok( $feat['image_zoom'] === 0, 'legacy data defaults to unzoomed (the sentinel)' );

  // A submitted crop, in range.
  $cropped = $d::sanitize_blocks( array(
      array( 'type' => 'featured', 'data' => array( 'image_id' => 12, 'image_fit' => 'crop', 'image_focal_x' => 30, 'image_focal_y' => 80, 'image_zoom' => 175 ) ),
  ) );
  $feat2 = $cropped[ array_search( 'featured', array_column( $cropped, 'type' ), true ) ]['data'];
  ptk_test_ok( $feat2['image_fit'] === 'crop' && $feat2['image_focal_x'] === 30 && $feat2['image_focal_y'] === 80 && $feat2['image_zoom'] === 175, 'a valid crop round-trips unchanged' );

  // Garbage in, safe defaults out -- never a fatal, never an out-of-range value stored.
  $garbage = $d::sanitize_blocks( array(
      array( 'type' => 'featured', 'data' => array( 'image_id' => 12, 'image_fit' => 'whatever', 'image_focal_x' => 'nope', 'image_focal_y' => -900, 'image_zoom' => 99999 ) ),
  ) );
  $feat3 = $garbage[ array_search( 'featured', array_column( $garbage, 'type' ), true ) ]['data'];
  ptk_test_ok( $feat3['image_fit'] === 'whole', 'garbage fit value falls back to whole' );
  ptk_test_ok( $feat3['image_focal_x'] === 50, 'non-numeric focal_x falls back to center' );
  ptk_test_ok( $feat3['image_focal_y'] === 0, 'out-of-range focal_y clamps, does not become garbage' );
  ptk_test_ok( $feat3['image_zoom'] === 250, 'over-range zoom clamps to the ceiling, is not dropped' );

  // Story cards: same four fields, per card.
  $cards = $d::sanitize_blocks( array(
      array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
          array( 'image_id' => 5, 'image_fit' => 'crop', 'image_focal_x' => 10, 'image_focal_y' => 20, 'image_zoom' => 130 ),
          array( 'image_id' => 6 ),
      ) ) ),
  ) );
  $card_data = $cards[ array_search( 'story_cards', array_column( $cards, 'type' ), true ) ]['data']['cards'];
  ptk_test_ok( $card_data[0]['image_fit'] === 'crop' && $card_data[0]['image_zoom'] === 130, 'first card keeps its crop' );
  ptk_test_ok( $card_data[1]['image_fit'] === 'whole' && $card_data[1]['image_focal_x'] === 50, 'second card (no crop fields posted) defaults' );

  // default_blocks() carries the four fields at their defaults on the featured block.
  $defaults = $d::default_blocks();
  $default_featured = $defaults[ array_search( 'featured', array_column( $defaults, 'type' ), true ) ]['data'];
  ptk_test_ok( $default_featured['image_fit'] === 'whole' && $default_featured['image_zoom'] === 0, 'default_blocks() featured starts at Show whole, unzoomed' );
  ```
- [ ] **Step 2: Run, confirm failure.**
- [ ] **Step 3: Implement.** Add `sanitize_image_crop( array $data )` (spec's Part A code block)
  to `PTK_Newsletter_Data`, requiring `class-focal-point.php` already loaded (Task 1, bootstrap
  order). Merge its result into the `TYPE_FEATURED` case's return array (`:249-256`) and into
  each card's array inside the `TYPE_STORY_CARDS` loop (`:265-272`) via `array_merge()`. Add the
  four keys at their defaults to `default_blocks()`'s `TYPE_FEATURED` entry (`:79-88`).
- [ ] **Step 4: Run tests, confirm green.**
- [ ] **Step 5: Commit.** `git add -A -- pta-knowledge-hub/includes/class-newsletter-data.php pta-knowledge-hub/tests/test-newsletter-data.php`

---

### Task 4: Renderer — the 16:9 crop, and the live preview for free

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-renderer.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: Write the failing tests.** Append to `tests/test-newsletter-renderer.php`:
  ```php
  $R = 'PTK_Newsletter_Renderer';
  $opts = array( 'image_url_cb' => function( $id ) { return 'https://example.org/img-' . $id . '.jpg'; } );

  // Show whole (default): byte-for-byte what today's markup is. Compare
  // against the EXACT string maybe_image() returns today -- read it from
  // class-newsletter-renderer.php before writing this assertion so it is
  // copied verbatim, not retyped from memory.
  $html_whole = $R::render( array(
      array( 'type' => 'header', 'data' => array() ),
      array( 'type' => 'featured', 'data' => array( 'headline' => 'Story', 'image_id' => 7, 'image_fit' => 'whole' ) ),
      array( 'type' => 'footer', 'data' => array() ),
  ), $opts );
  ptk_test_ok( false !== strpos( $html_whole, '<img src="https://example.org/img-7.jpg" alt="Story" style="display:block;width:100%;height:auto;border-radius:4px;margin:0;" />' ), 'Show whole: unchanged markup, byte for byte' );
  ptk_test_ok( false === strpos( $html_whole, 'object-fit' ), 'Show whole: no crop styling leaks in' );

  // Crop to fit: the 16:9 frame, clipped, with object-position and (when
  // zoomed) transform:scale.
  $html_crop = $R::render( array(
      array( 'type' => 'header', 'data' => array() ),
      array( 'type' => 'featured', 'data' => array( 'headline' => 'Story', 'image_id' => 7, 'image_fit' => 'crop', 'image_focal_x' => 30, 'image_focal_y' => 80, 'image_zoom' => 175 ) ),
      array( 'type' => 'footer', 'data' => array() ),
  ), $opts );
  ptk_test_ok( false !== strpos( $html_crop, 'aspect-ratio:16/9' ), 'Crop to fit: 16:9 frame' );
  ptk_test_ok( false !== strpos( $html_crop, 'overflow:hidden' ), 'Crop to fit: the frame clips' );
  ptk_test_ok( false !== strpos( $html_crop, 'object-fit:cover' ), 'Crop to fit: object-fit:cover' );
  ptk_test_ok( false !== strpos( $html_crop, 'object-position:30% 80%' ), 'Crop to fit: object-position from the focal point' );
  ptk_test_ok( false !== strpos( $html_crop, 'scale(1.75)' ), 'Crop to fit: zoom becomes a scale transform' );

  // Unzoomed crop: object-position present, no transform at all.
  $html_crop_unzoomed = $R::render( array(
      array( 'type' => 'header', 'data' => array() ),
      array( 'type' => 'featured', 'data' => array( 'headline' => 'Story', 'image_id' => 7, 'image_fit' => 'crop', 'image_focal_x' => 50, 'image_focal_y' => 50, 'image_zoom' => 0 ) ),
      array( 'type' => 'footer', 'data' => array() ),
  ), $opts );
  ptk_test_ok( false === strpos( $html_crop_unzoomed, 'transform:' ), 'Crop to fit, unzoomed: no transform emitted at all' );

  // Story cards get the same treatment (second call site).
  $html_cards = $R::render( array(
      array( 'type' => 'header', 'data' => array() ),
      array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
          array( 'heading' => 'A', 'image_id' => 3, 'image_fit' => 'crop', 'image_focal_x' => 0, 'image_focal_y' => 0, 'image_zoom' => 0 ),
      ) ) ),
      array( 'type' => 'footer', 'data' => array() ),
  ), $opts );
  ptk_test_ok( false !== strpos( $html_cards, 'aspect-ratio:16/9' ), 'Story cards: crop applies at the second call site too' );

  // No image at all: unaffected either way.
  $html_none = $R::render( array(
      array( 'type' => 'header', 'data' => array() ),
      array( 'type' => 'featured', 'data' => array( 'headline' => 'Story', 'image_fit' => 'crop' ) ),
      array( 'type' => 'footer', 'data' => array() ),
  ), $opts );
  ptk_test_ok( false === strpos( $html_none, 'aspect-ratio' ), 'No image_id: fit mode is moot, nothing crop-related renders' );
  ```
  Extend the existing one-sided-border grep test (round 1/2 already has one per the risks table
  in round 2's plan) to run against `$html_crop` and `$html_cards` too: zero `border-left`/
  `border-right` hits.
- [ ] **Step 2: Run, confirm failure** (`maybe_image()` doesn't take `$data` yet, whole 16:9
  markers won't exist).
- [ ] **Step 3: Implement.** Extend `maybe_image()`'s signature to `maybe_image( $image_id, array
  $data, array $opts, $alt = '' )` per the spec's Part D code block, requiring `class-focal-point.
  php`. Update BOTH call sites: `render_featured()` (`:426`, pass `$data`) and
  `render_story_cards()` (`:498`, pass `$card`).
- [ ] **Step 4: Run tests, confirm green.**
- [ ] **Step 5: Manual live-preview check** (no new code — the live preview already calls
  `render_opts()` + `render()`, spec fact/Part D "reaching the live preview"): re-verify fully in
  Task 9's Playground pass rather than adding code for it here.
- [ ] **Step 6: Commit.** `git add -A -- pta-knowledge-hub/includes/class-newsletter-renderer.php pta-knowledge-hub/tests/test-newsletter-renderer.php`

---

### Task 5: The picker control — markup, wiring, and the thumbnail quick win

This is the largest task and has no pure-PHP surface to unit test (it's DOM interaction) — verify
it in Playground (Task 9) rather than inventing a jsdom harness this codebase has no precedent
for or dependency on.

**Files:**
- New: `pta-knowledge-hub/assets/js/focal-point-picker.js`
- Modify: `pta-knowledge-hub/assets/js/newsletter-builder.js`
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php` (markup + enqueue)
- Modify: `pta-knowledge-hub/assets/css/newsletter-builder.css`

- [ ] **Step 1: Add the four hidden fields + fit `<select>` to both markup sites**, per the
  spec's Part C DOM shape: the featured block's static PHP (`class-newsletter-builder.php:
  1501-1511`) and the story-card `<template data-row-template>` (`:1547-1554`). Echo each stored
  value with `esc_attr()` for the featured block (the template has no PHP-side values — every
  field there starts at its blank/default attribute, matching how `image_id` already does at
  `:1549`). Wrap the whole "Image" field-group's contents (existing hidden `image_id` +
  button, plus the four new fields and the fit `<select>`) in a container the picker script can
  find via a stable selector, e.g. add `data-image-group` to the `.ptk-nl-field-group` itself.
- [ ] **Step 2: Implement `focal-point-picker.js`** per the spec's Part C behavior list — a
  single `ptkInitFocalPicker($group, options)` that:
  - Builds the surface DOM (photo `<img>`, focal dot `<button>`, zoom readout `<span>`, "Reset to
    center" button) once per `$group`, inserted after the fit `<select>`.
  - Fetches the attachment (via the shared `fetchAttachment()` helper from Task 5 Step 4) for the
    photo `src`.
  - Wires `pointerdown`/`pointermove`/`pointerup`/`pointercancel` (native `addEventListener`, not
    jQuery's event shorthand, so `pointerId` and `setPointerCapture` are available), a native
    `wheel` listener attached with `{ passive: false }`, and `keydown` on the dot.
  - On any change, writes `image_focal_x`/`image_focal_y`/`image_zoom` into the group's hidden
    `[data-field]` inputs via plain `.val(...)`, then `.trigger('change')` (jQuery) so the
    EXISTING delegated `bindSerializeTriggers()` listener re-serializes and re-previews with zero
    changes to that function (spec fact 3).
  - Exposes `ptkInitFocalPicker` and a `ptkDestroyFocalPicker($group)` (removes listeners; called
    before a group's picker is rebuilt for a newly-picked photo) on `window`, since
    `newsletter-builder.js` calls into it.
  - `options.aspect` (`'16:9'` or `'1:1'`) only changes the surface's CSS box (`aspect-ratio`
    style on the surface element) — no maths differ.
- [ ] **Step 3: Wire the fit `<select>` and image-picker changes.** In `newsletter-builder.js`:
  - A delegated `change` handler on `select[data-field="image_fit"]` (inside `#ptk-nl-blocks`)
    calls a new `refreshFocalPicker($group)`: builds/shows the picker surface when `value ===
    'crop'` and `image_id > 0`, hides/destroys it otherwise.
  - `prefillFromData()` needs no change (fact 3) for the FIELDS, but call `refreshFocalPicker()`
    once per image group at the end of `prefillFromData()`'s per-block loop, so an existing
    "crop" photo shows its picker on page load without a click.
  - `openImagePicker()`'s `select` handler (`:1477-1486`): after writing the new `image_id`,
    reset `image_fit` to `'whole'` (`.val('whole')`) and `image_focal_x`/`image_focal_y`/
    `image_zoom` to their defaults whenever the id actually CHANGED from a previous non-zero
    value (Decision "a fresh photo starts at Show whole" — compare the previous `.val()` before
    overwriting it), then call `refreshFocalPicker($group)` to hide the now-stale picker (fit is
    back to whole).
  - The `.ptk-nl-remove-image` handler (`:1441-1450`): also reset `image_fit`/focal/zoom to
    defaults and call `refreshFocalPicker($group)` to remove any picker surface.
- [ ] **Step 4: Implement the shared attachment fetch/cache and extend `refreshImageChip()`**
  per the spec's Part F code block — `fetchAttachment(id)` (a small `id -> promise` cache) is
  used by BOTH `refreshImageChip()` (thumbnail) and `focal-point-picker.js` (photo `src`), so an
  image_id already fetched for one purpose is never fetched twice.
- [ ] **Step 5: Enqueue.** In `class-newsletter-builder.php`'s `enqueue_assets()` (`:627-680`):
  add `wp_enqueue_script( 'ptk-focal-point', PTK_PLUGIN_URL . 'assets/js/focal-point.js', array(),
  PTK_VERSION, true )`, then `wp_enqueue_script( 'ptk-focal-point-picker', PTK_PLUGIN_URL .
  'assets/js/focal-point-picker.js', array( 'jquery', 'ptk-focal-point' ), PTK_VERSION, true )`,
  and add `'ptk-focal-point-picker'` to `ptk-newsletter-builder`'s own dependency array (`:648`)
  so it loads before the Builder script that calls into it. The Builder's boot block "dies
  silently on any throw, new boot code last, in try/catch" rule (hard constraint) means
  `refreshFocalPicker()`'s FIRST call (from `prefillFromData()`) must be inside that existing
  try/catch, not added as new bare code after it.
- [ ] **Step 6: CSS.** `.ptk-nl-image-thumb` (48×48, `object-fit:cover`, `border-radius:3px`);
  the focal surface (`position:relative`, `overflow:hidden`, `border-radius:4px`, a neutral
  `background:#f0eee7` while the photo loads); the dot (`position:absolute`,
  `width:28px;height:28px;border-radius:50%;border:2px solid #fff;` — all four sides, per the
  hard "never one-sided borders" rule — `background:rgba(0,0,0,0.25)`,
  `transform:translate(-50%,-50%)`, `cursor:grab`); the zoom readout (fixed min-width so it never
  reflows the row, per the reference's own regression note, ported into Part C). No
  `border-left`/`border-right` anywhere in this file's new rules.
- [ ] **Step 7: Run tests, confirm green** (no pure-PHP surface changed in this task; run the
  full command anyway to catch a stray PHP syntax error in the markup changes).
- [ ] **Step 8: Commit.** `git add -A -- pta-knowledge-hub/assets/js/focal-point-picker.js pta-knowledge-hub/assets/js/newsletter-builder.js pta-knowledge-hub/includes/class-newsletter-builder.php pta-knowledge-hub/assets/css/newsletter-builder.css`

---

### Task 6: The Instagram square — photo layer, pure crop math already tested

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-data.php`
- Modify: `pta-knowledge-hub/tests/test-share-data.php`
- Modify: `pta-knowledge-hub/includes/class-share-image.php`
- Modify: `pta-knowledge-hub/tests/test-share-image.php`

- [ ] **Step 1: `PTK_Share_Data`.** Add `META_SQUARE_PHOTO_ID`/`META_SQUARE_PHOTO_FOCAL_X`/
  `META_SQUARE_PHOTO_FOCAL_Y`/`META_SQUARE_PHOTO_ZOOM` constants and `get_square_photo()`/
  `save_square_photo()`/`clear_square_photo()`/`square_has_custom_photo( $post_id )` per the
  spec's Part E code block.
- [ ] **Step 2: Write failing tests for the hash signature change.** In `tests/test-share-data.
  php`, update every existing `square_inputs_hash(...)` call to the new 9-positional-arg
  signature (spec Part D: `issue, date, school_name, background, text, photo_id, photo_focal_x,
  photo_focal_y, photo_zoom, version`), and add: same everything, `photo_id` changed → hash
  changes; `photo_focal_x` changed → hash changes; `photo_zoom` changed → hash changes; a `0`
  `photo_id` with different focal/zoom values still produces the SAME hash as another `0`
  `photo_id` with different focal/zoom (no photo means focal/zoom are moot — decide whether to
  actually implement this normalization or accept the harmless over-invalidation; **the simpler,
  correct-by-default choice is to NOT normalize** — hash every input as given, accepting that a
  no-op focal/zoom edit on a photo-less square could theoretically mark it stale even though
  nothing visible changed; note this in a one-line comment rather than adding branching logic,
  matching the class's existing "when in doubt, regenerate" bias, `should_regenerate()`'s own
  "no baseline -> stale by definition"). Run — should fail (arity mismatch) until Step 3.
- [ ] **Step 3: Update `square_inputs_hash()`'s signature and `md5()` input string.** Run tests,
  confirm green.
- [ ] **Step 4: Write failing tests for `draw_square_photo()`/`render_png()`'s photo branch.** In
  `tests/test-share-image.php`:
  - `render_png()` with no `photo_id` (or `photo_id => 0`) in `$args`: unchanged behavior — reuse
    an existing assertion or add one confirming the returned PNG is non-empty and no new code
    path fired (this is the "existing squares are untouched" regression guard).
  - A `photo_id` pointing at a real test fixture image: check whether `tests/fixtures/` already
    has a usable image file (`ls tests/fixtures` before writing this) — if yes, reuse it; if not,
    generate one inline in the test with `imagecreatetruecolor()` + `imagejpeg()` to a temp file,
    matching how `render_png()`'s existing tests likely construct their own font/GD fixtures
    (read the existing test file's setup before deciding). Assert `render_png()` with that
    `photo_id` still returns a non-empty PNG string.
  - A `photo_id` pointing at a NON-image file (or a deleted attachment / unreadable path): assert
    `render_png()` still returns a non-empty PNG (the flat-square fallback), never `false`, never
    a fatal — this is the "never fatal, never blank" contract's exact test.
  - `PTK_Focal_Point::square_crop_rect()` is ALREADY tested in Task 1 — do not re-test its maths
    here, only that `render_png()` calls it correctly (e.g. via a fixture where the crop rectangle
    is knowable and a pixel sampled at a known coordinate matches the source image's color there —
    optional/nice-to-have if `imagecolorat()` makes this cheap; skip if it makes the test fragile,
    and say so in a comment).
- [ ] **Step 5: Implement `draw_square_photo()`** per the spec's Part D algorithm (load by mime,
  `function_exists('imagecreatefromwebp')` guard, `PTK_Focal_Point::square_crop_rect()`,
  `imagecopyresampled()`, scrim via `imagecolorallocatealpha()` + `imagealphablending()`), called
  from `render_png()` between the background fill and the eyebrow draw. Shrink the text size
  constants by the pinned `0.72` ratio when `$photo_id > 0` (spec Part D — apply to the
  `fit_text()`/`fit_block()` `$max`/`$min` arguments for the eyebrow, issue label, issue digits,
  dateline, and school name; leave the two hairline y-positions and the school-name block's
  bottom offset unchanged).
- [ ] **Step 6: Update `ensure_square()`.** Read `photo_id`/`photo_focal_x`/`photo_focal_y`/
  `photo_zoom` from `$args` (default `0`/`50`/`50`/`0`), pass them into
  `square_inputs_hash()` and `render_png()`'s `$args`.
- [ ] **Step 7: Run tests, confirm green.**
- [ ] **Step 8: Commit.** `git add -A -- pta-knowledge-hub/includes/class-share-data.php pta-knowledge-hub/includes/class-share-image.php pta-knowledge-hub/tests/test-share-data.php pta-knowledge-hub/tests/test-share-image.php`

---

### Task 7: Share-panel UI — "Use a photo behind the words," and the consent gap

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-panel.php`
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php` (`blocks_have_images()` call
  sites; expose the PII checkbox copy as a shared constant/string if it isn't already trivial to
  reuse)
- Modify: `pta-knowledge-hub/assets/js/share-panel.js`
- Modify: `pta-knowledge-hub/assets/css/share-panel.css`

- [ ] **Step 1: Extend `ajax_square()`'s mode vocabulary.** Add `mode=photo` (posted
  `attachment_id`, `focal_x`, `focal_y`, `zoom`; sanitize each through
  `PTK_Focal_Point::clamp_percent()`/`sanitize_zoom()` before saving), `mode=photo_from_featured`
  (no attachment id from the client — read `self::context( $post_id )['blocks']`'s
  `featured.image_id` server-side; refuse with a plain message if it's `0`), and `mode=no_photo`
  (calls `PTK_Share_Data::clear_square_photo()`). Existing `mode=custom`: add one line calling
  `clear_square_photo()` when switching to a custom upload (spec Part E).
- [ ] **Step 2: The consent gate (Decision 4).** Before any of the three new modes writes
  anything, check `get_post_meta( $post_id, PTK_Newsletter_Builder::META_PII_CONFIRMED, true )`.
  If set, proceed. If not, require `$_POST['pii_ok']` truthy; if absent,
  `wp_send_json_error( array( 'message' => '...' ), 409 )` with wording matching the main gate's
  voice (pull the exact sentence from `class-newsletter-builder.php:1097-1106` and adapt it
  minimally rather than writing new copy from scratch — read those lines again before writing the
  string). If `pii_ok` is truthy and confirmation wasn't already stored, `update_post_meta(
  $post_id, PTK_Newsletter_Builder::META_PII_CONFIRMED, current_time( 'Y-m-d' ) )` — the SAME key
  the main gate reads, so it shows confirmed everywhere after this.
- [ ] **Step 3: `blocks_have_images()` call sites gain the OR.** In
  `class-newsletter-builder.php`, both `handle_submission()` (`:218`) and `render_page()`
  (`:998`): `$has_images = PTK_Newsletter_Data::blocks_have_images( $blocks ) ||
  PTK_Share_Data::square_has_custom_photo( $edit_id ? $edit_id : $post_id )` — use whichever
  variable is in scope at each call site (read the surrounding code at both line numbers before
  writing this; `render_page()`'s variable naming may differ from `handle_submission()`'s).
- [ ] **Step 4: The panel UI.** In `square_html()` (`class-share-panel.php:412-474`), inside the
  Instagram-only section and only when `! $square['custom']`: a toggle ("Use a photo behind the
  words"), and — when on — "Use the top story's photo" (shown only when `$ctx['blocks']`'s
  featured block has an `image_id > 0`), "Choose a different photo," the focal-point-picker
  surface (`options.aspect = '1:1'`, reusing `focal-point-picker.js` from Task 5 — enqueue it
  alongside `ptk-share-panel` in `PTK_Share_Panel::enqueue_assets()`, which already loads on the
  same Builder page per its own docblock, fact 7 of the round-3 spec), and "Remove photo." Wire
  each action to the new AJAX modes in `assets/js/share-panel.js`, including surfacing the 409
  consent refusal as an inline message with a checkbox the volunteer ticks before retrying (mirror
  the existing `data-share-status` `role="status"` pattern already used for save/reset feedback in
  this file — read `share-panel.js`'s existing AJAX error handling before adding a new one so the
  pattern matches).
- [ ] **Step 5: Run tests, confirm green** (no new pure-PHP surface in this task beyond what
  Task 6 already tests — run the full suite to catch a stray syntax error).
- [ ] **Step 6: Commit.** `git add -A -- pta-knowledge-hub/includes/class-share-panel.php pta-knowledge-hub/includes/class-newsletter-builder.php pta-knowledge-hub/assets/js/share-panel.js pta-knowledge-hub/assets/css/share-panel.css`

---

### Task 8: Quick win check + full-suite regression pass

No new feature work — this task exists to re-run everything once photo, square, and consent
changes are all in place together, before the expensive Playground pass.

**Files:** none expected; fix forward in a small commit if something regressed.

- [ ] **Step 1: Full test suite.**
  `cd pta-knowledge-hub && for f in tests/test-*.php; do echo "== $f"; php "$f" || exit 1; done && node tests/test-relabel-js.mjs && node tests/test-focal-point-js.mjs`.
- [ ] **Step 2: Grep for one-sided borders across every file touched this round** (`grep -rn
  "border-left\|border-right" pta-knowledge-hub/includes/class-newsletter-renderer.php
  pta-knowledge-hub/includes/class-share-image.php pta-knowledge-hub/assets/css/
  newsletter-builder.css pta-knowledge-hub/assets/css/share-panel.css`) — zero hits expected;
  fix forward if not.
- [ ] **Step 3: Confirm at most one navy callout is still true** — round 3 adds no new navy fills
  (the square's scrim is black-alpha, not navy; the picker UI has no navy anywhere) — a quick
  read-through of Task 5-7's diffs for a stray `#1a2f5c` fill is enough, no new automated test
  needed.
- [ ] **Step 4: Commit only if Step 1 or 2 required a fix**, otherwise this task produces no
  commit.

---

### Task 9: Full Playground verification

```bash
npx --yes @wp-playground/cli@latest server --auto-mount "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round3/pta-knowledge-hub" --login --port 9405
```

Open `http://127.0.0.1:9405` (never `localhost`). Work through, in order:

- [ ] **Whole vs crop render.** Upload a landscape and a portrait test photo. Add one as the top
  story's image, leave it at "Show whole" — confirm the published newsletter's `<img>` is
  identical to a photo on a 4.3.0-only newsletter (view source, compare the `style` attribute).
  Switch it to "Crop to fit," drag the dot to a corner, save, confirm the published page shows a
  16:9 frame with the photo visibly cropped to that corner and no overflow outside the frame.
- [ ] **Focal/zoom persist and show in the live preview.** With the Builder open on step 3, drag
  the dot and zoom with the wheel — confirm the live preview panel updates without a page reload.
  Save as a draft, reload the Builder page fully (not just the panel), confirm the picker reopens
  showing the SAME focal point and zoom (not reset to center).
- [ ] **Zoom 100 is not stored.** Zoom in via keyboard `+`, then back down via `-` until the
  readout reads blank (fully unzoomed). Save. Check `ptk_nl_blocks` post meta directly (Query
  Monitor, or a throwaway `wp post meta get` via the Playground's WP-CLI if available, or by
  reading the AJAX preview response's underlying data) and confirm `image_zoom` is `0`.
- [ ] **Keyboard works.** Tab to the focal dot (confirm it receives visible focus), arrow-nudge
  it in all four directions, `+`/`-` with and without Shift — confirm the readout and the live
  preview both update on every key press.
- [ ] **Wheel over the photo zooms without scrolling the page.** Make the step-3 panel tall
  enough to need scrolling (add several story cards first). Roll the mouse wheel while hovering
  the crop-mode photo — confirm the PAGE does not scroll and only the photo zooms. This is the
  exact symptom if the `{ passive: false }` wheel listener regresses to a jQuery `.on('wheel',
  ...)` binding.
- [ ] **Thumbnails show.** Reload the Builder on an existing newsletter with saved photos —
  confirm each shows a real thumbnail image and filename, not "Image #N selected", and that this
  appears promptly (no long blank period before the fetch resolves — the fallback text chip
  should show first, then upgrade).
- [ ] **The square hash changes with photo/focal/zoom.** On step 4's share panel, note the
  square's current image URL. Turn on "Use a photo behind the words" → "Use the top story's
  photo" — confirm the URL changes. Adjust ONLY the focal point on the square's picker, confirm
  it changes again. Adjust ONLY the zoom, confirm it changes again.
  - **Photo cropping is visually verifiable in Playground even though text is not** (Playground's
    GD cannot draw text, per `capabilities()`'s docblock) — confirm the square's background shows
    a recognizable crop of the chosen photo (even if any text on top is missing/garbled), and that
    it is NOT the flat two-color square, which confirms `draw_square_photo()` ran.
  - For the TEXT-over-photo scrim/size behavior specifically, rely on Task 6's PHP unit tests
    (which can force `$caps['freetype'] => true` in an injected-capabilities test even though
    Playground's real environment can't draw); note in this checklist item that the visual
    "smaller text over a dark scrim" check was NOT possible in Playground and was verified via
    the unit test's injected capabilities instead — say so plainly, don't claim a visual check
    that didn't happen.
- [ ] **Consent gate covers the square photo.** Start a brand-new newsletter with NO photo
  anywhere in its blocks. On step 4, without ticking any photo checkbox yet, try "Use a photo
  behind the words" → pick any photo. Confirm it's refused with a plain-English message and an
  inline checkbox. Tick it, retry, confirm it now succeeds. Go back to step 1's PII gate (if
  visible) or attempt to Publish — confirm the checkbox there shows already confirmed (no second
  prompt).
- [ ] **The Builder still boots with no console errors** on both a new and an existing
  newsletter: walk all four steps, add a timeline row, a story with a cropped photo, a quick
  note, reorder in step 4, open and close the focal picker several times — no console errors at
  any point.
- [ ] **Delete any throwaway posts/probe files** created only for this verification pass before
  committing anything.
- [ ] **Commit** only if Playground verification required code fixes; otherwise this task
  produces no commit (verification-only).

---

### Task 10: Version 4.4.0 — changelog and zip

**Files:**
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php`
- Modify: `update-info.json`
- Rebuild: `pta-knowledge-hub.zip`

- [ ] **Step 1: Bump the version.** `pta-knowledge-hub.php`: `Version: 4.4.0` (header comment)
  and `define( 'PTK_VERSION', '4.4.0' );` — both.
- [ ] **Step 2: `update-info.json`.** Bump `"version"` to `"4.4.0"`, `"last_updated"` to today's
  date, and PREPEND a new `<h4>v4.4.0 — ...</h4><ul>...</ul>` entry ahead of the existing v4.3.0
  entry (the whole `changelog` value is one long string of stacked `<h4>`/`<ul>` blocks, newest
  first — read the existing string's exact structure before editing so the new entry matches
  its punctuation/voice, e.g. plain sentences, bold only for the headline change, no jargon).
  Cover, in plain language: every newsletter photo can now be shown whole (as before) or cropped
  to a wide frame, with a drag-the-dot-and-pinch-or-scroll-to-zoom control (no slider); a new
  thumbnail (instead of "Image #N") in the photo picker; the Instagram square can now show a real
  photo behind its text, either the top story's photo in one click or a different one; state
  plainly that the photo-privacy checkbox now also covers the square's photo, so it may ask for
  confirmation there too the first time.
- [ ] **Step 3: Rebuild the zip** from the worktree root:
  ```bash
  cd "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round3" && zip -rq pta-knowledge-hub.zip pta-knowledge-hub -x "pta-knowledge-hub/tests/*" -x "*/.DS_Store" -x "*/.*" -x "*/zz-*"
  ```
  Confirm `docs/` is not in the zip: `unzip -l pta-knowledge-hub.zip | grep -c '^.*docs/'` should
  print `0`.
- [ ] **Step 4: Final full test run.**
  `cd pta-knowledge-hub && for f in tests/test-*.php; do echo "== $f"; php "$f" || exit 1; done && node tests/test-relabel-js.mjs && node tests/test-focal-point-js.mjs`
- [ ] **Step 5: Commit.** `git add -A -- pta-knowledge-hub/pta-knowledge-hub.php update-info.json pta-knowledge-hub.zip` — **STOP here. No upload.**

---

## Risks carried into implementation (see spec for the full table)

- The 0.72 text-shrink ratio and the scrim alpha (Task 6 Step 5) are visual calls with no single
  spec-mandated number — pin them, comment why, and accept that Playground can only confirm the
  PHOTO half visually (its FreeType is broken); the TEXT-over-photo look is confirmed via the
  unit test's injected capabilities, not a screenshot, and Task 9's checklist says so explicitly
  rather than overclaiming a visual check.
- The consent-gap fix (Task 7) is the one place this round changes behavior for newsletters that
  use NO cropped photos at all (any newsletter that adds a square background photo now needs
  confirmation) — this is intentional (spec fact 11's gap) but worth flagging in code review as a
  genuine new prompt some volunteers will see for the first time.
- `imagecopyresampled()` on a large source photo is untested for real-world timing in this plan —
  accepted per the spec's risk table (gated to an authenticated admin request, only on an actual
  hash change, same shape as the class's existing expensive FreeType measurement loop).
