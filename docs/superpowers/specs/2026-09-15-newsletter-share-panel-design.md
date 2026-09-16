# Newsletter Share Panel — Design

> **STATUS: READY TO PLAN** (2026-09-16, rev 4, after three code audits). Brainstormed with Lucas;
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

It follows the proven sibling-panel pattern: `render_preview_panel()` already
sits outside `#ptk-nl-form` carrying `data-step="4"` (`:877`), and `showStep()`
toggles every `[data-step]` in `.ptk-nl-wizard` (`newsletter-builder.js:141-146`).

**The just-published state needs a boot hint, or it is unreachable.** After
`handle_submission()` redirects with `ptk_nl_msg=published` (`:201-207`), the JS
boot unconditionally calls `showStep(FIRST_STEP, false)` (`js:101`) and there is
no deep-link to a step anywhere in that file. The volunteer would land on step 1
with a "Newsletter published" notice while the share panel sat hidden three steps
away. Fix: PHP localizes `startStep = 4` when `ptk_nl_msg` is set, and the boot calls
`showStep( parseInt( ptkNlData.startStep, 10 ) || FIRST_STEP, false )`. The
`parseInt` is not optional dressing: `wp_localize_script()` casts scalars to
strings, so the value arrives as `"4"`. It happens to survive today because
`showStep()` clamps with `Math.max`/`Math.min` (`js:136`) and coerces on the way
through — the spec does not want that behaviour resting on an accident.
(`FIRST_STEP = 1`, `LAST_STEP = 4`, `js:50-51`.)

**CSS contract** (`newsletter-builder.css:9-26`): any new `[data-step]` element
must be a plain block, never `display:flex`, and never hidden by the stylesheet.

**Forms cannot nest** (`class-newsletter-builder.php:869-870`). The panel's
textareas therefore save by AJAX against their own nonce, not by riding the
Builder's form submit — a second nonce (`ptk_nl_share`) alongside the existing
one in `ptkNlData` (`:216`, `:447-451`).

**The panel's JS must not be able to kill the Builder.** The boot block
(`js:83-107`) is a flat list of `bind*()` calls; a throw anywhere in it aborts
before `showStep()` and the whole wizard goes inert — a documented failure mode
that `node --check` does not catch
(`plans/2026-07-16-newsletter-wizard-live-preview.md:462`). The share binding goes
**last** in the boot block and is wrapped in `try/catch`: the panel is optional,
the Builder is not. Its copy buttons are self-contained — `copy-button.js` is a
front-end asset and is not enqueued on this admin hook (`enqueue_assets()` `:416-452`).

## Components

### 1. Caption generator

Pure PHP, WordPress-free beyond sanitizing shims — the same shape as
`PTK_Newsletter_Data`, unit-testable the same way.

**Signature: `generate( array $blocks, array $opts )`.** The blocks do *not*
carry the URL, issue number, date, or a reliable school name — issue and date are
post meta (`class-newsletter-builder.php:300-301`), the permalink needs a post ID,
and `school_name` falls back to `get_bloginfo('name')` (`:346-358`). This mirrors
the renderer's existing `$opts` bag (`class-newsletter-renderer.php:52-57`).
**It cannot reuse `render_opts()`** (`:325-337`): that method is `private`, and it
supplies `issue`, `date`, `today`, `theme`, `logo_url`, `school_name` and
`image_url_cb` but **no `url`**. The panel builds its own opts — or a new public
helper does — adding `url = get_permalink( $post_id )`. `$opts` carries `url`,
`issue`, `date`, `school_name` and **`today`**.

`today` is required, not decorative: the blocks hold every event row including
past ones, so "upcoming events" needs a clock. The renderer already does exactly
this (`class-newsletter-renderer.php:178`, supplied at builder `:328`). Passing it
in also keeps the generator deterministic under test.

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
this issue" lines are hand-condensed.

