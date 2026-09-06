# Restoring a target from GitHub

This runbook covers the **operator layer** over Phase 7.3's server-side
restore: the two workflows an operator actually clicks, what each one does,
and — the part that matters most — what happens when the backup's data belongs
to a commit the target is not serving.

It is delivered by:

| | |
|---|---|
| `.github/workflows/restore-staging.yml` | **Restore staging**, fixed to `staging-main` |
| `.github/workflows/restore-production.yml` | **Restore production**, fixed to `tits-guru` |
| `.github/actions/restore-rateguru` | the one GitHub-side restore transport |
| `infrastructure/config/wrappers/rateguru-restore` | the one server-side restore perimeter |
| `infrastructure/scripts/restore-target` | all the restore logic ([`restore-target.md`](restore-target.md)) |

## Three operations, never conflated

| | RESTORE TARGET DATA | CONTROLLED CODE ALIGNMENT | RECOVER HOST |
|---|---|---|---|
| Phase | 7.3 | 7.4 (this runbook) | 7.6 / 7.7, future |
| What is wrong | the data | the code does not match the restored data | the server is gone |
| Host | alive | alive | a new, empty VPS |
| What runs | `restore-target --apply` | `deploy --restore-operation` then `restore-target --resume` | Prepare Host, then everything |
| What changes | database + storage | one release, atomically | everything |
| Migrations | never | never | never |
| Ends with | the target serving, or HELD | the target serving | the target serving |

**Restore** puts the data back. **Alignment** makes the code meet data that is
already back. **Recover** rebuilds a host that no longer exists. This runbook
is the first two; the third is a later phase and nothing here begins it.

## There is no target dropdown

Two workflows, two names. An operator picks the one whose title names the
environment, and the target is a structural property of that file — never an
input, under any name. The mechanisms underneath are shared exactly:
`restore-rateguru`, `build-rateguru`, `deploy-rateguru` and
`record-rateguru-deployment` are the same four actions in both.

## There is no commit input either

The operator chooses a **backup**, never a commit. The required commit flows:

```text
the chosen backup
  -> its verified release.json.source_sha        (checked by verify-backup)
  -> the restore operation's state.json + guard  (written by restore-target)
  -> RATEGURU_RESTORE_RESULT.required_source_sha (read by restore-rateguru)
  -> the exact application checkout ref          (built by build-rateguru)
  -> re-checked on the SERVER before current moves
```

Nothing in that chain is typed by a person, and the last step is the one that
matters: `deploy` reads the required commit out of the server's own restore
documents and refuses any artifact built from anything else. GitHub tells the
server *which operation*; the server decides *which commit*.

## Prerequisites

Each GitHub Environment (`staging`, `production`) needs the variables the
deploy and rollback workflows already use, plus one:

| variable | value |
|---|---|
| `RESTORE_WRAPPER` | `/usr/local/sbin/rateguru-restore` |

No new secret. A restore of an existing target reuses `DEPLOY_HOST`,
`DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` and `DEPLOY_KNOWN_HOSTS` — the
same restricted credential that deploys to it.

**No B2 credential is ever handed to GitHub.** An offsite restore is downloaded
*on the server*, by `fetch-backup`, through the host's own root-side
`rclone.conf`. The workflow only says `source: offsite`.

The wrapper itself and its sudo grant are installed by the existing
[`install-target-perimeter`](target-perimeter.md), so a clean-host bootstrap
gets them with no manual step:

```bash
sudo infrastructure/scripts/install-target-perimeter --verify
```

## Running it

### Inputs

| input | meaning |
|---|---|
| `mode` | `start` (a new restore) or `continue-held` (finish one already held) |
| `source` | `offsite` (default) or `local` |
| `backup` | exact `YYYYMMDD-HHMMSS`; required for `start`, forbidden otherwise |
| `operation` | the restore operation ID; required for `continue-held`, forbidden otherwise |
| `confirmation` | **production only** — must be exactly `RESTORE tits-guru` |

`offsite` is the default because the operator recovery path should reach for
the copy that survives the host. `local` stays a full option, including when
B2 is the thing that is unavailable.

There is no `latest`. A destructive restore names what it applies.

### CASE A — the backup matches the deployed code

```text
Restore staging  (mode=start, source=offsite, backup=20260115-023000)

  validate
    -> restore-target --apply
         restores the data, finds current already serves the backup's commit,
         resumes the target itself, health-checks it
    -> RATEGURU_RESTORE_RESULT status=completed  code_alignment=ALIGNED
    -> DONE
```

No build, no deployment, and deliberately **no deployment marker** in Sentry
or Nightwatch: nothing was deployed, and inventing a marker would record a
release that was never built.

### CASE B — the backup belongs to older code

