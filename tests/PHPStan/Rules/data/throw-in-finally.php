<?php

namespace ThrowInFinallyFixture;

use RuntimeException;

function thrownDuringCleanup(): void
{
    try {
        work();
    } finally {
        throw new RuntimeException('cleanup failed');
    }
}

function guardedThrowDuringCleanup(?RuntimeException $failure): void
{
    try {
        work();
    } finally {
        if ($failure !== null) {
            throw $failure;
        }
    }
}

function thrownAsAnExpression(?RuntimeException $failure): void
{
    try {
        work();
    } finally {
        $failure === null ?: throw $failure;
    }
}

function thrownAfterCleanup(bool $failed): void
{
    try {
        work();
    } finally {
        $failed = $failed || cleanUp();
    }

    if ($failed) {
        throw new RuntimeException('raised after the try statement');
    }
}

function thrownFromTryAndCatch(): void
{
    try {
        throw new RuntimeException('in try');
    } catch (RuntimeException $e) {
        throw $e;
    } finally {
        cleanUp();
    }
}

function closureDefinedDuringCleanup(): void
{
    try {
        work();
    } finally {
        register(fn () => throw new RuntimeException('runs later'));
        register(function (): void {
            throw new RuntimeException('runs later');
        });
    }
}

function work(): void {}

function cleanUp(): bool
{
    return false;
}

function register(callable $callback): void {}
