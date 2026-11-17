import { describe, expect, it } from 'vitest';
import { dayHeadingLabel } from './dayHeadingLabel';

const words = { today: 'Aujourd’hui', yesterday: 'Hier' };

describe('dayHeadingLabel', () => {
  it('names today and yesterday and dates every other day', () => {
    expect(dayHeadingLabel('2026-11-17', 'fr', words, '2026-11-17')).toBe('Aujourd’hui');
    expect(dayHeadingLabel('2026-11-16', 'fr', words, '2026-11-17')).toBe('Hier');
    expect(dayHeadingLabel('2026-11-15', 'fr', words, '2026-11-17')).toBe(
      'dimanche 15 novembre 2026',
    );
  });

  it('crosses a month and a year boundary', () => {
    expect(dayHeadingLabel('2026-02-28', 'fr', words, '2026-03-01')).toBe('Hier');
    expect(dayHeadingLabel('2025-12-31', 'fr', words, '2026-01-01')).toBe('Hier');
  });
});
