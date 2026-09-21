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

## The Newsletter Builder (4.26.0)

The Builder adopted it a release later, as wiring rather than a rewrite.
`openImagePicker()` was its single entry point, so the new picker opens
there when the look is on and `wp.media` still opens when it is off.

The five steps that follow a choice -- set the id, reset the crop if the
picture changed, refresh the chip, refresh the focal-point picker,
re-serialize the preview -- were pulled out into `applyChosenImage()` and
are now shared by both choosers, so neither can drift from the other. The
whole-photo / crop toggle and the focal point work exactly as before.

`wp.media` is still loaded on that screen: `fetchAttachment()` uses it to
read a photo's source for the chip and the focal picker. Replacing that is
a later job and buys little.

## Framing, inside the picker (4.27.0)

A photo chosen for an ENTRY used to be cut from the middle wherever it
appeared, with no way to say otherwise — search cards put it in a 140px box
with `object-fit: cover` and no `object-position`. The newsletter had had a
focal picker for months; entries had nothing.

Framing now lives in the picker, opt-in per caller:
`open({ frame: true, aspect: '16:9' })`. Create Entry asks for it; the
Newsletter Builder does not, because it already frames under its own field
and two surfaces on one screen would be worse than none. Both use the same
engine (`ptkInitFocalPicker`), so there is one implementation of the
behavior and two hosts.

The step after choosing shows the photo with the focal dot, **Whole photo /
Crop to fit** (the Builder's own words, reused so the screens agree), then
"What's in this picture?", then "Use this picture". Zoom is drag, pinch,
wheel or keys — never a slider.

`onChoose` gains `focalX`, `focalY`, `zoom`, `fit`. Create Entry saves them
as `ptk_image_focal_x` / `_y`, `ptk_image_zoom`, `ptk_image_fit`, sanitized
through `PTK_Focal_Point`.

### The half that makes it real

`PTK_Search_Engine::format_result()` returns a `thumbnailStyle`, and
`assets/js/search.js` applies it to the card. **An entry with no framing
data gets center / cover / no zoom — byte-for-byte what it always did.**
Verified on a rendered page, not in theory: a framed entry's card computes
`object-position: 8% 23%` while an unframed one next to it computes
`50% 50%`.

Search cards are the only place a `pta_knowledge` featured image is shown
cropped. The single entry page, the glossary and related entries show no
featured image at all. Note the **Best Answer** card shows no picture
either, so a framed entry that comes back as the single best match shows no
thumbnail — pre-existing, and worth revisiting if pictures should appear
there.

### A trap worth remembering

Only the screen that HAS the framing step may write those four meta values.
The older edit form posts a picture but none of them, so writing defaults on
its behalf silently un-cropped a picture somebody had framed and moved its
focal point back to the middle — a change they never asked for and would
only find by looking at the site. `handle_submission()` now writes the
framing meta only when the framing fields were actually submitted, and an
unrecognized `fit` falls back to `crop`, which is what entry pictures have
always done. Verified by saving a framed entry through the old form and
confirming it still renders at `8% 23%`.
