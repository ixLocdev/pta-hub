# Create Entry: you write the answer itself

- **Date:** 2026-09-18
- **Status:** Agreed with Lucas (direction 2 + direction 3's confirmation)
- **Replaces:** the question-first *form* shipped in 4.16.0 — same order of questions, completely different screen
- **Applies to:** `PTK_Content_Wizard` when `PTK_Hub_Look::on()`; the old screen is untouched when it's off

---

## 1. Why this exists

4.16.0 asked the right questions in the wrong container: a bare WordPress form with the question asked
twice, raw checkboxes, two grey disclosure pills, "Save as:" radio buttons and a floating empty
"Related entries" box. It was the old wizard wearing new words.

The newsletter works because we designed the finished newsletter first. The finished thing here is
**one answer a parent reads on the Hub**. So the writing screen *is* that answer: you type onto the card
families will see. There is no form.

## 2. The screen

One centered card, about 560px wide, on the Hub's paper background. Above it, "← Back to the Hub".
Nothing else on the page: no sidebar panels, no meta boxes, no step numbers.

Inside the card, in the order a parent reads it:

1. **The question**, set in Literata at the size parents see. Empty, it shows the prompt
   *"Where do I park for drop-off?"* as placeholder text. Coming from "Explain a PTA word" it reads
   *"Which word should we explain?"* and the placeholder is *"ASE"*.
2. **The answer**, in Karla, a text area that grows as you type, placeholder *"Write it the way you'd
   say it out loud."*
3. **Four chips** under the answer, dashed and quiet: **+ steps · + a form or file · + a date ·
   + a picture**. Clicking one adds that block *inside the card*, in the place and style a parent will
   see it (a numbered list, a link row, a date line, an image). Each added block has a small "remove".
4. Nothing else. No type picker, no tags field, no "Save as", no WordPress.

Fields look like text, not boxes: no borders at rest, a dashed underline on hover, a solid
`--ptk-primary` underline and a visible focus ring when focused. They are real `<input>` / `<textarea>`
elements with real labels (visually hidden), never `contenteditable`.

**Under the card:** **Put it on the Hub** (primary) and *Keep it to myself for now* (quiet), with the
reassurance *"Nobody sees it until you do."* When something similar already exists, one quiet line
appears below: *"Families can already read: Where do I park? — open it instead?"*

## 3. After you press the button

The card stays on screen, now read-only, with:

- a **SENT** stamp and *"You're all set. Families can read this on the Hub."* plus the link, or
- a **NOT SENT YET** stamp and *"Saved. Nobody sees it yet."*

Under it, the quiet type line — *"Filed as a how-to, so families see the steps as a numbered list.
Change that"* — and the next steps: *See it on the Hub · Answer another question · Tell families about
it · I'm done.*

The type is guessed exactly as in 4.16.0 (`PTK_Entry_Type::guess()`), never asked before writing, and
"Change that" opens the eight with their existing descriptions.

## 4. What must not change

The eight types in the database, the published output for each, autosave, validation, capability
checks, editing an existing entry (which keeps the full old form — the short card cannot hold the
dozen-plus fields some types carry), and the old screen with the new look off, byte-for-byte.

## 5. Done looks like

A volunteer types two things into what looks like a page, presses one button, and the thing they were
looking at is now live for parents. Nothing on screen names a content type, a status, a taxonomy or
WordPress.
