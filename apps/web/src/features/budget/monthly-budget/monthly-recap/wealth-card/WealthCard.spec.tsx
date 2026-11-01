import type { MonthlyRecapNetWorth, StaleValuation } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { WealthCard } from './WealthCard';

afterEach(cleanup);

function row(overrides: Partial<StaleValuation>): StaleValuation {
  return {
    accountId: '00000000-0000-7000-8000-000000000001',
    label: 'Livret A',
    institution: null,
    kind: 'SAVINGS',
    valuationMode: 'SNAPSHOTS',
    valuedOn: '2026-07-01',
    ageDays: 66,
    ...overrides,
  };
}

function netWorth(overrides: Partial<MonthlyRecapNetWorth>): MonthlyRecapNetWorth {
  return {
    previous: null,
    previousReason: null,
    current: null,
    currentReason: null,
    difference: null,
    differenceReason: null,
    changeRatio: null,
    changePercent: null,
    changePercentDisplay: null,
    changeReason: null,
    previousQuality: 'CURRENT',
    previousStalestAgeDays: null,
    previousMissingValuationCount: 0,
    previousStaleValuationCount: 0,
    quality: 'CURRENT',
    stalestAgeDays: null,
    eligibleAccountCount: 0,
    missingValuationCount: 0,
    staleValuationCount: 0,
    previousSourceAccountIds: [],
    currentSourceAccountIds: [],
    staleValuations: [],
    previousStaleValuations: [],
    ...overrides,
  } as MonthlyRecapNetWorth;
}

const stale = [
  row({}),
  row({
    accountId: '00000000-0000-7000-8000-000000000002',
    label: 'PEA',
    institution: 'Boursorama',
    kind: 'PORTFOLIO',
    valuationMode: 'PORTFOLIO',
    valuedOn: '2026-09-02',
    ageDays: 28,
  }),
];

describe('WealthCard stale valuations', () => {
  it('opens the list of stale accounts from the sentence and returns focus on close', () => {
    render(
      <WealthCard
        netWorth={netWorth({ staleValuationCount: 2, staleValuations: stale })}
        provisional={false}
      />,
    );

    const button = screen.getByRole('button', { name: '2 valorisations sont anciennes.' });
    button.focus();
    fireEvent.click(button);

    const dialog = screen.getByRole('dialog');
    const items = within(dialog).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    const livret = within(items[0]!).getByRole('link', { name: 'Livret A' });
    expect(livret.getAttribute('href')).toBe('/accounts/00000000-0000-7000-8000-000000000001');
    expect(within(items[0]!).getByText(/66 jours/)).toBeTruthy();
    const pea = within(items[1]!).getByRole('link', { name: 'PEA' });
    expect(pea.getAttribute('href')).toBe('/portfolios/00000000-0000-7000-8000-000000000002');
    expect(within(items[1]!).getByText('Boursorama')).toBeTruthy();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(document.activeElement).toBe(button);
  });

  it('lists the previous period accounts from the N-1 sentence', () => {
    render(
      <WealthCard
        netWorth={netWorth({
          previousStaleValuationCount: 1,
          previousStalestAgeDays: 66,
          previousStaleValuations: [row({})],
        })}
        provisional={false}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: /Au N−1, 1 valorisation est ancienne/ }));

    expect(within(screen.getByRole('dialog')).getAllByRole('listitem')).toHaveLength(1);
  });

  it('keeps the missing valuation sentence as plain text', () => {
    render(<WealthCard netWorth={netWorth({ missingValuationCount: 2 })} provisional={false} />);

    expect(screen.getByText('2 valorisations manquent.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /manquent/ })).toBeNull();
  });

  it('states that no account is listed when the list is empty', () => {
    render(<WealthCard netWorth={netWorth({ staleValuationCount: 1 })} provisional={false} />);

    fireEvent.click(screen.getByRole('button', { name: '1 valorisation est ancienne.' }));

    expect(screen.queryAllByRole('listitem')).toHaveLength(0);
    expect(within(screen.getByRole('dialog')).getByText(/Aucun compte/)).toBeTruthy();
  });

  it.each([
    ['no valuation date', { valuedOn: null }, 'Livret A', /^Valorisation vieille de 66 jours$/],
    ['no age', { ageDays: null }, 'Livret A', /^Valorisé le .*2026$/],
    ['neither date nor age', { valuedOn: null, ageDays: null }, 'Livret A', null],
  ])('still lists a row with %s without stray punctuation', (_name, overrides, label, detail) => {
    render(
      <WealthCard
        netWorth={netWorth({
          staleValuationCount: 1,
          staleValuations: [row(overrides as Partial<StaleValuation>)],
        })}
        provisional={false}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: '1 valorisation est ancienne.' }));

    const item = within(screen.getByRole('dialog')).getByRole('listitem');
    expect(within(item).getByRole('link', { name: label })).toBeTruthy();
    const small = item.querySelector('small');
    if (detail === null) {
      expect(small).toBeNull();
    } else {
      expect(small?.textContent).toMatch(detail);
      expect(small?.textContent).not.toMatch(/^\s|\s$|\(\)/);
    }
  });
});
