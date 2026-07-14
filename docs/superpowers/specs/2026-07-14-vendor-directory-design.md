# Vendor Directory — Design Spec (v3.0)

**Date:** 2026-07-14 · **Approved by:** Lucas (brainstorm session, visual companion)
**Origin:** IT council request — capture vendor recommendations and experiences
across PTAs; use it to introduce the Hub to new PTA presidents.
**Companion docs:** audit + architecture notes in
`docs/audits/2026-07-14-full-plugin-audit.md`; brainstorm mockups in
`.superpowers/brainstorm/44601-1784052250/` (directory-layout, vendor-detail,
review-form — options B, B, and ✓ chosen respectively).

## Product decisions (all locked with Lucas)

| Decision | Choice |
|---|---|
| Shape | Distinct directory, NOT a knowledge category |
| Scope | One council-wide list shared by all school subsites |
| Access | Members-only (login required) — always, regardless of the hub-wide `ptk_require_login` setting |
| Rendering | **Option A:** every school site renders the directory locally, reading centrally-stored data |
| Contributors | Any logged-in member can review AND suggest new vendors |
| Moderation | Approve-first: nothing appears until the Council approves it |
| Ratings | Thumbs up/down "would use again" + 1–5 stars Price + 1–5 stars Quality + free-text comment |
| Attribution | Automatic from login: display name + which PTA (the subsite the review was posted from) |
| Directory layout | Browse-first category tiles + search bar on top (mockup option B + search) |
| Vendor page layout | Two-column: reviews main, at-a-glance contact/verdict sidebar (mockup option B) |
| Design principle | **At-a-glance, no info overload** — one plain-English verdict line beats a stats wall |

## Architecture

### Storage: central vendors, shared reviews

- **Vendor = `ptk_vendor` CPT, existing ONLY on the Council (main) site.**
  Fields: title (vendor name), `vendor_category` taxonomy, meta: `ptk_vendor_phone`,
  `ptk_vendor_email`, `ptk_vendor_website`. Status: `publish` (live) or
  `pending` (member-suggested, awaiting approval). Not publicly queryable;
  no single-post permalinks (detail views render through the directory
  shortcode — see Routing).
- **Reviews = one network-wide table `{$wpdb->base_prefix}ptk_vendor_reviews`**
  (NOT per-site prefix), so all PTAs' ratings aggregate against one vendor:

  ```sql
  CREATE TABLE {base_prefix}ptk_vendor_reviews (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      vendor_id BIGINT UNSIGNED NOT NULL,        -- ptk_vendor post ID on main site
      blog_id BIGINT UNSIGNED NOT NULL,          -- which PTA (subsite) posted it
      user_id BIGINT UNSIGNED NOT NULL,          -- network user
      recommend TINYINT(1) NOT NULL,             -- 1 = would use again
      price_rating TINYINT UNSIGNED NOT NULL,    -- 1-5
      quality_rating TINYINT UNSIGNED NOT NULL,  -- 1-5
      comment TEXT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending | approved
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_user_vendor (vendor_id, user_id),
      KEY idx_vendor_status (vendor_id, status),
      KEY idx_status (status)
  )
  ```
  One review per user per vendor (UNIQUE key); re-submitting updates their
  review and resets it to `pending`. Attribution display values (user display
  name, PTA/site name) are resolved at render time from `user_id`/`blog_id`,
  not stored, so renames stay correct.

### Cross-site reads (rendering Option A)

Subsites render the directory with `switch_to_blog( get_main_site_id() )` for
vendor queries + direct `$wpdb` reads of the shared reviews table, cached in a
per-subsite transient (pattern: `PTK_Multisite::get_subsites()`), keyed by a
cache-version option (see Caching). Precedent for cross-site writes:
`PTK_Multisite::handle_suggest_to_council()`.

### Routing

One "Vendor Directory" page per site containing the `[pta_vendors]` shortcode
(auto-created on the Council site at activation; created on subsites by the
provisioning hook or on first render setup — same pattern as the Knowledge
Base page). Views:

- `/vendors/` — directory (category tiles + search + cards)
- `/vendors/?vendor=<slug>` — vendor detail (two-column)
- `/vendors/?vendor=<slug>&review=1` — review form (or inline reveal on detail)

Query-var routing (not CPT permalinks) because vendor posts exist only on the
Council site; a shortcode-driven page renders identically on every subsite.

### New class files

Following the one-class-per-concern convention:

- `includes/class-vendor-directory.php` — CPT + taxonomy registration (main
  site), shortcode, directory/detail/form rendering, cross-site read helpers,
  caching.
- `includes/class-vendor-reviews.php` — reviews table create/drop, submit
  AJAX endpoint, aggregation queries (per-vendor stats), one-per-user upsert.
- `includes/class-vendor-moderation.php` — admin queue page (pending reviews
  + pending vendors), approve/reject actions, email notifications.
- `includes/class-network-provisioning.php` — `wp_initialize_site` hook +
  activation-time network table creation (fixes audit #22 groundwork; also
  gives future features a home).
- `assets/css/vendor-directory.css`, `assets/js/vendor-directory.js`.

## Member experience

### Directory page (`[pta_vendors]`)

1. Search bar on top (filters vendor names + categories as you type; simple
   client-side filter over the loaded list — vendor counts are small).