```text
Restore staging  (mode=start, source=offsite, backup=20260115-023000)

  validate
    -> restore-target --apply
         restores the DATA, sees current serves a different commit,
         HOLDS the target: maintenance ON, queue STOPPED, scheduler HELD,
         restore guard on disk
    -> status=held  code_alignment=REQUIRED  required_source_sha=<A>
    -> checkout <A> exactly, build it            (no environment, no secrets)
    -> deploy --restore-operation <operation>    (no migrations, hold kept)
    -> restore-target --resume                   (the ONLY thing that resumes)
    -> record the deployment marker
    -> DONE
```

The target is **held for the whole middle of that chain** and comes back only
when `--resume` has verified the restored data and passed a health check.

## What the alignment deploy is, and is not

It is the ordinary `infrastructure/scripts/deploy`, in a mode. There is no
`restore-deploy`, no `historical-deploy` and no `recovery-deploy` — duplicating
the deployment algorithm is how the copy quietly stops matching the original.

**Same mechanics:** artifact and checksum verification, unsafe-path rejection,
disk space, extraction, permission normalization, the shared `.env` and storage
links, the public storage link, `verify-required-clis`, Laravel cache
preparation, the immutable release finalization, the atomic `current` switch,
the PHP-FPM reload, and the active-release verification.

**Different runtime policy.** After the switch it does *none* of this:

```text
NO artisan migrate            NO artisan up
NO HTTP health check          NO queue start / restart / transition
NO scheduler restoration      NO removal of the restore guard
```

`restore-target --resume` owns every one of those.

**`previous` is cleared, not repointed.** A controlled alignment is a code/data
reconciliation, not a release promotion: the release that *was* current is
exactly the code the restored data does not match, so making it the target's
"one step back" would arm an ordinary rollback to silently undo the alignment
once the hold ends. Clearing it makes `rollback --previous` fail closed until a
normal deployment establishes a real previous; `rollback --release <id>` stays
available to an operator who names a release deliberately. Every release
directory is left on disk.

**The history says what happened.** `restore-alignment-started` and
`restore-alignment-finished` (status `held`), never a
`deployment-finished`/`success` for a target that is still in maintenance.

## What the server checks before it accepts an alignment

`deploy --restore-operation` refuses, before a single byte moves, unless all of
this holds:

1. a restore guard exists for this target, is a regular root-owned file and is
   a JSON object;
2. `guard.target` is this target and `guard.operation` is the named operation;
3. `guard.status` is exactly `held` — `in-progress` and `failed-held` are
   refused, because neither is finished by deploying a commit;
4. `guard.required_source_sha` is a full 40-character commit;
5. the operation's `state.json` exists, names this target, is `status: held`,
   `phase: committed` and `code_alignment: required`, and records a backup;
6. `state.backup_source_sha` equals `guard.required_source_sha`;
7. `restore-target --inspect` independently agrees: same operation, same
   target, same commit, `status=held`, `code_alignment=REQUIRED`,
   `runtime_resumed=no` — and, inside that, the target really is still held:
   maintenance ON, its queue provably fully STOPPED, no scheduler cron entry;
8. `previous` is a symlink or absent;
9. after extraction and before `current` moves, the artifact's
   `release.json.source_sha` equals the required commit and its `release`
   equals the release being deployed.

`--migrate` together with `--restore-operation` is refused during argument
parsing, in the action *and* on the server.

## Trust boundary of the historical build

The commit being built is an arbitrary point in this repository's past, chosen
by a backup rather than by a person. So the build job:

* holds **no GitHub Environment**, and therefore no deploy SSH key, no Sentry
  token and no B2 credential;
* has `permissions: contents: read` and nothing else;
* checks out **operational tooling from `develop`** at the workspace root, and
  the **application at the exact required commit** into `application/`;
* runs `./.github/actions/build-rateguru` from the *tooling* checkout, pointed
  at the application checkout with `expected-source-sha` set.

The build action is never loaded from the historical commit. Historical
application source must never decide what the operational tooling does.

If that commit no longer builds — a dependency that has vanished, a registry
that no longer serves it — **the run fails and the target stays held**. There
is no fallback to a branch, to `HEAD`, or to a nearby commit: every one of
those would install code the data does not belong to.

### The release ID of an alignment build

The version prefix comes from the **backup's own release**:

```text
backup_release  v1.4.2-20260801-120000-deadbee
release-version v1.4.2
new release     v1.4.2-<today>-<hhmmss>-deadbee
```

It is a **new build of the same commit**, not a claim to be byte-identical to a
release that already exists — the commit is the identity that matters, and it
is carried exactly. `release.json` additionally records `restore_operation`,
`restore_backup` and `restore_alignment: true`; no core field is redefined. If
`backup_release` is not a canonical `vMAJOR.MINOR.PATCH-...`, the run fails
closed rather than inventing a version.

