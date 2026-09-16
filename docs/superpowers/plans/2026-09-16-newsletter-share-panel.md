# Newsletter Share Panel — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a PTA publishes a newsletter, generate ready-to-paste Facebook, Instagram and WhatsApp text from its blocks, plus a square image and a QR handoff to the volunteer's phone — with nothing posted automatically.

**Architecture:** Six new classes with one job each. A pure-PHP text generator (unit-testable with no WordPress), a data/meta layer holding the dirty flag and two hashes, a GD image generator, an admin panel rendered on the Builder's step 4, a public read-only share page, and a share-only colour accessor. Nothing hooks `save_post`; captions regenerate lazily at render.

**Tech Stack:** PHP 7.4+ WordPress plugin, no build pipeline. GD + FreeType for the square, bundled `phpqrcode` for the QR. Plain-php unit tests (no PHPUnit — this repo has no harness and we are not adding one). WordPress Playground for end-to-end verification.

**Spec:** `docs/superpowers/specs/2026-09-15-newsletter-share-panel-design.md` (rev 4). Read it first. Where this plan and the spec disagree, the spec wins — tell the user rather than guessing.

---

## Before you start

**Test command for every unit test in this plan:**

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/pta-knowledge-hub" && php tests/test-share-text.php
```

Tests are plain PHP scripts using `ptk_test_ok()` / `ptk_test_done()` from `tests/bootstrap.php`. They print `ok -` / `FAIL-` lines and exit 1 on any failure. Study `tests/test-newsletter-data.php` before writing the first one — match its style exactly.

**Playground (end-to-end, Task 12):**

```bash
npx --yes @wp-playground/cli@latest server --auto-mount "/Users/lucas/apps/PTA/PTA HUB/pta-knowledge-hub" --login --port 9400
```

Open **`http://127.0.0.1:9400`**, never `localhost:9400`. The site URL is `127.0.0.1`, so loading via `localhost` makes every admin-ajax call cross-origin; the browser blocks it and the live preview silently dies.

## File structure

| File | Responsibility |
|---|---|
| `includes/class-share-text.php` (new) | Pure PHP. HTML→text, and blocks+opts → three captions. No WordPress. |
| `includes/class-share-data.php` (new) | Meta keys, the two hashes, the dirty rule, resolving a caption for display. |
| `includes/class-share-color.php` (new) | `share_color()` resolution + contrast guard + the subsite picker page. |
| `includes/class-share-image.php` (new) | The square PNG: capability detection, drawing, media-library write, lifecycle. |
| `includes/class-share-panel.php` (new) | Admin panel on Builder step 4 + the AJAX save endpoint. |
| `includes/class-share-page.php` (new) | The public `?ptk_share=<id>` phone page. Read-only. |
| `assets/js/share-panel.js` (new) | Dirty marking, copy buttons, AJAX save. |
| `assets/css/share-panel.css` (new) | Panel styling. |
| `assets/fonts/` (new) | Libre Franklin + Newsreader, Latin subset, only the weights drawn. |
| `includes/class-qr-codes.php` | Modify: expose a public PNG-data-URL helper. |
| `includes/class-newsletter-builder.php` | Modify: render the panel on step 4, localize `startStep` + share nonce. |
| `assets/js/newsletter-builder.js` | Modify: honour `startStep` at boot. |
| `pta-knowledge-hub.php` | Modify: require the new classes, bump `PTK_VERSION`. |

---

### Task 1: HTML-to-text, and the test shim it needs

Every newsletter body field is HTML (`wp_kses_post`), so captions must be flattened before anything else works.

