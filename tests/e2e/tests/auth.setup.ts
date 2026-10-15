import { expect, test as setup } from '@playwright/test';
import { ownerCredentials, ownerStorageState } from './support/session';

// Signs in once for the whole run; every other project starts from the stored session.
setup('sign in as the e2e owner', async ({ page }) => {
  const { email, password } = ownerCredentials();

  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(email);
  await page.getByLabel('Mot de passe').fill(password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('navigation', { name: 'Navigation principale' })).toBeVisible();

  await page.context().storageState({ path: ownerStorageState });
});
