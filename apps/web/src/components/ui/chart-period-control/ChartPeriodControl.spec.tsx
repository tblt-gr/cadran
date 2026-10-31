import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import type { ChartPeriod } from '@/lib/chartPeriodRange';
import { ChartPeriodControl } from './ChartPeriodControl';

const labels = (group: HTMLElement) =>
  Array.from(group.querySelectorAll('button')).map((button) => button.textContent);

describe('ChartPeriodControl', () => {
  afterEach(cleanup);

  it('orders every subset canonically whatever the caller order', () => {
    render(
      <ChartPeriodControl
        label="Période A"
        onChange={vi.fn()}
        periods={['YTD', 'LAST_24_MONTHS', 'ALL', 'LAST_12_MONTHS']}
        value="ALL"
      />,
    );
    expect(labels(screen.getByRole('group', { name: 'Période A' }))).toEqual([
      'Tout',
      '24 mois',
      '12 mois',
      'Depuis janvier',
    ]);
    cleanup();
    render(
      <ChartPeriodControl
        label="Période B"
        onChange={vi.fn()}
        periods={['YTD', 'LAST_12_MONTHS']}
        value="YTD"
      />,
    );
    expect(labels(screen.getByRole('group', { name: 'Période B' }))).toEqual([
      '12 mois',
      'Depuis janvier',
    ]);
  });

  it('marks a single selected option and reports changes', () => {
    const change = vi.fn<(period: ChartPeriod) => void>();
    render(
      <ChartPeriodControl
        label="Période"
        onChange={change}
        periods={['ALL', 'LAST_12_MONTHS']}
        value="LAST_12_MONTHS"
      />,
    );
    expect(document.querySelectorAll('[aria-pressed="true"]')).toHaveLength(1);
    expect(screen.getByRole('button', { name: '12 mois' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Tout' }));
    expect(change).toHaveBeenCalledWith('ALL');
  });

  it('is inert when disabled and still shows the real labels', () => {
    const change = vi.fn();
    render(
      <ChartPeriodControl
        disabled
        label="Période"
        onChange={change}
        periods={['ALL', 'YTD']}
        value="YTD"
      />,
    );
    for (const name of ['Tout', 'Depuis janvier']) {
      expect((screen.getByRole('button', { name }) as HTMLButtonElement).disabled).toBe(true);
    }
  });

  it('describes year-to-date as January 1 through the reference day', () => {
    render(
      <ChartPeriodControl
        label="Période"
        onChange={vi.fn()}
        periods={['ALL', 'YTD']}
        value="ALL"
      />,
    );
    expect(screen.getByRole('button', { name: 'Depuis janvier' }).getAttribute('title')).toMatch(
      /31 décembre précédent/,
    );
    expect(screen.getByRole('button', { name: 'Tout' }).getAttribute('title')).toBeNull();
    const ytd = screen.getByRole('button', { name: 'Depuis janvier' });
    const described = document.getElementById(ytd.getAttribute('aria-describedby')!);
    expect(described?.className).toBe('sr-only');
    expect(described?.textContent).toMatch(/31 décembre précédent/);
    expect(
      screen.getByRole('button', { name: 'Tout' }).getAttribute('aria-describedby'),
    ).toBeNull();
  });

  it('keeps several instances independent and reachable by keyboard', () => {
    const first = vi.fn();
    const second = vi.fn();
    render(
      <>
        <ChartPeriodControl
          label="Graphique 1"
          onChange={first}
          periods={['ALL', 'YTD']}
          value="ALL"
        />
        <ChartPeriodControl
          label="Graphique 2"
          onChange={second}
          periods={['ALL', 'YTD']}
          value="YTD"
        />
      </>,
    );
    expect(document.querySelectorAll('[aria-pressed="true"]')).toHaveLength(2);
    const ids = Array.from(document.querySelectorAll('[id]')).map((node) => node.id);
    expect(new Set(ids).size).toBe(ids.length);
    const target = screen.getAllByRole('button', { name: 'Tout' })[1];
    expect(target.tagName).toBe('BUTTON');
    expect(target.tabIndex).toBe(0);
    target.focus();
    expect(document.activeElement).toBe(target);
    fireEvent.click(target);
    expect(second).toHaveBeenCalledWith('ALL');
    expect(first).not.toHaveBeenCalled();
  });
});
