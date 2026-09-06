<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The GitHub side of target-scoped repair: the `Repair staging target` and
 * `Repair production target` workflows and the shared
 * .github/actions/repair-rateguru-target action they both call.
 *
 * The properties pinned here are the ones an operator cannot see and a reviewer
 * would have to reconstruct by hand:
 *
 *   * nobody can choose a target, an environment or an application ref;
 *   * repair uses the privileged bootstrap credential and never falls back to
 *     the deployment key;
 *   * NO secret material is carried at all — that absence is the design, and a
 *     future input would silently turn a repair into a re-provisioning;
 *   * the trusted tooling comes from develop, never from the release the target
 *     is currently serving;
 *   * the lifecycle is checked before anything is uploaded;
 *   * uploaded tooling never outlives its run;
 *   * every reported outcome comes from the machine-readable result line, not
 *     from prose;
 *   * production stays fail-closed while tits-guru is lifecycle=planned.
 */

/** @return array<string, mixed> */
function repairWorkflow(string $name): array
{
    return Yaml::parse(File::get(base_path('.github/workflows/'.$name)));
}

function repairActionPath(): string
{
    return base_path('.github/actions/repair-rateguru-target/action.yml');
}

function repairActionSource(): string
{
    return File::get(repairActionPath());
}

/** @return array<string, mixed> */
function repairAction(): array
{
    return Yaml::parse(repairActionSource());
}

/**
 * The same text with comment lines removed.
 *
 * These files explain their own security properties in prose — "never the
 * deployment key", "no TOFU anywhere" — so a naive substring search over the
 * whole file would fail on the very sentence that promises the property. Only
 * executable lines are searched.
 */
function repairExecutable(string $source): string
{
    return executableSourceLines($source);
}

/**
 * The single repair step of a repair workflow.
 *
 * @return array<string, mixed>
 */
function repairStep(string $workflow): array
{
    $jobs = repairWorkflow($workflow)['jobs'];

    foreach ($jobs as $job) {
        $step = collect($job['steps'] ?? [])
            ->firstWhere('uses', './.github/actions/repair-rateguru-target');

        if ($step !== null) {
            return $step;
        }
    }

    throw new RuntimeException("{$workflow} must call the shared repair action");
}

/** @return array<string, mixed> */
function repairCheckoutStep(string $workflow): array
{
    $jobs = repairWorkflow($workflow)['jobs'];

    foreach ($jobs as $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                return $step;
            }
        }
    }

    throw new RuntimeException("{$workflow} must check out trusted tooling");
}

$repairWorkflows = ['repair-staging.yml', 'repair-production.yml'];

// =============================================================================
// Operator surface
// =============================================================================

it('offers a named button per environment and no target dropdown anywhere', function () {
    // Fixed identity per workflow: an operator picks the workflow that names
    // their environment, exactly as with deploy, rollback, restore and host
    // preparation.
    expect(repairStep('repair-staging.yml')['with']['deployment-target'])->toBe('staging-main');
    expect(repairStep('repair-staging.yml')['with']['environment'])->toBe('staging');

    expect(repairStep('repair-production.yml')['with']['deployment-target'])->toBe('tits-guru');
    expect(repairStep('repair-production.yml')['with']['environment'])->toBe('production');

    foreach (['repair-staging.yml', 'repair-production.yml'] as $workflow) {
        $source = File::get(base_path('.github/workflows/'.$workflow));

        expect(repairExecutable($source))->not->toContain('inputs.deployment-target');
        expect(repairExecutable($source))->not->toContain('inputs.target');
        expect(repairExecutable($source))->not->toContain('inputs.environment');
    }
});

it('gives staging no inputs at all', function () {
    $workflow = repairWorkflow('repair-staging.yml');

    // A repair has exactly one meaning, so there is nothing to choose. Any
    // input here would be a decision an operator should not be making at a
    // moment when a target is already broken.
    expect($workflow['on'])->toBe(['workflow_dispatch' => null]);
});

it('gates production behind an exact typed confirmation, in a job that holds no environment', function () {
    $workflow = repairWorkflow('repair-production.yml');

    expect(array_keys($workflow['on']['workflow_dispatch']['inputs']))->toBe(['confirmation']);

    $validate = $workflow['jobs']['validate'];

    // The confirmation is judged before the environment's protection rules are
    // consulted, so an unconfirmed request never becomes an approval request.
    expect($validate)->not->toHaveKey('environment');
    expect(json_encode($validate))->not->toContain('secrets.');

    $body = implode("\n", array_column($validate['steps'], 'run'));

    expect($body)->toContain('"${CONFIRMATION}" != "REPAIR tits-guru"');

    expect($workflow['jobs']['repair']['needs'])->toBe('validate');
    expect($workflow['jobs']['repair']['environment'])->toBe('production');
});

