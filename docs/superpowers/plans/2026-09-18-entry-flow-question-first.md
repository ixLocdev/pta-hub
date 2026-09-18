# Create Entry, question first — implementation plan

> Implement task-by-task. Each task ends with the suite silent and a commit.

**Goal:** A volunteer answers a question in their own words and puts it on the Hub without ever meeting the word "category".

**Spec:** `docs/superpowers/specs/2026-09-17-entry-flow-question-first-design.md`.
**Code:** `includes/class-content-wizard.php` (~1,840 lines), `assets/js/content-wizard.js`, `assets/css/content-wizard.css`, `includes/class-wizard-copy.php`, `includes/class-hub-ui.php`, `includes/class-hub-look.php`. Plugin at 4.15.1 on `main`.

**Architecture:** the question-first flow is a **second rendering path** inside `PTK_Content_Wizard`, used only when `PTK_Hub_Look::on()`. The old path (category picker first) is untouched and still serves everyone with the new look off. Both paths post to the same handler, produce the same entries, and use the same validation — the difference is the order fields are asked in and who picks the type.

**Non-negotiables:**
- `main` carries 8 of Lucas's own uncommitted one-line path fixes — never commit, revert or stash them; `git add` only the task's paths.
- **Look off = byte-for-byte today's screen.** Baseline hash + a grep of the look-off HTML for every new marker string, before you start and after every task.
- Same published output per type, same categories in the database, same autosave, same validation, same capability checks.
- **Every field must be typeable.** Phase 4 shipped a screen that looked right and could not be filled in; after every task, type into every visible field in a real browser and read the values back.
- Plain English, US spelling, no `__()` wrappers, never one-sided borders.
- Tests silent before each commit; Playground `pta-hub-main`, 127.0.0.1:9406; delete `zz-*` before committing.
- Commit messages end with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

---

## Task 1 — The guess (pure, tested first)

`includes/class-entry-type.php`: `PTK_Entry_Type::guess( array $signals )` implementing the spec's
ordered table, plus `explain( $slug )` returning the quiet line ("so families see the steps as a
numbered list").

Signals: `came_from` ('word'|'question'|''), `step_count`, `has_date`, `has_file_or_link`,
`question`, `answer`.

`tests/test-entry-type.php` must cover every row of the table, in order, including: "Explain a PTA word"
beats everything; two steps beat an FAQ-shaped question; a date plus steps gives event-playbook; a
question starting "Can I" with no steps gives faq; "What are the rules for reimbursement?" gives policy;
nothing at all gives faq; an explicit choice is never overridden (the caller's job, but assert the
function is pure and side-effect free).

---

## Task 2 — The page that grows

A new render path in `PTK_Content_Wizard`, reached only when the look is on:

1. `<h1>` = the question from the intention: "What's the question families keep asking?" or, with
   `?ptk_for=word`, "Which word should we explain?". One focused field.
2. "What's the answer?" — revealed once the question has text (JS), always present in the DOM so a
   no-JS post still works.
3. Follow-ups, revealed after the answer has text: *Are there steps to follow?* / *Is there a form, file
   or page families need?* / *Does it happen on a date?* Each is a plain toggle that reveals exactly one
   group of fields (steps repeater, link/file, date). The steps repeater reuses the existing markup and
   its JS; do not rewrite it.
4. "Anything else?" — a folded group holding picture, search words, who it's for.
5. Submit row from phase 3 (unchanged wording, stamps, next steps).

Rules: nothing is `display:none` at first paint that a person needs in order to post without JS; the
reveal is additive. The home screen's two intentions link here with `?ptk_for=question` / `?ptk_for=word`.

**Verify by typing:** fill the question, the answer, add two steps, tick the file question and paste a
link, open "Anything else?" and type search words — then read every value back out of the DOM, and post
it.

---

## Task 3 — The quiet type line

Under the answer, once there is a question and an answer: "We'll file this as a **how-to guide**, so
families see the steps as a numbered list. **Change that**". "Change that" reveals the existing eight
category cards (same markup, same descriptions), and choosing one sets a hidden `ptk_type_locked=1` so
the guess never overrides it again. The line updates live as the follow-ups change the guess. On edit of
an existing entry the current type is shown and locked from the start, never re-guessed.

Tests: `locked` wins over the guess; editing sets locked; the line's text comes from
`PTK_Entry_Type::explain()`.

---

## Task 4 — Release 4.16.0

Version in both places, changelog in plain English (leading with "nothing changes until a site turns the
new look on"), zip rebuilt from the repo root excluding `tests/`, dotfiles and `zz-*`, commit, and a
report of what was typed, what was posted, and what the published entry looked like for two different
types.
