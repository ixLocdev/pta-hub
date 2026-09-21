# "Words you've explained" — design (2026-09-21, shipped 4.22.0)

The third of the four remaining Hub screens, and the only one that replaced
nothing: there was no glossary admin screen at all. Glossary terms are
ordinary `pta_knowledge` entries carrying the `glossary` category, written
through Create Entry's "Explain a word or phrase" path and then scattered
among everything else on "What you've written".

Decided with Lucas 2026-09-21: give them a home, rather than skip it or
build a full glossary manager that would duplicate Create Entry.

## The screen

Title "Words you've explained", lead "Every PTA word or acronym you've
explained, in plain English — the same words families read in the
glossary." No stamp: nothing here is waiting on anybody, and the rule is
at most one per screen, not one for its own sake.

A card per term, alphabetical: the word, then what it means (the entry's
own summary, falling back to the first words of the answer, the same rule
`PTK_Glossary_Page` uses so the two never disagree). One button to explain
another word, a search box that filters as you type, and per card "Change
the wording" and "See it in the glossary". A term not yet published says
"Not sent yet." and has no glossary link, because there is nothing to see.

Empty: "Nothing explained yet." No match: "Nothing matched …" with an offer
to explain that word now — a search that finds nothing is itself a good
reason to write one.

## Reuse, not repetition

No new CSS. The screen is built from the cards, search and empty states
"What you've written" already has, and it reuses that screen's search
JavaScript as-is by matching its ids and `data-ptk-*` attributes rather
than shipping a second copy. Registered behind the same gate as the other
migrated screens, slug `ptk-words`.