it('is manual-only: nothing pushes, schedules or calls a repair', function () use ($repairWorkflows) {
    foreach ($repairWorkflows as $workflow) {
        $triggers = array_keys(repairWorkflow($workflow)['on']);

        expect($triggers)->toBe(['workflow_dispatch'], "{$workflow} must be manual-only");
    }
});

it('shares one concurrency domain per environment with every other mutation of that target', function () {
    // A repair converges the infrastructure a release runs inside, so it must
    // never overlap a deployment, rollback, restore or host preparation.
    expect(repairWorkflow('repair-staging.yml')['concurrency'])
        ->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    expect(repairWorkflow('repair-production.yml')['concurrency'])
        ->toBe(['group' => 'rateguru-production-release', 'cancel-in-progress' => false]);

    // The same groups the existing workflows already use.
    expect(repairWorkflow('deploy-staging.yml')['concurrency']['group'])->toBe('rateguru-staging-deployment');
    expect(repairWorkflow('release.yml')['concurrency']['group'])->toBe('rateguru-production-release');
});

it('requests only read permission', function () use ($repairWorkflows) {
    foreach ($repairWorkflows as $workflow) {
        expect(repairWorkflow($workflow)['permissions'])->toBe(['contents' => 'read']);
    }
});

// =============================================================================
// Trusted tooling
// =============================================================================

it('always builds the bundle from develop, never from an operator-selectable ref', function () use ($repairWorkflows) {
    foreach ($repairWorkflows as $workflow) {
        $checkout = repairCheckoutStep($workflow);

        expect($checkout['with']['ref'])->toBe('develop', "{$workflow} must check out develop");
        expect($checkout['with']['persist-credentials'])->toBeFalse();

        // Pinned by commit SHA, like every other action use in this
        // repository. The trailing "# v7" is a YAML comment, so it is checked
        // against the file text rather than the parsed value.
        expect($checkout['uses'])->toMatch('~^actions/checkout@[0-9a-f]{40}$~');
        expect(File::get(base_path('.github/workflows/'.$workflow)))
            ->toMatch('~actions/checkout@[0-9a-f]{40} \# v\d+~');
    }
});

it('never takes its tooling from the release the target is currently serving', function () {
    // The release under `current` is the thing being repaired around; it may
    // itself be damaged or stale, and it must never define what "repaired"
    // means.
    $source = repairExecutable(repairActionSource());

    expect($source)->not->toContain('/current/');
    expect($source)->not->toContain('/home/www/rateguru');

    // The bundle comes from the workspace the workflow checked out.
    expect($source)->toContain('--directory "${GITHUB_WORKSPACE}"');
});

it('packages only infrastructure, and proves the tooling it needs is in it', function () {
    $source = repairExecutable(repairActionSource());

    expect($source)->toContain('test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/repair-target"');
    expect($source)->toContain('test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/install-bootstrap-host-layout"');
    expect($source)->toContain('test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/install-bootstrap-services"');

    // The application is emphatically not part of the bundle: this operation
    // never touches code.
    expect($source)->not->toContain('app/');
    expect($source)->not->toContain('vendor');
    expect($source)->not->toContain('artifact');
});

// =============================================================================
// Credentials and secrets
// =============================================================================

it('uses the bootstrap credential and refuses to fall back to the deployment key', function () use ($repairWorkflows) {
    foreach ($repairWorkflows as $workflow) {
        $with = repairStep($workflow)['with'];

        expect($with['bootstrap-user'])->toBe('${{ vars.BOOTSTRAP_USER }}');
        expect($with['bootstrap-ssh-key'])->toBe('${{ secrets.BOOTSTRAP_SSH_KEY }}');
        expect($with['bootstrap-known-hosts'])->toBe('${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}');

        // The deploy key is restricted to the narrow deploy/rollback sudo
        // wrappers. Widening it into a recovery credential would remove the
        // separation that makes an ordinary deployment a small operation.
        expect(json_encode($with))->not->toContain('DEPLOY_SSH_KEY');
        expect(json_encode($with))->not->toContain('DEPLOY_KNOWN_HOSTS');
    }

    // And the action itself fails rather than substituting one for the other.
    $source = repairExecutable(repairActionSource());

    expect($source)->not->toContain('DEPLOY_SSH_KEY');
    expect($source)->toContain('has no bootstrap SSH credential');
});

