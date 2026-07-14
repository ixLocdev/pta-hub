# PTA Knowledge Hub — Full Plugin Audit (2026-07-14)

Audited at v2.8.0 across three surfaces: front-end UX, admin/volunteer experience,
and ops/infrastructure. Context: primary users are rotating, non-technical PTA
volunteers; live on montclairpta.org (multisite: Council + ~10 school subsites).

Status legend: `[ ]` open · `[x]` fixed · `[-]` won't fix

---

## 🔴 Bugs — fix now

- [x] **1. Broken "+ Create Entry" link in Search Analytics.**
  `includes/class-analytics.php:332` links to `admin.php?page=ptk-content-wizard`;
  the wizard is registered under `edit.php?post_type=pta_knowledge&page=ptk-content-wizard`
  (correct in `class-admin-helpers.php:442` and `templates/search-page.php:32`).
  Result: volunteers hit "Sorry, you are not allowed to access this page" at the
  exact "we found a content gap — write it" moment. One-line fix. Consider a shared
  wizard-URL helper so three hardcoded copies can't drift again.

- [x] **2. Search cache leaks role-restricted content.**
  `includes/class-search-engine.php:307` caches results keyed on `md5(query)` only,
  but results were filtered per-current-user by `PTK_Role_Access::filter_search_results`
  (line ~271; `class-role-access.php:166-170`). First searcher fixes visibility for
  everyone for 1 hour — admins can leak restricted entries to anonymous users and
  vice versa. Fix: add role/capability to the cache key, or filter after cache read.

- [x] **3. Autocomplete bypasses the login gate.**
  `handle_search` enforces `ptk_check_access()` (`class-search-engine.php:292-294`)
  but `handle_autocomplete` (line ~878) does not — with "Require Login" on, titles
  and permalinks still leak to logged-out visitors. `handle_track_click` (line ~990)
  similarly ungated (minor).

- [ ] **4. Phantom setting: importer visibility toggle does nothing.**
  Settings copy (`class-admin-helpers.php:112-115`) claims the importer hides after
  first import; `class-content-importer.php:28-38` always shows it and the
  `ptk_show_importer` option is never read to gate anything. Wire it up or fix the copy.

## 🟡 Volunteer experience (approachability)

- [ ] **5. Search error state is a dead end.**
  `templates/search-page.php:156-163` — "Search unavailable / Please try again in a
  moment." No retry button, no browse-categories fallback (the no-results state
  right above it does this well — mirror it). All fetch failures collapse to the
  same message (`assets/js/search.js:222-238`).

- [ ] **6. Wizard submission failures white-screen via `wp_die()`.**
  `class-content-wizard.php:1069-1138` (multiple call sites) — validation errors
  render a bare WP error page with no "Back" link; browser-back after POST risks
  losing work since the last autosave. Return to the form with inline errors instead.

- [ ] **7. No first-run "start here" for new volunteers.** Biggest structural gap
  for the rotating-volunteer reality. Guidance exists but is scattered (wizard banner
  only on list screen; tips only inside the editor). Build a Welcome page: what the
  Hub is, the 3 things you'll do, link to the wizard. Pairs with the IT council's
  "introduce the tool to new presidents" goal — ship with the Vendor Directory.

- [ ] **8. "Last Reviewed" dots are color-only.**
  `class-review-reminders.php:71-99` — green/amber/red dots with no title/aria-label
  or legend (accessibility: meaning by color alone). Add
  `title="Reviewed 14 months ago — overdue"` and/or a small legend.

- [ ] **9. QR meta box leaks raw server path to volunteers.**
  `class-qr-codes.php:47-50` — if the library is missing, any editor sees
  `vendor/phpqrcode/phpqrcode.php`. Reword ("QR codes aren't available right now —
  contact your tech committee"); technical detail behind `manage_options`.

- [ ] **10. "Publish now" in the wizard has no confirmation.** Draft default is good;
  Publish pushes live network-wide with no "visible immediately" moment
  (`class-content-wizard.php:1021-1023`).

- [ ] **11. Restricted-entry page is vague + dead-ends.**
  `templates/single-pta_knowledge.php:33-39` — "only available to certain roles.
  Contact your PTA administrator" (who?). Branch returns before the Suggest-a-topic
  CTA; no link back to search. Also: lock emoji (line 35) lacks aria-hidden.

- [ ] **12. Multisite banner jargon.** `single-pta_knowledge.php:106-111` — "Shared by
  the PTA Council" with no explanation of what that means for the reader.

- [ ] **13. Wizard edit-mode warning is dense.** `class-content-wizard.php:587` —
  accurate but one long sentence, uses "blocks" unexplained, doesn't bold the real
  risk (silent data loss). Also no per-field indicator of which fields parsed vs. blank.

- [ ] **14. Feedback error is generic.** `assets/js/feedback.js:82-88` — "Something
  went wrong." (The "already voted" path is handled well — extend that pattern.)
  No undo for votes (low stakes; note only).

- [ ] **15. Minor a11y:** autocomplete dropdown lacks listbox/option roles +
  aria-activedescendant (`search.js:699-738`); JS-built FAQ card secondary link
  focusability differs from the (dead) PHP template's intent.

## 🟠 Housekeeping / ops

- [ ] **16. Dead code trap: `templates/cards/*.php` (all 7) are never used.**
  Search results are built in `search.js` (`buildCard()`). Delete or mark
  `@deprecated` — edits there silently do nothing.

- [ ] **17. Log tables grow forever.** `ptk_search_log` + `ptk_click_log` have no
  retention; nopriv inserts mean bots can bloat them, ×11 sites. Add daily cron
  pruning (>12 months) matching the preview-token cleanup pattern
  (`class-public-preview.php:263`).

- [ ] **18. Leftover debug endpoint.** `?ptk_debug_terms=1`
  (`class-search-engine.php:84-136`) — admin-gated but flagged "remove after
  troubleshooting" twice; hooks admin_init on every load.

- [ ] **19. Chart.js floating `@4` + pinned SRI hash** (`class-analytics.php:497`) —
  next jsDelivr 4.x bump silently breaks the analytics chart. Pin exact version or bundle.

- [ ] **20. Transient invalidation breaks under persistent object caches.**
  `class-search-engine.php:141-148` (and uninstall.php:21-27) delete transients via
  SQL LIKE on wp_options — no-op under Redis/Memcached. Use a cache-version-salt key.

- [ ] **21. Uninstall gaps.** Doesn't drop `ptk_click_log` (`drop_click_table()` never
  called); misses options `ptk_installed_version`, `ptk_hub_slug`, `ptk_rewrite_flushed`;
  misses `ptk_ac_*`/popularity/updater transients + preview-token postmeta; cleans only
  the current site on multisite.

