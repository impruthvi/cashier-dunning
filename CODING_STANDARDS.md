# Coding standards

Read during review. These are judgement calls no tool checks; PHPStan, Pint and CI enforce the mechanical rules.

## Behaviour claims

When a diff changes isolation or side-effect behaviour, every statement of it must still be
true: grep README.md, GLOSSARY.md, docs/adr/ and the SimulationEnvironment docblock for the
glossary term (isolation, transaction, side effect), not just the changed hunk.

## Removed assertions

For every test whose body changed, list each removed `expect(...)` or `->and(...)` and what
replaces it: a new assertion, or a fixture match that now proves the same thing. A removed
assertion with no replacement is a finding. Porting a test to a new interface changes how it
reaches its subject, not what it checks.
