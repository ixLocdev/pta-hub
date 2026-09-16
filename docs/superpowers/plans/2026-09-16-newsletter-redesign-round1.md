# Newsletter Redesign, Round 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the newsletter the Builder publishes look like issue № 040 and the redesigned site: one navy announcement with a "when" line, timeline and button; "§ label" sections on white for the top story, stories and new quick notes; a masthead with the full "Newsletter № 041 · 2026–2027" eyebrow and a summary line; Coming up rows with weekdays; no doubled title. Old newsletters keep working.

**Architecture:** The data model gains fields and one block type, with the old announcement `pill` migrating inside the sanitizer so every reader of stored blocks sees the new shape. The inline-styled renderer is restyled block by block from #040. The reader's-date script learns to grey past rows. The Builder form gains the fields (its JS is already field-agnostic). Captions read the new fields. A four-line stylesheet hides the theme's own title. Nothing hooks `save_post`; nothing touches the theme template.

**Tech Stack:** PHP 7.4-compatible WordPress plugin, no build step. Plain-php tests plus one Node script. WordPress Playground for the Builder. The Browser pane for side-by-side comparison with #040.

**Spec:** `docs/superpowers/specs/2026-09-16-newsletter-redesign-round1-design.md`. Read it first; every inline style value is there.

**Review amendments (folded in 2026-09-16):** email-capable link inputs are `type="text" inputmode="url"` (Task 8); a 4.1.x text-only announcement puts its text in the headline slot (Tasks 4, 10); the one-time stale-caption warning is documented, not fixed (spec risks); #040 line citations corrected, and **#040 lines 400-504 are retired pre-redesign blocks — never copy styles from them**; every block type is deduped, first wins (Task 1); no `class_exists` guard for the data class in the renderer (Task 3); "§ More news" never repeats (Task 5). Where this plan and the spec disagree, the spec wins — say so rather than guessing.

**Where:** the git worktree `/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-redesign`, branch `newsletter-redesign`. Run everything from there. Do not `cd` to the parent repo and do not touch other branches.

---

## Before you start

**Test command, run after every task:**

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-redesign/pta-knowledge-hub" && for f in tests/test-*.php; do echo "== $f"; php "$f" || exit 1; done && node tests/test-relabel-js.mjs
```

All ten pass at the start (verified 2026-09-16). Tests are plain PHP scripts using `ptk_test_ok()` / `ptk_test_done()` from `tests/bootstrap.php`; they print `ok -` / `FAIL-` and exit 1 on any failure. Match `tests/test-newsletter-data.php` in style. The bootstrap's `wp_kses_post` shim keeps only `<a><strong><em><br><p>`, and `esc_url_raw` there mirrors WordPress's scheme allowlist — good enough for every assertion below.

**PHP 7.4:** your CLI is PHP 8.5; the sites are not. No `match`, `str_contains`, `str_starts_with`, arrow-typed properties, `readonly`, named arguments or nullsafe `?->`. `??` is fine.

**Playground (Tasks 8, 9, 11):**

```bash
npx --yes @wp-playground/cli@latest server --auto-mount "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-redesign/pta-knowledge-hub" --login --port 9400
```

Open **`http://127.0.0.1:9400`**, never `localhost:9400` — the site URL is `127.0.0.1`, so `localhost` makes every admin-ajax call cross-origin and the live preview dies silently.

**Commit rule:** every task ends in a commit whose message says why, not what, and ends with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`. Tests pass at every commit.

## File structure

| File | Change |
|---|---|
| `pta-knowledge-hub/includes/class-newsletter-data.php` | New type, new fields, `pill`→`when` migration, `school_year_label()`, `sanitize_link_url()`, `timeline_states()` |
| `pta-knowledge-hub/tests/test-newsletter-data.php` | Tests for the above |
| `pta-knowledge-hub/tests/test-newsletter-dates.php` | Tests for `school_year_label()` and `timeline_states()` |
| `pta-knowledge-hub/includes/class-newsletter-renderer.php` | Fonts, palette, every block restyled, `render_quick_notes()`, `section_rule()` |
| `pta-knowledge-hub/tests/test-newsletter-renderer.php` | Updated and new assertions; the #040 fixture render |
| `pta-knowledge-hub/tests/fixtures/newsletter-040-blocks.json` (new) | #040 as Builder blocks |
| `pta-knowledge-hub/assets/js/newsletter-relabel.js` | Past-row fading for events and timeline rows; `ptkIsPast()` |
| `pta-knowledge-hub/tests/test-relabel-js.mjs` | Cases for `ptkIsPast()` |
| `pta-knowledge-hub/includes/class-newsletter-builder.php` | Labels, intros, step map, the new fields and the Quick notes section |
| `pta-knowledge-hub/assets/js/newsletter-builder.js` | Fonts in the preview iframe; open a disclosure that has rows |
| `pta-knowledge-hub/assets/css/newsletter-builder.css` | The disclosure's look |
| `pta-knowledge-hub/includes/class-share-text.php` | Announcement headline/when; quick-note lines |
| `pta-knowledge-hub/tests/test-share-text.php` | Tests for the above |
| `pta-knowledge-hub/assets/css/newsletter-public.css` (new) | Hides the theme's title |
| `pta-knowledge-hub/includes/class-newsletter-post-type.php` | Enqueues that stylesheet and the fonts |
| `pta-knowledge-hub/pta-knowledge-hub.php`, `update-info.json`, `pta-knowledge-hub.zip` | 4.2.0 |

---

### Task 1: The data model — new fields, the new type, and the `pill` migration

Pure PHP, fully testable. Everything else in this plan reads what this task defines.

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-data.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-data.php`

- [ ] **Step 1: Write the failing tests** (append before `ptk_test_done()`)

