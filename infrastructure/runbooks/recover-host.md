# Recover Host

Rebuild one lost target onto a **prepared replacement machine**, from one exact
offsite backup and the exact commit that backup names.

```
OLD HOST LOST / UNUSABLE
        ↓
brand-new replacement VPS
        ↓
Prepare Host                          (runbooks/prepare-host.md)
        ↓
exact OFFSITE backup restored         recover-host --apply
        ↓
exact source_sha from that backup
        ↓
GitHub builds that exact commit       .github/actions/build-rateguru
        ↓
same universal deploy, migrations=false, --recovery-operation
        ↓
recover-host --resume                 runtime resumed
        ↓
health PASS
```

The invariant the whole operation exists to hold:

> **LOST HOST + OFFSITE BACKUP + GIT SOURCE → a new healthy host, the SAME
> backup data, and code built from that backup's own exact `source_sha`.**

---

## 1. Which operation is this?

Four scopes, deliberately never collapsed into one verb. Picking the wrong one
is how a "recovery" destroys a live system.

| The situation | The operation |
| --- | --- |
| A clean machine needs to become a prepared, empty target | **Prepare Host** — `prepare-host` |
| The host is alive and deployed; only its DATA is wrong | **Restore Target Data** — `restore-target` |
| The host is alive and deployed; only its target's own INFRASTRUCTURE drifted | **Repair Target** — `repair-target` |
| The host is GONE, and a prepared replacement must be filled | **Recover Host** — `recover-host` |

`recover-host` refuses a target that has a `current` release at all, and
`restore-target` refuses one that does not. There is no state in which both
apply.

It also performs none of: target provisioning, production activation, DNS
cutover, a migration, a maintenance-mode transition, an emergency backup, a
secret rotation, or any change to the target registry.

---

## 2. What a recovery requires, and what it refuses

Recovery starts on an **already prepared, still EMPTY** target — the
`PRE_DEPLOY` state Prepare Host produces. Every one of these is proven before
the first mutation, all of them are reported together, and each is a refusal
rather than a repair:

1. the target exists and is `lifecycle=active`;
2. `prepare-host --verify --target T` passes;
3. `current` is absent;
4. `previous` is absent;
5. `releases/` holds no release directories;
6. the canonical database exists;
7. the application role exists, can log in and holds no elevated privileges;
8. that database holds **zero** tables in the `public` schema;
9. `shared/.env` is a regular file and `shared/storage` is a real directory;
10. `shared/storage/app` is absent, or a real directory holding nothing but an
    empty `public/`;
11. the target's queue program reports no `RUNNING`, `STARTING` or `STOPPING`
    process (an unobservable group fails closed);
12. there is no restore guard;
13. there is no recovery guard;
14. both guards at once is a **hard failure** with no continuation path.

**Unknown data is never treated as old and unwanted.** A database with tables
in it, or a storage tree with files in it, means this is not the empty
replacement host the operation is for. Nothing is dropped, truncated or deleted
— the run refuses and says exactly what it found.

`recover-host --check --target T --backup <ID>` answers all of this read-only:
no lock file, no workspace, no guard, no staging directory, no database and no
directory of any kind is created.

---

## 3. The backup: offsite only, and exactly one

A recovery models the loss of the old machine, so the local backups on that
machine are gone with it. There is therefore:

* **no `--source` selector** — offsite is the only possibility;
* **no `latest`, no newest, no fallback** — the operator names the exact
  `YYYYMMDD-HHMMSS`;
* **no remote, bucket or path input anywhere** — where a backup lives comes
  from the target registry plus the fixed RateGuru offsite configuration, on the
  server.

Downloading, checksum verification, manifest identity, archive safety, the
`pg_restore` mechanics and the storage extraction are all the existing,
accepted primitives — `fetch-backup`, `verify-backup`, `restore-database`,
`restore-storage`. Recovery implements none of them a second time.

The backup format is unchanged. There is no manifest v3, no recovery-only
field and no duplicate `source_sha`: the commit comes from the
`release.json.source_sha` every backup already carries.

