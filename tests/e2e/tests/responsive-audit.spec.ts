import { appendFileSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { auditLayout, type AuditFinding } from './support/audit';
import { settled } from './support/page';
import { ownerStorageState } from './support/session';
import { openMovementEditModal } from './support/edit-movement';
import { openTakeoverModal } from './support/takeover';
import { auditAccessibility } from './support/a11y';
import {
  expandCountedFilters,
  expectFacetRowsFit,
  withLongAccountLabel,
} from './support/transaction-filters';

/**
 * The review grid of the responsive pass, as a gate: every authenticated route at 1280, 768 and
 * 360 px must have no horizontal page scroll, no amount cut off or pushed out of its box, touch
 * targets of at least 44 by 44 px on a coarse pointer, and at most one primary copper action.
 */
const WIDTHS = [
  { width: 1280, height: 800, touch: false },
  { width: 768, height: 1024, touch: true },
  { width: 360, height: 740, touch: true },
] as const;

interface RouteIds {
  accountId: string;
  planId: string;
  portfolioId: string;
}

const ids: Partial<RouteIds> = {};

/** Routes in scope, with the ids of the demo ledger resolved once. */
function routes(): string[] {
  const now = new Date();
  const lastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  const period = `${lastMonth.getFullYear()}-${String(lastMonth.getMonth() + 1).padStart(2, '0')}`;

  return [
    '/',
    '/accounts',
    '/accounts/groups',
    `/accounts/${ids.accountId}`,
    `/accounts/${ids.accountId}?tab=balance`,
    `/accounts/${ids.accountId}?tab=operations`,
    '/parameters/catalog',
    '/parameters/product-models',
    '/transactions',
    '/transactions?period=month',
    '/transactions?direction=OUTFLOW',
    '/transactions/categories',
    '/transactions/categories/rules',
    '/transactions/recurrences',
    `/budget/${period.replace('-', '/')}`,
    '/budget/plans',
    `/budget/plans/${ids.planId}`,
    `/budget/${now.getFullYear()}`,
    `/budget/${now.getFullYear()}?view=multi`,
    '/goals',
    '/investments',
    '/investments?view=account',
    '/investments/instruments',
    `/portfolios/${ids.portfolioId}`,
    `/portfolios/${ids.portfolioId}?tab=valuations`,
    `/portfolios/${ids.portfolioId}?tab=account`,
    `/portfolios/${ids.portfolioId}?tab=positions`,
    `/portfolios/${ids.portfolioId}?tab=operations`,
    '/parameters/interface',
    '/parameters/profile',
    '/parameters/metric-policy',
  ];
}

async function firstHref(browser: Browser, listing: string, pattern: RegExp): Promise<string> {
  const context = await browser.newContext({
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
    ignoreHTTPSErrors: true,
    storageState: ownerStorageState,
  });
  try {
    const page = await context.newPage();
    await page.goto(listing);
    await settled(page);
    let match: string | undefined;
    await expect
      .poll(
        async () => {
          const hrefs = await page
            .locator('a[href]')
            .evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
          match = hrefs.find((href) => pattern.test(href));
          return match;
        },
        { message: `No ${pattern} link on ${listing}` },
      )
      .toBeTruthy();

    return match!.split('?')[0]!;
  } finally {
    await context.close();
  }
}

test.beforeAll(async ({ browser }) => {
  ids.accountId = (await firstHref(browser, '/accounts', /^\/accounts\/[0-9a-f-]{36}$/)).split(
    '/',
  )[2];
  ids.planId = (
    await firstHref(browser, '/budget/plans', /^\/budget\/plans\/[0-9a-f-]{36}$/)
  ).split('/')[3];
  ids.portfolioId = (await firstHref(browser, '/accounts', /^\/portfolios\/[0-9a-f-]{36}$/)).split(
    '/',
  )[2];
});

// Playwright needs the test list before beforeAll runs, so the dynamic routes are matched by pattern.
const PATTERNS = [
  '/',
  '/accounts',
  '/accounts/groups',
  '/accounts/:account',
  '/accounts/:account?tab=balance',
  '/accounts/:account?tab=operations',
  '/parameters/catalog',
  '/parameters/product-models',
  '/transactions',
  '/transactions?period=month',
  '/transactions?direction=OUTFLOW',
  '/transactions/categories',
  '/transactions/categories/rules',
  '/transactions/recurrences',
  '/budget/:year/:month',
  '/budget/plans',
  '/budget/plans/:plan',
  '/budget/:year',
  '/budget/:year?view=multi',
  '/goals',
  '/investments',
  '/investments?view=account',
  '/investments/instruments',
  '/portfolios/:portfolio',
  '/portfolios/:portfolio?tab=valuations',
  '/portfolios/:portfolio?tab=account',
  '/portfolios/:portfolio?tab=positions',
  '/portfolios/:portfolio?tab=operations',
  '/parameters/interface',
  '/parameters/profile',
  '/parameters/metric-policy',
];

test.describe('responsive audit', () => {
  PATTERNS.forEach((pattern, index) => {
    for (const viewport of WIDTHS) {
      const key = `${pattern}@${viewport.width}`;

      test(key, async ({ browser }, testInfo) => {
        const path = routes()[index]!;
        const context = await browser.newContext({
          baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
          ignoreHTTPSErrors: true,
          storageState: ownerStorageState,
          viewport: { width: viewport.width, height: viewport.height },
          hasTouch: viewport.touch,
          isMobile: viewport.touch,
          locale: 'fr-FR',
          timezoneId: 'Europe/Paris',
        });
        try {
          const page = await context.newPage();
          await page.goto(path);
          await settled(page);

          const coarse = await page.evaluate(() => matchMedia('(pointer: coarse)').matches);
          expect(coarse, 'the touch viewports emulate a coarse pointer').toBe(viewport.touch);

          const findings: AuditFinding[] = await auditLayout(page, coarse);
          if (process.env.E2E_AUDIT_DUMP) {
            for (const finding of findings) {
              appendFileSync(
                process.env.E2E_AUDIT_DUMP,
                `${JSON.stringify({ route: path, width: viewport.width, ...finding })}\n`,
              );
            }
          }
          await testInfo.attach('findings', {
            body: JSON.stringify(findings, null, 2),
            contentType: 'application/json',
          });

          expect(findings).toEqual([]);
        } finally {
          await context.close();
        }
      });
    }
  });
});

/**
 * The accounts overview now carries the investment rows and the net worth pie: with a portfolio
 * account in the demo ledger, the pie card is present and the page never scrolls sideways.
 */
test.describe('accounts overview with investments', () => {
  for (const viewport of [WIDTHS[0], WIDTHS[2]]) {
    test(`shows the net worth pie without horizontal overflow at ${viewport.width}`, async ({
      browser,
    }) => {
      const context = await browser.newContext({
        baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
        ignoreHTTPSErrors: true,
        storageState: ownerStorageState,
        viewport: { width: viewport.width, height: viewport.height },
        hasTouch: viewport.touch,
        isMobile: viewport.touch,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
      });
      try {
        const page = await context.newPage();
        await page.goto('/accounts');
        await settled(page);

        await expect(page.locator('a[href^="/portfolios/"]').first()).toBeVisible();
        await expect(
          page.getByRole('heading', { level: 2, name: 'Répartition par compte' }),
        ).toBeVisible();
        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        expect(overflow, 'no horizontal page scroll').toBeLessThanOrEqual(0);
      } finally {
        await context.close();
      }
    });
  }
});

/**
 * Every creation or edition modal in scope, opened at 360 by 740 px: the dialog passes the same
 * layout rules as a page (no horizontal overflow, amounts intact, 44 px targets) and its submit
 * action can be reached inside the viewport, scrolling inside the dialog being allowed.
 */
interface ModalCase {
  name: string;
  /** Opens the modal and returns the accessible name of the action that confirms it. */
  open: (page: Page, ids: Partial<RouteIds>) => Promise<string | RegExp>;
}

const MODALS: ModalCase[] = [
  {
    name: 'transaction',
    open: async (page) => {
      await page.goto('/transactions');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle transaction' }).click();
      return 'Enregistrer';
    },
  },
  {
    name: 'transfer',
    open: async (page) => {
      await page.goto('/transactions');
      await settled(page);
      await page.getByRole('button', { name: 'Nouveau virement' }).click();
      return 'Créer le virement';
    },
  },
  {
    name: 'account transfer',
    open: async (page, ids) => {
      await page.goto(`/accounts/${ids.accountId}?tab=operations`);
      await settled(page);
      await page.getByRole('button', { name: /^Actions du compte/ }).click();
      await page.getByRole('button', { name: 'Nouveau virement', exact: true }).click();
      await expect(page.getByLabel('Compte source')).toBeDisabled();
      await expect(page.getByLabel('Compte de destination')).toBeFocused();
      return 'Créer le virement';
    },
  },
  {
    name: 'account',
    open: async (page) => {
      await page.goto('/accounts');
      await settled(page);
      await page.getByRole('button', { name: 'Nouveau compte' }).click();
      return 'Continuer';
    },
  },
  {
    name: 'category',
    open: async (page) => {
      await page.goto('/transactions/categories');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle catégorie' }).click();
      return 'Enregistrer';
    },
  },
  {
    name: 'budget target',
    open: async (page, ids) => {
      await page.goto(`/budget/plans/${ids.planId}`);
      await settled(page);
      await page.getByRole('button', { name: 'Ajouter un objectif' }).click();
      return 'Enregistrer';
    },
  },
  {
    name: 'goal',
    open: async (page) => {
      await page.goto('/goals');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvel objectif' }).click();
      return 'Enregistrer';
    },
  },
  {
    name: 'catalog settings',
    open: async (page) => {
      await page.goto('/parameters/catalog');
      await settled(page);
      await page.getByRole('button', { name: 'Filtres du catalogue', exact: true }).click();
      return 'Fermer';
    },
  },
  {
    name: 'product model',
    open: async (page) => {
      await page.goto('/parameters/product-models');
      await settled(page);
      await page.getByRole('button', { name: 'Nouveau modèle', exact: true }).click();
      return 'Créer le modèle';
    },
  },
  {
    name: 'portfolio operation',
    open: async (page, ids) => {
      await page.goto(`/portfolios/${ids.portfolioId}`);
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle opération' }).click();
      return 'Enregistrer';
    },
  },
  {
    name: 'portfolio takeover',
    open: async (page) => {
      await openTakeoverModal(page);
      return 'Enregistrer la reprise';
    },
  },
  {
    name: 'stacked instrument creation',
    open: async (page, ids) => {
      await page.goto(`/portfolios/${ids.portfolioId}?tab=operations`);
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle opération' }).click();
      const parent = page.getByRole('dialog', { name: 'Nouvelle opération' });
      await parent.getByRole('combobox', { name: 'Type', exact: true }).selectOption('BUY');
      await parent
        .getByRole('combobox', { name: 'Instrument', exact: true })
        .fill('Missing audit instrument');
      await parent.getByRole('option', { name: 'Créer « Missing audit instrument »' }).click();
      await expect(
        page.getByRole('dialog', { name: 'Créer un instrument' }).getByLabel('ISIN'),
      ).toBeFocused();
      return 'Enregistrer';
    },
  },
  {
    name: 'valuation',
    open: async (page, ids) => {
      await page.goto(`/portfolios/${ids.portfolioId}`);
      await settled(page);
      await page.getByRole('button', { name: /^Actions du compte/ }).click();
      await page.getByRole('button', { name: 'Nouvelle valorisation' }).click();
      await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Continuer', exact: true })
        .click();
      await settled(page);
      await expect(
        page.getByRole('dialog').getByRole('button', { name: 'Enregistrer', exact: true }),
      ).toBeVisible();
      await page.getByRole('radio', { name: 'Détail par ligne' }).check();
      return 'Enregistrer';
    },
  },
];

test.describe('responsive audit of modals', () => {
  for (const viewport of [WIDTHS[0], WIDTHS[2]]) {
    for (const modal of MODALS) {
      test(`${modal.name} at ${viewport.width} px`, async ({ browser }) => {
        const context = await browser.newContext({
          baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
          ignoreHTTPSErrors: true,
          storageState: ownerStorageState,
          viewport: { width: viewport.width, height: viewport.height },
          hasTouch: viewport.touch,
          isMobile: viewport.touch,
          locale: 'fr-FR',
          timezoneId: 'Europe/Paris',
        });
        try {
          const page = await context.newPage();
          const confirm = await modal.open(page, ids);
          const dialog = page.getByRole('dialog').last();
          await expect(dialog).toBeVisible();
          await dialog.evaluate((el) => el.setAttribute('data-audit-root', ''));

          const findings = await auditLayout(page, viewport.touch, '[data-audit-root]');
          await auditAccessibility(page);
          expect(findings).toEqual([]);

          const action = dialog.getByRole('button', { name: confirm }).last();
          await action.scrollIntoViewIfNeeded();
          const box = (await action.boundingBox())!;
          const panel = (await dialog.boundingBox())!;
          expect(box.y).toBeGreaterThanOrEqual(panel.y - 1);
          expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1);
          expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
          if (confirm !== 'Fermer') expect(box.width).toBeLessThan(panel.width * 0.85);
        } finally {
          await context.close();
        }
      });
    }
  }
});

