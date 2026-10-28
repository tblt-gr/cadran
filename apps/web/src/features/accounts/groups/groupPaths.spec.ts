import { describe, expect, it } from 'vitest';
import { GROUP_PATH_SEPARATOR, orderGroupsDepthFirst } from './groupPaths';

const group = (id: string, label: string, parentId: string | null, sortOrder = 0) => ({
  id,
  label,
  parentId,
  sortOrder,
});

describe('orderGroupsDepthFirst', () => {
  it('lists each group right after its parent, siblings in saved order', () => {
    const ordered = orderGroupsDepthFirst([
      group('bourse', 'Bourse', 'inv', 20),
      group('cash', 'Liquidités', null, 20),
      group('immo', 'Immobilier', 'inv', 10),
      group('inv', 'Investissement', null, 10),
      group('pea', 'PEA', 'bourse', 0),
    ]);

    expect(ordered.map((entry) => [entry.group.id, entry.depth])).toEqual([
      ['inv', 1],
      ['immo', 2],
      ['bourse', 2],
      ['pea', 3],
      ['cash', 1],
    ]);
  });

  it('exposes the immediate parent label, null for a root', () => {
    const ordered = orderGroupsDepthFirst([
      group('inv', 'Investissement', null),
      group('bourse', 'Bourse', 'inv'),
      group('pea', 'PEA', 'bourse'),
    ]);

    expect(ordered.map((entry) => entry.parentLabel)).toEqual([null, 'Investissement', 'Bourse']);
  });

  it('exposes the full path of each group', () => {
    const ordered = orderGroupsDepthFirst([
      group('inv', 'Investissement', null),
      group('bourse', 'Bourse', 'inv'),
    ]);

    expect(ordered.map((entry) => entry.path)).toEqual([
      'Investissement',
      `Investissement${GROUP_PATH_SEPARATOR}Bourse`,
    ]);
    expect(GROUP_PATH_SEPARATOR).toBe(' › ');
  });

  it('breaks sort order ties on label then id, and treats a missing parent as a root', () => {
    const ordered = orderGroupsDepthFirst([
      group('b', 'beta', null),
      group('a2', 'Alpha', null),
      group('a1', 'alpha', null),
      group('orphan', 'Orphan', 'gone', 5),
    ]);

    expect(ordered.map((entry) => entry.group.id)).toEqual(['a1', 'a2', 'b', 'orphan']);
    expect(ordered.at(-1)?.depth).toBe(1);
  });
});
