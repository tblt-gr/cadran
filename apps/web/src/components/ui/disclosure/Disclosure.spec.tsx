import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { Disclosure } from './Disclosure';

function Controlled({ initiallyOpen = false }: { initiallyOpen?: boolean }) {
  const [open, setOpen] = useState(initiallyOpen);

  return (
    <>
      <Disclosure meta="2 renseignés" onToggle={setOpen} open={open} title="Champs avancés">
        <label>
          Note
          <input />
        </label>
      </Disclosure>
      <button onClick={() => setOpen(true)} type="button">
        Ouvrir depuis l’hôte
      </button>
    </>
  );
}

describe('Disclosure', () => {
  afterEach(cleanup);

  it('shows its title and state in the summary while closed', () => {
    render(<Controlled />);

    const details = screen.getByText('Champs avancés').closest('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);
    expect(details.querySelector('summary')?.textContent).toContain('2 renseignés');
  });

  it('lets its host open it', () => {
    render(<Controlled />);

    fireEvent.click(screen.getByRole('button', { name: 'Ouvrir depuis l’hôte' }));

    expect((screen.getByText('Champs avancés').closest('details') as HTMLDetailsElement).open).toBe(
      true,
    );
  });

  it('spreads the title and the meta to the two ends of a full-width summary', () => {
    render(
      <Disclosure meta="1 / 2" onToggle={() => undefined} open={false} spread title="2026">
        <p>Contenu</p>
      </Disclosure>,
    );

    const summary = screen.getByText('2026').closest('summary')!;
    expect(summary.firstElementChild!.textContent).toBe('2026');
    expect(summary.lastElementChild!.textContent).toBe('1 / 2');
    expect(summary.closest('details')!.className).toMatch(/spread/);
  });
});
