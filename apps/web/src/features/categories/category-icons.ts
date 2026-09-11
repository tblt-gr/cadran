/**
 * Local, finite artwork only. Persisted keys never become URLs, markup or SVG path data.
 *
 * The catalogue is flat and carries no category meaning: the owner freely
 * associates any glyph with any category, so entries are named after what they
 * draw, never after what they could classify. Keys are stable and never
 * removed — a renamed French name keeps its key so stored categories keep
 * rendering.
 */
export const CATEGORY_ICONS = [
  { key: 'utensils', path: 'M4 3v6m3-6v6M2 3v6a3 3 0 0 0 6 0V3M5 12v9M18 21V3c-5 4-5 10 0 10' },
  { key: 'home', path: 'M3 10 12 3l9 7v11h-6v-7H9v7H3Z' },
  { key: 'shopping-cart', path: 'M2 3h3l3 12h11l3-9H6M9 20h.01M18 20h.01' },
  { key: 'car', path: 'm4 9 2-6h12l2 6M3 9h18v9H3ZM5 18v3m14-3v3M6 13h2m8 0h2' },
  { key: 'heart', path: 'M12 21 3 12C-3 4 7-1 12 6c5-7 15-2 9 6Z' },
  { key: 'graduation-cap', path: 'm2 8 10-5 10 5-10 5ZM6 10v7c4 4 8 4 12 0v-7m4-2v9' },
  { key: 'gift', path: 'M3 8h18v4H3Zm2 4v9h14v-9M12 8v13m0-13C0 8 7-3 12 8c5-11 12 0 0 0' },
  { key: 'briefcase', path: 'M3 7h18v14H3ZM8 7V3h8v4M3 12c6 5 12 5 18 0m-9 0v5' },
  { key: 'plane', path: 'm2 16 8-5V4c0-4 4-4 4 0v7l8 5v3l-8-3v4l3 2H7l3-2v-4l-8 3Z' },
  {
    key: 'music',
    path: 'M9 18V5l12-3v14M9 8l12-3M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm12-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
  },
  { key: 'coffee', path: 'M4 8h13v7c0 7-13 7-13 0ZM17 8h2c5 0 5 6 0 6h-2M6 2v3m5-3v3M2 22h18' },
  { key: 'wallet', path: 'M3 6h18v15H3V6Zm0 0V3h15v3m3 6h-6v5h6m-3-3h.01' },

  { key: 'basket', path: 'M4 9h16l-1.6 10.2H5.6ZM8.6 9 12 3.6 15.4 9M10 12.4v3.8M14 12.4v3.8' },
  {
    key: 'beer',
    path: 'M6 6h9v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2ZM15 9h2.5a1.5 1.5 0 0 1 1.5 1.5v4a1.5 1.5 0 0 1-1.5 1.5H15M6 10h9',
  },
  {
    key: 'bread',
    path: 'M4 13a5 5 0 0 1 5-5h6a5 5 0 0 1 5 5v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2ZM9 8v12M14 8v12',
  },
  {
    key: 'cake',
    path: 'M4 20h16M5 20v-6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6M9 12V8m3 4V7m3 5V8M9 5.5h.01M12 4.5h.01M15 5.5h.01',
  },
  {
    key: 'key',
    path: 'M20.5 3.5 11 13M17.5 6.5 20 9M14.5 9.5 17 12M8.6 14.4a3.6 3.6 0 1 0-5.1 5.1 3.6 3.6 0 0 0 5.1-5.1Z',
  },
  {
    key: 'bulb',
    path: 'M9 17h6M10 20h4M8 11a4 4 0 1 1 8 0c0 1.8-1 2.6-1.4 3.4-.3.6-.3 1.2-.3 1.6H9.7c0-.4 0-1-.3-1.6C9 13.6 8 12.8 8 11Z',
  },
  {
    key: 'droplet',
    path: 'M12 3.5c3.2 3.6 5.5 6.4 5.5 9.4a5.5 5.5 0 0 1-11 0c0-3 2.3-5.8 5.5-9.4Z',
  },
  {
    key: 'flame',
    path: 'M12 3.2c.6 3 2.2 4 3.8 5.8 1.3 1.5 2 3 2 4.8A5.8 5.8 0 0 1 6.2 13.8c0-1.4.4-2.4 1.2-3.4.3 1.1.9 1.8 1.8 2.1-.4-3.6 1-6.4 2.8-9.3Z',
  },
  {
    key: 'wifi',
    path: 'M3.5 9.5a12 12 0 0 1 17 0M6.6 13a7.6 7.6 0 0 1 10.8 0M9.8 16.4a3.1 3.1 0 0 1 4.4 0M12 20h.01',
  },
  {
    key: 'sofa',
    path: 'M5.5 11.5v-3a2 2 0 0 1 4 0v3M14.5 11.5v-3a2 2 0 0 1 4 0v3M3.5 11.5h17V17a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 17ZM6 18.5V20M18 18.5V20',
  },
  { key: 'plug', path: 'M9 3v5M15 3v5M6.5 8h11v2.5a5.5 5.5 0 0 1-11 0ZM12 16v5' },
  {
    key: 'hammer',
    path: 'M14 4.5 19.5 10 17 12.5 11.5 7ZM12.8 8.3l-8 8a2.4 2.4 0 0 0 3.4 3.4l8-8',
  },
  { key: 'leaf', path: 'M4.5 19.5C4.5 11 10 5.5 19.5 4.5c1 9.5-4.5 15-13 15ZM8 16 18 6' },
  {
    key: 'fuel',
    path: 'M5 20.5V5.5a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v15M3.5 20.5h12M7.5 7h4v3.5h-4ZM14 9h2.6a1.9 1.9 0 0 1 1.9 1.9v5.6a1.5 1.5 0 0 0 3 0v-4.6L19 8',
  },
  {
    key: 'bus',
    path: 'M5 5.5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2V17H5ZM5 11h14M7.5 14.2h.01M16.5 14.2h.01M8 17v2.5M16 17v2.5',
  },
  {
    key: 'train',
    path: 'M6.5 3.5h11a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-11a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2ZM4.5 9.5h15M8 13h.01M16 13h.01M8.5 16.5 6 21M15.5 16.5 18 21M4 21h16',
  },
  {
    key: 'bike',
    path: 'M6 19.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM18 19.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM6 16l3.5-7h5l3.5 7M9.5 9H15M12 16 9.5 9',
  },
  {
    key: 'truck',
    path: 'M3.5 7.5h9v9h-9ZM12.5 11h3.6l3.4 3.4v2.1h-7ZM7 16.5a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6M17 16.5a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6',
  },
  {
    key: 'pill',
    path: 'M13.8 4.4a4.8 4.8 0 0 1 6.8 6.8l-9.4 9.4a4.8 4.8 0 0 1-6.8-6.8ZM9.2 9l6.8 6.8',
  },
  { key: 'medical-cross', path: 'M9.5 3.5h5V9h5.5v5H14.5v5.5h-5V14H4V9h5.5Z' },
  {
    key: 'tooth',
    path: 'M7.5 3.5C5.3 3.5 4 5.3 4 7.6c0 3 1.3 4.4 1.8 7.3.4 2.3.6 5.6 2.2 5.6 1.4 0 1.6-2.6 2-4.6.2-1.1.8-1.9 2-1.9s1.8.8 2 1.9c.4 2 .6 4.6 2 4.6 1.6 0 1.8-3.3 2.2-5.6.5-2.9 1.8-4.3 1.8-7.3 0-2.3-1.3-4.1-3.5-4.1-1.6 0-2.6 1-4.5 1s-2.9-1-4.5-1Z',
  },
  {
    key: 'glasses',
    path: 'M3 11.5 5.5 6h3l-1 5.5M21 11.5 18.5 6h-3l1 5.5M3 13.5a3 3 0 1 0 6 0 3 3 0 0 0-6 0ZM15 13.5a3 3 0 1 0 6 0 3 3 0 0 0-6 0ZM9 13.5h6',
  },
  { key: 'dumbbell', path: 'M3 9.5v5M6 7v10M18 7v10M21 9.5v5M6 12h12' },
  {
    key: 'syringe',
    path: 'M14.5 3.5 20.5 9.5M18.5 5.5 21.5 2.5M16 8 6.5 17.5V21H10L19.5 11.5ZM10 14l2.5 2.5M12.5 11.5 15 14M3 21.5 6.5 18',
  },
  {
    key: 'users',
    path: 'M8.5 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM2.5 20.5c0-3.3 2.7-6 6-6s6 2.7 6 6M16 5.2a3.5 3.5 0 0 1 0 6.6M17.5 14.8c2.4.7 4 2.9 4 5.7',
  },
  {
    key: 'child',
    path: 'M12 7.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM12 7.5v6M8 10.5h8M9.5 21 12 13.5 14.5 21',
  },
  {
    key: 'paw',
    path: 'M7 12.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM17 12.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM10 8a1.8 1.8 0 1 0 0-3.6A1.8 1.8 0 0 0 10 8ZM14 8a1.8 1.8 0 1 0 0-3.6A1.8 1.8 0 0 0 14 8ZM12 20.5c-2.8 0-4.5-1.6-4.5-3.6 0-1.9 2-3.4 4.5-3.4s4.5 1.5 4.5 3.4c0 2-1.7 3.6-4.5 3.6Z',
  },
  {
    key: 'book',
    path: 'M12 7.5a3 3 0 0 0-3-3H4v12h5a3 3 0 0 1 3 3M12 7.5a3 3 0 0 1 3-3h5v12h-5a3 3 0 0 0-3 3M12 7.5v12',
  },
  { key: 'pencil', path: 'M4 20h4L20 8l-4-4L4 16ZM15 5l4 4M4 16l4 4' },
  {
    key: 'ticket',
    path: 'M3.5 9.5V8A1.5 1.5 0 0 1 5 6.5h14A1.5 1.5 0 0 1 20.5 8v1.5a2.5 2.5 0 0 0 0 5V16a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 16v-1.5a2.5 2.5 0 0 0 0-5ZM9.5 6.5v11',
  },
  { key: 'film', path: 'M3.5 4.5h17v15h-17ZM3.5 9.5h17M3.5 14.5h17M8 4.5v15M16 4.5v15' },
  {
    key: 'gamepad',
    path: 'M8.5 9.5H7A3.5 3.5 0 0 0 3.5 13v3.2a1.9 1.9 0 0 0 3.3 1.3l1.8-2h6.8l1.8 2a1.9 1.9 0 0 0 3.3-1.3V13a3.5 3.5 0 0 0-3.5-3.5h-1.5M7.5 12.5H10M8.75 11.25v2.5M15 12h.01M17 14h.01',
  },
  {
    key: 'camera',
    path: 'M3.5 9A1.5 1.5 0 0 1 5 7.5h2L8.5 5h7L17 7.5h2A1.5 1.5 0 0 1 20.5 9v9.5a1.5 1.5 0 0 1-1.5 1.5H5a1.5 1.5 0 0 1-1.5-1.5ZM12 17.5a3.8 3.8 0 1 0 0-7.6 3.8 3.8 0 0 0 0 7.6Z',
  },
  {
    key: 'headphones',
    path: 'M4 15v-3a8 8 0 0 1 16 0v3M4 14h2.5A1.5 1.5 0 0 1 8 15.5v3A1.5 1.5 0 0 1 6.5 20h-1A1.5 1.5 0 0 1 4 18.5ZM20 14h-2.5a1.5 1.5 0 0 0-1.5 1.5v3a1.5 1.5 0 0 0 1.5 1.5h1a1.5 1.5 0 0 0 1.5-1.5Z',
  },
  {
    key: 'suitcase',
    path: 'M8.5 6.5v-2A1.5 1.5 0 0 1 10 3h4a1.5 1.5 0 0 1 1.5 1.5v2M3.5 8A1.5 1.5 0 0 1 5 6.5h14A1.5 1.5 0 0 1 20.5 8v10.5a1.5 1.5 0 0 1-1.5 1.5H5a1.5 1.5 0 0 1-1.5-1.5ZM9 10.5v6M15 10.5v6',
  },
  { key: 'bed', path: 'M3.5 19.5V6.5M3.5 12.5h11a6 6 0 0 1 6 6v1M3.5 16.5h17' },
  { key: 'shirt', path: 'M8.5 3.5 12 6l3.5-2.5 4.5 2.5-2 4.5-2-1v11h-8V9.5l-2 1L4 6Z' },
  { key: 'bag', path: 'M6 8.5h12l1.3 11.5H4.7ZM9 8.5v-2a3 3 0 0 1 6 0v2' },
  {
    key: 'scissors',
    path: 'M7 9a2.8 2.8 0 1 0 0-5.6A2.8 2.8 0 0 0 7 9ZM7 20.6a2.8 2.8 0 1 0 0-5.6 2.8 2.8 0 0 0 0 5.6ZM9.3 7.6 20 19M9.3 16.4 20 5',
  },
  { key: 'laptop', path: 'M5 5.5h14v10H5ZM2.5 18.5h19l-1.5-3h-16Z' },
  {
    key: 'smartphone',
    path: 'M7.5 2.5h9A1.5 1.5 0 0 1 18 4v16a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V4a1.5 1.5 0 0 1 1.5-1.5ZM10.5 18.5h3',
  },
  { key: 'tv', path: 'M4 7.5h16v10H4ZM8.5 21h7M9 4l3 3.5L15 4' },
  {
    key: 'coins',
    path: 'M8.5 11.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM15.5 20.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
  },
  {
    key: 'banknote',
    path: 'M2.5 7.5h19v9h-19ZM12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM6 12h.01M18 12h.01',
  },
  { key: 'credit-card', path: 'M3 6.5h18v11H3ZM3 10.5h18M6.5 14h3' },
  {
    key: 'safe',
    path: 'M4 4.5h16v15H4ZM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM12 10.5v3M7 19.5V21M17 19.5V21',
  },
  {
    key: 'bank',
    path: 'M3 9.5 12 4l9 5.5M5 9.5V18M9.5 9.5V18M14.5 9.5V18M19 9.5V18M3 18h18M3 20.5h18',
  },
  { key: 'receipt', path: 'M6 3.5h12v17l-3-1.8-3 1.8-3-1.8-3 1.8ZM9 8h6M9 12h6' },
  { key: 'chart-up', path: 'M3.5 17.5 9.5 11l3.5 3.5 7-7.5M16 7h4.5v4.5M3.5 20.5h17' },
  { key: 'chart-pie', path: 'M12 3.5a8.5 8.5 0 1 0 8.5 8.5H12Z' },
  {
    key: 'building',
    path: 'M5 20.5V4.5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v16M15 9.5h3a1 1 0 0 1 1 1v10M8 7.5h1.5M11 7.5h1.5M8 11h1.5M11 11h1.5M8 14.5h1.5M11 14.5h1.5M3.5 20.5h17',
  },
  { key: 'shield', path: 'M12 3.5 20 6v6c0 4.5-3.4 7.5-8 9-4.6-1.5-8-4.5-8-9V6Z' },
  { key: 'umbrella', path: 'M12 3.5a9 9 0 0 1 9 9H3a9 9 0 0 1 9-9ZM12 12.5V18a2.5 2.5 0 0 0 5 0' },
  {
    key: 'globe',
    path: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM3.5 9.5h17M3.5 14.5h17M12 3a13 13 0 0 1 0 18M12 3a13 13 0 0 0 0 18',
  },
  { key: 'calendar', path: 'M5 6h14v14H5ZM5 10.5h14M8.5 3.5V7M15.5 3.5V7' },
  { key: 'clock', path: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 7.5V12l3.5 2.5' },
  {
    key: 'repeat',
    path: 'M4 10V8.5A3.5 3.5 0 0 1 7.5 5H18M18 5l-3-3M18 5l-3 3M20 14v1.5a3.5 3.5 0 0 1-3.5 3.5H6M6 19l3-3M6 19l3 3',
  },
  { key: 'tag', path: 'M11.5 3.5H20V12l-9 9-8.5-8.5ZM15.5 8h.01' },
  {
    key: 'star',
    path: 'm12 3.5 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.8l-5.6 2.9 1.1-6.2L3 10.1l6.2-.9Z',
  },
] as const;

export type CategoryIconKey = (typeof CATEGORY_ICONS)[number]['key'];

export function categoryIcon(key: string | null | undefined) {
  return CATEGORY_ICONS.find((icon) => icon.key === key);
}
