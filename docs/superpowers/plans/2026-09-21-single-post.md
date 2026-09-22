# Single Post ("Put one thing on the website") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a volunteer write one ordinary WordPress post — styled, with a featured image — on a Hub screen, without meeting the block editor.

**Architecture:** The Hub keeps the post's *parts* (kicker, headline, words, picture, steps, date, button) as post meta and renders them into finished HTML in `post_content` on save, so the post is an ordinary `post` that any theme, feed reader or Facebook can read. Pure classes hold the shape, the wording and the rendering; WordPress-facing classes hold the screens. Everything is gated behind `PTK_Hub_Look::on()`, which ships OFF.

**Tech Stack:** WordPress plugin PHP (no build step), jQuery, the project's plain-PHP test harness (`tests/bootstrap.php`, `ptk_test_ok()`), WP Playground for browser verification.

**Spec:** `docs/superpowers/specs/2026-09-21-single-post-design.md`
**Mockup:** `docs/superpowers/specs/mockups/2026-09-21-single-post-mockup.html`

---

## Conventions that apply to EVERY task

These are the user's standing rules. Breaking one is a defect even if tests pass:

- **NEVER a one-sided border.** No `border-left`/`border-right` accent bars anywhere. Emphasis = full fill, full four-sided border, spacing, a stamp, or type weight.
- Plain English, US spelling. **No `__()` / `esc_html__()` wrappers** — the test harness stubs none.
- **At most one stamp per screen.**
- No WordPress or database words on screen ("post type", "meta", "publish" as a noun, "excerpt", "trash").
- `PTK_Hub_UI` escapes plain text you pass it; HTML you assemble, you escape yourself.
- PHP's alternate `if/endif` indentation leaks into output — prefer separate methods over inline branching.
- **Do not commit** `AGENTS.md`, the five files under `docs/superpowers/plans/2026-07-*`, or `pta-knowledge-hub/assets/js/newsletter-relabel.js` — they carry the user's own uncommitted edits.

**Run the whole suite before every commit:**

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/pta-knowledge-hub" && for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done && for f in tests/*.mjs; do node "$f" >/dev/null || echo FAIL $f; done
```

Expected: no output other than nothing at all (silence = pass).

---

## File Structure

| File | Responsibility |
|---|---|
| `includes/class-post-parts.php` | **Create.** Pure. The parts array: defaults, sanitizing, "is it empty", and the summary sentence. No WordPress. |
| `includes/class-post-renderer.php` | **Create.** Pure. Parts → finished HTML, in the house style. Plus the content hash. No WordPress. |
| `includes/class-post-copy.php` | **Create.** Pure. Every sentence the screens say. |
| `includes/class-post-writer.php` | **Create.** WordPress-facing: the writing screen, the save handler, the confirmation. |
| `includes/class-posts-list.php` | **Create.** WordPress-facing: "Your posts", including the three-way classification. |
| `includes/class-share-settings.php` | **Modify.** One new school-wide option: the sign-off. |
| `includes/class-welcome.php` | **Modify.** The "Tell families what's happening" card gains two buttons. |
| `includes/class-hub-look.php` | **Modify.** Add both page slugs to `PAGES`. |
| `includes/class-simple-mode.php` | **Modify.** Add `ptk-posts` to the trimmed menu. |
| `pta-knowledge-hub.php` | **Modify.** Require the five new files; init the two screens. |
| `assets/css/hub.css` | **Modify.** Only what the new screens need; reuse existing classes first. |
| `tests/test-post-parts.php`, `tests/test-post-renderer.php`, `tests/test-post-copy.php`, `tests/test-posts-list.php` | **Create.** |

---

## Task 1: The parts shape

**Files:**
- Create: `pta-knowledge-hub/includes/class-post-parts.php`
- Test: `pta-knowledge-hub/tests/test-post-parts.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-post-parts.php';

$p = 'PTK_Post_Parts';

