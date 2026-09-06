# Prepare host

Phase 7.2B. Turning a clean supported VPS into infrastructure ready to host
one RateGuru target, as a single resumable operation:

- `infrastructure/scripts/prepare-host` — the server-side orchestrator;
- `infrastructure/scripts/install-target-prerequisites` — external material;
- `infrastructure/scripts/install-target-database` — the target's PostgreSQL
  role and database;
- `.github/actions/prepare-rateguru-host` — the GitHub transport;
- `.github/workflows/prepare-staging-host.yml` — **Prepare staging host**;
- `.github/workflows/prepare-production-host.yml` — **Prepare production
  host** (fail-closed until Phase 8; see below).

## What Prepare Host is, and is not

```text
clean supported Ubuntu VPS
  → RateGuru infrastructure installed
  → target prerequisites present
  → target infrastructure configured
  → empty database/role prerequisites ready
  → verification PASS
  → PREPARED FOR TARGET
```

At the end of a successful run it is **completely correct** that the host has:

- no restored database contents;
- no restored media;
- no historical release;
- **no current application release at all**.

The host is infrastructure-ready, not application-recovered. `prepare-host
--verify` says so explicitly:

```text
TARGET PREPARED: YES
APPLICATION DEPLOYED: NOT REQUIRED — a prepared target legitimately has no release yet
```

Prepare Host is therefore **not**:

| It is not | That is |
| --- | --- |
| a deploy | `Deploy to staging` / `Release to production` |
| a rollback | `Rollback staging` / `Rollback production` |
| a data restore | Phase 7.3 — Restore Target Data |
| a drift repair | Phase 7.5 — Repair Target |
| replacement-server recovery | Recover Host — [`recover-host.md`](recover-host.md) |
| production activation | Phase 8 |

## Operator usage

Run the **Prepare staging host** workflow from the Actions tab. It takes no
inputs: the environment is `staging`, the target is `staging-main`, and the
tooling always comes from `develop`. There is nothing to select, and
deliberately no application ref, no mode selector and no target dropdown.

On the host itself, the same operation is available directly from a checkout
of the trusted bootstrap bundle:

```bash
sudo infrastructure/scripts/prepare-host --check  --target staging-main
sudo infrastructure/scripts/prepare-host --apply  --target staging-main --material-dir /root/rateguru-prepare-<id>/material
sudo infrastructure/scripts/prepare-host --verify --target staging-main
```

`--check` is read-only and dependency-aware; `--apply` is convergent;
`--verify` is the gate. There is no `--force`, no `--skip` and no
`--continue-on-error`.

## The pipeline, and why the order is what it is

```text
5.2 install-bootstrap-runtime                      base packages
  → lifecycle gate (target must be active)
  → 7.2 install-target-prerequisites --scope host    Nginx-referenced material
  → 5.x bootstrap-host                               5.2 → 5.3 → 5.4 → preflight
  → 7.2 install-target-prerequisites --scope target  .env, deploy key, rclone
  → 7.2 install-target-database                      PostgreSQL role/database
  → final prepare-host verification
```

The gate sits where it does for one reason: reading a JSON registry needs `jq`,
and a clean Ubuntu image has none. The runtime slice is the only step allowed
to precede it, and it installs packages from a committed list — no RateGuru
identity, no target directory, no secret, nothing target-specific. On any host
that already has `jq` — which is every host that has ever been prepared — the
gate runs first, before anything at all.

Each step sits at the only point in the sequence where it can succeed:

- **Runtime first**, because everything after it needs tools a clean Ubuntu
  image does not carry — `jq` above all, without which the target registry
  cannot be read. It is one of Phase 5's own authoritative installers,
  invoked rather than reimplemented, and it installs packages only: no
  RateGuru identity, no target directory, no secret. `bootstrap-host`
  re-verifies the same slice below and SKIPs it.
- **Host-scope material next**, because `install-bootstrap-services` (slice
  5.4) fails closed when an Nginx vhost names a file that does not exist.
  This is the one manual step the clean-host bootstrap runbook still asks an
  operator to perform between `--check` and `--apply`; automating it is what
  makes preparation a single operation.
- **`bootstrap-host` then runs its whole pipeline**, unchanged and
  undecomposed. Prepare Host orchestrates it; it never absorbs it.
- **Target-scope material after**, because `shared/.env` and the deploy
  user's `authorized_keys` live inside directories and accounts slice 5.3
  creates.
- **The database last**, because it needs PostgreSQL *and* the credentials
  inside `shared/.env`.

