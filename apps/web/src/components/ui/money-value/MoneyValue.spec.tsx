import { cleanup, render } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { MoneyValue } from './MoneyValue';

afterEach(cleanup);

it('keeps the amount spans and classes while its value loads', () => {
  const { container, rerender } = render(
    <MoneyValue value={null} skeletonClassName="amount-placeholder" />,
  );
  const outer = container.firstElementChild!;
  const tail = outer.firstElementChild!;
  expect(outer.hasAttribute('data-visual-mask')).toBe(true);
  expect(tail.tagName).toBe('SPAN');
  expect(tail.querySelector('[data-skeleton]')?.className).toContain('amount-placeholder');
  expect(outer.textContent).toBe('');
  const tailClassName = tail.className;
  rerender(<MoneyValue value="231,10 €" />);
  expect(container.firstElementChild?.tagName).toBe(outer.tagName);
  expect(container.firstElementChild?.firstElementChild?.className).toBe(tailClassName);
  expect(container.querySelector('[data-skeleton]')).toBeNull();
  expect(container.textContent).toBe('231,10 €');
});

it('preserves unbreakable currency tails and grouped amount break opportunities', () => {
  const { container } = render(<MoneyValue value={'1\u202f234,50 €'} />);
  expect(container.querySelectorAll('wbr')).toHaveLength(1);
  expect(container.textContent).toBe('1\u202f234,50 €');
  expect(container.firstElementChild?.lastElementChild?.textContent).toBe('234,50 €');
});
