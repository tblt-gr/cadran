import type { GoalAnnualStatus, GoalStatus } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount } from '@/lib/decimal';
import { formatGoalMonth } from './goalMonth';
import styles from './GoalsPage.module.css';
import { monthHref } from '@/features/budget/monthly-budget/budgetPeriod';

export function GoalDetailModal({
  assetCode,
  close,
  status,
}: {
  assetCode: string;
  close: () => void;
  status: GoalStatus;
}) {
  const { t, i18n } = useTranslation();
  const annual = status.kind === 'ANNUAL' ? (status as GoalAnnualStatus) : null;
  const emptyCell = (
    <EmptyValue label={t(`goals.reasons.${status.reason ?? 'MISSING_MONTH_VALUE'}`)} />
  );
  return (
    <Modal close={close} title={t('goals.detailTitle')}>
      {annual?.months?.length ? (
        <div className={styles.statusTableWrap}>
          <table className={styles.statusTable}>
            <caption className="sr-only">{t('goals.statusTable.caption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('goals.statusTable.month')}</th>
                <th scope="col">{t('goals.statusTable.state')}</th>
                <th scope="col">{t('goals.statusTable.value')}</th>
                <th scope="col">{t('goals.statusTable.expected')}</th>
                <th scope="col">{t('goals.statusTable.cumulativeValue')}</th>
                <th scope="col">{t('goals.statusTable.cumulativeExpected')}</th>
              </tr>
            </thead>
            <tbody>
              {annual.months.map((month) => (
                <tr key={month.month}>
                  <th scope="row">
                    <a href={monthHref(month.month, null)}>
                      {formatGoalMonth(month.month, i18n.language)}
                    </a>
                  </th>
                  <td>{t(`goals.statusTable.states.${month.state}`)}</td>
                  <td>
                    {month.value === null
                      ? emptyCell
                      : formatAmount(month.value, assetCode, i18n.language)}
                  </td>
                  <td>
                    {month.expectedPart === null
                      ? emptyCell
                      : formatAmount(month.expectedPart, assetCode, i18n.language)}
                  </td>
                  <td>
                    {month.cumulativeValue === null
                      ? emptyCell
                      : formatAmount(month.cumulativeValue, assetCode, i18n.language)}
                  </td>
                  <td>
                    {month.cumulativeExpected === null
                      ? emptyCell
                      : formatAmount(month.cumulativeExpected, assetCode, i18n.language)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <p className={styles.notice}>{t('goals.detailUnavailable')}</p>
      )}
    </Modal>
  );
}
