import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it } from 'vitest';
import { FormSection } from './FormSection';

afterEach(cleanup);

function Host({ initiallyOpen = false }: { initiallyOpen?: boolean }) {
  const [open, setOpen] = useState(initiallyOpen);
  return (
    <FormSection onToggle={setOpen} open={open} title="Champs avancés">
      <label>
        Note
        <input />
      </label>
    </FormSection>
  );
}

it('is closed by default and spans both columns', () => {
  const { container } = render(<Host />);
  expect(container.querySelector('details')?.open).toBe(false);
  expect(container.firstElementChild?.getAttribute('data-span')).toBe('full');
});

it('opens from its summary and can start open', () => {
  const { container, unmount } = render(<Host />);
  fireEvent.click(screen.getByText('Champs avancés'));
  expect(container.querySelector('details')?.open).toBe(true);
  unmount();
  const second = render(<Host initiallyOpen />);
  expect(second.container.querySelector('details')?.open).toBe(true);
});