---

## 4. The `.env` rule

Three sources of truth, and they never overlap:

| What | Source of truth |
| --- | --- |
| Infrastructure | the committed repository, applied by **Prepare Host** |
| Secrets / external material | the **GitHub Environment**, consumed by Prepare Host |
| Mutable application DATA + historical code identity | the **backup** |

So the backup's `environment.env` is **never unpacked over a prepared host**.
After the backup is verified, recovery compares it against the prepared
`shared/.env`:

* byte-for-byte, with `cmp`;
* **nothing** about either file reaches the output — no content, no digest, no
  length, no differing offset. A hash of secret material is a fingerprint of
  that material and a length is an oracle. The diagnosis is `MATCH` or
  `MISMATCH`, and nothing else.

A `MISMATCH` **fails closed before any database or storage activation**. The
backup's data belongs to that environment: recovering underneath a different
one would point the restored application at a different database, storage disk,
mail transport or key.

The fix is never to edit the file on the host. Correct the external material the
GitHub Environment supplies to Prepare Host, re-run Prepare Host, and recover
again.

Recovery **never** rewrites `shared/.env`, changes a database password, runs
`ALTER ROLE`, or rotates any secret.

### `server-configuration.tar.gz`

It stays part of a verified backup — a backup missing it is incomplete and is
refused — and it is then **never unpacked**. It is a diagnostic and forensic
snapshot of how the lost host was configured, useful for reading; it is not an
executable source of truth. The committed infrastructure in this repository is,
and Prepare Host is what applies it.

---

## 5. There is no durable artifact archive

RateGuru deliberately keeps **no** durable release-artifact archive: no
dedicated bucket, no artifact-specific credentials, no retention policy, no
backup-to-artifact mapping, no artifact copied into a backup. GitHub Actions
artifacts stay what they are — temporary CI/deployment transport, including the
short-lived one that carries a recovery build from its build job to its deploy
job.

The recovery guarantee is instead:

```
backup.release.json.source_sha
        + the Git repository
        + the lockfiles and build scripts of that historical commit
        + the CURRENT trusted build tooling from develop
        = a new, short-lived recovery artifact
```

A commit is already stored, already immutable and already trusted. A tarball
would have to be made all three, and every one of those is a thing that can
expire, lose a credential, or be tampered with.

---

## 6. The state machine

```
initialized
  → backup-staged                    fetch-backup (offsite)
  → backup-verified                  verify-backup --for-restore
  → environment-matched              cmp backup environment.env ⇄ shared/.env
  → database-staged                  restore-database --stage
  → storage-staged                   restore-storage  --stage
  → recovery-activation-authorized   recovery guard written; runtime held
  → database-activated               restore-database --activate
  → storage-activated                restore-storage  --activate
  → verified                         data verification
  → awaiting-code                    ← --apply ends here
        ⋮                            controlled recovery deployment
  → completed                        ← --resume ends here
```

`recovery-activation-authorized` exists precisely so that recovery never has to
write `emergency-backup-verified`. A live restore takes an emergency backup of
the data it is about to replace, and that phase is the strongest safety claim
in the whole restore path. A recovery replaces a proven-EMPTY prepared state,
so there is nothing to emergency-back-up — and forging that phase would make
the claim mean nothing where it does matter.

### Operation kind

Every operation's own `state.json` records `operation_kind`:

* `target-restore` — a live target restore;
* `host-recovery` — a host recovery.

`restore-database` and `restore-storage` read the kind from that **trusted
persisted state**, never from a command line, and look their allowed phases up
in a table in `restore-common`. A caller able to name the kind could otherwise
drive a live restore's activation with a recovery's weaker gate.

**Legacy compatibility:** an operation state written before this field existed
carries no `operation_kind` and is interpreted as `target-restore` — because
nothing else could have written one.

### Namespaces

