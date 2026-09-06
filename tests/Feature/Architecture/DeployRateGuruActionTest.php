<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('defines a hardened reusable RateGuru deployment action', function () {
    $path = base_path('.github/actions/deploy-rateguru/action.yml');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);
    $action = Yaml::parse($source);
    $steps = collect(data_get($action, 'runs.steps'))->keyBy('name');

    expect($action)
        ->toBeArray()
        ->and(data_get($action, 'name'))->toBe('Deploy RateGuru artifact')
        ->and(data_get($action, 'runs.using'))->toBe('composite')
        ->and(data_get($action, 'inputs.deploy-port.default'))->toBe('22')
        ->and(data_get($action, 'inputs.run-migrations.default'))->toBe('true')
        // Empty by default, so an ordinary deployment's contract is unchanged.
        ->and(data_get($action, 'inputs.restore-operation.required'))->toBeFalse()
        ->and(data_get($action, 'inputs.restore-operation.default'))->toBe('');

    foreach ([
        'deployment-target',
        'deploy-host',
        'deploy-user',
        'deploy-incoming',
        'deploy-wrapper',
        'deploy-root',
        'ssh-private-key',
        'known-hosts',
        'release-id',
        'artifact-path',
        'checksum-path',
    ] as $requiredInput) {
        expect(data_get($action, "inputs.{$requiredInput}.required"))->toBeTrue();
    }

    expect($steps->keys()->all())->toBe([
        'Validate deployment inputs',
        'Configure SSH',
        'Test SSH connection',
        'Upload artifact',
        'Deploy release',
        'Verify active release',
        'Remove temporary SSH material',
    ]);

    expect($source)
        ->toContain("release_regex='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}$'")
        ->toContain('expected_artifact_name="rateguru-${RELEASE_ID}.tar.gz"')
        ->toContain('Artifact name ${artifact_name} does not match release ID ${RELEASE_ID}')
        ->toContain('test "${checksum_name}" = "${artifact_name}.sha256"')
        ->toContain('-o StrictHostKeyChecking=yes')
        ->toContain('-o UserKnownHostsFile="${RATEGURU_KNOWN_HOSTS_PATH}"')
        ->toContain("'sudo -n %q --target %q --release %q --artifact %q --checksum %q'")
        ->toContain('remote_command+=" --migrate"')
        // the controlled code alignment: the alignment mode is opt-in, names an operation and
        // never a commit, and is refused outright alongside a migration.
        ->toContain("operation_regex='^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$'")
        ->toContain('run-migrations must be false when restore-operation is set')
        ->toContain("printf -v restore_argument ' --restore-operation %q' \"\${RESTORE_OPERATION}\"")
        ->toContain("'basename \"$(readlink -f %q)\"'")
        ->toContain("jq -r '.release'")
        ->toContain('if: ${{ always() }}');

    foreach ($steps as $step) {
        expect(data_get($step, 'shell'))->toBe('bash')
            ->and(data_get($step, 'run'))->not->toContain('${{ inputs.');
    }
});

it('is called with an explicit, validly-shaped deployment-target by every workflow that consumes it', function () {
    $workflowFiles = glob(base_path('.github/workflows/*.yml'));
    expect($workflowFiles)->not->toBeEmpty();

    $consumers = [];

    foreach ($workflowFiles as $path) {
        $workflow = Yaml::parse(File::get($path));

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') !== './.github/actions/deploy-rateguru') {
                    continue;
                }

                $label = basename($path).":{$jobName}:".(data_get($step, 'name') ?? '(unnamed step)');
                $consumers[] = $label;

                $target = data_get($step, 'with.deployment-target');
                expect($target)
                    ->not->toBeNull("missing deployment-target in {$label}")
                    ->not->toBe('');
                expect($target)->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/', "deployment-target in {$label} is not a validly-shaped target ID: {$target}");
            }
        }
    }

    // Proves this scan actually found real call sites, not trivially
    // passing against zero consumers — and forces this test to be updated
    // the moment a new workflow adds a fourth one.
    expect($consumers)->toHaveCount(5, 'expected exactly five deploy-rateguru call sites (deploy-staging.yml, release.yml staging, release.yml production, and the controlled alignment in each restore workflow); found: '.implode(', ', $consumers));
});
