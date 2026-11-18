import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { DatedRow } from './DatedRow';
import { DatedRowList } from './DatedRowList';

afterEach(cleanup);

const rows = [
  { id: 'a', label: 'Alpha', value: '10,00 €' },
  { id: 'b', label: 'Bravo', value: null },
];

function list(items: typeof rows | null) {
  return (
    <DatedRowList
      getKey={(row) => row.id}
      items={items}
      label="Lignes"
      loadingLabel="Chargement"
      renderRow={(row) => (
        <DatedRow
          title={row ? <span>{row.label}</span> : <SkeletonBlock />}
          value={row ? row.value : <SkeletonBlock />}
        />
      )}
      skeletonRows={2}
    />
  );
}

describe('DatedRowList', () => {
  it('renders every row in one card without day headings', () => {
    render(list(rows));

    const rowList = screen.getByRole('list', { name: 'Lignes' });
    expect(within(rowList).getAllByRole('listitem')).toHaveLength(2);
    expect(screen.queryByRole('heading')).toBeNull();
    expect(within(rowList).getByText('10,00 €')).toBeTruthy();
    expect(within(rowList).getByText('Non calculable')).toBeTruthy();
  });

  it('mirrors the rows with placeholders and announces the loading once', () => {
    render(list(null));

    expect(screen.getByTestId('dated-row-list-skeleton').getAttribute('aria-busy')).toBe('true');
    expect(screen.getAllByRole('listitem')).toHaveLength(2);
    expect(screen.getAllByText('Chargement')).toHaveLength(1);
  });
});
