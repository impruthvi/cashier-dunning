<?php

namespace Impruthvi\CashierDunning\Record;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Util\CaseInsensitiveArray;

/**
 * Collects response ids for one recording without changing the supplied
 * client's credentials or request options. SDK services (including lists)
 * pass through request(), so clock, catalog and action calls share one capture.
 *
 * @internal
 */
final class RecordingStripeClient extends StripeClient
{
    /** @var list<string> */
    public array $requestIds = [];

    public function __construct(private readonly StripeClient $stripe)
    {
        parent::__construct();
    }

    public function request($method, $path, $params, $opts)
    {
        try {
            $response = $this->stripe->request($method, $path, $params, $opts);
        } catch (ApiErrorException $e) {
            $this->capture($e->getHttpHeaders());

            throw $e;
        }

        $this->capture($response->getLastResponse()?->headers);

        return $response;
    }

    /** @param array<string, mixed>|CaseInsensitiveArray|null $headers */
    private function capture(array|CaseInsensitiveArray|null $headers): void
    {
        foreach ($headers ?? [] as $name => $value) {
            if (strtolower($name) === 'request-id' && is_string($value) && $value !== '') {
                $this->requestIds[] = $value;
            }
        }
    }
}
