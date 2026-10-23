import { expect, test } from '@playwright/test';
import { settled } from './support/page';

test('pins, orders, reloads, clears and resets monthly account balances', async ({
  page,
  context,
}) => {
  const endpoint = '/api/v1/reports/monthly/recap-preferences';
  await page.goto('/budget');
  await settled(page);
  const originalResponse = await page.request.get(endpoint);
  expect(originalResponse.ok()).toBeTruthy();
  const original = await originalResponse.json();
  const configure = page.getByRole('button', { name: 'Configurer les comptes épinglés' });
  const panel = page.getByRole('region', { name: 'Soldes épinglés' });
  try {
    await configure.click();
    let dialog = page.getByRole('dialog', { name: 'Configurer les comptes épinglés' });
    await settled(page);
    for (const checkbox of await dialog.getByRole('checkbox').all())
      if (await checkbox.isChecked()) await checkbox.uncheck();
    const options = dialog.getByRole('checkbox');
    expect(await options.count()).toBeGreaterThanOrEqual(2);
    await options.nth(0).check();
    await options.nth(1).check();
    const order = dialog.getByRole('listitem');
    const secondName = (await order.nth(1).locator('span').first().innerText()).trim();
    const up = dialog.getByRole('button', { name: `Monter ${secondName}`, exact: true });
    await up.focus();
    await page.keyboard.press('Enter');
    await expect(order.first()).toContainText(secondName);
    await dialog.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(dialog).toBeHidden();
    await settled(page);
    await expect(configure).toBeFocused();
    await expect(panel.getByRole('listitem')).toHaveCount(2);
    await expect(panel.getByRole('listitem').first()).toContainText(secondName);
    await page.reload();
    await settled(page);
    await expect(panel.getByRole('listitem').first()).toContainText(secondName);
    await expect(panel.getByRole('link').first()).toHaveAttribute(
      'href',
      /\/accounts\/[^?]+\?month=\d{4}-\d{2}$/,
    );
    await page.setViewportSize({ width: 360, height: 800 });
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    await configure.click();
    dialog = page.getByRole('dialog', { name: 'Configurer les comptes épinglés' });
    await settled(page);
    for (const checkbox of await dialog.getByRole('checkbox').all())
      if (await checkbox.isChecked()) await checkbox.uncheck();
    await dialog.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(dialog).toBeHidden();
    await page.reload();
    await settled(page);
    await expect(panel).toContainText('Aucun compte épinglé. Configurez les comptes à afficher.');
    await configure.click();
    await settled(page);
    await page.getByRole('button', { name: 'Rétablir les comptes par défaut' }).click();
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Enregistrer', exact: true })
      .click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await settled(page);
    const defaultsResponse = await page.request.get(endpoint);
    expect((await defaultsResponse.json()).visibleAccountIds).toBeNull();
  } finally {
    const current = await (await page.request.get(endpoint)).json();
    const token = (await context.cookies()).find((cookie) => cookie.name === 'csrf_token')?.value;
    const restored = await page.request.put(endpoint, {
      headers: { 'X-CSRF-TOKEN': decodeURIComponent(token ?? '') },
      data: { ...original, version: current.version },
    });
    expect(restored.ok()).toBeTruthy();
  }
});
