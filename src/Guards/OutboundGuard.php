<?php

namespace Impruthvi\CashierDunning\Guards;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobQueued;
use Impruthvi\CashierDunning\Chaos\SideEffectLedger;
use Symfony\Component\Mime\Address;

/**
 * Watches what a replay makes the application do, and stops the parts of it
 * that would reach a real person.
 *
 * A replay delivers a recorded `invoice.payment_failed` to the real webhook
 * route, so the real dunning listener runs, and a dunning listener's whole job
 * is to email the customer. On a machine with live SMTP credentials — which is
 * to say, on most machines — that email is sent, to whatever address the
 * fixture's customer carries. Nothing about "replayed with no Stripe account"
 * warns anyone that mail is not also simulated.
 *
 * So the run is real right up to the last hop. The listener runs, the mailable
 * is built, the recipients are resolved, the ledger records the attempt — and
 * then the send is refused. `Mailer::shouldSendMessage()` and
 * `NotificationSender::shouldSendNotification()` both dispatch through
 * `until()` and treat a `false` from any listener as "do not deliver", which is
 * the seam this uses. Every bug that lives in the application's code is still
 * reachable and still observed; only the delivery is missing.
 *
 * This is deliberately not `Mail::fake()`. A fake swaps the mailer out, so a
 * listener that formats a message wrongly never formats it at all, and the run
 * stops being the real one — which is the same objection the ledger's
 * listen-don't-fake design was built around.
 *
 * Queued work is observed, not blocked. `JobQueued` fires after the push has
 * happened, so there is nothing left to refuse; a replay on a non-`sync` queue
 * connection leaves a real job in a real queue. That limit is documented rather
 * than papered over.
 */
final class OutboundGuard
{
    private bool $listening = false;

    /**
     * The run currently being observed, or null between runs.
     *
     * Listeners cannot be unregistered without also discarding the
     * application's own listeners for the same events — `Dispatcher::forget()`
     * takes an event, not a listener — so they stay registered and go inert
     * instead. Nothing is intercepted outside `protect()`, which is what makes
     * this safe to install in a long-lived application process.
     */
    private ?SideEffectLedger $ledger = null;

    public function __construct(private readonly Dispatcher $events) {}

    /**
     * Run a replay with outbound delivery observed and blocked.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function protect(SideEffectLedger $ledger, Closure $callback): mixed
    {
        $this->listen();

        // Restored rather than nulled, so a runner nested inside another run
        // hands observation back instead of silently disarming the guard.
        $previous = $this->ledger;
        $this->ledger = $ledger;

        try {
            return $callback();
        } finally {
            $this->ledger = $previous;
        }
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        $this->events->listen(JobQueued::class, function (JobQueued $event): void {
            $this->ledger?->record(
                'job:'.(is_object($event->job) ? $event->job::class : (string) $event->job)
            );
        });

        $this->events->listen(MessageSending::class, function (MessageSending $event): ?bool {
            if ($this->ledger === null) {
                return null;
            }

            // Recipients, not indexes. An entry reading "mail:0" tells whoever
            // is reading a failure nothing about who nearly got the second email.
            $recipients = array_map(
                static fn (Address $address): string => $address->getAddress(),
                $event->message->getTo()
            );

            $this->ledger->recordBlocked('mail:'.implode(',', $recipients));

            return false;
        });

        $this->events->listen(NotificationSending::class, function (NotificationSending $event): ?bool {
            if ($this->ledger === null) {
                return null;
            }

            $this->ledger->recordBlocked('notification:'.$event->notification::class);

            return false;
        });
    }
}
