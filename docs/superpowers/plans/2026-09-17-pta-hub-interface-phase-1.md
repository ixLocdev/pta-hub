# PTA Hub Interface Redesign — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the shared look of the PTA Hub admin (tokens, fonts, reusable parts) and the new intention-based home screen, behind one per-site setting that is **off by default** so no school's admin changes on upload.

**Architecture:** One option (`ptk_hub_new_look`, default `0`) gates everything. `PTK_Hub_Look` answers "is it on for this site, and is this a Hub screen?" and enqueues `assets/css/hub.css` only when both are true. `PTK_Hub_UI` renders the shared parts (page header, card, stamp, waiting row, next steps, empty state) so screens cannot drift. `PTK_Welcome` gains a second rendering path — the six intentions, in the new parts — used only when the look is on; its current output is untouched otherwise.

**Strings:** hardcoded English, like the rest of this plugin today — no `__()` wrappers. The plugin has
no translation calls anywhere yet, and the test harness stubs none, so adding them here would fatal the
plain-PHP tests. (The spec's note about keeping the text domain applies when translation is actually
taken on, as its own piece of work.)

**Tech Stack:** WordPress plugin PHP (no build step), vanilla CSS custom properties, plain-PHP tests (`tests/test-*.php`, run with `php`), Literata + Karla (SIL OFL) bundled under `assets/fonts/`.

**Spec:** `docs/superpowers/specs/2026-09-17-pta-hub-interface-design.md` (phase 1 = build-order items 1 and 2).

---

## File structure

| File | Responsibility |
|---|---|
| Create `includes/class-hub-look.php` | The gate: option read/write, "is this a Hub screen", enqueues, body class. Pure decision helpers are static and testable. |
| Create `includes/class-hub-ui.php` | Renders shared parts. No business logic, no queries. |
| Create `assets/css/hub.css` | Tokens + the parts' styles. Scoped under `body.ptk-hub-look`. |
| Create `assets/fonts/Literata-Variable.woff2`, `Karla-Variable.woff2`, `OFL-Literata.txt`, `OFL-Karla.txt` | Bundled faces + licenses (one variable-weight Latin woff2 per family -- Google serves the same file for every weight, so four static files would only duplicate bytes). |
| Modify `pta-knowledge-hub.php` | `require_once` the two new classes; init them. |
| Modify `includes/class-share-settings.php` | The "Use the new PTA Hub look" checkbox + save handling. |
| Modify `includes/class-welcome.php` | New-look rendering path for the home screen; old path untouched. |
| Create `tests/test-hub-look.php` | Gate decisions, screen predicate, contrast table. |
| Create `tests/test-hub-ui.php` | Part markup: stamp rules, escaping, one-stamp-per-render. |

---

## Task 1: The gate (option + screen predicate)

**Files:**
- Create: `pta-knowledge-hub/includes/class-hub-look.php`
- Test: `pta-knowledge-hub/tests/test-hub-look.php`
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php` (require + init)

- [ ] **Step 1: Write the failing test**

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-hub-look.php';

$t = 'PTK_Hub_Look';

// The option name and its default: off, always, until a site opts in.
ptk_test_ok( 'ptk_hub_new_look' === $t::OPTION, 'option is ptk_hub_new_look' );
ptk_test_ok( false === $t::on_for( '' ), 'an unset option means off' );
ptk_test_ok( false === $t::on_for( '0' ), '"0" means off' );
ptk_test_ok( true === $t::on_for( '1' ), '"1" means on' );

// Which admin screens the look applies to. Hook suffixes, not guesses.
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-welcome', '' ), 'a Hub settings page is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-newsletter-builder', '' ), 'the Builder is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'edit.php', 'pta_newsletter' ), 'the newsletter list is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'post.php', 'pta_knowledge' ), 'a Hub entry editor is a Hub screen' );
ptk_test_ok( false === $t::is_hub_screen( 'edit.php', 'post' ), 'the ordinary posts list is not' );
ptk_test_ok( false === $t::is_hub_screen( 'plugins.php', '' ), 'core screens are not' );
ptk_test_ok( false === $t::is_hub_screen( 'toplevel_page_something-else', '' ), "another plugin's page is not" );

ptk_test_done();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd pta-knowledge-hub && php tests/test-hub-look.php`
Expected: FAIL — `class-hub-look.php` does not exist yet.

