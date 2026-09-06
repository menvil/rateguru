<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The exact JSON literal release.yml uses to embed the release metadata's
 * target IDs — shared so a future target rename only needs updating here,
 * not independently in every test that checks for it.
 */
function releaseWorkflowTargetsMetadata(): string
{
    return '"targets": ["staging-main", "tits-guru"]';
}

beforeEach(function () {
    $path = base_path('.github/workflows/release.yml');

    expect(File::exists($path))->toBeTrue();

    $this->releaseWorkflowSource = File::get($path);
    $this->releaseWorkflow = Yaml::parse($this->releaseWorkflowSource);
    $this->validateSteps = collect(data_get($this->releaseWorkflow, 'jobs.validate.steps'))->keyBy('name');
    $this->buildSteps = collect(data_get($this->releaseWorkflow, 'jobs.build.steps'))->keyBy('name');
    $this->stagingSteps = collect(data_get($this->releaseWorkflow, 'jobs.deploy-staging.steps'))->keyBy('name');
    $this->productionSteps = collect(data_get($this->releaseWorkflow, 'jobs.deploy-production.steps'))->keyBy('name');
});

it('restricts production releases by trigger permissions and concurrency', function () {
    expect($this->releaseWorkflow)
        ->toBeArray()
        ->and(data_get($this->releaseWorkflow, 'name'))->toBe('Release to production')
        ->and(data_get($this->releaseWorkflow, 'on.push.tags'))->toBe(['v*'])
        ->and(data_get($this->releaseWorkflow, 'permissions.contents'))->toBe('read')
        ->and(data_get($this->releaseWorkflow, 'concurrency.group'))->toBe('rateguru-production-release')
        ->and(data_get($this->releaseWorkflow, 'concurrency.cancel-in-progress'))->toBeFalse();
});

it('orders release jobs through staging and production environments', function () {
    expect(data_get($this->releaseWorkflow, 'jobs.build.needs'))->toBe('validate')
        ->and(data_get($this->releaseWorkflow, 'jobs.deploy-staging.needs'))->toBe(['validate', 'build'])
        ->and(data_get($this->releaseWorkflow, 'jobs.deploy-staging.environment'))->toBe('staging')
        ->and(data_get($this->releaseWorkflow, 'jobs.deploy-production.needs'))->toBe(['validate', 'build', 'deploy-staging'])
        ->and(data_get($this->releaseWorkflow, 'jobs.deploy-production.environment'))->toBe('production');
});