test.describe('responsive audit of movement edit modals', () => {
  for (const kind of ['transaction', 'transfer', 'refund'] as const) {
    for (const viewport of [WIDTHS[0], WIDTHS[2]]) {
      test(`${kind} edit at ${viewport.width} px`, async ({ browser }) => {
        const context = await browser.newContext({
          baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
          ignoreHTTPSErrors: true,
          storageState: ownerStorageState,
          viewport: { width: viewport.width, height: viewport.height },
          hasTouch: viewport.touch,
          isMobile: viewport.touch,
          locale: 'fr-FR',
          timezoneId: 'Europe/Paris',
        });
        try {
          const page = await context.newPage();
          const dialog = await openMovementEditModal(page, kind);
          await expect(dialog).toBeVisible();
          await expect(dialog.getByText('Modification', { exact: true })).toBeVisible();
          await dialog.evaluate((el) => el.setAttribute('data-audit-root', ''));
          expect(await auditLayout(page, viewport.touch, '[data-audit-root]')).toEqual([]);
          await auditAccessibility(page);

          const save = dialog.getByRole('button', { name: 'Enregistrer', exact: true });
          await save.scrollIntoViewIfNeeded();
          await expect(save).toBeInViewport({ ratio: 1 });
          const form = (await dialog.locator('form').boundingBox())!;
          const action = (await save.boundingBox())!;
          expect(action.width).toBeLessThan(form.width * 0.75);
          expect(Math.abs(action.x + action.width - form.x - form.width)).toBeLessThan(2);
          expect(await save.evaluate((el) => getComputedStyle(el).boxShadow)).toBe('none');

          if (kind === 'refund') {
            await expect(
              dialog.getByRole('combobox', { name: 'Compte', exact: true }),
            ).toBeEnabled();
            await expect(dialog.getByLabel('Montant', { exact: true })).toBeDisabled();
            await expect(dialog.getByText(/Le montant et la répartition sont fixes/)).toBeVisible();
          }

          if (kind === 'transfer') {
            const label = (await dialog.getByLabel('Libellé', { exact: true }).boundingBox())!;
            const state = (await dialog.getByRole('combobox', { name: /^État/ }).boundingBox())!;
            expect(Math.abs(label.width - state.width)).toBeLessThan(2);
            if (viewport.width === 1280) {
              expect(Math.abs(label.y - state.y)).toBeLessThan(2);
              expect(state.x).toBeGreaterThan(label.x + label.width);
            } else {
              expect(state.y).toBeGreaterThan(label.y + label.height);
              expect(Math.abs(label.x - state.x)).toBeLessThan(2);
            }
          }
          const close = dialog.getByRole('button', { name: 'Fermer', exact: true });
          await close.focus();
          await expect(close).toBeFocused();
          await page.keyboard.press('Enter');
          await expect(dialog).toBeHidden();
        } finally {
          await context.close();
        }
      });
    }
  }
});

