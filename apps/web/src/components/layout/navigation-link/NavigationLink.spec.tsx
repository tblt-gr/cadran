import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { navigationItems } from '@/lib/navigation';
import { NavigationLink } from './NavigationLink';

function renderLink(onIntent?: (href: string) => void) {
  const item = navigationItems.find((candidate) => candidate.href === '/goals')!;
  render(<NavigationLink item={item} onIntent={onIntent} onNavigate={vi.fn()} path="/" />);
  return screen.getByRole('link', { name: 'Objectifs' });
}

describe('NavigationLink intent', () => {
  afterEach(cleanup);

  it('reports the target when the pointer enters the link, not before', () => {
    const onIntent = vi.fn();
    const link = renderLink(onIntent);
    expect(onIntent).not.toHaveBeenCalled();

    fireEvent.pointerEnter(link);

    expect(onIntent).toHaveBeenCalledWith('/goals');
  });

  it('reports the target when the link receives keyboard focus', () => {
    const onIntent = vi.fn();

    fireEvent.focus(renderLink(onIntent));

    expect(onIntent).toHaveBeenCalledWith('/goals');
  });

  it('works without an intent handler', () => {
    expect(() => fireEvent.focus(renderLink())).not.toThrow();
  });
});
