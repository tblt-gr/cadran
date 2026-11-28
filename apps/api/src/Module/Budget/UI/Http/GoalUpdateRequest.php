<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\UpdateGoalInput;
use App\Module\Foundation\UI\Http\JsonPayload;

final class GoalUpdateRequest
{
    private const array REQUIRED = ['label', 'version'];
    private const array OPTIONAL = ['valueType', 'targetAmount', 'ratio', 'periodicity', 'startPeriod', 'metric', 'scope', 'schedule', 'effectiveFrom', 'reason', 'tolerance'];
    /** Submitting one of these asks to change what the goal measures, which the use case refuses. */
    private const array DEFINITION_FIELDS = ['metric', 'scope', 'periodicity', 'startPeriod'];

    /** @param array<mixed> $body */
    public static function from(string $id, array $body): UpdateGoalInput
    {
        $payload = JsonPayload::of($body, self::REQUIRED, self::OPTIONAL);
        $target = $payload->nullableAmount('targetAmount');

        return new UpdateGoalInput(
            $id,
            $payload->integer('version'),
            $payload->string('label'),
            $target['value'] ?? null,
            $target['assetCode'] ?? null,
            $payload->nullableString('ratio'),
            [] !== array_intersect(array_keys($body), self::DEFINITION_FIELDS),
            GoalScheduleBody::read($payload),
            $payload->nullableString('effectiveFrom'),
            $payload->nullableString('reason'),
            $payload->nullableString('valueType'),
            $payload->nullableString('tolerance'),
        );
    }
}
