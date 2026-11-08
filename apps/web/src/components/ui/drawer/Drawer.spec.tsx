import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useRef, useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { Drawer } from './Drawer';

function Harness() {
  const [open, setOpen] = useState(false);
  const opener = useRef<HTMLButtonElement>(null);
  return (
    <>
      <main data-app-shell>
        <button onClick={() => setOpen(true)} ref={opener} type="button">
          Ouvrir
        </button>
      </main>
      {open ? (
        <Drawer
          close={() => setOpen(false)}
          footer={<button type="button">Nouvel objectif</button>}
          returnFocus={opener}
          title="Objectifs"
        >
          <button type="button">Premier</button>
        </Drawer>
      ) : null}
    </>
  );
}

describe('Drawer', () => {
  afterEach(cleanup);

  it('names the dialog, makes the page inert, traps focus and shows the footer', async () => {
    const close = vi.fn();
    render(
      <>
        <main data-app-shell>Application</main>
        <Drawer close={close} footer={<button type="button">Pied</button>} title="Objectifs">
          <button type="button">Corps</button>
        </Drawer>
      </>,
    );
    const dialog = screen.getByRole('dialog', { name: 'Objectifs' });
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(screen.getByRole('heading', { level: 2, name: 'Objectifs' })).toBeTruthy();
    expect(document.querySelector('[data-app-shell]')?.hasAttribute('inert')).toBe(true);
    await waitFor(() =>
      expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Fermer' })),
    );

    screen.getByRole('button', { name: 'Pied' }).focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Fermer' }));

    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));
    expect(close).toHaveBeenCalledOnce();
  });

  it('closes on Escape and returns the focus to its opener', async () => {
    render(<Harness />);
    const opener = screen.getByRole('button', { name: 'Ouvrir' });
    opener.focus();
    fireEvent.click(opener);
    expect(await screen.findByRole('dialog', { name: 'Objectifs' })).toBeTruthy();

    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(document.activeElement).toBe(opener));
  });

  it('closes when the backdrop is pressed but not when the panel is', () => {
    const close = vi.fn();
    render(
      <Drawer close={close} title="Objectifs">
        <p>Contenu</p>
      </Drawer>,
    );
    fireEvent.mouseDown(screen.getByRole('dialog'));
    expect(close).not.toHaveBeenCalled();
    fireEvent.mouseDown(screen.getByRole('dialog').parentElement!);
    expect(close).toHaveBeenCalledOnce();
  });
});
