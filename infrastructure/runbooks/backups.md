# RateGuru backup operations

## Three retention concepts, never conflated

Three independent retention policies exist, each with its own semantics:

1. **Release retention** (`cleanup`, registry field `release_retention`) —
   how many deployed release directories stay on disk. The `current` and
   `previous` releases (and pinned releases) are *always* protected,
   regardless of the number. `staging-main` keeps 5. This is deployment
   housekeeping, not a backup policy — deleting an old release directory
   loses nothing that a backup is expected to preserve.
2. **Local backup retention** (`backup`, registry fields
   `local_retention_days` + `minimum_retained_backups`) — an age window over
   the timestamped directories under
   `/home/www/rateguru/backups/<namespace>/`, backstopped by a minimum
   count: the newest `minimum_retained_backups` backups are always kept, no
   matter how old. `staging-main` keeps 5 days, minimum 2.
3. **Offsite (B2) backup retention** (`offsite-retention`, registry fields
   `offsite_retention_days` + `minimum_retained_backups`) — the same
   age-window-plus-minimum-count model over the remote namespace, with a
   longer window than local. `staging-main` keeps 14 days, minimum 2.

The minimum count exists so that age-based deletion alone can never leave a
namespace with fewer than two backups — a paused cron, a long outage, or a
mis-set window shrinks the *age* coverage, never the *count* below two. The
registry validator refuses any target whose `minimum_retained_backups` is
not a strict JSON integer of at least 2.

A backup contains the database dump, storage, `.env`, release metadata and
the server-configuration snapshot — **not** the built application artifact
itself, and there is deliberately no durable artifact archive anywhere in
RateGuru: no dedicated bucket, no artifact-specific credentials, no artifact
retention policy and no backup-to-artifact mapping. GitHub Actions artifacts
stay what they are, temporary CI/deployment transport.

That is not a gap waiting to be filled. Recovery rebuilds the application from
`release.json.source_sha` — the exact commit every backup already carries —
through the same one build implementation an ordinary release uses, with the
current trusted build tooling. A commit is already stored, already immutable
and already trusted; a tarball would have to be made all three.
`release.json.release` remains the useful historical identity of what was
running. See [`recover-host.md`](recover-host.md).

## Local backup and restore test

`backup` and `restore-test` accept exactly one selector, `--target TARGET_ID`:

```bash
sudo /home/www/rateguru/bin/backup --target staging-main
sudo /home/www/rateguru/bin/restore-test --target staging-main
```

Both scripts require root unconditionally, as the first action of every
invocation — before argument parsing even runs — matching `deploy`/
`rollback`'s exact contract.

```text
backup namespace = staging
backup root      = /home/www/rateguru/backups/staging
lock (backup)     = /home/www/rateguru/run/backup-staging.lock
lock (restore-test) = /home/www/rateguru/run/restore-test-staging.lock
```

`require_active_target` runs immediately after root authorization — before
the backup root, lock, database binary, `rclone`, or any filesystem work — so
a planned target (`tits-guru`) is rejected before anything is touched.

### Local retention: deterministic, count-aware, creation-gated

Local retention runs only after the new backup has been fully created and
atomically moved into its final timestamped directory — a failed creation
never triggers retention. Only direct-child directories named
`YYYYMMDD-HHMMSS` participate; auxiliary entries (a `database`, `manifests`
or `uploads` directory, any non-timestamp name, or a timestamp-named plain
file) are never touched. Names are round-trip-validated as real calendar
timestamps — a shape-matching but calendar-invalid name (month 13, day 99)
is skipped outright, never deleted and never counted toward the protected
minimum; offsite retention applies the identical validation to remote
names.

The listing is sorted newest-first by name, and each entry's age derives
from the timestamp in its *name*, never from filesystem mtime — the decision
is deterministic for a given listing. The newest `minimum_retained_backups`
entries are always kept (`KEEP minimum: ...`), regardless of age; entries
beyond the minimum are kept while inside `local_retention_days`
(`KEEP recent: ...`) and deleted once past it (`DELETE expired: ...`). The
just-created backup is the newest entry and therefore always inside the
protected minimum.

### Manifest: schema 2, backward compatible with schema 1