2. Category tiles with emoji + vendor counts: Food & Catering 🍕,
   Entertainment 🎉, Printing & Apparel 👕, Fundraising 💰, Event Supplies &
   Rentals 🎪, Photography 📸, Other Services 🔧. (Terms seeded at activation;
   Council can add more.)
3. Vendor cards inside a category, ranked by recommend-%, showing ONLY:
   name, combined star summary, "X of Y PTAs would use again", review count.
4. "Suggest a vendor" button — form: name, category, phone/email/website
   (at least one contact required), plus the suggester's first review inline
   (a vendor with zero reviews isn't useful). Entire submission → pending.
5. Empty states: no vendors yet ("Be the first to suggest one"), no search
   matches (offer clearing the filter).

### Vendor detail (two-column, stacks on mobile)

- **Sidebar card (the at-a-glance zone):** vendor name, category chip, ONE
  verdict line ("3 of 4 PTAs would use them again"), Price ★ and Quality ★
  averages, contact rows (tel:/mailto: links), "Write a review" button.
- **Main column:** approved reviews, newest first. Each: "PTA · Name",
  thumbs verdict, Price/Quality stars, comment. The viewer's own pending
  review shows to THEM with a "waiting for Council approval" chip.
- No other stats, charts, or metadata — the no-overload principle.

### Review form (approved mockup)

"Posting as {name} · {PTA}" banner (from login) → thumbs choice → Price ★ →
Quality ★ → one textarea ("What service did they provide, and how did it
go?") → expectation note ("checked by the PTA Council before it appears —
usually within a few days") → submit. Client + server validation: thumbs and
both star ratings required; comment required, ≤ 2000 chars.

### Access gate

Every vendor view (directory, detail, AJAX endpoints) requires
`is_user_logged_in()` — hard requirement independent of `ptk_require_login`.
Logged-out visitors get the existing friendly "Members Only" prompt
(`ptk_check_access( true )` pattern with vendor-specific copy).

## Moderation & notifications

- **Queue:** "Vendor Approvals" submenu under the PTA Hub menu on the Council
  site, capability `edit_others_posts` (so trusted Council editors can
  moderate, not only admins). Two sections: pending
  reviews (full text + who/PTA) and pending vendors. Actions: Approve /
  Reject (reject = delete row / trash vendor; no notification to author in
  v3.0 — keep simple).
- **Email:** on new pending submission, one email to the Council admin email
  (`get_option('admin_email')` on the main site) with the content and a link
  to the queue. Batched-per-submission (volume is low). Fixes the
  silent-queue failure mode the audit found in suggest-to-council.
- **Member expectation:** the form says "usually within a few days"; their
  own pending review is visible to them on the vendor page.

## Security, spam, and integrity

- All AJAX endpoints: nonce + `is_user_logged_in()` + object-level checks
  (verify vendor exists and is `publish` before accepting a review — the
  audit-#25 lesson).
- Spam: honeypot field + per-user rate limit (5 submissions/hour) copied from
  `PTK_Suggestions`. No IP handling needed — submitters are logged in.
- Sanitization: `sanitize_textarea_field` comments, `absint` ratings clamped
  1–5, contact fields `sanitize_email`/`esc_url_raw`/text.
- Reviews table writes only through `$wpdb->insert/update` with formats.

## Caching (audit #2 lesson baked in)

- Directory + per-vendor aggregates cached in per-site transients,
  1 hour, keyed `ptk_vendors_{version}` where `{version}` is a network option
  (`ptk_vendor_cache_ver`) bumped on every vendor/review approve/edit/delete —
  version-bump invalidation works under object caches (avoids audit #20's
  LIKE-delete trap) and needs no cross-site transient deletion.
- No role variance needed (everything is simply login-gated, same content for
  all members), but the gate check runs BEFORE cache read on every request.

## Provisioning & lifecycle (audit #22 groundwork)

- Activation on the main site: create reviews table (network-safe:
  `base_prefix`, created once), seed `vendor_category` terms, create the
  Vendor Directory page.
- `wp_initialize_site`: when a new school site joins, auto-create its Vendor
  Directory page (and the knowledge-base tables the audit flagged — included
  here as the natural home for that fix).
- Uninstall: drop the reviews table, delete vendor CPT posts + terms + pages?
  NO — follow existing philosophy (posts preserved on uninstall); drop the
  shared table only on network uninstall, and add the new options/transients
  to `uninstall.php`.

## Out of scope for this spec

- Volunteer Welcome page (own spec; ships alongside v3.0).
- Audit #28/#29 suggestion notifications for knowledge entries and #30
  network-wide content gaps (the vendor queue email covers the vendor side).
- Review replies/comment threads, vendor photos, vendor self-service, public
  visibility, per-school private notes. All YAGNI until asked for.

## Success criteria

1. A member on any school site can find a caterer, see "X of Y PTAs would
   use again" at a glance, and read attributed reviews — without leaving
   their school's site.
2. A member can submit a review in under 2 minutes; it appears after Council
   approval; the Council hears about it by email without checking a queue.
3. John's Pizza exists ONCE — a Hillside 👍 and a Bradford 👎 both attach to
   it and both show, attributed.
4. A brand-new 11th school site gets the directory automatically.
5. Logged-out visitors can never see any vendor data (pages, AJAX, or cache).
