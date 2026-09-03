/**
 * The viewer's own calendar day, as `YYYY-MM-DD`. The `en-CA` locale renders
 * exactly that shape, which avoids building the string from UTC parts and
 * showing yesterday to anyone west of Greenwich.
 */
export function todayInBrowser(): string {
  return new Date().toLocaleDateString('en-CA');
}
