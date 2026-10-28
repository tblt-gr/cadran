import { describe, expect, it } from 'vitest';
import { groupLabelParts } from './groupingMode';

const groups = new Map([
  ['inv', { label: 'Investissement', parentId: null }],
  ['bourse', { label: 'Bourse', parentId: 'inv' }],
  ['pea', { label: 'PEA', parentId: 'bourse' }],
]);

describe('groupLabelParts', () => {
  it('returns the name and the immediate parent only', () => {
    expect(groupLabelParts('pea', groups)).toEqual({ label: 'PEA', parentLabel: 'Bourse' });
  });
  it('has no parent label for a root', () => {
    expect(groupLabelParts('inv', groups)).toEqual({ label: 'Investissement', parentLabel: null });
  });
});
