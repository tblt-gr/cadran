import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { BusinessDateField } from './business-date-field/BusinessDateField';
import { MoneyValue } from './money-value/MoneyValue';
import { StatusBadge } from './status-badge/StatusBadge';

// The e2e visual comparisons mask every element carrying data-visual-mask, so the shared
// amount and date primitives must keep the attribute.
describe('visual comparison mask', () => {
  afterEach(cleanup);

  it('marks the shared amount primitive', () => {
    const { container } = render(<MoneyValue value="1 234,56 €" />);

    expect(container.firstElementChild?.hasAttribute('data-visual-mask')).toBe(true);
  });

  it('marks the shared business date primitive', () => {
    render(<BusinessDateField onChange={() => undefined} today="2026-09-02" value="2026-08-21" />);

    expect(screen.getByLabelText('Date métier').closest('[data-visual-mask]')).not.toBeNull();
  });

  it('masks a status badge only when asked to', () => {
    const { container } = render(
      <>
        <StatusBadge tone="warning" visualMask>
          Valorisation la plus ancienne : 14 jours
        </StatusBadge>
        <StatusBadge tone="positive">À jour</StatusBadge>
      </>,
    );

    const [masked, plain] = [...container.querySelectorAll('span[class]')].filter((node) =>
      node.className.includes('badge'),
    );
    expect(masked?.hasAttribute('data-visual-mask')).toBe(true);
    expect(plain?.hasAttribute('data-visual-mask')).toBe(false);
  });
});
