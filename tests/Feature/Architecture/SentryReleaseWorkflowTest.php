<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The GitHub side of release correlation: one shared composite action, called
 * only after a deployment has already succeeded, never able to fail a healthy
 * deployment, and never holding a Sentry credential anywhere near a server.
 */
function sentryReleaseAction(): array
{
    return Yaml::parse(File::get(base_path('.github/actions/sentry-release/action.yml')));
}

/**
 * Every place a local composite action is called, keyed by
 * "<workflow>.yml:<job>" for a workflow and "<action>/action.yml:runs" for
 * another action.
 *
 * One scanner rather than one per action: the two helpers below differ only in
 * which `uses:` value they look for, and a second copy would be a second place
 * to fix when the key shape or the search set changes.
 *
 * @return array<string, array{source: string, scope: string, step: array}>
 */
function localActionCallSites(string $uses): array
{
    $callSites = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') !== $uses) {
                    continue;
                }

                $callSites[basename($path).":{$jobName}"] = [
                    'source' => basename($path),
                    'scope' => $jobName,
                    'step' => $step,
                ];
            }
        }
    }

    foreach (glob(base_path('.github/actions/*/action.yml')) ?: [] as $path) {
        $action = Yaml::parse(File::get($path));

        foreach ((array) data_get($action, 'runs.steps', []) as $step) {
            if (data_get($step, 'uses') !== $uses) {
                continue;
            }

            $name = basename(dirname($path));

            $callSites["{$name}/action.yml:runs"] = [
                'source' => "{$name}/action.yml",
                'scope' => 'runs',
                'step' => $step,
            ];
        }
    }

    return $callSites;
}

/**
 * Every place the shared Sentry action is invoked — from a workflow job, or
 * from another composite action. the shared operation actions moved the post-rollback marker into
 * .github/actions/rollback-rateguru, and the deployment observability work moved every remaining
 * caller behind .github/actions/record-rateguru-deployment, so this scan
 * follows it there rather than losing sight of a call site.
 *
 * @return array<string, array{source: string, scope: string, step: array}>
 */
function sentryReleaseCallSites(): array
{
    return localActionCallSites('./.github/actions/sentry-release');
}

/**
 * the deployment observability work inserted one indirection: workflows no longer call the Sentry
 * action directly, they call .github/actions/record-rateguru-deployment, which
 * records the same transition in Sentry AND in Nightwatch.
 *
 * @return array<string, array{source: string, scope: string, step: array}>
 */
function deploymentRecordingCallSites(): array
{
    return localActionCallSites('./.github/actions/record-rateguru-deployment');
}

