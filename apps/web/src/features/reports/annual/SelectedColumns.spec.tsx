import { useState } from 'react';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { SelectedColumns } from './SelectedColumns';

function Harness({ initial = ['a', 'b', 'c', 'd'] }: { initial?: string[] }) {
  const [selected, setSelected] = useState(initial);
  return (
    <SelectedColumns
      columnOf={(id) => ({ id, label: id.toUpperCase() })}
      onMove={(from, to) =>
        setSelected((current) => {
          const next = [...current];
          const [id] = next.splice(from, 1);
          next.splice(to, 0, id!);
          return next;
        })
      }
      onRemove={(id) => setSelected((current) => current.filter((item) => item !== id))}
      selected={selected}
    />
  );
}
const rows = () => screen.getAllByRole('listitem');
const order = () => rows().map((row) => row.querySelector('span')?.textContent);
const handle = (name: string) => screen.getByRole('button', { name: `Déplacer : ${name}` });

beforeEach(() => {
  class TestPointerEvent extends MouseEvent {
    pointerId: number;
    constructor(type: string, options: PointerEventInit) {
      super(type, options);
      this.pointerId = options.pointerId ?? 1;
    }
  }
  vi.stubGlobal('PointerEvent', TestPointerEvent);
  HTMLElement.prototype.setPointerCapture = vi.fn();
  HTMLElement.prototype.releasePointerCapture = vi.fn();
  HTMLElement.prototype.scrollIntoView = vi.fn();
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function geometry() {
  rows().forEach((row, index) =>
    vi.spyOn(row, 'getBoundingClientRect').mockReturnValue({
      top: index * 40,
      bottom: (index + 1) * 40,
      height: 40,
      left: 0,
      right: 300,
      width: 300,
      x: 0,
      y: index * 40,
      toJSON: () => ({}),
    }),
  );
}

describe('SelectedColumns', () => {
  it('moves directly to either boundary with the keyboard and keeps focus on the moved handle', () => {
    render(<Harness />);
    handle('A').focus();
    fireEvent.keyDown(handle('A'), { key: 'End' });
    expect(order()).toEqual(['B', 'C', 'D', 'A']);
    expect(document.activeElement).toBe(handle('A'));
    expect(screen.getByRole('status').textContent).toBe('A : position 4 sur 4.');
    fireEvent.keyDown(handle('A'), { key: 'Home' });
    fireEvent.keyDown(handle('A'), { key: 'ArrowUp' });
    expect(order()).toEqual(['A', 'B', 'C', 'D']);
    fireEvent.keyDown(handle('A'), { key: 'ArrowDown' });
    expect(order()).toEqual(['B', 'A', 'C', 'D']);
  });

  it.each(['mouse', 'touch'])(
    'drags first to last and last to first with %s pointer input',
    (pointerType) => {
      render(<Harness />);
      geometry();
      fireEvent.pointerDown(handle('A'), { pointerId: 7, pointerType, clientY: 20, button: 0 });
      fireEvent.pointerMove(handle('A'), { pointerId: 7, pointerType, clientY: 170 });
      expect(rows()[0]?.getAttribute('data-dragging')).toBe('true');
      expect(rows()[3]?.getAttribute('data-drop-after')).toBe('true');
      expect(order()).toEqual(['A', 'B', 'C', 'D']);
      fireEvent.pointerUp(handle('A'), { pointerId: 7, pointerType, clientY: 170 });
      expect(order()).toEqual(['B', 'C', 'D', 'A']);
      expect(document.activeElement).toBe(handle('A'));
      geometry();
      fireEvent.pointerDown(handle('A'), { pointerId: 8, pointerType, clientY: 140, button: 0 });
      fireEvent.pointerMove(handle('A'), { pointerId: 8, pointerType, clientY: -10 });
      fireEvent.pointerUp(handle('A'), { pointerId: 8, pointerType, clientY: -10 });
      expect(order()).toEqual(['A', 'B', 'C', 'D']);
    },
  );

  it('cancels a drag on Escape or pointer cancellation without changing selection', () => {
    render(<Harness />);
    geometry();
    for (const cancel of ['escape', 'pointercancel']) {
      fireEvent.pointerDown(handle('A'), { pointerId: 1, clientY: 20, button: 0 });
      fireEvent.pointerMove(handle('A'), { pointerId: 1, clientY: 170 });
      if (cancel === 'escape') fireEvent.keyDown(handle('A'), { key: 'Escape' });
      else fireEvent.pointerCancel(handle('A'), { pointerId: 1 });
      expect(order()).toEqual(['A', 'B', 'C', 'D']);
      expect(screen.queryByText('A : position 4 sur 4.')).toBeNull();
      expect(rows()[0]?.hasAttribute('data-dragging')).toBe(false);
    }
  });

  it('retains the single-pointer move controls and announces each move', () => {
    render(<Harness />);
    fireEvent.click(within(rows()[0]!).getByRole('button', { name: 'Descendre : A' }));
    expect(order()).toEqual(['B', 'A', 'C', 'D']);
    expect(screen.getByRole('status').textContent).toBe('A : position 2 sur 4.');
  });

  it('moves focus to the same row handle when its move button becomes disabled at a boundary', () => {
    render(<Harness initial={['a', 'b']} />);
    const down = screen.getByRole('button', { name: 'Descendre : A' });
    down.focus();
    fireEvent.click(down);
    expect(order()).toEqual(['B', 'A']);
    expect(document.activeElement).toBe(handle('A'));
  });

  it('scrolls the containing modal near its edge and stops the animation on cancellation', () => {
    let nextFrame: FrameRequestCallback | undefined;
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
      nextFrame = callback;
      return 9;
    });
    const cancelFrame = vi.spyOn(window, 'cancelAnimationFrame');
    const { container } = render(
      <div style={{ overflowY: 'auto' }}>
        <Harness />
      </div>,
    );
    const scroller = container.firstElementChild as HTMLElement;
    vi.spyOn(scroller, 'getBoundingClientRect').mockReturnValue({
      top: 0,
      bottom: 120,
      height: 120,
      left: 0,
      right: 300,
      width: 300,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    });
    geometry();
    fireEvent.pointerDown(handle('A'), { pointerId: 1, clientY: 20, button: 0 });
    fireEvent.pointerMove(handle('A'), { pointerId: 1, clientY: 118 });
    act(() => nextFrame!(0));
    expect(scroller.scrollTop).toBeGreaterThan(0);
    fireEvent.pointerCancel(handle('A'), { pointerId: 1 });
    expect(cancelFrame).toHaveBeenCalledWith(9);
    expect(order()).toEqual(['A', 'B', 'C', 'D']);
  });

  it('does not offer a drag for zero or one column', () => {
    const { rerender } = render(<Harness initial={[]} />);
    expect(screen.queryByRole('list')).toBeNull();
    rerender(<Harness key="one" initial={['a']} />);
    expect((handle('A') as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Monter : A' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect(
      (screen.getByRole('button', { name: 'Descendre : A' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });
});
