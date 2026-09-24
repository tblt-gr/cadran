import { RouteRedirect } from '@/components/layout/route-redirect/RouteRedirect';

export function BudgetRouteRedirect({ href }: { href: string }) {
  return <RouteRedirect statusKey="budget.monthly.loading" to={href} />;
}
