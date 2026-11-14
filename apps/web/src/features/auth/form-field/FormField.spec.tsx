import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import { FormField } from './FormField';

afterEach(cleanup);

const field = (type: 'password' | 'email') => (
  <FormField
    autoComplete="off"
    id="f"
    label="Champ"
    onChange={() => undefined}
    type={type}
    value="secret"
  />
);

it('reveals and hides a password with an eye button that keeps the input', () => {
  render(field('password'));
  const input = screen.getByLabelText('Champ') as HTMLInputElement;
  expect(input.type).toBe('password');
  fireEvent.click(screen.getByRole('button', { name: 'Afficher le mot de passe' }));
  expect(input.type).toBe('text');
  fireEvent.click(screen.getByRole('button', { name: 'Masquer le mot de passe' }));
  expect(input.type).toBe('password');
});

it('offers no eye on a field that is not a password', () => {
  render(field('email'));
  expect(screen.queryByRole('button')).toBeNull();
});