Checked against the real thing rather than guessed. In `newsletter-040`, the story
headings are *already complete sentences carrying the fact* — "Film on the Field
moves to Friday, October 16.", "Can you help on Tuesdays? Your child gets a free
class." — and the Facebook lines in `fb-post-newsletter-040.md` are those headings,
lightly adjusted. The heading is the line.

Rule: **use the card's heading.** If the heading is under 30 characters or carries
no sentence-ending punctuation — a PTA writing "Film on the Field" as a label
rather than a sentence — append the first sentence of the body. If there is no
heading, use the first sentence of the body. Truncate at 120 characters on a word
boundary.

This is the single highest-leverage detail in the feature. Re-check it against the
`fb-post-*.md` files during implementation, and re-check it again the first time a
PTA that is not Northeast writes an issue, since the rule leans on a house-style
habit that may not travel.

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

**The group URL needs somewhere to live.** No Facebook URL exists anywhere in the
plugin today — Northeast's is a fact about Northeast, not about the other ten
PTAs. Read it from a `ptk_share_facebook_url` blog option, set on the same subsite
page as the share colour. When it is empty, show the caption and copy button with
no link-out rather than a dead button.

### 3. Storage and the regeneration rule

Edited captions save to post meta, per channel, by AJAX.

**An explicit dirty flag is required.** Because the textareas post their contents
back, the server cannot distinguish "generated text round-tripped" from "edited",
and cannot diff against "generated now" because the same save may have changed the
blocks. Rule: **JS marks a channel dirty on first `input`; only dirty channels
write meta. No meta means regenerate at render. "Reset to generated" deletes the
meta.**

**A frozen caption can go stale.** The dirty rule protects a hand-fixed caption,
but it also means "PTA Newsletter #040 is out" survives the issue being renumbered
to 41. Store `_ptk_share_caption_hash_{channel}` alongside each edited caption; when
it no longer matches, show "the newsletter changed since you edited this" beside the
reset control. Warn, do not silently overwrite.

**Two hashes, not one — they cover different inputs.**
`caption_inputs_hash = hash( wp_json_encode($blocks), url, issue, date, school_name )`.
The square's hash (section 4) deliberately excludes the blocks. Reusing the square's
hash for captions would miss the common case entirely: editing a story, adding an
event or fixing a typo in the announcement would never raise the stale warning.

**Dirty state on load.** A channel with stored meta loads **already dirty**, so
subsequent edits keep saving; a channel without meta is clean until the first
`input`. Without this, the first reload after an edit shows the stored text and then
silently stops saving. Meta keys: `_ptk_share_caption_{channel}`,
`_ptk_share_caption_hash_{channel}`, `_ptk_share_square_id`,
`_ptk_share_square_custom` (bool), `_ptk_share_square_hash`.

**Caption generation is stateless and may run anywhere, including the share page.**
Only the *square* is restricted to admin render, because only the square writes.

**Capabilities on the AJAX save.** The existing preview endpoint checks only
`current_user_can('edit_posts')` (`:218`) — fine for a stateless render, wrong for
a per-post write. The share save checks `current_user_can('edit_post', $post_id)`
**and** `get_post_type($post_id) === 'pta_newsletter'`, the pattern already used by
`handle_submission()` (`:150-159`) and `PTK_Public_Preview::guard_request()`
(`:295-304`). The upload-your-own-square path additionally needs `upload_files`
(the Builder already assumes it via `wp_enqueue_media()`, `:421`).

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

**Change detection is a stored hash**, not a guess:
`square_inputs_hash = hash( issue, date, school_name, share_color, PTK_VERSION )`
saved in post meta, compared when the Builder panel renders. This is a **separate**
hash from the caption's (section 3) — it deliberately excludes the blocks, since
newsletter copy does not change the square. Regeneration happens **only there** — never on the
public share page.

