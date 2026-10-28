import { describe, expect, it } from 'vitest';
import { wrapCaption } from './wrapCaption';

describe('wrapCaption', () => {
  it('keeps a short caption on one line', () => {
    expect(wrapCaption('Loyer · 42,00 %', 20, 3)).toEqual(['Loyer · 42,00 %']);
  });

  it('wraps at spaces', () => {
    expect(wrapCaption('Épargne de précaution · 12,50 %', 16, 3)).toEqual([
      'Épargne de',
      'précaution ·',
      '12,50 %',
    ]);
  });

  it('cuts a word longer than a line', () => {
    expect(wrapCaption('Bourse (Investissement) · 42,00 %', 14, 3)).toEqual([
      'Bourse',
      '(Investissemen',
      't) · 42,00 %',
    ]);
  });

  it('ends with an ellipsis beyond the last line', () => {
    expect(wrapCaption('un deux trois quatre cinq', 6, 2)).toEqual(['un', 'deux…']);
  });
});
