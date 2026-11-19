import type { RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import {
  useLifeInsuranceMoney,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import { ContractSection } from '@/features/portfolio/life-insurance/contract-section/ContractSection';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { CumulativeSources } from '@/features/portfolio/life-insurance/overview/recorded-amounts/cumulative-sources/CumulativeSources';
import styles from './RecordedAmounts.module.css';
export function RecordedAmounts({
  summary,
  accountId,
  sourceReturnFocus,
}: {
  summary: Summary | null;
  accountId: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const { t } = useTranslation();
  const money = useLifeInsuranceMoney();
  const cumulative = summary?.cumulative;
  const rows: [string, string | null][] = [
    [t('portfolio.lifeInsurance.recorded.rows.premiums'), cumulative?.premiums ?? null],
    [
      t('portfolio.lifeInsurance.recorded.rows.surrendersGross'),
      cumulative?.surrendersGross ?? null,
    ],
    [t('portfolio.lifeInsurance.figures.labels.surrenders'), cumulative?.surrendersNetPaid ?? null],
    [t('portfolio.lifeInsurance.recorded.rows.entryFees'), cumulative?.fees.ENTRY ?? null],
    [
      t('portfolio.lifeInsurance.recorded.rows.managementFees'),
      cumulative?.fees.MANAGEMENT ?? null,
    ],
    [t('portfolio.lifeInsurance.recorded.rows.switchFees'), cumulative?.fees.SWITCH ?? null],
    [t('portfolio.lifeInsurance.recorded.rows.surrenderFees'), cumulative?.fees.SURRENDER ?? null],
    [t('portfolio.lifeInsurance.recorded.rows.otherFees'), cumulative?.fees.OTHER ?? null],
    [
      t('portfolio.lifeInsurance.recorded.rows.incomeTax'),
      cumulative?.withheldTaxes.INCOME_TAX ?? null,
    ],
    [
      t('portfolio.lifeInsurance.recorded.rows.socialContributions'),
      cumulative?.withheldTaxes.SOCIAL_CONTRIBUTIONS ?? null,
    ],
    [
      t('portfolio.lifeInsurance.recorded.rows.otherWithholdings'),
      cumulative?.withheldTaxes.OTHER ?? null,
    ],
    [
      t('portfolio.lifeInsurance.recorded.rows.interestGross'),
      cumulative?.interestCredited.gross ?? null,
    ],
    [
      t('portfolio.lifeInsurance.recorded.rows.interestNet'),
      cumulative?.interestCredited.net ?? null,
    ],
    [
      t('portfolio.lifeInsurance.recorded.rows.profitSharingGross'),
      cumulative?.profitSharing.gross ?? null,
    ],
    [
      t('portfolio.lifeInsurance.recorded.rows.profitSharingNet'),
      cumulative?.profitSharing.net ?? null,
    ],
  ];
  return (
    <ContractSection
      title={t('portfolio.lifeInsurance.recorded.title')}
      hint={
        summary ? (
          t('portfolio.lifeInsurance.asOf', { date: formatCalendarNumericDay(summary.asOf) })
        ) : (
          <SkeletonBlock className={styles.hintSkeleton} />
        )
      }
      busy={!summary}
    >
      <p>{t('portfolio.lifeInsurance.recorded.intro')}</p>
      <div
        className={styles.scroll}
        role="region"
        aria-label={t('portfolio.lifeInsurance.recorded.region')}
        tabIndex={0}
      >
        <table>
          <caption className="sr-only">{t('portfolio.lifeInsurance.recorded.title')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('portfolio.lifeInsurance.recorded.nature')}</th>
              <th scope="col">{t('portfolio.lifeInsurance.recorded.amount')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(([label, value]) => (
              <tr key={label}>
                <th scope="row">{label}</th>
                <td className="money">
                  {summary ? (
                    money(value, summary.assetCode)
                  ) : (
                    <SkeletonBlock className={styles.amountSkeleton} />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <CumulativeSources
        summary={summary}
        accountId={accountId}
        sourceReturnFocus={sourceReturnFocus}
      />
    </ContractSection>
  );
}