```php
// --- 4.2.0 shape: new fields, quick notes, and the pill -> when migration. ---
$d = 'PTK_Newsletter_Data';

$defaults = $d::default_blocks();
$dtypes   = array_column( $defaults, 'type' );
ptk_test_ok( in_array( 'quick_notes', $dtypes, true ), 'default layout includes quick notes' );
ptk_test_ok( array_search( 'quick_notes', $dtypes, true ) === array_search( 'story_cards', $dtypes, true ) + 1, 'quick notes sits right after stories' );
ptk_test_ok( end( $dtypes ) === 'footer', 'footer is still last' );
$dh = $defaults[ array_search( 'header', $dtypes, true ) ]['data'];
ptk_test_ok( array_key_exists( 'summary', $dh ), 'header default has a summary key' );
$da = $defaults[ array_search( 'announcement', $dtypes, true ) ]['data'];
foreach ( array( 'when', 'headline', 'text', 'button_text', 'button_url', 'timeline' ) as $k ) {
    ptk_test_ok( array_key_exists( $k, $da ), "announcement default has $k" );
}
ptk_test_ok( ! array_key_exists( 'pill', $da ), 'announcement default no longer has pill' );

// A literal 4.1.x newsletter, exactly as the old plugin saved it.
$old = array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'headline' => '', 'greeting' => 'Hi' ) ),
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thursday · Jun 25', 'text' => 'Last day of school.' ) ),
    array( 'type' => 'featured', 'data' => array( 'eyebrow' => 'Year in review', 'headline' => 'What a year', 'body' => 'Thanks', 'image_id' => 3 ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array( array( 'heading' => 'Mum Sale', 'body' => 'Open', 'image_id' => 0, 'link_url' => 'mailto:x@y.org', 'link_text' => 'Email' ) ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => 'Bye', 'links' => array() ) ),
);
$mig   = $d::sanitize_blocks( $old );
$mtype = array_column( $mig, 'type' );
$ma    = $mig[ array_search( 'announcement', $mtype, true ) ]['data'];
ptk_test_ok( $ma['when'] === 'Thursday · Jun 25', 'old pill becomes the when line' );
ptk_test_ok( ! isset( $ma['pill'] ), 'pill key is not carried forward' );
ptk_test_ok( $ma['headline'] === '' && $ma['button_text'] === '' && $ma['button_url'] === '' && $ma['timeline'] === array(), 'new announcement keys default blank' );
ptk_test_ok( $mig[ array_search( 'header', $mtype, true ) ]['data']['summary'] === '', 'old header gains a blank summary' );
$mf = $mig[ array_search( 'featured', $mtype, true ) ]['data'];
ptk_test_ok( $mf['eyebrow'] === 'Year in review' && $mf['image_id'] === 3 && $mf['link_url'] === '' && $mf['link_text'] === '', 'old featured keeps its values and gains blank link keys' );
$mc = $mig[ array_search( 'story_cards', $mtype, true ) ]['data']['cards'][0];
ptk_test_ok( $mc['eyebrow'] === '' && $mc['link_url'] === 'mailto:x@y.org', 'old card gains a blank eyebrow and keeps a mailto link' );
ptk_test_ok( ! in_array( 'quick_notes', $mtype, true ), 'sanitize does not invent a quick notes block' );

// When both keys arrive (a tab open across the update), the new key wins.
$both = $d::sanitize_blocks( array( array( 'type' => 'announcement', 'data' => array( 'pill' => 'old', 'when' => 'new', 'text' => '' ) ) ) );
ptk_test_ok( $both[1]['data']['when'] === 'new', 'when wins over pill when both are posted' );

// Timeline rows and links.
$ann = $d::sanitize_blocks( array( array( 'type' => 'announcement', 'data' => array(
    'headline'    => 'ASE <b>opens</b>',
    'button_text' => 'Go',
    'button_url'  => 'javascript:alert(1)',
    'timeline'    => array(
        array( 'date' => '2026-09-14', 'time' => '8:30 AM–12:30 PM', 'what' => 'Members only' ),
        array( 'date' => 'nope', 'time' => array( 'x' ), 'what' => '' ),
        'junk',
    ),
) ) ) );
$aa = $ann[1]['data'];
ptk_test_ok( $aa['headline'] === 'ASE opens', 'announcement headline is plain text' );
ptk_test_ok( $aa['button_url'] === '', 'a javascript: button link is blanked' );
ptk_test_ok( count( $aa['timeline'] ) === 2 && $aa['timeline'][0]['time'] === '8:30 AM–12:30 PM', 'timeline rows are kept in order, non-arrays dropped' );
ptk_test_ok( $aa['timeline'][1]['date'] === '' && $aa['timeline'][1]['time'] === '', 'bad date and array time become empty strings' );

ptk_test_ok( $d::sanitize_link_url( ' https://x.test/a ' ) === 'https://x.test/a', 'http url: trimmed and kept' );
ptk_test_ok( $d::sanitize_link_url( 'HTTP://x.test' ) !== '', 'http url: an upper-case scheme is still a web address' );
ptk_test_ok( $d::sanitize_link_url( 'mailto:a@b.org' ) === 'mailto:a@b.org', 'link url: an email link is kept (#040 uses one)' );
ptk_test_ok( $d::sanitize_link_url( 'leslie@example.org' ) === 'mailto:leslie@example.org', 'link url: a bare email address becomes an email link' );
ptk_test_ok( $d::sanitize_link_url( 'data:text/html,x' ) === '', 'link url: data is rejected' );
ptk_test_ok( $d::sanitize_link_url( 'javascript:alert(1)' ) === '', 'http url: javascript is rejected' );
ptk_test_ok( $d::sanitize_link_url( array( 'x' ) ) === '', 'http url: array becomes empty, no warning' );
// Not asserted: a bare "x.test/page". Real esc_url_raw() prepends "http://"
// to a scheme-less address, so production KEEPS it; the test shim does not,
// so an assertion either way would describe the wrong environment.

$qn = $d::sanitize_blocks( array( array( 'type' => 'quick_notes', 'data' => array(
    'label' => 'Good to <i>know</i>',
    'items' => array(
        array( 'heading' => 'Lunch menu', 'body' => 'On the <strong>site</strong>. <script>x</script>', 'link_url' => 'https://x.test/lunch', 'link_text' => 'See the menu' ),
        array( 'heading' => array( 'x' ), 'body' => '', 'link_url' => 'ftp://x', 'link_text' => '' ),
    ),
) ) ) );
$qd = $qn[1]['data'];

// Only one of each section type: first one wins, like PTK_Share_Text::generate() reads them.
$dup = $d::sanitize_blocks( array(
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'First' ) ),
    array( 'type' => 'announcement', 'data' => array( 'headline' => 'Second' ) ),
) );
$dup_types = array_column( $dup, 'type' );
ptk_test_ok( count( array_keys( $dup_types, 'announcement', true ) ) === 1, 'two announcements become one' );
ptk_test_ok( $dup[ array_search( 'announcement', $dup_types, true ) ]['data']['headline'] === 'First', 'the first announcement is the one kept' );
ptk_test_ok( $qn[1]['type'] === 'quick_notes', 'quick notes is a known type' );
ptk_test_ok( $qd['label'] === 'Good to know', 'quick notes label is plain text' );
ptk_test_ok( strpos( $qd['items'][0]['body'], '<strong>' ) !== false && strpos( $qd['items'][0]['body'], '<script>' ) === false, 'note body keeps safe html, drops scripts' );
ptk_test_ok( $qd['items'][1]['heading'] === '' && $qd['items'][1]['link_url'] === '', 'note: array heading and ftp link become empty' );
```

- [ ] **Step 2: Run it and watch it fail**

`php tests/test-newsletter-data.php` → the first new assertion fails ("default layout includes quick notes"), then a fatal on `sanitize_link_url`. That is the expected shape of failure.

- [ ] **Step 3: Implement**

In `class-newsletter-data.php`:

1. Add `const TYPE_QUICK_NOTES = 'quick_notes';` beside the others (`:19-24`) and list it in `known_types()` (`:31-40`).
2. `default_blocks()` (`:48-94`): header data gains `'summary' => ''` (after `headline`); announcement data becomes `array( 'when' => '', 'headline' => '', 'text' => '', 'button_text' => '', 'button_url' => '', 'timeline' => array() )`; featured gains `'link_url' => '', 'link_text' => ''`; insert `array( 'type' => self::TYPE_QUICK_NOTES, 'data' => array( 'label' => '', 'items' => array() ) )` **between** `story_cards` and `footer`.
3. `sanitize_block_data()` (`:172-247`):
   - header: add `'summary' => sanitize_text_field( self::str_field( $data['summary'] ?? '' ) )`.
   - announcement: replace the two-key array with the six keys. The migration line is
     `$when = isset( $data['when'] ) ? $data['when'] : ( isset( $data['pill'] ) ? $data['pill'] : '' );`
     — `isset`, not `empty`, so a posted-but-blank `when` still wins over an old `pill` (the test "when wins over pill" covers the non-blank case; the blank case is the one a volunteer hits when they clear the field). Timeline rows: loop like events, each `array( 'date' => self::sanitize_date(...), 'time' => sanitize_text_field(...), 'what' => sanitize_text_field(...) )`, skipping non-arrays. `button_url` through `self::sanitize_link_url()`.
   - featured: add `link_url` (`esc_url_raw`) and `link_text`.
   - story_cards: add `'eyebrow' => sanitize_text_field(...)` as the first key of each card.
   - new `case self::TYPE_QUICK_NOTES:` → `label` + `items[]` with `heading` / `body` (`wp_kses_post`) / `link_url` (`sanitize_link_url`) / `link_text`.
4. Add the helper, `public static` so the Builder and tests can call it:

```php
    /**
     * A link a volunteer typed for a button or a quick note: a web address
     * (http or https) or an email link. A bare email address becomes an email
     * link, so nobody has to know the word "mailto". Anything else --
     * javascript:, data:, junk -- becomes '' rather than a link that surprises.
     */
    public static function sanitize_link_url( $url ) {
        $url = trim( self::str_field( $url ) );
        if ( preg_match( '/^[^@\s:\/]+@[^@\s\/]+\.[^@\s\/]+$/', $url ) ) {
            $url = 'mailto:' . $url;
        }
        $url = esc_url_raw( $url );
        return preg_match( '#^(https?://|mailto:)#i', $url ) ? $url : '';
    }
```

5. `sanitize_blocks()`: today only header and footer are deduped. Dedupe **every** type, first one wins — this is what actually guarantees "only one navy band".

**Trap:** `sanitize_block_data()` is `protected` and the test calls `sanitize_blocks()` — keep it that way; do not make the whole switch public just to test one branch.

- [ ] **Step 4: Run it and watch it pass**

`php tests/test-newsletter-data.php` → `PASSED`. Then the full test command: `tests/test-newsletter-renderer.php` still passes (it never asserts on `pill`), and so does `tests/test-share-text.php` (its fixture posts `pill`, which `generate()` still reads until Task 10).

- [ ] **Step 5: Commit**

```bash
git add pta-knowledge-hub/includes/class-newsletter-data.php pta-knowledge-hub/tests/test-newsletter-data.php
git commit -m "Give the announcement a headline, a when line, a button and dates; add quick notes

The old short label moves into the new When line inside the sanitizer, so
every reader of stored blocks -- builder, preview, save, share panel -- sees
4.1.x newsletters in the new shape without a migration script.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: The school year and the timeline states

Two more pure functions the renderer needs. Kept out of Task 1 so each commit does one thing.

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-data.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-dates.php`

- [ ] **Step 1: Write the failing tests** (append before `ptk_test_done()`)

