# Newsletter Round 2: Set It Once — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** A school fills in a handful of settings once — square colors, Facebook/Join/News/
Calendar links, contact email — and every newsletter after that carries them automatically: the
masthead logo and Join link, "See full calendar" under Coming up, an automatic "Got news?"
closing, and (for a brand-new newsletter) the footer, section labels and issue number copied
from the last issue. Newsletters move inside the PTA Hub admin menu. Version 4.3.0.

**Architecture:** One settings page (`PTK_Share_Settings`, renamed in place) grows five new
fields alongside its existing two. `render_opts()` grows four new keys read from those settings;
the renderer reads them at three points already stubbed with "Round 2 hook" comments in round 1.
`default_blocks_for_site()` grows a pure, unit-tested merge step that copies footer + two labels
from the most recent newsletter. The share square's single color becomes two (background, text)
with the same contrast-guard machinery reused for a second field. The `pta_newsletter` CPT
nests under the PTA Hub menu, which requires fixing two hand-built admin-page hook-suffix
strings to use WordPress's own captured hook value instead.

**Tech Stack:** PHP 7.4-compatible WordPress plugin, no build step. Plain-php tests plus one
Node script. WordPress Playground for the Builder and settings. The Browser pane for visual
checks.

**Spec:** `docs/superpowers/specs/2026-09-16-newsletter-round2-set-it-once-design.md`. Read it
first — every line reference, option key and decision is there. Where this plan and the spec
disagree, the spec wins — say so rather than guessing.

**Where:** the git worktree `/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round2`,
branch `newsletter-round2`, built on round 1 (4.2.0). Run everything from there. Do not `cd` to
the parent repo, do not touch other branches, never use bare `git stash`.

---

## Before you start

**Test command, run after every task:**

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round2/pta-knowledge-hub" && for f in tests/test-*.php; do echo "== $f"; php "$f" || exit 1; done && node tests/test-relabel-js.mjs
```

All existing tests pass at the start (verified 2026-09-16, same command as round 1's plan).
Tests are plain PHP scripts using `ptk_test_ok()` / `ptk_test_done()` from `tests/bootstrap.php`
— match the style of `tests/test-newsletter-data.php` and `tests/test-share-settings.php`.

**PHP 7.4:** your CLI may be newer; the sites are not. No `match`, `str_contains`,
`str_starts_with`, arrow-typed properties, `readonly`, named arguments, nullsafe `?->`. `??` is
fine (used throughout the existing codebase).

**Playground (Task 8):**

```bash
npx --yes @wp-playground/cli@latest server --auto-mount "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round2/pta-knowledge-hub" --login --port 9404
```

Open **`http://127.0.0.1:9404`**, never `localhost:9404` — the site URL is `127.0.0.1`, so
`localhost` makes admin-ajax calls cross-origin and the live preview dies silently. It
auto-logs-in via cookie + 302; probe with
`curl -s -L -c /tmp/r2.txt -b /tmp/r2.txt http://127.0.0.1:9404/wp-admin/...`. Delete any
throwaway probe files (test posts created only to poke at endpoints) before committing.

**Commit rule:** every task ends in a commit whose message says why, not what, and ends with
`Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`. Tests pass at every commit.

## File structure

| File | Change |
|---|---|
| `pta-knowledge-hub/includes/class-share-color.php` | New `BG_OPTION` constant, `TEXT_FALLBACK` |
| `pta-knowledge-hub/includes/class-share-settings.php` | Retitled "Newsletter settings"; 5 new fields; page/handler grow |
| `pta-knowledge-hub/assets/css/share-settings.css`, `assets/js/share-settings.js` | Second color control; preview shows both colors |
| `pta-knowledge-hub/tests/test-share-settings.php` | New field validators |
| `pta-knowledge-hub/includes/class-newsletter-data.php` | `merge_start_from_last()` pure helper |
| `pta-knowledge-hub/tests/test-newsletter-data.php` | Tests for the above |
| `pta-knowledge-hub/includes/class-newsletter-builder.php` | `render_opts()` new keys; `masthead_logo_url()`; `most_recent_newsletter_id()`; `default_blocks_for_site()` extended; "copied from" note; hook-suffix fix; `page_hook()` accessor |
| `pta-knowledge-hub/includes/class-newsletter-renderer.php` | Join link, calendar link, `render_news_cta()`, dispatch hook in `render()` |
| `pta-knowledge-hub/tests/test-newsletter-renderer.php` | New assertions |
| `pta-knowledge-hub/includes/class-share-image.php` | `render_png()`/`ensure_square()` take background+text |
| `pta-knowledge-hub/includes/class-share-data.php` | `square_inputs_hash()` gains a parameter |
| `pta-knowledge-hub/tests/test-share-image.php`, `tests/test-share-data.php` | Updated + new assertions |
| `pta-knowledge-hub/includes/class-share-panel.php` | Hook-suffix fix; passes background+text |
| `pta-knowledge-hub/includes/class-newsletter-post-type.php` | `show_in_menu` nests under PTA Hub |
| `pta-knowledge-hub/includes/class-welcome.php` | New Start Here card, demote existing primary |
| `pta-knowledge-hub/pta-knowledge-hub.php`, `update-info.json`, `pta-knowledge-hub.zip` | 4.3.0 |

