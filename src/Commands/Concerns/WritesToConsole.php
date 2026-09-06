<?php

namespace Impruthvi\CashierDunning\Commands\Concerns;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes to the raw console stream instead of Laravel's `OutputStyle`.
 *
 * `OutputStyle` collapses every run of spaces down to one. Both commands in
 * this package draw aligned columns — a timeline, a verdict column, a
 * recording-versus-application table — and all of that arrives single-spaced if
 * it goes through `$this->line()`.
 *
 * This is worth a shared trait rather than a copy in each command because the
 * copy is the dangerous part: a `write()` sitting in a command with no
 * explanation looks like an arbitrary style choice, and the obvious "cleanup"
 * is to replace it with `$this->line()`. That silently reintroduces the bug,
 * and it did once already — the published README examples had to be captured
 * from the test suite, which writes to a plain `BufferedOutput`, because the
 * CLI could not produce them.
 */
trait WritesToConsole
{
    private function display(): OutputInterface
    {
        return $this->output->getOutput();
    }

    private function write(string $line): void
    {
        $this->display()->writeln($line);
    }
}
