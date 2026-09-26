import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { SettingsTabs } from './SettingsTabs';

describe('SettingsTabs', () => {
  afterEach(cleanup);

  it('links both settings pages and marks the current one', () => {
    render(<SettingsTabs path="/settings/metric-policy" />);

    const nav = screen.getByRole('navigation', { name: 'Sections des paramètres' });
    expect(nav).toBeTruthy();
    const profile = screen.getByRole('link', { name: 'Profil' });
    const policy = screen.getByRole('link', { name: 'Politique d’indicateurs' });
    expect(profile.getAttribute('href')).toBe('/settings/profile');
    expect(profile.getAttribute('aria-current')).toBeNull();
    expect(policy.getAttribute('href')).toBe('/settings/metric-policy');
    expect(policy.getAttribute('aria-current')).toBe('page');
  });

  it('navigates client-side without a page load', () => {
    const seen: string[] = [];
    window.addEventListener('popstate', () => seen.push(window.location.pathname), { once: true });
    render(<SettingsTabs path="/settings/profile" />);

    fireEvent.click(screen.getByRole('link', { name: 'Politique d’indicateurs' }));

    expect(seen).toEqual(['/settings/metric-policy']);
  });
});
