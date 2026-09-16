# Newsletter Round 2: Set It Once — Design

> **STATUS: READY TO PLAN** (2026-09-16). Product decisions are made and approved by Lucas;
> this document grounds them in the code as it stands on the `newsletter-round2` branch
> (built on round 1 / 4.2.0, commit `16ac13a`). Plan:
> `docs/superpowers/plans/2026-09-16-newsletter-round2-set-it-once.md`.

> **AMENDMENT (2026-09-16, the controller, overriding Part D / Decision 5 below):** the
> Instagram square's two colors do **not** fall back to the Council/school palette
> (`PTK_Site_Colors::color_for()`) at all. Background is `ptk_share_bg_color`, default
> `#1a2f5c` when unset. Text is `ptk_share_color` (same key, so an existing saved pick still
> applies), default `#ffffff` when unset. `PTK_Share_Color::share_color()`'s own council-
> fallback chain is left untouched for any other caller, but the square now reads two new,
> council-free accessors instead: `PTK_Share_Color::square_background_color()` and
> `square_text_color()`. The readability check still compares the two CHOSEN colors and
> adjusts the TEXT color (never the background) when they fail, with a plain message, exactly
> as 4.1.1 already does for the single color. `PTK_Site_Colors::color_for()` (the owner dots)
> is untouched. Everywhere below that describes an "own → Council → default" resolution chain
> for the square specifically (Part D, Decision 5, fact 3) is superseded by this amendment;
> the same chain remains correct for `share_color()` as a general-purpose method, which now
> simply has no caller left that needs its Council branch for the square.

## The problem

Round 1 (4.2.0) made the Builder's output match issue № 040's look, but left four things a
school would otherwise type every single week: the Join-the-PTA link, the calendar link, the
"Got news?" closing, and the footer/section labels a school reuses issue after issue. Round 1's
design doc named these explicitly as round-2 work and left hooks for them (masthead
`class-newsletter-renderer.php:156`, Coming up `:319`, footer `:571-572`).

**The governing rule is still ease of use — easy to use, quick, very little setup.** Round 2's
answer is: a school fills in a handful of settings **once**, and every newsletter after that
carries them automatically. Nothing here is typed per issue.

Source of truth for the target look: `/Users/lucas/apps/PTA/NEPTANewsletter/newsletter-040-week-of-9-14-26.html`
(masthead lines 82-105; the "Got news?" / "SUBMIT TO NEWSLETTER" block lines 505-529 — **this
block sits outside the retired 400-504 range and its styling is current**, unlike lines 400-504
which must never be copied). House style: `/Users/lucas/apps/PTA/HOUSE-STYLE.md`.

## Scope

