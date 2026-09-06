<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 7.1's own scope guard: the operational model it establishes, and the
 * architecture it deliberately does not build.
 *
 * The model is one BUILD, one DEPLOY and one ROLLBACK implementation, with
 * separate operator-facing workflows wherever policy differs. The rejected
 * architecture is a durable release-artifact archive — no artifact bucket, no
 * artifact credentials, no artifact retention, no backup-to-artifact mapping.
 * Recovery rebuilds from the source SHA a backup already carries.
 */

/** @return array<string, array> */
function phase71Workflows(): array
{
    $workflows = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflows[basename($path)] = Yaml::parse(File::get($path));
    }

    return $workflows;
}

/** @return list<string> */
function phase71OperationalFiles(): array
{
    return array_values(array_filter(array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
        glob(base_path('infrastructure/scripts/*')) ?: [],
        glob(base_path('infrastructure/config/*')) ?: [],
        glob(base_path('infrastructure/config/**/*')) ?: [],
        [base_path('.env.example')],
    ), 'is_file'));
}

it('has exactly one build, one deploy and one rollback implementation', function () {
    $implementations = [
        'build' => './.github/actions/build-rateguru',
        'deploy' => './.github/actions/deploy-rateguru',
        'rollback' => './.github/actions/rollback-rateguru',
    ];

    foreach ($implementations as $operation => $action) {
        expect(File::exists(base_path(mb_substr($action, 2).'/action.yml')))
            ->toBeTrue("the shared {$operation} action is missing");
    }

    // And no near-miss sibling exists: one implementation per operation, never
    // a per-environment fork of it.
    $actions = collect(glob(base_path('.github/actions/*'), GLOB_ONLYDIR) ?: [])
        ->map(fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($actions)->toBe([
        'build-rateguru',
        'deploy-rateguru',
        // Phase 7.2's two additions: one PREPARE implementation and one
        // deployment-recording implementation. Still one action per operation,
        // never a per-environment fork of any of them.
        'prepare-rateguru-host',
        'record-rateguru-deployment',
        // One REPAIR implementation, covering both environments. Transport
        // only: it carries no material and cannot deploy, restore or prepare.
        'repair-rateguru-target',
        // Phase 7.4's one addition: one RESTORE implementation, covering all
        // three of its modes and both environments.
        'restore-rateguru',
        'rollback-rateguru',
        'sentry-release',
    ]);
});

it('keeps one operator-facing workflow per environment, with no target selector anywhere', function () {
    expect(array_keys(phase71Workflows()))->toEqualCanonicalizing([
        'ci.yml',
        'coverage.yml',
        'deploy-staging.yml',
        'label-review-bot-prs.yml',
        'prepare-production-host.yml',
        'prepare-staging-host.yml',
        'release.yml',
        // One repair workflow per environment, exactly like every other
        // operator-facing operation here.
        'repair-production.yml',
        'repair-staging.yml',
        // Phase 7.4: one restore workflow per environment, exactly like every
        // other operator-facing operation here.
        'restore-production.yml',
        'restore-staging.yml',
        'rollback-production.yml',
        'rollback-staging.yml',
    ]);

    // No workflow may let an operator type, choose or otherwise supply a
    // deployment target, an environment or a wrapper path.
    foreach (phase71Workflows() as $name => $workflow) {
        foreach ((array) data_get($workflow, 'on.workflow_dispatch.inputs', []) as $input => $definition) {
            foreach (['target', 'environment', 'wrapper', 'host'] as $forbidden) {
                expect(str_contains($input, $forbidden))
                    ->toBeFalse("{$name} lets the operator select {$input}");
            }
        }
    }

    // Every deployment-target and Sentry environment is a literal, never an
    // expression an operator could influence.
    foreach (phase71Workflows() as $name => $workflow) {
        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                foreach (['deployment-target', 'environment'] as $fixed) {
                    $value = data_get($step, "with.{$fixed}");

                    if ($value === null) {
                        continue;
                    }

                    expect($value)->not->toContain('${{', "{$name}:{$jobName} computes its {$fixed} instead of fixing it");
                }
            }
        }
    }
});

