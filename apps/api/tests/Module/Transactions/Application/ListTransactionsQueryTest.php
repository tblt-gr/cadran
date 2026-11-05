<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Transactions\Application\ListTransactionsQuery;
use PHPUnit\Framework\TestCase;

final class ListTransactionsQueryTest extends TestCase
{
    public function testWithReplacesTheNamedFieldsAndKeepsEveryOtherOne(): void
    {
        $query = $this->everyFieldSet();

        $copy = $query->with(['accountIds' => [], 'includeDescendants' => false, 'categorizationNone' => false]);

        self::assertSame([], $copy->accountIds);
        self::assertFalse($copy->includeDescendants);
        self::assertFalse($copy->categorizationNone);
        foreach ($this->properties() as $property) {
            if (!in_array($property, ['accountIds', 'includeDescendants', 'categorizationNone'], true)) {
                self::assertEquals($query->{$property}, $copy->{$property}, $property);
            }
        }
        // The request it was copied from does not change.
        self::assertSame(['00000000-0000-7000-8000-00000000a001'], $query->accountIds);
        self::assertTrue($query->includeDescendants);
    }

    public function testTheSampleSetsEveryFieldSoAFieldAddedLaterIsCarriedByWithoutAnyoneRemembering(): void
    {
        $query = $this->everyFieldSet();
        $constructor = (new \ReflectionClass(ListTransactionsQuery::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            self::assertNotEquals(
                $parameter->getDefaultValue(),
                $query->{$parameter->getName()},
                sprintf('Set "%s" in everyFieldSet() so that the copy test covers it.', $parameter->getName()),
            );
        }
        // A copy that changes nothing is the same request, field for field.
        self::assertEquals($query, $query->with([]));
    }

    public function testAFieldThatDoesNotExistFailsLoudlyInsteadOfBeingIgnored(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/accountId/');

        $this->everyFieldSet()->with(['accountId' => []]);
    }

    /** @return list<string> */
    private function properties(): array
    {
        $constructor = (new \ReflectionClass(ListTransactionsQuery::class))->getConstructor();
        self::assertNotNull($constructor);

        return array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters());
    }

    private function everyFieldSet(): ListTransactionsQuery
    {
        return new ListTransactionsQuery(
            from: '2026-01-01',
            to: '2026-01-31',
            accountIds: ['00000000-0000-7000-8000-00000000a001'],
            states: ['BOOKED'],
            natures: ['EXPENSE'],
            categoryIds: ['00000000-0000-7000-8000-00000000c001'],
            includeDescendants: true,
            axes: ['ESSENTIAL'],
            minAmount: '10',
            maxAmount: '50',
            assetCode: 'EUR',
            sources: ['IMPORT'],
            categorizationNone: true,
            q: 'cafe',
            includeVoided: true,
            direction: 'OUTFLOW',
            pageSize: 20,
            cursor: 'abc',
        );
    }
}
