# Repair target

Converging ONE live target's own infrastructure back onto what is committed in
this repository, without changing the code it serves or the data it holds:

- `infrastructure/scripts/repair-target` — the server-side orchestrator;
- `infrastructure/scripts/install-bootstrap-host-layout --target` — the
  authoritative owner of identities, memberships and directory entries;
- `infrastructure/scripts/install-bootstrap-services --target` — the
  authoritative owner of the target's service configuration;
- `.github/actions/repair-rateguru-target` — the GitHub transport;
- `.github/workflows/repair-staging.yml` — **Repair staging target**;
- `.github/workflows/repair-production.yml` — **Repair production target**
  (fail-closed until production is activated; see below).

## What Repair Target is, and is not

```text
BROKEN TARGET + HEALTHY HOST + EXISTING DATA/RELEASE
  → target-scoped infrastructure converged
  → same code, same database, same storage, same .env
  → queue running, scheduler present, health PASS
  → TARGET REPAIRED
```

The situation it exists for: the VPS is alive, the host runtime is intact, the
target is registered, `lifecycle=active` and has a real deployed release — and
only the target's **own** infrastructure has drifted. A wrong owner or mode on a
target directory. A lost code-group membership. A damaged Nginx vhost or a
missing enabled symlink. A PHP-FPM pool or Supervisor program that no longer
matches what is committed. An absent scheduler cron. A public-storage ACL that
was wiped. A queue that will not run because of any of those.

It is emphatically **not** the button for anything else:

| the problem | the operation |
|---|---|
| the CODE should change | **Deploy** |
| the code should go back | **Rollback** |
| the DATA is wrong | **Restore target data** |
| the host itself is new or bare | **Prepare host** |
| the whole VPS is gone | **Recover host** |

At the end of a successful run it is **completely correct** that:

- the same release is still under `current`, with the same `source_sha`;
- `previous` still points where it did;
- `shared/.env` is byte-for-byte the file that was already there;
- the database contains exactly what it contained before;
- `shared/storage` has exactly the entries it had before;
- no migration ran.

Those are not aspirations. Every one of them is captured before the first
mutation and asserted afterwards, and any change fails the repair.

## Operator usage

**Repair staging target** takes no inputs at all. A repair has exactly one
meaning, so there is nothing to choose — and offering a choice at the moment a
target is already broken would be offering a decision nobody should be making
under pressure.

**Repair production target** takes one input: a `confirmation` that must be
exactly `REPAIR tits-guru`, judged in a job that holds no GitHub Environment and
no secret, so an unconfirmed request never even becomes an approval request.

On the server, the same three modes every RateGuru installer has:

```bash
repair-target --check  --target staging-main   # strictly read-only; the plan
repair-target --apply  --target staging-main   # converge
repair-target --verify --target staging-main   # the authoritative gate
```

All three require root and all three refuse a target that is not
`lifecycle=active`. `--check` creates nothing — not even a lock file.

## The pipeline, and why the order is what it is

```text
 1. validate the arguments                 cheap, static, no side effects
 2. require root
 3. resolve the target                     lifecycle=active, or refuse
 4. ensure it is a deployed target         cheap pre-lock check
 5. acquire the target's deployment lock   the same lock deploy/rollback take
 6. re-read the identity UNDER the lock    authoritative
 7. gate: interlocks + BOTH contracts      every blocker collected together
 8. capture the immutable baseline
 9. converge the layout   (child --apply --target)
10. re-read the services contract          only to see what is left to do
11. converge the services (child --apply --target)
12. start this target's queue program      only if it is not already running
13. prove nothing else moved               current, previous, release, .env, storage
14. prove the scheduler is present
15. health check
16. RATEGURU_REPAIR_RESULT={...}
```

Step 4 is deliberately **not** authoritative: a deploy, rollback or restore can
finish between it and the lock. It exists so an obviously wrong request fails
immediately instead of queueing behind a running deployment only to be rejected.
Everything it observed is read again at step 6, and it is that second reading the
repair is built on.

Step 7 is the whole gate, and nothing is mutated before it finishes. Converging
five things and then discovering the sixth is unrepairable would leave a target
in a state nobody designed: partially repaired, still broken, and different from
what the operator inspected. So every blocker is collected first and the run
refuses as a whole, having touched nothing.

