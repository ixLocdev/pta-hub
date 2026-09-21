# "Which picture?" — design (2026-09-21, shipped 4.25.0)

Adding a picture was the last stock WordPress screen a volunteer met in the
middle of an ordinary task. Clicking "+ a picture" opened the media library:
Upload files / Media Library tabs, "Filter by date", a grid mixing real
photos with machine names like `image-1789314471203` and every newsletter
share square ever generated, and a side panel of Alt Text, Title, Caption,
Description, File URL and Edit Image.

## Our own modal, not a restyled wp.media

Decided deliberately: the profile screen had already shown what happens when
we fight core markup — the parts with no hooks win. This is our own modal
fed by our own two AJAX actions, which also means the Newsletter Builder can
use it later through a small public API rather than a rewrite:
`window.ptkPicturePicker.open({ onChoose })`, giving back `{ id, url, alt }`.

## One question, two ways to answer

**Which picture?**

1. **Use a picture from this device** — a large drop area and a real file
   input, which on a phone opens the camera roll.
2. **Pictures you've used before** — large thumbnails, newest first, with a
   plain "Find a picture" box. No filenames, no dates, no sizes, no
   checkboxes. Search earns its place here: a real site has hundreds.

Then one field and nothing else: **What's in this picture?**, with the line
"This is read aloud to families who can't see the picture." It pre-fills
with whatever the picture already says. That is alt text, asked in words
that explain why it matters — the only piece of metadata worth a volunteer's
attention.

## Share squares are filtered out

They are generated images nobody re-uses and they were most of the clutter.
The rule is exact, not a guess: `PTK_Share_Image::attachment_filename()`
produces `share-square-{post}[-{issue}]-{hash}.png`, so the filter matches
that basename prefix, plus PNGs titled "Share square for issue …" as a
second line of defense. It lives in a pure tested function, including a case
proving `share-squares-are-great.png` still passes — a prefix match must not
overreach.

## The two AJAX actions

Both carry a nonce and a capability check. Listing needs `edit_posts` and is
read-only. Saving needs `edit_posts`, plus `upload_files` when a real file is
posted; the file goes through `media_handle_upload()` (which sniffs the real
bytes rather than trusting the extension), and anything that comes back not
an `image/*` is deleted again. A client-supplied attachment id is confirmed
to be an image attachment before anything touches it.

**Alt text is only rewritten when the person can edit that picture**
(`edit_post` on the attachment). Choosing a picture is not the same as being
allowed to rewrite what it says — a contributor may use any picture on the
site but cannot change one someone else wrote. WordPress's own media screen
draws the line in the same place.

## Left for the next pass

The Newsletter Builder's photo fields still use the old picker. They have
their own focal-point and crop flow, and disturbing that in the same change
would have risked a working feature for an unrelated win.
