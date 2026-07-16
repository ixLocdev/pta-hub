# Knowledge-Base Content-Sharing Overhaul — Design Spec (v4.0)

**Date:** 2026-07-15 · **Approved by:** Lucas (brainstorm session + visual mockups)
**Origin:** Follow-on from the Welcome page work — Lucas wants the Council to be
the single authoritative source of knowledge content, targetable per-article,
un-editable on school sites, with each school's own content ranked first in its
own search, and clear per-school color cues in the admin.
**Companion mockups:** `.superpowers/brainstorm/79559-1784163683/`
(audience-control-v3, school-admin-list, school-palette).

## Product decisions (all locked with Lucas)

| Decision | Choice |
|---|---|
| Model | Keep the existing **copy/broadcast** multisite model (class-multisite.php) and add targeting + locking on top — NOT a rewrite to single-source (Option B) |
| Audience per article | **All schools** (default) · **Council only** · **Only these schools** (checklist). Always active — NO master on/off switch |
| Shared copies on schools | **Locked read-only** at the capability level — school admins can view but not edit/delete; only the Council edits (re-syncs) |
| School admin lists | One list with an **Owner** column (colored dot + name), filters **Ours \| From Council \| All**; Edit on own, View on Council's |
| School colors | **Council-set palette**, one screen, editable, pre-filled with the approved defaults; badges are a **colored dot + name** (text stays legible) |
| Search ranking | **Blended local boost** — a school's own articles get a scoring bonus so they usually rank first, but a strongly-relevant Council article can still win (NOT strict tiering) |
| Glossary | Follows automatically (same CPT) — no separate work |
| School local content | Schools still create + edit their own local articles; "Suggest to Council" remains |
| Migration | Normalize existing share meta; Council clicks the existing **"Sync all entries now"** button once after deploy (manual, visible) |
| Version | v4.0.0 |

## School roster & default palette

Confirmed live from montclairpta.org (Renaissance Middle **excluded — closed 2026**):
10 active schools + Council. Domains: bradfordpta.org, bullockpta.org,
edgemontpta.org, hillsidepta.org, nishuanepta.org, northeastpta.org,
watchungpta.org, buzzaldrinpta.org, glenfieldpta.org, mhs-pta.org; Council =
montclairpta.org.

Default colors (Council-editable; keyed by blog_id at runtime, names for reference):

| Site | Color | Site | Color |
|---|---|---|---|
| PTA Council | `#475569` | Nishuane | `#0d9488` |
| Northeast | `#2563eb` | Watchung | `#0891b2` |
| Bradford | `#dc2626` | Buzz Aldrin | `#7c3aed` |
| Bullock | `#ea580c` | Glenfield | `#db2777` |
| Edgemont | `#d97706` | Montclair High | `#4338ca` |
| Hillside | `#16a34a` | | |

Note: real school sites nearly all share a navy template, so auto-detected colors
aren't distinct — hence a hand-assigned distinct palette, Council-editable.

## Architecture

Builds on `class-multisite.php` (the existing sync engine): Council posts are the
source; `save_post_pta_knowledge` copies them to subsites; copies carry
`ptk_network_source` (Council post ID) + `ptk_network_source_blog` meta;
`is_network_copy()` identifies them. Today sharing is a boolean
(`ptk_share_network`, only `'0'` opts out) and copies are editable (only a
"Managed by Council" notice, no real lock). This overhaul changes four things
and adds display/ranking:

### 1. Audience targeting (replaces the boolean)
- **Storage — two clean metas** (avoids overloading one key with mixed
  string/array types and the meta_query ambiguity that causes):
  - `ptk_audience_mode` = `'all'` · `'some'` · `'none'` (Council-only).
  - `ptk_share_sites` = array of target blog_ids, used only when mode is `'some'`.
- **Accessor** `PTK_Multisite::audience_targets( $post_id )` returns the resolved
  list of target blog_ids (all subsites for `'all'`, the stored list for
  `'some'`, empty for `'none'`). It **normalizes legacy meta inline** — a post
  still carrying old `ptk_share_network` (`'1'`/unset → `'all'`, `'0'` → `'none'`)
  reads correctly even if the one-time migration missed it. So the accessor, not
  the migration, is the source of truth.
- Editor control (Council site only, in the existing Network Sharing meta box):
  radio **All schools / Council only / Only these schools** + a school checklist
  (populated from `get_sites()`, excluding the main site and any closed/archived).
- Sync logic (`sync_to_network` / `unshare_from_network`, parameterized by a
  target blog_id set): the save handler **captures the previous target set
  (`audience_targets`) BEFORE writing the new meta**, then pushes copies to the
  new targets and **removes** copies from schools dropped out of the set. Backfill
  (`backfill_all`) uses `audience_targets` per post. **`get_shared_post_ids()`
  (feeds the Network Sync admin page) must be reworked** to select posts whose
  mode ≠ `'none'` (its current `meta_value = '1'` query, and the `'1'`-stamping
  in `backfill_all`, are removed).
