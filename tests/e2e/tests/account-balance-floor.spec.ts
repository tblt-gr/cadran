import { expect, test } from '@playwright/test';
import { resolveFixtures } from './support/a11y';
import { settled } from './support/page';

const snapshot = {
  id: '00000000-0000-7000-8000-0000000000b1',
  accountId: '00000000-0000-7000-8000-0000000000c1',
  asOf: '2026-01-15',
  amount: { value: '100.00', assetCode: 'EUR' },
  source: 'MANUAL',
  reconciliationStatus: 'UNRECONCILED',
  comment: null,
  active: true,
  version: 1,
  recordedAt: '2026-01-16T09:00:00+00:00',
  supersededAt: null,
};

for (const width of [1440, 360]) {
  test(`a refused snapshot withdrawal is announced with its date and stays confirmable at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    const fixtures = await resolveFixtures(page);
    await page.route('**/api/v1/accounts/*/balances?*', (route) =>
      route.fulfill({ json: { items: [snapshot], page: 1, pageSize: 100, total: 1 } }),
    );
    await page.route('**/api/v1/accounts/*/balances/*/withdraw', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/problem+json',
        json: {
          type: '/problems/account-balance-insufficient',
          title: 'Insufficient balance',
          status: 422,
          accountId: snapshot.accountId,
          field: 'amount',
          firstNegativeOn: '2026-01-20',
        },
      }),
    );
    await page.goto(fixtures.account);
    await settled(page);
    await page
      .getByRole('navigation', { name: 'Sections du compte' })
      .getByRole('link', { name: 'Solde' })
      .click();
    await settled(page);

    // Keyboard only: open the row menu, pick the withdrawal, confirm.
    const actions = page.getByRole('button', { name: 'Actions pour le relevé du 15/01/2026' });
    await actions.focus();
    await actions.press('Enter');
    const withdraw = page.getByRole('button', { name: 'Retirer le relevé du 15/01/2026' });
    await withdraw.focus();
    await withdraw.press('Enter');
    const confirm = page.getByRole('button', { name: 'Retirer le relevé', exact: true });
    await expect(confirm).toBeFocused();
    await confirm.press('Enter');

    const alert = page.getByRole('alert').filter({ hasText: 'Retrait impossible' });
    await expect(alert).toBeVisible();
    await expect(alert.locator('time')).toHaveAttribute('datetime', '2026-01-20');
    await expect(alert).not.toContainText('100');
    await expect(
      page.getByRole('button', { name: 'Retirer le relevé', exact: true }),
    ).toBeVisible();
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
  });
}