**Files:**
- Create: `pta-knowledge-hub/includes/class-share-text.php`
- Create: `pta-knowledge-hub/tests/test-share-text.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-share-text.php';

set_error_handler( function ( $errno, $errstr ) {
    echo "  FAIL- PHP error: $errstr\n";
    $GLOBALS['ptk_test_failed'] = true;
    return true;
} );

$t = 'PTK_Share_Text';

ptk_test_ok( $t::html_to_text( '<p>One</p><p>Two</p>' ) === "One\nTwo", 'paragraphs become newlines' );
ptk_test_ok( $t::html_to_text( 'A<br>B' ) === "A\nB", 'br becomes a newline' );
ptk_test_ok( $t::html_to_text( '<strong>Bold</strong> text' ) === 'Bold text', 'inline tags are dropped' );
ptk_test_ok( $t::html_to_text( 'Join <a href="https://x.test/j">here</a>' ) === 'Join here (https://x.test/j)', 'links become text plus a bare URL' );
ptk_test_ok( $t::html_to_text( 'Mum&nbsp;Sale &amp; more' ) === 'Mum Sale & more', 'entities are decoded, nbsp becomes a space' );
ptk_test_ok( $t::html_to_text( "<p>A</p>\n\n\n<p>B</p>" ) === "A\nB", 'runs of blank lines collapse' );
ptk_test_ok( $t::html_to_text( '' ) === '', 'empty input stays empty' );
ptk_test_ok( $t::html_to_text( array( 'x' ) ) === '', 'array input becomes empty, no warning' );

ptk_test_done();
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php tests/test-share-text.php`
Expected: fatal — `Failed opening required .../class-share-text.php`.

- [ ] **Step 3: Write the minimal implementation**

**Do not reach for `wp_specialchars_decode()` here.** Core's version reverses only
`&amp;amp; &amp;lt; &amp;gt; &amp;quot; &amp;#039;` — it does **not** decode `&amp;nbsp;`, `&amp;mdash;` or `&amp;hellip;`.
A test shim built on `html_entity_decode` would pass in CLI while production
captions carried the literal text `Mum&amp;nbsp;Sale`. Call `html_entity_decode()`
directly: it is plain PHP, needs no shim, and serves the WordPress-free contract
better.

```php
<?php
/**
 * Turns newsletter block data into plain-text social captions.
 *
 * WordPress-free beyond the sanitizing shims in tests/bootstrap.php, so it can
 * be unit-tested with plain php — same contract as PTK_Newsletter_Data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Share_Text {

    /**
     * Flatten a wp_kses_post() field to plain text fit for a social post.
     */
    public static function html_to_text( $html ) {
        if ( ! is_scalar( $html ) ) {
            return '';
        }
        $s = (string) $html;

        // Keep a link's destination — captions have no markup to carry it.
        $s = preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            function ( $m ) {
                $label = trim( strip_tags( $m[2] ) );
                $url   = trim( $m[1] );
                if ( '' === $label ) { return $url; }
                return $label . ' (' . $url . ')';
            },
            $s
        );

        $s = preg_replace( '#<br\s*/?>#i', "\n", $s );
        $s = preg_replace( '#</p\s*>#i', "\n", $s );
        $s = wp_strip_all_tags( $s );
        $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $s = str_replace( "\xc2\xa0", ' ', $s );          // nbsp, now a real char
        $s = preg_replace( '/[ \t]+/', ' ', $s );
        $s = preg_replace( '/\n{2,}/', "\n", $s );

        return trim( $s );
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `php tests/test-share-text.php`
Expected: eight `ok -` lines, then `PASSED`.

- [ ] **Step 5: Commit**

```bash
git add pta-knowledge-hub/includes/class-share-text.php pta-knowledge-hub/tests/test-share-text.php
git commit -m "Flatten newsletter HTML into text a social post can carry"
```

---

### Task 2: The story shortening rule

The single highest-leverage detail in the feature. Isolated into its own method so it can be tuned without touching caption assembly.

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-text.php`
- Modify: `pta-knowledge-hub/tests/test-share-text.php`

- [ ] **Step 1: Write the failing test** (append before `ptk_test_done()`)

