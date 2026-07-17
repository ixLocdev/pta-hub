# Newsletter Wizard + Live Preview — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Newsletter Builder's single long form into a 4-step wizard with a live preview, so a first-time PTA volunteer isn't daunted — without changing the newsletter's published output, data model, or save path.

**Architecture:** The six block sections stay a **flat list** inside `#ptk-nl-blocks` (non-negotiable — `serialize()` reads direct children and takes newsletter order from DOM order). Steps are a **presentation layer**: every step-owned element carries `data-step="N"` and the JS shows/hides by attribute. **Step is a function of block TYPE (fixed); newsletter order is DOM order (mutable)** — they're independent, so a section dragged to the bottom is still edited on its own step. Steps 1–3 are pure writing; step 4 arranges (drag + Move up/down), runs the photo check, and publishes. The live preview is an **iframe** rendered by the **real PHP renderer** over AJAX — one renderer, never two.

**Tech Stack:** PHP (WordPress plugin, no framework), vanilla JS + jQuery + `wp.media` + `jquery-ui-sortable`, plain-php unit tests (no PHPUnit — this repo has no harness and we are not adding one), node for JS syntax checks, WordPress Playground for real end-to-end verification.

**Spec:** `docs/superpowers/specs/2026-07-16-newsletter-wizard-live-preview-design.md` — read §5a (the flat-DOM constraint) before touching anything.

---

## Load-bearing constraints (violate these and saving silently breaks)

1. **All six block sections MUST remain direct children of `#ptk-nl-blocks`.** `serialize()` (assets/js/newsletter-builder.js:416) and `updateMoveButtonStates()` (:267) use `$('#ptk-nl-blocks > .ptk-nl-block')`; block order is DOM order. **Never wrap sections in per-step parents.** Steps show/hide.
2. **Published output must not change.** The renderer's two new behaviours (`data-ptk-block`, placeholder stubs) are additive; placeholders are **preview-only** and never set by the save path. Phase 1's renderer tests must keep passing **unmodified**.
3. **One renderer.** The preview calls `PTK_Newsletter_Renderer::render()` server-side. Do NOT port rendering to JS.

## File structure

| File | Responsibility | Change |
|------|----------------|--------|
| `pta-knowledge-hub/includes/class-newsletter-renderer.php` | blocks → HTML | + `data-ptk-block` attr; + preview placeholder mode; `$opts` added to two signatures |
| `pta-knowledge-hub/includes/class-newsletter-builder.php` | admin screen + save | + wizard chrome, `data-step`; − Move/Remove from sections; + `render_opts()` helper; + AJAX preview endpoint; + step-4 arrange list |
| `pta-knowledge-hub/assets/js/newsletter-builder.js` | form behaviour | + step nav; + arrange list handlers (by type); + include/skip; + debounced preview fetch |
| `pta-knowledge-hub/assets/css/newsletter-builder.css` | styling | + wizard layout, sidebar, preview panel, responsive collapse |
| `pta-knowledge-hub/tests/test-newsletter-renderer.php` | renderer tests | + data-attr + placeholder assertions |

Unchanged: `class-newsletter-data.php`, the save path's behaviour, the block model, `class-newsletter-post-type.php`, `class-public-preview.php`.

---

## Task 1: Renderer — `$opts` on every block renderer + `data-ptk-block`

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-renderer.php`
- Test: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: Write the failing test** (append before `ptk_test_done()`; keep all existing assertions)

```php
// Every rendered block carries a data-ptk-block hook for the preview highlight.
$blocks2 = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'headline' => 'Week of X', 'greeting' => 'Hi' ) ),
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thu', 'text' => 'Last day' ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'Bye', 'links' => array() ) ),
);
$html2 = PTK_Newsletter_Renderer::render( $blocks2, array(
    'issue' => 5, 'date' => '2026-07-16', 'today' => '2026-07-16',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE PTA',
) );
ptk_test_ok( strpos( $html2, 'data-ptk-block="header"' ) !== false, 'header carries data-ptk-block' );
ptk_test_ok( strpos( $html2, 'data-ptk-block="announcement"' ) !== false, 'announcement carries data-ptk-block' );
ptk_test_ok( strpos( $html2, 'data-ptk-block="footer"' ) !== false, 'footer carries data-ptk-block' );
```

- [ ] **Step 2: Run it, watch it fail**

Run: `php pta-knowledge-hub/tests/test-newsletter-renderer.php`
Expected: the three new lines print `FAIL-`, final line `FAILED`, exit 1.

- [ ] **Step 3: Implement**

1. `render_announcement()` (line ~148) and `render_footer()` (line ~347) currently take `( array $data )` only — `render()` dispatches `self::$method( $data, $opts )` and PHP silently drops the extra arg. Change both signatures to `( array $data, array $opts )` (unused for now) so they can honour Task 2's flag.
2. In **each** `render_<type>()`, add `data-ptk-block="<type>"` to that block's **outermost** `<div>`. Use `esc_attr()`. Keep everything else byte-identical.

- [ ] **Step 4: Run to green — and confirm nothing else changed**

Run: `php pta-knowledge-hub/tests/test-newsletter-renderer.php` → `PASSED` (all prior assertions still pass).
Run: `php pta-knowledge-hub/tests/test-newsletter-data.php && php pta-knowledge-hub/tests/test-newsletter-dates.php` → both `PASSED`.

- [ ] **Step 5: Lint + commit**

```bash
php -l pta-knowledge-hub/includes/class-newsletter-renderer.php
git add pta-knowledge-hub/includes/class-newsletter-renderer.php pta-knowledge-hub/tests/test-newsletter-renderer.php
git commit -m "Tag each rendered newsletter block so the live preview can highlight the section being edited"
```
(Append `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>` to every commit in this plan.)

---

## Task 2: Renderer — preview-only placeholder mode

**Why:** every optional block returns `''` when blank (`render_announcement`:154, `render_events`:195, `render_featured`:251, `render_story_cards`:304, `render_footer`:367). On a brand-new newsletter there is then **nothing to outline for the section being edited** — exactly when it matters most.

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-renderer.php`
- Test: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: Write the failing test**

