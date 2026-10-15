import { useTranslation } from 'react-i18next';
import {
  SkeletonBlock,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import pendingStyles from './pending-transactions/PendingTransactionsTable.module.css';
import figuresStyles from './reconciliation-figures/ReconciliationFigures.module.css';
import formStyles from './resolution-form/ResolutionForm.module.css';
import skeletonStyles from './ReconciliationPanelSkeleton.module.css';

const CHOICES = ['MATCH', 'OVERRIDE', 'ADJUST'] as const;

/**
 * Mirrors the figures, pending table and resolution form of ReconciliationPanel
 * element for element: only the reconciliation values are pending.
 */
export function ReconciliationPanelSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  const figures = [
    'accounts.reconciliation.opening',
    'accounts.reconciliation.movements',
    'accounts.reconciliation.closing',
  ] as const;

  return (
    <div aria-busy="true" className={skeletonStyles.contents} data-testid="reconciliation-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={figuresStyles.figures}>
        <dl className={figuresStyles.list}>
          {figures.map((key) => (
            <div key={key}>
              <dt>{t(key)}</dt>
              <dd>
                <SkeletonBlock className={skeletonStyles.amount} />
              </dd>
            </div>
          ))}
        </dl>
      </div>
      <div className={pendingStyles.block}>
        <SkeletonBlock className={skeletonStyles.count} />
        <table className={pendingStyles.table}>
          <caption className="sr-only">{t('accounts.reconciliation.pending.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('accounts.reconciliation.pending.date')}</th>
              <th scope="col">{t('accounts.reconciliation.pending.label')}</th>
              <th scope="col">{t('accounts.reconciliation.pending.amount')}</th>
            </tr>
          </thead>
          <SkeletonTableRows columns={3} rows={3} />
        </table>
      </div>
      <form className={formStyles.form}>
        <fieldset className={formStyles.choices} disabled>
          <legend>{t('accounts.reconciliation.resolution.legend')}</legend>
          {CHOICES.map((value) => (
            <label className={formStyles.choice} key={value}>
              <input name="reconciliation-resolution-skeleton" type="radio" value={value} />
              <span>
                {t(`accounts.reconciliation.resolution.${value}.label`)}
                <small>{t(`accounts.reconciliation.resolution.${value}.hint`)}</small>
              </span>
            </label>
          ))}
        </fieldset>
        <div className={formStyles.actions}>
          <button className="primary-action" disabled type="submit">
            {t('accounts.reconciliation.reconcile')}
          </button>
        </div>
      </form>
    </div>
  );
}