```
/home/www/rateguru/run/restores/<target>/<operation>/     a live restore
/home/www/rateguru/run/restores/<target>/restore-guard

/home/www/rateguru/run/recoveries/<target>/<operation>/   a host recovery
/home/www/rateguru/run/recoveries/<target>/recovery-guard

/home/www/rateguru/recoveries/recovery-history.jsonl      root:root 0600
```

Everything under the run root is `root:root 0700`. The history journal carries
identity and state only — never a secret value and never a content hash.

The operation ID is the same strict `YYYYMMDD-HHMMSS-<6 hex>` format Restore
uses, generated on the server from `/dev/urandom`.

---

## 7. The guard, and what it stops

The recovery guard is written **before the first activation, never after**. The
hazard it covers is not a Bash failure — the terminal handler covers those. It
is termination the handler cannot see: between the database activation and the
end of the run the process can be SIGKILLed or OOM-killed, no trap runs, `flock`
is released by the kernel, and the host's own cron is free to act on a target
holding restored data with no code at all.

| Status | Meaning |
| --- | --- |
| `in-progress` | written before the first activation; the data may already be the backup's |
| `awaiting-code` | the data recovery completed; the exact commit is not deployed yet |
| `failed-held` | a failure left data that may be canonical and could not be fully compensated |
| *(removed)* | the recovery completed: code matches data, the target is serving and healthy |

While it exists, **every** ordinary operation on that target fails closed:
`deploy`, `rollback`, `cleanup`, `backup`, `restore-target`, `repair-target`,
and a second `recover-host --apply`. Only three things are allowed:

* `recover-host --inspect`;
* the controlled recovery deployment for that **exact** operation;
* `recover-host --resume`.

The restore guard and the recovery guard are mutually exclusive. Both present
is a hard failure requiring manual recovery — a live restore requires a
deployed target and a recovery requires an empty one, so the two can never
legitimately hold the same target at once.

---

## 8. Running it

All modes are root-only. On the replacement host, `recover-host` runs from the
trusted infrastructure bundle — the same transport Repair Target uses — because
a recovering host has no deployed release to run tooling from, and old
application code must never define how a host is recovered.

```bash
# read-only: is this a prepared, empty replacement host?
recover-host --check   --target staging-main --backup 20260115-023000

# restore the data; ends with the host deliberately NOT serving
recover-host --apply   --target staging-main --backup 20260115-023000

# read-only: what is this host waiting for?
recover-host --inspect --target staging-main --operation 20260115-041233-9be21c

# after the controlled recovery deployment: finish and prove it healthy
recover-host --resume  --target staging-main --operation 20260115-041233-9be21c

# read-only: does this target satisfy the final recovered contract?
recover-host --verify  --target staging-main
```

### End state of a successful `--apply`

```
DATA RESTORED:   YES
CODE DEPLOYED:   NO
TARGET SERVING:  NO
RECOVERY STATUS: AWAITING CODE
```

* recovery guard `status=awaiting-code`;
* the exact required `source_sha` persisted in both the guard and the state;
* the target's queue **STOPPED**;
* the target's scheduler cron entry **HELD** — moved out of `/etc/cron.d` into
  the operation workspace, with its ownership and mode recorded so `--resume`
  puts back exactly what was taken;
* `current` absent, `previous` absent;
* **no maintenance mode** — a `PRE_DEPLOY` target has no `current`, so there is
  no artisan to run and nothing serving to take down;
* the retained pre-recovery database and storage tree still present.

Never: `cron` stopped globally, Supervisor stopped globally, Nginx, PostgreSQL
or Redis touched at all. Only this target's own program group and its own cron
file.

---

## 9. The controlled recovery deployment

The exact commit is installed by the **same universal deploy**, with a
different runtime policy:

```bash
deploy --target staging-main \
       --release <release-id> \
       --artifact <path> \
       --checksum <path>.sha256 \
       --recovery-operation 20260115-041233-9be21c
```

There is no second deploy script. `--restore-operation` and
`--recovery-operation` are mutually exclusive, and `--migrate` is refused with
either — a recovery deployment installs the exact commit the recovered data
belongs to, and a migration is by definition a change to that data's shape.

