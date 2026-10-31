import type { PortfolioKpiExplanation, PortfolioOverviewSource } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { ExplanationFigure } from '@/features/portfolio/explanation/ExplanationFigure';
import { ExplanationSources } from '@/features/portfolio/explanation/ExplanationSources';
import explanation from '@/features/portfolio/explanation/Explanation.module.css';
import {
  kpiLabels,
  reasonLabels,
  type KpiCode,
} from '@/features/portfolio/portfolio-account/overviewLabels';
import { formatCalendarNumericDay } from '@/lib/decimal';

/** One summary indicator: its value first, how it is computed, what weakens it, then its sources. */
export function PortfolioKpiExplanationModal({
  code,
  value,
  explanation: data,
  onClose,
  onSource,
}: {
  code: KpiCode;
  /** The value as the summary shows it. */
  value: string;
  explanation: PortfolioKpiExplanation;
  onClose: () => void;
  onSource: (source: PortfolioOverviewSource) => void;
}) {
  const { t } = useTranslation();
  const day = formatCalendarNumericDay;
  return (
    <Modal close={onClose} title={kpiLabels[code]}>
      <div className={explanation.content}>
        <ExplanationFigure
          meta={
            <>
              <span>{`Du ${day(data.period.from)} au ${day(data.period.to)}`}</span>
              <span aria-hidden="true">·</span>
              <span>{reasonLabels[data.quality] ?? data.quality}</span>
            </>
          }
          value={value}
        />
        <section className={explanation.block}>
          <h3>Calcul</h3>
          <p>{t(`portfolio.kpi.${code}.formula`)}</p>
        </section>
        {data.reason ? (
          <ul className={explanation.warnings}>
            <li>{reasonLabels[data.reason] ?? data.reason}</li>
          </ul>
        ) : null}
        <ExplanationSources
          more={data.truncatedSourceCount}
          onSource={onSource}
          sources={data.sources}
        />
      </div>
    </Modal>
  );
}
