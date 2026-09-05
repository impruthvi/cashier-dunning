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

        $this->renderSideEffects($report);

        $this->line('');
        $this->renderVerdict($report);
        $this->line('');
    }

    /**
     * Render why an embedded replay failed without repeating its full header
     * and verdict. Chaos already names the pass and owns the final verdict; it
     * needs the failed timeline rows that explain the red pass marker.
     */
    public function renderFailures(ReplayReport $report): void
    {
        $failures = $report->failures();

        if ($failures === []) {
            $this->line('        <fg=red>'.$report->verdict().'</>');

            return;
        }

        foreach ($failures as $step) {
            $this->renderStep($step);
        }
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

    /**
     * What the application tried to do to the outside world, and what was
     * stopped.
     *
     * Shown on passing runs too, not only failing ones. "Your app emailed the
     * customer three times during this timeline" is information whether or not
     * the entitlements matched, and the reader has no other way to see it.
     *
     * The blocked count is a separate line because it answers a different
     * question — not "what did my app do" but "did any of that reach a real
     * person" — and that is the question somebody running this on a laptop with
     * production SMTP credentials needs answered without reading the source.
     */
    private function renderSideEffects(ReplayReport $report): void
    {
        if ($report->sideEffects === []) {
            return;
        }

        $this->line('');
        $this->line('  <options=bold>Side effects</>  what the application did');

        foreach ($report->sideEffects as $effect => $count) {
            $this->line(sprintf('      <fg=gray>%s</>  ×%d', $effect, $count));
        }

        if ($report->blockedDeliveries > 0) {
            $this->line(sprintf(
                '      <fg=yellow>%d outbound delivery(s) blocked. A replay never mails a real customer.</>',
                $report->blockedDeliveries,
            ));
        }
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
