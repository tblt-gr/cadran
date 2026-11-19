import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { LifeInsuranceEuroFundRate, LifeInsuranceEuroFund } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { ContractSection } from '@/features/portfolio/life-insurance/contract-section/ContractSection';
import { formatDecimal } from '@/lib/decimal';
import styles from './EuroFundRatesCard.module.css';
const columnKeys = ['year', 'support', 'served', 'bonus', 'basis', 'source', 'actions'] as const;

export function EuroFundRatesCard({
  items = [],
  euroFunds = [],
  lastClosedYear,
  loading = false,
  writable = true,
  onAdd,
  onRemove,
}: {
  items?: LifeInsuranceEuroFundRate[];
  euroFunds?: LifeInsuranceEuroFund[];
  lastClosedYear: number;
  loading?: boolean;
  writable?: boolean;
  onAdd: () => void;
  onRemove: (id: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const notProvided = <EmptyValue label={t('portfolio.lifeInsurance.notProvided')} />;
  const percent = (value: string) =>
    t('portfolio.lifeInsurance.percent', { value: formatDecimal(value, i18n.language) });
  const missing = euroFunds.some((fund) => fund.missingLastYear);
  const latestYear = items.reduce<number | null>(
    (year, item) => (year === null || item.year > year ? item.year : year),
    null,
  );
  return (
    <ContractSection
      title={t('portfolio.lifeInsurance.rates.title')}
      label={t('portfolio.lifeInsurance.rates.title')}
      hint={
        loading ? (
          <SkeletonBlock className={styles.hintSkeleton} />
        ) : missing ? (
          <StatusBadge tone="warning">
            {t('portfolio.lifeInsurance.rates.missingBadge', { year: lastClosedYear })}
          </StatusBadge>
        ) : latestYear !== null ? (
          t('portfolio.lifeInsurance.rates.hintLatest', { year: latestYear })
        ) : (
          t('portfolio.lifeInsurance.rates.hintNone')
        )
      }
      action={
        <button
          className="secondary-action"
          type="button"
          disabled={loading || !writable}
          onClick={onAdd}
        >
          {t('portfolio.lifeInsurance.rates.add')}
        </button>
      }
    >
      <div className={styles.warnings}>
        {euroFunds
          .filter((f) => f.missingLastYear)
          .map((f) => (
            <p key={f.instrument.id}>
              <InstrumentLink instrument={f.instrument} /> :{' '}
              <StatusBadge tone="warning">
                {t('portfolio.lifeInsurance.rates.unprovidedBadge', { year: lastClosedYear })}
              </StatusBadge>
            </p>
          ))}
      </div>
      <div
        className={styles.scroll}
        role="region"
        aria-label={t('portfolio.lifeInsurance.rates.title')}
        tabIndex={0}
      >
        <table className={styles.table}>
          <caption>{t('portfolio.lifeInsurance.rates.caption')}</caption>
          <thead>
            <tr>
              {columnKeys.map((key) => (
                <th key={key} scope="col">
                  {t(`portfolio.lifeInsurance.rates.columns.${key}`)}
                </th>
              ))}
            </tr>
          </thead>
          {loading ? (
            <SkeletonTableRows columns={7} />
          ) : (
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{item.year}</td>
                  <td>
                    {euroFunds.some((f) => f.instrument.id === item.instrumentId) ? (
                      <InstrumentLink
                        instrument={
                          euroFunds.find((f) => f.instrument.id === item.instrumentId)!.instrument
                        }
                      />
                    ) : (
                      t('portfolio.lifeInsurance.rates.unavailableSupport')
                    )}
                  </td>
                  <td>{percent(item.servedRate)}</td>
                  <td>{item.bonusRate === null ? notProvided : percent(item.bonusRate)}</td>
                  <td>{t(`portfolio.lifeInsurance.basis.${item.basis}`)}</td>
                  <td>{item.sourceNote ?? notProvided}</td>
                  <td>
                    <button
                      className="icon-ghost"
                      type="button"
                      disabled={!writable}
                      aria-label={t('portfolio.lifeInsurance.rates.remove', {
                        year: item.year,
                        name:
                          euroFunds.find((f) => f.instrument.id === item.instrumentId)?.instrument
                            .name ?? t('portfolio.lifeInsurance.rates.thisSupport'),
                      })}
                      onClick={() => onRemove(item.id)}
                    >
                      <Icon name="delete" size={16} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          )}
        </table>
      </div>
      {!loading && items.length === 0 ? <p>{t('portfolio.lifeInsurance.rates.empty')}</p> : null}
    </ContractSection>
  );
}
