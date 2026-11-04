<?php

namespace Impruthvi\CashierDunning\PHPStan\Tests;

use Impruthvi\CashierDunning\PHPStan\Rules\ThrowInFinallyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;

/**
 * Run with `vendor/bin/phpunit -c phpstan/phpunit.xml.dist`, not the package suite:
 * PHPStan's container is large and cached statically, and built inside the
 * package suite it leaves the remaining tests short of memory.
 *
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
