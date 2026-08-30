<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Domain;

use App\Module\Identity\Domain\Workspace;
use PHPUnit\Framework\TestCase;

final class WorkspaceTest extends TestCase
{
    public function testItAcceptsAValidWorkspace(): void
    {
        $workspace = new Workspace(
            id: '00000000-0000-7000-8000-000000000001',
            name: 'Household',
            timezone: Workspace::DEFAULT_TIMEZONE,
            baseCurrency: 'EUR',
            createdAt: new \DateTimeImmutable(),
        );

        self::assertSame('Europe/Paris', $workspace->timezone);
        self::assertSame('EUR', $workspace->baseCurrency);
    }

    public function testItRejectsATimezoneThatIsNotAnIanaIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Workspace(
            id: '00000000-0000-7000-8000-000000000001',
            name: 'Household',
            timezone: 'Mars/Olympus',
            baseCurrency: 'EUR',
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testItRejectsABaseCurrencyThatIsNotThreeUppercaseLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Workspace(
            id: '00000000-0000-7000-8000-000000000001',
            name: 'Household',
            timezone: Workspace::DEFAULT_TIMEZONE,
            baseCurrency: 'eur',
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testItRejectsABlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Workspace(
            id: '00000000-0000-7000-8000-000000000001',
            name: '   ',
            timezone: Workspace::DEFAULT_TIMEZONE,
            baseCurrency: 'EUR',
            createdAt: new \DateTimeImmutable(),
        );
    }
}
