# PTA HUB — Homepage Calendar Widget (Design Spec)

- **Date:** 2026-07-21
- **Status:** Approved design, pre-implementation
- **Author:** Lucas Deichl (with Claude)
- **Component:** New calendar feature in `pta-knowledge-hub`
- **Related workstream:** `NEPTACalendarAutoSync` (separate repo, runs in parallel — see §10)

All feed measurements below were taken from the live Northeast PTA public
calendar on 2026-07-21 (129 events). A snapshot of that feed is committed as a
test fixture (§12).

---

## 1. Goal

Replace the ICS Calendar (r34ics) plugin on the northeastpta.org homepage with a
calendar the hub owns, so that:

1. **Calendar edits actually show up.** Today they don't, and the reason is the
   plugin's cache, not the feed (§2).
2. **A parent can tell school dates from PTA events at a glance** — the thing the
   current calendar can't do at all.
3. **Any school on the network can turn it on** by pasting one URL.

**Success:** a parent glancing at the homepage on a phone knows within two
seconds whether their kid has school, whether they need an early pickup, and
what the PTA is doing next.

## 2. Context — what's actually broken

- The Google feed is **healthy and instant**. It responds
  `cache-control: no-cache` and its newest `LAST-MODIFIED` was minutes old when
  measured. Google is not the bottleneck.
- The site runs **r34ics**. Observed live on 2026-07-21: the homepage HTML
  contains `class="r34ics-ajax-container loading"`. This is a live-page
  observation, not something recorded in either repo. r34ics caches feeds in a
  WordPress transient and serves the cached copy.

So the staleness is a cache we don't control. **A rebuild genuinely fixes the
complaint** — this is not an upstream lag we would inherit.

## 3. Categories

Four. Three carry the meaning a parent needs; the fourth is the honest bucket for
school dates that are neither a closure nor a schedule change.

| Category | Meaning | Real examples |
|---|---|---|
| **No school** | Closures, holidays, recess | "Labor Day - District Closed", "Yom Kippur - District Closed" |
| **Special schedule** | Abbreviated days, early dismissal, delayed opening, conferences | "Abbreviated Day for Students and Staff", "NE: 2 Hour Delayed Opening" |
| **PTA event** | Anything the PTA runs | "Fall Global Festival", "SoccerThon", "Ice Cream Social" |
| **School date** | School-calendar dates that change nothing about the day's schedule | "First Day of School", "District Reopens" |

> **Decision needed from Lucas.** The brainstorm settled on three categories.
> "First Day of School" and "District Reopens" fit none of them, so a fourth was
> added rather than mislabeling them. If a fourth chip feels like one too many,
> the alternative is folding these into *Special schedule* — but that would tell
> parents to expect an early pickup on the first day of school, which is wrong.

## 4. Classification

Rules run **in this order**, first match wins. Title keywords run before source,
because a title that says "Closed" is a stronger signal than which feed it
arrived on.

### 4.1 Step one — title keywords, applied to every event

Regardless of source feed or UID:

- **No school** — `Closed`, `Recess`, `No School`, `Break`
- **Special schedule** — `Abbreviated`, `Early Dismissal`, `Delayed Opening`,
  `Conferences`, `P/T Conf`

This step is what catches the school dates sitting on the PTA feed (§4.3), and
it is the reason the §4.5 default is rarely reached.

**Match semantics — naive substring matching must not be used here either.**
Measured against the real feed, it produces the worst error this spec defines:

- `Break` as a substring matches **"Pancake Breakfast"** (2025-11-15, a PTA
  event) → *No school*.
- `Recess` as a substring matches **"School OPEN (Formally Spring Recess day
  1)"** (2026-03-30) → *No school*, on a day whose title says school is open.

So:

1. **Strip parenthetical segments** before matching. This is what saves "School
   OPEN (Formally Spring Recess day 1)"; word boundaries alone do not.
2. **Match on word boundaries**, not substrings. This is what saves "Pancake
   Breakfast" — `Breakfast` is not the word `Break`.
3. **Never classify as *No school*** a title containing `OPEN`, `School Open`, or
   `SCHOOL OPEN`. An explicit statement that school is open outranks every other
   keyword. (Real case: "Eid al-Fitr - SCHOOL OPEN!")

