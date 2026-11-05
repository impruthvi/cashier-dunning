<?php

namespace Impruthvi\CashierDunning\Fixtures;

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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            method: strtoupper((string) ($data['match']['method'] ?? 'GET')),
            path: (string) ($data['match']['path'] ?? ''),
            matchBody: (array) ($data['match']['body'] ?? []),
            responseStatus: (int) ($data['response']['status'] ?? 200),
            responseBody: (array) ($data['response']['body'] ?? []),
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