```php
// school_year_label(): August-July. Spec Decision 1.
foreach ( array(
    '2026-09-13' => '2026–2027', // #040
    '2026-08-01' => '2026–2027', // August starts the new year
    '2026-07-31' => '2025–2026', // July closes the old one
    '2026-06-22' => '2025–2026', // #038
    '2027-01-05' => '2026–2027',
    '2026-12-31' => '2026–2027',
) as $date => $want ) {
    ptk_test_ok( PTK_Newsletter_Data::school_year_label( $date ) === $want, "school year for $date is $want" );
}
ptk_test_ok( PTK_Newsletter_Data::school_year_label( '' ) === '', 'blank date -> blank school year' );
ptk_test_ok( PTK_Newsletter_Data::school_year_label( 'nope' ) === '', 'garbage date -> blank school year' );

// timeline_states(): past by whole day, last row is the deadline. Spec Decisions 2 and 3.
$rows = array(
    array( 'date' => '2026-09-14', 'time' => '8:30 AM', 'what' => 'Opens' ),
    array( 'date' => '2026-09-17', 'time' => 'noon',    'what' => 'Closes' ),
);
$s = PTK_Newsletter_Data::timeline_states( $rows, '2026-09-13' );
ptk_test_ok( $s === array( array( 'past' => false, 'deadline' => false ), array( 'past' => false, 'deadline' => true ) ), 'before: nothing past, last row is the deadline' );
$s = PTK_Newsletter_Data::timeline_states( $rows, '2026-09-14' );
ptk_test_ok( $s[0]['past'] === false, 'the day itself is not past' );
$s = PTK_Newsletter_Data::timeline_states( $rows, '2026-09-18' );
ptk_test_ok( $s[0]['past'] === true && $s[1]['past'] === true && $s[1]['deadline'] === true, 'after: everything past, deadline still marked' );
$s = PTK_Newsletter_Data::timeline_states( array( array( 'date' => '', 'time' => 'TBA', 'what' => 'x' ) ), '2026-09-18' );
ptk_test_ok( $s === array( array( 'past' => false, 'deadline' => true ) ), 'a row with no date is never past' );
ptk_test_ok( PTK_Newsletter_Data::timeline_states( array(), '2026-09-18' ) === array(), 'no rows, no states' );
```

- [ ] **Step 2: Run it and watch it fail** — fatal on the undefined method.

- [ ] **Step 3: Implement** (both `public static`, near `issue_week_monday()`):

```php
    /**
     * "2026–2027" for an issue date. The school year runs August to July:
     * months 8-12 belong to Y–(Y+1), months 1-7 to (Y-1)–Y. A June issue
     * is the year that is ending; an August one is the year about to start.
     *
     * @return string '' when the date is not valid.
     */
    public static function school_year_label( $date ) {
        $dt = self::parse_date( $date );
        if ( ! $dt ) {
            return '';
        }
        $y = (int) $dt->format( 'Y' );
        $m = (int) $dt->format( 'n' );
        $start = ( $m >= 8 ) ? $y : $y - 1;
        return $start . "\xe2\x80\x93" . ( $start + 1 );
    }

    /**
     * Per-row display state for an announcement timeline: past (its day is
     * over, the same rule the event tags use) and deadline (the last row as
     * entered -- rows are never sorted; the volunteer said which is last).
     *
     * @return array[] One array( 'past' => bool, 'deadline' => bool ) per row.
     */
    public static function timeline_states( array $rows, $today ) {
        $states = array();
        $last   = count( $rows ) - 1;
        foreach ( array_values( $rows ) as $i => $row ) {
            $date = is_array( $row ) && isset( $row['date'] ) ? $row['date'] : '';
            $states[] = array(
                'past'     => 'past' === self::relabel_for_date( $date, $today ),
                'deadline' => $i === $last,
            );
        }
        return $states;
    }
```

**Trap:** the en dash is written as a UTF-8 byte string so the file's encoding can never bite; `esc_html()` leaves it alone.

- [ ] **Step 4: Run it and watch it pass**, then the full test command.

- [ ] **Step 5: Commit** — `"Work out the school year from the issue date, and which timeline rows are past"`.

---

### Task 3: Renderer — fonts, palette, masthead, Coming up

The first half of the restyle: everything that does not depend on the new blocks.

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-renderer.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: Update the tests that will rightly break, and add the new ones**

In `tests/test-newsletter-renderer.php`:
- Line 65: `strpos( $empty_html, 'Upcoming' ) === false` → `strpos( $empty_html, 'Coming up' ) === false`.
- Line 86: `strpos( $full_html, 'Upcoming' ) !== false` → `strpos( $full_html, 'Coming up' ) !== false`.

Append (before the "Week of" loop is fine, or at the end before `ptk_test_done()`):

```php
// --- 4.2.0 masthead and Coming up. ----------------------------------------
$m = PTK_Newsletter_Renderer::render(
    array( array( 'type' => 'header', 'data' => array( 'school_name' => 'NE', 'headline' => '', 'summary' => 'ASE registration is open this week', 'greeting' => 'Hi' ) ) ),
    array( 'issue' => 41, 'date' => '2026-09-20', 'today' => '2026-09-20', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' )
);
ptk_test_ok( strpos( $m, 'Newsletter&nbsp;&#8470;&nbsp;041 · 2026–2027' ) !== false, 'masthead eyebrow reads "Newsletter № 041 · 2026–2027"' );
ptk_test_ok( strpos( $m, 'ASE registration is open this week' ) !== false, 'summary line renders under the date' );
ptk_test_ok( strpos( $m, "'Libre Franklin'" ) !== false && strpos( $m, "'Inter'" ) === false, 'Libre Franklin replaces Inter' );
ptk_test_ok( strpos( $m, 'Fraunces' ) === false, 'Fraunces is gone' );
ptk_test_ok( substr_count( $m, '<h1' ) === 1, 'exactly one h1' );
$m2 = PTK_Newsletter_Renderer::render(
    array( array( 'type' => 'header', 'data' => array( 'school_name' => 'NE', 'headline' => '', 'summary' => '', 'greeting' => '' ) ) ),
    array( 'issue' => '', 'date' => '', 'today' => '2026-09-20', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' )
);
ptk_test_ok( strpos( $m2, '&#8470;' ) === false && strpos( $m2, '2026' ) === false, 'no issue and no date: no eyebrow at all' );

$ev = PTK_Newsletter_Renderer::render(
    array( array( 'type' => 'events', 'data' => array( 'rows' => array(
        array( 'date' => '2026-09-14', 'title' => 'ASE registration opens', 'desc' => 'Members first' ),
        array( 'date' => '2026-09-10', 'title' => 'Already happened', 'desc' => '' ),
    ) ) ) ),
    array( 'issue' => 41, 'date' => '2026-09-13', 'today' => '2026-09-13', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' )
);
ptk_test_ok( strpos( $ev, '§ Coming up' ) !== false, 'events open with the § Coming up rule' );
ptk_test_ok( strpos( $ev, ">What's coming up<" ) !== false, 'events heading is "What\'s coming up"' );
ptk_test_ok( strpos( $ev, '>Monday<' ) !== false, 'weekday renders under the numeral' );
ptk_test_ok( strpos( $ev, 'data-event-row' ) !== false && strpos( $ev, 'data-event-numeral' ) !== false, 'rows and numerals carry hooks for the relabel script' );
ptk_test_ok( strpos( $ev, '#a51d23' ) === false, 'a past date is never red' );
ptk_test_ok( strpos( $ev, 'opacity:0.45' ) !== false, 'a past row is faded' );
ptk_test_ok( strpos( $ev, 'border-top:1px solid #111' ) === false, 'no strong line on the first row; the § rule is the strong line' );
ptk_test_ok( strpos( $ev, 'lining-nums tabular-nums' ) !== false, 'numerals use lining figures' );
```

- [ ] **Step 2: Run and watch the new assertions fail** (the fonts and eyebrow ones first).

- [ ] **Step 3: Implement**, per the spec's "Masthead" and "Coming up" blocks:

1. `FONT_SANS` / `FONT_SERIF` (`:49-50`) → the Libre Franklin / Newsreader stacks. Add `'on_navy' => '#cfd8e3', 'small' => '#6b6b6b', 'chip' => '#f0eee7'` to `PALETTE`.
2. Add the shared `§` rule helper (**not** named `render_…`, see `:72-74`):

```php
    /** The "§ Coming up" mark and its ink line, which opens every white section. */
    private static function section_rule( $mark, $margin_bottom = '16px' ) {
        return '<div style="display:flex;align-items:center;gap:18px;margin-bottom:' . esc_attr( $margin_bottom ) . ';">'
            . '<div style="font-family:' . self::FONT_SERIF . ';font-style:italic;font-weight:500;font-size:15px;color:' . esc_attr( self::PALETTE['primary'] ) . ';white-space:nowrap;">&#167; ' . esc_html( $mark ) . '</div>'
            . '<div style="flex:1;height:1px;background:' . esc_attr( self::PALETTE['text'] ) . ';min-width:20px;"></div>'
            . '</div>';
    }
```

   `&#167;` is `§`; the test looks for the literal `§ Coming up`, so emit the character itself (`'§ '`) rather than the entity, or the assertion must use the entity — pick the character, it is what #040 does.

3. `render_header()`: read `summary`; build the eyebrow as
   `'Newsletter&nbsp;&#8470;&nbsp;' . esc_html( issue_label ) . ( '' !== $year ? ' · ' . esc_html( $year ) : '' )` where `$year = PTK_Newsletter_Data::school_year_label( $date )`; only emit it when the issue is non-blank (the `&#8470;&nbsp;039` substring the older test wants is preserved). Wrap the logo+school-name in the two-level flex from the spec (the outer `justify-content:space-between` row is the round-2 hook). Emit the summary `div` after the date `div`, only when non-blank.
4. `render_events()`: outer padding `40px 20px 40px`; emit `section_rule( 'Coming up', '28px' )` then the `h2` "What's coming up"; every row `border-top: hairline`; add `data-event-row` on the row and `data-event-numeral` on the numeral; the weekday `div` from `$dt->format('l')` (extend `format_event_date()` or add `format_event_weekday()`); past rows: numeral color `PALETTE['small']`, row style gains `opacity:0.45;`; delete the `emphasis` branch at `:222`. `lining-nums tabular-nums` on the numeral.

