# Round 5 — Import from existing posts (design spec)

2026-09-17. Release 4.8.0. Builds on Round 4 (`class-ics-events-ajax.php`,
the calendar panel in `assets/js/newsletter-builder.js`) — same shape, same
inline-panel pattern, new source.

## Why

Many schools already post announcements as regular WordPress posts (one post
per event, or a weekly roundup). A volunteer building the newsletter should
be able to pull those in instead of retyping everything. The Builder never
touches the source post — it only reads it to prefill fields that stay 100%
editable, exactly like the calendar import.

## UX

- "Bring in your recent posts" button + inline panel on step 2 (near Coming
  up) and step 3 (Stories) — same look/pattern as "Add from your calendar":
  a toggle button, an empty `[data-import-panel]` mount, everything else
  built client-side from an AJAX JSON response.
- Panel: chips "Last 2 weeks · Last month" (default 14 days before the issue
  date), a search box, and a list of the site's recent published posts —
  checkbox, thumbnail, title, date, one-line excerpt. Posts already pulled
  into this newsletter show "Already added" and are disabled (source post ID
  stored on the imported item, e.g. `source_post`, mirrors the calendar
  panel's date+title identity key).
- Each ticked post gets a Story / Event / Quick note choice, preselected by
  `PTK_Post_Importer::classify()`.
- "Add selected (N)" fills the Builder per §Mapping below, focuses the first
  new item, and refreshes the live preview — same tail as
  `bindCalendarImport()`'s `[data-calendar-add]` handler.

## Server

`includes/class-post-importer.php` — pure, unit-tested, no WordPress calls
except through thin static wrappers the tests stub out (`sanitize_text_field`
etc., already stubbed in `tests/bootstrap.php`). Mirrors
`PTK_Ics_Reader`'s separation: parsing logic has no side effects.

`includes/class-post-import-ajax.php` — the WordPress-coupled half, mirrors
`PTK_Ics_Events_Ajax`: nonce + `edit_posts`, current site only, `post` type,
`post_status=publish`, returns recent posts + `PTK_Post_Importer` suggestions
as JSON.

## Mapping rules (see class-post-importer.php for the implementation)

- Emoji stripped (same `\p{Extended_Pictographic}` approach as the calendar
  panel's `stripEmoji()`, ported to PHP).
- `post_content` empty or Beaver-Builder-shaped (`[fl_builder...]` shortcode,
  a `_fl_builder_data` post meta the REST/AJAX layer can pass through) falls
  back to the excerpt; if that's also empty, the suggestion is title +
  featured image only, with a "Add a sentence or two" note — this is the
  best a plugin can do without rendering Beaver Builder's saved layout, which
  needs the live site's registered BB modules. Flagged as a known gap in the
  final report.
- Date parsing (`parse_date_from_title()`): leading `M/D |`, leading
  `MONTH D:`, and trailing `– [Weekday,] Month D[st/nd/rd/th]` all strip the
  date from the title and hand back the month/day. `Week of …` is left
  alone (no single date to extract). Body `When:` / `Where:` lines are
  parsed separately (`parse_when_where()`) for event detail/location, using
  the same month-name matcher.
- Year inference: the next occurrence of that month/day on or after
  (issue date − 7 days) — so a bake sale dated just before the issue still
  resolves to this year, not next.
- Links: if the cleaned body is short (≤ ~220 characters) and contains a
  link, the post is "just a pointer" and that first link becomes the
  story/quick-note link; otherwise the permalink is used, wording "Read
  more".
- Flyer-only posts (no usable body text, has a featured image): title + date
  + image, body left empty with the UI note "Add a sentence or two" (note is
  UI-only, never written into the newsletter content).
- Weekly roundups: `detect_sections()` looks for 2+ `<h1-4>` tags first,
  falling back to 2+ ALL-CAPS lines. When found, the panel row offers "Split
  into separate items," which classifies/shortens each section exactly like
  a standalone post (no image per section — the roundup's single featured
  image, if any, stays with the whole-post option instead).
- Shortening: first 1–2 paragraphs, cut to the previous sentence boundary
  once past ~420 characters. UI shows "Shortened from your post — edit as
  you like" (not written into the story body).

## Two testing-driven fixes bundled into 4.8.0

Reported by Lucas from the live 4.7.x testing pass, fixed alongside Round 5
since both touch files this round is already in:

1. **Auto-growing textareas.** Every Builder `<textarea>` (sign-off, story
   body, announcement text, etc.) now grows to fit its content instead of
   clipping text behind a scrollbar — on load, after "Start from last
   issue"/import prefill, on input, and when a hidden step becomes visible
   (a `display:none` step measures 0, so `showStep()` re-measures on
   reveal). Minimum ~3 rows, caps around 12 rows before a scrollbar
   reappears.
2. **Calendar/post import discoverability.** A short line right under step
   2's blurb — "Add dates from your calendar · Bring in recent posts" (only
   the parts that apply — a school with no calendar configured only sees the
   posts link) — jumps to Coming up, opens that panel, and focuses it. In
   Coming up itself, "Add from your calendar" is the primary (filled) button
   and "+ Add event" secondary when the list is empty; once rows exist they
   swap to equal-weight outline buttons. "Bring in your recent posts"
   follows the same primary/secondary rule in its own section.

## Known gaps (report honestly)

- Beaver Builder page-builder posts: this plugin cannot render BB's saved
  module layout outside the live site's registered BB module set. The
  importer falls back to the excerpt, then to title + image only — a real
  BB-built post can only be fully verified on a live site, not Playground.
- "First external link" heuristic is a length/content heuristic, not a
  guarantee — a short post with an unrelated link could be mis-detected as
  a pointer post. The volunteer can always edit the link field afterward.
