import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { AmountPrivacyProvider } from '@/components/ui/amount-privacy/AmountPrivacyProvider';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { AMOUNT_MASK, setAmountsHidden } from '@/lib/amountPrivacy';
import { formatAmount } from '@/lib/decimal';
import { AmountVisibilityToggle } from './AmountVisibilityToggle';

function Content() {
  return (
    <>
      <AmountVisibilityToggle />
      <MoneyValue value={formatAmount('1234.56', 'EUR', 'fr')} />
      <p title={formatAmount('9876.54', 'EUR', 'fr')}>Solde</p>
      <span>12,5 %</span>
    </>
  );
}

function Screen() {
  return (
    <AmountPrivacyProvider>
      <Content />
    </AmountPrivacyProvider>
  );
}

describe('amount privacy', () => {
  afterEach(() => {
    cleanup();
    act(() => setAmountsHidden(false));
    window.localStorage.clear();
  });

  it('hides and shows every amount, keeping percentages and leaking nothing in the DOM', () => {
    const { container } = render(<Screen />);
    expect(container.textContent).toContain('1 234,56');

    fireEvent.click(screen.getByRole('button', { name: 'Masquer les montants' }));

    const hiddenButton = screen.getByRole('button', { name: 'Afficher les montants' });
    expect(hiddenButton.getAttribute('aria-pressed')).toBe('true');
    expect(document.activeElement).toBe(hiddenButton);
    const html = container.innerHTML;
    expect(html).not.toMatch(/1.?234|9.?876/);
    expect(container.textContent).toContain(AMOUNT_MASK);
    expect(container.textContent).toContain('Montant masqué');
    expect(container.textContent).toContain('12,5 %');
    expect(container.querySelector('p')?.getAttribute('title')).toBe(AMOUNT_MASK);
    expect(container.querySelector('span[aria-hidden="true"]')?.textContent).toBe(AMOUNT_MASK);

    fireEvent.click(hiddenButton);
    expect(container.textContent).toContain('1 234,56');
    expect(
      screen.getByRole('button', { name: 'Masquer les montants' }).getAttribute('aria-pressed'),
    ).toBe('false');
  });

  it('persists the choice per device and tolerates unavailable storage', async () => {
    act(() => setAmountsHidden(true));
    expect(window.localStorage.getItem('cadran.amountsHidden')).toBe('1');
    act(() => setAmountsHidden(false));
    expect(window.localStorage.getItem('cadran.amountsHidden')).toBeNull();

    const original = Storage.prototype.setItem;
    Storage.prototype.setItem = () => {
      throw new Error('blocked');
    };
    try {
      act(() => setAmountsHidden(true));
      expect(formatAmount('5', 'EUR', 'fr')).toBe(AMOUNT_MASK);
    } finally {
      Storage.prototype.setItem = original;
    }
  });

  it('masks an exact zero too, as it is still an amount', () => {
    act(() => setAmountsHidden(true));
    expect(formatAmount('0', 'EUR', 'fr')).toBe(AMOUNT_MASK);
  });
});
