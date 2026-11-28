<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\CreateGoalInput;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;

final class GoalCreateRequest
{
    private const array REQUIRED = ['label', 'periodicity', 'metric', 'scope', 'startPeriod', 'valueType'];
    private const array OPTIONAL = ['endPeriod', 'targetAmount', 'ratio', 'schedule', 'tolerance', 'direction'];

    /** @param array<mixed> $body */
    public static function from(array $body): CreateGoalInput
    {
        $payload = JsonPayload::of($body, self::REQUIRED, self::OPTIONAL);
        $scope = $payload->nullableObject('scope', ['type', 'ref'])
            ?? throw new MalformedPayload('scope must be an object.');
        $target = $payload->nullableAmount('targetAmount');

        return new CreateGoalInput(
            $payload->string('label'),
            $payload->string('periodicity'),
            $payload->string('metric'),
            $scope->string('type'),
            $scope->nullableString('ref'),
            $payload->string('startPeriod'),
            $payload->nullableString('endPeriod'),
            $payload->string('valueType'),
            $target['value'] ?? null,
            $target['assetCode'] ?? null,
            $payload->nullableString('ratio'),
            GoalScheduleBody::read($payload),
            $payload->nullableString('tolerance'),
            $payload->nullableString('direction'),
        );
    }
}
