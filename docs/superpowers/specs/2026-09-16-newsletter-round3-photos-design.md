# Newsletter Round 3: Photos — Design

> **STATUS: READY TO PLAN** (2026-09-16). Product decisions are made and approved by Lucas;
> this document grounds them in the code as it stands on the `newsletter-round3` branch (built
> on 4.3.0, commit `3c7a8d2`). Plan: `docs/superpowers/plans/2026-09-16-newsletter-round3-photos.md`.

## The problem

Every newsletter photo today renders exactly as uploaded — no crop, no framing control — because
`maybe_image()` (`class-newsletter-renderer.php:731-745`) just emits `width:100%;height:auto`.
That is fine for a photo whose subject fills the frame, and wrong for one that doesn't: a
volunteer with a wide group shot or a portrait-oriented photo has no way to say "this is the
part that matters" short of cropping the file themselves before uploading, which is exactly the
kind of extra step round 1/2's governing rule (**easy to use, quick, very little setup**) rules
out.

A ported control already exists and is battle-tested: `docs/reference/tory-focal-point/` is the
focal-point-and-zoom picker from another Anthropic project (the "Ask Tory" / product-knowledge
hub this plugin was generalized from), used there to crop product photos. Round 3 ports its
maths and interaction model — not its React code — into this plugin's vanilla-JS Builder and
PHP renderer, and reuses the same control (square-shaped) to let the Instagram share square
show a real photo behind its text instead of a flat color field.

