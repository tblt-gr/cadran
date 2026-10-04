import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { annualFixture } from '@/features/portfolio/performance/performance-tab/annualFixtures';
import { PerformanceIndexChart } from './PerformanceIndexChart';

afterEach(cleanup);

describe('PerformanceIndexChart', () => {
  it('titles the chained curve and describes it for assistive technology', () => {
    render(<PerformanceIndexChart annual={annualFixture()} />);
    expect(screen.getByRole('heading', { name: 'Rendement chaîné (base 100)' })).toBeTruthy();
    const figure = screen.getByRole('figure');
    const description = document.getElementById(figure.getAttribute('aria-describedby') ?? '');
    expect(description?.textContent).toBe(
      'Évolution de 100 € investis selon le rendement Modified Dietz, mois par mois.',
    );
  });

  it('places a point per calculated month and leaves a gap where the index is null', () => {
    render(<PerformanceIndexChart annual={annualFixture()} />);
    expect(screen.getByLabelText(/janvier 2026, 102,35/)).toBeTruthy();
    expect(screen.getByLabelText(/février 2026, 103,70/)).toBeTruthy();
    expect(screen.queryByLabelText(/mars 2026/)).toBeNull();
    expect(screen.getAllByLabelText(/ 2026, /)).toHaveLength(2);
  });

  it('keeps the title while loading', () => {
    render(<PerformanceIndexChart annual={null} />);
    expect(screen.getByRole('heading', { name: 'Rendement chaîné (base 100)' })).toBeTruthy();
  });
});