Reading the services contract that early is only sound because it distinguishes
"unconverged prerequisite" from "unsafe" — see the next section. Step 10 is not a
second gate: every blocker was already ruled out, and the re-read only asks what
converging the layout has left to do. A blocker appearing there would mean the
target changed underneath a held lock, so it fails loudly rather than repairing
past it.

## No second implementation

`repair-target` is orchestration only. Every convergence is delegated to the
installer that already owns that contract, invoked in its target-scoped mode:

```bash
install-bootstrap-host-layout --apply --target TARGET_ID
install-bootstrap-services    --apply --target TARGET_ID
```

There is deliberately no layout or service check in `repair-target` itself, and a
test enumerates the vocabulary — `sites-enabled`, `pool.d`, `setfacl`, `usermod`,
`systemctl`, `nginx -t` — that must never appear in it. The same test also
enumerates `psql`, `pg_dump`, `DB_PASSWORD` and friends: the guarantee "a repair
never touches the database" is structural, not a promise, because there is no
database code path to misuse.

Layout converges before services, because identities and directories must exist
before the services that run inside them are configured.

## What `--target` does to the two installers

Both installers keep exactly one contract. `--target` narrows it to one active
target and turns everything host-wide into a prerequisite they only inspect,
reported with a new `HOST-REQ` status.

| | without `--target` (host mode) | with `--target` |
|---|---|---|
| host roots (`/home/www/rateguru`, `…/bin`, `/var/log/rateguru`, …) | created and re-owned | `HOST-REQ` if wrong; never touched |
| the target's own directories, identities, memberships | converged | converged |
| SSH deploy restriction (`70-rateguru-deploy.conf`) | installed, `sshd -t`, reloaded | not reported, not touched |
| `install-target-operations`, `install-target-perimeter` | converged via their own `--apply` | not reported, not verified, not touched — see below |
| mail capture, and the TLS material its vhosts reference | verified | not reported at all |
| base services (nginx, PHP-FPM, Supervisor, PostgreSQL, Redis) | enabled and started | `HOST-REQ` unless already **enabled and active**; never enabled, never started |
| the target's Nginx site, pool, program, cron, ACL | converged | converged |
| other active targets | converged | not read, not mentioned |

Without `--target`, both installers are exactly the host bootstrap they have
always been. That is the first property the test suite asserts for each of them,
before anything about the new mode.

### What a target repair does not depend on

The SSH deploy restriction is not reported, not verified and not converged in
target mode — and, as of this fix, its destination is not gated either. A
damaged `sshd_config.d` entry is invisible in the target-mode report, so
demanding a well-formed destination for it meant a host problem the operator
was never shown could abort the apply. With layout drift also present that is
worse than an unhelpful error: the layout would already have been converged,
breaking the one rule the gate exists for.

The destination directories that ARE gated are the ones a target repair
actually writes into: Nginx sites-available and sites-enabled, the PHP-FPM pool
directory, Supervisor conf.d and cron.d.

The committed sources are a separate matter and stay gated in both modes. They
are files in the trusted bundle rather than host state, so they cannot be
broken by the host and cannot surprise a run mid-flight — and an incomplete
bundle is a reason to stop everything, because the same corruption may have
reached files the repair does use.

### Why a base service is checked for both halves

The prerequisite asks whether each base service is **enabled and active**, not
merely active — and an apply gate proves it again before the first mutation.

Checking only `is-active` was a boundary in name only: an active-but-disabled
unit reported PASS, and the convergence that follows would then `systemctl
enable` it. That is a host-level mutation on a unit every target shares, made
silently, in the middle of repairing one of them. In target mode the
convergence functions now assert instead of converging, and a unit that is not
already enabled and active fails the run rather than being started.

The apply gate exists on top of the report because a report is a read at one
instant and an apply is a sequence of writes after it: a unit stopped between
the two would otherwise be met by a function that starts it.

### Why the operations and perimeter families are not even verified

