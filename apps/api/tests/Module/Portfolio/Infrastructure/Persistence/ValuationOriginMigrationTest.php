<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Infrastructure\Persistence;

use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The two legacy sentences become sources with no note; going down restores them. */
final class ValuationOriginMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261128120000';
    private const string REFRESH_NOTE = 'Mise à jour automatique après une opération.';
    private const string TAKEOVER_NOTE = 'Reprise des positions.';

    private Connection $connection;
    private bool $atHead = true;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $this->connection = $this->connect();
        (new WorkspaceFixture($this->connection))->reset();
        (new WorkspaceFixture($this->connection))->seed();
        (new PortfolioFixture($this->connection))->account();
    }

    protected function tearDown(): void
    {
        if (!$this->atHead) {
            $this->migrate('up');
        }
        (new WorkspaceFixture($this->connection))->reset();
        parent::tearDown();
    }

    public function testTheLegacySentencesBecomeSourcesAndAUserNoteStaysAsItIs(): void
    {
        $this->migrate('down');
        $this->atHead = false;
        $this->valuation(1, self::REFRESH_NOTE);
        $this->valuation(2, self::TAKEOVER_NOTE);
        $this->valuation(3, 'Relevé du courtier');
        $this->valuation(4, null);
        $this->connection->insert('portfolio_takeovers', [
            'id' => '00000000-0000-7000-8000-000000000e01', 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => PortfolioFixture::ACCOUNT,
            'taken_over_on' => '2026-02-02', 'valuation_id' => self::id(2), 'created_at' => '2026-02-02 10:00:00+00',
        ]);

        $this->migrate('up');
        $this->atHead = true;

        self::assertSame([['REFRESH', null], ['TAKEOVER', null], ['MANUAL', 'Relevé du courtier'], ['MANUAL', null]], $this->origins());

        $this->migrate('down');
        $this->atHead = false;
        self::assertSame([['MANUAL', self::REFRESH_NOTE], ['MANUAL', self::TAKEOVER_NOTE], ['MANUAL', 'Relevé du courtier'], ['MANUAL', null]], $this->origins());
        try {
            $this->connection->executeStatement("UPDATE portfolio_valuations SET source = 'REFRESH' WHERE id = ?", [self::id(1)]);
            self::fail('The former constraint refuses the new sources.');
        } catch (DbalException $refused) {
            self::assertStringContainsString('portfolio_valuations_source_valid', $refused->getMessage());
        }
    }

    /** @return list<array{mixed, mixed}> */
    private function origins(): array
    {
        return array_map(
            static fn (array $row): array => [$row['source'], $row['note']],
            $this->connection->fetchAllAssociative('SELECT source, note FROM portfolio_valuations ORDER BY valued_on'),
        );
    }

    private function valuation(int $number, ?string $note): void
    {
        $this->connection->insert('portfolio_valuations', [
            'id' => self::id($number), 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'account_id' => PortfolioFixture::ACCOUNT,
            'valued_on' => sprintf('2026-02-%02d', $number), 'asset_code' => 'EUR', 'cash_value' => '1', 'cash_scale' => 0,
            'derived_cash_value' => '1', 'derived_cash_scale' => 0, 'cash_source' => 'DERIVED', 'securities_value_value' => '0',
            'securities_value_scale' => 0, 'total_value_value' => '1', 'total_value_scale' => 0, 'detail' => 'TOTAL', 'completeness' => 'COMPLETE',
            'source' => 'MANUAL', 'note' => $note, 'recorded_at' => '2026-02-01 10:00:00+00', 'recorded_by' => WorkspaceFixture::OWNER_ID, 'version' => 1,
        ]);
    }

    private static function id(int $number): string
    {
        return sprintf('00000000-0000-7000-8000-000000000c%02d', $number);
    }

    private function migrate(string $direction): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => [self::MIGRATION], '--'.$direction => true, '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->connection = $this->connect();
    }

    private function connect(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
