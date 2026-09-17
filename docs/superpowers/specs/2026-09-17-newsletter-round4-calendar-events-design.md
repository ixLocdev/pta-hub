# Newsletter Builder — Round 4: add events from Google Calendar (Design Spec)

- **Date:** 2026-09-17
- **Status:** Implemented, release 4.7.0
- **Component:** `pta-knowledge-hub` — Newsletter Builder, Newsletter settings
- **Branch / worktree:** `newsletter-round3-1`
- **Related:** `docs/superpowers/specs/2026-07-21-calendar-widget-design.md` (the
  homepage calendar widget — NOT part of this round, but its §4–§7 ICS
  parsing/classification findings are reused here rather than rediscovered)

## 1. Goal

Every school PTA keeps a Google Calendar. Today a volunteer retypes each
event into the newsletter by hand. This round lets them pull events straight
from that calendar into step 2 ("What's happening") of the newsletter
builder, tick the ones that belong in this issue, and get editable rows —
never an automatic add.

**Decision from Lucas:** one Google Calendar setting per site, same shape
for every school. Built and tested against Northeast's calendar first
(public calendar id `c_fff6acf39aef90218f4e35915d4a2ec3fa80d2e83b6886c824e6b3594f80a285@group.calendar.google.com`).
The id is **not** hardcoded into the plugin — Lucas pastes it into the new
setting per site, same as every other Newsletter settings field.

## 2. Two calendar settings, kept separate

`class-share-settings.php` already had **"Calendar page on your website"**
(`ptk_calendar_url`) — a link to a page a human reads. This round adds
**"Google Calendar (for adding events)"** (`ptk_gcal_ics_url`) — a machine
feed the plugin reads. They serve different purposes and are easy to
confuse, so the two fields sit next to each other with distinct labels and
help text, and neither field's value ever substitutes for the other's.

## 3. Accepting whatever a volunteer pastes

Google Calendar's own "Get shareable link" button hands out several
different shapes of address depending on which UI a volunteer used to copy
it, and none of them is the "Public address in iCal format" the help text
asks for. `PTK_Calendar_Source::normalize()` (`includes/class-calendar-source.php`,
pure PHP, unit-tested in `tests/test-calendar-source.php`) accepts:

- the public `.../ical/<id>/public/basic.ics` address itself
- a bare calendar id (`...@group.calendar.google.com` or `...@gmail.com`)
- an "embed" share link (`.../calendar/embed?src=<id>&...`)
- a short "cid" share link (`.../calendar/u/0/r?cid=<base64 id>`)

All four normalize to the same canonical public `.ics` URL, which is what
gets stored and fetched. Anything else is rejected with a plain, actionable
message pointing at Settings → "Integrate calendar" → "Public address in
iCal format."

## 4. Test-fetch on save

Saving the setting immediately fetches the calendar once (`wp_remote_get`,
8-second timeout) and reports a plain result:
`PTK_Share_Settings::calendar_test_message()` (pure, unit-tested) turns a
fetch outcome into one of:

- `"Found 42 upcoming events."` (or the singular / zero-event phrasing)
- `"That calendar isn't public yet. …"` — 404/410 response
- `"Couldn't reach that calendar. …"` — network failure or non-2xx
- `"That address didn't return a calendar. …"` — 2xx response with no
  `BEGIN:VCALENDAR` in the body

This is a genuinely separate fetch from the one step 2's panel uses later —
it exists purely as immediate feedback on save, so a volunteer never
wonders whether the paste "worked."

## 5. The ICS reader (`includes/class-ics-reader.php`)

Pure PHP, no WordPress, no network — `PTK_Ics_Reader::events_in_range()`
takes raw `.ics` text plus a `[start, end]` display window and returns
concrete event occurrences. It deliberately reuses the hard-won rules from
the calendar widget spec (§6–§7) rather than re-deriving them:

