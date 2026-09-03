import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { Toast } from './Toast';

describe('Toast', () => {
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it('announces the message outside the page flow', () => {
    const { container } = render(
      <div>
        <p>Liste</p>
        <Toast onDismiss={vi.fn()}>Le compte a été enregistré.</Toast>
      </div>,
    );

    const toast = screen.getByRole('status');
    expect(toast.textContent).toContain('Le compte a été enregistré.');
    expect(container.contains(toast)).toBe(false);
    expect(document.body.contains(toast)).toBe(true);
  });

  it('dismisses from the close control without taking focus', () => {
    const onDismiss = vi.fn();
    render(<Toast onDismiss={onDismiss}>Le compte a été enregistré.</Toast>);

    const close = screen.getByRole('button', { name: 'Fermer' });
    expect(document.activeElement).not.toBe(close);

    fireEvent.click(close);
    expect(onDismiss).toHaveBeenCalledOnce();
  });

  it('dismisses itself after a short delay', () => {
    vi.useFakeTimers();
    const onDismiss = vi.fn();
    render(<Toast onDismiss={onDismiss}>Le compte a été enregistré.</Toast>);

    vi.advanceTimersByTime(500);
    expect(onDismiss).not.toHaveBeenCalled();

    vi.advanceTimersByTime(10_000);
    expect(onDismiss).toHaveBeenCalledOnce();
  });
});
