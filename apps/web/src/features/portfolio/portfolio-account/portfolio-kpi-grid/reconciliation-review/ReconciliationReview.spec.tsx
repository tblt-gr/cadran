import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ReconciliationReview } from './ReconciliationReview';

afterEach(cleanup);

function renderReview(code: string, count = 2, onClose = vi.fn()) {
  render(
    <ReconciliationReview
      accountId="a"
      asOf="2026-03-10"
      onClose={onClose}
      warnings={[{ code, count }]}
    />,
  );
  return screen.getByRole('listitem');
}

describe('ReconciliationReview', () => {
  it.each([
    ['NEGATIVE_CASH', 'Espèces négatives (2 opérations)', 'un versement, un dividende', 'cash'],
    [
      'UNLINKED_TRANSFER_IN',
      'Virements reçus sans versement (2 virements)',
      'Créez le versement',
      'cash',
    ],
    [
      'UNLINKED_TRANSFER_OUT',
      'Virements sortis sans retrait (2 virements)',
      'Créez le retrait',
      'cash',
    ],
    [
      'LINKED_TRANSFER_CHANGED',
      'Versements différents de leur virement (2 opérations)',
      'La date, le montant ou le sens',
      'cash',
    ],
    [
      'NON_TRANSFER_TRANSACTION',
      'Transactions bancaires hors virement (2 transactions)',
      'annulez la transaction',
      'account',
    ],
    [
      'CASH_DIFFERENCE',
      'Espèces du relevé différentes du calcul',
      'une opération manque',
      'valuations',
    ],
    [
      'OBSERVED_TOTAL_DIFFERENCE',
      'Total du relevé différent du calcul',
      'quantités et les prix',
      'valuations',
    ],
    ['POSITIONS_CHANGED', 'Valorisation à reprendre', 'Ressaisissez', 'valuations'],
    [
      'NEGATIVE_SECURITIES_VALUE',
      'Valeur des titres négative',
      'inférieur aux espèces',
      'valuations',
    ],
    ['STALE_PRICES', 'Prix anciens', 'plus de 5 jours', 'valuations'],
    ['PARTIAL_VALUATION', 'Valorisation partielle', 'Complétez', 'valuations'],
    ['STALE_VALUATION', 'Valorisation ancienne', 'plus récente', 'valuations'],
    [
      'UNKNOWN_LOT_COST',
      'Prix de revient inconnu (2 positions)',
      'opération d’origine',
      'operations',
    ],
    [
      'ORDER_DEPENDENT_DAY',
      'Ordre des opérations à vérifier (2 jours)',
      'ordre de saisie',
      'operations',
    ],
  ])('says what %s means, what to do and where', (code, title, hint, tab) => {
    const item = renderReview(code);
    expect(within(item).getByText(title).tagName).toBe('STRONG');
    expect(item.textContent).toContain(hint);
    expect(item.textContent).not.toContain(code);
    expect(within(item).getByRole('link').getAttribute('href')).toBe(
      `/portfolios/a?tab=${tab}&asOf=2026-03-10`,
    );
  });

  it('counts one item in the singular and keeps an unknown code visible', () => {
    expect(
      within(renderReview('NEGATIVE_CASH', 1)).getByText('Espèces négatives (1 opération)'),
    ).toBeTruthy();
    cleanup();
    const item = renderReview('SOMETHING_NEW');
    expect(within(item).getByText('SOMETHING_NEW')).toBeTruthy();
    expect(within(item).getByRole('link', { name: 'Ouvrir les opérations' })).toBeTruthy();
  });

  it('closes and navigates in the page to the tab of the point', () => {
    window.history.replaceState({}, '', '/portfolios/a');
    const close = vi.fn();
    renderReview('CASH_DIFFERENCE', 1, close);
    expect(screen.getByRole('dialog', { name: '1 point à vérifier' })).toBeTruthy();
    fireEvent.click(screen.getByRole('link', { name: 'Ouvrir les valorisations' }));
    expect(close).toHaveBeenCalled();
    expect(window.location.search).toBe('?tab=valuations&asOf=2026-03-10');
  });
});
