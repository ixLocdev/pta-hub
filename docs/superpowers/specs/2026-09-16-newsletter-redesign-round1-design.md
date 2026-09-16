# Newsletter Redesign, Round 1 — Design

> **STATUS: READY TO PLAN** (2026-09-16). The design decisions were made and approved by
> Lucas; this document grounds them in the code as it stands on the `newsletter-redesign`
> branch (branched from the 4.1.1 release, commit `e617456`). Plan:
> `docs/superpowers/plans/2026-09-16-newsletter-redesign-round1.md`.

## The problem

The Newsletter Builder (Newsletters → Add New, four steps with a live preview) publishes a
newsletter that looks like the Northeast site did *before* its 2026-09-11 redesign. Issue
№ 040, hand-built by Lucas, is what the site looks like now and is the target:

- Source: `/Users/lucas/apps/PTA/NEPTANewsletter/newsletter-040-week-of-9-14-26.html`
  (inline-styled, email-safe). Every size, color and spacing value below that says
  "from #040" was copied from that file.
- Live: https://northeastpta.org/2026/09/13/pta-newsletter-040-ase-registration-opens-monday/
- House style (authoritative): `/Users/lucas/apps/PTA/HOUSE-STYLE.md`.

Compared with #040, the Builder's output today (live test issue:
https://northeastpta.org/newsletters/newsletter-no-1-september-16-2026/) has:

| What | Today | #040 |
|---|---|---|
| Title | The theme prints "Newsletter No. 1 — September 16, 2026" plus a date **above** the newsletter's own masthead | The masthead is the page's only title |
| Masthead eyebrow | "NO. 1" (`class-newsletter-renderer.php:117`) | "NEWSLETTER № 040 · 2026–2027" |
| Under the date | nothing | a one-line summary ("ASE registration opens Monday") |
| Announcement | a 16px navy strip: grey rounded tag + one paragraph, no headline (`:155-178`) | the one navy callout: yellow italic "when" line, big white headline, text, a timeline, a white button |
| Featured story | a **second** navy band (`:265`) | white, opened by a "§ label" rule |
| Story cards | beige rounded boxes, `border-radius:14px;background:#f6f4ef` (`:330`) | "§ label" sections on white |
| Fonts | `'Inter'` / `'Fraunces'` (`:49-50`) | Libre Franklin / Newsreader |
| Past event dates | red numerals (`:222`) | greyed rows; red means no school or a deadline |

**The governing rule is ease of use.** Every field is plain English, optional where it can
be, and nothing a volunteer can't understand.

## Scope

**In round 1:** the fields, the data model and its migration, the renderer, the reader's-date
script, the Builder form, the share captions, the doubled title, verification, version 4.2.0.

**Deliberately not in round 1** (round 2 adds a settings page for them): the "Join the PTA"
masthead link, the "See full calendar" link on Coming up, an automatic "Got news?" closing.
Where a clean hook exists it is named below; nothing is built for them.

