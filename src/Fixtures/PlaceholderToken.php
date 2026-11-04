<?php

namespace Impruthvi\CashierDunning\Fixtures;

/**
 * One placeholder found in a fixture: a provider id, a point on the timeline,
 * or text that claims to be a placeholder and is not one. See {@see Placeholder}.
 *
 * @internal
 */
final readonly class PlaceholderToken
{
    private function __construct(
        /** The token as it appears in the fixture, braces included. */
        public string $text,
        public ?string $kind = null,
        public ?int $number = null,
        public ?Duration $offset = null,
    ) {}

    public static function id(string $text, string $kind, int $number): self
    {
        return new self($text, kind: $kind, number: $number);
    }

    public static function offset(string $text, Duration $offset): self
    {
        return new self($text, offset: $offset);
    }

    public static function unrecognised(string $text): self
    {
        return new self($text);
    }

    public function isRecognised(): bool
    {
        return $this->kind !== null || $this->offset !== null;
    }
}
