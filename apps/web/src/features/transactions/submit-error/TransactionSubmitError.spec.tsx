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

  it('names the first negative day of an insufficient balance in French and English', async () => {
    const { default: i18n } = await import('i18next');
    const { default: english } = await import('@/locales/en/translation.json');
    i18n.addResourceBundle('en', 'translation', english);
    render(<TransactionSubmitError className="x" day="2026-11-05" kind="insufficientBalance" />);

    expect(screen.getByRole('alert').textContent).toBe(
      'Solde insuffisant : ce montant mettrait le compte en négatif le 5 novembre 2026.',
    );
    expect(screen.getByRole('alert').querySelector('time')?.getAttribute('datetime')).toBe(
      '2026-11-05',
    );

    await i18n.changeLanguage('en');
    try {
      cleanup();
      render(<TransactionSubmitError className="x" day="2026-11-05" kind="insufficientBalance" />);
      expect(screen.getByRole('alert').textContent).toBe(
        'Insufficient balance: this amount would put the account below zero on November 5, 2026.',
      );
    } finally {
      await i18n.changeLanguage('fr');
    }
  });

  it('falls back to an undated message when the day is unknown', () => {
    render(<TransactionSubmitError className="x" kind="insufficientBalance" />);

    expect(screen.getByRole('alert').textContent).toBe(
      'Solde insuffisant : ce montant mettrait le compte en négatif.',
    );
  });
});
