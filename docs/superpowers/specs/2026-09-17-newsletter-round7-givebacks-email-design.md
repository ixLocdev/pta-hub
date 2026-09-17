# Round 7 — Copy email for GiveBacks (design spec)

2026-09-17. Release 4.10.0. Adds a "Copy email for GiveBacks" step to the
Newsletter Builder: a generated teaser-format email (the standard set by
`email-pta-newsletter-040.html`), a generated subject line, and instructions
for pasting both into GiveBacks. This file is documentation only — it lives
in the repo's `docs/`, not inside `pta-knowledge-hub/`, so it never ships in
the plugin zip.

## Why, and what "teaser" means

The GiveBacks weekly send is currently hand-built every week: open last
week's message, duplicate it, retype the whole newsletter into the Unlayer
editor's one HTML block. Round 7 makes that copy-paste instead of retype.

Per `NEPTANewsletter/EMAIL-TEMPLATE-GUIDE.md` and Lucas's 2026-09-13
approval, the newsletter email is **a teaser, not the newsletter**: the navy
announcement callout (the week's one urgent thing, in full), a "§ Inside
this issue" list of one linked row per section/story/dates-group, and ONE
button to the published post. No images, no full story bodies, no dates
list — those stay on the website. `email-pta-newsletter-040.html` is the
literal target; the generator's PHP output should read as the same email
with different words in it.

## Architecture

### `includes/class-newsletter-email.php` (`PTK_Newsletter_Email`)

Pure PHP, WordPress-free beyond the same sanitizing/escaping shims
`class-newsletter-renderer.php` and `class-newsletter-data.php` already rely
on (`tests/bootstrap.php`) — same contract, so it is unit-testable with
plain `php` and reusable outside wp-admin later if needed.

- `generate( array $blocks, array $opts ) : string` — the full HTML
  document (`<!DOCTYPE html>` … `</html>`), ready to select-all-and-paste
  into GiveBacks' Unlayer **HTML** block. Table layout, inline styles,
  web-safe font fallbacks (`FONT_SANS`/`FONT_SERIF`, mirroring the guide's
  stacks), max width 600px, no flexbox/grid, no `<img>`. Returns `''` when
  `$opts['permalink']` is blank — the email is meaningless without a
  working link, and gating that is the caller's job (see UI below).
- `subject( array $blocks, array $opts ) : string` — "PTA Newsletter № 0XX
  — {headline}", where {headline} is the announcement's headline, else the
  top story's headline, else "Week of {Month Day}" (never empty when a date
  is present).

`$opts` mirrors what `PTK_Newsletter_Builder::render_opts()` already builds
for the web render (issue, date, school_name, join/calendar/news urls,
contact email), plus `permalink` and `site_url` which the web renderer never
needed. `PTK_Share_Panel::context()` gains these two so both the share
panel and the email generator read from one place.

### Anchors: `PTK_Newsletter_Renderer::last_sections()`

The email's row links must point at real anchors on the published page, and
must never drift from what the renderer actually emits. Rather than a
second copy of "what counts as a linkable section" living in the email
class, `class-newsletter-renderer.php` now:

- Assigns every anchored block a `id="block-<slug>"` attribute, slugified
  from its own heading/label text (own ASCII fold, not `sanitize_title()` —
  the renderer is deliberately WordPress-free), deduplicated within one
  `render()` call via a reset-per-call `self::$anchors_used` registry.
  Anchored blocks: the top story (`render_featured`), each story card
  individually (`render_story_cards` — previously only the whole list had a
  wrapper, now each card does too), the quick notes section as a whole
  (`render_quick_notes`), the dates group as a whole
  (`render_events` — "coming-up"), and the settings-driven "Got news?"
  closing (`render_news_cta`). The announcement is **not** anchored — it
  becomes the email's own callout directly, the same as `#040`.
- Records `{ id, headline, teaser }` for each of those into
  `self::$sections`, reset at the top of every `render()` call and readable
  immediately after via the new public `last_sections()`. `teaser` is a
  plain-text (tags stripped), ~110-character clip of the section's body —
  for story/top-story that's the body text; for quick notes and the dates
  group it's a short list of item/event titles; for "Got news?" it's the
  settings sentence.

`PTK_Newsletter_Email::generate()` calls `PTK_Newsletter_Renderer::render()`
once (discarding the HTML — it only wants the side effect) to get
`last_sections()`, then builds each "Inside this issue" row as
`<a href="{permalink}#{id}">{headline}</a>{teaser}`. Because this is the
same render pass that (separately, at save time) produces the actual
published HTML with the same ids, the links cannot point at an anchor that
doesn't exist — short of the two calls disagreeing on `$blocks`/`$opts`,
which the shared code path rules out by construction.