it('pins the official Sentry release action by immutable commit SHA, like every other third-party action', function () {
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');

    $uses = data_get($steps->get('Create Sentry release and deployment marker'), 'uses');

    expect($uses)->toMatch('/^getsentry\/action-release@[0-9a-f]{40}$/');

    // The same pinning convention every existing workflow already follows.
    $source = File::get(base_path('.github/actions/sentry-release/action.yml'));

    expect($source)->toContain('getsentry/action-release@ff07929a6537bac57790c3451cf4d364aca38528 # v3.7.0');

    foreach ($steps as $step) {
        $stepUses = data_get($step, 'uses');

        if (is_string($stepUses) && ! str_starts_with($stepUses, './')) {
            expect($stepUses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');
        }
    }
});

it('never lets commit association stand between a deployment and its marker', function () {
    // getsentry/action-release runs `releases new` -> `set-commits` ->
    // `deploys new` -> `finalize` in that fixed order, so a failing set-commits
    // aborts the run *before* the deployment marker exists. Associating commits
    // needs the Sentry <-> GitHub repository integration; until that is
    // configured the call cannot succeed, and continue-on-error would turn the
    // failure into a green deployment with no correlation at all.
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');
    $sentryStep = $steps->get('Create Sentry release and deployment marker');

    expect(data_get($sentryStep, 'with.set_commits'))->toBe('skip');

    // `manual` without a resolved repo/commit, or `auto`, would both reintroduce
    // the same ordering hazard — neither may appear by accident.
    expect(data_get($sentryStep, 'with'))
        ->not->toHaveKey('repo')
        ->not->toHaveKey('commit')
        ->not->toHaveKey('previous_commit');

    // The environment is what actually produces the deploy marker.
    expect(data_get($sentryStep, 'with.environment'))->toBe('${{ inputs.environment }}');

    // No call site may pass a commit either, now that the input is gone —
    // neither the Sentry action's own caller nor the four places that call it.
    foreach (array_merge(sentryReleaseCallSites(), deploymentRecordingCallSites()) as $label => $site) {
        expect(data_get($site['step'], 'with'))
            ->not->toHaveKey('commit', "{$label} must not pass a commit");
    }
});

it('still correlates the commit on the events themselves', function () {
    // Dropping release-level association costs nothing that matters, because
    // the per-event commit tag comes from the artifact's own release.json and
    // depends on nothing outside it. If that ever stopped being true, skipping
    // set_commits would become a real loss.
    expect(phpSourceWithoutComments('app/Providers/ObservabilityServiceProvider.php'))
        ->toContain("'commit' => config('deployment.commit')");
});

it('never fails a healthy deployment when Sentry is unavailable', function () {
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');

    $sentryStep = $steps->get('Create Sentry release and deployment marker');

    // The whole point: an unreachable sentry.io is an observability gap, not a
    // reason to fail the job — and never a reason to roll the application back.
    expect(data_get($sentryStep, 'continue-on-error'))->toBeTrue();

    // ...but the outcome is surfaced explicitly rather than swallowed.
    $reportStep = $steps->get('Report observability outcome');

    expect(data_get($reportStep, 'run'))
        ->toContain('observability registration FAILED')
        ->toContain('recorded=')
        ->toContain('GITHUB_STEP_SUMMARY');
});

it('degrades to a no-op when an environment has no Sentry credentials yet', function () {
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');

    expect(data_get($steps->get('Create Sentry release and deployment marker'), 'if'))
        ->toBe("\${{ steps.preflight.outputs.configured == 'true' }}");

    expect(data_get($steps->get('Validate observability inputs'), 'run'))
        ->toContain('configured=false')
        ->toContain('skipping release registration');
});

it('rejects a release ID no RateGuru deployment could have produced, before any network call', function () {
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');
    $run = data_get($steps->get('Validate observability inputs'), 'run');

    // Byte-identical to the expression the deploy action validates against, so
    // a Sentry release and a RateGuru release can never diverge in shape.
    $releaseRegex = "release_regex='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'";

    expect($run)->toContain($releaseRegex);
    expect(File::get(base_path('.github/actions/deploy-rateguru/action.yml')))->toContain($releaseRegex);

    // Environment is the class only — a brand may never become an environment.
    expect($run)->toContain('environment must be staging or production');
});

it('takes no untrusted expression into a shell, and no secret onto a command line', function () {
    $action = sentryReleaseAction();

    foreach (data_get($action, 'runs.steps') as $step) {
        if (! isset($step['run'])) {
            continue;
        }

        expect(data_get($step, 'shell'))->toBe('bash');

        // Inputs reach bash through env:, never interpolated into the script.
        expect($step['run'])->not->toContain('${{ inputs.');
    }

    $source = File::get(base_path('.github/actions/sentry-release/action.yml'));

    // The auth token is only ever an env var on the official action's step —
    // never an argument, which would expose it in a process list.
    expect($source)
        ->toContain('SENTRY_AUTH_TOKEN: ${{ inputs.sentry-auth-token }}')
        ->not->toContain('--auth-token')
        ->not->toContain('echo "${SENTRY_AUTH_TOKEN')
        ->not->toContain('SENTRY_AUTH_TOKEN}"');
});

it('is called only after a successful, health-checked deployment', function () {
    // Since the deployment observability work the Sentry action has exactly one caller: the shared
    // recording action that produces every observability marker for a
    // deployment state transition. Nothing else may call it directly.
    expect(array_keys(sentryReleaseCallSites()))->toBe([
        'record-rateguru-deployment/action.yml:runs',
    ]);

    $callSites = deploymentRecordingCallSites();

    expect(array_keys($callSites))->toEqualCanonicalizing([
        'deploy-staging.yml:observability',
        'release.yml:deploy-staging',
        'release.yml:deploy-production',
        // One rollback marker for every target, not one per workflow.
        'rollback-rateguru/action.yml:runs',
        // the controlled code alignment: a recovery that had to install code really did deploy a
        // release, and it is marked only once restore-target --resume brought
        // the target back. A restore that came back ALIGNED deployed nothing
        // and deliberately records no marker at all.
        'restore-staging.yml:observability',
        'restore-production.yml:observability',
    ]);

    $deployStaging = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));

    // A separate job that needs the deploy job: it cannot start unless deploy
    // finished successfully, and deploy only finishes after the server-side
    // health check and the active-release verification both passed.
    expect(data_get($deployStaging, 'jobs.observability.needs'))->toBe(['build', 'deploy'])
        ->and(data_get($deployStaging, 'jobs.observability.environment'))->toBe('staging')
        ->and(data_get($deployStaging, 'jobs.observability.permissions.contents'))->toBe('read');

    $release = Yaml::parse(File::get(base_path('.github/workflows/release.yml')));

    // Inside the deploy jobs, after the deployment action has already run.
    foreach (['deploy-staging' => 'staging', 'deploy-production' => 'production'] as $job => $environment) {
        $steps = collect(data_get($release, "jobs.{$job}.steps"));
        $deployIndex = $steps->search(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/deploy-rateguru');
        $recordIndex = $steps->search(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/record-rateguru-deployment');

        expect($deployIndex)->not->toBeFalse("{$job} must still deploy through the deploy action")
            ->and($recordIndex)->toBeGreaterThan($deployIndex, "the deployment marker in {$job} must come after the deployment");

        expect(data_get($release, "jobs.{$job}.environment"))->toBe($environment);
    }

    // Inside the shared rollback action, after the wrapper call succeeded —
    // which it only does once the server-side health check passed — and after
    // the restored release was read back off the target.
    $rollbackAction = Yaml::parse(File::get(base_path('.github/actions/rollback-rateguru/action.yml')));
    $names = collect(data_get($rollbackAction, 'runs.steps'))->pluck('name')->all();

    expect(array_search('Roll back via target-aware wrapper', $names, true))
        ->toBeLessThan(array_search('Resolve the release now serving the target', $names, true))
        ->and(array_search('Resolve the release now serving the target', $names, true))
        ->toBeLessThan(array_search('Record the restored release in Sentry and Nightwatch', $names, true));
});

it('records the canonical release the pipeline built, never a recomputed one', function () {
    // The identity is fixed where the deployment happened, then forwarded
    // unchanged through the shared recording action to Sentry.
    expect(data_get(sentryReleaseCallSites()['record-rateguru-deployment/action.yml:runs']['step'], 'with.release-id'))
        ->toBe('${{ inputs.release-id }}');

    $callSites = deploymentRecordingCallSites();

    expect(data_get($callSites['deploy-staging.yml:observability']['step'], 'with.release-id'))
        ->toBe('${{ needs.build.outputs.release-id }}');

    foreach (['release.yml:deploy-staging', 'release.yml:deploy-production'] as $site) {
        // The one build job's own output — never a second identity recomputed
        // for Sentry, and never one the validate job derived independently.
        expect(data_get($callSites[$site]['step'], 'with.release-id'))
            ->toBe('${{ needs.build.outputs.release-id }}');
    }

    // A rollback records the release the target was actually read back as
    // serving, inside the shared action that performed the read-back.
    expect(data_get($callSites['rollback-rateguru/action.yml:runs']['step'], 'with.release-id'))
        ->toBe('${{ steps.active.outputs.release_id }}');

    // No workflow may build a second release identifier for Sentry's benefit.
    foreach (glob(base_path('.github/actions/*/action.yml')) ?: [] as $path) {
        expect(File::get($path))->not->toContain('SENTRY_RELEASE=');
    }

    // No workflow may build a second release identifier for Sentry's benefit.
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        expect(File::get($path))->not->toContain('SENTRY_RELEASE=');
    }
});

