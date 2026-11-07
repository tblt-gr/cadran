import type { GoalStatus } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatGoalFigure } from './goalFormat';
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
  const yearly = status.periodicity === 'YEARLY';
  const figure = (value: string) =>
    formatGoalFigure(value, status.valueType, assetCode, i18n.language);
  const emptyCell = (
    <EmptyValue label={t(`goals.reasons.${status.reason ?? 'MISSING_MONTH_VALUE'}`)} />
  );
  return (
    <Modal intent="detail" close={close} title={t('goals.detailTitle')}>
      {status.months?.length ? (
        <div className={styles.statusTableWrap}>
          <table className={styles.statusTable}>
            <caption className="sr-only">{t('goals.statusTable.caption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('goals.statusTable.month')}</th>
                <th scope="col">{t('goals.statusTable.state')}</th>
                <th scope="col">{t('goals.statusTable.value')}</th>
                <th scope="col">{t('goals.statusTable.expected')}</th>
                {yearly ? (
                  <>
                    <th scope="col">{t('goals.statusTable.cumulativeValue')}</th>
                    <th scope="col">{t('goals.statusTable.cumulativeExpected')}</th>
                  </>
                ) : null}
              </tr>
            </thead>
            <tbody>
              {status.months.map((month) => (
                <tr key={month.month}>
                  <th scope="row">
                    <a href={monthHref(month.month, null)}>
                      {formatGoalMonth(month.month, i18n.language)}
                    </a>
                  </th>
                  <td>{t(`goals.statusTable.states.${month.state}`)}</td>
                  <td>{month.value === null ? emptyCell : figure(month.value)}</td>
                  <td>{month.expectedPart === null ? emptyCell : figure(month.expectedPart)}</td>
                  {yearly ? (
                    <>
                      <td>
                        {month.cumulativeValue === null ? emptyCell : figure(month.cumulativeValue)}
                      </td>
                      <td>
                        {month.cumulativeExpected === null
                          ? emptyCell
                          : figure(month.cumulativeExpected)}
                      </td>
                    </>
                  ) : null}
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
