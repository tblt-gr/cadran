<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Recurrence and refund writers serialize on the workspace row before they lock
 * anything else; a row lock taken first can deadlock against a transaction edit.
 */
final class LockOrderRuleTest extends TestCase
{
    public function testEveryRowLockFollowsTheWorkspaceLock(): void
    {
        $application = dirname(__DIR__, 2).'/src/Module/Transactions/Application';
        $locking = $violations = [];
        foreach ([...glob($application.'/Recurrence/*.php') ?: [], $application.'/CreateRefund.php'] as $file) {
            $source = (string) file_get_contents($file);
            $rowLock = strpos($source, 'findForUpdate(');
            if (false === $rowLock) {
                continue;
            }
            $locking[] = basename($file);
            $workspaceLock = strpos($source, '->acquire(');
            if (false === $workspaceLock || $workspaceLock > $rowLock) {
                $violations[] = basename($file);
            }
        }

        self::assertContains('CreateRefund.php', $locking);
        self::assertContains('EditRecurrence.php', $locking);
        self::assertSame([], $violations, 'These files lock a row before the workspace.');
    }
}
