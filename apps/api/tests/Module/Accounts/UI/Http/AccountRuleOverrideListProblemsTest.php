<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ListAccountRuleOverrides;
use App\Module\Accounts\Application\ResolveAuthorNames;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountRuleOverrideRepository;
use App\Module\Accounts\UI\Http\AccountRuleOverrideController;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Identity\Application\AuthenticationUserRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Listing overrides reads stored rows only, so an `\UnexpectedValueException` there is a corrupt
 * or foreign row, not a malformed request: it must reach the global listener as a 500.
 */
final class AccountRuleOverrideListProblemsTest extends TestCase
{
    public function testARowThatCannotBeHydratedIsNotAnsweredAsAClientError(): void
    {
        $corruptRow = new \UnexpectedValueException('A row escaped its requested workspace.');
        $caller = self::createStub(CallerWorkspace::class);
        $caller->method('resolve')->willReturn(WorkspaceScope::fromString('01900000-0000-7000-8000-000000000001'));
        $accounts = self::createStub(AccountRepository::class);
        $accounts->method('find')->willThrowException($corruptRow);
        $envelope = new JsonRequestEnvelope(self::createStub(TranslatorInterface::class));
        $controller = new AccountRuleOverrideController($envelope, new ProblemMapper($envelope));

        $this->expectExceptionObject($corruptRow);

        $controller->list(
            '01900000-0000-7000-8000-000000000002',
            new ListAccountRuleOverrides($caller, $accounts, self::createStub(AccountRuleOverrideRepository::class)),
            new ResolveAuthorNames(self::createStub(AuthenticationUserRepository::class)),
        );
    }
}
