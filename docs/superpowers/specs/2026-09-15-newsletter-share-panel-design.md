# Newsletter Share Panel — Design

> **STATUS: DRAFT** (2026-09-15). Brainstormed with Lucas; not yet planned or built.

## The problem

When a PTA publishes a newsletter, telling people about it is a second, entirely
manual job. Today Lucas hand-writes a Facebook post for every Northeast issue
(see `NEPTANewsletter/fb-post-newsletter-040.md` — a full post, a short variant,
and posting notes). That work is re-done from scratch every week, by the one
person who knows how, and it does not scale to eleven PTAs.

The newsletter already contains everything the social post needs. The Newsletter
Builder stores it as structured blocks. Nothing reads them.

## What we are NOT building, and why

**No automatic posting. No Meta API. No access tokens. No app review.**

- **Facebook:** Montclair's PTAs post to a **Group**
  (`facebook.com/groups/284093221783396`), not a Page. Meta deprecated the Groups
  API on 2024-04-22, removing `publish_to_groups` from every API version. No tool
  — ours or anyone's — can publish to a Facebook Group. This is not a limitation
  we can engineer around.
- **Instagram:** publishing *is* technically possible (Professional account,
  `instagram_business_content_publish`, 2–4 week app review, all free).
  Rejected on maintenance grounds: it requires a Meta Business Manager owned by
  whoever set it up, on a board that turns over annually. A non-expiring System
  User token exists, but nobody except Lucas could diagnose it when it breaks.
- **WhatsApp:** the Cloud API Groups endpoint only addresses groups the *business*
  creates via API, caps at **8 participants**, and needs an Official Business
  Account. It cannot post into a parents' group. Unofficial libraries
  (Baileys, whatsapp-web.js) violate WhatsApp's terms and risk banning a
  volunteer's personal number.

Every path therefore ends with a human tapping "post." The feature removes the
**writing**, not the posting — which is where the time actually goes.

## Scope boundary

This reads `pta_newsletter` block data. It does **nothing** for newsletters
pasted in as Beaver Builder HTML, which is how Northeast currently publishes.
Accepted knowingly: the Builder is the destination, and this is one more reason
to move onto it.

Related: the Newsletter Builder itself has never shipped. The released zip is
v4.0.1 (2026-07-15) and contains no newsletter files; the Builder landed
2026-07-16/17 and is merged to `main` but unpackaged. This panel should ship in
the **same release** as the Builder's debut, so PTAs meet both at once.

## Decisions

| # | Decision | Chosen |
|---|---|---|
| 1 | Content source | `pta_newsletter` block data |
| 2 | Where it appears | Post-publish screen **and** edit-screen sidebar (one component, two mounts) |
| 3 | Editable? | Yes, and edits persist |
| 4 | Emoji | None |
| 5 | Channels | Facebook, Instagram, WhatsApp |
| 6 | Phone handoff | QR code → plugin-served mobile page |
| 7 | Instagram image | Auto-generated square, with upload-your-own override |
| 8 | School color | Council sets default, school may override, contrast-guarded |
| 9 | Color applies to | The square image only (newsletter styling unchanged) |

## Components

### 1. Caption generator

Pure PHP, WordPress-free beyond sanitizing shims — the same shape as
`PTK_Newsletter_Data`, and unit-testable the same way.

Input: the block array. Output: three strings.

| Channel | Shape |
|---|---|
| Facebook | Announcement as the opening line; one plain line per story card; upcoming events; the newsletter URL; membership/submission links. No emoji. |
| Instagram | Shorter. Captions carry no clickable links, so it ends with a "link in bio" pointer rather than a bare URL. |
| WhatsApp | Two or three lines and the link. |

This is where the feature succeeds or fails. A caption that reads like a machine
gets rewritten every week and saves nobody anything. Worked examples to calibrate
against live in `NEPTANewsletter/fb-post-newsletter-*.md`.

Must handle: no story cards, no announcement, no events, a very long school name,
a three-digit issue number, and a newsletter that is nearly empty.

### 2. Share panel

One renderer, two mounts:

- **Post-publish screen** — the moment they are actually thinking about telling
  people. Requires intercepting the post-publish redirect for `pta_newsletter`.
- **Edit-screen sidebar** — reachable any time afterwards.

Three sections, each a textarea plus a copy button. Facebook gets a link to the
group; WhatsApp gets a `wa.me` share link; Instagram gets the square plus the QR.

### 3. Storage

Edited captions save to post meta, per channel.

Regeneration rule: **untouched channels regenerate** from the blocks (so fixing a
newsletter typo fixes the caption); **an edited channel is frozen** and never
overwritten. A "reset to generated" control provides the way back.

### 4. Square image generator

Draws a PNG — school color, navy `#1a2f5c`, issue number, week, school name — and
stores it in the media library.

Fonts: **Libre Franklin** and **Newsreader**, both Google Fonts under the SIL Open
Font License, so they may be bundled in the plugin.

Regenerates when issue number, date, school name or school color change — **unless**
a custom square was uploaded, in which case it is never touched.

**Graceful degradation is required.** Server-side text rendering depends on GD
with TrueType support. If unavailable, the Instagram section must fall back to
"upload a square picture" and stay usable. It must not fatal, and it must not
render a blank image.

### 5. Mobile share page

A tokenized URL on the site itself: `?ptk_share=<32-hex>`, rendered on
`template_redirect`.

This mirrors `PTK_Public_Preview` deliberately: a **query var, not a rewrite
rule**. Rewrite rules need flushing, and the Network Admin upload path does not
fire activation hooks — a query var sidesteps that failure mode entirely.

Shows: the square (long-press to save), the caption with a copy button, and a
WhatsApp share button.

**A draft newsletter must not leak.** Token access follows the same rules as the
existing preview-link feature, including expiry and the daily cleanup cron.

### 6. School color override

Today `ptk_site_colors` is a **network option**, and its settings page renders
only on the main site for `manage_options`. A deterministic default palette
already gives every school a color.

Change: a per-site override layered over the Council value. The picker must
reject or darken colors that fail contrast against the text drawn on the square.

## Risks

| Risk | Mitigation |
|---|---|
| GD/TrueType missing on a host | Detect, fall back to upload-your-own, never fatal |
| No permalink before publishing | Sidebar says the link appears once published; no broken URL |
| Draft content leaking via token | Same access rules as `PTK_Public_Preview` |
| Plugin update skips activation hooks | Version-gated `admin_init` via `PTK_Network_Provisioning`; no rewrite rules |
| Generated captions read robotically | Calibrate against the hand-written `fb-post-*.md` files; ship "reset to generated" |
| Unreadable school colors | Contrast guard in the picker |

## Testing

- Unit tests for the caption generator, plain-PHP style matching
  `tests/test-newsletter-data.php` — including every empty/overlong edge case above.
- Smoke test that the image generator emits a valid PNG, and that the
  no-GD path degrades rather than fatals.
- End-to-end in WordPress Playground:
  `npx --yes @wp-playground/cli@latest server --auto-mount "<plugin dir>" --login --port 9400`
  — served at **`127.0.0.1:9400`, not `localhost:9400`** (mismatched origin breaks
  every admin-ajax call, silently killing the live preview).
