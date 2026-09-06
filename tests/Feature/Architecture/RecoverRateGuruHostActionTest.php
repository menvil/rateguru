<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The reusable host-recovery transport action.
 *
 * It carries trusted tooling from the caller's own `develop` checkout to a
 * replacement machine, runs one fixed argv, parses exactly one machine-readable
 * result and cleans up after itself. Every decision a recovery makes lives in
 * infrastructure/scripts/recover-host; nothing here reimplements, mirrors or
 * second-guesses any of it.
 */
function recoverActionPath(): string
{
    return base_path('.github/actions/recover-rateguru-host/action.yml');
}

function recoverAction(): array
{
    return Yaml::parseFile(recoverActionPath());
}

function recoverActionSource(): string
{
    return File::get(recoverActionPath());
}

/** One step's `run:` body, by step name. */
function recoverActionStep(string $name): string
{
    foreach (recoverAction()['runs']['steps'] as $step) {
        if (($step['name'] ?? '') === $name) {
            return $step['run'] ?? '';
        }
    }

    throw new RuntimeException("no step named {$name}");
}

// =============================================================================
// The input contract
// =============================================================================

it('accepts a closed set of inputs and nothing that could redirect it', function () {
    $inputs = array_keys(recoverAction()['inputs']);
    sort($inputs);

    expect($inputs)->toBe([
        'backup-id',
        'bootstrap-known-hosts',
        'bootstrap-ssh-key',
        'bootstrap-user',
        'deployment-target',
        'environment',
        'mode',
        'operation-id',
        'recovery-host',
        'recovery-port',
    ]);

    // Nothing that could name a command, a filesystem path, a remote, a
    // bucket, a commit, a release or a build. Where a backup lives comes from
    // the registry plus fixed configuration, on the server; which commit the
    // data belongs to comes from that backup's own release.json.
    foreach ([
        'command', 'script', 'path', 'remote', 'bucket', 'source', 'ref', 'branch',
        'tag', 'source-sha', 'release', 'artifact', 'run-migrations', 'args',
        'rclone-config', 'environment-file', 'material-dir',
    ] as $rejected) {
        expect($inputs)->not->toContain($rejected);
    }
});

