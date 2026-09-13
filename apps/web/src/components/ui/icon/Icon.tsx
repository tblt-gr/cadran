import styles from './Icon.module.css';

type IconName =
  | 'accounts'
  | 'add'
  | 'alert'
  | 'archive'
  | 'arrow-left'
  | 'arrow-down'
  | 'arrow-up'
  | 'balance'
  | 'budget'
  | 'catalog'
  | 'categories'
  | 'chevron-left'
  | 'chevron-right'
  | 'close'
  | 'copy'
  | 'delete'
  | 'edit'
  | 'goals'
  | 'home'
  | 'investments'
  | 'menu'
  | 'merge'
  | 'more'
  | 'move'
  | 'pie'
  | 'replace'
  | 'rules'
  | 'search'
  | 'settings'
  | 'tax'
  | 'transactions'
  | 'treemap';

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
    case 'catalog':
      return <path d="M5 4h11l3 3v13H5zM8 9h8M8 13h8M8 17h5" />;
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
        <path d="M10.74 6.54 10.79 3.89A8.2 8.2 0 0 1 13.21 3.89L13.26 6.54A5.6 5.6 0 0 1 14.97 7.25L16.88 5.41A8.2 8.2 0 0 1 18.59 7.12L16.75 9.03A5.6 5.6 0 0 1 17.46 10.74L20.11 10.79A8.2 8.2 0 0 1 20.11 13.21L17.46 13.26A5.6 5.6 0 0 1 16.75 14.97L18.59 16.88A8.2 8.2 0 0 1 16.88 18.59L14.97 16.75A5.6 5.6 0 0 1 13.26 17.46L13.21 20.11A8.2 8.2 0 0 1 10.79 20.11L10.74 17.46A5.6 5.6 0 0 1 9.03 16.75L7.12 18.59A8.2 8.2 0 0 1 5.41 16.88L7.25 14.97A5.6 5.6 0 0 1 6.54 13.26L3.89 13.21A8.2 8.2 0 0 1 3.89 10.79L6.54 10.74A5.6 5.6 0 0 1 7.25 9.03L5.41 7.12A8.2 8.2 0 0 1 7.12 5.41L9.03 7.25A5.6 5.6 0 0 1 10.74 6.54ZM12 9.4a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2Z" />
      );
    case 'menu':
      return <path d="M4 7h16M4 12h16M4 17h16" />;
    case 'more':
      return (
        <>
          <circle cx="5" cy="12" r="2" />
          <circle cx="12" cy="12" r="2" />
          <circle cx="19" cy="12" r="2" />
        </>
      );
    case 'search':
      return <path d="m20 20-4.4-4.4m2.4-4.6a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" />;
    case 'close':
      return <path d="m6 6 12 12M18 6 6 18" />;
    case 'copy':
      return <path d="M8 8h11v11H8zM5 16V5h11" />;
    case 'chevron-left':
      return <path d="m15 5-7 7 7 7" />;
    case 'chevron-right':
      return <path d="m9 5 7 7-7 7" />;
    case 'arrow-left':
      return <path d="M19 12H5m6-6-6 6 6 6" />;
    case 'arrow-up':
      return <path d="m7 14 5-5 5 5M12 9v10" />;
    case 'arrow-down':
      return <path d="m7 10 5 5 5-5M12 5v10" />;
    case 'alert':
      return <path d="M12 3 2.8 20h18.4ZM12 9v5m0 3h.01" />;
    case 'delete':
      return <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5m4-5v5" />;
    case 'edit':
      return <path d="M4 20h4L20 8l-4-4L4 16v4Zm10-14 4 4" />;
    case 'archive':
      return <path d="M4 6h16v3H4Zm2 3v11h12V9M9 13h6" />;
    case 'move':
      return <path d="M12 5v10m-4-4 4 4 4-4M5 19h14" />;
    case 'merge':
      return <path d="M8 4v8a4 4 0 0 0 8 0V4M8 8h8" />;
    case 'replace':
      return <path d="M7 8h11l-3-3M17 16H6l3 3" />;
    case 'rules':
      return <path d="M8 7h12M8 12h12M8 17h12M4 7h.01M4 12h.01M4 17h.01" />;
    case 'balance':
      return <path d="M4 19h16M12 5v14M6 9h5L8.5 14 6 9Zm7 0h5L15.5 14 13 9Z" />;
    case 'pie':
      return <path d="M12 4a8 8 0 1 0 8 8h-8Z" />;
    case 'treemap':
      return <path d="M4 4h16v16H4Zm0 7h16M12 11v9" />;
  }
}

export function Icon({ name, size = 20 }: IconProps) {
  if (name === 'more') {
    return (
      <svg
        aria-hidden="true"
        className={styles.icon}
        fill="currentColor"
        height={size}
        viewBox="0 0 24 24"
        width={size}
      >
        <IconPath name={name} />
      </svg>
    );
  }

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
