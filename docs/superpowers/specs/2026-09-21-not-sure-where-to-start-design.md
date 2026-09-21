# "I'm not sure where to start" — design (2026-09-21, shipped 4.19.0)

The last hole in the home screen. The card used to open onto five fixed
sentences; now it opens onto a box the volunteer writes in, and the Hub
answers.

## Decided with Lucas (2026-09-21)

1. **It stays on the home screen.** The card expands in place — no extra
   page, the six intentions stay in view above it.
2. **When it can't tell, it offers the closest two or three** and never
   forces a guess. When it can't tell at all it shows everything.
3. **Their words follow them** to the screen they land on.

## How it behaves

A plain GET form posts `ptk_start` back to the same screen — no JavaScript,
no AJAX, the back button behaves, and the answer has a real url. The card
renders open when there is an answer to show.

`PTK_Hub_Router` (pure, offline, no WordPress) scores the sentence against a
keyword table: multi-word phrases and unmistakable single words are worth 3,
ordinary hinting words 1. Phrases match anywhere; single words match whole
words only, so "old" never fires inside "household". Three states:

| State | When | The screen says |
|---|---|---|
| confident | top ≥ 3 points AND ≥ 2 clear of the runner-up | "That sounds like:" + one card |
| choices | something matched, nothing ran away with it | "Which of these sounds right?" + up to three |
| none | nothing matched | "I couldn't tell from that. Here's everything you can do:" |

It never redirects on its own. Every state ends in a link the volunteer
clicks, and the five example sentences stay underneath as the way out.
Only intentions actually on that volunteer's home screen are ranked — the
router cannot invent a destination it has no url for.

## What gets carried

- **Answer a question** → `ptk_prefill_title`, the sentence as typed. Create
  Entry already read that argument (search analytics has linked that way
  since 4.0), so nothing had to be taught to it.
- **Explain a word or phrase** → the same argument, but only when the
  volunteer wrote four words or fewer. That field holds "ASE" or "room
  parent"; a whole sentence dropped into it looks like a mistake.
- **Fix something that's wrong** → `s`, the distinctive words only: the
  common words and the words that merely said "this is broken" are dropped.
  More than three words left means no search at all, because a long search
  finds nothing and an empty result is worse than the full list.
- **The newsletter and the vendor directory** open as they always do —
  neither has anywhere sensible to put a loose sentence yet.

## Adding a word later

`PTK_Hub_Router::keywords()` is the whole vocabulary, in the home screen's
order (which also breaks ties, so the same sentence always gives the same
answer). `tests/test-hub-router.php` pins the five cue sentences, seven
sentences a volunteer would really write, and the "no false confidence"
cases; add a row there when adding a word.
