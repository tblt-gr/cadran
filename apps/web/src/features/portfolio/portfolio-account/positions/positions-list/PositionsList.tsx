import type { PortfolioPosition } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { DatedRowList } from '@/components/ui/dated-list/DatedRowList';
import { PositionRow } from './PositionRow';

interface Props {
  /** Null while loading: the list mirrors its loaded markup with placeholder rows. */
  items: PortfolioPosition[] | null;
  announce?: boolean;
  loadingLabel?: string;
  onOpen: (item: PortfolioPosition) => void;
  onReorder: (item: PortfolioPosition) => void;
}

/** The positions of a portfolio as compact rows on the shared dated list, without day headings. */
export function PositionsList({ announce, items, loadingLabel, onOpen, onReorder }: Props) {
  const { t } = useTranslation();
  return (
    <DatedRowList
      announce={announce}
      getKey={(position) => position.instrument.id}
      items={items}
      label={t('portfolio.account.positions.listLabel')}
      loadingLabel={loadingLabel ?? t('portfolio.account.positions.loading')}
      renderRow={(position) => (
        <PositionRow onOpen={onOpen} onReorder={onReorder} position={position} />
      )}
      skeletonRows={6}
      skeletonTestId="positions-skeleton"
    />
  );
}
