# Restoring a target's data

This runbook covers **RESTORE TARGET DATA**: the server is alive, the
infrastructure is intact, the target exists and is `lifecycle=active` — only
its data is wrong, and it has to be put back from a specific backup.

It is delivered by `infrastructure/scripts/restore-target` and the four
primitives it drives (`fetch-backup`, `verify-backup`, `restore-database`,
`restore-storage`), all installed by
[`install-target-operations`](install-target-operations.md).

## RESTORE TARGET DATA is not RECOVER HOST

These are different operations and must never be conflated.

| | RESTORE TARGET DATA (this runbook) | CONTROLLED CODE ALIGNMENT ([`github-restore.md`](github-restore.md)) | REPAIR TARGET ([`repair-target.md`](repair-target.md)) | RECOVER HOST (future) |
|---|---|---|---|---|
| What was lost | data | nothing — the data is already back, the code does not match it | this target's own infrastructure | the whole server |
| Starting point | a live, deployed, healthy-ish target | a target HELD by a completed restore | a live, deployed target on a healthy host | a new, empty VPS |
| Infrastructure | already installed and correct | already installed and correct | **the thing being converged**, for this target only | must be prepared first |
| Secrets / `.env` | already present, never touched | already present, never touched | already present, never touched | must be recovered |
| Server configuration | already present, never touched | already present, never touched | reconverged from what is committed | must be rebuilt |
| Application code | already deployed, never rebuilt | rebuilt in GitHub Actions from the backup's exact `source_sha` and deployed | already deployed, never rebuilt or switched | rebuilt from `release.json.source_sha` |
| Migrations | never | never (the code is being made to match the data) | never | never (the rebuilt code already matches) |
| What is restored | `database.dump` + `storage-app.tar.gz` | nothing — one release is installed | nothing — no backup is read | those, plus everything above |

A restore guard blocks Repair Target in every state, so these two can never run
against the same target at the same time.

A backup contains seven files. A live restore **applies exactly two of them**:

```
database.dump              restored
storage-app.tar.gz         restored
--------------------------------------------
manifest.json              verified, never applied
release.json               verified, never applied
SHA256SUMS                 verified, never applied
environment.env            verified, NEVER applied
server-configuration.tar.gz  verified, NEVER applied
```

`environment.env` and `server-configuration.tar.gz` are required to be present
and checksum-valid — a backup missing either is incomplete and is refused —
and are then never read again. A live restore therefore never changes
`shared/.env`, `rclone.conf`, TLS material, `authorized_keys`, the Nginx
site, the PHP-FPM pool, the Supervisor program, the installed infrastructure
under `/home/www/rateguru/bin`, or the `current` / `previous` release links.
Those belong to Recover Host and Repair Target.

## No migrations. Ever.

The restore path never runs `artisan migrate`, `migrate:fresh`,
`migrate:rollback`, `db:wipe`, a schema reset, or anything equivalent. Backup
data must meet the code it was taken under — identified by
`release.json.source_sha` — and making data meet *different* code is a
deliberate, separate deployment, never a side effect of a restore. This is
enforced by an architectural regression test, not only by convention.

## Selecting a backup

The operator names the exact backup. There is no `latest`, no default, and no
implicit selection anywhere in the destructive path:

```bash
sudo /home/www/rateguru/bin/restore-target \
    --apply \
    --target staging-main \
    --source local \
    --backup 20260115-023000
```

`--source` is `local` or `offsite`. Everything else is resolved from the
target registry plus fixed RateGuru configuration:

```text
local backup    /home/www/rateguru/backups/<backup.namespace>/<backup>
offsite backup  <fixed remote>:<fixed bucket>/rateguru/<backup.namespace>/<backup>
database        target registry .database.name
storage         <application_root>/shared/storage/app
```

No filesystem path, rclone remote, bucket, remote path or database name can be
supplied on the command line. A malformed or calendar-impossible backup
timestamp is refused before any workspace is created.

## The operation, step by step

Every fallible thing that *can* be done while the target is still serving
traffic is done then: the download, the full backup verification, the
`pg_restore` into a temporary database, and the storage extraction — the four
steps that scale with data size and are most likely to fail.

