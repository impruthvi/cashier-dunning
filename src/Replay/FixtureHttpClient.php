<?php

namespace Impruthvi\CashierDunning\Replay;

use Impruthvi\CashierDunning\Fixtures\ApiExchange;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Replay\Exceptions\NoSuchStep;
use Impruthvi\CashierDunning\Replay\Exceptions\UnmatchedRequest;
use Stripe\HttpClient\ClientInterface;

/**
 * Answers the application's Stripe calls from a fixture instead of from Stripe.
 *
 * Installed into `ApiRequestor::setHttpClient()` by the simulation environment,
 * which is the only seam the SDK offers: it ships its own CurlClient and never
 * passes through Laravel's HTTP stack, so `Http::fake()` cannot see any of this.
 *
 * Two rules do the real work.
 *
 * **Nothing is invented.** A call with no recorded response is an error naming
 * the call and the step, never an empty body. A replay that answers `{}` to
 * calls it does not recognise reports success while proving nothing.
 *
 * **Nothing is served out of time.** Responses are scoped to the step that
 * recorded them. A subscription that was `trialing` at step 1 is `past_due` at
 * step 3, and answering step 3 from step 1's recording would assert something
 * that was true once and is false now — with the run still green. When a call
 * is recorded in a different step, the error says which one, because that is
 * almost always the useful fact.
 */
final class FixtureHttpClient implements ClientInterface
{
    private int $stepIndex = 0;

    /** @var list<array{method: string, path: string, step: int}> */
    private array $calls = [];

    public function __construct(
        private readonly Fixture $fixture,
        private readonly Placeholders $placeholders,
    ) {}

    /**
     * Point the client at a step. Called by the runner as the timeline advances;
     * everything served from now on comes from this step's recordings.
     */
    public function atStep(int $index): void
    {
        if (! array_key_exists($index, $this->fixture->steps)) {
            throw NoSuchStep::at($index, count($this->fixture->steps));
        }

        $this->stepIndex = $index;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, string>  $headers
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtoupper((string) $method);
        $path = $this->path((string) $absUrl);

        $this->calls[] = ['method' => $method, 'path' => $path, 'step' => $this->stepIndex];

        if ($hasFile) {
            throw UnmatchedRequest::fileUpload($path);
        }

        foreach ($this->fixture->steps[$this->stepIndex]->apiExchanges as $exchange) {
            if ($this->matches($exchange, $method, $path, $params)) {
                return $this->respond($exchange);
            }
        }

        throw $this->explain($method, $path, $params);
    }

    /** @return list<array{method: string, path: string, step: int}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return array{0: string, 1: int, 2: array<string, string>} */
    private function respond(ApiExchange $exchange): array
    {
        $body = $this->placeholders->resolve($exchange->responseBody);

        return [
            (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $exchange->responseStatus,
            // A request id is included because Stripe always sends one and error
            // messages quote it. Its shape says plainly where it came from.
            ['request-id' => 'req_replay'.count($this->calls)],
        ];
    }

    /** @param array<string, mixed> $params */
    private function matches(ApiExchange $exchange, string $method, string $path, array $params): bool
    {
        if ($exchange->method !== $method) {
            return false;
        }

        if ((string) $this->placeholders->resolveString($exchange->path) !== $path) {
            return false;
        }

        // Subset match on body parameters, compared as strings: Stripe encodes
        // every parameter as one on the wire, so an integer quantity in a
        // fixture and a string quantity from the SDK are the same request.
        foreach ($exchange->matchBody as $key => $expected) {
            $resolved = $this->placeholders->resolve($expected);

            if (! array_key_exists($key, $params) || $this->stringify($params[$key]) !== $this->stringify($resolved)) {
                return false;
            }
        }

        return true;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_array($value) => (string) json_encode($value),
            default => (string) $value,
        };
    }

    /** @param array<string, mixed> $params */
    private function explain(string $method, string $path, array $params): UnmatchedRequest
    {
        $step = $this->fixture->steps[$this->stepIndex];

        foreach ($this->fixture->steps as $index => $candidate) {
            if ($index === $this->stepIndex) {
                continue;
            }

            foreach ($candidate->apiExchanges as $exchange) {
                if ($this->matches($exchange, $method, $path, $params)) {
                    return UnmatchedRequest::recordedInAnotherStep(
                        $method,
                        $path,
                        $this->stepIndex,
                        $step->label,
                        $index,
                        $candidate->label,
                    );
                }
            }
        }

        return UnmatchedRequest::notRecorded(
            $method,
            $path,
            $this->stepIndex,
            $step->label,
            array_map(
                fn (ApiExchange $exchange): string => $exchange->method.' '.$this->placeholders->resolveString($exchange->path),
                $step->apiExchanges
            ),
        );
    }

    /**
     * The SDK hands over an absolute URL built from the configured API base,
     * which varies with Connect and with the Files endpoint. Only the path is
     * matched on, because the base says nothing about billing behaviour.
     */
    private function path(string $absUrl): string
    {
        $path = parse_url($absUrl, PHP_URL_PATH);

        return is_string($path) ? $path : $absUrl;
    }
}
