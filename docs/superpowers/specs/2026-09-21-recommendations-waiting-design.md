# "Recommendations waiting for a look" — design (2026-09-21, shipped 4.20.0)

The Council's approval screen, the first of the four remaining Hub screens.
Old screen: two `widefat` tables, columns Vendor / Category / Contact /
Suggested by / Their review / Action, and two buttons per row, Approve and
Reject. Reject deleted a member's writing on one click.

## Decided with Lucas (2026-09-21)

**Turning something down asks first.** Rejecting really does delete the
member's review row — there is nothing to undo, so the screen says so
before it happens rather than pretending an Undo banner could save it.

## The screen

Title "Recommendations waiting for a look", lead "Members at every school
suggested these. Nothing reaches the directory until you say yes." One
stamp: WAITING FOR YOU, with "2 recommendations and 1 review are waiting
for a look."

One card per thing waiting, never a table:

- **A company a member suggested** — its name, the category and who
  suggested it, the contact lines, then what they wrote in a block set off
  by a full fill and a full border (verdict in words, "Price 3 out of 5 ·
  Quality 5 out of 5", the comment, and who wrote it).
- **A review of a company already in the directory** — the same block under
  the company's name, marked "Already in the directory · this is what one
  member wants to add."

Two buttons: **Add them to the directory** / **Show this review** (primary),
and **Turn this down** / **Turn it down** (plain). The words "approve",
"reject", "pending", "queue" and "moderate" appear nowhere on the screen,
and a test asserts it.

Empty: "Nothing is waiting." with a sentence saying what will land here.

## The confirm step

"Turn this down" is only a link to a question — it deletes nothing. It opens
one screen:

> **Turn down Ana Ruiz's review of Party Pros?**
> What they wrote will be deleted. There's no way to get it back.
> [Keep it for now] [Yes, turn it down]

The safe answer is the primary button and comes first. The destructive one
is the existing nonce'd `admin-post.php` action, unchanged — approving and
rejecting still run exactly the code they ran before, so the data behavior
of 4.19.0 is untouched. A confirm link for something no longer waiting (a
stale tab, a made-up id) falls back to the list rather than offering to
delete anything.

## Notes for the next screen

- `PTK_Approvals_Copy` holds every sentence, pure and tested; the screen
  file holds only layout. Copy the split.
- Ratings are words, not star glyphs: a screen reader reads "★★★☆☆" as five
  separate characters.
- `body.ptk-hub-look .ptk-page a` outranks `.ptk-btn-primary` (two elements
  to one), so a button rendered as a link took the page's link color and its
  label vanished into its own fill. Fixed once for every screen with
  `.ptk-page a.ptk-btn*` rules — don't re-fix it locally.