The downtime window is steps 7 to 13. It contains a full emergency backup of
the current state **and its restore test**, which also scale with data size:
on a target of any real size this is **minutes, not seconds**. That is the
deliberate price of an emergency backup consistent with the data being
replaced — it has to be taken after the writers stop, or it captures a moving
target. Plan the maintenance window around the size of the database and the
storage tree, not around the four renames.

```text
 1  validate target            lifecycle=active, deployed, canonical current release
 2  stage backup               fetch-backup: hardlink (local) or download (offsite)
 3  verify backup              checksums, SHA256SUMS entry list, manifest identity,
                               archive safety, release/source_sha recovery identity
 4  stage database             restore-database --stage: a NEW temporary database
 5  stage storage              restore-storage --stage: a NEW sibling tree
 ------------------------------ nothing live has been touched up to here -----------
 6  acquire deployment lock    the EXISTING per-target lock deploy/rollback use
 7  quiesce target             maintenance, this target's queue, this target's cron
 8  emergency backup           the existing `backup`, verified by `restore-test`
 9  activate database          two catalog renames
10  activate storage           two directory renames
11  verify restored data       database, storage, and immutable configuration
12  commit                     drop the pre-restore database and tree
13  code alignment             resume, or hold for a later alignment deploy
```

### Staging survives retention

`backup` applies local retention immediately after creating a backup. When an
operator restores an OLD local backup, the emergency backup in step 8 could
otherwise delete the very source being restored from, mid-operation. Local
staging hardlinks the backup's files into the operation workspace, so the
staged copy stays valid even if the original timestamped directory disappears
entirely.

### The operation workspace

Root-only, `0700`, built from validated components only:

```text
/home/www/rateguru/run/restores/<target>/<operation-id>/
    state.json            0600 root:root, operational identity only, never a secret
    verified-identity.json 0600 root:root, the release/source_sha verification proved
    selected-backup/      0700 root:root, the staged backup
    scheduler-hold/       0700 root:root, this target's cron entry while held
```

The operation ID is generated server-side as `YYYYMMDD-HHMMSS-<6 hex>` — no
slash, no whitespace, no shell metacharacter — so no operator-supplied
filesystem path exists anywhere in the operation.

### Backup verification

Every destructive restore re-verifies its selected backup inside its own
operation, from scratch. An entry in `restore-tests.jsonl` proves a backup was
good when it was tested; it proves nothing about the bytes in this workspace
right now. In order:

1. the seven backup files exist and are plain regular files;
2. `SHA256SUMS` names exactly the six checksummed backup files, once each, and
   nothing else — checked **before** `sha256sum --check` follows a single
   path, so an entry naming `/etc/shadow` or `../../something` never reaches
   it;
3. every checksum matches;
4. the manifest identifies THIS target — project, environment, database, and
   (schema 2) backup namespace and target. The existing schema 1 / schema 2
   contract is reused unchanged, including its acceptance of historical
   manifests that predate the target field;
5. the storage archive would create nothing but directories and regular files,
   all under `app/`.

For a destructive restore only, `--for-restore` adds the **recovery identity**
gate: `release.json` must carry a usable `release` and `source_sha`, and a
manifest `release` other than `unknown` must agree with it. A backup that
cannot say which commit its data belongs to cannot be restored onto a live
target, because nothing could then decide whether the code on that target
matches the data.

A historical backup with an empty `release.json` remains perfectly valid for
`restore-test` and for a non-destructive `verify-backup`. Historical backups
are never rewritten.

### Storage archive safety

`tar -tzf` proves an archive is readable. It proves nothing about what
extraction would create. Before a root process extracts anything:

* no absolute path;
* no `..` (or `.`) path component;
* the archive root is exactly `app` and its descendants;
* no symlink, hard link, device node, FIFO or socket — only directories and
  regular files.

Extraction then runs `--no-same-owner --no-same-permissions`, so no uid, gid
or mode from inside the archive is trusted, and the extracted tree is walked
again afterwards to confirm it holds nothing but directories and regular
files.

Ownership is assigned from the target registry, preserving the exact
`www-data` access model `deploy` and `install-public-storage-access` already
establish:

```text
app             <runtime user>:www-data       2710  Nginx traverses, never lists
app/public/**   <runtime user>:www-data       2750 dirs / 0640 files
everything else <runtime user>:<runtime grp>  2750 dirs / 0640 files
```

