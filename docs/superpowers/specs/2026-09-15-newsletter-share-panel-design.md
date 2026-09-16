# Newsletter Share Panel — Design

> **STATUS: DRAFT** (2026-09-15, rev 2 after code audit). Brainstormed with Lucas;
> not yet planned or built.

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
  — ours or anyone's — can publish to a Facebook Group.
- **Instagram:** publishing *is* technically possible (Professional account,
  `instagram_business_content_publish`, 2–4 week app review, all free).
  Rejected on maintenance grounds: it requires a Meta Business Manager owned by
  whoever set it up, on a board that turns over annually.
- **WhatsApp:** the Cloud API Groups endpoint only addresses groups the *business*
  creates via API, caps at **8 participants**, and needs an Official Business
  Account. Unofficial libraries violate WhatsApp's terms and risk banning a
  volunteer's personal number.

Every path therefore ends with a human tapping "post." The feature removes the
**writing**, not the posting.

## Scope boundary

This reads `pta_newsletter` block data. It does **nothing** for newsletters
pasted in as Beaver Builder HTML, which is how Northeast currently publishes.
Accepted knowingly.

## Release hazard — read before shipping

`PTK_VERSION` is still `4.0.1` (`pta-knowledge-hub.php:16`), identical to the
shipped zip. Both `ptk_maybe_clear_cache_on_update` (`:170`) and
`ptk_maybe_flush_rewrites_on_update` (`:189`) are gated on that constant changing.
**Shipping the Newsletter Builder without bumping the version means the rewrite
flush never runs and `/newsletters/` 404s on all 11 sites.** The release that
debuts the Builder and this panel must bump `PTK_VERSION` and `update-info.json`
together. This predates this feature but sits directly in its release path.

## Decisions

| # | Decision | Chosen |
|---|---|---|
| 1 | Content source | `pta_newsletter` blocks **plus an opts bag** (see below) |
| 2 | Where it appears | **One mount: the Builder's final step.** See "Why one mount". |
| 3 | Editable? | Yes, and edits persist, tracked by an explicit per-channel dirty flag |
| 4 | Emoji | None |
| 5 | Channels | Facebook, Instagram, WhatsApp |
| 6 | Phone handoff | QR code → plugin-served mobile page, **no token** (see below) |
| 7 | Instagram image | Auto-generated square, with upload-your-own override |
| 8 | School color | Council default, school may override, contrast-guarded |
| 9 | Color applies to | The square image only — via a **separate accessor** |

### Why one mount

The original design said "post-publish screen and edit-screen sidebar." **Neither
exists for this post type.** `redirect_edit_to_builder()`
(`class-newsletter-builder.php:107-118`) redirects every `pta_newsletter` edit
request into the Builder, and `handle_submission()` (`:201-207`) owns the save
redirect. Core hooks such as `post_submitbox_misc_actions` never fire for
newsletters — which is why the Builder re-implemented the preview-link panel as
`render_preview_panel()` (`:874`).

So: **one panel, on the Builder's "Finish & publish" step**, rendered when
`ptk_nl_edit_id` is set, in a "you just published" state when
`ptk_nl_msg=published`. Behaviourally this is what we wanted; it is simply one
screen rather than two.

**Forms cannot nest** (`class-newsletter-builder.php:869-870`). The panel's
textareas therefore save by AJAX against their own nonce, not by riding the
Builder's form submit.

## Components

### 1. Caption generator

Pure PHP, WordPress-free beyond sanitizing shims — the same shape as
`PTK_Newsletter_Data`, unit-testable the same way.

**Signature: `generate( array $blocks, array $opts )`.** The blocks do *not*
carry the URL, issue number, date, or a reliable school name — issue and date are
post meta (`class-newsletter-builder.php:300-301`), the permalink needs a post ID,
and `school_name` falls back to `get_bloginfo('name')` (`:346-358`). This mirrors
the renderer's existing `$opts` bag (`class-newsletter-renderer.php:52-57`, built
by `render_opts()` at `:325-337`). `$opts` carries `url`, `issue`, `date`,
`school_name`.

**All body fields are HTML**, not plain text — `announcement.text`,
`featured.body`, `card.body`, `event.desc`, `greeting`, `signoff` all pass through
`wp_kses_post` (`class-newsletter-data.php:178,184,197,206,219,240`). The
generator converts to plain text: strip tags, `<br>`/`</p>` become newlines,
`<a href>` becomes a bare URL. `tests/bootstrap.php` needs an HTML-to-text shim;
it has none today.

Output shapes:

| Channel | Shape |
|---|---|
| Facebook | **Featured** block as the lead paragraph (this is what the reference post does), then the announcement, then one line per story card, then events, then the URL and footer links. No emoji. |
| Instagram | Shorter. Captions carry no clickable links — ends with a "link in bio" pointer. |
| WhatsApp | Two or three lines and the link. |

**Story shortening rule.** Card bodies are multi-sentence textareas
(`class-newsletter-builder.php:1132`, rows=3), while the reference post's "Also in
this issue" lines are hand-condensed. Rule: **use the card's heading; if it has
none, use the first sentence of the body, truncated at 120 characters on a word
boundary.** This is the single highest-leverage detail in the feature and should
be tuned against the `fb-post-*.md` files during implementation.

**Footer links are untyped `{label, url}` pairs** (`class-newsletter-data.php:234-237`)
— nothing marks which is membership vs. submissions. The generator lists every
footer link with its label. (Adding typed roles to footer links is a possible
follow-up, out of scope here.)

Must handle: no story cards, no announcement, no events, no featured block, a very
long school name, a three-digit issue number, an almost-empty newsletter.