**Existing published newsletters:** adding `id` attributes to `render()`'s
output only affects newsletters rendered (i.e. saved or re-saved) from now
on. A newsletter published before this change keeps its already-stored
`post_content` untouched — nothing re-renders old posts automatically — so
old issues are unaffected; only an edit-and-resave (or a fresh newsletter)
gets anchors. This is the same "nothing re-renders automatically" behavior
every prior renderer change in this plugin has had.

### UI — step 5 "Publish & share"

`includes/class-share-panel.php` gains an "Email (GiveBacks)" channel,
same collapsible `<details data-share-channel="email">` pattern as
Facebook/Instagram/WhatsApp (one open at a time — `bindChannelDisclosures()`
in `assets/js/share-panel.js` already treats every `[data-share-channel]`
generically, so the new channel needs no JS changes there), added after the
three existing channels. Only rendered when the newsletter is published
(mirrors `$published` gating already used for the copy/save actions on the
other channels); before that, a one-line note: "Publish first — the email
links to your newsletter."

Contents:
- **Subject line**, read-only text, with "Copy subject".
- **"Copy email code"** — a primary button copying `generate()`'s full HTML
  string to the clipboard (same `data-share-copy`-style pattern as the
  caption copy buttons, targeting a hidden `<textarea>` holding the
  generated HTML rather than a visible one — nobody hand-edits raw HTML
  here). "Copied" feedback reuses the existing `[data-share-status]`
  pattern.
- **"Preview the email"** — a disclosure that, when opened, injects the
  generated HTML into a `<iframe sandbox>` inline (no network request; the
  HTML is already in the page from the initial render, matching how
  `square_html()` is embedded rather than fetched).
- **"How to send it in GiveBacks"** — a `<details>` walkthrough (closed by
  default) with the 9 numbered steps from the handoff §Round 7, each step
  paired with its screenshot where one exists (steps 1, 4, 5, 8, plus the
  editor-open and send-menu shots — see Screenshots below).

AJAX: a new `wp_ajax_ptk_nl_share_email` handler (same
`authorize_request()` guard as the other panel handlers) returns
`{ subject, html }` for the post, so the panel can be re-rendered after a
save without a full page reload — mirrors `ajax_square`'s shape.

### Screenshots

Source: `/Users/lucas/apps/PTA/NEPTANewsletter/docs/givebacks/`
(01-messages-menu, 03-editor, 04-design-editor, 05-html-block-selected,
06-send-menu, 07-send-preview). Copied into
`pta-knowledge-hub/assets/images/givebacks/`, resized to max 900px wide,
compressed under 120KB each (`sips` + `pngcrush`/`cwebp` as available).
Privacy: each was read and checked for names/emails/phone numbers before
bundling — see the round's commit message / session report for what was
found and how it was handled (crop or blur, never a full-image redaction
that hides the useful part of the screenshot).

### Phone/QR page

Skipped for this round: the phone/QR page (`class-qr-codes.php` /
`class-share-page.php`) is a save-the-picture and copy-caption surface for
a parent standing at pickup with their phone — pasting an email's raw HTML
isn't a phone task, and GiveBacks sending is a desktop-editor workflow done
by the newsletter's writer, not a bystander. Adding "email" there would be
a UI affordance with no real use.

## Testing

- `tests/test-newsletter-email.php` — structure (table layout, no
  flex/grid, no `<img>`, no one-sided borders, a DOCTYPE-to-`</html>`
  document), the one navy callout, "Inside this issue" rows built from
  `last_sections()` with permalink-anchored links, empty-newsletter
  behavior (no callout, no "Inside this issue" rule, button still
  present), `generate()` refusing without a permalink, escaping (a
  quote-breaking school name or a raw `<b>` in a headline cannot inject
  markup), and the subject-line fallback chain.
- `tests/test-newsletter-renderer.php` — unchanged assertions all still
  pass; new anchor ids are additive and don't alter any existing rendered
  byte sequence being asserted on.

## Explicit gaps

- **Real GiveBacks paste** can only be verified by Lucas inside the actual
  Unlayer editor — this plugin has no GiveBacks API access (confirmed in
  the handoff §7: no Messages API in their public Postman workspace), so
  "paste replaces the HTML block cleanly and Send Preview looks right" is
  reported as unverified here.
- **Facebook's link-preview card** for the newsletter post (og:image) is
  Round 3.2 scope, not this round — the email itself carries no images by
  design, so it isn't affected.
