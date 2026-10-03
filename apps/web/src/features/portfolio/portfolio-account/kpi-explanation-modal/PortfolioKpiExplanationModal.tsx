import type { PortfolioKpiExplanation, PortfolioOverviewSource } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import {
  kpiLabels,
  reasonLabels,
  type KpiCode,
} from '@/features/portfolio/portfolio-account/overviewLabels';
import styles from './PortfolioKpiExplanationModal.module.css';
export function PortfolioKpiExplanationModal({
  code,
  explanation,
  onClose,
  onSource,
}: {
  code: KpiCode;
  explanation: PortfolioKpiExplanation;
  onClose: () => void;
  onSource: (source: PortfolioOverviewSource) => void;
}) {
  const { t } = useTranslation();
  return (
    <Modal close={onClose} title={`Expliquer : ${kpiLabels[code]}`}>
      <div className={styles.content}>
        <section>
          <h3>Formule</h3>
          <p>{t(`portfolio.kpi.${code}.formula`)}</p>
        </section>
        <section>
          <h3>Période</h3>
          <p>
            Du {explanation.period.from} au {explanation.period.to}
          </p>
        </section>
        <section>
          <h3>Qualité</h3>
          <p>{reasonLabels[explanation.quality] ?? explanation.quality}</p>
          {explanation.reason ? (
            <p>{reasonLabels[explanation.reason] ?? explanation.reason}</p>
          ) : null}
        </section>
        <section>
          <h3>Sources</h3>
          {explanation.sources.length ? (
            <ul>
              {explanation.sources.map((source) => (
                <li key={`${source.kind}-${source.id}`}>
                  <button
                    className="secondary-action"
                    type="button"
                    onClick={() => onSource(source)}
                  >
                    {source.kind === 'OPERATION' ? 'Opération' : 'Valorisation'} du {source.date}
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <p>Aucune source à cette date.</p>
          )}
          {explanation.truncatedSourceCount > 0 ? (
            <p>et {explanation.truncatedSourceCount} autres</p>
          ) : null}
        </section>
      </div>
    </Modal>
  );
}
