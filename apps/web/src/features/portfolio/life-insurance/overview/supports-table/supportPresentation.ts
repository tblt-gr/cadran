import { createElement } from 'react';
import { HiddenSupportResult } from './HiddenSupportResult';
import { areAmountsHidden } from '@/lib/amountPrivacy';
import { formatFinancialDecimal, formatExactAmount, signedPresentation } from '@/lib/decimal';
import type { LifeInsuranceSummarySupport } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { signed } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import { lifeInsuranceMoney } from '@/features/portfolio/life-insurance/overview/summaryPresentation';

const headerKeys = [
  'support',
  'type',
  'units',
  'unitPrice',
  'date',
  'value',
  'weight',
  'costBasis',
  'performance',
  'interest',
] as const;

export function useSupportHeaders(): string[] {
  const { t } = useTranslation();
  return headerKeys.map((key) => t(`portfolio.lifeInsurance.supports.headers.${key}`));
}

export function supportCells(
  row: LifeInsuranceSummarySupport,
  assetCode: string,
  t: TFunction,
  locale: string,
) {
  const notCalculable = t('states.notCalculable.label');
  const money = (value: string | null) =>
    lifeInsuranceMoney(value, assetCode, locale, notCalculable);
  const rate = row.latestServedRate;
  const performance =
    row.type === 'UNIT_LINKED'
      ? t('portfolio.lifeInsurance.supports.unrealized', {
          gain:
            row.unrealizedGain === null
              ? money(null)
              : signed(money(row.unrealizedGain), row.unrealizedGain).text,
          performance:
            row.unrealizedPerformance === null
              ? notCalculable
              : t('portfolio.lifeInsurance.supports.unrealizedRatio', {
                  ratio: signed(
                    formatRatioPercentage(row.unrealizedPerformance, locale),
                    row.unrealizedPerformance,
                  ).text,
                }),
        })
      : rate
        ? t('portfolio.lifeInsurance.supports.servedRate', {
            year: rate.year,
            rate: formatFinancialDecimal(rate.rate, locale),
            basis: t(`portfolio.lifeInsurance.basis.${rate.basis}`),
            bonus:
              rate.bonusRate === null
                ? ''
                : t('portfolio.lifeInsurance.supports.bonus', {
                    rate: formatFinancialDecimal(rate.bonusRate, locale),
                  }),
          })
        : t('portfolio.lifeInsurance.supports.servedRateMissing');
  return [
    row.name,
    t(`portfolio.lifeInsurance.supports.types.${row.type}`),
    formatFinancialDecimal(row.quantity, locale),
    row.unitPrice === null ? notCalculable : formatExactAmount(row.unitPrice, assetCode, locale),
    t('portfolio.lifeInsurance.supports.priceLine', {
      date: row.priceDate
        ? formatCalendarNumericDay(row.priceDate)
        : t('portfolio.lifeInsurance.supports.priceDateMissing'),
      quality: t(`portfolio.lifeInsurance.supports.quality.${row.priceQuality}`),
    }),
    money(row.marketValue),
    row.weight === null ? notCalculable : formatRatioPercentage(row.weight, locale),
    money(row.costBasis),
    areAmountsHidden() && row.type === 'UNIT_LINKED'
      ? createElement(HiddenSupportResult, {
          gain: row.unrealizedGain,
          performance: row.unrealizedPerformance,
          missing: notCalculable,
        })
      : performance,
    row.type === 'EURO_FUND'
      ? t('portfolio.lifeInsurance.supports.credited', {
          year: row.lastClosedYear,
          amount: money(row.creditedLastClosedYear),
        })
      : t('portfolio.lifeInsurance.supports.notApplicable'),
  ];
}

/** Colour of the result cell: only a calculated unrealized gain is green or red. */
export function supportResultTone(row: LifeInsuranceSummarySupport): 'gain' | 'loss' | undefined {
  const sign =
    row.type === 'UNIT_LINKED' && row.unrealizedGain !== null
      ? signedPresentation('', row.unrealizedGain).sign
      : 0;
  return sign < 0 ? 'loss' : sign > 0 ? 'gain' : undefined;
}
