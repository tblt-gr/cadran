import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import { HiddenSupportResult } from './HiddenSupportResult';

afterEach(cleanup);

it.each(['12', '-12', '0', '0.000000000000000000000001'])(
  'keeps a missing performance reason beside masked gain %s',
  (gain) => {
    const { container } = render(
      <HiddenSupportResult gain={gain} performance={null} missing="Non calculable" />,
    );
    expect(screen.getByText('Montant masqué')).toBeTruthy();
    expect(screen.getByText('Non calculable')).toBeTruthy();
    expect(container.textContent).not.toMatch(/[0-9+−]/);
  },
);

it('keeps the missing gain reason while masking an available performance', () => {
  render(<HiddenSupportResult gain={null} performance="-0.5" missing="Non calculable" />);
  expect(screen.getByText('Montant masqué')).toBeTruthy();
  expect(screen.getByText('Non calculable')).toBeTruthy();
});
