<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\RuleKind;

/**
 * The precedence the three authorities are read in, stated once.
 *
 * A local claim wins over what the account follows, which wins over what the
 * system catalogue publishes behind it. The order lives here rather than in
 * each of the three layered rules so that a fourth authority — or a change of
 * mind about who wins — cannot be applied to ceilings and forgotten on rates.
 */
final class AccountRuleLayers
{
    public static function effectiveLayer(
        RuleKind $kind,
        bool $hasCatalog,
        bool $hasInherited,
        bool $hasOverride,
    ): AccountRuleLayer {
        return match (true) {
            $hasOverride => AccountRuleLayer::OVERRIDE,
            $hasInherited => AccountRuleLayer::INHERITED,
            $hasCatalog => AccountRuleLayer::CATALOG,
            default => throw new InvalidAccount(sprintf('A %s rule with no layer at all is not a rule.', $kind->value)),
        };
    }
}
