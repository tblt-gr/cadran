<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\LoadGroupTree;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadGroupColors;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use PHPUnit\Framework\TestCase;

final class ReadGroupColorsTest extends TestCase
{
    public function testATreeBeyondTheLimitIsRefusedRatherThanTruncated(): void
    {
        $groups = [];
        for ($index = 0; $index <= AccountGroup::MAX_PER_WORKSPACE; ++$index) {
            $groups[] = AccountGroupFixture::group(id: sprintf('00000000-0000-7000-8000-1%011d', $index), label: 'G'.$index);
        }

        $this->expectException(NetWorthScopeTooLarge::class);

        (new ReadGroupColors(new LoadGroupTree(new InMemoryAccountGroupRepository(...$groups))))(WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE));
    }
}
