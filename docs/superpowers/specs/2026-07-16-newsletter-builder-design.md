# PTA HUB — Newsletter Builder (v1 Design Spec)

- **Date:** 2026-07-16
- **Status:** Approved design, pre-implementation
- **Author:** Lucas Deichl (with Claude)
- **Component:** New feature inside the PTA Knowledge Hub plugin (`pta-knowledge-hub`)

---

## 1. Goal

Let any school admin build a good-looking newsletter for their school's WordPress
website by filling in a simple, spelled-out form — no HTML, no wrestling with the
WordPress block editor. The output looks and feels close to the Northeast PTA
newsletter (e.g. `northeastpta.org/2026/06/14/post-3386/`), but is templated and
approachable for non-technical PTA volunteers across the network.

**Guiding principle (applies to every screen):** plain English, low cognitive
load. A human should be able to look at anything on screen and immediately know
what it is and what it connects to. Every review/guard item names the block it
belongs to and quotes the actual text. Never give the user mental overload.

## 2. Context

- **Distribution:** PTA HUB is a WordPress **multisite network**; each school is a
  sub-site. The plugin already syncs "Council" content down to school sub-sites
  and assigns each school a network-managed identity color
  (`ptk_site_colors`).
- **Users:** Non-technical PTA volunteers (school admins). Some are comfortable
  with WordPress; many are not. The design assumes the least-technical user.
- **Email platform:** All schools send email newsletters through **Givebacks**.
  Email is explicitly **out of scope for v1** (see §14), but the data model is
  designed so an email-safe renderer can be added later.
- **Author's current workflow (what we're replacing):** Lucas hand-authors HTML
  and uses an AI assistant to update dates, add/remove articles, and refresh
  content each week. Other admins have no AI assistant — the guided form + the
  duplicate-last-issue path replace that workflow.

## 3. Scope

### In scope (v1)

1. A **Newsletters** admin area on every school sub-site.
2. A **guided form** authoring experience (no WP editor, no raw HTML).
3. A pre-filled **suggested layout** so doing nothing yields a complete newsletter.
4. **Duplicate last issue** as a fast starting point.
5. A **carried-over content guard** (review-before-publish) that blocks publish
   until stale/carried-over items are confirmed.
6. **Theme-based color control** (presets + one optional brand-color override),
   pre-seeded from the school's Council identity color.
7. **Drag-and-drop images** (stored in the WP media library under the hood).
8. **Council-pushed defaults** (suggested layout + theme presets), network-wide.
9. Publish as a public **newsletter post** with its own archive page.
10. **Share-a-preview** links for sign-off before publishing (reuses existing
    public-preview system).
11. A plain-English **"OK to share publicly / no student faces or PII"** confirm
    in the publish step.

### Out of scope (v1) — revisit later

- Email version / Givebacks integration (see §14).
- Any AI writing help.
- Separate primary/accent color pickers (only a single brand-color override).
- Reordering or removing the locked Header/Footer.
- Multiple template *styles* (v1 ships one default template; more later).
- Per-newsletter QR codes (explicitly dropped — posts already surface on the
  site homepage).
- Feeding newsletters into the "What's New" notifier (optional fast-follow).

## 4. Architecture Overview

- **Post type:** A dedicated public custom post type (working name
  `pta_newsletter`) with its own archive page ("Newsletters"), kept separate from
  the `pta_knowledge` knowledge base and the regular blog so newsletters are easy
  to find. Published newsletters render as normal public posts.
