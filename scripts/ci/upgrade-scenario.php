<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/*
 * Helper of scripts/ci/check-migrations.sh (OPS-003). It boots the application
 * found under <apps/api directory> (the released tag or the current branch) and
 * prints only table names and row counts, never a value.
 *
 *   php upgrade-scenario.php provision <apps/api>   create the owner through the real setup command
 *   php upgrade-scenario.php counts <apps/api>      print "<table> <row count>" for every table
 *
 * The owner password is random, lives only in this process and is never printed.
 */

[, $action, $root] = $argv + [null, null, null];
if (!in_array($action, ['provision', 'counts'], true) || !is_string($root) || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "usage: upgrade-scenario.php provision|counts <apps/api directory>\n");
    exit(2);
}

require $root.'/vendor/autoload.php';

$environment = getenv('APP_ENV') ?: 'dev';
$kernel = new Kernel($environment, false);
$kernel->boot();

if ('provision' === $action) {
    $application = new Application($kernel);
    $application->setAutoExit(false);
    $password = bin2hex(random_bytes(16)).'Aa1!';
    $tester = new CommandTester($application->find('cadran:identity:setup'));
    $tester->setInputs(['upgrade-check@example.invalid', 'Upgrade Check', 'Upgrade check', '', $password, $password]);
    $status = $tester->execute([], ['interactive' => true]);
    if (0 !== $status) {
        fwrite(STDERR, "The released tag could not provision its owner (status $status).\n");
        exit(1);
    }

    exit(0);
}

/** @var \Doctrine\DBAL\Connection $connection */
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$tables = $connection->fetchFirstColumn(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name",
);
foreach ($tables as $table) {
    if (!is_string($table) || 1 !== preg_match('/^[a-z0-9_]+$/', $table)) {
        fwrite(STDERR, "Unexpected table name.\n");
        exit(1);
    }
    printf("%s %d\n", $table, $connection->fetchOne(sprintf('SELECT count(*) FROM "%s"', $table)));
}
