<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * the controlled code alignment: restore-staging.yml and restore-production.yml.
 *
 * These are policy files. Every mechanism they use already exists — one build,
 * one deploy, one restore, one observability marker — and the only thing they
 * add is the decision of what runs when, for which fixed target.
 *
 * So this file asserts policy, and specifically the parts of it that are
 * dangerous to get wrong:
 *
 *   * the target is structural, never an operator input. Two named buttons,
 *     no dropdown, anywhere in the repository;
 *   * the required commit is never an operator input either. It flows backup
 *     -> verified release.json -> restore state -> action output -> checkout,
 *     and nothing in that chain is typed by a person;
 *   * the historical build holds no environment and no secret. It compiles an
 *     arbitrary commit out of this repository's past, and it must never be
 *     able to reach a deployment key;
 *   * the whole chain shares ONE concurrency group, because restore -> build
 *     -> alignment deploy -> resume is one logical mutation of one target;
 *   * nothing resumes the target except restore-target --resume, and nothing
 *     records a deployment marker unless code was actually deployed.
 *
 * @return array{0: array, 1: string}
 */
function restoreWorkflow(string $file): array
{
    $path = base_path(".github/workflows/{$file}");

    expect(File::exists($path))->toBeTrue("{$file} is missing");

    $source = File::get($path);

    return [Yaml::parse($source), $source];
}

/** @return array<string, array> */
function restoreWorkflowStepsByName(array $workflow, string $job): array
{
    return collect(data_get($workflow, "jobs.{$job}.steps", []))
        ->filter(static fn (array $step): bool => isset($step['name']))
        ->keyBy('name')
        ->all();
}

dataset('restore workflows', [
    'staging' => ['restore-staging.yml', 'Restore staging', 'staging-main', 'staging', 'rateguru-staging-deployment'],
    'production' => ['restore-production.yml', 'Restore production', 'tits-guru', 'production', 'rateguru-production-release'],
]);

it('is manual-only, fixes its own target, and offers no target selector', function (
    string $file,
    string $name,
    string $target,
    string $environment,
    string $concurrency,
) {
    [$workflow] = restoreWorkflow($file);

    expect(data_get($workflow, 'name'))->toBe($name)
        ->and(array_keys($workflow['on']))->toBe(['workflow_dispatch'])
        ->and($workflow['permissions'])->toBe(['contents' => 'read']);

    $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

    // No target input under any name. The operator picks the workflow whose
    // title names the environment, and the target is a literal below.
    foreach (['target', 'deployment-target', 'deployment_target', 'environment'] as $forbidden) {
        expect($inputs)->not->toHaveKey($forbidden, "{$file} must not let an operator choose a target");
    }

    // And no commit input under any name either: the operator chooses a
    // backup, never a commit.
    foreach (['sha', 'source-sha', 'source_sha', 'required_source_sha', 'required-source-sha', 'historical_sha', 'ref', 'ref_to_restore'] as $forbidden) {
        expect($inputs)->not->toHaveKey($forbidden, "{$file} must not let an operator choose a commit");
    }

    // Every job that talks to the target does so at the fixed identity.
    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        foreach ((array) data_get($job, 'steps', []) as $step) {
            $stepTarget = data_get($step, 'with.deployment-target');

            if ($stepTarget !== null) {
                expect($stepTarget)->toBe($target, "{$file}:{$jobName} must act on {$target}");
            }
        }
    }
})->with('restore workflows');

it('offers exactly the two operator modes and defaults to the independent offsite copy', function (
    string $file,
) {
    [$workflow] = restoreWorkflow($file);
    $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

    expect(data_get($inputs, 'mode.type'))->toBe('choice')
        ->and(data_get($inputs, 'mode.options'))->toBe(['start', 'continue-held'])
        ->and(data_get($inputs, 'mode.default'))->toBe('start')
        ->and(data_get($inputs, 'mode.required'))->toBeTrue();

    // offsite by default: the operator recovery path uses the copy that
    // survives the host. local stays a full option, including when B2 is the
    // thing that is unavailable.
    expect(data_get($inputs, 'source.type'))->toBe('choice')
        ->and(data_get($inputs, 'source.options'))->toBe(['offsite', 'local'])
        ->and(data_get($inputs, 'source.default'))->toBe('offsite');

    // No "latest" anywhere: a backup is named exactly, or not at all.
    expect(data_get($inputs, 'backup.type'))->toBe('string')
        ->and(data_get($inputs, 'backup.required'))->toBeFalse()
        ->and(data_get($inputs, 'operation.type'))->toBe('string')
        ->and(data_get($inputs, 'operation.required'))->toBeFalse();
})->with('restore workflows');

