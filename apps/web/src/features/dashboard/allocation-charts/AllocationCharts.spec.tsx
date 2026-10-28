import type { NetWorthAllocationEntry } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AllocationCharts } from './AllocationCharts';
import { AllocationLegend } from '@/features/dashboard/allocation-panel/AllocationLegend';

vi.mock('@/hooks/use-box-size', () => ({
  useBoxSize: () => ({ ref: () => undefined, size: { width: 400, height: 200 } }),
}));
vi.mock('recharts', () => ({
  Treemap: ({
    content: Tile,
    data,
  }: {
    content: React.ComponentType<Record<string, unknown>>;
    data: Record<string, unknown>[];
  }) => (
    <svg>
      {data.map((entry, index) => (
        <Tile
          key={String(entry.groupId)}
          {...entry}
          payload={entry}
          depth={1}
          height={100}
          width={200}
          x={index * 200}
          y={0}
          index={index}
        />
      ))}
    </svg>
  ),
}));
vi.mock('@/components/ui/charts/donut-chart/DonutChart', () => ({
  DonutChart: ({ slices }: { slices: { key: string; fill: string }[] }) => (
    <svg data-testid="donut">
      {slices.map((slice) => (
        <circle key={slice.key} fill={slice.fill} />
      ))}
    </svg>
  ),
}));

function entry(groupId: string, color: string): NetWorthAllocationEntry {
  return {
    groupId,
    color,
    label: groupId,
    parentId: null,
    depth: 1,
    value: null,
    share: { ratio: '0.5', percent: '50', percentDisplay: '50', reason: null },
    directValue: null,
    directShare: { ratio: null, percent: null, percentDisplay: null, reason: null },
    directPreviousValue: null,
    hasChildren: false,
  };
}

afterEach(cleanup);
describe('account group allocation colors', () => {
  it('keeps the same color after reorder/filter and contrasts labels on black and white', () => {
    const dark = entry('Noir', '#000000');
    const light = entry('Blanc', '#FFFFFF');
    const { container, rerender } = render(
      <AllocationCharts allocation={[dark, light]} reason={null} />,
    );
    expect(
      [...container.querySelectorAll('rect')].map((node) => node.getAttribute('fill')),
    ).toEqual(['#000000', '#FFFFFF']);
    expect(
      [...container.querySelectorAll('text')].map((node) => node.getAttribute('fill')),
    ).toEqual(['#FFFFFF', '#000000']);
    rerender(<AllocationCharts allocation={[light, dark]} reason={null} />);
    expect(
      [...container.querySelectorAll('rect')].map((node) => node.getAttribute('fill')),
    ).toEqual(['#FFFFFF', '#000000']);
    rerender(<AllocationCharts allocation={[light]} reason={null} />);
    expect(container.querySelector('rect')?.getAttribute('fill')).toBe('#FFFFFF');
    fireEvent.click(screen.getByRole('button', { name: 'Camembert' }));
    expect(screen.getByTestId('donut').querySelector('circle')?.getAttribute('fill')).toBe(
      '#FFFFFF',
    );
  });
  it('renders matching legend markers/bars without coloring the label or amount', () => {
    const { container } = render(
      <AllocationLegend
        allocation={[entry('Noir', '#000000'), entry('Blanc', '#FFFFFF')]}
        reason={null}
      />,
    );
    expect(
      [...container.querySelectorAll('rect')].map((node) => node.getAttribute('fill')),
    ).toEqual(['#000000', '#000000', '#FFFFFF', '#FFFFFF']);
    expect(screen.getByText('Noir').hasAttribute('style')).toBe(false);
    expect(screen.getByText('Blanc').hasAttribute('style')).toBe(false);
  });
});
