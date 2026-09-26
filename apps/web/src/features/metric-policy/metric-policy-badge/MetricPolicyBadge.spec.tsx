import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { MetricPolicyBadge } from './MetricPolicyBadge';

describe('MetricPolicyBadge', () => {
  afterEach(cleanup);

  it('names the policy version with its label as a tooltip', () => {
    render(<MetricPolicyBadge policy={{ version: 3, label: 'Sans titres-restaurant' }} />);

    const badge = screen.getByText('Politique v3');
    expect(badge.parentElement?.getAttribute('title')).toBe('Sans titres-restaurant');
    expect(badge.parentElement?.textContent).toBe('Politique v3, Sans titres-restaurant');
  });

  it('says the policies are mixed when the period has no single version', () => {
    render(<MetricPolicyBadge policy={{ version: null, label: null }} />);

    expect(screen.getByText('Politiques mixtes')).toBeTruthy();
  });
});
