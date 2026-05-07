# PTA HUB

## What this is

A WordPress plugin called **PTA Knowledge Hub** — a searchable knowledge base
that lets PTA volunteers publish content through WordPress and lets parents/
members find answers instantly via a smart search bar with relevance ranking,
synonyms, content-type grouping, and a recommendation wizard.

The original demo was built as **"Ask Tory"** — a knowledge hub for Tory Burch
retail associates (product info, talking points, care guides, styling tips,
short videos). The same engine has since been generalized for PTA use. The
project is named "PTA HUB" because the PTA-targeted variant is the active
shape, but the underlying plugin is reusable for any organization.

See `Ask_Tory_Project_Overview.md` for the original demo's UX walkthrough,
which still describes the user-facing flows accurately.

Lucas Deichl is the author (see plugin header) and uses this for his Northeast
PTA chapter (sibling project: `NEPTANewsletter`).

## Stack & tooling

- **WordPress plugin** (PHP). Plugin entry: `pta-knowledge-hub/pta-knowledge-hub.php`.
- Plugin version: `2.3.0` (as of last edit).
- Custom post type backed by class files in `includes/`.
- Front-end search via shortcode + page templates.
- No node/build pipeline. PHP + a small amount of bundled CSS/JS in
  `pta-knowledge-hub/assets/`.

## Project layout

- `pta-knowledge-hub/` — The actual WordPress plugin.
  - `pta-knowledge-hub.php` — Plugin bootstrap. Defines `PTK_VERSION`,
    `PTK_PLUGIN_DIR`, requires the `includes/class-*.php` files.
  - `includes/` — One class per concern:
    - `class-post-type.php` — Custom post type registration.
    - `class-search-engine.php` — Search relevance, synonyms, ranking.
    - `class-shortcode.php` — Front-end shortcode renderer.
    - `class-analytics.php` — Tracks searches, clicks, copies; powers
      the admin dashboard's Content Gaps view.
    - `class-content-wizard.php` — The 3-question recommendation wizard.
    - `class-meta-fields.php`, `class-block-patterns.php`,
      `class-admin-helpers.php` — Editorial UX.
    - `class-glossary-page.php`, `class-glossary-tooltips.php` — Glossary.
    - `class-role-access.php`, `class-multisite.php`,
      `class-notifications.php`, `class-feedback.php`,
      `class-content-importer.php`, `class-auto-updater.php` — Operations.
  - `templates/` — Front-end PHP templates: `search-page.php`,
    `single-pta_knowledge.php`, `cards/`.
  - `data/synonyms.json` — Synonym dictionary used by search.
  - `assets/css/`, `assets/js/`.
  - `uninstall.php` — Cleanup on plugin removal.
- `pta-knowledge-hub.zip` — Pre-built distributable.
- `Ask_Tory_Project_Overview.md` — UX overview from the original demo.
- `PTA Technology Infrastructure Primer and Use.pdf` — Background reading.
- `update-info.json` — Used by the auto-updater.
- `.buddyshelf.json` — BuddyShelf project marker; remote is
  `github.com/ixLocdev/pta-hub.git`.

## Conventions

- All PHP classes use `class-*.php` filenames and are required in order
  from the plugin bootstrap.
- Constants are prefixed `PTK_` (PTA Knowledge).
- Six content types are first-class: Spotlight, Talking Points, Video,
  Compare, Care Guide, Styling. Don't invent a seventh without revisiting
  `Ask_Tory_Project_Overview.md`.

## Commands

This is a WordPress plugin — install it into a WordPress site to run it.
There is no local build/test command. To ship a release, zip the
`pta-knowledge-hub/` directory.

## Lucas-context

Lucas's PTA work has two halves: the weekly/monthly emails
(`/Users/lucas/apps/NEPTANewsletter`) and this knowledge hub. The hub
is the deeper product — built first as the Tory Burch "Ask Tory" demo,
then repurposed for PTAs. Lucas's commercial Tory Burch work today is
the PowerPoint automation contract (`Ashley PPT`, `TB One Pager`),
which is unrelated to this codebase.

## Gotchas

- The plugin presents itself to WordPress as "PTA Knowledge Hub" but the
  repo/folder is named "PTA HUB". Don't rename one without the other.
- `Ask_Tory_Project_Overview.md` describes the Tory Burch demo's tone
  and content types — it's still the best UX spec, but s/Tory/PTA/ when
  applying it to the current product.
- `update-info.json` is consumed by `class-auto-updater.php`; bumping
  plugin version in `pta-knowledge-hub.php` without updating it (or
  vice versa) breaks self-update.
