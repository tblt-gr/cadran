import { cleanup, fireEvent, render, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { Modal } from './Modal';

describe('Modal', () => {
  afterEach(() => {
    cleanup();
  });

  it('names the dialog, focuses the requested control, traps focus and closes', async () => {
    const close = vi.fn();
    const { getByRole, unmount } = render(
      <>
        <main data-app-shell>Application</main>
        <Modal close={close} eyebrow="Création" title="Nouvelle catégorie">
          <input aria-label="Libellé" data-autofocus />
          <button type="button">Enregistrer</button>
        </Modal>
      </>,
    );

    const appShell = document.querySelector('[data-app-shell]');
    const dialog = getByRole('dialog', { name: 'Nouvelle catégorie' });
    const field = getByRole('textbox', { name: 'Libellé' });
    const submit = getByRole('button', { name: 'Enregistrer' });

    await waitFor(() => expect(document.activeElement).toBe(field));
    expect(dialog.textContent).toContain('Création');
    expect(appShell?.hasAttribute('inert')).toBe(true);
    expect(document.body.style.overflow).toBe('hidden');

    submit.focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(getByRole('button', { name: 'Fermer' }));

    fireEvent.click(getByRole('button', { name: 'Fermer' }));
    expect(close).toHaveBeenCalledOnce();

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(close).toHaveBeenCalledTimes(2);

    unmount();
    expect(appShell?.hasAttribute('inert')).toBe(false);
    expect(document.body.style.overflow).toBe('');
  });

  it('closes on a backdrop press but not on a press inside the dialog', () => {
    const close = vi.fn();
    const { getByRole } = render(
      <Modal close={close} title="Nouvelle catégorie">
        <button type="button">Enregistrer</button>
      </Modal>,
    );

    const dialog = getByRole('dialog', { name: 'Nouvelle catégorie' });
    fireEvent.mouseDown(dialog);
    expect(close).not.toHaveBeenCalled();

    fireEvent.mouseDown(dialog.parentElement as HTMLElement);
    expect(close).toHaveBeenCalledOnce();
  });
});
