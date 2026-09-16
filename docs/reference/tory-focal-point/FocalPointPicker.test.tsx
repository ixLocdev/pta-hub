/**
 * Milestone 5.6 step 4 — the drag-to-frame control (RED, TDD-first).
 *
 * `src/app/admin/_components/FocalPointPicker.tsx` does not exist yet, so this whole
 * file fails at import. That import failure IS the red state for the component's
 * existence; the cases below pin what it has to do once it is there.
 *
 * The control is deliberately thin: jsdom has no layout, and `src/lib` is gated at 80%
 * coverage, so the maths (`pointerToFocal`, `focalToObjectPosition`) lives in
 * `src/lib/focal-point.ts` and is unit-tested there. What this file guards is the part
 * only a component can get wrong — where the marker sits, that a drag reports clamped
 * percentages, that the keyboard can reach it, and that nothing here reaches for a
 * browser dialog (5.12's rule; `window.alert` is stubbed to throw exactly as
 * `ImageDropField.test.tsx` does).
 *
 * DOM contract pinned here (a picker is untestable without one):
 *   - the drag area carries `data-testid="focal-surface"` — jsdom reports a 0×0 rect for
 *     everything, so the test stubs this element's `getBoundingClientRect`
 *   - the marker carries `data-testid="focal-marker"`, is focusable, and is positioned
 *     with percentage `left` / `top` inline styles
 *   - reset: a control named /reset/i calls `onReset` when one was passed, and falls back
 *     to `onChange({ x: 50, y: 50 })` when it was not. Both are pinned below so the
 *     caller may leave `onReset` off and still get a working reset.
 *
 * Run: npx vitest run tests/components/FocalPointPicker.test.tsx
 */
import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FocalPointPicker } from "@/app/admin/_components/FocalPointPicker";

// jsdom ships PointerEvent but not the capture API a drag control may reasonably use.
// Shimming it keeps the test from dictating how the implementation tracks the pointer.
type CaptureElement = Element & {
  setPointerCapture?: (id: number) => void;
  releasePointerCapture?: (id: number) => void;
  hasPointerCapture?: (id: number) => boolean;
};
const proto = Element.prototype as CaptureElement;
proto.setPointerCapture ??= () => {};
proto.releasePointerCapture ??= () => {};
proto.hasPointerCapture ??= () => false;

const SRC = "https://cdn.example.com/fleming.jpg";
const onChange = vi.fn();
const onReset = vi.fn();

/** 200×100 at the origin — one pixel across is 0.5%, one pixel down is 1%. */
function stubSurfaceRect(el: Element, left = 0, top = 0, width = 200, height = 100) {
  vi.spyOn(el, "getBoundingClientRect").mockReturnValue({
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
    x: left,
    y: top,
    toJSON: () => ({}),
  } as DOMRect);
}

function pointer(
  el: Element,
  type: "pointerdown" | "pointermove" | "pointerup",
  clientX: number,
  clientY: number,
  pointerId = 1
) {
  fireEvent(
    el,
    new PointerEvent(type, {
      bubbles: true,
      cancelable: true,
      clientX,
      clientY,
      pointerId,
      isPrimary: pointerId === 1,
      buttons: type === "pointerup" ? 0 : 1,
    })
  );
}

function surface(): HTMLElement {
  const el = screen.getByTestId("focal-surface");
  stubSurfaceRect(el);
  return el;
}

function lastFocal(): { x: number; y: number } {
  const call = onChange.mock.calls.at(-1);
  expect(call, "onChange was never called").toBeDefined();
  return call![0];
}

