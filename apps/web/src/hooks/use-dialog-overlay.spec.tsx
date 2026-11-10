import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { useDialogOverlay } from './use-dialog-overlay';

afterEach(cleanup);

function Dialog({ children }: { children: ReactNode }) {
  const ref = useDialogOverlay(vi.fn());
  return (
    <section aria-label="Dialog" ref={ref} role="dialog">
      {children}
    </section>
  );
}

it('allows Tab to reach a native summary and wraps forward and backward at the real ends', async () => {
  render(
    <Dialog>
      <button type="button">Close</button>
      <button type="button">Before history</button>
      <details>
        <summary>Revision 1</summary>
        <p>Values</p>
      </details>
    </Dialog>,
  );
  const first = screen.getByRole('button', { name: 'Close' });
  const before = screen.getByRole('button', { name: 'Before history' });
  const summary = screen.getByText('Revision 1');
  await waitFor(() => expect(document.activeElement).toBe(first));
  before.focus();
  const forward = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
  document.dispatchEvent(forward);
  expect(forward.defaultPrevented).toBe(false);
  first.focus();
  fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
  expect(document.activeElement).toBe(summary);
  fireEvent.keyDown(document, { key: 'Tab' });
  expect(document.activeElement).toBe(first);
});

it('excludes disabled summaries and content inside closed or hidden ancestors', async () => {
  render(
    <Dialog>
      <button type="button">Close</button>
      <details>
        <summary>Visible revision</summary>
        <button type="button">Hidden action</button>
        <details>
          <summary>Hidden nested revision</summary>
          <button type="button">Hidden nested action</button>
        </details>
      </details>
      <details>
        <summary aria-disabled="true">Disabled revision</summary>
      </details>
      <details>
        <summary tabIndex={-1}>Excluded revision</summary>
      </details>
      <div hidden>
        <button type="button">Hidden button</button>
      </div>
      <div style={{ display: 'none' }}>
        <details>
          <summary>CSS-hidden revision</summary>
        </details>
      </div>
    </Dialog>,
  );
  const first = screen.getByRole('button', { name: 'Close' });
  await waitFor(() => expect(document.activeElement).toBe(first));
  fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
  expect(document.activeElement).toBe(screen.getByText('Visible revision'));
  fireEvent.keyDown(document, { key: 'Tab' });
  expect(document.activeElement).toBe(first);
  fireEvent.click(screen.getByText('Visible revision'));
  first.focus();
  fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
  expect(document.activeElement).toBe(screen.getByText('Hidden nested revision'));
});

it('can initially focus an enabled native summary when it is the first control', async () => {
  render(
    <Dialog>
      <details>
        <summary>Revision</summary>
        <p>Values</p>
      </details>
    </Dialog>,
  );
  await waitFor(() => expect(document.activeElement).toBe(screen.getByText('Revision')));
});