```php
// Northeast's headings are already whole sentences carrying the fact.
$card = array( 'heading' => 'Film on the Field moves to Friday, October 16.', 'body' => '<p>Bring a blanket. Rain date October 23.</p>' );
ptk_test_ok( $t::story_line( $card ) === 'Film on the Field moves to Friday, October 16.', 'a sentence heading is the whole line' );

// A label-style heading is too thin on its own, so the body completes it.
$label = array( 'heading' => 'Mum Sale', 'body' => '<p>Open through September 25. Pickup at the car wash.</p>' );
ptk_test_ok( $label_line = $t::story_line( $label ), 'label heading returns something' );
ptk_test_ok( strpos( $label_line, 'Mum Sale' ) === 0, 'label heading still leads' );
ptk_test_ok( strpos( $label_line, 'Open through September 25.' ) !== false, 'label heading gains the first sentence' );

ptk_test_ok( $t::story_line( array( 'heading' => '', 'body' => '<p>First one. Second one.</p>' ) ) === 'First one.', 'no heading falls back to the first sentence' );
ptk_test_ok( $t::story_line( array( 'heading' => '', 'body' => '' ) ) === '', 'an empty card yields nothing' );

$long = array( 'heading' => '', 'body' => '<p>' . str_repeat( 'word ', 60 ) . '</p>' );
$cut  = $t::story_line( $long );
// Count CHARACTERS, not bytes: '…' is three bytes in UTF-8, so strlen() would
// read 122 here and the assertion would fail against correct code.
ptk_test_ok( mb_strlen( $cut ) <= 121, 'long text is truncated near 120 chars' );
// Likewise substr( $cut, -1 ) returns a single byte ("\xa6") and can never
// equal '…' — this assertion has to be multibyte-aware too.
ptk_test_ok( mb_substr( $cut, -1 ) === '…', 'truncation is marked with an ellipsis' );
ptk_test_ok( strpos( $cut, 'wor…' ) === false, 'truncation lands on a word boundary' );

// A cut landing mid-character must not emit broken UTF-8 into a caption.
$dashes = array( 'heading' => '', 'body' => '<p>' . str_repeat( 'a—b ', 40 ) . '</p>' );
$dcut   = $t::story_line( $dashes );
ptk_test_ok( mb_check_encoding( $dcut, 'UTF-8' ), 'truncation never splits a multibyte character' );
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php tests/test-share-text.php`
Expected: `FAIL- PHP error: ... undefined method ... story_line`.

- [ ] **Step 3: Implement**

```php
    const LINE_MAX = 120;

    /**
     * One "also in this issue" line for a story card.
     *
     * In our own newsletters the heading is already a whole sentence carrying
     * the fact ("Film on the Field moves to Friday, October 16.") and the
     * Facebook line is that heading barely touched — so the heading IS the
     * line. A PTA writing "Mum Sale" as a label gets the first sentence of the
     * body appended, since a label alone tells a reader nothing.
     */
    public static function story_line( $card ) {
        $heading = self::html_to_text( isset( $card['heading'] ) ? $card['heading'] : '' );
        $body    = self::html_to_text( isset( $card['body'] ) ? $card['body'] : '' );

        if ( '' === $heading ) {
            return self::truncate( self::first_sentence( $body ) );
        }

        if ( self::reads_as_a_sentence( $heading ) ) {
            return self::truncate( $heading );
        }

        $first = self::first_sentence( $body );
        $line  = ( '' === $first ) ? $heading : $heading . ' — ' . $first;

        return self::truncate( $line );
    }

    /** Long enough to be a statement, and punctuated like one. */
    private static function reads_as_a_sentence( $s ) {
        return strlen( $s ) >= 30 && preg_match( '/[.!?]$/', $s );
    }

    private static function first_sentence( $s ) {
        if ( '' === $s ) { return ''; }
        $parts = preg_split( '/(?<=[.!?])\s+/', $s, 2 );
        return trim( $parts[0] );
    }

    /**
     * Multibyte throughout. Our newsletters are full of en and em dashes, so a
     * byte-based substr() would split a character and emit invalid UTF-8 into a
     * caption, and a byte-based rtrim( $cut, "—" ) would strip the em dash's
     * three bytes individually and could eat the front of another character.
     */
    private static function truncate( $s ) {
        if ( mb_strlen( $s ) <= self::LINE_MAX ) { return $s; }
        $cut = mb_substr( $s, 0, self::LINE_MAX );
        $sp  = mb_strrpos( $cut, ' ' );
        if ( false !== $sp ) { $cut = mb_substr( $cut, 0, $sp ); }
        return preg_replace( '/[\s,;:—–-]+$/u', '', $cut ) . '…';
    }
```

