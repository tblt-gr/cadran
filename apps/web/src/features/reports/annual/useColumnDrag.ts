import { useEffect, useRef, useState, type PointerEvent, type RefObject } from 'react';

interface Drag {
  id: string;
  pointerId: number;
  startY: number;
  y: number;
  target: number;
  active: boolean;
  handle: HTMLButtonElement;
  scroller: HTMLElement | null;
}

function scrollContainer(element: HTMLElement): HTMLElement | null {
  for (let parent = element.parentElement; parent; parent = parent.parentElement) {
    if (/(auto|scroll)/.test(getComputedStyle(parent).overflowY)) return parent;
  }
  return null;
}

/** Pointer capture keeps touch and mouse moves attached to the handle, including outside a row.
 * The list stays put until release; edge scrolling remeasures the insertion point every frame. */
export function useColumnDrag(
  selected: readonly string[],
  move: (from: number, to: number) => void,
  listRef: RefObject<HTMLOListElement | null>,
) {
  const dragRef = useRef<Drag | null>(null);
  const frame = useRef<number | null>(null);
  const [preview, setPreview] = useState<{ id: string; target: number } | null>(null);

  useEffect(
    () => () => {
      if (frame.current !== null) cancelAnimationFrame(frame.current);
    },
    [],
  );

  function cancel() {
    const drag = dragRef.current;
    dragRef.current = null;
    if (frame.current !== null) cancelAnimationFrame(frame.current);
    frame.current = null;
    setPreview(null);
    if (drag?.handle.hasPointerCapture?.(drag.pointerId)) {
      drag.handle.releasePointerCapture(drag.pointerId);
    }
  }

  function measure(drag: Drag) {
    const rows = Array.from(listRef.current?.children ?? []);
    drag.target = rows.filter((row) => {
      if ((row as HTMLElement).dataset.columnId === drag.id) return false;
      const bounds = row.getBoundingClientRect();
      return drag.y > bounds.top + bounds.height / 2;
    }).length;
    setPreview((previous) =>
      previous?.id === drag.id && previous.target === drag.target
        ? previous
        : { id: drag.id, target: drag.target },
    );
  }

  function tick() {
    const drag = dragRef.current;
    if (!drag?.active) return;
    if (drag.scroller) {
      const bounds = drag.scroller.getBoundingClientRect();
      // Geometry only: a small edge zone and bounded speed keep long lists controllable.
      const edge = Math.min(48, bounds.height / 4);
      const distance =
        drag.y < bounds.top + edge
          ? drag.y - bounds.top - edge
          : drag.y > bounds.bottom - edge
            ? drag.y - bounds.bottom + edge
            : 0;
      drag.scroller.scrollTop += Math.max(-12, Math.min(12, distance / 4));
    }
    measure(drag);
    frame.current = requestAnimationFrame(tick);
  }

  function start(event: PointerEvent<HTMLButtonElement>, id: string) {
    if (selected.length < 2 || event.button !== 0 || dragRef.current) return;
    event.preventDefault();
    event.currentTarget.focus({ preventScroll: true });
    event.currentTarget.setPointerCapture(event.pointerId);
    dragRef.current = {
      id,
      pointerId: event.pointerId,
      startY: event.clientY,
      y: event.clientY,
      target: selected.indexOf(id),
      active: false,
      handle: event.currentTarget,
      scroller: scrollContainer(event.currentTarget),
    };
  }

  function update(event: PointerEvent<HTMLButtonElement>) {
    const drag = dragRef.current;
    if (!drag || event.pointerId !== drag.pointerId) return;
    drag.y = event.clientY;
    if (!drag.active && Math.abs(drag.y - drag.startY) < 4) return;
    event.preventDefault();
    if (!drag.active) {
      drag.active = true;
      frame.current = requestAnimationFrame(tick);
    }
    measure(drag);
  }

  function finish(event: PointerEvent<HTMLButtonElement>) {
    const drag = dragRef.current;
    if (!drag || event.pointerId !== drag.pointerId) return;
    if (drag.active) {
      drag.y = event.clientY;
      measure(drag);
      move(selected.indexOf(drag.id), drag.target);
    }
    cancel();
  }

  return {
    preview,
    start,
    update,
    finish,
    cancel,
    active: () => dragRef.current !== null,
  };
}