---

### Task 1: Newsletter settings — five new fields (pure validators first)

The color/link validators are pure PHP and testable without WordPress, exactly like
`PTK_Share_Settings::validate_facebook_url()` and `PTK_Newsletter_Data::sanitize_link_url()`
already are. This task adds the option keys, the pure sanitizing, and the save/render wiring;
it does NOT touch the renderer, the square, or the menu.

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-color.php`
- Modify: `pta-knowledge-hub/includes/class-share-settings.php`
- Modify: `pta-knowledge-hub/assets/css/share-settings.css`
- Modify: `pta-knowledge-hub/assets/js/share-settings.js`
- Modify: `pta-knowledge-hub/tests/test-share-settings.php`

- [ ] **Step 1: Add the background-color option constant and the new text default.**
  In `PTK_Share_Color`, add `const BG_OPTION = 'ptk_share_bg_color';` next to `const OPTION =
  'ptk_share_color';` (`class-share-color.php:36`), and `const TEXT_FALLBACK = '#ffffff';` next
  to `const FALLBACK = '#1a2f5c';` (`:30`). Do not change `share_color()`'s resolution chain
  (own → council → `FALLBACK`) — per spec Decision 5, `ptk_share_color` keeps its existing
  chain and existing navy `FALLBACK`; the WHITE default only applies when NEITHER `ptk_share_
  color` nor the council color is set, which `share_color()` already produces (it falls through
  to `FALLBACK` = navy today). **Add a new method** `text_color( $blog_id = null )` that returns
  `share_color( $blog_id )`, unless that chain resolved all the way to `FALLBACK` with no own
  or council value set (reuse `PTK_Share_Settings::color_source()`'s three-way logic — or, to
  keep this pure and WordPress-light, simpler: have the CALLER decide. Re-check against the
  spec before implementing: the simplest correct reading is "default is white when unset",
  which means the fallback constant `share_color()` uses when nothing is set should itself
  become `TEXT_FALLBACK`, not `FALLBACK`.) **Resolve this ambiguity before writing code**: run
  `php -r` against `PTK_Share_Color::share_color()` as it exists today to confirm what an
  unset school currently returns (it will be `#1a2f5c`, the navy `FALLBACK`), decide whether
  changing `share_color()`'s own final fallback to white is safe for OTHER callers of
  `share_color()` (grep every call site first — `class-share-settings.php`,
  `class-share-panel.php`, any others), and only change it if `share_color()` has no caller
  that needs the navy fallback for a non-text purpose. If any such caller exists, leave
  `share_color()` alone and apply the white default at the `render_opts()`/`ensure_square()`
  call site instead (`'' !== $val ? $val : PTK_Share_Color::TEXT_FALLBACK`).
- [ ] **Step 2: Write failing tests for a background-color validator.** `PTK_Share_Settings`
  already has `submitted_color()` (`:134-156`) — it is generic over "picker value, typed value,
  initial value," not tied to which option it saves. Add tests to
  `tests/test-share-settings.php` that call `PTK_Share_Settings::submitted_color()` with a
  background-flavored scenario (e.g. `submitted_color('#1a2f5c', '2a3f6c', '#1a2f5c')` →
  `array('value' => '#2a3f6c', 'error' => '')`) to confirm the SAME helper is reusable for the
  second field with zero changes — if a test fails here, the helper needs a second copy or a
  parameter, not a rewrite.
- [ ] **Step 3: Run tests, confirm green** (the helper needs no changes if Step 2 passes as
  written — commit only the test additions, or skip to Step 4 if genuinely nothing changed).
- [ ] **Step 4: Wire two color fields into `handle_save()` and `render_page()`.** Duplicate the
  `own`/`council` radio pattern for background — but per spec, background has NO council
  concept (it was never network-driven), so simplify: background is either "our own color" or
  blank (falls back to `PTK_Share_Color::FALLBACK` navy) — no radio group needed, just a
  picker+hex pair that saves `ptk_share_bg_color` via `submitted_color()`, or clears it back to
  the default with a "Use the default navy" action. Follow the existing markup patterns at
  `:378-414` for structure (fieldset, legend, picker, hex box, hint, error slot) but simplified
  (no `<fieldset class="ptk-ss-choices">` radio group — a single "Use your own color" checkbox
  or just a picker that defaults to navy is enough; pick whichever reads simplest and match it
  in both PHP and JS).
