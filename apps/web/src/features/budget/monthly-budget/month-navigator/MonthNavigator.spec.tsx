import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { MonthNavigator } from './MonthNavigator';

describe('MonthNavigator', () => {
  afterEach(cleanup);

  it('exposes future months as disabled buttons and past months as links', () => {
    render(<MonthNavigator axis={null} currentMonth="2026-03" month="2026-03" />);

    const future = screen.getByRole('button', { name: 'Avril' }) as HTMLButtonElement;
    expect(future.disabled).toBe(true);
    expect(screen.getByRole('link', { name: 'Février' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Mars' }).getAttribute('aria-current')).toBe('page');
  });
});
