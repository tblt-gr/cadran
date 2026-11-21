import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import type { DecimalAmount, PortfolioValuation } from '@cadran/api-client';
import type { ReactNode, RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { useValuation } from '@/features/portfolio/valuations/useValuations';
import { flagSentence } from '@/features/portfolio/valuations/valuationLabels';
import { ValuationPositionsTable } from './ValuationPositionsTable';
import styles from './ValuationDetailModal.module.css';
import { formatExactAmount } from '@/lib/decimal';

function Row({ children, label }: { children: ReactNode; label: string }) {
  return (
    <div className={styles.row}>
      <dt>{label}</dt>
      <dd>
        {typeof children === 'string' ? (
          <MoneyValue wrapGroups={false} value={children} />
        ) : (
          children
        )}
      </dd>
    </div>
  );
}

/** One valuation read by id, superseded ones included; figures are shown exactly, never rounded. */
export function ValuationDetailModal({
  id,
  returnFocus,
  onClose,
  onOpen,
}: {
  id: string;
  returnFocus?: RefObject<HTMLElement | null>;
  onClose: () => void;
  onOpen: (id: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const timeZone = useWorkspaceTimeZone();
  const query = useValuation(id);
  const valuation = query.data;
  const exact = (
    amount: DecimalAmount | null,
    reason = t('portfolio.valuations.common.notCalculable'),
  ) =>
    amount ? (
      formatExactAmount(amount.value, amount.assetCode, i18n.language)
    ) : (
      <EmptyValue label={reason} />
    );
  const title = valuation
    ? t('portfolio.explanation.source.VALUATION', {
        date: formatCalendarNumericDay(valuation.valuedOn),
      })
    : t('portfolio.valuations.detailModal.titleFallback');

  const pending = query.isPending;
  const block = <SkeletonBlock className={styles.valueSkeleton} />;
  const value = (render: (loaded: PortfolioValuation) => ReactNode) =>
    valuation ? render(valuation) : block;

  return (
    <Modal intent="detail" returnFocus={returnFocus} close={onClose} title={title}>
      {query.isError ? (
        <div role="alert">
          <p className={styles.error}>{t('portfolio.valuations.detailModal.unavailable')}</p>
          <button className="secondary-action" onClick={() => void query.refetch()} type="button">
            {t('actions.retry')}
          </button>
        </div>
      ) : (
        <div aria-busy={pending}>
          {pending ? (
            <SkeletonStatus>{t('portfolio.valuations.detailModal.loading')}</SkeletonStatus>
          ) : null}
          <dl className={styles.list}>
            <Row label={t('portfolio.valuations.detailModal.completeness')}>
              {value((loaded) =>
                loaded.completeness === 'COMPLETE' ? (
                  <StatusBadge tone="positive">
                    {t('portfolio.valuations.common.complete')}
                  </StatusBadge>
                ) : (
                  <StatusBadge tone="warning">
                    {t('portfolio.valuations.common.partial')}
                  </StatusBadge>
                ),
              )}
            </Row>
            <Row label={t('portfolio.valuations.detailModal.totalValue')}>
              {value((v) => exact(v.totalValue))}
            </Row>
            <Row label={t('portfolio.takeover.securities')}>
              {value((v) => exact(v.securitiesValue))}
            </Row>
            <Row label={t('portfolio.takeover.cash')}>{value((v) => exact(v.cash))}</Row>
            <Row label={t('portfolio.valuations.detailModal.derivedCash')}>
              {value((v) => exact(v.derivedCash))}
            </Row>
            <Row label={t('portfolio.valuations.flags.CASH_DIFFERENCE')}>
              {value((v) => exact(v.cashDifference, t('portfolio.valuations.detailModal.noGap')))}
            </Row>
            <Row label={t('portfolio.valuations.common.statementTotal')}>
              {value((v) => exact(v.observedTotal, t('portfolio.lifeInsurance.notProvided')))}
            </Row>
            <Row label={t('portfolio.valuations.detailModal.gap')}>
              {value((v) =>
                exact(
                  v.observedTotalDifference,
                  t('portfolio.valuations.detailModal.notApplicable'),
                ),
              )}
            </Row>
            <Row label={t('portfolio.lifeInsurance.explain.tolerance')}>
              {value((v) => exact(v.tolerance))}
            </Row>
            {valuation?.note ? (
              <Row label={t('portfolio.valuations.common.note')}>{valuation.note}</Row>
            ) : null}
          </dl>
          {valuation && valuation.flags.length > 0 ? (
            <section className={styles.section}>
              <h3>{t('portfolio.valuations.detailModal.alerts')}</h3>
              <ul className={styles.flags}>
                {valuation.flags.map((flag) => (
                  <li key={flag}>{flagSentence[flag]}</li>
                ))}
              </ul>
            </section>
          ) : null}
          <section className={styles.section}>
            <h3>{t('portfolio.valuations.detailModal.positions')}</h3>
            <ValuationPositionsTable valuation={valuation ?? null} />
          </section>
          <div className={styles.links}>
            {valuation?.supersedesId ? (
              <button
                className="secondary-action"
                onClick={() => onOpen(valuation.supersedesId!)}
                type="button"
              >
                {t('portfolio.valuations.detailModal.replaces')}
              </button>
            ) : null}
            {valuation?.supersededAt ? (
              <p>
                {t('portfolio.valuations.detailModal.supersededOn', {
                  date: formatCalendarNumericDay(
                    workspaceToday(new Date(valuation.supersededAt), timeZone),
                  ),
                })}
              </p>
            ) : null}
          </div>
        </div>
      )}
    </Modal>
  );
}
