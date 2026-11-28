<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonPayloadTest extends TestCase
{
    private const string ID = '0198a5e0-7c1b-7a3e-8f21-123456789abc';

    public function testItReadsTypedFieldsAndNestedObjects(): void
    {
        $payload = JsonPayload::of([
            'name' => 'Livret', 'version' => 2, 'flag' => false, 'id' => self::ID, 'tags' => ['a'],
            'rows' => [['value' => '1']], 'amount' => ['value' => '1.50', 'assetCode' => 'EUR'],
        ], ['name', 'version', 'flag', 'id', 'tags', 'rows', 'amount'], ['note']);

        self::assertSame('Livret', $payload->string('name'));
        self::assertSame(2, $payload->integer('version'));
        self::assertFalse($payload->boolean('flag'));
        self::assertSame(self::ID, $payload->uuid('id'));
        self::assertSame(['a'], $payload->stringList('tags', 1));
        self::assertSame('1', $payload->objects('rows', ['value'], 1)[0]->string('value'));
        self::assertSame(['value' => '1.50', 'assetCode' => 'EUR'], $payload->amount('amount'));
        self::assertNull($payload->nullableString('note'));
        self::assertNull($payload->nullableInteger('note'));
        self::assertNull($payload->nullableUuid('note'));
        self::assertNull($payload->raw('note'));
    }

    /** @param array<mixed> $body */
    #[DataProvider('wrongKeys')]
    public function testItRefusesAnyOtherFieldSet(array $body): void
    {
        $this->expectException(MalformedPayload::class);

        JsonPayload::of($body, ['version']);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function wrongKeys(): iterable
    {
        yield 'missing' => [[]];
        yield 'extra' => [['version' => 1, 'other' => 1]];
        yield 'non-string key' => [['version' => 1, 0 => 1]];
        yield 'list' => [[1]];
    }

    /** @param \Closure(JsonPayload): mixed $read */
    #[DataProvider('coercions')]
    public function testItNeverCoerces(mixed $value, \Closure $read): void
    {
        $this->expectException(MalformedPayload::class);

        $read(JsonPayload::of(['field' => $value], ['field']));
    }

    /** @return iterable<string, array{mixed, \Closure(JsonPayload): mixed}> */
    public static function coercions(): iterable
    {
        yield 'text version' => ['1', static fn (JsonPayload $p): int => $p->integer('field')];
        yield 'text flag' => ['true', static fn (JsonPayload $p): bool => $p->boolean('field')];
        yield 'numeric string' => [1, static fn (JsonPayload $p): string => $p->string('field')];
        yield 'nullable numeric string' => [1, static fn (JsonPayload $p): ?string => $p->nullableString('field')];
        yield 'upper case identifier' => [strtoupper(self::ID), static fn (JsonPayload $p): string => $p->uuid('field')];
        yield 'list over its bound' => [['a', 'b'], static fn (JsonPayload $p): array => $p->stringList('field', 1)];
        yield 'map for a list' => [['a' => 'b'], static fn (JsonPayload $p): array => $p->stringList('field', 5)];
        yield 'number in a string list' => [[1], static fn (JsonPayload $p): array => $p->stringList('field', 5)];
        yield 'scalar nested object' => [['a'], static fn (JsonPayload $p): array => $p->objects('field', ['value'], 5)];
        yield 'extra nested field' => [[['value' => 1, 'x' => 1]], static fn (JsonPayload $p): array => $p->objects('field', ['value'], 5)];
        yield 'numeric amount' => [['value' => 1.5, 'assetCode' => 'EUR'], static fn (JsonPayload $p): array => $p->amount('field')];
    }
}
