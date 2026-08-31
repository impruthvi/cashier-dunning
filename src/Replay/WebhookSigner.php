<?php

namespace Impruthvi\CashierDunning\Replay;

/**
 * Signs replayed webhook payloads the way Stripe signs real ones.
 *
 * Cashier applies its signature middleware only when a webhook secret is
 * configured. The tempting shortcut is to leave the secret unset so replayed
 * events sail through unverified — but signature handling is precisely the part
 * of webhook code that is hard to get right and easy to break, so a replay that
 * skips it proves less than a live run.
 *
 * Instead the simulation invents a secret, and this signs against it with the
 * real algorithm. Cashier's middleware stays switched on and does the same work
 * it would do in production. Nothing is bypassed; the secret is simply one
 * nobody else knows, for a payload that never crossed a network.
 *
 * Header format, matching Stripe's:
 *
 *     Stripe-Signature: t=1767225600,v1=<hex hmac of "t.payload">
 */
final readonly class WebhookSigner
{
    private const SCHEME = 'v1';

    public function __construct(private string $secret) {}

    public function sign(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= $this->now();

        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        return 't='.$timestamp.','.self::SCHEME.'='.$signature;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{payload: string, headers: array<string, string>}
     */
    public function envelope(array $event, ?int $timestamp = null): array
    {
        $payload = (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'payload' => $payload,
            'headers' => ['Stripe-Signature' => $this->sign($payload, $timestamp)],
        ];
    }

    /**
     * Real wall-clock time, deliberately, even though the simulation has moved
     * Carbon weeks into the future.
     *
     * Stripe's verifier compares the header timestamp against PHP's `time()`,
     * which Carbon's test clock does not affect, and rejects anything outside
     * the tolerance window — five minutes by default. Signing with the
     * simulated timestamp would make every replayed event fail verification the
     * moment a scenario advanced past that window, which is to say immediately.
     *
     * The event's own `created` field still carries simulated time. That is the
     * value the application reads; this one only proves the payload was not
     * tampered with in transit, and there was no transit.
     */
    private function now(): int
    {
        return time();
    }
}