- [ ] **Step 5: Wire the four link/email fields.** For each of Join (`ptk_join_url`), News
  (`ptk_news_url`), Calendar (`ptk_calendar_url`): a `type="text" inputmode="url"` input (round
  1 Decision 6's exact reasoning — a `type="url"` input rejects a bare email before
  `sanitize_link_url()` ever sees it), sanitized via
  `PTK_Newsletter_Data::sanitize_link_url()` on save, with the same "not saved, here's why"
  error pattern as the Facebook field (`:289-304`) — reuse the plain-English error string style
  (`"That doesn’t look like a web address or an email. …"` — write one, matching
  `validate_facebook_url()`'s voice). For contact email (`ptk_contact_email`): a plain `type=
  "email"` input, sanitized with `sanitize_email()`, saved only if `is_email()` is true, else
  the same "not saved" pattern.
- [ ] **Step 6: Rename the page.** Change `'Sharing settings'` → `'Newsletter settings'` at both
  string arguments in `add_page()` (`:219-220`) and the `<h1>` in `render_page()` (`:349`).
  Update the intro paragraph (`:350`) to describe all seven settings, not just the two "used by
  Share this newsletter" ones. **Do not** change `PAGE_SLUG`, the class name, or the file name
  (spec Decision 4).
- [ ] **Step 7: `data-ptk-share-settings` JS.** Extend `assets/js/share-settings.js`'s `boot()`
  to wire the second color pair the same way as the first (duplicate `fromPicker`/hex-sync
  logic, or generalize it into a small function called twice — prefer generalizing since it's
  the same four lines twice).
  Run: `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done`.
- [ ] **Step 8: Commit.** `git add -A -- pta-knowledge-hub/includes/class-share-color.php pta-knowledge-hub/includes/class-share-settings.php pta-knowledge-hub/assets/css/share-settings.css pta-knowledge-hub/assets/js/share-settings.js pta-knowledge-hub/tests/test-share-settings.php`

---

### Task 2: The "start from last issue" merge — pure logic first

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-data.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-data.php`

- [ ] **Step 1: Write the failing tests.** Append to `tests/test-newsletter-data.php`:

```php
// --- 4.3.0: "start from last issue" merge rule. ---
$d = 'PTK_Newsletter_Data';

$defaults = $d::default_blocks();
$last = $d::sanitize_blocks( array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA' ) ),
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'Stale news' ) ),
    array( 'type' => 'featured', 'data' => array( 'eyebrow' => 'Date change', 'headline' => 'Old story' ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array( array( 'eyebrow' => 'Old card label', 'heading' => 'x' ) ) ) ),
    array( 'type' => 'quick_notes', 'data' => array( 'label' => 'Good to know', 'items' => array( array( 'heading' => 'Old note' ) ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'Thanks!', 'links' => array( array( 'label' => 'Site', 'url' => 'https://x.org' ) ) ) ),
) );

$merged = $d::merge_start_from_last( $defaults, $last );
$mtype  = array_column( $merged, 'type' );

$mf = $merged[ array_search( 'featured', $mtype, true ) ]['data'];
ptk_test_ok( $mf['eyebrow'] === 'Date change', 'top story label copies from last issue' );
ptk_test_ok( $mf['headline'] === '', 'top story headline does NOT copy' );

$mq = $merged[ array_search( 'quick_notes', $mtype, true ) ]['data'];
ptk_test_ok( $mq['label'] === 'Good to know', 'quick notes label copies from last issue' );
ptk_test_ok( $mq['items'] === array(), 'quick notes items do NOT copy' );

$ma = $merged[ array_search( 'announcement', $mtype, true ) ]['data'];
ptk_test_ok( $ma['headline'] === '', 'announcement does NOT copy' );

$mc = $merged[ array_search( 'story_cards', $mtype, true ) ]['data'];
ptk_test_ok( $mc['cards'] === array(), 'story cards do NOT copy (card eyebrow is per-card, not a section label)' );

$mfoot = $merged[ array_search( 'footer', $mtype, true ) ]['data'];
ptk_test_ok( $mfoot['signoff'] === 'Thanks!', 'footer signoff copies wholesale' );
ptk_test_ok( count( $mfoot['links'] ) === 1 && $mfoot['links'][0]['url'] === 'https://x.org', 'footer links copy wholesale' );

// Blank labels in the source stay blank in the default, not overwritten with ''.
$blank_last = $d::sanitize_blocks( array( array( 'type' => 'featured', 'data' => array( 'eyebrow' => '' ) ) ) );
$merged2 = $d::merge_start_from_last( $d::default_blocks(), $blank_last );
$mf2 = $merged2[ array_search( 'featured', array_column( $merged2, 'type' ), true ) ]['data'];
ptk_test_ok( $mf2['eyebrow'] === '', 'a blank label in the source leaves the default blank (no-op, not an error)' );

// An empty "last" array (no previous newsletter) returns the defaults unchanged.
$merged3 = $d::merge_start_from_last( $d::default_blocks(), array() );
ptk_test_ok( $merged3 === $d::default_blocks(), 'no previous newsletter: defaults pass through unchanged' );
```

- [ ] **Step 2: Run, confirm failure** (`merge_start_from_last` doesn't exist yet).
- [ ] **Step 3: Implement `merge_start_from_last( array $defaults, array $last_blocks ) : array`**
  in `PTK_Newsletter_Data`, alongside the other pure block-shape helpers. Copy `footer` data
  wholesale (replace the default footer block's `data` with the last footer's `data`, if a
  footer block exists in `$last_blocks`). Copy `featured.eyebrow` into the default featured
  block's `data['eyebrow']` only when the source's value is non-blank... **no** — re-read the
  test above: `mf2['eyebrow'] === ''` when the source is blank passes trivially whether or not
  you special-case "only copy if non-blank," since copying an empty string onto an empty
  string is a no-op. Simplify: always assign the source's value (if the block type exists in
  `$last_blocks`), whether blank or not — there is no meaningful difference for a fresh
  `default_blocks()` target where every field already starts blank. Do the same for
  `quick_notes.label`. Use `array_column` / a small lookup-by-type helper to find each block
  type in `$last_blocks` without assuming array order.
- [ ] **Step 4: Run tests, confirm green.**
- [ ] **Step 5: Commit.** `git add -A -- pta-knowledge-hub/includes/class-newsletter-data.php pta-knowledge-hub/tests/test-newsletter-data.php`

---

### Task 3: Wire "start from last issue" into the Builder

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php`

- [ ] **Step 1: Factor out `most_recent_newsletter_id()`.** Extract the `get_posts()` call at
  `:664-673` (inside `next_issue_number()`) into a new private static method returning `int`
  (`0` if none), and have `next_issue_number()` call it. Run tests — `next_issue_number()`'s
  own behavior is unchanged, so this is a pure refactor with no new assertions needed, but
  confirm `tests/test-newsletter-data.php` (which exercises `compute_next_issue()`, not this
  WordPress-coupled method) still passes, and manually sanity-check `next_issue_number()`'s
  logic is untouched by reading the diff.
- [ ] **Step 2: Extend `default_blocks_for_site()`.** After the existing school-name fill
  (`:617-624`), call `self::most_recent_newsletter_id()`; if non-zero, decode
  `get_post_meta( $id, 'ptk_nl_blocks', true )` through
  `json_decode(..., true)` → `PTK_Newsletter_Data::sanitize_blocks()` (same pattern as
  `blocks_for_js()`'s edit branch, `:598`), then `$blocks = PTK_Newsletter_Data::
  merge_start_from_last( $blocks, $last_blocks )`.
- [ ] **Step 3: Add the "copied from" note.** In `render_page()`, after the existing intro
  paragraph (`:883`), when `! $edit_id`, look up the same most-recent id, read its
  `ptk_nl_issue` meta, and — only if found — print a `.ptk-nl-msg.ptk-nl-msg-ok`-styled
  paragraph: `"We copied your footer and section names from No. {issue_label}."` using
  `PTK_Share_Text::issue_label()` for the padding. Nothing prints when there is no previous
  newsletter (first-ever newsletter on a site).
- [ ] **Step 4: Manual check.** No new pure-PHP surface here (it's WordPress-coupled glue over
  the already-tested `merge_start_from_last()`), so verify by reading the diff against Task 2's
  tests' expectations, and re-verify fully in Task 8's Playground pass.
  Run: `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done`.
- [ ] **Step 5: Commit.** `git add -A -- pta-knowledge-hub/includes/class-newsletter-builder.php`

---

### Task 4: Renderer — logo source, Join link, calendar link, "Got news?"

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php` (`render_opts()`, new
  `masthead_logo_url()`)
- Modify: `pta-knowledge-hub/includes/class-newsletter-renderer.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: `masthead_logo_url()`.** New private static method on
  `PTK_Newsletter_Builder`: `get_theme_mod( 'custom_logo' )` → if truthy,
  `wp_get_attachment_image_url( $id, 'thumbnail' )` → if truthy, return it; else fall through to
  `get_site_icon_url() ?: ''`. Wire into `render_opts()` (`:419`) replacing the direct
  `get_site_icon_url()` call.
- [ ] **Step 2: `render_opts()` gains four keys** per the spec's Part C code block
  (`join_url`, `calendar_url`, `news_url`, `contact_email`), each read from its option and run
  through the same sanitizer used on save (belt-and-suspenders, matching existing style).
- [ ] **Step 3: Write failing renderer tests.** Append to `tests/test-newsletter-renderer.php`:
  a render with `'join_url' => 'https://example.org/join', 'date' => '2026-09-13'` asserts the
  output contains `Join the PTA for 2026` (school year) and the href; a render with `'join_url'
  => ''` asserts no such link; a render with `'calendar_url' => 'https://example.org/cal'`
  asserts `See full calendar` appears after `What's coming up`... **only when there's at least
  one valid Coming-up row** (`render_events()` returns `placeholder()` with no rows at all —
  confirm whether the calendar link should still show on an EMPTY Coming-up block; the spec's
  Part C text doesn't resolve this explicitly — default to: no, because `render_events()`
  bails out to `placeholder()` before reaching the hook when there are no valid rows, so
  wire the calendar link inside the non-placeholder branch only, and add a test that asserts
  an empty-rows block with a calendar_url set still shows the placeholder text, not the
  calendar link, keeping the "empty sections render nothing" rule from round 1 fact 7 intact).
  A render with `'news_url' => 'https://example.org/submit', 'contact_email' =>
  'ne@example.org'` asserts `Got news? Put it in the newsletter.` and `Questions? Email` both
  appear, immediately before the footer's own content in the output string (use `strpos` to
  assert relative order). A render with `'news_url' => ''` asserts neither string appears.