The split is deliberate. `app` keeps setgid with the web group exactly as
`deploy` creates it, so a `public` directory Laravel recreates later still
lands in that group. But private application storage goes back into the
target's **own runtime group**: www-data can traverse `app`, so leaving
private content group-`www-data` would hand it to Nginx. Nothing here is ever
wider than what is on disk today — Laravel's own runtime `mkdir` leaves 2755
world-readable directories under `app`.

### Database: a staged swap, never a drop-and-restore

The restore is deliberately **not**:

```bash
dropdb rateguru_staging; createdb rateguru_staging; pg_restore ...   # NO
pg_restore --clean --dbname=rateguru_staging ...                    # NO
```

Both destroy the live database first and only then find out whether the dump
restores. Instead:

* **stage** — a brand new temporary database from `template0`, owned by the
  target's application role, restored `--exit-on-error --single-transaction
  --no-owner --no-privileges` **as the application role over TCP** with the
  credentials from `shared/.env` (read by a six-key reader, never sourced or
  `eval`'d). Restoring as the role is what leaves every object owned the way a
  normal migration would. The staged database is then verified: owner, public
  table count > 0, a readable migrations count, and a real connection as the
  application role. The live database is not read, written, locked or renamed.
* **activate** — block new connections to the live database, terminate the
  sessions it has (scoped to that database only), rename it aside to a
  pre-restore name, rename the staged database into the canonical name,
  re-enable connections.
* **commit** — only after the whole operation succeeded, drop the retained
  pre-restore database.

Every database name is derived from the registry backup namespace plus the
generated operation ID and matched against a closed lowercase-identifier
class. No SQL identifier from a command line ever reaches a statement.

### Storage: a staged swap too

```text
shared/storage/
    app                             the live tree
    .restore-<operation>/app        the staged tree
    .pre-restore-app-<operation>    the previous tree, after activation