- [ ] **Step 4: Run it and watch it pass**

Run: `php tests/test-share-text.php` → `PASSED`.

- [ ] **Step 5: Sanity-check against a real newsletter**

Not a unit test — a judgement call the plan wants made deliberately. Open `NEPTANewsletter/fb-post-newsletter-040.md` and `NEPTANewsletter/newsletter-040-week-of-9-14-26.html` side by side. Do the generated lines read like the hand-written ones? If not, tune `LINE_MAX` and `reads_as_a_sentence()` — **not** the caption assembly in Task 3. Report what you changed and why.

- [ ] **Step 6: Commit**

```bash
git add pta-knowledge-hub/includes/class-share-text.php pta-knowledge-hub/tests/test-share-text.php
git commit -m "Turn a story card into the one line a Facebook post wants"
```

---

### Task 3: The three captions

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-text.php`
- Modify: `pta-knowledge-hub/tests/test-share-text.php`

`generate( array $blocks, array $opts )`. `$opts` carries `url`, `issue`, `date`, `school_name` and **`today`**. **Do not try to reuse `PTK_Newsletter_Builder::render_opts()`** — it is `private` and supplies no `url`.

**`today` is not optional.** The blocks contain every event row, including ones
already past. "Upcoming events" needs a clock, and the renderer already solves
this the same way (`class-newsletter-renderer.php:178`, supplied by `render_opts()`
at builder `:328`). Without it the generator either lists stale events or invents
its own now — and then the tests are not reproducible.

Facebook order, per the spec: featured block as the lead, then the announcement, then one `story_line()` per card, then events, then the URL, then footer links (untyped `{label,url}` pairs — list them all with their labels).

- [ ] **Step 1: Write the failing test** for `generate()`, asserting: the featured headline leads the Facebook text; each story card contributes exactly one line; the URL appears once; **no emoji appears in any channel** (`preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $fb) === 0`); the Instagram caption contains no bare `http` URL but does say "link in bio"; the WhatsApp text is under 400 characters and contains the URL; and a newsletter with **no** featured block, **no** cards and **no** events still produces non-empty Facebook text without a PHP warning.
- [ ] **Step 2: Run it and watch it fail.**
- [ ] **Step 3: Implement `generate()`**, plus private `facebook()`, `instagram()`, `whatsapp()` helpers. Return `array( 'facebook' => ..., 'instagram' => ..., 'whatsapp' => ... )`.
- [ ] **Step 4: Run it and watch it pass.**
- [ ] **Step 5: Tune against one real issue.** Structural assertions prove the
  shape, not the quality, and this is the output the whole feature is judged on.
  Build a fixture from `NEPTANewsletter/newsletter-040-week-of-9-14-26.html` —
  blocks plus a fixed `today` — and put the generated Facebook text beside
  `NEPTANewsletter/fb-post-newsletter-040.md`. They will not match word for word
  and should not; the hand-written one has judgement in it. What matters is
  whether a volunteer would post the generated one unedited. Tune until they
  would, and say what you changed.
- [ ] **Step 6: Commit** — `git commit -m "Write the three posts from what the newsletter already says"`

---

### Task 4: Storage, the dirty rule and the two hashes

**Files:**
- Create: `pta-knowledge-hub/includes/class-share-data.php`
- Create: `pta-knowledge-hub/tests/test-share-data.php`

Meta keys (from the spec, use exactly these):
`_ptk_share_caption_{channel}`, `_ptk_share_caption_hash_{channel}`, `_ptk_share_square_id`, `_ptk_share_square_custom`, `_ptk_share_square_hash`.

**The two hashes are different and must stay different:**

```php
// Captions depend on the words. The square does not.
caption_inputs_hash( $blocks, $url, $issue, $date, $school_name )
    => md5( json_encode( $blocks ) . '|' . $url . '|' . $issue . '|' . $date . '|' . $school_name )

square_inputs_hash( $issue, $date, $school_name, $share_color, $version )
    => md5( $issue . '|' . $date . '|' . $school_name . '|' . $share_color . '|' . $version )