`install-target-operations --verify` runs the application health check on a
DEPLOYED target and fails when the site does not answer. A broken Nginx vhost or
PHP-FPM pool is exactly the damage a target repair exists to fix, so gating that
repair on this verify would mean **the site has to be healthy before it can be
made healthy**.

Neither family is a prerequisite of converging one target's service
configuration in the first place — they install the host's operational CLI
bundle and its sudo perimeter — so they belong to the host bootstrap, exactly
like mail capture and the SSH restriction. Whether the target actually serves at
the end is proven directly, by the health check at step 15.

### What counts as repairable

The distinction the whole gate rests on. In target mode:

| condition | verdict |
|---|---|
| a managed directory has the wrong owner, group or mode | **DRIFT** — the installer chowns and chmods that entry, never recursively |
| a managed directory is a symlink or a file | **CONFLICT** — resolving it would mean deleting something |
| this target's layout is not converged yet | **DRIFT** — a named owner converges it, and it is converged first |
| `nginx -t` / `php-fpm -t` / `supervisorctl reread` fails **and** this target's own configuration is drifted | **DRIFT** — the damaged file is what the parser is choking on, and replacing it is the repair |
| the same parser fails while this target's files are byte-identical to what is committed | **CONFLICT** — the cause is elsewhere on the host, and converging this target would change nothing |
| an installer aborted instead of reporting a contract | **CONFLICT** — it exits 1 for that too, and only the `ERROR:` line tells the two apart; an installer that could not run says nothing about whether the target is repairable |

The last row is why a failing parser is not simply ignored: the error may be in
a completely different vhost. And a committed candidate is still validated
before it is installed — a candidate that does not parse is restored, so nothing
is installed on the strength of that attribution.

In host mode every one of these stays exactly what it was.

### External prerequisites

A target-scoped run reports only the material **its own** vhost references. The
shared mail-capture certificates are excluded: they have no per-target
component, they belong to host preparation, and a missing one would otherwise
make a perfectly healthy target unrepairable over secret material this run can
never provide.

What it does report, it reports as `CONFLICT` rather than `MISSING`, and the
difference is load-bearing. `MISSING` means "`--apply` converges this".
`--apply` never converges an external prerequisite — it refuses, because the
material is operator-supplied. An orchestrator reading `MISSING` would treat it
as repairable and proceed, and would discover the refusal only after converging
something else.

## What it refuses, and why each refusal belongs to somebody else

| refusal | why a repair must not decide it |
|---|---|
| a restore guard with `status=held` | the target is intentionally held for controlled code alignment — finish that operation with the Restore workflow, `mode=continue-held` |
| a restore guard with `status=in-progress` | a restore is running or was interrupted, so the live data state is unresolved |
| a restore guard with `status=failed-held` | a restore failed and the live data may already have been replaced; which data state is authoritative is a manual recovery decision |
| maintenance mode with **no** guard | an operator put the target there deliberately, and a repair never runs `artisan up` |
| no canonical deployed release | choosing which code the target should serve is a deployment or recovery decision; "the newest directory in `releases/`" is a guess, and a wrong guess is a silent incident |
| `HOST-REQ` from either installer | the damage is above target level — that is Prepare Host, and a target repair must never quietly become a host bootstrap |
| `CONFLICT` from either installer | resolving it would mean deleting or replacing something to make room, which nothing here ever does |
| a failing health check with nothing to converge | the cause is outside this operation's scope; applying the repair would change nothing, so reporting "repair required" would be a lie |

A guard is never cleared, rewritten or resumed. A maintenance flag is never
removed. An operation workspace is never deleted.

A target that is held is *supposed* to be in maintenance, so that combination is
reported as one problem rather than two.

## What it never does

- never runs `artisan migrate`, under any circumstance, however old the schema
  looks;
- never runs `artisan up`, and has no `--force-up` of any kind;
- never builds, downloads or extracts an artifact;
- never moves `current` or `previous`;
- never writes, replaces or restores `shared/.env`;
- never restores a backup or reads one;
- never installs a package or touches host-global runtime;
- never rewrites the SSH policy, the perimeter, mail capture or the backup cron;
- never provisions a database or a role;
- never runs `supervisorctl start all`, restarts the Supervisor daemon, or
  touches another target's program group;
