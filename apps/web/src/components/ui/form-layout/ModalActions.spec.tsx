import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { ModalActions } from './ModalActions';

afterEach(cleanup);

it('keeps the secondary action before the primary one', () => {
  render(
    <ModalActions>
      <button type="button">Annuler</button>
      <button type="submit">Enregistrer</button>
    </ModalActions>,
  );
  const names = screen.getAllByRole('button').map((button) => button.textContent);
  expect(names).toEqual(['Annuler', 'Enregistrer']);
  expect(
    screen
      .getByRole('button', { name: 'Enregistrer' })
      .parentElement?.hasAttribute('data-modal-actions'),
  ).toBe(true);
});
