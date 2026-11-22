import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AboutCard } from './AboutCard';

describe('AboutCard', () => {
  it('shows the product version', () => {
    render(<AboutCard />);
    expect(screen.getByRole('heading', { name: 'À propos' })).toBeTruthy();
    expect(screen.getByText(__APP_VERSION__)).toBeTruthy();
    expect(__APP_VERSION__).toMatch(/^\d+\.\d+\.\d+$/);
  });
});
