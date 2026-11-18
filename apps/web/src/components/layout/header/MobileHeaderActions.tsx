import {
  Children,
  cloneElement,
  Fragment,
  isValidElement,
  type MouseEvent,
  type ReactElement,
  type ReactNode,
} from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu, type ActionMenuItem } from '@/components/ui/action-menu/ActionMenu';
import { Icon } from '@/components/ui/icon/Icon';
import { navigateFromPage } from '@/hooks/use-client-navigation';
import styles from './MobileHeaderActions.module.css';

/**
 * Up to this many actions stay on the title line. Beyond it, two stay and every other action goes
 * into the "more" menu, which therefore always holds at least two items.
 */
const MAX_INLINE = 3;
const VISIBLE_WITH_MENU = 2;

type ActionElement = ReactElement<{
  'aria-label'?: string;
  'aria-pressed'?: boolean;
  'data-icon-only'?: boolean;
  children?: ReactNode;
  className?: string;
  disabled?: boolean;
  href?: string;
  onClick?: (event: MouseEvent<HTMLElement>) => void;
  title?: string;
}>;

/** Fragments are looked through: the page's actions are their leaves, in reading order. */
function leaves(children: ReactNode): ReactNode[] {
  return Children.toArray(children).flatMap((child) =>
    isValidElement(child) && child.type === Fragment
      ? leaves((child as ReactElement<{ children?: ReactNode }>).props.children)
      : [child],
  );
}

interface Parsed {
  element: ActionElement;
  icon: ReactElement<{ name: never }>;
  text: string;
}

/**
 * A plain button or link made of an icon and a label can be shown as its icon alone, or listed in
 * a menu. Anything else (a toggle component, a custom control) is left exactly as the page wrote it.
 */
function parse(node: ReactNode): Parsed | null {
  if (!isValidElement(node) || (node.type !== 'button' && node.type !== 'a')) return null;
  const element = node as ActionElement;
  const parts = Children.toArray(element.props.children);
  const icon = parts.find(
    (part): part is ReactElement<{ name: never }> => isValidElement(part) && part.type === Icon,
  );
  if (!icon) return null;
  const text =
    parts
      .filter((part): part is string => typeof part === 'string')
      .join('')
      .trim() ||
    element.props['aria-label'] ||
    element.props.title ||
    '';
  return text === '' ? null : { element, icon, text };
}

function toItem(parsed: Parsed, index: number): ActionMenuItem {
  const { element, icon, text } = parsed;
  const props = element.props;
  return {
    checked: props['aria-pressed'],
    disabled: props.disabled,
    icon: icon.props.name,
    id: `page-action-${index}`,
    label: text,
    onSelect: () => {
      if (props.onClick)
        props.onClick({ preventDefault: () => undefined } as MouseEvent<HTMLElement>);
      else if (props.href) navigateFromPage(props.href);
    },
    text,
  };
}

/**
 * The page actions of a phone header: icons only, on the title line, two at most, the rest in a
 * "more" menu. The page keeps writing buttons with an icon and a label; this reads them.
 */
export function MobileHeaderActions({ children }: { children: ReactNode }) {
  const { t } = useTranslation();
  const all = leaves(children);
  const visible = all.length <= MAX_INLINE ? all.length : VISIBLE_WITH_MENU;
  const shown = all.slice(0, visible);
  const rest = all.slice(visible);
  const parsedRest = rest.map(parse);
  const menu = parsedRest.flatMap((parsed, index) => (parsed ? [toItem(parsed, index)] : []));
  // Controls the page wrote as components cannot go in a menu: they stay beside the icons.
  const kept = rest.filter((_, index) => parsedRest[index] === null);

  return (
    <>
      {shown.map((node, index) => {
        const parsed = parse(node);
        if (!parsed) return <Fragment key={index}>{node}</Fragment>;
        const { element, icon, text } = parsed;
        return cloneElement(
          element,
          {
            'aria-label': text,
            className: `${element.props.className ?? ''} ${styles.iconOnly}`,
            'data-icon-only': true,
            key: index,
            title: text,
          },
          icon,
        );
      })}
      {kept.map((node, index) => (
        <Fragment key={`kept-${index}`}>{node}</Fragment>
      ))}
      {menu.length > 0 ? <ActionMenu items={menu} label={t('actions.morePageActions')} /> : null}
    </>
  );
}
