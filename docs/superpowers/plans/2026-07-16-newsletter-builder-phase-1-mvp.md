# Newsletter Builder — Phase 1 (MVP) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A school admin can build a newsletter from a guided form (default layout pre-filled), save it as a draft, preview the real rendered result, and publish it as a public post on their school's WordPress site — using the default Northeast-style template.

**Architecture:** A dedicated `pta_newsletter` custom post type. Each newsletter's content lives as **structured post meta** (an ordered list of typed blocks + issue/date). A WordPress-free **renderer** turns that structured data into the newsletter HTML, which is written into `post_content` on every save (so WordPress Preview and the public single view "just work," and draft mode is free). A guided-form admin screen — mirroring the existing `class-content-wizard.php` — collects the structured data. Pure logic (sanitizing, default layout, issue numbering, date relabeling, rendering) is unit-tested with tiny standalone PHP scripts; WordPress glue is verified functionally.

**Tech Stack:** PHP (WordPress plugin, no framework), vanilla JS + jQuery + `wp.media` for the form, inline-styled HTML output. No build step. Tests are plain `php` scripts using `assert()` against a small WP-shim bootstrap (no PHPUnit — the plugin has no test harness and we are not adding one).

**Scope note:** This is Phase 1 of the feature described in
`docs/superpowers/specs/2026-07-16-newsletter-builder-design.md`. Deferred to later
plans: the carried-over guard (§7 of spec), theme presets / brand-color override
(§8), Council network push (§10), duplicate-last-issue (§6), drag-and-drop beyond the
standard `wp.media` frame, and the email renderer (§14). Phase 1 renders the single
default template ("Harbor Navy") only.

---

## Block Data Model (reference for all tasks)

A newsletter is stored in post meta:

- `ptk_nl_issue` — integer issue number.
- `ptk_nl_date` — `YYYY-MM-DD` issue date.
- `ptk_nl_theme` — string theme key; Phase 1 always `'harbor-navy'`.
- `ptk_nl_blocks` — JSON-encoded ordered array of blocks. **Header is always
  first, Footer always last** (enforced on sanitize). Middle blocks are movable.

Block shape (`type` + `data`):

```
header       => { school_name, greeting }
announcement => { pill, text }
events       => { rows: [ { date:"YYYY-MM-DD", title, desc } ] }
featured     => { eyebrow, headline, body, image_id }
story_cards  => { cards: [ { heading, body, image_id, link_url, link_text } ] }
footer       => { signoff, links: [ { label, url } ] }
```

Logo comes from the site (Phase 1: the site icon / a filter), not a block field.

---

## Task 1: Register the `pta_newsletter` post type