`prepare-host` owns ordering, per-slice status, fail-fast behaviour and the
readiness aggregation — nothing else. Every child remains authoritative for
its own contract.

## Convergence and idempotency

Per slice: run the child's own verification; if it passes, **SKIP**;
otherwise run the child's own `--apply` and require its verification
afterwards. Nothing is reinstalled "to make sure", because making sure is
what overwrites a live `.env` and rotates a working credential.

On an already prepared host — including one that is already deployed and
serving traffic — a second run is SKIPs throughout. It must not, and does
not:

- reinstall anything blindly;
- destroy or modify database contents;
- rotate a secret or replace a TLS key;
- overwrite `.env`, `authorized_keys`, `htpasswd` or `rclone.conf`;
- recreate a user destructively;
- break the deployed `current` release, delete releases or touch storage;
- run a migration or restore a backup.

A failed run stops at the failing slice, leaves earlier slices converged and
resumes there on the next run.

## Minimum clean-host contract

Intentionally small:

- a supported clean Ubuntu release (the Phase 5.1 preflight's hard gate);
- SSH connectivity;
- the bootstrap/recovery credential;
- root, or passwordless non-interactive `sudo`;
- the base shell/core utilities needed to receive and run bootstrap material.

The host is **not** required to have Git, Composer, Node, npm, application
source, RateGuru wrappers, RateGuru system users or the RateGuru directory
layout. Those are the bootstrap system's job. `jq` is not required either: it
arrives with the runtime slice, which is precisely why that slice runs first
and why it is the only step allowed to precede the lifecycle gate — it
installs packages from a committed list and provisions nothing
target-specific.

## Trusted bootstrap bundle

A clean host has no RateGuru repository, and no manual `git clone` is needed.
GitHub Actions is the control plane:

```text
checkout trusted develop
  → tar the infrastructure/ directory (nothing else)
  → scp to the bootstrap user's 0700 staging directory
  → move into /root/rateguru-prepare-<run>, root:root, go-rwx
  → run prepare-host --apply, then prepare-host --verify
  → remove the remote directory (on success AND on failure)
  → remove the local key, known_hosts and material
```

The bundle contains only `infrastructure/` — every bootstrap and preparation
script resolves every file it needs beneath that one directory. No
application artifact is built or uploaded, and no application ref is ever
involved.

## Credential separation

Two credentials, two lifecycles, and neither substitutes for the other:

| Credential | Used by | Reaches |
| --- | --- | --- |
| `DEPLOY_SSH_KEY` | deploy, rollback, deployment markers | the restricted deploy user and the `rateguru-*` sudo wrappers only |
| `BOOTSTRAP_SSH_KEY` | Prepare Host (and later Recover Host) | root, or passwordless `sudo` |

Ordinary deployment never uses the bootstrap credential, and Prepare Host
refuses to fall back to the deployment credential — that key is restricted to
the deploy wrappers and cannot bootstrap a host.

Every bootstrap SSH and SCP invocation uses `BatchMode=yes`,
`IdentitiesOnly=yes`, `StrictHostKeyChecking=yes` and an explicit
`known_hosts`. There is no TOFU, no `StrictHostKeyChecking=no` and no
password fallback anywhere in the path.

## GitHub Environment contract

Configured per environment (`staging`, `production`). **No secret value
belongs in this document or in any other repository file.**

### Variables

| Variable | Meaning |
| --- | --- |
| `DEPLOY_HOST` | The physical host currently serving the target. Reused deliberately: a GitHub Environment is where a logical target is bound to a physical host, and that host is the same one whether we deploy to it or prepare it. |
| `DEPLOY_PORT` | SSH port. |
| `BOOTSTRAP_USER` | The privileged bootstrap/recovery user: root, or a passwordless sudoer. |

### Secrets

| Secret | Meaning |
| --- | --- |
| `BOOTSTRAP_SSH_KEY` | Privileged bootstrap SSH private key. |
| `BOOTSTRAP_KNOWN_HOSTS` | Verified `known_hosts` entry for the bootstrap host. |
| `PREPARE_LARAVEL_ENV` | The target's Laravel environment file. |
| `PREPARE_DEPLOY_AUTHORIZED_KEYS` | `authorized_keys` for the restricted deploy user. |
| `PREPARE_RCLONE_CONFIG` | rclone configuration for offsite backups. |
| `PREPARE_BASIC_AUTH` | Basic Auth htpasswd hashes. |
| `PREPARE_TLS_CERTIFICATE` | TLS certificate chain for the target's public hostname. |
| `PREPARE_TLS_PRIVATE_KEY` | TLS private key for the target's public hostname. |
| `PREPARE_TLS_DHPARAMS` | Shared TLS DH parameters. |
| `PREPARE_NGINX_TLS_OPTIONS` | Shared Nginx TLS options snippet. |
| `PREPARE_MAIL_TLS_CERTIFICATE` | TLS certificate chain for the mail-capture vhosts. |
| `PREPARE_MAIL_TLS_PRIVATE_KEY` | TLS private key for the mail-capture vhosts. |

Every `PREPARE_*` secret is **optional**. An unset secret is simply not
uploaded, and the server preserves whatever the host already holds. On an
already-prepared host none of them are required at all.

## External prerequisites, and the safe-existing-file contract

The prerequisite set is derived, never hard-coded in GitHub. Registry-owned
paths come from the target registry; Nginx-owned paths are parsed out of this
repository's committed vhosts using the same directive contract
`install-bootstrap-services` gates on — so the two can never disagree about
which files a host must have.

For `staging-main` that resolves to ten logical prerequisites:

| Logical name | Scope | Destination owner |
| --- | --- | --- |
| `basic-auth` | host | the target's Nginx vhost |
| `tls-certificate` / `tls-private-key` | host | the target's Nginx vhost |
| `tls-dhparams` / `nginx-tls-options` | host | the target's Nginx vhost |
| `mail-tls-certificate` / `mail-tls-private-key` | host | the shared mail-capture vhosts |
| `laravel-env` | target | the target registry |
| `deploy-authorized-keys` | target | the target registry |
| `rclone-config` | target | host-global root configuration |

Each row also declares the owner, group and mode its destination must have —
the runtime identity for `.env`, the deploy user for `authorized_keys`,
`root:www-data` for the htpasswd, and so on. Those are installed on material
this operation delivers, and verified on material that was already there.

The rules, in full:

| Destination | Supplied material | Result |
| --- | --- | --- |
| absent | supplied | **install** |
| absent | none | **MISSING** — apply fails closed; nothing is invented |
| present | none | **PASS**, untouched |
| present | identical | **PASS**, untouched — not even rewritten |
| present | different | **CONFLICT** — apply fails closed |

That last row is the important one. A differing `.env`, TLS private key,
htpasswd or `rclone.conf` means the supplied material and the live host
disagree, and the safe response is to say so — not to silently rotate a
credential every running process is holding. Rotation is a separate,
deliberate operation.

Secrets are compared, never read. A conflict reports only that the two
differ: no content, no length, no hash, no byte offset.

### Symlinked destinations

A destination may be a **symlink only where an ACME client actually publishes
one**, and all of the following must hold together:

- it is one of the four TLS rows (`tls-certificate`, `tls-private-key`,
  `mail-tls-certificate`, `mail-tls-private-key`);
- the destination is exactly
  `/etc/letsencrypt/live/<certificate>/fullchain.pem` or
  `…/privkey.pem`, with the leaf matching the row;
- the link resolves to that **same certificate's** numbered archive file,
  `/etc/letsencrypt/archive/<certificate>/<leaf><version>.pem`.

That is precisely what certbot publishes, and such a link is accepted as
present and never written through. Every condition is load-bearing: the logical
name alone, or a loose "points somewhere inside `/etc/letsencrypt`" test, would
let a link at an allowed path be repointed at another certificate's key — or at
any other file in the tree — and preparation would bless the substitution as
correct state. A link resolving to nothing, to something that is not a regular
file, or anywhere other than its own archive file fails closed.

Everywhere else a link is refused outright, and that is a security property
rather than tidiness: a target's `shared/` directory is writable by the
application's own runtime user, so accepting a link there would let a
compromised runtime replace `.env` with a pointer at attacker-controlled
material and have preparation bless it as present and correct.

### Ownership and mode

Verification enforces the **owner, group and mode the prerequisite table
declares**, exactly — not merely presence, and not merely the absence of
world-read. `--apply` checks the same contract on everything already present
*before* it installs anything, so a host with one prerequisite absent and
another one's ownership wrong is never left half-converged.

Presence alone is not readiness. A `shared/.env` that drifted to `root:root
0600` is perfectly protected from outsiders and completely unreadable by the
PHP-FPM and queue workers that have to read it; an htpasswd that lost its
`www-data` group breaks Nginx the same way; an `authorized_keys` or
`rclone.conf` with the wrong owner is simply ignored. Each of those is a target
verification would otherwise call prepared and that would then fail on its first
real use, so each is a failure here, with the exact `chown`/`chmod` to run in
the message.

`stat` dereferences, so for the ACME material that legitimately is a link the
file behind it is checked — which is what any reader actually opens, and whose
modes certbot already sets to what this table declares.

Supplied material has its trailing newlines normalized to exactly one, since
GitHub secrets do not record whether the operator's file ended with one.

## Database prerequisites

`install-target-database` closes the clean-host gap Phase 5 deliberately left
open: it creates the target's PostgreSQL role and database from credentials
the operator already supplied in `shared/.env`, and generates nothing.

On a clean host it creates the role (`LOGIN NOSUPERUSER NOCREATEDB
NOCREATEROLE NOREPLICATION`), creates the database owned by that role, grants
`CONNECT`, and then proves the target's own credentials can connect. On an
already-prepared host both objects are SKIPped.

It also refuses a pre-existing role that cannot log in, or that holds
`SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` or `BYPASSRLS`: "it exists"
is not "it is safe to hand the application", and this installer never alters an
existing role. Both checks run **before** anything is created, so a refused run
never leaves a database and a `CONNECT` grant behind for an account it went on
to reject.

It never, in any mode:

- drops a role or a database;
- recreates, renames or truncates anything;
- reads, alters or deletes a row of application data;
- resets a schema or runs a migration — schema is applied by `deploy
  --migrate`;
- rotates the password of a role that already exists;
- restores a backup.

Where an existing object cannot be proven to match the intended
configuration — a database owned by someone else, credentials that do not
connect, a registry/`.env` disagreement — it **fails closed** and reports the
mismatch. Reconciling genuine drift is Repair Target (Phase 7.5).

The credentials are read from `shared/.env` by a deliberately limited reader:
six keys, plain string extraction, never sourced and never `eval`'d. A `.env`
is operator-authored secret material, and executing it as shell in a root
process would hand whoever wrote it a root shell. The password reaches
PostgreSQL on stdin or through `PGPASSWORD`; it never appears in an argument
vector, a log line or a summary.

## Target lifecycle

`staging-main` is `lifecycle=active`; `tits-guru` is `lifecycle=planned`.
Phase 7.2 changes neither.

`prepare-host` refuses any target that is not `active`, **before any
target-specific mutation**. Both target-aware children enforce the same gate
independently. Host-global bootstrap is therefore not a loophole for silently
provisioning a planned production target.

### Prepare production host

The workflow exists, is wired to the same shared action, and is pinned to the
real `tits-guru` target ID — so a real run fails closed on the server's
lifecycle gate today. That is deliberate: it proves production will be
prepared by exactly the same mechanism once Phase 8 activates and provisions
the target, rather than by a separate production-shaped procedure invented
under pressure on launch day. The production GitHub Environment's own
protection rules stay authoritative on top of it.

## Boundaries

Prepare Host is RateGuru repository infrastructure, not a general
configuration-management engine for the machine. It touches only resources
this repository's own contract declares — resolved from the target registry
or from a committed RateGuru vhost — and never unrelated projects,
databases, vhosts, PHP-FPM pools, Supervisor programs, system users or
`/home/www` trees. It never deletes host configuration merely because the
repository does not know about it.

## Concurrency

`Prepare staging host` runs in the `rateguru-staging-deployment` concurrency
group — the same domain as `Deploy to staging`, `Rollback staging` and the
staging verification step of a production release, and the domain future
restore and recover operations will join. `Prepare production host` runs in
`rateguru-production-release`. Neither cancels an in-flight run. This is
orchestration on top of, never a replacement for, the server-side deployment
lock.

## What builds on this

Restore Target Data, Repair Target and Recover Host all build on this
operation and are explicitly out of scope here.

Recover Host is the closest neighbour, and the boundary is worth stating
plainly: a recovery REQUIRES a prepared host and refuses to run without one —
it runs `prepare-host --verify` as its first precondition. It never prepares
one. Preparation stays the owner of packages, identities, layout,
Nginx/FPM/Supervisor/cron, external material, `shared/.env`,
`authorized_keys`, rclone, TLS, Basic Auth and the empty database; recovery
fills that prepared, empty target with data and code and touches none of it.

A recovery rebuilds a lost application from the `source_sha` every backup
already carries in its `release.json`, through the same single build
implementation a normal release uses — there is no durable artifact archive.
See [`recover-host.md`](recover-host.md).
