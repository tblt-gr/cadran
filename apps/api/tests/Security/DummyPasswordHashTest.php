<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Identity\Infrastructure\Security\IdentityUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * ASVS 5.0 V6/V7 (credential timing): verifying an unknown account against the dummy hash must cost what verifying
 * a real one costs, or response latency tells which e-mail exists. The cost parameters are read from the
 * production security configuration, not copied here, so a change of one without the other fails.
 */
final class DummyPasswordHashTest extends TestCase
{
    public function testTheDummyHashUsesTheProductionArgon2idCost(): void
    {
        $configuration = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/security.yaml');
        self::assertIsArray($configuration);
        $security = $configuration['security'] ?? null;
        self::assertIsArray($security);
        $hashers = $security['password_hashers'] ?? null;
        self::assertIsArray($hashers);
        $production = reset($hashers);
        self::assertIsArray($production);
        self::assertSame('argon2id', $production['algorithm'] ?? null);
        // The production hasher leaves the thread count at its default of one.
        self::assertArrayNotHasKey('threads', $production);

        $dummy = (new \ReflectionClassConstant(IdentityUserProvider::class, 'DUMMY_HASH'))->getValue();
        self::assertIsString($dummy);
        $info = password_get_info($dummy);

        self::assertSame('argon2id', $info['algoName']);
        self::assertIsArray($info['options']);
        $options = $info['options'];
        self::assertSame($production['memory_cost'] ?? null, $options['memory_cost'] ?? null);
        self::assertSame($production['time_cost'] ?? null, $options['time_cost'] ?? null);
        self::assertSame(1, $options['threads'] ?? null);
    }
}
