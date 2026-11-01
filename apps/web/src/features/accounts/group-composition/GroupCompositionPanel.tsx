import type { AccountGroupComposition } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { accountEntries, subgroupEntries, type CompositionView } from './compositionEntries';
import { GroupCompositionChart } from './GroupCompositionChart';
import { GroupCompositionLegend } from './GroupCompositionLegend';
import styles from './GroupComposition.module.css';

interface GroupCompositionPanelProps {
  /** Null while the composition is being read. */
  composition: AccountGroupComposition | null;
  view: CompositionView;
}

/** One view of the split: the pie (or why there is none) above its table. */
export function GroupCompositionPanel({ composition, view }: GroupCompositionPanelProps) {
  const { t } = useTranslation();
  const entries =
    composition === null
      ? null
      : view === 'subgroup'
        ? subgroupEntries(composition, t('accounts.composition.direct'))
        : accountEntries(composition);

  return (
    <div className={styles.panel}>
      <GroupCompositionChart
        reasonText={
          composition?.chartReason
            ? t(`accounts.composition.chartReasons.${composition.chartReason}`)
            : null
        }
        entries={entries}
        label={t('accounts.composition.chart', { label: composition?.label ?? '' })}
        total={composition?.total ?? null}
      />
      <GroupCompositionLegend composition={composition} entries={entries} view={view} />
    </div>
  );
}
