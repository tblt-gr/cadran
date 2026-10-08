import type { MouseEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import type { BreadcrumbItem } from './breadcrumbRoutes';
import styles from './Breadcrumb.module.css';

export function Breadcrumb({
  items,
  onNavigate,
}: {
  items: BreadcrumbItem[];
  onNavigate: (event: MouseEvent<HTMLAnchorElement>, href: string) => void;
}) {
  const { t } = useTranslation();
  return (
    <nav aria-label={t('breadcrumb.label')} className={styles.breadcrumb}>
      <ol className={styles.list}>
        {items.map((item, index) => (
          <li className={styles.item} key={item.href ?? `current-${index}`}>
            {index > 0 ? (
              <span aria-hidden="true" className={styles.separator}>
                /
              </span>
            ) : null}
            {item.href ? (
              <a
                aria-busy={item.pending || undefined}
                aria-label={item.pending ? item.label : undefined}
                href={item.href}
                onClick={(event) => onNavigate(event, item.href!)}
              >
                {item.pending ? (
                  <SkeletonBlock className={styles.accountPlaceholder} />
                ) : (
                  item.label
                )}
              </a>
            ) : item.pending ? (
              <>
                <span aria-busy="true" aria-current="page" className="sr-only">
                  {item.label}
                </span>
                <SkeletonBlock className={styles.accountPlaceholder} />
              </>
            ) : (
              <span
                aria-busy={item.pending || undefined}
                aria-current="page"
                aria-label={item.pending ? item.label : undefined}
              >
                {item.label}
              </span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