/**
 * The transaction filters at 360 px: no column squeezes the list, the search keeps the full
 * width, the chips and the clear-all button share one height, and the filters open in a bottom
 * sheet that stays inside the viewport.
 */
test('transaction filters at 360 px open in a bottom sheet', async ({ browser }) => {
  const context = await browser.newContext({
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
    ignoreHTTPSErrors: true,
    storageState: ownerStorageState,
    viewport: { width: 360, height: 740 },
    hasTouch: true,
    isMobile: true,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
  });
  try {
    const page = await context.newPage();
    await withLongAccountLabel(page);
    await page.goto('/transactions?direction=OUTFLOW&state=BOOKED');
    await settled(page);

    await expect(page.getByRole('complementary', { name: 'Filtres' })).toHaveCount(0);
    const toggle = page.getByRole('button', { name: /^Filtres/ });
    await expect(toggle).toHaveAttribute('aria-haspopup', 'dialog');
    const row = (await toggle.locator('xpath=..').boundingBox())!;
    const search = (await page
      .getByRole('searchbox')
      .locator('xpath=ancestor::label')
      .boundingBox())!;
    expect(search.width, 'the search takes the row but the toggle').toBeGreaterThan(row.width / 2);

    const heights = await page
      .getByRole('group', { name: 'Filtres actifs' })
      .getByRole('button')
      .evaluateAll((buttons) =>
        buttons.map((button) => Math.round(button.getBoundingClientRect().height)),
      );
    expect(heights.length).toBeGreaterThan(1);
    expect(new Set(heights).size, 'chips and clear-all share one height').toBe(1);

    await toggle.click();
    const sheet = page.getByRole('dialog', { name: 'Filtres' });
    await expect(sheet).toBeVisible();
    const sheetBox = (await sheet.boundingBox())!;
    expect(sheetBox.x).toBeGreaterThanOrEqual(0);
    expect(sheetBox.x + sheetBox.width).toBeLessThanOrEqual(360);

    await expandCountedFilters(page, sheet);
    await expectFacetRowsFit(sheet);
    await auditAccessibility(page);
    // A zero remains a choice, including for keyboard users.
    const zero = sheet.getByRole('checkbox', { name: /^Annulée\s*, 0 transaction$/ });
    await expect(zero).toBeEnabled();
    await zero.focus();
    await zero.press('Space');
    await settled(page);
    await expect(zero).toBeChecked();
    await zero.press('Space');
    await settled(page);
    await expect(zero).not.toBeChecked();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow, 'no horizontal page scroll').toBeLessThanOrEqual(0);
    expect(await auditLayout(page, true)).toEqual([]);
  } finally {
    await context.close();
  }
});