- [ ] **Step 4: Implement `render_news_cta( array $opts )`** per the spec's Part C markup, and
  wire the dispatch check into `render()`'s loop per the spec's code sketch (checking
  `PTK_Newsletter_Data::TYPE_FOOTER === $type` and a non-blank `$opts['news_url']`).
- [ ] **Step 5: Implement the Join link** inside `render_header()` at the `:156` hook comment,
  and the calendar link inside `render_events()` at the `:319-320` hook comment, per the spec's
  Part C markup — respecting the empty-Coming-up-block decision from Step 3.
- [ ] **Step 6: Grep the full renderer output in a test for `border-left`/`border-right`** —
  add or extend the existing one-sided-border test (round 1 already has this per its risks
  table) to cover the new `render_news_cta()` markup specifically.
- [ ] **Step 7: Run tests, confirm green.**
  `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done`.
- [ ] **Step 8: Commit.** `git add -A -- pta-knowledge-hub/includes/class-newsletter-builder.php pta-knowledge-hub/includes/class-newsletter-renderer.php pta-knowledge-hub/tests/test-newsletter-renderer.php`

---

### Task 5: Two-color Instagram square — pure color logic and hashing first

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-data.php`
- Modify: `pta-knowledge-hub/tests/test-share-data.php`
- Modify: `pta-knowledge-hub/includes/class-share-image.php`
- Modify: `pta-knowledge-hub/tests/test-share-image.php`

- [ ] **Step 1: Write failing tests for the hash signature change.** In
  `tests/test-share-data.php`, update every existing `square_inputs_hash(...)` call to pass a
  fifth positional value (text color) before `$version`, and add: same issue/date/school/
  version, background changed → hash changes; text changed → hash changes; both unchanged →
  hash unchanged. Run — this SHOULD fail (arity mismatch) until Step 2.
- [ ] **Step 2: Update `PTK_Share_Data::square_inputs_hash()`** signature to `( $issue, $date,
  $school_name, $background, $text, $version )`, updating the `md5()` input string to include
  both. Run tests, confirm green.
- [ ] **Step 3: Write failing tests for `render_png()`/`accent_for()` replacement.** In
  `tests/test-share-image.php`, replace any `accent_for()` call/assertion with the new
  `text_for( $text, $background )` (or whatever name is chosen — keep it symmetrical: spec
  suggests `text_for`), asserting `text_for('#000000', '#1a2f5c')` returns a lightened color
  (same behavior `accent_for` had, just background is now a parameter instead of the `GROUND`
  constant) and `text_for('#ffffff', '#1a2f5c')` returns `#ffffff` unchanged (already readable).
  Add a `render_png()` test that passes `'background' => '#efece6', 'text' => '#1a2f5c'`
  (light background, dark text — the inverse of today's only case) and asserts it still returns
  a non-empty PNG string (can't assert pixel colors without decoding the PNG; if the test
  harness already decodes pixels for the existing single-color test, mirror that approach for
  two colors — check `tests/test-share-image.php`'s existing assertions before deciding how
  deep to go here).
- [ ] **Step 4: Implement.** `GROUND` stays as the default background constant.
  `accent_for( $color )` → `text_for( $text, $background )` = `PTK_Share_Color::readable_pair(
  $text, $background )`. In `render_png()`: `$background = PTK_Share_Color::normalize_hex(
  isset($args['background']) && '' !== $args['background'] ? $args['background'] : self::
  GROUND )`; `$text = self::text_for( isset($args['text']) && '' !== $args['text'] ?
  $args['text'] : PTK_Share_Color::TEXT_FALLBACK, $background )`. Replace `$ground`,
  `$accent_col`, `$white` allocations with `$bg_col = self::allocate($im, $background)` and
  `$text_col = self::allocate($im, $text)`; every draw call that used `$accent_col` or `$white`
  now uses `$text_col`; the `imagefilledrectangle` ground fill uses `$bg_col`. Decide and
  implement the hairline color formula per spec Part D ("computed mix... pin down with a
  concrete formula") — simplest defensible choice: keep it a fixed step off the BACKGROUND
  (e.g. `readable_pair`-style lighten/darken by a small fixed ratio, or reuse
  `PTK_Share_Color::readable_pair($background, $text)` at a lower target ratio via a new
  constant) — pick one, write it, and add a one-line comment explaining the choice, matching
  the file's existing comment density.
- [ ] **Step 5: Update `ensure_square()`.** `$args['color']` → `$args['background']` /
  `$args['text']`; compute `$drawn_text = self::text_for(...)` before hashing (fact 4's rule:
  hash the drawn color); call `PTK_Share_Data::square_inputs_hash( $issue, $date, $school,
  $background, $drawn_text, $version )`; pass both to `render_png()`.
- [ ] **Step 6: Run tests, confirm green.**
  `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done`.
- [ ] **Step 7: Commit.** `git add -A -- pta-knowledge-hub/includes/class-share-data.php pta-knowledge-hub/includes/class-share-image.php pta-knowledge-hub/tests/test-share-data.php pta-knowledge-hub/tests/test-share-image.php`

---

### Task 6: Wire two colors through the settings page and the share panel

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-panel.php`
- Modify: `pta-knowledge-hub/includes/class-share-settings.php` (preview swatch)
- Modify: `pta-knowledge-hub/assets/css/share-settings.css`
- Modify: `pta-knowledge-hub/assets/js/share-settings.js`

- [ ] **Step 1: `PTK_Share_Panel::square_html()`** (`:406-419`): replace `'color' =>
  PTK_Share_Color::share_color()` with `'background' => get_option(
  PTK_Share_Color::BG_OPTION, '' ), 'text' => PTK_Share_Color::share_color()`.
- [ ] **Step 2: Preview swatch shows both colors.** In `PTK_Share_Settings::render_page()`, set
  the preview's background from the chosen/default background value (was hard-coded navy in the
  markup — confirm by reading `.ptk-ss-preview`'s CSS before assuming) and its text via the
  existing `--ptk-ss-accent` custom property, now driven by the SECOND field's drawn value
  (`readable_pair(text, background)`, computed server-side same as today's single-color
  preview at `:341` `$drawn = PTK_Share_Color::readable_pair( $current, self::GROUND );` — this
  becomes `readable_pair( $current_text, $current_background )`).
  `PTK_Share_Settings::GROUND` (`:37`) either becomes unused (remove it) or becomes the literal
  default fallback used when no background is chosen — reconcile with `PTK_Share_Color::
  FALLBACK` rather than keeping two separate navy constants; prefer removing `GROUND` here and
  referencing `PTK_Share_Color::FALLBACK` everywhere the class used its own copy.
- [ ] **Step 3: JS preview parity** in `share-settings.js`: `update()` (`:99-113`) currently
  reads a fixed `ground` from `data-ground` on the form (`:363` in the PHP,
  `data-ground="<?php echo esc_attr( self::GROUND ); ?>"`) — this becomes the LIVE background
  field's current value instead of a static attribute, so the preview updates when EITHER color
  changes. Re-wire `update()` to read the background input's current value on every call rather
  than the form's static `data-ground`.
- [ ] **Step 4: `color_message()`** (`class-share-settings.php:97-118`) — its contrast comparison
  runs against whichever background is now in force, not a hard-coded ground; thread the actual
  background value through `handle_save()`'s color-saving branch (`:261-280`) into
  `color_message()`'s call.
- [ ] **Step 5: Run tests, confirm green.**
  `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done`.
- [ ] **Step 6: Commit.** `git add -A -- pta-knowledge-hub/includes/class-share-panel.php pta-knowledge-hub/includes/class-share-settings.php pta-knowledge-hub/assets/css/share-settings.css pta-knowledge-hub/assets/js/share-settings.js`

---

### Task 7: Menu move — fix the hook-suffix trap, nest the CPT, Start Here card

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php`
- Modify: `pta-knowledge-hub/includes/class-share-panel.php`
- Modify: `pta-knowledge-hub/includes/class-newsletter-post-type.php`
- Modify: `pta-knowledge-hub/includes/class-welcome.php`

- [ ] **Step 1: Fix the hook-suffix trap FIRST, before moving the menu**, so the fix is provably
  correct against the CURRENT (working) top-level menu before the parent changes. In
  `PTK_Newsletter_Builder`: add `private static $hook = '';`; in `add_page()` (`:511-520`),
  capture `self::$hook = (string) add_submenu_page(...)`; add `public static function
  page_hook() { return self::$hook; }`; in `enqueue_assets()` (`:527-530`), compare `$hook !==
  self::$hook` instead of the rebuilt string.
- [ ] **Step 2: Verify Step 1 changes nothing yet.** In Playground (or by reading
  `wp-admin/menu.php`'s behavior if Playground isn't up yet — but prefer testing it live),
  confirm the Builder's CSS/JS still load with the CPT still top-level. This isolates "did my
  hook-capture pattern work" from "did the menu move break something else."
- [ ] **Step 3: Fix `PTK_Share_Panel::enqueue_assets()`** (`:44-47`) to compare against
  `PTK_Newsletter_Builder::page_hook()` instead of rebuilding the string. Re-verify per Step 2.
- [ ] **Step 4: Move the menu.** In `class-newsletter-post-type.php`, change `'show_in_menu' =>
  true` (`:68`) to `'show_in_menu' => 'edit.php?post_type=pta_knowledge'`; remove
  `'menu_position' => 6` (`:70`) and `'menu_icon' => 'dashicons-email'` (`:71`) (dead once
  nested).
- [ ] **Step 5: Verify in Playground**: the Newsletters submenu now sits under PTA Hub; Add New
  and an existing newsletter both load with CSS/JS applied (not raw HTML) and no console
  errors; `PTK_Newsletter_Builder::url()` still resolves correctly (spec fact 9's last bullet)
  by opening it directly.
- [ ] **Step 6: Start Here card.** In `PTK_Welcome::get_cards()`, add the newsletter card first
  per the spec's Part E code block, using a new shared accessor for "most recent newsletter"
  (either call `PTK_Newsletter_Builder::most_recent_newsletter_id()` directly if it's public, or
  make it public — prefer making Task 3's `most_recent_newsletter_id()` `public static` from the
  start so this task doesn't need to change its visibility). Demote the existing "Add or update
  an entry" card's `'primary'` to `false`.
- [ ] **Step 7: Run tests, confirm green** (no pure-PHP surface changed here beyond
  visibility — this task is mostly WordPress registration glue; the test suite should be
  unaffected, run it anyway to catch any accidental syntax error).
  `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done`.
- [ ] **Step 8: Commit.** `git add -A -- pta-knowledge-hub/includes/class-newsletter-builder.php pta-knowledge-hub/includes/class-share-panel.php pta-knowledge-hub/includes/class-newsletter-post-type.php pta-knowledge-hub/includes/class-welcome.php`

---

### Task 8: Full Playground verification

No code changes expected; fix forward in small follow-up commits if something in Tasks 1-7
doesn't hold up end to end.

```bash
npx --yes @wp-playground/cli@latest server --auto-mount "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round2/pta-knowledge-hub" --login --port 9404
```

Open `http://127.0.0.1:9404` (never `localhost`). Work through, in order:

- [ ] **Settings save and appear everywhere.** Open Newsletter settings. Set both colors (try
  one bad hex code, confirm the plain-English error and that the OTHER field's good value still
  saves). Set the Facebook link (try `http://` to confirm the existing https-only error still
  fires). Set Join, News, Calendar as bare `https://` links, and set Contact email. Save. Start
  a new newsletter; confirm the live preview shows the logo (if a custom logo or site icon is
  set — if neither, confirm no broken image), the Join link in the masthead, "See full
  calendar" under Coming up (after adding at least one event row — confirm it's ABSENT with
  zero event rows), and the Got-news closing with the contact email. Publish; confirm the same
  four appear on the published page.
- [ ] **A new newsletter pre-fills, and does not carry stale content.** With at least one
  newsletter published, start a second one. Confirm the "We copied your footer and section
  names from No. X" note appears, the footer fields and the top-story/quick-notes § labels are
  pre-filled, the issue number is one more than the last, and the announcement/events/stories/
  greeting/summary are all blank.
- [ ] **Both colors change the square's hash.** On a newsletter's step 4, note the generated
  square's image URL/attachment id. Change ONLY the background color in settings, reload step
  4, confirm the square regenerated (different attachment id or at least visibly different
  background). Repeat changing ONLY the text color.
- [ ] **The menu move keeps the Builder's CSS/JS loading.** Confirm Newsletters is a PTA Hub
  submenu (not its own top-level item), and that Add New / an existing newsletter's edit screen
  are visibly styled (not raw HTML) with the browser console open and empty of errors.
- [ ] **Start Here card shows.** Open Start Here (PTA Hub → Start Here); confirm the newsletter
  card is first, primary-styled, and (once a newsletter exists) shows the "Last issue: No. X ·
  {date} · Edit" line; confirm it's absent on installs with none (harder to test without a
  fresh Playground instance — note this as visually confirmed via the "before any newsletter
  exists" state if reachable, otherwise reason about it from the code).
- [ ] **The Builder still boots with no console errors.** Walk all four steps on both a new and
  an existing newsletter, add a timeline row, a story, a quick note, reorder in step 4 — no
  console errors at any point (round 1's existing check, re-run because the menu move and new
  settings-driven render_opts keys are new surface area).
- [ ] **Delete any throwaway posts/probe files** created only for this verification pass before
  committing anything.
- [ ] **Commit** only if Playground verification required code fixes; otherwise this task
  produces no commit (verification-only).

---

### Task 9: Version 4.3.0 — changelog and zip

**Files:**
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php`
- Modify: `update-info.json`
- Rebuild: `pta-knowledge-hub.zip`

- [ ] **Step 1: Bump the version.** `pta-knowledge-hub.php`: `Version: 4.3.0` (header comment)
  and `define( 'PTK_VERSION', '4.3.0' );` — both, matching round 1's Decision/Risk about keeping
  them in sync (round 1 spec, risks table, "Version not bumped").
- [ ] **Step 2: `update-info.json`.** Bump `"version"` to `"4.3.0"`, `"last_updated"` to
  today's date, and prepend a plain-language changelog entry, matching the existing voice (see
  the 4.2.0 / 4.1.1 entries already in the file for tone — short sentences, no jargon, bold for
  the headline change). Cover: one Newsletter settings page (renamed from Sharing settings) with
  school colors, Join/News/Calendar links and a contact email; new newsletters pre-fill their
  footer and section names from the last issue; the masthead logo, Join link, "See full
  calendar" and an automatic "Got news?" closing now appear automatically when set; the
  Instagram square now uses two colors (background and text); Newsletters moved under the PTA
  Hub menu; Start Here has a one-click newsletter card. State plainly that settings saved today
  only affect newsletters saved or updated from today on (spec Decision 10) — do not imply
  past issues change retroactively.
- [ ] **Step 3: Rebuild the zip** from the worktree root:
  ```bash
  cd "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round2" && zip -rq pta-knowledge-hub.zip pta-knowledge-hub -x "pta-knowledge-hub/tests/*" -x "*/.DS_Store" -x "*/.*" -x "*/zz-*"
  ```
- [ ] **Step 4: Final full test run.**
  `cd pta-knowledge-hub && for f in tests/test-*.php; do echo "== $f"; php "$f" || exit 1; done && node tests/test-relabel-js.mjs`
- [ ] **Step 5: Commit.** `git add -A -- pta-knowledge-hub/pta-knowledge-hub.php update-info.json pta-knowledge-hub.zip` — **STOP here. No upload.**

---

## Risks carried into implementation (see spec for full table)

- The nested-CPT hook-suffix string is not hand-derived anywhere in this plan — Task 7 fixes
  the mechanism instead of guessing the string, and verifies it live in Playground before AND
  after the menu move (Task 7 Steps 2 and 5) so a regression is caught at the step that
  introduced it, not three tasks later.
- `ptk_share_color`'s meaning changes (accent → text) without a data migration; Task 9's
  changelog entry must say so in plain words, not imply nothing changed.
- The hairline color formula in Task 5 Step 4 has no single obviously-correct answer in the
  spec — implement the simplest defensible choice, comment why, and eyeball it in Playground
  (Task 8) rather than treating it as fully pinned down by this plan.