A custom uploaded square is **never** regenerated over. It needs a way back,
though: a **"Use the generated square again"** action clears the custom-attachment
meta and returns to auto. Without it, a school that uploads once is stuck forever.

**This is the plugin's first writer to the media library** (existing code only
reads, `class-newsletter-builder.php:334`). It must `require wp-admin/includes/image.php`
for `wp_generate_attachment_metadata` and respect per-site upload quotas.

**Cleanup needs an explicit hook — `post_parent` does not delete anything.**
`wp_delete_post()` *reparents* child attachments rather than deleting them, and
`wp_trash_post()` leaves them alone entirely. Store the square's attachment ID in
post meta and hook `before_delete_post`, calling `wp_delete_attachment( $id, true )`
— half of the pattern `PTK_Multisite` uses (`class-multisite.php:39-40`, which hooks
both `before_delete_post` and `trashed_post`). **Only `before_delete_post` here**:
trash is restorable, and deleting the square on trash would force a regeneration on
restore. The handler must check `get_post_type( $post_id ) === 'pta_newsletter'`
first — `before_delete_post` fires for every post type, including the attachment
being deleted — and must verify the stored attachment still exists, since a
volunteer may have deleted it from the Media Library by hand. **Only the auto-generated square is deleted**, never
a user-uploaded one, which may be an existing library image used elsewhere.
`post_parent` is still worth setting for the "Uploaded to" column. Uploads land per-site
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
expiry and cron. **The page gates on BOTH `post_type === 'pta_newsletter'` and
`post_status === 'publish'`, and 404s otherwise.** The post-type check is not
optional: without it, `?ptk_share=<id>` pointed at a restricted `pta_knowledge`
entry could leak at least a title. Drafts get no phone handoff; the panel says the
QR appears once published.

`ptk_share` must be registered through the `query_vars` filter exactly as
`ptk_preview` is (`class-public-preview.php:64-67`), or `get_query_var()` returns
nothing. No other code claims that var.

**The page is strictly read-only.** It never regenerates the square and never
writes to the media library — otherwise an unauthenticated GET could trigger image
work on the server.

Shows: the square (long-press to save), then **the Instagram caption first** —
Instagram is the reason the phone is involved at all — followed by the WhatsApp
text with its own copy button and `wa.me` share button. Both captions, in that
order.

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

The subsite picker is a **new admin page** — the existing one is main-site only,
and hangs off `edit.php?post_type=pta_knowledge` (`class-site-colors.php:164`). The
subsite picker belongs under the **Newsletters** menu instead, where the person
setting it is already working. Capability: `manage_options` on the subsite (a site
admin). Option keys: `ptk_share_color` and `ptk_share_facebook_url`, both blog options,
the first distinct from the network `ptk_site_colors`.

## Risks

| Risk | Mitigation |
|---|---|
| Version not bumped → `/newsletters/` 404s network-wide | Mandate `PTK_VERSION` + `update-info.json` bump in the release |
| FreeType missing | Fall back to upload-your-own square; never fatal |
| GD missing | QR handoff also dead — panel says so and stays usable |
| Captions read robotically | Calibrate the shortening rule against `fb-post-*.md`; ship "reset to generated" |
| Dirty-flag lost → user edits silently overwritten | Absence of meta means regenerate; only JS-marked channels write |
| Unreadable school colors | Contrast guard on *resolved* colors |
| Media-library writes on multisite | Quota check, `wp-admin/includes/image.php` |
| Orphaned squares after delete | `before_delete_post` + `wp_delete_attachment`; auto-generated only |
| Share panel JS throws and kills the Builder | Binding goes last in the boot block, wrapped in `try/catch` |
| Just-published state unreachable | `startStep` boot hint; verify in Playground, not by reading code |
| Frozen caption goes stale after renumbering | Inputs hash stored with the caption; warn beside reset |
| Public share URL triggering server work | Share page is read-only; regeneration is admin-render only |
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
