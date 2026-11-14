import { describe, expect, it } from 'vitest';
import { readYearView, writeYearView } from './yearViewSearch';

describe('yearViewSearch', () => {
  it('defaults to the annual report', () => {
    expect(readYearView('')).toEqual({ view: 'annual', range: null });
    expect(readYearView('?from=2020&to=2024')).toEqual({ view: 'annual', range: null });
  });

  it('reads the multi-year mode with its range, and ignores the retired display parameter', () => {
    expect(readYearView('?view=multi&display=detailed&from=2020&to=2024')).toEqual({
      view: 'multi',
      range: { from: 2020, to: 2024 },
    });
    expect(readYearView('?view=multi')).toEqual({ view: 'multi', range: null });
  });

  it('ignores a malformed or half-given range', () => {
    expect(readYearView('?view=multi&from=2020').range).toBeNull();
    expect(readYearView('?view=multi&from=20x0&to=2024').range).toBeNull();
    expect(readYearView('?view=multi&from=2020&to=99999').range).toBeNull();
  });

  it('round-trips and writes nothing for the annual view', () => {
    const state = { view: 'multi', range: { from: 2019, to: 2023 } } as const;
    expect(readYearView(writeYearView(state))).toEqual(state);
    expect(writeYearView({ view: 'annual', range: null })).toBe('');
    expect(writeYearView({ view: 'multi', range: null })).toBe('?view=multi');
  });
});
