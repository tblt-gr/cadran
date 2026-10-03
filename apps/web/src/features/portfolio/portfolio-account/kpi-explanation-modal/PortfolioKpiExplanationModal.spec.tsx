import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { afterEach, describe, it, expect, vi } from 'vitest';
import '@/i18n';
import { PortfolioKpiExplanationModal } from './PortfolioKpiExplanationModal';
import { summaryFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
afterEach(cleanup);
describe('PortfolioKpiExplanationModal', () => {
  it('translates a known valuation warning used as explanation quality and reason', () => {
    render(
      <PortfolioKpiExplanationModal
        code="RECONCILIATION"
        explanation={{
          ...summaryFixture().explanations.RECONCILIATION,
          quality: 'STALE_PRICES',
          reason: 'CASH_DIFFERENCE',
        }}
        onClose={vi.fn()}
        onSource={vi.fn()}
      />,
    );
    expect(
      screen.getByText('Au moins un prix date de plus de 5 jours avant la valorisation.'),
    ).toBeTruthy();
    expect(
      screen.getByText('Les espèces constatées diffèrent des espèces calculées.'),
    ).toBeTruthy();
    expect(screen.queryByText('STALE_PRICES')).toBeNull();
    expect(screen.queryByText('CASH_DIFFERENCE')).toBeNull();
  });

  it('shows formula, period, quality and opens its source', () => {
    const source = vi.fn();
    render(
      <PortfolioKpiExplanationModal
        code="TOTAL_VALUE"
        explanation={{ ...summaryFixture().explanations.TOTAL_VALUE, truncatedSourceCount: 2 }}
        onClose={vi.fn()}
        onSource={source}
      />,
    );
    expect(screen.getByRole('dialog', { name: 'Expliquer : Valeur totale' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Formule' })).toBeTruthy();
    expect(screen.getByText(/Valeur totale = espèces/)).toBeTruthy();
    expect(screen.getByText('et 2 autres')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Valorisation du 2026-03-10' }));
    expect(source).toHaveBeenCalledWith({
      kind: 'VALUATION',
      id: 'valuation-1',
      date: '2026-03-10',
    });
  });
  it('closes with Escape for keyboard users', () => {
    const close = vi.fn();
    render(
      <PortfolioKpiExplanationModal
        code="CASH"
        explanation={summaryFixture().explanations.CASH}
        onClose={close}
        onSource={vi.fn()}
      />,
    );
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(close).toHaveBeenCalled();
  });
});