describe("FocalPointPicker", () => {
  beforeEach(() => {
    onChange.mockClear();
    onReset.mockClear();
    // 5.12: admin never opens a native dialog. Throwing makes a stray one a test failure
    // wherever it happens, not just where it is asserted.
    vi.spyOn(window, "alert").mockImplementation(() => {
      throw new Error("window.alert must never be used in admin (5.12)");
    });
    vi.spyOn(window, "confirm").mockImplementation(() => {
      throw new Error("window.confirm must never be used in admin (5.12)");
    });
  });

  it("shows the photo with the marker on the focal point", () => {
    render(<FocalPointPicker src={SRC} value={{ x: 37, y: 62 }} onChange={onChange} />);

    expect(document.querySelector(`img[src="${SRC}"]`)).not.toBeNull();

    const marker = screen.getByTestId("focal-marker");
    expect(marker.style.left).toBe("37%");
    expect(marker.style.top).toBe("62%");
  });

  it("starts centred when no focal point has been set", () => {
    render(<FocalPointPicker src={SRC} onChange={onChange} />);

    const marker = screen.getByTestId("focal-marker");
    expect(marker.style.left).toBe("50%");
    expect(marker.style.top).toBe("50%");
  });

  it("reports the focal point the pointer was dragged to", () => {
    render(<FocalPointPicker src={SRC} onChange={onChange} />);
    const area = surface();

    pointer(area, "pointerdown", 74, 62);
    pointer(area, "pointermove", 150, 50);
    pointer(area, "pointerup", 150, 50);

    expect(onChange).toHaveBeenCalled();
    expect(lastFocal().x).toBeCloseTo(75, 1);
    expect(lastFocal().y).toBeCloseTo(50, 1);
  });

  it("clamps a drag that runs off the photo to the edges", () => {
    render(<FocalPointPicker src={SRC} onChange={onChange} />);
    const area = surface();

    pointer(area, "pointerdown", 100, 50);
    pointer(area, "pointermove", -40, 400);
    pointer(area, "pointerup", -40, 400);

    expect(lastFocal().x).toBeCloseTo(0, 1);
    expect(lastFocal().y).toBeCloseTo(100, 1);
  });

  it("ignores the pointer moving across the photo when nothing is being dragged", () => {
    render(<FocalPointPicker src={SRC} onChange={onChange} />);
    const area = surface();

    pointer(area, "pointermove", 20, 20);

    expect(onChange).not.toHaveBeenCalled();
  });

  it("stops following the pointer once it is released", () => {
    render(<FocalPointPicker src={SRC} onChange={onChange} />);
    const area = surface();

    pointer(area, "pointerdown", 100, 50);
    pointer(area, "pointerup", 100, 50);
    const settled = onChange.mock.calls.length;

    pointer(area, "pointermove", 10, 90);

    expect(onChange.mock.calls.length).toBe(settled);
  });

  it("lets the keyboard nudge the focal point", () => {
    render(<FocalPointPicker src={SRC} value={{ x: 50, y: 50 }} onChange={onChange} />);

    const marker = screen.getByTestId("focal-marker");
    // Reachable by tab — a drag-only control is unusable without a mouse.
    expect(marker.tabIndex).toBeGreaterThanOrEqual(0);

    fireEvent.keyDown(marker, { key: "ArrowRight" });
    const right = lastFocal();
    expect(right.y).toBeCloseTo(50, 1);
    expect(right.x).toBeGreaterThan(50);
    // A nudge, not a jump.
    expect(right.x).toBeLessThanOrEqual(55);

    onChange.mockClear();
    fireEvent.keyDown(marker, { key: "ArrowUp" });
    const up = lastFocal();
    expect(up.x).toBeCloseTo(50, 1);
    expect(up.y).toBeLessThan(50);
    expect(up.y).toBeGreaterThanOrEqual(45);
  });

  it("caps how large the photo is drawn without letterboxing it", () => {
    render(<FocalPointPicker src={SRC} onChange={onChange} />);

    const area = screen.getByTestId("focal-surface");
    const photo = document.querySelector(`img[src="${SRC}"]`) as HTMLElement;

    // The cap itself: a tall photo used to fill its whole column. jsdom has no layout, so
    // the classes are the only thing left to pin — and it is the regression worth pinning,
    // since nothing else here would notice the picker going full-size again.
    expect(photo.className).toContain("max-h-80");
    // Aspect survives the cap: height follows width, neither axis is forced.
    expect(photo.className).toContain("h-auto");
    expect(photo.className).toContain("w-auto");
    // And the surface shrink-wraps the photo rather than framing it — a letterboxed
    // surface would hand back focal points for pixels that aren't on the picture.
    expect(area.classList.contains("w-fit")).toBe(true);
    expect(area.classList.contains("w-full")).toBe(false);
  });

  it("recentres through onReset when the caller handles it", () => {
    render(
      <FocalPointPicker
        src={SRC}
        value={{ x: 12, y: 90 }}
        onChange={onChange}
        onReset={onReset}
      />
    );

    fireEvent.click(screen.getByRole("button", { name: /reset/i }));

    expect(onReset).toHaveBeenCalledTimes(1);
  });

  it("recentres through onChange when the caller passes no onReset", () => {
    render(<FocalPointPicker src={SRC} value={{ x: 12, y: 90 }} onChange={onChange} />);

    fireEvent.click(screen.getByRole("button", { name: /reset/i }));

    expect(onChange).toHaveBeenCalledWith({ x: 50, y: 50 });
  });

  it("never opens a browser dialog", () => {
    render(<FocalPointPicker src={SRC} value={{ x: 12, y: 90 }} onChange={onChange} />);
    const area = surface();

    pointer(area, "pointerdown", 10, 10);
    pointer(area, "pointermove", 190, 90);
    pointer(area, "pointerup", 190, 90);
    fireEvent.click(screen.getByRole("button", { name: /reset/i }));

    expect(window.alert).not.toHaveBeenCalled();
    expect(window.confirm).not.toHaveBeenCalled();
  });
});

/**
 * Zoom, said with the same gesture as everything else (2026-09-08).
 *
 * Lucas, having tried it as a slider: *"the focus point drag is a really easy tool - any
 * want to integrate the zoom into it or something similar rather than a bar? I want it to
 * feel effortless."* So: pinch on a touch screen, wheel on a desktop, and the keys for
 * anyone without either.
 */