- **Line unfolding** — a continuation line starts with a single space/tab,
  which is stripped and concatenated onto the previous line, no space
  inserted (RFC 5545's own unfolding rule, not "add a space").
- **All-day events** (`VALUE=DATE`) are floating dates, never timezone
  converted. `DTEND` is exclusive, so the display end is one day back —
  the round3-1 fixture's "Winter Break" (`DTSTART:20261228` /
  `DTEND:20270101`) renders as Dec 28–31, not Dec 28–Jan 1.
- **Timed events** carry either a bare UTC `Z` timestamp or a `TZID`; both
  convert to `wp_timezone()` for display. Verified against the real
  Northeast fixture's "PTA Meeting!" (`20260227T000000Z`), which is
  2026-02-26 in America/New_York, not Feb 27.
- **Multi-day events** render on their start day; the caller (JS) appends
  "through Fri, Oct 3" when `end !== start`.
- **RRULE expansion** — DAILY/WEEKLY/MONTHLY with COUNT, UNTIL, BYDAY,
  capped at `PTK_Ics_Reader::MAX_OCCURRENCES` (60) and always bounded by
  the requested display range. `EXDATE` removes specific occurrences.
  `RECURRENCE-ID` events override a single occurrence (including moving
  its time); a `RECURRENCE-ID` override with `STATUS:CANCELLED` removes
  just that one occurrence, while a `STATUS:CANCELLED` **master** (no
  recurrence) is dropped entirely. Neither real fixture contains a VEVENT
  RRULE (the district fixture's two RRULEs live inside `VTIMEZONE` DST
  rules and are correctly never read as event recurrence), so this part
  is covered by synthetic fixtures in `tests/test-ics-reader.php`, per the
  original spec's own testing plan (§12).
