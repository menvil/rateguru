<?php

use App\Support\Deployment\DeploymentMetadata;
use Illuminate\Support\Facades\File;

/**
 * The canonical release identity boundary. Everything here works on a scratch
 * directory rather than the repository root, so a working copy never needs a
 * release.json to run the suite and a test can never leave one behind.
 */
function deploymentMetadataFixture(?string $contents): string
{
    $root = sys_get_temp_dir().'/rateguru-release-metadata-'.uniqid('', true);

    File::makeDirectory($root, 0o755, true);

    if ($contents !== null) {
        File::put($root.'/release.json', $contents);
    }

    return $root;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/rateguru-release-metadata-*') ?: [] as $leftover) {
        File::deleteDirectory($leftover);
    }
});

it('reads the release and commit a real build pipeline writes', function () {
    // Byte-for-byte the shape .github/workflows/deploy-staging.yml produces.
    $root = deploymentMetadataFixture(json_encode([
        'project' => 'rateguru',
        'environment' => 'staging',
        'source_ref' => 'develop',
        'source_sha' => 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1',
        'release' => 'v0.0.0-20260826-120211-ca7d1c7',
        'built_at' => '2026-08-26T12:02:11Z',
        'workflow_run_id' => '17654321',
        'workflow_run_number' => '412',
    ]));

    $metadata = DeploymentMetadata::fromBasePath($root);

    expect($metadata->release())->toBe('v0.0.0-20260826-120211-ca7d1c7')
        ->and($metadata->commit())->toBe('ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1')
        ->and($metadata->state())->toBe(DeploymentMetadata::STATE_PRESENT);
});

it('reads the production release manifest, which carries extra fields the staging one does not', function () {
    // release.yml adds source_tag/version/targets. Reading must not depend on
    // the two manifests being identical — only on the two canonical fields.
    $root = deploymentMetadataFixture(json_encode([
        'project' => 'rateguru',
        'source_ref' => 'v0.5.0',
        'source_tag' => 'v0.5.0',
        'source_sha' => 'a81d7f2c3b4a5968778899aabbccddeeff001122',
        'version' => 'v0.5.0',
        'release' => 'v0.5.0-20260717-151500-a81d7f2',
        'targets' => ['staging-main', 'tits-guru'],
        'built_at' => '2026-07-17T15:15:00Z',
        'workflow_run_id' => '1',
        'workflow_run_number' => '1',
    ]));

    $metadata = DeploymentMetadata::fromBasePath($root);

    expect($metadata->release())->toBe('v0.5.0-20260717-151500-a81d7f2')
        ->and($metadata->commit())->toBe('a81d7f2c3b4a5968778899aabbccddeeff001122')
        ->and($metadata->state())->toBe(DeploymentMetadata::STATE_PRESENT);
});

it('reports missing metadata as unknown rather than inventing a release', function () {
    $metadata = DeploymentMetadata::fromBasePath(deploymentMetadataFixture(null));

    expect($metadata->release())->toBeNull()
        ->and($metadata->commit())->toBeNull()
        ->and($metadata->state())->toBe(DeploymentMetadata::STATE_MISSING);
});

it('never fabricates a release from malformed metadata', function (string $label, string $contents) {
    $metadata = DeploymentMetadata::fromBasePath(deploymentMetadataFixture($contents));

    expect($metadata->release())->toBeNull("{$label} must not produce a release")
        ->and($metadata->commit())->toBeNull("{$label} must not produce a commit")
        ->and($metadata->state())->toBe(DeploymentMetadata::STATE_MALFORMED);
})->with([
    ['truncated JSON', '{"release": "v0.0.0-20260826-120211-ca7d1c7"'],
    ['empty file', ''],
    ['a JSON scalar', '"v0.0.0-20260826-120211-ca7d1c7"'],
    ['a JSON array', '["v0.0.0-20260826-120211-ca7d1c7"]'],
    ['no release key', '{"project": "rateguru"}'],
    ['null release', '{"release": null, "source_sha": null}'],
    ['non-string release', '{"release": 42, "source_sha": 42}'],
]);

