import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import { GoalSubmitError } from './GoalSubmitError';

afterEach(cleanup);

it('announces the refusal as an alert', () => {
  render(<GoalSubmitError assetCode="EUR" kind="invalid_goal_tolerance" mismatch={null} />);
  expect(screen.getByRole('alert')).toBeTruthy();
});

it('adds the exact schedule difference when the server reports one', () => {
  render(
    <GoalSubmitError
      assetCode="EUR"
      kind="schedule_sum_mismatch"
      mismatch={{ difference: '12.50' } as never}
    />,
  );
  expect(screen.getByRole('alert').textContent).toMatch(/12,50/);
});
