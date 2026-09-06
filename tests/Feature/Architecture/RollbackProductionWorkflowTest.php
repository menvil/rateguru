<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The production rollback workflow, built on the shared operation actions. Structurally the
 * staging one with a different fixed identity: same operator semantics, same
 * shared implementation, a different target, environment and concurrency
 * domain — and none of them selectable at dispatch time.
 */
beforeEach(function () {
    $path = base_path('.github/workflows/rollback-production.yml');

    expect(File::exists($path))->toBeTrue();

    $this->source = File::get($path);
    $this->workflow = Yaml::parse($this->source);
    $this->steps = collect(data_get($this->workflow, 'jobs.rollback.steps'));
    $this->stepsByName = $this->steps->keyBy('name');
});

it('rolls back production manually, through the same shared implementation', function () {
    expect(data_get($this->workflow, 'name'))->toBe('Rollback production')
        ->and(array_keys($this->workflow['on']))->toBe(['workflow_dispatch'])
        ->and($this->workflow['permissions'])->toBe(['contents' => 'read'])
        ->and(array_keys($this->workflow['jobs']))->toBe(['rollback'])
        ->and(data_get($this->workflow, 'jobs.rollback.runs-on'))->toBe('ubuntu-latest');

    $rollback = $this->stepsByName->get('Roll back tits-guru');

    expect(data_get($rollback, 'uses'))->toBe('./.github/actions/rollback-rateguru')
        ->and(data_get($rollback, 'id'))->toBe('rollback');

    // Byte-for-byte the same shared action the staging workflow calls.
    $staging = collect(data_get(Yaml::parse(File::get(base_path('.github/workflows/rollback-staging.yml'))), 'jobs.rollback.steps'))
        ->keyBy('name')
        ->get('Roll back staging-main');

    expect(data_get($rollback, 'uses'))->toBe(data_get($staging, 'uses'));

    // The two workflows differ only in their fixed identity; everything the
    // operator can influence is passed through identically.
    foreach ([
        'mode',
        'release-id',
        'deploy-host',
        'deploy-port',
        'deploy-user',
        'deploy-root',
        'ssh-private-key',
        'known-hosts',
        'sentry-auth-token',
        'sentry-org',
        'sentry-project',
    ] as $input) {
        expect(data_get($rollback, "with.{$input}"))
            ->toBe(data_get($staging, "with.{$input}"), "{$input} diverges between the two rollback workflows");
    }
});

it('is structurally pinned to tits-guru and the production environment', function () {
    $rollback = $this->stepsByName->get('Roll back tits-guru');

    expect(data_get($rollback, 'with.deployment-target'))->toBe('tits-guru')
        ->and(data_get($rollback, 'with.environment'))->toBe('production')
        ->and(data_get($this->workflow, 'jobs.rollback.environment'))->toBe('production');

    // The operator may choose what to roll back to, never where.
    expect(array_keys((array) data_get($this->workflow, 'on.workflow_dispatch.inputs')))
        ->toBe(['mode', 'release-id']);

    expect($this->source)
        ->not->toContain('staging-main')
        ->not->toContain('environment: staging');
});

it('supports the same previous / explicit release modes as staging', function () {
    $stagingWorkflow = Yaml::parse(File::get(base_path('.github/workflows/rollback-staging.yml')));

    expect(data_get($this->workflow, 'on.workflow_dispatch.inputs'))
        ->toBe(data_get($stagingWorkflow, 'on.workflow_dispatch.inputs'));

    expect(data_get($this->workflow, 'on.workflow_dispatch.inputs.mode.options'))
        ->toBe(['previous', 'release'])
        ->and(data_get($this->workflow, 'on.workflow_dispatch.inputs.mode.default'))
        ->toBe('previous');
});

