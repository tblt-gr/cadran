import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { useState } from 'react';
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

  it('keeps only the top nested dialog active and restores focus to its trigger', async () => {
    function NestedDialogs() {
      const [childOpen, setChildOpen] = useState(false);
      const [parentOpen, setParentOpen] = useState(true);

      return parentOpen ? (
        <Modal close={() => setParentOpen(false)} title="Transaction">
          <button onClick={() => setChildOpen(true)} type="button">
            Créer une catégorie
          </button>
          {childOpen ? (
            <Modal close={() => setChildOpen(false)} title="Nouvelle catégorie">
              <input aria-label="Libellé" data-autofocus />
            </Modal>
          ) : null}
        </Modal>
      ) : null;
    }

    render(<NestedDialogs />);
    const trigger = screen.getByRole('button', { name: 'Créer une catégorie' });
    trigger.focus();
    fireEvent.click(trigger);

    const parent = screen.getByRole('dialog', { name: 'Transaction' });
    await waitFor(() => expect(document.activeElement).toBe(screen.getByLabelText('Libellé')));
    expect(parent.hasAttribute('inert')).toBe(true);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(screen.queryByRole('dialog', { name: 'Nouvelle catégorie' })).toBeNull();
    expect(screen.getByRole('dialog', { name: 'Transaction' }).hasAttribute('inert')).toBe(false);
    expect(screen.getByRole('dialog', { name: 'Transaction' })).toBeTruthy();
    await waitFor(() => expect(document.activeElement).toBe(trigger));

    fireEvent.click(trigger);
    const childClosedByButton = screen.getByRole('dialog', { name: 'Nouvelle catégorie' });
    fireEvent.click(within(childClosedByButton).getByRole('button', { name: 'Fermer' }));
    expect(screen.queryByRole('dialog', { name: 'Nouvelle catégorie' })).toBeNull();
    expect(screen.getByRole('dialog', { name: 'Transaction' })).toBeTruthy();

    fireEvent.click(trigger);
    const childClosedByBackdrop = screen.getByRole('dialog', { name: 'Nouvelle catégorie' });
    fireEvent.mouseDown(childClosedByBackdrop.parentElement as HTMLElement);
    expect(screen.queryByRole('dialog', { name: 'Nouvelle catégorie' })).toBeNull();
    expect(screen.getByRole('dialog', { name: 'Transaction' })).toBeTruthy();
  });
});