Every backup carries a `manifest_schema_version: 2` manifest naming its
`target`, `environment` and `backup_namespace`, alongside the pre-existing
`project`, `database`, `release`, `postgres_version` and `php_version`
fields, plus the leftover `selector` field described below. `restore-test`
validates whichever schema it finds on the backup it selects:

- always required: `project == rateguru`, `environment` matching the target's
  environment class, `database` matching the resolved database;
- additionally required for schema 2 only: `backup_namespace` matching the
  resolved namespace, and a non-null manifest `target` matching the target ID
  given;
- a schema 1 backup (produced before schema 2 existed, with none of the newer
  fields) remains fully restorable, as long as the schema-1-only fields above
  still match.

`manifest_schema_version` is recognized strictly, by its JSON type: absent or
JSON `null` is schema 1; a JSON *number* equal to `2` is schema 2. Any other
value — `3`, `0`, the JSON *string* `"2"`, an array, an object, a boolean — is
rejected outright, before the temporary database is created, with
`unsupported backup manifest schema_version: ...` naming the offending value.

Manifest validation always completes — like checksum and storage-archive
validation — before the temporary restore-test database is created.

## Target-aware offsite backup path

`offsite-backup`, `offsite-retention` and `offsite-restore-test` accept the
same `--target TARGET_ID` selector:

```bash
sudo /home/www/rateguru/bin/offsite-backup --target staging-main

sudo /home/www/rateguru/bin/offsite-retention --target staging-main
sudo /home/www/rateguru/bin/offsite-retention --target staging-main --apply

sudo /home/www/rateguru/bin/offsite-restore-test --target staging-main
```

All three scripts require root unconditionally, as the first action of every
invocation. `require_active_target` runs immediately after root
authorization — before any `rclone` config check, remote listing, local
backup root, lock, temp directory, or database work — so a planned target
(`tits-guru`) is rejected before anything is touched, including before any
Backblaze B2 access.

### Remote namespace

```text
backup namespace = staging
remote root       = rateguru-b2:rateguru-database-backups/rateguru/staging
lock (offsite-backup)      = /home/www/rateguru/run/offsite-backup-staging.lock
lock (offsite-retention)   = /home/www/rateguru/run/offsite-retention-staging.lock
lock (offsite-restore-test) = /home/www/rateguru/run/offsite-restore-test-staging.lock
```

Every remote root and lock filename is built from the target's own
`backup_namespace` (`target_backup_namespace`).

### Retention: side-effect-free dry-run, target-specific retention days

`offsite-retention`'s default mode is a dry-run that lists remote backups and
prints `WOULD DELETE` lines. It never calls `rclone purge` in dry-run mode —
this is a genuine code-path guarantee, not a reliance on `rclone`'s own
dry-run flag. Only `--apply` performs real deletion, and even then: the
candidate set is listed once for a preview, the lock is acquired, the remote
is listed *again* under the lock, and only backups present in that
re-listing are purged — so a backup uploaded between the preview and the
locked listing is never deleted.

The retention window is read from the target's own `offsite_retention_days`
field in the registry (`target_offsite_backup_retention`), and the minimum
count from `minimum_retained_backups` (`target_minimum_retained_backups`).
Every run protects the newest `minimum_retained_backups` remote backups
unconditionally (`KEEP minimum: ...`), regardless of age; backups beyond the
minimum are kept while inside the window (`KEEP recent: ...`) and become
candidates only once past it. Dry-run and `--apply` share the single
candidate computation — dry-run prints `WOULD DELETE`, apply prints `DELETE`
from its own locked, authoritative recomputation.

### Manifest validation reuses the same strict schema contract

`offsite-backup` and `offsite-restore-test` validate the manifest of the
backup they select using the identical strict, type-based
`manifest_schema_version` classification as local `restore-test` (absent or
JSON `null` → schema 1; JSON number `2` → schema 2; anything else, including
the JSON string `"2"`, is rejected outright). Schema 2 additionally requires
`backup_namespace` to match the resolved namespace, and a non-null manifest
`target` to match the target ID given. `offsite-backup` validates the
manifest of the local backup it is about to upload before any Backblaze B2
access check; `offsite-restore-test` validates the manifest of the remote
backup it downloads before creating the temporary restore database.