- [ ] **22. Activation doesn't provision subsites or new sites.** Tables created only
  via activation hook on one site (`pta-knowledge-hub.php:219-221`); inserts on
  table-less subsites fail silently (analytics/feedback). No `wp_initialize_site`
  hook for future schools. Required groundwork for the Vendor Directory.

- [ ] **23. Sync fidelity is partial.** `class-multisite.php:278-284` copies
  title/content/excerpt/terms only — no featured image, no custom meta, and
  **role restrictions (`ptk_visible_roles`) are not propagated** (restricted Council
  entries become unrestricted on subsites — borderline 🔴).

- [ ] **24. Backfill sync is synchronous O(sites × posts)** (`class-multisite.php:400-430`)
  — will eventually hit PHP timeouts. Batch or cron it.

- [ ] **25. `handle_suggest_to_council` checks `edit_posts` not `edit_post, $id`**
  (`class-multisite.php:465`). Trivial here; do NOT copy this pattern into vendor reviews.

- [ ] **26. Update zip has no integrity check** (`class-auto-updater.php:54-63`) —
  compromise of ixcreations.com = code exec on all sites. Standard for self-hosted
  updaters; consider a hash in update-info.json.

- [ ] **27. Meta-box gating quirk:** subsites always get the "Network Status" box even
  when sharing is off (`class-multisite.php:54`).

## ✅ Already done well (don't re-recommend)

Content Wizard flow (progressive steps, autosave + recovered-draft banner, draft-by-
default, category-specific validation messages, "Custom Entry" escape hatch); glossary
tooltips (longest-first matching, tap+hover, cached); two distinct glossary empty
states; zero-result search fallback + "Did you mean"; fresh-install empty state with
CTA; 44px touch targets, focus-visible, reduced-motion, iOS-zoom-safe inputs; admin
contextual tips (title placeholder, per-category tips box, Quick Tips meta box, CSS
hint boxes); custom admin columns; friendly post-update messages; starter content
importer (draft-only, confirm-gated); Content Visibility UX; review reminders
auto-stamping (and not resetting on Council sync); QR tips; analytics Content Gaps
framing; public preview tokens (random_bytes, TTL, auto-revoke, cron cleanup, friendly
deny); consistent nonce/capability hygiene; prepared SQL throughout; hashed IPs;
updater failure-caching + no_update handling.

---

## Vendor Directory — locked decisions & architecture notes

Decisions (Lucas, 2026-07-14): **distinct directory** (not a knowledge category) ·
**council-wide** (one shared list for all schools) · **members-only**.

Architecture (from ops audit):
- Vendors = CPT **on the Council site only**. Subsites read via
  `switch_to_blog( get_main_site_id() )` + per-subsite transient cache
  (pattern: `get_subsites()`, `class-multisite.php:721-743`). Do NOT broadcast-copy
  vendors (copies fragment reviews; sync doesn't carry meta anyway — see #23).
- Reviews = **network-wide shared table** `{base_prefix}ptk_vendor_reviews`
  (schema template: `class-feedback.php:31-52` — post_id/user_id/ip_hash dedup),
  + columns: blog_id (which PTA), rating dims (price, value/quality, recommend),
  comment, status (pending/approved).
- Cross-site writes precedent: `handle_suggest_to_council` (`class-multisite.php:462-533`).
- Spam protection: copy the Suggestions form pattern (nonce + honeypot + IP rate limit,
  `class-suggestions.php`).
- Build-right requirements from findings: cache by role from day one (#2); create the
  shared table network-safely + add to uninstall (#21, #22); object-level capability
  checks (#25); provision new sites (#22).
- Moderation: reviews attributed to real user + PTA; light approve queue on Council.
- Launch alongside the volunteer Welcome page (#7) as v3.0.

## "Smarter loop" improvements (knowledge flow, keep copy-based storage)

Decision (Lucas, 2026-07-14): keep the copy-based broadcast model for knowledge
articles (fix sync fidelity #23); strengthen the contribution loop instead:

- [ ] **28. Notify Council when a school suggests an entry** — suggestions currently
  sit invisible until someone visits the Network Sync page.
- [ ] **29. Notify the school when their suggestion is published** (and consider
  crediting: "Contributed by Hillside PTA"). Silence kills contribution habits.
- [ ] **30. Network-wide Content Gaps** — aggregate zero-result searches across all
  subsites on the Council analytics page ("3 schools searched 'tax exempt letter'").
  Biggest available "gets smarter" win; no storage changes needed.

## Suggested packaging

- **v2.9 "Trust & polish":** items 1–15 (bugs + volunteer experience), plus #16, #18.
- **v3.0 "Vendor Directory":** vendor CPT + reviews table + directory page + Welcome
  page, with #17, #20–25 groundwork folded in where the vendor work touches them.
