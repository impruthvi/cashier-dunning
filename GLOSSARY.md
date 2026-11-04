# Cashier Dunning

Records real Stripe billing lifecycles and replays them through a Laravel Cashier application, to prove the application grants and revokes access the way the recording says it should.

## Language

**Fixture**:
A recording of one billing lifecycle: the webhooks Stripe sent and the API responses it gave, grouped into steps along a timeline.
_Avoid_: Recording (as a noun for the file), snapshot

**Replay**:
One run of a fixture through the application's real webhook route, in recorded order or a chaos ordering.
_Avoid_: Simulation run, test run

**Simulation environment**:
The sealed world a replay runs inside, and the only thing that builds it and puts it back.
_Avoid_: Sandbox, harness

**Isolation**:
The guarantee that a replay leaves no trace. Database writes on the billable's connection and the default connection are rolled back, no outbound delivery reaches anyone, and the application's real Stripe key can't be read.
_Avoid_: Safety, rollback (rollback is only one part)

**Billable**:
The application's customer record that the fixture's webhooks are addressed to.
_Avoid_: User, customer (Stripe's customer is a different thing)

**Side effect**:
Something the application tried to do to the outside world during a replay, such as a mail, notification or queued job. Side effects are recorded and counted, and their delivery is refused.
_Avoid_: Outbound, delivery