## Target-aware backup cycle

`backup-cycle` accepts the same `--target TARGET_ID` selector as every other
backup-path script:

```bash
sudo /home/www/rateguru/bin/backup-cycle --target staging-main
```

`backup-cycle` requires root unconditionally, as the first action of every
invocation. `require_active_target` runs immediately after root
authorization — before the cycle lock, the history file, or any child
command — so a planned target (`tits-guru`) is rejected before anything is
touched.

```text
backup namespace = staging
cycle lock       = /home/www/rateguru/run/backup-cycle-staging.lock
history file     = /home/www/rateguru/backups/backup-cycles.jsonl
```

### The five-step pipeline, strictly in order, fail-fast

```text
1. backup
2. restore-test
3. offsite-backup
4. offsite-retention --apply
5. offsite-restore-test
```

Every step is invoked with the exact same target the cycle itself received,
and its real stdout/stderr is never suppressed. Each step only runs if the
previous one exited `0`; the first failure stops the cycle immediately, and
the cycle's own exit code is the failing child's exit code, unmodified.

### Retention safety ordering

`offsite-retention --apply` — the one step in this pipeline that actually
deletes old remote backups — only ever runs after local backup, local
restore-test, and the offsite upload have all already succeeded. If any of
those three fails, retention is never reached, so old B2 backups are never
purged on the strength of a local backup or upload that did not actually
happen. `offsite-restore-test` always runs after retention: if retention
succeeds but the offsite restore-test then fails, the cycle is still recorded
as failed — the retention deletion itself is **not** rolled back; this is a
deliberate, documented limitation, not an oversight.

**This does not delete local backups.** Local retention (pruning old
timestamped directories under `/home/www/rateguru/backups/<namespace>/`,
minimum-count protected — see "Local retention" above) is `backup`'s own
behaviour — `backup-cycle` does not add any local retention of its own.

### Cycle history: one compact JSON record per cycle

Every started cycle appends exactly one line to
`/home/www/rateguru/backups/backup-cycles.jsonl` (created `0600`, inside a
`0700` root-owned directory), generated entirely through `jq -cn`:

```json
{"status":"ok","started_at":"2026-07-29T15:00:00Z","completed_at":"2026-07-29T15:02:00Z","selector":"target","target":"staging-main","environment":"staging","backup_namespace":"staging","completed_steps":["backup","restore-test","offsite-backup","offsite-retention","offsite-restore-test"],"failed_step":null}
```

A failed cycle additionally carries `exit_code` (the failing child's own exit
code) and a `completed_steps` array that only lists the steps that actually
finished before the failure:

```json
{"status":"failed","started_at":"...","completed_at":"...","selector":"target","target":"staging-main","environment":"staging","backup_namespace":"staging","completed_steps":["backup","restore-test"],"failed_step":"offsite-backup","exit_code":1}
```

`selector` is always `"target"` now — a leftover field name from when a
record could be written by either of two interfaces, kept as-is so existing
JSONL history readers do not need to change to parse post-migration records.

A `lifecycle=planned` rejection or a cycle-lock contention writes **no**
history record at all — history only ever records a cycle that genuinely
started (i.e., past the lock). A history write failure after every step
already succeeded still turns the cycle into a reported failure; a history
write failure on the failure path is logged but never replaces the original
child's exit code.

### Cron calls backup-cycle by target

`/etc/cron.d/rateguru-backups` (installed from
`infrastructure/config/cron/rateguru-backups`) calls all three operational
commands — the nightly `backup-cycle`, and the weekly `restore-test` /
`offsite-restore-test` — with `--target staging-main`. Schedules and log
paths are unchanged from before the registry-based model existed; see
[`target-perimeter.md`](target-perimeter.md) for the full perimeter this
belongs to.

### Recovering from an immutable partial upload

If an interrupted upload leaves a stale remote object whose content differs
from the local backup, later `--immutable` retries will fail. Inspect the
timestamped remote backup directory, confirm that it is the incomplete upload,
and perform manual cleanup of that remote directory before rerunning
`offsite-backup`. Never remove a remote directory that has already passed the
offsite restore test.
