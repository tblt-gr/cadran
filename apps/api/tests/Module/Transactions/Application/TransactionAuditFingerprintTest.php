<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\TransactionAuditFingerprint;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use PHPUnit\Framework\TestCase;

/**
 * An amount has too little entropy for any digest of it to stay
 * non-reversible in an audit trail, so the fingerprint never carries one:
 * only the coarse, non-identifying signals {@see TransactionAuditFingerprint::of()}
 * exposes, plus the plain boolean {@see TransactionAuditFingerprint::changed()}
 * adds for an edit or a reconciliation settlement.
 */
final class TransactionAuditFingerprintTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    public function testTheFingerprintNeverCarriesTheAmountOrAnyDigestOfIt(): void
    {
        $transaction = self::transaction('-128.45');

        $fingerprint = TransactionAuditFingerprint::of($transaction);

        self::assertArrayNotHasKey('amount', $fingerprint);
        self::assertArrayNotHasKey('amountDigest', $fingerprint);
        foreach ($fingerprint as $value) {
            if (is_string($value)) {
                self::assertStringNotContainsString('128.45', $value);
            }
        }
    }

    public function testChangedFlagsAnAmountThatMoved(): void
    {
        $before = self::transaction('-128.45');
        $after = self::transaction('-99.00');

        $fingerprint = TransactionAuditFingerprint::changed($before, $after);

        self::assertTrue($fingerprint['figureChanged']);
        self::assertArrayNotHasKey('amount', $fingerprint);
        self::assertArrayNotHasKey('amountDigest', $fingerprint);
    }

    public function testChangedFlagsAnAmountThatDidNotMoveEvenAcrossAnotherScale(): void
    {
        $before = self::transaction('-128.450000');
        $after = self::transaction('-128.45');

        $fingerprint = TransactionAuditFingerprint::changed($before, $after);

        self::assertFalse($fingerprint['figureChanged']);
    }

    /**
     * The signal survives the audit trail's own redaction rules: an
     * attribute name built from a sensitive whole word (like `amount`) is
     * redacted regardless of its value, which would make the boolean useless
     * for anyone reading the trail.
     */
    public function testTheChangedSignalIsNotRedactedByTheAuditDiff(): void
    {
        $before = self::transaction('-128.45');
        $after = self::transaction('-99.00');

        $diff = AuditDiff::change(TransactionAuditFingerprint::of($before), TransactionAuditFingerprint::changed($before, $after));

        self::assertSame('true', $diff->after['figureChanged'] ?? null);
    }

    private static function transaction(string $amount): Transaction
    {
        $now = new \DateTimeImmutable('2026-04-14T09:12:04+00:00');

        return new Transaction(
            id: '00000000-0000-7000-8000-0000000000f1', workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: self::ACCOUNT, amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            originalAmount: null, exchangeRate: null, state: TransactionState::PENDING, nature: TransactionNature::EXPENSE,
            source: TransactionSource::PROVIDER, sourceRef: null, bookedOn: new \DateTimeImmutable('2026-04-12', new \DateTimeZone('UTC')),
            valueOn: null, authorizedOn: null, rawLabel: 'CB CARREFOUR 1234', counterparty: null, note: null,
            paymentMethod: null, mcc: null, maskedCard: null, bankReference: null, splits: [], version: 1,
            createdAt: $now, updatedAt: $now, voidedAt: null, lastEditorId: null,
        );
    }
}