it('carries no secret material at all, which is the design and not an omission', function () use ($repairWorkflows) {
    $inputs = array_keys(repairAction()['inputs']);

    // A repair converges what this repository commits, around material the
    // host already holds. An input for any of these would silently turn a
    // repair into a re-provisioning that can rotate a credential.
    foreach ([
        'laravel-env', 'deploy-authorized-keys', 'rclone-config', 'basic-auth',
        'tls-certificate', 'tls-private-key', 'tls-dhparams', 'nginx-tls-options',
        'mail-tls-certificate', 'mail-tls-private-key',
    ] as $material) {
        expect($inputs)->not->toContain($material);
    }

    expect($inputs)->toBe([
        'deployment-target', 'environment', 'bootstrap-host', 'bootstrap-port',
        'bootstrap-user', 'bootstrap-ssh-key', 'bootstrap-known-hosts',
    ]);

    // Nor do the workflows pass any PREPARE_* secret.
    foreach ($repairWorkflows as $workflow) {
        expect(json_encode(repairStep($workflow)['with']))->not->toContain('PREPARE_');
    }

    // There is no material staging step at all — nothing to leak.
    $source = repairExecutable(repairActionSource());

    expect($source)->not->toContain('material');
    expect($source)->not->toContain('htpasswd');
    expect($source)->not->toContain('authorized_keys');
});

it('never relaxes host key checking and never uses a password', function () {
    $source = repairExecutable(repairActionSource());

    expect($source)->not->toContain('StrictHostKeyChecking=no');
    expect($source)->not->toContain('StrictHostKeyChecking=accept-new');
    expect($source)->not->toContain('UserKnownHostsFile=/dev/null');
    expect($source)->not->toContain('sshpass');
    expect($source)->not->toContain('PasswordAuthentication');

    // Every ssh and scp invocation is batch mode with a pinned known_hosts.
    expect(substr_count($source, 'StrictHostKeyChecking=yes'))
        ->toBe(substr_count($source, 'UserKnownHostsFile="${RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH}"'));

    expect($source)->toContain('BatchMode=yes');
});

// =============================================================================
// Order of operations
// =============================================================================

it('validates the lifecycle before anything is uploaded and before the key is used', function () {
    $steps = repairAction()['runs']['steps'];
    $names = array_column($steps, 'name');

    $lifecycle = array_search('Validate the target lifecycle before anything is uploaded', $names, true);
    $configure = array_search('Configure bootstrap SSH', $names, true);
    $upload = array_search('Upload the trusted repair bundle', $names, true);

    expect($lifecycle)->not->toBeFalse();
    expect($lifecycle)->toBeLessThan($configure);
    expect($lifecycle)->toBeLessThan($upload);

    // It asks the repository's own targets CLI against the committed registry,
    // rather than reimplementing a lifecycle rule in YAML.
    $body = $steps[$lifecycle]['run'];

    expect($body)->toContain('infrastructure/scripts/targets');
    expect($body)->toContain('infrastructure/config/deployment-targets.json');
    expect($body)->toContain('lifecycle');
});

it('inspects, repairs and then verifies independently, in that order', function () {
    $names = array_column(repairAction()['runs']['steps'], 'name');

    $inspect = array_search('Inspect the target', $names, true);
    $repair = array_search('Repair the target', $names, true);
    $verify = array_search('Verify the repaired target', $names, true);

    expect($inspect)->toBeLessThan($repair);
    expect($repair)->toBeLessThan($verify);

    $steps = repairAction()['runs']['steps'];

    expect($steps[$inspect]['run'])->toContain('--check');
    expect($steps[$repair]['run'])->toContain('--apply');
    expect($steps[$verify]['run'])->toContain('--verify');
});

