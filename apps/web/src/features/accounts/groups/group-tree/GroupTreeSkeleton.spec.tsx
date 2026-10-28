import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { GroupTreeSkeleton } from './GroupTreeSkeleton';

describe('GroupTreeSkeleton', () => {
  afterEach(cleanup);

  it('draws the named tree with one status and no value', () => {
    render(<GroupTreeSkeleton status="Chargement des groupes…" />);

    expect(screen.getAllByRole('status')).toHaveLength(1);
    const tree = screen.getByRole('list', { name: 'Groupes de comptes' });
    expect(tree.querySelectorAll(':scope > li')).toHaveLength(6);
    expect(tree.querySelector('table, th, td')).toBeNull();
    expect(tree.textContent).toBe('');
  });
});