```

Both are direct siblings inside the target's own `shared/storage`, which is
what makes the two activation renames same-filesystem and therefore atomic. A
symlinked `shared/storage/app` is refused rather than followed.

### Runtime quiesce — this target only

Never a global service. `nginx`, PostgreSQL, Redis, the Supervisor daemon and
`cron` all keep running for every other project and target on the host.

* **HTTP** — `php artisan down` from the target's ACTUAL current release, as
  the target's runtime user. `shared/.env` is not touched.
* **Queue** — `supervisorctl stop <program>:*` for the registry-declared
  program of this target, with a bounded wait for a confirmed STOPPED state.
  No `reread`, no `update`, no `stop all`. See
  [Reading `supervisorctl status`](#reading-supervisorctl-status) for why a
  non-zero exit code from `status` is not an error.
* **Scheduler** — this target's own `/etc/cron.d/<scheduler.name>` is **moved**
  into the root-only operation workspace for the duration, with its original
  ownership and mode recorded. The global cron daemon is never stopped, and no
  other project's cron file is touched. A `schedule:run` that already started
  is asked to stop with `artisan schedule:interrupt`, and the operation waits
  (bounded) until no scheduler process for that runtime user remains — it
  refuses to swap data underneath a writer.
* **Database writers** — the connection barrier at activation is the last hard
  write barrier.

### Preserving the original runtime state

A restore never assumes everything was RUNNING beforehand. It records:

```text
maintenance_before
queue_running_before
scheduler_present_before
```

and puts back **exactly** that state:

* a queue that was stopped before stays stopped;
* an application already in maintenance stays down — no `artisan up`;
* a cron entry that did not exist is never invented.

### The emergency pre-restore backup

After the selected backup is staged and verified, the database and storage are
staged and verified, and the target is quiesced — but **before the first live
mutation** — a full, ordinary RateGuru backup of the CURRENT state is taken
with the existing `backup` implementation, and verified with the existing
`restore-test`. It is a normal, checksum-complete backup an operator already
knows how to restore from; there is no second backup format.

Exactly one new timestamped backup must appear. Zero, or more than one, is a
hard failure: a restore that cannot name its own safety net does not proceed.
If the backup fails, or its restore test fails, **no live data is mutated** and
the runtime is returned to its original state.

Local only, on purpose: a B2 outage must never make repairing a live target
impossible. The emergency backup is uploaded by the next ordinary backup
cycle.

One deliberate consequence: because this target's `/etc/cron.d` entry is held
aside for the duration of the operation, the emergency backup's
`server-configuration.tar.gz` does not contain it. That snapshot is never
applied by a live restore in any case, and the committed repository remains
the source of truth for that file — holding the scheduler *before* taking the
backup is what keeps a `schedule:run` from writing into the database and the
storage tree while they are being captured.

### Reading `supervisorctl status`

`supervisorctl status <group>:*` reports a non-running group through its **exit
code** as well as its output. From the pinned supervisor 4.2.1 the host runs
(`supervisorctl.py` `LSBStatusExitStatuses`, `states.py` `STOPPED_STATES`):

| Exit code | Meaning |
| --- | --- |
| `0` | every matched process is in a running-ish state |
| `3` | at least one matched process is `STOPPED`, `EXITED`, `FATAL` or `UNKNOWN` |
| `4` | `upcheck()` could not reach supervisord, **or** the name matched nothing (`<group>: ERROR (no such group)`) |

So `0` and `3` are the only codes that mean *supervisord answered, about the
group we asked for*. A correctly stopped queue reports **exit code 3**, and
reading that as a failed observation is what aborted the first live staging
restore: the queue had stopped exactly as asked, `supervisorctl` printed it as
`STOPPED`, and the confirmation loop discarded the answer and waited out its
whole budget.

The exit code alone is not enough, so the output is parsed as well. Every
non-empty line must name a process inside this target's own group and carry one
of Supervisor's own process states — `STOPPED`, `STARTING`, `RUNNING`,
`BACKOFF`, `STOPPING`, `EXITED`, `FATAL`, `UNKNOWN`. Anything else — no output,
another program's line, `ERROR (no such group)`, a truncated line, a state
Supervisor does not have — is an observation failure and **fails closed**: a
group that cannot be seen is never recorded as "not running", because that
would let the quiesce skip the stop and swap live data underneath a worker.

`stderr` is captured rather than discarded, so a genuine Supervisor failure is
reported as itself; the diagnostic is a short, flattened excerpt rather than an
unbounded dump.

Classification is unchanged by any of this: a group counts as **running** only
when every process reports `RUNNING`, and as **fully stopped** only when every
process reports `STOPPED`. A mixed group is neither.

## Locking

Three locks, none of them host-global:

```text
run/restore-target-<namespace>.lock   this operation; two restores in the same
                                      backup namespace serialize
<target>/locks/deployment.lock        the EXISTING per-target deployment lock,
                                      so a restore can never race deploy,
                                      rollback or cleanup
run/backup-<namespace>.lock           the EXISTING backup lock, taken only AFTER
                                      the emergency backup has released it, and
                                      held across activation
