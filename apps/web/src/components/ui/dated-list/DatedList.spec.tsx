import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { datedListLoaded } from '@/components/ui/dated-list/datedListLoaded';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { DatedCell } from './DatedCell';
import { DatedList } from './DatedList';
import { DatedRow } from './DatedRow';
import { DatedRowCheckbox } from './DatedRowCheckbox';
import type { DatedListSelection, RenderDatedRow } from './datedListTypes';

interface Entry {
  id: string;
  day: string;
  label: string;
  value: string | null;
  muted?: boolean;
}

const entries: Entry[] = [
  { id: 'a', day: '2026-09-11', label: 'Alpha', value: '10,00 €' },
  { id: 'b', day: '2026-09-11', label: 'Bravo', value: null },
  { id: 'c', day: '2026-09-10', label: 'Charlie', value: '3,00 €', muted: true },
];

function listProps(extra: { renderRow?: RenderDatedRow<Entry> } = {}) {
  return {
    dayLabel: (day: string) => `Jour ${day}`,
    getDay: (entry: Entry) => entry.day,
    getKey: (entry: Entry) => entry.id,
    label: 'Mouvements',
    loadingLabel: 'Chargement',
    nextLoadedLabel: 'Suivants',
    previousLoadedLabel: 'Précédents',
    renderRow: extra.renderRow ?? defaultRow(),
  };
}

function defaultRow(
  onOpen: (entry: Entry) => void = () => {},
  extras: (entry: Entry) => { aside?: ReactNode } = () => ({}),
): RenderDatedRow<Entry> {
  return (entry, { selection }) => (
    <DatedRow
      aside={entry ? extras(entry).aside : undefined}
      cells={<DatedCell column={1}>{entry ? `Compte ${entry.id}` : <SkeletonBlock />}</DatedCell>}
      leading={
        selection ? (
          <DatedRowCheckbox
            checked={selection.selected}
            disabled={selection.disabled}
            label={entry ? `Sélectionner ${entry.label}` : 'Sélection en chargement'}
            onToggle={selection.toggle}
          />
        ) : undefined
      }
      menu={<button type="button">{entry ? `Menu ${entry.label}` : 'Menu'}</button>}
      meta={<small>{entry ? `Note ${entry.id}` : <SkeletonBlock />}</small>}
      onOpen={entry ? () => onOpen(entry) : undefined}
      state={
        entry?.muted ? { cue: <span aria-label="Annulée" role="img" />, tone: 'muted' } : undefined
      }
      title={
        entry ? (
          <button onClick={() => onOpen(entry)} type="button">
            {entry.label}
          </button>
        ) : (
          <button disabled type="button">
            <SkeletonBlock />
          </button>
        )
      }
      value={entry ? entry.value : <SkeletonBlock />}
    />
  );
}

