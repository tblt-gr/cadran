import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';

test.describe('monthly budget', () => {
  test('opens the current month from the navigation and reads a closed month', async ({ page }) => {
    await page.goto('/');
    await navigateTo(page, 'Budget');

    await expect(page).toHaveURL(/\/budget\/\d{4}\/\d{2}$/);
    await expect(page.getByRole('heading', { level: 1, name: /^Budget de / })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Revenus du mois', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Dépenses du mois', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Revenus du mois', exact: true })).toHaveAttribute(
      'href',
      /^\/transactions\?nature=INCOME&categoryType=INCOME&period=month&month=\d{4}-\d{2}$/,
    );

    // The previous month of the demo ledger is closed: the page says so instead of hiding it.
    const months = page.getByRole('navigation', { name: 'Mois du budget' });
    // The last link is the annual report, the one before it the current month.
    const current = await months.getByRole('link').count();
    await months
      .getByRole('link')
      .nth(current - 3)
      .click();
    await settled(page);

    await expect(
      page.getByRole('status').filter({ hasText: 'Cette période est clôturée.' }),
    ).toBeVisible();
    await expect(
      page.getByRole('button', { name: 'Ajouter une dépense', exact: true }),
    ).toBeDisabled();
    await expect(
      page.getByRole('button', { name: 'Ajouter un revenu', exact: true }),
    ).toBeDisabled();
  });

  test('redirects a direct load of /budget to the current month and renders it', async ({
    page,
  }) => {
    await page.goto('/budget');
    await settled(page);

    await expect(page).toHaveURL(/\/budget\/\d{4}\/\d{2}$/);
    await expect(page.getByRole('heading', { level: 1, name: /^Budget de / })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Revenus du mois', exact: true })).toBeVisible();
  });

  test('follows the panel titles to the transactions of the month', async ({ page }) => {
    await page.goto('/budget');
    await settled(page);

    const expenses = page.getByRole('link', { name: 'Dépenses du mois', exact: true });
    await expect(expenses).toHaveAttribute(
      'href',
      /^\/transactions\?nature=EXPENSE&nature=FEE&nature=REFUND&categoryType=EXPENSE&period=month&month=\d{4}-\d{2}$/,
    );
    await expect(
      page.getByRole('link', { name: 'Virements du mois', exact: true }),
    ).toHaveAttribute('href', /^\/transactions\?nature=TRANSFER&period=month&month=\d{4}-\d{2}$/);

    await expenses.click();
    await settled(page);
    await expect(page).toHaveURL(/\/transactions\?.*categoryType=EXPENSE/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  });

  test('opens the goals panel from the month page and closes it with Escape', async ({ page }) => {
    await page.goto('/');
    await navigateTo(page, 'Budget');

    const opener = page.getByRole('button', { name: /^Objectifs/ });
    await opener.click();
    await settled(page);

    await expect(page).toHaveURL(/panel=goals$/);
    await expect(page.getByRole('dialog', { name: /^Objectifs · / })).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: /^Objectifs · / })).toBeHidden();
    await expect(opener).toBeFocused();
  });
});
