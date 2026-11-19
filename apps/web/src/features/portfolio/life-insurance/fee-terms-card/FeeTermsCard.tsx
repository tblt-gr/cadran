import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import type { LifeInsuranceFeeTerms } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount, formatDecimal, formatCalendarNumericDay } from '@/lib/decimal';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { ContractSection } from '@/features/portfolio/life-insurance/contract-section/ContractSection';
import styles from './FeeTermsCard.module.css';
const columnKeys = [
  'from',
  'entry',
  'euroFund',
  'unitLinked',
  'switch',
  'switchFixed',
  'source',
  'actions',
] as const;

export function FeeTermsCard({
  items = [],
  loading = false,
  writable = true,
  onAdd,
  onRemove,
}: {
  items?: LifeInsuranceFeeTerms[];
  loading?: boolean;
  writable?: boolean;
  onAdd: () => void;
  onRemove: (id: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const notProvided = <EmptyValue label={t('portfolio.lifeInsurance.notProvided')} />;
  const percent = (value: string | null) =>
    value === null
      ? notProvided
      : t('portfolio.lifeInsurance.percent', { value: formatDecimal(value, i18n.language) });
  const latest = items.reduce<string | null>(
    (date, item) => (date === null || item.validFrom > date ? item.validFrom : date),
    null,
  );
  return (
    <ContractSection
      title={t('portfolio.lifeInsurance.fees.title')}
      label={t('portfolio.lifeInsurance.fees.title')}
      hint={
        loading ? (
          <SkeletonBlock className={styles.hintSkeleton} />
        ) : latest ? (
          t('portfolio.lifeInsurance.fees.hintLatest', { date: formatCalendarNumericDay(latest) })
        ) : (
          t('portfolio.lifeInsurance.fees.hintNone')
        )
      }
      action={
        <button
          className="secondary-action"
          type="button"
          disabled={loading || !writable}
          onClick={onAdd}
        >
          {t('portfolio.lifeInsurance.fees.add')}
        </button>
      }
    >
      <div
        className={styles.scroll}
        role="region"
        aria-label={t('portfolio.lifeInsurance.fees.title')}
        tabIndex={0}
      >
        <table className={styles.table}>
          <thead>
            <tr>
              {columnKeys.map((key) => (
                <th scope="col" key={key}>
                  {t(`portfolio.lifeInsurance.fees.columns.${key}`)}
                </th>
              ))}
            </tr>
          </thead>
          {loading ? (
            <SkeletonTableRows columns={8} />
          ) : (
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{formatCalendarNumericDay(item.validFrom)}</td>
                  <td>{percent(item.entryFeeRate)}</td>
                  <td>{percent(item.euroFundManagementFeeRate)}</td>
                  <td>{percent(item.unitLinkedManagementFeeRate)}</td>
                  <td>{percent(item.switchFeeRate)}</td>
                  <td>
                    {item.switchFeeFixed
                      ? formatAmount(
                          item.switchFeeFixed.value,
                          item.switchFeeFixed.assetCode,
                          i18n.language,
                        )
                      : notProvided}
                  </td>
                  <td>{item.sourceNote ?? notProvided}</td>
                  <td>
                    <button
                      className="icon-ghost"
                      type="button"
                      disabled={!writable}
                      aria-label={t('portfolio.lifeInsurance.fees.remove', {
                        date: formatCalendarNumericDay(item.validFrom),
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
      {!loading && items.length === 0 ? <p>{t('portfolio.lifeInsurance.fees.empty')}</p> : null}
    </ContractSection>
  );
}
