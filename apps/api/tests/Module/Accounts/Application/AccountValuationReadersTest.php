<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use PHPUnit\Framework\TestCase;

final class AccountValuationReadersTest extends TestCase
{
    public function testAllBalanceReadersUseTheSharedResolver(): void
    {
        $root = dirname(__DIR__, 4).'/src/Module/Accounts/Application';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $name = $file->getBasename('.php');
            if ('ResolveAccountValuations' === $name || 'AssessPeriodClosing' === $name || str_contains($name, 'AccountBalanceSnapshot')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('/AccountValuation::of\s*\(/', $source, $name.' must use ResolveAccountValuations.');
            self::assertDoesNotMatchRegularExpression('/->findLatestForAccounts(?:OnDates)?\s*\(/', $source, $name.' must use ResolveAccountValuations.');
        }
    }
}
