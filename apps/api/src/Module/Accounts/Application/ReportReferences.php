<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** Accounts and groups of one workspace, archived or not, as reports may reference them by id. */
final readonly class ReportReferences
{
    /**
     * @param list<ReportAccountReference> $accounts     ordered by label
     * @param array<string, string>        $groups       label by group id, depth-first saved order
     * @param array<string, ?string>       $groupParents label of the immediate parent by group id, null for a root
     */
    public function __construct(public array $accounts, public array $groups, public array $groupParents = [])
    {
    }
}
