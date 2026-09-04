<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The one canonical GitHub-side rollback implementation, shared by both environments.
 *
 * Transport and orchestration only: every rollback safety rule — deployment
 * lock, target lifecycle, release path validation, the atomic current/previous
 * switch, PHP-FPM handling, the health check with automatic restore and the
 * rollback history — stays in infrastructure/scripts/rollback behind the
 * generic sudo wrapper, and is never mirrored here.
 */
function rollbackRateGuruActionPath(): string
{
    return base_path('.github/actions/rollback-rateguru/action.yml');
}

function rollbackRateGuruAction(): array
{
    return Yaml::parse(File::get(rollbackRateGuruActionPath()));
}

function rollbackRateGuruStep(string $name): array
{
    $step = collect(data_get(rollbackRateGuruAction(), 'runs.steps'))->keyBy('name')->get($name);

    expect($step)->not->toBeNull("the rollback action has no step named {$name}");

    return $step;
}

/**
 * Runs the action's own "Validate rollback inputs" script — the real one,
 * extracted from the action — against a set of inputs.
 *
 * @return array{0: int, 1: string}
 */
function runRollbackInputValidation(array $overrides = []): array
{
    $env = array_merge([
        'DEPLOYMENT_TARGET' => 'staging-main',
        'ENVIRONMENT' => 'staging',
        'MODE' => 'previous',
        'RELEASE_ID' => '',
        'DEPLOY_HOST' => 'staging.example.test',
        'DEPLOY_PORT' => '22',
        'DEPLOY_USER' => 'deploy-rateguru-staging',
    ], $overrides);

    $assignments = collect($env)
        ->map(fn (string $value, string $name): string => $name.'='.escapeshellarg($value))
        ->implode(' ');

    $command = 'env '.$assignments.' bash -c '.escapeshellarg(data_get(rollbackRateGuruStep('Validate rollback inputs'), 'run')).' 2>&1';

    $output = [];
    $exit = 0;
    exec($command, $output, $exit);

    return [$exit, implode("\n", $output)];
}

it('defines a hardened reusable RateGuru rollback action', function () {
    expect(File::exists(rollbackRateGuruActionPath()))->toBeTrue();

    $action = rollbackRateGuruAction();
    $steps = collect(data_get($action, 'runs.steps'));

    expect(data_get($action, 'name'))->toBe('Roll back RateGuru target')
        ->and(data_get($action, 'runs.using'))->toBe('composite');

    expect($steps->pluck('name')->all())->toBe([
        'Validate rollback inputs',
        'Configure SSH',
        'Roll back via target-aware wrapper',
        'Resolve the release now serving the target',
        // the deployment observability work: one step records the restored release in every
        // observability system, through the shared action, rather than a
        // Sentry-only step here.
        'Record the restored release in Sentry and Nightwatch',
        'Write rollback summary',
        'Remove temporary SSH material',
    ]);

    foreach ([
        'deployment-target',
        'environment',
        'deploy-host',
        'deploy-user',
        'deploy-root',
        'ssh-private-key',
        'known-hosts',
        'mode',
    ] as $required) {
        expect(data_get($action, "inputs.{$required}.required"))
            ->toBeTrue("{$required} must be a required input");
    }

    expect(data_get($action, 'inputs.deploy-port.default'))->toBe('22')
        ->and(data_get($action, 'inputs.release-id.required'))->toBeFalse()
        ->and(data_get($action, 'inputs.release-id.default'))->toBe('');

    // Observability is secondary to the rollback, so its coordinates are
    // optional and default to nothing: an environment with no Sentry
    // credentials rolls back exactly the same way, marker or no marker.
    foreach (['sentry-auth-token', 'sentry-org', 'sentry-project'] as $observability) {
        expect(data_get($action, "inputs.{$observability}.required"))
            ->toBeFalse("{$observability} must never be required to roll a target back")
            ->and(data_get($action, "inputs.{$observability}.default"))->toBe('');
    }

    // The read-back release and its commit are the only things a caller gets
    // out of a rollback — both resolved from the server, never from the
    // request.
    expect(data_get($action, 'outputs.active-release-id.value'))
        ->toBe('${{ steps.active.outputs.release_id }}');
    expect(data_get($action, 'outputs.active-source-sha.value'))
        ->toBe('${{ steps.active.outputs.source_sha }}');

    // Every run script takes its inputs through env, never interpolation.
    // The observability step is a `uses:` and has no script to interpolate
    // into, so it is skipped explicitly rather than silently passing on null.
    foreach ($steps as $step) {
        if (is_string(data_get($step, 'uses'))) {
            expect(data_get($step, 'uses'))->toBe('./.github/actions/record-rateguru-deployment');

            continue;
        }

        expect(data_get($step, 'shell'))->toBe('bash')
            ->and(data_get($step, 'run'))->not->toContain('${{ inputs.');
    }
});

