import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { FormGrid } from './FormGrid';

afterEach(cleanup);

it('wraps its fields in one grid and keeps a caller class', () => {
  render(
    <FormGrid className="extra">
      <label>
        Libellé
        <input />
      </label>
      <div data-span="full">Large</div>
    </FormGrid>,
  );
  const grid = screen.getByLabelText('Libellé').closest('div')!;
  expect(grid.className).toContain('extra');
  expect(screen.getByText('Large').getAttribute('data-span')).toBe('full');
});
