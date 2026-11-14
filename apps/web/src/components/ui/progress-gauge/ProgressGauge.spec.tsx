import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { ProgressGauge } from './ProgressGauge';

afterEach(cleanup);

it('draws one bar when no segment count is given', () => {
  render(<ProgressGauge label="Progression" ratio="0.5" />);
  expect(screen.queryByTestId('gauge-segments')).toBeNull();
});

it('cuts the bar into one segment per month and fills them in turn', () => {
  render(<ProgressGauge label="4,5 mois sur 6" ratio="0.75" segments={6} />);
  const parts = Array.from(screen.getByTestId('gauge-segments').children).map(
    (segment) => (segment.firstElementChild as HTMLElement).style.width,
  );
  expect(parts).toEqual(['100%', '100%', '100%', '100%', '50%', '0%']);
  expect(screen.getByRole('meter').getAttribute('aria-valuenow')).toBe('0.75');
});