it('enforces the two mode contracts before any environment or secret is reached', function (
    string $file,
) {
    [$workflow, $source] = restoreWorkflow($file);

    // The validation job holds no GitHub Environment, so a malformed request
    // never even becomes an approval request.
    expect(data_get($workflow, 'jobs.validate.environment'))->toBeNull()
        ->and(data_get($workflow, 'jobs.validate.steps.0.uses'))->toBeNull();

    expect($source)
        ->toContain('mode=start requires an exact backup timestamp YYYYMMDD-HHMMSS')
        ->toContain('mode=start must not name an operation')
        ->toContain('mode=continue-held requires the restore operation ID')
        ->toContain('mode=continue-held must not name a backup')
        ->toContain('a new restore must never be started on top of a held target')
        ->toContain('^[0-9]{8}-[0-9]{6}$')
        ->toContain('^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$');

    // Every job that touches the target depends on that validation — not just
    // the first one. `restore` needing it would already make the others wait
    // transitively, but only while today's chain happens to be linear: a job
    // later rewired to start earlier would silently stop being gated. Each one
    // names it directly, and this asserts that rather than the chain's shape.
    //
    // `observability` is deliberately absent: it is not in this list because
    // it mutates nothing on the target and runs only after `resume` has
    // already succeeded.
    foreach (['restore', 'build', 'align', 'resume'] as $job) {
        expect(data_get($workflow, "jobs.{$job}"))->not->toBeNull("{$file} must define the {$job} job");

        // A single dependency is a string, several are a list; both mean the
        // same thing here.
        //
        // in_array + toBeTrue rather than toContain: toContain is variadic in
        // Pest, so a second "message" argument becomes another needle and the
        // assertion starts demanding the diagnostic itself be one of the job's
        // dependencies. toBeTrue takes a real message.
        expect(in_array('validate', (array) data_get($workflow, "jobs.{$job}.needs"), true))
            ->toBeTrue("{$file}:{$job} must not start before the request is validated");
    }
})->with('restore workflows');

it('holds one concurrency group for the entire restore chain', function (
    string $file,
    string $name,
    string $target,
    string $environment,
    string $concurrency,
) {
    [$workflow] = restoreWorkflow($file);

    // Workflow level, not job level: restore -> build -> alignment deploy ->
    // resume is one logical mutation, and a deploy or rollback slipping in
    // between two of its jobs would move `current` away from the commit the
    // restored data belongs to while the target is held and cannot object.
    expect(data_get($workflow, 'concurrency.group'))->toBe($concurrency)
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse();

    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        expect(data_get($job, 'concurrency'))->toBeNull("{$file}:{$jobName} must not carry its own concurrency group");
    }
})->with('restore workflows');

it('shares its concurrency domain with the other workflows that mutate the same target', function () {
    [$staging] = restoreWorkflow('restore-staging.yml');
    [$production] = restoreWorkflow('restore-production.yml');

    $deploy = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
    $rollbackStaging = Yaml::parse(File::get(base_path('.github/workflows/rollback-staging.yml')));
    $release = Yaml::parse(File::get(base_path('.github/workflows/release.yml')));
    $rollbackProduction = Yaml::parse(File::get(base_path('.github/workflows/rollback-production.yml')));

    expect($staging['concurrency'])->toBe($deploy['concurrency'])
        ->and($staging['concurrency'])->toBe($rollbackStaging['concurrency'])
        ->and($production['concurrency'])->toBe($release['concurrency'])
        ->and($production['concurrency'])->toBe($rollbackProduction['concurrency']);
});