**No `class_exists( 'PTK_Newsletter_Data' )` guard** for `school_year_label()`. The renderer already calls `PTK_Newsletter_Data::issue_week_monday()` unguarded, so guarding only the new call protects nothing: the renderer depends on the data class. Say so in its docblock rather than pretend otherwise.

**Trap:** the tests match literal characters — `§`, `·`, the en dash in `2026–2027`, and the straight apostrophe in `What's coming up`. Emit those characters themselves in the PHP source (the files are UTF-8), not `&#167;`, `&middot;`, `&ndash;` or `&#8217;`. The one entity that stays is `&#8470;` for №, because an older test already asserts on it.

- [ ] **Step 4: Run and watch it pass**, then the full test command.

- [ ] **Step 5: Commit** — `"Set the masthead and Coming up in the site's fonts, with the full issue line and weekdays"`.

---

### Task 4: Renderer — the announcement is the one navy callout

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-renderer.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: Write the failing tests** (append)

```php
// --- 4.2.0 announcement: when line, headline, text, timeline, button. -------
$an_opts = array( 'issue' => 40, 'date' => '2026-09-13', 'today' => '2026-09-15', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE' );
$an = PTK_Newsletter_Renderer::render( array( array( 'type' => 'announcement', 'data' => array(
    'when'        => 'Opens Monday, Sept 14',
    'headline'    => 'ASE registration opens Monday.',
    'text'        => 'Twelve classes for grades K–5.',
    'button_text' => 'Go to ASE registration',
    'button_url'  => 'https://app.givebacks.gives/c691c4',
    'timeline'    => array(
        array( 'date' => '2026-09-14', 'time' => '8:30 AM–12:30 PM', 'what' => 'PTA members only' ),
        array( 'date' => '2026-09-17', 'time' => '12:00 noon', 'what' => 'Registration closes' ),
    ),
) ) ), $an_opts );
ptk_test_ok( substr_count( $an, 'background:#1a2f5c' ) === 1, 'the announcement is one navy fill' );
ptk_test_ok( strpos( $an, 'color:#ffd166' ) !== false && strpos( $an, 'Opens Monday, Sept 14' ) !== false, 'the when line is yellow' );
ptk_test_ok( strpos( $an, '<h2' ) !== false && strpos( $an, 'color:#ffffff;max-width:720px;">ASE registration opens Monday.' ) !== false, 'the headline is a white h2' );
ptk_test_ok( strpos( $an, 'color:#cfd8e3' ) !== false, 'the text is on-navy grey' );
ptk_test_ok( substr_count( $an, 'data-timeline-date="' ) === 2, 'two timeline rows carry their date' );
ptk_test_ok( substr_count( $an, 'data-timeline-deadline' ) === 1 && preg_match( '/data-timeline-date="2026-09-17" data-timeline-deadline/', $an ) === 1, 'the last row, and only it, is the deadline' );
ptk_test_ok( strpos( $an, 'Mon, Sep 14' ) !== false, 'timeline date reads "Mon, Sep 14"' );
ptk_test_ok( preg_match( '/data-timeline-date="2026-09-14"[^>]*opacity:0\.45/', $an ) === 1, 'a past timeline row is faded on the server' );
ptk_test_ok( preg_match( '/data-timeline-date="2026-09-17"[^>]*opacity/', $an ) === 0, 'a future row is not faded' );
ptk_test_ok( strpos( $an, 'background:#ffffff;color:#1a2f5c' ) !== false && strpos( $an, '>Go to ASE registration<' ) !== false, 'the button is white on navy' );
ptk_test_ok( strpos( $an, 'rgba(255,255,255,0.18)' ) !== false, 'timeline hairlines are translucent white' );
ptk_test_ok( strpos( $an, 'border-left' ) === false && strpos( $an, 'border-right' ) === false, 'no one-sided borders' );

$an_min = PTK_Newsletter_Renderer::render( array( array( 'type' => 'announcement', 'data' => array( 'when' => '', 'headline' => 'Just a headline', 'text' => '', 'button_text' => 'Go', 'button_url' => '', 'timeline' => array() ) ) ), $an_opts );
ptk_test_ok( strpos( $an_min, 'Just a headline' ) !== false && strpos( $an_min, '>Go<' ) === false && strpos( $an_min, 'border-top:1px solid rgba' ) === false, 'headline alone renders; no button without a link; no empty timeline' );
// A migrated 4.1.x announcement: text only. The text takes the headline slot; no empty h2, no lone paragraph.
$an_old = PTK_Newsletter_Renderer::render( array( array( 'type' => 'announcement', 'data' => array( 'when' => 'Thursday · Jun 25', 'headline' => '', 'text' => '<p>Last <strong>day</strong> of school.</p>', 'button_text' => '', 'button_url' => '', 'timeline' => array() ) ) ), $an_opts );
ptk_test_ok( preg_match( '/<h2[^>]*>Last day of school\.<\/h2>/', $an_old ) === 1, 'old text-only announcement: the text becomes the headline, tags stripped' );
ptk_test_ok( strpos( $an_old, 'color:#cfd8e3' ) === false, 'old text-only announcement: no separate paragraph' );
$an_none = PTK_Newsletter_Renderer::render( array( array( 'type' => 'announcement', 'data' => array( 'when' => '', 'headline' => '', 'text' => '', 'button_text' => '', 'button_url' => '', 'timeline' => array( array( 'date' => '', 'time' => '', 'what' => '' ) ) ) ) ), $an_opts );
ptk_test_ok( strpos( $an_none, 'data-ptk-block="announcement"' ) === false, 'all-blank announcement (blank timeline row included) renders nothing' );
```

- [ ] **Step 2: Run and watch it fail.**

- [ ] **Step 3: Rewrite `render_announcement()`** per the spec block. Skeleton:

```php
    private static function render_announcement( array $data, array $opts ) {
        $when     = isset( $data['when'] ) ? self::str( $data['when'] ) : '';
        $headline = isset( $data['headline'] ) ? self::str( $data['headline'] ) : '';
        $text     = isset( $data['text'] ) ? self::str( $data['text'] ) : '';
        $btn_text = isset( $data['button_text'] ) ? self::str( $data['button_text'] ) : '';
        $btn_url  = isset( $data['button_url'] ) ? self::str( $data['button_url'] ) : '';
        $today    = isset( $opts['today'] ) ? self::str( $opts['today'] ) : '';

        $rows = array();
        foreach ( ( isset( $data['timeline'] ) && is_array( $data['timeline'] ) ? $data['timeline'] : array() ) as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $r = array(
                'date' => isset( $row['date'] ) ? self::str( $row['date'] ) : '',
                'time' => isset( $row['time'] ) ? self::str( $row['time'] ) : '',
                'what' => isset( $row['what'] ) ? self::str( $row['what'] ) : '',
            );
            if ( '' === trim( $r['date'] . $r['time'] . $r['what'] ) ) { continue; }
            $rows[] = $r;
        }
        $has_button = '' !== trim( $btn_text ) && '' !== trim( $btn_url );

        if ( '' === trim( $when ) && '' === trim( $headline ) && '' === trim( $text ) && ! $has_button && empty( $rows ) ) {
            return self::placeholder( 'announcement', 'Your announcement will appear here.', $opts );
        }
        // A 4.1.x announcement has only text: show it, tags stripped, as the headline.
        if ( '' === trim( $headline ) && '' !== trim( $text ) ) {
            $headline = trim( wp_strip_all_tags( $text ) );
            $text     = '';
        }
        // ... build per the spec; timeline states from PTK_Newsletter_Data::timeline_states( $rows, $today )
    }
```

   Row markup: `<div data-timeline-date="…"` + ` data-timeline-deadline` on the last + inline style, with ` opacity:0.45;` appended to the style when past. The date cell uses `DateTime::createFromFormat( '!Y-m-d', … )->format( 'D, M j' )`, falling back to the raw string (blank date → empty cell, the `flex:0 0 190px` still holds the column). The detail cell joins `<strong>{time}</strong>` and `{what}` with ` · ` only when both are non-blank.

**Trap:** the test `preg_match( '/data-timeline-date="2026-09-14"[^>]*opacity:0\.45/' )` needs the style attribute on the **same element** as `data-timeline-date`, after it. Put the data attributes first, then `style="…"`.

- [ ] **Step 4: Run and watch it pass** (the old `'Bake sale today'` / `'Last day'` announcement tests keep passing because `text` still renders). Full test command.

- [ ] **Step 5: Commit** — `"Make the announcement the newsletter's one navy callout, with a when line, dates and a button"`.

---

### Task 5: Renderer — top story, stories and quick notes on white

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-renderer.php`
- Modify: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: Write the failing tests** (append)

```php
// --- 4.2.0 stories on white, with § labels; quick notes. --------------------
$st_opts = array( 'issue' => 40, 'date' => '2026-09-13', 'today' => '2026-09-13', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'NE',
    'image_url_cb' => function ( $id ) { return 'https://x.test/img-' . $id . '.jpg'; } );
