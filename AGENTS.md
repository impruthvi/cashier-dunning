# cashier-dunning

## Checks

Run `composer check`. It runs format, analyse, then test, and stops at the first failure, so a syntax error costs one line rather than the whole suite. Call PHPStan through `composer analyse`: it carries the memory limit PHPStan needs to finish.

## Agent skills

### Issue tracker

Read an issue with `gh issue view <n> --json number,title,body,labels,comments`; every other operation is in `docs/agents/issue-tracker.md`. A design settled in conversation gets an issue before code, referenced from the commits, so `/code-review` has a spec.

### Implement

The seams an issue's acceptance criteria name are the pre-agreed seams for `tdd`; ask only when they name none. Starting on `main`, create a branch named after the issue before the first commit, such as `fixtures/stripe-error-response`.

### Triage labels

The five default triage labels, each named after its role: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