// Defaults: every key present, nothing null.
$empty = $p::defaults();
foreach ( array( 'kicker', 'headline', 'words', 'image_id', 'date_label', 'date_note', 'steps', 'link_url', 'link_text' ) as $key ) {
    ptk_test_ok( array_key_exists( $key, $empty ), "defaults() has $key" );
}
ptk_test_ok( array() === $empty['steps'], 'steps default to none' );
ptk_test_ok( 0 === $empty['image_id'], 'no picture by default' );

// Sanitizing keeps text, drops markup, clamps the id.
$dirty = array(
    'kicker'    => '  Class Parents 2026-2027 <script>x</script> ',
    'headline'  => 'We still need class parents.',
    'words'     => "First paragraph.\n\nSecond paragraph.",
    'image_id'  => '-4',
    'link_url'  => 'javascript:alert(1)',
    'link_text' => 'Sign up',
    'steps'     => array( array( 'heading' => 'Sign up', 'body' => 'Fill the form.' ) ),
);
$clean = $p::sanitize( $dirty );
ptk_test_ok( false === strpos( $clean['kicker'], '<script' ), 'markup never survives the kicker' );
ptk_test_ok( 'Class Parents 2026-2027 x' === $clean['kicker'] || false === strpos( $clean['kicker'], 'script' ), 'kicker is plain text' );
ptk_test_ok( 0 === $clean['image_id'], 'a negative picture id becomes none' );
ptk_test_ok( '' === $clean['link_url'], 'a javascript: link is refused' );
ptk_test_ok( 1 === count( $clean['steps'] ), 'a step survives' );

// The summary: the first paragraph, trimmed to one readable sentence-ish line.
ptk_test_ok( 'First paragraph.' === $p::summary( $clean ), 'the summary is the first paragraph' );
ptk_test_ok( '' === $p::summary( $p::defaults() ), 'nothing written, nothing summarized' );

// "Is there anything here at all?"
ptk_test_ok( false === $p::has_content( $p::defaults() ), 'empty parts have no content' );
ptk_test_ok( true === $p::has_content( $clean ), 'filled parts do' );

ptk_test_done();
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/pta-knowledge-hub" && php tests/test-post-parts.php
```

Expected: fatal error, `class-post-parts.php` does not exist.

- [ ] **Step 3: Write `PTK_Post_Parts`**

Pure PHP only — no `sanitize_text_field()`, no `esc_url_raw()`, because the harness stubs no WordPress. Write the small amount of cleaning by hand (`strip_tags`, `trim`, collapse whitespace, allow only `http`/`https`/`mailto` in links, clamp ids with `(int)` and `max(0, …)`). Keep `summary()` to: first non-empty paragraph, whitespace collapsed, cut at a sentence boundary near 180 characters.

- [ ] **Step 4: Run the test until it passes, then the whole suite**

- [ ] **Step 5: Commit**

```bash
git add pta-knowledge-hub/includes/class-post-parts.php pta-knowledge-hub/tests/test-post-parts.php
git commit -m "The parts a single post is made of"
```

---

## Task 2: Rendering the parts, without the picture

**Files:**
- Create: `pta-knowledge-hub/includes/class-post-renderer.php`
- Test: `pta-knowledge-hub/tests/test-post-renderer.php`

Read `docs/superpowers/specs/mockups/2026-09-21-single-post-mockup.html` first — it is the target output, built from `HOUSE-STYLE.md`. Colors and type come from there: navy `#1a2f5c`, ink `#111111`, body `#4a4a4a`, muted `#6b6b6b`, rule `#e6e3dc`, red `#a51d23`, Libre Franklin for everything except section marks and numerals, which are Newsreader italic.

**Do NOT reuse `PTK_Newsletter_Renderer::render_story_cards()`.** It is coupled to the Builder's block array, preview anchors, placeholder cards and email constraints. Share the *look*, not the method.

- [ ] **Step 1: Write the failing test** — cover, one assertion each: an empty parts array renders `''`; a headline renders inside an `<h1>`; the kicker renders above it and is omitted entirely when blank; two paragraphs render as two `<p>`s; a step list renders numbered; a date renders one callout; a button renders once with its text; `javascript:` never appears in output; and the same parts twice produce identical HTML (`render()` is deterministic).

- [ ] **Step 2: Run it and watch it fail**

