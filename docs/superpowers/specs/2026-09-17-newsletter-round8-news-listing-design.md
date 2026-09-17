# Round 8 — Newsletters appear with news posts (design spec)

2026-09-17. Release 4.11.0. Two independent, **default-OFF** settings so
published newsletters can optionally show up alongside regular news posts
— without ever copying the newsletter into a second page. This file is
documentation only — it lives in the repo's `docs/`, not inside
`pta-knowledge-hub/`, so it never ships in the plugin zip.

**Ships off.** Lucas is still testing 4.4.0–4.10.1 on northeastpta.org and
does not want anything new to appear publicly yet. Both settings below
default to unchecked, and upgrading an existing install must not turn
either on — see "Verify" for the explicit baseline check.

## Why two settings, not one

- **Part 1 — "Show newsletters with your news posts."** No duplicate
  content: the newsletter stays one page at `/newsletters/<slug>/`, and
  WordPress's own post-listing queries (blog home, date/author/category
  archives, the main RSS feed) and a school's page-builder "Posts" module
  are taught to include `pta_newsletter` alongside `post`. This is the
  recommended approach — Lucas agreed 2026-09-17 that no-duplicate is
  better than a second page to maintain.
- **Part 2 — "Also add a short news post that links to it."** A fallback
  for the case where Part 1's query-patching doesn't reach a particular
  module (see the coverage table below) or a school wants a distinct
  "teaser" post in feeds that don't understand `pta_newsletter` at all
  (e.g. a Mailchimp RSS-to-email integration hard-coded to `post`). A
  REAL, separate `post`, not a copy of the newsletter's content — see
  "What the linked post contains."

They are independent: either can be on alone, both, or neither. Turning one
off never touches what the other already did.

## Part 1 — `includes/class-newsletter-news-listing.php` (`PTK_Newsletter_News_Listing`)

Option: `ptk_newsletters_in_news` (blog option, boolean, default off).

### Pure decision helpers (WordPress-free, unit-tested)