```php
// Preview mode: empty blocks become outlinable placeholder stubs...
$empty = PTK_Newsletter_Data::default_blocks(); // all fields blank
$opts_base = array( 'issue' => 1, 'date' => '2026-07-16', 'today' => '2026-07-16',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Demo PTA' );

$preview = PTK_Newsletter_Renderer::render( $empty, array_merge( $opts_base, array( 'preview_placeholders' => true ) ) );
ptk_test_ok( strpos( $preview, 'data-ptk-block="featured"' ) !== false, 'preview mode: empty featured still outlinable' );
ptk_test_ok( strpos( $preview, 'data-ptk-block="events"' ) !== false, 'preview mode: empty events still outlinable' );
ptk_test_ok( stripos( $preview, 'will appear here' ) !== false, 'preview mode: placeholder tells the user what goes here' );

// ...but published output is UNCHANGED — empty blocks still render nothing.
$published = PTK_Newsletter_Renderer::render( $empty, $opts_base );
ptk_test_ok( strpos( $published, 'data-ptk-block="featured"' ) === false, 'published: empty featured renders nothing' );
ptk_test_ok( stripos( $published, 'will appear here' ) === false, 'published: no placeholder text leaks out' );
```

- [ ] **Step 2: Run it, watch it fail**

Run: `php pta-knowledge-hub/tests/test-newsletter-renderer.php`
Expected: the three preview-mode lines FAIL (the published ones already pass).

- [ ] **Step 3: Implement**

Add a private helper and call it at each of the five `return '';` bailouts:

```php
/**
 * Preview-only stub for a block the user hasn't written yet, so the builder can
 * outline it and show where it will land. NEVER used by the save path — the
 * published newsletter still renders nothing for an empty block.
 */
private static function placeholder( $type, $label, array $opts ) {
    if ( empty( $opts['preview_placeholders'] ) ) {
        return '';
    }
    return '<div data-ptk-block="' . esc_attr( $type ) . '" style="font-family:' . self::FONT_SANS
        . ';background:' . esc_attr( self::PALETTE['surface'] ) . ';color:#9a9482;padding:26px 20px;'
        . 'box-sizing:border-box;border:2px dashed ' . esc_attr( self::PALETTE['hairline'] ) . ';'
        . 'text-align:center;font-size:14px;">' . esc_html( $label ) . '</div>';
}
```

Replace each bailout with a `return self::placeholder( <type>, <label>, $opts );`, labels in plain English:
- announcement → `'Your key announcement will appear here.'`
- events → `'Your upcoming events will appear here.'`
- featured → `'Your featured story will appear here.'`
- story_cards → `'Your story cards will appear here.'`
- footer → `'Your sign-off and links will appear here.'`

(`render_announcement`/`render_footer` now have `$opts` from Task 1.) Follow the design rule: **full dashed border, never a single-side stripe.**

- [ ] **Step 4: Run to green**

Run all three PHP suites + `node pta-knowledge-hub/tests/test-relabel-js.mjs` → all `PASSED`.
**Critical:** the pre-existing "empty blocks render nothing" assertions must pass **unmodified**. If you edited them, you broke the guarantee — revert and fix the code instead.

- [ ] **Step 5: Lint + commit**

```bash
php -l pta-knowledge-hub/includes/class-newsletter-renderer.php
git add -A && git commit -m "Show placeholder stubs for unwritten sections in the builder preview only, so people can see where each part will land"
```

---