it('runs the restore through the shared action and decides the rest from its result alone', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow, $source] = restoreWorkflow($file);
    $steps = restoreWorkflowStepsByName($workflow, 'restore');

    expect(data_get($workflow, 'jobs.restore.environment'))->toBe($environment);

    $apply = collect($steps)->first(static fn (array $step): bool => data_get($step, 'with.mode') === 'apply');
    $inspect = collect($steps)->first(static fn (array $step): bool => data_get($step, 'with.mode') === 'inspect');

    expect(data_get($apply, 'uses'))->toBe('./.github/actions/restore-rateguru')
        ->and(data_get($apply, 'if'))->toBe("\${{ needs.validate.outputs.mode == 'start' }}")
        ->and(data_get($apply, 'with.backup-id'))->toBe('${{ needs.validate.outputs.backup }}')
        ->and(data_get($apply, 'with.restore-source'))->toBe('${{ needs.validate.outputs.source }}');

    expect(data_get($inspect, 'uses'))->toBe('./.github/actions/restore-rateguru')
        ->and(data_get($inspect, 'if'))->toBe("\${{ needs.validate.outputs.mode == 'continue-held' }}")
        ->and(data_get($inspect, 'with.operation-id'))->toBe('${{ needs.validate.outputs.operation }}')
        ->and(data_get($inspect, 'with.backup-id'))->toBeNull()
        ->and(data_get($inspect, 'with.restore-source'))->toBeNull();

    // The branch is decided from the machine-readable result, never by
    // grepping a log.
    expect($source)
        ->toContain('case "${STATUS}" in')
        ->toContain('build_required=no')
        ->toContain('build_required=yes')
        ->toContain('resume_required=yes')
        ->toContain('Unexpected restore status: ${STATUS}')
        ->toContain('if [[ "${CURRENT_SOURCE_SHA}" == "${REQUIRED_SOURCE_SHA}" ]]; then');

    // The alignment release keeps the BACKUP's version prefix, and refuses to
    // invent one.
    expect($source)
        ->toContain('^(v[0-9]+\.[0-9]+\.[0-9]+)-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}$')
        ->toContain('refusing to invent a version for the alignment build')
        ->toContain('release_version="${BASH_REMATCH[1]}"');
})->with('restore workflows');

it('builds the exact required commit with trusted tooling and no privilege whatsoever', function (
    string $file,
) {
    [$workflow] = restoreWorkflow($file);
    $build = data_get($workflow, 'jobs.build');
    $steps = restoreWorkflowStepsByName($workflow, 'build');

    // The trust boundary. This job compiles an arbitrary historical commit,
    // chosen by a backup rather than by a person: it must hold no GitHub
    // Environment, and therefore no deployment key, no Sentry token and no B2
    // credential.
    expect(data_get($build, 'environment'))->toBeNull("{$file}: the historical build must hold no GitHub Environment")
        ->and(data_get($build, 'permissions'))->toBe(['contents' => 'read'])
        ->and(data_get($build, 'if'))->toBe("\${{ needs.restore.outputs.build_required == 'yes' }}");

    $buildSource = json_encode($build);

    foreach (['secrets.', 'DEPLOY_SSH_KEY', 'DEPLOY_KNOWN_HOSTS', 'SENTRY_AUTH_TOKEN', 'B2_'] as $forbidden) {
        // toContain is variadic in Pest: a second "message" argument is read
        // as another needle, and a negation then passes on anything. The
        // diagnostic goes in a comment, never in the call.
        expect($buildSource)->not->toContain($forbidden);
    }

    // Two checkouts, and which is which is the whole point: the operational
    // tooling always comes from develop, the application from the exact commit.
    $tooling = $steps['Checkout trusted build tooling'];
    $application = $steps['Checkout the required historical application source'];

    expect(data_get($tooling, 'with.ref'))->toBe('develop')
        ->and(data_get($tooling, 'with.persist-credentials'))->toBeFalse()
        ->and(data_get($tooling, 'with.path'))->toBeNull();

    expect(data_get($application, 'with.ref'))->toBe('${{ needs.restore.outputs.required_source_sha }}')
        ->and(data_get($application, 'with.path'))->toBe('application')
        ->and(data_get($application, 'with.persist-credentials'))->toBeFalse();

    // The ONE build implementation, loaded from the tooling checkout and
    // pointed at the historical one — never loaded from the historical commit.
    $buildStep = $steps['Build the alignment release artifact'];

    expect(data_get($buildStep, 'uses'))->toBe('./.github/actions/build-rateguru')
        ->and(data_get($buildStep, 'with.source-root'))->toBe('${{ github.workspace }}/application')
        ->and(data_get($buildStep, 'with.source-ref'))->toBe('${{ needs.restore.outputs.required_source_sha }}')
        ->and(data_get($buildStep, 'with.expected-source-sha'))->toBe('${{ needs.restore.outputs.required_source_sha }}')
        ->and(data_get($buildStep, 'with.release-version'))->toBe('${{ needs.restore.outputs.release_version }}')
        ->and(data_get($buildStep, 'with.release-metadata'))->toBe('${{ needs.restore.outputs.release_metadata }}');
})->with('restore workflows');

