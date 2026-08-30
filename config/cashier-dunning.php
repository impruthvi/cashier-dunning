<?php

// config for Impruthvi/CashierDunning
return [

    /*
    |--------------------------------------------------------------------------
    | Entitlement resolver
    |--------------------------------------------------------------------------
    |
    | Answers "what may this billable do right now?" after every replayed
    | webhook, so a simulation can show entitlements flipping across a dunning
    | timeline. This package ships no implementation: what a plan grants is a
    | domain decision, not a billing one.
    |
    | Set this to a class implementing
    | Impruthvi\CashierDunning\Contracts\EntitlementResolver, or leave it null
    | and register a closure from a service provider instead:
    |
    |     CashierDunning::resolveEntitlementsUsing(fn ($billable) => [
    |         'teams' => $billable->subscribed('default'),
    |         'projects' => $billable->subscribed('default') ? 10 : 0,
    |     ]);
    |
    | A closure cannot live in this file, because `config:cache` cannot
    | serialise one. Leaving this null is fine: simulations still run and the
    | entitlement column is simply empty.
    |
    */

    'entitlements' => [
        'resolver' => null,
    ],

];
