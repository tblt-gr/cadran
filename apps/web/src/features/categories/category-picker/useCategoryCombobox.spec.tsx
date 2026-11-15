import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { useCategoryCombobox } from './useCategoryCombobox';

const items = [
  {
    id: 'c1',
    label: 'Courses',
    type: 'EXPENSE',
    archivedAt: null,
    effectiveColor: null,
    effectiveIcon: null,
  },
  {
    id: 'c2',
    label: 'Salaire',
    type: 'INCOME',
    archivedAt: null,
    effectiveColor: null,
    effectiveIcon: null,
  },
];

vi.mock('./useCategoryCandidates', () => ({
  useCategoryCandidates: () => ({ data: { items }, isError: false }),
}));

const keyEvent = (key: string) =>
  ({ key, preventDefault: vi.fn(), stopPropagation: vi.fn() }) as never;

function setup(options: Partial<Parameters<typeof useCategoryCombobox>[0]> = {}) {
  const onChange = vi.fn();
  const hook = renderHook(() =>
    useCategoryCombobox({ onChange, parentEligible: false, value: '', ...options }),
  );
  return { ...hook, onChange };
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

it('stays closed until focused, then lists the eligible choices', () => {
  const { result } = setup();
  expect(result.current.expanded).toBe(false);
  expect(result.current.choices).toHaveLength(2);
  act(() => result.current.inputProps.onFocus());
  expect(result.current.expanded).toBe(true);
  expect(result.current.inputProps['aria-expanded']).toBe(true);
});

it('puts the preferred type first and prepends the empty option when asked', () => {
  const { result } = setup({ emptyOptionLabel: 'Aucune', preferredType: 'INCOME' });
  expect(result.current.choices.map((choice) => choice.id)).toEqual(['', 'c2', 'c1']);
});

it('moves the active option with the arrows and wraps around', () => {
  const { result } = setup();
  act(() => result.current.inputProps.onFocus());
  act(() => result.current.inputProps.onKeyDown(keyEvent('ArrowUp')));
  expect(result.current.listProps.activeIndex).toBe(1);
  act(() => result.current.inputProps.onKeyDown(keyEvent('ArrowDown')));
  expect(result.current.listProps.activeIndex).toBe(0);
});

it('chooses the active option on Enter and closes the list', () => {
  const { result, onChange } = setup();
  act(() => result.current.inputProps.onFocus());
  act(() => result.current.inputProps.onKeyDown(keyEvent('Enter')));
  expect(onChange).toHaveBeenCalledWith('c1', expect.objectContaining({ id: 'c1' }));
  expect(result.current.expanded).toBe(false);
});

it('closes on Escape without choosing', () => {
  const { result, onChange } = setup();
  act(() => result.current.inputProps.onFocus());
  const event = keyEvent('Escape');
  act(() => result.current.inputProps.onKeyDown(event));
  expect(result.current.expanded).toBe(false);
  expect(onChange).not.toHaveBeenCalled();
});

it('closes a moment after the blur so a click on an option still registers', () => {
  const { result } = setup();
  act(() => result.current.inputProps.onFocus());
  act(() => result.current.inputProps.onBlur());
  expect(result.current.expanded).toBe(true);
  act(() => {
    vi.advanceTimersByTime(130);
  });
  expect(result.current.expanded).toBe(false);
});

it('does not reopen on the focus that follows a creation request', () => {
  const onCreateRequest = vi.fn();
  const { result } = setup({ onCreateRequest });
  act(() => result.current.inputProps.onFocus());
  act(() => result.current.listProps.onChoose(result.current.choices.at(-1)!));
  expect(onCreateRequest).toHaveBeenCalled();
  act(() => result.current.inputProps.onFocus());
  expect(result.current.expanded).toBe(false);
});
