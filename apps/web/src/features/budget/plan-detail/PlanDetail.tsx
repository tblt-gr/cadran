import type { BudgetPlanDetail, BudgetTargetDetail } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { BudgetComparisonsPanel } from '@/features/budget/budget-comparisons/BudgetComparisonsPanel';
import { budgetErrorKind } from '@/features/budget/budgetError';
import styles from './PlanDetail.module.css';

const tones = { DRAFT: 'info', ACTIVE: 'positive', CLOSED: 'warning' } as const;

/**
 * One budget plan's summary, its target table and, for a monthly plan, its
 * comparisons panel. A named, self-contained section of BudgetPage: it owns
 * no state of its own, but its rendering rules (lifecycle actions per state,
 * warnings per target) are numerous enough to justify their own file and test
 * surface, separate from the page that lists and creates plans.
 */
export function PlanDetail({
  detail,
  language,
  onEditPlan,
  onEditTarget,
  onNewTarget,
  onLifecycle,
  lifecycleError,
  lifecyclePending,
}: {
  detail: BudgetPlanDetail;
  language: string;
  onEditPlan: () => void;
  onEditTarget: (target: BudgetTargetDetail) => void;
  onNewTarget: () => void;
  onLifecycle: (action: 'activate' | 'close') => void;
  lifecycleError: ReturnType<typeof budgetErrorKind>;
  lifecyclePending: boolean;
}) {
  const { t } = useTranslation();
  const activationHintId = `budget-plan-${detail.id}-activation-hint`;
  const emptyDraft = detail.state === 'DRAFT' && detail.targets.length === 0;
  return (
    <>
      <section className={`card ${styles.summary}`}>
        <div>
          <span>{t('budget.columns.currency')}</span>
          <strong>{detail.assetCode}</strong>
        </div>
        <div>
          <span>{t('budget.columns.state')}</span>
          <StatusBadge tone={tones[detail.state]}>{t(`budget.states.${detail.state}`)}</StatusBadge>
        </div>
        <div className={styles.summaryActions}>
          {detail.state === 'DRAFT' ? (
            <>
              <button className="secondary-action" onClick={onEditPlan} type="button">
                {t('budget.edit')}
              </button>
              <button
                aria-describedby={emptyDraft ? activationHintId : undefined}
                className="primary-action"
                disabled={lifecyclePending || emptyDraft}
                onClick={() => onLifecycle('activate')}
                type="button"
              >
                {t('budget.activate')}
              </button>
            </>
          ) : null}
          {detail.state === 'ACTIVE' ? (
            <button
              className="secondary-action"
              disabled={lifecyclePending}
              onClick={() => onLifecycle('close')}
              type="button"
            >
              {t('budget.close')}
            </button>
          ) : null}
        </div>
        {lifecycleError ? (
          <p className={styles.lifecycleError} role="alert">
            {t(`budget.errors.${lifecycleError}`)}
          </p>
        ) : null}
      </section>
      <section className={styles.targets}>
        <div className={styles.targetHeading}>
          <div>
            <h3>{t('budget.targets.title')}</h3>
            <p>{t('budget.targets.description')}</p>
          </div>
          {detail.state !== 'CLOSED' ? (
            <button className="primary-action" onClick={onNewTarget} type="button">
              {t('budget.targets.add')}
            </button>
          ) : null}
        </div>
        {detail.targets.length === 0 ? (
          <p className={styles.emptyTargets} id={emptyDraft ? activationHintId : undefined}>
            {t('budget.targets.empty')}
          </p>
        ) : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <thead>
                <tr>
                  <th>{t('budget.columns.scope')}</th>
                  <th>{t('budget.columns.value')}</th>
                  <th>{t('budget.columns.resolved')}</th>
                  <th>{t('budget.columns.warning')}</th>
                  <th>
                    <span className="sr-only">{t('actions.more')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {detail.targets.map((target) => (
                  <tr key={target.id}>
                    <td>
                      {t(`budget.scopeTypes.${target.scopeType}`)} · {target.scopeLabel}
                    </td>
                    <td>
                      {target.valueType === 'AMOUNT' && target.storedAmount ? (
                        <MoneyValue
                          value={formatAmount(target.storedAmount, detail.assetCode, language)}
                        />
                      ) : target.storedRatio ? (
                        formatRatioPercentage(target.storedRatio, language)
                      ) : (
                        '—'
                      )}
                    </td>
                    <td>
                      {target.resolvedAmount === null ? (
                        <span>{t('budget.nonCalculable')}</span>
                      ) : (
                        <MoneyValue
                          value={formatAmount(target.resolvedAmount, detail.assetCode, language)}
                        />
                      )}
                    </td>
                    <td>
                      {target.overlapping || target.nonCalculableReason ? (
                        <div className={styles.warnings}>
                          {target.overlapping ? (
                            <span className={styles.warning}>{t('budget.overlap')}</span>
                          ) : null}
                          {target.nonCalculableReason ? (
                            <span className={styles.warning}>
                              {t(`budget.reasons.${target.nonCalculableReason}`)}
                            </span>
                          ) : null}
                        </div>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td>
                      {detail.state !== 'CLOSED' ? (
                        <button
                          className="secondary-action"
                          onClick={() => onEditTarget(target)}
                          type="button"
                        >
                          {t('budget.edit')}
                        </button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      {detail.periodType === 'MONTH' ? (
        <section className={styles.comparisons} aria-labelledby="budget-comparisons-title">
          <div className={styles.targetHeading}>
            <div>
              <h3 id="budget-comparisons-title">{t('budget.comparisons.title')}</h3>
              <p>{t('budget.comparisons.description')}</p>
            </div>
          </div>
          <BudgetComparisonsPanel planId={detail.id} />
        </section>
      ) : null}
    </>
  );
}
