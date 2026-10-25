import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthNavigator } from './MonthNavigator';

describe('MonthNavigator', () => {
  afterEach(cleanup);

  it('exposes future months as disabled buttons and past months as links', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        firstDataMonth="2020-01"
        month="2026-03"
      />,
    );

    const future = screen.getByRole('button', { name: 'Avril' }) as HTMLButtonElement;
    expect(future.disabled).toBe(true);
    expect(screen.getByRole('link', { name: 'Février' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Mars' }).getAttribute('aria-current')).toBe('page');
  });

  it('disables months before the first data month with a reason and keeps later gaps selectable', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        firstDataMonth="2023-11"
        month="2023-12"
        status="ready"
      />,
    );

    const before = screen.getByRole('button', {
      name: 'Octobre, avant le début de l’historique',
    }) as HTMLButtonElement;
    expect(before.disabled).toBe(true);
    expect(before.title).toBe('Avant le début de l’historique');
    expect(screen.getAllByRole('button', { name: /avant le début/ })).toHaveLength(10);
    expect(screen.getByRole('link', { name: 'Novembre' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Décembre' }).getAttribute('aria-current')).toBe(
      'page',
    );
  });

  it('keeps months after the first data month selectable even without transactions', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-10"
        firstDataMonth="2026-03"
        month="2026-05"
        status="ready"
      />,
    );

    expect(screen.getByRole('link', { name: 'Avril' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Juin' })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Février/ })).toBeTruthy();
  });

  it('offers only the current month and no year history for an empty workspace', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        firstDataMonth={null}
        month="2026-03"
        status="ready"
      />,
    );

    expect(screen.getByRole('link', { name: 'Mars' })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Février/ })).toBeTruthy();
  });

  it('disables every control while the bounds load', () => {
    render(<MonthNavigator axis={null} currentMonth="2026-03" month="2026-03" status="loading" />);

    expect((screen.getByRole('combobox', { name: 'Année' }) as HTMLSelectElement).disabled).toBe(
      true,
    );
    expect(screen.queryAllByRole('link')).toHaveLength(0);
    expect(
      screen.getAllByRole('button').every((button) => (button as HTMLButtonElement).disabled),
    ).toBe(true);
  });

  it('offers a retry and keeps controls disabled when the bounds failed', () => {
    const onRetry = vi.fn();
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        month="2026-03"
        onRetry={onRetry}
        status="error"
      />,
    );

    expect(screen.queryAllByRole('link')).toHaveLength(0);
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(onRetry).toHaveBeenCalledOnce();
  });

  it('scrolls the selected month into view so a narrow strip never hides it', () => {
    const scrollIntoView = vi.fn();
    Element.prototype.scrollIntoView = scrollIntoView;

    render(<MonthNavigator axis={null} currentMonth="2026-10" month="2026-10" />);

    expect(scrollIntoView).toHaveBeenCalledTimes(1);
    expect(scrollIntoView.mock.instances[0]).toBe(screen.getByRole('link', { name: 'Octobre' }));
    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'nearest', inline: 'center' });
  });

  function yearOptions() {
    return within(screen.getByRole('combobox', { name: 'Année' }))
      .getAllByRole('option')
      .map((option) => option.textContent);
  }

  it('lists years from the first year with data up to the current year', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        firstDataMonth="2024-11"
        month="2026-03"
      />,
    );

    expect(yearOptions()).toEqual(['2026', '2025', '2024']);
  });

  it('keeps the selected year even when it predates the first data', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        firstDataMonth="2025-01"
        month="2023-05"
      />,
    );

    expect(yearOptions()).toEqual(['2026', '2025', '2024', '2023']);
  });

  it('offers only the current year when there is no data yet', () => {
    render(
      <MonthNavigator axis={null} currentMonth="2026-03" firstDataMonth={null} month="2026-03" />,
    );

    expect(yearOptions()).toEqual(['2026']);
    expect(screen.queryByText('Année')).toBeNull();
    const select = screen.getByRole('combobox', { name: 'Année' });
    select.focus();
    expect(document.activeElement).toBe(select);
  });

  it('marks the active month with the primary colour class and no other decoration', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        firstDataMonth="2020-01"
        month="2026-03"
      />,
    );

    const active = screen.getByRole('link', { name: 'Mars' });
    expect(active.getAttribute('aria-current')).toBe('page');
    expect(active.className).toContain('active');
    expect(screen.getByRole('link', { name: 'Février' }).className).toBe('');
  });

  describe('annual report', () => {
    it('offers the annual report after the last month tab, pointing at the year', () => {
      render(
        <MonthNavigator
          axis={null}
          currentMonth="2026-03"
          firstDataMonth="2020-01"
          month="2026-03"
        />,
      );

      const tabs = screen.getByRole('navigation', { name: 'Mois du budget' });
      const entries = within(tabs).getAllByRole('link').concat(within(tabs).getAllByRole('button'));
      const annual = within(tabs).getByRole('link', { name: 'Rapport annuel' });
      expect(annual.getAttribute('href')).toBe('/budget/2026');
      expect(annual.getAttribute('aria-current')).toBeNull();
      expect(entries).toContain(annual);
      expect(tabs.lastElementChild).toBe(annual);
      expect(within(tabs).getByRole('link', { name: 'Février' }).getAttribute('href')).toBe(
        '/budget/2026/02',
      );
    });

    it('marks only the annual report as current on the year view', () => {
      render(
        <MonthNavigator
          axis={null}
          currentMonth="2026-03"
          firstDataMonth="2020-01"
          month={null}
          year={2025}
        />,
      );

      expect(
        screen.getByRole('link', { name: 'Rapport annuel' }).getAttribute('aria-current'),
      ).toBe('page');
      expect(screen.getByRole('link', { name: 'Mars' }).getAttribute('aria-current')).toBeNull();
      expect((screen.getByRole('combobox', { name: 'Année' }) as HTMLSelectElement).value).toBe(
        '2025',
      );
    });

    it('keeps future months of the year disabled on the year view', () => {
      render(
        <MonthNavigator
          axis={null}
          currentMonth="2026-03"
          firstDataMonth="2020-01"
          month={null}
          year={2026}
        />,
      );

      expect((screen.getByRole('button', { name: 'Avril' }) as HTMLButtonElement).disabled).toBe(
        true,
      );
    });

    it('stays on the annual report when another year is chosen on the year view', () => {
      window.history.replaceState({}, '', '/budget/2026');
      render(
        <MonthNavigator
          axis={null}
          currentMonth="2026-03"
          firstDataMonth="2020-01"
          month={null}
          year={2026}
        />,
      );

      fireEvent.change(screen.getByRole('combobox', { name: 'Année' }), {
        target: { value: '2024' },
      });

      expect(window.location.pathname).toBe('/budget/2024');
    });
  });
});
