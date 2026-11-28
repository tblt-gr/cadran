<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Accounts\UI\Http\AccountBalanceProblems;
use App\Module\Accounts\UI\Http\AccountGroupProblems;
use App\Module\Accounts\UI\Http\AccountOverviewProblems;
use App\Module\Accounts\UI\Http\AccountProblems;
use App\Module\Accounts\UI\Http\PeriodClosureProblems;
use App\Module\Accounts\UI\Http\ProductModelProblems;
use App\Module\Budget\UI\Http\GoalProblems;
use App\Module\Categories\UI\Http\CategoryProblems;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Portfolio\UI\Http\PortfolioProblems;
use App\Module\Portfolio\UI\Http\PortfolioReadProblems;
use App\Module\Reporting\UI\Http\ReportingProblems;
use App\Module\Transactions\UI\Http\CategorizationRuleProblems;
use App\Module\Transactions\UI\Http\RecurrenceProblems;
use App\Module\Transactions\UI\Http\TransactionProblems;
use App\Module\Transactions\UI\Http\TransferProblems;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every problem table of the API: an internal inconsistency is never a client error, while a
 * refused body answers the status its endpoint documents.
 */
final class MalformedPayloadTablesTest extends TestCase
{
    /** Every static, parameterless method of these classes is a table. */
    private const array TABLE_CLASSES = [
        AccountBalanceProblems::class, AccountGroupProblems::class, AccountOverviewProblems::class, AccountProblems::class,
        PeriodClosureProblems::class, ProductModelProblems::class, GoalProblems::class, CategoryProblems::class,
        PortfolioProblems::class, PortfolioReadProblems::class, ReportingProblems::class, CategorizationRuleProblems::class,
        RecurrenceProblems::class, TransactionProblems::class, TransferProblems::class,
    ];

    /** @param array<class-string<\Throwable>, ProblemRule|int|\Closure> $rules */
    #[DataProvider('tables')]
    public function testAnInternalInconsistencyIsRethrown(array $rules): void
    {
        $fault = new \UnexpectedValueException('A row escaped its requested workspace.');

        $this->expectExceptionObject($fault);

        $this->mapper()->respond($fault, $rules);
    }

    /** @param array<class-string<\Throwable>, ProblemRule|int|\Closure> $rules */
    #[DataProvider('bodyTables')]
    public function testARefusedBodyAnswersItsDocumentedStatus(array $rules, int $status): void
    {
        self::assertSame($status, $this->mapper()->respond(new MalformedPayload(), $rules)->getStatusCode());
    }

    /** @return iterable<string, array{array<class-string<\Throwable>, ProblemRule|int|\Closure>}> */
    public static function tables(): iterable
    {
        foreach (self::TABLE_CLASSES as $class) {
            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() && 0 === $method->getNumberOfRequiredParameters() && 'array' === (string) $method->getReturnType()) {
                    /** @var array<class-string<\Throwable>, ProblemRule|int|\Closure> $rules */
                    $rules = $method->invoke(null);

                    yield $class.'::'.$method->getName() => [$rules];
                }
            }
        }
    }

    /** @return iterable<string, array{array<class-string<\Throwable>, ProblemRule|int|\Closure>, int}> */
    public static function bodyTables(): iterable
    {
        yield 'transaction create' => [TransactionProblems::create(), 422];
        yield 'transaction update' => [TransactionProblems::update(), 422];
        yield 'transaction void' => [TransactionProblems::void(), 422];
        yield 'transaction reconcile' => [TransactionProblems::reconcile(), 422];
        yield 'transaction duplicate' => [TransactionProblems::duplicate(), 422];
        yield 'transaction splits' => [TransactionProblems::replaceSplits(), 422];
        yield 'transaction bulk' => [TransactionProblems::bulk(), 422];
        yield 'refund' => [TransactionProblems::createRefund(), 422];
        yield 'account reconciliation' => [TransactionProblems::accountReconciliationResolve(), 422];
        yield 'transfer' => [TransferProblems::rules(), 422];
        yield 'recurrence create' => [RecurrenceProblems::create(), 422];
        yield 'recurrence update' => [RecurrenceProblems::update(), 422];
        yield 'recurrence run' => [RecurrenceProblems::run(), 422];
        yield 'rule create' => [CategorizationRuleProblems::create(), 422];
        yield 'rule apply' => [CategorizationRuleProblems::apply(), 422];
        yield 'category write' => [CategoryProblems::write(), 422];
        yield 'category lifecycle' => [CategoryProblems::lifecycle(), 422];
        yield 'goal write' => [GoalProblems::write(), 422];
        yield 'goal preview' => [GoalProblems::preview(), 422];
        yield 'goal stop' => [GoalProblems::stop(), 400];
        yield 'valuation' => [PortfolioProblems::valuation(), 422];
        yield 'operation' => [PortfolioProblems::operation(), 422];
        yield 'takeover' => [PortfolioProblems::takeover(), 422];
        yield 'life insurance' => [PortfolioProblems::lifeInsurance(), 422];
        yield 'operation order' => [PortfolioProblems::position(), 422];
        yield 'instrument' => [PortfolioProblems::instrumentWrite(), 422];
        yield 'account create' => [AccountProblems::write(true), 422];
        yield 'account move' => [AccountProblems::move(), 422];
        yield 'account group move' => [AccountGroupProblems::move(), 422];
        yield 'account balance' => [AccountBalanceProblems::record(), 422];
    }

    private function mapper(): ProblemMapper
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ProblemMapper(new JsonRequestEnvelope($translator));
    }
}