**The governing rule is still ease of use.** "Show whole" (today's behavior) stays the default
for every photo; nobody who doesn't touch this feature sees any difference. "Crop to fit" is an
opt-in, and its own control has **no slider** — Lucas rejected one explicitly when the reference
control was built ("I want it to feel effortless"): the photo itself is the control, via drag,
pinch, or wheel.

## Scope

**In round 3:** a fit mode (`whole` default / `crop`), a focal point and a zoom, stored per photo
next to its existing `image_id`, for both photo slots the data model has today (the top story's
`featured.image_id`, each story card's `story_cards.cards[].image_id`); a focal-point-and-zoom
picker ported from the reference implementation, wired into the Builder's existing generic
`[data-field]` serialize/prefill machinery; a 16:9 crop renderer for the public newsletter and
the live preview; a "photo behind the words" mode for the Instagram share square, reusing the
same picker at a 1:1 frame, drawn server-side with GD; and a thumbnail-instead-of-"Image #12"
quick win in the same picker UI.

**Deliberately not in round 3:** a zoom slider (rejected upstream, and the task is explicit);
automatic subject-centering ("can the Hub center product/garment photos automatically" is still
an open question upstream and out of scope here too — this ships the manual control only); any
change to the header/masthead logo (it has no `image_id` field, it is a site-level logo url, out
of the "every newsletter photo" scope); email rendering of cropped photos (the renderer stays
inline-styled so a *future* email renderer can reuse it, but today's email is a text-only teaser
with no photos at all — see round 2's newsletter-email-is-teaser decision, unchanged here); and
any change to the "custom" (school-uploaded, pre-finished) Instagram square path, which still
fully replaces the generated square exactly as today.

---

## Facts verified in code (2026-09-16)

1. **Every `image_id` in the data model, confirmed against `class-newsletter-data.php`:**
   `TYPE_FEATURED`'s `sanitize_block_data()` case (`:248-256`) has `image_id` (`absint`,
   default `0`, `:253`), and `TYPE_STORY_CARDS`'s case (`:258-274`) has one `image_id` per card
   (`:269`). No other block type has an `image_id` field — `TYPE_HEADER`, `TYPE_ANNOUNCEMENT`,
   `TYPE_EVENTS`, `TYPE_QUICK_NOTES`, `TYPE_FOOTER` do not carry photos today. `default_blocks()`
   (`:50-111`) confirms the same two slots (`:84`, cards array starts empty at `:93`). This is
   the exact and complete "every image_id" set the task means by "top story and stories."

2. **Where those images render today:** `maybe_image( $image_id, array $opts, $alt = '' )`
   (`class-newsletter-renderer.php:723-745`) is called from `render_featured()` (`:426`) and
   `render_story_cards()` (`:498`), the only two call sites. It returns `''` when `$image_id <= 0`
   or `$opts['image_url_cb']` is missing/uncallable (`:732-737`), else exactly:
   ```html
   <img src="{url}" alt="{alt}" style="display:block;width:100%;height:auto;border-radius:4px;margin:0;" />
   ```
   (`:745`). `$opts['image_url_cb']` is set once, in `PTK_Newsletter_Builder::render_opts()`
   (`:449-451`), as `function( $id ) { return wp_get_attachment_image_url( $id, 'large' ); }` —
   the ONE place that resolves an attachment id to a URL, shared by the save path and the live
   preview (`render_opts()`'s docblock, `:422-426`, already states this "used by both" contract).
   **"Show whole" must keep emitting this exact string, byte for byte**, matching the reference's
   own guarantee ("An unzoomed picture emits `object-position` and nothing else... byte for byte
   what every surface renders now").

3. **The Builder's `[data-field]` machinery is already fully generic and needs zero changes to
   carry new per-image fields.** Verified by reading `assets/js/newsletter-builder.js`:
   - `prefillFromData()` (`:867-908`) walks every `[data-field]` inside a block (skipping ones
     inside `[data-rows]`, handled separately) and calls `setFieldValue($field, data[field])`
     (`:884-889`) for whatever keys exist on the saved block's `data` object — an unrecognized
     key already in the DOM as a hidden input "just works" with no per-field wiring.
   - `serialize()` (`:1571-1614`) is the mirror: it walks every `[data-field]` present in the DOM
     (again skipping ones inside `[data-rows]`, handled by the rows loop at `:1596-1610`) and
     writes `data[field] = getFieldValue($field)` for each one (`:1613`... i.e. the block loop
     at `:1608-1611`). Any new hidden input with `[data-field="image_fit"]` etc. is picked up
     automatically.
   - `bindSerializeTriggers()` (`:1543-1553`) listens for `input change` on `#ptk-nl-blocks
     [data-field]` (delegated, `:1544-1546`) and calls `serializeAndPreview()` — so a script that
     sets a hidden field's value and fires a native `change` event re-serializes and re-renders
     the live preview with no new wiring.
   - `addRow()` (`:961-987`) — used both for a blank new story card (`:936`) and for hydrating an
     existing one from saved data (`prefillFromData()`'s rows loop, `:906`) — fills `[data-field]`
     children the same generic way (`:975-980`).
   - `setFieldValue()`/`getFieldValue()` (`:1518-1539`) special-case only `image_id` (parses it
     as an int and calls `refreshImageChip()`); every other field falls through to a plain
     `.val()` get/set (`:1526`, `:1536`), which is exactly right for a plain hidden text/number
     input holding `whole`/`crop`, a 0-100 integer, or a 0/100-250 zoom integer.
   - **The unsaved-changes guard already compares the exact string `serialize()` produces**
     (`formState()`, `:373-380`, reads `#ptk-nl-blocks-json` after calling `serialize()`) — so
     new fields participate in "did anything change" automatically, satisfying the task's hard
     constraint with no extra code.
   This means round 3's new stored fields need **no changes** to `prefillFromData`, `serialize`,
   `bindSerializeTriggers`, `addRow`, or the unsaved-changes guard — only new markup (hidden
   inputs) and a new picker script that reads/writes those inputs' `.val()` and fires `change`.

4. **The two markup sites for an image field, both read:** the featured block's static PHP markup
   (`class-newsletter-builder.php:1501-1511`, one `<label>Image</label>` field-group holding the
   hidden `image_id` input and the `Add image` button) and the story-card `<template
   data-row-template>` (`:1547-1554`, identical shape). `assignRowIds()`
   (`assets/js/newsletter-builder.js:996-1039`) only assigns a real `id`/`for`/`aria-describedby`
   to the field-group's FIRST `[data-field]` child (`$group.find('> [data-field]').first()`,
   `:1002`) and specifically treats a `type="hidden"` first field as needing its hint attached to
   the sibling `.ptk-nl-add-image` button instead (`:1029-1035`) — additional hidden
   `[data-field]` siblings added after `image_id` inside the same group need no id of their own
   (they carry no visible label), so this pattern is untouched by adding more hidden inputs to
   the same group.

5. **The image picker's current chip is a plain-text placeholder by its own docblock's
   admission:** `refreshImageChip()` (`assets/js/newsletter-builder.js:1492-1510`) renders
   `'Image #' + id + ' selected'` and says outright "A real thumbnail isn't available
   client-side without an extra AJAX round trip, so a plain-text chip stands in for MVP"
   (`:1495-1496`). `wp.media` is already enqueued on this page
   (`class-newsletter-builder.php:632`, `wp_enqueue_media()`) and its attachment model exposes
   `sizes.thumbnail.url` and `filename` once fetched — `mediaFrame`'s own `select` handler
   already calls `.toJSON()` on a freshly-picked attachment (`:1481`) and that JSON already has
   both keys for a picked attachment; for an attachment id loaded from saved data (page load,
   no picker interaction yet) the same JSON is available via `wp.media.attachment(id).fetch()`,
   which returns a jQuery-style promise resolving once WordPress's own `query-attachment` AJAX
   action responds. This is the exact "no extra round trip we didn't already plan for" the round
   3 task asks for, and the SAME fetch serves two needs at once: the "Image #N" → thumbnail
   quick win, and the "photo" the focal-point picker needs a `src` for.

6. **The renderer's `render()` dispatch loop** (`class-newsletter-renderer.php:76-91`, already
   extended once by round 2 for `render_news_cta()`) walks `$blocks` and calls `render_<type>`
   per block, then falls through to type-specific private methods (`render_featured()`,
   `render_story_cards()`) that both call the shared `maybe_image()` — the one place round 3's
   crop rendering needs to change to affect every photo in the newsletter, matching fact 2's "one
   `image_url_cb`, two call sites" shape.

7. **The share-panel's Instagram section, read in full
   (`includes/class-share-panel.php`):** `render()` (`:342-400`) renders each channel from
   `channel_labels()` (`:229-235`); the Instagram-only extra markup is the conditional block at
   `:390-395` (`square_html()` output plus `render_phone_handoff()`). `square_html()`
   (`:412-474`) already has the exact "never fatal" shape the task's `render_png()` extension
   must keep: it calls `PTK_Share_Image::ensure_square()` (`:419-428`), and on a `WP_Error`
   result falls to a plain sentence (`:435-436`, `:457-459`) rather than ever emitting a broken
   `<img>`. `ensure_square()`'s own args today are `issue`, `date`, `school_name`, `background`,
   `text` (`:419-428`, matching `class-share-image.php:608-634`'s signature) — this is where
   round 3's `photo_id`/`photo_focal_x`/`photo_focal_y`/`photo_zoom` args are added.

8. **The square's TWO existing "own picture" concepts, and why round 3's is a THIRD:**
   `PTK_Share_Data::get_square()`/`save_square()` (`class-share-data.php:134-146`) store
   `image_id` (the FINAL rendered PNG attachment — either GD-drawn or a school's own upload) and
   `custom` (`true` when a school uploaded a finished, already-square picture that fully replaces
   drawing, per `ensure_square()`'s `:625-633` "hand it back untouched" branch). Round 3's "photo
   behind the words" is neither of these: it is a THIRD input, a source photo that
   `render_png()` crops and composes UNDER the drawn text — still a GD-generated PNG, still
   subject to the SAME staleness hash and regeneration machinery as today's flat-color square,
   just with one more layer. `custom === true` (a school's own finished upload) and "photo behind
   the words" are mutually exclusive by construction: choosing a background photo only ever
   writes to the GENERATED-square meta, never to `META_SQUARE_CUSTOM`.

9. **`render_png()`'s full body** (`class-share-image.php:161-274`) draws, in order: the flat
   background fill (`imagefilledrectangle`, `:210`), the "NEWSLETTER" eyebrow + hairline
   (`:218-219`), the issue number (`:225-234`), the dateline (`:237-241`), a second hairline
   (`:249`), and the school name (`:251-262`) — ALL of it in `$text_col`
   (`self::allocate($im, $text)`, `:207`) against the flat `$bg_col` (`:206`). Round 3's photo
   layer draws between the background fill and the eyebrow: a cropped photo covering the full
   1080×1080 canvas, then a dark scrim, then the SAME text drawing calls unchanged (still
   `$text_col`, still every existing size/position/wrap/widow logic in `fit_text()`/`fit_block()`/
   `draw_tracked()` — none of that needs to change) — so the "smaller, over a dark scrim" part
   of the task is satisfied by resizing/repositioning the SAME draw calls, not rewriting them
   (see Part D).

10. **GD load functions for the three formats the task names, confirmed to exist in stock GD (no
    plugin-specific wrapper today):** `imagecreatefromjpeg()`, `imagecreatefrompng()` are always
    present when GD is compiled with those formats (near-universal); `imagecreatefromwebp()`
    exists only on a GD build compiled with libwebp support and is genuinely host-variable — the
    same "measure, don't trust `function_exists` alone where WordPress Playground lies"
    discipline `capabilities()`'s docblock already applies to FreeType (`:77-82`) is the model:
    check `function_exists( 'imagecreatefromwebp' )` before calling it, and treat its absence
    (or any load failure for any format) as "no photo," falling back to the flat square — never
    a fatal, matching the class's own stated contract throughout (`:16-19`, `:166-170`).

11. **The photo-privacy consent gate, read in full (`class-newsletter-builder.php`):**
    `handle_submission()` computes `$has_images = PTK_Newsletter_Data::blocks_have_images(
    $blocks )` (`:218`) and forces a publish down to a draft when photos exist and the checkbox
    wasn't ticked (`:220-223`); `render_page()` recomputes the same `$has_images` for the gate's
    visibility (`:998`). `blocks_have_images()` (`class-newsletter-data.php:366-388`) scans
    `$blocks` for any `image_id` key `> 0`, including nested rows — it has **no way to see** the
    Instagram square's photo, because that photo is never part of `$blocks`; it lives in
    `PTK_Share_Data`'s own post meta (`META_SQUARE_ID` etc., fact 8), set via
    `PTK_Share_Panel::ajax_square()` — a **separate AJAX endpoint the main form's submit-time
    gate never runs**. Worse: `handle_submission()`'s "once published, stays published" rule
    (`:227-235`, "this form must never take it down again") means a volunteer can add a
    background photo to the square via AJAX at any time AFTER first publish, entirely outside the
    gate that protects every other photo in the newsletter. The task's "the photo-privacy consent
    must also cover the square photo" names exactly this gap; Part E below closes it.

12. **`META_PII_CONFIRMED`** (`class-newsletter-builder.php:47`, `const META_PII_CONFIRMED =
    'ptk_nl_pii_confirmed'`) is a plain `const` (implicitly `public`), so
    `PTK_Newsletter_Builder::META_PII_CONFIRMED` is already readable from
    `class-share-panel.php` without a new accessor.

---

## Part A — Data model: fit, focal point, zoom, per photo

### New fields, exact keys, exact defaults

Both `TYPE_FEATURED` and each `TYPE_STORY_CARDS` card gain four fields alongside `image_id`, all
sanitized in `PTK_Newsletter_Data::sanitize_block_data()` via one shared pure helper (Decision 1):

| Field | Type | Default | Range / sanitizing |
|---|---|---|---|
| `image_fit` | string | `'whole'` | `'whole'` or `'crop'`; anything else → `'whole'` |
| `image_focal_x` | int | `50` | clamp 0-100; non-numeric/absent → `50` |
| `image_focal_y` | int | `50` | clamp 0-100; non-numeric/absent → `50` |
| `image_zoom` | int | `0` | `0` means "not zoomed" (the stored form of the reference's "dropped, not stored at 100" — see Decision 2); a submitted value `<= 100` sanitizes to `0`; `100 < value <= 250` clamps and rounds; non-numeric → `0` |

`default_blocks()` (`class-newsletter-data.php:50-111`) gains these four keys, at their defaults,
on the `TYPE_FEATURED` block's `data` array (`:79-88`); `TYPE_STORY_CARDS`'s `cards` array stays
`array()` (cards are created via the Builder's row template, fact 4, which already carries them).

### The shared sanitizer

```php
/**
 * The four fields every cropped photo carries, sanitized identically
 * wherever an image_id appears (featured, each story card).
 */
protected static function sanitize_image_crop( array $data ) {
    return array(
        'image_fit'     => ( isset( $data['image_fit'] ) && 'crop' === $data['image_fit'] ) ? 'crop' : 'whole',
        'image_focal_x' => PTK_Focal_Point::clamp_percent( isset( $data['image_focal_x'] ) ? $data['image_focal_x'] : 50 ),
        'image_focal_y' => PTK_Focal_Point::clamp_percent( isset( $data['image_focal_y'] ) ? $data['image_focal_y'] : 50 ),
        'image_zoom'    => PTK_Focal_Point::sanitize_zoom( isset( $data['image_zoom'] ) ? $data['image_zoom'] : 0 ),
    );
}
```

Called from inside the `TYPE_FEATURED` case (merged into the existing return array at
`:249-256`) and inside the `TYPE_STORY_CARDS` per-card loop (merged into the existing per-card
array at `:265-272`) via `array_merge( $existing, self::sanitize_image_crop( $data ) )` /
`array_merge( $existing_card, self::sanitize_image_crop( $card ) )`. This is the ONE place the
"safe defaults so existing data is untouched" requirement is satisfied: a newsletter saved before
round 3 has no `image_fit` key in its stored JSON at all, `isset()` is false for all four, and
every new field resolves to its default (`whole`, centered, unzoomed) — visually identical to
today, exactly the "Show whole" contract.

### `blocks_have_images()` is untouched

It already matches on any `image_id` key `> 0` regardless of what else is in the same `data`
array (`:374-380`) — adding four sibling keys next to `image_id` changes nothing about that scan.

---

## Part B — The maths, ported and tested (PHP + JS)

### New pure classes, one per language, one-for-one

`includes/class-focal-point.php`, class `PTK_Focal_Point`, WordPress-free like
`PTK_Newsletter_Data` (testable with plain `php`):

```php
class PTK_Focal_Point {
    const ZOOM_MIN = 100;
    const ZOOM_MAX = 250;

    /** Whole percent 0-100. Non-numeric/NaN/absent -> 50 (center), matching the reference's
     *  toPercent()'s "not finite -> 50" and the reference's clampPercent() rounding. */
    public static function clamp_percent( $value ) {
        if ( ! is_numeric( $value ) ) { return 50; }
        return (int) min( 100, max( 0, round( (float) $value ) ) );
    }

    /** Clamp+round into [100,250]; <=100 or non-numeric drops to the sentinel 0
     *  ("not zoomed" -- the stored equivalent of the reference's "absent key"). */
    public static function sanitize_zoom( $value ) {
        if ( ! is_numeric( $value ) ) { return 0; }
        $clamped = min( self::ZOOM_MAX, max( self::ZOOM_MIN, (float) $value ) );
        return ( $clamped <= self::ZOOM_MIN ) ? 0 : (int) round( $clamped );
    }

    /** The zoom actually used when rendering: the stored sentinel (0) reads as 100. */
    public static function effective_zoom( $stored_zoom ) {
        $z = (int) $stored_zoom;
        return ( $z >= self::ZOOM_MIN ) ? $z : 100;
    }

    /** object-position value, always both parts, always clamped. */
    public static function object_position( $x, $y ) {
        return self::clamp_percent( $x ) . '% ' . self::clamp_percent( $y ) . '%';
    }

    /**
     * The extra style fragment beyond object-fit:cover;object-position:...
     * for a zoomed CSS crop -- empty string when not zoomed, matching the
     * reference's focalZoomStyle() "unzoomed returns nothing at all."
     */
    public static function css_zoom_style( $x, $y, $stored_zoom ) {
        $effective = self::effective_zoom( $stored_zoom );
        if ( $effective <= self::ZOOM_MIN ) { return ''; }
        $origin = self::object_position( $x, $y );
        $scale  = round( $effective / 100, 4 );
        return 'transform:scale(' . $scale . ');transform-origin:' . $origin . ';';
    }

    /**
     * Square-crop rectangle in SOURCE pixels for a $dest x $dest square
     * (the Instagram square), covering $src_w x $src_h at $focal_x/$focal_y
     * (0-100) and $stored_zoom (0 or 100-250), object-fit:cover semantics.
     * Pure geometry -- see spec Part D for the derivation.
     *
     * @return array{0:float,1:float,2:float} [crop_x, crop_y, crop_side], all in source pixels.
     */
    public static function square_crop_rect( $src_w, $src_h, $focal_x, $focal_y, $stored_zoom ) {
        $src_w = max( 1.0, (float) $src_w );
        $src_h = max( 1.0, (float) $src_h );
        $zoom       = self::effective_zoom( $stored_zoom ) / 100;
        $cover_side = min( $src_w, $src_h );
        $crop_side  = $cover_side / $zoom;
        $avail_x    = $src_w - $crop_side;
        $avail_y    = $src_h - $crop_side;
        $fx         = self::clamp_percent( $focal_x ) / 100;
        $fy         = self::clamp_percent( $focal_y ) / 100;
        return array(
            max( 0.0, min( $avail_x, $avail_x * $fx ) ),
            max( 0.0, min( $avail_y, $avail_y * $fy ) ),
            $crop_side,
        );
    }
}
```

`square_crop_rect()`'s formula, verified 2026-09-16 with `php` against a 2000×1000 source,
1080-square destination (`min($src_w,$src_h)` = cover baseline; extra zoom shrinks the crop
window; the window's slide-range times the focal fraction positions it):

```
$ php -r '
function crop($sw,$sh,$fx,$fy,$z){ $z=max(100,min(250,$z?:100))/100; $c=min($sw,$sh); $cs=$c/$z;
  $ax=$sw-$cs; $ay=$sh-$cs; return [round(max(0,min($ax,$ax*$fx/100)),2), round(max(0,min($ay,$ay*$fy/100)),2), round($cs,2)]; }
var_dump(crop(2000,1000,50,50,100)); // centered, no zoom: [500, 0, 1000]
var_dump(crop(2000,1000,0,50,100));  // focal far left: [0, 0, 1000]
var_dump(crop(2000,1000,100,50,100)); // focal far right: [1000, 0, 1000]
var_dump(crop(2000,1000,50,50,200)); // 2x zoom, centered: [750, 250, 500]
'
```
outputs exactly `[500,0,1000]`, `[0,0,1000]`, `[1000,0,1000]`, `[750,250,500]` — a centered,
unzoomed crop on a 2:1 landscape source takes the full height and a centered 1000px-wide slice
(matching plain `object-fit:cover;object-position:50% 50%`); focal 0%/100% slides that slice to
the left/right edge; 200% zoom halves the crop window's side and re-centers the halved window,
exactly as `object-fit:cover` plus an extra `transform:scale(2)` would. This is the algorithm to
implement verbatim — the plan's PHP test re-runs these exact four cases.

### JS mirror

`assets/js/focal-point.js`, plain global functions (no ES modules — matches
`assets/js/newsletter-relabel.js`'s pattern exactly: top-level `function` declarations, a
`module.exports` guard at the bottom for node, loaded via `wp_enqueue_script` in the browser):

```js
var PTK_FOCAL_ZOOM_MIN = 100;
var PTK_FOCAL_ZOOM_MAX = 250;

function ptkFocalClampPercent(value) { /* same rule as PTK_Focal_Point::clamp_percent() */ }
function ptkFocalSanitizeZoom(value) { /* same rule as PTK_Focal_Point::sanitize_zoom() */ }
function ptkFocalEffectiveZoom(storedZoom) { /* same rule as ::effective_zoom() */ }
function ptkFocalPointerToFocal(rect, clientX, clientY) {
    // Ported from focal-point.ts's pointerToFocal(): clamped percent of the
    // rect; a zero-size rect (not yet laid out) returns { x: 50, y: 50 }.
}
function ptkFocalCssZoomStyle(x, y, storedZoom) { /* same rule as ::css_zoom_style(), returns a CSS string fragment */ }

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = {
        ptkFocalClampPercent: ptkFocalClampPercent,
        ptkFocalSanitizeZoom: ptkFocalSanitizeZoom,
        ptkFocalEffectiveZoom: ptkFocalEffectiveZoom,
        ptkFocalPointerToFocal: ptkFocalPointerToFocal,
        ptkFocalCssZoomStyle: ptkFocalCssZoomStyle
    };
}
```

`ptkFocalPointerToFocal` and `ptkFocalCssZoomStyle` are the two functions the picker control
(Part C) calls on every drag/pinch/wheel/keyboard event; the plan's node test
(`tests/test-focal-point-js.mjs`, styled exactly like `tests/test-relabel-js.mjs`) asserts they
agree number-for-number with `PTK_Focal_Point`'s PHP methods on the same inputs, and separately
pins the reference's own asserted cases translated to this module (zoom 120 → pinch 2× → 240;
zoom 200 halved → dropped to unzoomed; keyboard `+` → +5, Shift+`+` → +25; arrow nudge ±2/±10).

---

## Part C — The focal-point-and-zoom picker, ported to vanilla JS

### One control, two frame shapes

`assets/js/focal-point-picker.js`, a jQuery-based control (this codebase has no build step and
no React; jQuery is already a hard dependency of `newsletter-builder.js` and `share-panel.js`).
One function, `ptkInitFocalPicker($surface, options)`, instantiates the control inside any
container; `options.aspect` is `'16:9'` (a photo slot) or `'1:1'` (the Instagram square, Part D).
The control never assumes which frame it's in — the aspect only affects the CSS box the photo
sits in, never the maths (Part B's functions are frame-shape-agnostic; only
`square_crop_rect()`, which is GD-only and 1:1-only by name, is square-specific).

### Behavior, ported case-by-case from `FocalPointPicker.tsx`

- **Drag**: `pointerdown`/`pointermove`/`pointerup` on the photo surface (native DOM Pointer
  Events, not jQuery's `.on('mousedown', ...)` — the reference relies on `pointerId` to
  distinguish two simultaneous touches, which mouse events cannot do). On down, capture the
  pointer if `setPointerCapture` exists (feature-detect; degrade silently if not, matching the
  reference's own `typeof ... === 'function'` guard, `FocalPointPicker.tsx:153-159`) and call
  `ptkFocalPointerToFocal()` against `getBoundingClientRect()`; on move while dragging, same call;
  zoom rides along on every move exactly as the reference does (`report()`, `:142-145`) — write
  the CURRENT zoom back into the hidden field on every drag event, not just x/y, or a drag
  silently resets zoom (the exact regression the reference's own tests exist to pin,
  `FocalPointPicker.test.tsx:306-315`).
- **Pinch (touch)**: track live pointers in a `Map` keyed by `pointerId` (`pointers`, mirroring
  `FocalPointPicker.tsx:90`). A second `pointerdown` while one is already active computes the
  two-finger distance and stores `{ distance, zoom }` as the pinch's start state (`pinchStart`,
  `:161-168`) — **and abandons any in-progress single-finger drag** (`setDragging(false)`,
  `:166`), so a second finger landing mid-drag does not lurch the photo to the midpoint. Every
  subsequent `pointermove` with 2+ live pointers computes the new distance and sets zoom to
  `pinchStart.zoom * (newDistance / pinchStart.distance)` (`:179-183`) — scaled from the zoom the
  pinch BEGAN at, so releasing and pinching again continues rather than jumping (this is the
  exact behavior `FocalPointPicker.test.tsx`'s "spreads two fingers... from the zoom the pinch
  started at" case pins, and the plan's node test ports that same case: zoom 120, fingers 80px
  apart, moved to 160px apart → zoom 240).
- **Wheel (desktop)**: attached with `addEventListener('wheel', handler, { passive: false })` —
  **never** jQuery's `.on('wheel', ...)`, which registers passively and makes `preventDefault()`
  silently do nothing, exactly the trap the reference's PR write-up calls out by name
  (`PR-focal-zoom-description.md`, "The wheel listener is attached by hand with `passive:
  false`"). `event.preventDefault()` first (stops the page scrolling), then
  `setZoom(currentZoom - event.deltaY * 0.25)`, same multiplier as the reference
  (`FocalPointPicker.tsx:121`) so the feel matches exactly.
- **Keyboard**: the dot is a real, focusable `<button type="button" tabindex="0">` (not a `<div>`
  with a click handler — keyboard reachability is the whole point, per the reference's docblock).
  `+`/`=` and `-`/`_` zoom by 5 (25 with Shift); arrow keys nudge the focal point by 2 percentage
  points (10 with Shift), **carrying the current zoom along unchanged** in the same write (same
  trap as drag — the reference's dedicated regression test, `FocalPointPicker.tsx:222-226`,
  spreads `...(zoom > FOCAL_ZOOM_MIN ? { zoom } : {})` into every arrow-key update; the port must
  do the same: write zoom into the hidden field alongside x/y on every arrow-key change).
- **Zoom readout, not a control**: a `<span>` below the photo, always present (never
  conditionally rendered — the reference's own regression note explains why: rendering it only
  while zoomed reflows the row and visibly shoves the "Reset to center" button around the moment
  someone touches the wheel). Text is `''` when unzoomed, `"{round(effectiveZoom)}%"` when
  zoomed.
- **Reset**: a plain button, `"Reset to center"`, sets focal to 50/50 and zoom to the dropped
  sentinel (`0`) in one write.
- **No slider anywhere in this control.** The only numeric input a person can type is nothing —
  drag, pinch, wheel, or arrow keys are the entire surface.

### DOM shape and wiring into the existing hidden fields

The picker's surface replaces nothing already serialized — it READS and WRITES the same four
hidden `[data-field]` inputs (`image_fit`, `image_focal_x`, `image_focal_y`, `image_zoom`) fact 3
already established are auto-serialized/auto-prefilled/auto-dirty-tracked. Concretely, per image
field-group (both the static featured markup, `class-newsletter-builder.php:1501-1511`, and the
story-card `<template>`, `:1547-1554`):

```html
<div class="ptk-nl-field-group ptk-nl-image-group">
    <label>Image</label>
    <input type="hidden" data-field="image_id" value="0">
    <input type="hidden" data-field="image_fit" value="whole">
    <input type="hidden" data-field="image_focal_x" value="50">
    <input type="hidden" data-field="image_focal_y" value="50">
    <input type="hidden" data-field="image_zoom" value="0">
    <p class="description">Optional. Please don't use photos of students' faces.</p>
    <button type="button" class="button ptk-nl-add-image">Add image</button>
    <!-- refreshImageChip() appends the thumbnail chip here (Part F) -->
    <!-- when image_id > 0, a "Show whole photo / Crop to fit (16:9)" select
         and (only when image_fit=crop) the focal-point surface render here,
         built by ptkInitFocalPicker() rather than as static PHP markup,
         because the surface needs the attachment's URL (fact 5's
         wp.media.attachment(id).fetch()), which PHP does not have without
         a new query -- this keeps the "no extra round trip" property. -->
</div>
```

`bindImagePicker()` (`newsletter-builder.js:1432-1450`) already fires `serializeAndPreview()`
after a new attachment is picked (`:1449`) — extend its `select` handler (or a function it calls)
to also: reset `image_fit` to `whole` when a BRAND NEW photo replaces an old one in the same slot
(a fresh photo should not inherit a crop framed for a different picture), call
`refreshImageChip()` (Part F), and call a new `refreshFocalPicker($group)` that shows/hides/
(re)builds the fit-mode control and, when `image_fit === 'crop'`, the picker surface itself. The
same `refreshFocalPicker()` runs from `prefillFromData()`'s existing per-block loop (fact 3) once
per image group on page load, so an existing "crop" photo shows its picker immediately without
requiring a click.

### Fit-mode control

A plain `<select data-field="image_fit">` with two options, `Show whole photo` (`value="whole"`)
and `Crop to fit (16:9)` (`value="crop"`) — a `<select>` participates in the SAME generic
`[data-field]` machinery as every other single-value field (fact 3), needs no special-casing in
`getFieldValue`/`setFieldValue` (both already fall through to plain `.val()` for anything that
isn't `image_id`), and its own `change` handler (delegated, alongside the existing
`bindSerializeTriggers()` delegation) calls `refreshFocalPicker($group)` to show/hide the picker
surface — this is UI-only work, not a new serialization path.

---

## Part D — Rendering: 16:9 crop for photos, square crop for Instagram

### `maybe_image()`, extended (not replaced)

```php
private static function maybe_image( $image_id, array $data, array $opts, $alt = '' ) {
    if ( $image_id <= 0 ) { return ''; }
    if ( ! isset( $opts['image_url_cb'] ) || ! is_callable( $opts['image_url_cb'] ) ) { return ''; }
    $url = call_user_func( $opts['image_url_cb'], $image_id );
    if ( ! is_string( $url ) || '' === trim( $url ) ) { return ''; }

    $fit = isset( $data['image_fit'] ) && 'crop' === $data['image_fit'] ? 'crop' : 'whole';
    if ( 'whole' === $fit ) {
        // BYTE FOR BYTE what this returned before round 3 (fact 2) -- no visual
        // change for a newsletter that never touches "Crop to fit."
        return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" style="display:block;width:100%;height:auto;border-radius:4px;margin:0;" />';
    }

    $x    = isset( $data['image_focal_x'] ) ? $data['image_focal_x'] : 50;
    $y    = isset( $data['image_focal_y'] ) ? $data['image_focal_y'] : 50;
    $zoom = isset( $data['image_zoom'] ) ? $data['image_zoom'] : 0;
    $pos  = PTK_Focal_Point::object_position( $x, $y );
    $zoom_style = PTK_Focal_Point::css_zoom_style( $x, $y, $zoom );

    // The frame MUST clip -- a zoomed photo is scaled past its own box on
    // purpose (reference: "two boxes that forgot to clip"). aspect-ratio
    // gives the 16:9 frame without a fixed pixel height, so this keeps
    // working at every screen width the newsletter already supports.
    return '<div style="width:100%;aspect-ratio:16/9;overflow:hidden;border-radius:4px;">'
        . '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" style="display:block;width:100%;height:100%;object-fit:cover;object-position:' . esc_attr( $pos ) . ';' . esc_attr( $zoom_style ) . '" />'
        . '</div>';
}
```

Both call sites (`render_featured()` `:426`, `render_story_cards()` `:498`) pass the block's
`$data`/`$card` array through as the new second argument (signature grows from
`maybe_image( $image_id, $opts, $alt )` to `maybe_image( $image_id, $data, $opts, $alt )` —
update both call sites).

Note per the task: **email clients ignore `object-fit`.** This is accepted rather than worked
around, because the plugin's only email surface (`#040`-style teaser email, round 2's
"newsletter-email-is-teaser" decision) carries no photos at all — the renderer's inline-style
discipline (doc-block: "applied inline so the same markup is safe to reuse in a future email
renderer") is preserved for whenever that changes, but nothing in round 3 needs it to render
correctly in an email today.

### The live preview shows the crop

`writePreview()`/the AJAX preview handler already call `render_opts()` +
`PTK_Newsletter_Renderer::render()` — the exact pair the publish path uses (round 2 fact,
unchanged). Because the crop logic lives entirely inside `maybe_image()`, the live preview shows
it automatically the moment a volunteer flips a photo to "Crop to fit" and the Builder's existing
`serializeAndPreview()` round-trip re-renders — **no separate preview wiring needed**, verify in
Playground rather than adding code for it (same pattern round 2's Part C used).

### The Instagram square: photo behind the words

`render_png()` (`class-share-image.php:161-274`) gains a photo layer, drawn between the flat
background fill (`:210`) and the eyebrow (`:218`):

```php
// New $args keys: photo_id (0 = no photo, the existing flat-color path),
// photo_focal_x, photo_focal_y, photo_zoom (same semantics as Part A/B).
$photo_id = isset( $args['photo_id'] ) ? absint( $args['photo_id'] ) : 0;
if ( $photo_id > 0 ) {
    self::draw_square_photo( $im, $photo_id, $args ); // draws photo + scrim, or does nothing on any failure
}
```

`draw_square_photo( $im, $photo_id, array $args )`:

1. Resolve the source file path via `get_attached_file( $photo_id )`; bail (return, no-op — the
   flat background fill already drawn stands as the fallback) if it's not a readable file.
2. `getimagesize()` the file; bail if it fails or reports a mime type this method doesn't
   recognize.
3. Load via the matching GD function: `imagecreatefromjpeg()` for `image/jpeg`,
   `imagecreatefrompng()` for `image/png`, `imagecreatefromwebp()` for `image/webp` **only when
   `function_exists( 'imagecreatefromwebp' )`** (fact 10 — host-variable); bail on any other
   mime, a missing function, or a `false` return (corrupt file).
4. Compute the crop rectangle: `list( $cx, $cy, $cs ) = PTK_Focal_Point::square_crop_rect(
   imagesx( $src ), imagesy( $src ), $args['photo_focal_x'], $args['photo_focal_y'],
   $args['photo_zoom'] )` (Part B's verified formula).
5. `imagecopyresampled( $im, $src, 0, 0, (int) $cx, (int) $cy, self::SIZE, self::SIZE, (int)
   round( $cs ), (int) round( $cs ) )` — draws the cropped, resampled photo over the flat fill,
   covering the full 1080×1080 canvas.
6. Free `$src` (same `PHP_VERSION_ID < 80000` guard as `free()`, `:283-287`).
7. Draw the scrim: a semi-transparent dark rectangle over the whole canvas, via
   `imagecolorallocatealpha( $im, 0, 0, 0, $alpha )` (alpha in GD's 0-127 range, 127 = fully
   transparent; pick a fixed alpha that reads as "smaller text over a dark wash" per the task —
   **concrete choice, pin it and screenshot-check in Playground**: `imagecolorallocatealpha( $im,
   0, 0, 0, 64 )`, roughly 50% black, applied with `imagealphablending( $im, true )` set before
   the fill and reset to `false` afterward so the later text draws are not blended unexpectedly)
   and `imagefilledrectangle( $im, 0, 0, self::SIZE - 1, self::SIZE - 1, $scrim )`.
8. Return normally; the caller's existing eyebrow/issue/dateline/school-name draw calls run
   unchanged after this, now over the photo+scrim instead of the flat fill.

**"Smaller" text, per the task ("the square keeps 'Newsletter', the issue number and the week on
top, but smaller, over a dark scrim"):** when `$photo_id > 0`, the existing size constants passed
to `fit_text()`/`fit_block()` for the eyebrow (`30`/`11` track, `:218`), issue label (`34`/`12`
track, `:230`) and issue digits (`300`/`96` bounds, `:232`) shrink by a fixed ratio — concretely,
multiply every `$max`/`$min` size argument by `0.72` when a photo is present (issue digits: max
300→216, min 96→69; eyebrow 30→22; dateline 56→40; school 54→39) so the numerals still dominate
the frame but leave more of the photo visible; the two hairline y-positions and the school-name
block's bottom offset stay as-is (the photo fills edge-to-edge regardless of text size, so
layout coordinates don't need to move, only the type scale). **Pin this ratio in Playground**
(task 8 of the plan) — it's a visual call, not a hard requirement from the spec text, exactly
like round 2's hairline-color formula was.

### `ensure_square()` and the hash

`ensure_square( $post_id, array $args )` (`class-share-image.php:608-734`) gains `photo_id`,
`photo_focal_x`, `photo_focal_y`, `photo_zoom` to its `$args`, read straight through from
`PTK_Share_Data::get_square_photo( $post_id )` (new, Part E) at the ONE existing caller,
`PTK_Share_Panel::square_html()` (`:419-428`). `PTK_Share_Data::square_inputs_hash()`
(`class-share-data.php:50-52`) grows from `( $issue, $date, $school_name, $background, $text,
$version )` to `( $issue, $date, $school_name, $background, $text, $photo_id, $photo_focal_x,
$photo_focal_y, $photo_zoom, $version )` — `$version` stays last (matches round 2's own pattern
of appending new inputs before the trailing version param); its one caller
(`ensure_square()`, `:651`) passes the four new values through.

---

## Part E — Photo behind the Instagram square: the share-panel UI and consent gap

### New storage

`PTK_Share_Data` gains a fourth small storage pair, alongside `get_square()`/`save_square()`
(`class-share-data.php:134-146`):

```php
const META_SQUARE_PHOTO_ID     = '_ptk_share_square_photo_id';
const META_SQUARE_PHOTO_FOCAL_X = '_ptk_share_square_photo_focal_x';
const META_SQUARE_PHOTO_FOCAL_Y = '_ptk_share_square_photo_focal_y';
const META_SQUARE_PHOTO_ZOOM   = '_ptk_share_square_photo_zoom';

public static function get_square_photo( $post_id ) {
    return array(
        'photo_id' => absint( get_post_meta( $post_id, self::META_SQUARE_PHOTO_ID, true ) ),
        'focal_x'  => PTK_Focal_Point::clamp_percent( get_post_meta( $post_id, self::META_SQUARE_PHOTO_FOCAL_X, true ) ),
        'focal_y'  => PTK_Focal_Point::clamp_percent( get_post_meta( $post_id, self::META_SQUARE_PHOTO_FOCAL_Y, true ) ),
        'zoom'     => PTK_Focal_Point::sanitize_zoom( get_post_meta( $post_id, self::META_SQUARE_PHOTO_ZOOM, true ) ),
    );
}

public static function save_square_photo( $post_id, $photo_id, $focal_x, $focal_y, $zoom ) { /* update_post_meta x4 */ }
public static function clear_square_photo( $post_id ) { /* delete_post_meta x4, back to the flat square */ }
```

A `photo_id` of `0` (the default — every newsletter today has none) is the "flat color square,
exactly as today" state; `render_png()`'s `draw_square_photo()` is simply never called (Part D,
step "photo_id > 0").

### The share-panel UI

Inside `square_html()`'s Instagram-only markup (`class-share-panel.php:390-395`), add — only when
`! $square['custom']` (a school's own uploaded finished square has nothing to layer a photo
behind, fact 8) — a new subsection: "Use a photo behind the words," a checkbox/toggle that, when
on, shows:

- **"Use the top story's photo"** — a one-click button, shown only when `featured.image_id > 0`
  for THIS newsletter's current blocks (read via the same `$ctx['blocks']` `square_html()`
  already has, fact "context()"). Clicking it posts to a new AJAX action with no attachment id at
  all — the handler reads `featured.image_id` off the post's OWN saved blocks server-side (not a
  value round-tripped from the client), so there is no way for this action to attach a photo the
  newsletter doesn't already contain.
- **"Choose a different photo"** — opens the same `wp.media` picker pattern `openImagePicker()`
  already uses (`newsletter-builder.js:1462-1488`), scoped to `library: { type: 'image' }`.
- Once a photo is chosen (either path), the SAME `ptkInitFocalPicker()` control from Part C
  renders in `options.aspect = '1:1'` mode, writing to hidden fields the panel's own AJAX save
  posts alongside the attachment id.
- **"Remove photo"** — clears back to the flat square.

New/extended AJAX, in `PTK_Share_Panel`:

- Extend `ajax_square()`'s `mode` vocabulary (`:190-221`) with `mode=photo` (attachment_id,
  focal_x, focal_y, zoom posted) and `mode=photo_from_featured` (no attachment id posted; reads
  `featured.image_id` from `self::context( $post_id )['blocks']` server-side) and `mode=no_photo`
  (clears the four meta keys). Each of the photo-setting modes calls
  `PTK_Share_Data::save_square_photo()` then re-renders via the existing `square_html()` call at
  the end of `ajax_square()` (`:219-221`), unchanged.
- **`mode=custom` (a school's own finished upload) already clears nothing about the photo-behind
  fields** — add one line: when switching TO `custom`, also call `PTK_Share_Data::
  clear_square_photo( $post_id )`, so switching back to "generated" later (`mode=generated`,
  `PTK_Share_Image::use_generated_square()`) doesn't resurrect a stale photo choice nobody
  confirmed consent for after the intervening custom upload (belt-and-suspenders with Decision 4
  below, which is the real gate).

### Closing the consent gap (fact 11/12)

**Decision 4**: `ajax_square()`'s photo-setting modes (`photo`, `photo_from_featured`) require
the SAME photo-privacy confirmation the main form's Publish button requires, checked
independently of it, because they are reachable independently of it (fact 11).

- If `get_post_meta( $post_id, PTK_Newsletter_Builder::META_PII_CONFIRMED, true )` is already
  set (this newsletter has been confirmed once, for ANY photo, ever — matching the existing
  "first confirmation is kept, never re-asked" rule the main gate already implements,
  `class-newsletter-builder.php:246`), proceed with no new prompt.
- If it is NOT set, `ajax_square()` requires `$_POST['pii_ok']` to be truthy on the SAME request
  that sets the photo; if absent, `wp_send_json_error()` (409) with wording that matches the
  existing gate's voice ("Please confirm the photos are OK before using one on the Instagram
  square — the same checkbox as Publish."), and the panel's "Use a photo behind the words" UI
  shows an inline checkbox (same copy as the main gate's checkbox,
  `class-newsletter-builder.php:1097-1106`, reused as a string constant so the two never drift)
  that must be ticked before the "Use the top story's photo" / "Choose a different photo" actions
  are enabled. Ticking it and succeeding sets `META_PII_CONFIRMED` to today's date via
  `update_post_meta()` — the SAME meta key the main gate reads, so returning to step 4's main
  gate (or a later save) sees it already confirmed and doesn't ask twice.
- `blocks_have_images()`'s two call sites (`class-newsletter-builder.php:218`, `:998`) each gain
  an `||` with a new tiny accessor, `PTK_Share_Data::square_has_custom_photo( $post_id )` (`0 !==
  get_square_photo( $post_id )['photo_id']`, one cheap meta read, no query) — so a newsletter
  whose ONLY photo is the Instagram square's background photo (no `image_id` anywhere in blocks)
  still shows and enforces the main gate's checkbox on save, matching the task's "must also
  cover the square photo" for the save path too, not only the AJAX path.

This closes the gap without inventing a second consent MECHANISM — it reuses
`META_PII_CONFIRMED` and the exact wording already in place, extended to a second entry point.

---

## Part F — Quick win: thumbnail instead of "Image #12 selected"

`refreshImageChip()` (`newsletter-builder.js:1492-1510`) is extended, not replaced:

```js
function refreshImageChip($hidden) {
    var $group = $hidden.closest('.ptk-nl-field-group');
    var id = parseInt($hidden.val(), 10) || 0;
    $group.find('.ptk-nl-image-chip').remove();
    if (id <= 0) { return; }

    // Placeholder chip immediately (no flash of nothing), upgraded to a
    // thumbnail once wp.media resolves it -- attachment data for a NEWLY
    // picked image is already in hand (mediaFrame's select handler has the
    // full JSON); for one hydrated from saved data on page load, fetch it
    // once via wp.media's own attachment cache (fact 5) -- the SAME call
    // Part C's picker needs for its `src`, so this and the focal picker
    // share one fetch per image_id, not two.
    var $chip = $('<span class="ptk-nl-image-chip"><span class="ptk-nl-image-thumb"></span>'
        + '<span class="ptk-nl-image-name">Image #' + id + '</span> '
        + '<button type="button" class="button button-small ptk-nl-remove-image">Remove image</button></span>');
    $group.append($chip);

    fetchAttachment(id).done(function (attachment) {
        var thumbUrl = attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url;
        if (thumbUrl) {
            $chip.find('.ptk-nl-image-thumb').html('<img src="' + thumbUrl + '" alt="" width="48" height="48">');
        }
        if (attachment.filename) {
            $chip.find('.ptk-nl-image-name').text(attachment.filename);
        }
    });
}

/** One shared attachment fetch/cache, id -> wp.media attachment JSON (a promise). */
var attachmentCache = {};
function fetchAttachment(id) {
    if (attachmentCache[id]) { return attachmentCache[id]; }
    var attachment = wp.media.attachment(id);
    attachmentCache[id] = attachment.fetch().then(function () { return attachment.toJSON(); });
    return attachmentCache[id];
}
```

`.ptk-nl-image-thumb` gets a small (`48×48`, `object-fit:cover`, `border-radius:3px`) CSS rule in
`assets/css/newsletter-builder.css`, alongside the existing `.ptk-nl-add-image` rule (`:383`).
The fallback text (`"Image #12"`) stays visible until the fetch resolves, and stays as the ID if
`attachment.filename` never arrives (network hiccup, a since-deleted attachment) — never a blank
chip.

---

## Decisions

| # | Decision | Chosen, and why |
|---|---|---|
| 1 | **One shared sanitizer for the four crop fields, called from both TYPE_FEATURED and TYPE_STORY_CARDS** | The two block types' image handling is already identical in shape (fact 1); duplicating four field sanitizers per card type instead of factoring them once would drift the first time only one call site got a fix. |
| 2 | **Zoom stored as an integer sentinel (`0` = unzoomed), not an optional/absent key** | The PHP block data model is a fixed-shape associative array everywhere else (every field always present, blank/zero standing in for "unset" — `image_id: 0`, `eyebrow: ''`), unlike the reference's TypeScript object where `zoom` is a genuinely optional property. `0` is never a valid zoom (the real range starts at 100), so it is an unambiguous, type-stable sentinel that ports the reference's "dropped rather than stored" guarantee into a fixed schema without introducing the one inconsistent field in the whole data model. |
| 3 | **The picker surface is built by JS from an attachment fetch, not server-rendered PHP** | PHP has no attachment URL to hand the picker without a new per-image query at page-render time (multiplied by however many photos a newsletter has); `wp.media.attachment(id).fetch()` is already the mechanism this same round uses for the thumbnail quick win (fact 5), so building the picker in JS reuses that one fetch instead of adding a second data path. |
| 4 | **The photo-privacy consent gap is closed by reusing `META_PII_CONFIRMED`, not a second consent flag** | Two independent "have you confirmed no children's faces" states for the same newsletter would be confusing to reason about and easy for a future change to desync; the existing meta already means exactly "this newsletter's photos are confirmed," and the square's background photo is just another newsletter photo the gate didn't know how to see yet (fact 11). |
| 5 | **"Photo behind the words" is a variant of the GENERATED square, never compatible with a school's own uploaded finished square** | A school that uploads its own finished 1080×1080 image (`custom === true`) has already made every layout decision GD would otherwise make; layering a background photo under GD-drawn text on TOP of an image the school designed themselves would silently damage their own work. Mutually exclusive by construction (Part E). |
| 6 | **Text shrinks by a fixed ratio (0.72) when a background photo is present, not a second user-facing size setting** | Matches round 2's own precedent for an unspecified cosmetic value (the hairline color formula, "pin it down with a concrete formula... not a new user-facing setting") — "very little setup" rules out a font-size control for this. |
| 7 | **`webp` support is checked with `function_exists`, treated as a per-photo degrade, never a whole-square capability gate** | Unlike FreeType (which the class already gates the ENTIRE square on, because a square with no words is worse than no square, fact `capabilities()`), a webp photo that can't load still leaves a perfectly good flat-color square available — gating on it would turn "I can't draw this specific format" into "nothing works," which the class's own stated contract ("never fatal, never blank") forbids. |

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| `aspect-ratio` CSS is unsupported in genuinely old browsers (pre-2021 Safari/Chrome) | Accepted: the renderer already assumes evergreen browsers for its flex layouts (masthead, Coming-up header) introduced in round 2 with no fallback; a photo without `aspect-ratio` support simply renders at its natural height inside a `width:100%` box, which is a graceful (if imperfect) degrade, not a broken layout. |
| The webp GD function is genuinely absent on some hosts (fact 10) | `draw_square_photo()`'s `function_exists()` check degrades to "no photo, flat square" per Decision 7 — never a fatal error, verified with an injected-capabilities test the same way `render_png( $args, $caps )`'s existing tests inject `$caps` (`class-share-image.php:161`, testable design already in place). |
| The 0.72 text-shrink ratio (Part D) is a visual call with no spec-mandated number | Pin it, screenshot-check in Playground, and say so in a one-line code comment — exactly the pattern round 2's own risk table used for its hairline-color formula, which shipped the same way. |
| A newly-picked photo inherits a crop framed for the PREVIOUS photo in the same slot | `bindImagePicker()`'s extended `select` handler (Part C) resets `image_fit` to `whole` whenever `image_id` changes to a NEW value, so a fresh photo always starts at "Show whole" and a volunteer re-crops deliberately rather than inheriting an old frame that may not even contain the new photo's subject. |
| The consent-gap fix (Part E) adds a second call site that must agree with the main gate's wording and behavior forever | Both read/write the SAME `META_PII_CONFIRMED` constant and (per Decision 4) the checkbox copy is pulled from one shared string rather than duplicated, so a future wording change to the main gate can't silently leave the square's copy stale. |
| `imagecopyresampled()` on a very large source photo (e.g. a 12MP phone photo) is measurably slower than the class's existing pure-drawing calls | Accepted without a resize step: `ensure_square()` already only runs on an admin request by someone with `edit_post` (fact `may_write()`, `class-share-image.php:796-807`), never on a public page load, and only when the input hash actually changed (`should_regenerate()`) — the same "expensive but rare and gated" shape the class already relies on for its FreeType text measurement loop (`fit_text()`'s per-size-step `imagettfbbox()` calls). |

---

## Verification (required before the version bump)

1. **Unit tests green:** `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done && node tests/test-relabel-js.mjs && node tests/test-focal-point-js.mjs`.
2. **The pure maths agrees PHP-to-JS** on the same fixture inputs (Part B) — both test files assert
   the reference's own pinned cases (zoom 120→240 via 2× pinch; zoom 200 halved → dropped;
   keyboard +5/+25; arrow ±2/±10) and the `square_crop_rect()`/`crop()` four cases verified above.
3. **WordPress Playground**, `http://127.0.0.1:9405` (always `127.0.0.1`; auto-login cookie + 302;
   probe with `curl -s -L -c /tmp/r3.txt -b /tmp/r3.txt http://127.0.0.1:9405/wp-admin/...`;
   delete throwaway probe posts/files before committing):
   - **Whole vs crop render**: a photo left at "Show whole" renders identically to a 4.3.0
     newsletter (byte-diff the `<img>` tag if practical); a photo switched to "Crop to fit" shows
     a 16:9 frame with the chosen focal point centered and, once zoomed, visibly cropped tighter
     with no overflow outside the frame (confirms `overflow:hidden` actually clips).
   - **Focal/zoom persist and show in the live preview**: drag the dot, zoom via wheel, save as a
     draft, reload the Builder — the picker reopens at the same focal point and zoom, and the
     live preview reflects it without a page reload.
   - **Zoom 100 is not stored**: set zoom to something above 100 via keyboard, then zoom back down
     to exactly 100 via `-`; save; inspect `ptk_nl_blocks` meta directly (or via the preview
     endpoint's JSON) and confirm `image_zoom` is `0`, not `100`.
   - **Keyboard works**: tab to the focal dot, arrow-nudge it, `+`/`-` zoom with and without Shift,
     confirm the readout updates and the hidden fields change.
   - **Wheel over the photo zooms without scrolling the page**: with the Builder's step 3/4 panel
     tall enough to scroll, roll the wheel over a crop-mode photo and confirm the PAGE does not
     scroll (only the photo zooms) — this is the `{ passive: false }` trap's exact symptom if it
     regresses.
   - **Thumbnails show**: an existing newsletter's saved photos show a real thumbnail + filename
     on the Builder's load, not "Image #N selected".
   - **The square hash changes with photo/focal/zoom**: note the square's attachment id, turn on
     "Use a photo behind the words" → "Use the top story's photo," confirm the id changes; change
     ONLY the focal point, confirm it changes again; change ONLY the zoom, confirm it changes
     again. **Because WordPress Playground's FreeType is broken (GD claims support but draws
     nothing, per `capabilities()`'s own docblock), the TEXT half of this square cannot be
     visually verified there** — verify the PHOTO half instead by either (a) temporarily calling
     `PTK_Share_Image::draw_square_photo()` in isolation via a throwaway admin-ajax probe that
     saves the PNG to a file and checking its pixel values at known coordinates with
     `imagecolorat()`, or (b) trusting the PHP unit test's injected-`$caps` render path (which can
     force `freetype => false` and confirm the method still returns a valid PNG with photo pixels
     present) and confirming only NON-text behavior (attachment id changes, no fatal, no blank
     image) live in Playground. Document which approach was used in the plan's task notes.
   - **Consent gate covers the square photo**: on a newsletter with NO photos in its blocks and no
     prior confirmation, attempt "Use the top story's photo" (with a featured image set) via the
     share panel and confirm it's refused/gated until the checkbox is ticked; confirm ticking it
     sets the SAME `ptk_nl_pii_confirmed` meta the main gate reads, and reopening the Builder's
     step 1 gate shows it already confirmed.
   - **The Builder boots with no console errors** on both a new and an existing newsletter, with
     the focal picker open, across at least one drag, one wheel-zoom, and one keyboard nudge.
4. **No one-sided borders**: grep every new renderer/picker markup string literal for
   `border-left`/`border-right` — zero hits (the picker's dot, frame, and readout use no borders
   at all beyond the dot's own all-sides `border: 2px solid #fff`, ported unchanged from the
   reference).
