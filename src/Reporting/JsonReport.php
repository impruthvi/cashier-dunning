<?php

namespace Impruthvi\CashierDunning\Reporting;

use Impruthvi\CashierDunning\Entitlements\EntitlementChange;
use Impruthvi\CashierDunning\Runner\EventResult;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\StepResult;

/**
 * The same replay, in a shape a machine can read.
 *
 * Terminal output is for the person who ran the command; this is for the run
 * they were not watching. A CI job that fails at 3am leaves a log nobody reads
 * and an artifact somebody can diff, and the artifact is what turns "billing
 * broke sometime last month" into a date.
 *
 * Key order is fixed and values are plain scalars, so two reports can be
 * compared without a parser that understands them.
 */
final readonly class JsonReport
{
    /** @return array<string, mixed> */
    public static function toArray(ReplayReport $report): array
    {
        return [
            'scenario' => $report->scenario,
            'passed' => $report->passed(),
            'verdict' => $report->verdict(),
            'assertions' => $report->assertions,
            'events_delivered' => $report->eventsDelivered(),
            'completed' => $report->completed,
            'side_effects' => $report->sideEffects,
            'blocked_deliveries' => $report->blockedDeliveries,
            'steps' => array_map(self::step(...), $report->steps),
        ];
    }

    public static function encode(ReplayReport $report): string
    {
        return json_encode(
            self::toArray($report),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )."\n";
    }

    public static function write(ReplayReport $report, string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, self::encode($report));
    }

    /** @return array<string, mixed> */
    private static function step(StepResult $step): array
    {
        return [
            'index' => $step->index,
            'label' => $step->label,
            'advance_to' => $step->advanceTo,
            'passed' => ! $step->failed(),
            'events' => array_map(self::event(...), $step->events),
            'entitlements' => [
                'recording' => $step->expectedEntitlements,
                'application' => $step->actualEntitlements,
            ],
            'mismatches' => $step->mismatches,
        ];
    }

    /** @return array<string, mixed> */
    private static function event(EventResult $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'status' => $event->status,
            'failure' => $event->failure,
            'changes' => array_map(
                static fn (EntitlementChange $change): array => [
                    'feature' => $change->feature,
                    'from' => $change->from,
                    'to' => $change->to,
                    'caused_by' => $change->eventType,
                ],
                $event->changes
            ),
        ];
    }
}