**The caller never names the commit.** GitHub hands the server an operation ID;
the required `source_sha` is read on the server, out of that operation's own
persisted recovery documents, and the artifact is checked against it there.

Authorization, all of it before a single byte moves:

1. a recovery guard exists;
2. its status is `awaiting-code`;
3. its operation matches the one on the command line;
4. the persisted operation's target matches;
5. the persisted backup matches the guard's;
6. the persisted `operation_kind` is `host-recovery`;
7. the required `source_sha` matches between guard and state, and is a full
   40-character commit;
8. `current` is absent and `previous` is absent;
9. the artifact's `release.json.source_sha` equals that commit — checked against
   the extracted tree, before `current` is switched;
10. everything the ordinary deploy contract already proves about an artifact.

**What the recovery deployment does:** artifact checksum validation, path safety,
extraction, permission normalization, `verify-required-clis`, `release.json`
validation, the immutable release directory, Laravel cache preparation, the
atomic `current` switch, the PHP-FPM reload.

**What it does not do:** migrate, restore data, clear the guard, start the
queue, restore the scheduler, run the ordinary post-deploy runtime transition,
transition the Nightwatch sidecar, or record an observability marker.

Afterwards `current` points at the rebuilt release and **`previous` stays
absent**. A rebuilt host has exactly one release, so there is no earlier one to
roll back to; synthesising a `previous` would arm an ordinary rollback to
"undo" the only code the recovered data belongs to.

---

## 10. `--resume`

Under the target's own deployment lock:

1. the guard must be `awaiting-code`, and the operation identity exact;
2. `current` must now exist;
3. `previous` must still be absent;
4. `current/release.json`'s `source_sha` must equal the required commit;
5. the canonical database is the restored one, reachable with the
   application's own credentials over TCP;
6. its migration row count equals the count the staged database was verified
   with — **this is what proves no migration ran as part of the recovery**;
7. the storage tree is the restored one, with the ownership and modes deploy
   itself creates;
8. only **this** target's scheduler cron entry is put back, byte-for-byte;
9. only **this** target's queue program is started;
10. the health check passes;
11. only then are the retained pre-recovery database and storage tree
    committed (dropped/removed);
12. the guard is cleared, a `completed` history record is appended, and one
    machine-readable result is emitted.

If the health check or the final verification fails **after** code alignment,
the guard is **not** silently cleared. The recovery stays held and diagnosable,
the retained pre-recovery copies are **not** dropped, and the code is not
automatically rolled back to nothing.

---

## 11. Compensation

Compensation is driven by **observed state**, never by how far the run believes
it got. Both halves are attempted regardless of the other's outcome.

```
prepared canonical DB   →  retained pre-recovery DB
staged restored DB      →  canonical DB            (two catalog renames)

prepared app tree       →  retained pre-recovery tree
staged restored app     →  canonical app           (two directory renames)
```

**Compensation complete** — the prepared, empty database and storage tree are
back:

* the target is a prepared `PRE_DEPLOY` host again;
* the scheduler cron entry is put back;
* the queue is left **stopped** (it was not running before — a prepared host has
  no application for its workers, and starting it would invent a runtime state
  the machine never had);
* the recovery guard is cleared;
* status `failed-recovered`;
* no restored data is left canonical.

**Compensation incomplete:**

* status `failed-held`;
* the guard stays, re-labelled;
* the queue stays stopped and the scheduler stays held;
* an explicit `MANUAL RECOVERY REQUIRED` report names the failed step, the
  compensation status and the state file;
* the original error is never masked by a cleanup failure.

The retained pre-recovery database and storage tree are **never** dropped
before the full recovery commits in `--resume`.

### The prepared storage baseline

A freshly prepared host has `shared/storage` but **no** `shared/storage/app`:
the host layout stops at the structural root and leaves Laravel's descendants
to the deployment pipeline. The staged storage swap needs a canonical `app` to
move aside, so recovery creates the empty one a first deployment would have
created, with byte-identical ownership and mode (`runtime:www-data`, `2710`).