- [ ] **Step 3: Write the class**

```php
<?php
/**
 * The switch that decides whether a site sees the redesigned Hub at all.
 *
 * Ships OFF. While it is off nothing about the admin changes: the new
 * stylesheet and fonts are never enqueued, no body class is added, and
 * PTK_Welcome renders exactly what it rendered before.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Hub_Look {

    /** Per-site option. '1' = on. Absent or '0' = off. */
    const OPTION = 'ptk_hub_new_look';

    /**
     * Hub pages registered by this plugin, by their page slug. Later phases
     * migrate more screens (vendor approvals, the content wizard, suggestions,
     * analytics) -- each one is added here when its screen is migrated, never
     * before, so a half-styled screen can't appear.
     */
    const PAGES = array(
        'ptk-welcome',
        'ptk-newsletter-builder',
        'ptk-share-settings',
    );

    /** Post types the Hub owns. */
    const POST_TYPES = array( 'pta_knowledge', 'pta_newsletter' );

    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
    }

    /** Pure: is the stored option value "on"? */
    public static function on_for( $stored ) {
        return '1' === (string) $stored;
    }

    /** Is the new look on for this site? */
    public static function on() {
        return self::on_for( get_option( self::OPTION, '0' ) );
    }

    /**
     * Pure: does this admin screen belong to the Hub? $hook is
     * get_current_screen()->id / the admin_enqueue_scripts hook suffix;
     * $post_type is the screen's post type (may be '').
     */
    public static function is_hub_screen( $hook, $post_type ) {
        $hook      = (string) $hook;
        $post_type = (string) $post_type;

        // As built: the post-type rule applies only to WordPress's own list
        // and editor screens. Every submenu page under the PTA Hub menu also
        // reports post_type = pta_knowledge, and the content wizard already
        // uses .ptk-card / .ptk-field, so it would have been half-restyled.
        $core = array( 'edit.php', 'post.php', 'post-new.php', 'edit', 'post', 'edit-' . $post_type, $post_type );
        if ( in_array( $post_type, self::POST_TYPES, true ) && in_array( $hook, $core, true ) ) {
            return true;
        }
        foreach ( self::PAGES as $page ) {
            if ( '' !== $page && false !== strpos( $hook, $page ) ) {
                return true;
            }
        }
        return false;
    }

    /** True only when the look is on AND we are on a Hub screen. */
    public static function active() {
        if ( ! self::on() || ! function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }
        return self::is_hub_screen( $screen->id, isset( $screen->post_type ) ? $screen->post_type : '' );
    }

    public static function enqueue( $hook ) {
        if ( ! self::active() ) {
            return;
        }
        wp_enqueue_style( 'ptk-hub', PTK_PLUGIN_URL . 'assets/css/hub.css', array(), PTK_VERSION );
    }

    public static function body_class( $classes ) {
        return self::active() ? $classes . ' ptk-hub-look' : $classes;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd pta-knowledge-hub && php tests/test-hub-look.php`
Expected: PASS, ends with `PASSED`.

- [ ] **Step 5: Wire it up**

In `pta-knowledge-hub.php`, beside the other `require_once` lines (after `class-focal-point.php`):

```php
require_once PTK_PLUGIN_DIR . 'includes/class-hub-look.php';
```

**Only that one line.** `pta-knowledge-hub.php` runs on every request, so a `require_once` for a file
that does not exist yet fatals every page on all 11 sites, setting on or off. `class-hub-ui.php` is
required in Task 4, when it exists. The CLI tests would not catch it: they never load the plugin file.

