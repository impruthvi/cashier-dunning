# Cashier Dunning

Records real Stripe billing lifecycles and replays them through a Laravel Cashier application, to prove the application grants and revokes access the way the recording says it should.

## Language

**Fixture**:
A recording of one billing lifecycle: the webhooks Stripe sent and the API responses it gave, grouped into steps along a timeline.
_Avoid_: Recording (as a noun for the file), snapshot

**API exchange**:
One call the application made to Stripe during a step and the response Stripe gave, as recorded in a fixture. A replay answers a call only from an exchange in the current step whose method, path and chosen request parameters match.
_Avoid_: Stub, mock, cassette

**Placeholder**:
A token in a fixture that stands for a provider id, such as `{{sub_1}}`, or a point on the timeline, such as `{{t+14d}}`. Minted once when the fixture is recorded, and resolved to concrete values afresh for each replay.
_Avoid_: Template variable, normalised id

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

**Allowlist**:
The fields a fixture may contain. Anything else is dropped when a fixture is recorded, and refused when a fixture is loaded. Fixtures are committed to a public repository, which is why the allowlist exists.
_Avoid_: Redaction list, denylist, publish (for saving a file)

**Manifest**:
The event types a scenario promises its recording will contain. Required events must appear and optional ones may. A recording missing a required event is incomplete, and is refused when loaded.
_Avoid_: Expected events, checklist

**Quarantine**:
The diagnostics retained from a refused recording: metadata about the events Stripe sent before it was refused, and why it was refused. A recording is refused when it is incomplete, cannot be turned into a fixture, does not replay clean, or replays clean but cannot be saved. A quarantine is kept where it can never be loaded as a fixture. If it cannot be saved, the refusal reports that failure instead of claiming a quarantine exists.
_Avoid_: Failed fixture, draft, partial recording