it('uses the environment class for Sentry, never the deployment target', function () {
    // The Sentry action's one caller forwards the environment class it was
    // given; the class itself is fixed at the four call sites below.
    expect(data_get(sentryReleaseCallSites()['record-rateguru-deployment/action.yml:runs']['step'], 'with.environment'))
        ->toBe('${{ inputs.environment }}');

    $callSites = deploymentRecordingCallSites();

    $environments = collect($callSites)->map(fn (array $site): mixed => data_get($site['step'], 'with.environment'));

    expect($environments->all())->toEqualCanonicalizing([
        'deploy-staging.yml:observability' => 'staging',
        'release.yml:deploy-staging' => 'staging',
        'release.yml:deploy-production' => 'production',
        // The shared rollback action serves every target, so its environment
        // is the one its caller fixed rather than a literal of its own.
        'rollback-rateguru/action.yml:runs' => '${{ inputs.environment }}',
        // The restore workflows fix theirs, exactly like the deploy ones.
        'restore-staging.yml:observability' => 'staging',
        'restore-production.yml:observability' => 'production',
    ]);

    // ...and the callers that fix it pass an environment class, never a brand.
    $rollbackEnvironments = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') === './.github/actions/rollback-rateguru') {
                    $rollbackEnvironments[basename($path).":{$jobName}"] = data_get($step, 'with.environment');
                }
            }
        }
    }

    expect($rollbackEnvironments)->toEqual([
        'rollback-staging.yml:rollback' => 'staging',
        'rollback-production.yml:rollback' => 'production',
    ]);

    foreach ($callSites as $label => $site) {
        $environment = (string) data_get($site['step'], 'with.environment');

        foreach (['tits-guru', 'staging-main', 'food-guru'] as $target) {
            expect(str_contains($environment, $target))
                ->toBeFalse("{$label} must not turn the brand {$target} into a Sentry environment");
        }
    }
});

