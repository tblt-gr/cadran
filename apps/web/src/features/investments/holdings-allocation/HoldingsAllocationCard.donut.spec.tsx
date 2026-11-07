import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { holdingsFixture } from '@/features/investments/holdingsFixture';
import { HoldingsAllocationCard } from './HoldingsAllocationCard';

const plot = vi.hoisted(() => ({ props: null as null | Record<string, unknown> }));
vi.mock('@/components/ui/charts/donut-chart/DonutPlot', () => ({
  DonutPlot: (props: Record<string, unknown>) => {
    plot.props = props;
    return null;
  },
}));

afterEach(cleanup);

describe('HoldingsAllocationCard donut centre', () => {
  it('gives the shared donut the total securities value for its centre', () => {
    render(<HoldingsAllocationCard holdings={holdingsFixture()} />);
    expect(plot.props?.totalText).toMatch(/^2\s510,00\s€$/);
  });

  it('gives each slice an amount and a captioned share for the hover centre', () => {
    render(<HoldingsAllocationCard holdings={holdingsFixture()} />);
    const slices = plot.props?.slices as { amountText: string; caption: string }[];
    expect(slices[0]!.caption).toMatch(/^Actions · 100/);
    expect(slices[0]!.amountText).toMatch(/2\s510,00/);
  });

  it('leaves the centre empty, never zero, when the total is not calculable', () => {
    const base = holdingsFixture();
    render(
      <HoldingsAllocationCard
        holdings={{ ...base, totals: { ...base.totals, securitiesValue: null } }}
      />,
    );
    expect(plot.props?.totalText).toBeNull();
  });
});
