import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import type { LifeInsuranceContract } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { managementLabels } from '@/features/portfolio/life-insurance/lifeInsuranceLabels';
import styles from './ContractCard.module.css';
export function ContractCard({
  contract,
  onEdit,
  writable = true,
}: {
  contract?: LifeInsuranceContract;
  onEdit: () => void;
  writable?: boolean;
}) {
  const { t } = useTranslation();
  const fields = [
    [t('portfolio.lifeInsurance.contract.insurer'), contract?.insurer],
    [t('portfolio.lifeInsurance.contract.distributor'), contract?.distributor],
    [t('portfolio.lifeInsurance.contract.commercialName'), contract?.commercialName],
    [t('portfolio.lifeInsurance.contract.number'), contract?.contractNumberMasked],
    [
      t('portfolio.lifeInsurance.contract.subscribedOn'),
      contract ? formatCalendarNumericDay(contract.subscribedOn) : null,
    ],
    [
      t('portfolio.lifeInsurance.contract.taxStart'),
      contract ? formatCalendarNumericDay(contract.taxStartOn) : null,
    ],
    [
      t('portfolio.lifeInsurance.contract.managementMode'),
      contract ? managementLabels[contract.managementMode] : null,
    ],
  ];
  return (
    <section className={styles.card} aria-label={t('portfolio.lifeInsurance.contract.cardLabel')}>
      <header className={styles.heading}>
        <h3>{t('portfolio.lifeInsurance.contract.title')}</h3>
        <button
          className="icon-ghost"
          type="button"
          aria-label={t('portfolio.lifeInsurance.contract.edit')}
          disabled={!contract || !writable}
          onClick={onEdit}
        >
          <Icon name="edit" size={16} />
        </button>
      </header>
      <dl className={styles.details}>
        {fields.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>
              {!contract ? (
                <SkeletonBlock className={styles.valueSkeleton} />
              ) : (
                (value ?? <EmptyValue label={t('portfolio.lifeInsurance.notProvided')} />)
              )}
            </dd>
          </div>
        ))}
        <div>
          <dt>{t('portfolio.lifeInsurance.contract.status')}</dt>
          <dd>
            {contract ? (
              <StatusBadge tone={contract.status === 'OPEN' ? 'positive' : 'neutral'}>
                {contract.status === 'OPEN'
                  ? t('portfolio.lifeInsurance.contract.open')
                  : t('portfolio.lifeInsurance.contract.closed')}
              </StatusBadge>
            ) : (
              <SkeletonBlock className={styles.valueSkeleton} />
            )}
            {contract?.closedOn
              ? t('portfolio.lifeInsurance.contract.closedOn', {
                  date: formatCalendarNumericDay(contract.closedOn),
                })
              : null}
          </dd>
        </div>
      </dl>
    </section>
  );
}
