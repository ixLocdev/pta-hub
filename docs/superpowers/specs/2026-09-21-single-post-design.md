# "Put one thing on the website" — single post design (2026-09-21)

Status: agreed with Lucas 2026-09-21. Not built.
Mockup: `mockups/2026-09-21-single-post-mockup.html`

## Why

Today the only way to tell families something is the weekly newsletter. A PTA
that wants to say one thing — class parents are still needed, the bake sale is
Tuesday — either waits for Sunday or meets WordPress's block editor.

Three Montclair sites show the range this has to serve:

- **Northeast** writes designed single stories: a kicker ("— Class Parents
  2026–2027"), a headline with a rule under it, a deadline callout, numbered
  steps, one button, a sign-off. A newsletter story standing on its own.
- **Bradford** has no singular announcements at all. Every "Recent News" item
  is a whole newsletter pasted into a post, emoji headers and all.
- **Watchung** writes short announcements filed under *School Events* and
  *News*, with the date jammed into the title ("11/4 | ELECTION DAY BAKE
  SALE") and WHEN/WHERE lines in the body.

The feature must fit someone writing three careful sections and someone
writing four lines about a bake sale.

## What it is

**A real WordPress post**, written on a Hub screen. Not a new post type: the
same `post` that already appears under Latest news, with a featured image,
its own url, RSS, search and Facebook previews. We replace the block editor,
not the content type — the same trick Create Entry played on the old entry
form.

Anyone who wants more than the screen offers can still show all of WordPress.
That escape hatch is what lets this screen stay short.

## The decisions, and why

**1. A post can feed the newsletter.** Write it once; it is offered as a story
when the next newsletter is built. The plumbing already exists —
`PTK_Post_Importer` ("Bring in your recent posts") parses posts into Builder
blocks today, guessing dates out of titles and stripping emoji, precisely
because posts are written in raw WordPress. A Hub-written post carries its
parts, so that import becomes a copy rather than a guess.

**2. One story plus chips, not a mini newsletter.** A kicker, a headline and
the words, then the chips Create Entry already taught: **+ a picture**,
**+ steps**, **+ a date**, **+ a button**. That reproduces Northeast's class-parents post
exactly and still lets Watchung write four lines. A multi-story composer would
become a second newsletter, with all the same decisions.

**3. Write the structure, and render it.** The Hub keeps the parts and renders
finished HTML into `post_content` on save, using the newsletter's story
vocabulary, so the post looks the same on every school's theme. Rejected:
plain content (easier, but Bradford's posts stay as plain as they are now, and
we would have made writing easier without making anything look better) and
render-at-view-time (cleanest data, but the post becomes a shell — feed
readers, search excerpts and Facebook see nothing, which is a real cost for
something meant to be shared).

**4. One intention, branching on the card.** "Tell families what's happening"
keeps one card on the home screen, with two ways out of it:

> **Tell families what's happening**
> The weekly newsletter, or a single announcement on the website.
> [ **Just one thing** ] [ This week's newsletter ]

"Just one thing" is the filled button and comes first: one-offs are more
frequent than a weekly. A branch *screen* was rejected — an extra click before
the newsletter is a tax on the most frequent job in the Hub, paid weekly by
someone who already knows what they want. Cards with two buttons already exist
(the approvals screen).

**5. The sign-off is a setting.** A school writes "Thank you, as always, /
Your Northeast PTA" once in Newsletter settings and every post ends with it.
Blank means no sign-off. Nobody retypes it and it cannot drift between writers.

This is a **new school-wide option** on `PTK_Share_Settings`, not the
newsletter's existing per-issue signoff field, which lives inside each
issue's own data and stays as it is.

## The screens

**"Your posts"** — built exactly like "Your newsletters": a card per post,
newest first, the state it is in, *Open it* / *See what families see*, and a
**Write a post** button — with two exceptions set out under "What the list
actually contains" below. One menu item covers listing and writing, so the
trimmed menu goes to eight rather than ten.

### What the list actually contains

`post` is WordPress's ordinary post type, so a school's already full of posts
this screen did not write. Three kinds, handled differently:

| Kind | How to tell | What the card does |
|---|---|---|
| Written here | carries the Hub's parts meta | *Open it* opens the writing screen with the parts loaded. |
| Written in WordPress | no parts meta — every legacy post, Bradford's newsletter-pastes, Watchung's "11/4 \| …" announcements | Listed, and says plainly that it was written in WordPress. Its action is *Open it in WordPress*, never the Hub screen: there are no parts to load, and opening it here would offer to replace real writing with an empty form. |
| Made by the newsletter | carries `_ptk_linked_source_newsletter_id` (see `class-newsletter-linked-post.php`) | **Not listed.** Nobody wrote it; it is a byproduct of publishing a newsletter, it is maintained by that newsletter, and editing it here would be overwritten the next time the newsletter is saved. It is already represented on "Your newsletters". |

Listing foreign posts rather than hiding them is deliberate: a volunteer who
wrote something last year should find it where their posts live, and be told
why it opens somewhere else — the same way "What you've written" lists
Council entries and says they are read-only rather than pretending they do
not exist.

**The writing screen** — one card in the Create Entry shape:

| Part | Notes |
|---|---|
| Kicker | Optional. Only when it tells families something (a date, an issue, "This Thursday"), per the house style rule on labels. |
| Headline | Becomes the post title and the link families click. |
| The words | Paragraphs. The first also seeds the summary. |
| + a picture | The featured image, through the picker, with framing. Sits **below** the headline: a photo above it pushes the news off a phone screen. A full-width-at-the-top option is a later chip, not a redesign. |
| + a date | Draws the one callout — the thing a parent must not miss. |
| + steps | Draws a numbered § section. |
| + a button | The single navy button. Everything else is a text link. |

Buttons: *Put it on the website* / *Keep it to myself for now*, with the same
promise Create Entry makes.

## What gets saved

- `post_title` — the headline
- `post_content` — finished HTML rendered from the parts
- `post_excerpt` — a written summary. This is what Latest news and Facebook
  actually show; today it is whatever WordPress can scrape, which is why some
  rows on Northeast's home page trail off mid-sentence.
- featured image — the chosen picture
- post meta — the parts, plus a hash of the HTML the Hub rendered

## The edges

**Someone edits the post in WordPress afterwards.** The stored parts go stale,
and reopening in the Hub would throw their work away. The hash catches it: the
Hub says so plainly and offers to open it in WordPress instead. Same instinct
as the wizard's "Saving here replaces the whole entry" warning, which exists
because that trap is real. A 4.27.0 bug taught the sharper version of this
lesson — only the screen that owns a field may write it.

**Publishing rights.** Someone whose role cannot publish gets only "Keep it to
myself for now", and the screen says who presses the button — rather than
offering an action that will fail.

**Removing** applies only to posts written here, and is a trash with a real
Undo, like entries and suggestions. No confirm step: nothing is destroyed. A
post written in WordPress gets no Remove button — this screen did not write
it, and offering to bin somebody else's work beside a card we cannot even open
would be a nasty surprise. The newsletter's own posts are not listed at all.

## Deliberately out of scope

- **Pages.** They are Beaver Builder layouts and the Hub cannot safely rewrite
  them. Page Builder stays in the front-end admin bar; that door is a separate
  problem.
- **Categories.** Watchung's *School Events* vs *News* split could be set from
  whether a post has a date. Worth revisiting once posts exist and we can see
  whether it matters.
- **Ageing out.** Watchung's home page still lists last October's events. Real,
  and a different feature.

## Testing

The standard the last nine screens were held to:

- pure tests for the copy and for parts → HTML composition
- a test that the hash guard catches an outside edit
- the look-off proof: with `ptk_hub_new_look` off, nothing anywhere changes
- browser verification that ends at the **published post on the front end**,
  not at a saved record. On the framing work the rendering half was the part
  that could quietly have been theatre; the same risk applies here.
