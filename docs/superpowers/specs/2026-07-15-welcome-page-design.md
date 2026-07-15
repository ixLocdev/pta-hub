# Welcome "Start Here" Page — Design Spec (v3.1)

**Date:** 2026-07-15 · **Approved by:** Lucas (brainstorm session, visual companion mockup)
**Origin:** Audit item #7 ("No first-run 'start here' for new volunteers") + the IT
council's "introduce the tool to new PTA presidents" goal.
**Companion:** audit `docs/audits/2026-07-14-full-plugin-audit.md`; approved mockup
`.superpowers/brainstorm/61046-1784158502/start-here-layout-v2.html`.

## Product decisions (all locked with Lucas)

| Decision | Choice |
|---|---|
| Audience & placement | **Admin "Start Here" screen** for logged-in volunteers/presidents (NOT a front-end members page) |
| Entry point | Clicking **PTA Hub** in the admin sidebar lands on Start Here (All Entries becomes the next submenu); PLUS a Dashboard-home widget nudge |
| First-run redirect | **None** — the calmer menu-landing + widget, nothing hijacked |
| Scope | Orientation **+ a light "needs attention" strip** (live counts that link to action), not a stats-heavy dashboard |
| Role/site awareness | Each person sees only cards/nudges for tools they can use **on the current site** |
| Content ownership | Built-in plugin copy (NOT an editable page/CPT) |
| Design rule | **No half/single-side accent borders** — full borders, fills, or colored content only ([[design-no-half-borders]]) |
| Version | v3.1.0 |

## Screen anatomy (the approved mockup)

Rendered inside the standard wp-admin content area (gray page, white cards,
WP-admin blue `#2271b1` primary action). Top to bottom:

1. **Welcome header** — `👋 Welcome to the PTA Hub` + one plain-English line
   ("Everything your PTA knows, in one place — plus a few easy things you can do
   here. No tech skills needed.").

2. **"Waiting for you" strip** — a row of small full-bordered cards, each a large
   **colored number** (red `#d63638` = needs action, amber `#bd8600` = soon) over
   a blue action link. A card renders **only when its count > 0** (a quiet week
   shows an empty strip, or the strip's heading is hidden entirely). Cards:
   - **Vendor reviews to approve** → the Vendor Approvals queue. *Council
     moderators only* (`edit_others_posts`), *main site only*.
   - **Topic suggestions from members** → the Suggestions list. *Editors who can
     process them* (`edit_posts`).
   - **Entries due for a review** → the entries list filtered/sorted by review
     status. *Content editors* (`edit_posts`).
   Below the strip: "These only appear when something's actually waiting."

3. **"What would you like to do?" cards** — 2-column grid of full-bordered cards,
   each an emoji + title + one-line + button:
   - **➕ Add or update an entry** → the Content Wizard (`PTK_Content_Wizard::url()`).
     Primary (filled) button. Shown to anyone who can `edit_posts`.
   - **🏪 Manage the Vendor Directory** → the vendor CPT list / approvals.
     *Council only* (`edit_others_posts`), *main site only*.
   - **🔎 See the live Hub** → the front-end Knowledge Base page (`ptk_hub_url()`),
     opens in a new tab. Shown to everyone.
   - **📖 Browse the glossary** → the front-end Glossary page, new tab. Everyone.

4. **Reassuring footer** — "New to all this? Everything here is safe to click
   around — you can't break anything, and nothing goes public until it's ready. 💛"

## Role / site matrix

| Viewer | Sees |
|---|---|
| Council admin (main site) | Everything: all three nudges + all four action cards |
| School volunteer / editor (subsite) | Welcome, "Add an entry", "See the live Hub", "Browse glossary"; the "Topic suggestions" and "Entries due for review" nudges for their own site if they can `edit_posts`; NO vendor-management card and NO vendor-approval nudge (vendors are Council-managed) |
| Low-privilege contributor | Welcome, "See the live Hub", "Browse glossary", and "Add an entry" if they hold `edit_posts` |

Principle: **capability-gate every card and nudge; render a card only if the
viewer can act on it.** A card whose count is zero, or whose capability the
viewer lacks, is simply absent — never shown greyed-out or with a "you can't do
this" message (no dead weight, no scolding).

## Data sources (reuse — no new storage)

The three counts already exist elsewhere; Start Here reads them:

- **Vendor reviews to approve:** `PTK_Vendor_Moderation::pending_count()` (currently
  private — the plan promotes it to a public accessor). Main site only.
- **Topic suggestions:** count of published `ptk_suggestion` posts
  (`PTK_Suggestions::POST_TYPE`) via `wp_count_posts()` — per current site.
- **Entries due for review:** overdue count from `PTK_Review_Reminders` (the plan
  adds a small public `overdue_count()` helper alongside the existing dashboard
  widget logic and `get_threshold()`). Per current site.

Counts are read live on page render (cheap; these are small tables) — no caching
layer needed for a once-per-session admin screen.

## Architecture

- **New class `includes/class-welcome.php` (`PTK_Welcome`)** — owns:
  - The **Start Here submenu page** under `edit.php?post_type=pta_knowledge`,
    registered so it sorts to the TOP of the PTA Hub submenu (so clicking the
    top-level "PTA Hub" opens it). Capability: `edit_posts` (any volunteer who
    can contribute). Renders the screen described above.
  - The **Dashboard-home widget** (`wp_add_dashboard_widget` on
    `wp_dashboard_setup`): "PTA Hub — Start Here" — one friendly line, the single
    most urgent waiting-count if any, and a button to the Start Here screen.
    Registered for anyone who can `edit_posts`.
  - **Submenu reordering** so Start Here precedes "All Entries" (adjust the
    `$submenu` global for the `edit.php?post_type=pta_knowledge` parent, the
    standard WP technique).
- **New `assets/css/welcome.css`** — the card/strip styles (mirrors the plugin's
  existing admin idioms; full borders only).
- **Wiring:** `require_once` + `PTK_Welcome::init()` in `pta-knowledge-hub.php`,
  alongside the other classes. No activation or DB changes.
- **Multisite:** the screen and widget render on every site's admin (volunteers
  contribute from their own school site). Council-only pieces are gated by
  capability AND `is_main_site()` so subsites never show vendor management.
- **No front-end footprint** — this is entirely wp-admin; the members-facing side
  is unchanged.

## Out of scope (deliberately)

- A front-end members landing page (Lucas chose admin-only).
- Activity feeds, charts, or a rich analytics dashboard (overwhelms the audience).
- Editable/CMS-managed welcome content — the copy is built into the plugin.
- First-run auto-redirect.
- Any change to the existing search, wizard, vendor, or knowledge behavior.

## Success criteria

1. A brand-new volunteer who logs into their school site sees, without hunting, a
   friendly screen naming the 2-3 things they'll do and one-click buttons to each.
2. A Council admin sees, at a glance, how many vendor reviews / suggestions /
   stale entries are waiting, each linking straight to the action — and nothing
   when the queues are empty.
3. Every card and nudge a viewer sees is something they can actually act on
   (correct capability + site); nothing they can't act on appears.
4. No half/single-side accent borders anywhere in the UI.
5. Zero new database tables, options, or front-end changes; existing behavior
   untouched.