it('is the only GitHub rollback implementation, used by both operator workflows', function () {
    $callSites = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') === './.github/actions/rollback-rateguru') {
                    $callSites[] = basename($path).":{$jobName}";
                }
            }
        }
    }

    expect($callSites)->toEqualCanonicalizing([
        'rollback-staging.yml:rollback',
        'rollback-production.yml:rollback',
    ]);

    // No workflow may keep its own copy of the rollback transport. Comment
    // lines are excluded: other workflows legitimately explain, in prose, that
    // the credential they use is NOT the one the rollback wrappers accept.
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $executable = executableSourceLines(File::get($path));

        foreach (['rateguru-rollback', 'ssh-keygen', 'readlink -f'] as $mechanic) {
            expect(str_contains($executable, $mechanic))
                ->toBeFalse(basename($path).' re-implements the shared rollback transport: '.$mechanic);
        }
    }
});

it('owns the one post-rollback deployment marker, and lets it fail without failing the rollback', function () {
    $marker = rollbackRateGuruStep('Record the restored release in Sentry and Nightwatch');

    // Delegates to the shared recording action — which reuses the Sentry
    // action and is fail-open throughout — rather than reimplementing either
    // observability call. Both identities must have been resolved from the
    // server first.
    expect(data_get($marker, 'uses'))->toBe('./.github/actions/record-rateguru-deployment')
        ->and(data_get($marker, 'if'))->toBe("\${{ steps.active.outputs.release_id != '' && steps.active.outputs.source_sha != '' }}")
        ->and(data_get($marker, 'with.release-id'))->toBe('${{ steps.active.outputs.release_id }}')
        ->and(data_get($marker, 'with.source-sha'))->toBe('${{ steps.active.outputs.source_sha }}')
        ->and(data_get($marker, 'with.deployment-target'))->toBe('${{ inputs.deployment-target }}')
        ->and(data_get($marker, 'with.environment'))->toBe('${{ inputs.environment }}')
        ->and(data_get($marker, 'with.sentry-auth-token'))->toBe('${{ inputs.sentry-auth-token }}')
        ->and(data_get($marker, 'with.sentry-org'))->toBe('${{ inputs.sentry-org }}')
        ->and(data_get($marker, 'with.sentry-project'))->toBe('${{ inputs.sentry-project }}');

    $sentryStep = collect(data_get(Yaml::parse(File::get(base_path('.github/actions/sentry-release/action.yml'))), 'runs.steps'))
        ->keyBy('name')
        ->get('Create Sentry release and deployment marker');

    expect(data_get($sentryStep, 'continue-on-error'))
        ->toBeTrue('the shared observability action must stay fail-open');

    // Cleanup still happens even though a step was inserted before it.
    $names = collect(data_get(rollbackRateGuruAction(), 'runs.steps'))->pluck('name')->all();

    expect(array_search('Record the restored release in Sentry and Nightwatch', $names, true))
        ->toBeLessThan(array_search('Remove temporary SSH material', $names, true));

    // Exactly one implementation: neither operator workflow may carry a copy.
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        $callsRollback = collect(data_get($workflow, 'jobs.rollback.steps', []))
            ->contains(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/rollback-rateguru');

        if (! $callsRollback) {
            continue;
        }

        expect(collect(data_get($workflow, 'jobs.rollback.steps'))->pluck('uses')->all())
            ->not->toContain('./.github/actions/sentry-release')
            ->not->toContain('./.github/actions/record-rateguru-deployment');
    }
});

