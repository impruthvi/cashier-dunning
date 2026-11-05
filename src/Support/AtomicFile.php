<?php

namespace Impruthvi\CashierDunning\Support;

use RuntimeException;
use Throwable;

/** @internal Writes complete local files; concurrent replacements are last-successful-write wins. */
final class AtomicFile
{
    /** Names the tool that left a stray temporary file; the suffix is always `.tmp`. */
    public const TEMPORARY_PREFIX = '.cashier-dunning-';

    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create directory [{$directory}] for [{$path}].");
        }

        // Stay beside the destination: rename must not cross filesystems, and
        // tempnam can silently fall back to the system temporary directory.
        $temporary = $directory.DIRECTORY_SEPARATOR.self::TEMPORARY_PREFIX.bin2hex(random_bytes(16)).'.tmp';
        $stream = @fopen($temporary, 'xb');

        if ($stream === false) {
            throw new RuntimeException("Cannot create temporary file for [{$path}].");
        }

        try {
            if (@fwrite($stream, $contents) !== strlen($contents) || ! @fflush($stream)) {
                throw new RuntimeException("Cannot write complete file for [{$path}].");
            }

            $closed = @fclose($stream);

            if (! $closed) {
                throw new RuntimeException("Cannot close temporary file for [{$path}].");
            }

            if (! @rename($temporary, $path)) {
                throw new RuntimeException("Cannot replace file [{$path}].");
            }
        } finally {
            // Cleanup must not replace the write failure on its way out.
            try {
                if (is_resource($stream)) {
                    @fclose($stream);
                }
            } catch (Throwable) {
            }

            try {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            } catch (Throwable) {
            }
        }
    }
}