it('pairs each fixed target with the environment that owns it', function () {
    $expected = [
        'deploy-staging.yml' => ['staging-main' => 'staging'],
        'release.yml' => ['staging-main' => 'staging', 'tits-guru' => 'production'],
        'rollback-staging.yml' => ['staging-main' => 'staging'],
        'rollback-production.yml' => ['tits-guru' => 'production'],
    ];

    foreach ($expected as $name => $pairs) {
        $workflow = phase71Workflows()[$name];

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                $target = data_get($step, 'with.deployment-target');

                if ($target === null) {
                    continue;
                }

                expect(array_key_exists($target, $pairs))
                    ->toBeTrue("{$name}:{$jobName} deploys to an unexpected target {$target}");

                expect(data_get($job, 'environment'))
                    ->toBe($pairs[$target], "{$name}:{$jobName} runs {$target} in the wrong GitHub Environment");
            }
        }
    }
});

it('introduces no durable release-artifact archive of any kind', function () {
    // The cancelled Phase 7.1 architecture, asserted absent by its own names
    // so it cannot creep back in under a rename of the phase.
    foreach (phase71OperationalFiles() as $path) {
        $source = File::get($path);
        $relative = str_replace(base_path().'/', '', $path);

        foreach ([
            'B2_ARTIFACT',
            'rateguru-release-artifacts',
            'archive-release-artifact',
            'fetch-release-artifact',
            'release-artifact-common',
            'artifact_retention',
            'recovery-point',
            'artifacts/<release',
        ] as $forbidden) {
            expect(str_contains($source, $forbidden))
                ->toBeFalse("{$relative} reintroduces the cancelled artifact-archive architecture: {$forbidden}");
        }
    }

    // No script, action or workflow implements one either.
    foreach ([
        'infrastructure/scripts/archive-release-artifact',
        'infrastructure/scripts/fetch-release-artifact',
        'infrastructure/scripts/release-artifact-common',
        '.github/actions/archive-release-artifact/action.yml',
        '.github/workflows/archive-release-artifact.yml',
    ] as $rejected) {
        expect(File::exists(base_path($rejected)))
            ->toBeFalse("{$rejected} belongs to the cancelled artifact-archive architecture");
    }

    // GitHub artifacts stay temporary transport: nothing outside the two
    // build call sites sets an artifact retention policy for a release.
    $retentions = [];

    foreach (phase71Workflows() as $name => $workflow) {
        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') !== './.github/actions/build-rateguru') {
                    continue;
                }

                $retentions[$name] = data_get($step, 'with.artifact-retention-days');
            }
        }
    }

    // Every build's artifact retention is a caller-owned POLICY, and each one
    // is a short, bounded window on a GitHub workflow artifact — never a
    // durable archive anything recovers from. Recovery rebuilds from the
    // source_sha a backup already carries, which is why an alignment build's
    // artifact may expire without weakening anything.
    expect($retentions)->toBe([
        'deploy-staging.yml' => '3',
        'release.yml' => '90',
        'restore-production.yml' => '7',
        'restore-staging.yml' => '7',
    ]);
});

it('records Phase 7 as the consolidated plan, with the artifact archive gone', function () {
    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    // The obsolete slice, and the metadata mapping it required, are gone.
    expect($roadmap)
        ->not->toContain('7.1 Durable immutable release artifact archive')
        ->not->toContain('Backup ↔ exact release mapping')
        ->not->toContain('artifact reference, artifact checksum')
        ->not->toContain('rateguru/artifacts/')
        ->not->toContain('retrieve the exact immutable artifact');

    // The final Phase 7 headings, in order.
    foreach ([
        '**7.1 Common operational primitives',
        '**7.2 Deployment observability + Prepare Host',
        '**7.3 Restore Target Data',
        '**7.4 GitHub Restore actions + controlled code alignment',
        '**7.5 Repair Target',
        '**7.6 Recover Host',
        '**7.7 GitHub Recover + clean-host rehearsal',
        '**7.8 Full DR acceptance, measured RPO and RTO',
    ] as $heading) {
        expect($roadmap)->toContain($heading);
    }

    $positions = array_map(
        fn (string $heading): int => (int) mb_strpos($roadmap, $heading),
        ['**7.1 ', '**7.2 ', '**7.3 ', '**7.4 ', '**7.5 ', '**7.6 ', '**7.7 ', '**7.8 '],
    );

    expect($positions)->toBe(array_values(array_filter($positions)))
        ->and($positions)->toBe(collect($positions)->sort()->values()->all(), 'the Phase 7 slices are out of order');

    // The four scopes stay explicitly distinguished.
    expect($roadmap)
        ->toContain('**Prepare Host** — produce clean, prepared infrastructure')
        ->toContain('**Restore Target Data** — restore application state onto a host that already')
        ->toContain('**Repair Target** — repair one RateGuru target')
        ->toContain('**Recover Host** — full replacement-server recovery')
        ->toContain('rebuilds the application from the exact `source_sha`');

    // Phase 6 is still the single current phase; 7.1 landing does not open 7.
    expect(substr_count($roadmap, '🚧 current'))->toBe(1);
    expect($roadmap)->toMatch('/^\|\s*7\s*\|[^|]+\|\s*⏳ planned\s*\|$/m');
});