- Migration: a version-gated one-time normalizer (scanning **all post statuses**,
  not just publish) writes `ptk_audience_mode`/`ptk_share_sites` from legacy
  `ptk_share_network`. Mapping is one-way-safe; the inline accessor covers anything
  it misses.

### 2. Lock the copies (net-new enforcement)
- A `map_meta_cap` filter: on a **subsite**, for a `pta_knowledge` post that
  `is_network_copy()`, deny the per-post meta caps `edit_post` and `delete_post`
  (return `do_not_allow`). (Only these two — they're per-post caps that carry the
  post ID in `$args`; primitive caps like `publish_posts` have no post to scope
  to and aren't part of a per-post lock.) This is real permission-level
  enforcement, not just hidden UI — and it's transparent to the sync process,
  which writes copies via `wp_insert/update/trash_post` and never checks caps.
- Row actions on the list: replace **Edit** with **View** for network copies;
  keep the existing "Managed by PTA Council — edit on Council site" notice on the
  edit screen (still reachable read-only / redirect as appropriate).

### 3. Owner column + filters (all sites)
- Add an **Owner** column to the `pta_knowledge` list table: a colored dot +
  name — `is_network_copy()` → the Council's slate + "PTA Council" with a 🔒;
  otherwise the **current site's** color + its name. (This is a second
  `manage_pta_knowledge_posts_columns` filter alongside the existing ones in
  admin-helpers/review-reminders — it must **append** to the columns array, not
  replace it.)
- Add filter views **Ours | From Council | All** (via the `views_edit-pta_knowledge`
  filter + a query var that filters on the presence/absence of `ptk_network_source`).

### 4. School color palette (Council)
- New "School Colors" settings screen on the **Council site**: lists every site
  (`get_sites()`) with a color input, pre-filled from the default palette.
- Stored as a **network option** `ptk_site_colors` = `{ blog_id: hex }` via
  `get_site_option`/`update_site_option` (NOT per-blog `get_option`), so every
  subsite's Owner column reflects the Council-set colors. On single-site,
  `get_site_option` falls back to `get_option` — still safe.
- Helper `ptk_site_color( $blog_id )` returns the set color or the palette default;
  used by the Owner column (and available for future front-end use).

### 5. Local-first search boost
- In `class-search-engine.php` `score_post()`, give **local-origin** posts (NOT
  `is_network_copy()`) a **relevance bonus** — enough that a school's own articles
  usually outrank comparable Council ones, but NOT an absolute override (a
  much-stronger Council match can still win). One tunable constant, added to the
  per-post score alongside the existing recency/popularity layers.
- **Gate it like the other layers:** apply only when the post's base `$score > 0`
  (never surface a non-matching local post) and only under
  `is_multisite() && ! is_main_site()` (inert on the Council site, where
  everything is local anyway).

### Files (responsibilities; exact split finalized in the plan)
- `class-multisite.php` — audience meta + editor control, targeted sync/diff-remove,
  backfill-respects-audience, legacy→audience migration.
- New `class-content-lock.php` — the `map_meta_cap` read-only lock + View row action.
- New `class-site-colors.php` — palette option, Council settings screen,
  `ptk_site_color()`, the Owner column + Ours/From-Council/All filters (display).
- `class-search-engine.php` — the local-first scoring bonus.
- `pta-knowledge-hub.php` — require/init the new classes; migration hook.

## Multisite / single-site safety
- All new cross-site work guards `is_multisite()` (single-site has no subsites,
  ms-blogs functions don't exist there); the lock and Owner column are no-ops on
  single-site (nothing is a network copy). Council-only pieces gate on **core
  `is_main_site()`** (true on single-site) — never `PTK_Multisite::is_main_site()`.

## Out of scope (deliberately)
- Front-end (member-facing) colored badges — this is admin-side scanning for now.
- Rebuilding to true single-source (Option B) — the lock delivers the same result.
- The role-restriction sync-fidelity gap (audit #23) — stays in the backlog.
- Any change to the vendor directory, search relevance beyond the local bonus, or
  the Welcome screen.

## Success criteria
1. A Council article set to **All schools** appears — locked, view-only — on every
   school; **Only these** appears only on the chosen schools; **Council only**
   appears on no school.
2. A school admin **cannot** edit or delete a Council article (no Edit action, and
   the capability is denied even by direct URL); they can freely edit their own.
3. Every entry list (Council + schools) shows colored-dot **Owner** badges and the
   **Ours | From Council | All** filters.
4. The Council "School Colors" screen sets each school's color; badges reflect it
   immediately.
5. On a school site, that school's own articles get a visible search boost — they
   usually rank above comparable Council articles, without hard-hiding stronger
   Council matches.
6. Glossary terms obey audience, lock, and ranking identically to other entries.
7. A single-site install is completely unaffected (no fatals, no lock, no badges).
