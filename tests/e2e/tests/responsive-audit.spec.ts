import { appendFileSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { auditLayout, type AuditFinding } from './support/audit';
import { settled } from './support/page';
import { ownerStorageState } from './support/session';

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
    '/parameters/catalog',
    '/parameters/product-models',
    '/transactions',
    '/transactions/categories',
    '/transactions/categories/rules',
    '/transactions/recurrences',
    `/budget/${period}`,
    '/budget/plans',
    `/budget/plans/${ids.planId}`,
    '/reports',
    `/reports/annual/${now.getFullYear()}`,
    '/reports/all-years',
    '/goals',
    '/portfolios',
    '/portfolios/instruments',
    `/portfolios/${ids.portfolioId}`,
    `/portfolios/${ids.portfolioId}?tab=valuations`,
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
    const hrefs = await page
      .locator('a[href]')
      .evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
    const match = hrefs.find((href) => pattern.test(href));
    if (!match) throw new Error(`No ${pattern} link on ${listing}`);

    return match.split('?')[0]!;
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
  ids.portfolioId = (
    await firstHref(browser, '/portfolios', /^\/portfolios\/[0-9a-f-]{36}$/)
  ).split('/')[2];
});

// Playwright needs the test list before beforeAll runs, so the dynamic routes are matched by pattern.
const PATTERNS = [
  '/',
  '/accounts',
  '/accounts/groups',
  '/accounts/:account',
  '/parameters/catalog',
  '/parameters/product-models',
  '/transactions',
  '/transactions/categories',
  '/transactions/categories/rules',
  '/transactions/recurrences',
  '/budget/:month',
  '/budget/plans',
  '/budget/plans/:plan',
  '/reports',
  '/reports/annual/:year',
  '/reports/all-years',
  '/goals',
  '/portfolios',
  '/portfolios/instruments',
  '/portfolios/:portfolio',
  '/portfolios/:portfolio?tab=valuations',
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
    name: 'portfolio operation',
    open: async (page, ids) => {
      await page.goto(`/portfolios/${ids.portfolioId}`);
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle opération' }).click();
      return 'Enregistrer';
    },
  },
  {
    name: 'valuation',
    open: async (page, ids) => {
      await page.goto(`/portfolios/${ids.portfolioId}`);
      await settled(page);
      await page.getByRole('link', { name: 'Valorisations' }).click();
      await settled(page);
      await page.getByRole('button', { name: 'Enregistrer une valorisation' }).click();
      return 'Continuer';
    },
  },
];

test.describe('responsive audit of modals at 360 px', () => {
  for (const modal of MODALS) {
    test(modal.name, async ({ browser }) => {
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
        const confirm = await modal.open(page, ids);
        const dialog = page.getByRole('dialog').last();
        await expect(dialog).toBeVisible();
        await dialog.evaluate((el) => el.setAttribute('data-audit-root', ''));

        const findings = await auditLayout(page, true, '[data-audit-root]');
        expect(findings).toEqual([]);

        const action = dialog.getByRole('button', { name: confirm }).last();
        await action.scrollIntoViewIfNeeded();
        const box = (await action.boundingBox())!;
        const panel = (await dialog.boundingBox())!;
        expect(box.y).toBeGreaterThanOrEqual(panel.y - 1);
        expect(box.y + box.height).toBeLessThanOrEqual(740 + 1);
        expect(box.x + box.width).toBeLessThanOrEqual(360 + 1);
      } finally {
        await context.close();
      }
    });
  }
});
