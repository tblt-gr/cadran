import { describe, expect, it } from 'vitest';
import { deltaDirection } from './deltaDirection';

describe('deltaDirection', () => {
  it('reads the direction from the digits, never from a Number', () => {
    expect(deltaDirection('1740.00')).toBe('up');
    expect(deltaDirection('-1740.00')).toBe('down');
  });

  it('answers flat for a zero at any scale', () => {
    expect(deltaDirection('0')).toBe('flat');
    expect(deltaDirection('0.00')).toBe('flat');
    expect(deltaDirection('0.000000000000000000000000')).toBe('flat');
  });
});
