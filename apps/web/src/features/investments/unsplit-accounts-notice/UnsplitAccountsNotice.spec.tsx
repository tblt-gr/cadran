import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { unsplitAccount } from '@/features/investments/holdingsFixture';
import { UnsplitAccountsNotice } from './UnsplitAccountsNotice';

afterEach(cleanup);

describe('UnsplitAccountsNotice', () => {
  it('renders nothing without an unsplit account', () => {
    const { container } = render(<UnsplitAccountsNotice accounts={[]} />);
    expect(container.firstChild).toBeNull();
  });

  it('names each account and links to its valuations', () => {
    render(<UnsplitAccountsNotice accounts={[unsplitAccount]} />);
    expect(
      screen.getByText(
        'Assurance vie est valorisé en total : ses titres ne sont pas détaillés par actif.',
      ),
    ).toBeTruthy();
    expect(
      screen.getByRole('link', { name: 'Saisir une valorisation détaillée' }).getAttribute('href'),
    ).toBe(`/portfolios/${unsplitAccount.accountId}?tab=valuations`);
  });
});
