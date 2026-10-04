import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { QualityBadge } from './QualityBadge';

afterEach(cleanup);

describe('QualityBadge', () => {
  it.each([
    ['EXACT', 'Exact'],
    ['APPROXIMATION', 'Approximation'],
    ['DEGRADED', 'Données à vérifier'],
    ['NOT_CALCULABLE', 'Non calculable'],
  ] as const)('labels %s in French', (quality, label) => {
    render(<QualityBadge quality={quality} />);
    expect(screen.getByText(label)).toBeTruthy();
  });
});