- **Data model — structured content, then render (decision #6):**
  - The newsletter's **structured content** (ordered list of blocks + each
    block's fields) is stored as **post meta**, not baked only into HTML.
  - **Render target (decided):** post meta is the single source of truth; on
    every save the plugin **regenerates** the post's `post_content` from that
    structured data using the default template's styles. `post_content` is always
    machine-generated, never hand-edited — so there is no drift, the newsletter
    behaves like a normal post (feeds, search, and later a Givebacks copy-paste
    all work), and editing always reloads the form from meta, not from HTML.
  - Rationale: (a) "duplicate last issue" and "edit" reconstruct the form from
    structured data; (b) the deferred email version becomes a *second renderer*
    over the same data instead of a rebuild; (c) the carried-over guard can
    compare structured fields between issues.
- **Admin surface:** A custom guided-form admin screen (following the existing
  `class-content-wizard.php` pattern), not the native block editor.
- **New classes (proposed, mirroring existing naming/`init()` conventions):**
  - `class-newsletter-post-type.php` — registers the CPT + archive.
  - `class-newsletter-builder.php` — the guided-form admin UI + save handler.
  - `class-newsletter-renderer.php` — structured data → HTML (default template).
  - `class-newsletter-guard.php` — carried-over / staleness review logic.
  - `class-newsletter-themes.php` — theme presets, brand-color override, seeding
    from `ptk_site_colors`.
  - Council default push handled via the existing multisite/sync layer
    (`class-multisite.php` / `class-network-provisioning.php`).
- **Suggested build sequence** (for the implementation plan): CPT + renderer
  first (a newsletter that renders from structured meta), then the guided-form UI
  as the spine, then the carried-over guard, then themes + Council push. Each is
  part of one coherent feature, but this order keeps every step independently
  testable.

## 5. The Building Blocks

Every newsletter is Header → [movable middle] → Footer.

| Block | Role | Notes |
|-------|------|-------|
| **1. Header** | Core, pinned top (locked) | Logo, school name, issue number, date, short greeting. Issue # and date auto-fill. |
| **2. Key announcement strip** | Optional, movable | One colored bar with the single most important thing this week. |
| **3. Upcoming events** | Core, movable, repeatable rows | Date + title + one-line description per row. The "This week / Next week" pill auto-relabels from the reader's current date. |
| **4. Featured story / hero** | Optional, movable | Big colored block: headline + a paragraph or two, optional image. |
| **5. Story cards** | Optional, movable, repeatable | Heading + paragraph + optional image + optional "read more" link. |
| **6. Footer** | Core, pinned bottom (locked) | Sign-off, PTA links, "view full calendar." Set once, reused every issue. |

- **Header and Footer are locked** (always present, always in place).
- **Everything between is a movable list** the admin can reorder, add, remove, or
  repurpose (e.g. use the announcement strip lower down).
- **A suggested default order is pre-filled** (Header → Announcement → Events →
  Featured → Story cards → Footer), so customization is optional.
- **Section dividers** (the italic "§ …" labels) are inserted automatically
  between blocks — not something the admin manages.
- **Reordering UX** must be dead simple and plain-language (clear up/down or
  drag with obvious labels), never a source of overload.

## 6. Authoring Flow

- **Two starting points:**
  - **Start fresh** — opens the suggested layout, pre-filled and ready.
  - **Start from last issue** — one click clones the most recent newsletter as a
    new draft (triggers the carried-over guard, §7).
- **The form:** one plain-language section per block, repeatable rows via
  "+ Add event" / "+ Add story" style controls. No HTML, no WP editor.
- **Live preview** of the rendered newsletter alongside the form.

## 7. The Carried-Over Guard (review-before-publish)

When an admin starts from last issue, publishing is gated by a **Before you
publish** review.

- **Auto-flagged (amber), because we can prove staleness:**
  - Any event/date **in the past**.
  - The **issue number** (auto-bumps to the next number).
  - Any field whose text is **byte-for-byte identical** to last issue.
- **Listed for a human glance (not auto-judged):** every other carried-over
  block, because "is this story still true?" can't be detected — only a person
  can confirm.
- **Each item, in plain English, names its block and quotes its actual text**,
  with clear actions (e.g. Update date / Remove / Keep anyway; Edit / Keep as-is).
- **Publish stays locked** until every flagged item is confirmed; then the button
  unlocks. No stale content can slip out unreviewed.
- **Reuse vs. new logic:** the field-level diff between issues and the
  publish-blocking gate are **genuinely new logic** — not a thin wrapper. What we
  borrow from `class-review-reminders.php` is its proven *patterns*: stamping
  state on publish/update **except during Council sync**, and the green/amber/red
  indicator vocabulary. Plan for real new code here, reusing those patterns.

## 8. Color Theming

- **Primary control:** pick a **theme** (a bundled primary + accent + background),
  applied instantly. Ships with a small set (e.g. Harbor Navy, Forest, Maroon,
  Teal). "Harbor Navy" mirrors the current Northeast look.
- **Advanced (tucked away, optional):** a single **brand color** swatch for
  schools whose color isn't a preset. Text/contrast **auto-derive** so nothing
  can become unreadable (no white-on-white).
- **Seeding:** each school's default theme is pre-seeded from the network
  identity color already stored in `ptk_site_colors`, so a new school is on-brand
  with zero setup.
- **No** separate primary/accent pickers in v1 (deferred).

## 9. Images

- **Drag-and-drop** onto story cards and the featured hero.
- Images are **stored in the WordPress media library** behind the scenes, so
  resizing and reuse come for free — it just *feels* like drag-and-drop.

## 10. Council Defaults (network)

- The Council (network admin) can push a **suggested layout + theme presets**
  down to all school sub-sites, in the same spirit as Council article sync.
- Schools **start from those defaults and can adjust** locally.
- Implemented via the existing multisite sync layer; Council pushes must **not**
  reset a school's local review/edit state (consistent with how
  `class-review-reminders.php` avoids resetting subsite clocks during sync).