describe("zoom", () => {
  it("has no slider — the photo itself is the control", () => {
    render(<FocalPointPicker src="/a.png" value={{ x: 50, y: 50 }} onChange={onChange} />);

    expect(screen.queryByRole("slider")).toBeNull();
  });

  it("spreads two fingers to zoom in, from the zoom the pinch started at", () => {
    render(<FocalPointPicker src="/a.png" value={{ x: 50, y: 50, zoom: 120 }} onChange={onChange} />);
    const el = surface();

    pointer(el, "pointerdown", 100, 100, 1);
    pointer(el, "pointerdown", 180, 100, 2); // 80px apart
    pointer(el, "pointermove", 260, 100, 2); // 160px apart — twice the spread

    expect(onChange.mock.calls.at(-1)?.[0].zoom).toBe(240);
  });

  it("pinches back in to zoom out", () => {
    render(<FocalPointPicker src="/a.png" value={{ x: 50, y: 50, zoom: 200 }} onChange={onChange} />);
    const el = surface();

    pointer(el, "pointerdown", 100, 100, 1);
    pointer(el, "pointerdown", 200, 100, 2); // 100px apart
    pointer(el, "pointermove", 150, 100, 2); // 50px apart — half

    // Halving 200 lands exactly on the bottom of the range, where the zoom is dropped
    // rather than stored — so the photo goes back to emitting what it always emitted.
    expect(onChange.mock.calls.at(-1)?.[0]).toEqual({ x: 50, y: 50 });
  });

  it("does not drag the focal point while two fingers are down", () => {
    // The second finger landing must abandon the drag, or the photo lurches to the midpoint.
    render(<FocalPointPicker src="/a.png" value={{ x: 50, y: 50, zoom: 150 }} onChange={onChange} />);
    const el = surface();

    pointer(el, "pointerdown", 100, 100, 1);
    onChange.mockClear();
    pointer(el, "pointerdown", 180, 100, 2);
    pointer(el, "pointermove", 260, 100, 2);

    for (const call of onChange.mock.calls) {
      expect(call[0]).toMatchObject({ x: 50, y: 50 });
    }
  });

  it("keeps the zoom when the dot is dragged afterwards", () => {
    // The regression this was always going to have: `pointerToFocal` answers with x and y
    // alone, so a move would silently throw the zoom away.
    render(<FocalPointPicker src="/a.png" value={{ x: 50, y: 50, zoom: 175 }} onChange={onChange} />);
    const el = surface();

    pointer(el, "pointerdown", 130, 140, 1);

    expect(onChange.mock.calls.at(-1)?.[0].zoom).toBe(175);
  });

  it("zooms from the keyboard, because the dot is focusable and a gesture is not enough", () => {
    render(<FocalPointPicker src="/a.png" value={{ x: 50, y: 50 }} onChange={onChange} />);
    const marker = screen.getByTestId("focal-marker");

    fireEvent.keyDown(marker, { key: "+" });
    expect(onChange.mock.calls.at(-1)?.[0].zoom).toBe(105);

    fireEvent.keyDown(marker, { key: "+", shiftKey: true });
    expect(onChange.mock.calls.at(-1)?.[0].zoom).toBe(125);
  });

  it("keeps the readout in the layout at every zoom, so nothing below the photo moves", () => {
    // Lucas, 2026-09-08: "using the wheel or pinching seems to make the text below it move."
    // It did: the readout was rendered only while zoomed, so the first turn of the wheel
    // added an element to the row, re-wrapped the hint beside it and shifted Reset. The
    // slot is always there now — empty when there is nothing to say.
    const { rerender } = render(
      <FocalPointPicker src="/a.png" value={{ x: 50, y: 50 }} onChange={onChange} />
    );

    const unzoomed = screen.getByTestId("focal-zoom-readout");
    expect(unzoomed).toBeTruthy();
    expect(unzoomed.textContent).toBe("");
    expect(unzoomed.getAttribute("aria-hidden")).toBe("true");

    rerender(
      <FocalPointPicker src="/a.png" value={{ x: 50, y: 50, zoom: 175 }} onChange={onChange} />
    );

    const zoomed = screen.getByTestId("focal-zoom-readout");
    expect(zoomed.textContent).toBe("175%");
    // Same element, same slot — not one that appears and disappears.
    expect(zoomed.className).toBe(unzoomed.className);
  });

  it("drops the zoom entirely at the bottom of the range", () => {
    // Not stored as 100: an unzoomed photo must keep emitting object-position and nothing
    // else, exactly as it did before zoom existed.
    render(<FocalPointPicker src="/a.png" value={{ x: 40, y: 60, zoom: 105 }} onChange={onChange} />);
    const marker = screen.getByTestId("focal-marker");

    fireEvent.keyDown(marker, { key: "-" });

    expect(onChange.mock.calls.at(-1)?.[0]).toEqual({ x: 40, y: 60 });
  });
});