it('validates every input combination before any SSH material or connection exists', function () {
    $names = collect(data_get(rollbackRateGuruAction(), 'runs.steps'))->pluck('name')->all();

    expect(array_search('Validate rollback inputs', $names, true))
        ->toBeLessThan(array_search('Configure SSH', $names, true))
        ->and(array_search('Configure SSH', $names, true))
        ->toBeLessThan(array_search('Roll back via target-aware wrapper', $names, true));

    [$exit, $output] = runRollbackInputValidation();
    expect($exit)->toBe(0, "the validator rejected a correct set of inputs:\n{$output}");

    [$exit] = runRollbackInputValidation(['MODE' => 'release', 'RELEASE_ID' => 'v0.5.0-20260826-120211-ca7d1c7']);
    expect($exit)->toBe(0, 'an explicit release rollback must be accepted');

    $rejections = [
        'a release ID in previous mode' => [['RELEASE_ID' => 'v0.5.0-20260826-120211-ca7d1c7'], 'release-id must be empty when mode=previous'],
        'a missing release ID in release mode' => [['MODE' => 'release'], 'release-id is required when mode=release'],
        'an unknown mode' => [['MODE' => 'rollforward'], 'Invalid mode: rollforward'],
        'a flag-shaped target' => [['DEPLOYMENT_TARGET' => '--all'], 'Invalid deployment target'],
        'a target with a path in it' => [['DEPLOYMENT_TARGET' => 'a/../b'], 'Invalid deployment target'],
        'an empty target' => [['DEPLOYMENT_TARGET' => ''], 'Invalid deployment target'],
        'a brand as the environment' => [['ENVIRONMENT' => 'tits-guru'], 'environment must be staging or production'],
        'an unconfigured host' => [['DEPLOY_HOST' => ''], 'DEPLOY_HOST is not configured for the staging environment'],
        'an unconfigured port' => [['DEPLOY_PORT' => ''], 'DEPLOY_PORT is not configured for the staging environment'],
        'an unconfigured user' => [['DEPLOY_USER' => ''], 'DEPLOY_USER is not configured for the staging environment'],
    ];

    foreach ($rejections as $case => [$overrides, $message]) {
        [$exit, $output] = runRollbackInputValidation($overrides);

        expect($exit)->not->toBe(0, "the validator accepted {$case}");
        expect(str_contains($output, $message))
            ->toBeTrue("wrong diagnostic for {$case}: {$output}");
    }

    // An unprovisioned production environment names its own environment in the
    // diagnostic rather than silently falling back to staging's configuration.
    [$exit, $output] = runRollbackInputValidation([
        'DEPLOYMENT_TARGET' => 'tits-guru',
        'ENVIRONMENT' => 'production',
        'DEPLOY_HOST' => '',
    ]);

    expect($exit)->not->toBe(0);
    expect(str_contains($output, 'DEPLOY_HOST is not configured for the production environment'))
        ->toBeTrue($output);
});

it('invokes the generic target-aware wrapper as a quoted argv, and nothing else', function () {
    $rollback = rollbackRateGuruStep('Roll back via target-aware wrapper');

    expect(data_get($rollback, 'id'))->toBe('rollback')
        ->and(data_get($rollback, 'env.DEPLOYMENT_TARGET'))->toBe('${{ inputs.deployment-target }}')
        ->and(data_get($rollback, 'env.MODE'))->toBe('${{ inputs.mode }}')
        ->and(data_get($rollback, 'env.RELEASE_ID'))->toBe('${{ inputs.release-id }}');

    expect(data_get($rollback, 'run'))
        ->toContain('sudo -n /usr/local/sbin/rateguru-rollback')
        ->toContain('--target "${DEPLOYMENT_TARGET}"')
        ->toContain('remote_command+=(--previous)')
        ->toContain('remote_command+=(--release "${RELEASE_ID}")')
        ->toContain('"${remote_command[@]@Q}"')
        ->toContain('-o BatchMode=yes')
        ->toContain('-o IdentitiesOnly=yes')
        ->toContain('-o ConnectTimeout=')
        ->toContain('-o StrictHostKeyChecking=yes')
        ->toContain('-o UserKnownHostsFile=');

    // Not one piece of server-side business logic is duplicated here.
    $source = File::get(rollbackRateGuruActionPath());

    foreach ([
        'flock',
        'systemctl',
        'target_lifecycle',
        'require_active_target',
        '/releases/',
        'ln -sfn',
        'rollback-history',
    ] as $serverSideConcern) {
        expect(str_contains($source, $serverSideConcern))
            ->toBeFalse("rollback business logic leaked into GitHub: {$serverSideConcern}");
    }
});

