import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  useLifeInsuranceMoney,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import { ContractSection } from '@/features/portfolio/life-insurance/contract-section/ContractSection';
import styles from './TaxTrackingCard.module.css';
const columnKeys = [
  'year',
  'premiums',
  'surrendersGross',
  'surrendersNetPaid',
  'withheldIncomeTax',
  'withheldSocialContributions',
] as const;
export function TaxTrackingCard({ summary }: { summary: Summary | null }) {
  const { t } = useTranslation();
  const money = useLifeInsuranceMoney();
  const age = summary?.contractAge;
  return (
    <ContractSection
      title={t('portfolio.lifeInsurance.tax.title')}
      hint={
        summary ? (
          t('portfolio.lifeInsurance.asOf', { date: formatCalendarNumericDay(summary.asOf) })
        ) : (
          <SkeletonBlock className={styles.hintSkeleton} />
        )
      }
      busy={!summary}
      testId={!summary ? 'tax-tracking-skeleton' : undefined}
    >
      {!summary ? (
        <SkeletonBlock className={styles.age} />
      ) : age ? (
        <p>
          {t('portfolio.lifeInsurance.tax.age', {
            years: age.years,
            months: age.months,
            date: formatCalendarNumericDay(age.taxStartOn),
          })}
        </p>
      ) : (
        <p>{t('portfolio.lifeInsurance.tax.dateUnavailable')}</p>
      )}
      <p>{t('portfolio.lifeInsurance.tax.noEstimate')}</p>
      <div
        className={styles.scroll}
        role="region"
        aria-label={t('portfolio.lifeInsurance.tax.region')}
        tabIndex={0}
      >
        <table>
          <caption className="sr-only">{t('portfolio.lifeInsurance.tax.caption')}</caption>
          <thead>
            <tr>
              {columnKeys.map((key) => (
                <th key={key} scope="col">
                  {t(`portfolio.lifeInsurance.tax.columns.${key}`)}
                </th>
              ))}
            </tr>
          </thead>
          {!summary ? (
            <SkeletonTableRows columns={6} />
          ) : (
            <tbody>
              {summary.taxYears.map((row) => (
                <tr key={row.year}>
                  <th scope="row">{row.year}</th>
                  {columnKeys
                    .filter((key) => key !== 'year')
                    .map((key) => (
                      <td className="money" key={key}>
                        {money(row[key] as string, summary.assetCode)}
                      </td>
                    ))}
                </tr>
              ))}
            </tbody>
          )}
        </table>
      </div>
      {summary?.taxYears.length === 0 ? <p>{t('portfolio.lifeInsurance.tax.noYear')}</p> : null}
    </ContractSection>
  );
}
