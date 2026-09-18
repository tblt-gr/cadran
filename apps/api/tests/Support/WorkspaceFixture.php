<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Two populated workspaces on a real PostgreSQL schema, so an isolation test
 * can always ask the question that matters: does this use case, repository or
 * endpoint show the caller anything that belongs to the other one?
 *
 * Every module gets the same fixture. A test that only ever seeds one workspace
 * cannot fail the way a leak actually happens.
 */
final readonly class WorkspaceFixture
{
    public const string OWN_WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    public const string OTHER_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';
    public const string OWNER_ID = '00000000-0000-7000-8000-000000000001';
    public const string OTHER_OWNER_ID = '00000000-0000-7000-8000-000000000002';
    public const string OWNER_EMAIL = 'owner@example.test';
    public const string OTHER_OWNER_EMAIL = 'stranger@example.test';
    public const string OWNER_PASSWORD = 'correct horse battery staple';

    private const string CREATED_AT = '2026-08-31 12:00:00.000000+00';
    private const string OWN_MEMBERSHIP_ID = '00000000-0000-7000-8000-0000000000b1';
    private const string OTHER_MEMBERSHIP_ID = '00000000-0000-7000-8000-0000000000b2';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Integration tests are skipped locally when no database is configured, but
     * never in CI: a silently skipped isolation test proves nothing.
     */
    public static function requireDatabase(): void
    {
        if (false !== getenv('DATABASE_URL')) {
            return;
        }

        if (false !== getenv('CI')) {
            Assert::fail('DATABASE_URL must be set in CI; PostgreSQL integration tests may not be skipped there.');
        }

        Assert::markTestSkipped('This PostgreSQL integration test requires DATABASE_URL.');
    }

    public static function own(): WorkspaceScope
    {
        return WorkspaceScope::fromString(self::OWN_WORKSPACE);
    }

    public static function other(): WorkspaceScope
    {
        return WorkspaceScope::fromString(self::OTHER_WORKSPACE);
    }

    /**
     * Two workspaces, each with its own owner. The password hash is optional:
     * only tests that actually sign in pay for hashing it.
     */
    public function seed(?PasswordHasher $hasher = null): void
    {
        $hash = null === $hasher ? null : $hasher->hash(PlainPassword::fromString(self::OWNER_PASSWORD));

        // Both owners get the credential: a test that can only ever sign in as
        // one of them proves isolation from one side, which is the side a leak
        // does not show up on.
        foreach ([
            [self::OWNER_ID, self::OWNER_EMAIL],
            [self::OTHER_OWNER_ID, self::OTHER_OWNER_EMAIL],
        ] as [$id, $email]) {
            $this->connection->insert('identity_users', [
                'id' => $id,
                'email' => $email,
                'display_name' => 'Owner',
                'created_at' => self::CREATED_AT,
                'password_hash' => $hash,
            ]);
        }

        foreach ([
            [self::OWN_WORKSPACE, self::OWN_MEMBERSHIP_ID, self::OWNER_ID],
            [self::OTHER_WORKSPACE, self::OTHER_MEMBERSHIP_ID, self::OTHER_OWNER_ID],
        ] as $index => [$workspaceId, $membershipId, $userId]) {
            $this->connection->insert('identity_workspaces', [
                'id' => $workspaceId,
                'name' => 'Workspace '.$index,
                'timezone' => 'Europe/Paris',
                'base_currency' => 'EUR',
                'created_at' => self::CREATED_AT,
            ]);
            $this->connection->insert('identity_workspace_memberships', [
                'id' => $membershipId,
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'role' => 'OWNER',
                'created_at' => self::CREATED_AT,
            ]);
        }
    }

    /**
     * TRUNCATE, not DELETE, on the trail: its append-only trigger rejects row
     * deletion, and its workspace and actor foreign keys would then block the
     * identity cleanup that follows.
     */
    public function reset(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE audit_events');
        // Transactions restrict account and category deletion. Splits follow a
        // deleted transaction, but clearing both keeps reset order explicit.
        // Transfers restrict their own legs, so they clear before either.
        // Occurrences restrict the transaction they matched, so the forecast
        // clears before the movements it points at.
        $this->connection->executeStatement('DELETE FROM transaction_recurrence_occurrences');
        $this->connection->executeStatement('DELETE FROM transaction_recurrences');
        $this->connection->executeStatement('DELETE FROM transaction_recurrence_dismissals');
        $this->connection->executeStatement('DELETE FROM transaction_transfers');
        $this->connection->executeStatement('DELETE FROM transaction_refunds');
        // A review points at both the reviewed row and the rows it could settle.
        $this->connection->executeStatement('DELETE FROM transaction_reconciliations');
        $this->connection->executeStatement('DELETE FROM transaction_reconciliation_candidates');
        $this->connection->executeStatement('DELETE FROM transaction_idempotency_keys');
        $this->connection->executeStatement('DELETE FROM transaction_categorization_previews');
        $this->connection->executeStatement('DELETE FROM transaction_splits');
        $this->connection->executeStatement('DELETE FROM transaction_transactions');
        $this->connection->executeStatement('DELETE FROM transaction_categorization_rules');
        // Snapshots restrict account deletion: the trail of observed balances
        // must be cleared first. Rule overrides and their rate brackets follow
        // the account through ON DELETE CASCADE.
        $this->connection->executeStatement('DELETE FROM account_balance_snapshots');
        $this->connection->executeStatement('DELETE FROM account_financial_accounts');
        $this->connection->executeStatement('DELETE FROM account_groups');
        // The capabilities, rule periods and brackets follow their model
        // through ON DELETE CASCADE.
        $this->connection->executeStatement('DELETE FROM account_product_models');
        // Redirections restrict category deletion: they point at both ends of a merge.
        $this->connection->executeStatement('DELETE FROM category_replacements');
        $this->connection->executeStatement('DELETE FROM category_categories');
        $this->connection->executeStatement('DELETE FROM identity_initial_provisionings');
        $this->connection->executeStatement('DELETE FROM identity_workspace_memberships');
        $this->connection->executeStatement('DELETE FROM identity_workspaces');
        $this->connection->executeStatement('DELETE FROM identity_users');
    }
}
