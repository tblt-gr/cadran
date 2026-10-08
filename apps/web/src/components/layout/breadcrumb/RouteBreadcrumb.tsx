import type { MouseEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Breadcrumb } from './Breadcrumb';
import { breadcrumbItems } from './breadcrumbRoutes';
import { useBreadcrumbAccountValue } from './BreadcrumbContext';

export function RouteBreadcrumb({
  path,
  onNavigate,
}: {
  path: string;
  onNavigate: (event: MouseEvent<HTMLAnchorElement>, href: string) => void;
}) {
  const { t } = useTranslation();
  const account = useBreadcrumbAccountValue(path);
  return <Breadcrumb items={breadcrumbItems(path, t, account)} onNavigate={onNavigate} />;
}
