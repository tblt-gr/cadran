<?php

declare(strict_types=1);

namespace App\Tests\Module\Categories\Domain;

use App\Module\Categories\Domain\CategoryReplacement;
use App\Module\Categories\Domain\CategoryReplacementKind;
use App\Module\Categories\Domain\InvalidCategoryReplacement;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\TestCase;

final class CategoryReplacementTest extends TestCase
{
    private const string ID = '00000000-0000-7000-8000-0000000000f1';
    private const string SOURCE = '00000000-0000-7000-8000-0000000000c1';
    private const string TARGET = '00000000-0000-7000-8000-0000000000c2';

    public function testAMergeRedirectsTheWholeHistoryAndCarriesNoDate(): void
    {
        $merge = CategoryReplacement::merge(
            self::ID,
            $this->workspace(),
            self::SOURCE,
            self::TARGET,
            new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        );

        self::assertSame(CategoryReplacementKind::MERGE, $merge->kind);
        self::assertNull($merge->effectiveFrom);
    }

    public function testAReplacementIsAnchoredOnTheDayItTakesEffect(): void
    {
        $replacement = CategoryReplacement::from(
            self::ID,
            $this->workspace(),
            self::SOURCE,
            self::TARGET,
            new \DateTimeImmutable('2026-04-01T18:45:12+02:00'),
            new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        );

        self::assertSame(CategoryReplacementKind::REPLACEMENT, $replacement->kind);
        // The submitted time of day must not shift which period the redirection covers.
        self::assertSame('2026-04-01 00:00:00', $replacement->effectiveFrom?->format('Y-m-d H:i:s'));
    }

    public function testACategoryCannotReplaceItself(): void
    {
        $this->expectException(InvalidCategoryReplacement::class);
        $this->expectExceptionMessage('cannot replace itself');

        CategoryReplacement::merge(
            self::ID,
            $this->workspace(),
            self::SOURCE,
            self::SOURCE,
            new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        );
    }

    public function testADatedRedirectionCannotBeRecordedAsAMerge(): void
    {
        $this->expectException(InvalidCategoryReplacement::class);
        $this->expectExceptionMessage('carries no date');

        new CategoryReplacement(
            self::ID,
            $this->workspace(),
            self::SOURCE,
            self::TARGET,
            CategoryReplacementKind::MERGE,
            new \DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        );
    }

    public function testAReplacementWithoutADateIsRefused(): void
    {
        $this->expectException(InvalidCategoryReplacement::class);
        $this->expectExceptionMessage('must carry the date');

        new CategoryReplacement(
            self::ID,
            $this->workspace(),
            self::SOURCE,
            self::TARGET,
            CategoryReplacementKind::REPLACEMENT,
            null,
            new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        );
    }

    private function workspace(): WorkspaceScope
    {
        return WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1');
    }
}