Anything these rules still get wrong is what §4.4 overrides and the §9.2 admin
preview exist for.

### 4.2 Step two — source feed

Two feeds. For events with no keyword match, category comes from **which feed the
event arrived on**:

- **District feed** — one URL, set once by PTAC at network level, inherited by
  every school. Its events become *School date*.

  The URL is `https://www.montclair.k12.nj.us/ICalendarHandler?calendarId=889662`
  — verified live on 2026-07-21: `text/calendar`, 117 events, no auth. The
  district's calendar page does not link it; it is reachable only through the
  page's `subscribeToICalAndRssFeeds` action. Treat it as undocumented and
  therefore liable to change without notice — §9.1's last-good-copy and the
  §9.2 staleness warning are what make that survivable.

  **It requires one extra filter.** The feed is districtwide and includes other
  schools' events (measured: 22 Chestnut Hill, 9 Renaissance, 6 Nishuane, 2
  Bradford, 1 Glenfield). Drop any title beginning with another school's name.
  Northeast's own items and genuine districtwide closures are both unprefixed,
  so this is safe. It also removes the duplicate-closure problem the §5 dedupe
  cannot: "Memorial Day - Schools Closed" and "CHB - District closed - Memorial
  Day" share a date but not a title, so only the prefix filter separates them.
  The school-name list is a network-level setting alongside the feed URL.
- **School PTA feed** — set per site. Its events become *PTA event*, subject to
  §4.3.

This is exact and needs no heuristics — a school that has done nothing but paste
its own calendar URL is correct on day one.

### 4.3 Step three — UID prefix, for events on a PTA feed

Northeast's PTA calendar has district dates baked into it from past bulk imports,
and AutoSync will keep adding more. For an unmatched event on a PTA feed, check
the `UID`:

| UID prefix | Origin | Category |
|---|---|---|
| `mps…` | Written by AutoSync (`apps-script/20_identity.gs:26` → `'mps' + sha256Hex(key)`) | School date |
| `CSVConvert…` | Past CSV bulk import | School date |
| `Ical…` | Past ical import | School date |
| anything else | Hand-created in Google Calendar | PTA event |

Google's public `basic.ics` exposes the raw event ID as `UID`, which is what
makes this work. Measured distribution: 36 `CSVConvert`, 47 `Ical`, 46 plain
UUID.

**The plain-UUID bucket is not purely PTA.** At least four hand-made events are
school dates: "School Closed Special Election" (2026-02-05 and 2026-04-16),
"Abbreviated day BOE Special Election" (2026-03-10), and "NE: 2 Hour Delayed
Opening" (2026-02-25) — roughly 4 in 46. All four are caught by §4.1 keywords, so
they classify correctly anyway. That is precisely why keywords run first.

### 4.4 Step four — manual override

An admin list of `event UID → category` overrides. Because §4.1 catches the known
counterexamples, overrides should be rare — but they are a genuine part of the
system, not a theoretical escape hatch, and the admin preview (§9) exists so
mistakes are visible before a parent finds them.

### 4.5 Default

Nothing matched: **PTA event**. Mislabeling a school date as PTA is a smaller
error than telling a parent there's no school when there is.

## 5. Deduplication

**There are zero date+title collisions in the feed today.** The two past import
batches cover different school years (the `Ical` batch is SY2025-26, the
`CSVConvert` batch SY2026-27), so the apparent duplicate "District Reopens"
is in fact two different dates — 2026-01-05 and 2027-01-04. Nothing to collapse.

Dedupe still ships, because the moment a site configures **both** a district feed
and a PTA feed that already contains district dates — which is Northeast's exact
situation, and becomes more so as AutoSync runs — every district date will arrive
twice.

- **Identity:** date + normalized title. Normalization: lowercase, delete periods
  (so `N.J.E.A.` matches `NJEA`), map remaining punctuation to a space, collapse
  whitespace, trim. Several feed titles carry trailing whitespace; trimming is
  required, not cosmetic.
- **Tiebreak, in order:** district-feed copy wins; then UID prefix precedence
  `mps` > `CSVConvert` > `Ical` > plain; then newest `LAST-MODIFIED`. The chain
  must be total so output is deterministic.

