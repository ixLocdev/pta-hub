"use client";

/**
 * Say which part of a photo must survive the crop: drag a dot onto it.
 *
 * Every place a photo is shown to associates crops it to a frame the photo was never cut
 * for — a card, a banner, a pane. Left alone the crop takes the middle, which is where the
 * subject usually isn't. This is the one control that fixes that, and it is deliberately
 * literal: the whole photo, and a dot on the thing that matters.
 *
 * The photo is drawn at its OWN aspect ratio, not letterboxed inside a fixed frame, so the
 * drag surface and the picture are the same box. That keeps the maths a straight
 * percentage of the rect — a letterboxed photo would make the bars draggable and report a
 * point that isn't on the picture. The maths itself lives in `src/lib/focal-point.ts`
 * (jsdom has no layout, so the component measures and hands off).
 *
 * It is capped at MAX_PHOTO_HEIGHT rather than drawn at whatever size the column allows.
 * Left uncapped a tall photo — a boot, a full-length look — filled the whole column and
 * pushed the rest of the form off the screen; the deck-cover panel, which happens to sit in
 * a half-width grid column, always read better. The cap shrinks the box without letterboxing
 * it: the image keeps its aspect and the surface shrink-wraps the image, so surface ==
 * image still holds and the rect maths stays exact at any size.
 *
 * Keyboard-reachable on purpose: a drag-only control is unusable without a mouse, and this
 * is the kind of adjustment people want in single steps rather than by hand.
 */
import { useEffect, useRef, useState } from "react";
import {
  FOCAL_ZOOM_MAX,
  FOCAL_ZOOM_MIN,
  focalZoom,
  pointerToFocal,
  type ImageFocalPoint,
} from "@/lib/focal-point";

const CENTER: ImageFocalPoint = { x: 50, y: 50 };

/** Arrow keys step; Shift covers ground for a photo that needs a big move. */
const NUDGE = 2;
const NUDGE_FAST = 10;

/**
 * How tall the photo is ever drawn — 20rem, near enough the height a cover reaches in the
 * deck-cover panel's half-width column, which is the scale this was asked to match. Kept
 * here as a class rather than left to each caller so the hero, the story looks, News and
 * the cover all come out the same size.
 */
const MAX_PHOTO_HEIGHT = "max-h-80";

const NUDGES: Record<string, [number, number]> = {
  ArrowLeft: [-1, 0],
  ArrowRight: [1, 0],
  ArrowUp: [0, -1],
  ArrowDown: [0, 1],
};

function clampPercent(value: number): number {
  return Math.min(100, Math.max(0, Math.round(value)));
}