and where other classes are initialised:

```php
PTK_Hub_Look::init();
```

- [ ] **Step 6: Run the whole suite**

Run: `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done && for f in tests/*.mjs; do node "$f" >/dev/null || echo FAIL $f; done`
Expected: no output.

- [ ] **Step 7: Commit**

```bash
git add pta-knowledge-hub/includes/class-hub-look.php pta-knowledge-hub/tests/test-hub-look.php pta-knowledge-hub/pta-knowledge-hub.php
git commit -m "The new Hub look, behind a per-site switch that ships off"
```

---

## Task 2: The setting a site turns on

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-settings.php`
- Test: `pta-knowledge-hub/tests/test-hub-look.php` (extend)

- [ ] **Step 1: Write the failing test** (append before `ptk_test_done();`)

```php
// Saving the checkbox: only an explicit tick turns it on.
ptk_test_ok( '1' === $t::sanitize_choice( 'on' ), 'a ticked checkbox stores 1' );
ptk_test_ok( '1' === $t::sanitize_choice( '1' ), '1 stores 1' );
ptk_test_ok( '0' === $t::sanitize_choice( null ), 'an unticked checkbox stores 0' );
ptk_test_ok( '0' === $t::sanitize_choice( 'yes please' ), 'anything else stores 0' );
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd pta-knowledge-hub && php tests/test-hub-look.php`
Expected: FAIL — `sanitize_choice` undefined.

- [ ] **Step 3: Add the helper to `PTK_Hub_Look`**

```php
    /** Pure: what a submitted checkbox should store. */
    public static function sanitize_choice( $submitted ) {
        $submitted = is_scalar( $submitted ) ? (string) $submitted : '';
        return ( 'on' === $submitted || '1' === $submitted ) ? '1' : '0';
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd pta-knowledge-hub && php tests/test-hub-look.php` → PASS.

- [ ] **Step 5: Add the field to Newsletter settings**

In `includes/class-share-settings.php`, in the settings form (after the existing sections) render:

Match the file's existing markup — plain `<h2>` / `<label>` / `<p class="description">` sections, not
Settings-API `form-table` markup (this page is a manual `admin-post.php` handler with a nonce, not the
Settings API):

```php
<h2>The look of these screens</h2>
<label>
    <input type="checkbox" name="ptk_hub_new_look" value="1" <?php checked( PTK_Hub_Look::on() ); ?>>
    Use the new PTA Hub look on this site
</label>
<p class="description">
    A calmer, plainer set of screens, with plain-English questions instead of technical labels.
    Off by default while it is being tested — turning it on changes only what you and other
    volunteers see when you sign in, never what families see on the website.
</p>
```

and in the save handler, beside the other `update_option()` calls:

```php
update_option( PTK_Hub_Look::OPTION, PTK_Hub_Look::sanitize_choice( isset( $_POST['ptk_hub_new_look'] ) ? wp_unslash( $_POST['ptk_hub_new_look'] ) : null ) );
```

- [ ] **Step 6: Verify in Playground**

Start: Browser pane `preview_start` name `pta-hub-main` (http://127.0.0.1:9406 only). Open
`edit.php?post_type=pta_knowledge&page=ptk-share-settings`, tick the box, save, confirm it stays ticked;
untick, save, confirm it stays unticked. With it unticked, confirm no `hub.css` request appears in the
page source.

- [ ] **Step 7: Commit**

```bash
git add pta-knowledge-hub/includes/class-share-settings.php pta-knowledge-hub/includes/class-hub-look.php pta-knowledge-hub/tests/test-hub-look.php
git commit -m "Add the setting that turns the new Hub look on for a site"
```

---

## Task 3: Tokens, fonts and the stylesheet

**Files:**
- Create: `pta-knowledge-hub/assets/css/hub.css`
- Create: `pta-knowledge-hub/assets/fonts/Literata-Variable.woff2`, `Karla-Variable.woff2`, `OFL-Literata.txt`, `OFL-Karla.txt`
- Test: `pta-knowledge-hub/tests/test-hub-look.php` (extend)

- [x] **Step 1: Write the failing contrast test** (append)
> **As built (2026-09-17):** three of the pairs below failed as written -- `#C58A39` is 2.97:1 on white
> (below even the 3:1 border floor), `#4E8A68` is 4.07:1, `#B85C5C` is 4.45:1. Per Step 4 they were
> darkened to the nearest passing value: `--ptk-success #477E5F` (4.75 / 4.50), `--ptk-error #B15858`
> (4.77 / 4.51), `--ptk-warning #BD8437` for the stamp border (3.22 / 3.05) and a new
> `--ptk-warning-ink #96692B` for the stamp text (4.83 / 4.57). The committed test asserts these values,
> plus that `hub.css` declares exactly them. Fonts: one variable woff2 per family (see Step 5).


```php
// Every pair the spec promises is readable, computed, not eyeballed.
$pairs = array(
    array( '#243039', '#F8F9F7', 4.5 ),
    array( '#243039', '#FFFFFF', 4.5 ),
    array( '#68747C', '#F8F9F7', 4.5 ),
    array( '#68747C', '#FFFFFF', 4.5 ),
    array( '#356F8A', '#F8F9F7', 4.5 ),
    array( '#356F8A', '#FFFFFF', 4.5 ),
    array( '#FFFFFF', '#356F8A', 4.5 ),
    array( '#356F8A', '#E7F1F5', 4.5 ),
    array( '#4E8A68', '#FFFFFF', 4.5 ),
    array( '#4E8A68', '#F8F9F7', 4.5 ),
    array( '#B85C5C', '#FFFFFF', 4.5 ),
    array( '#B85C5C', '#F8F9F7', 4.5 ),
    // The warning color is used for a stamp's border and its uppercase text.
    // Borders need 3:1; the text needs 4.5:1, so it is checked at both.
    array( '#C58A39', '#FFFFFF', 3.0 ),
    array( '#C58A39', '#F8F9F7', 3.0 ),
);
$text_pairs = array(
    array( '#C58A39', '#FFFFFF', 4.5 ),
    array( '#C58A39', '#F8F9F7', 4.5 ),
);
foreach ( $pairs as $pair ) {
    list( $fg, $bg, $min ) = $pair;
    $ratio = $t::contrast_ratio( $fg, $bg );
    ptk_test_ok( $ratio >= $min, "$fg on $bg is " . round( $ratio, 2 ) . ":1, needs $min:1" );
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd pta-knowledge-hub && php tests/test-hub-look.php`
Expected: FAIL — `contrast_ratio` undefined.

- [ ] **Step 3: Add `contrast_ratio()` to `PTK_Hub_Look`**

```php
    /** WCAG relative-luminance contrast between two #rrggbb colors. */
    public static function contrast_ratio( $fg, $bg ) {
        $lum = function ( $hex ) {
            $hex = ltrim( (string) $hex, '#' );
            $out = 0.0;
            $channels = array( 0.2126, 0.7152, 0.0722 );
            foreach ( array( 0, 2, 4 ) as $i => $offset ) {
                $c = hexdec( substr( $hex, $offset, 2 ) ) / 255;
                $c = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
                $out += $c * $channels[ $i ];
            }
            return $out;
        };
        $a = $lum( $fg );
        $b = $lum( $bg );
        $light = max( $a, $b );
        $dark  = min( $a, $b );
        return ( $light + 0.05 ) / ( $dark + 0.05 );
    }
```

- [ ] **Step 4: Run it**

Run: `cd pta-knowledge-hub && php tests/test-hub-look.php`
Expected: PASS. **If a pair fails, darken that token in the spec's table and here until it passes** —
the palette is the starting point, not permission to ship unreadable text. Report any change made.

`#C58A39` is likely to fail the 4.5:1 **text** check while passing 3:1 for a border. If it does, use a
darkened warning ink for stamp text (a `--ptk-warning-ink` token) and keep `#C58A39` for the border, so
Lucas's color still reads as the stamp's color. Report the value chosen.

- [ ] **Step 5: Fetch and subset the fonts**

Google Fonts serves each family as a single variable-weight Latin-subset `.woff2` (the 400 and 600/700
URLs are the same file), so ship one file per family: `Literata-Variable.woff2` (39,260 B, wght 400-900)
and `Karla-Variable.woff2` (24,264 B, wght 400-800), fetched from fonts.gstatic.com with a Chrome user
agent, plus each family's OFL text as `OFL-Literata.txt` / `OFL-Karla.txt`. `@font-face` declares the
weight range (`font-weight: 400 600` / `400 700`).

- [ ] **Step 6: Write `assets/css/hub.css`**

Tokens on `body.ptk-hub-look`, `@font-face` for the four faces (`font-display: swap`), then the parts:
`.ptk-page`, `.ptk-page-title` (Literata), `.ptk-card`, `.ptk-card-title`, `.ptk-help`,
`.ptk-stamp` (+ `--success/--warning/--dim/--error` modifiers, `white-space: nowrap`, `rotate(-3deg)`),
`.ptk-waiting`, `.ptk-next-steps`, `.ptk-empty`, `.ptk-btn` / `.ptk-btn-primary`, `.ptk-field`,
and the phone rules from the spec (`@media (max-width: 782px)`). Constraints:

- Every color comes from a token; no raw hex outside `:root`-level token declarations.
- **No `border-left` / `border-right` accent bars anywhere.**
- Focus: `outline: 2px solid var(--ptk-primary); outline-offset: 2px` on every interactive part.
- Nothing outside `body.ptk-hub-look` is styled.

- [ ] **Step 7: Check both bans mechanically**

Run: `grep -nE "border-(left|right):" pta-knowledge-hub/assets/css/hub.css | grep -v "none"`
Expected: no output.

Run: `grep -nE "#[0-9a-fA-F]{3,8}" pta-knowledge-hub/assets/css/hub.css | grep -v -- "--ptk-"`
Expected: no output — every color outside the token declarations comes from `var(--ptk-…)`.

Also assert the fonts shipped, in `tests/test-hub-look.php`:

```php
foreach ( array( 'Literata-Variable', 'Karla-Variable' ) as $face ) {
    $path = __DIR__ . '/../assets/fonts/' . $face . '.woff2';
    ptk_test_ok( is_readable( $path ), "bundled font exists: $face.woff2" );
    ptk_test_ok( filesize( $path ) < 60000, "$face.woff2 is under 60KB (" . filesize( $path ) . ')' );
}
foreach ( array( 'OFL-Literata.txt', 'OFL-Karla.txt' ) as $license ) {
    ptk_test_ok( is_readable( __DIR__ . '/../assets/fonts/' . $license ), "license shipped: $license" );
}
```

- [ ] **Step 8: Commit**

```bash
git add pta-knowledge-hub/assets/css/hub.css pta-knowledge-hub/assets/fonts pta-knowledge-hub/tests/test-hub-look.php pta-knowledge-hub/includes/class-hub-look.php
git commit -m "Hub look: tokens, bundled Literata and Karla, shared parts"
```

---

## Task 4: The shared parts (`PTK_Hub_UI`)

**Files:**
- Create: `pta-knowledge-hub/includes/class-hub-ui.php`
- Test: `pta-knowledge-hub/tests/test-hub-ui.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-hub-ui.php';

$u = 'PTK_Hub_UI';

$stamp = $u::stamp( 'Waiting for you', 'warning' );
ptk_test_ok( false !== strpos( $stamp, 'ptk-stamp' ), 'stamp carries its class' );
ptk_test_ok( false !== strpos( $stamp, 'ptk-stamp--warning' ), 'stamp carries its state modifier' );
ptk_test_ok( false !== strpos( $stamp, 'Waiting for you' ), 'the meaning is in the text, not only the color' );
ptk_test_ok( false === strpos( $stamp, '<script' ), 'no markup smuggled through' );

$escaped = $u::stamp( '<b>Sent</b>', 'success' );
ptk_test_ok( false === strpos( $escaped, '<b>' ), 'stamp text is escaped' );

$bad = $u::stamp( 'Whatever', 'nonsense' );
ptk_test_ok( false === strpos( $bad, 'ptk-stamp--nonsense' ), 'an unknown state falls back, never prints itself' );

$card = $u::card( array( 'title' => 'Tell families what\'s happening', 'meta' => 'Last one went out Sep 14' ) );
ptk_test_ok( false !== strpos( $card, 'ptk-card' ) && false !== strpos( $card, 'Sep 14' ), 'card renders title and meta' );

$steps = $u::next_steps( array( array( 'label' => 'Add another', 'url' => 'https://example.org/a' ) ) );
ptk_test_ok( false !== strpos( $steps, 'Add another' ) && false !== strpos( $steps, 'https://example.org/a' ), 'next steps render' );

ptk_test_done();
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd pta-knowledge-hub && php tests/test-hub-ui.php` → FAIL (class missing).

- [ ] **Step 3: Write `PTK_Hub_UI`**

Static methods returning HTML strings (never echoing): `page_open()`, `page_close()`, `card()`,
`section_fold()`, `field()`, `stamp()`, `waiting_row()`, `primary_button()`, `next_steps()`,
`empty_state()`. Rules:

- Every caller-supplied string goes through `esc_html()` / `esc_url()` / `esc_attr()`.
- `stamp()` accepts only `warning|success|dim|error`; anything else renders unmodified.
- No queries, no options, no side effects — this file is presentation only, which is what makes it testable.

- [ ] **Step 4: Run to verify it passes**

Run: `cd pta-knowledge-hub && php tests/test-hub-ui.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add pta-knowledge-hub/includes/class-hub-ui.php pta-knowledge-hub/tests/test-hub-ui.php
git commit -m "Shared Hub parts so screens can't drift"
```

---

## Task 5: The home screen, as six intentions

**Files:**
- Modify: `pta-knowledge-hub/includes/class-welcome.php`
- Test: `pta-knowledge-hub/tests/test-hub-ui.php` (extend with the intentions list)

- [ ] **Step 1: Write the failing test** (append)

```php
require __DIR__ . '/../includes/class-welcome.php';

// The six intentions: what the volunteer wants, not what the system stores.
$intents = PTK_Welcome::intentions( array( 'edit_posts' => true, 'manage_options' => true ) );
$titles  = array_column( $intents, 'title' );
ptk_test_ok( 6 === count( $intents ), 'six intentions for a full-capability user' );
ptk_test_ok( "Tell families what's happening" === $titles[0], 'the newsletter comes first' );
ptk_test_ok( in_array( "I'm not sure where to start", $titles, true ), 'the unsure route is always offered' );
foreach ( $titles as $title ) {
    ptk_test_ok( ! preg_match( '/\b(Add New|Edit|Publish|Manage|Settings|Post|Entry)\b/', $title ), "no system words in: $title" );
}

// Someone who can't edit sees only what they can actually do.
$limited = PTK_Welcome::intentions( array( 'edit_posts' => false, 'manage_options' => false ) );
ptk_test_ok( count( $limited ) < count( $intents ), 'fewer choices without editing rights' );
```

- [ ] **Step 2: Run to verify it fails** → `PTK_Welcome::intentions` undefined.

- [ ] **Step 3: Add `PTK_Welcome::intentions( array $caps )`**

Pure: takes a capability map, returns the ordered list (`key`, `title`, `meta`, `url`) from the spec's
§4 table. No `current_user_can()` inside — the caller passes the map, which is what makes it testable.

**Every one of the six points at something that already exists in phase 1** — no card leads to an
unbuilt screen:

| Intention | Phase-1 destination |
|---|---|
| Tell families what's happening | `PTK_Newsletter_Builder::url()` |
| Answer a question families keep asking | `PTK_Content_Wizard::url()` |
| Recommend someone we've used | the vendor admin screen this plugin already registers |
| Explain a PTA word | the glossary screen it already registers |
| Fix something that's wrong | `edit.php?post_type=pta_knowledge` (what you've written, with WordPress's own search) — the purpose-built find-and-change screen is a later phase |
| I'm not sure where to start | no new screen: the card expands in place to the other five, each with a plain-language cue ("We have a PTA meeting next Thursday" → Tell families what's happening) |

