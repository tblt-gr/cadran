import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './TabsNav.module.css';

export interface TabsNavItem {
  id: string;
  label: string;
  /** The page the tab shows: underlined, `aria-current="page"`. */
  active?: boolean;
  /** A reference point such as today's month: marked by a dot, not by colour alone. */
  current?: boolean;
  disabled?: boolean;
  /** Renders a link; without it the tab is a button calling `onSelect`. */
  href?: string;
  onSelect?: () => void;
  title?: string;
}

interface TabsNavProps {
  /** Accessible name of the navigation landmark. */
  label: string;
  items: readonly TabsNavItem[];
  /** `secondary` is the smaller, lighter strip nested under a primary one. */
  variant?: 'primary' | 'secondary';
}

/** Underlined tab strip for sibling views: links for routes, buttons for in-page state. */
export function TabsNav({ items, label, variant = 'primary' }: TabsNavProps) {
  return (
    <nav aria-label={label} className={`${styles.tabs} ${styles[variant]}`}>
      {items.map((item) => {
        const className = styles.tab;
        const data = item.current ? { 'data-current': 'true' } : {};
        if (item.disabled) {
          return (
            <span
              aria-disabled="true"
              className={className}
              key={item.id}
              title={item.title}
              {...data}
            >
              {item.label}
            </span>
          );
        }
        const href = item.href;
        if (href !== undefined) {
          return (
            <a
              aria-current={item.active ? 'page' : undefined}
              className={className}
              href={href}
              key={item.id}
              onClick={(event) => handleClientNavigation(event, href)}
              title={item.title}
              {...data}
            >
              {item.label}
            </a>
          );
        }
        return (
          <button
            aria-current={item.active ? 'page' : undefined}
            className={className}
            key={item.id}
            onClick={item.onSelect}
            title={item.title}
            type="button"
            {...data}
          >
            {item.label}
          </button>
        );
      })}
    </nav>
  );
}