- **TEXT unescaping** — `\\` → `\`, `\;` → `;`, `\,` → `,`, `\n`/`\N` →
  newline. Verified against the real fixture's escaped-comma `LOCATION`
  values ("Porta\n499 Bloomfield Ave\, Montclair\, NJ …").

## 6. Server: fetch, cache, classify (`includes/class-ics-events-ajax.php`)

`PTK_Ics_Events_Ajax` is the AJAX endpoint (`wp_ajax_ptk_calendar_events`,
nonce-checked, `edit_posts` capability — the same gate the rest of the
builder uses, not `manage_options`, since any volunteer who can write a
newsletter can pull from an already-configured calendar) that step 2's
panel calls. It:

- **Caches the raw feed** in a 10-minute transient
  (`PTK_Ics_Events_Ajax::cache_key()`), with a `refresh` flag that bypasses
  it — the panel's "Refresh" link sets this.
- **Computes the requested range** (`range_for()`, pure, unit-tested):
  "This week" is the *issue's* week (Monday–Sunday), via the same
  `PTK_Newsletter_Data::issue_week_monday()` logic the rest of the builder
  already uses — not today's week — so the default matches what the
  newsletter is actually covering. "Next week" / "Next 2 weeks" / "This
  month" are computed from that same Monday.
- **Classifies each title** (`classify_title()`, pure, unit-tested) using
  only the calendar-widget spec's §4.1 title-keyword rules (word-boundary
  matching, parenthetical segments stripped first, an explicit "school
  open" always wins over "No school"/"Special schedule"). This round has
  exactly one configured calendar per site, so there is no second feed or
  UID-prefix signal to distinguish "School date" from "PTA event" by (spec
  §4.2/§4.3) — anything that isn't a closure or a schedule change is left
  untagged rather than guessed at. The regression cases from the original
  spec ("Pancake Breakfast" staying untagged, "School OPEN (Formally
  Spring Recess day 1)" never reading as *No school*) are re-tested here
  against the new classifier, not assumed to carry over.

## 7. Client: the inline panel (`assets/js/newsletter-builder.js`)

`PTK_Newsletter_Builder::render_calendar_import()` renders, above the
events repeater on step 2, either:

- an **"Add from your calendar"** button + empty mount point, when a
  calendar is configured, or
- a one-line hint linking to Newsletter settings — shown only to people
  who can act on it (`manage_options`), so a volunteer without that
  capability isn't shown a dead end.

Everything else is built client-side from the AJAX JSON: range chips,
events grouped by day (checkbox, date, time or "All day", title, location,
classification tag when present), "Already added" detection (same date +
case-insensitive title as an existing row, checkbox disabled), and "Add
selected (N)". Adding appends real repeater rows via the existing
`addRow()` helper — no new row-rendering logic, so every existing behavior
(focal-point pickers, validation, live preview refresh) keeps working
unmodified. Title emoji is stripped for the added row (`stripEmoji()`,
replacing each pictographic character with a space rather than deleting
it outright, so an emoji sitting flush against text doesn't fuse the two
words on either side of it) and trimmed; the detail field becomes plain
English ("9am · Gym", "All day", "through Fri, Oct 3" appended for
multi-day events).

**Not implemented:** automatic date-sorting of the events repeater after
an insert. The plan called for this only "if the repeater is date-sorted
today" — it isn't (rows are simply appended in the order added, matching
existing behavior), so nothing was added here; sorting the whole repeater
would be new behavior outside this round's scope.

## 8. What's explicitly out of scope

- The homepage calendar **widget** itself (shortcode/block, the four-color
  chip palette, the "Also hidden: N…" footer line) — that's the other
  spec's own deliverable, not built here.
- District-feed / UID-prefix classification (spec §4.2–§4.3) — no second
  feed exists in this round to classify against.
- Per-event manual override list (spec §4.4) and the admin classification
  preview (spec §9.2) — those belong to the widget's own admin screen.
- Network-level inheritance of a calendar URL (spec §9.3) — each school
  pastes its own; there is no network-level default in this round.

## 9. Testing

- `tests/test-ics-reader.php` — line unfolding, TEXT unescaping, the two
  real fixtures (known events in known weeks, the UTC day-shift case, the
  exclusive multi-day `DTEND`, escaped-comma `LOCATION`), plus synthetic
  RRULE (WEEKLY+COUNT, DAILY+UNTIL+EXDATE), RECURRENCE-ID override,
  STATUS:CANCELLED (both master and override), and TZID cases.
- `tests/test-calendar-source.php` — all four accepted input shapes, plus
  whitespace, empty-means-clear, and rejected garbage.
- `tests/test-ics-events-ajax.php` — `range_for()` against a real issue
  date (including the Sunday-rolls-forward case shared with
  `issue_week_monday()`), and `classify_title()`'s keyword regression
  cases from the original spec.
- `tests/test-share-settings.php` — `calendar_test_message()`'s four
  outcomes.
- Live feed: the reader was run from the command line against a fresh
  curl of Northeast's actual public `.ics` (northeastpta.org's GridPane
  blocks curl; Google's calendar endpoint does not), listing October 2026
  — 6 events including "Back-to-School Night" (6:00pm Oct 1) and the two
  Parent-Teacher Conference abbreviated days — as a sanity check that the
  reader's output looks right against live data, not just fixtures.
- Playground (`pta-hub-round3-1`, `http://127.0.0.1:9406` — this Playground
  instance *can* reach the public internet, so the live Northeast calendar
  id was used directly rather than a fixture-served stand-in): pasted the
  id into Newsletter settings (test-fetch: "Found 27 upcoming events."),
  then on step 2 opened the panel, switched chips through This
  week/This month, ticked two events, clicked "Add selected (2)" — both
  rows appeared filled and editable, the first got focus, the live
  preview stayed intact, and reopening the panel showed both as "Already
  added" and disabled.

## 10. Honest gaps

- No automated JS unit test for `stripEmoji()` / `calEventDetail()` / the
  panel-rendering functions in `newsletter-builder.js` — they were
  verified by hand in Playground, not covered by a `.mjs` test (the
  existing JS test files cover other, smaller pure helpers; adding a DOM
  harness for this panel was judged out of proportion to this round).
- The classifier's "No school" / "Special schedule" tags are a smaller
  subset of the full widget spec's rules (§3 above) — correct as far as
  it goes, but a school relying on it for anything beyond a cheap visual
  hint should wait for the real widget.
- No network-level default calendar URL, so a council-wide rollout is
  still one paste per school (matches Lucas's stated decision for this
  round, not an oversight).
