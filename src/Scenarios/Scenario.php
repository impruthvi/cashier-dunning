<?php

namespace Impruthvi\CashierDunning\Scenarios;

use Impruthvi\CashierDunning\Fixtures\Duration;
use Impruthvi\CashierDunning\Fixtures\Manifest;

/**
 * A billing story to record: what to do to a Stripe test account, when, and
 * what must come back.
 *
 * The manifest is the important half. Without it a recording that ended early
 * still produces a fixture, and that fixture is wrong in the worst possible
 * way — plausible. It replays green forever and the drift job, comparing it
 * against another short recording, agrees. Declaring the required events up
 * front turns "the recording looks fine" into a question with an answer.
 *
 * Scenarios are data. `billing:doctor` can print what one will do to your
 * Stripe account without doing any of it, and two scenarios can be diffed.
 */
final readonly class Scenario
{
    /** @param list<ScenarioStep> $steps */
    public function __construct(
        public string $name,
        public string $description,
        public array $steps,
        public Manifest $manifest,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            steps: array_map(
                ScenarioStep::fromArray(...),
                array_values((array) ($data['steps'] ?? []))
            ),
            manifest: Manifest::fromArray((array) ($data['manifest'] ?? [])),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'manifest' => $this->manifest->toArray(),
            'steps' => array_map(
                static fn (ScenarioStep $step): array => $step->toArray(),
                $this->steps
            ),
        ];
    }

    /**
     * How far the test clock has to travel in total.
     *
     * Worth knowing before starting: Stripe refuses to advance a test clock
     * more than two billing periods at a time, and a clock that has to cover
     * two months of monthly billing needs the recorder to move it in stages.
     */
    public function span(): Duration
    {
        $furthest = 0;

        foreach ($this->steps as $step) {
            $furthest = max($furthest, $step->advanceTo->minutes);
        }

        return Duration::fromMinutes($furthest);
    }

    /**
     * The steps that will create or change something in the Stripe account, as
     * opposed to simply advancing the clock and watching.
     *
     * @return list<ScenarioStep>
     */
    public function mutatingSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (ScenarioStep $step): bool => $step->action->type->touchesStripe()
        ));
    }

    /** @return list<string> */
    public function describeActions(): array
    {
        return array_map(
            static fn (ScenarioStep $step): string => '+'.$step->advanceTo->toString().'  '.$step->action->describe(),
            $this->steps
        );
    }
}
