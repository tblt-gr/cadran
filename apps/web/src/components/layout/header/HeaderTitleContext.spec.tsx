import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { HeaderTitleProvider, useHeaderTitle, useHeaderTitleValue } from './HeaderTitleContext';

function Publisher({ path, title }: { path: string; title: string | null }) {
  useHeaderTitle(path, title);
  return null;
}

function Probe({ path }: { path: string }) {
  return <span data-testid={`header-title-${path}`}>{useHeaderTitleValue(path)}</span>;
}

describe('HeaderTitleContext', () => {
  afterEach(cleanup);

  it('publishes the title only for its own path', () => {
    render(
      <HeaderTitleProvider>
        <Publisher path="/reports/annual/2025" title="2025" />
        <Probe path="/reports/annual/2025" />
        <Probe path="/reports" />
      </HeaderTitleProvider>,
    );
    expect(screen.getByTestId('header-title-/reports/annual/2025').textContent).toBe('2025');
    expect(screen.getByTestId('header-title-/reports').textContent).toBe('');
  });

  it('updates the title when it changes and clears it on unmount', () => {
    const tree = render(
      <HeaderTitleProvider>
        <Publisher path="/reports" title="Septembre 2026" />
        <Probe path="/reports" />
      </HeaderTitleProvider>,
    );
    expect(screen.getByTestId('header-title-/reports').textContent).toBe('Septembre 2026');
    tree.rerender(
      <HeaderTitleProvider>
        <Publisher path="/reports" title="Août 2026" />
        <Probe path="/reports" />
      </HeaderTitleProvider>,
    );
    expect(screen.getByTestId('header-title-/reports').textContent).toBe('Août 2026');
    tree.unmount();
    render(
      <HeaderTitleProvider>
        <Probe path="/reports" />
      </HeaderTitleProvider>,
    );
    expect(screen.getByTestId('header-title-/reports').textContent).toBe('');
  });
});
