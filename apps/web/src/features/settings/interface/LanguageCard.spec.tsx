import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import i18n, { setLanguage } from '@/i18n';
import { LanguageCard } from './LanguageCard';

describe('LanguageCard', () => {
  afterEach(async () => {
    await setLanguage('fr');
    window.localStorage.clear();
  });

  it('lists each language in its own language and switches at once', async () => {
    render(<LanguageCard />);
    const select = screen.getByRole('combobox', { name: 'Langue' });
    expect(screen.getByRole('option', { name: 'Français' })).toBeTruthy();
    expect(screen.getByRole('option', { name: 'English' })).toBeTruthy();

    fireEvent.change(select, { target: { value: 'en' } });

    await waitFor(() => expect(i18n.language).toBe('en'));
    expect(document.documentElement.lang).toBe('en');
    expect(window.localStorage.getItem('cadran.language')).toBe('en');
    expect((screen.getByRole('combobox', { name: 'Language' }) as HTMLSelectElement).value).toBe(
      'en',
    );
  });
});
