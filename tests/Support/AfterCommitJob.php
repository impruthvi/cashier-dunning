<?php

namespace Impruthvi\CashierDunning\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class AfterCommitJob implements ShouldQueue
{
    use Queueable;

    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}
