import { describe, expect, it } from 'vitest';
import { sliceCaption } from './sliceCaption';

describe('sliceCaption', () => {
  it('joins the label and the backend share with a middle dot', () => {
    expect(sliceCaption('Livrets', '16.20')).toBe('Livrets · 16,20 %');
  });

  it('keeps the label alone when the share is missing', () => {
    expect(sliceCaption('Livrets', null)).toBe('Livrets');
  });
});