It is deliberately **not** removed by compensation: it holds no data, it is the
correct shape for a prepared target, and the guarded storage remover refuses to
touch anything but this tooling's own operation-scoped siblings — which is
exactly the perimeter that keeps a failed recovery from deleting a real tree.

---

## 12. Retrying after a failed historical build

A recovery routinely outlives the workflow run that started it. The historical
commit may no longer build, a runner may die, a run may be cancelled, or the
operator may come back the next day.

None of that is a problem. `--apply` is finished; the host is `awaiting-code`
and stays that way, correctly and indefinitely. To pick it up again:

```bash
recover-host --inspect --target T --operation <ID>
```

It reports the backup, the backup's release, the required `source_sha` and what
`current` is (absent, until the deployment lands), and changes nothing. Fix the
build, run it again for the same commit, deploy with the same
`--recovery-operation`, then `--resume`.

`--apply` is **not** re-runnable for the same host while the guard exists: a
second recovery is refused, by name, pointing at the operation that already
owns the target.

---

## 13. What recovery does not do

* **No DNS cutover.** Nothing here edits a DNS record, a GitHub Environment
  variable, or `DEPLOY_HOST`. The target registry contains logical identity, not
  IP addresses: a TARGET is a stable logical identity, a HOST is a replaceable
  physical machine, and the replacement host is passed explicitly by the caller.
* **No production activation.** `tits-guru` is `lifecycle=planned`, and every
  server-side and action-side path fails closed for it through the same
  active-target gate.
* **No observability marker.** `record-rateguru-deployment` is called by the
  workflow *after* the recovery deployment succeeded, `--resume` succeeded and
  health passed. Observability stays fail-open, and there is no second marker
  implementation.
* **No durable artifact storage.** See §5.

---

## 14. Machine-readable result

Exactly one line per terminal success, identity only — never a secret value, a
digest, a private path or a host detail:

```
RATEGURU_RECOVER_RESULT={"status":"awaiting-code","operation":"…","target":"staging-main",
  "environment":"staging","backup":"20260115-023000","backup_release":"…",
  "required_source_sha":"…","data_restored":true, …}
```

| Mode | `status` |
| --- | --- |
| `--apply` | `awaiting-code` |
| `--inspect` | `awaiting-code` |
| `--resume` | `completed` (plus `current_release`, `source_sha`, `health=pass`, `queue=running`, `scheduler=present`) |
| `--verify` | `verified` |

`--check` emits no result line, exactly as `repair-target --check` does not: it
is a read-only report, not a terminal outcome a workflow branches on.

No result line is ever printed on a failure — a non-zero exit is already
unambiguous, and a result on a failed run would invite a consumer to read state
out of something that did not finish.

---

## 15. The final contract (`--verify`)

Read-only, and independent of any operation:

* the target is `lifecycle=active`;
* no recovery guard, no restore guard;
* `current` is a canonical symlink into this target's own `releases/`, with
  valid release metadata;
* the database is reachable with the application's own credentials, owned by
  the application role, allows connections, has tables and a coherent
  `migrations` table;
* the storage tree is present with the ownership and modes deploy creates;
* the scheduler cron entry is present;
* the queue is **RUNNING**;
* the health check **passes**.

`previous` being absent is **not** a failure. A freshly recovered host has had
exactly one deployment and the recovery deployment leaves no implicit rollback
target on purpose — demanding a deployment history that never existed would make
a correct recovery unverifiable.

---

## See also

* [`prepare-host.md`](prepare-host.md) — producing the prepared machine this
  operation requires
* [`restore-target.md`](restore-target.md) — restoring data onto a live,
  deployed target
* [`repair-target.md`](repair-target.md) — converging one live target's own
  infrastructure
* [`backups.md`](backups.md) — the backup format this reads, and why there is no
  artifact archive
* [`github-restore.md`](github-restore.md) — the operator surface for Restore,
  whose shape the Recover workflows will follow
