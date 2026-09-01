<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkspaceScopeTest extends TestCase
{
    private const string WORKSPACE_ID = '00000000-0000-7000-8000-0000000000a1';

    public function testItCarriesTheWorkspaceItWasBuiltFrom(): void
    {
        $scope = WorkspaceScope::fromString(self::WORKSPACE_ID);

        self::assertSame(self::WORKSPACE_ID, $scope->id);
        self::assertSame(self::WORKSPACE_ID, (string) $scope);
    }

    public function testTheSameWorkspaceInAnotherCaseIsTheSameScope(): void
    {
        $scope = WorkspaceScope::fromString(strtoupper(self::WORKSPACE_ID));

        self::assertSame(self::WORKSPACE_ID, $scope->id);
        self::assertTrue($scope->equals(WorkspaceScope::fromString(self::WORKSPACE_ID)));
    }

    public function testTwoWorkspacesAreNeverEqual(): void
    {
        self::assertFalse(
            WorkspaceScope::fromString(self::WORKSPACE_ID)
                ->equals(WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a2')),
        );
    }

    /**
     * A scope reaches SQL, so anything that is not exactly a UUID is refused
     * here rather than being handed to a query.
     */
    #[DataProvider('valuesThatAreNotWorkspaces')]
    public function testItRefusesAnythingThatIsNotAUuid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorkspaceScope::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNotWorkspaces(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['household'];
        yield 'truncated' => ['00000000-0000-7000-8000-0000000000a'];
        yield 'sql fragment' => ["' OR '1'='1"];
        yield 'trailing predicate' => [self::WORKSPACE_ID.' OR 1=1'];
        yield 'newline' => [self::WORKSPACE_ID."\n"];
    }
}