it('rejects a plausible-looking stand-in that is not the canonical release ID', function (string $label, string $release) {
    // These are the substitutions that would be easiest to accept by accident
    // and worst to accept: a bare Git SHA, a moving label, or a nearly-right
    // shape. The commit alongside them is genuine, and is still surfaced.
    $root = deploymentMetadataFixture(json_encode([
        'release' => $release,
        'source_sha' => 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1',
    ]));

    $metadata = DeploymentMetadata::fromBasePath($root);

    expect($metadata->release())->toBeNull("{$label} must not be accepted as a release")
        ->and($metadata->commit())->toBe('ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1')
        ->and($metadata->state())->toBe(DeploymentMetadata::STATE_MALFORMED);
})->with([
    ['a bare commit SHA', 'ca7d1c7'],
    ['a full commit SHA', 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1'],
    ['a moving label', 'latest'],
    ['an unknown-current placeholder', 'unknown-current'],
    ['a bare version', 'v0.0.0'],
    ['a missing v prefix', '0.0.0-20260826-120211-ca7d1c7'],
    ['a bare timestamp', '20260826-120211'],
]);

it('reports an existing but unreadable file as malformed, not as missing', function () {
    // The two are different diagnoses: absent is the normal state of a working
    // copy, unreadable inside a deployed release is a broken deploy someone has
    // to fix. Reporting the second as "missing" would hide it.
    $root = deploymentMetadataFixture('{"release": "v1.2.3-20260101-000000-abc1234", "source_sha": "abc1234"}');
    $path = $root.'/release.json';

    chmod($path, 0o000);

    // Running as root defeats the permission bit entirely, so only assert what
    // the environment can actually demonstrate.
    if (is_readable($path)) {
        chmod($path, 0o644);
        $this->markTestSkipped('cannot make a file unreadable for the current user');
    }

    $metadata = DeploymentMetadata::fromFile($path);

    chmod($path, 0o644);

    expect($metadata->state())->toBe(DeploymentMetadata::STATE_MALFORMED)
        ->and($metadata->release())->toBeNull()
        ->and($metadata->commit())->toBeNull();
});

it('keeps a valid release when only the commit is unusable, and still flags the file', function () {
    $root = deploymentMetadataFixture(json_encode([
        'release' => 'v0.0.0-20260826-120211-ca7d1c7',
        'source_sha' => 'not-a-sha',
    ]));

    $metadata = DeploymentMetadata::fromBasePath($root);

    expect($metadata->release())->toBe('v0.0.0-20260826-120211-ca7d1c7')
        ->and($metadata->commit())->toBeNull()
        ->and($metadata->state())->toBe(DeploymentMetadata::STATE_MALFORMED);
});

it('never consults Git to discover the release', function () {
    // A deployed release directory contains no .git at all, so any Git call
    // here would either fail on the server or — far worse — report whatever
    // repository happened to be an ancestor of the deploy path. Comments are
    // stripped first: the class documents the absence of .git at length, and
    // prose about the rule must not be mistaken for a violation of it.
    $code = phpSourceWithoutComments('app/Support/Deployment/DeploymentMetadata.php');

    foreach (['exec', 'shell_exec', 'proc_open', 'passthru', 'popen', 'system'] as $forbidden) {
        expect($code)->not->toMatch("/\\b{$forbidden}\\s*\\(/", "DeploymentMetadata must not call {$forbidden}()");
    }

    foreach (['git', 'rev-parse', 'describe'] as $forbidden) {
        expect(str_contains($code, $forbidden))
            ->toBeFalse("DeploymentMetadata must not reference {$forbidden}");
    }
});

it('keeps the whole application free of a runtime Git dependency for release identity', function () {
    // The rule is wider than one class: nothing that resolves the deployed
    // release may reach for Git, in app code or in the config that feeds it.
    foreach ([
        'config/deployment.php',
        'config/sentry.php',
        // the Nightwatch evaluation: Nightwatch's own `deploy` field is the canonical release
        // too, read the same way. A second vendor must not become a second
        // reason to shell out to Git.
        'config/nightwatch.php',
        'app/Providers/ObservabilityServiceProvider.php',
    ] as $path) {
        $code = phpSourceWithoutComments($path);

        foreach (['exec', 'shell_exec', 'proc_open', 'passthru', 'popen', 'system'] as $forbidden) {
            expect($code)->not->toMatch("/\\b{$forbidden}\\s*\\(/", "{$path} must not call {$forbidden}()");
        }

        expect(str_contains($code, 'rev-parse'))->toBeFalse("{$path} must not shell out to Git");
    }
});

it('resolves the metadata file next to the application root', function () {
    $root = deploymentMetadataFixture('{"release": "v1.2.3-20260101-000000-abc1234", "source_sha": "abc1234"}');

    // Trailing slash tolerated: config/deployment.php passes dirname(__DIR__),
    // but a caller normalizing paths must not silently read a different file.
    expect(DeploymentMetadata::fromBasePath($root)->release())
        ->toBe(DeploymentMetadata::fromBasePath($root.'/')->release())
        ->toBe('v1.2.3-20260101-000000-abc1234');

    expect(DeploymentMetadata::fileName())->toBe('release.json');
});