Capability rules: the first two need `edit_posts`; vendors and glossary need whatever their own screens
need; "Fix something that's wrong" needs `edit_posts`; "I'm not sure where to start" shows whenever at
least two others do.

- [ ] **Step 4: Run to verify it passes.**

- [ ] **Step 5: Render it, only when the look is on**

In `PTK_Welcome::render_page()` (or equivalent), branch at the top:

```php
if ( class_exists( 'PTK_Hub_Look' ) && PTK_Hub_Look::on() ) {
    self::render_new_home();
    return;
}
```

`render_new_home()` uses `PTK_Hub_UI` parts: page title "What would you like to do?", the reassurance
line "Nothing goes out to families until you say so.", the intentions as cards (the first one carrying
`--soft`), `waiting_row()` with a single `stamp( 'Waiting for you', 'warning' )` **only when there is
something waiting**, then the two quiet links: "Set up the basics (once)" pointing at
`PTK_Share_Settings`'s page (the existing settings screen), and "Show all of WordPress", which in
phase 1 links to `admin.php`/the dashboard and becomes the real Simple-mode switch in phase 2. Neither
link is rendered if the person lacks the capability for its destination.

- [ ] **Step 6: Verify both paths in Playground**

With the setting **off**: the Start Here screen is byte-for-byte the old one (compare before/after
HTML). With it **on**: the six intentions render, the stamp appears only when something is waiting,
tab order runs top to bottom, focus rings are visible, and at 375px width the cards are one column.
Delete any `zz-*` files afterwards.

