import type { DecimalAmount, PortfolioValuation } from '@cadran/api-client';
import type { ReactNode, RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { useValuation } from '@/features/portfolio/valuations/useValuations';
import { flagSentence } from '@/features/portfolio/valuations/valuationLabels';
import { ValuationPositionsTable } from './ValuationPositionsTable';
import styles from './ValuationDetailModal.module.css';

function Row({ children, label }: { children: ReactNode; label: string }) {
  return (
    <div className={styles.row}>
      <dt>{label}</dt>
      <dd>{children}</dd>
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
  const { i18n } = useTranslation();
  const timeZone = useWorkspaceTimeZone();
  const query = useValuation(id);
  const valuation = query.data;
  const exact = (amount: DecimalAmount | null, reason = 'Non calculable') =>
    amount ? (
      `${formatDecimal(amount.value, i18n.language)} ${amount.assetCode}`
    ) : (
      <EmptyValue label={reason} />
    );
  const title = valuation
    ? `Valorisation du ${formatCalendarNumericDay(valuation.valuedOn)}`
    : 'Valorisation';

  const pending = query.isPending;
  const block = <SkeletonBlock className={styles.valueSkeleton} />;
  const value = (render: (loaded: PortfolioValuation) => ReactNode) =>
    valuation ? render(valuation) : block;

  return (
    <Modal returnFocus={returnFocus} close={onClose} eyebrow="Portefeuille" title={title}>
      {query.isError ? (
        <div role="alert">
          <p className={styles.error}>La valorisation est indisponible.</p>
          <button className="secondary-action" onClick={() => void query.refetch()} type="button">
            Réessayer
          </button>
        </div>
      ) : (
        <div aria-busy={pending}>
          {pending ? <SkeletonStatus>Chargement de la valorisation…</SkeletonStatus> : null}
          <dl className={styles.list}>
            <Row label="Complétude">
              {value((loaded) =>
                loaded.completeness === 'COMPLETE' ? (
                  <StatusBadge tone="positive">Complète</StatusBadge>
                ) : (
                  <StatusBadge tone="warning">Partielle</StatusBadge>
                ),
              )}
            </Row>
            <Row label="Valeur totale">{value((v) => exact(v.totalValue))}</Row>
            <Row label="Titres">{value((v) => exact(v.securitiesValue))}</Row>
            <Row label="Espèces">{value((v) => exact(v.cash))}</Row>
            <Row label="Espèces calculées">{value((v) => exact(v.derivedCash))}</Row>
            <Row label="Écart d’espèces">
              {value((v) => exact(v.cashDifference, 'Aucun écart'))}
            </Row>
            <Row label="Total du relevé">
              {value((v) => exact(v.observedTotal, 'Non renseigné'))}
            </Row>
            <Row label="Écart">
              {value((v) => exact(v.observedTotalDifference, 'Non applicable'))}
            </Row>
            <Row label="Tolérance">{value((v) => exact(v.tolerance))}</Row>
            {valuation?.note ? <Row label="Note">{valuation.note}</Row> : null}
          </dl>
          {valuation && valuation.flags.length > 0 ? (
            <section className={styles.section}>
              <h3>Alertes</h3>
              <ul className={styles.flags}>
                {valuation.flags.map((flag) => (
                  <li key={flag}>{flagSentence[flag]}</li>
                ))}
              </ul>
            </section>
          ) : null}
          <section className={styles.section}>
            <h3>Positions</h3>
            <ValuationPositionsTable valuation={valuation ?? null} />
          </section>
          <div className={styles.links}>
            {valuation?.supersedesId ? (
              <button
                className="secondary-action"
                onClick={() => onOpen(valuation.supersedesId!)}
                type="button"
              >
                Remplace une valorisation précédente
              </button>
            ) : null}
            {valuation?.supersededAt ? (
              <p>
                Remplacée ou retirée le{' '}
                {formatCalendarNumericDay(
                  workspaceToday(new Date(valuation.supersededAt), timeZone),
                )}
                .
              </p>
            ) : null}
          </div>
        </div>
      )}
    </Modal>
  );
}