## Task 3: Builder — extract a shared `render_opts()` helper (pure refactor)

**Why:** the opts array is built inline inside the private `persist_newsletter()` (:217-227) and depends on private `school_name_from_blocks()` (:290). The preview endpoint must use the **same** opts or the preview drifts from reality — the exact failure §7 rejects a JS renderer to avoid.

**Files:** Modify `pta-knowledge-hub/includes/class-newsletter-builder.php`

- [ ] **Step 1: Extract**

```php
/**
 * The render options for a newsletter — ONE definition, used by both the save
 * path and the live-preview endpoint so the preview can never drift from what
 * actually gets published.
 *
 * @param array $extra Preview-only additions (e.g. preview_placeholders => true).
 */
private static function render_opts( array $blocks, $issue, $date, array $extra = array() ) {
    return array_merge( array(
        'issue'        => $issue,
        'date'         => $date,
        'today'        => current_time( 'Y-m-d' ),
        'theme'        => self::DEFAULT_THEME,
        'logo_url'     => get_site_icon_url() ? get_site_icon_url() : '',
        'school_name'  => self::school_name_from_blocks( $blocks ),
        'image_url_cb' => function ( $id ) { return wp_get_attachment_image_url( $id, 'large' ); },
    ), $extra );
}
```

- [ ] **Step 2: Use it in `persist_newsletter()`**

Replace the inline opts array with `PTK_Newsletter_Renderer::render( $blocks, self::render_opts( $blocks, $issue, $date ) )`. **Behaviour must be identical** — same keys, same values. Read the original array and diff it key-by-key against the helper before deleting it.

- [ ] **Step 3: Verify no behaviour change**

