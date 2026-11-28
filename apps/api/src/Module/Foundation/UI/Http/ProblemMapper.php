<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Answers an application exception from a declarative table, so a module lists
 * each exception once instead of repeating a `catch` or `match` per action.
 */
final readonly class ProblemMapper
{
    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    /**
     * The first rule whose class matches wins, so a subclass must precede its parent.
     * An `int` rule means "status only": the key and extensions come from {@see CarriesProblem}.
     * A closure builds the rule from the exception when the answer depends on its state.
     * An exception absent from the table is rethrown for the global listener.
     *
     * @param array<class-string<\Throwable>, ProblemRule|int|\Closure> $rules
     */
    public function respond(\Throwable $exception, array $rules): Response
    {
        foreach ($rules as $class => $rule) {
            if (!$exception instanceof $class) {
                continue;
            }
            if ($rule instanceof \Closure) {
                /** @var ProblemRule|int $rule the closure builds the rule from the exception it was declared for */
                $rule = $rule($exception);
            }
            if (is_int($rule)) {
                if (!$exception instanceof CarriesProblem) {
                    throw new \LogicException(sprintf('%s must implement %s to be mapped by status alone.', $exception::class, CarriesProblem::class));
                }
                $rule = new ProblemRule($rule, 'api.problem.'.$exception->problemCode(), extensions: $exception->problemExtensions());
            }

            return $this->envelope->problem($rule->status, $rule->translationKey, $rule->type, $rule->extensions, $rule->headers, $rule->parameters);
        }

        throw $exception;
    }
}