```

**Plain `json_encode`, and the version passed in as an argument.** `tests/bootstrap.php`
defines neither `wp_json_encode()` nor `PTK_VERSION`, so reaching for either
fatals on the test's first run — for a reason that costs time to diagnose. The
caller supplies `PTK_VERSION`.

Reusing one for both is the mistake this plan exists to prevent: the square's hash ignores the blocks on purpose, so a shared hash would never raise the stale-caption warning when someone edits a story — the common case.

- [ ] **Step 1: Write the failing test** for the pure functions — the two hash builders (different inputs produce different hashes; changing only a block changes the caption hash but **not** the square hash), and `is_stale( $stored_hash, $current_hash )`.
- [ ] **Step 2: Run it and watch it fail.**
- [ ] **Step 3: Implement.** Keep the hash builders and `is_stale()` WordPress-free so they are testable; put `get_post_meta`/`update_post_meta` access in separate thin methods.
- [ ] **Step 4: Run it and watch it pass.**
- [ ] **Step 5: Add `resolve_caption( $post_id, $channel, $blocks, $opts )`** implementing the dirty rule: **meta present → return it** (and flag stale if its stored hash no longer matches); **meta absent → generate fresh, do not write**. Generation is stateless and may run anywhere; only the square writes.
- [ ] **Step 6: Commit** — `git commit -m "Remember an edited caption, and notice when the newsletter moved on"`

---

### Task 5: Share colour and the contrast guard

**Files:**
- Create: `pta-knowledge-hub/includes/class-share-color.php`
- Create: `pta-knowledge-hub/tests/test-share-color.php`

**Do not touch `PTK_Site_Colors::color_for()`.** It feeds the Owner-column dots on every site (`class-site-colors.php:294,304`); layering a per-site override into it would make one school show different colours depending on which site you viewed from.

Resolution order: blog option `ptk_share_color` → the Council's `ptk_site_colors` value → palette default.

**The guard applies to resolved colours, not just the picker.** Several palette defaults already fail: `#d97706` is 3.19:1 against white, `#16a34a` 3.30:1, `#ea580c` 3.56:1, `#0891b2` 3.68:1, `#0d9488` 3.74:1 (AA wants 4.5:1); `#475569` is 1.73:1 against navy `#1a2f5c`, `#4338ca` 1.66:1, `#7c3aed` 2.30:1.

- [ ] **Step 1: Write the failing test:** `contrast_ratio('#ffffff','#1a2f5c')` ≈ 12.6 (assert within 0.1); `contrast_ratio('#d97706','#ffffff')` ≈ 3.19; `readable_pair()` given `#d97706` returns a darkened colour reaching ≥ 4.5:1 against white; a colour already passing is returned unchanged; `#zzz` and `''` fall back to navy rather than erroring.
- [ ] **Step 2: Run it and watch it fail.**
- [ ] **Step 3: Implement** `contrast_ratio()` (WCAG relative luminance), `readable_pair()` (darken in steps until it passes, cap the iterations), `share_color( $blog_id )`.
- [ ] **Step 4: Run it and watch it pass.**
- [ ] **Step 5: Commit** — `git commit -m "Give each school its own colour without making it unreadable"`

---

### Task 6: The square image

**Files:**
- Create: `pta-knowledge-hub/includes/class-share-image.php`
- Create: `pta-knowledge-hub/tests/test-share-image.php`
- Create: `pta-knowledge-hub/assets/fonts/` (Libre Franklin + Newsreader, **Latin subset, only the weights actually drawn** — two full families would add ~1 MB to a 218 KB plugin, which today bundles no fonts at all)

**Two separate capability checks, not one:**

```php
function_exists( 'imagecreatetruecolor' )   // GD — the QR needs this too
function_exists( 'imagettftext' )           // FreeType — only the square needs this
```

Missing FreeType → the Instagram section falls back to "upload a square picture" and stays usable. Missing GD → the QR handoff is dead too, and the panel must say so. Neither may fatal; neither may emit a blank image.