it('never fails an already-healthy rollback for an observability reason', function () {
    $resolve = rollbackRateGuruStep('Resolve the release now serving the target');

    expect(data_get($resolve, 'id'))->toBe('active')
        ->and(data_get($resolve, 'run'))
        ->toContain("'basename \"\$(readlink -f %q)\"'")
        ->toContain('release_id=${active_release}')
        // the deployment observability work: the commit is resolved alongside the release, from that
        // release's own release.json, because a rollback request carries none.
        ->toContain('source_sha=${active_sha}')
        ->toContain("jq -r '.source_sha // empty'")
        ->toContain('skipping the deployment markers')
        ->toContain('if ! active_release="$(')
        ->toContain('Could not read the active release back from the target.')
        ->toContain('Could not read release.json back from the target.')
        ->toContain('DEPLOY_ROOT is not configured; cannot resolve the active release.')
        ->toContain("release_regex='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'");

    // The read-back can only follow a successful wrapper call.
    $names = collect(data_get(rollbackRateGuruAction(), 'runs.steps'))->pluck('name')->all();

    expect(array_search('Roll back via target-aware wrapper', $names, true))
        ->toBeLessThan(array_search('Resolve the release now serving the target', $names, true));
});

it('always reports the outcome and always removes the SSH material', function () {
    $summary = rollbackRateGuruStep('Write rollback summary');

    expect(data_get($summary, 'if'))->toBe('${{ always() }}')
        ->and(data_get($summary, 'env.ROLLBACK_OUTCOME'))->toBe('${{ steps.rollback.outcome }}')
        ->and(data_get($summary, 'run'))
        ->toContain('GITHUB_STEP_SUMMARY')
        ->toContain('Target: ${DEPLOYMENT_TARGET}')
        ->toContain('Environment: ${ENVIRONMENT}')
        ->toContain('Mode: ${MODE}')
        ->toContain('Result: ${ROLLBACK_OUTCOME:-not attempted}');

    $cleanup = rollbackRateGuruStep('Remove temporary SSH material');

    expect(data_get($cleanup, 'if'))->toBe('${{ always() }}')
        ->and(data_get($cleanup, 'run'))
        ->toContain('RATEGURU_SSH_KEY_PATH')
        ->toContain('RATEGURU_KNOWN_HOSTS_PATH');
});

it('keeps every SSH hardening property the deploy action already has', function () {
    $source = File::get(rollbackRateGuruActionPath());

    expect(data_get(rollbackRateGuruStep('Configure SSH'), 'env.SSH_PRIVATE_KEY'))
        ->toBe('${{ inputs.ssh-private-key }}')
        ->and(data_get(rollbackRateGuruStep('Configure SSH'), 'env.KNOWN_HOSTS'))
        ->toBe('${{ inputs.known-hosts }}')
        ->and(data_get(rollbackRateGuruStep('Configure SSH'), 'run'))
        ->toContain('install -m 0600 /dev/null')
        ->toContain('ssh-keygen -y');

    expect($source)
        ->not->toMatch('/\beval\b/')
        ->not->toContain('bash -c')
        ->not->toContain('StrictHostKeyChecking=no')
        ->not->toContain('StrictHostKeyChecking=accept-new')
        ->not->toContain('root@')
        ->not->toContain('--environment')
        // The key is written to a file and never echoed anywhere.
        ->not->toContain('echo "${SSH_PRIVATE_KEY')
        ->not->toContain('echo "${KNOWN_HOSTS');

    // No target ID is hard-coded in the shared implementation: the caller
    // fixes it, so a new target never needs a new rollback implementation.
    foreach (['staging-main', 'tits-guru'] as $target) {
        expect(str_contains($source, $target))
            ->toBeFalse("the shared rollback action must not know about {$target}");
    }
});