**In round 2:** one Newsletter settings page (school colors for the square, Facebook link, Join
link, News link, Calendar link, contact email); "start from last issue" auto-prefill (footer,
section labels, issue number); three renderer hooks (logo, Join link, calendar link, "Got
news?" closing) reading those settings; the two-color Instagram square; moving Newsletters
under the PTA Hub menu with a fixed hook-suffix trap; a Start Here card. Version 4.3.0.

**Deliberately not in round 2:** re-rendering already-published newsletters when settings
change (same Decision 9 from round 1: `post_content` is static HTML written at save time — a
setting saved today only affects newsletters saved/updated from today on); a numbered-steps
"how to submit news" block (#040's actual "Got news?" section has a 3-step list with
Northeast-specific policy text — "Send it by Friday at 5 PM" — that isn't data any settings
field collects; round 2's "Got news?" closing is the simpler heading + one line + link version,
matching the § pattern, not the numbered list. A future round could add per-school deadline
text if a school asks).

---

## Facts verified in code (2026-09-16)

1. **The settings page to replace.** `includes/class-share-settings.php`, class
   `PTK_Share_Settings`. Registered at `edit.php?post_type=pta_newsletter` →
   `ptk-share-settings` (`add_page()`, `:216-225`), title "Sharing settings". It already owns
   two blog options: `ptk_share_color` (`PTK_Share_Color::OPTION`, `class-share-color.php:36`)
   and `ptk_share_facebook_url` (`PTK_Share_Settings::FB_OPTION`, `:33`). It already has the
   exact pattern round 2 needs for every new link field: `validate_facebook_url()` (`:58-88`,
   https-only, plain-English errors) and, for email-capable links,
   `PTK_Newsletter_Data::sanitize_link_url()` (`class-newsletter-data.php:338-345` — trim, bare
   email → `mailto:`, `esc_url_raw()`, keep only `https?://` or `mailto:`). **This page already
   avoids the hook-suffix trap**: `add_page()` stores the real return value in `self::$hook`
   (`:40`, `:217`) and `enqueue_assets()` compares against that (`:227-230`), not a hand-built
   string. Round 2 keeps this pattern and extends it — no fix needed here, only in the two
   places that don't do this (fact 8).

2. **The color/contrast machinery is already exactly what "two colors, plain hex box,
   contrast-checked" needs**, and needs no new maths. `PTK_Share_Color::contrast_ratio()`
   (`class-share-color.php:118-126`), `readable_pair()` (`:149-199`, WCAG AA 4.5:1, steps
   toward white on a dark ground / toward black on a light one, `MAX_STEPS = 40`),
   `is_hex()` / `normalize_hex()` (`:239-244`, `:51-67`, accept `1a2f5c`, `#1a2f5c`, `#abc`).
   `PTK_Share_Settings::submitted_color()` (`:134-156`) already resolves "the picker or the
   typed box, whichever the person actually changed" — the exact swatch+hex sync round 2 needs
   for a SECOND color. `assets/js/share-settings.js` already ports the same contrast maths to
   JS (`readableOn()`, `:47-79`) for the live preview swatch, and already wires one
   picker+hex+radio group (`boot()`, `:82` on). Round 2 duplicates this wiring for a second
   field instead of inventing a new pattern.

3. **The square today draws THREE colors, not one**, and none of them is currently a school
   setting for the ground: `PTK_Share_Image::GROUND = '#1a2f5c'` (`class-share-image.php:28`,
   a class constant, not an option) is the fixed navy fill (`render_png()` `:190`,
   `imagefilledrectangle`). `accent_for($color)` (`:111-113`) is `readable_pair($color,
   GROUND)` — the school's own `ptk_share_color`, contrast-guarded against navy — drawn for the
   "NEWSLETTER" eyebrow, the hairline under it, and the week dateline (`:203-225`). A THIRD,
   fixed `white` (`:192`) draws the "ISSUE" label, the issue digits, and the school name
   (`:215-244`). A fourth, fixed `#3a4f7c` hairline (`:193`) draws the two rule lines
   (`:204`, `:234`) and is not tied to either color. Round 2's "background" and "text" map
   onto GROUND and a **single, unified** text role (see Decision 5) — the eyebrow/hairline/date
   "accent" and the issue/school "white" collapse into one text color, because the task's "two
   colors" contract is background + text, not three-or-four roles.

4. **The hash that decides staleness:** `PTK_Share_Data::square_inputs_hash( $issue, $date,
   $school_name, $share_color, $version )` (`class-share-data.php:46-48`) is called from
   `PTK_Share_Image::ensure_square()` (`:612`) with `$accent` (the ALREADY-drawn, contrast-
   guarded color, per the comment at `:607-609`: "two schools whose picks both get corrected to
   the same readable color should not each think the other's square is stale"). Round 2 adds a
   background parameter the same way — hash the drawn colors, not the raw picks.

5. **`default_blocks_for_site()`** (`class-newsletter-builder.php:612-627`) is the exact
   extension point for "start from last issue": today it takes `PTK_Newsletter_Data::
   default_blocks()` and fills in only the header's `school_name` from `get_bloginfo('name')`.
   It is called from `blocks_for_js()` (`:601`) only when there is **no** `$edit_id` — i.e. only
   for a brand-new newsletter — which is exactly the "brand-new newsletter" scope round 2 needs
   (an edit of an existing newsletter must NOT be overwritten by "last issue" data; it already
   isn't, because `blocks_for_js()` takes the `$edit_id` branch instead, `:594-599`).

6. **`next_issue_number()`** (`:663-682`) already implements "last + 1, or 0 (blank, ask) on a
   first run": it queries the highest `ptk_nl_issue` meta across `post_status => 'any'`
   (`:664-673`) and runs the result through `PTK_Newsletter_Data::compute_next_issue()`
   (`:396-398`, `max(1, last+1)`), returning `0` when there are no newsletters yet (`:675-677`).
   `render_page()` already calls it for a new newsletter's `$issue_number` (`:866`), independent
   of `blocks_for_js()`. **Round 2 does not touch issue-number logic at all** — it is already
   correct and already reused; the task's "reuse it" is satisfied by leaving it alone.

7. **The renderer's round-1 hooks**, all exact insertion points already commented:
   - Masthead: `class-newsletter-renderer.php:156`, `// Round 2 hook: the "Join the PTA" link
     goes here, as this row's second flex child` — inside the first flex row (school name +
     logo), which already closes at `:157`.
   - Coming up: `:319`, `// Round 2 hook: "See full calendar" sits right-aligned in a flex row
     with this h2` — immediately before the `<h2>What's coming up</h2>` at `:320`.
   - Footer: `render_footer()` returns at `:571` (closing the two outer divs) after building
     `$html` from `:553`. The "Got news?" closing is a **separate block rendered immediately
     before the footer block**, not inside `render_footer()` — `render()`'s dispatch loop
     (`:76-91`) walks `$blocks` and calls `render_<type>`, so the insertion point is in
     `render()` itself: when the type about to render is `footer` and `$opts['news_url']` is
     non-blank, emit the new section first (see "Renderer changes" below).

8. **Logo today is the SITE ICON, not the theme's custom logo.**
   `render_opts()` (`class-newsletter-builder.php:413-425`) sets `'logo_url' =>
   get_site_icon_url() ?: ''` (`:419`) — that is Settings → General → Site Icon (the favicon),
   not Appearance → Customize → Site Identity → Logo. `render_header()`
   (`class-newsletter-renderer.php:100-172`) already draws it at 32×32 if non-blank (`:119-122`,
   `:153`) — the masthead logo slot itself needs **no renderer change**, only a different URL
   source. The task's "the site's custom logo if set" means `get_theme_mod( 'custom_logo' )` →
   `wp_get_attachment_image_url( $id, 'thumbnail' )`, which is the actual header-logo concept
   bb-theme sites set under Customizer. Decision 6 below keeps the existing site-icon fallback
   so a school that only ever set a favicon does not lose its current masthead icon.

9. **The two hook-suffix traps are real and are exactly these two lines** (identical bug, two
   places), plus the one page that already avoids it:
   - `class-newsletter-builder.php:528`: `if ( 'pta_newsletter_page_' . self::PAGE_SLUG !==
     $hook ) { return; }` inside `enqueue_assets()`.
   - `class-share-panel.php:45`: `if ( 'pta_newsletter_page_' . PTK_Newsletter_Builder::
     PAGE_SLUG !== $hook ) { return; }` inside `enqueue_assets()`.
   - `class-share-settings.php` does **not** have this bug (fact 1) — it is the model to copy.
   - `class-welcome.php:69`: `if ( 'pta_knowledge_page_' . self::PAGE_SLUG !== $hook )` — this
     one is safe today (its parent, `edit.php?post_type=pta_knowledge`, is not moving), but it
     is the same fragile pattern and is noted so nobody "fixes" it by accident thinking it's in
     scope; it is unaffected by moving `pta_newsletter`, not touched by this plan.
   - **Why the string breaks:** `pta_newsletter` is registered with `'show_in_menu' => true`
     (`class-newsletter-post-type.php:68`), which makes WordPress build it as its own top-level
     menu (`menu_position => 6`, `menu_icon => 'dashicons-email'`, `:70-71`) and, per WP core's
     `wp-admin/menu.php`, register the hook prefix for its submenus as `pta_newsletter_page_*`
     because the CPT itself is the top-level page. Once `show_in_menu` becomes the STRING
     `'edit.php?post_type=pta_knowledge'` (nesting it under the PTA Hub parent, exactly how
     `includes/class-vendor-directory.php:110` and `includes/class-suggestions.php:61` already
     do it — `'show_in_menu' => 'edit.php?post_type=pta_knowledge', // Lives under the PTA Hub
     menu`), the CPT is no longer a top-level page, and WordPress's hook-prefix derivation for
     its submenus changes. **The exact resulting string is not asserted here** — hand-deriving
     WP core's `get_plugin_page_hookname()` branch for a nested CPT is guesswork, and guessing
     wrong is exactly the failure mode this trap causes. The fix is to stop hand-building the
     string at all: capture the return value of `add_submenu_page()` (as `class-share-
     settings.php` already does, fact 1) and compare against that captured value in
     `enqueue_assets()`. This is provably correct for any parent, because it is WordPress's own
     answer to "what hook does this page get," not a guess about it.
   - `PTK_Newsletter_Builder::url()` (`:504-506`) builds `admin_url( 'edit.php?post_type=
     pta_newsletter&page=' . self::PAGE_SLUG )` — an **admin URL by post_type + page query
     args**, not a menu-tree path. This keeps working unchanged after the menu move (WordPress
     resolves `edit.php?post_type=X&page=Y` the same way regardless of where the CPT's menu
     lives), so old bookmarks/links to the Builder are not broken by task 5's menu move. Verify
     this in Playground (task 8) rather than asserting it.

10. **Start Here** (`includes/class-welcome.php`): `get_cards()` (`:137-185`) returns an
    ordered array rendered by `render_page()` (`:209-221`) as a CSS grid, 2 columns desktop / 1
    column ≤782px (`assets/css/welcome.css:13-14`). Each card is `{icon, title, desc, button,
    url, primary, new_tab}`; `primary => true` gets the blue `.ptk-welcome-btn-primary` button
    class (`welcome.css:20-23`). Today "Add or update an entry" (`:141-149`) is the only
    `primary => true` card and is first in the array. The task's "first, most prominent card"
    is satisfied by: new card first in the array, `primary => true`; demote "Add or update an
    entry" to `primary => false` (Decision 8) so only one card reads as the default action.

---

## Part A — Newsletter settings (replaces Sharing settings)

### What it holds, and the option keys

| Setting | Option key | New or existing | Sanitizer |
|---|---|---|---|
| Square background color | `ptk_share_bg_color` | **new** | `PTK_Share_Color::is_hex()` / `normalize_hex()`, same submit flow as today's color (fact 2) |
| Square text color | `ptk_share_color` | **existing key, redefined role** (Decision 5) | same |
| Facebook group link | `ptk_share_facebook_url` | existing, unchanged | `PTK_Share_Settings::validate_facebook_url()` (https only) |
| Join the PTA link | `ptk_join_url` | new | `PTK_Newsletter_Data::sanitize_link_url()` (bare email → mailto) |
| Send us your news link | `ptk_news_url` | new | `PTK_Newsletter_Data::sanitize_link_url()` |
| Calendar page link | `ptk_calendar_url` | new | `PTK_Newsletter_Data::sanitize_link_url()` |
| Contact email | `ptk_contact_email` | new | `sanitize_email()`; kept only if `is_email()` passes |

All seven are **blog options** (per-site), exactly like the two existing ones — nothing here is
a network/site option, matching the class docblock's existing rule
(`class-share-settings.php:9-14`).

**Blank settings simply don't appear anywhere.** Every renderer hook and the Start Here card
check for a non-blank value and render nothing when blank — the same pattern round 1 already
uses for every block (`placeholder()`, `class-newsletter-renderer.php:636-654`) and that
`class-share-settings.php` already uses for the Facebook link (no button when empty,
`class-share-panel.php` — the Facebook section is conditional today; verify at plan time which
exact line gates it).

### Page identity: same slug, same class, new title

**Decision 4** (below) is: keep the file `includes/class-share-settings.php`, the class
`PTK_Share_Settings`, and `PAGE_SLUG = 'ptk-share-settings'` unchanged; add the five new fields
to the same `render_page()` / `handle_save()`; change the page title and the `<h1>` from
"Sharing settings" to "Newsletter settings"; change the submenu label the same way
(`add_page()` `:216-225`, the two string args at `:219-220`). This satisfies "REPLACES the
existing Sharing settings page" — a volunteer who has the old page bookmarked or open lands on
the same URL and sees the newsletter settings, not a 404 or a second page to find. Renaming the
file/class was considered and rejected: it would touch `pta-knowledge-hub.php`'s require list,
every place that calls `PTK_Share_Settings::page_url()` (`class-share-panel.php` likely links
to it — verify at plan time), and buys nothing a user can see.

### Migrating `ptk_share_color`

No `admin_init` migration code is needed. The option is **kept under its existing key** and its
resolution chain (own → Council's `ptk_site_colors` → a default) is **unchanged in shape**; only
the FINAL fallback default changes, from navy to white (Decision 5). A school that already set
its own `ptk_share_color` keeps seeing exactly the same value, now drawn as the square's text
color instead of its "accent" role — visually the same pixels, since the "accent" role already
drew that color as text (fact 3: eyebrow, hairline, dateline). This is safe to state as a
migration in the changelog wording, but requires zero migration code.

---

## Part B — "Start from last issue"

### What copies, and from where

A brand-new newsletter (`blocks_for_js()` with no `$edit_id`,
`class-newsletter-builder.php:594-601`) pre-fills, from the **most recent `pta_newsletter` post
on this site, any status** (same query shape as `next_issue_number()`, fact 6):

- **Footer** (`footer.signoff`, `footer.links[]`) — copied wholesale.
- **§ section labels**: `featured.eyebrow` ("top story label") and `quick_notes.label` ("quick
  notes label") — copied individually, each only if non-blank in the source.
- **Issue number** = last + 1 (fact 6, unchanged code path — `render_page()`'s
  `next_issue_number()` call already does this for the visible issue field; no new code needed
  here beyond what fact 6 already does).

**Does NOT copy:** stories, events, the announcement, the greeting, or the summary — the task's
"nothing stale" list. `story_cards.cards[].eyebrow` is per-card, not a section label, and is not
copied either (only `featured.eyebrow` and `quick_notes.label`, matching the task's exact
wording "top story label, quick notes label").

### Where this lives in code

Extend `default_blocks_for_site()` (`class-newsletter-builder.php:612-627`):

1. Find the most recent `pta_newsletter` post id, any status — factor the `get_posts()` call at
   `:664-673` out of `next_issue_number()` into a small private helper (e.g.
   `most_recent_newsletter_id()`) so both `next_issue_number()` and
   `default_blocks_for_site()` share one query instead of running it twice per page load.
2. If found, decode and sanitize its `ptk_nl_blocks` meta (same
   `json_decode(...) → PTK_Newsletter_Data::sanitize_blocks()` pattern as `blocks_for_js()`'s
   edit branch, `:598`).
3. Copy `footer` wholesale into the new default's footer block. Copy `featured.eyebrow` into
   the new default's featured block when non-blank. Copy `quick_notes.label` into the new
   default's quick_notes block when non-blank.
4. Return the modified defaults, exactly as today for the school-name fill.

This is pure data shuffling inside an already-WordPress-coupled method (it calls
`get_bloginfo()` today) — no new pure-PHP helper is required, and nothing here needs a unit
test harness beyond what a renderer/builder integration check in Playground already covers,
**except** the "which fields copy" rule itself, which is worth a plain-PHP test if it can be
isolated into a pure function `merge_start_from_last( array $defaults, array $last_blocks ) :
array` — do this (Decision 7) so the copy rule is unit-tested without a WordPress post query.

### The plain-English note

`render_page()` (`:842-913`) already prints an intro paragraph right after the `<h1>` and
`render_notice()` call (`:883`, "Four short steps..."). Add one more paragraph, shown only when
`! $edit_id` (a brand-new newsletter — matching the "start from last issue" scope exactly) and a
most-recent newsletter was found: `"We copied your footer and section names from No. 041."` —
the issue number is the LAST issue's `ptk_nl_issue` meta (not the new one being started),
formatted with `PTK_Share_Text::issue_label()` the same way the masthead does
(`class-newsletter-renderer.php:131`). Style it like the existing `.ptk-nl-msg` notice class
(full four-sided box, `render_notice()` docblock `:451-454`, never a one-sided accent bar) so it
reads as one family with the save/publish notices, not a new visual pattern.

---

## Part C — Renderer changes

`render_opts()` (`class-newsletter-builder.php:413-425`) gains four keys, all resolved from the
new settings, all defaulting to `''` when unset:

```php
'logo_url'      => self::masthead_logo_url(),   // replaces the get_site_icon_url() line
'join_url'      => PTK_Newsletter_Data::sanitize_link_url( get_option( 'ptk_join_url', '' ) ),
'calendar_url'  => PTK_Newsletter_Data::sanitize_link_url( get_option( 'ptk_calendar_url', '' ) ),
'news_url'      => PTK_Newsletter_Data::sanitize_link_url( get_option( 'ptk_news_url', '' ) ),
'contact_email' => sanitize_email( (string) get_option( 'ptk_contact_email', '' ) ),
```

Running stored option values back through `sanitize_link_url()` here is redundant with what
`PTK_Share_Settings::handle_save()` already sanitized on the way in, but cheap and consistent
with how `render_opts()` already treats every other value as untrusted at render time — match
the existing style rather than trusting storage.

**Decision 6 (logo source):** `masthead_logo_url()` is `get_theme_mod( 'custom_logo' )` →
`wp_get_attachment_image_url( $id, 'thumbnail' )` if set, else the existing
`get_site_icon_url() ?: ''` fallback. A school that has set neither still renders no logo, same
as today.

### Masthead (`render_header()`, fact 7)

At `:156` (the round-2 hook comment, currently the only content of that flex row's second
child), add the Join link when `$opts['join_url']` is non-blank:

```html
<a href="{join_url}" style="font-size:13px;font-weight:700;color:#1a2f5c;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;white-space:nowrap;">Join the PTA for {school_year} →</a>
```

Exact style and text pattern from #040 line 90 (`Join the PTA for 2026–27 →`), with
`{school_year}` computed via the same `PTK_Newsletter_Data::school_year_label( $date )` call
already used two lines above it (`:129`) — do not compute it twice; pass the already-computed
`$year` string into the new markup. When `$year` is `''` (unparseable date), the link still
renders with just "Join the PTA →" (no dangling " for " with nothing after it) — mirror the
existing pattern at `:132` that omits the whole `· {year}` fragment when blank, not just its
value.

### Coming up (`render_events()`, fact 7)

At `:319-320`, wrap the existing `<h2>What's coming up</h2>` and, when `$opts['calendar_url']`
is non-blank, a right-aligned link in one flex row:

```html
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
  <h2 ...>What's coming up</h2>
  <a href="{calendar_url}" style="font-size:14px;font-weight:700;color:#1a2f5c;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;white-space:nowrap;">See full calendar →</a>
</div>
```

When `calendar_url` is blank, render the bare `<h2>` exactly as today (no wrapping div added) —
do not change the markup for schools that never set a calendar link.

### "Got news?" closing (new, before the footer, fact 7)

A new private method, dispatched from `render()` itself rather than from the generic
`render_<type>` loop (it is settings-driven, not a stored block):

```php
foreach ( $blocks as $block ) {
    $type = self::str( $block['type'] );
    if ( self::TYPE_FOOTER === $type && '' !== trim( self::str( $opts['news_url'] ?? '' ) ) ) {
        $out .= self::render_news_cta( $opts );
    }
    ...existing dispatch...
}
```

`render_news_cta( $opts )` — house style, white background, the § pattern (`section_rule()`,
`:624-628`), **never navy** (house rule: at most one navy callout, and the announcement already
owns it):

```
div  font-family:FONT_SANS;color:#111;background:#fff;padding:40px 20px 8px;box-sizing:border-box;
  div  max-width:840px;margin:0 auto;
    § rule  mark = "Your news"
    h2   ...clamp(24px,5vw,30px)...   Got news? Put it in the newsletter.
    p    font-size:16px;line-height:1.65;color:#4a4a4a;margin:0 0 20px;max-width:620px;
         Send it our way and we'll get it in the next issue.
    p    margin:20px 0 0;
      a  font-size:16px;font-weight:700;color:#1a2f5c;text-decoration:underline;...   Open the submission form →
      (+ "  Questions? Email {contact_email}." appended, plain text with a mailto link, only when contact_email is set — matching #040 line 525's exact pattern)
```

Padding `40px 20px 8px` matches the "first-in-a-run" rhythm the stories section already uses
(round 1's `padding:40px 20px 8px` on the first card, `class-newsletter-renderer.php` story
padding rule) so this reads as one more white section in the flow, not a special case. Heading
level `<h2>` (round 1 Decision 12: every section gets an h2).

**This block never renders inside the live-preview-only placeholder system** —
`placeholder()` (`:636-654`) is for a block with no data yet; `render_news_cta()` either
renders (news_url set) or is skipped entirely (blank), same as the Join link and calendar link.
There is no "Your Got news block will appear here" preview placeholder, because there is no
Builder step for it — this is settings-driven, not something a volunteer fills in per issue.

### Reaching the live preview

`writePreview()` and the AJAX preview handler already call the SAME `render_opts()` +
`PTK_Newsletter_Renderer::render()` pair the publish path uses (round 1 fact 5, "the live
preview runs `sanitize_blocks()` and the real renderer, so it shows new fields as soon as the
data model and renderer do"). Because all four new hooks live inside `render_opts()` and the
renderer, **no separate preview wiring is needed** — verify this in Playground (task 8) rather
than adding new code for it.

---

## Part D — Two-color Instagram square

### The color model (Decision 5)

Two roles, not three: **background** (was the fixed `GROUND` constant) and **text** (unifies
the old `accent` role — eyebrow, hairline, dateline — and the old fixed `white` role — issue
label, issue digits, school name — into one settable color). The hairline rules
(`class-share-image.php:204`, `:234`, currently fixed `#3a4f7c`) stay a **computed** mix rather
than a third stored color: `readable_pair()`'s contrast guard already exists for text-vs-
background; the hairline is cosmetic and derives from the background (e.g. lightened/darkened a
fixed amount, matching its current role as "a step off the ground color") — this is an
implementation detail the plan should pin down with a concrete formula and a rendered visual
check, not a new user-facing setting.

### Code changes

- `PTK_Share_Image::GROUND` stays as the **default** background (`'#1a2f5c'`), used when
  `ptk_share_bg_color` is unset — it stops being hard-coded into `render_png()`'s fill call.
- `accent_for( $color )` (`:111-113`) is replaced by a method that takes both colors and returns
  the drawn text color: `text_for( $text, $background )` = `PTK_Share_Color::readable_pair(
  $text, $background )` — same guard, now against the CHOSEN background instead of the fixed
  constant.
- `render_png( array $args )` (`:156-259`): `$args['color']` → `$args['background']` (falls back
  to `GROUND` when absent/blank, via `PTK_Share_Color::normalize_hex()`) and `$args['text']`
  (falls back to `'#ffffff'` when absent/blank — Decision 5's stated default). Every
  `self::allocate($im, ...)` call for `$ground`, `$accent_col`/text, and `$white` collapses to
  two allocations: `$background` and `$text`; every draw call that used `$accent_col` or
  `$white` uses `$text`.
- `PTK_Share_Data::square_inputs_hash()` (`class-share-data.php:46-48`) gains a parameter:
  `square_inputs_hash( $issue, $date, $school_name, $background, $text, $version )`. Update its
  one caller, `PTK_Share_Image::ensure_square()` (`:612`), to pass both drawn colors (matching
  fact 4's rule: hash the DRAWN colors, not the raw option values).
- `ensure_square( $post_id, array $args )` (`:575-...`): `$args['color']` → `$args['background']`
  / `$args['text']`, sourced from `get_option( 'ptk_share_bg_color', '' )` and
  `PTK_Share_Color::share_color()` (unchanged resolution chain, fact/Decision 5) respectively.
- `PTK_Share_Panel::square_html()` (`class-share-panel.php:406-419`) passes `'color' =>
  PTK_Share_Color::share_color()` at `:417` — becomes `'background' => get_option(
  'ptk_share_bg_color', '' ), 'text' => PTK_Share_Color::share_color()`.

### The settings page swatch + preview

Two color controls on the Newsletter settings page, each swatch + hex text box, kept in sync,
reusing `assets/js/share-settings.js`'s existing `boot()` wiring (fact 2) duplicated for a
second `[data-ptk-share-settings]`-scoped field pair. The live preview swatch
(`.ptk-ss-preview`, `:405-413` today) shows **both** colors — background as the swatch's own
background (replacing the fixed navy CSS today), text as the `--ptk-ss-accent` custom property
already used for the preview's eyebrow/rule/issue/date text (`share-settings.css`, not read
here — verify property names at plan time). The contrast warning
(`PTK_Share_Settings::color_message()`, `:97-118`) runs against the CHOSEN background (which may
no longer be the fixed navy `GROUND` constant) rather than `self::GROUND` — its second parameter
becomes the submitted/saved background color, not the class constant.

---

## Part E — Newsletters move inside the PTA Hub menu

### The move

`class-newsletter-post-type.php:68`: `'show_in_menu' => true` → `'show_in_menu' =>
'edit.php?post_type=pta_knowledge'` (fact 9 — the exact pattern `class-vendor-directory.php:110`
and `class-suggestions.php:61` already use). `menu_position` (`:70`) and `menu_icon` (`:71`)
become meaningless for a nested CPT and should be removed (they only apply to top-level menu
entries) — leaving them in place is harmless but dead, so remove them for clarity.

Submenu items under the PTA Hub parent, in order: **Newsletters** (the CPT's own list —
automatic once nested, per WP core), **New newsletter** (`PTK_Newsletter_Builder::add_page()`,
unchanged — still registers under `edit.php?post_type=pta_newsletter`, which is now itself
nested), **Newsletter settings** (`PTK_Share_Settings::add_page()`, unchanged registration
target). Nothing about `add_submenu_page()`'s first argument changes for either — they already
target `edit.php?post_type=pta_newsletter`, which simply now resolves to a nested page instead
of a top-level one.

### Fixing the two real traps (fact 9)

In both `class-newsletter-builder.php` and `class-share-panel.php`:

1. Add a `private static $hook = '';` (or equivalent) alongside the class's other statics.
2. In `add_page()` (builder) / wherever the page is registered (share-panel enqueues for the
   BUILDER's page, not its own — it has no `add_page()` of its own; it hooks the Builder's
   existing hook, see below), capture `add_submenu_page()`'s return value.
3. In `enqueue_assets( $hook )`, compare `$hook` against the captured value, not a rebuilt
   string.

`class-share-panel.php` is a special case: it enqueues assets for the BUILDER's page (`share
panel is rendered on the Builder's step 4`, fact/docblock `class-share-panel.php:8-19`), so it
has no `add_page()` call of its own to capture a hook from. Fix: have
`PTK_Newsletter_Builder::add_page()` store its captured hook in a public accessor (e.g.
`PTK_Newsletter_Builder::page_hook()`), and have `PTK_Share_Panel::enqueue_assets()` compare
against `PTK_Newsletter_Builder::page_hook()` instead of rebuilding the string itself. This
also means `class-newsletter-builder.php:528`'s own comparison should use the same stored value
rather than compute it twice in two classes.

### Old bookmarks

`PTK_Newsletter_Builder::url()` (`:504-506`) is unaffected (fact 9, last bullet) — it is a
query-arg URL, not a menu-tree path. No redirect or rewrite is needed for old bookmarks to the
Builder or to individual newsletter edit links (`redirect_edit_to_builder()`, referenced at
`:67`, also uses `edit.php?post_type=...` query args, not menu paths — verify at plan time it is
unaffected the same way).

### Start Here card (fact 10)

New first card in `PTK_Welcome::get_cards()` (`:137-185`):

```php
array(
    'icon'    => '📰',
    'title'   => "Write this week's newsletter",
    'desc'    => 'Four short steps, with a live preview as you go.' . ( $last_line ? ' Last issue: ' . $last_line : '' ),
    'button'  => 'Start the newsletter',
    'url'     => PTK_Newsletter_Builder::url(),
    'primary' => true,
    'new_tab' => false,
)
```

`$last_line` is built only when a most-recent newsletter exists (same lookup as Part B, factored
into the shared `most_recent_newsletter_id()` helper so `PTK_Welcome` can call
`PTK_Newsletter_Builder`'s helper rather than duplicating the query) — "No. 041 · Sept 13 ·
Edit", where "Edit" links to that newsletter's edit URL
(`admin_url('edit.php?post_type=pta_newsletter&page=' . PAGE_SLUG . '&ptk_nl_edit_id=' . $id)`,
matching `redirect_edit_to_builder()`'s existing target shape — verify exact query arg name at
plan time). Hidden (the whole second line omitted) when there is no newsletter yet — matching
the task's "hidden when none." Demote the existing "Add or update an entry" card
(`:141-149`) to `'primary' => false`.

`class-welcome.php` needs `class_exists( 'PTK_Newsletter_Builder' )` guarded the same way every
other card already guards its class dependency (`:140`, `:152`) before adding the card, and
`current_user_can( 'edit_posts' )` the same way the entry-wizard card does (`:140`) — a
newsletter is edit_posts-capable per `PTK_Newsletter_Post_Type::register()`'s
`'capability_type' => 'post'` (`:75`).

---

## Decisions

| # | Decision | Chosen, and why |
|---|---|---|
| 1 | **One settings page, not several** | All seven settings (two colors, four links, one email) live on one "Newsletter settings" page, replacing Sharing settings in place. Reason: "very little setup" means one place to visit once, not a settings page per feature. |
| 2 | **`ptk_share_facebook_url` keeps its key and its exact validator** | No change to that field at all — it already does everything the other new link fields need, and https-only (not the bare-email path) is correct for a Facebook group URL specifically. |
| 3 | **New link fields use `sanitize_link_url()`, not `validate_facebook_url()`** | Join, News and Calendar links may reasonably be an email in a pinch (e.g. a Google Form fallback is unlikely, but a `mailto:` calendar request is plausible for a small school); round 1's Decision 6 already settled that bare-email links are allowed and easy, and `sanitize_link_url()` is the existing, tested helper for exactly this. Contact email itself is not run through `sanitize_link_url()` — it's stored as a bare address (`sanitize_email()`), not a link, because it is used both as `mailto:` text and potentially as plain display text. |
| 4 | **Keep the file, class and slug for settings; change only the title** | See "Page identity" above. Avoids touching every caller of `PTK_Share_Settings::page_url()` for a rename that buys nothing visible. |
| 5 | **Two color roles (background, text), not three or four** | The existing square already draws three-plus roles (fixed ground, school accent, fixed white, fixed hairline) that a volunteer never asked for and never sees named. The task's contract is exactly two: background and text. `ptk_share_color` keeps its key and becomes the text color (same pixels a school already chose, same visual role — it was already the "colored text" on the square); a new `ptk_share_bg_color` is the ground. Default background is the existing `GROUND` constant (navy); default text is `#ffffff` white outright (not a contrast-guard-derived near-white from comparing navy to itself) — an explicit default reads as intentional, not as an edge case of the contrast maths. |
| 6 | **Logo source: custom_logo first, site icon fallback** | `get_theme_mod('custom_logo')` is the actual "logo" WordPress site owners set; `get_site_icon_url()` (today's source) is the favicon and was likely wired as a placeholder in round 1 since it was the readily-available option. Falling back to it preserves any masthead icon a school already sees rather than regressing to blank. |
| 7 | **"Start from last issue" copy rule is a pure function** | `merge_start_from_last()` is factored out so the exact copy rule (footer wholesale, two named labels only, nothing else) is unit-tested without a WordPress post query — matching the codebase's existing split between pure logic (`PTK_Newsletter_Data`) and thin WordPress access (the builder class itself, fact 5's docblock pattern). |
| 8 | **Only one Start Here card is `primary`** | The newsletter card takes the primary treatment and the first position; "Add or update an entry" (a generic knowledge-base action) steps back to secondary. A page with two blue "primary" buttons has no primary action at all. |
| 9 | **No `admin_init` migration for anything in round 2** | Every new option defaults cleanly to blank/navy/white when unset — there is nothing to backfill. The task's "activation hooks do not fire on the Network Admin upload path" constraint is satisfied by needing no activation-time code at all, which is safer than writing a migration that must also be version-gated correctly. |
| 10 | **No re-render of published newsletters when settings change** | Same reasoning as round 1 Decision 9: `post_content` is static HTML from save time. A school that sets its Join link today does not see it appear on newsletters published last month — only on ones saved/updated from now on. Documented in the changelog, not silently rewritten network-wide. |

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| The nested-CPT hook-suffix string is genuinely unknown until tested | Fact 9's fix removes the need to know it — captured return values are correct by construction. Task 8's Playground check confirms Builder CSS/JS still load after the move, which is the only observable symptom if this were ever wrong. |
| `class-share-panel.php` has no `add_page()` of its own to capture a hook from | `PTK_Newsletter_Builder::page_hook()` accessor (Part E) gives it the Builder's captured hook instead of rebuilding the string. |
| A school's existing `ptk_share_color` suddenly looks different once it becomes "text" instead of "accent" | It does not — fact 3/Decision 5 show the old "accent" role already drew that exact color as text (eyebrow, hairline, dateline); only the OLD fixed-white issue-number/school-name text now also takes that color, which is a real visual change for existing squares. Call this out plainly in the changelog rather than treating it as a silent no-op. |
| The "Got news?" block's copy drifts from #040's actual voice since round 2 deliberately simplifies it | Scope section above states the simplification explicitly (no numbered steps, no hard-coded Friday-5PM deadline) so nobody "completes" it to match #040 exactly by accident. |
| A newsletter's `featured.eyebrow` / `quick_notes.label` copied from last issue is stale if a school renames a section (e.g. drops "Volunteers" for "Membership") | Every field stays fully editable after the copy — this is a starting value, identical in spirit to `school_name` prefill today (round 1 fact/`default_blocks_for_site()` docblock: "Everything stays editable; this only supplies a sensible starting value"). |
| Two new blog options with similar names (`ptk_share_bg_color` vs `ptk_share_color`) invite a typo | Both are class constants (`PTK_Share_Color::OPTION`, a new `PTK_Share_Color::BG_OPTION` or similar) referenced by name, never a raw string, outside the two definitions — grep for `'ptk_share_color'` / `'ptk_share_bg_color'` at review time to confirm no stray literal. |
| Moving the CPT's menu changes nothing about capability checks, but is worth stating | `capability_type => 'post'` (`class-newsletter-post-type.php:75`) is untouched by `show_in_menu`; no access-control risk from this move. |

---

## Verification (required before the version bump)

1. **Unit tests green:** `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" || exit 1; done && node tests/test-relabel-js.mjs`.
2. **WordPress Playground**, `http://127.0.0.1:9404` (always `127.0.0.1`, never `localhost` — it
   auto-logs-in via cookie + 302; probe with
   `curl -s -L -c /tmp/r2.txt -b /tmp/r2.txt http://127.0.0.1:9404/wp-admin/...`):
   - Newsletter settings: save each of the seven fields (including a bad Facebook URL and a bad
     hex code, to see the plain-English errors), confirm the saved values appear in a
     **published** newsletter (logo, Join link, calendar link, Got-news block) and in the
     Builder's **live preview** for a newsletter not yet published.
   - A brand-new newsletter (after at least one exists) shows the "We copied your footer and
     section names from No. X" note, its footer/labels/issue prefilled, and its stories/
     announcement/events/greeting/summary blank.
   - Changing EITHER the background or the text color changes the square's stored hash (fetch
     the square twice, confirm the attachment id changes after each color-only edit).
   - The Newsletters menu is a submenu of PTA Hub; opening Add New and an existing newsletter
     shows the Builder's CSS/JS actually applied (not raw unstyled HTML) and the browser
     console has no errors.
   - Start Here shows the newsletter card first, and its "Last issue" line matches the most
     recent newsletter (or is absent on a fresh install with none).
3. **No one-sided borders**: grep the renderer's new output (the "Got news?" block especially)
   for `border-left` / `border-right` — zero hits, matching round 1's existing renderer test
   pattern.
4. **At most one navy callout**: render a newsletter with an announcement AND all four new
   settings filled in; assert `background:#1a2f5c` (or the school's custom background hex, if
   different from navy) appears at most where the announcement itself renders it — the Got-news
   block and the masthead/events links must never be navy-filled.
