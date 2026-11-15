import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';
import { ownerCredentials } from './support/session';

test.describe('session expiry', () => {
  test('returns to sign-in on any request and restores the page after signing in again', async ({
    page,
  }) => {
    await page.goto('/');
    await settled(page);
    await navigateTo(page, 'Budget');

    await page.context().clearCookies({ name: 'cadran_session' });
    await page
      .getByRole('navigation', { name: 'Budget et transactions' })
      .getByRole('link', { name: 'Transactions', exact: true })
      .click();

    await expect(page.getByRole('heading', { name: 'Connexion' })).toBeVisible();
    await expect(page).toHaveURL(/\/connexion$/);

    const { email, password } = ownerCredentials();
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Se connecter' }).click();

    await expect(page).toHaveURL(/\/transactions\?period=month&month=\d{4}-\d{2}/);
    await settled(page);
  });
});
