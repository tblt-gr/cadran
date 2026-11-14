import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { MonthLink } from './MonthLink';

afterEach(cleanup);

it('links a month to its budget', () => {
  render(<MonthLink month="2026-03">mars</MonthLink>);
  expect(screen.getByRole('link', { name: 'mars' }).getAttribute('href')).toBe('/budget/2026/03');
});