## 11. Default Template & Design System

The single v1 template mirrors the existing NEPTA newsletter design (source of
truth: `NEPTANewsletter/newsletter-038-week-of-6-22-26.html` and
`NEPTANewsletter/templates/`).

- **Typography:** Inter (headings + body), Fraunces (serif italic accents — date
  numbers, section labels).
- **Default palette (Harbor Navy):** navy `#1a2f5c`, cream page `#efece6`, gold
  accent `#ffd166`, red emphasis `#a51d23`, near-black text `#111`, muted
  `#4a4a4a`, hairline borders `#e6e3dc`. Theme selection swaps primary/accent/bg;
  text and contrast derive from those.
- **Block styles must follow PTA HUB's current design rules:** no single-side
  accent borders / colored side-stripes (recently removed from the plugin; also a
  standing design rule). Use full borders, fills, or colored content instead, so
  newsletters look native to the hub.
- **Keep the auto-relabeling date behavior** from the current newsletter (event
  pills relabel to "This week / Next week / Past" from the reader's current date).

## 12. Reuse Map (existing code this builds on)

| Existing system | Reused for |
|-----------------|-----------|
| `class-public-preview.php` (7-day no-login preview tokens) | Share-a-preview sign-off before publish. |
| `class-review-reminders.php` (stamp-on-publish-except-sync, freshness dots) | The carried-over / staleness guard engine. |
| `class-site-colors.php` / `ptk_site_colors` | Seeding each school's default theme color. |
| `class-multisite.php`, `class-network-provisioning.php` | Council push of default layout + theme presets. |
| `class-content-wizard.php` | Pattern for the guided-form admin UI. |
| WP media library | Storage behind drag-and-drop image uploads. |

## 13. Publishing Behavior

- Publishes as a public `pta_newsletter` post with a "Newsletters" archive.
- **Issue number** auto-increments; **date** auto-fills (both editable).
- **Share-a-preview** link available on drafts (reuses public-preview tokens) so a
  president/principal can review without a login.
- **Publish-step confirm:** a single plain-English checkbox — "These photos are
  OK to share publicly (no student faces or personal info)" — surfacing the PTA
  house rule to admins who may not know it. (Trial feature; evaluate how it
  tracks in real use.)

## 14. Forward Compatibility (design now, build later)

- **Email version:** deferred. Because content is stored structured (§4), the
  email path becomes a **second, email-safe renderer** (table-based layout,
  inline styles — matching the existing `email-*.html` templates) over the same
  data. No rebuild of the builder.
- **Givebacks:** all schools send email via Givebacks. The pragmatic email design
  is to produce **clean, email-safe HTML that pastes directly into the Givebacks
  newsletter form** — exactly the current manual workflow. Whether Givebacks
  offers an API to push a newsletter directly is **unverified** and logged as an
  open research item for the email phase (see §16). It does not affect v1.

## 15. Fast-follows (post-v1)

- Feed published newsletters into the "What's New" notifier
  (`class-notifications.php`).
- Additional template *styles* beyond the default.
- Email renderer + Givebacks paste-in flow (then investigate Givebacks API).
- **Typography presets** (decided 2026-07-16): the plugin deliberately does NOT
  load web fonts. Font stacks are `'Inter', <system sans>` and
  `'Fraunces', Georgia, serif` — a school whose theme already provides those
  fonts (e.g. Northeast) gets the intended design for free; everyone else
  degrades to a clean system sans + Georgia. No external request, no privacy
  question, nothing forced on schools with their own type. When theme presets
  (§8) are built, bundle typography INTO each preset so a school picks a whole
  look (colors + type together) rather than choosing fonts à la carte.
- **Theme chrome fit:** a school's theme renders its own post title above the
  newsletter and may constrain the content width. Decide how the newsletter
  should sit inside an arbitrary theme (full-bleed vs. constrained, title
  suppression) once real school themes are in play.

## 16. Open Questions

1. **Givebacks API:** Does Givebacks expose a public API to push/send a
   newsletter, or is paste-in-HTML the only route? Resolve during the email phase.
2. **CPT vs. normal posts:** Confirm a dedicated `pta_newsletter` CPT (recommended
   for clean separation + its own archive) vs. reusing standard posts. Design
   assumes the CPT.
3. **Reorder interaction:** exact non-technical-friendly control for reordering
   the movable middle blocks (up/down buttons vs. drag), to be finalized in the
   implementation plan against the plain-English/low-overload principle.
