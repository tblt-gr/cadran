import type { AccountValuation } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { AccountValuationCell } from './AccountValuationCell';

afterEach(cleanup);

function valuation(overrides: Partial<AccountValuation> = {}): AccountValuation {
  return {
    accountId: '00000000-0000-7000-8000-0000000000d1',
    requestedOn: '2026-09-05',
    asOf: '2026-09-03',
    amount: { value: '231.10', assetCode: 'EUR' },
    display: { value: '231.10', assetCode: 'EUR' },
    belowDisplayStep: false,
    source: 'MANUAL',
    ageDays: 2,
    quality: 'STALE',
    reconciliationStatus: 'UNRECONCILED',
    snapshotId: '00000000-0000-7000-8000-0000000000b1',
    version: 1,
    basis: 'SNAPSHOT',
    derivation: null,
    reason: null,
    ...overrides,
  };
}

describe('AccountValuationCell', () => {
  it('names a missing valuation instead of inventing zero', () => {
    render(
      <AccountValuationCell
        valuation={valuation({
          asOf: null,
          amount: null,
          display: null,
          source: null,
          ageDays: null,
          quality: 'MISSING',
          reconciliationStatus: null,
          snapshotId: null,
          version: null,
        })}
      />,
    );

    expect(screen.getByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Aucune valorisation')).toBeTruthy();
    expect(screen.queryByText(/0/)).toBeNull();
  });

  it('preserves the snapshot source and date', () => {
    render(
      <AccountValuationCell
        valuation={valuation({ quality: 'CURRENT', basis: 'SNAPSHOT', ageDays: 0 })}
      />,
    );
    expect(screen.getByText(/Au jour · au 3 septembre 2026 · Saisie/)).toBeTruthy();
  });

  it('keeps a stale figure and says how old it is', () => {
    render(<AccountValuationCell valuation={valuation()} />);

    expect(screen.getByText(/231,10/)).toBeTruthy();
    expect(screen.getByText(/Ancienne/)).toBeTruthy();
    expect(screen.getByText(/2 jours/)).toBeTruthy();
    expect(screen.getByText(/Saisie/)).toBeTruthy();
  });

  it('prints the observed figure against the published ceiling and fills a decorative bar', () => {
    const { container } = render(
      <AccountValuationCell
        ceiling={{ assetCode: 'EUR', value: '22950' }}
        valuation={valuation({ quality: 'CURRENT', ageDays: 0 })}
      />,
    );

    expect(container.textContent).toContain('231,10');
    expect(container.textContent).toContain('22');
    expect(container.querySelector('[style*="width"]')).toBeTruthy();
  });

  it('does not display a rounded zero for a figure below the step', () => {
    render(
      <AccountValuationCell
        valuation={valuation({
          amount: { value: '0.0000000012', assetCode: 'ETH' },
          display: null,
          belowDisplayStep: true,
          quality: 'CURRENT',
          ageDays: 0,
          source: 'MANUAL',
        })}
      />,
    );

    expect(screen.getByText('Inférieur à l’échelon d’affichage')).toBeTruthy();
    expect(screen.queryByText(/^0,00/)).toBeNull();
  });
});

describe('derived balance provenance', () => {
  it.each([0, 1, 2])('describes the anchor and movements with %i pending', (pendingCount) => {
    const { container } = render(
      <AccountValuationCell
        valuation={valuation({
          basis: 'SNAPSHOT_PLUS_MOVEMENTS',
          quality: 'CURRENT',
          ageDays: 0,
          asOf: '2026-10-10',
          derivation: {
            anchorAsOf: '2026-09-30',
            anchorAgeDays: 10,
            movementCount: 3,
            pendingCount,
          },
        })}
      />,
    );
    expect(container.querySelector('small')?.textContent).toContain(
      'Au jour · Relevé du 30 septembre 2026 + 3 mouvement(s)',
    );
    expect(container.textContent).not.toContain('10 octobre 2026');
    expect(container.textContent).not.toContain('Saisie');
    expect(container.textContent?.includes('en attente')).toBe(pendingCount > 0);
    if (pendingCount > 0)
      expect(container.textContent).toContain(`dont ${pendingCount} en attente`);
  });

  it('invites a transaction account to record its starting balance', () => {
    render(
      <AccountValuationCell
        startingBalanceHint
        valuation={valuation({
          quality: 'MISSING',
          amount: null,
          display: null,
          basis: null,
        })}
      />,
    );
    expect(screen.getByText('Enregistrez un solde de départ pour calculer ce solde')).toBeTruthy();
  });

  it('explains mixed units without proposing a starting balance', () => {
    render(
      <AccountValuationCell
        startingBalanceHint
        valuation={valuation({
          quality: 'MISSING',
          amount: null,
          display: null,
          basis: null,
          reason: 'MIXED_ASSETS',
        })}
      />,
    );
    expect(
      screen.getByText('Des mouvements utilisent une autre unité que celle du compte'),
    ).toBeTruthy();
    expect(screen.queryByText(/Enregistrez un solde/)).toBeNull();
  });
});

it('keeps the loaded cell structure and the provenance small block during loading', () => {
  const { container, rerender } = render(<AccountValuationCell valuation={null} />);
  const loading = container.firstElementChild;
  expect(loading?.querySelector('small [data-skeleton]')).toBeTruthy();
  expect(loading?.querySelector('[role="status"]')).toBeNull();
  const childTags = Array.from(loading?.children ?? []).map((element) => element.tagName);
  rerender(<AccountValuationCell valuation={valuation({ quality: 'CURRENT' })} />);
  expect(
    Array.from(container.firstElementChild?.children ?? []).map((element) => element.tagName),
  ).toEqual(childTags);
});

it.each([
  { size: 'md' as const, ceiling: null },
  { size: 'lg' as const, ceiling: null },
  { size: 'md' as const, ceiling: { assetCode: 'EUR', value: '500' } },
  { size: 'lg' as const, ceiling: { assetCode: 'EUR', value: '500' } },
])('mirrors every known tag and class for size $size and ceiling $ceiling', (props) => {
  function structure(element: Element): unknown {
    return {
      tag: element.tagName,
      className: element.className,
      children: Array.from(element.children)
        .filter((child) => !child.hasAttribute('data-skeleton'))
        .map(structure),
    };
  }
  const { container, rerender } = render(<AccountValuationCell {...props} valuation={null} />);
  const loadingStructure = structure(container.firstElementChild!);
  const loadingSmall = container.querySelector('small');
  expect(loadingSmall?.querySelector('[data-skeleton]')).toBeTruthy();
  if (props.ceiling) {
    const track = container.querySelector('span[aria-hidden="true"]:not([data-skeleton])');
    expect(track?.firstElementChild).toBeTruthy();
  }
  rerender(<AccountValuationCell {...props} valuation={valuation({ quality: 'CURRENT' })} />);
  expect(structure(container.firstElementChild!)).toEqual(loadingStructure);
});
