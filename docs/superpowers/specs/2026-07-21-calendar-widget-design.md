# PTA HUB — Homepage Calendar Widget (Design Spec)

- **Date:** 2026-07-21
- **Status:** Approved design, pre-implementation
- **Author:** Lucas Deichl (with Claude)
- **Component:** New calendar feature in `pta-knowledge-hub`
- **Related workstream:** `NEPTACalendarAutoSync` (separate repo, runs in parallel — see §9)

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

Measured against the live feed on 2026-07-21:

- The Google feed is **healthy and instant**. It responds
  `cache-control: no-cache` and its newest `LAST-MODIFIED` was minutes old at the
  time of writing. Google is not the bottleneck.
- The site runs **r34ics** — `class="r34ics-ajax-container loading"` appears in
  the homepage HTML. It caches feeds in a WordPress transient and serves the
  cached copy.

So the staleness is a cache we don't control. **A rebuild genuinely fixes the
complaint** — this is not an upstream lag we would inherit.

## 3. Categories

Three, not two. A parent's real questions are *is my kid home?* and *do I need to
pick up early?* — which the district's own titles already distinguish.

| Category | Meaning | Examples from the live feed |
|---|---|---|
| **No school** | Closures, holidays, recess | "Labor Day - District Closed", "Yom Kippur - District Closed" |
| **Special schedule** | Abbreviated days, early dismissal, conferences | "Abbreviated Day for Students and Staff", "Evening P/T Conferences (K-12)" |
| **PTA event** | Anything the PTA runs | "Fall Global Festival", "SoccerThon", "Ice Cream Social" |

## 4. Classification

### 4.1 Primary — by source feed

Two feeds. Category comes from **which feed an event arrived on**:

- **District feed** — one URL, set once by PTAC at network level, inherited by
  every school. All its events are school events, split into *No school* vs
  *Special schedule* by title keywords.
- **School PTA feed** — set per site. All its events are *PTA event*.

This is exact, needs no heuristics, and is correct on day one for a school that
has done nothing but paste their own calendar URL.

### 4.2 Fallback — by UID prefix

Northeast's PTA calendar has district dates baked into it from past bulk imports,
and AutoSync will keep adding more. For any event on a PTA feed, check the `UID`:

| UID prefix | Origin | Category |
|---|---|---|
| `mps…` | Written by AutoSync (`apps-script/20_identity.gs` → `'mps' + sha256Hex(key)`) | School |
| `CSVConvert…` | Past CSV bulk import | School |
| `Ical…` | Past ical import | School |
| anything else | Hand-created in Google Calendar | PTA |

Verified against the live feed on 2026-07-21 — 36 `CSVConvert`, 47 `Ical`, 46
plain UUIDs, and the plain UUIDs are PTA events without exception. Google's
public `basic.ics` exposes the raw event ID as `UID`, which is what makes this
work.

School-classified events then split *No school* vs *Special schedule* by the same
title keywords as §4.1.

### 4.3 Fallback — manual override

An admin list of event-identity → category overrides, for the stragglers. Known
case: Northeast's hand-made "Abbreviated day BOE Special Election" is a school
date sitting on a PTA feed with a plain UUID.

### 4.4 Default when nothing matches

**Default to PTA event.** Mislabeling a school date as PTA is a much smaller
error than telling a parent there's no school when there is.

## 5. Deduplication

The live feed already contains true duplicates across the two past import batches
— "District Reopens" exists as both a `CSVConvert` and an `Ical` event. Running
both a district feed and a PTA feed that contains district dates will produce
more.

Collapse events sharing the same **date + normalized title**, preferring the
district-feed copy. Normalization follows AutoSync's existing `normaliseTitle`
approach (lowercase, strip punctuation, collapse whitespace) so the two projects
agree on what "the same event" means.

This stays harmless after AutoSync tidies the source.

## 6. The widget

Agenda list — chosen over a month grid because most traffic is phones, where a
grid is mostly empty squares and unreadable dots.

```
┌──────────────────────────────────────┐
│ Coming up              Full calendar │
├──────────────────────────────────────┤
│ AUG │ Play Date K & New Students     │
│  6  │ [PTA event]                    │
├──────────────────────────────────────┤
│ SEP │ First day of school            │
│  3  │ [School day]                   │
├──────────────────────────────────────┤
│ SEP │ Labor Day                      │
│  7  │ [No school]                    │
├──────────────────────────────────────┤
│ Also this month: 3 staff and         │
│ districtwide dates →                 │
└──────────────────────────────────────┘
```

- **Five upcoming events** by default (configurable).
- Date block, title, category chip. Chip colors inherit from
  `class-site-colors.php` rather than being hardcoded.
- Ships as **both a shortcode (`[pta_calendar]`) and a block**, matching how the
  vendor directory already works.