Run: `php -l pta-knowledge-hub/includes/class-newsletter-builder.php`
Run all three PHP suites → `PASSED` (they don't cover this path, so this is a smoke check only).
Read the diff: the ONLY change should be extraction — no key added, removed, or reordered in a way that changes values.

- [ ] **Step 4: Commit**

```bash
git add pta-knowledge-hub/includes/class-newsletter-builder.php
git commit -m "Extract one shared definition of the newsletter render options so the live preview cannot drift from what gets published"
```

---

## Task 4: Builder — the AJAX live-preview endpoint

**Files:** Modify `pta-knowledge-hub/includes/class-newsletter-builder.php`

- [ ] **Step 1: Register the endpoint**

In `init()` add: `add_action( 'wp_ajax_ptk_nl_preview', array( __CLASS__, 'handle_preview_ajax' ) );`
(No `nopriv` — this is admin-only.)

- [ ] **Step 2: Implement the handler**

```php
/**
 * Render the live preview for the builder. Runs the SAME sanitize + render path
 * as a real save, so what the volunteer sees is what families will get.
 */
public static function handle_preview_ajax() {
    check_ajax_referer( 'ptk_nl_preview', 'nonce' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
    }

    $raw    = json_decode( wp_unslash( isset( $_POST['blocks'] ) ? $_POST['blocks'] : '' ), true );
    $blocks = PTK_Newsletter_Data::sanitize_blocks( $raw );

    // Issue + date live OUTSIDE the blocks JSON and the renderer reads them from
    // opts, so they must be posted separately or the masthead would be blank.
    $issue = absint( isset( $_POST['issue'] ) ? $_POST['issue'] : 0 );
    if ( $issue < 1 ) {
        $issue = 1;
    }
    $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        $date = current_time( 'Y-m-d' );
    }

    $html = PTK_Newsletter_Renderer::render(
        $blocks,
        self::render_opts( $blocks, $issue, $date, array( 'preview_placeholders' => true ) )
    );

    wp_send_json_success( array( 'html' => $html ) );
}
```

- [ ] **Step 3: Localize the nonce + ajax url**

In `enqueue_assets()`, extend the existing `wp_localize_script( 'ptk-newsletter-builder', 'ptkNlData', ... )` array with:
```php
'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
'previewNonce' => wp_create_nonce( 'ptk_nl_preview' ),
```

- [ ] **Step 4: Verify**

Run: `php -l pta-knowledge-hub/includes/class-newsletter-builder.php`
Functional (deferred to the Playground pass, Task 10): POST to `admin-ajax.php?action=ptk_nl_preview` returns rendered HTML; a bad nonce fails; a logged-out request fails.

- [ ] **Step 5: Commit**

```bash
git add pta-knowledge-hub/includes/class-newsletter-builder.php
git commit -m "Add a live-preview endpoint that renders the newsletter through the real renderer"
```

---

## Task 5: Builder — wizard chrome, `data-step`, and removing the in-section controls

**Files:** Modify `pta-knowledge-hub/includes/class-newsletter-builder.php`

- [ ] **Step 1: Map each element to a step (by TYPE, not position)**

Add:
```php
/** Which wizard step edits a given block type. Step is a property of the TYPE —
 *  never of position — so a section dragged to the bottom is still edited here. */
protected static function step_for_type( $type ) {
    $map = array(
        PTK_Newsletter_Data::TYPE_HEADER       => 1,
        PTK_Newsletter_Data::TYPE_ANNOUNCEMENT => 2,
        PTK_Newsletter_Data::TYPE_EVENTS       => 2,
        PTK_Newsletter_Data::TYPE_FEATURED     => 3,
        PTK_Newsletter_Data::TYPE_STORY_CARDS  => 3,
        PTK_Newsletter_Data::TYPE_FOOTER       => 4,
    );
    return isset( $map[ $type ] ) ? $map[ $type ] : 2;
}

protected static function steps() {
    return array(
        1 => array( 'title' => 'The basics',        'blurb' => 'Who it&rsquo;s from and how you say hello. Most of this is filled in already.' ),
        2 => array( 'title' => "What's happening",  'blurb' => 'The dates, and the one big thing families shouldn&rsquo;t miss. Skip anything you don&rsquo;t need.' ),
        3 => array( 'title' => 'Stories',           'blurb' => 'The longer bits &mdash; a featured story and any shorter articles. All optional.' ),
        4 => array( 'title' => 'Finish &amp; publish', 'blurb' => 'Put it in order, check the photos, and send it out.' ),
    );
}
```

- [ ] **Step 2: Add `data-step` to every step-owned element**

**Naming — two different things, do not conflate them:**
- **"live preview"** = the new `<iframe>` (Task 8). It is a **column of the wizard** and must be visible on EVERY step. It must **NOT** carry `data-step` — `showStep()` hides every `[data-step]` that isn't current, so tagging it would hide the entire feature on the writing steps.
- **"share-a-preview panel"** = the EXISTING `render_preview_panel()` / `.ptk-nl-preview-panel` (no-login token link, builder line ~577). It belongs on step 4 (spec §3.4).

Then:
- `render_block_section()`: add `data-step="<?php echo (int) self::step_for_type( $type ); ?>"` to the `<section class="ptk-nl-block">`.
- The `.ptk-nl-meta-row` (issue/date, ~line 544): add `data-step="1"`.
- Wrap the PII gate + submit buttons in a `<div class="ptk-nl-finish" data-step="4">`.
- The share-a-preview panel is rendered **outside** `<form id="ptk-nl-form">` (~:577) and contains its **own** `<form>`s, so it **cannot** be nested inside `.ptk-nl-finish` (nested forms are invalid). Leave it where it is and put `data-step="4"` on its own wrapper.
- **Do NOT move `#ptk-nl-blocks` or nest its children.** The sections stay direct children (see constraints).

- [ ] **Step 2b: Edit mode must render ALL SIX sections (or Remove is a permanent trap)**

`render_page()` (~:522-532) currently builds `$blocks` from `sanitize_blocks( saved meta )` — which contains **only the included types**. So after Remove → Save → reopen, an excluded section has **no DOM node at all**: the "Not included" list would be empty and there would be **no way to add it back, ever**. That defeats the entire point of §8a.

Fix: in edit mode, render the full set of six sections —
- Start from the saved blocks (they give the **order** of what's included).
- Take any type from `PTK_Newsletter_Data::default_blocks()` that is **absent** from the saved blocks and **insert it immediately BEFORE the footer block** (i.e. at the end of the movable run), in default order, each rendered with **`data-excluded="1"`** (hidden, empty fields).
- Result: every type always has a DOM node, so "Not included" is populated on reopen and Add back works (returning an empty section — the honest limitation spec §8a already states).

**Do NOT simply append to the array.** `sanitize_blocks()` always emits `[header, …middles…, footer]`, so appending would place excluded sections **after the pinned footer**. Published output would survive (save re-sanitizes and forces footer last), but **Add back would be quietly broken**: the restored section would sit below the footer, and Task 7's move guard ("never cross the pinned footer") would see the footer as its previous sibling — so **Move up could never rescue it**, failing the keyboard-equal path spec §5 calls non-negotiable, for exactly the section this step exists to make recoverable. Task 10's check 8b would still look like it passed.

Keep header first and footer last. Do this for the **rendered sections**; `blocks_for_js()` needs no change (a type absent from saved data simply prefills nothing).

- [ ] **Step 2c: Fix the intro copy**

The line at ~:538 ("Fill in the sections below — header and footer are always included, and you can add, remove, and reorder the sections in between") describes the old one-page form and contradicts the wizard. Replace with something that matches: e.g. *"Four short steps. We've filled in what we can — you write the news."*

- [ ] **Step 3: Remove the in-section reorder controls**

Delete the Move up / Move down / Remove buttons from `render_block_section()` (~lines 660-664). Steps 1–3 must have **no** reorder controls.

- [ ] **Step 4: Render the sidebar + step headers**

In `render_page()`, inside `.wrap`, wrap the form area in a `.ptk-nl-wizard` flex container:
- A `<nav class="ptk-nl-steps" aria-label="Newsletter steps">` listing the 4 steps as `<button type="button" data-goto-step="N">` with the title; the active one gets `aria-current="step"`.
- For each step, a `<div class="ptk-nl-step-head" data-step="N">` with an `<h2>` (the title, and "Step N of 4") and the blurb in plain English. **Demote the per-section headings in `render_block_section()` (currently `<h2>`, builder:656) to `<h3>`** so a step outranks the sections inside it (spec §9: steps are real headings, not styled divs).
- A Back / Next pair (`data-step-nav="prev|next"`), hidden appropriately at the ends.
- The preview panel container: `<div class="ptk-nl-preview"><iframe id="ptk-nl-preview-frame" title="Preview of your newsletter"></iframe></div>`.

Escape everything. Keep copy plain.

- [ ] **Step 5: Verify + commit**

Run: `php -l pta-knowledge-hub/includes/class-newsletter-builder.php`
Read the output logic: confirm `#ptk-nl-blocks`' children are still exactly the six sections, unwrapped.

```bash
git add -A && git commit -m "Lay the newsletter builder out as four steps with a sidebar, and take the reorder buttons out of the writing steps"
```

---

## Task 6: JS — step navigation

**Files:** Modify `pta-knowledge-hub/assets/js/newsletter-builder.js`

- [ ] **Step 1: Implement `showStep(n)`**

- Hide every `[data-step]` whose value !== n; show the matching ones. (Generic: covers the meta row, the six sections, the step heads, and the finish block — regardless of DOM order, which is why the flat list is fine.)
- Update the sidebar: `aria-current="step"` on the active button, a class for styling.
- Update Back/Next visibility (no Back on 1, Next becomes hidden on 4).
- **Focus management:** after switching, focus the new step's `<h2>` (give it `tabindex="-1"`) so screen-reader and keyboard users land in the right place.
- Remember the current step in a module var; default to 1.

- [ ] **Step 2: Wire the triggers**

- Sidebar `[data-goto-step]` clicks → `showStep(n)` (never locked — a weekly editor can jump straight to 4).
- `[data-step-nav]` Back/Next → `showStep(current ± 1)`.
- Call `showStep(1)` on boot, after `prefillFromData()`.

- [ ] **Step 3: Verify**

Run: `node --check pta-knowledge-hub/assets/js/newsletter-builder.js`
Re-read: confirm you did NOT change `serialize()`'s selector or move any section in the DOM.

- [ ] **Step 4: Commit**

```bash
git add pta-knowledge-hub/assets/js/newsletter-builder.js
git commit -m "Show one step at a time in the newsletter builder, with a sidebar you can jump around in"
```

---

## Task 7: Step 4 — the arrange list (order, include/skip, add back)

**Files:** Modify `class-newsletter-builder.php` (markup) + `assets/js/newsletter-builder.js` (behaviour)

- [ ] **Step 1: Render the arrange list (PHP)**

Inside the `.ptk-nl-finish[data-step=4]` block, above the PII gate, render:
- `<h3>Order of your newsletter</h3>` + a plain-English line ("Drag to change the order, or use the arrows.").
- A pinned row for Header ("🔒 Header — always first").
- `<ul class="ptk-nl-arrange" data-arrange>` — **empty**; the JS builds its rows from the live DOM (so it always reflects real order). 
- A pinned row for Footer ("🔒 Footer — always last").
- `<div class="ptk-nl-excluded" data-excluded-list><h4>Not included</h4><ul></ul></div>`.

**Visual order — RESOLVED by placement, no CSS `order` needed.** The footer's *fields* are a child of `#ptk-nl-blocks`, so if the arrange list lived inside `.ptk-nl-finish` the footer fields would appear above it — and CSS `order` couldn't fix it, because `order` only sorts *siblings* and those two live in different parents.

Instead, render the arrange panel as **its own `<div class="ptk-nl-arrange-panel" data-step="4">`, a sibling of `#ptk-nl-blocks`, placed immediately BEFORE it** (right after `.ptk-nl-meta-row`). `.ptk-nl-finish` then holds only the PII gate + submit buttons. On step 4 the visible elements fall in natural DOM order:

> arrange panel → footer fields (inside `#ptk-nl-blocks`) → photo check + buttons (`.ptk-nl-finish`) → share-a-preview panel

which is exactly the wanted reading order, with no CSS trickery. (`.ptk-nl-meta-row` is `data-step="1"`, so it's hidden on step 4 and doesn't interfere.) This does **not** touch the flat-DOM constraint — that governs `#ptk-nl-blocks`'s *children*; the arrange panel is a sibling of the container. **Do not** use `display:contents` on `.ptk-nl-finish` — it risks the jQuery `getDefaultDisplay()` clobbering described in the Task 9 CSS constraint. **Do not** move the footer section out of `#ptk-nl-blocks`.

- [ ] **Step 2: Build the rows from the DOM (JS)**

`renderArrangeList()`: read `$('#ptk-nl-blocks > .ptk-nl-block').not('[data-pinned]')` in DOM order; for each, append a row with: a drag handle, the section's label (reuse the section's existing heading text), **Move up**, **Move down**, and **Remove**. Store the block type on the row (`data-type`). Excluded sections render into the "Not included" list with **Add back**.
Re-run `renderArrangeList()` whenever order/inclusion changes, and when entering step 4.

- [ ] **Step 3: Handlers — target by type, NOT by DOM ancestry**

**First: delete the old `bindMoveAndRemoveBlock()` (js ~:216-259) and stop calling it.** It binds delegated `document` handlers on `.ptk-nl-move-up` / `.ptk-nl-move-down` / `.ptk-nl-remove-block` that resolve via `closest('.ptk-nl-block')`. If the new arrange rows reuse those class names and the old function survives, **both handlers fire**: the user gets **two confirm dialogs**, and the old `.remove()` path destroys the section outright — precisely the data loss §8a exists to prevent. Also give the new rows **distinct class names** (e.g. `.ptk-nl-arr-up` / `.ptk-nl-arr-down` / `.ptk-nl-arr-remove` / `.ptk-nl-arr-addback`) so a stale handler can't match them.

Also delete or repurpose `updateMoveButtonStates()` (~:266-273): once the in-section buttons are gone it matches an empty set. Port its "disable at the boundary" behaviour to the new arrange rows (Move up disabled on the first movable, Move down on the last) — that affordance is worth keeping.

**Delete their BOOT CALLS too — `bindMoveAndRemoveBlock()` at js:51 and `updateMoveButtonStates()` at js:55**, inside the `$(function(){…})` boot block. Deleting a function but leaving its call throws a `ReferenceError` on boot, before `serialize()` at :56 — **the entire builder goes dead, and `node --check` will NOT catch it** (it's valid syntax). If you see a blank/inert form in Playground, look here first.

New handlers resolve the target with
`var $section = $('#ptk-nl-blocks > .ptk-nl-block[data-type="' + type + '"]').first();`
- **Move up/down:** swap `$section` with its previous/next non-pinned sibling (guard against crossing the pinned header/footer), then `renderArrangeList()` + `serialize()`.
- **Drag:** `jquery-ui-sortable` on `[data-arrange]` (add `'jquery-ui-sortable'` to the script's deps in `enqueue_assets()`); on `stop`, reorder the real `$section`s in `#ptk-nl-blocks` to match the row order, then `renderArrangeList()` + `serialize()`.
- **Accessibility:** Move up/down are the keyboard-equal path and must always work — never drag-only.

- [ ] **Step 4: Include/skip + add back (NOT destroy)**

Phase 1's Remove did `$section.remove()`, destroying typed content. Instead:
- **Remove:** `window.confirm()` naming the section ("Remove the Featured story? Anything you typed in it won't be published."), then mark `$section.attr('data-excluded','1')` (do **not** remove from the DOM). Re-render lists + `serialize()`.
- **Add back:** clear `data-excluded` → its text is still there (within the session). Re-render + `serialize()`.
- **Let `showStep()` own visibility — do NOT `.show()`/`.hide()` sections here.** A section is visible **iff `!excluded && data-step === currentStep`**. Remove/Add back only toggle the attribute, then call `renderArrangeList()` + `showStep(current)`. (Otherwise Add back — which happens on step 4 — would `.show()` a `data-step="2"` announcement's fields *on step 4*.)
- **`serialize()` must skip excluded sections** — change its loop to `.not('[data-excluded]')`. This is `serialize()`'s ONLY change; do not touch its `#ptk-nl-blocks > .ptk-nl-block` selector.
- Also skip excluded sections in `showStep()` (a hidden-because-excluded section must not reappear when its step opens).
- Copy must be honest: note in the UI that once saved, an excluded section's text isn't kept.

- [ ] **Step 5: Verify + commit**

Run: `node --check ...` and `php -l ...`.

```bash
git add -A && git commit -m "Add a finish step where you can drag sections into order, remove them without losing what you wrote, and add them back"
```

---

## Task 8: JS — the live preview iframe

**Files:** Modify `pta-knowledge-hub/assets/js/newsletter-builder.js`

- [ ] **Step 1: Debounced refresh**

`refreshPreview()`: POST to `ptkNlData.ajaxUrl` with
`{ action:'ptk_nl_preview', nonce: ptkNlData.previewNonce, blocks: $('#ptk-nl-blocks-json').val(), issue: $('[name="ptk_nl_issue"]').val(), date: $('[name="ptk_nl_date"]').val() }`.
Debounce ~400ms.

**Selector warning:** the element **ids** are `ptk-nl-issue` / `ptk-nl-date` (hyphens); `ptk_nl_issue` / `ptk_nl_date` are the **name** attributes. Use the `[name="…"]` selectors above (or the hyphenated ids) — `#ptk_nl_issue` matches nothing, would post `undefined`, and the endpoint's floors would silently render "issue 1 / today": a plausible-looking preview that lies about the exact two fields step 1 owns.

Call `serialize()` before reading the hidden field so the JSON is current.

- [ ] **Step 2: Trigger it on ALL edits — including issue/date**

`bindSerializeTriggers()` currently binds `'#ptk-nl-blocks [data-field]'` — **scoped inside the blocks container**, so the issue/date inputs fire nothing. Bind the debounced refresh to that selector **and** to `[name="ptk_nl_issue"], [name="ptk_nl_date"]`. Without this, **step 1 — the step whose whole purpose is the issue number and date — would show a preview that never moves.** Also refresh after add/remove row, image pick, reorder, and include/skip.

- [ ] **Step 3: Write into the iframe**

On success, write the returned HTML into `#ptk-nl-preview-frame` via its document (`open()/write()/close()`), with `<body style="margin:0;width:840px">`. The iframe is a real 840px viewport so the design's `clamp(...,Nvw,...)` type behaves exactly as it will for families. Scale it to the panel with a CSS `transform: scale()` + `transform-origin: top left`, recomputed on resize.

- [ ] **Step 4: Be robust**

- **Drop stale responses:** keep a request counter; ignore any reply that isn't the newest.
- **Fail quietly:** on error keep the last good preview and show a small "Preview couldn't update" note — never blank the panel.

- [ ] **Step 5: Highlight the current section**

When the step changes or a field is focused, outline the matching `[data-ptk-block="<type>"]` inside the iframe (inject a small style + a class). Thanks to Task 2's placeholders, an unwritten section still has something to outline.

- [ ] **Step 6: Verify + commit**

Run: `node --check pta-knowledge-hub/assets/js/newsletter-builder.js`

```bash
git add -A && git commit -m "Show the newsletter building itself beside the form as you type"
```

---

## Task 9: CSS — wizard layout + responsive collapse

**Files:** Modify `pta-knowledge-hub/assets/css/newsletter-builder.css`

### ⚠ CSS CONSTRAINT — never hide a `[data-step]` element from a stylesheet

`showStep()` uses jQuery `.toggle(bool)`. jQuery's `.show()` clears the **inline** display and lets the cascade win — **but only if the element isn't hidden by a stylesheet rule.** If CSS hides it, jQuery falls into `getDefaultDisplay()` and stamps inline **`display:block`**, which would clobber e.g. `.ptk-nl-meta-row`'s `display:flex`.

So:
- **DO NOT** write `[data-step] { display: none }` + an `.is-active` reveal.
- **DO NOT** hide any `[data-step]` element in a media query.
- Let the JS own show/hide entirely; CSS only styles.
- `.ptk-nl-wizard` / `.ptk-nl-steps` / `.ptk-nl-preview` carry no `data-step` and are never toggled — making them flex is safe.

**The same trap applies to `.ptk-nl-excluded`** (the "Not included" list), even though it has no `data-step`: `renderArrangeList()` toggles it with jQuery `.toggle()`. If CSS gives it `display:flex`/`grid`, jQuery's `.show()` will stamp inline `display:block` and clobber the layout. **Style its children instead**, or give it a plain (non-flex) outer element. Rule of thumb: **any element the JS toggles must not be given a non-`block` display by CSS.** Grep the JS for `.toggle(` / `.show(` / `.hide(` before styling anything.

### ⚠ What the preview JS already owns — do not fight it

Task 8 landed; these are facts about the live DOM, not suggestions:
- **`.ptk-nl-preview-scale`** is a wrapper **created by JS** between `.ptk-nl-preview` and the iframe, carrying inline `overflow:hidden` + a computed `height`. **JS owns that height — never set `height` on it in CSS.**
- **`#ptk-nl-preview-frame`** gets inline `width`/`height`/`transform`/`transform-origin`/`border`/`display` from JS. **Inline styles win — CSS width/height on the iframe will be ignored.**
- **`.ptk-nl-preview` MUST have a real, nonzero width at boot.** `scalePreview()` early-returns on a zero width, and the preview then renders unscaled at 840px and gets clipped. **Never start the panel in a `display:none` or zero-width container.**
- **Don't `display:none` the panel at a breakpoint** without triggering a refresh on return — a zero width skips scaling and the wrapper keeps a stale height. For the responsive collapse, prefer moving/resizing the column over hiding it, or wire the toggle to re-run the scale.

- [ ] **Step 1: Layout**

`.ptk-nl-wizard` = flex row: `.ptk-nl-steps` sidebar (~170px) | fields column (min ~420px, flex 1) | `.ptk-nl-preview` (flex, the widest that fits). Style the active step (`.is-active` / `[aria-current="step"]`), the pinned/arrange rows, the drag handle, the excluded list, and the placeholder look. Follow the project rule: **full borders/fills, never single-side accent stripes.**

- [ ] **Step 1b: Verify the share-a-preview panel reads as part of the finish column**

**Premise updated (Task 5 already moved it):** Task 5 placed the share-a-preview panel inside `.ptk-nl-fields` — still a sibling of `#ptk-nl-form`, so no nested forms — and gave it `data-step="4"`. So it no longer sits below the whole wizard, and **it does not need "fixing" again**. Just style it so it reads as part of the finish column, and confirm in the Playground pass.

- [ ] **Step 2: Responsive**

Below ~1100px the preview collapses to a "Show preview" toggle (or drops beneath the fields) rather than crushing the form — wp-admin on a laptop must stay usable. Sidebar collapses to a horizontal stepper if needed.

- [ ] **Step 3: Commit**

```bash
git add pta-knowledge-hub/assets/css/newsletter-builder.css
git commit -m "Style the newsletter wizard: sidebar, steps, arrange list, and preview panel"
```

---

## Task 10: Integration pass — drive the real thing

**This is the task that actually finds the bugs.** Phase 1's four worst defects (KSES stripping, `wp_slash` text corruption, permalink 404s, the masthead) were ALL invisible to unit tests and only appeared in a real WordPress.

- [ ] **Step 1: Run everything**

```bash
for t in pta-knowledge-hub/tests/test-*.php; do echo "== $t"; php "$t" || exit 1; done
node pta-knowledge-hub/tests/test-relabel-js.mjs
node --check pta-knowledge-hub/assets/js/newsletter-builder.js
for f in pta-knowledge-hub/includes/class-newsletter-*.php; do php -l "$f"; done
```
All must pass. **The Phase 1 renderer assertions must be untouched and green.**

- [ ] **Step 2: Boot a real WordPress**

```bash
npx --yes @wp-playground/cli@latest server --auto-mount "/Users/lucas/apps/PTA HUB/pta-knowledge-hub" --login --port 9400
```
(Node-only; no Docker. `--login` auto-logs in as admin — note curl will redirect-loop on the auto-login cookie, so drive it in a browser.)

- [ ] **Step 3: Drive it — Newsletters → Add New**

Check, and report anything that fails:
1. Only step 1 is visible; sidebar shows 4 steps; issue/date/school name/headline are pre-filled.
2. The preview iframe renders, showing placeholder stubs for unwritten sections.
3. **Typing in the issue number or date updates the preview** (the §7 trap).
4. Typing a greeting updates the preview; the section being edited is outlined.
5. Sidebar jumps to any step; Back/Next work; focus lands on the step heading.
6. Add an event / a story card; both appear in the preview.
7. Step 4: drag reorders (and the preview follows); **Move up/down do the same via keyboard**; pinned header/footer can't move.
8. Remove a written section → confirm prompt → it leaves the newsletter but **Add back restores the text**.
8b. **The round-trip that matters:** remove a section → **Save draft** → **reopen from the list** → it must still appear under "Not included" with a working **Add back** (returning an empty section). If "Not included" is empty on reopen, Step 2b wasn't done and Remove is a permanent trap.
8c. Only ONE confirmation dialog appears when removing (two means the old `bindMoveAndRemoveBlock()` handlers are still bound — Task 7 Step 3).
9. PII gate still blocks Publish; checking it publishes.
10. Published newsletter still looks right, and `data-event-date` still survives (Phase 1's KSES fix).
11. Reopen for edit: the saved layout, order, and every value come back.
12. Resize the window below ~1100px: the form stays usable.

- [ ] **Step 4: Fix what you find, then commit**

```bash
git add -A && git commit -m "Fix issues found driving the newsletter wizard in a real WordPress"
```

---

## Definition of done

- The builder shows one step at a time with a jump-around sidebar; a first-time volunteer sees a short, plain-English screen instead of seven stacked cards.
- The newsletter builds itself beside the form as they type, outlining the part they're on — including sections they haven't written yet.
- Writing (steps 1–3) and arranging (step 4) are separate; drag AND Move up/down both work; removing a section doesn't silently destroy what they wrote.
- **Published output, the block model, and the save path are unchanged** — Phase 1's tests pass untouched, and a published newsletter is byte-identical to before.
- Verified by driving it in a real WordPress, not by green unit tests alone.
