<?php

namespace Impruthvi\CashierDunning\Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;

/**
 * @extends RuleTestCase<ThrowInFinallyRule>
 */
final class ThrowInFinallyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ThrowInFinallyRule;
    }

    // PHPStan reads its cache with warnings suppressed, but from inside its phar,
    // which PHPUnit's exclude list cannot name, so a cold cache (every CI run)
    // reports the suppressed warning and failOnWarning turns it into a failure.
    #[WithoutErrorHandler]
    public function test_it_flags_only_throws_that_run_during_cleanup(): void
    {
        $message = 'Throwing inside a finally block replaces any exception already on its way out.';
        $tip = 'Record the failure and throw it after the try statement.';

        $this->analyse([__DIR__.'/data/throw-in-finally.php'], [
            [$message, 12, $tip],
            [$message, 22, $tip],
            [$message, 32, $tip],
        ]);
    }
}
