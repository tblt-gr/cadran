import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import { buildAccount } from '@/features/accounts/account-overview/overviewFixtures';
import { AccountNameCell } from './AccountNameCell';

afterEach(() => cleanup());

function structure(element: Element): unknown {
  return {
    tag: element.tagName,
    className: element.className,
    dataLabel: element.getAttribute('data-label'),
    scope: element.getAttribute('scope'),
    children: Array.from(element.children)
      .filter((child) => !child.hasAttribute('data-skeleton'))
      .map(structure),
  };
}

it.each([360, 1280])(
  'keeps the primary row header and status structure while loading at %s px',
  (width) => {
    const previousWidth = window.innerWidth;
    window.innerWidth = width;
    const account = buildAccount('00000000-0000-7000-8000-0000000000d1', 'Compte courant', {
      isPrimary: true,
    });
    const header = (value: typeof account | null) => (
      <table>
        <tbody>
          <tr>
            <th data-label="Libellé" scope="row">
              <AccountNameCell account={value} />
            </th>
          </tr>
        </tbody>
      </table>
    );
    const { container, rerender } = render(header(null));
    const skeleton = structure(container.firstElementChild!);

    rerender(header(account));

    expect(structure(container.firstElementChild!)).toEqual(skeleton);
    expect(screen.getByRole('link', { name: account.label }).getAttribute('href')).toBe(
      `/accounts/${account.id}`,
    );
    expect(screen.getByText('Principal')).toBeTruthy();
    rerender(header({ ...account, isPrimary: false }));
    expect(structure(container.firstElementChild!)).toEqual(skeleton);
    expect(screen.queryByText('Principal')).toBeNull();
    window.innerWidth = previousWidth;
  },
);