it('carries no secret material of any kind', function () {
    // Judged as code: the header prose lists, by name, every kind of material
    // this action deliberately does not carry, and a whole-file grep would
    // read that sentence as a violation of itself.
    $source = executableSourceLines(recoverActionSource());

    // The only credential is the privileged bootstrap one. There is no
    // application environment file, no authorized_keys, no rclone config, no
    // Basic Auth material and no TLS key anywhere in it.
    foreach ([
        'APP_KEY',
        'DB_PASSWORD',
        'authorized_keys',
        'htpasswd',
        'rclone.conf',
        'fullchain.pem',
        'privkey.pem',
        'B2_',
        'SENTRY_',
        'NIGHTWATCH_',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    expect(array_keys(recoverAction()['inputs']))->toContain('bootstrap-ssh-key');
});

it('refuses to fall back to the deployment key', function () {
    $source = recoverActionSource();

    expect($source)
        ->not->toContain('DEPLOY_SSH_KEY:')
        ->not->toContain('inputs.ssh-private-key');

    expect(recoverActionStep('Validate fixed caller inputs'))
        ->toContain('has no bootstrap SSH credential')
        ->toContain('must not be widened into a recovery credential');
});

it('holds every operand to its own mode', function () {
    $validation = recoverActionStep('Validate fixed caller inputs');

    expect($validation)
        ->toContain('apply|inspect|resume|verify')
        ->toContain('mode=apply requires backup-id as an exact YYYYMMDD-HHMMSS timestamp')
        ->toContain("There is no 'latest' and no implicit selection.")
        ->toContain('operation-id is not valid with mode=apply')
        ->toContain('requires operation-id in the server')
        ->toContain('backup-id is not valid with mode=${MODE}')
        ->toContain('mode=verify takes neither backup-id nor operation-id');

    // The two closed formats, checked on the runner so a malformed value never
    // reaches an SSH command line.
    expect($validation)
        ->toContain('^[0-9]{8}-[0-9]{6}$')
        ->toContain('^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$');
});

// =============================================================================
// Transport safety
// =============================================================================

it('uses strict host key checking with no TOFU anywhere', function () {
    $source = recoverActionSource();

    expect(substr_count($source, 'StrictHostKeyChecking=yes'))->toBeGreaterThanOrEqual(4);

    foreach ([
        'StrictHostKeyChecking=no',
        'StrictHostKeyChecking=accept-new',
        'UserKnownHostsFile=/dev/null',
        'ssh-keyscan',
        'PasswordAuthentication=yes',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // Every strict-checking option is paired with the verified known_hosts
    // file, so no invocation can pin one without the other.
    expect(substr_count($source, 'StrictHostKeyChecking=yes'))
        ->toBe(substr_count($source, 'UserKnownHostsFile="${RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH}"'));
});

it('verifies root or passwordless sudo before anything is uploaded', function () {
    $steps = array_column(recoverAction()['runs']['steps'], 'name');

    $access = array_search('Verify privileged access on the replacement host', $steps, true);
    $upload = array_search('Upload the trusted recovery bundle', $steps, true);
    $lifecycle = array_search('Validate the target lifecycle before anything is uploaded', $steps, true);

    expect($access)->toBeLessThan($upload);
    expect($lifecycle)->toBeLessThan($upload);

    expect(recoverActionStep('Verify privileged access on the replacement host'))
        ->toContain('sudo -n true')
        ->toContain('Recovery requires root; nothing was uploaded and nothing was changed.');
});

it('refuses a lifecycle=planned target on the runner, before the credential is used', function () {
    $steps = array_column(recoverAction()['runs']['steps'], 'name');

    expect(array_search('Validate the target lifecycle before anything is uploaded', $steps, true))
        ->toBeLessThan(array_search('Configure bootstrap SSH', $steps, true));

    expect(recoverActionStep('Validate the target lifecycle before anything is uploaded'))
        ->toContain('infrastructure/scripts/targets')
        ->toContain('lifecycle=${lifecycle:-unknown}, not active')
        ->toContain('Recovery is refused before any tooling is uploaded');
});

it('packages only infrastructure, never the application', function () {
    $package = recoverActionStep('Package trusted recovery bundle');

    expect($package)
        ->toContain('--directory "${GITHUB_WORKSPACE}"')
        ->toContain('infrastructure/scripts/recover-host')
        ->toContain('infrastructure/scripts/prepare-host');

    // One tar operand, and it is `infrastructure`.
    expect(preg_match('/^\s+infrastructure$/m', $package))->toBe(1);

    foreach (['app/', 'vendor', 'composer', 'npm', 'artisan', 'public/'] as $forbidden) {
        expect($package)->not->toContain($forbidden);
    }
});

it('runs recover-host from a root-only bundle with a fixed argv', function () {
    $run = recoverActionStep('Run the server-side recovery');

    // A Bash array, quoted element-wise — never an interpolated shell string.
    expect($run)
        ->toContain('remote_command=(')
        ->toContain('"${RATEGURU_REMOTE_ROOT}/infrastructure/scripts/recover-host"')
        ->toContain('"${remote_command[@]@Q}"');

    // Only the closed flag set, and only the operand its mode allows.
    expect($run)
        ->toContain('"--${MODE}"')
        ->toContain('--target "${DEPLOYMENT_TARGET}"')
        ->toContain('--backup "${BACKUP_ID}"')
        ->toContain('--operation "${OPERATION_ID}"');

    foreach (['eval ', 'bash -c "$', '--source', '--release', '--artifact'] as $forbidden) {
        expect($run)->not->toContain($forbidden);
    }

    // The bundle is installed root-only before anything runs from it.
    expect(recoverActionStep('Upload the trusted recovery bundle'))
        ->toContain('install -d -m 0700 -o root -g root')
        ->toContain('chown -R root:root');
});

it('parses exactly one machine-readable result, and checks it describes this run', function () {
    $run = recoverActionStep('Run the server-side recovery');

    // Counted, never `grep -m1`: the primitive's contract is exactly one
    // result per terminal success, and accepting two silently is the shape a
    // bug in that contract would take.
    expect($run)
        ->toContain("grep -c '^RATEGURU_RECOVER_RESULT='")
        ->toContain('Expected exactly one RATEGURU_RECOVER_RESULT line');

    expect(executableSourceLines($run))->not->toContain('grep -m1');

    // The status each mode must report, so a run that succeeds about a
    // different state is not passed on as a success.
    expect($run)
        ->toContain('apply)   expected_status=awaiting-code')
        ->toContain('inspect) expected_status=awaiting-code')
        ->toContain('resume)  expected_status=completed')
        ->toContain('verify)  expected_status=verified')
        ->toContain('.target == $target');
});

it('exposes typed outputs drawn only from that result', function () {
    $outputs = array_keys(recoverAction()['outputs']);
    sort($outputs);

    expect($outputs)->toBe([
        'backup',
        'backup-release',
        'current-release',
        'data-restored',
        'health',
        'operation',
        'required-source-sha',
        'source-sha',
        'status',
    ]);

    foreach (recoverAction()['outputs'] as $output) {
        expect($output['value'])->toStartWith('${{ steps.recover.outputs');
    }
});

it('removes its local and remote temporary files on success and on failure', function () {
    $steps = recoverAction()['runs']['steps'];

    $cleanups = array_values(array_filter(
        $steps,
        static fn (array $step): bool => str_starts_with($step['name'] ?? '', 'Remove '),
    ));

    expect($cleanups)->toHaveCount(2);

    foreach ($cleanups as $step) {
        expect($step['if'] ?? '')->toBe('${{ always() }}');
    }

    expect(recoverActionStep('Remove the remote recovery bundle'))
        ->toContain('rm -rf %q && rm -rf %q')
        ->toContain('never masks the real outcome');

    expect(recoverActionStep('Remove temporary local files'))
        ->toContain('RATEGURU_BUNDLE_PATH')
        ->toContain('RATEGURU_BOOTSTRAP_SSH_KEY_PATH')
        ->toContain('RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH');
});

// =============================================================================
// It is transport, not policy
// =============================================================================

it('contains no recovery business logic', function () {
    $source = recoverActionSource();

    // Every decision belongs to the server primitive. Nothing here reads a
    // guard, a state document, a backup or a database.
    foreach ([
        'recovery-guard',
        'state.json',
        'psql',
        'pg_restore',
        'createdb',
        'dropdb',
        'sha256sum',
        'supervisorctl',
        'cron.d',
        'shared/.env',
        'storage-app.tar.gz',
        'emergency',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden, "the transport action must never: {$forbidden}");
    }
});

it('never builds, deploys, prepares, repairs or restores', function () {
    $source = recoverActionSource();

    foreach ([
        'build-rateguru',
        'deploy-rateguru',
        'prepare-rateguru-host',
        'repair-rateguru-target',
        'restore-rateguru',
        'actions/upload-artifact',
        'actions/download-artifact',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // And it never runs any other server primitive.
    preg_match_all('#infrastructure/scripts/[a-z-]+#', $source, $matches);
    expect(array_values(array_unique($matches[0])))->toEqualCanonicalizing([
        'infrastructure/scripts/recover-host',
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/targets',
    ]);
});
