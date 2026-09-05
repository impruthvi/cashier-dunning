<?php

namespace Impruthvi\CashierDunning\Tests\Support;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;

/**
 * A billable model for the package's own tests.
 *
 * Real Cashier, real table, real subscription records. The webhook controller
 * looks a user up by stripe_id and writes subscriptions, so a replay cannot be
 * exercised end to end against a stub.
 */
class User extends Authenticatable
{
    use Billable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    // Every application's User has this and Laravel's base class does not, so
    // a replay that never notified would not be exercising the door most
    // dunning policies actually use.
    use Notifiable;

    protected $guarded = [];

    public $timestamps = true;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
