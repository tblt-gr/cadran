import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { RecurrenceRow } from './RecurrenceRow';

describe('RecurrenceRow', () => {
  it('marks the row menu cell so mobile cards pin it to their top right corner', () => {
    render(
      <ul>
        <RecurrenceRow actions={<button type="button">Menu</button>} meta="Monthly" title="Rent" />
      </ul>,
    );

    expect(
      screen.getByRole('button', { name: 'Menu' }).parentElement?.hasAttribute('data-actions'),
    ).toBe(true);
  });
});
