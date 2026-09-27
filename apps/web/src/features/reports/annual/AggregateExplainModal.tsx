import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { AnnualCellValue } from './AnnualCellValue';
import { monthLabel } from './annualRowLabels';
import { useAnnualColumnExplanation } from './useAnnualReport';
import styles from './AggregateExplainModal.module.css';

interface AggregateExplainModalProps {
  assetCode: string | null;
  close: () => void;
  columnId: string;
  columnLabel: string;
  year: number;
}

export function AggregateExplainModal({
  assetCode,
  close,
  columnId,
  columnLabel,
  year,
}: AggregateExplainModalProps) {
  const { t, i18n } = useTranslation();
  const explanation = useAnnualColumnExplanation(year, columnId);

  return (
    <Modal close={close} eyebrow={t('reports.annual.explainEyebrow')} title={columnLabel}>
      {explanation.isPending ? (
        <p role="status">{t('reports.explanationLoading')}</p>
      ) : explanation.isError ? (
        <div role="alert">
          <p>{t('reports.explanationError')}</p>
          <button
            className="secondary-action"
            onClick={() => void explanation.refetch()}
            type="button"
          >
            {t('reports.retry')}
          </button>
        </div>
      ) : (
        <div className={styles.body}>
          <dl className={styles.facts}>
            <dt>{t('reports.formula')}</dt>
            <dd>{explanation.data.formula}</dd>
            <dt>{t('reports.annual.policy')}</dt>
            <dd>
              {explanation.data.policy.state === 'MIXED'
                ? t('reports.annual.mixedPolicy', {
                    versions: explanation.data.policy.versions.join(', '),
                  })
                : `${explanation.data.policy.label ?? ''} (v${explanation.data.policy.version ?? ''})`}
            </dd>
            {explanation.data.aggregate.periodEnd ? (
              <>
                <dt>{t('reports.annual.periodEnd')}</dt>
                <dd>
                  <AnnualCellValue
                    assetCode={assetCode}
                    kind={explanation.data.kind}
                    reason={null}
                    value={explanation.data.aggregate.periodEnd.value}
                  />
                </dd>
              </>
            ) : null}
          </dl>
          <table className={styles.months}>
            <caption>{t('reports.annual.explainMonths')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('reports.annual.month')}</th>
                <th scope="col">{t('reports.value')}</th>
                <th scope="col">{t('reports.annual.counted')}</th>
              </tr>
            </thead>
            <tbody>
              {explanation.data.months.map((month) => (
                <tr key={month.month}>
                  <th scope="row">{monthLabel(month.month, i18n.language)}</th>
                  <td>
                    <AnnualCellValue
                      assetCode={assetCode}
                      kind={explanation.data.kind}
                      reason={month.reason}
                      value={month.value}
                    />
                  </td>
                  <td>
                    {month.counted
                      ? t('reports.annual.countedYes')
                      : t('reports.annual.excludedBecause', {
                          reason: t(`reports.annual.exclusions.${month.exclusionReason ?? ''}`, {
                            defaultValue: month.exclusionReason ?? '',
                          }),
                        })}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  );
}
