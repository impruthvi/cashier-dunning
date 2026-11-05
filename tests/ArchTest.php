<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('keeps the helpers test files share in tests/Pest.php', function () {
    // Pest makes every function a test file declares global, so one test file
    // can quietly depend on another's helper. Nothing then points from the
    // caller to where the helper lives, and deleting or renaming the test file
    // that defines it breaks a test somewhere else.
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    $shared = [];

    foreach ($files as $path => $source) {
        if (! str_ends_with($path, 'Test.php') || ! preg_match_all('/^function (\w+)\(/m', $source, $declared)) {
            continue;
        }

        foreach ($declared[1] as $function) {
            foreach ($files as $other => $otherSource) {
                if ($other !== $path && preg_match('/(?<![\w$>:])(?<!function )'.$function.'\(/', $otherSource)) {
                    $shared[] = "{$function}() is declared in ".basename($path).' and called from '.basename($other);
                }
            }
        }
    }

    expect($shared)->toBe([]);
});
