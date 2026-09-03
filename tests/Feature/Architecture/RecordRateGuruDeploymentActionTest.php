<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * the deployment observability work: .github/actions/record-rateguru-deployment — the single place a
 * successful RateGuru deployment state transition is recorded, in Sentry and in
 * Laravel Nightwatch alike.
 *
 * A deploy and a rollback are the same event to observability: an immutable
 * release became the release this target serves, at this moment. These tests
 * pin the two properties that make that safe — the markers can only be produced
 * AFTER the application state transition succeeded, and no observability
 * failure can ever turn a healthy deployment into a failed one.
 */

/** @return array<string, mixed> */
function rrdAction(): array
{
    return Yaml::parse(File::get(base_path('.github/actions/record-rateguru-deployment/action.yml')));
}

function rrdActionSource(): string
{
    return File::get(base_path('.github/actions/record-rateguru-deployment/action.yml'));
}

/** @return array<string, mixed> */
function rrdWorkflow(string $name): array
{
    return Yaml::parse(File::get(base_path('.github/workflows/'.$name)));
}

/** @return array<int, array<string, mixed>> */
function rrdSteps(array $node): array
{
    return $node['steps'] ?? [];
}

// =============================================================================
// One shared implementation
// =============================================================================

it('is the one place a successful deployment is recorded', function () {
    expect(File::exists(base_path('.github/actions/record-rateguru-deployment/action.yml')))->toBeTrue();

    // Every caller uses the shared action; none reaches for the Sentry action
    // directly any more, which is what stops deploy, release and rollback from
    // growing three different observability implementations.
    $callers = [
        '.github/workflows/deploy-staging.yml',
        '.github/workflows/release.yml',
        '.github/actions/rollback-rateguru/action.yml',
    ];

    foreach ($callers as $caller) {
        $source = File::get(base_path($caller));

        // toContain is variadic in Pest — a second argument would be read as
        // another needle, not a message, so the diagnostic lives in a comment.
        expect($source)->toContain('./.github/actions/record-rateguru-deployment');
        expect($source)->not->toMatch('/^\s*uses:\s*\.\/\.github\/actions\/sentry-release\s*$/m',
            "{$caller} must not call the Sentry action directly any more");
    }

    // And the shared action reuses the existing Sentry implementation rather
    // than duplicating its logic.
    expect(rrdActionSource())->toContain('./.github/actions/sentry-release');
    expect(rrdActionSource())->not->toContain('getsentry/action-release');
});

it('takes the verified release and its real source commit, never a requested one', function () {
    $inputs = rrdAction()['inputs'];

    foreach (['deployment-target', 'environment', 'release-id', 'source-sha'] as $required) {
        expect($inputs)->toHaveKey($required);
        expect($inputs[$required]['required'])->toBeTrue("{$required} must be required");
    }
});

// =============================================================================
// Ordering: markers only follow a successful state transition
// =============================================================================

it('records the staging deployment only in a job that needs a successful deploy', function () {
    $workflow = rrdWorkflow('deploy-staging.yml');

    expect($workflow['jobs'])->toHaveKey('observability');
    expect($workflow['jobs']['observability']['needs'])->toContain('deploy');

    // The deploy job is what extracts the artifact, switches current, reloads
    // PHP-FPM, health-checks and verifies the active release against
    // release.json. A failed deployment therefore produces no marker at all.
    $names = array_column(rrdSteps($workflow['jobs']['observability']), 'uses');
    expect($names)->toContain('./.github/actions/record-rateguru-deployment');
});

it('records each release deployment after its own deploy step, inside the same job', function () {
    $workflow = rrdWorkflow('release.yml');

    foreach (['deploy-staging' => 'staging-main', 'deploy-production' => 'tits-guru'] as $job => $target) {
        $steps = rrdSteps($workflow['jobs'][$job]);
        $uses = array_map(static fn (array $step): string => $step['uses'] ?? '', $steps);

        $deployIndex = array_search('./.github/actions/deploy-rateguru', $uses, true);
        $recordIndex = array_search('./.github/actions/record-rateguru-deployment', $uses, true);

        expect($deployIndex)->not->toBeFalse("{$job} must deploy");
        expect($recordIndex)->not->toBeFalse("{$job} must record the deployment");
        expect($recordIndex)->toBeGreaterThan($deployIndex,
            "{$job} must record observability only after the deployment step");

        expect($steps[$recordIndex]['with']['deployment-target'])->toBe($target);
    }
});

it('records a rollback only after the restored release was read back off the server', function () {
    $action = Yaml::parse(File::get(base_path('.github/actions/rollback-rateguru/action.yml')));
    $steps = $action['runs']['steps'];

    $ids = array_map(static fn (array $step): string => $step['id'] ?? '', $steps);
    $uses = array_map(static fn (array $step): string => $step['uses'] ?? '', $steps);

    $rollbackIndex = array_search('rollback', $ids, true);
    $activeIndex = array_search('active', $ids, true);
    $recordIndex = array_search('./.github/actions/record-rateguru-deployment', $uses, true);

    expect($rollbackIndex)->toBeLessThan($activeIndex);
    expect($activeIndex)->toBeLessThan($recordIndex);

    // And it is skipped outright unless BOTH identities were resolved.
    $condition = $steps[$recordIndex]['if'];
    expect($condition)->toContain("steps.active.outputs.release_id != ''");
    expect($condition)->toContain("steps.active.outputs.source_sha != ''");
});