```

The ordering matters: `restore-target` must let `backup` acquire and release
the backup lock before taking it itself, otherwise the emergency backup would
deadlock against its own orchestrator.

`install-target-operations --apply` takes the restore lock too, for every
active target's backup namespace, before it validates destinations or touches
anything. Replacing the bundle file by file underneath a running restore could
otherwise hand that restore a mismatched pair of Phase 7.3 scripts halfway
through. An upgrade attempted during a restore fails closed with
`a restore is running for backup namespace <ns>` and installs nothing.

## Compensation

Once activation begins, three things exist: the staged (now live) data, the
retained pre-restore data, and the emergency backup. If **any** activation
step or the final verification fails, the operation compensates:

* storage — the pre-restore tree goes back;
* database — the pre-restore database goes back under the canonical name.

Both are driven by what the catalog and the filesystem actually show, not by
how far the caller believes activation got, so a swap interrupted between its
two renames reverses correctly.

**If compensation is complete**, the runtime is returned to its original
state, the operation is recorded `failed-recovered`, and the command exits
non-zero.

**If compensation is not complete**, the target is deliberately left down:
no `artisan up`, no queue start, no cron entry restored. The operation is
recorded `failed-held`, a loud `MANUAL RECOVERY REQUIRED` block is printed with
the operation ID, the failed step and the emergency backup ID, and the command
exits non-zero. The original failure is never masked by a secondary cleanup
error.

## Code alignment

A restore never switches releases, never builds, and never migrates. After the
data is verified and committed it answers one question:

```text
current/release.json.source_sha  ==  backup release.json.source_sha ?
```

**ALIGNED** — the scheduler, the queue and maintenance mode go back to exactly
their before-state, the target is health-checked, and the operation completes:

```text
RESTORE DATA COMPLETE: YES
CODE ALIGNMENT: ALIGNED
TARGET RESUMED: YES
```

**REQUIRED** — the DATA restore is complete and successful, but the code on
the target does not match the data. The target stays intentionally held:
maintenance ON, queue HELD, scheduler HELD. No migration, no release switch.

```text
RESTORE DATA COMPLETE: YES
CODE ALIGNMENT: REQUIRED
BACKUP RELEASE: ...
BACKUP SOURCE SHA: ...
CURRENT RELEASE: ...
CURRENT SOURCE SHA: ...
TARGET RESUMED: NO
```

The exit code is **0**: the requested DATA restore succeeded. The summary and
the operation state are what say the runtime is intentionally held.

### The restore guard

```text
/home/www/rateguru/run/restores/<target>/restore-guard
```

Root-only (everything under the run root is `root:root 0700`, deliberately not
the target's own `locks/`, which the deployment user can write to). It contains
identity and nothing else — operation, target, the `source_sha` the data is
waiting for, the status, a timestamp. No secret.

It exists because an ordinary backup takes its **data** from disk and its
**release identity** from `current/release.json`. Those normally describe the
same thing, but not during or after a restore: the data is the backup's while
`current` still serves something else. A backup created in that window asserts
that this data belongs to a commit it does not, breaking the invariant every
recovery depends on:

```text
backup release.json.source_sha  ->  the code the restored data belongs to
```

**It is written before the first live mutation, not after the restore ends.**
That ordering is the whole point. A failure is handled — the terminal handler
compensates and reports. Termination is not: a `SIGKILL` or an OOM kill between
the database activation and the end of the run executes no trap, and the kernel
releases every `flock` the operation held. The host's own backup cron is not
the Laravel scheduler, is not held by this operation, and is then free to run:

```text
30 2 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main
```

A guard written only on completion is absent in exactly that case. Written
first, it survives the kill and the cron refuses.

Writing it is a **hard prerequisite** of the activation, not a best effort. If
it cannot be written — read-only filesystem, full disk, permissions — the
operation fails there, with no live data touched and the runtime put back.

| status | meaning |
| --- | --- |
| `in-progress` | written before the first activation; the data may be the backup's |
| `held` | the data restore completed, the code does not match yet |
| `failed-held` | a failure left data that may have been replaced |
| *(removed)* | the data provably matches the code again |

While it exists:

* `backup` refuses, before it creates a temporary root, a snapshot or a
  retention pass — and `backup-cycle` inherits that by starting with `backup`;
* `restore-target --apply` refuses to start a second restore on the target,
  before an operation ID or a workspace is even created;
* the refusal names the operation, the status and the `source_sha` being
  waited for.

`offsite-backup` is not blocked: it uploads a backup that already exists and was
already correctly labelled.

It is removed only by an outcome that proves the data matches the code again:
an aligned restore that passed its health check, a successful `--resume` after
its data verification, or a compensation that put the live data back. A
`failed-held` operation's guard is removed by the operator as part of the manual
recovery the output demands; the path is printed in the `MANUAL RECOVERY
REQUIRED` block.

## Resuming a held target

**Do not use the normal deployment path.** A normal deploy is not
restore-aware: it health-checks the target and transitions the queue, both of
which fight the hold this operation is deliberately keeping.

Since Phase 7.4 there is exactly one supported way to finish a held operation,
and it exists in two forms of the same thing:

* **from GitHub** — run the Restore workflow for this target with
  `mode=continue-held` and the operation ID. It inspects the hold, builds the
  exact required commit, deploys it as a controlled alignment that keeps the
  target held throughout, and then resumes. See
  [`github-restore.md`](github-restore.md);
* **by hand** — once an artifact built from the exact required commit is on
  the host, `deploy --target <target> --release <id> --artifact <path>
  --checksum <path> --restore-operation <operation>`. That is the ORDINARY
  deploy in alignment mode: same mechanics, no migrations, no health check, no
  queue transition, no scheduler restoration and no guard removal. It then
  needs `--resume` below, exactly as the workflow does.

Neither form lets the operator choose the commit: the required `source_sha` is
read on the server, from the restore guard and the operation's own
`state.json`, and the artifact is checked against it before `current` moves.

Once `current` genuinely serves the required `source_sha`:

```bash
sudo /home/www/rateguru/bin/restore-target \
    --resume \
    --target staging-main \
    --operation 20260115-024512-3f9ac1