it('cannot mutate tits-guru concurrently with the workflow that deploys it', function () {
    // release.yml is the only workflow that deploys to a production target, so
    // a production rollback shares its concurrency domain exactly the way the
    // staging rollback shares deploy-staging.yml's.
    $release = Yaml::parse(File::get(base_path('.github/workflows/release.yml')));

    expect($this->workflow['concurrency'])->toBe($release['concurrency'])
        ->and(data_get($this->workflow, 'concurrency.group'))->toBe('rateguru-production-release')
        ->and(data_get($this->workflow, 'concurrency.cancel-in-progress'))->toBeFalse();
});

it('fails closed while production is unprovisioned, without weakening any gate', function () {
    // tits-guru is still lifecycle=planned. Nothing in this workflow tries to
    // work around that: the gate is server-side, the DEPLOY_* configuration
    // comes from the production GitHub Environment, and the shared action
    // stops on the missing configuration before any SSH connection is made.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);

    expect(data_get($registry, 'targets.tits-guru.lifecycle'))->toBe('planned');

    expect($this->source)
        ->not->toContain('continue-on-error')
        ->not->toContain('|| true')
        ->not->toContain('RATEGURU_ALLOW_TEST_OVERRIDES')
        ->not->toContain('--force')
        // The gate is server-side; nothing here re-decides it.
        ->not->toContain('lifecycle=active')
        ->not->toContain('lifecycle:');

    // Nothing here provisions or activates anything.
    foreach (['install-target', 'bootstrap-host', 'deployment-targets.json'] as $forbidden) {
        expect(str_contains($this->source, $forbidden))
            ->toBeFalse("the production rollback workflow must not {$forbidden}");
    }
});

it('takes deployment tooling from develop and keeps the same closed secret set', function () {
    $checkout = $this->stepsByName->get('Checkout rollback and observability actions');

    expect(data_get($checkout, 'uses'))->toMatch('/^actions\/checkout@[0-9a-f]{40}$/')
        ->and(data_get($checkout, 'with.ref'))->toBe('develop')
        ->and(data_get($checkout, 'with.persist-credentials'))->toBeFalse();

    expect($this->steps->filter(fn (array $step): bool => isset($step['run']))->all())->toBe([]);

    expect($this->source)
        ->not->toMatch('/\beval\b/')
        ->not->toContain('bash -c')
        ->not->toContain('StrictHostKeyChecking=no')
        ->not->toContain('StrictHostKeyChecking=accept-new')
        ->not->toContain('root@')
        ->not->toContain('--environment');

    preg_match_all('/\$\{\{\s*vars\.([A-Z_]+)\s*\}\}/', $this->source, $varMatches);
    preg_match_all('/\$\{\{\s*secrets\.([A-Z_]+)\s*\}\}/', $this->source, $secretMatches);

    expect(array_values(array_unique($varMatches[1])))
        ->toEqualCanonicalizing(['DEPLOY_HOST', 'DEPLOY_PORT', 'DEPLOY_USER', 'DEPLOY_ROOT', 'SENTRY_ORG', 'SENTRY_PROJECT'])
        ->and(array_values(array_unique($secretMatches[1])))
        ->toEqualCanonicalizing(['DEPLOY_SSH_KEY', 'DEPLOY_KNOWN_HOSTS', 'SENTRY_AUTH_TOKEN']);
});

it('delegates the restored-release marker to the same shared implementation', function () {
    // No marker block of its own: the shared rollback action records it, using
    // the coordinates this workflow forwards and the environment it fixes.
    expect($this->steps->pluck('uses')->all())
        ->not->toContain('./.github/actions/sentry-release');

    expect($this->steps->pluck('name')->all())->toBe([
        'Checkout rollback and observability actions',
        'Roll back tits-guru',
    ]);

    expect(data_get($this->stepsByName->get('Roll back tits-guru'), 'with.environment'))
        ->toBe('production');

    expect($this->source)
        ->not->toContain('active-release-id')
        ->not->toMatch('/release-id:\s*[\'"]?rollback/');
});
