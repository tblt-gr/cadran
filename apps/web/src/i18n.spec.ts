import { afterEach, describe, expect, it, vi } from 'vitest';
import i18n, { initialLanguage, setLanguage } from './i18n';

describe('language selection', () => {
  afterEach(async () => {
    vi.restoreAllMocks();
    window.localStorage.clear();
    await setLanguage('fr');
  });

  it('prefers the stored choice, then an English browser, then French', () => {
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('fr-FR');
    expect(initialLanguage()).toBe('fr');
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('en-GB');
    expect(initialLanguage()).toBe('en');
    window.localStorage.setItem('cadran.language', 'fr');
    expect(initialLanguage()).toBe('fr');
    window.localStorage.setItem('cadran.language', 'de');
    expect(initialLanguage()).toBe('en');
  });

  it('falls back to the browser language when storage is blocked', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('en-US');
    expect(initialLanguage()).toBe('en');
  });

  it('keeps the last choice when two switches overlap', async () => {
    await Promise.all([setLanguage('en'), setLanguage('fr')]);
    expect(i18n.language).toBe('fr');
  });
});
