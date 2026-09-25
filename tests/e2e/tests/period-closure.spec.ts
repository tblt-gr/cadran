import { expect, test, type Page } from '@playwright/test';

/**
 * Closing a past month must make an ordinary write against it refused in the
 * UI, and reopening must bring it back to normal — the guarded-write
 * invariant (CLS-001 / AssertPeriodOpen) as a user actually experiences it,
 * not just as an HTTP contract test.
 *
 * This spec needs a provisioned owner (`make cadran:identity:setup` / the
 * one-time `SetupIdentityCommand`), which is a per-install secret this repo
 * never stores. Point it at one with:
 *
 *   E2E_OWNER_EMAIL=owner@example.test E2E_OWNER_PASSWORD=... pnpm --filter @cadran/e2e e2e
 *
 * Without both variables the whole file is skipped rather than failing the
 * optional `make e2e` run.
 */
const ownerEmail = process.env.E2E_OWNER_EMAIL;
const ownerPassword = process.env.E2E_OWNER_PASSWORD;

test.describe('period closure', () => {
  test.skip(
    !ownerEmail || !ownerPassword,
    'Set E2E_OWNER_EMAIL and E2E_OWNER_PASSWORD to a provisioned owner to run this spec.',
  );

  test('closing a past month refuses a write dated in it, and reopening restores it', async ({
    page,
  }) => {
    await login(page);

    // A month far enough in the past to be over, but distinct run to run: a
    // month closed by an earlier run of this same spec is reopened first,
    // below, so the close/reopen cycle stays repeatable.
    const target = monthsAgo(2);
    const targetLabel = frenchMonthYear(target);

    await page
      .getByRole('navigation', { name: 'Navigation principale' })
      .getByRole('link', { name: 'Transactions', exact: true })
      .click();

    // Only one modal is ever open at a time in this flow, so an unnamed
    // `dialog` role locator stays valid across its title change (the panel
    // relabels itself "Clôturer <mois>" / "Rouvrir <mois>" once a sub-form
    // opens); a name filter here would go stale the moment that happens.
    const closurePanel = page.getByRole('dialog');

    await test.step('open the period closure panel and select the target month', async () => {
      await page.getByRole('button', { name: 'Clôture du mois' }).click();
      await expect(closurePanel).toBeVisible();
      await closurePanel.locator('input[type="month"]').fill(target);
    });

    await test.step('reopen the month first if an earlier run left it closed', async () => {
      const reopenAction = closurePanel.getByRole('button', { name: 'Rouvrir le mois' });
      if (await reopenAction.isVisible().catch(() => false)) {
        await reopenAction.click();
        await closurePanel.getByLabel('Motif de la réouverture').fill('e2e: reset before re-run');
        await closurePanel.getByRole('button', { name: 'Confirmer la réouverture' }).click();
        await expect(closurePanel.getByText('Mois ouvert')).toBeVisible();
      }
    });

    await test.step('close the month, confirming every blocking condition it reports', async () => {
      await closurePanel.getByRole('button', { name: 'Clôturer le mois' }).click();
      await expect(
        closurePanel.getByRole('heading', { name: `Clôturer ${targetLabel}` }),
      ).toBeVisible();

      // Zero to three named conditions (unreconciled account, unexplained
      // discrepancy, pending transactions) can block: each needs its own
      // checkbox and reason, never a single "force" switch (CLS-001).
      const blockers = closurePanel.locator('fieldset');
      const blockerCount = await blockers.count();
      for (let index = 0; index < blockerCount; index += 1) {
        const blocker = blockers.nth(index);
        await blocker.getByRole('checkbox').check();
        await blocker.getByRole('textbox').fill(`e2e: confirmed for the ${targetLabel} closure`);
      }

      await closurePanel.getByRole('button', { name: 'Confirmer la clôture' }).click();
      await expect(closurePanel.getByText('Mois clôturé')).toBeVisible();
    });

    await closurePanel.getByRole('button', { name: 'Fermer' }).click();
    await expect(closurePanel).toBeHidden();

    await test.step('a transaction dated in the closed month is refused with the shared reason', async () => {
      await page.getByRole('button', { name: 'Nouvelle transaction' }).click();
      const editor = page.getByRole('dialog');
      await editor.getByLabel('Date comptable').fill(`${target}-10`);
      await editor.getByLabel('Montant').fill('-12.34');
      await editor.getByLabel('Libellé').fill('e2e: write against a closed month');
      await editor.getByRole('button', { name: 'Enregistrer' }).click();

      await expect(editor.getByRole('alert')).toContainText(
        'Cette période est clôturée : la modification est refusée.',
      );
      await editor.getByRole('button', { name: 'Fermer' }).click();
    });

    await test.step('reopening with a reason makes the month writable again', async () => {
      await page.getByRole('button', { name: 'Clôture du mois' }).click();
      await closurePanel.locator('input[type="month"]').fill(target);
      await closurePanel.getByRole('button', { name: 'Rouvrir le mois' }).click();
      await closurePanel
        .getByLabel('Motif de la réouverture')
        .fill('e2e: end of period-closure spec');
      await closurePanel.getByRole('button', { name: 'Confirmer la réouverture' }).click();

      await expect(closurePanel.getByText('Mois ouvert')).toBeVisible();
      await closurePanel.getByRole('button', { name: 'Fermer' }).click();
    });
  });
});

async function login(page: Page): Promise<void> {
  await page.goto('/');
  await page.getByLabel('Adresse e-mail').fill(ownerEmail!);
  await page.getByLabel('Mot de passe').fill(ownerPassword!);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

/** `YYYY-MM` for the first day of the month `count` months before today. */
function monthsAgo(count: number): string {
  const now = new Date();
  const date = new Date(now.getFullYear(), now.getMonth() - count, 1);

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

/** The French "month année" label the closure panel titles itself with. */
function frenchMonthYear(period: string): string {
  return new Intl.DateTimeFormat('fr', { month: 'long', timeZone: 'UTC', year: 'numeric' }).format(
    new Date(`${period}-01T12:00:00Z`),
  );
}