**This is deliberately *not* the same identity rule as AutoSync.** AutoSync keys
on normalized title + occurrence index and *excludes the date on purpose*
(`apps-script/20_identity.gs:13-14`) so a rescheduled event keeps its identity.
We key on the date because we are collapsing two feeds describing the same day,
not tracking an event across a reschedule. The two rules answer different
questions and are not expected to agree.

## 6. Time and date handling

The feed mixes formats and gets this wrong in visible ways, so the rules are
explicit.

- **All-day events** (`DTSTART;VALUE=DATE`, 97 of 129) are **floating dates**.
  Never apply a timezone conversion to them. A date is a date.
- **Timed events** (32 of 129) are all UTC `Z` — the feed has no `TZID` and no
  `VTIMEZONE`, while declaring `X-WR-TIMEZONE:America/New_York`. Convert them to
  the site's WordPress timezone for display.
  **This is not cosmetic:** four events land on a different calendar day if
  rendered in UTC — "PTA Meeting!" (`20260227T000000Z` → Feb 26 ET), "Winter
  Dance 3-5" (`20260131T003000Z` → Jan 30 ET), "Globall", and "PTA Virtual
  Meeting!". Rendering those a day late is exactly the failure this project
  exists to fix.
- **Sorting and "is it upcoming"** are computed in site-local time.
- **All-day `DTEND` is exclusive.** `DTSTART:20260826 / DTEND:20260828` is a
  two-day event. The parser subtracts one day. Nine multi-day all-day events
  exist; getting this wrong makes every one of them a day long.
- **Recurring events:** the feed currently contains **zero** `RRULE`s, so this is
  not a live problem — but a volunteer creating a repeating PTA meeting in Google
  Calendar is one click away. The parser expands `RRULE` to concrete instances
  within the display window, capped at 50 per event. If expansion fails, render
  the first instance and log; never drop the event silently.

## 7. Multi-day and repeated events

The feed encodes long breaks two different ways, and both would wreck a
five-slot agenda.

- **True multi-day events** — "Winter Break - Schools Closed" is
  `DTSTART:20261228 / DTEND:20270101`, which after §6's exclusive-`DTEND`
  subtraction is **Dec 28 – Dec 31**. "Spring Break - Schools Closed" is
  `20270322 / 20270326` → **Mar 22 – Mar 25**. Render as a date range on one row
  ("DEC 28 – DEC 31"), occupying one slot. Note how easily this reads as "Dec 28
  – Jan 1": that is the off-by-one from §6, and rendering it would tell parents
  school is closed on a day it reopens.
- **Split single-day events** — the older batch writes recesses as separate
  one-day events: "Winter Recess - Schools Closed" on Dec 26, 29, 30, 31;
  "Spring Recess - Schools Closed" on Mar 31, Apr 1, Apr 2. Left alone, four
  identical rows would eat the whole widget. **Coalesce consecutive
  same-normalized-title events into a single range row.** Consecutive means the
  next calendar day; weekend gaps between school-closure days are bridged
  (Dec 26 → Dec 29 above).

  **This is not a live condition.** Every split run in the feed belongs to the
  historical `Ical` SY2025-26 batch and is entirely in the past as of
  2026-07-21; the forward-looking `CSVConvert` SY2026-27 batch encodes breaks
  exclusively as true multi-day events. Coalescing still ships — the split form
  is one bulk import away from returning, and the historical runs make excellent
  fixtures — but no current parent would see the four-identical-rows failure.
- **In-progress multi-day events count as upcoming** until their last day has
  passed. A parent looking at the homepage during winter break should see that
  break, not the next thing after it.

## 8. The widget

Agenda list — chosen over a month grid because most traffic is phones, where a
grid is mostly empty squares and unreadable dots.