it('resolves the rollback release and its commit from the server, not from the request', function () {
    $source = File::get(base_path('.github/actions/rollback-rateguru/action.yml'));

    // `mode=previous` carries no release at all, so both identities have to
    // come from the target: the current symlink for the release, and that
    // release's own release.json for the commit.
    expect($source)->toContain('basename "$(readlink -f %q)"');
    expect($source)->toContain("jq -r '.source_sha // empty'");
    expect($source)->toContain("jq -r '.release // empty'");

    // The two must agree before either is published.
    expect($source)->toContain('release.json names ${metadata_release:-nothing}, but current resolves to ${active_release}');

    // A rollback records the existing immutable release; there is no synthetic
    // release identity anywhere in the pipeline.
    expect($source)->not->toMatch('/rollback-\$\{/');
    expect($source)->not->toContain('rollback-${GITHUB_RUN_ID}');
});

it('validates identity before any marker is produced', function () {
    $source = rrdActionSource();

    // Release ID, source SHA, target and environment are all matched against
    // closed patterns in the very first step, before any network call.
    expect($source)->toContain("release_regex='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'");
    expect($source)->toContain('[[ "${SOURCE_SHA}" =~ ^[0-9a-f]{7,40}$ ]]');
    expect($source)->toContain("target_regex='^[a-z0-9]+(-[a-z0-9]+)*\$'");

    $steps = rrdAction()['runs']['steps'];
    expect($steps[0]['id'])->toBe('preflight');
});

// =============================================================================
// Fail-open
// =============================================================================

it('cannot fail a healthy deployment when Nightwatch is unreachable', function () {
    $steps = rrdAction()['runs']['steps'];

    $nightwatch = collect($steps)->firstWhere('id', 'nightwatch');

    expect($nightwatch)->not->toBeNull();
    expect($nightwatch['continue-on-error'])->toBeTrue(
        'the deployment already succeeded; a Nightwatch outage is an observability gap, never a failed deployment');
});

it('cannot fail a healthy deployment when Sentry is unreachable', function () {
    $sentry = Yaml::parse(File::get(base_path('.github/actions/sentry-release/action.yml')));
    $record = collect($sentry['runs']['steps'])->firstWhere('id', 'sentry');

    expect($record['continue-on-error'])->toBeTrue();
});

it('skips the Nightwatch marker cleanly when the environment has no SSH material', function () {
    $source = rrdActionSource();

    expect($source)->toContain('nightwatch_configured=false');
    expect($source)->toContain('skipping the Nightwatch deployment marker');

    $steps = rrdAction()['runs']['steps'];
    $nightwatch = collect($steps)->firstWhere('id', 'nightwatch');

    expect($nightwatch['if'])->toContain("steps.preflight.outputs.nightwatch_configured == 'true'");
});

it('reports honestly which markers were and were not recorded', function () {
    $source = rrdActionSource();

    expect($source)->toContain('Nightwatch deployment marker FAILED');
    expect($source)->toContain('this is an observability gap and must not be rolled back for');
    expect($source)->toContain('- Sentry recorded:');
    expect($source)->toContain('- Nightwatch recorded:');
});

// =============================================================================
// Trust boundary
// =============================================================================

it('never runs application code on the runner and never sends credentials into a build', function () {
    $source = rrdActionSource();

    // The marker is produced on the server by the runtime user, inside the
    // release that is actually serving — never by booting a staging ref on a
    // GitHub runner with observability credentials attached.
    expect($source)->toContain('sudo -n /usr/local/sbin/rateguru-nightwatch-deployment');

    // Not one artisan invocation, and not one observability credential, on the
    // runner: the marker is produced by the target's own runtime user, inside
    // the release that is actually serving.
    $executable = executableSourceLines($source);

    expect($executable)->not->toContain('artisan');
    expect($executable)->not->toContain('NIGHTWATCH_TOKEN');
    expect($executable)->not->toContain('SENTRY_AUTH_TOKEN=');

    // The build job that compiles an arbitrary staging ref holds no
    // environment and therefore no observability secret.
    $deployStaging = rrdWorkflow('deploy-staging.yml');
    expect($deployStaging['jobs']['build'])->not->toHaveKey('environment');
});

it('builds the remote command as a fixed argv with no arbitrary shell', function () {
    $source = rrdActionSource();

    expect($source)->toContain('remote_command=(');
    expect($source)->toContain('"${remote_command[@]@Q}"');

    expect($source)->not->toMatch('/\beval\b/');
    expect($source)->not->toMatch('/StrictHostKeyChecking=no/');
    expect($source)->toContain('StrictHostKeyChecking=yes');
    expect($source)->toContain('BatchMode=yes');
    expect($source)->toContain('IdentitiesOnly=yes');
});

it('uses the ordinary deploy credential, in files its callers cannot clobber', function () {
    $source = rrdActionSource();

    // Recording a marker is a routine post-deployment step, not host
    // administration, so it deliberately does NOT reach for the privileged
    // bootstrap credential.
    expect($source)->not->toContain('BOOTSTRAP_SSH_KEY');

    // Distinct env and file names from the deploy and rollback actions: this
    // action runs inside jobs that already hold their own SSH material, and a
    // shared name would make the caller's cleanup delete the wrong file.
    expect($source)->toContain('RATEGURU_OBSERVABILITY_SSH_KEY_PATH');
    expect($source)->toContain('rateguru_observability_key');
    expect($source)->not->toContain('RATEGURU_SSH_KEY_PATH');

    $cleanup = collect(rrdAction()['runs']['steps'])
        ->firstWhere('name', 'Remove temporary observability SSH material');

    expect($cleanup['if'])->toBe('${{ always() }}');
});
