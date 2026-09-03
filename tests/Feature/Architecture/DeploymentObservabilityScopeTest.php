<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Prepare Host's own scope guard: what this phase establishes, and — more
 * importantly — the four later phases it deliberately does not begin.
 *
 * 7.2 ends when a clean VPS can be PREPARED and a successful deployment state
 * transition is visible in both observability systems. Restore (7.3), Repair
 * (7.5) and Recover (7.6) come after it, the rejected durable-artifact archive
 * stays rejected, the backup architecture is untouched, and production stays
 * unprovisioned until the production launch.
 */

// =============================================================================
// What Prepare Host adds
// =============================================================================

it('adds exactly the operator-facing workflows Prepare Host is meant to add', function () {
    // The workflows Prepare Host established, all of which must still exist and
    // still be the ONLY ones covering their operation. This is deliberately a
    // containment check rather than an exact inventory: later phases add
    // operator workflows of their own (7.4 added the two restore ones), and
    // each phase's guard owns the exact inventory as of itself —
    // RestoreOperatorSurfaceScopeTest is the current one.
    $workflows = collect(glob(base_path('.github/workflows/*.yml')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    foreach ([
        'ci.yml',
        'coverage.yml',
        'deploy-staging.yml',
        'label-review-bot-prs.yml',
        'prepare-production-host.yml',
        'prepare-staging-host.yml',
        'release.yml',
        'rollback-production.yml',
        'rollback-staging.yml',
    ] as $workflow) {
        expect($workflows)->toContain($workflow);
    }

    // What 7.2 rejected, and every later phase inherits: one generic
    // "Operations" workflow, and per-environment duplicates of an operation
    // that already has a shared implementation.
    foreach ([
        'operations.yml',
        'deploy-production.yml',
        'prepare-host.yml',
        'release-staging.yml',
    ] as $rejected) {
        expect($workflows)->not->toContain($rejected);
    }
});

it('adds exactly the shared actions Prepare Host is meant to add', function () {
    // Containment, for the same reason as the workflow inventory above: 7.4
    // added restore-rateguru, and RestoreOperatorSurfaceScopeTest owns the exact list.
    $actions = collect(glob(base_path('.github/actions/*'), GLOB_ONLYDIR) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    foreach ([
        'build-rateguru',
        'deploy-rateguru',
        'prepare-rateguru-host',
        'record-rateguru-deployment',
        'rollback-rateguru',
        'sentry-release',
    ] as $action) {
        expect($actions)->toContain($action);
    }

    // No per-environment fork of a shared action, ever.
    foreach ($actions as $action) {
        expect($action)->not->toContain('-staging')
            ->and($action)->not->toContain('-production');
    }
});

it('keeps one implementation per operation', function () {
    // the shared operation actions's model, extended by exactly two operations rather than
    // forked: one BUILD, one DEPLOY, one ROLLBACK, one PREPARE, one
    // DEPLOYMENT-RECORDING implementation, with per-environment workflows on
    // top wherever policy differs.
    foreach ([
        'build' => 'build-rateguru',
        'deploy' => 'deploy-rateguru',
        'rollback' => 'rollback-rateguru',
        'prepare' => 'prepare-rateguru-host',
        'observability' => 'record-rateguru-deployment',
    ] as $operation => $action) {
        expect(File::exists(base_path(".github/actions/{$action}/action.yml")))
            ->toBeTrue("the shared {$operation} action is missing");
    }
});

// =============================================================================
// No Restore, Repair or Recover
// =============================================================================

it('implements no Restore operation', function () {
    // restore-test remains what it has always been: a read-only proof that a
    // backup can be restored into a throwaway scratch database. Turning it
    // into a live restore was Restore Target Data, and nothing 7.2 added does it.
    //
    // Restore Target Data landed the SERVER primitives (restore-target and friends) and
    // the controlled code alignment the GitHub-facing surface; each owns its own scope guard
    // (RestoreServerPrimitivesScopeTest, RestoreOperatorSurfaceScopeTest). What this test still owns is 7.2's
    // own promise: nothing 7.2 added restores anything, and no restore
    // implementation ever appeared under a name outside those phases.
    expect(File::exists(base_path('infrastructure/scripts/restore-test')))->toBeTrue();

    foreach ([
        'infrastructure/scripts/restore',
        'infrastructure/scripts/restore-staging',
        'infrastructure/scripts/restore-production',
        '.github/actions/restore-staging/action.yml',
        '.github/actions/restore-production/action.yml',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} is not part of any phase's restore design");
    }

    // And nothing added in 7.2 restores anything.
    foreach ([
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/install-target-prerequisites',
        'infrastructure/scripts/install-target-database',
    ] as $script) {
        $source = File::get(base_path($script));

        expect($source)->not->toContain('pg_restore');
        expect($source)->not->toContain('database.dump');
        expect($source)->not->toContain('storage-app.tar.gz');
    }
});

it('reports drift and fails closed rather than reconciling it', function () {
    // Host preparation reports drift and refuses; it never reconciles it.
    // That distinction is what keeps repair a separate operation with its own
    // interlocks, rather than a side effect of preparing a host.
    expect(File::get(base_path('infrastructure/scripts/install-target-database')))
        ->toContain('resolve the mismatch manually');
    expect(File::get(base_path('infrastructure/scripts/install-target-prerequisites')))
        ->toContain('refusing to overwrite');
});

it('implements no Recover operation', function () {
    foreach ([
        'infrastructure/scripts/recover-host',
        '.github/workflows/recover-staging-host.yml',
        '.github/workflows/recover-production-host.yml',
        '.github/actions/recover-rateguru-host/action.yml',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} belongs to Recover Host/7.7, not 7.2");
    }

    // Recovery rebuilds an application from the source SHA a backup carries.
    // Preparation never builds anything.
    expect(File::get(base_path('.github/actions/prepare-rateguru-host/action.yml')))
        ->not->toContain('build-rateguru');
});

// =============================================================================
// No durable artifact archive, no backup redesign
// =============================================================================

it('adds no durable release-artifact archive', function () {
    foreach (operationalFiles() as $path) {
        $source = File::get($path);

        foreach ([
            'rateguru-release-artifacts',
            'B2_ARTIFACT_',
            'ARTIFACT_BUCKET',
            'artifact_retention',
        ] as $rejected) {
            expect($source)->not->toContain($rejected);
        }
    }

    // GitHub artifacts stay what they are: temporary CI/deployment transport.
    $deployStaging = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
    $build = collect($deployStaging['jobs']['build']['steps'])
        ->firstWhere('uses', './.github/actions/build-rateguru');

    expect($build)->not->toBeNull('the staging build step is missing');
    expect($build['with']['artifact-retention-days'])->toBe('3');
});

it('leaves the backup architecture and its manifest schema untouched', function () {
    // Prepare Host promised not to redesign backups, and this is what that means
    // in terms anyone can check on any branch: the artifact contract and the
    // manifest schema are still the ones that shipped before it.
    //
    // This was originally a git diff of the branch against its base, which
    // only expressed the promise while the branch under test WAS Prepare Host.
    // From a later branch it asserted something else entirely — that no
    // subsequent phase may touch a backup file — and Restore Target Data legitimately
    // does (see below). A structural guard says the intended thing from
    // anywhere.
    $backup = File::get(base_path('infrastructure/scripts/backup'));

    // The same six files, written under the same names.
    foreach ([
        'database.dump',
        'storage-app.tar.gz',
        'environment.env',
        'server-configuration.tar.gz',
        'SHA256SUMS',
        'manifest.json',
    ] as $artifact) {
        expect($backup)->toContain($artifact);
    }

    // The same manifest schema, and the classifier that reads it.
    expect($backup)->toContain('--argjson manifest_schema_version 2');
    expect(File::get(base_path('infrastructure/scripts/common')))
        ->toContain('manifest_schema_classify');

    // And no artifact-archive concept anywhere in the backup path.
    foreach ([
        'infrastructure/scripts/backup',
        'infrastructure/scripts/backup-cycle',
        'infrastructure/scripts/offsite-backup',
        'infrastructure/scripts/offsite-retention',
        'infrastructure/scripts/offsite-restore-test',
        'infrastructure/scripts/restore-test',
    ] as $script) {
        $source = File::get(base_path($script));

        foreach (['rateguru-release-artifacts', 'B2_ARTIFACT_', 'ARTIFACT_BUCKET'] as $rejected) {
            expect($source)->not->toContain($rejected);
        }
    }
});

it('lets Restore Target Data add exactly one fail-closed guard to backup, and nothing else', function () {
    // The one deliberate change a later phase made to this path, asserted
    // positively so it cannot quietly grow into backup logic. A target held
    // after a restore has data belonging to a different commit than
    // current/release.json names, and a backup taken there would label it with
    // that commit — so backup refuses, and refuses without touching anything.
    $backup = File::get(base_path('infrastructure/scripts/backup'));

    expect($backup)->toContain('assert_no_restore_hold "${TARGET_ID}" "${RUN_ROOT}" "a backup"');

    // Before perform_backup, which is what makes it a refusal rather than a
    // half-created snapshot.
    expect(mb_strpos($backup, 'assert_no_restore_hold'))
        ->toBeLessThan(mb_strpos($backup, "\n    perform_backup\n"));

    // Read-only: the guard reads a marker and fails. It never writes, removes
    // or repairs one — clearing the hold belongs to restore-target --resume.
    $common = File::get(base_path('infrastructure/scripts/common'));

    expect($common)->toContain('assert_no_restore_hold()');
    expect($common)->not->toMatch('/assert_no_restore_hold\(\)[\s\S]{0,2000}?\b(rm|mv|install|touch|printf[^\n]*>)\s/');
});

it('keeps release.json exactly as it is', function () {
    // release.release stays the operator/history identity, release.source_sha
    // stays what a future Recover Host will rebuild from. Prepare Host reads both
    // and adds nothing.
    $metadata = File::get(base_path('app/Support/Deployment/DeploymentMetadata.php'));

    expect($metadata)->toContain("\$decoded['release']");
    expect($metadata)->toContain("\$decoded['source_sha']");

    $build = File::get(base_path('.github/actions/build-rateguru/action.yml'));
    expect($build)->not->toContain('backup_namespace');
    expect($build)->not->toContain('artifact_archive');
});

// =============================================================================
// Nothing is activated, nothing unrelated is managed
// =============================================================================

it('activates no production target', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    // The production perimeter, sudoers and Nightwatch allowlist all stay
    // closed to tits-guru.
    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-deploy')))
        ->not->toContain('deploy-rateguru-tits-guru');
    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-nightwatch-deployment')))
        ->not->toContain('deploy-rateguru-tits-guru');
    expect(File::get(base_path('infrastructure/scripts/common')))
        ->toContain("staging-main) printf 'rateguru-staging-nightwatch\\n' ;;");
});

it('manages no unrelated project on the same host', function () {
    foreach ([
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/install-target-prerequisites',
        'infrastructure/scripts/install-target-database',
        'infrastructure/scripts/record-nightwatch-deployment',
        '.github/actions/prepare-rateguru-host/action.yml',
    ] as $path) {
        $source = File::get(base_path($path));

        foreach (['CatalogHub', 'cataloghub', 'Polymarket', 'polymarket'] as $foreign) {
            expect($source)->not->toContain($foreign);
        }

        // No sweeping operation over a shared directory: every path acted on
        // is resolved from the registry or from a committed RateGuru vhost.
        expect($source)->not->toMatch('#rm\s+-rf\s+/(etc|home|var|opt)(/\S*)?\s*$#m');
        expect($source)->not->toMatch('#for\s+\w+\s+in\s+/etc/nginx/sites-enabled/\*#');
        expect($source)->not->toMatch('#psql.*FROM\s+pg_database\s*;#');
    }
});

it('does not weaken the clean-host bootstrap or the shared operation actions contracts', function () {
    // Every the clean-host bootstrap primitive still exists and still owns what it owned.
    foreach ([
        'bootstrap-host',
        'bootstrap-host-preflight',
        'install-bootstrap-runtime',
        'install-bootstrap-host-layout',
        'install-bootstrap-services',
        'install-target-operations',
        'install-target-perimeter',
        'install-public-storage-access',
    ] as $script) {
        expect(File::exists(base_path('infrastructure/scripts/'.$script)))->toBeTrue();
    }

    // bootstrap-host keeps its own three slices and its own preflight; nothing
    // in Prepare Host reached into it.
    $bootstrapHost = File::get(base_path('infrastructure/scripts/bootstrap-host'));
    expect($bootstrapHost)->toContain('SLICE_IDS=(5.2 5.3 5.4)');
    expect($bootstrapHost)->not->toContain('prepare-host');
    expect($bootstrapHost)->not->toContain('install-target-database');
    expect($bootstrapHost)->not->toContain('install-target-prerequisites');

    // And the deploy transport is exactly what the target-aware migration established.
    expect(File::get(base_path('.github/actions/deploy-rateguru/action.yml')))
        ->toContain('rateguru-deploy');
});