```
┌────────────────────────────────────────┐
│ Coming up                Full calendar │
├────────────────────────────────────────┤
│ AUG │ Play Date K & New Students       │
│  6  │ [PTA event]                      │
├────────────────────────────────────────┤
│ AUG │ Play Date - K + New Students     │
│ 22  │ [PTA event]                      │
├────────────────────────────────────────┤
│ SEP │ Ice Cream Social for Kindergarten│
│  1  │ Only                             │
│     │ [PTA event]                      │
├────────────────────────────────────────┤
│ SEP │ First Day of School              │
│  3  │ [School date]                    │
├────────────────────────────────────────┤
│ SEP │ Labor Day                        │
│  7  │ [No school]                      │
├────────────────────────────────────────┤
│ Also hidden: 4 staff and districtwide  │
│ dates in the next 60 days →            │
└────────────────────────────────────────┘
```

This mock is the literal output for 2026-07-21 under the rules in this spec —
verified by walking §4, §7, §8.1, and §8.2 over the real feed. Titles wrap rather
than truncate; "Ice Cream Social for Kindergarten Only" is the full feed title.

- **Five upcoming events** by default (configurable). Revisit if five proves
  wrong in practice.
- **Display titles are cleaned.** The chip already says the category, so a
  trailing ` - District Closed`, ` - Schools Closed`, or ` - Abbreviated Day` is
  stripped for display: "Labor Day - District Closed" renders as "Labor Day".
  Strip only a trailing suffix, never mid-title text, and never the whole title —
  if stripping empties it, keep the original.
- **Category chip colors are their own four-color palette**, defined by this
  feature. They cannot come from `class-site-colors.php`, which maps
  `blog_id => hex` (one identity color per school) and has no semantic slots — as
  written, all four chips would render the same color. The palette must hold up
  against any school's identity color, so it is independent of it.
- Ships as **both a shortcode (`[pta_calendar]`) and a block**, matching how the
  vendor directory already works.
- **Empty state:** never a blank card. If the next event is far off, show it
  anyway under a line like "Nothing until August." This is a live condition — on
  2026-07-21 the next event is 16 days out.

### 8.1 Filtering

The homepage widget hides staff-only and non-elementary events. Without it,
"Staff Convocation", "Staff Professional Development", "New Teacher Orientation",
and "Freshman and New Student Orientation" crowd the late-August slots.

**Naive substring matching is dangerous and must not be used.** Measured against
the real feed, a bare `Staff` keyword would hide:

- "Abbreviated Day for Students and Staff" — §3's flagship *Special schedule* example
- "Last Day for Students and Staff - Abbreviated Day"
- "Early Dismissal for Students and Staff"
- "Election Day - Schools Closed / Staff Professional Development" — a closure

and a bare `6-12` or `Middle and High School` would hide "Afternoon P/T
Conferences (Elem) / PD 6-12" and "Elementary Evening Parent-Teacher Conferences
/ Middle and High School Professional Development" — both elementary events.
Word-boundary matching does not fix this.

**Match semantics:**

1. Split the title on ` / ` and consider only the **first segment**. District
   titles put the audience-relevant part first.
2. **Never hide** a title containing `Elem`, `Elementary`, `Students and Staff`,
   or `K-12` — these are elementary-relevant regardless of what else they say.
3. **Never hide** an event classified *No school* or *Special schedule*. If it
   changes whether or when a child is in school, it is relevant no matter who
   else it mentions.
4. Otherwise hide if the first segment matches `Staff Only`, `Staff
   Convocation`, `Staff Professional Development`, `New Teacher`, `Freshman`, or
   begins with `Middle and High School`.

Rules 2 and 3 are the safety net; rule 4 is deliberately a short list of specific
phrases rather than loose keywords.

Rule 4's `Middle and High School` clause does no work against the current feed —
both such titles are Abbreviated Days, so rule 3 rescues them first. That is the
intended precedence, not a dead rule: rule 3 outranks rule 4 by design, and the
clause is there for a future title that is genuinely 6–12 only.

### 8.2 Why there is no toggle

**No toggle.** On a glance surface, an off-by-default control is paid for by
everyone and used by almost no one, and its state has to live somewhere.

Instead, the footer line — *"Also hidden: N staff and districtwide dates in the
next 60 days →"* — links to the full calendar page. Nothing is hidden silently,
the count is the proof, and it costs no interaction. This matters because the
filter is rule-based and **will** eventually hide something it shouldn't; a
parent who can't find a known event and concludes the calendar is broken is the
exact failure this project exists to fix.

