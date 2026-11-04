import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategorizationTabs } from './CategorizationTabs';

describe('CategorizationTabs', () => {
  afterEach(cleanup);

  it('underlines the current tab and switches on click', () => {
    const onChange = vi.fn();
    render(<CategorizationTabs onChange={onChange} value="ALL" />);

    expect(screen.getByRole('button', { name: 'Toutes' }).getAttribute('aria-current')).toBe(
      'page',
    );
    fireEvent.click(screen.getByRole('button', { name: 'À catégoriser' }));

    expect(onChange).toHaveBeenCalledWith('NONE');
  });

  it('renders inert tabs while disabled', () => {
    render(<CategorizationTabs disabled onChange={vi.fn()} value="ALL" />);

    expect(screen.queryByRole('button', { name: 'Toutes' })).toBeNull();
    expect(screen.getByText('À catégoriser').getAttribute('aria-disabled')).toBe('true');
  });
});
