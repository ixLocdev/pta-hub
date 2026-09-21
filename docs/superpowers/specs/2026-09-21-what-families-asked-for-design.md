# "What families have asked for" — design (2026-09-21, shipped 4.21.0)

The second of the four remaining Hub screens. Topic suggestions from members
had no screen of their own — only WordPress's list for a hidden post type,
with a "Suggester" column and a "Convert to Draft" row action.

## The screen

Title "What families have asked for", lead "A member couldn't find something
on the Hub, so they asked. Answer it, and it's here for the next person
too." One stamp: WAITING FOR YOU, "1 question is waiting for you." /
"3 questions are waiting for you."

A card per question: what they asked, anything else they wrote (in the same
full-fill block the approvals screen uses), and "Asked by Devon Price" — or
"Asked by A member" when they didn't say. The stored IP hash is never shown;
an email appears only as a mailto link, and only when they left one.

**Answer it** (primary) and **Remove it** (quiet). Removing is a trash, so
it gets the "Removed. Undo" banner, not the approvals screen's confirm step
— that one exists only because rejecting a recommendation really deletes.

Registered only when the new look is on (`PTK_Written_List`'s gate), slug
`ptk-asked-for`. WordPress's own list stays reachable from the foot of the
screen.

## Two bugs this screen exposed

1. **Undo didn't undo.** `wp_untrash_post()` restores to `draft` (core
   behavior since 5.6), and a suggestion is only ever visible as `publish`,
   so the card never came back. `handle_untrash()` now puts the status back
   explicitly.
2. **"Answer it" landed on a dead screen.** Converting a suggestion creates
   a `pta_knowledge` draft with **no category**, and the wizard's edit mode
   reveals its fields per category — with none set, every field, including
   the entry's own title, rendered at zero height. The conversion now sets
   the type the Hub would have guessed (`PTK_Entry_Type::guess()`, which a
   question resolves to FAQ). Worth knowing: any entry with no category
   opens that way in the wizard, so the same trap is waiting for other
   entry points.

## Note

Editing an existing entry still uses the full old wizard form (a known
"deliberately left" item since 4.18.0). "Answer it" lands there, prefilled
and with the right section open.