- [ ] **Step 1: Write the failing test:** `capabilities()` returns both booleans; `render_png( $args )` returns a binary string whose first bytes are the PNG signature `\x89PNG`; the image is 1080×1080; and `render_png( $args, array( 'gd' => true, 'freetype' => false ) )` returns `false` rather than throwing.

**Signature: `render_png( $args, $caps = null )`.** Plain PHP cannot stub
`function_exists('imagettftext')`, so the capabilities have to be injectable or
the degradation path is untestable — `null` means detect for real. Both GD and
FreeType are present in this machine's CLI, so the happy path runs locally.
- [ ] **Step 2: Run it and watch it fail.**
- [ ] **Step 3: Implement `render_png()`** — navy `#1a2f5c` ground, the school's `share_color()` as the accent (already run through `readable_pair()`), issue number, week, school name. Drawing only; **no** media-library access in this method.
- [ ] **Step 4: Run it and watch it pass.**
- [ ] **Step 5: Eyeball it.** Write the PNG to the scratchpad and open it. A test proves it is a PNG, not that it looks like anything. Check a long school name, a short one, issue `1` and issue `140`.
- [ ] **Step 6: Commit** — `git commit -m "Draw the square Instagram wants, in the school's colour"`

---

### Task 7: The square's life in the media library

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-image.php`

This is the plugin's **first writer** to the media library — existing code only reads (`class-newsletter-builder.php:334`).

- [ ] **Step 1: `ensure_square( $post_id )`** — compare `square_inputs_hash`; if it matches and the attachment still exists, return it. Otherwise render, `wp_insert_attachment`, `require_once ABSPATH . 'wp-admin/includes/image.php'`, `wp_generate_attachment_metadata`, store the new hash. Set `post_parent` for the "Uploaded to" column. **Never run this on the public share page** — regeneration is admin-render only, or an unauthenticated GET triggers GD work and a media write.
- [ ] **Step 2: Respect per-site upload quotas.** On a multisite with a quota, check before writing and degrade to "upload a square picture" rather than failing hard.
- [ ] **Step 3: Cleanup.** Hook **`before_delete_post` only** — not `trashed_post`; trash is restorable and deleting the square on trash forces a regeneration on restore. The handler **must** check `get_post_type( $post_id ) === 'pta_newsletter'` first, because `before_delete_post` fires for every post type including the attachment being deleted. Verify the stored attachment still exists (a volunteer may have deleted it by hand). Call `wp_delete_attachment( $id, true )` **only** when `_ptk_share_square_custom` is falsy — never delete a user-uploaded image, which may be used elsewhere.
- [ ] **Step 4: "Use the generated square again"** — clears `_ptk_share_square_custom` and `_ptk_share_square_id` so the next render regenerates. Without this, a school that uploads once can never get back.
- [ ] **Step 5: Commit** — `git commit -m "Keep the generated square in step, and clean it up when the newsletter goes"`

---

### Task 8: The panel

**Files:**
- Create: `pta-knowledge-hub/includes/class-share-panel.php`
- Create: `pta-knowledge-hub/assets/css/share-panel.css`
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php`

There is no post-publish screen and no sidebar for this post type: `redirect_edit_to_builder()` (`:107-118`) sends every edit into the Builder, which owns its own save redirect (`:201-207`). Core hooks like `post_submitbox_misc_actions` never fire. The panel therefore lives on **Builder step 4**, following the proven pattern of `render_preview_panel()` (`:877`): a sibling of `#ptk-nl-form` carrying `data-step="4"`.

**CSS contract** (`newsletter-builder.css:9-26`): a `[data-step]` element must be a plain block, never `display:flex`, and must never be hidden by the stylesheet — `showStep()` owns visibility.

