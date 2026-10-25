import { expect, test, type Locator, type Page } from '@playwright/test';
import { auditAccessibility } from './a11y';
import { movementRows, settled, uniqueLabel } from './page';

/** Reach the real sequential focus target, with a bound that also detects an unreachable control. */
async function tabTo(page: Page, target: Locator, reverse = false) {
  await expect(target).toBeVisible();
  for (let count = 0; count < 160; count++) {
    if (await target.evaluate((element) => element === document.activeElement)) return;
    await page.keyboard.press(reverse ? 'Shift+Tab' : 'Tab');
  }
  throw new Error('Control is unreachable through sequential keyboard navigation');
}

async function visibleFocus(target: Locator) {
  await expect(target).toBeFocused();
  const indicator = () =>
    target.evaluate((element) => {
      const style = getComputedStyle(element);
      return `${style.outlineStyle}|${style.outlineWidth}|${style.outlineColor}|${style.boxShadow}`;
    });
  const focused = await indicator();
  // A permanent glow, such as the primary action shadow, must not count as a focus ring.
  await target.evaluate((element) => {
    if (element instanceof HTMLElement) element.blur();
  });
  const blurred = await indicator();
  await target.evaluate((element) => {
    if (element instanceof HTMLElement) element.focus();
  });
  await expect(target).toBeFocused();
  expect(focused).not.toBe(blurred);
}

export function keyboardJourneys() {
  for (const transfer of [false, true]) {
    test(`keyboard only ${transfer ? 'transfer' : 'transaction'} entry, trap and return`, async ({
      page,
    }) => {
      await page.goto('/transactions');
      await settled(page);
      const trigger = page.getByRole('button', {
        name: transfer ? 'Nouveau virement' : 'Nouvelle transaction',
        exact: true,
      });
      await tabTo(page, trigger);
      await visibleFocus(trigger);
      await page.keyboard.press('Enter');
      await settled(page);
      const dialog = page.getByRole('dialog');
      const firstField = transfer
        ? dialog.getByLabel('Compte source')
        : dialog.getByLabel('Montant', { exact: true });
      await expect(firstField).toBeFocused();
      const close = dialog.getByRole('button', { name: 'Fermer', exact: true });
      const submit = dialog.getByRole('button', {
        name: transfer ? 'Créer le virement' : 'Enregistrer',
        exact: true,
      });
      await tabTo(page, close, true);
      await page.keyboard.press('Shift+Tab');
      await expect(submit).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(close).toBeFocused();
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(trigger).toBeFocused();
      await page.keyboard.press('Enter');
      await settled(page);
      if (transfer) {
        await tabTo(page, dialog.getByLabel('Compte source'));
        await page.keyboard.press('Home');
        await page.keyboard.press('c');
        await page.keyboard.press('Enter');
        await expect(dialog.getByLabel('Compte source').locator('option:checked')).toHaveText(
          'Compte courant',
        );
        await tabTo(page, dialog.getByLabel('Compte de destination'));
        await page.keyboard.press('Home');
        await page.keyboard.press('l');
        await page.keyboard.press('Enter');
        await expect(
          dialog.getByLabel('Compte de destination').locator('option:checked'),
        ).toHaveText('Livret A');
      } else {
        const account = dialog.getByLabel('Compte', { exact: true });
        await tabTo(page, account, true);
        await page.keyboard.press('Home');
        await page.keyboard.press('c');
        await page.keyboard.press('Enter');
        await expect(account.locator('option:checked')).toHaveText('Compte courant');
      }
      const amount = dialog.getByLabel(transfer ? 'Montant (EUR)' : 'Montant', { exact: true });
      await tabTo(page, amount);
      await page.keyboard.type(transfer ? '25.00' : '-12.34');
      const label = uniqueLabel(transfer ? 'A11Y virement' : 'A11Y achat');
      await tabTo(page, dialog.getByLabel('Libellé', { exact: true }));
      await page.keyboard.type(label);
      await tabTo(page, submit);
      await visibleFocus(submit);
      await page.keyboard.press('Enter');
      await expect(dialog).toBeHidden();
      await settled(page);
      const rows = movementRows(page, label);
      await expect(rows).toHaveCount(transfer ? 2 : 1);
      await expect(
        rows.getByRole('img', {
          name: transfer ? 'Sortie de 25,00 €' : 'Sortie de 12,34 €',
          exact: true,
        }),
      ).toBeVisible();
      if (transfer)
        await expect(
          rows.getByRole('img', { name: 'Entrée de 25,00 €', exact: true }),
        ).toBeVisible();
    });
  }

  test('keyboard navigation, breadcrumb and month selector have visible focus in visual order', async ({
    page,
  }, testInfo) => {
    await page.goto('/budget');
    await settled(page);
    const mobile = testInfo.project.name === 'Mobile 360';
    const nav = page.getByRole('navigation', {
      name: mobile ? 'Navigation mobile' : 'Navigation principale',
      exact: true,
    });
    const breadcrumb = page.getByRole('navigation', { name: /ariane/i });
    const months = page.getByRole('navigation', { name: 'Mois du budget', exact: true });
    const regions = mobile ? [breadcrumb, months, nav] : [nav, breadcrumb, months];
    const targets: Locator[] = [];
    for (const region of regions) {
      const links = region.getByRole('link');
      await expect(links.first()).toBeVisible();
      targets.push(links.first());
      if ((await links.count()) > 1) targets.push(links.nth(1));
    }
    // A reload/redirect and the selected month's scrollIntoView can leave Chromium's
    // sequential starting point away from the document start even when activeElement is body.
    // Reach the shell's first keyboard stop using Shift+Tab before measuring one forward pass.
    const skip = page.getByRole('link', { name: 'Aller au contenu', exact: true });
    await tabTo(page, skip, true);
    await visibleFocus(skip);
    expect(
      await page.evaluate(() => {
        const main = document.querySelector('main');
        const mobileNav = document.querySelector('nav[aria-label="Navigation mobile"]');
        return Boolean(
          main &&
          mobileNav &&
          main.compareDocumentPosition(mobileNav) & Node.DOCUMENT_POSITION_FOLLOWING,
        );
      }),
    ).toBe(true);
    const visited: number[] = [];
    // Record one forward traversal. Reaching any target twice
    // before all targets are visited is a wrap, which must not hide an incorrect DOM order.
    for (let step = 0; step < 160 && visited.length < targets.length; step++) {
      await page.keyboard.press('Tab');
      for (const [index, target] of targets.entries()) {
        if (await target.evaluate((element) => element === document.activeElement)) {
          expect(visited).not.toContain(index);
          await visibleFocus(target);
          visited.push(index);
        }
      }
    }
    expect(visited).toEqual(targets.map((_, index) => index));
    if (mobile) {
      const more = page.getByRole('button', { name: 'Plus', exact: true });
      await tabTo(page, more);
      await visibleFocus(more);
      await page.keyboard.press('Enter');
      await auditAccessibility(page);
      await page.keyboard.press('Escape');
      await expect(more).toBeFocused();
    }
  });
}