export function FocalPointPicker({
  src,
  value,
  onChange,
  onReset,
  onImageError,
  label = "Framing",
  hint = "Drag the dot onto what has to stay in view.",
}: Readonly<{
  src: string;
  /** Absent = centred, which is exactly how the photo renders today. */
  value?: ImageFocalPoint | null;
  onChange: (focal: ImageFocalPoint) => void;
  /**
   * Recentre. Optional: a caller that only holds a focal point can leave it off and get
   * `onChange({ x: 50, y: 50 })`. A caller that has to tell "centred" apart from "cleared"
   * — as the admin override merge does — passes this and clears its own field.
   */
  onReset?: () => void;
  onImageError?: () => void;
  label?: string;
  hint?: string;
}>) {
  const [dragging, setDragging] = useState(false);
  const focal = value ?? CENTER;
  const zoom = focalZoom(focal);
  // Live pointers on the photo, so two of them can be told apart from one. A pinch is the
  // only gesture that needs history; a drag is answered from the event alone.
  const surfaceRef = useRef<HTMLDivElement | null>(null);
  const pointers = useRef(new Map<number, { x: number; y: number }>());
  const pinchStart = useRef<{ distance: number; zoom: number } | null>(null);

  /** Apply a zoom, dropping it entirely at the bottom of the range. */
  const setZoom = (next: number) => {
    const clamped = Math.min(FOCAL_ZOOM_MAX, Math.max(FOCAL_ZOOM_MIN, next));
    if (clamped <= FOCAL_ZOOM_MIN) {
      // Dropped, not stored as 100 — an author who zooms in and back out leaves the photo
      // emitting exactly what it emitted before they touched it.
      const { zoom: _drop, ...rest } = focal;
      onChange({ ...rest });
      return;
    }
    onChange({ ...focal, zoom: Math.round(clamped) });
  };

  /**
   * Wheel-to-zoom, attached by hand rather than with `onWheel`.
   *
   * React registers wheel listeners passively, so `preventDefault` inside a JSX handler
   * does nothing — the photo would zoom AND the form would scroll out from under it. A
   * native listener with `passive: false` is the only way to hold the page still.
   *
   * A trackpad pinch arrives here too, as a wheel event with `ctrlKey` set, so the same
   * handler serves the gesture on a laptop and the wheel on a mouse.
   */
  useEffect(() => {
    const surface = surfaceRef.current;
    if (!surface) return;
    const onWheel = (event: WheelEvent) => {
      event.preventDefault();
      setZoomRef.current(focalZoom(valueRef.current ?? CENTER) - event.deltaY * 0.25);
    };
    surface.addEventListener("wheel", onWheel, { passive: false });
    return () => surface.removeEventListener("wheel", onWheel);
  }, []);

  // The wheel listener is attached once; these keep it looking at today's values rather
  // than the ones that existed when it was attached.
  const setZoomRef = useRef(setZoom);
  setZoomRef.current = setZoom;
  const valueRef = useRef(value);
  valueRef.current = value;

  const pinchDistance = (): number | null => {
    const live = [...pointers.current.values()];
    if (live.length < 2) return null;
    return Math.hypot(live[0].x - live[1].x, live[0].y - live[1].y);
  };

  // Zoom rides along on every move. `pointerToFocal` answers with x and y only, so
  // without this a drag would silently reset how close the author had zoomed in.
  const report = (surface: Element, clientX: number, clientY: number) => {
    const next = pointerToFocal(surface.getBoundingClientRect(), clientX, clientY);
    onChange(zoom > FOCAL_ZOOM_MIN ? { ...next, zoom } : next);
  };

  const handlePointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    // Stops the browser starting its own image-drag, which would swallow the move events.
    e.preventDefault();
    const surface = e.currentTarget;
    // Not every environment has the capture API (jsdom doesn't); the drag still works
    // without it, it just stops tracking once the pointer leaves the photo.
    if (typeof surface.setPointerCapture === "function") {
      try {
        surface.setPointerCapture(e.pointerId);
      } catch {
        /* capture is a nicety, never a requirement */
      }
    }
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY });
    const distance = pinchDistance();
    if (distance) {
      // Second finger down: this is a pinch, not a drag. Whatever the first finger had
      // started is abandoned so the photo does not lurch to the midpoint.
      pinchStart.current = { distance, zoom };
      setDragging(false);
      return;
    }
    setDragging(true);
    report(surface, e.clientX, e.clientY);
  };

  const handlePointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    if (pointers.current.has(e.pointerId)) {
      pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY });
    }
    const start = pinchStart.current;
    if (start) {
      const distance = pinchDistance();
      // Ratio of the finger spread, applied to the zoom the pinch began at — so letting
      // go and pinching again continues from where it stopped rather than jumping.
      if (distance) setZoom((start.zoom * distance) / start.distance);
      return;
    }
    if (!dragging) return;
    report(e.currentTarget, e.clientX, e.clientY);
  };

  const handlePointerUp = (e: React.PointerEvent<HTMLDivElement>) => {
    pointers.current.delete(e.pointerId);
    if (pointers.current.size < 2) pinchStart.current = null;
    if (!dragging) return;
    const surface = e.currentTarget;
    if (typeof surface.releasePointerCapture === "function") {
      try {
        surface.releasePointerCapture(e.pointerId);
      } catch {
        /* see above */
      }
    }
    setDragging(false);
    report(surface, e.clientX, e.clientY);
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLButtonElement>) => {
    // Zoom has to be reachable without a pointer at all: the dot is focusable on purpose,
    // and a gesture-only control would put this out of reach of a keyboard entirely.
    if (e.key === "+" || e.key === "=") {
      e.preventDefault();
      setZoom(zoom + (e.shiftKey ? 25 : 5));
      return;
    }
    if (e.key === "-" || e.key === "_") {
      e.preventDefault();
      setZoom(zoom - (e.shiftKey ? 25 : 5));
      return;
    }
    const direction = NUDGES[e.key];
    if (!direction) return;
    e.preventDefault();
    const step = e.shiftKey ? NUDGE_FAST : NUDGE;
    onChange({
      x: clampPercent(focal.x + direction[0] * step),
      y: clampPercent(focal.y + direction[1] * step),
      ...(zoom > FOCAL_ZOOM_MIN ? { zoom } : {}),
    });
  };

  return (
    <div>
      <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-black">
        {label}
      </p>

      <div
        ref={surfaceRef}
        data-testid="focal-surface"
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        onPointerCancel={(e) => {
          pointers.current.delete(e.pointerId);
          if (pointers.current.size < 2) pinchStart.current = null;
          setDragging(false);
        }}
        // `w-fit` so the box is the picture and nothing else — with a height cap in play a
        // full-width surface would leave bars beside a tall photo, and those bars would be
        // draggable. `max-w-full` keeps a wide photo inside its column.
        className={`relative mx-auto w-fit max-w-full touch-none select-none overflow-hidden rounded-lg border border-stone-200 bg-stone-100 ${
          dragging ? "cursor-grabbing" : "cursor-crosshair"
        }`}
      >
        {/*
          Zoomed, the picture is scaled and pulled back inside the same box — the surface
          keeps its size and the photo grows within it, which is what a card will do. The
          box already clips (`overflow-hidden`) and is already a positioning context, so
          nothing else has to change to hold a zoomed photo.
        */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={src}
          alt=""
          draggable={false}
          onError={onImageError}
          style={
            zoom > FOCAL_ZOOM_MIN
              ? { transform: `scale(${zoom / 100})`, transformOrigin: `${focal.x}% ${focal.y}%` }
              : undefined
          }
          className={`pointer-events-none block h-auto w-auto max-w-full ${MAX_PHOTO_HEIGHT}`}
          loading="lazy"
          decoding="async"
        />
        <button
          type="button"
          data-testid="focal-marker"
          tabIndex={0}
          aria-label={`Focal point, ${focal.x}% across and ${focal.y}% down${
            zoom > FOCAL_ZOOM_MIN ? `, zoomed to ${Math.round(zoom)} percent` : ""
          }. Drag it, or nudge it with the arrow keys. Plus and minus zoom.`}
          onKeyDown={handleKeyDown}
          style={{ left: `${focal.x}%`, top: `${focal.y}%` }}
          className="absolute h-7 w-7 -translate-x-1/2 -translate-y-1/2 cursor-grab rounded-full border-2 border-white bg-black/25 shadow-[0_0_0_1px_rgba(0,0,0,0.4)] focus:outline-none focus-visible:ring-2 focus-visible:ring-black/40 active:cursor-grabbing"
        >
          <span className="pointer-events-none absolute left-1/2 top-1/2 h-1.5 w-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white" />
        </button>
      </div>

      {/*
        How close, beside where — and said with the same gesture as everything else.
        Lucas, 2026-09-08: "the focus point drag is a really easy tool... rather than a bar?
        I want it to feel effortless." So there is no slider: pinch the photo on an iPad,
        or roll the wheel over it on a desktop, and it zooms around the dot. The readout
        below only reports; it is not a control.
      */}
      <div className="mt-1 flex items-center gap-3">
        <p className="min-w-0 flex-1 text-[10px] leading-snug text-stone-400">
          {hint} Pinch or scroll to zoom. Arrow keys nudge it; hold Shift to move further,
          plus and minus to zoom.
        </p>
        {/*
          Always present, never conditional. Rendering this only while zoomed made the row
          re-flow the moment you touched the wheel — the hint beside it re-wrapped and
          Reset moved — so zooming appeared to shove the interface around (Lucas,
          2026-09-08). It holds its width whether it has a number in it or not.
        */}
        <span
          data-testid="focal-zoom-readout"
          aria-hidden={zoom <= FOCAL_ZOOM_MIN}
          className="w-9 shrink-0 text-right text-[11px] tabular-nums text-stone-500"
        >
          {zoom > FOCAL_ZOOM_MIN ? `${Math.round(zoom)}%` : ""}
        </span>
        <button
          type="button"
          onClick={() => (onReset ? onReset() : onChange({ ...CENTER }))}
          className="min-h-[44px] shrink-0 px-1 text-[11px] font-semibold text-stone-500 underline decoration-dotted underline-offset-2 hover:text-stone-700"
        >
          Reset to center
        </button>
      </div>
    </div>
  );
}
