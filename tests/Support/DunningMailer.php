<?php

namespace Impruthvi\CashierDunning\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * An application that emails a customer when a payment fails.
 *
 * Two modes, because the interesting question is whether a test suite can tell
 * them apart. The naive one emails on every delivery; the careful one remembers
 * which events it has already handled. Stripe delivers at least once, so the
 * naive one emails some customers twice — and both end the run with an
 * identical, perfectly correct subscription row.
 */
class DunningMailer
{
    public static bool $deduplicate = false;

    public static function reset(): void
    {
        self::$deduplicate = false;
    }

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;

        if (($payload['type'] ?? null) !== 'invoice.payment_failed') {
            return;
        }

        $id = (string) ($payload['id'] ?? '');

        // Deduplication lives in the database, not in a static, because that is
        // where a real application keeps it — and because a chaos run replays
        // the timeline several times in one process, so anything remembered in
        // memory would leak from one pass into the next and look like a finding.
        if (self::$deduplicate) {
            if (DB::table('handled_webhooks')->where('event_id', $id)->exists()) {
                return;
            }

            DB::table('handled_webhooks')->insert(['event_id' => $id]);
        }

        Mail::raw('Your payment failed.', function ($message): void {
            $message->to('jenny@example.com')->subject('Payment failed');
        });
    }
}
