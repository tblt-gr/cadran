import type { MouseEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { breadcrumbItems, detailBackTarget } from '@/components/layout/breadcrumb/breadcrumbRoutes';
import { BackLink } from './BackLink';

export function RouteBackLink({
  path,
  onNavigate,
}: {
  path: string;
  onNavigate: (event: MouseEvent<HTMLAnchorElement>, href: string) => void;
}) {
  const { i18n, t } = useTranslation();
  // Parents of detail pages are static list or year items: no request label is needed.
  const parent = detailBackTarget(breadcrumbItems(path, t, null, i18n.language), path);
  return parent?.href ? (
    <BackLink href={parent.href} label={parent.label} onNavigate={onNavigate} />
  ) : null;
}
