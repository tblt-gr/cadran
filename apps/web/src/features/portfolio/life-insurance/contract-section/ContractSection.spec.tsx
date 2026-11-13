import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { ContractSection } from './ContractSection';

afterEach(cleanup);

it('is closed by default, keeps its title and date in the summary and opens its action and content', () => {
  render(
    <ContractSection
      action={<button type="button">Ajouter</button>}
      hint="au 31/10/2026"
      title="Montants"
    >
      <p>Contenu</p>
    </ContractSection>,
  );
  const details = screen.getByText('Montants').closest('details') as HTMLDetailsElement;

  expect(details.open).toBe(false);
  expect(screen.getByText('au 31/10/2026').closest('summary')).toBeTruthy();
  fireEvent.click(details.querySelector('summary')!);
  expect(details.open).toBe(true);
  expect(screen.getByRole('button', { name: 'Ajouter' })).toBeTruthy();
});