it('keeps the Sentry auth token in GitHub secrets and out of every server-facing surface', function () {
    $callSites = array_merge(sentryReleaseCallSites(), deploymentRecordingCallSites());

    foreach ($callSites as $label => $site) {
        // A composite action has no secrets context of its own, so it forwards
        // what its caller passed in; a workflow reads the environment secret
        // directly. Either way the token is never a literal and never a var.
        $expectedToken = str_ends_with($label, '/action.yml:runs')
            ? '${{ inputs.sentry-auth-token }}'
            : '${{ secrets.SENTRY_AUTH_TOKEN }}';

        expect(data_get($site['step'], 'with.sentry-auth-token'))
            ->toBe($expectedToken, "{$label} must take the token from environment secrets");

        // Org and project are not credentials; they follow the repository's
        // existing convention of vars for non-secret deployment coordinates.
        $expectedOrg = str_ends_with($label, '/action.yml:runs') ? '${{ inputs.sentry-org }}' : '${{ vars.SENTRY_ORG }}';
        $expectedProject = str_ends_with($label, '/action.yml:runs') ? '${{ inputs.sentry-project }}' : '${{ vars.SENTRY_PROJECT }}';

        expect(data_get($site['step'], 'with.sentry-org'))->toBe($expectedOrg)
            ->and(data_get($site['step'], 'with.sentry-project'))->toBe($expectedProject);
    }

    // And every workflow that forwards through the rollback action still takes
    // the credential from the environment secret, not from anywhere else.
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') !== './.github/actions/rollback-rateguru') {
                    continue;
                }

                expect(data_get($step, 'with.sentry-auth-token'))
                    ->toBe('${{ secrets.SENTRY_AUTH_TOKEN }}', basename($path).":{$jobName} must forward the environment secret")
                    ->and(data_get($step, 'with.sentry-org'))->toBe('${{ vars.SENTRY_ORG }}')
                    ->and(data_get($step, 'with.sentry-project'))->toBe('${{ vars.SENTRY_PROJECT }}');
            }
        }
    }

    // Nothing that is ever installed on, copied to, or read by a VPS may so
    // much as mention the auth token.
    $serverFacing = array_merge(
        glob(base_path('infrastructure/scripts/*')) ?: [],
        glob(base_path('infrastructure/templates/environment/*')) ?: [],
        glob(base_path('infrastructure/config/**/*')) ?: [],
        [base_path('infrastructure/config/deployment-targets.json'), base_path('.env.example')],
    );

    foreach ($serverFacing as $path) {
        if (! is_file($path)) {
            continue;
        }

        expect(str_contains(File::get($path), 'SENTRY_AUTH_TOKEN'))
            ->toBeFalse('SENTRY_AUTH_TOKEN must never reach the server: '.str_replace(base_path().'/', '', $path));
    }
});

