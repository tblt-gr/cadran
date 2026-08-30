import { cleanup, fireEvent, render, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ModalSheet } from './ModalSheet';

describe('ModalSheet', () => {
  afterEach(() => {
    cleanup();
  });

  it('isolates the app, traps focus, closes with Escape, and restores document scrolling', async () => {
    const close = vi.fn();
    const { getByRole, unmount } = render(
      <>
        <main data-app-shell>Application</main>
        <ModalSheet ariaLabel="Dialogue de test" close={close}>
          <button data-autofocus type="button">
            Premier
          </button>
          <button type="button">Dernier</button>
        </ModalSheet>
      </>,
    );

    const appShell = document.querySelector('[data-app-shell]');
    const firstButton = getByRole('button', { name: 'Premier' });
    const lastButton = getByRole('button', { name: 'Dernier' });
    await waitFor(() => expect(document.activeElement).toBe(firstButton));
    expect(appShell?.hasAttribute('inert')).toBe(true);
    expect(document.body.style.overflow).toBe('hidden');

    lastButton.focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(firstButton);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(close).toHaveBeenCalledOnce();

    unmount();
    expect(appShell?.hasAttribute('inert')).toBe(false);
    expect(document.body.style.overflow).toBe('');
  });
});
