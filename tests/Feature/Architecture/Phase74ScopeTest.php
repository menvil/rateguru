<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 7.4's own scope guard: what this phase establishes, and — more
 * importantly — everything it deliberately does not begin.
 *
 * 7.4 ends when an operator can restore a target from GitHub, and a backup
 * taken under older code is followed automatically by a build of THAT commit,
 * a controlled deployment that keeps the target held, and a resume. Repair
 * Target is 7.5, Recover Host is 7.6/7.7, the rejected durable artifact
 * archive stays rejected, nothing is ever built on the VPS, and production
 * stays unprovisioned until Phase 8.
 *
 * @return list<string>
 */
function p74OperationalFiles(): array
{
    $configFiles = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('infrastructure/config'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($tree as $entry) {
        if ($entry->isFile()) {
            $configFiles[] = $entry->getPathname();
        }
    }

    return array_values(array_filter(array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
        glob(base_path('infrastructure/scripts/*')) ?: [],
        $configFiles,
    ), 'is_file'));
}

/**
 * The revision this branch is measured against: the pull request's own base
 * commit in CI, `origin/develop` locally, or null when neither is available.
 */
function p74BaseRevision(): ?string
{
    $baseSha = getenv('BASE_SHA');

    if (is_string($baseSha) && $baseSha !== '' && p74GitSucceeds(['cat-file', '-e', $baseSha.'^{commit}'])) {
        return $baseSha;
    }

    return p74GitSucceeds(['rev-parse', '--verify', 'origin/develop']) ? 'origin/develop' : null;
}

/**
 * @param  list<string>  $arguments
 */
function p74GitSucceeds(array $arguments): bool
{
    // Every argument is escaped individually: BASE_SHA is an environment
    // value and this runs through a shell.
    $command = 'cd '.escapeshellarg(base_path()).' && git '
        .implode(' ', array_map('escapeshellarg', $arguments))
        .' >/dev/null 2>&1; echo $?';

    return trim((string) shell_exec($command)) === '0';
}

/** @return list<string> */
function p74ChangedFiles(): array
{
    $baseline = trim((string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git diff --name-only '
            .escapeshellarg((string) p74BaseRevision()).' HEAD 2>/dev/null'
    ));

    return $baseline === '' ? [] : explode("\n", $baseline);
}

/**
 * The body of one shell function, so an ordering assertion is about what
 * actually runs rather than about where things happen to be declared. Every one
 * of these scripts defines its helpers above its pipeline, so a whole-file
 * position comparison would routinely say the opposite of the truth.
 */
function p74FunctionBody(string $source, string $name): string
{
    $start = mb_strpos($source, "\n{$name}() {\n");

    expect($start)->not->toBeFalse("{$name} is not defined");

    $end = mb_strpos($source, "\n}\n", $start);

    expect($end)->not->toBeFalse("{$name} has no closing brace");

    return mb_substr($source, $start, $end - $start);
}

// =============================================================================
// What Phase 7.4 adds
// =============================================================================

