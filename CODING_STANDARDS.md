# Coding standards

Read during review. These are judgement calls no tool checks; PHPStan, Pint and CI enforce the mechanical rules.

## Behaviour claims

When a diff changes isolation or side-effect behaviour, every statement of it must still be
true: grep README.md, GLOSSARY.md, docs/adr/ and the SimulationEnvironment docblock for the
glossary term (isolation, transaction, side effect), not just the changed hunk.

When a diff changes how a recording is refused, written or quarantined, every statement of
it must still be true: grep README.md, GLOSSARY.md and the Recorder, RecordAndVerify, Quarantine and
RecordingRefused docblocks for quarantine, refused, manifest and
what an artifact holds.
A claim about what a quarantine contains needs a test that writes one and finds it there.

When a diff touches how a fixture is loaded or validated, every statement of what loading
refuses must still be true: grep README.md and GLOSSARY.md for refuses and allowlist, not just
the changed hunk. Every docblock that says a fixture is refused, rejected or verified needs a test that loads such a fixture and sees it refused
for that reason: the expected message names what was refused (the field, the event, the
token), not text other refusals share, such as `Step 0`. A refusal claim with no test behind
it, or only a test that any refusal would pass, is a finding.

## Static analysis

The PHPStan baseline stays empty: fix an error by narrowing the type where the value enters, such as a fixture's decoded JSON, never by a baseline entry, an ignore or a cast (#65).

## Paths

A path the code under test produces is compared against `nativePath()` from `tests/Pest.php`,
not a string joined with `'/'`: the package joins with `DIRECTORY_SEPARATOR`, so a slash-built
expectation passes on Linux and fails only in the Windows CI job.

## Removed assertions

For every test whose body changed, list each removed `expect(...)` or `->and(...)` and what
replaces it: a new assertion, or a fixture match that now proves the same thing. A removed
assertion with no replacement is a finding, and so is a replacement that cannot fail where
the removed one would: `toContain((string) $x)` passes when `$x` is null. So is an expected
value read off the subject under test: once `file === encode($fixture)` is asserted,
`read($path)->scenario` toBe `$fixture->scenario` compares the fixture with itself. Expect the
literal the test set up. Porting a test to a new interface changes how it reaches its subject,
not what it checks.