it('marks a rollback as a new deployment of the same immutable release', function () {
    // The read-back, the fail-open rules around it, the release-shape guard and
    // the marker itself all live in the one shared rollback action; the two
    // operator workflows contribute only their fixed environment class.
    $action = Yaml::parse(File::get(base_path('.github/actions/rollback-rateguru/action.yml')));
    $steps = collect(data_get($action, 'runs.steps'))->keyBy('name');

    // The release is read back off the server after the rollback succeeded, so
    // mode=previous reports what the target actually landed on.
    $resolve = $steps->get('Resolve the release now serving the target');

    expect(data_get($resolve, 'run'))
        ->toContain("'basename \"\$(readlink -f %q)\"'")
        ->toContain('release_id=${active_release}')
        // A rollback that succeeded must never be failed by observability, so
        // anything that is not a canonical release ID records nothing instead.
        ->toContain('skipping the deployment markers')
        // Same rule for the read-back connection itself: this is a second SSH
        // session made only to fetch a value, and under `set -e` an unguarded
        // assignment would abort the step and fail an already-healthy rollback.
        ->toContain('if ! active_release="$(')
        ->toContain('Could not read the active release back from the target.');

    // Nothing is recorded when the release could not be resolved.
    $marker = $steps->get('Record the restored release in Sentry and Nightwatch');

    expect(data_get($marker, 'uses'))->toBe('./.github/actions/record-rateguru-deployment')
        ->and(data_get($marker, 'with.release-id'))->toBe('${{ steps.active.outputs.release_id }}')
        ->and(data_get($marker, 'if'))->toBe("\${{ steps.active.outputs.release_id != '' && steps.active.outputs.source_sha != '' }}");

    // No synthetic "rollback" release is ever created, anywhere.
    foreach ([
        '.github/actions/rollback-rateguru/action.yml',
        '.github/workflows/rollback-staging.yml',
        '.github/workflows/rollback-production.yml',
    ] as $path) {
        expect(File::get(base_path($path)))
            ->not->toMatch('/release-id:\s*[\'"]?rollback/');
    }

    // And neither operator workflow keeps a marker block of its own any more.
    foreach ([
        '.github/workflows/rollback-staging.yml' => 'Roll back staging-main',
        '.github/workflows/rollback-production.yml' => 'Roll back tits-guru',
    ] as $path => $rollbackStep) {
        $workflowSteps = collect(data_get(Yaml::parse(File::get(base_path($path))), 'jobs.rollback.steps'));

        expect($workflowSteps->pluck('uses')->all())
            ->not->toContain('./.github/actions/sentry-release', "{$path} still duplicates the Sentry marker");

        expect($workflowSteps->pluck('name')->all())->toContain($rollbackStep);
    }
});

it('leaves the deployment workflows themselves otherwise unchanged', function () {
    // the observability work adds observability; it does not redesign deployment. Every
    // deploy-rateguru call site, and the health-check ordering they rely on,
    // must still be exactly what the target-aware migration and the clean-host bootstrap established.
    $deployStaging = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));

    expect(data_get($deployStaging, 'jobs.deploy.needs'))->toBe(['resolve', 'build'])
        ->and(data_get($deployStaging, 'permissions.contents'))->toBe('read')
        ->and(data_get($deployStaging, 'concurrency.group'))->toBe('rateguru-staging-deployment');

    $rollback = Yaml::parse(File::get(base_path('.github/workflows/rollback-staging.yml')));

    expect(data_get($rollback, 'permissions.contents'))->toBe('read')
        ->and(data_get($rollback, 'concurrency.group'))->toBe('rateguru-staging-deployment');

    // No workflow was given wider permissions to make Sentry work.
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        expect(data_get($workflow, 'permissions'))->not->toBe('write-all');

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            expect(data_get($job, 'permissions'))
                ->not->toBe('write-all', "{$path}:{$jobName} must not request write-all");
        }
    }
});
