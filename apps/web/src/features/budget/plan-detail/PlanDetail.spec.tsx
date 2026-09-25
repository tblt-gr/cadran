import type { BudgetPlanDetail, BudgetTargetDetail } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PlanDetail } from './PlanDetail';

vi.mock('@/features/budget/budget-comparisons/BudgetComparisonsPanel', () => ({
  BudgetComparisonsPanel: ({ planId }: { planId: string }) => (
    <div data-testid="comparisons-panel">{planId}</div>
  ),
}));

afterEach(() => {
  cleanup();
});

const detail = (overrides: Partial<BudgetPlanDetail> = {}): BudgetPlanDetail =>
  ({
    id: '00000000-0000-7000-8000-000000000001',
    periodType: 'MONTH',
    period: '2026-03',
    assetCode: 'EUR',
    state: 'DRAFT',
    version: 1,
    targets: [],
    ...overrides,
  }) as BudgetPlanDetail;

const target = (overrides: Partial<BudgetTargetDetail> = {}): BudgetTargetDetail =>
  ({
    id: '00000000-0000-7000-8000-000000000002',
    scopeType: 'AXIS',
    scopeId: 'ESSENTIAL',
    scopeLabel: 'Essential',
    valueType: 'AMOUNT',
    storedAmount: '400.00',
    storedRatio: null,
    resolvedAmount: '400.00',
    nonCalculableReason: null,
    overlapping: false,
    version: 1,
    ...overrides,
  }) as BudgetTargetDetail;

function renderPlan(props: Partial<Parameters<typeof PlanDetail>[0]> = {}) {
  const handlers = {
    onEditPlan: vi.fn(),
    onEditTarget: vi.fn(),
    onNewTarget: vi.fn(),
    onLifecycle: vi.fn(),
  };

  render(
    <PlanDetail
      detail={detail()}
      language="fr"
      lifecycleError={null}
      lifecyclePending={false}
      onEditPlan={handlers.onEditPlan}
      onEditTarget={handlers.onEditTarget}
      onLifecycle={handlers.onLifecycle}
      onNewTarget={handlers.onNewTarget}
      {...props}
    />,
  );

  return handlers;
}

describe('PlanDetail', () => {
  it('shows the currency, the state and an empty-targets message with no targets', () => {
    renderPlan();

    expect(screen.getByText('EUR')).toBeTruthy();
    expect(screen.getByText('Brouillon')).toBeTruthy();
    expect(screen.getByText('Ajoutez un objectif avant d’activer ce plan.')).toBeTruthy();
  });

  it('disables activation and links the hint while a draft has no targets', () => {
    renderPlan();

    const activate = screen.getByRole('button', { name: 'Activer' });
    expect(activate.hasAttribute('disabled')).toBe(true);
    const hintId = activate.getAttribute('aria-describedby');
    expect(hintId).toBeTruthy();
    expect(document.getElementById(hintId!)?.textContent).toBe(
      'Ajoutez un objectif avant d’activer ce plan.',
    );
  });

  it('enables activation once a draft has at least one target', () => {
    renderPlan({ detail: detail({ targets: [target()] }) });

    const activate = screen.getByRole('button', { name: 'Activer' });
    expect(activate.hasAttribute('disabled')).toBe(false);
  });

  it('calls onEditPlan from the draft summary action', () => {
    // No target row, so the summary's own "Modifier" is the only one on the
    // page (a target row offers the same-labelled action once it exists).
    const handlers = renderPlan({ detail: detail({ targets: [] }) });

    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    expect(handlers.onEditPlan).toHaveBeenCalledTimes(1);
  });

  it('calls onLifecycle("activate") once a draft has a target to activate with', () => {
    const handlers = renderPlan({ detail: detail({ targets: [target()] }) });

    fireEvent.click(screen.getByRole('button', { name: 'Activer' }));
    expect(handlers.onLifecycle).toHaveBeenCalledWith('activate');
  });

  it('offers only the close action for an active plan, never the draft ones', () => {
    const handlers = renderPlan({ detail: detail({ state: 'ACTIVE', targets: [] }) });

    expect(screen.queryByRole('button', { name: 'Activer' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Clôturer' }));
    expect(handlers.onLifecycle).toHaveBeenCalledWith('close');
  });

  it('offers no lifecycle action, no target creation and no per-row edit for a closed plan', () => {
    renderPlan({ detail: detail({ state: 'CLOSED', targets: [target()] }) });

    expect(screen.queryByRole('button', { name: 'Modifier' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Activer' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Clôturer' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Ajouter un objectif' })).toBeNull();
  });

  it('shows the lifecycle error as an alert when the mutation failed', () => {
    renderPlan({ lifecycleError: 'network' });

    expect(screen.getByRole('alert').textContent).toContain('échoué');
  });

  it('renders a target row with its amount, resolved value and no warning', () => {
    renderPlan({ detail: detail({ targets: [target()] }) });

    const row = screen.getByRole('row', { name: /Essential/ });
    expect(row.textContent).toContain('400,00');
    expect(row.textContent).toContain('—');
  });

  it('renders a ratio target and a non-calculable resolved value with its reason', () => {
    renderPlan({
      detail: detail({
        targets: [
          target({
            valueType: 'RATIO',
            storedAmount: null,
            storedRatio: '0.30',
            resolvedAmount: null,
            nonCalculableReason: 'ZERO_CASH_INCOME',
          }),
        ],
      }),
    });

    expect(screen.getByText('30 %')).toBeTruthy();
    expect(screen.getByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Revenus de trésorerie nuls')).toBeTruthy();
  });

  it('flags an overlapping target', () => {
    renderPlan({ detail: detail({ targets: [target({ overlapping: true })] }) });

    expect(screen.getByText('Chevauchement de périmètre')).toBeTruthy();
  });

  it('calls onEditTarget with the clicked row', () => {
    // ACTIVE state: the summary offers no "Modifier" of its own, so the
    // row's edit button is the only match.
    const handlers = renderPlan({ detail: detail({ state: 'ACTIVE', targets: [target()] }) });

    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    expect(handlers.onEditTarget).toHaveBeenCalledWith(target());
  });

  it('calls onNewTarget from the targets heading', () => {
    const handlers = renderPlan({ detail: detail({ targets: [target()] }) });

    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un objectif' }));
    expect(handlers.onNewTarget).toHaveBeenCalledTimes(1);
  });

  it('mounts the comparisons panel only for a monthly plan', () => {
    renderPlan({ detail: detail({ periodType: 'MONTH' }) });
    expect(screen.getByTestId('comparisons-panel').textContent).toBe(
      '00000000-0000-7000-8000-000000000001',
    );
  });

  it('does not mount the comparisons panel for an annual plan', () => {
    renderPlan({ detail: detail({ periodType: 'YEAR', period: '2026' }) });
    expect(screen.queryByTestId('comparisons-panel')).toBeNull();
  });
});
