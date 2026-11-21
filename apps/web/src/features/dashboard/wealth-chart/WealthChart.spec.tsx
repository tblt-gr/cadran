import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { setAmountsHidden } from '@/lib/amountPrivacy';
import { WealthChart } from './WealthChart';

vi.mock('@/components/ui/charts/curve-chart/CurveChart', () => ({
  CurveChart: ({ points }: { points: { geometryValue: string | null }[] }) => (
    <div data-testid="chart-frame">
      {points.some((point) => point.geometryValue !== null) ? (
        <div data-testid="curve-geometry" />
      ) : null}
    </div>
  ),
}));
afterEach(() => {
  cleanup();
  setAmountsHidden(false);
});

it('omits geometry and hit targets while keeping dates and a masked table', () => {
  const points = [
    {
      on: '2026-01-01',
      quality: 'CURRENT' as const,
      reason: null,
      total: {
        value: '999999999999999999999.123456789',
        assetCode: 'EUR',
        display: { value: '12.34', assetCode: 'EUR' },
        belowDisplayStep: false,
      },
    },
  ];
  setAmountsHidden(true);
  const view = render(<WealthChart points={points} />);
  expect(screen.queryByTestId('curve-geometry')).toBeNull();
  expect(screen.getByText('1 janvier 2026')).toBeTruthy();
  expect(screen.getByText('Montant masqué')).toBeTruthy();
  expect(view.container.textContent).not.toContain('999999');
  setAmountsHidden(false);
  view.rerender(<WealthChart points={points} />);
  expect(screen.getByTestId('curve-geometry')).toBeTruthy();
  expect(view.container.textContent).toContain('12,34');
});
