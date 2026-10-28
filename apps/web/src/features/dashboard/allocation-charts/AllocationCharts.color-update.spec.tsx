import type { NetWorthAllocationEntry } from '@cadran/api-client';
import { cleanup, render } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { AllocationCharts } from './AllocationCharts';

vi.mock('@/hooks/use-box-size', () => ({
  useBoxSize: () => ({ ref: () => undefined, size: { width: 400, height: 200 } }),
}));
afterEach(cleanup);

it('updates the live Recharts tile and label when only the group color changes', () => {
  const entry: NetWorthAllocationEntry = {
    groupId: 'group-1',
    color: '#000000',
    label: 'Épargne',
    parentId: null,
    depth: 1,
    value: null,
    share: { ratio: '1', percent: '100', percentDisplay: '100', reason: null },
    directValue: null,
    directShare: { ratio: null, percent: null, percentDisplay: null, reason: null },
    directPreviousValue: null,
    hasChildren: false,
  };
  const { container, rerender } = render(<AllocationCharts allocation={[entry]} reason={null} />);
  const tile = () => container.querySelector('rect[fill="#000000"], rect[fill="#FFFFFF"]');
  expect(tile()?.getAttribute('fill')).toBe('#000000');
  expect(container.querySelector('text')?.getAttribute('fill')).toBe('#FFFFFF');
  rerender(<AllocationCharts allocation={[{ ...entry, color: '#FFFFFF' }]} reason={null} />);
  expect(tile()?.getAttribute('fill')).toBe('#FFFFFF');
  expect(container.querySelector('text')?.getAttribute('fill')).toBe('#000000');
});