```

`--resume` is not a new restore. It applies only to an operation whose data
restore completed and whose runtime is held, and it fails closed unless:

* the operation's state names this target and status `held`;
* the backup's `source_sha` is known;
* `current` is a canonical release under the target's own `releases/`;
* `current/release.json.source_sha` now equals the backup's `source_sha`.

If the SHAs still disagree, nothing is changed and the target stays held.

On success it restores the scheduler only if it was present before, starts the
queue only if it was running before, brings the application up only if this
restore was the thing that took it down, health-checks the target, re-verifies
the restored data, records the outcome, and removes the operation workspace.
A failing health check does not count as a successful resume: the target is
put back into as held a state as possible and the command exits non-zero.

### Reading a held operation without changing it

`--inspect` is the read-only answer to "what is this operation waiting for?".
It changes no database, no storage tree, no queue, no scheduler entry, no
maintenance flag, no `current` link and no guard:

```bash
sudo /home/www/rateguru/bin/restore-target \
    --inspect \
    --target staging-main \
    --operation 20260115-024512-3f9ac1
```

It fails closed unless the operation exists, belongs to this target, is held
for code alignment, and its guard and state agree on a full 40-character
required commit — and unless the target is STILL held: maintenance on, its
queue provably fully STOPPED, and no scheduler cron entry present. That last
check is also what the controlled alignment deploy relies on, rather than
re-deriving the same Supervisor reading a second time.

Two hold statuses are deliberately NOT continuable this way. `in-progress`
means a restore is still running or was killed mid-flight; `failed-held` means
a restore failed with live data that may already have been replaced. Neither is
finished by deploying a commit, and `--inspect` says so and refuses.

### The machine-readable result

Every terminal success also prints exactly one line for machines:

```text
RATEGURU_RESTORE_RESULT={"status":"held","operation_id":"...","target":"...", ...}
```

Ten fields — `status`, `operation_id`, `target`, `backup`, `backup_release`,
`backup_source_sha`, `required_source_sha`, `current_source_sha`,
`code_alignment`, `runtime_resumed` — carrying operational identity and no
secret. `status` is `completed` (restored and serving), `held` (data restored,
code alignment required) or `resumed`. The GitHub restore action branches on
this object and never on the human summary above it.

## Journal and history

Every terminal operation appends one compact JSON object to:

```text
/home/www/rateguru/restores/restore-history.jsonl   root:root 0600
```

```json
{
  "status": "completed",
  "operation_id": "20260115-024512-3f9ac1",
  "started_at": "...", "completed_at": "...",
  "target": "staging-main", "environment": "staging",
  "backup_namespace": "staging", "source": "local", "backup": "20260115-023000",
  "backup_release": "...", "backup_source_sha": "...",
  "current_release_before": "...", "current_source_sha_before": "...",
  "emergency_backup": "20260115-024430",
  "code_alignment": "ALIGNED", "runtime_resumed": "yes",
  "failed_step": null, "compensation_status": "not-required"
}
```

`status` is one of `completed`, `held`, `resumed`, `failed`,
`failed-recovered`, `failed-held`. Nothing secret is ever written: no `.env`
content, no database password, no token, no key material, no rclone
credential, and no file content hash that could act as a fingerprint for
secret material. An immutable release ID and a Git commit are public build
identity and are recorded in full.

## Non-destructive verification

`fetch-backup` and `verify-backup` are usable on their own, and change nothing
about the live target:

```bash
# Stage and verify an offsite backup without restoring anything.
sudo /home/www/rateguru/bin/fetch-backup \
    --target staging-main --source offsite --backup 20260115-023000
