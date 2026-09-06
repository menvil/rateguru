<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The staging rollback workflow after the shared operation actions: a thin operator-facing shell
 * whose target and environment are structural, calling the one shared
 * .github/actions/rollback-rateguru implementation
 * (RollbackRateGuruActionTest owns that contract).
 */
it('rolls back staging manually, through the fixed target-aware wrapper only', function () {
    $path = base_path('.github/workflows/rollback-staging.yml');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);
    $workflow = Yaml::parse($source);
    $steps = collect(data_get($workflow, 'jobs.rollback.steps'));
    $stepsByName = $steps->keyBy('name');

    // Manual-only: workflow_dispatch is the one and only trigger.
    expect($workflow)->toBeArray()
        ->and(data_get($workflow, 'name'))->toBe('Rollback staging')
        ->and(array_keys($workflow['on']))->toBe(['workflow_dispatch'])
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.mode.type'))->toBe('choice')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.mode.options'))->toBe(['previous', 'release'])
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.mode.default'))->toBe('previous')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.mode.required'))->toBeTrue()
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.release-id.type'))->toBe('string')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.release-id.required'))->toBeFalse();

    // The operator chooses what to roll back to — never which target.
    expect(array_keys((array) data_get($workflow, 'on.workflow_dispatch.inputs')))
        ->toBe(['mode', 'release-id']);

    // Minimal permissions, the staging environment boundary, one job.
    expect($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and(array_keys($workflow['jobs']))->toBe(['rollback'])
        ->and(data_get($workflow, 'jobs.rollback.environment'))->toBe('staging')
        ->and(data_get($workflow, 'jobs.rollback.runs-on'))->toBe('ubuntu-latest');

    // Identical concurrency domain as the staging deploy workflow: a rollback
    // queues behind (and is never cancelled by) a staging deploy.
    $deployWorkflow = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
    expect($workflow['concurrency'])->toBe($deployWorkflow['concurrency'])
        ->and(data_get($workflow, 'concurrency.group'))->toBe('rateguru-staging-deployment')
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse();

    // The shared implementation, with staging's fixed identity and the two
    // operator inputs passed straight through.
    $rollback = $stepsByName->get('Roll back staging-main');

    expect(data_get($rollback, 'uses'))->toBe('./.github/actions/rollback-rateguru')
        ->and(data_get($rollback, 'id'))->toBe('rollback')
        ->and(data_get($rollback, 'with.deployment-target'))->toBe('staging-main')
        ->and(data_get($rollback, 'with.environment'))->toBe('staging')
        ->and(data_get($rollback, 'with.mode'))->toBe('${{ inputs.mode }}')
        ->and(data_get($rollback, 'with.release-id'))->toBe('${{ inputs.release-id }}')
        ->and(data_get($rollback, 'with.deploy-host'))->toBe('${{ vars.DEPLOY_HOST }}')
        ->and(data_get($rollback, 'with.deploy-port'))->toBe('${{ vars.DEPLOY_PORT }}')
        ->and(data_get($rollback, 'with.deploy-user'))->toBe('${{ vars.DEPLOY_USER }}')
        ->and(data_get($rollback, 'with.deploy-root'))->toBe('${{ vars.DEPLOY_ROOT }}')
        ->and(data_get($rollback, 'with.ssh-private-key'))->toBe('${{ secrets.DEPLOY_SSH_KEY }}')
        ->and(data_get($rollback, 'with.known-hosts'))->toBe('${{ secrets.DEPLOY_KNOWN_HOSTS }}')
        // Observability coordinates are forwarded, not re-implemented here.
        ->and(data_get($rollback, 'with.sentry-auth-token'))->toBe('${{ secrets.SENTRY_AUTH_TOKEN }}')
        ->and(data_get($rollback, 'with.sentry-org'))->toBe('${{ vars.SENTRY_ORG }}')
        ->and(data_get($rollback, 'with.sentry-project'))->toBe('${{ vars.SENTRY_PROJECT }}');

    // Two steps, both of them `uses:` — a checkout and the shared action.
    expect($steps->pluck('name')->all())->toBe([
        'Checkout rollback and observability actions',
        'Roll back staging-main',
    ]);

    // Deployment tooling is taken from develop, never from a release ref.
    $checkout = $stepsByName->get('Checkout rollback and observability actions');

    expect(data_get($checkout, 'uses'))->toMatch('/^actions\/checkout@[0-9a-f]{40}$/')
        ->and(data_get($checkout, 'with.ref'))->toBe('develop')
        ->and(data_get($checkout, 'with.persist-credentials'))->toBeFalse();

    // The workflow itself holds no shell logic at all any more.
    expect($steps->filter(fn (array $step): bool => isset($step['run']))->all())->toBe([]);

    // No forbidden constructs anywhere in the workflow: no eval, no bash -c,
    // no disabled host-key checking, no root SSH, no legacy --environment
    // selector, no production target.
    expect($source)
        ->not->toMatch('/\beval\b/')
        ->not->toContain('bash -c')
        ->not->toContain('StrictHostKeyChecking=no')
        ->not->toContain('StrictHostKeyChecking=accept-new')
        ->not->toContain('root@')
        ->not->toContain('--environment')
        ->not->toContain('tits-guru');

    // The complete, closed set of repository variables and secrets this
    // workflow may reference. Adding one has to be a deliberate edit here.
    //
    // DEPLOY_ROOT is the same staging-environment variable deploy-staging.yml
    // already consumes; the rollback needs it to read back which release the
    // target actually landed on. The three SENTRY_* entries are the observability work
    // observability, and their split is the secret model: the auth token is a
    // credential and is a secret, the org and project slugs are coordinates
    // and are variables — matching how DEPLOY_* is already split here.
    preg_match_all('/\$\{\{\s*vars\.([A-Z_]+)\s*\}\}/', $source, $varMatches);
    preg_match_all('/\$\{\{\s*secrets\.([A-Z_]+)\s*\}\}/', $source, $secretMatches);
    expect(array_values(array_unique($varMatches[1])))
        ->toEqualCanonicalizing(['DEPLOY_HOST', 'DEPLOY_PORT', 'DEPLOY_USER', 'DEPLOY_ROOT', 'SENTRY_ORG', 'SENTRY_PROJECT'])
        ->and(array_values(array_unique($secretMatches[1])))
        ->toEqualCanonicalizing(['DEPLOY_SSH_KEY', 'DEPLOY_KNOWN_HOSTS', 'SENTRY_AUTH_TOKEN']);
});

it('delegates the restored-release marker instead of carrying its own copy', function () {
    $source = File::get(base_path('.github/workflows/rollback-staging.yml'));
    $workflow = Yaml::parse($source);
    $steps = collect(data_get($workflow, 'jobs.rollback.steps'));

    // The marker moved into the shared rollback action, which performed the
    // read-back it depends on. Nothing here duplicates it.
    expect($steps->pluck('uses')->all())
        ->not->toContain('./.github/actions/sentry-release');

    expect($source)
        ->not->toContain('active-release-id')
        ->not->toMatch('/release-id:\s*[\'"]?rollback/');

    // What it does contribute is the environment class the marker is recorded
    // against — fixed by this workflow, exactly like the target.
    expect(data_get($steps->keyBy('name')->get('Roll back staging-main'), 'with.environment'))
        ->toBe('staging');
});
