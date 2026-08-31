<?php

namespace Impruthvi\CashierDunning\Chaos;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobQueued;
use Symfony\Component\Mime\Address;

/**
 * Records what an application did to the outside world during a replay.
 *
 * Idempotency bugs hide in final state. An application that emails a customer
 * twice, or dispatches a dunning job twice, ends the run with a perfectly
 * correct subscription row — the damage is in what left the building, and the
 * database has no memory of it. Comparing end states across orderings would
 * therefore find nothing, which is exactly why most billing test suites believe
 * their handlers are idempotent.
 *
 * Effects are collected by listening rather than by faking. Laravel's fakes
 * change what the application does — a faked queue never runs the handler that
 * contains the bug — and this way the run is the real one.
 */
final class SideEffectLedger
{
    /** @var list<string> */
    private array $entries = [];

    private bool $listening = false;

    public function __construct(private readonly Dispatcher $events) {}

    public function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        $this->events->listen(JobQueued::class, function (JobQueued $event): void {
            $this->entries[] = 'job:'.(is_object($event->job) ? $event->job::class : (string) $event->job);
        });

        $this->events->listen(MessageSending::class, function (MessageSending $event): void {
            // Recipients, not indexes. An entry reading "mail:0" tells whoever
            // is reading a failure nothing about who got the second email.
            $recipients = array_map(
                static fn (Address $address): string => $address->getAddress(),
                $event->message->getTo()
            );

            $this->entries[] = 'mail:'.implode(',', $recipients);
        });

        $this->events->listen(NotificationSending::class, function (NotificationSending $event): void {
            $this->entries[] = 'notification:'.$event->notification::class;
        });
    }

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<string> */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * What the application did, counted by kind.
     *
     * Order is deliberately discarded and counts are kept. Two orderings are
     * allowed to produce the same effects in a different sequence — that is
     * what "order does not matter" means — but they are not allowed to produce
     * a different number of them, because that is a customer emailed twice.
     *
     * @return array<string, int>
     */
    public function signature(): array
    {
        $counts = array_count_values($this->entries);

        ksort($counts);

        return $counts;
    }
}
