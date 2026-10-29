import type { EmergencyFundCoverage } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarDay } from '@/lib/decimal';
import { formatCeiledFigure, formatFigure } from './formatFigure';
import { formatGoalMonth } from '@/features/goals/goalMonth';
import styles from './EmergencyFund.module.css';
import { monthHref } from '@/features/budget/monthly-budget/budgetPeriod';

interface Props {
  close: () => void;
  coverage: EmergencyFundCoverage;
}

/** Every source account and reference month of the ratio, each linking to its origin. */
export function EmergencyFundExplainModal({ close, coverage }: Props) {
  const { t, i18n } = useTranslation();
  const { reference } = coverage;
  const excluded = new Map(reference.excludedMonths.map((entry) => [entry.month, entry.reason]));
  return (
    <Modal close={close} title={t('goals.emergencyFund.explainTitle')}>
      <div className={styles.explain}>
        <section aria-labelledby="emergency-fund-accounts">
          <h3 id="emergency-fund-accounts">{t('goals.emergencyFund.accountsTitle')}</h3>
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">{t('goals.emergencyFund.accountsCaption')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('goals.emergencyFund.account')}</th>
                  <th scope="col">{t('goals.emergencyFund.value')}</th>
                  <th scope="col">{t('goals.emergencyFund.valuedOn')}</th>
                </tr>
              </thead>
              <tbody>
                {coverage.liquidAssets.accounts.map((account) => (
                  <tr key={account.id}>
                    <th scope="row">
                      <a
                        href={`/accounts/${account.id}`}
                        onClick={(event) =>
                          handleClientNavigation(event, `/accounts/${account.id}`)
                        }
                      >
                        {account.label}
                      </a>
                    </th>
                    <td className="money">
                      {account.value === null ? (
                        <EmptyValue label={t('goals.emergencyFund.missingValuation')} />
                      ) : (
                        formatFigure(account.value, account.assetCode, i18n.language)
                      )}
                    </td>
                    <td>
                      {account.valuedOn === null ? null : (
                        <span className={styles.date}>
                          {formatCalendarDay(account.valuedOn, i18n.language)}
                          {account.stale ? (
                            <StatusBadge icon="alert" tone="warning">
                              {t('goals.emergencyFund.stale')}
                            </StatusBadge>
                          ) : null}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
        <section aria-labelledby="emergency-fund-months">
          <h3 id="emergency-fund-months">{t('goals.emergencyFund.monthsTitle')}</h3>
          <p className={styles.summary}>
            {t('goals.emergencyFund.usedMonths', {
              used: reference.usedMonths,
              expected: reference.expectedMonths,
            })}
            {' · '}
            {reference.average === null ? (
              <EmptyValue label={t('goals.noData')} />
            ) : (
              t('goals.emergencyFund.average', {
                amount: formatCeiledFigure(reference.average, reference.assetCode, i18n.language),
              })
            )}
          </p>
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption className="sr-only">{t('goals.emergencyFund.monthsCaption')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('goals.statusTable.month')}</th>
                  <th scope="col">{t('goals.emergencyFund.value')}</th>
                  <th scope="col">{t('goals.statusTable.state')}</th>
                </tr>
              </thead>
              <tbody>
                {reference.months.map((month) => {
                  const reason = excluded.get(month.month);
                  return (
                    <tr key={month.month}>
                      <th scope="row">
                        <a
                          href={monthHref(month.month, null)}
                          onClick={(event) =>
                            handleClientNavigation(event, monthHref(month.month, null))
                          }
                        >
                          {formatGoalMonth(month.month, i18n.language)}
                        </a>
                      </th>
                      <td className="money">
                        {month.value === null ? (
                          <EmptyValue label={t('goals.noData')} />
                        ) : (
                          formatFigure(month.value, reference.assetCode, i18n.language)
                        )}
                      </td>
                      <td>
                        {reason
                          ? t('goals.emergencyFund.excluded', {
                              reason: t(`goals.emergencyFund.excludedReasons.${reason}`),
                            })
                          : t('goals.emergencyFund.used')}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </Modal>
  );
}
