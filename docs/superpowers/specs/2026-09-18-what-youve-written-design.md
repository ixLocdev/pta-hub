# What you've written (All Entries, redesigned)

- **Date:** 2026-09-18
- **Applies to:** the Hub's entry list when `PTK_Hub_Look::on()`; WordPress's own list is untouched otherwise
- **Answers:** the home screen's "Fix something that's wrong"

---

## 1. Why

Today "All Entries" is WordPress's list table: Category, Tags, Visibility, Feedback, three Rank Math SEO
columns, Owner, bulk actions, and filters reading "Ours (0) | From Council (20) | All (20) | Published
(20) | Pillar Content (0)". A volunteer arrives with one question — *where is the thing I wrote, and how
do I change it?* — and meets a spreadsheet about content management.

## 2. The screen

**Our own page**, not a restyled list table. WordPress's list stays exactly as it is for anyone who wants
it (and for the new look being off); this is a second door, and in Simple mode it's the one in the menu.

Top: **"What you've written"** and one wide search box — *"Type a word you remember."* It filters as you
type, matching the question, the answer and the words people might search for.

Then a short row of quiet filters, only those that apply to this site:
**Everything · Ours · From the Council · Not sent yet**

Then the entries, as cards, newest first:

- The **question**, in the serif, at reading size.
- One line of the **answer**, trimmed at a word.
- A quiet meta line: *Filed as a how-to · updated Sep 14* — plus **NOT SENT YET** (one stamp per card,
  only when it's a draft) and *From the Council* when it isn't this school's own.
- Two plain actions: **Change the details** and **See it on the Hub**.
- Council entries a school can't edit say so instead: *The Council wrote this — ask them to change it.*

**Empty:** "Nothing here yet. **Answer a question families keep asking →**"
**Nothing matched:** "Nothing matched *carnival*. Try a different word, or **answer that question now →**"

At the foot, the next steps row: *Answer another question · Tell families about it · I'm done.*

## 3. Removing something

Under each card's actions, quietly: **Remove it**. It asks in place — *"Remove this? Families won't see
it any more."* — and then says *"Removed. **Undo**"*. It uses WordPress's trash, so Undo is real and the
entry can still be recovered later from the full WordPress view.

## 4. What must not change

WordPress's own list screen, the entries themselves, capability checks (a person only ever sees and edits
what they could before), Council-shared entries' read-only rule, and everything when the new look is off.

## 5. Done looks like

Someone types "carnival", sees the one thing they wrote about it, presses **Change the details**, fixes
the time, and is back on the Hub — without meeting a column header, a bulk action or the word "post".