**Also out of scope, noted so nobody "fixes" them by accident:** the event tag vocabulary
("Upcoming" stays; #040's "In 2 weeks" / "Save the date" is a later change); the theme's
featured-image handling; re-rendering already-published newsletters (see Decision 9).

---

## Facts verified in code and on the live site (2026-09-16)

1. **The doubled title.** Fetched the live test issue (HTTP 200; northeastpta.org does not
   block fetchers the way montclairpta.org does). Body class includes
   `single-pta_newsletter wp-theme-bb-theme fl-theme-1-7-20 fl-theme-builder-header
   fl-theme-builder-footer` — bb-theme 1.7.20 with Themer header and footer layouts and no
   Themer singular layout. The extra title is:

   ```html
   <article class="fl-post post-3733 pta_newsletter ..." id="fl-post-3733" itemscope ...>
     <header class="fl-post-header">
       <h1 class="fl-post-title" itemprop="headline">Newsletter No. 1 — September 16, 2026</h1>
       <div class="fl-post-meta fl-post-meta-top"><span class="fl-post-date">September 16, 2026</span></div>
       <meta itemprop="..."> ... (schema.org microdata, invisible)
     </header>
     <div class="fl-post-content clearfix" itemprop="text">
       <div style="background:#efece6;"> ... the newsletter ...
   ```

   The date div is **inside** the header, so hiding `.fl-post-header` removes both. The
   microdata it contains is invisible and unaffected by `display:none`. The renderer's
   masthead already emits an `<h1>` (`class-newsletter-renderer.php:122`), which becomes the
   content's only h1 once the theme's is hidden.

   **Caveat found on the live page:** Northeast's Themer *footer* layout uses `<h1
   class="fl-heading">` for "Get In Touch" and "About Us". Those are the theme owner's, not
   the plugin's; round 1 makes the masthead the only h1 *in the content*, and the report
   flags the footer h1s for Lucas to change in Beaver Themer.

   Northeast already loads Libre Franklin + Newsreader through its Customizer CSS. The other
   ten sites do not, so the plugin must load the fonts itself (Decision 10).

2. **The renderer is inline-styled on purpose** (`class-newsletter-renderer.php:8-11`) for a
   future email reuse (round 7). Inline styles stay; the only stylesheet added is the tiny
   theme-side one in section "The doubled title" — it styles the theme, never the newsletter.

3. **Reader's-date relabeling** already exists: `assets/js/newsletter-relabel.js`
   (`ptkRelabelForDate()`, a port of `PTK_Newsletter_Data::relabel_for_date()`) rewrites every
   `[data-event-date]` pill's text for the reader's local day, tested by
   `tests/test-relabel-js.mjs`. It does **not** grey past rows today (`:143-152` only sets
   `textContent`). `PTK_Newsletter_Post_Type::enqueue_public()` (`:26-38`) enqueues it on
   `is_singular('pta_newsletter')`, which the public-preview view also satisfies
   (`PTK_Public_Preview::maybe_render_preview()` sets `$wp_query->is_singular` and the
   queried object at `class-public-preview.php:115-127` before the template runs).

4. **Data model** (`includes/class-newsletter-data.php`): types at `:19-24`, `known_types()`
   `:31-40`, `default_blocks()` `:48-94`, `sanitize_blocks()` `:105-163` (header first, footer
   last, unknown types dropped), `sanitize_block_data()` `:172-247`, `sanitize_date()`
   `:267-270`, `blocks_have_images()` `:280-302` (any `image_id > 0`, including inside row
   arrays — new row arrays are covered automatically), `relabel_for_date()` `:358-391`.
   The `sanitize_blocks()` pass runs on **every** read of stored blocks: the Builder
   (`class-newsletter-builder.php:597, :855`), the save (`:165`), the live preview (`:264`)
   and the share panel (`PTK_Share_Panel::context()`, `class-share-panel.php:252-254`). So a
   migration written into `sanitize_block_data()` reaches every consumer.

5. **The Builder** (`includes/class-newsletter-builder.php`, `assets/js/newsletter-builder.js`,
   `assets/css/newsletter-builder.css`):
   - Sections are direct children of `#ptk-nl-blocks` (`class-newsletter-builder.php:944-952`);
     `serialize()` reads `'#ptk-nl-blocks > .ptk-nl-block'` (`newsletter-builder.js:1558`).
   - `[data-step]` elements are plain blocks, never hidden by CSS
     (`newsletter-builder.css:7-26`).
   - The boot block is `newsletter-builder.js:83-118`; 4.1.1 added `safeBoot()` (`:191-199`)
     and runs its optional bindings last. New boot code follows that pattern.
   - `serialize()` (`:1555-1608`) is generic: every `[data-field]` in a section, plus one
     array per `[data-rows][data-rows-for]` container. `prefillFromData()` (`:853-894`) is
     its mirror. **Both are already field-agnostic**, so new plain fields and new repeaters
     need no JS changes to save or load. The two constraints are in `bindAddRow()` (`:916`,
     `$section.find('[data-rows]').first()`) and `addRow()` (`:947`, the first
     `template[data-row-template]` in the section): **one repeater per section**. Every
     section in this design has at most one.
   - The unsaved-changes guard (`:323-368`) compares `formState()` = the serialized blocks +
     issue + date, so new `[data-field]`s participate with no change.
   - The live preview (`handle_preview_ajax()`, `:256-286`) runs `sanitize_blocks()` and the
     real renderer, so it shows new fields as soon as the data model and renderer do. The
     iframe document is written by `writePreview()` (`:593-621`) with **no font link**, which
     is why the preview shows Helvetica today.
   - Step 4's arrange list is rebuilt from the live sections
     (`renderArrangeList()`, `:1077-1117`), labelled from each section's `<h3>`
     (`sectionLabel()`, `:1066-1069`). A new section type appears there automatically.
   - `sections_to_render()` (`:790-833`) renders a shell for every type in
     `default_blocks()` that a saved newsletter lacks, marked excluded, so it can be added
     back. That is the mechanism by which old newsletters gain Quick notes.

6. **Share captions** (`includes/class-share-text.php`, `tests/test-share-text.php`):
   `generate()` (`:134-217`) reads `announcement.text`, `featured.headline/body`,
   `story_cards.cards[]` through `story_line()` (`:76-92`), events after `today`, footer
   links. `PTK_Share_Data`'s two hashes (`class-share-data.php:33-48`) hash the whole blocks
   array, so new fields change the caption hash automatically and the square hash never
   sees them — exactly as designed.

7. **Empty sections already render nothing when published.** Each `render_*` returns
   `placeholder()` (`class-newsletter-renderer.php:407-415`) for an all-blank block, which
   is `''` unless `preview_placeholders` is set (only the live preview sets it,
   `class-newsletter-builder.php:282`). `tests/test-newsletter-renderer.php:51-67` proves it.

8. **`PTK_Share_Text::issue_label()`** (`class-share-text.php:59-65`) zero-pads to 3 digits
   and is already used by the masthead (`class-newsletter-renderer.php:117`).

9. Tests are plain PHP (`tests/bootstrap.php` shims; `php tests/test-*.php`) and one Node
   script. All ten pass on this branch as of writing. PHP 8.5 CLI locally; the plugin must
   stay PHP 7.4-compatible (no `match`, no `str_contains`, no typed properties, no
   `readonly`).

---

## What the volunteer fills in

### Step 1 · The basics

| Field | Label | Help text | Notes |
|---|---|---|---|
| `school_name` | School name | Shown at the top of every newsletter. | unchanged |
| `headline` | Headline | The big title at the top — for example "Week of September 14." Leave blank and we'll use the week of your issue date. | unchanged; automatic |
| **`summary`** (new) | One-line summary | Optional. One short line under the date saying what the issue is about. For example: ASE registration is open this week. About 60 characters fits on one line. | text |
| `greeting` | Greeting | unchanged | |
| issue / date | unchanged | | outside the blocks |

### Step 2 · What's happening

**Announcement** (was "Key announcement"). Intro line: *The one thing families must not miss
this week. It's the only colored block in the newsletter. Skip it if there isn't one.*

| Field | Label | Help text |
|---|---|---|
| **`when`** (replaces `pill`) | When | Optional. When it happens or closes, in a few words. For example: Closes Thursday, Sept 17 at noon. About 60 characters fits on one line. |
| **`headline`** (new) | Headline | One sentence that says the news. For example: ASE registration opens Monday. PTA members go first. About 30 characters reads best at this size; longer still works. |
| `text` | Text | A sentence or two with the details. For example: Twelve classes for grades K–5, Tuesdays and Wednesdays, 3 to 4 PM. About 90 characters keeps it to two lines. |
| **`button_text`** (new) | Button words | Optional. What the button says. For example: Go to ASE registration |
| **`button_url`** (new) | Button link | Where the button goes. Must start with https:// (or http://). |
| **`timeline`** (new repeater) | disclosure: **Add dates to this announcement** | Optional. Add a row for each date that matters. Rows in the past grey out by themselves, and the last row is shown as the deadline. |

Each timeline row: `date` (Date — "When."), `time` (Time — "Optional. For example: 8:30
AM–12:30 PM, or noon."), `what` (What happens — "For example: PTA members only, or
Registration closes."). Rows are entered in date order by the volunteer; the renderer does
not sort them (see Decision 3).

**Coming up** (was "Upcoming events"): fields unchanged (`date`, `title`, `desc`). Intro line:
*Dates coming up. Each one gets a "This week" or "Next week" tag that updates itself, and past
dates fade out.*

### Step 3 · Stories

**Top story** (was "Featured story"; type key stays `featured`). Intro: *The main story, at the
top of the stories. Optional.*

| Field | Label | Help text |
|---|---|---|
| `eyebrow` | Short label | Optional. Two or three words naming the section, shown as "§ …" above the headline. For example: Date change. If you leave it blank we use "Top story". |
| `headline` | Headline | A whole sentence that carries the news. For example: Film on the Field moves to Friday, October 16. About 40 characters fits on one line. |
| `body` | Story | A paragraph or two in your own words. |
| `image_id` | Photo | Optional. Please don't use photos of students' faces. |
| **`link_url`**, **`link_text`** (new on `featured`) | Link address / Link wording | as story cards today |

**Stories** (was "Story cards"; type key stays `story_cards`, rows stay `cards`). Intro:
*Shorter articles, each with its own "§ label" line. Add as many as you need.*

Each card: **`eyebrow`** (new; "Short label" — same help as above, default "More news"),
`heading`, `body`, `image_id`, `link_url`, `link_text`.

**Quick notes** (new type `quick_notes`). Intro: *Small reminders and useful links, grouped
under one heading. Good for "three things worth bookmarking".*

| Field | Label | Help text |
|---|---|---|
| `label` | Group label | Shown as "§ …" above the notes. For example: Good to know. If you leave it blank we use "Quick notes". |
| `items[]` | repeater "+ Add note" | |
| — `heading` | Headline | A few words. For example: Lunch menu. About 40 characters fits on one line. |
| — `body` | Text | A sentence or two. For example: This week's menus are always on the site. |
| — `link_url` | Link address | Optional. Must start with https:// (or http://). |
| — `link_text` | Link wording | What the link says. For example: See the menu |

### Step 4 · Finish & publish — unchanged

Do not touch `render_page()`'s step-4 markup (`class-newsletter-builder.php:917-942`,
`:956-987`), `render_notice()`, `render_preview_panel()`, `handle_preview_link_ajax()`, the
`safeBoot()` bindings or the PII gate — all of them received 4.1.1 fixes. The arrange list
gains a "Quick notes" row with no code change (fact 5).

---

## Before → after field mapping, and migration

| Block | 4.1.x key | 4.2.0 key | Sanitizer (`sanitize_block_data()`) | Migration |
|---|---|---|---|---|
| header | `school_name` | same | `sanitize_text_field` | — |
| header | `headline` | same | `sanitize_text_field` | — |
| header | — | `summary` | `sanitize_text_field` | new, `''` |
| header | `greeting` | same | `wp_kses_post` | — |
| announcement | `pill` | **`when`** | `sanitize_text_field` | `when = data['when'] if set, else data['pill'], else ''`. The `pill` key is not emitted. |
| announcement | — | `headline` | `sanitize_text_field` | new, `''` |
| announcement | `text` | same | `wp_kses_post` | — |
| announcement | — | `button_text` | `sanitize_text_field` | new |
| announcement | — | `button_url` | `sanitize_http_url` (new helper, Decision 6) | new |
| announcement | — | `timeline[]` `{date,time,what}` | `sanitize_date` / `sanitize_text_field` / `sanitize_text_field` | new, `array()` |
| events | `rows[]` `{date,title,desc}` | same | unchanged | — |
| featured | `eyebrow` | same (now the § label) | `sanitize_text_field` | value kept; it moves from "small line above" to the section mark |
| featured | `headline`, `body`, `image_id` | same | unchanged | — |
| featured | — | `link_url`, `link_text` | `esc_url_raw` / `sanitize_text_field` (same as cards) | new |
| story_cards | `cards[]` `{heading,body,image_id,link_url,link_text}` | same + `eyebrow` | `eyebrow`: `sanitize_text_field` | new key, `''` |
| quick_notes | — | `label`, `items[]` `{heading,body,link_url,link_text}` | `sanitize_text_field`, `sanitize_text_field` / `wp_kses_post` / `sanitize_http_url` / `sanitize_text_field` | new type |
| footer | `signoff`, `links[]` | same | unchanged | — |

**Where migration happens:** `sanitize_block_data( 'announcement', … )`. Because every reader
of stored blocks goes through `sanitize_blocks()` (fact 4), a 4.1.x newsletter opened in the
Builder shows its old "short label" in the new "When" field, the live preview renders it in
the yellow italic line, the share captions see it, and pressing Update rewrites the meta in
the new shape. Nothing is lost; nothing needs a one-off migration script.

**`default_blocks()`** gains `summary`, the announcement's new keys, `eyebrow` on the card
template (cards are an empty array, so this is documentation), `link_url`/`link_text` on
featured, and a `quick_notes` block between `story_cards` and `footer` (Decision 4).
`known_types()` gains `quick_notes`. Keep `TYPE_FEATURED = 'featured'` and
`TYPE_STORY_CARDS = 'story_cards'` so stored data and saved order survive.

**Every stored newsletter still renders:** blank new keys render nothing (each renderer
skips empty parts), `pill` migrates, and the old `eyebrow` on `featured` simply becomes the §
label. `tests/test-newsletter-data.php` gets a test that feeds a literal 4.1.x block set
(with `pill`, without the new keys) through `sanitize_blocks()` and asserts the shape.

---

## What the published newsletter looks like

All styles inline. Fonts: `FONT_SANS = "'Libre Franklin',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif"`,
`FONT_SERIF = "Newsreader,Georgia,serif"` (from #040 line 278/305). Palette additions:
`'on_navy' => '#cfd8e3'`, `'small' => '#6b6b6b'`, `'chip' => '#f0eee7'` (HOUSE-STYLE Color
table). Every date numeral gets `font-variant-numeric:lining-nums tabular-nums;` (HOUSE-STYLE
"Type"). The renderer's outer wrapper `<div style="background:#efece6;">` and each block's
`data-ptk-block` attribute are unchanged — the Builder's highlight depends on the latter.

Rules applied throughout: no `border-left`/`border-right` anywhere (hairlines are
`border-top`/`border-bottom` or a 1px-high div); the announcement is the **only** navy fill;
yellow appears only inside it; links are `text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;`
(replacing the old `border-bottom` hack at `class-newsletter-renderer.php:343, :391`); `№`
via `&#8470;`; US spelling.

### Masthead (`render_header`) — from #040 lines 278-299

```
div[data-ptk-block=header]  font-family:FONT_SANS;color:#111;background:#fff;padding:32px 20px;border-bottom:1px solid #e6e3dc;box-sizing:border-box;
  div  max-width:840px;margin:0 auto;
    div  display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:24px;flex-wrap:wrap;
      div  display:flex;align-items:center;gap:12px;
        img (logo, if any)  width:32px;height:32px;display:block;flex-shrink:0;
        div  font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:#1a2f5c;font-weight:700;line-height:1.4;   {school_name}
      ← round-2 hook: the "Join the PTA" link goes here as the row's second flex child
    div  display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;border-top:1px solid #111;padding-top:20px;
      div  flex:1 1 240px;min-width:240px;
        div  font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:#4a4a4a;font-weight:600;margin-bottom:10px;
             Newsletter&nbsp;&#8470;&nbsp;{issue_label} · {school_year}        ← "Newsletter № 041 · 2026–2027"
        h1   font-family:FONT_SANS;font-weight:800;font-size:clamp(30px,7vw,44px);line-height:1.02;letter-spacing:-0.025em;margin:0;color:#111;   {headline}
      div  font-size:13px;color:#4a4a4a;line-height:1.5;
        div  font-weight:600;color:#111;   {date, "Sunday, September 13, 2026"}
        div  {summary}                      ← only when non-blank
    p  font-size:16px;line-height:1.65;color:#4a4a4a;margin:24px 0 0;max-width:620px;   {greeting}
```

The eyebrow is omitted entirely when there is no issue number (a site's first, unnumbered
preview). When the date is blank or unparseable the ` · {school_year}` part is omitted. The
existing test string `&#8470;&nbsp;039` must still appear.

### Announcement (`render_announcement`) — from #040 lines 303-337 ("FEATURED HERO")

Rendered when any of `when`, `headline`, `text`, `button_text`+`button_url`, or a non-blank
timeline row exists; otherwise `placeholder()`.

```
div[data-ptk-block=announcement]  font-family:FONT_SANS;color:#111;background:#1a2f5c;padding:48px 20px;box-sizing:border-box;
  div  max-width:840px;margin:0 auto;
    div  font-family:FONT_SERIF;font-style:italic;font-weight:500;font-size:15px;color:#ffd166;margin-bottom:14px;   {when}
    h2   font-family:FONT_SANS;font-weight:800;font-size:clamp(32px,6vw,52px);line-height:1.0;letter-spacing:-0.03em;margin:0 0 18px;color:#ffffff;max-width:720px;   {headline}
    div  font-size:16px;line-height:1.65;color:#cfd8e3;margin:0 0 28px;max-width:660px;   {text via wp_kses_post}
    div  max-width:660px;border-top:1px solid rgba(255,255,255,0.25);          ← timeline, only if rows
      div[data-timeline-date="YYYY-MM-DD"] [data-timeline-deadline on the last row]
           display:flex;flex-wrap:wrap;gap:4px 20px;padding:14px 0;border-bottom:1px solid rgba(255,255,255,0.18);   + opacity:0.45; when past
        div  flex:0 0 190px;font-family:FONT_SERIF;font-weight:500;font-size:20px;line-height:1.3;color:#ffffff;font-variant-numeric:lining-nums tabular-nums;   {date as "Mon, Sep 14"}   deadline row: color:#ffd166
        div  flex:1 1 240px;font-size:15px;line-height:1.5;color:#cfd8e3;
             <strong style="color:#ffffff;">{time}</strong> · {what}     deadline row: strong color:#ffd166 ; the " · " only when both present
    div  margin-top:28px;                                                     ← only with button_text AND button_url
      a    display:inline-block;background:#ffffff;color:#1a2f5c;font-size:14px;font-weight:700;letter-spacing:0.02em;padding:14px 24px;border-radius:8px;text-decoration:none;   {button_text}
```

The `h2` is white on navy (HOUSE-STYLE "Callout"). The button is white on navy, as #040 does
inside its navy block (line 331); the house "one navy button per page" rule is not broken
because there is no navy button anywhere. Timeline row states: see Decisions 2 and 3. The
old `pill` chip markup (`class-newsletter-renderer.php:165-168`) is deleted.

### Coming up (`render_events`) — from #040 lines 371-376 and 387-397

```
div[data-ptk-block=events]  font-family:FONT_SANS;color:#111;background:#fff;padding:40px 20px 40px;box-sizing:border-box;
  div  max-width:840px;margin:0 auto;
    div  display:flex;align-items:center;gap:18px;margin-bottom:28px;            ← § rule (shared helper section_rule())
      div  font-family:FONT_SERIF;font-style:italic;font-weight:500;font-size:15px;color:#1a2f5c;white-space:nowrap;   § Coming up
      div  flex:1;height:1px;background:#111;min-width:20px;
    ← round-2 hook: "See full calendar →" is a right-aligned link in a flex row with the h2 below; not built now
    h2   font-family:FONT_SANS;font-weight:800;font-size:clamp(24px,5vw,30px);line-height:1.05;letter-spacing:-0.02em;margin:0 0 28px;color:#111;   What's coming up
    (per row)
    div  display:flex;flex-wrap:wrap;gap:12px 20px;align-items:flex-start;padding:18px 0;border-top:1px solid #e6e3dc; (last row also border-bottom:1px solid #e6e3dc;)   + opacity:0.45; when past
      div  flex:0 0 112px;                                                       ← keep 112px (4.1.1 fix, tested at tests/test-newsletter-renderer.php:151)
        div  font-family:FONT_SERIF;font-weight:500;font-size:30px;line-height:0.95;white-space:nowrap;color:#1a2f5c;font-variant-numeric:lining-nums tabular-nums;   Sep 14      past: color:#6b6b6b (never red)
        div  font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:#4a4a4a;font-weight:600;margin-top:4px;   Monday
      div  flex:1 1 220px;min-width:200px;
        div  font-size:17px;font-weight:600;line-height:1.35;margin-bottom:4px;   {title}
        div  font-size:14px;color:#4a4a4a;line-height:1.5;   {desc}
      span[data-event-date][data-default]  font-size:10px;letter-spacing:0.16em;text-transform:uppercase;font-weight:700;color:#1a2f5c;background:#f0eee7;padding:5px 9px;border-radius:4px;white-space:nowrap;align-self:flex-start;   {label}
```

Change from today: the first row's top border was `1px solid #111` (`:225`); with the § rule
above the list that strong line is the rule itself (HOUSE-STYLE "Event row": *the § rule
above the list is the only strong line*), so every row uses the hairline. The weekday line is
new (`$dt->format('l')`). The red past numeral goes (Decision 8).

### Top story (`render_featured`) — from #040 lines 341-367 and 499-527

```
div[data-ptk-block=featured]  font-family:FONT_SANS;color:#111;background:#fff;padding:40px 20px 8px;box-sizing:border-box;
  div  max-width:840px;margin:0 auto;
    § rule (section_rule())  margin-bottom:16px;   mark = "§ " + (eyebrow or "Top story")
    h2   font-family:FONT_SANS;font-weight:800;font-size:clamp(24px,5vw,30px);line-height:1.05;letter-spacing:-0.02em;margin:0 0 12px;color:#111;   {headline}
    div  font-size:16px;line-height:1.65;color:#4a4a4a;margin:0 0 20px;max-width:620px;   {body}
    figure  margin:28px 0 0;                                                     ← only with an image
      img  display:block;width:100%;height:auto;border-radius:4px;   alt = headline
    p    margin:20px 0 0;
      a  font-size:16px;font-weight:700;color:#1a2f5c;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;   {link_text}
```

The image moves **below** the text (as #040's Film on the Field block) and drops to 4px
corners with no margin-bottom (`maybe_image()` at `:441` currently emits
`border-radius:10px;margin:0 0 18px;` — change it to `border-radius:4px;margin:0;` and let
the figure carry spacing). The navy hero, the yellow eyebrow and the 52px white headline are
gone from this block; that treatment now belongs to the announcement.

### Stories (`render_story_cards`) — same block as Top story, per card

Each card renders exactly the Top story structure with `mark = "§ " + (card.eyebrow or "More
news")`, `padding:40px 20px 8px` on the first card and `padding:24px 20px 8px` on the rest, so
consecutive sections keep the house 40/24 rhythm. The old card box
(`border:1px solid …;border-radius:14px;padding:36px 28px;background:#f6f4ef;`, `:330`) is
deleted. Headline is `<h2>` (a section headline), not `<h3>`.

### Quick notes (`render_quick_notes`) — from #040 lines 566-592 (the "Membership" list)

Rendered when at least one item has a heading, body or link; otherwise `placeholder(
'quick_notes', 'Your quick notes will appear here.', $opts )`.

```
div[data-ptk-block=quick_notes]  font-family:FONT_SANS;color:#111;background:#fff;padding:40px 20px 24px;box-sizing:border-box;
  div  max-width:840px;margin:0 auto;
    § rule  margin-bottom:16px;   mark = "§ " + (label or "Quick notes")
    div  border-top:1px solid #e6e3dc;max-width:700px;
      (per item)
      div  padding:16px 0;border-bottom:1px solid #e6e3dc;
        h3   font-family:FONT_SANS;font-size:17px;font-weight:700;line-height:1.35;color:#111;margin:0 0 4px;   {heading}
        div  font-size:15px;line-height:1.6;color:#4a4a4a;   {body}
        p    margin:8px 0 0;
          a  font-size:14px;font-weight:700;color:#1a2f5c;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;   {link_text}
```

### Footer (`render_footer`) — unchanged except: fonts, and links switch from the
`border-bottom` hack (`:391`) to the underline style above. The round-2 "Got news?" closing
attaches as a new block rendered immediately before the footer; nothing is added now.

### Shared helper

`private static function section_rule( $mark )` returns the § rule flex row above. It is a
private helper whose name does **not** start with `render_`, per the dispatch warning at
`class-newsletter-renderer.php:72-74`.

---

## Reader's-date behaviour (`assets/js/newsletter-relabel.js`)

The server renders every state against `$opts['today']`, which `render_opts()` supplies as
`current_time('Y-m-d')` at save time (`class-newsletter-builder.php:416`) — the day the
issue was saved, not the issue date. That is fine (it is what the event pills do today) because
the script corrects everything for the reader:

1. Event pills: unchanged relabeling, **plus** past rows fade: the pill's row (the closest
   ancestor `div` with `data-event-date`'s parent) gets `opacity:0.45`, the numeral turns
   `#6b6b6b`, and the pill's background becomes `#e6e3dc` / color `#4a4a4a` (#040 lines
   781-788). To find the numeral without a class, the renderer marks it
   `data-event-numeral` and the row `data-event-row`.
2. Timeline rows: every `[data-timeline-date]` gets `opacity` `0.45` when
   `ptkRelabelForDate(date, today) === 'past'`, else `''` (cleared, so a row the server
   greyed on save day un-greys for a reader who opens it earlier — e.g. a preview link).
   The deadline row's yellow is static markup and is never touched by the script.

Pure logic is exported as before (`module.exports`), and `tests/test-relabel-js.mjs` gains
cases for the new pure helper `ptkIsPast(dateISO, todayISO)` (a thin wrapper over
`ptkRelabelForDate`, exported so the test pins its contract).

---

## Share captions (`includes/class-share-text.php`)

- `generate()` reads the announcement as `headline`, `text`, `when` (falling back to `pill`
  for un-resaved 4.1.x meta — cheap insurance even though `context()` sanitizes). The
  Facebook announcement paragraph is the non-blank ones of `[headline, text, when]` joined by
  `"\n"`. A headline-only announcement therefore still produces a line.
- Instagram's lead (`generate_instagram()`, `:246`) becomes: announcement `headline`, else
  announcement `text`, else featured headline. WhatsApp's middle (`:261`): featured headline,
  else announcement headline, else announcement text.
- "Also in this issue" lines = every story card via `story_line()`, then quick-note items via
  the same `story_line()` (they have `heading` + `body`, so it needs no new function), capped
  at **8 lines total**, cards first. `INSTAGRAM_STORY_LINES = 2` is unchanged. Reason for 8:
  #040's hand-written post has six "also" lines; eight leaves room without turning the post
  into a table of contents.
- The no-emoji rule (`strip_emoji()`), the heading-as-line rule, entity decoding and every
  existing assertion in `tests/test-share-text.php` stay green. The existing fixture there
  still uses `pill`; it keeps working because `generate()` falls back to it.

---

## The doubled title

New file `assets/css/newsletter-public.css`, enqueued from
`PTK_Newsletter_Post_Type::enqueue_public()` next to the relabel script (same
`is_singular( self::POST_TYPE )` gate, so the `?ptk_preview=` view gets it too — fact 3):

```css
/* The newsletter's masthead is the page's title (house style rule 5). bb-theme prints its
   own title and date above the content; hide that, never the theme template. */
body.single-pta_newsletter .fl-post-header,
body.single-pta_newsletter .fl-post-meta-top { display: none; }
```

Both selectors, even though the meta sits inside the header today, so a future bb-theme that
moves the date out still hides it. **Do not** filter `single_template` for `pta_newsletter`
(`ptk_single_template()` in `pta-knowledge-hub.php:304-314` is `pta_knowledge` only):
bb-theme's container and the Themer header/footer depend on the theme template.

The same function enqueues the fonts (Decision 10):
`https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,500;1,6..72,500&display=swap`,
handle `ptk-newsletter-fonts`, version `null` (Google's URL is the version).

---

## Decisions

| # | Decision | Chosen, and why |
|---|---|---|
| 1 | **School year rule** | August–July: an issue dated in months 8–12 is `Y–(Y+1)`; months 1–7 is `(Y−1)–Y`. Rendered "2026–2027" with an en dash (from #040 line 289). Reason: Montclair's year starts the first week of September and ends in June; #038 (June 22, 2026) is a 2025–2026 issue, and an August back-to-school issue belongs to the year about to start. July is the only ambiguous month; a July issue is a wrap-up far more often than a preview, so it stays with the year just ended. Implemented as `PTK_Newsletter_Data::school_year_label( $date )`, returning `''` for an unparseable date. |
| 2 | **When a timeline row is past** | At the end of that calendar day: `relabel_for_date( row.date, today ) === 'past'`, i.e. `date < today`. Reason: the time field is free text ("8:30 AM–12:30 PM", "noon") so it can be typed the way it is spoken, the plugin has no reliable reader timezone, and this is exactly the rule the event tags already use — one rule, one JS port, one set of tests. #040's hour-precise fading needed a hand-written script per issue; that is not something a volunteer can fill in. |
| 3 | **"Last row = deadline"** | The last row **as entered** is the deadline (yellow numeral and time, `data-timeline-deadline`); rows are not sorted. "Deadline" is a role, "past" is a state: once the last row's day has passed it greys like the others and stays yellow underneath, exactly as #040's Thu Sept 17 row carries both `color:#ffd166` and `data-fade-after`. No sorting because a volunteer who lists "Mon opens / Thu closes" has already said which is last, and silently reordering rows would surprise them. |
| 4 | **Quick notes in the default layout** | Yes, between Stories and the footer. Empty sections publish nothing (fact 7), so a volunteer who never uses it pays nothing, and it is in the arrange list from the start rather than hidden under "Not included". Old newsletters get it as an excluded shell (fact 5, `sections_to_render()`), so they are unchanged until someone adds it back. |
| 5 | **Field names, labels, help text** | As tabled above. Keys are snake_case matching their neighbours; the character guidance (headline ~30, When ~60, text ~90, story heading ~40, note headline ~40) is help text only — never `maxlength`, never validation — because a longer line still renders, just on two lines. |
| 6 | **Link validation** | New helper `PTK_Newsletter_Data::sanitize_http_url()`: trim → `esc_url_raw()` → keep only if it matches `/^https?:\/\//i`, else `''`. Applied to `announcement.button_url` and `quick_notes.items[].link_url`. Existing `story_cards.cards[].link_url` and `footer.links[].url` keep `esc_url_raw()` so stored data is not silently blanked. Note that WordPress's `esc_url_raw()` prepends `http://` to a scheme-less address, so a volunteer who types `northeastpta.org/traffic` gets a working link in production; the test shim does not do this, so the plan deliberately does not assert on bare domains. **Flag:** #040's own "Email Leslie to volunteer" is a `mailto:`; under this rule a volunteer cannot put a mailto on the announcement button or a quick note. Recorded as a follow-up, not designed around. |
| 7 | **Default § labels** | `featured`: "Top story"; each card: "More news"; quick notes: "Quick notes". The § rule needs a mark, and a blank one would break the pattern; these defaults are the least category-like fallbacks available, and the help text pushes for a real label ("Date change", "Volunteers"). |
| 8 | **Past event dates are grey, not red** | The renderer paints past numerals `#a51d23` (`class-newsletter-renderer.php:222`). House style reserves red for no school, deadlines and urgent; #040 greys past rows. Past → numeral `#6b6b6b`, row `opacity:0.45`. Red is not produced by the renderer at all in round 1 (a "no school" flag is a later feature). |
| 9 | **No automatic re-render of published newsletters** | `post_content` is static HTML written at save time (`persist_newsletter()`, `:345`). A 4.1.x newsletter keeps its old look until someone opens it and presses Update, which re-renders through the new code. Reason: a version-gated rewrite of every `pta_newsletter` on eleven sites with nobody looking is the kind of thing that goes wrong; there is one live test issue today. The changelog says so in plain words. |
| 10 | **The plugin loads the fonts** | Northeast has them in its Customizer CSS; the other ten sites do not, and the Builder's preview iframe has none. Enqueue the Google Fonts stylesheet on the single newsletter view and add the same `<link>` to the preview iframe's `<head>` in `writePreview()`. A duplicate `<link>` on Northeast is harmless (same URL, cached). |
| 11 | **Timeline date format** | `D, M j` → "Mon, Sep 14", the same abbreviation as the Coming up numerals and `format_event_date()`. #040 hand-writes "Sept"; matching the plugin's own dates matters more than matching one hand-typed issue. |
| 12 | **Heading levels** | Masthead `h1`; announcement, Coming up, Top story and each story `h2`; quick-note items `h3`. One h1, every section an h2, matching #040 and giving screen readers a real outline. |

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| A boot-time throw in new Builder JS leaves the wizard inert (documented failure; `node --check` cannot catch it) | All new JS boot code goes after the existing `safeBoot()` calls and inside `safeBoot()`. Nothing new is added to the flat list above `showStep()`. Verified in Playground: steps switch, preview updates. |
| The old `pill` value is lost | Migration inside `sanitize_block_data()`; unit test with literal 4.1.x data; the share text falls back to `pill` as well. |
| A second navy band sneaks back in (house style: one callout) | Renderer test asserts `background:#1a2f5c` appears at most once in a full render, and zero times when the announcement is empty. |
| One-sided border creeps in | Renderer test greps the full render for `border-left` and `border-right` and fails on any hit. |
| Fonts show as Helvetica on the ten non-Northeast sites and in the preview | Decision 10; verification checks a computed `font-family` in the Browser pane. |
| Hiding `.fl-post-header` hides something a future theme puts there that matters | Selector is scoped to `body.single-pta_newsletter`; the header holds only the title, date and invisible microdata today (verified). |
| The masthead is not the page's only `<h1>` | It is the only h1 in the content. Northeast's Themer footer has two `h1.fl-heading` — flagged to Lucas; not a plugin change. |
| Timeline rows greyed on save day for a reader who opens earlier (preview link) | The script sets *and clears* opacity from the reader's date. |
| The existing "Upcoming" heading assertion (`tests/test-newsletter-renderer.php:65, :86`) breaks with the new heading | The test is updated in the same commit as the renderer change (the plan says exactly which lines). |
| Announcement grows to 5 fields + a repeater and stops feeling easy | The timeline is behind a `<details>` disclosure; the button and When are marked Optional; the section intro says "skip it if there isn't one". |
| `blocks_have_images()` misses images in a new place | No new image fields are added; quick notes have none. The existing function covers `featured.image_id` and `cards[].image_id`. |
| Version not bumped → rewrite/cached-asset gates never fire | Task 12 bumps `PTK_VERSION` and the plugin header together; the changelog goes in `update-info.json`. |

---

## Verification (required before the version bump)

1. **Unit tests green:** `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done && node tests/test-relabel-js.mjs`.
2. **A #040-equivalent issue from a fixture.** `tests/fixtures/newsletter-040-blocks.json`
   holds the #040 content as Builder blocks: the ASE announcement (When "Opens Monday, Sept 14
   · 8:30 AM for PTA members", headline, text, the four timeline rows, the "Go to ASE
   registration" button), the nine Coming up rows, "§ ASE volunteers" as the top story, "§
   Date change" (Film on the Field, with its photo id 0 for the test) and "§ Membership" as
   stories, and "Three things worth bookmarking" as quick notes. A renderer test renders it
   with `issue => 40, date => '2026-09-13', today => '2026-09-13'` and writes the HTML to the
   scratchpad; the developer opens it and `newsletter-040-week-of-9-14-26.html` side by side in
   the Browser pane at **840px** and **375px** and compares masthead, announcement, timeline,
   stories, quick notes and Coming up against the values in this spec.
3. **The same issue made through the Builder** in WordPress Playground
   (`npx --yes @wp-playground/cli@latest server --auto-mount "<worktree>/pta-knowledge-hub" --login --port 9400`,
   opened at `http://127.0.0.1:9400`, never `localhost`): every new field entered by hand, the
   live preview updating after each, the disclosure opening, the arrange list showing Quick
   notes, publish, reopen — every value round-trips.
4. **Old data still renders:** insert a `pta_newsletter` in Playground whose `ptk_nl_blocks`
   meta is a literal 4.1.x JSON (with `pill`, without new keys); open it in the Builder (the
   When field shows the pill text, Quick notes is under "Not included"), the preview renders,
   Update saves, the published page renders.
5. **The doubled title is gone** on the published view **and** on a `?ptk_preview=` link.
   Playground has no bb-theme, so this is checked two ways: (a) the enqueued CSS is present
   in the page source of both views in Playground; (b) after Lucas deploys, the live test
   issue is opened in the Browser pane and `document.querySelector('.fl-post-header')` reports
   `display: none`, and `document.querySelectorAll('.fl-post-content h1').length === 1`.
6. **The Builder still boots:** in Playground, with the browser console open, load
   Add New and an existing newsletter: steps switch by sidebar and Back/Next, the live
   preview updates within a second of typing, and there are **no console errors** on load,
   on every step, after adding a timeline row, a story, a quick note, and after reorder.
7. **Captions:** the share panel on step 4 of a published issue shows the announcement
   headline in all three captions and quick-note lines under "Also in this issue".
