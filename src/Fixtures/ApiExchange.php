<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;

/**
 * One recorded provider API call and its response, scoped to a step.
 *
 * Matching, done by FixtureHttpClient, is deliberately narrow: method, path,
 * and an explicit set of body parameters. Headers, telemetry, idempotency keys,
 * the API base and Connect account headers are all ignored, because the
 * provider SDK varies them between runs for reasons that have nothing to do
 * with billing behaviour. Matching on that noise would make replay fail for
 * reasons unrelated to the app under test.
 *
 * Exchanges live inside a step rather than in one flat map so that a replay can
 * never serve a response that was only true later in the timeline.
 */
final readonly class ApiExchange
{
    /**
     * @param  array<string, mixed>  $matchBody  parameter name => value; nested values such as `items` and `expand` are arrays
     * @param  array<string, mixed>  $responseBody
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $matchBody,
        public int $responseStatus,
        public array $responseBody,
    ) {}

    /**
     * A missing key takes the format's default: method GET, status 200, empty
     * bodies. The path has no default: a missing one is refused, naming the
     * step. A key with the wrong type is refused.
     *
     * @throws InvalidFixture
     */
    public static function fromDecoded(DecodedObject $exchange, int $step): self
    {
        $match = $exchange->nested('match');
        $response = $exchange->nested('response');
        $method = strtoupper($match->string('method', 'GET'));

        if (! $match->has('path')) {
            throw InvalidFixture::missingMatchPath($step, $method);
        }

        return new self(
            method: $method,
            path: $match->string('path'),
            matchBody: $match->object('body'),
            responseStatus: $response->int('status', 200),
            responseBody: $response->object('body'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'match' => [
                'method' => $this->method,
                'path' => $this->path,
                'body' => Json::object($this->matchBody),
            ],
            'response' => [
                'status' => $this->responseStatus,
                'body' => Json::object($this->responseBody),
            ],
        ];
    }
}
