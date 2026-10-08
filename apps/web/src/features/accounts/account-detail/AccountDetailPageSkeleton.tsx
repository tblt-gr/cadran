import {
  SkeletonBlock,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import headerStyles from './account-detail-header/AccountDetailHeader.module.css';
import identityStyles from './account-identity/AccountIdentityCard.module.css';
import movementsStyles from './account-movements/AccountMovementsTable.module.css';
import sectionStyles from './account-movements-section/AccountMovementsSection.module.css';
import styles from './AccountDetailPage.module.css';
import skeletonStyles from './AccountDetailPageSkeleton.module.css';

export function AccountDetailPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.page} data-testid="account-detail-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <section className={headerStyles.intro}>
        <div className={headerStyles.titleRow}>
          <p>{t('accounts.detail.eyebrow')}</p>
        </div>
        <div className={headerStyles.actions}>
          <button className="secondary-action" disabled type="button">
            {t('accounts.detail.actions.rules')}
          </button>
          <button className="secondary-action" disabled type="button">
            {t('accounts.detail.actions.reconcile')}
          </button>
          <button className="secondary-action" disabled type="button">
            {t('accounts.detail.actions.addTransfer')}
          </button>
          <button className="primary-action" disabled type="button">
            {t('accounts.detail.actions.addTransaction')}
          </button>
        </div>
      </section>
      <div className={`card ${identityStyles.card}`}>
        <div className={identityStyles.top}>
          <div className={identityStyles.identity}>
            <p>{t('accounts.detail.identity.title')}</p>
            <SkeletonBlock className={skeletonStyles.name} />
            <SkeletonBlock className={skeletonStyles.line} />
            <SkeletonBlock className={skeletonStyles.line} />
          </div>
          <div className={identityStyles.balancePanel}>
            <span className={identityStyles.balanceLabel}>{t('accounts.fields.balance')}</span>
            <SkeletonBlock className={skeletonStyles.balance} />
            <SkeletonBlock className={skeletonStyles.line} />
          </div>
        </div>
      </div>
      <div className={sectionStyles.movementsHeading}>
        <h3 className={sectionStyles.movementsTitle}>{t('accounts.detail.movements.title')}</h3>
      </div>
      <div className={`card ${movementsStyles.panel}`}>
        <div className={movementsStyles.tableScroll}>
          <table>
            <thead>
              <tr>
                <th scope="col">{t('transactions.fields.bookedOn')}</th>
                <th scope="col">{t('transactions.fields.nature')}</th>
                <th scope="col">{t('transactions.fields.rawLabel')}</th>
                <th scope="col">{t('transactions.fields.category')}</th>
                <th scope="col">{t('accounts.detail.movements.counterpart')}</th>
                <th scope="col">{t('transactions.fields.amount')}</th>
                <th scope="col">{t('transactions.fields.state')}</th>
              </tr>
            </thead>
            <SkeletonTableRows columns={7} rows={5} />
          </table>
        </div>
      </div>
    </div>
  );
}