it('implements nothing from Phase 7.5 onwards', function () {
    // Prepare Host landed in Phase 7.2, Restore Target Data in 7.3 and the
    // GitHub restore surface with controlled code alignment in 7.4; each has
    // its own scope guard (Phase72ScopeTest, Phase73ScopeTest,
    // Phase74ScopeTest), and target-scoped repair followed. Recover remains
    // future work, and nothing may ship an implementation of it.
    foreach ([
        'infrastructure/scripts/recover-host',
        '.github/workflows/recover-staging.yml',
        '.github/workflows/recover-production.yml',
        '.github/actions/recover-rateguru-host/action.yml',
    ] as $futureWork) {
        expect(File::exists(base_path($futureWork)))
            ->toBeFalse("{$futureWork} is Phase 7.5+ work and must not exist yet");
    }

    // restore-test stays what it always was: a scratch-database integrity
    // check, never a live restore. Phase 7.3's live restore is a separate
    // primitive built beside it, not a mutation of it.
    expect(File::exists(base_path('infrastructure/scripts/restore-test')))->toBeTrue();
    expect(File::get(base_path('infrastructure/scripts/restore-test')))
        ->toContain('rateguru_restore_')
        ->not->toContain('ALTER DATABASE');
});

it('leaves every accepted Phase 4 and Phase 5 primitive in place', function () {
    // Phase 7.1 removes duplicated orchestration; it does not collapse the
    // operational scripts, whose ownership boundaries and side effects differ.
    foreach ([
        'deploy',
        'rollback',
        'cleanup',
        'backup',
        'backup-cycle',
        'restore-test',
        'offsite-backup',
        'offsite-retention',
        'offsite-restore-test',
        'bootstrap-host',
        'bootstrap-host-preflight',
        'install-bootstrap-runtime',
        'install-bootstrap-host-layout',
        'install-bootstrap-services',
        'install-target-operations',
        'install-target-perimeter',
        'install-public-storage-access',
        'health-check',
        'status',
        'common',
        'verify-required-clis',
    ] as $script) {
        expect(File::exists(base_path("infrastructure/scripts/{$script}")))
            ->toBeTrue("infrastructure/scripts/{$script} must not be removed or merged away");
    }

    foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup', 'rateguru-restore'] as $wrapper) {
        expect(File::exists(base_path("infrastructure/config/wrappers/{$wrapper}")))
            ->toBeTrue("the generic {$wrapper} wrapper must stay the privilege boundary");
    }

    expect(File::exists(base_path('infrastructure/config/deployment-targets.json')))->toBeTrue();
});