it('deploys the alignment as a controlled deploy that never migrates and never resumes', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = restoreWorkflow($file);
    $align = data_get($workflow, 'jobs.align');
    $steps = restoreWorkflowStepsByName($workflow, 'align');

    expect(data_get($align, 'environment'))->toBe($environment)
        ->and(data_get($align, 'if'))->toBe("\${{ needs.restore.outputs.build_required == 'yes' }}");

    // Deployment tooling always comes from develop, never from the historical
    // ref that is about to be installed.
    expect(data_get($steps['Checkout deployment action'], 'with.ref'))->toBe('develop');

    $deploy = $steps['Deploy the alignment release'];

    // The SAME deployment action every ordinary release uses. The only
    // difference is the operation ID — never a commit.
    expect(data_get($deploy, 'uses'))->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($deploy, 'with.deployment-target'))->toBe($target)
        ->and(data_get($deploy, 'with.run-migrations'))->toBe('false')
        ->and(data_get($deploy, 'with.restore-operation'))->toBe('${{ needs.restore.outputs.operation_id }}')
        ->and(data_get($deploy, 'with.release-id'))->toBe('${{ needs.build.outputs.release-id }}');

    foreach (['source-sha', 'required-source-sha', 'commit'] as $forbidden) {
        expect(data_get($deploy, "with.{$forbidden}"))->toBeNull("{$file}: the alignment deploy must never name a commit");
    }
})->with('restore workflows');