it('adds exactly one restore action, two operator workflows and one server wrapper', function () {
    $workflows = collect(glob(base_path('.github/workflows/*.yml')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($workflows)->toBe([
        'ci.yml',
        'coverage.yml',
        'deploy-staging.yml',
        'label-review-bot-prs.yml',
        'prepare-production-host.yml',
        'prepare-staging-host.yml',
        'release.yml',
        'repair-production.yml',
        'repair-staging.yml',
        'restore-production.yml',
        'restore-staging.yml',
        'rollback-production.yml',
        'rollback-staging.yml',
    ]);

    $actions = collect(glob(base_path('.github/actions/*'), GLOB_ONLYDIR) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($actions)->toBe([
        'build-rateguru',
        'deploy-rateguru',
        'prepare-rateguru-host',
        'record-rateguru-deployment',
        'repair-rateguru-target',
        'restore-rateguru',
        'rollback-rateguru',
        'sentry-release',
    ]);

    $wrappers = collect(glob(base_path('infrastructure/config/wrappers/*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($wrappers)->toBe([
        'rateguru-cleanup',
        'rateguru-deploy',
        'rateguru-nightwatch-deployment',
        'rateguru-restore',
        'rateguru-rollback',
    ]);

    // No new sudoers file: the restore grant extends the one that already
    // exists for this deploy account.
    $sudoers = collect(glob(base_path('infrastructure/config/sudoers/*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($sudoers)->toBe(['rateguru-deploy', 'rateguru-nightwatch-deployment']);

    // And no new server-side CLI at all: 7.4 is a GitHub/operator layer over
    // primitives 7.3 already accepted on real staging.
    $scripts = collect(glob(base_path('infrastructure/scripts/*')) ?: [])
        ->filter(static fn (string $path): bool => is_file($path))
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    $expected = [...requiredCliManifestNames(), ...sourcedLibraryNames()];
    sort($expected);

    expect($scripts)->toBe($expected);
});

// =============================================================================
// ONE BUILD, ONE DEPLOY, ONE RESTORE
// =============================================================================

it('adds no second build, deployment or restore implementation', function () {
    // Every RELEASE build in this repository is
    // .github/actions/build-rateguru. ci.yml and coverage.yml are deliberately
    // excluded: they install dependencies to run the test suite, which is not
    // building a release and never produces a deployable artifact.
    $buildCallers = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $name = basename($path);

        if (in_array($name, ['ci.yml', 'coverage.yml', 'label-review-bot-prs.yml'], true)) {
            continue;
        }

        $source = File::get($path);

        if (str_contains($source, './.github/actions/build-rateguru')) {
            $buildCallers[] = $name;
        }

        // No operator workflow reimplements the build pipeline itself.
        foreach (['composer install', 'npm ci', 'npm run build'] as $mechanism) {
            expect($source)->not->toContain($mechanism);
        }
    }

    expect($buildCallers)->toContain('restore-staging.yml')
        ->toContain('restore-production.yml');

    // The alignment deploy is the ordinary deploy action and the ordinary
    // server-side deploy — there is no restore-deploy, historical-deploy or
    // recovery-deploy anywhere.
    foreach ([
        '.github/actions/restore-deploy-rateguru/action.yml',
        '.github/actions/align-rateguru/action.yml',
        'infrastructure/scripts/restore-deploy',
        'infrastructure/scripts/historical-deploy',
        'infrastructure/scripts/recovery-deploy',
        'infrastructure/scripts/align-target',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} would be a second deployment implementation");
    }

    // And the alignment is a MODE of deploy, not a fork of it: exactly one
    // extraction, one current switch and one PHP-FPM reload exist in the file.
    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    expect(substr_count($deploy, 'tar \\'))->toBe(1);
    expect(substr_count($deploy, 'mv -Tf \\'))->toBe(2, 'exactly the current switch and the previous update');
    expect(substr_count($deploy, "\nperform_deploy() {"))->toBe(1);
});

it('never builds an application on the VPS and stores no durable artifact archive', function () {
    // The host-side build ban is about the SERVER: nothing installed on a VPS
    // may compile an application. GitHub Actions is where every build happens,
    // so the workflow files are not in scope here — .github/workflows/ci.yml
    // installs dependencies to run tests, which is exactly right.
    $serverFiles = array_values(array_filter(array_merge(
        glob(base_path('infrastructure/scripts/*')) ?: [],
        glob(base_path('infrastructure/config/wrappers/*')) ?: [],
        glob(base_path('infrastructure/config/cron/*')) ?: [],
        glob(base_path('infrastructure/config/sudoers/*')) ?: [],
    ), 'is_file'));

    expect($serverFiles)->not->toBeEmpty();

    foreach ($serverFiles as $path) {
        // Executable lines only. These scripts legitimately DESCRIBE what the
        // GitHub build does ("composer install --no-dev, ...") in comments,
        // and a whole-file scan would forbid explaining the very boundary it
        // is enforcing.
        $source = executableSourceLines(File::get($path));

        // The recovery contract is exact source_sha + repository lockfiles +
        // mutable backup state, assembled in GitHub Actions and nowhere else.
        foreach ([
            'composer install',
            'composer update',
            'npm ci',
            'npm install',
            'npm run build',
            'git clone',
            'git checkout',
            'git fetch',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }

    // The rejected durable artifact archive stays rejected everywhere.
    foreach (p74OperationalFiles() as $path) {
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
});

// =============================================================================
// The operator never chooses a target or a commit
// =============================================================================

it('offers no target selector and no commit input anywhere', function () {
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $source = File::get($path);

        // The exact rejected shape: a workflow_dispatch choice listing target
        // IDs. Named workflows are the interface instead.
        expect($source)->not->toMatch('/options:\s*\n\s*-\s*staging-main/');
        expect($source)->not->toMatch('/options:\s*\n\s*-\s*tits-guru/');
    }

    $restoreWorkflows = [
        base_path('.github/workflows/restore-staging.yml'),
        base_path('.github/workflows/restore-production.yml'),
    ];

    foreach ($restoreWorkflows as $path) {
        $source = File::get($path);
        $workflow = Yaml::parse($source);

        // The required commit is never typed by a person: it flows from the
        // backup's own verified release.json through the restore state and the
        // action's output into the checkout.
        expect($source)->toContain('${{ needs.restore.outputs.required_source_sha }}');

        // Asserted against the parsed INPUTS, not the raw text: the workflow
        // legitimately passes a required_source_sha between jobs, and it is
        // only ever an operator input that would be wrong.
        $inputs = array_keys((array) data_get($workflow, 'on.workflow_dispatch.inputs'));

        expect($inputs)->toBe(
            basename($path) === 'restore-production.yml'
                ? ['mode', 'source', 'backup', 'operation', 'confirmation']
                : ['mode', 'source', 'backup', 'operation'],
        );
    }
});

// =============================================================================
// The guard is never bypassed
// =============================================================================

it('makes every ordinary target mutation refuse while a restore guard exists', function () {
    foreach (['backup', 'deploy', 'rollback', 'cleanup'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        expect(substr_count($source, 'assert_no_restore_hold'))->toBe(
            1,
            "{$script} must consult the restore guard exactly once",
        );
    }

    // And each one does it BEFORE its own first mutation, measured inside the
    // pipeline function that actually runs them.
    $deploy = p74FunctionBody(File::get(base_path('infrastructure/scripts/deploy')), 'perform_deploy');

    expect(mb_strpos($deploy, 'assert_no_restore_hold'))
        ->toBeLessThan(mb_strpos($deploy, 'append_history \\'));

    $rollback = p74FunctionBody(File::get(base_path('infrastructure/scripts/rollback')), 'perform_rollback');

    expect(mb_strpos($rollback, 'assert_no_restore_hold'))
        ->toBeLessThan(mb_strpos($rollback, 'validate_releases_root'));

    $cleanup = p74FunctionBody(File::get(base_path('infrastructure/scripts/cleanup')), 'perform_apply');

    expect(mb_strpos($cleanup, 'assert_no_restore_hold'))
        ->toBeLessThan(mb_strpos($cleanup, 'ensure_pinned_file_exists'));

    // Each refusal is also under the target's own deployment lock, so it
    // cannot race a restore that is about to take the same lock.
    foreach ([$deploy, $rollback, $cleanup] as $pipeline) {
        expect(mb_strpos($pipeline, 'acquire_deployment_lock'))
            ->toBeLessThan(mb_strpos($pipeline, 'assert_no_restore_hold'));
    }

    // Only restore-target may write or clear a guard. Nothing else in the
    // repository touches it.
    foreach (p74OperationalFiles() as $path) {
        if (basename($path) === 'restore-target' || basename($path) === 'common') {
            continue;
        }

        foreach (['write_restore_guard', 'clear_restore_guard'] as $forbidden) {
            expect(File::get($path))->not->toContain($forbidden);
        }
    }
});

it('never migrates, health-checks or resumes a target during a controlled alignment', function () {
    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    // The alignment path returns from perform_deploy before the ordinary
    // post-switch sequence — that ordering is the guarantee.
    $fork = mb_strpos($deploy, 'if restore_alignment_mode; then'.PHP_EOL.'        finalize_restore_alignment');

    expect($fork)->not->toBeFalse('the post-switch fork is missing');
    expect($fork)->toBeLessThan(mb_strpos($deploy, '"${HEALTH_CHECK_BIN}"'));
    expect($fork)->toBeLessThan(mb_strpos($deploy, 'perform_queue_transition'.PHP_EOL.PHP_EOL));

    // And the mutually-exclusive rule is enforced in parsing, before anything
    // is resolved or locked.
    expect($deploy)->toContain('--migrate and --restore-operation are mutually exclusive');
    expect(mb_strpos($deploy, '--migrate and --restore-operation are mutually exclusive'))
        ->toBeLessThan(mb_strpos($deploy, "\nperform_deploy() {"));

    // finalize_restore_alignment does none of the resuming actions.
    //
    // Through p74FunctionBody, which asserts both markers were actually found.
    // Raw mb_strpos here would turn a renamed function into `false`, which
    // mb_substr reads as offset 0 — and the scan below would then "pass" while
    // describing the whole file instead of that one function.
    $section = p74FunctionBody($deploy, 'finalize_restore_alignment');

    foreach ([
        'HEALTH_CHECK_BIN',
        'perform_queue_transition',
        'artisan up',
        'supervisorctl start',
        'clear_restore_guard',
        'cron.d',
        'artisan migrate',
    ] as $forbidden) {
        expect($section)->not->toContain($forbidden);
    }
});

// =============================================================================
// No Recover, no production activation
// =============================================================================

it('implements no Recover Host', function () {
    foreach ([
        'infrastructure/scripts/recover-host',
        '.github/workflows/recover-staging-host.yml',
        '.github/workflows/recover-production-host.yml',
        '.github/actions/recover-rateguru-host/action.yml',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} belongs to a later phase, not 7.4");
    }

    // --inspect refuses the two guard states that need manual recovery rather
    // than a deployment, and says so — including that Repair Target is not a
    // way out of either, because it refuses any target under a restore guard.
    expect(File::get(base_path('infrastructure/scripts/restore-target')))
        ->toContain('Repair Target is not a way out either');
});

it('activates no production target, provisions nothing and changes no DNS', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    foreach (p74OperationalFiles() as $path) {
        $source = File::get($path);

        foreach (['cloudflare', 'route53', 'dns_record', 'certbot --force-renewal'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
});

// =============================================================================
// The blast radius of this phase
// =============================================================================

it('leaves the accepted backup subsystem and the 7.3 restore primitives untouched', function () {
    $changed = p74ChangedFiles();

    // The restore mechanics themselves were accepted on a real destructive
    // staging run. 7.4 adds --inspect and a machine-readable result line to
    // restore-target; it must not touch the primitives that swap the data.
    // (toContain is variadic in Pest, so no message argument here.)
    foreach ([
        'infrastructure/scripts/backup',
        'infrastructure/scripts/backup-cycle',
        'infrastructure/scripts/offsite-backup',
        'infrastructure/scripts/offsite-retention',
        'infrastructure/scripts/offsite-restore-test',
        'infrastructure/scripts/restore-test',
        'infrastructure/scripts/fetch-backup',
        'infrastructure/scripts/verify-backup',
        'infrastructure/scripts/restore-database',
        'infrastructure/scripts/restore-storage',
        'infrastructure/config/cron/rateguru-backups',
        'infrastructure/config/supervisor/rateguru-staging-queue.conf',
        'infrastructure/config/cron/rateguru-staging-scheduler',
        'infrastructure/config/deployment-targets.json',
    ] as $untouched) {
        expect($changed)->not->toContain($untouched);
    }
})->skip(fn (): bool => p74BaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('does not weaken the ordinary deploy, rollback or cleanup contract', function () {
    // Every one of these must still be exactly what it was, apart from the
    // one fail-closed refusal and (for deploy) the explicit alignment mode.
    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    // The normal path still health checks, still transitions the queue, and
    // still records an ordinary successful deployment.
    expect($deploy)
        ->toContain('deployment health check failed')
        ->toContain('perform_queue_transition')
        ->toContain('DEPLOY_HISTORY_FINISH_EVENT="deployment-finished"')
        ->toContain('DEPLOY_HISTORY_START_EVENT="deployment-started"');

    // Alignment is opt-in and empty by default, so a normal deployment takes
    // the identical code path it always did.
    expect($deploy)
        ->toContain('RESTORE_OPERATION_ID=""')
        ->toContain('restore_alignment_mode() {');

    $rollback = File::get(base_path('infrastructure/scripts/rollback'));
    expect($rollback)
        ->toContain('rollback health check failed')
        ->toContain('--previous');

    $cleanup = File::get(base_path('infrastructure/scripts/cleanup'));
    expect($cleanup)->toContain('run_dry_run');
});

it('ships the runbooks and points the README and roadmap at them', function () {
    expect(File::exists(base_path('infrastructure/runbooks/restore-target.md')))->toBeTrue();
    expect(File::exists(base_path('infrastructure/runbooks/github-restore.md')))->toBeTrue();

    $runbook = File::get(base_path('infrastructure/runbooks/github-restore.md'));

    // The three operations documentation must never blur together.
    expect($runbook)
        ->toContain('RESTORE TARGET DATA')
        ->toContain('CONTROLLED CODE ALIGNMENT')
        ->toContain('RECOVER HOST')
        ->toContain('continue-held')
        ->toContain('RESTORE tits-guru')
        ->toContain('RESTORE_WRAPPER');

    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/github-restore.md');

    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)
        ->toContain('7.3 Restore Target Data — ACCEPTED')
        ->toContain('7.4 GitHub Restore actions')
        ->toContain('runbooks/github-restore.md');

    // Implemented, not accepted: CI proves the structure, only a real GitHub
    // staging run proves the pipeline.
    expect(preg_replace('/\s+/', ' ', $roadmap))
        ->toContain('implemented, awaiting real GitHub staging acceptance');
});
