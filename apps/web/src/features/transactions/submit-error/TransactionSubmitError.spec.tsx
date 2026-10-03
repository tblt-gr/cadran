import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { TransactionSubmitError } from './TransactionSubmitError';

afterEach(cleanup);

describe('TransactionSubmitError', () => {
  it('links a portfolio-mode refusal to the portfolio of the account', () => {
    render(
      <TransactionSubmitError accountId="a-1" className="x" kind="portfolioOperationsRequired" />,
    );

    expect(screen.getByRole('alert').textContent).toContain(
      'Ce compte est suivi en mode portefeuille : enregistrez une opération de portefeuille.',
    );
    expect(screen.getByRole('link', { name: 'Ouvrir le portefeuille' }).getAttribute('href')).toBe(
      '/portfolios/a-1',
    );
  });

  it('keeps the server detail for other refusals and shows no link', () => {
    render(<TransactionSubmitError className="x" detail="Reste 5,00 €" kind="invalid" />);

    expect(screen.getByRole('alert').textContent).toBe('Reste 5,00 €');
    expect(screen.queryByRole('link')).toBeNull();
  });

  it('falls back to the generic message without detail', () => {
    render(<TransactionSubmitError className="x" kind="network" />);

    expect(screen.getByRole('alert').textContent).toBe('L’enregistrement a échoué. Réessayez.');
  });
});
