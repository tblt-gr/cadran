import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { annualFixture } from '@/features/portfolio/performance/performance-tab/annualFixtures';
import { PerformanceValueChart } from './PerformanceValueChart';

afterEach(cleanup);

describe('PerformanceValueChart', () => {
  it('titles the curve with the year and describes it for assistive technology', () => {
    render(<PerformanceValueChart annual={annualFixture()} year={2026} />);
    expect(screen.getByRole('heading', { name: 'Évolution de la valeur 2026' })).toBeTruthy();
    const figure = screen.getByRole('figure');
    const description = document.getElementById(figure.getAttribute('aria-describedby') ?? '');
    expect(description?.textContent).toContain('fin de chaque mois');
  });

  it('places the month end value of each valued month and leaves a gap elsewhere', () => {
    render(<PerformanceValueChart annual={annualFixture()} year={2026} />);
    expect(screen.getByLabelText(/janvier 2026, .*11 250,00/)).toBeTruthy();
    expect(screen.queryByLabelText(/mars 2026/)).toBeNull();
  });

  it('keeps the title while loading', () => {
    render(<PerformanceValueChart annual={null} year={2026} />);
    expect(screen.getByRole('heading', { name: 'Évolution de la valeur 2026' })).toBeTruthy();
  });
});