**The count is defined as:** events excluded by §8.1 with a start date in the
next 60 days from today. Not "this month" — the visible events routinely span two
months (the mock above runs August 6 to September 7), so a calendar-month window
would produce a number that doesn't correspond to anything on screen. Events
merely pushed past the fifth slot are *not* counted; they aren't hidden, just
below the fold, and the "Full calendar" link already covers them. If the count is
zero, the line is omitted entirely.

Filter chips (`All` / `No school` / `Special schedule` / `PTA`) are a genuinely
good idea — on the `/calendar/` page in phase two, where there's room and the
visitor arrived with intent. Not here.

## 9. Data, caching, and admin

### 9.1 Two stores, not one

§2 criticizes r34ics for exactly this, so the distinction matters:

- **Short cache** — parsed events in a 15-minute transient. The first visitor
  after expiry triggers a refresh; everyone else gets it instantly.
- **Last good copy** — the raw feed body plus its fetch timestamp in a persistent
  option (site option for the district feed, blog option for the PTA feed).
  **Not a transient**, so it survives cache expiry and object-cache eviction.

If a fetch fails, serve the last good copy. The homepage never breaks because of
an upstream hiccup. Only if there has *never* been a successful fetch does the
widget render its empty state.

- **HTTP timeout:** 5 seconds.
- **Staleness warning:** if the last good copy is older than 24 hours, the admin
  screen shows a warning. The widget itself stays quiet — a parent can't act on
  it, and an alarming banner on a working calendar is worse than a slightly old
  date.

