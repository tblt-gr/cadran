import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { RecordBalanceFormSkeleton } from './RecordBalanceFormSkeleton';

afterEach(cleanup);
describe('RecordBalanceFormSkeleton', () => {
  it('keeps the recording controls and disclosures while account values are pending', () => {
    const { container } = render(<RecordBalanceFormSkeleton />);
    const action = screen.getByRole('button', {
      name: 'Enregistrer le solde',
    }) as HTMLButtonElement;
    expect(action.disabled).toBe(true);
    expect(screen.getByText('Champs avancés')).toBeTruthy();
    expect(screen.getByText('Relevés enregistrés')).toBeTruthy();
    expect(container.querySelectorAll('[data-skeleton]')).toHaveLength(4);
    expect(container.querySelector('form')).toBeTruthy();
  });
});
