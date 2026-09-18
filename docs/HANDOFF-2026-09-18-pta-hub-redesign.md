# PTA Hub redesign — handoff (2026-09-18)

Everything needed to continue the PTA Hub interface work in a new chat. Start here.

---

## 1. Where things stand

**Plugin:** `/Users/lucas/apps/PTA/PTA HUB` (git, branch `main`, pushed to GitHub `ixLocdev/pta-hub`).
Plugin dir `pta-knowledge-hub/`. **Version 4.18.3.** Zip built at the repo root:
`/Users/lucas/apps/PTA/PTA HUB/pta-knowledge-hub.zip` — Lucas uploads it himself (Network Admin →
Plugins → Add Plugin → Upload → "Replace current with uploaded"; one upload = all 11 school sites).

`main` carries **8 of Lucas's own uncommitted one-line path fixes** (`AGENTS.md`, 5 plan docs,
`newsletter-relabel.js`). Never commit, revert or stash them; `git add` only your own paths.

**Everything in this redesign is behind one per-site setting, `ptk_hub_new_look`, which SHIPS OFF.**
With it off every admin screen renders exactly as it did before — that property is verified by hash +
marker grep before every release, and it is the single most important thing not to break. Lucas has it
ON for northeastpta.org only.

---

## 2. The documents that matter

| What | Where |
|---|---|
| The design standard for every Hub screen | `docs/superpowers/specs/2026-09-17-pta-hub-interface-design.md` |
| Create Entry (write the answer itself) | `docs/superpowers/specs/2026-09-18-entry-write-the-answer-design.md` |
| What you've written (the entry list) | `docs/superpowers/specs/2026-09-18-what-youve-written-design.md` |
| Phase plans 1–4 + entry flow | `docs/superpowers/plans/2026-09-1*-*.md` |
| Reusable, product-agnostic conventions | `/Users/lucas/Downloads/Assistant-Voice Interface Conventions.md` |
| Lucas's source concept | `/Users/lucas/Downloads/PTA_Zero_Training_Interface_Concept.docx` |
| Newsletter work (separate project, same plugin) | `/Users/lucas/apps/PTA/NEWSLETTER-BUILDER-HANDOFF.md` |

---

## 3. What is built (4.12.0 → 4.18.3)

- **The switch + shared look** (`class-hub-look.php`, `class-hub-ui.php`, `assets/css/hub.css`):
  tokens, Literata + Karla bundled as variable woff2, cards, stamps, the no-widow rule.
- **Home screen** — "What would you like to do?" and six intentions: tell families what's happening ·
  answer a question families keep asking · recommend someone we've used · explain a word or phrase ·
  fix something that's wrong · I'm not sure where to start. One WAITING FOR YOU stamp, two quiet links.
- **Simple mode** (`class-simple-mode.php`) — menu trimmed to PTA Hub + Newsletter settings + Profile,
  admin bar trimmed, login lands on the Hub, other plugins' notices cleared on Hub screens, per-role
  defaults, and "Show all of WordPress" always one click away. Permissions never change.
- **Newsletter Builder** — question-style labels (`class-builder-copy.php`), one section open at a time
  inside a step, human confirmations with SENT / NOT SENT YET stamps and a next-steps row, phone layout.
- **Create Entry** — one card that looks like the answer families will read: the question, the answer,
  four chips (+ steps · + a form or file · + a date · + a picture), a quiet "We'll file this as a …
  Change that" line (`class-entry-type.php` guesses; the eight types stay hidden until asked for).
- **What you've written** (`class-written-list.php`) — search as you type, filters, a card per entry,
  Change the details / See it on the Hub, Remove with a real Undo. Council entries are read-only and say
  so. In Simple mode this replaces "All Entries" in the menu; WordPress's own list is still reachable.

---

## 4. What's left

1. **"I'm not sure where to start"** — today it opens plain cues. It should take a sentence
   ("I need parents to volunteer for the book fair") and route to the right screen, prefilled.
2. **The remaining Hub screens:** vendors, glossary, suggestions, search analytics — same treatment,
   added to `PTK_Hub_Look::PAGES` only when each one is migrated.
3. **Flip the default:** when Lucas is happy, a release turns `ptk_hub_new_look` on by default; sites
   that explicitly turned it off stay off.
4. **Milestone A — newsletter themes** (Editorial + a Fun one, same settings, school picks a look).
5. **Milestone C — Google Workspace in the Hub** (Drive files on entries and in Hub search; permissions
   need care). Both need a brainstorm with Lucas first.

Known, deliberately left: restoring a removed entry brings it back as a draft (WordPress's own untrash
behavior); editing an existing entry still uses the full old wizard form.

---

## 5. How to work on this (Lucas's standing preferences)

- **Models:** Opus orchestrates (the main session or an Opus agent); implementers are **sonnet**, haiku
  for mechanical work. **No Fable.** Small edits: do them yourself.
- Every implementer prompt opens with: "Do this work yourself. Do NOT use the Agent tool or spawn any
  subagent; your final message must be the report."
- **Verify by clicking and typing like a person, never POST-only.** A phase once shipped a screen whose
  fields were collapsed to an 81px strip because the implementer only posted data to it.
- **Look-off proof before every release:** hash the screen's HTML with the new look off, and grep it for
  every new marker string. PHP's alternate `if/endif` indentation leaks into output — prefer separate
  methods over inline branching.
- **NEVER one-sided borders.** Plain English, US spelling, no `__()` wrappers (the test harness stubs
  none). One stamp per screen.
- Tests, silent before every commit:
  `cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done && for f in tests/*.mjs; do node "$f" >/dev/null || echo FAIL $f; done`
- **Playground:** `preview_start` name `pta-hub-main` (port 9406, mounts the real plugin dir), always
  `http://127.0.0.1:9406`, never `localhost`. Log in with a throwaway `zz-login.php`
  (`require '/wordpress/wp-load.php'; wp_set_auth_cookie(1,true); update_option('ptk_hub_new_look','1');`)
  and **delete every `zz-*` file before committing**.
- Build the zip from the repo root:
  `zip -rq pta-knowledge-hub.zip pta-knowledge-hub -x "pta-knowledge-hub/tests/*" -x "*/.DS_Store" -x "*/.*" -x "*/zz-*"`
  and check nothing from `docs/`, `tests/` or `zz-*` is inside.
- Hand files over by revealing them in Finder (`reveal_path`) plus a markdown link; `file://` links do
  nothing for Lucas.
- Ask before anything outward-facing. Uploads are Lucas's call; he tests on northeastpta.org and reports
  back, usually with a screenshot.

---

## 6. Traps already paid for

- A `require_once` for a file that a later task creates fatals every page on all 11 sites.
- WordPress reports `post_type = pta_knowledge` for every submenu page under the PTA Hub menu — the
  "is this a Hub screen" rule must also check the core screen id, or unmigrated screens get half-styled.
- An admin's Profile is nested under Users, so trimming to "Hub + Profile" needs a Profile item added.
- A disabled submit button sends no value: disabling on submit made "Put it on the Hub" save drafts.
- `content: " · "` collapses its spaces; use a margin for link separators.
- `text-wrap: pretty` isn't reliable; tie the last two words with `&nbsp;` (`PTK_Hub_UI::no_widow()`).
- Old wizard CSS reserves 300px for a sidebar and uses an ID selector — a class rule can't beat it.
