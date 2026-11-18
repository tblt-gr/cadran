import { appendFileSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { auditLayout, expectFormActionLayout, type AuditFinding } from './support/audit';

import { clickPageAction, settled } from './support/page';
import { ownerStorageState } from './support/session';
import { openMovementEditModal } from './support/edit-movement';
import { openTakeoverModal } from './support/takeover';
import { auditAccessibility } from './support/a11y';
import { openTransactionSplits } from './support/transaction-splits';
import { openBulkGoalConfirmation } from './support/bulk-goals';
import {
  expandCountedFilters,
  expectFacetRowsFit,
  withLongAccountLabel,
} from './support/transaction-filters';

test('touch audit ignores fully clipped controls but checks them when revealed', async ({
  page,
}) => {
  await page.setContent(
    '<button style="width: 34px; height: 44px; clip-path: inset(50%)">Next</button>',
  );
  expect(await auditLayout(page, true)).toEqual([]);
  await page.locator('button').evaluate((button) => (button.style.clipPath = 'none'));
  expect(await auditLayout(page, true)).toEqual([
    expect.objectContaining({ rule: 'touch-target' }),
  ]);
});

test('audit fails a row menu that sits on a line of its own and passes a pinned one', async ({
  page,
}) => {
  const card = (style: string) =>
    `<ul><li style="position: relative"><p>Label</p><div style="${style}"><button data-action-menu aria-label="Actions" style="width: 44px; height: 44px"></button></div></li></ul>`;
  await page.setContent(card(''));
  expect(await auditLayout(page, true)).toEqual([
    expect.objectContaining({ rule: 'actions-own-line' }),
  ]);
  await page.setContent(card('position: absolute; top: 0; right: 0'));
  expect(await auditLayout(page, true)).toEqual([]);
});

/**
 * The review grid of the responsive pass, as a gate: every authenticated route at 1280, 768 and
 * 360 px must have no horizontal page scroll, no amount cut off or pushed out of its box, touch
 * targets of at least 44 by 44 px on a coarse pointer, and at most one primary action.
 */
const WIDTHS = [
  { width: 1280, height: 800, touch: false },
  { width: 768, height: 1024, touch: true },
  { width: 360, height: 740, touch: true },
] as const;

interface RouteIds {
  accountId: string;
  portfolioId: string;
  instrumentId: string;
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
    `/budget/${now.getFullYear()}`,
    `/budget/${now.getFullYear()}?view=multi`,
    `/budget/${period.replace('-', '/')}?panel=goals`,
    `/budget/${now.getFullYear()}?panel=goals`,
    '/investments',
    '/investments?view=asset',
    '/investments/instruments',
    `/investments/instruments/${ids.instrumentId}`,
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
  ids.instrumentId = (
    await firstHref(
      browser,
      '/investments/instruments',
      /^\/investments\/instruments\/[0-9a-f-]{36}$/,
    )
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
  '/budget/:year',
  '/budget/:year?view=multi',
  '/budget/:year/:month?panel=goals',
  '/budget/:year?panel=goals',
  '/investments',
  '/investments?view=asset',
  '/investments/instruments',
  '/investments/instruments/:instrument',
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

          if (pattern === '/investments/instruments/:instrument' && viewport.width === 360) {
            const caption = page.getByRole('table', { name: 'Par compte' }).locator('caption');
            const widths = await caption.evaluate((element) => ({
              caption: element.getBoundingClientRect().width,
              table: element.closest('table')!.getBoundingClientRect().width,
            }));
            expect(
              widths.caption,
              'the mobile caption spans its table instead of shrinking to its words',
            ).toBeGreaterThanOrEqual(widths.table - 1);
          }

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
        // The default view groups the accounts and splits the pie by group: the pie by account
        // belongs to the all-accounts view.
        await page.getByRole('button', { name: 'Tous les comptes', exact: true }).click();
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

test.describe('portfolio operations filters', () => {
  for (const viewport of [WIDTHS[0], WIDTHS[2]]) {
    test(`size their controls to the width at ${viewport.width}`, async ({ browser }) => {
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
        await page.goto(`/portfolios/${ids.portfolioId}?tab=operations`);
        await settled(page);
        // Up to 767 px the inputs wait behind the Filtrer button, in a sheet.
        if (viewport.width < 768) {
          await expect(page.getByRole('group', { name: 'Filtres des opérations' })).toHaveCount(0);
          await page.getByRole('button', { name: /^Filtrer/ }).click();
        }
        const toolbar = page.getByRole('group', { name: 'Filtres des opérations' });
        const box = await toolbar.boundingBox();
        const types = await toolbar
          .getByRole('button', { name: /Types d’opération/ })
          .boundingBox();
        const from = await toolbar.getByLabel('Du').boundingBox();
        const instrument = await toolbar
          .getByRole('combobox', { name: 'Instrument' })
          .boundingBox();
        if (!box || !types || !from || !instrument) throw new Error('filters are not rendered');
        if (viewport.touch) {
          for (const control of [types, from, instrument]) {
            expect(control.width).toBeGreaterThanOrEqual(box.width - 1);
          }
        } else {
          expect(types.width, 'desktop keeps the content sized type filter').toBeLessThan(
            box.width / 2,
          );
        }
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
    name: 'transaction split',
    open: async (page) => {
      await openTransactionSplits(page);
      return 'Enregistrer';
    },
  },
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
      await clickPageAction(page, 'Nouveau virement');
      return 'Créer le virement';
    },
  },
  {
    name: 'account transfer',
    open: async (page, ids) => {
      await page.goto(`/accounts/${ids.accountId}?tab=operations`);
      await settled(page);
      await clickPageAction(page, 'Nouveau virement');
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
    name: 'bulk goal stop',
    open: async (page) => openBulkGoalConfirmation(page, 'stop'),
  },
  {
    name: 'bulk goal delete',
    open: async (page) => openBulkGoalConfirmation(page, 'delete'),
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
      await page.getByRole('button', { name: 'Créer son modèle' }).click();
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
          if (viewport.touch) {
            // Below 42rem every modal fills the viewport.
            expect(panel).toMatchObject({
              x: 0,
              y: 0,
              width: viewport.width,
              height: viewport.height,
            });
          }
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
          await expectFormActionLayout(save);
          // The primary action carries only its one-pixel contact shadow, never a glow.
          expect(
            await save.evaluate((el) => {
              const probe = document.createElement('span');
              probe.style.boxShadow = 'var(--shadow-primary)';
              document.body.append(probe);
              const expected = getComputedStyle(probe).boxShadow;
              probe.remove();
              return getComputedStyle(el).boxShadow === expected;
            }),
          ).toBe(true);

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
  const page = await context.newPage();
  try {
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
    const zero = sheet.getByRole('checkbox', { name: /, 0 transaction$/ }).first();
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
    // Aborted browser requests can leave route.fetch running in the label fixture.
    await page.unrouteAll({ behavior: 'wait' });
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
  const page = await context.newPage();
  try {
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
    // Drain the label fixture before disposing the request context it fetches through.
    await page.unrouteAll({ behavior: 'wait' });
    await context.close();
  }
});

/** The five icon-only destinations share the bottom bar at 360 px: each is named and touchable. */
test('mobile navigation shows its five named icon destinations at 360 px', async ({ browser }) => {
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
  const page = await context.newPage();
  try {
    await page.goto('/');
    await settled(page);
    const links = page.getByRole('navigation', { name: 'Navigation mobile' }).getByRole('link');
    await expect(links).toHaveCount(5);
    for (const name of ['Synthèse', 'Budget', 'Comptes', 'Investissements', 'Paramètres']) {
      await expect(links.filter({ has: page.getByText(name, { exact: true }) })).toHaveCount(1);
    }
    for (const link of await links.all()) {
      const box = (await link.boundingBox())!;
      expect(box.width).toBeGreaterThanOrEqual(44);
      expect(box.height).toBeGreaterThanOrEqual(44);
    }
  } finally {
    await context.close();
  }
});

/**
 * On a very wide screen the page column stops at the content maximum width (the
 * `--content-max-width` token, 90 rem) and sits centred in the space right of the sidebar, so the
 * extra width becomes equal side margins and the header title row keeps the same left edge.
 */
test.describe('content column on a wide screen', () => {
  for (const path of ['/', '/accounts', '/transactions']) {
    test(`caps and centres the column at 2560 px on ${path}`, async ({ browser }) => {
      const context = await browser.newContext({
        baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
        ignoreHTTPSErrors: true,
        storageState: ownerStorageState,
        viewport: { width: 2560, height: 1200 },
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
      });
      try {
        const page = await context.newPage();
        await page.goto(path);
        await settled(page);

        const column = await page.evaluate(() => {
          const body = document.querySelector('main')!.parentElement!;
          const workspace = body.parentElement!.getBoundingClientRect();
          const style = getComputedStyle(body);
          const inner = {
            left: body.getBoundingClientRect().left + parseFloat(style.paddingLeft),
            right: body.getBoundingClientRect().right - parseFloat(style.paddingRight),
          };
          const title = document.querySelector('h1')!.getBoundingClientRect();
          const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
          return {
            width: inner.right - inner.left,
            left: inner.left - workspace.left,
            right: workspace.right - inner.right,
            titleOffset: title.left - inner.left,
            max: 90 * rem,
          };
        });

        expect(column.width, 'the column stops at the maximum width').toBeLessThanOrEqual(
          column.max + 1,
        );
        expect(Math.abs(column.left - column.right), 'equal side margins').toBeLessThanOrEqual(1);
        expect(column.left, 'a real margin appears on each side').toBeGreaterThan(32);
        expect(
          Math.abs(column.titleOffset),
          'the title shares the content left edge',
        ).toBeLessThanOrEqual(1);
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

/** The positions are compact rows: the detail and the lots dialogs never scroll sideways at 360 px. */
test('portfolio positions, detail and lots dialogs fit 360 px', async ({ browser }) => {
  const context = await browser.newContext({
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
    ignoreHTTPSErrors: true,
    storageState: ownerStorageState,
    viewport: { width: 360, height: 800 },
    hasTouch: true,
    isMobile: true,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
  });
  try {
    const page = await context.newPage();
    await page.goto(`/portfolios/${ids.portfolioId}?tab=positions`);
    await settled(page);
    const list = page.getByRole('list', { name: 'Positions du portefeuille' });
    await expect(list).toBeVisible();
    await list.getByRole('button').first().click();
    await page.getByRole('button', { name: 'Voir les lots' }).click();
    await expect(page.getByRole('list', { name: 'Lots ouverts' })).toBeVisible();
    await settled(page);
    const overflow = await page.evaluate(() =>
      [document.documentElement, ...document.querySelectorAll('[role="dialog"]')].some(
        (element) => element.scrollWidth > element.clientWidth + 1,
      ),
    );
    expect(overflow, 'no horizontal scroll on the page or the dialog').toBe(false);
    expect(await auditLayout(page, true)).toEqual([]);
  } finally {
    await context.close();
  }
});