**Files:**
- Create: `pta-knowledge-hub/includes/class-newsletter-post-type.php`
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php` (require + init)

- [ ] **Step 1: Create the post-type class**

Mirror `class-post-type.php`. `public => true`, `has_archive => 'newsletters'`,
`rewrite slug 'newsletters'`, menu icon `dashicons-email`, menu position `6`.
Supports `title`, `thumbnail`, `revisions`, `author` (NOT `editor` — content is
generated). `show_in_rest => false` (no Gutenberg; we use our own form).

```php
<?php
/**
 * Registers the pta_newsletter custom post type (school website newsletters).
 *
 * Content is authored via the Newsletter Builder form and stored as structured
 * post meta; post_content is regenerated from that meta on every save.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Newsletter_Post_Type {

    const POST_TYPE = 'pta_newsletter';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        $labels = array(
            'name'               => 'Newsletters',
            'singular_name'      => 'Newsletter',
            'menu_name'          => 'Newsletters',
            'add_new'            => 'Add New',
            'add_new_item'       => 'New Newsletter',
            'edit_item'          => 'Edit Newsletter',
            'view_item'          => 'View Newsletter',
            'all_items'          => 'All Newsletters',
            'archives'           => 'Newsletter Archive',
            'not_found'          => 'No newsletters yet.',
            'not_found_in_trash' => 'No newsletters in Trash.',
        );

        register_post_type( self::POST_TYPE, array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => false,
            'menu_position'      => 6,
            'menu_icon'          => 'dashicons-email',
            'supports'           => array( 'title', 'thumbnail', 'revisions', 'author' ),
            'has_archive'        => 'newsletters',
            'rewrite'            => array( 'slug' => 'newsletters' ),
            'capability_type'    => 'post',
        ) );
    }
}
```

- [ ] **Step 2: Wire into the main plugin file**

In `pta-knowledge-hub/pta-knowledge-hub.php`, add next to the other requires
(after the last `require_once`, ~line 81) and inits (after the last `::init();`,
the init block begins ~line 131):

```php
require_once PTK_PLUGIN_DIR . 'includes/class-newsletter-post-type.php';
```
```php
PTK_Newsletter_Post_Type::init();
```

- [ ] **Step 3: Lint**

Run: `php -l pta-knowledge-hub/includes/class-newsletter-post-type.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Functional verify**

In a WordPress dev site with the plugin active: visit **flush permalinks**
(Settings → Permalinks → Save), confirm a **Newsletters** menu appears, and
`/newsletters/` archive loads (empty). Confirm no PHP notices in `debug.log`.

- [ ] **Step 5: Commit**

```bash
git add pta-knowledge-hub/includes/class-newsletter-post-type.php pta-knowledge-hub/pta-knowledge-hub.php
git commit -m "Add Newsletters post type with its own archive"
```

---

## Task 2: Test bootstrap + data model (default layout & sanitizing)

**Files:**
- Create: `pta-knowledge-hub/tests/bootstrap.php`
- Create: `pta-knowledge-hub/includes/class-newsletter-data.php`
- Test: `pta-knowledge-hub/tests/test-newsletter-data.php`

- [ ] **Step 1: Write the WP-shim bootstrap** (so pure classes run without WordPress)

```php
<?php
// Minimal shims so WordPress-free plugin logic can be unit-tested with plain php.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $s ) ) ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $s ) { return strip_tags( (string) $s, '<a><strong><em><br><p>' ); }
}
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return filter_var( (string) $s, FILTER_SANITIZE_URL ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return filter_var( (string) $s, FILTER_SANITIZE_URL ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( intval( $n ) ); } }

function ptk_test_ok( $cond, $label ) {
    if ( $cond ) { echo "  ok  - $label\n"; }
    else { echo "  FAIL- $label\n"; $GLOBALS['ptk_test_failed'] = true; }
}
function ptk_test_done() {
    if ( ! empty( $GLOBALS['ptk_test_failed'] ) ) { echo "FAILED\n"; exit( 1 ); }
    echo "PASSED\n"; exit( 0 );
}
```

- [ ] **Step 2: Write the failing test for `PTK_Newsletter_Data`**

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';

// default_blocks() returns the suggested layout, header first + footer last.
$blocks = PTK_Newsletter_Data::default_blocks();
$types  = array_column( $blocks, 'type' );
ptk_test_ok( $types[0] === 'header', 'default layout starts with header' );
ptk_test_ok( end( $types ) === 'footer', 'default layout ends with footer' );
ptk_test_ok( in_array( 'events', $types, true ), 'default layout includes events' );

// sanitize_blocks() drops unknown types and forces header-first/footer-last.
$raw = array(
    array( 'type' => 'events', 'data' => array( 'rows' => array( array( 'date' => '2026-06-25', 'title' => 'Last Day <script>x</script>', 'desc' => 'Bye' ) ) ) ),
    array( 'type' => 'evil',   'data' => array() ),
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'greeting' => 'Hi' ) ),
);
$clean = PTK_Newsletter_Data::sanitize_blocks( $raw );
$ctypes = array_column( $clean, 'type' );
ptk_test_ok( $ctypes[0] === 'header', 'sanitize forces header first' );
ptk_test_ok( end( $ctypes ) === 'footer', 'sanitize appends footer if missing' );
ptk_test_ok( ! in_array( 'evil', $ctypes, true ), 'sanitize drops unknown block types' );
$eventRow = $clean[ array_search( 'events', $ctypes, true ) ]['data']['rows'][0];
ptk_test_ok( strpos( $eventRow['title'], '<script>' ) === false, 'sanitize strips scripts from titles' );

ptk_test_done();
```

- [ ] **Step 3: Run it and watch it fail**

Run: `php pta-knowledge-hub/tests/test-newsletter-data.php`
Expected: fatal error (class not found).

- [ ] **Step 4: Implement `class-newsletter-data.php`**

Constants for known block types; `default_blocks()` returns the suggested layout
with empty placeholder fields; `sanitize_blocks()` normalizes, drops unknown
types, sanitizes each field per the data model, and guarantees a header block
first and footer block last (injecting empty ones if absent). Include the
`ABSPATH` guard. Use `sanitize_text_field` for short fields, `wp_kses_post` for
`greeting`/`body`/`desc`, `absint` for `image_id`, `esc_url_raw` for URLs, and a
`preg_match('/^\d{4}-\d{2}-\d{2}$/', ...)` check for dates (blank if invalid).

- [ ] **Step 5: Run the test to green**

Run: `php pta-knowledge-hub/tests/test-newsletter-data.php`
Expected: all `ok`, final line `PASSED`.

- [ ] **Step 6: Lint + commit**

```bash
php -l pta-knowledge-hub/includes/class-newsletter-data.php
git add pta-knowledge-hub/tests/bootstrap.php pta-knowledge-hub/includes/class-newsletter-data.php pta-knowledge-hub/tests/test-newsletter-data.php
git commit -m "Add newsletter data model: suggested default layout + input sanitizing (tested)"
```

---

## Task 3: Issue numbering + date relabeling (pure logic)

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-data.php`
- Test: `pta-knowledge-hub/tests/test-newsletter-dates.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';

ptk_test_ok( PTK_Newsletter_Data::compute_next_issue( 38 ) === 39, 'next issue after 38 is 39' );
ptk_test_ok( PTK_Newsletter_Data::compute_next_issue( 0 ) === 1, 'next issue floors at 1' );
ptk_test_ok( PTK_Newsletter_Data::compute_next_issue( 'x' ) === 1, 'non-numeric last issue -> 1' );

// relabel_for_date( eventDate, today ) — week starts Monday.
$today = '2026-06-22'; // a Monday
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-20', $today ) === 'past', 'past date' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-25', $today ) === 'this-week', 'this week' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-06-30', $today ) === 'next-week', 'next week' );
ptk_test_ok( PTK_Newsletter_Data::relabel_for_date( '2026-07-20', $today ) === 'upcoming', 'further out' );

ptk_test_done();
```

- [ ] **Step 2: Run it, watch it fail**

Run: `php pta-knowledge-hub/tests/test-newsletter-dates.php`
Expected: fatal (methods not defined).

- [ ] **Step 3: Implement `compute_next_issue()` and `relabel_for_date()`**

`compute_next_issue($last)` → `max( 1, intval($last) + 1 )`.
`relabel_for_date($event, $today)`: compare as dates; if `$event < $today` →
`'past'`; else find the Monday of `$today`'s week and the end of next week; classify
into `'this-week'`, `'next-week'`, else `'upcoming'`. Use `DateTime`/`strtotime`
only (no WP calls) so it stays unit-testable.

- [ ] **Step 4: Run to green**

Run: `php pta-knowledge-hub/tests/test-newsletter-dates.php`
Expected: `PASSED`.

- [ ] **Step 5: Commit**

```bash
git add pta-knowledge-hub/includes/class-newsletter-data.php pta-knowledge-hub/tests/test-newsletter-dates.php
git commit -m "Add issue-number bump and this-week/next-week date relabeling (tested)"
```

---

## Task 4: The renderer (default "Harbor Navy" template)

**Files:**
- Create: `pta-knowledge-hub/includes/class-newsletter-renderer.php`
- Test: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: Write failing smoke tests**

Assert that rendering a small newsletter produces HTML containing the key content
and the default primary color. (Coarse substring assertions — enough to catch a
broken renderer without being brittle about exact markup.)

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-newsletter-data.php';
require __DIR__ . '/../includes/class-newsletter-renderer.php';

$blocks = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'Northeast PTA', 'greeting' => 'Hi families' ) ),
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thursday', 'text' => 'Last day of school' ) ),
    array( 'type' => 'events', 'data' => array( 'rows' => array(
        array( 'date' => '2026-06-25', 'title' => 'Last Day of School', 'desc' => 'Early dismissal' ),
    ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'See you soon', 'links' => array() ) ),
);
$html = PTK_Newsletter_Renderer::render( $blocks, array(
    'issue' => 39, 'date' => '2026-06-22', 'today' => '2026-06-22',
    'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Northeast PTA',
) );

ptk_test_ok( strpos( $html, 'Northeast PTA' ) !== false, 'renders school name' );
ptk_test_ok( strpos( $html, 'Last Day of School' ) !== false, 'renders event title' );
ptk_test_ok( strpos( $html, '#1a2f5c' ) !== false, 'uses Harbor Navy primary color' );
ptk_test_ok( strpos( $html, 'No.&nbsp;39' ) !== false || strpos( $html, '39' ) !== false, 'renders issue number' );
ptk_test_ok( strpos( $html, '<script' ) === false, 'no raw script tags in output' );

ptk_test_done();
```

- [ ] **Step 2: Run it, watch it fail**

Run: `php pta-knowledge-hub/tests/test-newsletter-renderer.php`
Expected: fatal (class not found).

- [ ] **Step 3: Implement the renderer**

`PTK_Newsletter_Renderer::render( array $blocks, array $opts ) : string` loops the
blocks and dispatches to a private `render_<type>()` per block, concatenating the
HTML inside a max-width container. Each block builder uses **inline styles** (robust
for both WP content and the future email renderer) driven by a palette constant:

```php
const PALETTE = array(
    'primary' => '#1a2f5c', 'accent' => '#ffd166', 'emphasis' => '#a51d23',
    'bg' => '#efece6', 'surface' => '#ffffff', 'text' => '#111111',
    'muted' => '#4a4a4a', 'hairline' => '#e6e3dc',
);
```

Match the source design in `NEPTANewsletter/newsletter-038-week-of-6-22-26.html`:
masthead (issue label + date + greeting), announcement strip (primary background),
events (date + title + desc + a "This week / Next week / Past" pill computed via
`PTK_Newsletter_Data::relabel_for_date( $row['date'], $opts['today'] )`), featured
hero (primary background, eyebrow + headline + body + optional image), story cards
(heading + body + optional image + optional read-more link), footer. Escape every
value (`esc_html`/`esc_attr`/`esc_url`); allow limited inline HTML in body fields
via `wp_kses_post`. Follow the plugin design rule: **no single-side accent borders**
— use full borders/fills only.

Define the event-pill labels as an explicit key→display map so the server text and
the Task 6 client script stay identical:
`'past' => 'Past'`, `'this-week' => 'This week'`, `'next-week' => 'Next week'`,
`'upcoming' => 'Upcoming'`. Emit each event pill with
`data-event-date="YYYY-MM-DD"` and `data-default="<server label>"` so the client
script can re-derive the label from the reader's date.

Include an inline `<script>`-free static container; the reader's-date relabel
enhancement script is added at render time by the plugin (Task 6, enqueued
separately — not embedded in this pure output so tests stay script-free).

- [ ] **Step 4: Run to green**

Run: `php pta-knowledge-hub/tests/test-newsletter-renderer.php`
Expected: `PASSED`.

- [ ] **Step 5: Eyeball the real output** (quick visual sanity check)

Run: `php -r 'define("ABSPATH",1); require "pta-knowledge-hub/includes/class-newsletter-data.php"; require "pta-knowledge-hub/includes/class-newsletter-renderer.php"; file_put_contents("/tmp/nl.html", PTK_Newsletter_Renderer::render(PTK_Newsletter_Data::default_blocks(), array("issue"=>39,"date"=>"2026-06-22","today"=>"2026-06-22","theme"=>"harbor-navy","logo_url"=>"","school_name"=>"Demo PTA")));'`
Open `/tmp/nl.html` in a browser; confirm it resembles the Northeast newsletter.
(This step will surface missing `esc_*`/`wp_kses_post` shims — they are in bootstrap
but not in a bare `php -r`; if it fatals, add the two requires plus
`require "pta-knowledge-hub/tests/bootstrap.php";` to the one-liner.)

- [ ] **Step 6: Lint + commit**

```bash
php -l pta-knowledge-hub/includes/class-newsletter-renderer.php
git add pta-knowledge-hub/includes/class-newsletter-renderer.php pta-knowledge-hub/tests/test-newsletter-renderer.php
git commit -m "Add default-template newsletter renderer (structured data -> HTML, tested)"
```

---

## Task 5: Builder admin page (server-rendered form skeleton)

**Files:**
- Create: `pta-knowledge-hub/includes/class-newsletter-builder.php`
- Create: `pta-knowledge-hub/assets/css/newsletter-builder.css`
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php` (require + init)

- [ ] **Step 1: Create the builder class with a submenu page**

Mirror `class-content-wizard.php`'s page registration. Add a submenu under the
Newsletters menu (`add_submenu_page( 'edit.php?post_type=pta_newsletter', 'New Newsletter', 'Add New', 'edit_posts', 'ptk-newsletter-builder', array( __CLASS__, 'render_page' ) )`).
`init()` registers `admin_menu`, `admin_enqueue_scripts`, and (Task 6)
`admin_init` for the submit handler.

- [ ] **Step 2: Render the form server-side from the default layout**

`render_page()`: capability check (`current_user_can('edit_posts')`), open a
`<form method="post">`, `wp_nonce_field( 'ptk_nl_save', 'ptk_nl_nonce' )`, issue #
and date fields (prefilled from `PTK_Newsletter_Data::compute_next_issue(...)` and
today), then a `<div id="ptk-nl-blocks">` containing one server-rendered section per
block from `PTK_Newsletter_Data::default_blocks()`. Header/footer sections are
marked "pinned"; middle sections show plain-language controls. Submit buttons:
**Save draft**, **Preview**, **Publish** (name=`ptk_nl_status`).
Keep copy in plain English per the spec's guiding principle.

- [ ] **Step 3: Enqueue the CSS** (JS added in Task 7)

Mirror the wizard enqueue; gate on the page hook. For a submenu under a CPT menu,
WordPress builds the hook from the post type, so the expected value is
**`pta_newsletter_page_ptk-newsletter-builder`** (not the archive slug). Confirm by
logging `$hook` once, then hard-code it. Enqueue `assets/css/newsletter-builder.css`
with `PTK_VERSION`.

- [ ] **Step 4: Wire into main plugin file** (require + `PTK_Newsletter_Builder::init();`).

- [ ] **Step 5: Lint + functional verify**

Run: `php -l pta-knowledge-hub/includes/class-newsletter-builder.php`
In WP admin: Newsletters → Add New shows the guided form with the default layout
pre-filled. No fatal/notice in `debug.log`.

- [ ] **Step 6: Commit**

```bash
git add pta-knowledge-hub/includes/class-newsletter-builder.php pta-knowledge-hub/assets/css/newsletter-builder.css pta-knowledge-hub/pta-knowledge-hub.php
git commit -m "Add Newsletter Builder admin page with default layout form (no JS yet)"
```

---

## Task 6: Save handler (create/update, render into post_content, draft/publish)

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php`
- Create: `pta-knowledge-hub/assets/js/newsletter-relabel.js` (reader's-date pills on the public post)
- Modify: `pta-knowledge-hub/includes/class-newsletter-post-type.php` (enqueue relabel script on single view)

- [ ] **Step 1: Implement `handle_submission()`**

On `admin_init`, when our nonce + `ptk_nl_status` are present: verify nonce
(`check_admin_referer`), capability (`current_user_can('edit_posts')`, and
`edit_post` when editing). Read `$_POST['ptk_nl_blocks']` (JSON from the form),
`json_decode` → `PTK_Newsletter_Data::sanitize_blocks()`. Read issue (absint) and
date (validated). Render via `PTK_Newsletter_Renderer::render()` with
`today = current_time('Y-m-d')`, `logo_url` from `get_site_icon_url()` (filterable),
and `school_name` from the header block or `get_bloginfo('name')`.

Build `$post_data` with `post_type => 'pta_newsletter'`, `post_title` (e.g.
`"Newsletter No. {issue} — {formatted date}"`), `post_content => $rendered_html`,
`post_status => ( 'publish' === $status ? 'publish' : 'draft' )`. `wp_insert_post`
or `wp_update_post`. Persist meta: `ptk_nl_issue`, `ptk_nl_date`, `ptk_nl_theme`,
`ptk_nl_blocks` (`wp_json_encode`). Redirect: for **Preview**, save as draft then
redirect to `get_preview_post_link()`; for draft/publish redirect back to the edit
form with a success notice.

- [ ] **Step 1b: Gate Publish behind the photo/PII consent checkbox** (spec §3.11, §13)

In the builder form (Task 5), render a single plain-English required checkbox near
the Publish button: **"These photos are OK to share publicly — no student faces or
personal info."** (name `ptk_nl_pii_ok`). In `handle_submission()`, when
`ptk_nl_status === 'publish'` and the box is not checked, do **not** publish: save as
draft instead and redirect back with a clear notice ("Confirm the photo/privacy
check before publishing."). Draft and Preview never require it. Keep the copy plain
and the connection obvious (the checkbox sits with the Publish action, not buried).

- [ ] **Step 2: Add the reader's-date relabel script for the public post**

`newsletter-relabel.js`: on the published single view, find elements with
`data-event-date` and set the pill text from the reader's *current* date. It must
**reimplement the same Monday-week logic as `PTK_Newsletter_Data::relabel_for_date()`
in JS**, mapping to the same labels ("Past / This week / Next week / Upcoming").
Reference implementation to adapt: the inline `data-event-date` helper script at the
bottom of `NEPTANewsletter/newsletter-038-week-of-6-22-26.html`. The renderer already
emits `data-event-date="YYYY-MM-DD"` and a `data-default` server label; this script
overrides them client-side. Enqueue only on `is_singular('pta_newsletter')`.

- [ ] **Step 3: Functional verify (the core end-to-end)**

In WP admin:
1. Newsletters → Add New → fill issue/date, edit an event → **Save draft**.
2. Confirm a draft `pta_newsletter` exists; open **Preview** → the rendered
   newsletter appears and matches the template.
3. Edit again → **Publish** → the public `/newsletters/...` URL shows it.
4. Verify `ptk_nl_blocks` meta is present and is valid JSON (Tools → or
   `get_post_meta`).
5. Confirm past/again date pills relabel on the public view.

- [ ] **Step 4: Lint + commit**

```bash
php -l pta-knowledge-hub/includes/class-newsletter-builder.php
git add -A
git commit -m "Save newsletters as draft/publish: sanitize, render into post_content, store structured meta"
```

---

## Task 7: Builder JS — repeatable rows, reorder, images, edit prefill

**Files:**
- Create: `pta-knowledge-hub/assets/js/newsletter-builder.js`
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php` (enqueue JS + localize edit data)

- [ ] **Step 1: Enqueue the builder JS and localize data**

Add `wp_enqueue_media();` and enqueue `assets/js/newsletter-builder.js`
(`array('jquery','media-upload')`). `wp_localize_script` a `ptkNlData` object:
`{ blocks: <current blocks JSON>, imageBase: ... }` — for a new newsletter that's
`PTK_Newsletter_Data::default_blocks()`; for edit mode (`?ptk_nl_edit_id=`) it's the
stored `ptk_nl_blocks`.

- [ ] **Step 2: Implement the form interactions (plain-English, low-overload)**

`newsletter-builder.js`:
- Render middle-block sections from `ptkNlData.blocks` (header/footer pinned, not
  movable/removable).
- **+ Add event** / **+ Add story** append a labeled row; each row has a **Remove**.
- **Move up / Move down** buttons reorder middle blocks (simple, clearly labeled —
  no drag needed for MVP; keeps it approachable).
- Image fields use the `wp.media` frame ("Choose / upload image", which itself
  supports drag-and-drop upload); store the selected attachment id in a hidden
  input and show a thumbnail + **Remove image**.
- On submit, serialize the whole block list (in DOM order) into the hidden
  `ptk_nl_blocks` field as JSON.

- [ ] **Step 3: Functional verify**

Add/remove events and story cards; reorder a couple of middle blocks; attach an
image to a story card; Save draft; reopen via edit — confirm everything reloads in
the same order with the image intact.

- [ ] **Step 4: Commit**

```bash
git add pta-knowledge-hub/assets/js/newsletter-builder.js pta-knowledge-hub/includes/class-newsletter-builder.php
git commit -m "Add builder interactions: repeatable rows, move up/down, image picker, edit prefill"
```

---

## Task 8: Edit path + "Add New" redirect + share-a-preview link

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php`
- Modify: `pta-knowledge-hub/includes/class-newsletter-post-type.php`
- Modify: `pta-knowledge-hub/includes/class-public-preview.php`

- [ ] **Step 1: Route editing through the builder**

Add an **Edit** row action on the `pta_newsletter` list table that links to
`ptk-newsletter-builder&ptk_nl_edit_id={id}` (mirror the wizard's
`add_edit_wizard_row_action`). In `render_page()`, when `ptk_nl_edit_id` is set,
load issue/date/blocks from meta and prefill. Redirect the default
`post-new.php?post_type=pta_newsletter` to the builder (mirror the wizard's
`redirect_add_new_to_wizard` + `remove_default_add_new`).

- [ ] **Step 2: Generalize public-preview to cover newsletters** (spec §3.10, §12, §13)

`class-public-preview.php` is currently hard-coupled to `pta_knowledge` in four
places: the query in `maybe_render_preview()` (`'post_type' => 'pta_knowledge'`), and
the three `'pta_knowledge' !== $post->post_type` checks in `render_publish_box()`,
`handle_generate()`, and `handle_revoke()`. Replace these with a filterable supported
list:

```php
public static function supported_post_types() {
    return apply_filters( 'ptk_preview_post_types', array( 'pta_knowledge', 'pta_newsletter' ) );
}
```

Use `in_array( $post->post_type, self::supported_post_types(), true )` for the checks,
and `'post_type' => self::supported_post_types()` in the query. This is a low-risk,
additive change (existing `pta_knowledge` behavior is unchanged). Note: token render
uses the post's normal single template — for `pta_newsletter` that already shows the
rendered `post_content`, so no template work is needed.

- [ ] **Step 3: Surface the preview link in the builder form**

The existing UI hangs off `post_submitbox_misc_actions` (the classic editor publish
box), which our custom form does not use. So in the builder, when editing a saved
draft, render a small **"Share a preview link (no login needed)"** control that
calls the same `admin_action_ptk_generate_preview` / `ptk_revoke_preview` handlers
(reuse `PTK_Public_Preview`'s existing generate/revoke actions and the
`ptk_preview_token`/expiry meta) and shows the copyable URL. Plain-English label; keep
it visually tied to the draft it belongs to.

- [ ] **Step 4: Functional verify**

"Add New" and list-table "Edit" both open the builder (never the block editor);
editing round-trips. On a saved draft, generating a preview link produces a
`?ptk_preview=<token>` URL that renders the newsletter for a logged-out visitor and
auto-revokes on publish.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Route newsletter add/edit through the builder; add no-login share-a-preview links"
```

---

## Task 9: Integration pass + version bump + docs

**Files:**
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php` (version constant + header)
- Modify: `pta-knowledge-hub/uninstall.php` (clean up newsletter meta/posts if the
  plugin's convention is to remove its data — match existing uninstall behavior)

- [ ] **Step 1: Run every unit test**

Run:
```bash
for t in pta-knowledge-hub/tests/test-*.php; do echo "== $t =="; php "$t" || exit 1; done
```
Expected: every file ends `PASSED`.

- [ ] **Step 2: Full manual end-to-end checklist** (single WP site)

Build → Save draft → Preview → Publish → view archive `/newsletters/` → confirm the
published newsletter looks right, past-date pills relabel, images show, and no
`debug.log` notices.

- [ ] **Step 3: Uninstall hygiene**

Check `uninstall.php`; if it deletes plugin post types/meta, extend it to include
`pta_newsletter` posts and `ptk_nl_*` meta, matching the existing pattern. If the
plugin deliberately preserves content, leave newsletters in place and note it.

- [ ] **Step 4: Bump version + commit**

Update `PTK_VERSION` and the plugin header per the project's release convention,
then:
```bash
git add -A
git commit -m "Newsletter Builder MVP: build, draft-preview, and publish a website newsletter from a guided form"
```

---

## Definition of done (Phase 1)

- A non-technical admin can open **Newsletters → Add New**, get a pre-filled
  suggested layout, edit plain-English fields, add/remove/reorder middle blocks,
  attach images, **save a draft, preview the real rendered newsletter** (in-admin,
  or via a **no-login share-a-preview link**), confirm the **photo/PII checkbox**,
  and **publish** it to `/newsletters/` on their school site — all without touching
  HTML or the block editor.
- Content is stored as structured meta and re-rendered on every save.
- All pure-logic units have passing standalone tests.
- No new test framework was added; the codebase's no-framework style is preserved.
