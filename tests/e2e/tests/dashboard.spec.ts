import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';

test.describe('dashboard', () => {
  test('shows the net worth with its quality, the allocation and the monthly indicators', async ({
    page,
  }) => {
    await page.goto('/');
    await settled(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Synthèse' })).toBeVisible();

    const netWorth = page.getByRole('region', { name: 'Patrimoine net' });
    await expect(netWorth).toContainText('€');
    // Valuation quality remains visible as supporting metadata.
    await expect(netWorth).toContainText(/Valorisation|à jour|Valorisations/);
    await expect(netWorth).not.toContainText(/Au \d{1,2} \p{L}+ \d{4}/u);

    await expect(page.getByRole('region', { name: 'Allocation' })).toBeVisible();
    for (const indicator of ['Revenus', 'Dépenses', 'Taux d’épargne']) {
      await expect(page.getByRole('region', { name: indicator })).toBeVisible();
    }
  });

  test('offers the net worth curve as a table', async ({ page }) => {
    await page.goto('/');
    await settled(page);

    const figure = page.getByRole('figure', { name: 'Évolution du patrimoine' });
    await expect(figure.getByText('Voir les données sous forme de tableau')).toHaveCount(0);

    await expect(figure.getByRole('table')).toBeAttached();
    await expect(figure.getByRole('table')).toHaveAccessibleName('Évolution du patrimoine');
  });

  test('persists the chart period and supports radio keyboard navigation at 360px', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/?periode=tout');
    await settled(page);
    const group = page.getByRole('radiogroup', { name: 'Période du graphique' });
    await expect(group.getByRole('radio', { name: 'Tout' })).toBeChecked();
    const all = group.getByRole('radio', { name: 'Tout' });
    await all.focus();
    const response = page.waitForResponse((response) =>
      response.url().includes('/net-worth/history?period=LAST_12_MONTHS'),
    );
    await all.press('ArrowRight');
    const history = (await (await response).json()) as { points: { on: string }[] };
    await expect(group.getByRole('radio', { name: '12 mois' })).toBeChecked();
    await expect(page).toHaveURL(/periode=12m/);
    const figure = page.getByRole('figure', { name: 'Évolution du patrimoine' });
    await expect(figure.getByText('Voir les données sous forme de tableau')).toHaveCount(0);
    await expect(figure.locator('tbody tr')).toHaveCount(history.points.length);
    await expect(group).toBeInViewport();
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    await page.reload();
    await settled(page);
    await expect(page.getByRole('radio', { name: '12 mois' })).toBeChecked();
  });

  test('shows the API comparison amount, rate and baseline for every selected period', async ({
    page,
  }) => {
    await page.goto('/?periode=unknown');
    await settled(page);
    const card = page.getByRole('region', { name: 'Patrimoine net' });
    for (const [period, label] of [
      ['ALL', 'Tout'],
      ['YTD', 'Depuis janvier'],
      ['LAST_12_MONTHS', '12 mois'],
    ] as const) {
      const response = await page.request.get(`/api/v1/net-worth/history?period=${period}`);
      expect(response.ok()).toBe(true);
      const group = page.getByRole('radiogroup', { name: 'Période du graphique' });
      await group.getByText(label, { exact: true }).click();
      await expect(group.getByRole('radio', { name: label, exact: true })).toBeChecked();
      const history = (await response.json()) as {
        delta: {
          comparedOn: string;
          amount: {
            value: string;
            assetCode: string;
            display: { value: string; assetCode: string } | null;
          } | null;
          ratePercentDisplay: string | null;
        };
      };
      await settled(page);
      const delta = history.delta;
      const date = new Intl.DateTimeFormat('fr', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
      }).format(new Date(`${delta.comparedOn}T00:00:00Z`));
      await expect(card.getByText(`depuis le ${date}`, { exact: true })).toBeVisible();
      if (delta.amount !== null) {
        const shown = delta.amount.display ?? delta.amount;
        const value = shown.value.replace(/^-/, '');
        const formatted = new Intl.NumberFormat('fr', {
          style: 'currency',
          currency: shown.assetCode,
        }).format(value as unknown as number);
        const direction = delta.amount.value.startsWith('-') ? 'Baisse' : 'Hausse';
        if (!/^0(?:\.0+)?$/.test(value))
          await expect(
            card.getByRole('img', { name: `${direction} de ${formatted}`, exact: true }),
          ).toBeVisible();
        else
          await expect(
            card.getByRole('img', { name: 'Patrimoine stable, aucune variation' }),
          ).toBeVisible();
      } else await expect(card.getByText('Non calculable', { exact: true }).first()).toBeVisible();
      if (delta.ratePercentDisplay !== null) {
        const rate = delta.ratePercentDisplay.replace('-', '−').replace('.', ',');
        await expect(card.getByText(`${rate} %`, { exact: true })).toBeVisible();
      }
    }
  });

  test('navigates away and back through the breadcrumb', async ({ page }) => {
    await page.goto('/');
    await settled(page);

    await navigateTo(page, 'Transactions');
    await expect(page.getByRole('heading', { level: 1, name: 'Transactions' })).toBeVisible();

    await page
      .getByRole('navigation', { name: 'Fil d’Ariane' })
      .getByRole('link', { name: 'Accueil' })
      .click();
    await settled(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Synthèse' })).toBeVisible();
  });
});
