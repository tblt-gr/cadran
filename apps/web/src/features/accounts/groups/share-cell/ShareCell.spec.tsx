import type { NetWorthShare } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ShareCell } from './ShareCell';

const missing: NetWorthShare = {
  ratio: null,
  percent: null,
  percentDisplay: null,
  reason: 'MISSING_VALUATION',
};

describe('ShareCell', () => {
  afterEach(() => {
    cleanup();
  });

  it('shows the backend reason and never invents 0%', () => {
    render(<ShareCell share={missing} />);

    expect(screen.getByText('Valorisation manquante')).toBeTruthy();
    expect(screen.queryByText(/0\s*%/)).toBeNull();
  });

  it('renders a backend percent without multiplying a ratio', () => {
    render(
      <ShareCell
        share={{
          ratio: '0.400000000000000000000000',
          percent: '40.000000000000000000000000',
          percentDisplay: '40.00',
          reason: null,
        }}
      />,
    );

    expect(screen.getByText('40 %')).toBeTruthy();
  });

  it('explains an excluded account without a reason', () => {
    render(
      <ShareCell share={{ ratio: null, percent: null, percentDisplay: null, reason: null }} />,
    );

    expect(screen.getByText('Hors patrimoine éligible')).toBeTruthy();
    expect(screen.queryByText(/0\s*%/)).toBeNull();
  });
});
