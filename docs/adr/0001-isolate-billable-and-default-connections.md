# Isolate the billable's connection and the default connection, not every connection

A replay rolls back database writes on two connections: the one Cashier's customer model uses, and the application's default connection. When they are the same connection it is wrapped once. Isolating only the billable's connection left the application's writes to the default connection behind whenever the billable lived elsewhere, which is the most common multi-connection setup. Isolating every configured connection would open connections to databases that often don't exist in a test or CI environment, so a replay would fail for reasons unrelated to billing. Writes to any third connection remain outside the isolation guarantee, and the README says so.

## Considered Options

- **Billable's connection only.** The behaviour before this decision. Rejected because it leaked writes to the default connection.
- **Every configured connection.** Rejected because it requires each configured database to be reachable just to run a replay.
