<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Domain;

use App\Module\Audit\Domain\AuditDiff;
use PHPUnit\Framework\TestCase;

final class AuditDiffTest extends TestCase
{
    public function testACreationHasNoBeforeSide(): void
    {
        $diff = AuditDiff::creation(['role' => 'OWNER']);

        self::assertSame([], $diff->before);
        self::assertSame(['role' => 'OWNER'], $diff->after);
        self::assertFalse($diff->isEmpty());
    }

    public function testAnEventWithoutAttributesIsEmptyOnBothSides(): void
    {
        $diff = AuditDiff::none();

        self::assertTrue($diff->isEmpty());
    }

    /**
     * The guarantee the ticket names first: a credential, a banking identifier
     * or a financial value never reaches the stored trail in clear.
     *
     * @param array<string, scalar|null> $attributes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveAttributes')]
    public function testASensitiveAttributeIsStoredRedacted(array $attributes, string $name): void
    {
        $diff = AuditDiff::creation($attributes);

        self::assertSame(AuditDiff::REDACTED, $diff->after[$name]);
        self::assertStringNotContainsString(
            (string) $attributes[$name],
            (string) json_encode($diff->after),
        );
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>, string}>
     */
    public static function sensitiveAttributes(): iterable
    {
        yield 'plain password' => [['password' => 'correct horse battery staple'], 'password'];
        yield 'password hash' => [['passwordHash' => '$argon2id$v=19$m=65536'], 'passwordHash'];
        yield 'session token' => [['csrfToken' => 'a1b2c3'], 'csrfToken'];
        yield 'banking identifier' => [['iban' => 'FR7630006000011234567890189'], 'iban'];
        // camelCase and snake_case must behave identically: a substring match
        // on "account_number" would silently miss the camelCase spelling that
        // every attribute in this codebase actually uses.
        yield 'camelCase account number' => [['accountNumber' => 'FR7630006000011234567890189'], 'accountNumber'];
        yield 'snake_case account number' => [['account_number' => 'FR7630006000011234567890189'], 'account_number'];
        yield 'monetary amount' => [['amount' => '1234.560000000000000000000000'], 'amount'];
        yield 'nested monetary amount' => [['openingBalance' => '900.00'], 'openingBalance'];
        yield 'transaction label' => [['label' => 'Loyer août'], 'label'];
        yield 'free-text description' => [['description' => 'Virement Jean Dupont'], 'description'];
        yield 'counterparty' => [['payeeName' => 'Jean Dupont'], 'payeeName'];
        yield 'email address' => [['contactEmail' => 'owner@example.test'], 'contactEmail'];
        yield 'raw import content' => [['rawLine' => 'a;b;c'], 'rawLine'];
        yield 'dated rate' => [['annualRate' => '0.03'], 'annualRate'];
    }

    /**
     * A word list matched as a raw substring would swallow these: "discarded"
     * contains "card", "drawdown" contains "raw", "keyword" contains "key".
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('structuralAttributes')]
    public function testAStructuralAttributeKeepsItsValue(string $name): void
    {
        $diff = AuditDiff::creation([$name => 'kept']);

        self::assertSame('kept', $diff->after[$name]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function structuralAttributes(): iterable
    {
        yield 'discarded' => ['discarded'];
        yield 'drawdown' => ['drawdown'];
        yield 'keyword' => ['keyword'];
        yield 'cardinality' => ['cardinality'];
        yield 'role' => ['role'];
        yield 'timezone' => ['timezone'];
        yield 'baseCurrency' => ['baseCurrency'];
        yield 'displayName' => ['displayName'];
    }

    public function testABooleanAndANullSurviveAsExplicitValues(): void
    {
        $diff = AuditDiff::change(['archived' => false], ['archived' => true, 'closedOn' => null]);

        self::assertSame(['archived' => 'false'], $diff->before);
        self::assertSame(['archived' => 'true', 'closedOn' => null], $diff->after);
    }

    public function testAValueIsTruncatedToTheDocumentedBound(): void
    {
        $diff = AuditDiff::creation(['name' => str_repeat('x', AuditDiff::MAX_VALUE_LENGTH + 10)]);

        self::assertSame(
            str_repeat('x', AuditDiff::MAX_VALUE_LENGTH).AuditDiff::TRUNCATED_SUFFIX,
            $diff->after['name'],
        );
    }

    public function testControlCharactersAreStrippedSoAViewerCannotBeForged(): void
    {
        $diff = AuditDiff::creation(['name' => "House\u{0000}hold\nsplit"]);

        self::assertSame('Householdsplit', $diff->after['name']);
    }

    public function testItRefusesMoreAttributesThanTheBound(): void
    {
        $attributes = [];
        for ($index = 0; $index <= AuditDiff::MAX_ATTRIBUTES; ++$index) {
            $attributes['attribute'.$index] = 'value';
        }

        $this->expectException(\InvalidArgumentException::class);

        AuditDiff::creation($attributes);
    }

    public function testItRefusesAnAttributeNameThatIsNotStructural(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditDiff::creation(['user email' => 'owner@example.test']);
    }

    /**
     * The domain bound must imply the schema bound: a diff this object accepts
     * always fits the migration's CHECK, so an audit write can never abort the
     * business transaction it belongs to.
     */
    public function testItRefusesASideThatWouldOverflowTheStoredColumn(): void
    {
        $attributes = [];
        for ($index = 1; $index <= AuditDiff::MAX_ATTRIBUTES; ++$index) {
            $attributes['attribute'.$index] = str_repeat('x', AuditDiff::MAX_VALUE_LENGTH);
        }

        $this->expectException(\InvalidArgumentException::class);

        AuditDiff::creation($attributes);
    }

    public function testTheLargestAcceptedSideStaysUnderTheEncodedCeiling(): void
    {
        $attributes = [];
        for ($index = 1; $index <= 12; ++$index) {
            $attributes['attribute'.$index] = str_repeat('x', AuditDiff::MAX_VALUE_LENGTH);
        }

        $diff = AuditDiff::creation($attributes);

        self::assertLessThanOrEqual(
            AuditDiff::MAX_ENCODED_LENGTH,
            mb_strlen((string) json_encode($diff->after), '8bit'),
        );
    }

    public function testItRefusesABinaryFloatingPointValue(): void
    {
        // The financial invariant reaches the trail too: a decimal arrives as
        // its canonical string, never as a float.
        $this->expectException(\InvalidArgumentException::class);

        AuditDiff::creation(['threshold' => 0.1 + 0.2]);
    }
}