- never activates a `lifecycle=planned` target.

## The queue

If the target's Supervisor program is already `RUNNING`, nothing happens. If it
is not, **only that program group** is started — `supervisorctl start
<program>:*` — after its configuration has been converged. A queue that cannot
then be proven `RUNNING` fails the repair, because reporting a target as repaired
while its queue is down would be false.

## The immutability proof

Captured before the first mutation, asserted after the last one:

| what | how |
|---|---|
| `current` | the raw symlink target |
| `previous` | the raw symlink target |
| release identity | `release` and `source_sha` from the release's own `release.json` |
| `shared/.env` | device, inode, size, mtime, owner, group, mode |
| `shared/storage` | the top two levels of entries with their type, owner, group and mode, **excluding `logs/`** |

`.env` is fingerprinted rather than hashed on purpose: a hash of secret material
is a fingerprint of that material, and nothing about `.env` content belongs in
this operation's output or logs. Storage is compared structurally rather than by
content: the content is user data, it can be large, and a repair may not create,
delete or re-own anything under it — which is exactly what the structural
comparison detects.

`shared/storage/logs` is excluded, and that is the boundary being drawn
correctly rather than a loophole. `install-bootstrap-services` owns that
directory — the committed PHP-FPM and Supervisor configs write into it — so
repairing its owner or mode is one of the things this operation is **for**.
Including it would make a legitimate repair fail its own immutability proof. It
would also make the proof flaky for a reason unrelated to safety: the
application writes log files continuously, and a new one appearing mid-repair
would read as the repair having touched user data.

The database is proven differently, and more strongly: `repair-target` contains
no database code at all, so there is nothing to misuse. Reachability is proven
end to end by the application's own health endpoint, which exercises Nginx,
PHP-FPM, the environment file and the database without this script learning a
single credential.

## Trusted tooling bundle

The bundle is `infrastructure/` only, built from whatever the calling workflow
checked out, and every caller checks out `develop`.

It is **never** taken from `/home/www/rateguru/<target>/current/infrastructure`.
The release under `current` is the thing being repaired around; it may itself be
damaged or stale, and letting a broken release define what "repaired" means is
precisely the failure this rule removes. `repair-target` likewise resolves the
two installers relative to its own path, so a repair always delegates to the
installers from the same trusted bundle it was run from.

Uploaded to `/root/rateguru-repair-<run>-<attempt>`, `0700 root:root`, and
removed in an `always()` step whatever happened in between.

## Credential separation

Repair uses the privileged **BOOTSTRAP** credential — `vars.BOOTSTRAP_USER`,
`secrets.BOOTSTRAP_SSH_KEY`, `secrets.BOOTSTRAP_KNOWN_HOSTS` — the same one host
preparation uses.

It deliberately does **not** fall back to `DEPLOY_SSH_KEY`. That key is
restricted to the narrow `rateguru-deploy` / `rateguru-rollback` sudo wrappers
and cannot converge infrastructure; widening it so that it could would remove the
separation that keeps an ordinary deployment a small operation. An environment
with no bootstrap credential fails the run with that stated reason.

Strict host key checking is non-negotiable: no TOFU, no relaxed host-key policy,
no password fallback anywhere in the action.

## No secrets, by construction

`.github/actions/repair-rateguru-target` has **no material inputs at all**:

- no `laravel-env`;
- no `deploy-authorized-keys`;
- no `rclone-config`;
- no `basic-auth`;
- no `tls-certificate`, `tls-private-key`, `tls-dhparams`, `nginx-tls-options`;
- no `mail-tls-certificate`, `mail-tls-private-key`.

That absence is the design. A repair converges what this repository commits,
around material the host already holds. When a host is missing an external
prerequisite, the server-side installers report it as a missing prerequisite and
the run fails closed — nothing here can generate, replace or rotate it, because
nothing here ever carries it.

## GitHub Environment contract

### Variables

| name | used for |
|---|---|
| `DEPLOY_HOST` | the physical host currently serving the target |
| `DEPLOY_PORT` | SSH port |
| `BOOTSTRAP_USER` | root, or a passwordless non-interactive sudoer |

### Secrets

