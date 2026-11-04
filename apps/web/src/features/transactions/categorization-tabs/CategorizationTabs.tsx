import { useTranslation } from 'react-i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';

export type Categorization = 'ALL' | 'NONE';

interface CategorizationTabsProps {
  /** Renders the tabs as inert text while the list they drive is still loading. */
  disabled?: boolean;
  onChange: (value: Categorization) => void;
  value: Categorization;
}

/**
 * Switches the transactions list between every movement and the "to categorise" queue, on the
 * shared tab strip: the selected tab is underlined and marked `aria-current`, never by colour
 * alone.
 */
export function CategorizationTabs({ disabled = false, onChange, value }: CategorizationTabsProps) {
  const { t } = useTranslation();

  return (
    <TabsNav
      items={(['ALL', 'NONE'] as const).map((candidate) => ({
        active: value === candidate,
        disabled,
        id: candidate,
        label: t(
          candidate === 'ALL'
            ? 'transactions.filters.categorizationAll'
            : 'transactions.filters.categorizationQueue',
        ),
        onSelect: () => onChange(candidate),
      }))}
      label={t('transactions.filters.categorizationLabel')}
    />
  );
}