- [ ] **Step 7: Run the whole suite, then commit**

```bash
cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done && for f in tests/*.mjs; do node "$f" >/dev/null || echo FAIL $f; done
git add pta-knowledge-hub/includes/class-welcome.php pta-knowledge-hub/tests/test-hub-ui.php
git commit -m "Start Here becomes six things a volunteer might want to do"
```

---

## Task 6: Release 4.12.0

- [ ] **Step 1:** Bump `Version:` and `PTK_VERSION` to `4.12.0` in `pta-knowledge-hub/pta-knowledge-hub.php`.
- [ ] **Step 2:** Add an `update-info.json` changelog entry in plain English, stating that nothing changes until a site ticks "Use the new PTA Hub look".
- [ ] **Step 3:** Rebuild the zip from the repo root:

```bash
rm -f pta-knowledge-hub.zip && zip -rq pta-knowledge-hub.zip pta-knowledge-hub -x "pta-knowledge-hub/tests/*" -x "*/.DS_Store" -x "*/.*" -x "*/zz-*"
unzip -l pta-knowledge-hub.zip | grep -cE "docs/|zz-|/tests/"   # expect 0
```

- [ ] **Step 4:** Commit and report: what shipped, what was verified in a browser, and anything only checkable live.

---

## Out of scope for phase 1 (later phases)

Simple mode (menu trimming, admin-bar, login landing, the per-person switch), the Builder's foldable
sections and question-style labels, Newsletter settings and the entry wizard, "Fix something that's
wrong", and the "I'm not sure where to start" router beyond a stub that links to the newsletter.
