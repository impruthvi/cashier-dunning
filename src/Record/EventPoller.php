<?php

namespace Impruthvi\CashierDunning\Record;

use Stripe\StripeClient;

/**
 * Collects the events a clock advance produced.
 *
 * Polls `GET /v1/events` rather than shelling out to `stripe listen`. The
 * Stripe CLI cannot be installed by Composer, so depending on it would mean
 * every contributor and every CI runner has to install a binary by hand before
 * they can record — and a dependency that cannot be declared is a dependency
 * that will be missing.
 *
 * Events are paged newest-first and returned oldest-first, because a billing
 * timeline read backwards is not a timeline. Ids already seen are skipped:
 * Stripe's event list is eventually consistent, so the same event can appear in
 * two consecutive polls, and a fixture with a duplicated event would look like
 * a delivery bug that never happened.
 *
 * Only events belonging to this recording are kept. A test account is shared —
 * another recording, or a colleague clicking around the dashboard, will emit
 * events into the same stream, and a fixture that swept those up would be both
 * wrong and potentially somebody else's data.
 */
final class EventPoller
{
    private const POLL_INTERVAL_MICROSECONDS = 1_000_000;

    /** @var array<string, true> */
    private array $seen = [];

    /** @param list<string> $objectIds */
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly int $since,
        private array $objectIds = [],
    ) {}

    /**
     * Events belonging to this recording become relevant as they are created —
     * the subscription id is not known until the subscription exists.
     */
    public function watch(string $objectId): void
    {
        if ($objectId !== '' && ! in_array($objectId, $this->objectIds, true)) {
            $this->objectIds[] = $objectId;
        }
    }

    /**
     * Poll until every expected event type has been seen, or until the wait
     * runs out.
     *
     * Returns whatever arrived either way. A caller that got less than it asked
     * for is not necessarily broken — optional events genuinely may not come —
     * so the decision about whether a shortfall matters belongs to the manifest,
     * not here.
     *
     * @param  list<string>  $expected
     * @return list<array<string, mixed>>
     */
    public function collect(array $expected = [], int $timeoutSeconds = 30): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $collected = [];

        do {
            foreach ($this->fetch() as $event) {
                $collected[] = $event;
            }

            $types = array_map(
                static fn (array $event): string => (string) ($event['type'] ?? ''),
                $collected
            );

            if ($expected !== [] && array_diff($expected, $types) === []) {
                return $collected;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        } while (true);

        return $collected;
    }

    /**
     * One pass over the event list, oldest first, skipping anything already
     * seen or belonging to something else.
     *
     * @return list<array<string, mixed>>
     */
    public function fetch(): array
    {
        $response = $this->stripe->events->all([
            'limit' => 100,
            'created' => ['gte' => $this->since],
        ]);

        $events = [];

        foreach (array_reverse($response->data) as $event) {
            /** @var array<string, mixed> $data */
            $data = $event->toArray();
            $id = (string) ($data['id'] ?? '');

            if ($id === '' || isset($this->seen[$id]) || ! $this->belongsToRecording($data)) {
                continue;
            }

            $this->seen[$id] = true;
            $events[] = $data;
        }

        return $events;
    }

    /**
     * An event belongs to this recording if the object it carries is one we
     * created, or points at one.
     *
     * @param  array<string, mixed>  $event
     */
    private function belongsToRecording(array $event): bool
    {
        if ($this->objectIds === []) {
            return false;
        }

        $object = (array) (($event['data'] ?? [])['object'] ?? []);

        foreach (['id', 'customer', 'subscription'] as $key) {
            $value = $object[$key] ?? null;

            if (is_string($value) && in_array($value, $this->objectIds, true)) {
                return true;
            }
        }

        return false;
    }
}
