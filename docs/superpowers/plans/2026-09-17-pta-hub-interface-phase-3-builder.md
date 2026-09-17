# PTA Hub Interface — Phase 3: the Newsletter Builder

> Implement task-by-task. Each task ends with the suite silent and a commit.

**Goal:** The screen volunteers use every week speaks like an assistant and fits on a screen: plain-English questions instead of field labels, one section open at a time, human confirmations, and a real next step at the end.

**Architecture:** No new flow. The five steps, the live preview, validation, the dirty-state guard, the calendar and post panels all stay exactly as they are. What changes is (a) the labels and help text, (b) the blocks inside a step fold, and (c) what the Builder says when something succeeds. Everything renders through `PTK_Hub_UI` and only when `PTK_Hub_Look::on()`; with the new look off, the Builder is byte-for-byte what it is today.

**Spec:** `docs/superpowers/specs/2026-09-17-pta-hub-interface-design.md` §2, §4, §6.
**Read first:** `includes/class-newsletter-builder.php` (note its "the preview column must never carry `data-step`" contract), `assets/js/newsletter-builder.js`, `assets/css/newsletter-builder.css`, `includes/class-hub-ui.php`, `includes/class-simple-mode.php`.

**Non-negotiables:**
- `main` carries 8 of Lucas's own uncommitted one-line path fixes — never commit, revert or stash them; `git add` only the task's paths.
- **With the new look off, the Builder must not change at all** — same markup, same classes, same behavior. Capture a baseline hash before you start and compare after every task.
- Nothing about saving, publishing, validation, the share panel or the preview may change behavior. This phase is words and folding only.
- Plain English, US spelling, no `__()` wrappers, never one-sided borders.
- Tests silent before each commit; Playground = `preview_start` name `pta-hub-main`, 127.0.0.1:9406 only; delete `zz-*` before committing.
- Commit messages end with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

---

## Task 1 — The words

One pure map, `PTK_Newsletter_Builder::labels()` (or a small `class-builder-copy.php`), giving the
new-look label and help for every field, with the current strings as the fallback when the look is off.
The Builder renders from the map instead of hardcoded strings.

Questions, from the spec's §6 vocabulary — the whole point is that a volunteer reads a question, not a
noun:

| Today | New look |
|---|---|
| Headline | What's the big news this week? |
| One-line summary | If families read one line, what should it say? |
| Greeting | How do you want to say hello? |
| Announcement → When | When does it happen, or close? |
| Announcement → Headline | What's the one thing families must not miss? |
| Announcement → Text | What should families know about it? |
| Button words / Button link | What should the button say? / Where should it take them? |
| Coming up → Date / Title / Description | When is it? / What's it called? / One short line about it |
| Top story → Short label / Headline / Story | What kind of story is this? / What's the news? / Tell it in a paragraph or two |
| Image | Would a photo help? |
| Link address / Link wording | Where should families go for more? / What should the link say? |
| Quick notes | Anything else families should know? |
| Footer → Sign-off | How do you want to sign off? |

Step names become plainer too: *The basics · What's happening · Stories · Put it in order · Send it out*.
Tests: every field in the map has a question ending in `?` or a plain instruction, none contains a
banned word from the spec's list, and the fallback map matches today's strings exactly.

---

## Task 2 — One section open at a time

Inside a step, each block (Announcement, Coming up, Quick notes, Top story, Stories, Footer) becomes a
foldable section using `PTK_Hub_UI::section_fold()`:

- Closed by default **unless** it is empty and it is the step's first block, or it has a validation error.
- The closed row shows a one-line summary from its content: "3 dates added", "Ready — ASE registration
  opens Monday", "Nothing yet".
- Opening one closes the others in that step (a single open section, per the spec).
- Which section is open is remembered per newsletter per person (user meta keyed by post id) so coming
  back lands where you left off.
- The live preview column is untouched and never folds.
- Keyboard: the summary row is a real `<button>`; Enter/Space toggles; focus stays on it.

Pure helpers for the summary text (`section_summary( $type, $data )`) get tests — "1 date added" vs
"3 dates added" vs "Nothing yet".

---

## Task 3 — What it says when something works

- Save draft → "Saved. Nobody sees it yet." with a **NOT SENT YET** stamp.
- Publish → "You're all set. Families can read it here." with a **SENT** stamp and the link.
- Update → "Updated. Families see the new version now."
- After publishing, a next-steps row (`PTK_Hub_UI::next_steps()`): *Share it · Copy the email for
  GiveBacks · Start next week's · I'm done*.
- Errors keep the phase-1 rules: the field outlined, a plain sentence under it, and the summary at the
  top of the step.
- The example newsletter shows the **EXAMPLE** stamp in place of the publish controls.

---

## Task 4 — Fit and phone

Apply the phase-1 tokens to the Builder: fields, buttons, help text, step navigation, the panels'
chrome. At 782px and below, the preview moves below the form behind a "Preview" toggle, the step
navigation becomes one line, and tap targets stay at least 44px. Mechanical checks: no `border-left` /
`border-right`, no raw hex outside tokens in anything this phase touches.

---

## Task 5 — Release 4.14.0

Version bump, plain-English changelog leading with "nothing changes until a site turns the new look on",
zip rebuilt from the repo root, commit, and a report saying exactly what was verified in both states.
