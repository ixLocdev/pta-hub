# PTA Hub Interface — Phase 2: Simple mode

> **For agentic workers:** implement task-by-task. Each task ends with the suite silent and a commit.

**Goal:** When a site has the new look on, a volunteer signs in and sees the PTA Hub and nothing else — no WordPress menu of other people's tools, no notices from other plugins — with an always-visible one-click way back to the whole of WordPress.

**Architecture:** `PTK_Simple_Mode` owns one question — *is simple mode on for this user, on this site, right now?* — and acts on it in four places: the admin menu, the admin bar, the login landing, and notices inside Hub screens. Every decision helper is pure and tested; the WordPress-coupled parts are thin wrappers. Nothing runs unless `PTK_Hub_Look::on()` is true, so a site that hasn't opted in is untouched.

**Spec:** `docs/superpowers/specs/2026-09-17-pta-hub-interface-design.md` §3, §3.1.

**Non-negotiables:**
- `main` carries 8 of Lucas's own uncommitted one-line path fixes — never commit, revert or stash them; `git add` only the task's paths.
- **With the new look off, nothing in this phase may run at all.** That is the property to protect above every other.
- Permissions never change. Hiding is not security: every screen keeps its own capability checks, and a hidden URL still works if typed.
- Plain English; US spelling; no `__()` wrappers; never one-sided borders.
- Tests before every commit: `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done && for f in tests/*.mjs; do node "$f" >/dev/null || echo FAIL $f; done`
- Playground: `preview_start` name `pta-hub-main`, http://127.0.0.1:9406 only, `zz-*` deleted before committing.

---

## Task 1 — The decision (`includes/class-simple-mode.php`, `tests/test-simple-mode.php`)

Per-user meta `ptk_simple_mode` holding `'1'`, `'0'` or absent; per-site option `ptk_simple_mode_roles`
holding the roles it defaults on for (default: every role except `administrator`).

Pure helpers, all tested:

- `on_for( $user_meta, $roles, $default_roles )` — the person's own choice wins; absent means "on if any
  of their roles is in the default list".
- `default_roles()` — `array( 'editor', 'author', 'contributor', 'subscriber' )`, i.e. everyone but
  administrators, as the spec says.
- `sanitize_roles( $submitted, $all_roles )` — only real role slugs survive; `administrator` may be
  included if a site chooses.
- `too_small_for_hub( $caps )` — true when the person lacks `edit_posts`; those people never land in
  the admin at all (task 3).

Tests must cover: an explicit `'0'` beats a default-on role; an explicit `'1'` beats a default-off role;
an admin with no meta is off; a volunteer with no meta is on; an unknown role is ignored; nothing is
on when the new look is off (`active( $look_on, ... )` returns false whatever else is true).

---

## Task 2 — The menu and the admin bar

- `admin_menu` at priority 999: when `PTK_Simple_Mode::active()`, `remove_menu_page()` every top-level
  menu except the Hub's (`edit.php?post_type=pta_knowledge`) plus WordPress's own profile screen, and
  `remove_submenu_page()` anything under the Hub that isn't a Hub task. Never CSS hiding.
- Keep, always: the Hub menu, "Profile" (people must be able to change their own password), and — on
  multisite — nothing about Network Admin changes.
- `admin_bar_menu` at priority 999: remove `new-content`, `comments`, `updates`, `wp-logo` and plugin
  nodes; keep `site-name`, `my-sites` (multisite), `my-account`, and add one node: **"Show all of
  WordPress"**.
- Hub screens hide Screen Options and the help tab (`screen_options_show_screen`, `contextual_help`).
- A pure `keep_menu_slug( $slug, $hub_slugs )` decides what survives, so the list is testable without
  WordPress.

Verify in Playground with three accounts' capability sets simulated: the menu shows only PTA Hub and
Profile with simple mode on; the full menu returns the moment it is off.

---

## Task 3 — Landing and the way out

- `login_redirect`: with simple mode on and the person at or above `edit_posts`, send them to the Hub
  home. Below `edit_posts`, send them to the **public** Hub page, never `wp-admin`.
- `admin_init`: someone in simple mode who lands on `index.php` (the dashboard) is moved to the Hub
  home. No other screen is redirected — typed URLs still work, as the spec promises.
- The switch itself: `admin_post_ptk_simple_mode` toggles the current user's meta (nonce, capability
  check, referer-safe redirect back). Wire it to the home screen's quiet link and the admin-bar node,
  so "Show all of WordPress" turns it off and "Back to the simple view" turns it on.
- Never redirect an AJAX, REST, cron or network-admin request.

---

## Task 4 — Quieter Hub screens

- On Hub screens only, clear other plugins' notices: on `in_admin_header`, drop every callback on
  `admin_notices` / `all_admin_notices` except the Hub's own, then print the Hub's own messages under
  the page title.
- Keep WordPress's own critical notices (update-nag is removed; a screen's own error/success stays).
- A pure `should_clear_notices( $is_hub_screen, $simple_on )` decides, and is tested.

---

## Task 5 — The settings

On Newsletter settings, under the "Use the new PTA Hub look" checkbox and only shown when it is ticked:
**"Who gets the simple view by default"** — a checkbox per role, defaulting to everyone but
administrators, with one line: "People can always switch for themselves." Saving uses
`sanitize_roles()`.

---

## Task 6 — Release 4.13.0

Version bump in both places, `update-info.json` entry in plain English (leading with the fact that
nothing changes until a site turns the new look on), zip rebuilt from the repo root excluding
`tests/`, dotfiles and `zz-*`, commit. Report what was verified and what only the live sites can show.
