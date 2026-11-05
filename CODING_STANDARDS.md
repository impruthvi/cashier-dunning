# Coding standards

Read during review. These are judgement calls no tool checks; PHPStan, Pint and CI enforce the mechanical rules.

## Behaviour claims

When a diff changes isolation or side-effect behaviour, every statement of it must still be
true: grep README.md, GLOSSARY.md, docs/adr/ and the SimulationEnvironment docblock for the
glossary term (isolation, transaction, side effect), not just the changed hunk.

When a diff changes how a recording is refused, written or quarantined, every statement of
it must still be true: grep README.md, GLOSSARY.md and the Recorder, RecordAndVerify and
RecordingRefused and QuarantineArtifact docblocks for quarantine, refused, manifest and
what an artifact holds.
A claim about what a quarantine contains needs a test that writes one and finds it there.

When a diff touches how a fixture is loaded or validated, every docblock that says a fixture
is refused, rejected or verified needs a test that loads such a fixture and sees it refused
for that reason: the expected message names what was refused (the field, the event, the
token), not text other refusals share, such as `Step 0`. A refusal claim with no test behind
it, or only a test that any refusal would pass, is a finding.

## Paths

A path the code under test produces is compared against `nativePath()` from `tests/Pest.php`,
not a string joined with `'/'`: the package joins with `DIRECTORY_SEPARATOR`, so a slash-built
expectation passes on Linux and fails only in the Windows CI job.

## Removed assertions

For every test whose body changed, list each removed `expect(...)` or `->and(...)` and what
replaces it: a new assertion, or a fixture match that now proves the same thing. A removed
assertion with no replacement is a finding, and so is a replacement that cannot fail where
the removed one would: `toContain((string) $x)` passes when `$x` is null. Porting a test to a
new interface changes how it reaches its subject, not what it checks.
