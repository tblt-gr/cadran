import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { SettingsTabs } from './SettingsTabs';

describe('SettingsTabs', () => {
  afterEach(cleanup);

  it('links every settings page and marks the current one', () => {
    render(<SettingsTabs path="/parameters/metric-policy" />);

    const nav = screen.getByRole('navigation', { name: 'Sections des paramètres' });
    expect(nav).toBeTruthy();
    const profile = screen.getByRole('link', { name: 'Profil' });
    const policy = screen.getByRole('link', { name: 'Politique d’indicateurs' });
    expect(profile.getAttribute('href')).toBe('/parameters/profile');
    expect(profile.getAttribute('aria-current')).toBeNull();
    expect(policy.getAttribute('href')).toBe('/parameters/metric-policy');
    expect(policy.getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: 'Catalogue' }).getAttribute('href')).toBe(
      '/parameters/catalog',
    );
    expect(screen.getByRole('link', { name: 'Modèles' }).getAttribute('href')).toBe(
      '/parameters/product-models',
    );
  });

  it('navigates client-side without a page load', () => {
    const seen: string[] = [];
    window.addEventListener('popstate', () => seen.push(window.location.pathname), { once: true });
    render(<SettingsTabs path="/parameters/profile" />);

    fireEvent.click(screen.getByRole('link', { name: 'Politique d’indicateurs' }));

    expect(seen).toEqual(['/parameters/metric-policy']);
  });
});
