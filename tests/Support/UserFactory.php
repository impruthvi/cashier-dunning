<?php

namespace Impruthvi\CashierDunning\Tests\Support;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Jenny',
            'email' => 'jenny@example.com',
        ];
    }
}
