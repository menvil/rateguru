# CLAUDE.md

Project conventions that apply to every change in this repository.

## Name things by what they do, never by when they were built

Release bookkeeping — phase numbers, slice numbers, PR and commit references —
describes *the schedule that produced* a piece of code. It does not describe the
code, it is meaningless to anyone reading the file a year later, and it ages
badly the moment the plan changes.

It must not appear in:

* identifiers — no `p73Scratch()`, no `PHASE_7_4_ROOT`, no `RGTEST_` sibling
  keyed to a release step;
* file names — no `Phase74ScopeTest.php`;
* comments — no `# Phase 5 slice 5.4: install the services`, no
  `# fixed in PR #1124`.

Name the subject instead. A scope guard for the restore operator surface is
`RestoreOperatorSurfaceScopeTest`. The installer that lays out the host is
`install-bootstrap-host-layout`, and a comment referring to it says so.

Where a comment genuinely needs to point at an earlier decision, describe the
decision — "the wrapper contract established when deploy stopped taking an
`--environment` selector" — not the number of the release that made it.

**Deliberate exceptions**, where the numbering is the subject rather than
bookkeeping about it:

* `infrastructure/ROADMAP.md` — a roadmap of phases; the numbers are its
  content.
* `infrastructure/runbooks/` — operator prose written about the numbered
  bootstrap steps and structured by them.
* `docs/` and the `tests/Feature/Docs/` tests that assert on it — the product's
  own review checklists, numbered independently of infrastructure work.
* `infrastructure/scripts/bootstrap-host` — identifies its children by slice
  number in control flow, state and operator output. Renaming those is a
  behaviour change, not a comment cleanup, and is still outstanding.

`tests/Feature/Architecture/ReleaseBookkeepingTest.php` enforces this on the
operational surface, so the rule is checked rather than merely written down. It
matches plural ranges (`Phases 7-10`) and the legacy `Part E` labels as well as
the singular forms — the same bookkeeping wearing a different spelling.

## Where shared test helpers live

`tests/Pest.php` holds every helper used by more than one test file — scratch
directories, fixtures, stubs, the scope-guard git helpers, and
`executableSourceLines()`. A helper copied into a second test file is a defect:
the copies drift, and a guard that scans for a forbidden construct then means
something different in each file.
