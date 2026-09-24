<?php

namespace Impruthvi\CashierDunning\Runner;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Impruthvi\CashierDunning\Replay\WebhookSigner;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivers a replayed event to the application the way Stripe would.
 *
 * Through the HTTP kernel rather than by calling Cashier's controller directly.
 * Routing, middleware and signature verification are all part of what a webhook
 * has to survive in production, and they are where a surprising amount of
 * billing breakage lives — a global middleware that rejects requests without a
 * session, a route cache that never picked the webhook up, a signature check
 * that was quietly disabled. Calling the controller would skip every one of
 * those and report a green run.
 */
final readonly class WebhookDelivery
{
    public function __construct(
        private Kernel $kernel,
        private WebhookSigner $signer,
        private string $path,
    ) {}

    /** @param array<string, mixed> $event */
    public function deliver(array $event): Response
    {
        $envelope = $this->signer->envelope($this->asStripeSends($event));

        $request = Request::create(
            uri: $this->path,
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $envelope['payload'],
        );

        foreach ($envelope['headers'] as $header => $value) {
            $request->headers->set($header, $value);
        }

        $response = $this->kernel->handle($request);

        // Queued listeners and terminable middleware run here. Skipping this
        // would hide side effects that a real webhook delivery would have.
        $this->kernel->terminate($request, $response);

        return $response;
    }

    /**
     * Fill in the envelope fields every real Stripe event carries.
     *
     * Fixtures are written by hand, so they can leave out a field that is never
     * absent on the wire. `livemode` is the one that matters: an application
     * that checks it -- refusing to apply a live event to a test-mode install --
     * refuses the entire replay instead, and reports it as the application's
     * fault. That is precisely the class of breakage delivering through the real
     * kernel exists to catch, so it must not be one the harness causes.
     *
     * A replay never reaches a provider, so it is always test mode. A fixture
     * that declares its own value keeps it.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function asStripeSends(array $event): array
    {
        return $event + ['livemode' => false];
    }
}