it('wires release steps to reuse one immutable artifact', function () {
    expect(data_get($this->validateSteps->get('Checkout production tag'), 'with.persist-credentials'))
        ->toBeFalse()
        ->and(data_get($this->validateSteps->get('Validate tag and main ancestry'), 'env.SOURCE_TAG'))
        ->toBe('${{ github.ref_name }}')
        ->and(data_get($this->validateSteps->get('Validate tag and main ancestry'), 'run'))
        ->not->toContain('${{');

    expect(data_get($this->buildSteps->get('Checkout exact release commit'), 'with.ref'))
        ->toBe('${{ needs.validate.outputs.source-sha }}')
        ->and(data_get($this->buildSteps->get('Checkout exact release commit'), 'with.persist-credentials'))
        ->toBeFalse();

    foreach ([$this->stagingSteps, $this->productionSteps] as $deploymentSteps) {
        expect(data_get($deploymentSteps->get('Checkout trusted deployment action'), 'with.ref'))
            ->toBe('${{ needs.validate.outputs.source-sha }}')
            ->and(data_get($deploymentSteps->get('Checkout trusted deployment action'), 'with.persist-credentials'))
            ->toBeFalse();
    }

    expect(data_get($this->stagingSteps->get('Deploy release artifact to staging'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($this->stagingSteps->get('Deploy release artifact to staging'), 'with.release-id'))
        ->toBe('${{ needs.build.outputs.release-id }}')
        ->and(data_get($this->productionSteps->get('Deploy verified artifact to production'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($this->productionSteps->get('Deploy verified artifact to production'), 'with.release-id'))
        ->toBe('${{ needs.build.outputs.release-id }}')
        ->and(data_get($this->stagingSteps->get('Download immutable production artifact'), 'with.name'))
        ->toBe('${{ needs.build.outputs.workflow-artifact-name }}')
        ->and(data_get($this->productionSteps->get('Download the same verified artifact'), 'with.name'))
        ->toBe('${{ needs.build.outputs.workflow-artifact-name }}');
});

it('builds through the one shared build implementation, passing only production policy', function () {
    $build = $this->buildSteps->get('Build immutable release artifact');

    expect(data_get($build, 'uses'))->toBe('./.github/actions/build-rateguru')
        ->and(data_get($build, 'id'))->toBe('build');

    expect(data_get($build, 'with.source-root'))->toBe('${{ github.workspace }}')
        ->and(data_get($build, 'with.source-ref'))->toBe('${{ needs.validate.outputs.source-tag }}')
        ->and(data_get($build, 'with.release-version'))->toBe('${{ needs.validate.outputs.version }}')
        // The build refuses to produce an artifact from anything but the
        // commit `validate` proved is a semantic tag contained in main.
        ->and(data_get($build, 'with.expected-source-sha'))->toBe('${{ needs.validate.outputs.source-sha }}')
        ->and(data_get($build, 'with.workflow-artifact-prefix'))->toBe('rateguru-production')
        ->and(data_get($build, 'with.artifact-retention-days'))->toBe('90')
        ->and(data_get($build, 'with.validate-composer'))->toBe('true')
        // Production deliberately restores nothing from a cache.
        ->and(data_get($build, 'with.node-cache'))->toBeNull();

    expect(data_get($build, 'with.release-metadata'))
        ->toContain('"source_tag": ${{ toJSON(needs.validate.outputs.source-tag) }}')
        ->toContain('"version": ${{ toJSON(needs.validate.outputs.version) }}')
        ->toContain(releaseWorkflowTargetsMetadata());

    expect(data_get($this->releaseWorkflow, 'jobs.build.outputs'))->toBe([
        'release-id' => '${{ steps.build.outputs.release-id }}',
        'artifact-name' => '${{ steps.build.outputs.artifact-name }}',
        'workflow-artifact-name' => '${{ steps.build.outputs.workflow-artifact-name }}',
    ]);
});

it('builds the production artifact exactly once and promotes that same artifact', function () {
    // The operational model: validate -> build ONE artifact ->
    // staging -> approval -> production, with no rebuild in between. Proved
    // structurally rather than by wording.
    $buildJobs = collect(data_get($this->releaseWorkflow, 'jobs'))
        ->filter(fn (array $job): bool => collect(data_get($job, 'steps', []))
            ->contains(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/build-rateguru'));

    expect($buildJobs->keys()->all())->toBe(['build'], 'production must build in exactly one job');

    $buildSteps = collect(data_get($this->releaseWorkflow, 'jobs.build.steps'))
        ->filter(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/build-rateguru');

    expect($buildSteps)->toHaveCount(1, 'the build job must call the build action exactly once');

    // Both deployments name the same workflow artifact, the same tarball and
    // the same release ID — every one of them an output of that single build.
    $staging = $this->stagingSteps->get('Deploy release artifact to staging');
    $production = $this->productionSteps->get('Deploy verified artifact to production');

    foreach (['release-id', 'artifact-path', 'checksum-path'] as $input) {
        expect(data_get($production, "with.{$input}"))
            ->toBe(data_get($staging, "with.{$input}"), "production {$input} diverges from the staging one");

        expect(str_contains((string) data_get($staging, "with.{$input}"), 'needs.build.outputs.'))
            ->toBeTrue("{$input} must come from the single build job");
    }

    expect(data_get($this->stagingSteps->get('Download immutable production artifact'), 'with.name'))
        ->toBe(data_get($this->productionSteps->get('Download the same verified artifact'), 'with.name'));

    // Neither deployment job may install, compile or repackage anything.
    foreach (['deploy-staging', 'deploy-production'] as $job) {
        foreach ((array) data_get($this->releaseWorkflow, "jobs.{$job}.steps") as $step) {
            foreach (['composer install', 'npm ci', 'npm run build', 'tar ', 'sha256sum', 'build-rateguru'] as $mechanic) {
                expect(str_contains((string) data_get($step, 'run', '').(string) data_get($step, 'uses', ''), $mechanic))
                    ->toBeFalse("{$job} rebuilds instead of promoting: {$mechanic}");
            }
        }
    }
});

it('builds the validated tag commit as one tree, tooling and application alike', function () {
    // Production has no arbitrary source selector, so it needs no second
    // checkout: the tag commit `validate` proved is contained in main is both
    // the application being built and the tooling that builds and deploys it.
    // A production release therefore stays fully described by its own tag.
    $checkouts = $this->buildSteps->filter(fn (array $step): bool => str_contains((string) data_get($step, 'uses'), 'actions/checkout@'));

    expect($checkouts->keys()->all())->toBe(['Checkout exact release commit'])
        ->and(data_get($checkouts->first(), 'with.path'))->toBeNull();

    expect(data_get($this->buildSteps->get('Build immutable release artifact'), 'with.source-root'))
        ->toBe('${{ github.workspace }}');

    // No operator-facing source selector exists anywhere in the workflow.
    expect(data_get($this->releaseWorkflow, 'on.workflow_dispatch'))->toBeNull()
        ->and(array_keys((array) data_get($this->releaseWorkflow, 'on')))->toBe(['push']);

    foreach (['inputs.ref', 'inputs.source', 'inputs.branch', 'inputs.commit'] as $selector) {
        expect(str_contains($this->releaseWorkflowSource, $selector))
            ->toBeFalse("production must not gain a source selector: {$selector}");
    }
});

it('serializes whole releases against production, and its staging mutation against staging', function () {
    // The workflow-level group keeps a whole production release serialized
    // against a production rollback. The job-level group additionally puts the
    // release's staging deployment in the same domain every other staging
    // mutation uses, so a manual staging deploy or rollback can no longer
    // overlap a release's staging verification.
    expect(data_get($this->releaseWorkflow, 'concurrency'))->toBe([
        'group' => 'rateguru-production-release',
        'cancel-in-progress' => false,
    ]);

    expect(data_get($this->releaseWorkflow, 'jobs.deploy-staging.concurrency'))->toBe([
        'group' => 'rateguru-staging-deployment',
        'cancel-in-progress' => false,
    ]);

    // The production deployment stays covered by the workflow-level group
    // alone — it must not be pulled into the staging domain.
    expect(data_get($this->releaseWorkflow, 'jobs.deploy-production.concurrency'))->toBeNull();

    // Orchestration only: the server-side deployment lock is still the thing
    // that actually protects the target, and nothing here replaces it.
    expect(File::get(base_path('infrastructure/scripts/common')))->toContain('flock');
});

it('passes deployment-target: staging-main to the staging job and tits-guru to the production job', function () {
    expect(data_get($this->stagingSteps->get('Deploy release artifact to staging'), 'with.deployment-target'))
        ->toBe('staging-main')
        ->and(data_get($this->productionSteps->get('Deploy verified artifact to production'), 'with.deployment-target'))
        ->toBe('tits-guru');
});

it('never references the operational --environment selector anywhere in the release workflow', function () {
    expect($this->releaseWorkflowSource)->not->toContain('--environment');
});

it('records target IDs, not environment classes, in the release metadata targets array', function () {
    expect($this->releaseWorkflowSource)
        ->toContain(releaseWorkflowTargetsMetadata())
        ->not->toContain('"staging"')
        ->not->toContain('"production"');
});

it('pins every external release action to a commit SHA', function () {
    $externalActions = $this->validateSteps
        ->merge($this->buildSteps)
        ->merge($this->stagingSteps)
        ->merge($this->productionSteps)
        ->pluck('uses')
        ->filter(fn (mixed $uses): bool => is_string($uses) && ! str_starts_with($uses, './'));

    foreach ($externalActions as $uses) {
        expect($uses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');
    }
});

it('does not gate deployments on an external social preview fixture', function () {
    foreach ([$this->stagingSteps, $this->productionSteps] as $deploymentSteps) {
        expect($deploymentSteps->has('Verify social preview as external crawler'))->toBeFalse();
    }
});

it('retains required production release script safeguards', function () {
    expect($this->releaseWorkflowSource)
        ->toContain("tag_regex='^v([0-9]+)\\.([0-9]+)\\.([0-9]+)")
        ->toContain('git merge-base \\')
        ->toContain('--is-ancestor \\')
        ->toContain('Tag ${SOURCE_TAG} does not point to a commit contained in main.')
        ->toContain('version="v${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[3]}"')
        ->toContain(releaseWorkflowTargetsMetadata())
        ->not->toContain('["staging", "production"]')
        ->toContain('run-migrations: "true"');
});

it('never rebuilds the mechanical pipeline that now belongs to the shared build action', function () {
    foreach ([
        'composer install',
        'composer validate',
        'npm ci',
        'npm run build',
        'rsync',
        'sha256sum',
        'tar \\',
        '"${package_root}/release.json"',
        'verify-required-clis',
        'setup-php',
        'setup-node',
        'upload-artifact',
        'release_id=',
    ] as $mechanic) {
        expect(str_contains($this->releaseWorkflowSource, $mechanic))
            ->toBeFalse("release.yml re-implements the shared build step: {$mechanic}");
    }
});