- [ ] **Step 1:** Render three sections — Facebook, Instagram, WhatsApp. Each: a textarea, a Copy button, a "reset to generated" control, and the stale warning when the stored hash no longer matches.
- [ ] **Step 2:** Instagram section additionally shows the square, "upload your own instead", "use the generated square again", and the QR. When FreeType is missing, show the upload prompt instead of a broken image; when GD is missing, say the phone handoff is unavailable and keep the captions working.
- [ ] **Step 3:** A published newsletter gets the QR and the real link. **A draft gets neither** — say the link appears once published rather than handing over a broken URL.
- [ ] **Step 4: `require_once` the new classes** in `pta-knowledge-hub.php`, beside the existing block. This is the first task that wires into WordPress, and without it the Builder change references an undefined class and fatals the Builder page — the same dead wizard Task 9 Step 4 warns about, arriving from a different direction. The Playground checks in Tasks 9 and 10 depend on this.
- [ ] **Step 5:** Register the panel from the Builder's step-4 render. Enqueue the CSS on the Builder hook only.
- [ ] **Step 6: Where the Facebook group link comes from.** The panel links out to the PTA's Facebook group, and **no such URL exists anywhere in the plugin** — Northeast's is hard-coded in prose in the spec, which is no use to the other ten PTAs. Read it from a `ptk_share_facebook_url` blog option (set on the Task 11 page). When it is empty, show the caption and the copy button with no link-out rather than a dead button. Same for the panel's `wa.me` link, which is a plain URL scheme and needs no setting.
- [ ] **Step 7: Commit** — `git commit -m "Put the three posts on the last step of the builder"`

---

### Task 9: Saving, and getting the volunteer to the panel

**Files:**
- Create: `pta-knowledge-hub/assets/js/share-panel.js`
- Modify: `pta-knowledge-hub/includes/class-share-panel.php`
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php`
- Modify: `pta-knowledge-hub/assets/js/newsletter-builder.js`

- [ ] **Step 1: The AJAX endpoint.** `check_ajax_referer( 'ptk_nl_share', 'nonce' )`, then `current_user_can( 'edit_post', $post_id )` **and** `get_post_type( $post_id ) === 'pta_newsletter'`. The existing preview endpoint's bare `edit_posts` (`:218`) is fine for a stateless render and **wrong** for a per-post write — follow `handle_submission()` (`:150-159`) instead. The upload path also needs `upload_files`.
- [ ] **Step 2: Dirty marking.** JS marks a channel dirty on first `input`; only dirty channels write meta. **A channel that loads with stored meta starts dirty** — otherwise the first reload after an edit shows the stored text and then silently stops saving. "Reset" deletes the meta and clears the flag.
- [ ] **Step 3: The boot hint.** Localize `startStep = 4` when `ptk_nl_msg` is set, and change the boot call to `showStep( parseInt( ptkNlData.startStep, 10 ) || FIRST_STEP, false )`. Without this the volunteer lands on step 1 after publishing while the panel sits hidden three steps away — the feature appears not to exist. **The `parseInt` is required:** `wp_localize_script()` casts scalars to strings, so the value arrives as `"4"`; it survives today only because `showStep()` clamps with `Math.max`/`Math.min` (`js:136`) and coerces as a side effect, which is not something to depend on.
- [ ] **Step 4: Do not break the Builder.** The boot block (`js:83-107`) is a flat list of `bind*()` calls; a throw anywhere in it aborts before `showStep()` and the whole wizard goes inert — a documented failure `node --check` will not catch (`plans/2026-07-16-newsletter-wizard-live-preview.md:462`). Put `bindSharePanel()` **last** and wrap it in `try/catch`. The panel is optional; the Builder is not. Write the copy buttons self-contained — `copy-button.js` is a front-end asset and is not enqueued on this admin hook.
- [ ] **Step 5: Verify in Playground** that the Builder still boots — edit a newsletter, confirm the live preview still updates as you type. If the wizard is inert, look at the boot block first.
- [ ] **Step 6: Commit** — `git commit -m "Save what the volunteer changed, and land them on it after publishing"`

---

### Task 10: The phone page

**Files:**
- Create: `pta-knowledge-hub/includes/class-share-page.php`
- Modify: `pta-knowledge-hub/includes/class-qr-codes.php`

- [ ] **Step 1: Expose the QR generator.** `generate_png_data_url()` is `private` (`:127`) and the meta box is bound to `pta_knowledge` only (`:23`). Extract a public helper. It uses the bundled `vendor/phpqrcode` with plain GD — no remote service — so the no-external-dependency premise holds.
- [ ] **Step 2: Register the query var** `ptk_share` through the `query_vars` filter exactly as `ptk_preview` is registered (`class-public-preview.php:64-67`), or `get_query_var()` returns nothing. No other code claims it.
- [ ] **Step 3: Render on `template_redirect`.** Gate on **both** `get_post_type() === 'pta_newsletter'` **and** `post_status === 'publish'`; 404 otherwise. The post-type check is not optional — without it a guessed ID could leak the title of a restricted `pta_knowledge` entry. Do **not** reuse `PTK_Public_Preview`'s token: its lookup only matches `draft/pending/private/future` (`:92`) and it deletes tokens on publish (`:310-318`) — exactly backwards here. A published newsletter is already public, so there is no token at all.
- [ ] **Step 4: Content, in this order** — the square (long-press to save), then the **Instagram caption first** (Instagram is why the phone is involved), then the WhatsApp text with its own copy button and `wa.me` link. Phone-width layout.
- [ ] **Step 5: Read-only.** The page never regenerates the square and never writes to the media library. Generating caption *text* is stateless computation and is fine here.
- [ ] **Step 6: Verify on a real phone viewport** at 390px. Confirm a draft's ID 404s.
- [ ] **Step 7: Commit** — `git commit -m "Hand the post to the phone, since Instagram lives there"`

---

### Task 11: The subsite colour picker

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-color.php`

