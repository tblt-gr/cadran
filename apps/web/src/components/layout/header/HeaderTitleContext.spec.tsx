import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import {
  HeaderTitleProvider,
  useHeaderTitle,
  useHeaderTitleValue,
  useHeaderSubtitleValue,
} from './HeaderTitleContext';

function Publisher({
  path,
  title,
  subtitle,
}: {
  path: string;
  title: string | null;
  subtitle?: string;
}) {
  useHeaderTitle(path, title, subtitle);
  return null;
}

function Probe({ path }: { path: string }) {
  return <span data-testid={`header-title-${path}`}>{useHeaderTitleValue(path)}</span>;
}

afterEach(cleanup);

describe('HeaderTitleContext', () => {
  it('publishes the title only for its own path', () => {
    render(
      <HeaderTitleProvider>
        <Publisher path="/budget/2025" title="2025" />
        <Probe path="/budget/2025" />
        <Probe path="/budget/2026/09" />
      </HeaderTitleProvider>,
    );
    expect(screen.getByTestId('header-title-/budget/2025').textContent).toBe('2025');
    expect(screen.getByTestId('header-title-/budget/2026/09').textContent).toBe('');
  });

  it('updates the title when it changes and clears it on unmount', () => {
    const tree = render(
      <HeaderTitleProvider>
        <Publisher path="/budget/2026/09" title="Septembre 2026" />
        <Probe path="/budget/2026/09" />
      </HeaderTitleProvider>,
    );
    expect(screen.getByTestId('header-title-/budget/2026/09').textContent).toBe('Septembre 2026');
    tree.rerender(
      <HeaderTitleProvider>
        <Publisher path="/budget/2026/09" title="Août 2026" />
        <Probe path="/budget/2026/09" />
      </HeaderTitleProvider>,
    );
    expect(screen.getByTestId('header-title-/budget/2026/09').textContent).toBe('Août 2026');
    tree.unmount();
    render(
      <HeaderTitleProvider>
        <Probe path="/budget/2026/09" />
      </HeaderTitleProvider>,
    );
    expect(screen.getByTestId('header-title-/budget/2026/09').textContent).toBe('');
  });
});

it('keeps account subtitles scoped to the route', () => {
  function Subtitle({ path }: { path: string }) {
    return <span data-testid={path}>{useHeaderSubtitleValue(path)}</span>;
  }
  render(
    <HeaderTitleProvider>
      <Publisher path="/accounts/a" title="Compte" subtitle="Banque · Livret" />
      <Subtitle path="/accounts/a" />
      <Subtitle path="/accounts/b" />
    </HeaderTitleProvider>,
  );
  expect(screen.getByTestId('/accounts/a').textContent).toBe('Banque · Livret');
  expect(screen.getByTestId('/accounts/b').textContent).toBe('');
});
