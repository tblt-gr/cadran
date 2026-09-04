import type { RateApplication } from '@cadran/api-client';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ScalePreview } from './ScalePreview';

const scale = [
  { lowerBound: '0', upperBound: '10000', percentage: '4' },
  { lowerBound: '10000', upperBound: '', percentage: '2' },
];

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

function balanceField() {
  return screen.getByLabelText('Solde d’exemple') as HTMLInputElement;
}

function typeBalance(value: string) {
  fireEvent.change(balanceField(), { target: { value } });
  act(() => {
    vi.advanceTimersByTime(300);
  });
}

function renderPreview(rateApplication: RateApplication = 'MARGINAL') {
  render(<ScalePreview brackets={scale} rateApplication={rateApplication} />);
}

describe('ScalePreview', () => {
  it('shows nothing yet before the reader has typed a balance, not an error', () => {
    renderPreview();

    expect(screen.getByRole('status').textContent).toBe('');
    expect(balanceField().hasAttribute('aria-invalid')).toBe(false);
  });

  it('does not submit the surrounding form on Enter, since it carries nothing to save', () => {
    renderPreview();

    const enterPrevented = fireEvent.keyDown(balanceField(), { key: 'Enter', cancelable: true });
    // The guard must be specific to Enter — one that swallowed every key
    // would leave the field unable to receive digits at all.
    const digitPrevented = fireEvent.keyDown(balanceField(), { key: '1', cancelable: true });

    expect(enterPrevented).toBe(false);
    expect(digitPrevented).toBe(true);
  });

  it('bounds the field to the longest canonical decimal DecimalValue can store', () => {
    renderPreview();

    expect(balanceField().maxLength).toBe(51);
  });

  it('names the bracket rate reached in MARGINAL mode without claiming it covers the whole balance', () => {
    renderPreview('MARGINAL');

    typeBalance('15000');

    const status = screen.getByRole('status').textContent ?? '';
    expect(status).toContain('2 %');
    expect(status).not.toMatch(/totalité du solde/);
  });

  it('states the flat rate applies to the whole balance in FLAT_BY_BRACKET mode, naming the bracket reached', () => {
    renderPreview('FLAT_BY_BRACKET');

    typeBalance('15000');

    const status = screen.getByRole('status').textContent ?? '';
    expect(status).toContain('totalité du solde');
    // The bracket reached at 15 000 is the second one, at 2 % — not the
    // first bracket's 4 %, which a component that read the wrong bracket
    // would still make this test pass without this assertion.
    expect(status).toContain('2 %');
  });

  it('answers a different message per application mode for the same balance', () => {
    renderPreview('MARGINAL');
    typeBalance('15000');
    const marginalText = screen.getByRole('status').textContent;
    cleanup();

    renderPreview('FLAT_BY_BRACKET');
    typeBalance('15000');
    const flatText = screen.getByRole('status').textContent;

    expect(marginalText).not.toBe(flatText);
  });

  it('stays quiet for a trailing dot still being typed, rather than blaming the digits-and-dot rule', () => {
    renderPreview();

    typeBalance('1000.');

    expect(balanceField().hasAttribute('aria-invalid')).toBe(false);
    expect(screen.getByRole('status').textContent).toBe('');
  });

  it('names a negative balance as such, not as a malformed figure', () => {
    renderPreview();

    typeBalance('-500');

    const describedBy = balanceField().getAttribute('aria-describedby');
    expect(balanceField().getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(describedBy ?? '')?.textContent).toBe(
      'Un solde d’exemple ne peut pas être négatif.',
    );
    expect(screen.getByRole('status').textContent).toBe('');
  });

  it('names a padded balance as such, not as a malformed figure', () => {
    renderPreview();

    typeBalance('007');

    const describedBy = balanceField().getAttribute('aria-describedby');
    expect(balanceField().getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(describedBy ?? '')?.textContent).toBe(
      'Retirez le zéro inutile en tête du solde.',
    );
    expect(screen.getByRole('status').textContent).toBe('');
  });

  it('settles on scale content, not the array identity a caller rebuilt', () => {
    const { rerender } = render(<ScalePreview brackets={[...scale]} rateApplication="MARGINAL" />);
    typeBalance('15000');
    expect(screen.getByRole('status').textContent).toContain('2 %');

    rerender(<ScalePreview brackets={[...scale]} rateApplication="MARGINAL" />);
    act(() => {
      vi.advanceTimersByTime(299);
    });
    // Same bounds, new array: a reference-equality debounce would go quiet
    // here and stay blank until a later keystroke.
    expect(screen.getByRole('status').textContent).toContain('2 %');
  });

  it('marks the field invalid and names the reason through the field itself, once settled', () => {
    renderPreview();
    const hintId = balanceField().getAttribute('aria-describedby');

    typeBalance('4,5');

    const field = balanceField();
    expect(field.getAttribute('aria-invalid')).toBe('true');
    const describedBy = field.getAttribute('aria-describedby');
    expect(describedBy).toBeTruthy();
    const reason = document.getElementById(describedBy ?? '');
    expect(reason?.textContent).toBe(
      'Saisissez un solde en chiffres, avec un point pour séparateur décimal.',
    );
    // No focus move accompanies the field turning invalid mid-typing, so the
    // reason must announce itself rather than wait to be read on demand.
    expect(reason?.getAttribute('aria-live')).toBe('polite');
    // The region carrying it is the one that was already on the page as the
    // hint, not one that turns live at the instant of the swap — several
    // screen readers skip that first announcement.
    expect(describedBy).toBe(hintId);
    // The reason lives with the field, not duplicated in the status region.
    expect(screen.getByRole('status').textContent).toBe('');
  });

  it('keeps the message region live from the first render, while it still holds the hint', () => {
    renderPreview();

    const hint = document.getElementById(balanceField().getAttribute('aria-describedby') ?? '');

    expect(hint?.textContent).toBe(
      'Un aperçu de saisie, sans lien avec le solde réel d’un compte.',
    );
    expect(hint?.getAttribute('aria-live')).toBe('polite');
  });

  it('clears the invalid state once the balance is corrected, rather than leaving it stuck', () => {
    renderPreview();
    typeBalance('4,5');
    expect(balanceField().getAttribute('aria-invalid')).toBe('true');

    fireEvent.change(balanceField(), { target: { value: '5000' } });
    act(() => {
      vi.advanceTimersByTime(299);
    });
    // Still mid-debounce: neither the old nor a new verdict should show.
    expect(balanceField().hasAttribute('aria-invalid')).toBe(false);

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(balanceField().hasAttribute('aria-invalid')).toBe(false);
    expect(screen.getByRole('status').textContent).toContain('4 %');
  });

  it('gives a distinct message for an incomplete draft scale, not the invalid-balance message', () => {
    render(
      <ScalePreview
        brackets={[
          { lowerBound: '0', upperBound: '', percentage: '' },
          { lowerBound: '', upperBound: '', percentage: '' },
        ]}
        rateApplication="MARGINAL"
      />,
    );

    typeBalance('5000');

    expect(screen.getByRole('status').textContent).toBe(
      'Le barème n’est pas encore complet : il doit partir de zéro, ses tranches se succéder sans trou ni recouvrement, et la dernière rester sans plafond.',
    );
    expect(balanceField().hasAttribute('aria-invalid')).toBe(false);
  });

  it('does not blame the bounds when they tile correctly and only the reached rate is blank', () => {
    render(
      <ScalePreview
        brackets={[
          { lowerBound: '0', upperBound: '10000', percentage: '4' },
          { lowerBound: '10000', upperBound: '', percentage: '' },
        ]}
        rateApplication="MARGINAL"
      />,
    );

    typeBalance('15000');

    const status = screen.getByRole('status').textContent ?? '';
    expect(status).toMatch(/n’a pas encore de taux/);
    expect(status).not.toMatch(/pas encore complet/);
    expect(balanceField().hasAttribute('aria-invalid')).toBe(false);
  });

  it('still names the bracket reached below a tier whose rate is not filled in yet', () => {
    render(
      <ScalePreview
        brackets={[
          { lowerBound: '0', upperBound: '10000', percentage: '4' },
          { lowerBound: '10000', upperBound: '', percentage: '' },
        ]}
        rateApplication="MARGINAL"
      />,
    );

    typeBalance('5000');

    expect(screen.getByRole('status').textContent).toContain('4 %');
  });

  it('rejects a scale only well-formed once its rows are reordered, since row order is what is submitted', () => {
    render(
      <ScalePreview
        brackets={[
          { lowerBound: '10000', upperBound: '', percentage: '2' },
          { lowerBound: '0', upperBound: '10000', percentage: '4' },
        ]}
        rateApplication="MARGINAL"
      />,
    );

    typeBalance('15000');

    expect(screen.getByRole('status').textContent).toMatch(/pas encore complet/);
  });

  it('stays quiet until the debounce settles, and only then answers', () => {
    renderPreview();

    fireEvent.change(balanceField(), { target: { value: '15000' } });
    act(() => {
      vi.advanceTimersByTime(299);
    });
    expect(screen.getByRole('status').textContent).toBe('');

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(screen.getByRole('status').textContent).toContain('2 %');
  });

  it('answers once for a run of keystrokes rather than once per keystroke', () => {
    renderPreview();

    for (const partial of ['1', '15', '150', '1500', '15000']) {
      fireEvent.change(balanceField(), { target: { value: partial } });
      act(() => {
        vi.advanceTimersByTime(100);
      });
      // Never enough time for any single keystroke to settle on its own.
      expect(screen.getByRole('status').textContent).toBe('');
    }

    act(() => {
      vi.advanceTimersByTime(200);
    });
    expect(screen.getByRole('status').textContent).toContain('2 %');
  });

  it('goes quiet again while a settled balance is edited, rather than keep naming the old bracket', () => {
    renderPreview();
    typeBalance('15000');
    expect(screen.getByRole('status').textContent).toContain('2 %');

    fireEvent.change(balanceField(), { target: { value: '5000' } });
    act(() => {
      vi.advanceTimersByTime(299);
    });
    // 5000 belongs to the first bracket (4 %): a stale message still
    // reading "2 %" here would describe a balance no longer on screen.
    expect(screen.getByRole('status').textContent).toBe('');

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(screen.getByRole('status').textContent).toContain('4 %');
  });

  it('rearms the same delay when the scale itself changes, not only the balance', () => {
    const { rerender } = render(<ScalePreview brackets={scale} rateApplication="MARGINAL" />);
    typeBalance('15000');
    expect(screen.getByRole('status').textContent).toContain('2 %');

    const widened = [
      { lowerBound: '0', upperBound: '20000', percentage: '4' },
      { lowerBound: '20000', upperBound: '', percentage: '2' },
    ];
    rerender(<ScalePreview brackets={widened} rateApplication="MARGINAL" />);
    act(() => {
      vi.advanceTimersByTime(299);
    });
    // 15 000 now belongs to the widened first bracket; a message still
    // announcing the old scale's answer would describe a scale no longer
    // being edited.
    expect(screen.getByRole('status').textContent).toBe('');

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(screen.getByRole('status').textContent).toContain('4 %');
  });
});