describe('DatedList', () => {
  afterEach(cleanup);

  // Keep this test first: the windowing loads once per file, so only the first render shows the fallback.
  it('shows the placeholder rows while the windowing loads, then the real rows', async () => {
    render(<DatedList {...listProps()} items={entries} />);

    expect(screen.getByTestId('dated-list-skeleton').getAttribute('aria-busy')).toBe('true');
    expect(screen.queryByText('Alpha')).toBeNull();
    await datedListLoaded();
    expect(screen.getByText('Alpha')).toBeTruthy();
    expect(screen.queryByTestId('dated-list-skeleton')).toBeNull();
  });

  it('groups rows under one heading per day, in order, one card per day', async () => {
    render(<DatedList {...listProps()} items={entries} />);
    await datedListLoaded();

    const headings = screen.getAllByRole('heading').map((heading) => heading.textContent);
    expect(headings).toEqual(['Jour 2026-09-11', 'Jour 2026-09-10']);
    const days = within(screen.getByRole('list', { name: 'Mouvements' })).getAllByRole('listitem');
    const first = days[0]!;
    expect(within(first).getByText('Alpha')).toBeTruthy();
    expect(within(first).getByText('Bravo')).toBeTruthy();
    expect(within(first).queryByText('Charlie')).toBeNull();
  });

  it('uses the requested heading level', async () => {
    render(<DatedList {...listProps()} headingLevel={4} items={entries} />);
    await datedListLoaded();

    expect(screen.getAllByRole('heading', { level: 4 })).toHaveLength(2);
  });

  it('shows title, meta, value, cells and menu of a row, and the aside slot', async () => {
    render(
      <DatedList
        {...listProps({ renderRow: defaultRow(undefined, () => ({ aside: 'Extra 5' })) })}
        items={entries}
      />,
    );
    await datedListLoaded();

    const row = screen.getByText('Alpha').closest('li')!;
    expect(within(row).getByText('Note a')).toBeTruthy();
    expect(within(row).getByText('10,00 €')).toBeTruthy();
    expect(within(row).getByText('Compte a')).toBeTruthy();
    expect(within(row).getByText('Extra 5')).toBeTruthy();
    expect(within(row).getByRole('button', { name: 'Menu Alpha' })).toBeTruthy();
  });

  it('shows the shared empty value, never zero, for a value that cannot be computed', async () => {
    render(<DatedList {...listProps()} items={entries} />);
    await datedListLoaded();

    const row = screen.getByText('Bravo').closest('li')!;
    expect(within(row).getByText('Non calculable')).toBeTruthy();
    expect(row.textContent).not.toMatch(/0,00/);
  });

  it('flags a row that keeps its menu on a phone', async () => {
    render(
      <DatedList
        {...listProps({
          renderRow: (entry) => (
            <DatedRow
              menu={<button type="button">Menu</button>}
              menuOnPhone
              title={entry?.label}
              value="1"
            />
          ),
        })}
        items={entries}
      />,
    );
    await datedListLoaded();

    expect(screen.getByText('Alpha').closest('li')!.getAttribute('data-menu-phone')).toBe('true');
  });

  it('opens the detail from anywhere on the row but not from its own controls', async () => {
    const open = vi.fn();
    render(<DatedList {...listProps({ renderRow: defaultRow(open) })} items={entries} />);
    await datedListLoaded();

    fireEvent.click(screen.getByText('Compte a'));
    expect(open).toHaveBeenCalledTimes(1);
    expect(open).toHaveBeenCalledWith(expect.objectContaining({ id: 'a' }));
    open.mockClear();
    fireEvent.click(screen.getByRole('button', { name: 'Menu Alpha' }));
    expect(open).not.toHaveBeenCalled();
    expect(screen.getByText('Compte a').closest('li')!.getAttribute('data-clickable')).toBe('true');
  });

  it('opens the detail from the label button and puts focus back on it after the dialog closes', async () => {
    function Harness() {
      const [open, setOpen] = useState(false);
      return (
        <>
          <DatedList
            {...listProps({
              renderRow: defaultRow(() => setOpen(true)),
            })}
            items={entries}
          />
          {open ? (
            <button onClick={() => setOpen(false)} type="button">
              Fermer
            </button>
          ) : null}
        </>
      );
    }
    render(<Harness />);
    await datedListLoaded();

    const label = screen.getByRole('button', { name: 'Alpha' });
    act(() => label.focus());
    fireEvent.click(label);
    fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));
    expect(screen.queryByRole('button', { name: 'Fermer' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Alpha' })).toBe(label);
  });

  it('orders the tab stops checkbox, label, menu when the list has a selection', async () => {
    const toggle = vi.fn();
    const selection: DatedListSelection = { onToggle: toggle, selectedIds: new Set(['b']) };
    render(<DatedList {...listProps()} items={entries} selection={selection} />);
    await datedListLoaded();

    const row = screen.getByText('Alpha').closest('li')!;
    const stops = within(row)
      .getAllByRole('button')
      .map((button) => button.textContent);
    const checkbox = within(row).getByRole('checkbox', { name: 'Sélectionner Alpha' });
    expect(row.querySelector('input, button')).toBe(checkbox);
    expect(stops).toEqual(['Alpha', 'Menu Alpha']);
    expect((checkbox as HTMLInputElement).checked).toBe(false);
    fireEvent.click(checkbox);
    expect(toggle).toHaveBeenCalledWith('a');
    expect(
      (screen.getByRole('checkbox', { name: 'Sélectionner Bravo' }) as HTMLInputElement).checked,
    ).toBe(true);
  });

  it('greys a muted row by colour class and keeps its text cue, never an opacity', async () => {
    render(<DatedList {...listProps()} items={entries} />);
    await datedListLoaded();

    const muted = screen.getByText('Charlie').closest('li')!;
    const normal = screen.getByText('Alpha').closest('li')!;
    expect(muted.className).toContain('muted');
    expect(within(muted).getByRole('img', { name: 'Annulée' })).toBeTruthy();
    expect(muted.getAttribute('style')).toBeNull();
    expect(normal.className).not.toContain('muted');
  });

  it('mirrors the loaded markup while loading, with no value and one announcement', async () => {
    const { container, rerender } = render(<DatedList {...listProps()} items={entries} />);
    await datedListLoaded();
    const signature = (element: Element): string => {
      const own = `${element.tagName.toLowerCase()}.${element.getAttribute('class') ?? ''}`;
      const children = Array.from(element.children)
        .filter((child) => !child.hasAttribute('data-skeleton'))
        .map(signature);
      return children.length === 0 ? own : `${own}(${children.join(',')})`;
    };
    const loaded = signature(container.querySelector('ol > li > div > ul > li') as Element);
    rerender(<DatedList {...listProps()} items={null} />);
    const loading = signature(container.querySelector('ol > li > div > ul > li') as Element);

    expect(loading.replace(/\bmuted\b/g, '')).toBe(loaded.replace(/\bmuted\b/g, ''));
    expect(screen.getByTestId('dated-list-skeleton').getAttribute('aria-busy')).toBe('true');
    expect(screen.queryByText('10,00 €')).toBeNull();
    expect(screen.queryByText('Alpha')).toBeNull();
    expect(screen.getAllByText('Chargement')).toHaveLength(1);
    expect(container.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
  });

  it('draws a disabled checkbox on placeholder rows and can skip the announcement', () => {
    const selection: DatedListSelection = { onToggle: () => {}, selectedIds: new Set() };
    render(
      <DatedList
        {...listProps()}
        announce={false}
        items={null}
        selection={selection}
        skeletonDays={[2]}
      />,
    );

    const boxes = screen.getAllByRole('checkbox', { name: 'Sélection en chargement' });
    expect(boxes).toHaveLength(2);
    expect((boxes[0] as HTMLInputElement).disabled).toBe(true);
    expect(screen.queryByText('Chargement')).toBeNull();
  });

  it('bounds the mounted rows of a long day and reveals the rest by keyboard controls', async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    const many = Array.from({ length: 1000 }, (_, index) => ({
      id: `n-${index}`,
      day: '2026-09-11',
      label: `Ligne ${index}`,
      value: '1,00 €',
    }));
    const { container } = render(<DatedList {...listProps()} items={many} />);
    await datedListLoaded();

    expect(container.querySelectorAll('li[data-clickable], li[class*="row"]').length).toBeLessThan(
      200,
    );
    expect(screen.getByText('Ligne 0')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Suivants' }));
    expect(document.activeElement?.closest('li')?.textContent).toMatch(/Ligne /);
  });
});
