/**
 * Focal-point math.
 *
 * A focal point says which part of a photo must survive a crop: percentages of the
 * image's own box, straight into CSS `object-position`. The numbers live here rather
 * than in the picker because jsdom has no layout — the component measures a rect and
 * hands it over, and every value it produces is testable without a render.
 */

/**
 * Where the subject sits inside its own image, as percentages (0–100) from top-left, and
 * how much the crop is zoomed in on it.
 *
 * `zoom` is a percentage where 100 is the whole picture. It is optional and absent means
 * 100, so every focal stored before 2026-08-25 stays valid and renders unchanged.
 */
export type ImageFocalPoint = { x: number; y: number; zoom?: number };

/** The part of a DOMRect the math needs; a plain object so tests need no layout. */
type FocalRect = { left: number; top: number; width: number; height: number };

const CENTER: ImageFocalPoint = { x: 50, y: 50 };

/** Whole percent, pinned to the image. A hundred stops is finer than the eye reads. */
function toPercent(value: number): number {
  if (!Number.isFinite(value)) return 50;
  return Math.min(100, Math.max(0, Math.round(value)));
}

/**
 * Turn a pointer position into the focal point of the image box it landed in.
 *
 * Clamped, so dragging past an edge pins the focal there instead of running off the
 * picture; centred on a zero-size box, which is what an unlaid-out picker measures.
 */
export function pointerToFocal(
  rect: FocalRect,
  clientX: number,
  clientY: number
): ImageFocalPoint {
  if (!rect.width || !rect.height) return { ...CENTER };
  return {
    x: toPercent(((clientX - rect.left) / rect.width) * 100),
    y: toPercent(((clientY - rect.top) / rect.height) * 100),
  };
}

/**
 * Format a focal as an `object-position` value; centre when there is none.
 *
 * Stored data is not trusted: out-of-range numbers clamp and a corrupt coordinate
 * falls back to centre, because `NaN%` silently kills the whole declaration.
 */
export function focalToObjectPosition(focal?: ImageFocalPoint | null): string {
  if (!focal) return "50% 50%";
  return `${toPercent(focal.x)}% ${toPercent(focal.y)}%`;
}

/* ------------------------------------------------------------------------ zoom */

/**
 * The zoom slider's range, taken from the standalone Bag Brief editor the team already
 * uses: 100 is the untouched picture, 250 is as far in as it goes.
 */
export const FOCAL_ZOOM_MIN = 100;
export const FOCAL_ZOOM_MAX = 250;

/** A stored zoom, clamped and defaulted. Corrupt values read as "not zoomed". */
export function focalZoom(focal?: ImageFocalPoint | null): number {
  const raw = focal?.zoom;
  if (raw === undefined || raw === null || !Number.isFinite(raw)) return FOCAL_ZOOM_MIN;
  return Math.min(FOCAL_ZOOM_MAX, Math.max(FOCAL_ZOOM_MIN, raw));
}

/** What a zoomed image needs on top of `object-position`. */
export interface FocalCropStyle {
  objectPosition: string;
  position?: "absolute";
  width?: string;
  height?: string;
  left?: string;
  top?: string;
}

/**
 * The style for a cropped photo: where to anchor it, and — when zoomed — how far to grow
 * it and how far to pull it back so the focal point stays where the author put it.
 *
 * **An unzoomed image gets exactly `object-position` and nothing else**, which is what
 * every surface in the Hub already emits. That is deliberate: adding zoom must not move a
 * single existing picture.
 *
 * The arithmetic is the Bag Brief editor's, kept identical so a brief imported from one of
 * those files is framed exactly as its author framed it. The container must clip
 * (`overflow: hidden`) and establish a positioning context, which is already true wherever
 * the Hub crops a photo — a zoomed image overflows its box on purpose.
 */
export function focalToCropStyle(focal?: ImageFocalPoint | null): FocalCropStyle {
  const objectPosition = focalToObjectPosition(focal);
  const zoom = focalZoom(focal);
  if (zoom <= FOCAL_ZOOM_MIN) return { objectPosition };

  const z = zoom / 100;
  const [x, y] = objectPosition.split(" ").map((part) => Number.parseFloat(part));
  const offset = (percent: number) => `${round((1 - z) * percent)}%`;

  return {
    objectPosition,
    position: "absolute",
    width: `${round(zoom)}%`,
    height: `${round(zoom)}%`,
    left: offset(x),
    top: offset(y),
  };
}

/** Two decimals, and never `-0`. The editor writes numbers like `-10.85%`. */
function round(value: number): number {
  return Math.round(value * 100) / 100 + 0;
}

/**
 * The zoom half of a focal point, as a style object to spread beside `objectPosition`.
 *
 * Scaling about the focal point rather than absolutely positioning a grown image
 * (2026-09-08): every container in the Hub that crops a photo already clips, but not all
 * of them establish a positioning context, and a transform needs neither. An unzoomed
 * focal returns nothing at all, so a picture that has never been zoomed renders byte for
 * byte as it does today.
 *
 * `focalToCropStyle` above is the absolute-position form, kept because it is the Bag Brief
 * editor's own arithmetic and a one-pager imported from that editor must frame exactly as
 * its author framed it.
 */
export function focalZoomStyle(
  focal?: ImageFocalPoint | null
): { transform: string; transformOrigin: string } | undefined {
  if (!focal) return undefined;
  const zoom = focalZoom(focal);
  if (zoom <= FOCAL_ZOOM_MIN) return undefined;
  return {
    transform: `scale(${zoom / 100})`,
    transformOrigin: `${focal.x}% ${focal.y}%`,
  };
}
