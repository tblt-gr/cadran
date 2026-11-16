import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { CategoryColumnOptions } from './category-column-options/CategoryColumnOptions';
import { ColumnOptions } from './ColumnOptions';
import type { CatalogueEntry, useColumnCatalogue } from './useColumnCatalogue';
import styles from './ColumnPickerModal.module.css';

export interface CatalogueData {
  accounts: CatalogueEntry[];
  categories: CatalogueEntry[];
  groups: CatalogueEntry[];
}

interface CatalogueColumnOptionsProps {
  catalogue: ReturnType<typeof useColumnCatalogue>;
  full: boolean;
  pick: (data: CatalogueData) => CatalogueEntry[];
  prefix: string;
  selected: string[];
  tree: boolean;
  onToggle: (id: string) => void;
}

/** The workspace's own entries: placeholders while they load, a retry when they failed. */
export function CatalogueColumnOptions({
  catalogue,
  full,
  pick,
  prefix,
  selected,
  tree,
  onToggle,
}: CatalogueColumnOptionsProps) {
  const { t } = useTranslation();

  if (catalogue.isPending && tree) {
    return (
      <div aria-busy="true">
        <SkeletonStatus>{t('reports.annual.picker.loading')}</SkeletonStatus>
        <CategoryColumnOptions entries={null} full={full} onToggle={onToggle} selected={selected} />
      </div>
    );
  }
  if (catalogue.isPending) {
    return (
      <div aria-busy="true">
        <SkeletonStatus>{t('reports.annual.picker.loading')}</SkeletonStatus>
        <div aria-hidden="true" className={styles.options}>
          {Array.from({ length: 4 }, (_, index) => (
            <SkeletonBlock className={styles.skeletonOption} key={index} />
          ))}
        </div>
      </div>
    );
  }
  if (catalogue.isError) {
    return (
      <div role="alert">
        <p>{t('reports.annual.picker.catalogueError')}</p>
        <button className="secondary-action" onClick={() => void catalogue.refetch()} type="button">
          {t('reports.retry')}
        </button>
      </div>
    );
  }
  if (tree) {
    return (
      <CategoryColumnOptions
        entries={pick(catalogue.data)}
        full={full}
        onToggle={onToggle}
        selected={selected}
      />
    );
  }
  return (
    <ColumnOptions
      entries={pick(catalogue.data)}
      full={full}
      onToggle={onToggle}
      prefix={prefix}
      selected={selected}
    />
  );
}