Rejected alternatives: **WP-Cron hourly** (fires on visits, so on a low-traffic
site it is *less* reliable than fetch-on-demand while being more machinery);
**live fetch every load** (ties homepage speed to Google's servers).

### 9.2 Admin screen

A submenu under the hub menu, following the established pattern
(`class-vendor-moderation.php:67`, `class-newsletter-builder.php:401`), gated on
`manage_options`:

- District feed URL (inherited, overridable) and school PTA feed URL
- **"Refresh now"** — clears both feeds' short cache and refetches immediately.
  The escape hatch for "someone entered the wrong date and we don't want to wait
  15 minutes."
- "Last updated N minutes ago", plus the staleness warning above
- A live preview of every classified event, with its source feed, UID prefix, and
  the rule that decided its category — so a wrong classification is diagnosable,
  not just visible
- Per-event override control (§4.4)

### 9.3 Network inheritance

PTAC sets the district feed URL and the §8.1 filter phrase list at network level,
on a network-admin screen gated on `manage_network_options`. Each school may
override both.

- **An empty site value means inherit**, not "no filter". A school that wants no
  filtering sets an explicit empty-list marker rather than clearing the field —
  otherwise the common case (never touched it) and the rare case (deliberately
  disabled) are indistinguishable.
- **A site's filter list replaces the network list; it does not merge.** Merging
  makes it impossible to remove an inherited phrase, and the resulting behavior
  is hard to reason about from either screen.

**A new school's setup is:** paste their PTA calendar URL, drop the block on
their homepage. Everything else inherits.

Not locked. `class-content-lock.php` is related prior art but is not a settings-
locking mechanism — it filters edit capabilities on network-copied posts — so a
locked mode would be new work, deliberately deferred.

## 10. Relationship to NEPTACalendarAutoSync

The two run **in parallel**; neither blocks the other. The dependency is one-way
and additive: this spec's §4.3 reads UID prefixes that already exist in the feed
today, and AutoSync going live simply starts adding a fourth prefix to a list
already written.

AutoSync does **not** become redundant now that the hub reads the district feed
directly. Its value is putting district dates into the *Google Calendar parents
subscribe to on their phones*; the website is one consumer of that. The two stop
being coupled.

Useful side effect: this widget becomes the easiest way to verify AutoSync's
first non-dry-run — flip `dryRun` to `FALSE` and see on the homepage whether
district dates landed and got colored correctly.

## 11. Structure

Separate units, each independently testable:

| Unit | Responsibility |
|---|---|
| Fetcher | HTTP + 15-minute cache + persistent last-good-copy + timeout |
| Parser | ICS text → event array. Pure function, no network |
| Classifier | Event + source feed → category. Pure function |
| Deduper | Event array → event array, including §7 coalescing. Pure function |
| Filter | Event array → visible array + hidden count. Pure function |
| Renderer | Event array → widget markup |
| Admin | Settings, refresh, preview, overrides, inheritance |

Parser, classifier, deduper, and filter are pure functions over ICS text, testable
against the committed fixture with no network calls. This matters: the
classification and filter rules are the parts most likely to need adjustment, and
they are the parts easiest to test.

## 12. Testing

A snapshot of the real 129-event feed is committed as a fixture. Tests cover:

- **Parser** — line folding (139 folded lines in the real feed), `DTSTART;VALUE=DATE`
  vs timed, escaped commas in `LOCATION` (6 of 12 values), `DESCRIPTION` present
  on 35 events, titles with trailing whitespace, and the placeholder title "New
  Event" (2026-02-01).
- **Time handling** — the four events that shift a calendar day under UTC (§6);
  exclusive all-day `DTEND` on the nine multi-day events; all-day dates never
  timezone-converted.
- **Recurrence** — synthetic `RRULE` fixture, since the real feed has none. Cap
  and failure fallback both exercised.
- **Classifier** — all four UID origins. The `mps` case needs a **synthetic
  fixture**: the real feed contains zero `mps` UIDs because AutoSync is still in
  dry-run. Also the four plain-UUID school dates from §4.3, proving keywords beat
  UID.
- **Keyword match semantics (§4.1)** — "Pancake Breakfast" stays a PTA event;
  "School OPEN (Formally Spring Recess day 1)" and "Eid al-Fitr - SCHOOL OPEN!"
  never classify as *No school*. These are the live false positives naive
  matching produces, so they are regression tests, not hypotheticals.
- **Overrides** — an override beats every automatic rule.
- **Deduper** — same date + title across two feeds (synthetic, since the real
  feed has zero collisions); the full tiebreak chain; the district-copy
  preference when both feeds are configured.
- **Coalescing** — the real split "Winter Recess" (Dec 26, 29, 30, 31) collapses
  to one range row across the weekend gap; the real multi-day "Winter Break"
  renders as one range; an in-progress multi-day event still counts as upcoming.
- **Filter** — every false positive named in §8.1 stays visible; the genuine
  staff-only and Freshman events are hidden; rule 3 keeps a "Students and Staff"
  abbreviated day visible.
- **Footer count** — the 60-day window; events below the fifth slot are not
  counted; a zero count omits the line.
- **Fetcher** — network failure serves last good copy; never-fetched renders
  empty state; last good copy survives short-cache expiry; timeout honored.
- **Inheritance** — site override beats network default; empty site value
  inherits; explicit empty-list marker disables filtering.
- **Empty/sparse state** — using the real 16-day July gap.

## 13. Out of scope (phase two)

The `/calendar/` page stays on r34ics for now. Once the homepage widget is
proven, phase two replaces it with a month grid plus filter chips. Keeping these
separate means the foundation is proven on one widget before touching a page
people already rely on.

## 14. Open items

- ~~Confirm the district's public feed URL.~~ **Resolved 2026-07-21** — found and
  verified, see §4.2. Northeast still works without it (§4.1 and §4.3 classify
  correctly on the PTA feed alone), so it remains an improvement rather than a
  prerequisite — but it is what makes another school's setup a single paste.
- ~~Confirm the fourth category.~~ **Approved 2026-07-21.** Rendered as a neutral
  gray chip, deliberately not a color: the three colored chips mean "act on this"
  (child is home / leave work early / come to a thing), while *School date* means
  "worth knowing, normal day." A colored fourth chip would compete with the ones
  that require a parent to do something.

### Flagged to the AutoSync workstream, not decided here

`NEPTACalendarAutoSync` reads the board-approved district calendar PDF with
Gemini, a design chosen on the belief that no district feed existed. One does
(§4.2). A feed is far simpler and more reliable than PDF extraction — but the PDF
is the authoritative board-approved document, while this feed is undocumented,
rolling-window, and could be restructured without notice. Switching sources
versus keeping the PDF as the authority and using the feed as a cross-check is a
real trade-off, and it belongs to that project.

Either way it does not block this one, and §10 still holds: this widget is the
easiest way to see what AutoSync did.
