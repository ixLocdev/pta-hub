# "What families are looking for" — design (2026-09-21, shipped 4.23.0)

The last of the four remaining Hub screens. Search Analytics was a
dashboard: a range filter of numeric links, four stat cards, a Chart.js bar
chart pulled from a CDN, then three tables — Top Searches, Content Gaps,
Recent Searches.

Decided with Lucas 2026-09-21: it stops being a screen of numbers and
becomes a screen you act on.

## The order is the argument

1. **What nobody could answer.** Searches that found nothing, most-searched
   first, each a card: the words they typed, "9 people looked for this, most
   recently today", and **Write the answer** — straight into Create Entry
   with the term already written in as the question (`ptk_prefill_title`,
   the same argument the router and the old Content Gaps button use).
   Carries the screen's one stamp: WAITING FOR YOU.
2. **What they found**, underneath and quieter: "ASE — found 7 times".
3. **The numbers**, folded and closed: the four counts, the range as words
   (This week · This month · Last 3 months · This year · All time), a
   day-by-day bar chart, and "Download the spreadsheet".
4. **Recent searches**, its own closed fold.

When every search found something the screen says so and shows no empty
table.

## Chart.js is gone

The old screen pulled `chart.js` from a CDN with a raw `<script src>` — an
external request out of eleven schools' admin screens, for one bar chart
most people never scroll to. The new screen draws the bars in plain HTML
styled by `.ptk-days*` in `hub.css`; the only inline value is each bar's
width, which is the datum itself. The CDN tag still exists in the old
dashboard path, untouched, and runs only with the look off.

## Unchanged on purpose

`handle_csv_export()` and its nonce are exactly as they were — the export is
hooked on `admin_init`, outside this screen's render path, and still works.
The old dashboard, including its `analytics-admin.css` enqueue, renders
byte-for-byte as before when the look is off.

Known, pre-existing and deliberately not touched: the export requires
`manage_options` while the screen itself requires `edit_posts`, so a
volunteer who can see the numbers cannot download them.

## A note on proving look-off here

This screen prints relative times ("2 minutes ago") from real data, so two
fetches minutes apart differ by themselves. Normalize both `"time":"\d+"`
(WordPress's own) and `\d+ (minute|hour|day)s? ago` before comparing, or
the proof reports a difference that has nothing to do with the change.