it('makes restore-target --resume the only thing that ends a hold', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow, $source] = restoreWorkflow($file);
    $resume = data_get($workflow, 'jobs.resume');
    $steps = restoreWorkflowStepsByName($workflow, 'resume');

    expect(data_get($resume, 'environment'))->toBe($environment)
        ->and(data_get($resume, 'needs'))->toBe(['validate', 'restore', 'build', 'align']);

    // Runs after a successful alignment AND on the continue-held path where
    // the required commit is already installed — but never after a failed
    // build or a failed alignment.
    $condition = preg_replace('/\s+/', ' ', (string) data_get($resume, 'if'));

    expect($condition)
        ->toContain("needs.restore.outputs.resume_required == 'yes'")
        ->toContain("needs.build.result == 'success' || needs.build.result == 'skipped'")
        ->toContain("needs.align.result == 'success' || needs.align.result == 'skipped'")
        ->toContain('!cancelled()');

    $resumeStep = collect($steps)->first(static fn (array $step): bool => data_get($step, 'with.mode') === 'resume');

    expect(data_get($resumeStep, 'uses'))->toBe('./.github/actions/restore-rateguru')
        ->and(data_get($resumeStep, 'with.operation-id'))->toBe('${{ needs.restore.outputs.operation_id }}');

    // Nothing anywhere in the workflow resumes a target by hand: no artisan
    // up, no queue start, no scheduler restoration, no guard removal.
    foreach ([
        'artisan up', 'queue:restart', 'supervisorctl', 'cron.d', 'restore-guard',
        'rateguru-deploy --', 'artisan migrate',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with('restore workflows');

it('records a deployment marker only when code was actually deployed and the target came back', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = restoreWorkflow($file);
    $observability = data_get($workflow, 'jobs.observability');
    $steps = restoreWorkflowStepsByName($workflow, 'observability');

    $condition = preg_replace('/\s+/', ' ', (string) data_get($observability, 'if'));

    // A restore that came back ALIGNED deployed nothing, so there is no
    // deployment to mark — inventing one would put a release into Sentry and
    // Nightwatch that was never built or installed.
    expect($condition)
        ->toContain("needs.restore.outputs.build_required == 'yes'")
        ->toContain("needs.align.result == 'success'")
        ->toContain("needs.resume.result == 'success'");

    $record = $steps['Record deployment in Sentry and Nightwatch'];

    expect(data_get($record, 'uses'))->toBe('./.github/actions/record-rateguru-deployment')
        ->and(data_get($record, 'with.deployment-target'))->toBe($target)
        ->and(data_get($record, 'with.environment'))->toBe($environment)
        ->and(data_get($record, 'with.release-id'))->toBe('${{ needs.build.outputs.release-id }}')
        ->and(data_get($record, 'with.source-sha'))->toBe('${{ needs.build.outputs.source-sha }}');
})->with('restore workflows');

it('reuses the existing restricted deploy credential and never sees a B2 credential', function (
    string $file,
) {
    [, $source] = restoreWorkflow($file);

    // The restore reads its offsite copy through the host's own root-side
    // rclone configuration; no bucket credential is ever handed to GitHub.
    foreach (['B2_ACCOUNT', 'B2_KEY', 'B2_APPLICATION', 'RCLONE_CONFIG', 'rclone.conf'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // And no new SSH credential: restoring an existing target uses the
    // credential that already deploys to it.
    expect($source)
        ->toContain('${{ secrets.DEPLOY_SSH_KEY }}')
        ->toContain('${{ secrets.DEPLOY_KNOWN_HOSTS }}')
        ->toContain('${{ vars.RESTORE_WRAPPER }}');

    foreach (['RESTORE_SSH_KEY', 'RESTORE_DEPLOY_USER', 'RESTORE_HOST'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with('restore workflows');

it('gates a production restore behind an exact typed confirmation, before any environment', function () {
    [$workflow, $source] = restoreWorkflow('restore-production.yml');
    [$staging] = restoreWorkflow('restore-staging.yml');

    $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

    expect($inputs)->toHaveKey('confirmation')
        ->and(data_get($inputs, 'confirmation.required'))->toBeTrue()
        ->and(data_get($inputs, 'confirmation.type'))->toBe('string')
        ->and(data_get($inputs, 'confirmation.default'))->toBe('');

    expect($source)->toContain('if [[ "${CONFIRMATION}" != "RESTORE tits-guru" ]]; then');

    // Checked in the job that holds no environment, so an unconfirmed run
    // ends before approval is requested and long before any SSH connection.
    expect(data_get($workflow, 'jobs.validate.environment'))->toBeNull();

    // Staging has no such input: the confirmation is a production gate, and
    // adding it to staging would train operators to type past it.
    expect((array) data_get($staging, 'on.workflow_dispatch.inputs'))->not->toHaveKey('confirmation');
});

it('does not activate production or change any target lifecycle', function () {
    [, $source] = restoreWorkflow('restore-production.yml');

    // tits-guru stays planned, and this workflow is not what changes that.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    // Asserted against executable content only: the workflow's own header
    // legitimately EXPLAINS that tits-guru is lifecycle=planned, and a blunt
    // whole-file scan would forbid saying so.
    $executable = executableSourceLines($source);

    foreach ([
        'targets set',
        'deployment-targets.json',
        'lifecycle=active',
        'certbot',
        'dns',
        'provision',
    ] as $forbidden) {
        expect(mb_strtolower($executable))->not->toContain(mb_strtolower($forbidden));
    }
});

it('keeps the two restore workflows structurally identical apart from their identity', function () {
    [$staging] = restoreWorkflow('restore-staging.yml');
    [$production] = restoreWorkflow('restore-production.yml');

    // Same jobs, same order, same shared actions: production is not a second
    // implementation, it is the same one at a different identity.
    expect(array_keys($staging['jobs']))->toBe(array_keys($production['jobs']));

    $usesOf = static fn (array $workflow): array => collect($workflow['jobs'])
        ->flatMap(static fn (array $job): array => collect(data_get($job, 'steps', []))
            ->pluck('uses')
            ->filter()
            ->all())
        ->values()
        ->all();

    expect($usesOf($staging))->toBe($usesOf($production));
});
