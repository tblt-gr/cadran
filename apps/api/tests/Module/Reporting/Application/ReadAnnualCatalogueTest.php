<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Application\ReadReportReferences;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\ReadAnnualCatalogue;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use App\Tests\Module\Categories\Application\Double\InMemoryCategoryRepository;
use PHPUnit\Framework\TestCase;

/** Two workspaces share one store: only the colors of the caller's own categories and groups resolve. */
final class ReadAnnualCatalogueTest extends TestCase
{
    private const string PARENT = '00000000-0000-7000-8000-0000000000e1';
    private const string CHILD = '00000000-0000-7000-8000-0000000000e2';
    private const string FOREIGN_CATEGORY = '00000000-0000-7000-8000-0000000000e3';
    private const string GROUP = AccountGroupFixture::ID;
    private const string FOREIGN_GROUP = '00000000-0000-7000-8000-0000000000d2';

    public function testOnlyTheColorsOfTheWorkspaceOwnCategoriesAndGroupsResolve(): void
    {
        $catalogue = $this->read(AccountGroupFixture::WORKSPACE);

        // The child has no color of its own and shows its parent's.
        self::assertSame('#112233', $catalogue->categoryColor(self::PARENT));
        self::assertSame('#112233', $catalogue->categoryColor(self::CHILD));
        self::assertNull($catalogue->categoryColor(self::FOREIGN_CATEGORY));
        self::assertNull($catalogue->categoryLabel(self::FOREIGN_CATEGORY));
        self::assertNotNull($catalogue->groupColor(self::GROUP));
        self::assertNull($catalogue->groupColor(self::FOREIGN_GROUP));
        self::assertNull($catalogue->groupLabel(self::FOREIGN_GROUP));
    }

    public function testTheOtherWorkspaceSeesOnlyItsOwnIdentities(): void
    {
        $catalogue = $this->read(AccountGroupFixture::OTHER_WORKSPACE);

        self::assertSame('#ABCDEF', $catalogue->categoryColor(self::FOREIGN_CATEGORY));
        self::assertNull($catalogue->categoryColor(self::PARENT));
        self::assertNull($catalogue->categoryColor(self::CHILD));
        self::assertNotNull($catalogue->groupColor(self::FOREIGN_GROUP));
        self::assertNull($catalogue->groupColor(self::GROUP));
    }

    public function testACategoryWithoutAnyColorInItsLineageHasNone(): void
    {
        $categories = new InMemoryCategoryRepository();
        $categories->add(self::category('00000000-0000-7000-8000-0000000000e4', AccountGroupFixture::WORKSPACE, 'Sans couleur', null, null));

        $catalogue = (new ReadAnnualCatalogue(
            new ReadBudgetCategoryFacts($categories),
            new ReadReportReferences(new InMemoryAccountRepository(), new InMemoryAccountGroupRepository()),
        ))(WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE));

        self::assertNull($catalogue->categoryColor('00000000-0000-7000-8000-0000000000e4'));
        self::assertSame('Sans couleur', $catalogue->categoryLabel('00000000-0000-7000-8000-0000000000e4'));
    }

    private function read(string $workspace): \App\Module\Reporting\Application\AnnualCatalogue
    {
        $categories = new InMemoryCategoryRepository();
        $categories->add(self::category(self::PARENT, AccountGroupFixture::WORKSPACE, 'Parent', null, '#112233'));
        $categories->add(self::category(self::CHILD, AccountGroupFixture::WORKSPACE, 'Enfant', self::PARENT, null));
        $categories->add(self::category(self::FOREIGN_CATEGORY, AccountGroupFixture::OTHER_WORKSPACE, 'Etranger', null, '#ABCDEF'));
        $groups = new InMemoryAccountGroupRepository(
            AccountGroupFixture::group(self::GROUP, label: 'Investissement'),
            AccountGroupFixture::group(self::FOREIGN_GROUP, AccountGroupFixture::OTHER_WORKSPACE, 'Foreign'),
        );

        return (new ReadAnnualCatalogue(
            new ReadBudgetCategoryFacts($categories),
            new ReadReportReferences(new InMemoryAccountRepository(), $groups),
        ))(WorkspaceScope::fromString($workspace));
    }

    private static function category(string $id, string $workspace, string $label, ?string $parentId, ?string $color): Category
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');

        return new Category($id, WorkspaceScope::fromString($workspace), CategoryType::EXPENSE, $label, $parentId, null, $color, [], true, 0, null === $parentId ? 1 : 2, 1, $now, $now);
    }
}
