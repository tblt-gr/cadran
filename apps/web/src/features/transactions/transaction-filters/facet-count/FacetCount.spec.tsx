import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { FacetCount } from './FacetCount';

/** The count sits inside the option's label, as in the filter lists: the label names the checkbox. */
function renderOption(count: number | null) {
  return render(
    <label>
      <input type="checkbox" />
      <span>Comptabilisée</span>
      <FacetCount count={count} />
    </label>,
  );
}

describe('FacetCount', () => {
  afterEach(cleanup);

  it('shows the number and reads it as a sentence after the label', () => {
    const { container } = renderOption(184);

    expect(screen.getByRole('checkbox', { name: 'Comptabilisée, 184 transactions' })).toBeTruthy();
    const visible = container.querySelector('[aria-hidden="true"]');
    expect(visible?.textContent).toBe('184');
  });

  it('reads zero and one as singular, as French does', () => {
    renderOption(1);
    expect(screen.getByRole('checkbox', { name: 'Comptabilisée, 1 transaction' })).toBeTruthy();
    cleanup();

    renderOption(0);
    expect(screen.getByRole('checkbox', { name: 'Comptabilisée, 0 transaction' })).toBeTruthy();
  });

  it('keeps a very large count as plain digits in both the number and the sentence', () => {
    const { container } = renderOption(1234567);

    expect(container.querySelector('[aria-hidden="true"]')?.textContent).toBe('1234567');
    expect(
      screen.getByRole('checkbox', { name: 'Comptabilisée, 1234567 transactions' }),
    ).toBeTruthy();
  });

  it('holds a skeleton in the value tag while the first answer is pending', () => {
    const { container } = renderOption(null);

    expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(1);
    const tag = container.querySelector('[data-skeleton]')?.parentElement;
    expect(tag?.getAttribute('aria-hidden')).toBe('true');
    expect(tag?.textContent).toBe('');
    // The option stays named by its bare label and the skeleton adds no announcement.
    expect(screen.getByRole('checkbox', { name: 'Comptabilisée' })).toBeTruthy();
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('puts the loaded number in the same tag the skeleton took the place of', () => {
    const pending = renderOption(null);
    const skeletonTag = pending.container.querySelector('[data-skeleton]')?.parentElement;
    const skeletonClass = skeletonTag?.className;
    cleanup();

    const loaded = renderOption(7);
    expect(loaded.container.querySelector('[aria-hidden="true"]')?.className).toBe(skeletonClass);
  });
});
