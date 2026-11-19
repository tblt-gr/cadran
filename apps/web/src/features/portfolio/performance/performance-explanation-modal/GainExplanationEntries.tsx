import type { PortfolioGainFigure } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import styles from './PerformanceExplanationModal.module.css';
type FactKey =
  | 'startValue'
  | 'endValue'
  | 'netFlows'
  | 'realized'
  | 'unrealizedChange'
  | 'income'
  | 'fees'
  | 'taxes'
  | 'residual';

export function GainExplanationEntries({
  gain,
  assetCode,
}: {
  gain: PortfolioGainFigure;
  assetCode: string;
}) {
  const { t, i18n } = useTranslation();
  const fact = (key: FactKey) => t(`portfolio.performance.facts.${key}`);
  const facts: [string, string | null][] = [
    [fact('startValue'), gain.inputs.startValue],
    [fact('endValue'), gain.inputs.endValue],
    [fact('netFlows'), gain.inputs.netFlows],
  ];
  if (gain.decomposition.status === 'COMPLETE')
    facts.push(
      [fact('realized'), gain.decomposition.realized],
      [fact('unrealizedChange'), gain.decomposition.unrealizedChange],
      [fact('income'), gain.decomposition.income],
      [fact('fees'), gain.decomposition.fees],
      [fact('taxes'), gain.decomposition.taxes],
      [fact('residual'), gain.decomposition.residual],
    );
  return (
    <div className={styles.content}>
      <ul>
        {(['start', 'end'] as const).map((key) => {
          const anchor = gain.freshness[key];
          return (
            <li key={key}>
              {t('portfolio.performance.anchor.gainLine', {
                label: t(`portfolio.performance.anchor.${key}`),
                details: anchor
                  ? t('portfolio.performance.anchor.gainDetails', {
                      date: formatCalendarNumericDay(anchor.date),
                      count: anchor.ageDays,
                      quality: t(
                        `portfolio.performance.qualityLower.${anchor.priceQuality === 'STALE' ? 'STALE' : anchor.priceQuality === 'CURRENT' ? 'CURRENT' : 'NOT_DETAILED'}`,
                      ),
                    })
                  : t('portfolio.performance.anchor.noValuation'),
              })}
            </li>
          );
        })}
      </ul>
      <dl className={styles.facts}>
        {facts.map(([label, value]) => (
          <div className={styles.fact} key={label}>
            <dt>{label}</dt>
            <dd>
              {value === null
                ? t('states.notCalculable.label')
                : formatAmount(value, assetCode, i18n.language)}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
