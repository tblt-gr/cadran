<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Runs the capture command, the only writer of month snapshots besides the closing event. */
trait CapturesSnapshots
{
    private function captureSnapshots(): void
    {
        $command = (new Application(self::$kernel ?? self::bootKernel()))->find('cadran:reporting:capture-snapshots');

        Assert::assertSame(0, (new CommandTester($command))->execute(['email' => WorkspaceFixture::OWNER_EMAIL]));
    }
}