- [ ] **Step 1:** A new admin page — the existing picker is main-site only and hangs off `edit.php?post_type=pta_knowledge` (`class-site-colors.php:164`). Put this one under the **Newsletters** menu, where the person setting it is already working. Capability `manage_options` on the subsite. Option key `ptk_share_color` (a blog option, distinct from the network `ptk_site_colors`).
- [ ] **Step 2:** Show a live preview of the square using the chosen colour, and refuse (or auto-darken, saying so) anything failing the contrast guard.
- [ ] **Step 3: Add the Facebook group URL field** — option `ptk_share_facebook_url`, validated as an `https://` URL, empty allowed. It belongs on this page: it is the other per-PTA setting the share panel needs, and a second settings page for one field would be worse.
- [ ] **Step 4: Confirm the Owner-column dots are unchanged** on both the main site and a subsite. If any dot moved, the override leaked into `color_for()` — back it out.
- [ ] **Step 5: Commit** — `git commit -m "Let a school pick its own colour, from where it is already working"`

---

### Task 12: Ship it

**Files:**
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php`
- Modify: `update-info.json`

- [ ] **Step 1: BUMP `PTK_VERSION`.** It is still `4.0.1` (`:16`) — identical to the shipped zip. Both `ptk_maybe_clear_cache_on_update` (`:170`) and `ptk_maybe_flush_rewrites_on_update` (`:189`) are gated on that constant changing. **Ship the Newsletter Builder without bumping it and the rewrite flush never runs, so `/newsletters/` 404s on all 11 sites.** This predates this feature and would break the Builder's debut on its own.
- [ ] **Step 2: Update `update-info.json`** — version and a plain-language changelog entry matching the existing voice (what it does for a volunteer, not what changed in the code).
- [ ] **Step 3: Full Playground pass.** Create a newsletter, publish it, confirm you land on step 4 with the panel showing. Edit a caption, reload, confirm it persisted and still saves. Change the issue number, confirm the stale warning appears. Scan the QR with an actual phone. Trash the newsletter, then delete it, and confirm the square is gone from the media library.
- [ ] **Step 4: Run every test.**

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/pta-knowledge-hub" && for f in tests/test-*.php; do echo "== $f"; php "$f" || exit 1; done
```

- [ ] **Step 5: Rebuild the zip** and confirm it contains the newsletter *and* share classes — the current zip contains neither.
- [ ] **Step 6: Stop before uploading.** Deployment is Lucas's call: Network Admin → Plugins → Add Plugin → Upload → "Replace current with uploaded", which updates all 11 sites at once. Ask; do not upload.
- [ ] **Step 7: Commit** — `git commit -m "Bump the version so the newsletter links survive the update"`
