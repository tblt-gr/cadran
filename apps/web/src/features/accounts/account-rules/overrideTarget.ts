import type { ProductRuleKind } from '@cadran/api-client';

/**
 * The rule an override is about to be recorded against, and the kinds this
 * account may state at all.
 *
 * The list travels with the choice because it is derived from what the
 * account's authority already answers for: offering every kind the catalogue
 * knows would present choices the API refuses, and deriving it again in the
 * form would mean fetching the rules a second time.
 */
export interface OverrideDraft {
  kind: ProductRuleKind;
  kinds: ProductRuleKind[];
}
