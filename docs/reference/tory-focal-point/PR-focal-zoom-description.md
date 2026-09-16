# A focal point can say how close, not just where

Lucas, 2026-09-08: *"the focus point can move around the picture but not zoom in and out
can we get that functionality as well?"*

## Most of this already existed

He asked the same question on **25 August**, and it was built: a commit that puts zoom in
the same object as the focal point, with the arithmetic **lifted from the standalone Bag
Brief editor the team already uses**, so a brief imported from one of those files is framed
exactly as its author framed it rather than approximately. It was never merged — it has been
sitting on a side branch ever since.

That commit is rescued here unchanged, tests and all. What was missing was the control and
the surfaces.

## The control: the photo itself

There is no slider. **Pinch the photo on an iPad, or roll the wheel over it on a desktop**,
and it zooms around the dot — the same surface and the same gesture vocabulary as the drag
that was already there (Lucas, after trying it as a bar: *"I want it to feel effortless"*).
What sits below the photo is a readout, not a control.

Three things had to be right for it to feel like nothing:

- **A second finger landing abandons the drag** the first had started, so the photo does not
  lurch to the midpoint between the fingers.
- **A pinch scales from the zoom it began at**, not from wherever the last one ended, so
  letting go and pinching again continues rather than jumping.
- **The wheel listener is attached by hand with `passive: false`.** React registers wheel
  handlers passively, so `preventDefault` inside a JSX handler does nothing at all — the
  photo would have zoomed while the form scrolled out from under it.

Plus and minus zoom from the keyboard, with Shift for bigger steps. The dot is focusable on
purpose, and a gesture-only control would have put zoom out of reach of a keyboard entirely.

### The trap this had to avoid

Dragging the dot and nudging it with the arrow keys both answered with `x` and `y` alone. Had
zoom not been carried through them, adjusting the position would have silently reset how far
in an author had zoomed — the kind of thing that is only ever found by someone losing work.

Checked in the browser: **zoom holds at 175 across a drag**, and the scale origin follows the
dot to where it lands (`scale(1.75)`, origin `30% 25%`).

## The surfaces

Five associate surfaces draw a product photo — Browse cards, the best-answer card, the
fundamentals mini and page, the franchise sections — and **all five come through
`SpotlightCardHeroImage`**, so the zoom is applied once there rather than five times over.
The two admin previews apply it as well, so the card preview beside the editor shows what the
card will show.

**It scales about the focal point rather than absolutely positioning a grown image.** Not all
of the containers establish a positioning context, and a transform needs none. The absolute
form (`focalToCropStyle`) stays exactly where it belongs: the one-pagers, where matching the
Bag Brief editor's own saved files byte for byte is the whole point.

### Two boxes that forgot to clip

A zoomed photo is scaled past its own box on purpose, so every container that draws one has
to clip. **Two did not** — the admin card preview and the franchise banner — and the zoomed
photo spilled over the card's own text. Lucas caught it in the preview within minutes of
trying the control. Both now clip; everywhere else already did, which is exactly why the
gap survived until someone zoomed one in.

## Nothing that exists today moves

- An unzoomed picture emits `object-position` and nothing else — byte for byte what every
  surface renders now.
- A zoom of 100 is **dropped rather than stored**, so an author who slides it up and back
  leaves no trace behind.
- A corrupt or out-of-range stored value reads as "not zoomed" instead of throwing.

Four of the new tests exist only to pin that guarantee.

## Gates

- Suite **4,622 pass / 8 skip**.
- `npx tsc --noEmit` **119** — baseline, zero added.
- `npm run build` clean.
- Browser: pinch, wheel and keyboard all driven in the product-knowledge editor; zoom held
  across a drag; the card preview reframing and — at 250% — clipping cleanly instead of
  spilling over the card text.

The two failures on `dev` are the 5.52a glossary tests — a 6.12/5.52a merge interaction fixed
on the open 6.15 branch, unrelated to this.

## Worth knowing

This is the control, not the answer to *"can the Hub automatically centre garment, shoe and
handbag images so products are not cropped?"* — that one is still an open investigation, and
automatic centring means deciding what the subject is. Zoom makes the manual decision far
more capable in the meantime, and may turn out to be enough.