/**
 * On a wide screen the filters sit in a column beside the list, which the round toggle hides
 * and shows again.
 */
test('transaction filters at 1440 px sit in a column beside the list', async ({ browser }) => {
  const context = await browser.newContext({
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
    ignoreHTTPSErrors: true,
    storageState: ownerStorageState,
    viewport: { width: 1440, height: 900 },
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
  });
  try {
    const page = await context.newPage();
    await withLongAccountLabel(page);
    await page.goto('/transactions?direction=OUTFLOW');
    await settled(page);

    const column = page.getByRole('complementary', { name: 'Filtres' });
    const search = page.getByRole('searchbox');
    const columnBox = (await column.boundingBox())!;
    const searchBox = (await search.boundingBox())!;
    expect(columnBox.x + columnBox.width, 'the column sits left of the list').toBeLessThanOrEqual(
      searchBox.x,
    );

    const toggle = page.getByRole('button', { name: /^Filtres/ });
    await toggle.click();
    await expect(column).toBeHidden();
    await toggle.click();
    await expect(column).toBeVisible();
    await expandCountedFilters(page, column);
    await expectFacetRowsFit(column);
    await auditAccessibility(page);
    const accounts = column.getByRole('group', { name: 'Comptes', exact: true });
    const accountCounts = await accounts.locator('label').allTextContents();
    const firstAccount = accounts.getByRole('checkbox').first();
    await firstAccount.check();
    await settled(page);
    expect(
      await accounts.locator('label').allTextContents(),
      'choosing an account preserves every sibling account count',
    ).toEqual(accountCounts);
    await firstAccount.uncheck();
    await settled(page);
    expect(await auditLayout(page, false)).toEqual([]);
  } finally {
    await context.close();
  }
});
