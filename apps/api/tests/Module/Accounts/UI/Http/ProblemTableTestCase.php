<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Tests\Module\Foundation\UI\Http\ProblemTableAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pins a problem table to the answers it must give. Each answer is
 * `[exception, status, translation key, type, extensions]`, so a table that
 * gains, loses or reorders a row in a way a client could see fails here.
 */
abstract class ProblemTableTestCase extends TestCase
{
    /**
     * @return iterable<string, array{array<class-string<\Throwable>, ProblemRule|\Closure>, list<array{0: \Throwable, 1: int, 2: string, 3?: string, 4?: array<string, mixed>}>}>
     */
    abstract public static function tables(): iterable;

    /**
     * @param array<class-string<\Throwable>, ProblemRule|\Closure>                               $rules
     * @param list<array{0: \Throwable, 1: int, 2: string, 3?: string, 4?: array<string, mixed>}> $answers
     */
    #[DataProvider('tables')]
    public function testTheTableDeclaresExactlyTheExpectedRows(array $rules, array $answers): void
    {
        ProblemTableAssertions::assertWellFormed($rules);
        self::assertEqualsCanonicalizing(
            array_values(array_unique(array_map(static fn (array $answer): string => $answer[0]::class, $answers))),
            array_keys($rules),
        );
    }

    /**
     * @param array<class-string<\Throwable>, ProblemRule|\Closure>                               $rules
     * @param list<array{0: \Throwable, 1: int, 2: string, 3?: string, 4?: array<string, mixed>}> $answers
     */
    #[DataProvider('tables')]
    public function testEveryRowAnswersItsProblemDocumentAndAnythingElseIsRethrown(array $rules, array $answers): void
    {
        $mapper = $this->mapper();

        foreach ($answers as $answer) {
            [$exception, $status, $translationKey] = $answer;
            $response = $mapper->respond($exception, $rules);

            self::assertSame($status, $response->getStatusCode(), $exception::class);
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'), $exception::class);
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $exception::class);
            self::assertSame(
                [
                    'type' => $answer[3] ?? 'about:blank',
                    'title' => $translationKey.'.title',
                    'status' => $status,
                    'detail' => $translationKey.'.detail',
                    ...($answer[4] ?? []),
                ],
                json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
                $exception::class,
            );
        }

        $unmapped = new \RuntimeException('The repository failed.');
        $this->expectExceptionObject($unmapped);
        $mapper->respond($unmapped, $rules);
    }

    private function mapper(): ProblemMapper
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ProblemMapper(new JsonRequestEnvelope($translator));
    }
}
