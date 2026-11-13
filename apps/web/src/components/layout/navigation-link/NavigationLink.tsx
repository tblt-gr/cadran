import { useTranslation } from 'react-i18next';
import type { MouseEvent } from 'react';
import type { NavigationItem } from '@/lib/navigation';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './NavigationLink.module.css';

export type NavigationHandler = (event: MouseEvent<HTMLAnchorElement>, href: string) => void;

interface NavigationLinkProps {
  className?: string;
  /** Icon only: the label stays as text for assistive technology. */
  hideLabel?: boolean;
  iconSize?: number;
  item: NavigationItem;
  /** The user shows interest in the target (hover, focus): lets the app warm it up. */
  onIntent?: (href: string) => void;
  onNavigate: NavigationHandler;
  path: string;
}

export function NavigationLink({
  className,
  hideLabel = false,
  iconSize,
  item,
  onIntent,
  onNavigate,
  path,
}: NavigationLinkProps) {
  const { t } = useTranslation();
  const isCurrent = item.match(path);
  const label = t(item.labelKey);

  return (
    <a
      aria-current={isCurrent ? 'page' : undefined}
      className={`${styles.link}${className ? ` ${className}` : ''}`}
      href={item.href}
      onFocus={() => onIntent?.(item.href)}
      onPointerEnter={() => onIntent?.(item.href)}
      onClick={(event) => onNavigate(event, item.href)}
      title={label}
    >
      <Icon name={item.icon} size={iconSize} />
      <span className={hideLabel ? 'sr-only' : undefined}>{label}</span>
    </a>
  );
}