it('serializes every mutation of the same target in the GitHub orchestration layer too', function () {
    // The final operational model promises that two operations mutating the
    // same target cannot run at once. The server-side deployment lock is the
    // thing that actually enforces integrity; GitHub concurrency exists so one
    // workflow does not fail merely because another was already holding it.
    //
    // Every place a target is mutated, and the group that must cover it:
    //
    // Phase 7.2 added two operations that name a target: preparing its host,
    // which reconfigures the machine a deployed release runs on and therefore
    // belongs in the same domain, and recording an already-completed
    // deployment, which mutates nothing on the target but inherits the domain
    // of the workflow it reports on.
    //
    // Phase 7.4 added four more per restore workflow, and they are the reason
    // the group is declared at WORKFLOW level there rather than per job: the
    // restore, the controlled alignment deploy and the resume are one logical
    // mutation of one target, and a deploy or rollback slipping in between two
    // of them would move `current` away from the commit the restored data
    // belongs to while the target is held and cannot object.
    $mutations = [
        'deploy-staging.yml:deploy' => ['staging-main', 'rateguru-staging-deployment'],
        'deploy-staging.yml:observability' => ['staging-main', 'rateguru-staging-deployment'],
        'rollback-staging.yml:rollback' => ['staging-main', 'rateguru-staging-deployment'],
        'release.yml:deploy-staging' => ['staging-main', 'rateguru-staging-deployment'],
        'release.yml:deploy-production' => ['tits-guru', 'rateguru-production-release'],
        'rollback-production.yml:rollback' => ['tits-guru', 'rateguru-production-release'],
        'prepare-staging-host.yml:prepare' => ['staging-main', 'rateguru-staging-deployment'],
        'prepare-production-host.yml:prepare' => ['tits-guru', 'rateguru-production-release'],
        'restore-staging.yml:restore' => ['staging-main', 'rateguru-staging-deployment'],
        'restore-staging.yml:align' => ['staging-main', 'rateguru-staging-deployment'],
        'restore-staging.yml:resume' => ['staging-main', 'rateguru-staging-deployment'],
        'restore-staging.yml:observability' => ['staging-main', 'rateguru-staging-deployment'],
        'restore-production.yml:restore' => ['tits-guru', 'rateguru-production-release'],
        'restore-production.yml:align' => ['tits-guru', 'rateguru-production-release'],
        'restore-production.yml:resume' => ['tits-guru', 'rateguru-production-release'],
        'restore-production.yml:observability' => ['tits-guru', 'rateguru-production-release'],
        // A repair converges the infrastructure a release runs inside, so it
        // shares the domain of every other mutation of that target.
        'repair-staging.yml:repair' => ['staging-main', 'rateguru-staging-deployment'],
        'repair-production.yml:repair' => ['tits-guru', 'rateguru-production-release'],
    ];

    $found = [];

    foreach (phase71Workflows() as $name => $workflow) {
        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            $target = collect(data_get($job, 'steps', []))
                ->map(fn (array $step): mixed => data_get($step, 'with.deployment-target'))
                ->first(fn (mixed $value): bool => is_string($value) && $value !== '');

            if ($target === null) {
                continue;
            }

            // A job-level group wins where present; otherwise the workflow's.
            $concurrency = data_get($job, 'concurrency') ?? data_get($workflow, 'concurrency');

            $found["{$name}:{$jobName}"] = [$target, data_get($concurrency, 'group')];

            expect(data_get($concurrency, 'cancel-in-progress'))
                ->toBeFalse("{$name}:{$jobName} must never cancel a deployment in flight");
        }
    }

    expect($found)->toEqual($mutations);

    // Both rollback workflows sit in the same domain as the workflow that
    // deploys their target, so a rollback and a deploy cannot interleave.
    $groups = collect(phase71Workflows())->map(fn (array $workflow): mixed => data_get($workflow, 'concurrency.group'));

    expect($groups['rollback-staging.yml'])->toBe($groups['deploy-staging.yml'])
        ->and($groups['rollback-production.yml'])->toBe($groups['release.yml'])
        ->and($groups['release.yml'])->toBe('rateguru-production-release')
        ->and($groups['prepare-staging-host.yml'])->toBe($groups['deploy-staging.yml'])
        ->and($groups['prepare-production-host.yml'])->toBe($groups['release.yml'])
        ->and($groups['restore-staging.yml'])->toBe($groups['deploy-staging.yml'])
        ->and($groups['restore-production.yml'])->toBe($groups['release.yml'])
        ->and($groups['repair-staging.yml'])->toBe($groups['deploy-staging.yml'])
        ->and($groups['repair-production.yml'])->toBe($groups['release.yml']);

    // ...and GitHub concurrency never replaced the server-side lock.
    expect(File::get(base_path('infrastructure/scripts/common')))->toContain('flock');
});
