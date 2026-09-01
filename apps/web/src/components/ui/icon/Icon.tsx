import styles from './Icon.module.css';

type IconName =
  | 'accounts'
  | 'add'
  | 'alert'
  | 'arrow-down'
  | 'arrow-up'
  | 'budget'
  | 'categories'
  | 'chevron-left'
  | 'chevron-right'
  | 'close'
  | 'goals'
  | 'home'
  | 'investments'
  | 'menu'
  | 'more'
  | 'search'
  | 'settings'
  | 'tax'
  | 'transactions';

interface IconProps {
  name: IconName;
  size?: number;
}

function IconPath({ name }: { name: IconName }) {
  switch (name) {
    case 'home':
      return <path d="M3.5 10.5 12 3l8.5 7.5V21h-6v-6h-5v6h-6Z" />;
    case 'transactions':
      return <path d="M4 7h13m-3-3 3 3-3 3M20 17H7m3 3-3-3 3-3" />;
    case 'accounts':
      return <path d="M4 7.5h16v12H4zM3 7.5 12 3l9 4.5M8 11v5m4-5v5m4-5v5" />;
    case 'add':
      return <path d="M12 5v14M5 12h14" />;
    case 'budget':
      return <path d="M5 4h14v16H5zM8 8h8M8 12h3m2 0h3M8 16h3m2 0h3" />;
    case 'categories':
      return <path d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z" />;
    case 'investments':
      return <path d="M4 19V9m6 10V5m6 14v-7m4 7V3M3 19h18" />;
    case 'goals':
      return (
        <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-5a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0-4h.01" />
      );
    case 'tax':
      return <path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h6m-6 4h6" />;
    case 'settings':
      return (
        <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7.5-3.5 1.5 1-2 3.5-1.8-.8a8 8 0 0 1-2.2 1.2l-.2 2.1h-4l-.2-2.1a8 8 0 0 1-2.2-1.2l-1.8.8-2-3.5 1.5-1a8 8 0 0 1 0-2l-1.5-1 2-3.5 1.8.8a8 8 0 0 1 2.2-1.2l.2-2.1h4l.2 2.1a8 8 0 0 1 2.2 1.2l1.8-.8 2 3.5-1.5 1a8 8 0 0 1 0 2Z" />
      );
    case 'menu':
      return <path d="M4 7h16M4 12h16M4 17h16" />;
    case 'more':
      return <path d="M5 12h.01M12 12h.01M19 12h.01" />;
    case 'search':
      return <path d="m20 20-4.4-4.4m2.4-4.6a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" />;
    case 'close':
      return <path d="m6 6 12 12M18 6 6 18" />;
    case 'chevron-left':
      return <path d="m15 5-7 7 7 7" />;
    case 'chevron-right':
      return <path d="m9 5 7 7-7 7" />;
    case 'arrow-up':
      return <path d="m7 14 5-5 5 5M12 9v10" />;
    case 'arrow-down':
      return <path d="m7 10 5 5 5-5M12 5v10" />;
    case 'alert':
      return <path d="M12 3 2.8 20h18.4ZM12 9v5m0 3h.01" />;
  }
}

export function Icon({ name, size = 20 }: IconProps) {
  return (
    <svg
      aria-hidden="true"
      className={styles.icon}
      fill="none"
      height={size}
      viewBox="0 0 24 24"
      width={size}
    >
      <g stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.7">
        <IconPath name={name} />
      </g>
    </svg>
  );
}

export type { IconName };