| name | used for |
|---|---|
| `BOOTSTRAP_SSH_KEY` | the privileged repair/recovery key |
| `BOOTSTRAP_KNOWN_HOSTS` | the verified host key entry |

Nothing else. If a `PREPARE_*` secret ever appears in a repair workflow, that is
a bug: see the section above.

## The machine-readable result

Exactly one line, on exactly one terminal success:

```text
RATEGURU_REPAIR_RESULT={"status":"completed","target":"staging-main","environment":"staging","changed":true,"current_release":"20260101120000","source_sha":"a1b2c3d","health":"pass","queue":"running","scheduler":"present"}
```

`status` is `completed` for `--apply` and `verified` for `--verify`. `--check`
emits nothing: it is an inspection, not an outcome.

The GitHub action branches on that object and never on prose, so a wording change
in the human report can never become a silent behaviour change. `changed` is
`false` when the target was already correct — a repair that converged nothing
says so rather than claiming credit.

`changed` is read from the `--apply` result and never from the verification:
`--verify` is a fresh read-only invocation, so its own `changed` is `false` by
construction, and publishing that would report "changed: false" after a real
repair. The action also counts the result lines rather than taking the first
match — "exactly one" is a contract, and `grep -m1` would accept two in silence.

## Report vocabulary

| status | meaning |
|---|---|
| `PASS` | satisfied |
| `DRIFT` | target-scoped drift the owning installer can converge |
| `MISSING` | a target-scoped item that is absent |
| `CONFLICT` | drift that is never repaired automatically |
| `HOST-REQ` | a host-level prerequisite — converge the host first |
| `RESTORE-HOLD` | a restore operation owns this target's data right now |
| `MAINTENANCE` | a deliberate maintenance window with no restore guard |

`REPAIR REQUIRED: NO` / `YES` / `BLOCKED` closes the summary. `BLOCKED` means
there is something to fix that this operation is not allowed to fix.

## Concurrency

| workflow | group |
|---|---|
| Repair staging target | `rateguru-staging-deployment` |
| Repair production target | `rateguru-production-release` |

The same domains deploy, rollback, restore and host preparation already use: a
repair converges the infrastructure a release runs inside, so it must never
overlap any of them. `cancel-in-progress` stays `false` — a cancelled repair is
not an undone repair — and the server-side deployment lock remains the ultimate
protection regardless.

## Repair production target

`tits-guru` is `lifecycle=planned` and unprovisioned. The action refuses a
non-active target before it uploads anything, and `repair-target` refuses it
again server-side, so a real run of `repair-production.yml` fails closed today.

It exists now to prove that production will be repaired by exactly the same
mechanism once production is provisioned and activated, rather than by a
production-shaped procedure invented under pressure during an incident. Nothing
in it weakens that gate, nothing in it edits the registry, and the production
GitHub Environment's own protection rules stay authoritative on top of the typed
confirmation.

## Failure behaviour

| what failed | target |
|---|---|
| argument, root or lifecycle validation | untouched; nothing uploaded |
| the pre-mutation gate (any blocker) | untouched; nothing converged |
| a child `--apply` | that child's own transaction restored what it had changed; no further repair step ran |
| the queue could not be proven running | converged, but the run fails — the repair is not complete |
| the immutability proof | the run fails loudly naming exactly what moved; investigate before running anything else |
| the scheduler is still absent | the run fails; the service convergence did not do its job |
| the health check | converged, but the run fails — the target is not serving |
| remote cleanup | reported as a warning; never masks the real outcome |

A failed repair emits **no** `RATEGURU_REPAIR_RESULT` line. The line means the
operation reached a terminal success, never that it was attempted.

## History

A completed repair appends one entry to the target's own
`deployments/history.jsonl`:

```json
{"timestamp":"…","event":"repair","release":"20260101120000","status":"completed","user":"…"}
```

so an operator investigating "why did behaviour change at 14:03" sees the repair
alongside the deployments around it.

## Boundaries

- one target, always: another active target on the same host is not read, not
  mentioned and not touched;
- the host is inspected, never converged;
- the code is never changed;
- the data is never touched;
- the environment file is never written;
- a planned target is never activated.