$st = PTK_Newsletter_Renderer::render( array(
    array( 'type' => 'featured', 'data' => array( 'eyebrow' => 'ASE volunteers', 'headline' => 'Can you help on Tuesdays?', 'body' => '<p>Free class.</p>', 'image_id' => 7, 'link_url' => 'https://x.test/v', 'link_text' => 'Email Leslie' ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
        array( 'eyebrow' => 'Date change', 'heading' => 'Film on the Field moves.', 'body' => 'Oct 16.', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
        array( 'eyebrow' => '', 'heading' => 'Second story', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    ) ) ),
    array( 'type' => 'quick_notes', 'data' => array( 'label' => 'Good to know', 'items' => array(
        array( 'heading' => 'Lunch menu', 'body' => 'On the site.', 'link_url' => 'https://x.test/lunch', 'link_text' => 'See the menu' ),
        array( 'heading' => 'Handbook', 'body' => '', 'link_url' => '', 'link_text' => '' ),
    ) ) ),
), $st_opts );
ptk_test_ok( strpos( $st, 'background:#1a2f5c' ) === false, 'no navy band among the stories' );
ptk_test_ok( strpos( $st, '#f6f4ef' ) === false && strpos( $st, 'border-radius:14px' ) === false, 'no beige card boxes' );
ptk_test_ok( strpos( $st, '§ ASE volunteers' ) !== false, 'top story uses its label as the § mark' );
ptk_test_ok( strpos( $st, '§ Date change' ) !== false, 'a story uses its label as the § mark' );
ptk_test_ok( strpos( $st, '§ More news' ) !== false, 'a story with no label falls back to "More news"' );
ptk_test_ok( strpos( $st, '§ Good to know' ) !== false, 'quick notes use the group label' );
ptk_test_ok( substr_count( $st, '<h2' ) === 3, 'top story and each story are h2s' );
ptk_test_ok( substr_count( $st, '<h3' ) === 2, 'quick-note items are h3s' );
ptk_test_ok( strpos( $st, 'https://x.test/img-7.jpg' ) !== false && strpos( $st, 'border-radius:4px' ) !== false, 'the photo renders with 4px corners' );
ptk_test_ok( strpos( $st, '<figure' ) > strpos( $st, 'Free class.' ), 'the photo sits below the text' );
ptk_test_ok( strpos( $st, 'text-underline-offset:3px' ) !== false && strpos( $st, 'border-bottom:1px solid #1a2f5c' ) === false, 'links are underlined, not bordered' );
ptk_test_ok( strpos( $st, '>See the menu<' ) !== false, 'a quick note link renders' );
ptk_test_ok( strpos( $st, 'data-ptk-block="quick_notes"' ) !== false, 'quick notes carry the preview hook' );
ptk_test_ok( strpos( $st, 'border-left' ) === false && strpos( $st, 'border-right' ) === false, 'no one-sided borders' );

// "§ More news" never repeats: only the first of a run of unlabeled stories gets it.
$mn = PTK_Newsletter_Renderer::render( array( array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
    array( 'eyebrow' => '', 'heading' => 'One', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    array( 'eyebrow' => '', 'heading' => 'Two', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    array( 'eyebrow' => 'Membership', 'heading' => 'Three', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    array( 'eyebrow' => '', 'heading' => 'Four', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    array( 'eyebrow' => '', 'heading' => 'Five', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
) ) ) ), $st_opts );
ptk_test_ok( substr_count( $mn, '§ More news' ) === 2, 'More news: once per run of unlabeled stories' );
ptk_test_ok( strpos( $mn, '§ Membership' ) !== false, 'a labeled story always shows its own mark' );
ptk_test_ok( substr_count( $mn, '<h2' ) === 5, 'every story still renders' );

$qn_empty = PTK_Newsletter_Renderer::render( array( array( 'type' => 'quick_notes', 'data' => array( 'label' => 'Good to know', 'items' => array() ) ) ), $st_opts );
ptk_test_ok( strpos( $qn_empty, 'data-ptk-block' ) === false, 'a label with no items renders nothing when published' );
$qn_ph = PTK_Newsletter_Renderer::render( array( array( 'type' => 'quick_notes', 'data' => array( 'label' => '', 'items' => array() ) ) ), array_merge( $st_opts, array( 'preview_placeholders' => true ) ) );
ptk_test_ok( strpos( $qn_ph, 'data-ptk-block="quick_notes"' ) !== false, 'preview mode outlines empty quick notes' );
$ft_default = PTK_Newsletter_Renderer::render( array( array( 'type' => 'featured', 'data' => array( 'eyebrow' => '', 'headline' => 'X', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ) ) ), $st_opts );
ptk_test_ok( strpos( $ft_default, '§ Top story' ) !== false, 'top story with no label falls back to "Top story"' );
```

- [ ] **Step 2: Run and watch it fail.**

- [ ] **Step 3: Implement.**

1. A private `story_section( $mark, $headline, $body, $image_html, $link_url, $link_text, $padding )` helper (not `render_`-prefixed) that emits the spec's Top story structure. `render_featured()` calls it with `padding 40px 20px 8px`; `render_story_cards()` calls it per valid card with `40px 20px 8px` for the first and `24px 20px 8px` after. Note `render_story_cards()` still wraps all cards in one `div[data-ptk-block="story_cards"]` (one outer div, several sections inside — the preview highlight keys on the outer attribute).
2. `maybe_image()` (`:441`): `border-radius:4px;margin:0;` and wrap the call site in `<figure style="margin:28px 0 0;">…</figure>` inside `story_section()`; the featured test asserts the figure comes after the body.
3. `render_quick_notes( array $data, array $opts )` per the spec. Valid item = non-blank heading, body or (link_url and link_text).
4. Delete the `emphasis`-colored, navy featured hero and the beige card box; the old `'Your featured story will appear here.'` placeholder text becomes `'Your top story will appear here.'` and `'Your story cards…'` → `'Your stories will appear here.'`.
5. `render_footer()` links → the underline style (`:391`).

**Unlabeled runs:** track whether the previous rendered card was unlabeled. An unlabeled card that follows an unlabeled card gets a full-width 1px `#e6e3dc` hairline (margin-bottom 16px) in place of the § rule; a labeled card resets the run.

**Trap:** `render_story_cards()`'s validity check (`:308`) must also consider `eyebrow` — a card with only a label typed is still "something", and dropping it would lose text the volunteer entered.

- [ ] **Step 4: Run and watch it pass.** The older assertions `'populated featured hero still renders'` (line 87) checks `background:#1a2f5c` in `$full_html` — that render also has a populated announcement, so it still holds; but read it and change its label to `'populated top story still renders'` and assert on `'What a year'` only, so it stops implying a navy hero. Full test command.

- [ ] **Step 5: Commit** — `"Set the top story, stories and quick notes on white, each opened by a § rule"`.

---

### Task 6: The #040 fixture, and the whole-newsletter guarantees

One render of a realistic issue, used by a test and by the visual comparison in Task 11.

**Files:**
- Create: `pta-knowledge-hub/tests/fixtures/newsletter-040-blocks.json`
- Modify: `pta-knowledge-hub/tests/test-newsletter-renderer.php`

- [ ] **Step 1: Write the fixture.** Transcribe #040 into blocks (plain text and `<p>`/`<strong>`/`<a>` only, `image_id` 0 everywhere): header (school name "Northeast Elementary PTA · Montclair, NJ", summary "ASE registration opens Monday", greeting = #040's intro paragraph in its Sunday version), announcement (When "Opens Monday, Sept 14 · 8:30 AM for PTA members", headline "ASE registration opens Monday, Sept 14. PTA members go first.", text, the four timeline rows from #040 lines 114-131, button "Go to ASE registration" → `https://app.givebacks.gives/c691c4`), events (the nine rows, lines 192-298), featured ("ASE volunteers" / "Can you help on Tuesdays? Your child gets a free class." / body / link "Email Leslie to volunteer on Tuesdays" — note #040's link is a `mailto:`; email links are allowed on buttons and quick notes too, so it survives everywhere), story_cards ("Date change" Film on the Field; "New on northeastpta.org" Getting to School; "Membership" Were you a member), quick_notes ("Good to know": Lunch menu, Subscribe to the calendar, Family Handbook, each with its link — the *words* come from #040's pre-redesign 3-up at lines 451-479; its styles are retired and are never a reference), footer (the sign-off and the two links).

- [ ] **Step 2: Add the test** (append)

```php
// --- The #040 fixture: whole-newsletter guarantees, and the HTML for eyeballing. ---
$fx = json_decode( file_get_contents( __DIR__ . '/fixtures/newsletter-040-blocks.json' ), true );
ptk_test_ok( is_array( $fx ), 'the #040 fixture parses' );
$fx = PTK_Newsletter_Data::sanitize_blocks( $fx );
$fx_html = PTK_Newsletter_Renderer::render( $fx, array( 'issue' => 40, 'date' => '2026-09-13', 'today' => '2026-09-13', 'theme' => 'harbor-navy', 'logo_url' => '', 'school_name' => 'Northeast Elementary PTA' ) );
ptk_test_ok( substr_count( $fx_html, 'background:#1a2f5c' ) === 1, '#040: exactly one navy band' );
ptk_test_ok( strpos( $fx_html, 'border-left' ) === false && strpos( $fx_html, 'border-right' ) === false, '#040: no one-sided borders anywhere' );
ptk_test_ok( substr_count( $fx_html, '<h1' ) === 1, '#040: one h1' );
ptk_test_ok( strpos( $fx_html, 'Inter' ) === false && strpos( $fx_html, 'Fraunces' ) === false, '#040: old fonts gone' );
ptk_test_ok( preg_match_all( '/#ffd166/', $fx_html ) >= 2, '#040: yellow appears (when line, deadline row)' );
ptk_test_ok( strpos( $fx_html, 'Newsletter&nbsp;&#8470;&nbsp;040 · 2026–2027' ) !== false, '#040: the eyebrow' );
$out = getenv( 'PTK_RENDER_OUT' );
if ( $out ) {
    file_put_contents( $out, "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><link href=\"https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&display=swap\" rel=\"stylesheet\"><style>body{margin:0}</style></head><body>" . $fx_html . "</body></html>" );
    echo "  (wrote $out)\n";
}
```

  The `PTK_RENDER_OUT` environment variable is how Task 11 gets the file; the test never writes when it is unset, so the suite stays side-effect free.

- [ ] **Step 3: Run it**: `PTK_RENDER_OUT=/tmp/x.html php tests/test-newsletter-renderer.php` → `PASSED` and the file exists. Then the full command (without the variable).

- [ ] **Step 4: Commit** — `"Keep issue 040 as a fixture so the whole newsletter is checked, not just its parts"`.

---

### Task 7: The reader's-date script greys past rows

**Files:**
- Modify: `pta-knowledge-hub/assets/js/newsletter-relabel.js`
- Modify: `pta-knowledge-hub/tests/test-relabel-js.mjs`

- [ ] **Step 1: Write the failing test** (append before the `if ( failed )` block)

```js
// ptkIsPast(): the timeline and event-row fade rule -- past by whole day,
// the same bucket rule as the pills, so the two can never disagree.
const { ptkIsPast } = require( path.join( __dirname, '..', 'assets', 'js', 'newsletter-relabel.js' ) );
ok( ptkIsPast( '2026-09-14', '2026-09-15' ) === true,  'yesterday is past' );
ok( ptkIsPast( '2026-09-15', '2026-09-15' ) === false, 'today is not past' );
ok( ptkIsPast( '2026-09-16', '2026-09-15' ) === false, 'tomorrow is not past' );
ok( ptkIsPast( '', '2026-09-15' ) === false,           'no date is never past' );
ok( ptkIsPast( 'nope', '2026-09-15' ) === false,       'garbage date is never past' );
```

- [ ] **Step 2: Run it and watch it fail**: `node tests/test-relabel-js.mjs` → `TypeError: ptkIsPast is not a function`.

- [ ] **Step 3: Implement.**

```js
/** Past by whole day: the fade rule for event rows and timeline rows. */
function ptkIsPast( dateISO, todayISO ) {
    return 'past' === ptkRelabelForDate( dateISO, todayISO );
}
```

  Export it: `module.exports = { ptkRelabelForDate: ptkRelabelForDate, ptkIsPast: ptkIsPast };`.

  In `ptkInitRelabel()`:
  - For each pill: after setting the label, find `var row = el.closest( '[data-event-row]' );` and `var numeral = row && row.querySelector( '[data-event-numeral]' );`. If `ptkIsPast(...)`: `row.style.opacity = '0.45'; numeral.style.color = '#6b6b6b'; el.style.background = '#e6e3dc'; el.style.color = '#4a4a4a';` else clear all four (`''`). Guard every element for `null` — a 4.1.x newsletter's HTML has pills but no `data-event-row`, and this script runs on those pages too.
  - Then `document.querySelectorAll( '[data-timeline-date]' )`: `el.style.opacity = ptkIsPast( el.getAttribute( 'data-timeline-date' ), today ) ? '0.45' : '';`.

**Trap:** the file is loaded by Node via `require()` for the test, so it must keep running without `document` (`:156`) and must not use `closest()` outside the `typeof document !== 'undefined'` path. `Element.closest` is fine in every browser the sites support.

- [ ] **Step 4: Run and watch it pass.** Also `node --check assets/js/newsletter-relabel.js`.

- [ ] **Step 5: Commit** — `"Fade past dates for the reader, in the timeline and the Coming up list"`.

---

### Task 8: The Builder form

**Files:**
- Modify: `pta-knowledge-hub/includes/class-newsletter-builder.php`
- Modify: `pta-knowledge-hub/assets/css/newsletter-builder.css`

No unit test reaches this (it is WordPress-rendered HTML); the check is Playground, in Step 4.

- [ ] **Step 1: Names and steps.**
  - `label_for_type()` (`:689-700`): `announcement` → "Announcement", `events` → "Coming up", `featured` → "Top story", `story_cards` → "Stories", add `quick_notes` → "Quick notes". Header/Footer unchanged.
  - `intro_for_type()` (`:711-722`): the intro lines from the spec's "What the volunteer fills in".
  - `step_for_type()` (`:732-743`): add `PTK_Newsletter_Data::TYPE_QUICK_NOTES => 3`.
  - `steps()` (`:752-771`): step 2 blurb "The one big thing families must not miss, and the dates coming up. Skip anything you don't need."; step 3 blurb "The top story, shorter stories and quick notes. All optional."
  - The class docblock (`:13-14`) names the fixed and repeatable sections — update the parenthetical so it does not lie.

- [ ] **Step 2: Fields**, in `render_block_fields()` (`:1232-1401`), following the existing markup exactly (a `.ptk-nl-field-group` with `label for=`, the input with `data-field` and `aria-describedby`, a `p.description` hint with the matching id):
  - `header`: after the headline group, `summary` ("One-line summary").
  - `announcement`: replace the `pill` group with `when`, then `headline`, `text` (existing), `button_text`, `button_url` (**`type="text" inputmode="url"`** — a `type="url"` input would make the browser reject `leslie@example.org` and block the save before `sanitize_link_url()` ever sees it; help text "A web address (https://…) or an email address."), then:

```php
                <details class="ptk-nl-disclosure" data-disclosure>
                    <summary>Add dates to this announcement</summary>
                    <p class="description">Optional. One row for each date that matters. Rows in the past grey out by themselves, and the last row is shown as the deadline.</p>
                    <div class="ptk-nl-rows" data-rows data-rows-for="timeline"></div>
                    <button type="button" class="button ptk-nl-add">+ Add a date</button>
                    <template data-row-template>
                        <div class="ptk-nl-row" data-row>
                            <div class="ptk-nl-field-group"><label>Date</label><input type="date" data-field="date"><p class="description">When.</p></div>
                            <div class="ptk-nl-field-group"><label>Time</label><input type="text" data-field="time"><p class="description">Optional. For example: 8:30 AM–12:30 PM, or noon.</p></div>
                            <div class="ptk-nl-field-group"><label>What happens</label><input type="text" data-field="what"><p class="description">For example: PTA members only, or Registration closes.</p></div>
                            <button type="button" class="button ptk-nl-remove-row">Remove</button>
                        </div>
                    </template>
                </details>
```

    The `<details>` carries **no** `data-step` and is never toggled by jQuery, so the CSS contract at `newsletter-builder.css:7-26` is untouched. `bindAddRow()` finds this repeater via `$section.find('[data-rows]').first()` and `addRow()` finds the template via `$section.find('template[data-row-template]')[0]` — both work through a `<details>` because `find()` is descendant-based. There is exactly one repeater in the section.
  - `featured`: relabel `eyebrow` → "Short label" with the spec's help text; add `link_url` / `link_text` groups after the image, copied from the card template (with static ids `ptk-nl-featured-link_url` etc.).
  - `story_cards`: add an `eyebrow` group ("Short label") as the first field of the row template; relabel "Heading" help to the spec's.
  - new `case 'quick_notes':` — a `label` group ("Group label", static id `ptk-nl-quick_notes-label`), then `div.ptk-nl-rows[data-rows][data-rows-for="items"]`, `+ Add note`, and a template row with `heading`, `body` (textarea rows=2), `link_url` (**`type="text" inputmode="url"`**, same reason and help text as the button link), `link_text`. Story-card and footer link inputs are left as they are.

**Trap:** every static id in a single-instance section must be unique on the page (`ptk-nl-announcement-when`, `-headline`, `-button_text`, `-button_url`; `ptk-nl-featured-link_url`, `-link_text`; `ptk-nl-quick_notes-label`). Template rows carry **no** ids — `assignRowIds()` gives them out (`newsletter-builder.js:985-1023`).

- [ ] **Step 3: CSS** — append to `newsletter-builder.css`, in the "Repeatable rows" area:

```css
/* "Add dates to this announcement". A native <details>: nothing here is
   toggled by jQuery, so it may look however it likes. Full border, no
   accent bar. */
.ptk-nl-disclosure {
    margin: 4px 0 16px;
    padding: 12px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f9fafb;
}
.ptk-nl-disclosure > summary {
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    color: #1f2937;
    min-height: 28px;
}
.ptk-nl-disclosure[open] > summary { margin-bottom: 10px; }
.ptk-nl-disclosure .ptk-nl-rows { margin-top: 8px; }
```

- [ ] **Step 4: Verify in Playground.** Start the server (see "Before you start"), log in, Newsletters → Add New, console open. Check: step 2 shows Announcement with When / Headline / Text / Button words / Button link and the closed disclosure; opening it and pressing "+ Add a date" adds a row; step 3 shows Top story, Stories, Quick notes; "+ Add note" adds a row; step 4's arrange list lists Coming up, Announcement, Top story, Stories, Quick notes (in default order: Announcement, Coming up, Top story, Stories, Quick notes); the live preview updates as you type in each new field; **no console errors**. Publish, reopen from the list: every value is back. **Email link check:** type a bare `leslie@example.org` into Button link (with Button words filled), publish — the form submits, and the rendered button is `href="mailto:leslie@example.org"`. Then run the full test command.

- [ ] **Step 5: Commit** — `"Ask for the announcement's headline, when line, button and dates; add quick notes to the form"`.

---

### Task 9: Builder JS — fonts in the preview, and a disclosure that opens itself

**Files:**
- Modify: `pta-knowledge-hub/assets/js/newsletter-builder.js`

- [ ] **Step 1: Fonts.** In `writePreview()` (`:608-612`) add, after `<meta charset="utf-8">`:

```js
            '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&display=swap">' +
```

   The document is rewritten on every refresh; the browser caches the stylesheet after the first, so this costs one request per session.

- [ ] **Step 2: Open a disclosure that already has rows.** A reopened newsletter with timeline rows must show them, not hide them behind a closed `<details>`. Add, as the **last** line of the boot block, inside `safeBoot()`:

```js
        safeBoot(openFilledDisclosures);
```

   with

```js
    /**
     * A closed "Add dates" disclosure hiding rows that were saved would look
     * like the dates had vanished. Open any disclosure that has rows in it.
     */
    function openFilledDisclosures() {
        $('[data-disclosure]').each(function () {
            if ($(this).find('[data-rows] > [data-row]').length) {
                this.open = true;
            }
        });
    }
```

**Trap (the one this repo has been bitten by):** the boot block `:83-118` is a flat list; anything above `showStep()` that throws leaves the wizard inert and `node --check` will not catch it. The new call goes **after** the five existing `safeBoot()` lines, wrapped like them. Nothing new goes above `showStep()`.

- [ ] **Step 3: `node --check assets/js/newsletter-builder.js`**, then Playground: reopen the newsletter published in Task 8 — the disclosure is open with its rows; the preview shows Libre Franklin (right-click the preview → Inspect → computed `font-family` on the masthead h1, or read `getComputedStyle(frame.contentDocument.querySelector('h1')).fontFamily` in the console). No console errors. Full test command.

- [ ] **Step 4: Commit** — `"Show the preview in the real fonts, and open the dates when a newsletter has them"`.

---

### Task 10: Share captions read the new fields

**Files:**
- Modify: `pta-knowledge-hub/includes/class-share-text.php`
- Modify: `pta-knowledge-hub/tests/test-share-text.php`

- [ ] **Step 1: Write the failing tests** (append before `ptk_test_done()`)

```php
// --- 4.2.0: announcement headline + when, quick notes as "also" lines. ------
$v2 = PTK_Share_Text::generate( array(
    array( 'type' => 'header', 'data' => array( 'school_name' => 'NE', 'headline' => '', 'summary' => '', 'greeting' => '' ) ),
    array( 'type' => 'announcement', 'data' => array( 'when' => 'Closes Thursday at noon', 'headline' => 'ASE registration opens Monday.', 'text' => '<p>Members go first.</p>', 'button_text' => '', 'button_url' => '', 'timeline' => array() ) ),
    array( 'type' => 'story_cards', 'data' => array( 'cards' => array(
        array( 'eyebrow' => '', 'heading' => 'Film on the Field moves to Friday, October 16.', 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ),
    ) ) ),
    array( 'type' => 'quick_notes', 'data' => array( 'label' => 'Good to know', 'items' => array(
        array( 'heading' => 'Lunch menu', 'body' => '<p>This week\'s menus are on the site.</p>', 'link_url' => 'https://x.test/l', 'link_text' => 'See the menu' ),
        array( 'heading' => '', 'body' => '', 'link_url' => '', 'link_text' => '' ),
    ) ) ),
    array( 'type' => 'footer', 'data' => array( 'signoff' => '', 'links' => array() ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 41, 'date' => '2026-09-20', 'school_name' => 'NE', 'today' => '2026-09-20' ) );
$fb2 = $v2['facebook'];
ptk_test_ok( strpos( $fb2, "ASE registration opens Monday.\nMembers go first.\nCloses Thursday at noon" ) !== false, 'facebook: announcement is headline, text, when' );
ptk_test_ok( strpos( $fb2, 'Film on the Field moves to Friday, October 16.' ) !== false, 'facebook: story line still there' );
ptk_test_ok( strpos( $fb2, 'Lunch menu' ) !== false && strpos( $fb2, "This week's menus are on the site." ) !== false, 'facebook: a quick note becomes an also-line via the heading rule' );
ptk_test_ok( strpos( $fb2, 'Film on the Field' ) < strpos( $fb2, 'Lunch menu' ), 'facebook: stories come before quick notes' );
ptk_test_ok( substr_count( $fb2, "\nLunch menu" ) === 1, 'facebook: the blank note adds nothing' );
ptk_test_ok( strpos( $v2['instagram'], 'ASE registration opens Monday.' ) !== false, 'instagram: leads with the announcement headline' );
ptk_test_ok( strpos( $v2['whatsapp'], 'ASE registration opens Monday.' ) !== false, 'whatsapp: with no top story the announcement headline is the middle line' );

// A migrated announcement with no headline: the text leads, the When line follows.
$nohead = PTK_Share_Text::generate( array(
    array( 'type' => 'announcement', 'data' => array( 'when' => 'Thursday · Jun 25', 'headline' => '', 'text' => 'Last day of school.', 'button_text' => '', 'button_url' => '', 'timeline' => array() ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 1, 'date' => '2026-06-22', 'school_name' => 'NE', 'today' => '2026-06-22' ) );
ptk_test_ok( strpos( $nohead['facebook'], "Last day of school.\nThursday · Jun 25" ) !== false, 'no headline: the text is the lead line and the When line follows it' );

// Un-resaved 4.1.x meta still carries pill; captions must not lose it.
$v1 = PTK_Share_Text::generate( array(
    array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thursday', 'text' => 'Last day.' ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 1, 'date' => '2026-06-22', 'school_name' => 'NE', 'today' => '2026-06-22' ) );
ptk_test_ok( strpos( $v1['facebook'], "Last day.\nThursday" ) !== false, 'an old pill still reaches the caption as the when line' );

// The cap: 5 cards + 5 notes -> 8 also-lines, cards first.
$many_cards = array(); $many_notes = array();
for ( $i = 1; $i <= 5; $i++ ) {
    $many_cards[] = array( 'eyebrow' => '', 'heading' => "Card number $i is a whole sentence here.", 'body' => '', 'image_id' => 0, 'link_url' => '', 'link_text' => '' );
    $many_notes[] = array( 'heading' => "Note number $i is a whole sentence here.", 'body' => '', 'link_url' => '', 'link_text' => '' );
}
$cap = PTK_Share_Text::generate( array(
    array( 'type' => 'story_cards', 'data' => array( 'cards' => $many_cards ) ),
    array( 'type' => 'quick_notes', 'data' => array( 'label' => '', 'items' => $many_notes ) ),
), array( 'url' => 'https://x.test/n', 'issue' => 1, 'date' => '2026-06-22', 'school_name' => 'NE', 'today' => '2026-06-22' ) );
ptk_test_ok( substr_count( $cap['facebook'], 'Card number' ) === 5 && substr_count( $cap['facebook'], 'Note number' ) === 3, 'also-lines cap at 8, cards first' );
ptk_test_ok( strpos( $cap['facebook'], 'Note number 4' ) === false, 'the ninth line is dropped' );
```

- [ ] **Step 2: Run and watch it fail.**

- [ ] **Step 3: Implement** in `generate()` (`:134-217`):
  - `$announce_headline = html_to_text( $announce['headline'] ?? '' )`; `$announce_when = html_to_text( isset( $announce['when'] ) ? $announce['when'] : ( isset( $announce['pill'] ) ? $announce['pill'] : '' ) )`; `$announce_text` as today. `$announce_para = implode( "\n", array_filter( array( $announce_headline, $announce_text, $announce_when ), 'strlen' ) )`; use `$announce_para` where `$announce_text` was in the Facebook sections list.
  - Add `const ALSO_LINES_MAX = 8;`. Build `$story_lines` from cards, then append `story_line()` of each `quick_notes.items[]` (they carry `heading` + `body`, so the existing rule applies unchanged), then `array_slice( $story_lines, 0, self::ALSO_LINES_MAX )`.
  - `generate_instagram()`: lead = `$announce_headline`, else `$announce_text`, else `$featured_headline` (change the signature to pass the headline through).
  - `generate_whatsapp()`: middle = `$featured_headline`, else `$announce_headline`, else `$announce_text`.

**Trap:** the existing assertion `'facebook: featured headline leads before story cards'` and the WhatsApp `< 400` check must keep passing — run the whole file, not just the new lines. `story_line()` on a note with a label-like heading ("Lunch menu") appends the body's first sentence with an em dash; that is what the "Lunch menu" test asserts.

- [ ] **Step 4: Run and watch it pass**; full test command (`test-share-data.php` proves the two hashes still work).

- [ ] **Step 5: Commit** — `"Write the posts from the announcement's headline, and mention the quick notes"`.

---

### Task 11: The doubled title, and the fonts on every site

**Files:**
- Create: `pta-knowledge-hub/assets/css/newsletter-public.css`
- Modify: `pta-knowledge-hub/includes/class-newsletter-post-type.php`

- [ ] **Step 1: The stylesheet** — exactly the four lines in the spec ("The doubled title"), with its comment.

- [ ] **Step 2: Enqueue** in `enqueue_public()` (`:26-38`), after the existing script:

```php
        // The newsletter's masthead is the page's title; the theme's own title
        // and date above it are hidden here, never by replacing the template
        // (bb-theme's container and the Themer header/footer depend on it).
        wp_enqueue_style( 'ptk-newsletter-public', PTK_PLUGIN_URL . 'assets/css/newsletter-public.css', array(), PTK_VERSION );

        // The other ten sites don't load the house fonts themselves.
        wp_enqueue_style( 'ptk-newsletter-fonts', 'https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&display=swap', array(), null );
```

   `null` as the version so WordPress does not append `?ver=` to Google's URL.

- [ ] **Step 3: Verify in Playground** that both `<link>`s appear in the page source of a published newsletter **and** of its `?ptk_preview=` link (make one from step 4 of a draft), and on neither the home page nor a `pta_knowledge` entry. `php -l` both PHP files.

- [ ] **Step 4: Commit** — `"Let the newsletter's masthead be the page's only title, and load the fonts on every site"`.

---

### Task 12: Verification

Everything in the spec's "Verification" section, in order. Do not skip the visual pass — the unit tests prove structure, not looks.

- [ ] **Step 1: Full test command** → all green.

- [ ] **Step 2: Render the fixture and compare with #040.**
  `PTK_RENDER_OUT="<scratchpad>/040-builder.html" php tests/test-newsletter-renderer.php`. Open that file and `/Users/lucas/apps/PTA/NEPTANewsletter/newsletter-040-week-of-9-14-26.html` in the Browser pane, at 840px and then at 375px (`resize_window`). Compare, in this order: masthead (eyebrow text, h1 size, date/summary column, greeting width); the navy announcement (yellow when line 15px italic, white headline, `#cfd8e3` text, timeline rows with the last one yellow, white button); the § rules; top story and stories (30px headline, 16px body, link underline, photo below text with 4px corners); quick notes (17px headings, hairlines); Coming up (numeral + weekday, tags right). Note every difference in the report; fix anything that is a mistake against the spec, and report anything that is a judgement call rather than silently deciding.

- [ ] **Step 3: The same issue through the Builder in Playground.** Enter #040's content by hand through the four steps (copy from the fixture). Confirm the preview updates after each field, the timeline disclosure works, Quick notes appears in the arrange list, publish succeeds, reopening shows every value, and the published page matches the fixture render. Console: no errors at any point.

- [ ] **Step 4: Old data.** Playground has no shell, so seed the post with a blueprint. Write `<scratchpad>/seed-old.json`:

```json
{ "steps": [ { "step": "runPHP", "code": "<?php require '/wordpress/wp-load.php'; $id = wp_insert_post( array( 'post_type' => 'pta_newsletter', 'post_status' => 'publish', 'post_title' => 'Newsletter No. 7 — June 22, 2026', 'post_content' => '<p>old html</p>' ) ); update_post_meta( $id, 'ptk_nl_issue', 7 ); update_post_meta( $id, 'ptk_nl_date', '2026-06-22' ); update_post_meta( $id, 'ptk_nl_theme', 'harbor-navy' ); update_post_meta( $id, 'ptk_nl_blocks', wp_slash( json_encode( array( array( 'type' => 'header', 'data' => array( 'school_name' => 'NE PTA', 'headline' => '', 'greeting' => 'Hi' ) ), array( 'type' => 'announcement', 'data' => array( 'pill' => 'Thursday · Jun 25', 'text' => 'Last day of school.' ) ), array( 'type' => 'events', 'data' => array( 'rows' => array( array( 'date' => '2026-06-25', 'title' => 'Last day', 'desc' => '' ) ) ) ), array( 'type' => 'featured', 'data' => array( 'eyebrow' => 'Year in review', 'headline' => 'What a year', 'body' => 'Thanks', 'image_id' => 0 ) ), array( 'type' => 'story_cards', 'data' => array( 'cards' => array( array( 'heading' => 'Mum Sale', 'body' => 'Open', 'image_id' => 0, 'link_url' => '', 'link_text' => '' ) ) ) ), array( 'type' => 'footer', 'data' => array( 'signoff' => 'Bye', 'links' => array() ) ) ) ) ) );" } ] }
```

  Restart Playground with `--blueprint="<scratchpad>/seed-old.json"` added to the command in "Before you start". (The `wp_slash()` matters for the same reason it does in `persist_newsletter()`, `class-newsletter-builder.php:391-396`.) Open the seeded newsletter in the Builder: When shows "Thursday · Jun 25", Quick notes is under "Not included", the preview renders the announcement in navy. Press Update, then View newsletter: the new look. Also confirm the seeded post's **original** `post_content` (`<p>old html</p>`) was replaced — that is Decision 9 working as described.

- [ ] **Step 5: The doubled title.** Playground: page source of the published view and the preview-link view both include `newsletter-public.css`. Record in the report that the live check (`.fl-post-header` computed `display: none`; one `h1` inside `.fl-post-content`; Northeast's footer `h1.fl-heading`s remain and are the theme's) is to be done by Lucas or the agent after deploy — Playground has no bb-theme.

- [ ] **Step 6: Builder health.** Add New and Edit both boot; sidebar and Back/Next switch steps; the unsaved-changes prompt appears after typing in a new field and leaving; it does not appear after Update. No console errors.

- [ ] **Step 7:** Nothing to commit unless Step 2 found a mistake; if it did, fix it with a test where one is possible and commit `"Match #040 on <what>"`.

---

### Task 13: Version 4.2.0

**Files:**
- Modify: `pta-knowledge-hub/pta-knowledge-hub.php` (header line 6 and `PTK_VERSION` line 16 — both)
- Modify: `update-info.json`
- Rebuild: `pta-knowledge-hub.zip`

- [ ] **Step 1: Bump** `Version: 4.2.0` and `define( 'PTK_VERSION', '4.2.0' );`. Both, or the rewrite-flush and cache-clear gates (`:183`, `:201`) never fire on the sites.

- [ ] **Step 2: Changelog** — prepend to `update-info.json`'s `changelog` (one string, HTML, newest first) and set `"version": "4.2.0"`, `"last_updated": "2026-09-16"`. In the existing voice (what it does for a volunteer):

```html
<h4>v4.2.0 — Newsletters look like the new site</h4><ul><li>The newsletter the builder publishes now matches issue № 040 and the redesigned site: one navy announcement at the top, stories on white with a "§ label" line, and the school's fonts.</li><li>The announcement has its own headline, a "When" line (your old short label moves here), an optional button, and dates you can add — past dates grey out on their own and the last one is shown as the deadline.</li><li>New: <strong>Quick notes</strong>, a short list of reminders and links under one heading.</li><li>The top of the newsletter reads "Newsletter № 041 · 2026–2027", with room for a one-line summary under the date.</li><li>Coming up shows the weekday under each date, and past dates fade.</li><li>The page no longer shows a second title above the newsletter.</li><li>Facebook, Instagram and WhatsApp posts use the announcement's headline and mention your quick notes.</li><li>Newsletters you published before keep their old look until you open them and press Update.</li></ul>
```

- [ ] **Step 3: Rebuild the zip** from the worktree root:

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-redesign" && rm -f pta-knowledge-hub.zip && zip -rq pta-knowledge-hub.zip pta-knowledge-hub -x "pta-knowledge-hub/tests/*" -x "*/.DS_Store" -x "*/.*" -x "*/zz-*" && unzip -l pta-knowledge-hub.zip | grep -E "newsletter-public.css|class-newsletter-renderer.php|tests/" 
```

   Expect the first two listed and **no** `tests/` entries.

- [ ] **Step 4: Full test command** one last time.

- [ ] **Step 5: Commit** — `"Release 4.2.0: the newsletter builder matches the site redesign"`, body summarising the volunteer-facing changes and the Decision-9 note about old issues.

- [ ] **Step 6: STOP.** Do not upload. Deployment is Lucas's call (Network Admin → Plugins → Add Plugin → Upload → "Replace current with uploaded", which updates all eleven sites). Report: the commit SHA, the visual differences found in Task 12 Step 2, the live-site checks still to do after deploy (Task 12 Step 5), and the one follow-up (Northeast's footer h1s, a Beaver Themer change).
