<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\InvalidAccount;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\MaskedIdentifier;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    private const string NOW = '2026-09-03T10:00:00+00:00';

    public function testItCarriesItsDenominationKindAndInclusionPolicies(): void
    {
        $account = $this->account();

        self::assertSame('Livret A Banque X', $account->label);
        self::assertSame('EUR', $account->assetCode->toString());
        self::assertSame(AccountKind::SAVINGS, $account->kind);
        self::assertSame('4821', (string) $account->maskedIdentifier);
        self::assertTrue($account->includeInNetWorth);
        self::assertFalse($account->isClosed());
    }

    public function testALiabilityContributesANegativeSignToNetWorth(): void
    {
        self::assertSame(1, $this->account()->netWorthSign());
        self::assertSame(-1, $this->account(kind: AccountKind::LIABILITY)->netWorthSign());
    }

    #[DataProvider('invalidLabels')]
    public function testItRejectsAnUnboundedOrExecutableLabel(string $label): void
    {
        $this->expectException(InvalidAccount::class);

        $this->account(label: $label);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLabels(): iterable
    {
        yield 'empty' => [''];
        yield 'untrimmed' => [' Livret A '];
        yield 'control character' => ["Livret\nA"];
        yield 'too long' => [str_repeat('a', Account::MAX_LABEL_LENGTH + 1)];
    }

    #[DataProvider('unmaskedIdentifiers')]
    public function testItRefusesAnIdentifierThatIsNotAMaskedSuffix(string $identifier): void
    {
        $this->expectException(InvalidAccount::class);

        MaskedIdentifier::fromString($identifier);
    }

    /** @return iterable<string, array{string}> */
    public static function unmaskedIdentifiers(): iterable
    {
        yield 'full iban' => ['FR7630006000011234567890189'];
        yield 'card number' => ['4970123456789012'];
        yield 'separated suffix' => ['48 21'];
        yield 'single character' => ['4'];
        yield 'lowercase' => ['ab12'];
    }

    public function testAPortfolioValuationRequiresAKindThatHoldsPositions(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('holds positions');

        $this->account(kind: AccountKind::CURRENT, valuationMode: AccountValuationMode::PORTFOLIO);
    }

    public function testAnAccountExcludedFromNetWorthCannotBackTheEmergencyFund(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('emergency fund');

        $this->account(includeInNetWorth: false, includeInEmergencyFund: true);
    }

    #[DataProvider('invalidLifecycles')]
    public function testItRejectsIncoherentLifecycleDates(string $openedOn, ?string $closedOn, string $expected): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage($expected);

        $this->account(openedOn: $openedOn, closedOn: $closedOn);
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function invalidLifecycles(): iterable
    {
        yield 'opened in the future' => ['2026-09-04', null, 'opened in the future'];
        yield 'closed before opening' => ['2026-01-10', '2026-01-09', 'closed before it was opened'];
        yield 'closed in the future' => ['2026-01-10', '2026-09-04', 'closed in the future'];
        yield 'opened before the calendar bound' => ['1899-12-31', null, 'cannot be opened before'];
    }

    public function testAnOpeningDateIsACalendarDayRatherThanAnInstant(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('calendar day');

        $this->account(openedOn: '2026-01-10T08:30:00');
    }

    public function testClosingAnAccountKeepsItReadableAndBumpsItsVersion(): void
    {
        $closed = $this->reconfigured($this->account(), closedOn: new \DateTimeImmutable('2026-08-31', new \DateTimeZone('UTC')));

        self::assertTrue($closed->isClosed());
        self::assertSame(2, $closed->version);
        self::assertNull($closed->archivedAt);
    }

    public function testALaterEditCanStillRecordAPastOpeningDate(): void
    {
        // Paperwork arriving after the account was recorded: 2026-06-01 is in the
        // past at edit time, even though it follows the creation day.
        $account = $this->account(openedOn: '2026-01-10');
        $corrected = $account->reconfigure(
            label: $account->label,
            kind: $account->kind,
            productCode: $account->productCode,
            productModelId: $account->productModelId,
            institution: $account->institution,
            maskedIdentifier: $account->maskedIdentifier,
            valuationMode: $account->valuationMode,
            liquidityLevel: $account->liquidityLevel,
            includeInNetWorth: $account->includeInNetWorth,
            includeInEmergencyFund: $account->includeInEmergencyFund,
            openedOn: new \DateTimeImmutable('2026-06-01', new \DateTimeZone('UTC')),
            closedOn: null,
            updatedAt: new \DateTimeImmutable('2026-06-20T09:00:00+00:00'),
        );

        self::assertSame('2026-06-01', $corrected->openedOn->format('Y-m-d'));
    }

    public function testAUsedAccountCannotChangeKind(): void
    {
        $account = $this->account(usedAt: new \DateTimeImmutable(self::NOW));

        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('used account');

        $this->reconfigured($account, kind: AccountKind::CURRENT);
    }

    #[DataProvider('invalidInstitutions')]
    public function testItRejectsAnUnboundedOrExecutableInstitution(string $institution): void
    {
        $this->expectException(InvalidAccount::class);

        $this->account(institution: $institution);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInstitutions(): iterable
    {
        yield 'empty' => [''];
        yield 'untrimmed' => [' Banque X '];
        yield 'control character' => ["Banque\nX"];
        yield 'too long' => [str_repeat('a', Account::MAX_INSTITUTION_LENGTH + 1)];
    }

    public function testAnAccountKeepsOnlyItsProductReference(): void
    {
        $account = $this->account();

        self::assertSame('FR_LIVRET_A', $account->productCode?->toString());
        self::assertSame('Banque X', $account->institution);
    }

    public function testAnAccountDescribedByHandCarriesNoProduct(): void
    {
        self::assertNull($this->account(productCode: null, institution: null)->productCode);
    }

    public function testAnAccountReferencesAtMostOneProductOrModel(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('at most one product or model');

        $this->account(
            productCode: 'FR_LIVRET_A',
            productModelId: '00000000-0000-7000-8000-0000000000e1',
        );
    }

    public function testAModelBackedAccountCarriesNoCatalogueProduct(): void
    {
        $account = $this->account(productCode: null, productModelId: '00000000-0000-7000-8000-0000000000e1');

        self::assertSame('00000000-0000-7000-8000-0000000000e1', $account->productModelId);
        self::assertNull($account->productCode);
    }

    public function testAProductModelReferenceMustBeACanonicalUuid(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('canonical UUID');

        $this->account(productCode: null, productModelId: 'not-a-uuid');
    }

    /**
     * Swapping the model of an account that already carries history would
     * re-read every past movement against another set of dated rules.
     */
    public function testAUsedAccountCannotChangeModel(): void
    {
        $account = $this->account(
            productCode: null,
            productModelId: '00000000-0000-7000-8000-0000000000e1',
            usedAt: new \DateTimeImmutable(self::NOW),
        );

        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('change model');

        $this->reconfigured($account, productModelId: '00000000-0000-7000-8000-0000000000e2');
    }

    public function testAUsedAccountKeepingItsModelIsStillEditable(): void
    {
        $account = $this->account(
            productCode: null,
            productModelId: '00000000-0000-7000-8000-0000000000e1',
            usedAt: new \DateTimeImmutable(self::NOW),
        );

        $renamed = $this->reconfigured($account, label: 'Livret A Banque Y');

        self::assertSame('00000000-0000-7000-8000-0000000000e1', $renamed->productModelId);
    }

    /**
     * Swapping the model of an account that already carries history would
     * re-read every past movement against another set of dated rules.
     */
    public function testAUsedAccountCannotChangeProduct(): void
    {
        $account = $this->account(usedAt: new \DateTimeImmutable(self::NOW));

        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('change product');

        $this->reconfigured($account, productCode: ProductCode::fromString('FR_LDDS'));
    }

    public function testAUsedAccountKeepingItsProductIsStillEditable(): void
    {
        $account = $this->account(usedAt: new \DateTimeImmutable(self::NOW));

        $renamed = $this->reconfigured($account, label: 'Livret A Banque Y');

        self::assertSame('FR_LIVRET_A', $renamed->productCode?->toString());
    }

    public function testAnArchivedAccountIsReadOnly(): void
    {
        $archived = $this->account()->archive(new \DateTimeImmutable(self::NOW));

        self::assertNotNull($archived->archivedAt);

        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('archived account');

        $archived->archive(new \DateTimeImmutable(self::NOW));
    }

    public function testTheDenominationNeverChangesThroughAnEdit(): void
    {
        $renamed = $this->reconfigured($this->account(), label: 'Livret A Banque Y');

        self::assertSame('Livret A Banque Y', $renamed->label);
        self::assertSame('EUR', $renamed->assetCode->toString());
    }

    public function testItStartsWithoutAGroupLink(): void
    {
        $account = $this->account();

        self::assertNull($account->primaryGroupId);
        self::assertSame([], $account->tagGroupIds);
    }

    public function testItAcceptsOnePrimaryGroupAndDistinctTags(): void
    {
        $grouped = $this->account()->regroup(
            primaryGroupId: '00000000-0000-7000-8000-0000000000b1',
            tagGroupIds: ['00000000-0000-7000-8000-0000000000b2'],
            updatedAt: new \DateTimeImmutable(self::NOW),
        );

        self::assertSame('00000000-0000-7000-8000-0000000000b1', $grouped->primaryGroupId);
        self::assertSame(['00000000-0000-7000-8000-0000000000b2'], $grouped->tagGroupIds);
        self::assertSame(2, $grouped->version);
    }

    public function testATagCannotRepeatThePrimaryGroup(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('primary group');

        $this->account()->regroup(
            primaryGroupId: '00000000-0000-7000-8000-0000000000b1',
            tagGroupIds: ['00000000-0000-7000-8000-0000000000b1'],
            updatedAt: new \DateTimeImmutable(self::NOW),
        );
    }

    public function testATagCannotBeAssignedTwice(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('tag');

        $this->account()->regroup(
            primaryGroupId: '00000000-0000-7000-8000-0000000000b1',
            tagGroupIds: [
                '00000000-0000-7000-8000-0000000000b2',
                '00000000-0000-7000-8000-0000000000b2',
            ],
            updatedAt: new \DateTimeImmutable(self::NOW),
        );
    }

    private function reconfigured(
        Account $account,
        ?string $label = null,
        ?AccountKind $kind = null,
        ?\DateTimeImmutable $closedOn = null,
        ?ProductCode $productCode = null,
        ?string $productModelId = null,
    ): Account {
        return $account->reconfigure(
            label: $label ?? $account->label,
            kind: $kind ?? $account->kind,
            productCode: $productCode ?? $account->productCode,
            productModelId: $productModelId ?? $account->productModelId,
            institution: $account->institution,
            maskedIdentifier: $account->maskedIdentifier,
            valuationMode: $account->valuationMode,
            liquidityLevel: $account->liquidityLevel,
            includeInNetWorth: $account->includeInNetWorth,
            includeInEmergencyFund: $account->includeInEmergencyFund,
            openedOn: $account->openedOn,
            closedOn: $closedOn ?? $account->closedOn,
            updatedAt: new \DateTimeImmutable(self::NOW),
        );
    }

    private function account(
        string $label = 'Livret A Banque X',
        AccountKind $kind = AccountKind::SAVINGS,
        AccountValuationMode $valuationMode = AccountValuationMode::TRANSACTIONS,
        bool $includeInNetWorth = true,
        bool $includeInEmergencyFund = false,
        string $openedOn = '2026-01-10',
        ?string $closedOn = null,
        ?\DateTimeImmutable $usedAt = null,
        ?string $productCode = 'FR_LIVRET_A',
        ?string $productModelId = null,
        ?string $institution = 'Banque X',
    ): Account {
        $now = new \DateTimeImmutable(self::NOW);
        $utc = new \DateTimeZone('UTC');

        return new Account(
            id: '00000000-0000-7000-8000-0000000000d1',
            workspace: WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            label: $label,
            assetCode: AssetCode::fromString('EUR'),
            kind: $kind,
            productCode: null === $productCode ? null : ProductCode::fromString($productCode),
            productModelId: $productModelId,
            institution: $institution,
            maskedIdentifier: MaskedIdentifier::fromString('4821'),
            valuationMode: $valuationMode,
            liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: $includeInNetWorth,
            includeInEmergencyFund: $includeInEmergencyFund,
            openedOn: new \DateTimeImmutable($openedOn, $utc),
            closedOn: null === $closedOn ? null : new \DateTimeImmutable($closedOn, $utc),
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            usedAt: $usedAt,
        );
    }
}
