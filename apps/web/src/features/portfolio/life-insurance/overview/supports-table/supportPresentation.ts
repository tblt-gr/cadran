import type { LifeInsuranceSummarySupport } from '@cadran/api-client';
import { formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { basisLabels } from '@/features/portfolio/life-insurance/lifeInsuranceLabels';
import { money } from '@/features/portfolio/life-insurance/overview/summaryPresentation';
export const supportHeaders = [
  'Support',
  'Type',
  'Parts',
  'Valeur liquidative',
  'Date',
  'Valeur',
  'Poids',
  'Coût de revient',
  'Performance ou taux',
  'Intérêts crédités',
];
const qualityLabels = {
  CURRENT: 'Prix actuel',
  STALE: 'Prix ancien',
  MISSING: 'Prix manquant',
  NOT_DETAILED: 'Prix non détaillé',
};
export function supportCells(row: LifeInsuranceSummarySupport, assetCode: string) {
  const rate = row.latestServedRate;
  const performance =
    row.type === 'UNIT_LINKED'
      ? `Plus-value latente : ${money(row.unrealizedGain, assetCode)} (${row.unrealizedPerformance === null ? 'Non calculable' : `${formatRatioPercentage(row.unrealizedPerformance, 'fr')} latente`})`
      : rate
        ? `Taux servi ${rate.year} : ${formatDecimal(rate.rate, 'fr')} % (${basisLabels[rate.basis]})${rate.bonusRate === null ? '' : `, bonus enregistré : ${formatDecimal(rate.bonusRate, 'fr')} %`}`
        : 'Taux servi non renseigné';
  return [
    row.name,
    row.type === 'EURO_FUND' ? 'Fonds en euros' : 'Unité de compte',
    formatDecimal(row.quantity, 'fr'),
    row.unitPrice === null
      ? 'Non calculable'
      : `${formatDecimal(row.unitPrice, 'fr')} ${assetCode}`,
    `${row.priceDate ? formatCalendarNumericDay(row.priceDate) : 'Date indisponible'} · ${qualityLabels[row.priceQuality]}`,
    money(row.marketValue, assetCode),
    row.weight === null ? 'Non calculable' : formatRatioPercentage(row.weight, 'fr'),
    money(row.costBasis, assetCode),
    performance,
    row.type === 'EURO_FUND'
      ? `Intérêts crédités ${row.lastClosedYear} : ${money(row.creditedLastClosedYear, assetCode)}`
      : 'Sans objet',
  ];
}
