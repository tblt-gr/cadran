<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Application\Goals;

use App\Module\Budget\Domain\Goals\GoalEvaluationStatus;
use App\Module\Budget\Domain\Goals\GoalPace;
use App\Module\Budget\Domain\Goals\GoalPolicyState;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalStatusQuality;
use PHPUnit\Framework\TestCase;

/** Statuses, paces, reasons, qualities and policy states are only ever named through their enums. */
final class GoalVocabularyTest extends TestCase
{
    public function testNoApplicationClassSpellsAGoalVocabularyWord(): void
    {
        $words = array_merge(...array_map(
            static fn (string $enum): array => array_column($enum::cases(), 'value'),
            [GoalEvaluationStatus::class, GoalPace::class, GoalReason::class, GoalStatusQuality::class, GoalPolicyState::class],
        ));
        $pattern = '/[\'"]('.implode('|', array_unique($words)).')[\'"]/';
        $files = glob(\dirname(__DIR__, 5).'/src/Module/Budget/Application/Goals/*.php') ?: [];
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression($pattern, (string) file_get_contents($file), basename($file));
        }
    }
}