- `post_type_is_post_or_empty( $pt )` — true for `''`, `null`, `'post'`, or
  `array('post')`. Used to refuse to touch any query that already names a
  different/specific post type (never fight another CPT's own query).
- `slug_is_newsletter_category( $slug )` — case-insensitive match against
  `newsletter` / `newsletters`.
- `should_include_main_query( array $ctx )` — the `pre_get_posts` decision,
  given already-computed booleans (`setting_on`, `is_admin`,
  `is_main_query`, `post_type`, `is_home`, `is_date`, `is_author`,
  `is_newsletter_category`, `is_feed`, `is_singular`, `is_search`). Never
  admin, never a non-main query, never a query that already names a
  specific non-`post` type. **Deliberately excludes `is_search`** — see
  "Search: a deliberate non-change" below.
- `should_include_builder_query( $post_type_value )` — true only when the
  page-builder module's own `post_type` arg is the exact string `'post'`
  (never an array, never empty, never a different CPT).
- `should_auto_update_thumbnail( $current_thumb_id, $auto_prev_id,
  $candidate_id )` — returns `'set' | 'clear' | 'leave'`. `'leave'` whenever
  the current thumbnail is non-zero AND doesn't match what this class set
  last time (i.e. someone chose it by hand) — a manual choice is never
  overwritten. Otherwise `'set'` to the candidate when there is one, else
  `'clear'`.
- `resolve_category_choice( array $existing )` — given a list of
  `{term_id, name, slug}` for the site's categories, returns the term_id of
  an existing "Newsletter"/"Newsletters" category (by slug OR name,
  case-insensitive) or `0` meaning "create one."

### Query integration

- **`pre_get_posts`** (always registered; checks the option itself so
  toggling the setting needs no re-registration): on the **main query
  only**, when `post_type_is_post_or_empty()` and the query is one of —
  the blog/posts index (`is_home()`), a date archive, an author archive,
  the Newsletter category archive (`slug_is_newsletter_category()` against
  the queried category), or any other **main, non-search, non-singular**
  feed — sets `post_type => array('post', 'pta_newsletter')`. Never runs
  on `is_admin()` (so the Newsletters/Posts admin list screens, Quick
  Edit, etc. are untouched) and never on a query that already asked for a
  specific non-`post` type (so another plugin's CPT archive is never
  polluted with newsletters).
- **`fl_builder_loop_query_args`** and **`uabb_blog_posts_query_args`**
  (see "Page-builder modules covered" below): when the module's own
  `post_type` arg is exactly `'post'`, adds `pta_newsletter` to it.
- **Category taxonomy**: `register_taxonomy_for_object_type( 'category',
  'pta_newsletter' )` on `init` (priority 20, after core registers
  `category` and after the post type itself registers at the default
  priority). Registered **unconditionally** — this has no public effect by
  itself (nothing queries by it unless the setting is on or a category
  archive is visited), so it's safe to leave registered even with the
  setting off. pta_newsletter gains no "Categories" metabox in wp-admin
  because it has no native edit screen (`class-newsletter-builder.php`
  redirects `post.php`/`post-new.php` straight to the Builder).
- **Category assignment**: on `save_post_pta_newsletter`, only when the
  setting is ON and the post's status is `publish`, resolve (or create)
  the "Newsletter" category via `resolve_category_choice()` against
  `get_categories()`, and add it to the post if not already there
  (`wp_set_post_categories( …, append )`). Idempotent — safe to run on
  every publish-status save, so a category removed by hand is quietly
  restored on the next save (matches the "regenerated every save" pattern
  the rest of this CPT already follows for title/content).

### List appearance (stored regardless of the setting)

On every `save_post_pta_newsletter` (skipping autosaves/revisions, guarded
against re-entrancy since it calls `wp_update_post`/`set_post_thumbnail`
itself):

- **Excerpt**: recomputed every save from
  `PTK_Newsletter_SEO::build_description()` — the SAME "one-line summary,
  else announcement headline, else 'Newsletter № 041 · Week of …'" pipeline
  Round 3.2 already built and unit-tests for the link-preview description.
  No new logic; reuse only. Always overwritten, the same way title and
  `post_content` are already regenerated from the block data on every save
  (see `class-newsletter-post-type.php`'s docblock) — an excerpt has no
  "manual" state to protect here because the Builder has no excerpt field.
- **Featured image**: resolved via `PTK_Newsletter_SEO::choose_image_source()`
  — also pure reuse from Round 3.2 (top story photo, else the share
  picture's own background photo, else the generated/uploaded share
  picture, else none). Applied via `should_auto_update_thumbnail()`'s
  decision: never overwrites a thumbnail someone (or the block editor,
  hypothetically) set by hand — tracked with a small
  `_ptk_nl_auto_thumbnail_id` meta recording what THIS class set last, so
  "did I set this" survives across requests.
- **Supports**: `class-newsletter-post-type.php`'s `register()` gains
  `'excerpt'` in its `supports` array (`'thumbnail'` was already there
  since 4.1.0) — required for `get_the_excerpt()` / `the_post_thumbnail()`
  to work on this post type at all, regardless of the setting.

This half runs every save specifically because it's cheap, harmless with
the setting off (nothing reads the excerpt/thumbnail unless something
lists the newsletter), and means flipping the setting ON later doesn't
require re-saving every past newsletter to get a decent-looking listing.

### Search: a deliberate non-change

`pta_newsletter` is registered `'public' => true` with no
`'exclude_from_search'` override, so its default is `false` — WordPress's
own search (`is_search()` with no explicit `post_type`) already resolves
to `post_type => 'any'` internally and includes newsletters **today**,
independent of both Round 8 settings. Forcing search's post_type to
`array('post','pta_newsletter')` from `pre_get_posts` would NARROW an
`'any'` search down to only those two types, hiding every other public CPT
(vendor directory, Hub knowledge entries) from search results — a real
regression unrelated to this round. So `should_include_main_query()`
explicitly returns `false` whenever `is_search` is true, and search is left
completely alone. Confirmed as a pre-existing (not new) behavior — see
"Verify."

### Page-builder modules covered

| Module | Filter used | Coverage |
|---|---|---|
| Beaver Builder core **Posts** module | `fl_builder_loop_query_args` (`FLBuilderLoop::query()`) | **Covered.** Documented BB core filter. |
| **PowerPack Content Grid** | `fl_builder_loop_query_args` | **Covered, with a caveat.** Content Grid is a paid PowerPack module (not in the free `powerpack-lite-beaver-builder` GitHub source, so its exact query path couldn't be read directly from this worktree). PowerPack's own changelog documents migrating *other* modules (Advanced Accordion) from a private `pp_accordion_cpt_query_args` filter to the shared `fl_builder_loop_query_args`, and Content Grid's own module extends the same Beaver Builder loop convention as every other PowerPack listing module. Treated as covered on that basis, not independently confirmed against Content Grid's own source. |
| **UABB Advanced Posts** — the module actually live on northeastpta.org's homepage (`fl-node-9k4i2fes5xr8`, `uabb-*` classes) | `uabb_blog_posts_query_args` | **Covered, separately.** UABB's own documentation (`ultimatebeaver.com/docs/filter-query-parameters-advanced-posts/`) confirms Advanced Posts builds its query through `uabb_blog_posts_query_args`, its OWN filter — it does **not** go through `fl_builder_loop_query_args` at all. Hooking only the BB filter would have silently missed the one module Lucas actually needs. Both filters are hooked. |
| Theme's own blog page / archive template | `pre_get_posts` main query | **Covered** (that's exactly what the main-query branch is for). |

Neither UABB Advanced Posts nor PowerPack Content Grid could be installed
in this worktree/Playground to exercise live (both are paid add-ons); the
`uabb_blog_posts_query_args` hook is unit-testable only for its pure
decision (`should_include_builder_query()`) — the actual filter
registration can only be verified once Lucas turns the setting on with
UABB active on a real site. Documented as an honest gap in "Verify."

## Part 2 — `includes/class-newsletter-linked-post.php` (`PTK_Newsletter_Linked_Post`)

Option: `ptk_newsletters_linked_post` (blog option, boolean, default off).
Independent of Part 1's option — reads it only to show the settings-page
warning note (see below), never to gate its own behavior.

### Behavior, driven by `save_post_pta_newsletter` (+ `before_delete_post`)

- **Setting off**: does nothing at all, including leaving alone any linked
  post an earlier ON period already created (per spec: "leave existing
  linked posts alone").
- **Newsletter published** (status `publish`, and it isn't the "EXAMPLE —
  do not publish" newsletter — checked via `PTK_Example_Newsletter::is_example()`
  the same way the excerpt/thumbnail sync and Part 1's category assignment
  both skip nothing special, but a linked post FOR the example would be a
  confusing stray draft, so it's explicitly skipped): create the linked
  post if none is stored for this newsletter yet (`_ptk_nl_linked_post_id`
  meta), else update the existing one **in place** — never a second
  linked post for the same newsletter (`get_post_meta` lookup runs first,
  every time).
- **Newsletter unpublished/pending/private, or trashed**: if a linked post
  exists and isn't already `draft`/`trash`, set it to `draft`.
- **Newsletter permanently deleted** (`before_delete_post`): same
  draft-the-linked-post step, as a last safety net — the newsletter's own
  post meta (holding the link) is about to disappear with it.
- Meta stored **both ways**: `_ptk_nl_linked_post_id` on the newsletter →
  the post; `_ptk_linked_source_newsletter_id` on the post → the
  newsletter (so a stray linked post can always be traced back, and so a
  future "which posts came from a newsletter" listing needs no guessing).
- Re-entrancy guarded the same way as Part 1 (`wp_update_post` inside a
  `save_post` handler would otherwise recurse).

### What the linked post contains

- **Title**: `PTK_Newsletter_Email::subject( $blocks, array('issue'=>…,
  'date'=>…) )` — pure reuse of the exact "PTA Newsletter № 041 — {lead
  headline}" logic Round 7 built and unit-tests for the GiveBacks email
  subject line. No new title logic.
- **Content** (web-friendly HTML — `<p>`/`<h3>`/`<ul>`, no email tables):
  the newsletter's one-line summary (from the `header` block, same
  `PTK_Share_Text::html_to_text()` extraction `choose_description()`
  already does) as an opening paragraph, then an "Inside this issue" list
  — one `<li><a href="…#anchor">headline</a> — teaser</li>` per entry from
  `PTK_Newsletter_Renderer::last_sections()` (the SAME section list and
  anchor ids Round 7's email uses — reuse, not a re-implementation), then
  a "Read the full newsletter →" button-styled link to the newsletter's
  permalink.
- **Featured image**: the same `PTK_Newsletter_SEO::choose_image_source()`
  choice as Part 1's excerpt/thumbnail sync.
- **Category**: "Newsletter," via the same `resolve_category_choice()` /
  create-once logic Part 1 uses (both classes call into
  `PTK_Newsletter_News_Listing::resolve_or_create_category()`, a public
  static method, so there is exactly one place that creates the category
  and exactly one name for it, however either setting is combined).
- **Never** the newsletter's own rendered HTML, and never saved as
  `pta_newsletter` — always a plain `post`, so the theme's normal post
  template handles it and the Builder's own design is never duplicated or
  fought over (the round's explicit "don't save a newsletter AS a regular
  post" rule is about the newsletter itself; this is a deliberately
  short, different, linking document).

### Settings-page note

When Part 2's checkbox is on AND Part 1's is also on, `class-share-settings.php`
shows a plain sentence under Part 2's field: *"Heads up: with both of these
on, a newsletter can show up twice in your news list — once as itself,
once as the short linked post."* No enforcement, no blocking — Lucas may
want both on some sites and not others; this is information, not a gate.

## `includes/class-share-settings.php` additions

New "Newsletters in your news list" section, after the Google Calendar
field and before Contact email (grouping it with the other "how newsletters
behave" settings rather than the more site-identity-flavored fields above
it). Two plain checkboxes:

- **"Show newsletters with your news posts"** — *"Published newsletters
  also appear in your news list, blog page and RSS feed, as if they were
  posts. They stay one page — nothing is copied."*
- **"Also add a short news post that links to it"** — labelled explicitly
  as creating a real, separate post (per the round brief's "label the
  setting clearly as creating a real post"): *"Creates a real, separate
  post — a short summary with a link to the full newsletter — every time
  you publish or update one. Turning this off later leaves any posts it
  already made in place."*

Saved the same way every other Newsletter settings checkbox-shaped option
in this file is saved: plain `update_option()`, a one-line "saved" notice
only when the value actually changed (matching the file's existing
`$previous !== $value` pattern for every other field).

## Testing

`tests/test-newsletter-news-listing.php` and
`tests/test-newsletter-linked-post.php` — pure decision helpers only, same
WordPress-free harness (`tests/bootstrap.php`) as every other pure class in
this codebase:

- `post_type_is_post_or_empty()`, `slug_is_newsletter_category()`,
  `should_include_main_query()` (every context combination: admin, non-main
  query, another CPT's own archive, search excluded, each of
  home/date/author/newsletter-category/feed included), `should_include_builder_query()`.
- `should_auto_update_thumbnail()`: no candidate + no current → `'leave'`
  (well, `'clear'` is a no-op when already empty — tested as idempotent);
  candidate + empty current → `'set'`; candidate changes and current still
  matches the LAST auto value → `'set'` (follow the new choice); current
  differs from the last auto value (a manual pick) → `'leave'`, even when a
  candidate exists.
- `resolve_category_choice()`: exact slug match, name-only match
  (`"Newsletters"` plural), case-insensitivity, no match → `0`.
- Part 2: a small `should_draft( $status )` / `is_publishable_status( $status )`
  pair covering `publish`/`draft`/`pending`/`private`/`trash`/`future`.

The `save_post_pta_newsletter` wiring, `pre_get_posts`/`fl_builder_loop_query_args`/
`uabb_blog_posts_query_args` registration, category creation against a real
`wp_insert_term()`, and the actual linked-post create/update/draft cycle all
need a real WordPress request — verified in Playground per the round
brief's checklist, not unit-tested (same split every other WordPress
integration class in this codebase already uses — see
`class-newsletter-seo.php`'s own docblock for the precedent).
