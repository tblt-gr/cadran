import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import '@/i18n';
import { useAnnualColumnLabel } from './columnLabel';

describe('useAnnualColumnLabel', () => {
  const label = () => renderHook(() => useAnnualColumnLabel()).result.current;

  it('names indicators and axes like the column picker, whatever the backend label says', () => {
    expect(label()({ id: 'budgetSurplus', label: 'Solde budgétaire' })).toBe('Excédent');
    expect(label()({ id: 'endNetWorth', label: 'Patrimoine final' })).toBe('Patrimoine net');
    expect(label()({ id: 'axis:ESSENTIAL', label: 'Dépenses : Essentiel' })).toBe('Essentiel');
  });

  it('keeps the workspace label of categories, groups and accounts', () => {
    expect(label()({ id: 'category:1', label: 'Courses' })).toBe('Courses');
    expect(label()({ id: 'account:2', label: 'Livret A' })).toBe('Livret A');
    expect(label()({ id: 'group:3', label: 'Épargne' })).toBe('Épargne');
  });

  it('falls back to the backend label for an unknown indicator or axis', () => {
    expect(label()({ id: 'futureIndicator', label: 'Nouveau' })).toBe('Nouveau');
    expect(label()({ id: 'axis:OTHER', label: 'Dépenses : Autre' })).toBe('Dépenses : Autre');
  });
});