## Resuming a run that failed: `mode=continue-held`

A held target stays held indefinitely and safely. If the build failed, the
runner died, the run was cancelled, or you simply came back the next day:

```text
Restore staging  (mode=continue-held, operation=20260115-024512-3f9ac1)

  validate
    -> restore-target --inspect        (read-only; changes nothing)
    -> current already serves the required commit?
         yes -> resume
         no  -> build <A> -> deploy --restore-operation -> resume
```

`continue-held` **refuses a backup input**: a held target already has restored
data, from a backup its own state names, and starting a second restore on top
of it would replace that data while an alignment for the old commit was in
flight.

Find the operation ID in the failed run's summary, in the server's journal
(`/home/www/rateguru/restores/restore-history.jsonl`), or in the guard itself
(`/home/www/rateguru/run/restores/<target>/restore-guard`).

## While a target is held, everything else refuses

```text
backup    refuses    (it would label this data with the wrong commit)
deploy    refuses    (it would move current further from the data)
rollback  refuses    (it would move current to a third commit, and resume the target)
cleanup   refuses    (it could delete the very release the alignment needs)
```

Each refusal happens **before** its first mutation and names the operation, the
status and the commit being waited for. The only things that end a hold are the
controlled alignment deploy followed by `restore-target --resume`.

`offsite-backup` is deliberately not blocked: it uploads a backup that already
exists and was already correctly labelled.

## Failure behaviour

| what failed | workflow | target |
|---|---|---|
| `--apply` before any mutation | fails | untouched; the server's own recovery ran |
| `--apply` produced a held mismatch | **continues** to alignment — this is not a failure | HELD |
| historical checkout or build | fails | stays HELD, guard intact, backup/deploy/rollback/cleanup still refused |
| controlled deploy before the `current` switch | fails | stays HELD; the staged release is discarded |
| controlled deploy after the `current` switch | fails | prior `current` restored if possible, PHP-FPM reloaded — but stays HELD, with no queue, maintenance or guard change |
| `current` could not be safely restored | fails **loudly** | stays HELD; do not resume until an operator has inspected the target root |
| `--resume` | fails | `restore-target`'s existing `failed-held` semantics own the safety |
| observability marker | warning only | unaffected — the recovery itself succeeded |

After a failure that leaves the target held with `guard.status = held`, the way
back in is always the same: **Restore staging / Restore production,
`mode=continue-held`, with the operation ID.** Every row above except the last
one leaves exactly that state.

`failed-held` is NOT one of those, and `continue-held` will refuse it — see
condition 3 in the list above. That status means a restore failed while the live
data may already have been replaced, so which data state is authoritative is a
decision only an operator can make. It ends in the `MANUAL RECOVERY REQUIRED`
block `restore-target` prints, with the emergency backup named there, and the
guard is cleared by hand as the last step of that recovery. Neither a repair nor
a deployment is a way out of it: `repair-target` refuses a target under any
restore guard, and `deploy`, `rollback`, `backup` and `cleanup` refuse it too.

## Production

`restore-production.yml` is the same policy at a different identity, with one
extra gate: a `confirmation` input that must be exactly

```text
RESTORE tits-guru
```

checked in a job that holds **no** GitHub Environment, so an unconfirmed run
ends before the production environment's approval is even requested and long
before any SSH connection is made. That is *in addition to* the environment's
own protection rules, not instead of them.

`tits-guru` remains `lifecycle=planned` and unprovisioned. The workflow exists
for architectural parity and fails closed on a real run — the wrapper and
`restore-target` both reject a planned target before touching anything. Nothing
in Phase 7.4 activates production.

## Concurrency

The **whole workflow** holds one group, not just its first job:

| workflow | group |
|---|---|
| Restore staging | `rateguru-staging-deployment` |
| Restore production | `rateguru-production-release` |

with `cancel-in-progress: false`. On a code mismatch the chain restore → build
→ alignment deploy → resume is *one logical mutation of one target*: a deploy
or rollback slipping in between two of its jobs would move `current` away from
the commit the restored data belongs to, while the target is held and cannot
object. The server-side deployment lock, the restore/backup locks and the
restore guard remain the ultimate integrity protection regardless.

## Related runbooks

* [`restore-target.md`](restore-target.md) — the server-side restore itself,
  its locks, its compensation, its guard and its journal
* [`deployment-targets.md`](deployment-targets.md) — the target registry
* [`target-perimeter.md`](target-perimeter.md) — the sudo wrappers and the
  sudoers rule, including `rateguru-restore`
* [`backups.md`](backups.md) — what a backup contains and how it is verified