# -> prints the generated operation ID

sudo /home/www/rateguru/bin/verify-backup \
    --target staging-main --operation <operation-id> --for-restore

# Remove the fetch-only workspace when finished.
sudo /home/www/rateguru/bin/fetch-backup \
    --discard --target staging-main --operation <operation-id>
```

`--discard` refuses to touch a workspace belonging to a `restore-target`
operation (one carrying `state.json`).

## Failure modes

| Symptom | Meaning | Live data | What to do |
|---|---|---|---|
| `lifecycle=planned` | the target is not active (`tits-guru`) | untouched | nothing to do; production activation is Phase 8 |
| `invalid backup ID` | the timestamp is malformed or impossible | untouched | name the exact backup directory |
| `does not exist in namespace` | wrong backup or wrong target | untouched | list the namespace and pick again |
| `failed SHA-256 verification` | the staged backup is corrupt | untouched | use another backup; investigate the corrupt one |
| `carries no source_sha` | a historical backup with no recoverable identity | untouched | this backup cannot be restored onto a live target |
| `contains a symbolic link` / `device node` / … | the storage archive is unsafe to extract | untouched | do not restore from it; investigate |
| `another deployment operation is already running` | a deploy, rollback or cleanup holds the lock | untouched | wait for it and retry |
| `no live data was mutated` | the emergency backup or its verification failed | untouched | fix the backup path first, then retry |
| `failed-recovered` | activation or verification failed; everything was undone | restored to its pre-restore state | read the journal, then retry or investigate |
| `failed-held` + `MANUAL RECOVERY REQUIRED` | compensation could not complete | inconsistent | do not start the target; use the emergency backup named in the output |
| `backup`, `deploy`, `rollback` or `cleanup` refuses: `… is held after restore operation …` | a restore guard is in place for this target | restored, or mid-restore | controlled code alignment is required — do **not** use the normal deployment path. Run the Restore workflow with `mode=continue-held`, or `deploy --restore-operation <operation>` by hand, then `--resume` |
| `WARNING: one or more writers could NOT be proven stopped` | a queue worker, the scheduler or maintenance mode could not be confirmed | unknown, possibly still being written | treat the data as live; find and stop the writer named in the `ERROR` lines before touching anything |
| the process was killed mid-activation | no handler ran, so nothing compensated | possibly half-swapped | read `state.json` in the operation workspace for the exact phase, then either finish or reverse it with the `restore-database` / `restore-storage` steps, or restore from the emergency backup |
| `CODE ALIGNMENT: REQUIRED` | the data is restored, the code does not match | restored | controlled code alignment is required — do **not** use the normal deployment path. Run the Restore workflow with `mode=continue-held` and the operation ID, or `deploy --restore-operation <operation>` by hand, then `--resume`. The target stays held throughout either way |

## Expected server commands

```bash
# What is available locally, and offsite.
sudo ls -1 /home/www/rateguru/backups/staging
sudo rclone --config /root/.config/rclone/rclone.conf \
    lsf rateguru-b2:rateguru-database-backups/rateguru/staging --dirs-only

# Restore a specific backup.
sudo /home/www/rateguru/bin/restore-target \
    --apply --target staging-main --source local --backup 20260115-023000

# Read the journal.
sudo tail -5 /home/www/rateguru/restores/restore-history.jsonl | jq .

# Read a held operation without changing anything.
sudo /home/www/rateguru/bin/restore-target \
    --inspect --target staging-main --operation 20260115-024512-3f9ac1

# Resume a held target after the aligning deployment.
sudo /home/www/rateguru/bin/restore-target \
    --resume --target staging-main --operation 20260115-024512-3f9ac1
```

The same three operations, through the restricted deploy credential and the
generic sudo wrapper — which is what the GitHub restore action calls, and the
only remote route into a restore:

```bash
sudo -n /usr/local/sbin/rateguru-restore \
    --apply --target staging-main --source offsite --backup 20260115-023000
sudo -n /usr/local/sbin/rateguru-restore \
    --inspect --target staging-main --operation 20260115-024512-3f9ac1
sudo -n /usr/local/sbin/rateguru-restore \
    --resume --target staging-main --operation 20260115-024512-3f9ac1
```
