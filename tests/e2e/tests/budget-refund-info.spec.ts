import { expect, test } from '@playwright/test';
import { settled } from './support/page';
import { ownerStorageState } from './support/session';

for (const width of [360, 1280]) {
  test(`refund information precedes the amount in the budget at ${width}px`, async ({
    browser,
  }) => {
    const context = await browser.newContext({
      baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
      ignoreHTTPSErrors: true,
      storageState: ownerStorageState,
      viewport: { width, height: 800 },
      hasTouch: width === 360,
      isMobile: width === 360,
    });
    try {
      const page = await context.newPage();
      await page.route('**/api/v1/reports/monthly/ledger/expense/*/movements**', (route) =>
        route.fulfill({
          json: {
            items: [
              {
                id: 'refund',
                transactionId: 'refund',
                transferId: null,
                bookedOn: '2026-11-02',
                label: 'Remboursement médecin',
                amount: { value: '18.00', assetCode: 'EUR' },
                direction: null,
                counterpartAccountId: null,
                counterpartAccountLabel: null,
                pending: false,
                upcoming: false,
              },
            ],
            hasMore: false,
            nextCursor: null,
            pageSize: 50,
          },
        }),
      );
      await page.goto('/budget');
      await settled(page);
      await page
        .getByRole('region', { name: 'Dépenses du mois' })
        .getByRole('button', { name: /^Afficher les mouvements de / })
        .first()
        .click();
      const info = page.getByRole('button', { name: 'Comprendre ce remboursement' });
      await expect(info).toBeVisible();
      const amount = info.locator('xpath=following-sibling::*[1]');
      await expect(amount).toContainText('18,00');
      expect((await info.boundingBox())!.x).toBeLessThan((await amount.boundingBox())!.x);
      await info.click();
      const dialog = page.getByRole('dialog', { name: 'Remboursement dans le budget' });
      await expect(dialog).toContainText('réduit les dépenses du mois où il est reçu');
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(info).toBeFocused();
    } finally {
      await context.close();
    }
  });
}
