# A Fixture object always obeys the fixture rules

`Fixture`'s constructor refuses any content a fixture may not hold: an unrecognised placeholder, no steps, a field the allowlist does not permit, a literal provider id, a missing or malformed match path, or a required event its manifest never received. Decoding keeps only the rules about the document itself: valid JSON, required keys, the format version, and field types. So a `Fixture` that exists can always be saved and loaded. The rules used to run in `FixtureFile` on decode, which let tests and the recorder hold fixtures the loader would refuse, made the recorder round-trip through JSON just to reach them, and gave six consecutive fixes (#63 to #74) one private method to grow.

## Considered Options

- **A separate validator that the loader and recorder call.** Rejected because it keeps the rules a convention: any new path into a `Fixture` can skip them, and tests did. Tests that need a refused fixture assert that building it throws, rather than holding one.
- **A private constructor behind a validating factory.** Rejected because it gives the same guarantee as the constructor with a second entry point to learn.
