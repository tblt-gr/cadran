import { renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { setLanguage } from '@/i18n';
import { useMetricPolicyLabel } from './useMetricPolicyLabel';

describe('useMetricPolicyLabel', () => {
  const name = () => renderHook(() => useMetricPolicyLabel()).result.current;

  afterEach(async () => {
    await setLanguage('fr');
  });

  it('translates the built-in version whatever label the server sends', async () => {
    expect(name()({ version: 1, label: 'All accounts' })).toBe('Tous les comptes');
    await setLanguage('en');
    expect(name()({ version: 1, label: null })).toBe('All accounts');
  });

  it('keeps the label of a stored version', () => {
    expect(name()({ version: 2, label: 'Sans titres' })).toBe('Sans titres');
  });

  it('says a version without a label is not found, and names nothing without a version', () => {
    expect(name()({ version: 7, label: null })).toBe('Politique introuvable');
    expect(name()({ version: null, label: null })).toBeNull();
  });
});