- [ ] **Step 3: Implement `PTK_Post_Renderer::render( array $parts, $signoff = '' )`**

Returns a string of HTML with inline styles (the post must survive any school's theme). One `<h1>`, one callout at most, one button at most. Escape everything with the harness-safe helpers you write locally (`htmlspecialchars`), not WordPress's.

- [ ] **Step 4: Tests pass, suite silent**

- [ ] **Step 5: Commit** — `"Render a post from its parts"`

---

## Task 3: The picture, and the content hash

**Files:**
- Modify: `pta-knowledge-hub/includes/class-post-renderer.php`
- Modify: `pta-knowledge-hub/tests/test-post-renderer.php`

The renderer is pure, so it cannot call `wp_get_attachment_image_src()`. `render()` takes an optional `$picture` argument: `array( 'url' => …, 'alt' => …, 'fit' => 'crop'|'whole', 'position' => '50% 50%', 'zoom' => '' )` — the same shape `PTK_Search_Engine::format_result()` already produces, so the caller can reuse `PTK_Focal_Point`.

- [ ] **Step 1: Failing tests** — a picture renders a `<figure>` below the headline (never above it — see the spec); `fit: whole` renders `object-fit:contain`; `fit: crop` renders `object-position` from the framing; no picture renders no `<figure>`; the alt text is used.
- [ ] **Step 2: Watch it fail**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Add `PTK_Post_Renderer::hash( $html )`** — a plain `sha1()` over the rendered HTML, used later to notice a WordPress edit. Test: same HTML same hash, different HTML different hash.
- [ ] **Step 5: Tests pass, suite silent. Commit** — `"A post's picture, framed, and the hash that guards it"`

---

## Task 4: The words on the screens

**Files:**
- Create: `pta-knowledge-hub/includes/class-post-copy.php`
- Test: `pta-knowledge-hub/tests/test-post-copy.php`

Follow `includes/class-picture-copy.php` and `class-newsletters-copy.php` exactly: a pure class, one method per sentence.

- [ ] **Step 1: Failing test** — pin the screen title, the lead, the two buttons (*Put it on the website* / *Keep it to myself for now*), the confirmation line, the "written in WordPress" line, the hash-mismatch warning, the empty state, and a loop asserting none of `post type`, `meta`, `excerpt`, `trash`, `publish`, `WordPress post` appear in any of them. (The word "WordPress" alone IS allowed — "Open it in WordPress" is the honest label.)
- [ ] **Step 2: Watch it fail. Step 3: Write the class. Step 4: Pass.**
- [ ] **Step 5: Commit** — `"The words a single post screen says"`

---

## Task 5: The writing screen (render only)

**Files:**
- Create: `pta-knowledge-hub/includes/class-post-writer.php`
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php`
- Modify: `pta-knowledge-hub/includes/class-hub-look.php`

Copy the shape of `PTK_Content_Wizard::render_question_first_wizard()` — the card, the chips, the two buttons — and the gating of `PTK_Words_List::add_page()`: the page is registered ONLY when `PTK_Hub_Look::on()`.

- [x] **Step 1:** Register `ptk-post-writer` under `edit.php?post_type=pta_knowledge`, capability `edit_posts`. Add the slug to `PTK_Hub_Look::PAGES`. Update the assertion in `tests/test-hub-look.php` that says it is not a Hub screen yet.
- [x] **Step 2:** Render the card: kicker, headline, words, then the four chips (**+ a picture**, **+ steps**, **+ a date**, **+ a button**) and the blocks they reveal, hidden until used — exactly the progressive-enhancement pattern the wizard uses (real fields, works with JavaScript off).
- [x] **Step 3:** Wire **+ a picture** to `window.ptkPicturePicker.open({ frame: true, aspect: '16:9', onChoose })`, storing id/alt/focal/zoom/fit in hidden fields beside it. Reuse `assets/js/content-wizard.js`'s approach; do not write a second picker.
- [x] **Step 4: Verify in the browser** (Playground, `http://127.0.0.1:9406`, **127.0.0.1 never localhost**): the screen renders, every chip reveals its block, the picker opens and returns a picture. Nothing saves yet.
- [x] **Step 5: Commit** — `"The screen you write one post on"`

**Found doing Task 5 (matters for Task 12):** Simple mode's menu trim makes a Hub
screen that is NOT in `PTK_Simple_Mode::hub_task_submenu_slugs()` return a 403 --
core's `user_can_access_admin_page()` looks the page up in `$submenu`, which the
trim has emptied. This is not new and not ours: `ptk-search-analytics`, shipped and
linked from the home screen, 403s the same way for a Simple mode user today. So the
writing screen must either be in that list or be reached only with Simple mode off,
and the pre-existing hole is worth a fix of its own.

---

## Task 6: Saving — compose the post

**Files:**
- Modify: `pta-knowledge-hub/includes/class-post-writer.php`
- Modify: `pta-knowledge-hub/includes/class-share-settings.php`

- [x] **Step 1:** Add the sign-off option to `PTK_Share_Settings` (`const SIGNOFF_OPTION = 'ptk_post_signoff';`) with a field on that screen. **This is a new school-wide option**, not the newsletter's per-issue signoff — do not touch that one.
- [x] **Step 2:** Write `handle_submission()`: nonce + `current_user_can( 'edit_posts' )`; build parts with `PTK_Post_Parts::sanitize()`; refuse to save when `has_content()` is false, with the validation sentence from the copy class.
- [x] **Step 3:** Compose and save:
  - `post_title` = headline
  - `post_content` = `PTK_Post_Renderer::render( $parts, $signoff, $picture )`
  - `post_excerpt` = `PTK_Post_Parts::summary( $parts )`
  - `post_type` = `post`, status `publish` or `draft`
  - featured image = the chosen picture (`set_post_thumbnail`)
  - meta: the parts; **all five framing fields the picker returns** (`image_id`, `focalX`, `focalY`, `zoom`, `fit` — the same hidden fields Task 5 Step 3 put on the screen, not just the id); and `PTK_Post_Renderer::hash()` of what was rendered
- [x] **Step 4: Publishing rights.** Offer *Put it on the website* only when `current_user_can( 'publish_posts' )`; otherwise show only the draft button and the copy line that says who presses it. Never offer an action that will fail.
- [x] **Step 5: Verify in the browser end to end** — write a post with every chip used, publish it, then **open it on the front end** and confirm the headline, picture, callout, steps and button all render, and that the home page's Latest news row shows the summary. A saved record is not proof; the published page is.
- [x] **Step 6: Commit** — `"Save a post the Hub wrote"`

**Found doing Task 6:** two things the plan did not name.

1. **kses.** `wp_insert_post()` runs the content through `wp_kses_post` for
   anyone without `unfiltered_html` -- which on multisite is every school admin
   -- and that strips the inline styles this feature is made of. The save wraps
   the insert in `kses_remove_filters()` / `kses_init_filters()` (try/finally),
   the same trusted-write bypass `PTK_Newsletter_Builder::handle_save()` uses,
   and it is verified as a contributor: a draft saved by a user with
   `unfiltered_html = no` keeps `display:flex`.
2. **The sign-off field is part of the redesign**, so Newsletter settings draws
   it only while `PTK_Hub_Look::on()`, and the save only touches the option when
   the field was actually posted (otherwise a save with the look off would wipe
   a sign-off the school set with it on). The look-off render of that screen was
   diffed against `main`: identical, once the `<?php echo ?>` tag was written
   with no surrounding whitespace -- the indentation in front of it was 13 bytes
   of difference on its own.

---

## Task 7: The confirmation

**Files:** Modify `class-post-writer.php`, `class-post-copy.php`

- [x] Render a confirmation after saving, in the shape `PTK_Content_Wizard::render_question_first_confirmation()` uses: what happened, one stamp at most, and next steps — *See it the way families see it* · *Put it in the next newsletter* (the Builder's url) · *Write another*.
- [x] Verify in the browser. Commit — `"Say what happened after a post is saved"`

**Built as:** `render()` shows the confirmation instead of the form when
`ptk_post_saved` names a post that carries the parts AND that this person can
edit -- a contributor asking for somebody else's post gets the plain form and
sees nothing of it. The card is rebuilt from the saved parts, not from the
rendered HTML: that HTML is set for a 63-character measure and a 44px headline,
and squeezing it into an admin column would show somebody something that is not
what families will see. One stamp (ON THE WEBSITE / NOT ON THE WEBSITE YET), and
the first next step is the permalink when it is up, the preview link when it is
not -- never a dead link.

---

## Task 8: "Your posts"

**Files:**
- Create: `pta-knowledge-hub/includes/class-posts-list.php`
- Test: `pta-knowledge-hub/tests/test-posts-list.php`
- Modify: `class-hub-look.php`, `pta-knowledge-hub.php`, `tests/test-hub-look.php`

Build it from `class-newsletters-list.php` — same cards, same shape, no new CSS if the existing classes fit.

- [x] **Step 1: Failing test for the classification, as a PURE function** — `PTK_Posts_List::kind( $has_parts_meta, $has_newsletter_meta )` returns `'ours'`, `'wordpress'` or `'newsletter'`. Cases: parts only → ours; newsletter meta → newsletter (even if parts somehow exist); neither → wordpress.
- [x] **Step 2: Watch it fail. Step 3: Implement. Step 4: Pass.**
- [x] **Step 5:** The screen: slug `ptk-posts`, gated on the look, a card per post newest first. Per the spec:
  - **ours** → *Open it* (the writing screen) + *See what families see* + *Remove it*
  - **wordpress** → says it was written in WordPress; *Open it in WordPress*; **no Remove**
  - **newsletter** (`_ptk_linked_source_newsletter_id`) → **not listed at all**
- [x] **Step 6: Verify in the browser** with all three kinds present: write one here, write one in WordPress, and publish a newsletter with the linked-post setting on to generate the third. Confirm the third never appears.
- [x] **Step 7: Commit** — `"Your posts"`

**Built without *Remove it*:** that is Task 10, and a button that does nothing
is worse than one that is not there yet. Same reasoning the other way for
*Open it*, which points at `?ptk_post_edit_id=N` and only loads the post once
Task 9 lands.

**Verified with all three kinds really present:** a post written here, one
written in WordPress ("11/4 | ELECTION DAY BAKE SALE"), and a third made by
turning the linked-post setting on and saving a published newsletter, so the
plugin's own `save_post_pta_newsletter` hook created it. The newsletter's post
never appears on the screen; the other two do, with the right actions each.

---

## Task 9: Editing, and the guard

**Files:** Modify `class-post-writer.php`, `class-post-copy.php`

- [x] **Step 1:** `?ptk_post_edit_id=N` loads the parts back into the screen.
- [x] **Step 2: The guard.** Before loading, compare `PTK_Post_Renderer::hash( $post->post_content )` with the stored hash. On a mismatch, do not load the form: say plainly that the post was changed in WordPress, and offer *Open it in WordPress* and *Go back*. **Only the screen that owns a field may write it** — a 4.27.0 bug taught this the hard way, where an older form silently reset framing it did not know about. The precedent is `includes/class-content-wizard.php`, the `$framing_posted` guard in `handle_submission()` (commit `26d6563`); read it before writing this step.
- [x] **Step 3: Verify in the browser:** edit a Hub post here (works), then edit the same post in WordPress, come back, and confirm the warning appears and nothing is overwritten.
- [x] **Step 4: Commit** — `"Edit a post, and never overwrite work done elsewhere"`

**Three things this turned up:**

- The guard is checked **twice**: when the form opens, and again when it is
  saved. A form can sit open while somebody edits the post in WordPress, and
  only the second check catches that. Verified: with the form open and the post
  changed underneath it, saving showed the guard and wrote nothing -- the
  hand-made paragraph and the old title both survived.
- A refused save shows the **guard**, not the form with a message above it.
  Offering the button again would be offering to overwrite somebody's work.
- **A post already on the website is never taken down from here.** Editing one
  that is up shows only *Put it on the website*; "Keep it to myself for now"
  would quietly pull something families can already read. Taking one down is
  *Remove it*, where it can be undone.

Framing is read back from the post's own meta when editing, not rebuilt from
defaults -- the 4.27.0 lesson cuts both ways: a screen that does not own a
field must hand back what it was given.

---

## Task 10: Remove, with Undo

**Files:** Modify `class-posts-list.php`

- [x] Copy `PTK_Asked_For_List`'s trash/untrash: `admin-post.php` actions, per-item nonces, a "Removed. Undo" banner. **Note the 4.21.0 trap:** `wp_untrash_post()` restores to `draft`, so Undo must put the status back to what it was, or the card silently fails to return.
- [x] Verify in the browser: remove, undo, confirm the post returns with its previous status. Commit — `"Remove a post, and really undo it"`

**The 4.21.0 trap, avoided and pinned:** `status_after_undo()` is a pure
function with tests, and the pre-trash status is read from
`_wp_trash_meta_status` BEFORE untrashing -- `wp_untrash_post()` deletes that
meta as part of restoring, so afterwards there is nothing left to ask.
Verified both ways in the browser: a post that was on the website came back on
it, a draft came back a draft.

**Also checked:** a forged removal of a post written in WordPress is refused
("You don't have permission to remove that."), even for an administrator who
could delete it in WordPress -- this screen did not write it, so it does not
bin it.

---

## Task 11: The home screen branch

**Files:** Modify `class-welcome.php`, `class-post-copy.php`, `assets/css/hub.css`

- [ ] The "Tell families what's happening" intention becomes one card with two buttons, **"Just one thing" first and filled**, "This week's newsletter" beside it as the plain one. Meta line: "The weekly newsletter, or a single announcement on the website."
- [ ] Cards with two buttons already exist (the approvals screen) — reuse that markup rather than inventing a component.
- [ ] Verify in the browser at desktop and phone width. Commit — `"One intention, two ways out of it"`

---

## Task 12: Menu, and the look-off proof

**Files:** Modify `class-simple-mode.php`, `tests/test-simple-mode.php`

- [ ] Add `ptk-posts` to `hub_task_submenu_slugs( true )` — one menu item covers listing and writing, so the trimmed menu goes to eight. Update the test.
- [ ] Add both new slugs to the reorder list in `PTK_Welcome::reorder_menu()` so they do not pile up at the bottom.
- [ ] **The look-off proof, the release gate for every screen in this redesign:**
  1. Turn `ptk_hub_new_look` off at `/wp-admin/admin.php?page=ptk-share-settings`.
  2. Fetch Start Here, Create Entry, the Builder, `edit.php` (posts) and the front-end home page, normalizing `t.replace(/"time":"\d+"/g,'"time":"X"')` and `t.replace(/\d+\s+(second|min|minute|hour|day)s?\s+ago/g,'AGO')`.
  3. `git stash push -u -m proof -- <only your files, named explicitly>` — **never a bare `git stash`**.
  4. Re-fetch and compare byte-for-byte; none of your markers may appear.
  5. `git stash pop`.
  - **The front end is not gated by the look.** A post already published must render identically with the switch off — if it does not, that is a bug, not an expected difference.
- [ ] Commit — `"Put one thing on the website: menu, order, and the look-off proof"`

---

## Task 13: Release

- [ ] Bump `Version:` and `PTK_VERSION` in `pta-knowledge-hub/pta-knowledge-hub.php` (4.27.2 → 4.28.0).
- [ ] Prepend a changelog entry to `update-info.json` (`version`, `last_updated`, `changelog`), written for a volunteer, not a developer.
- [ ] Rebuild the zip:

```bash
cd "/Users/lucas/apps/PTA/PTA HUB" && rm -f pta-knowledge-hub.zip && zip -rq pta-knowledge-hub.zip pta-knowledge-hub -x "pta-knowledge-hub/tests/*" -x "*.DS_Store" -x "*/node_modules/*" -x "pta-knowledge-hub/.omc/*" -x "pta-knowledge-hub/.impeccable/*"
```

- [ ] Update the spec's status line to say what shipped, and add a "what this taught us" note if anything surprised you.
- [ ] Commit. **Do not push** unless the user asks.
