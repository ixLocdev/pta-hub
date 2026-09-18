# PTA Hub Interface — Phase 4: the entry wizard

> Implement task-by-task. Each task ends with the suite silent and a commit.

**Goal:** "Answer a question families keep asking" and "Explain a PTA word" lead into a form that sounds like the rest of the Hub: plain questions, one part at a time, and a human confirmation with a real next step.

**Architecture:** `PTK_Content_Wizard` (1,840 lines, four steps: category → basics → the category's own fields → publish) keeps its flow, its validation, its autosave and its category logic. As in phase 3, only three things change, and all of them only when `PTK_Hub_Look::on()`: the words, the look, and what it says at the end. A pure copy map (`class-wizard-copy.php`, the sibling of `class-builder-copy.php`) holds every new string with today's string as the fallback.

**Spec:** `docs/superpowers/specs/2026-09-17-pta-hub-interface-design.md` §2, §6.
**Read first:** `includes/class-content-wizard.php`, `assets/css/*wizard*`, `assets/js/*wizard*`, `includes/class-builder-copy.php` (follow its shape), `includes/class-hub-ui.php`.

**Non-negotiables:**
- `main` carries 8 of Lucas's own uncommitted one-line path fixes — never commit, revert or stash them; `git add` only the task's paths.
- **With the new look off, the wizard must be byte-for-byte what it is today.** Capture a baseline hash of the form's HTML in your own session before starting, re-check after every task, and also grep the look-off HTML for every new-look-only marker string (that check is the one that really proves it).
- No behavior changes: same validation, same autosave, same categories, same published output.
- Plain English, US spelling, no `__()` wrappers, never one-sided borders.
- Tests silent before each commit; Playground `pta-hub-main` on 127.0.0.1:9406; delete `zz-*` before committing.
- Commit messages end with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

---

## Task 1 — The words

`includes/class-wizard-copy.php`, pure, no WordPress calls, same shape as `PTK_Builder_Copy`.

| Today | New look |
|---|---|
| "4 quick steps · takes about 5 minutes" | "Four short questions. About five minutes." |
| Category step heading | What kind of thing is this? |
| Title * | What's the question families ask? |
| Short Summary | If someone reads one line, what should it say? |
| Tags | What words might someone search for? |
| Featured Image | Would a picture help? |
| Introduction | How would you explain it in a sentence? |
| Difficulty / Time Estimate | How hard is it? / How long does it take? |
| What You'll Need | Does anyone need anything first? |
| Steps * | What are the steps? |
| Resource: file/URL | Where is the file or page? |
| How to use | How should families use it? |
| Publish / Save as draft | Put it on the Hub / Keep it to myself for now |

Rules the tests assert: every new-look label is a question or a plain instruction; none contains a word
from the spec's banned list (Add New, Edit, Publish, Status, Excerpt, Featured Image, Taxonomy, …); the
fallback map matches today's strings exactly; every key in the new map exists in the fallback map and
vice versa.

Placeholders stay as examples ("e.g., How to Set Up for a Bake Sale") — they are already the plain-English
examples the spec asks for; only the labels above them change.

---

## Task 2 — One part at a time, in the Hub's look

- The four steps render through `PTK_Hub_UI` cards; the current step is open, earlier ones fold shut
  with a one-line summary ("Category: How-to guide", "Title: How to set up a bake sale").
- Tokens applied to fields, buttons, help text, the category cards and the required-field legend.
- The step counter reads "Question 2 of 4", not "Step 2".
- At 782px and below: one column, 44px tap targets, no horizontal scrolling.
- Mechanical checks: no `border-left`/`border-right` added, no raw hex outside tokens.

---

## Task 3 — What it says at the end

- "Entry Created Successfully!" → **"You're all set. Families can find this on the Hub."** with a
  **SENT** stamp and the link.
- "Entry Updated Successfully!" → **"Updated. Families see the new version now."**
- Saved as draft → **"Saved. Nobody sees it yet."** with a **NOT SENT YET** stamp.
- A next-steps row: *See it on the Hub · Answer another question · Tell families about it (the newsletter)
  · I'm done* — each pointing at something that exists today.
- Validation messages keep their current behavior but read as sentences ("This needs a title so families
  can find it.").

---

## Task 4 — Release 4.15.0

Version in both places, plain-English changelog leading with "nothing changes until a site turns the new
look on", zip rebuilt from the repo root excluding `tests/`, dotfiles and `zz-*`, commit, and a report
of what was verified in both states.
