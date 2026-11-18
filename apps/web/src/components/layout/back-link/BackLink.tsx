import type { MouseEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { useMediaQuery } from '@/hooks/use-media-query';
import styles from './BackLink.module.css';

/** Phone only: the breadcrumb is hidden there, so a detail page goes up one level from its title. */
export function BackLink({
  label,
  href,
  onNavigate,
}: {
  label: string;
  href: string;
  onNavigate: (event: MouseEvent<HTMLAnchorElement>, href: string) => void;
}) {
  const { t } = useTranslation();
  const phone = useMediaQuery('(max-width: 767px)');
  if (!phone) return null;
  return (
    <a
      aria-label={t('breadcrumb.back', { label })}
      className={styles.back}
      href={href}
      onClick={(event) => onNavigate(event, href)}
    >
      <Icon name="arrow-left" size={20} />
    </a>
  );
}
