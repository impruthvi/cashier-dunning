# Changelog

All notable changes to `cashier-dunning` will be documented in this file.

## 0.1.0 - 2026-08-31

First release.

- Replay a recorded Stripe billing lifecycle with no Stripe account, key or network
- Record scenarios against a Stripe test account using test clocks, with the
  recording replayed and verified before any fixture is written
- `EntitlementResolver` contract, resolved once per webhook so entitlement
  changes can be attributed to the event that caused them
- Chaos replay: `--shuffle` and `--duplicate` run the same timeline in orders
  Stripe is entitled to use, comparing side effects rather than end state
- Refuses to record against anything but a Stripe test key
- `billing:doctor` reports what an installation can do, entirely offline
- Two recorded reference scenarios: trial dunning through cancellation and
  recovery, and a mid-period downgrade