it('passes a closed flag set to the primitive and never an interpolated command', function () {
    $source = repairExecutable(repairActionSource());

    // A fixed argv built as a Bash array and quoted element-wise. Nothing an
    // operator can type reaches a remote shell.
    expect(substr_count($source, 'remote_command=('))->toBe(3);
    expect(substr_count($source, '"${remote_command[@]@Q}"'))->toBe(3);

    foreach (['--check', '--apply', '--verify', '--target "${DEPLOYMENT_TARGET}"'] as $flag) {
        expect($source)->toContain($flag);
    }

    // The forbidden neighbours: a repair is not a deployment, a restore or a
    // migration, and cannot become one. (source_sha is deliberately absent
    // from this list: the action REPORTS the sha of the release it did not
    // change, which is the opposite of selecting one.)
    foreach (['migrate', '--force-up', 'run-migrations', '--backup', '--source', '--operation'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // And no input can select code: there is no ref, branch, tag or sha to give.
    foreach (array_keys(repairAction()['inputs']) as $input) {
        expect($input)->not->toContain('ref');
        expect($input)->not->toContain('sha');
        expect($input)->not->toContain('branch');
        expect($input)->not->toContain('tag');
    }
});

it('removes the uploaded tooling even when the run failed', function () {
    $steps = repairAction()['runs']['steps'];

    $cleanup = collect($steps)->firstWhere('name', 'Remove the remote repair bundle');

    expect($cleanup['if'])->toBe('${{ always() }}');
    expect($cleanup['run'])->toContain('rm -rf');

    $local = collect($steps)->firstWhere('name', 'Remove temporary local files');

    expect($local['if'])->toBe('${{ always() }}');
    expect($local['run'])->toContain('RATEGURU_BOOTSTRAP_SSH_KEY_PATH');
});

// =============================================================================
// The machine-readable result
// =============================================================================

it('reports every outcome from the result line rather than from prose', function () {
    $steps = repairAction()['runs']['steps'];
    $verify = collect($steps)->firstWhere('id', 'verify')['run'];

    expect($verify)->toContain('rateguru_repair_result "${verification_output}" verified');

    foreach (['current_release', 'source_sha', 'health', 'queue', 'scheduler'] as $field) {
        expect($verify)->toContain($field);
    }
});

it('proves the result contract instead of taking the first line that matches', function () {
    // `grep -m1` would silently accept two result objects, which is exactly
    // the shape a bug in the primitive's "exactly one line" contract takes.
    $source = repairExecutable(repairActionSource());

    expect($source)->not->toContain('grep -m1');
    expect($source)->toContain("grep -c '^RATEGURU_REPAIR_RESULT='");
    expect($source)->toContain('Expected exactly one RATEGURU_REPAIR_RESULT line');

    // Both call sites validate the status they expect, so an apply result can
    // never be read as a verification or the other way round.
    expect(substr_count($source, 'rateguru_repair_result() {'))->toBe(2);
    expect($source)->toContain('rateguru_repair_result "${repair_output}" completed');
    expect($source)->toContain('rateguru_repair_result "${verification_output}" verified');
});

it('takes changed from the repair, never from the read-only verification', function () {
    // --verify is a fresh read-only invocation, so its own `changed` is false
    // by construction. Publishing that would tell an operator "changed:
    // false" after a real repair.
    expect(repairAction()['outputs']['changed']['value'])
        ->toBe('${{ steps.repair.outputs.changed }}');

    $steps = repairAction()['runs']['steps'];

    expect(collect($steps)->firstWhere('id', 'repair')['run'])
        ->toContain('echo "changed=$(jq -r \'.changed\' <<<"${result_json}")" >> "${GITHUB_OUTPUT}"');

    // The verification step reports it, but only by carrying the apply's
    // answer through — it never re-derives it.
    expect(collect($steps)->firstWhere('id', 'verify')['env']['RATEGURU_REPAIR_CHANGED'])
        ->toBe('${{ steps.repair.outputs.changed }}');
});

it('exposes the release it did not change as an output', function () {
    $outputs = repairAction()['outputs'];

    expect(array_keys($outputs))->toBe(['repaired', 'changed', 'current-release', 'source-sha']);

    // Hyphenated output names must use index syntax: `steps.x.outputs.a-b` is
    // ambiguous with subtraction in a GitHub expression.
    expect($outputs['current-release']['value'])->toBe("\${{ steps.verify.outputs['current-release'] }}");
    expect($outputs['source-sha']['value'])->toBe("\${{ steps.verify.outputs['source-sha'] }}");
});

// =============================================================================
// Production stays fail-closed
// =============================================================================

it('cannot repair production while tits-guru is lifecycle=planned', function () {
    $registry = json_decode(
        File::get(base_path('infrastructure/config/deployment-targets.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    // The workflow is pinned to the real production target ID, so it fails
    // closed on the lifecycle gate. Routing it at staging-main, or activating
    // tits-guru to make it pass, would defeat the point of adding it now.
    expect(repairStep('repair-production.yml')['with']['deployment-target'])->toBe('tits-guru');

    // And nothing in either workflow edits a registry or activates anything.
    foreach (['repair-staging.yml', 'repair-production.yml'] as $workflow) {
        $source = repairExecutable(File::get(base_path('.github/workflows/'.$workflow)));

        expect($source)->not->toContain('deployment-targets.json');
        expect($source)->not->toContain('lifecycle');
    }
});
