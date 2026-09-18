# Create Entry, question first

- **Date:** 2026-09-17
- **Status:** Agreed with Lucas; supersedes phase 4's treatment of this screen
- **Applies to:** `PTK_Content_Wizard` when `PTK_Hub_Look::on()`

---

## 1. The problem

A volunteer clicks "Answer a question families keep asking" and the very next screen asks them to
classify their knowledge into eight kinds: How-to guide, FAQ, Glossary term, Checklist, Event playbook,
Policy / rules, Resource, Custom. That is the system's filing cabinet, not the person's intent — the
exact pattern Lucas's concept doc rejects. No assistant opens with "which of our eight content types is
this?"

Phase 4 made the words friendlier but left that order intact, which is why the screen still feels wrong.

## 2. The shape

**One page that grows.** The volunteer always starts with their own words; everything else appears
underneath as it becomes relevant, so someone writing their fifth entry sees the whole form at once and
a first-timer is never shown a field they don't need yet.

1. **"What's the question families keep asking?"** — one field, focused, nothing else competing.
   (Entering from "Explain a PTA word" it reads **"Which word should we explain?"**.)
2. **"What's the answer?"** — appears once there's a question. A plain box; no formatting to learn.
3. **Follow-ups, at most two,** chosen from what they wrote:
   - *Are there steps to follow?* → a steps list appears (how-to guide)
   - *Is there a form, file or page families need?* → a link/file field appears (resource)
   - *Does it happen on a date?* → a date field appears (event playbook)
   Answering "no" to all is fine; that's a plain answer (FAQ) or a definition (glossary term).
4. **The quiet type line**, after they've written something:
   > We'll file this as a **how-to guide**, so families see the steps as a numbered list. *Change that*
   "Change that" opens the eight, each with the one-line description the code already has.
5. **The extras, folded away** under "Anything else?": a picture, words people might search for, who it's
   for. Never required, never in the way.
6. **Put it on the Hub** / **Keep it to myself for now**, then the phase-3 confirmation and next steps.

## 3. Choosing the type

Pure, ordered, and testable — the first rule that matches wins:

| Evidence | Type |
|---|---|
| The volunteer came from "Explain a PTA word" | glossary |
| They added two or more steps | how-to-guide |
| They added a date and steps | event-playbook |
| They added a file or link and little else | resource |
| The question starts "Can I", "Do I", "Is", "Are", "When", "Where", "How much" | faq |
| The answer is a list of things to tick off ("Bring…", lines starting with a dash) | checklist |
| The question mentions rules, policy, bylaws, allowed, must | policy |
| Nothing above | faq |

Rules: the guess is shown, never hidden; a person's explicit choice always wins and is remembered for
that entry; editing an existing entry keeps its current type and never re-guesses; the eight stay
exactly as they are in the database, so every existing entry and the public Hub are unaffected.

## 4. What must not change

The published output for each type, the categories themselves, autosave, validation, capability checks,
and the old screen when the new look is off (byte-for-byte, proven by hash and marker search).

## 5. How we'll know it worked

A volunteer who has never seen the Hub can answer a question and put it on the Hub without ever meeting
the word "category" — and someone who wants the old control can still reach all eight in one click.
