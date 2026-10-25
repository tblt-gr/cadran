import { expect, type Page } from '@playwright/test';
import type { AuditFixtures } from './a11y';
import { movementRows, settled, uniqueLabel } from './page';

export const modalCases = [
  'transaction create',
  'transaction edit',
  'transaction error',
  'quick category',
  'transfer create',
  'transfer error',
  'account step 1',
  'account step 2',
  'account step 3',
  'account edit',
  'category create',
  'category edit',
  'budget target create',
  'budget target edit',
  'goal create',
  'goal edit',
  'operation create',
  'operation edit',
  'valuation create',
  'valuation details',
  'valuation replace',
  'insurance contract',
  'insurance fee',
  'insurance rate',
  'insurance operation',
  'insurance valuation',
  'insurance valuation details',
] as const;
type ModalCase = (typeof modalCases)[number];

export async function openAuditModal(page: Page, name: ModalCase, fixtures: AuditFixtures) {
  const route = name.startsWith('account')
    ? '/accounts'
    : name.startsWith('category')
      ? '/transactions/categories'
      : name.startsWith('budget')
        ? fixtures.plan
        : name.startsWith('goal')
          ? '/goals'
          : name.startsWith('operation')
            ? `${fixtures.portfolio}?tab=operations`
            : name.startsWith('valuation')
              ? `${fixtures.portfolio}?tab=valuations`
              : name === 'insurance operation'
                ? `${fixtures.insurance}?tab=operations`
                : name.startsWith('insurance valuation')
                  ? `${fixtures.insurance}?tab=valuations`
                  : name.startsWith('insurance')
                    ? `${fixtures.insurance}?tab=contract`
                    : '/transactions';
  await page.goto(route);
  await settled(page);
  const button = (label: string | RegExp) =>
    page.getByRole('button', { name: label, exact: typeof label === 'string' });
  if (name === 'transaction edit') {
    // Earlier projects may have just created a transfer, whose legs cannot be edited.
    // Create and select our own ordinary transaction instead of depending on list order.
    const label = uniqueLabel('A11Y transaction modifiable');
    await button('Nouvelle transaction').click();
    await settled(page);
    const creation = page.getByRole('dialog', { name: 'Nouvelle transaction' });
    await creation.getByLabel('Compte', { exact: true }).selectOption({ label: 'Compte courant' });
    await creation.getByLabel('Montant', { exact: true }).fill('-1.00');
    await creation.getByLabel('Libellé', { exact: true }).fill(label);
    await creation.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(creation).toBeHidden();
    await settled(page);
    const row = movementRows(page, label);
    await expect(row).toHaveCount(1);
    await row.getByRole('button', { name: /^Actions de la transaction/ }).click();
    await button(/^Modifier/).click();
  } else if (
    name === 'category edit' ||
    name === 'account edit' ||
    name === 'goal edit' ||
    name === 'operation edit' ||
    name === 'valuation replace'
  ) {
    const actions = name.startsWith('category')
      ? /^Actions de la catégorie/
      : name.startsWith('account')
        ? /^Actions du compte/
        : name.startsWith('goal')
          ? /^Actions de l’objectif/
          : name.startsWith('operation')
            ? /^Actions pour l’opération/
            : /^Actions pour la valorisation/;
    await expect(button(actions).first()).toBeVisible();
    await button(actions).first().click();
    await button(name === 'valuation replace' ? /^Remplacer/ : /^Modifier/)
      .first()
      .click();
  } else if (name.startsWith('transaction') || name === 'quick category') {
    await button('Nouvelle transaction').click();
    if (name === 'quick category') {
      const label = uniqueLabel('A11Y catégorie');
      await page.getByRole('combobox', { name: 'Catégorie', exact: true }).fill(label);
      await page.getByRole('option', { name: `Créer « ${label} »` }).click();
    }
    if (name === 'transaction error') await button('Enregistrer').click();
  } else if (name.startsWith('transfer')) {
    await button('Nouveau virement').click();
    if (name === 'transfer error') await button('Créer le virement').click();
  } else if (name.startsWith('account')) {
    await button('Nouveau compte').click();
    if (name !== 'account step 1') await button('Continuer').click();
    if (name === 'account step 3') {
      await page
        .getByRole('dialog')
        .getByLabel('Libellé', { exact: true })
        .fill(uniqueLabel('A11Y compte'));
      await page
        .getByLabel('Date d’ouverture')
        .fill(new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Paris' }).format(new Date()));
      await button('Continuer').click();
    }
  } else {
    const trigger =
      name === 'category create'
        ? 'Nouvelle catégorie'
        : name === 'budget target create'
          ? 'Ajouter un objectif'
          : name === 'budget target edit'
            ? 'Modifier'
            : name === 'goal create'
              ? 'Nouvel objectif'
              : name === 'operation create' || name === 'insurance operation'
                ? 'Nouvelle opération'
                : name.startsWith('valuation') || name.startsWith('insurance valuation')
                  ? 'Enregistrer une valorisation'
                  : name === 'insurance contract'
                    ? 'Modifier'
                    : name === 'insurance fee'
                      ? 'Ajouter un barème'
                      : 'Renseigner un taux';
    // Target editing belongs to a table row; a draft plan may also have a summary edit button.
    const target =
      name === 'budget target edit'
        ? page.getByRole('row').getByRole('button', { name: 'Modifier', exact: true }).first()
        : button(trigger).first();
    await expect(target).toBeVisible();
    await target.click();
  }
  await settled(page);
  if (name === 'valuation details' || name === 'insurance valuation details') {
    await page.getByRole('dialog').getByRole('button', { name: 'Continuer', exact: true }).click();
    await settled(page);
    await expect(
      page.getByRole('dialog').getByRole('button', { name: 'Enregistrer', exact: true }),
    ).toBeVisible();
  }
}
