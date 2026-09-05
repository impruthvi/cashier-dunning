<?php

namespace Impruthvi\CashierDunning\Tests\Support;

class BillingUser extends User
{
    protected $connection = 'billing';

    protected $table = 'users';

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}
