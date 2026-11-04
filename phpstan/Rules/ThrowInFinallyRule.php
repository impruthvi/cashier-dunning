<?php

namespace Impruthvi\CashierDunning\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags a `throw` written inside a `finally` block.
 *
 * The simulation environment restores global state and rolls back its
 * transactions in `finally`, so a replay leaves no trace however it ends. A
 * throw there replaces any exception already on its way out, and that exception
 * is the one that says why the run ended (#34). Failures found during cleanup
 * are recorded and raised after the try statement instead.
 *
 * Lexical only: a throw inside a closure or class defined in the block runs
 * later, not during cleanup, so it is skipped. A guarded throw that is genuinely
 * safe takes a `@phpstan-ignore` with the reason.
 *
 * @implements Rule<TryCatch>
 */
final class ThrowInFinallyRule implements Rule
{
    public const IDENTIFIER = 'cashierDunning.throwInFinally';

    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->finally === null) {
            return [];
        }

        $errors = [];

        foreach ($this->throwsIn($node->finally->stmts) as $throw) {
            $errors[] = RuleErrorBuilder::message('Throwing inside a finally block replaces any exception already on its way out.')
                ->identifier(self::IDENTIFIER)
                ->line($throw->getStartLine())
                ->tip('Record the failure and throw it after the try statement.')
                ->build();
        }

        return $errors;
    }

    /**
     * @param  array<Node>  $stmts
     * @return list<Throw_>
     */
    private function throwsIn(array $stmts): array
    {
        $collector = new class extends NodeVisitorAbstract
        {
            /** @var list<Throw_> */
            public array $throws = [];

            public function enterNode(Node $node): ?int
            {
                // Code defined here runs later, outside the cleanup.
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Throw_) {
                    $this->throws[] = $node;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser($collector);
        $traverser->traverse($stmts);

        return $collector->throws;
    }
}
