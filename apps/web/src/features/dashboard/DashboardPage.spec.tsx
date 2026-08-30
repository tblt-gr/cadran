import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '../../i18n';
import { DashboardContextPanel } from './context-panel/DashboardContextPanel';
import { DashboardPage } from './DashboardPage';

describe('DashboardPage', () => {
  afterEach(() => {
    cleanup();
  });

  it('renders the representative states and accessible chart data', () => {
    render(
      <>
        <DashboardPage apiVersion="v1" />
        <DashboardContextPanel />
      </>,
    );

    const netWorthCard = screen.getByRole('heading', { name: 'Patrimoine net' }).closest('section');
    expect(netWorthCard?.textContent).toContain('124 680,00 €');
    expect(screen.getByText('Données anciennes')).toBeTruthy();
    expect(screen.getByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Aucun élément à traiter')).toBeTruthy();

    fireEvent.click(screen.getByText('Voir les données sous forme de tableau'));
    const dataTable = screen.getByRole('table', { name: 'Évolution du patrimoine' });
    expect(within(dataTable).getByText('Mars 2026')).toBeTruthy();
    expect(dataTable.textContent).toContain('124 680,00 €');

    const donutSegments = Array.from(
      document.querySelectorAll('circle[stroke-dasharray][stroke-dashoffset]'),
    );
    expect(
      donutSegments.map((segment) => [
        segment.getAttribute('stroke-dasharray'),
        segment.getAttribute('stroke-dashoffset'),
      ]),
    ).toEqual([
      ['48.2 51.8', '0'],
      ['36.8 63.2', '-48.2'],
      ['15 85', '-85'],
    ]);
  });
});
