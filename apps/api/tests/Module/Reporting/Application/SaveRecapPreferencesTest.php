<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Categories\Application\ReadBudgetCategoryFlags;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Application\WorkspaceTimezoneReader;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\ReadRecapPreferences;
use App\Module\Reporting\Application\SaveRecapPreferences;
use App\Module\Reporting\Application\SaveRecapPreferencesInput;
use App\Module\Reporting\Domain\RecapPreferences;
use App\Module\Reporting\Domain\RecapPreferencesRepository;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SaveRecapPreferencesTest extends TestCase
{
    public function testTheWorkspaceOwnerCanSaveTheSelection(): void
    {
        $preferences = $this->createMock(RecapPreferencesRepository::class);
        $preferences->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (RecapPreferences $saved): bool => WorkspaceFixture::OWN_WORKSPACE === $saved->workspace->id), 0)
            ->willReturn(true);

        $saved = ($this->useCase(self::caller(true), $preferences))(new SaveRecapPreferencesInput([], ['FIXED'], 0));

        self::assertSame(['FIXED'], $saved->visibleAxes);
        self::assertSame(1, $saved->version);
    }

    public function testAWorkspaceMemberCannotSaveTheSelection(): void
    {
        $preferences = $this->createMock(RecapPreferencesRepository::class);
        $preferences->expects(self::never())->method('save');

        $this->expectException(WorkspaceAccessDenied::class);

        ($this->useCase(self::caller(false), $preferences))(new SaveRecapPreferencesInput([], ['FIXED'], 0));
    }

    public function testAWorkspaceMemberCanStillReadTheSelection(): void
    {
        $preferences = $this->createMock(RecapPreferencesRepository::class);
        $preferences->expects(self::once())
            ->method('find')
            ->willReturn(RecapPreferences::unsaved(WorkspaceFixture::own(), ['FIXED']));

        $visible = (new ReadRecapPreferences(self::caller(false), $preferences))();

        self::assertSame(['FIXED'], $visible->visibleAxes);
        self::assertSame(0, $visible->version);
    }

    private function useCase(
        CallerWorkspace&CallerWorkspaceContext $caller,
        RecapPreferencesRepository $preferences,
    ): SaveRecapPreferences {
        return new SaveRecapPreferences(
            $caller,
            $preferences,
            new ReadBudgetCategoryFlags($this->createStub(CategoryRepository::class)),
            new WorkspaceCalendar(
                new MockClock('2026-09-24T12:00:00+00:00'),
                $caller,
                $this->createStub(WorkspaceTimezoneReader::class),
            ),
        );
    }

    private static function caller(bool $isOwner): CallerWorkspace&CallerWorkspaceContext
    {
        return new readonly class($isOwner) implements CallerWorkspace, CallerWorkspaceContext {
            public function __construct(private bool $isOwner)
            {
            }

            public function resolve(): WorkspaceScope
            {
                return WorkspaceFixture::own();
            }

            public function resolveContext(): WorkspaceContext
            {
                return new WorkspaceContext(WorkspaceFixture::own(), WorkspaceFixture::OWNER_ID, $this->isOwner);
            }
        };
    }
}
