# cashier-dunning

## Checks

Run `composer check`. It runs format, analyse, then test, and stops at the first failure, so a syntax error costs one line rather than the whole suite. Call PHPStan through `composer analyse`: it carries the memory limit PHPStan needs to finish.

## Agent skills

### Issue tracker

Issues are tracked in GitHub Issues for impruthvi/cashier-dunning, via the `gh` CLI. See `docs/agents/issue-tracker.md`. A design settled in conversation gets an issue before code, referenced from the commits, so `/code-review` has a spec.

### Triage labels

The five default triage labels, each named after its role: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