### 2. Share panel

Rendered on the Builder's final step. Three sections — Facebook, Instagram,
WhatsApp — each a textarea, a copy button, and a "reset to generated" control.
Facebook links out to the group; WhatsApp gets a `wa.me` link; Instagram shows the
square and the QR.

### 3. Storage and the regeneration rule

Edited captions save to post meta, per channel, by AJAX.

**An explicit dirty flag is required.** Because the textareas post their contents
back, the server cannot distinguish "generated text round-tripped" from "edited",
and cannot diff against "generated now" because the same save may have changed the
blocks. Rule: **JS marks a channel dirty on first `input`; only dirty channels
write meta. No meta means regenerate at render. "Reset to generated" deletes the
meta.**

Regeneration happens **lazily at render**, not on `save_post`:
`persist_newsletter()` writes the post *before* its meta
(`class-newsletter-builder.php:279-309`), so a `save_post` hook would read stale
blocks, issue and date.

### 4. Square image generator

Draws a PNG — school color, navy `#1a2f5c`, issue number, week, school name — and
stores it in the media library.

Fonts: **Libre Franklin** and **Newsreader**, both SIL Open Font License, so they
may be bundled. **Bundle only the weights and the Latin subset the square actually
draws** — two full families would add roughly 1 MB to a 218 KB plugin. The plugin
bundles no fonts today.

Regenerates when issue, date, school name or share color change — **unless** a
custom square was uploaded, which is never touched.

**This is the plugin's first writer to the media library** (existing code only
reads, `class-newsletter-builder.php:334`). It must `require wp-admin/includes/image.php`
for `wp_generate_attachment_metadata`, respect per-site upload quotas, and set
`post_parent` so the square is deleted with its newsletter. Uploads land per-site
(`sites/N/`), which is correct; `PTK_Multisite` syncs only `pta_knowledge`
(`class-multisite.php:312,419,498`), so the square is never copied between sites.

**Two separate capability checks, not one.** The QR encoder needs plain GD;
the square additionally needs **FreeType** for TrueType text. If FreeType is
missing, the Instagram section falls back to "upload a square picture." If GD
itself is missing, the QR handoff is dead too and the panel must say so and stay
usable. Neither may fatal, and neither may emit a blank image.

### 5. Mobile share page

A URL on the site itself, served on `template_redirect` via a **query var**
(`?ptk_share=<post_id>`), matching `PTK_Public_Preview`'s approach
(`class-public-preview.php:37,64-67`).

**No token.** The original design said "same rules as the preview link." That is
not implementable: preview lookups only match `draft/pending/private/future`
(`class-public-preview.php:92`) and tokens are *deleted* on publish (`:310-318`) —
the exact opposite of what a share page needs. Since the page serves an
already-published newsletter, a token protects nothing and would need its own
expiry and cron. **The page gates on `post_status === 'publish'` and 404s
otherwise.** Drafts get no phone handoff; the panel says the QR appears once
published.

Shows: the square (long-press to save), the caption with a copy button, and a
WhatsApp share button.

### 6. School color override

`ptk_site_colors` is a **network option** whose settings page renders only on the
main site under `manage_options` (`class-site-colors.php:8,46-48,107-118,162-171`).

**The override must NOT be layered into `color_for()`.** That accessor feeds the
Owner-column dots on every site (`:294,304`); a per-site override there would make
one school show different colors depending on which site you were viewing from,
contradicting Decision 9. Instead: a **separate `share_color()` accessor**, used
only by the square, resolving per-site override → Council value → palette default.

**The contrast guard applies to resolved colors, not just the picker.** Several
palette defaults already fail AA against white (`#d97706` 3.19:1, `#16a34a` 3.30:1,
`#ea580c` 3.56:1, `#0891b2` 3.68:1, `#0d9488` 3.74:1) and several are unreadable
against navy (`#475569` 1.73:1, `#4338ca` 1.66:1, `#7c3aed` 2.30:1). The square
must darken or re-pair any color that fails, whoever chose it.

The subsite picker is a **new admin page** — the existing one is main-site only.
The spec names the capability as `manage_options` on the subsite (a site admin).

## Risks

| Risk | Mitigation |
|---|---|
| Version not bumped → `/newsletters/` 404s network-wide | Mandate `PTK_VERSION` + `update-info.json` bump in the release |
| FreeType missing | Fall back to upload-your-own square; never fatal |
| GD missing | QR handoff also dead — panel says so and stays usable |
| Captions read robotically | Calibrate the shortening rule against `fb-post-*.md`; ship "reset to generated" |
| Dirty-flag lost → user edits silently overwritten | Absence of meta means regenerate; only JS-marked channels write |
| Unreadable school colors | Contrast guard on *resolved* colors |
| Media-library writes on multisite | `post_parent`, quota check, `wp-admin/includes/image.php` |
| Font bundle bloats the zip | Latin subset, only the weights drawn |

## Testing

- Unit tests for the generator, plain-PHP style matching
  `tests/test-newsletter-data.php`, including every empty/overlong case above and
  the HTML-to-text conversion. Requires a new shim in `tests/bootstrap.php`.
- Smoke test that the image generator emits a valid PNG, and that the
  missing-FreeType and missing-GD paths degrade rather than fatal.
- End-to-end in WordPress Playground:
  `npx --yes @wp-playground/cli@latest server --auto-mount "<plugin dir>" --login --port 9400`
  — served at **`127.0.0.1:9400`, not `localhost:9400`** (mismatched origin breaks
  every admin-ajax call, silently killing the live preview).
