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

    let expectedPath = new URL(page.url()).pathname;
    await page.context().clearCookies({ name: 'cadran_session' });
    // A background query of the open page may already have found the session closed.
    const transactions = page
      .getByRole('navigation', { name: 'Navigation principale' })
      .getByRole('link', { name: 'Transactions', exact: true });
    if (await transactions.isVisible()) {
      await transactions.click();
      expectedPath = '/transactions';
    }

    await expect(page.getByRole('heading', { name: 'Connexion' })).toBeVisible();
    await expect(page).toHaveURL(/\/connexion$/);

    const { email, password } = ownerCredentials();
    await page.getByLabel('Adresse e-mail').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Se connecter' }).click();

    await expect(page).toHaveURL(new RegExp(`${expectedPath}(\\?|$)`));
    await settled(page);
  });
});
