import { useTranslation } from 'react-i18next';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import type { YearView } from './yearViewSearch';

interface ReportViewSwitchProps {
  /** The multi-year view needs at least two years of data. */
  multiYearAvailable: boolean;
  onViewChange: (view: YearView) => void;
  view: YearView;
}

/** The annual or multi-year switch of the report page. */
export function ReportViewSwitch({
  multiYearAvailable,
  onViewChange,
  view,
}: ReportViewSwitchProps) {
  const { t } = useTranslation();

  return (
    <SegmentedControl
      label={t('budget.year.view.label')}
      onChange={onViewChange}
      options={[
        { value: 'annual', label: t('budget.year.view.annual') },
        {
          value: 'multi',
          label: t('budget.year.view.multi'),
          disabled: !multiYearAvailable && view !== 'multi',
          description: multiYearAvailable ? undefined : t('budget.year.view.multiNeedsHistory'),
        },
      ]}
      value={view}
    />
  );
}
