<?php

namespace Impruthvi\CashierDunning\Reporting;

use Impruthvi\CashierDunning\Runner\EventResult;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\StepResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Draws a replay as a timeline.
 *
 * The output is the product. A replay that is correct but unreadable gets
 * skimmed, and a billing test nobody reads is a billing test nobody trusts.
 *
 * Two audiences, one renderer. Interactively it uses colour and marks, because
 * the eye finds a red row before it finds a word. Piped into CI it drops both,
 * because escape codes and box-drawing characters in a log are noise that makes
 * the failure harder to find, not easier — and the Windows console is not
 * reliably able to draw them at all.
 */
final readonly class TimelineRenderer
{
    public function __construct(
        private OutputInterface $output,
        private bool $explain = false,
    ) {}

    public function render(ReplayReport $report): void
    {
        $this->line('');
        $this->line("  <options=bold>{$report->scenario}</>  replayed with no Stripe account");
        $this->line('');

        foreach ($report->steps as $step) {
            $this->renderStep($step);
        }

        $this->line('');
        $this->renderVerdict($report);
        $this->line('');
    }

    private function renderStep(StepResult $step): void
    {
        $this->line(sprintf(
            '  %s <options=bold>+%s</>  %s',
            $step->failed() ? $this->mark('✗', 'FAIL', 'red') : $this->mark('✓', 'ok', 'green'),
            $step->advanceTo,
            $step->label,
        ));

        foreach ($step->events as $event) {
            $this->renderEvent($event);
        }

        if ($step->mismatches !== []) {
            $this->renderDivergence($step);
        }
    }

    private function renderEvent(EventResult $event): void
    {
        $status = $event->failed()
            ? "<fg=red>{$event->status}</>"
            : "<fg=gray>{$event->status}</>";

        $this->line("      {$event->type}  {$status}");

        if ($event->failure !== null) {
            $this->line("        <fg=red>{$event->failure}</>");
        }

        if ($this->explain) {
            foreach ($event->changes as $change) {
                $this->line('        <fg=yellow>'.$change->describe().'</>');
            }
        }
    }

    /**
     * The recording and the application side by side, for the step where they
     * stopped agreeing.
     *
     * Every declared feature is shown, not only the differing ones. A single
     * red line reads as an isolated glitch; the same line among four green ones
     * reads as "everything else held, this one moved", which is usually the
     * whole diagnosis.
     */
    private function renderDivergence(StepResult $step): void
    {
        $features = array_keys($step->expectedEntitlements);
        $width = max(array_map(strlen(...), [...$features, 'feature']));

        $this->line('');
        $this->line(sprintf('        %-'.$width.'s  %-12s  %s', 'feature', 'recording', 'application'));

        foreach ($features as $feature) {
            $expected = $this->value($step->expectedEntitlements[$feature] ?? null);
            $actual = $this->value($step->actualEntitlements[$feature] ?? null);
            $differs = $expected !== $actual;

            $row = sprintf('        %-'.$width.'s  %-12s  %s', $feature, $expected, $actual);

            $this->line($differs ? "<fg=red>{$row}</>" : "<fg=gray>{$row}</>");
        }

        $this->line('');
    }

    private function renderVerdict(ReplayReport $report): void
    {
        $this->line($report->passed()
            ? '  <fg=green>PASS</>  '.$report->verdict()
            : '  <fg=red>FAIL</>  '.$report->verdict());
    }

    private function value(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * Marks degrade to words when the output is not a terminal. A CI log that
     * renders ✓ as a replacement character has lost the one column a reader
     * scans first.
     */
    private function mark(string $symbol, string $word, string $colour): string
    {
        // Padded so the columns still line up when the words replace the marks:
        // "ok" and "FAIL" are different widths and a ragged left edge costs the
        // reader the scan the marks were there to provide.
        return $this->output->isDecorated()
            ? "<fg={$colour}>{$symbol}</>"
            : str_pad($word, 4);
    }

    private function line(string $line): void
    {
        $this->output->writeln($line);
    }
}
