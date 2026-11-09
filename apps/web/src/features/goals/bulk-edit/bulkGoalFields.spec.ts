import { describe, expect, it } from 'vitest';
import type { GoalSummary } from '@cadran/api-client';
import { bulkCompatibility, bulkChanges } from './bulkGoalFields';

const goal = (type = 'AMOUNT', assetCode = 'EUR', mode?: string) =>
  ({
    assetCode,
    currentVersion: { valueType: type, schedule: mode ? { mode } : null },
  }) as GoalSummary;

describe('bulk goal fields', () => {
  it('preserves unchecked fields and clears an explicitly empty tolerance', () => {
    expect(bulkChanges(false, '900', true, '', 'AMOUNT')).toEqual({ tolerance: '0' });
    expect(bulkChanges(true, '900', false, '30', 'AMOUNT')).toEqual({ target: '900' });
    expect(bulkChanges(false, '', false, '', 'AMOUNT')).toBeNull();
  });
  it('converts percentage targets and tolerance points exactly', () => {
    expect(bulkChanges(true, '15', true, '0.1', 'RATE')).toEqual({
      target: '0.15',
      tolerance: '0.001',
    });
    expect(bulkChanges(false, '', true, '-1', 'RATE')).toBeNull();
  });
  it('blocks mixed units and preserves custom schedules with tolerance only', () => {
    expect(bulkCompatibility([goal(), goal('RATE')]).compatible).toBe(false);
    expect(bulkCompatibility([goal(), goal('AMOUNT', 'USD')]).compatible).toBe(false);
    expect(bulkCompatibility([goal('AMOUNT', 'EUR', 'CUSTOM')])).toEqual({
      compatible: true,
      targetAllowed: false,
      valueType: 'AMOUNT',
      assetCode: 'EUR',
    });
  });
});
