# Round 3.2 — "Share picture" on every channel + link-preview image/description (2026-09-17)

Release 4.6.0. Worktree `newsletter-round3-1` (branch `newsletter-round3-1`), continuing from 4.5.3.

## Part 1 — "Share picture" everywhere, one place to edit it

### Wording
"Instagram square" (user-facing) becomes **"Share picture"** everywhere a volunteer sees it: the
Builder's step 5 panel, the phone (QR) page, and any status/error copy. Internal meta keys
(`ptk_share_square_*`), function/class names (`PTK_Share_Image`, `square_html()`,
`ensure_square()`, `ptk_nl_share_square` AJAX action, etc.) are UNCHANGED — this is a labeling
change only. A short explanatory note goes under the picture: "Made for you from this issue. Use
it on Instagram, Facebook or WhatsApp."

### Layout (Builder step 5, `PTK_Share_Panel::render()`)
Today the picture and its controls (photo/colors/framer/upload) live entirely inside the
Instagram `<details>`, duplicated nowhere but only reachable from that one channel. New layout:

1. **Share picture block** (`.ptk-nl-share-picture`), rendered ONCE, before the three channels:
   - The picture itself (`square_html()`'s figure, canvas preview, "Save the picture", "Upload
     your own instead").
   - An **"Adjust the picture"** `<details>` disclosure, closed by default, holding everything
     that changes how the picture looks: the photo picker / "Use a photo behind the words",
     "Choose a different photo" / "Remove photo", the photo consent checkbox, and the Text
     color / Band color fields. Folding these away is what keeps the default view short (spec
     item: "so the default view is short").
2. **Three collapsible channel posts** — Facebook, Instagram, WhatsApp — exactly as today
   (`<details data-share-channel>`, one open at a time, Facebook open by default). Each keeps its
   caption textarea + Copy text button. Two get an ADDED one-line plain tip plus, where noted, a
   secondary "Save the picture" button that mirrors the master picture's current download link:
   - **Facebook** (default channel, picture + text): "Post the share picture with this text —
     picture posts get noticed more in groups." + a **Save the picture** button.
   - **Instagram**: unchanged copy — it already needs the picture; no tip line added (the picture
     lives in the block above it now, not inside this channel).
   - **WhatsApp** (default: text with link): "Paste the text — WhatsApp shows a preview of the
     newsletter from the link. Adding the picture is optional." + a secondary **Save the picture**
     button.

No second image is generated or stored anywhere — same one square, mirrored by reference.

### Mirroring "Save the picture"
The Facebook/WhatsApp buttons are `<a data-share-save-picture-mirror>` with no `href` until JS
copies it from the master `[data-share-save-picture]` anchor rendered in the Share picture block
(same page load, and again every time that anchor's `href` changes after an AJAX square update).
If the master has no picture yet (GD/FreeType missing, or nothing generated), the mirrors are not
rendered at all — never a dead button.

### Facebook/WhatsApp caption text
`PTK_Share_Text::generate()` already puts the newsletter `$url` in both the Facebook post (its own
paragraph) and the WhatsApp message (last line) — verified by reading `class-share-text.php`.
`generate_whatsapp()` always appends `$url` when non-empty, including in its own truncation path.
No caption-generation change needed; only the wording/layout above.

### Phone (QR) page (`PTK_Share_Page`)
Same relabeling: the picture section heading becomes "Share picture" / "Save the picture" (it
already says that — only the section rule label "§ The picture" and any lingering "Instagram
square" wording change). Layout is already "picture first, then each channel's text with Copy" —
no structural change needed there, only wording and adding the WhatsApp/Facebook framing tip lines
next to their Copy buttons, matching the Builder panel's phrasing. "Open in WhatsApp" is unchanged.

## Part 2 — link-preview image + description for published newsletters

### Selection logic (pure, unit-tested — new `PTK_Newsletter_SEO` class + `tests/test-newsletter-seo.php`)

**Image**, in order:
1. The top story's (`TYPE_FEATURED` block) photo, if `image_id` is set and the attachment exists —
   full/large size URL with its real width/height (`wp_get_attachment_image_src(..., 'large')`).
2. Else the share picture's background photo (`PTK_Share_Data::get_square_photo()`'s `photo_id`),
   full/large size, if set.
3. Else the generated/uploaded share picture itself (`PTK_Share_Data::get_square()`'s
   `image_id`), which is always 1080×1080 when present.
4. Else: nothing — leave the SEO plugin's own default (or, no-SEO-plugin path, print no og:image
   at all).

**Description**, in order:
1. The header block's one-line `summary` field, trimmed.
2. Else the announcement block's `headline`.
3. Else `"Newsletter № 041 · Week of September 14"` built from the existing formatters
   (`PTK_Share_Text::issue_label()`, `PTK_Share_Image::dateline()` minus its "Week of" prefix
   handling — reuse `dateline()` directly, which already returns "Week of September 14").
All three trimmed/stripped of tags and collapsed to ~155 chars (word-boundary truncate + "…", no
emoji — reuse `PTK_Share_Text::html_to_text()` for tag-stripping and a small local truncator
mirroring `PTK_Share_Text::truncate()`'s multibyte-safe approach since that method is private).

**Title**: left alone entirely (no filter touches it).

Only applies to singular, published `pta_newsletter` posts — everything returns "no override" for
any other post type/status so nothing else on the site is touched.

### Integration
- **Rank Math** (verified active on northeastpta.org 2026-09-17): hook
  `rank_math/opengraph/facebook/image`, `rank_math/opengraph/facebook/image_width`,
  `rank_math/opengraph/facebook/image_height`, `rank_math/opengraph/facebook/image_secure_url`,
  `rank_math/opengraph/twitter/image`, `rank_math/opengraph/twitter/card_type` (force
  `summary_large_image` when we hand it a photo), and `rank_math/frontend/description`. These are
  documented as public/stable Rank Math OpenGraph filter names (Rank Math opengraph class exposes
  one filter per emitted meta tag, `rank_math/opengraph/{network}/{tag}` — cannot be executed
  against the live Rank Math install from this worktree/Playground, since Playground has no Rank
  Math; filter existence is asserted from Rank Math's own published developer hooks reference and
  must be confirmed live). Registered only when `class_exists('RankMath')`.
- **Yoast** (optional, trivial parity): `wpseo_opengraph_image` (image URL only — Yoast infers
  width/height from the attachment itself) and `wpseo_metadesc` / `wpseo_opengraph_desc`.
  Registered only when `class_exists('WPSEO_Options')`.
- **No SEO plugin**: our own minimal `wp_head` output (`og:title`, `og:description`, `og:image`
  +`og:image:width`/`height`, `og:url`, `og:type=article`, `twitter:card`) for single, published
  `pta_newsletter` posts only, guarded so it never double-prints if an SEO plugin is later
  activated (checked at render time, not just at hook-registration time, since plugin activation
  doesn't re-run our init).

### Verification
- Unit tests cover only the pure selection functions (image choice, description choice/trim) —
  no WordPress hook wiring is exercised by `tests/`.
- Playground has no Rank Math, so the filter hooks can only be exercised via the no-SEO-plugin
  `wp_head` fallback path (view-source of a published test newsletter). Confirming Rank Math's
  actual output (and that Facebook's crawler can fetch it past GridPane) is a live-only check.
