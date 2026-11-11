import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { IncrementalList } from './IncrementalList';

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
const query = () => ({
  resetKey: 'list',
  hasNextPage: true,
  isFetching: false,
  isError: false,
  stale: false,
  loadMore: vi.fn(),
  restart: vi.fn(),
});
it('loads once per approach, preserves focus and leaves errors to explicit retry', () => {
  let notify: IntersectionObserverCallback | undefined;
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      constructor(callback: IntersectionObserverCallback) {
        notify = callback;
      }
      observe() {}
      disconnect() {}
    },
  );
  const state = query();
  const children = (items: number[]) => (
    <ul>
      {items.map((id) => (
        <li key={id}>{id}</li>
      ))}
    </ul>
  );
  const { rerender } = render(
    <IncrementalList items={[1]} query={state}>
      {children}
    </IncrementalList>,
  );
  const intersect = (visible: boolean) =>
    act(() =>
      notify?.(
        [{ isIntersecting: visible } as IntersectionObserverEntry],
        {} as IntersectionObserver,
      ),
    );
  const button = screen.getByRole('button', { name: 'Afficher plus' });
  button.focus();
  intersect(true);
  intersect(true);
  rerender(
    <IncrementalList items={[1, 2]} query={state}>
      {children}
    </IncrementalList>,
  );
  intersect(true);
  expect(state.loadMore).toHaveBeenCalledTimes(1);
  expect(document.activeElement).toBe(button);
  rerender(
    <IncrementalList items={[1, 2]} query={{ ...state, isError: true }}>
      {children}
    </IncrementalList>,
  );
  intersect(false);
  intersect(true);
  expect(state.loadMore).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
  expect(state.loadMore).toHaveBeenCalledTimes(2);
});
it('renders at most 200 rows and keeps all loaded rows reachable', () => {
  const items = Array.from({ length: 451 }, (_, index) => index + 1);
  const state = { ...query(), hasNextPage: false };
  render(
    <IncrementalList items={items} query={state}>
      {(rows) => (
        <ul>
          {rows.map((id) => (
            <li key={id}>{id}</li>
          ))}
        </ul>
      )}
    </IncrementalList>,
  );
  expect(screen.getAllByRole('listitem')).toHaveLength(200);
  fireEvent.click(screen.getByRole('button', { name: 'Éléments suivants déjà chargés' }));
  expect(screen.getAllByRole('listitem')[0]?.textContent).toBe('201');
  fireEvent.click(screen.getByRole('button', { name: 'Éléments suivants déjà chargés' }));
  expect(screen.getAllByRole('listitem')).toHaveLength(51);
  fireEvent.click(screen.getByRole('button', { name: 'Éléments précédents déjà chargés' }));
  expect(screen.getAllByRole('listitem')[0]?.textContent).toBe('201');
});

it('retains keyboard focus when either loaded-window control disappears at its boundary', () => {
  const items = Array.from({ length: 451 }, (_, index) => index + 1);
  render(
    <IncrementalList items={items} query={{ ...query(), hasNextPage: false }}>
      {(rows) => (
        <ul>
          {rows.map((id) => (
            <li key={id}>{id}</li>
          ))}
        </ul>
      )}
    </IncrementalList>,
  );
  const next = screen.getByRole('button', { name: 'Éléments suivants déjà chargés' });
  const footer = next.parentElement!;
  next.focus();
  fireEvent.click(next);
  expect(document.activeElement).toBe(next);
  fireEvent.click(next);
  expect(screen.queryByRole('button', { name: 'Éléments suivants déjà chargés' })).toBeNull();
  expect(document.activeElement).toBe(footer);

  const previous = screen.getByRole('button', { name: 'Éléments précédents déjà chargés' });
  previous.focus();
  fireEvent.click(previous);
  expect(document.activeElement).toBe(previous);
  fireEvent.click(previous);
  expect(screen.queryByRole('button', { name: 'Éléments précédents déjà chargés' })).toBeNull();
  expect(document.activeElement).toBe(footer);
  expect(screen.getAllByRole('listitem')[0]?.textContent).toBe('1');
});

it('keeps every loaded window reachable while a stale list waits for explicit restart', () => {
  const items = Array.from({ length: 451 }, (_, index) => index + 1);
  const state = { ...query(), stale: true, isError: true };
  render(
    <IncrementalList items={items} query={state}>
      {(rows) => (
        <ul>
          {rows.map((id) => (
            <li key={id}>{id}</li>
          ))}
        </ul>
      )}
    </IncrementalList>,
  );
  expect(screen.getByRole('alert').textContent).toContain('La liste a changé');
  expect(screen.queryByRole('button', { name: 'Afficher plus' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Éléments suivants déjà chargés' }));
  fireEvent.click(screen.getByRole('button', { name: 'Éléments suivants déjà chargés' }));
  expect(screen.getAllByRole('listitem')[0]?.textContent).toBe('401');
  fireEvent.click(screen.getByRole('button', { name: 'Éléments précédents déjà chargés' }));
  fireEvent.click(screen.getByRole('button', { name: 'Éléments précédents déjà chargés' }));
  expect(screen.getAllByRole('listitem')[0]?.textContent).toBe('1');
  expect(state.loadMore).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Recharger la liste' }));
  expect(state.restart).toHaveBeenCalledOnce();
});

it('keeps the footer height and keyboard focus when the final page replaces retry', () => {
  const state = { ...query(), isError: true };
  const children = (rows: number[]) => (
    <ul>
      {rows.map((id) => (
        <li key={id}>{id}</li>
      ))}
    </ul>
  );
  const { rerender } = render(
    <IncrementalList items={[1]} query={state}>
      {children}
    </IncrementalList>,
  );
  const retry = screen.getByRole('button', { name: 'Réessayer' });
  const footer = retry.parentElement!;
  Object.defineProperty(footer, 'getBoundingClientRect', { value: () => ({ height: 120 }) });
  retry.focus();
  rerender(
    <IncrementalList items={[1]} query={{ ...state, isFetching: true }}>
      {children}
    </IncrementalList>,
  );
  rerender(
    <IncrementalList items={[1, 2]} query={{ ...state, isError: false, hasNextPage: false }}>
      {children}
    </IncrementalList>,
  );
  expect(screen.getByText('Tous les éléments sont affichés.')).toBeTruthy();
  expect(footer.style.minHeight).toBe('120px');
  expect(document.activeElement).toBe(footer);
  expect(screen.queryByRole('button')).toBeNull();
});
it('has no footer for an empty or single-page list and offers explicit stale restart', () => {
  const state = { ...query(), hasNextPage: false };
  const { rerender } = render(
    <IncrementalList items={[]} query={state}>
      {() => <p>Vide</p>}
    </IncrementalList>,
  );
  expect(screen.queryByRole('button')).toBeNull();
  rerender(
    <IncrementalList items={[1]} query={state}>
      {() => <p>Une ligne</p>}
    </IncrementalList>,
  );
  expect(screen.queryByRole('button')).toBeNull();
  rerender(
    <IncrementalList items={[1]} query={{ ...state, stale: true }}>
      {() => <p>Une ligne</p>}
    </IncrementalList>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Recharger la liste' }));
  expect(state.restart).toHaveBeenCalledOnce();
});