- **Empty state:** never a blank card. If the next event is far off, show it
  anyway under a line like "Nothing until August." (This is a live condition —
  as of 2026-07-21 there is a three-week gap.)

### 6.1 Filtering, and why there is no toggle

The homepage widget hides staff-only and non-elementary events — titles matching
`Staff`, `Freshman`, `Middle and High School`, `6-12`. Without this, three of the
five late-August slots go to "New Teacher Orientation", "Staff Convocation", and
"Staff Professional Development".

**No toggle.** On a glance surface, an off-by-default control is paid for by
everyone and used by almost no one, and its state has to live somewhere.

Instead, a quiet footer line — *"Also this month: N staff and districtwide dates
→"* — linking to the full calendar page. Nothing is hidden silently, the count is
the proof, and it costs no interaction. This matters because the filter is
keyword-based and **will** eventually hide something it shouldn't; a parent who
can't find a known event and concludes the calendar is broken is the exact
failure this project exists to fix.

Filter chips (`All` / `No school` / `Special schedule` / `PTA`) are a genuinely
good idea — on the `/calendar/` page in phase two, where there's room and the
visitor arrived with intent. Not here.

## 7. Data and caching

**Fetch on demand, cache 15 minutes.** The first visitor after expiry triggers a
refresh; everyone else gets the cached copy instantly.

Rejected alternatives:

- **WP-Cron hourly** — WP-Cron fires on visits, so on a low-traffic site it is
  *less* reliable than fetch-on-demand while being more machinery. An hour is
  also slower than wanted after fixing a typo.
- **Live fetch every load** — ties homepage speed to Google's servers.

**Failure behavior:** if the fetch fails, keep serving the last good copy and
note its age in the admin. The homepage never breaks because of an upstream
hiccup. Only if there has never been a successful fetch does the widget render
its empty state.

## 8. Admin

A submenu under the hub menu, following the established pattern
(`class-vendor-moderation.php:67`, `class-newsletter-builder.php:401`):

- District feed URL (inherited from network, overridable) and school PTA feed URL
- **"Refresh now"** button — the escape hatch for "someone entered the wrong date
  and we don't want to wait 15 minutes"
- "Last updated N minutes ago"
- A live preview of every classified event, so it's visible exactly what the site
  thinks each one is
- Per-event override control (§4.3)

**Network vs site:** PTAC sets the district feed and filter keywords at network
level as defaults; each school may override. Not locked — `class-content-lock.php`
exists if PTAC ever wants that later.

**A new school's setup is:** paste their PTA calendar URL, drop the block on
their homepage. Everything else inherits.

## 9. Relationship to NEPTACalendarAutoSync

The two run **in parallel**; neither blocks the other. The dependency is one-way
and additive: this spec's classifier reads UID prefixes that already exist in the
feed today, and AutoSync going live simply starts adding a fourth prefix to a
list already written.

AutoSync does **not** become redundant now that the hub reads the district feed
directly. Its value is putting district dates into the *Google Calendar parents
subscribe to on their phones*; the website is one consumer of that. The two stop
being coupled.

Useful side effect: this widget becomes the easiest way to verify AutoSync's
first non-dry-run — flip `dryRun` to `FALSE` and see on the homepage whether
district dates landed and got colored correctly.

## 10. Structure

Separate units, each independently testable:

| Unit | Responsibility |
|---|---|
| Fetcher | HTTP + 15-minute cache + last-good-copy fallback |
| Parser | ICS text → event array. Pure function, no network |
| Classifier | Event + source feed → category. Pure function |
| Deduper | Event array → event array. Pure function |
| Renderer | Event array → widget markup |
| Admin | Settings, refresh, preview, overrides |

The parser, classifier, and deduper are pure functions over ICS text, so they can
be tested against a saved copy of the real feed with no network calls. This
matters: the classification rules are the part most likely to need adjustment,
and they are the part easiest to test.

## 11. Testing

- Parser against a saved snapshot of the real feed (129 events), including its
  quirks: line folding, `DTSTART;VALUE=DATE` all-day vs `DTSTART` timed, escaped
  commas in `LOCATION`.
- Classifier against known events of all four UID origins.
- Deduper against the known real duplicate ("District Reopens").
- Filter against the real staff-only and 6–12 titles.
- Fetcher failure path: no network → last good copy served; never fetched → empty
  state.
- Empty/sparse state, using the real three-week July gap.

## 12. Out of scope (phase two)

The `/calendar/` page stays on r34ics for now. Once the homepage widget is
proven, phase two replaces it with a month grid plus filter chips. Keeping these
separate means the foundation is proven on one widget before touching a page
people already rely on.

## 13. Open items

- Confirm the district's own public feed URL (Montclair publishes one; the exact
  URL is not yet recorded here).
- Decide the widget's default event count if five proves wrong in practice.
