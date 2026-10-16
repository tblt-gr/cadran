import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { DecimalInput } from './DecimalInput';
afterEach(cleanup);
it('names the decimal field independently and associates its error and provenance hint', () => {
  render(
    <DecimalInput
      label="Valeur totale"
      value=""
      onChange={vi.fn()}
      hint="Relevé précédent"
      error="Saisissez un décimal exact"
    />,
  );
  const input = screen.getByRole('textbox', { name: 'Valeur totale' });
  expect(input.getAttribute('aria-invalid')).toBe('true');
  const ids = input.getAttribute('aria-describedby')?.split(' ') ?? [];
  expect(ids.map((id) => document.getElementById(id)?.textContent)).toEqual([
    'Relevé précédent',
    'Saisissez un décimal exact',
  ]);
});
