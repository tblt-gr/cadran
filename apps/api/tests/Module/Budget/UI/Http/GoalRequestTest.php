<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\UI\Http\GoalCreateRequest;
use App\Module\Budget\UI\Http\GoalUpdateRequest;
use App\Module\Foundation\UI\Http\MalformedPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Creation and update share one reader, so they refuse the same bodies the same way. */
final class GoalRequestTest extends TestCase
{
    private const array CREATE = [
        'label' => 'Vacances', 'periodicity' => 'MONTHLY', 'metric' => 'SAVINGS', 'scope' => ['type' => 'WORKSPACE', 'ref' => null],
        'startPeriod' => '2026-01', 'valueType' => 'AMOUNT',
    ];
    private const array UPDATE = ['label' => 'Vacances', 'version' => 1];

    public function testBothReadersAcceptTheirMinimalBodyAndACustomSchedule(): void
    {
        $schedule = ['schedule' => ['mode' => 'CUSTOM', 'parts' => array_fill(0, 24, '1.00')]];

        self::assertCount(24, GoalCreateRequest::from([...self::CREATE, ...$schedule])->schedule->parts ?? []);
        self::assertCount(24, GoalUpdateRequest::from('id', [...self::UPDATE, ...$schedule])->schedule->parts ?? []);
        self::assertNull(GoalCreateRequest::from(self::CREATE)->schedule);
        self::assertNull(GoalUpdateRequest::from('id', self::UPDATE)->schedule);
    }

    /**
     * @param array<string, mixed> $create
     * @param array<string, mixed> $update
     */
    #[DataProvider('malformedBodies')]
    public function testAnUnknownOrMissingFieldIsMalformedForBoth(array $create, array $update): void
    {
        foreach ([static fn () => GoalCreateRequest::from($create), static fn () => GoalUpdateRequest::from('id', $update)] as $read) {
            try {
                $read();
                self::fail('The body must be refused.');
            } catch (MalformedPayload) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function malformedBodies(): iterable
    {
        yield 'unknown field' => [[...self::CREATE, 'extra' => 1], [...self::UPDATE, 'extra' => 1]];
        yield 'missing field' => [array_diff_key(self::CREATE, ['label' => 0]), array_diff_key(self::UPDATE, ['label' => 0])];
        yield 'amount as a number' => [
            [...self::CREATE, 'targetAmount' => ['value' => 10, 'assetCode' => 'EUR']],
            [...self::UPDATE, 'targetAmount' => ['value' => 10, 'assetCode' => 'EUR']],
        ];
    }

    /** @param array<string, mixed> $schedule */
    #[DataProvider('malformedSchedules')]
    public function testAMalformedScheduleCarriesTheSameCodeForBoth(array $schedule): void
    {
        foreach ([
            static fn () => GoalCreateRequest::from([...self::CREATE, 'schedule' => $schedule]),
            static fn () => GoalUpdateRequest::from('id', [...self::UPDATE, 'schedule' => $schedule]),
        ] as $read) {
            try {
                $read();
                self::fail('The schedule must be refused.');
            } catch (GoalInputError $error) {
                self::assertSame('invalid_goal_schedule', $error->errorCode);
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedSchedules(): iterable
    {
        yield 'more than 24 parts' => [['mode' => 'CUSTOM', 'parts' => array_fill(0, 25, '1.00')]];
        yield 'a part as a number' => [['mode' => 'CUSTOM', 'parts' => [1]]];
        yield 'an unknown member' => [['mode' => 'EVEN', 'extra' => true]];
        yield 'a missing mode' => [['parts' => []]];
    }
}
