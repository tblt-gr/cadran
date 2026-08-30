import { useTranslation } from 'react-i18next';
import type { MouseEvent } from 'react';
import type { NavigationItem } from '../../../lib/navigation';
import { Icon } from '../../ui/icon/Icon';
import styles from './NavigationLink.module.css';

export type NavigationHandler = (event: MouseEvent<HTMLAnchorElement>, href: string) => void;

interface NavigationLinkProps {
  className?: string;
  item: NavigationItem;
  onNavigate: NavigationHandler;
  path: string;
}

export function NavigationLink({ className, item, onNavigate, path }: NavigationLinkProps) {
  const { t } = useTranslation();
  const isCurrent = item.match(path);
  const label = t(item.labelKey);

  return (
    <a
      aria-current={isCurrent ? 'page' : undefined}
      className={`${styles.link}${className ? ` ${className}` : ''}`}
      href={item.href}
      onClick={(event) => onNavigate(event, item.href)}
      title={label}
    >
      <Icon name={item.icon} />
      <span>{label}</span>
    </a>
  );
}
