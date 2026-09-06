<?php

use Illuminate\Support\Facades\File;

/**
 * Recover Host: rebuilding one lost target onto a prepared replacement machine.
 *
 * Executes the real shipped infrastructure/scripts/recover-host end to end
 * against a file-backed fake PostgreSQL, a fake offsite remote, a fake
 * Supervisor and the REAL fetch-backup/verify-backup/restore-database/
 * restore-storage primitives. That is what makes these tests real rather than
 * rigged: the staged swap, the guard, the retained pre-recovery copies and the
 * compensation are all observable across separate script invocations, so
 * "nothing canonical was replaced" and "the prepared state came back" are
 * checked against catalog and filesystem state, not against log lines.
 */
function recoverHostScript(): string
{
    return base_path('infrastructure/scripts/recover-host');
}

/**
 * @return array{exit: int, output: string}
 */
function recoverHostRun(string $scratch, array $arguments, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    $env = infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
        fakePostgresEnv($scratch),
        targetRuntimeEnv($scratch),
        recoveryEnv($scratch),
        $envOverrides,
    ));

    [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'recover-host'), $arguments, $env);

    return ['exit' => $exit, 'output' => $output];
}

/**
 * A prepared, EMPTY replacement host with one exact offsite backup waiting for
 * it: the only starting state a recovery accepts.
 */
function recoveryFixture(string $scratch, array $options = []): void
{
    preparedTargetTreeFixture($scratch, $options);
    installFakePostgres($scratch, $options);
    installTargetRuntimeStubs($scratch);
    installFakePrepareHost($scratch);
    offsiteRcloneStub($scratch);

    file_put_contents($scratch.'/rclone.conf', "[rateguru-b2]\ntype = b2\n");
    touch($scratch.'/rclone.log');

    @mkdir($scratch.'/cron.d', 0o755, true);
    file_put_contents($scratch.'/cron.d/parity-scheduler', "* * * * * root true\n");

    // A prepared host's queue program is registered but has no application to
    // run: the committed program keeps autostart=true and crash-loops until a
    // deployment exists. FATAL is what that looks like, and it is not RUNNING.
    file_put_contents($scratch.'/supervisor-state', ($options['queue_state'] ?? 'FATAL')."\n");

    // The prepared database exists and is EMPTY.
    @mkdir($scratch.'/pg/tables', 0o755, true);
    @mkdir($scratch.'/pg/migrations', 0o755, true);
    file_put_contents($scratch.'/pg/tables/parity_db', ($options['prepared_tables'] ?? 0)."\n");
    file_put_contents($scratch.'/pg/migrations/parity_db', "0\n");

    recoveryOffsiteBackupFixture($scratch, $options['backup'] ?? '20260115-023000', $options['backup_options'] ?? []);
}

/** Runs a full --apply and returns the operation ID it generated. */
function recoveryApply(string $scratch, array $envOverrides = [], string $backupId = '20260115-023000'): array
{
    $result = recoverHostRun($scratch, [
        '--apply', '--target', 'parity-target', '--backup', $backupId,
    ], $envOverrides);

    return $result;
}

function recoveryOperationIdIn(string $output): string
{
    expect(preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $output, $matches))
        ->toBe(1, "no machine-readable recovery result in:\n".$output);

    return json_decode($matches[1], true)['operation'];
}

// =============================================================================
// Preconditions: the prepared, EMPTY contract
// =============================================================================

it('refuses a planned target before it reads a backup, a workspace or a database', function (string $mode) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $arguments = ['--'.$mode, '--target', 'planned-target'];

        if ($mode === 'check' || $mode === 'apply') {
            $arguments[] = '--backup';
            $arguments[] = '20260115-023000';
        } elseif ($mode !== 'verify') {
            $arguments[] = '--operation';
            $arguments[] = '20260115-041233-9be21c';
        }

        $result = recoverHostRun($scratch, $arguments);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('lifecycle=planned');

        expect(File::exists($scratch.'/run/recoveries'))->toBeFalse();
        expect(File::get($scratch.'/rclone.log'))->toBe('');
        expect(File::get($scratch.'/prepare-host.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }
})->with(['check', 'apply', 'inspect', 'resume', 'verify']);

it('requires root for every mode, before anything else', function (string $mode) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // The UNPATCHED script: its own require_root is the production gate.
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);
        $env = infraScriptEnv($scratch, $registryPath, $targetsPath, recoveryEnv($scratch));
        unset($env['RGTEST_BYPASS_ROOT']);

        [$exit, $output] = runInfraScript(recoverHostScript(), [
            '--'.$mode, '--target', 'parity-target', '--backup', '20260115-023000',
        ], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('must be executed as root');
    } finally {
        removeScratchDir($scratch);
    }
})->with(['check', 'apply'])->skip(fn (): bool => getmyuid() === 0, 'the root gate cannot be observed as root');

it('refuses a deployed target and names the operation that actually applies', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // Give the prepared host a release and a current link: it is deployed.
        mkdir($scratch.'/target/releases/'.FIXTURE_RELEASE, 0o755, true);
        symlink($scratch.'/target/releases/'.FIXTURE_RELEASE, $scratch.'/target/current');

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('this target is deployed, so it is not a lost host being rebuilt')
            ->toContain('Restore Target Data')
            ->toContain('Repair Target');

        expect(File::exists($scratch.'/run/recoveries'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a stray previous link and a releases tree that already holds a release', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        mkdir($scratch.'/target/releases/'.FIXTURE_RELEASE, 0o755, true);
        symlink($scratch.'/target/releases/'.FIXTURE_RELEASE, $scratch.'/target/previous');

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('but no current')
            ->toContain('already holds release directories');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a database that already holds tables, and never drops or truncates it', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['prepared_tables' => 12]);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('already holds 12 tables in the public schema')
            ->toContain('never drops, truncates or overwrites data it did not put there');

        expect(File::get($scratch.'/dropdb.log'))->toBe('');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a storage tree that already holds application data, and never removes it', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        mkdir($scratch.'/target/shared/storage/app/public', 0o755, true);
        file_put_contents($scratch.'/target/shared/storage/app/somebody-elses.txt', "data\n");

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('never deletes data it did not put there');

        expect(File::exists($scratch.'/target/shared/storage/app/somebody-elses.txt'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a host whose Prepare Host verification does not pass', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000'], [
            'RGTEST_PREPARE_HOST_EXIT' => '1',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('this machine is not a prepared host, and a recovery never prepares one');

        expect(File::get($scratch.'/prepare-host.log'))
            ->toContain('prepare-host --verify --target parity-target');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a running queue program, and an unobservable one', function (array $env, string $expected) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['queue_state' => 'RUNNING']);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000'], $env);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'a live worker' => [[], 'reports RUNNING'],
    'an unobservable group' => [
        ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'parity-queue: ERROR (no such group)'],
        'refusing to recover without knowing whether a worker is running',
    ],
]);

it('refuses a target held by a restore, and a target already being recovered', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        mkdir($scratch.'/run/restores/parity-target', 0o700, true);
        file_put_contents(
            $scratch.'/run/restores/parity-target/restore-guard',
            json_encode(['operation' => '20260115-120000-abc123', 'target' => 'parity-target', 'status' => 'held']),
        );

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a restore owns this target\'s data right now');
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        mkdir($scratch.'/run/recoveries/parity-target', 0o700, true);
        file_put_contents(
            $scratch.'/run/recoveries/parity-target/recovery-guard',
            json_encode(['operation' => '20260115-041233-9be21c', 'target' => 'parity-target', 'status' => 'awaiting-code']),
        );

        $result = recoverHostRun($scratch, ['--apply', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('is already being recovered by operation 20260115-041233-9be21c')
            ->toContain('rather than starting a second one');
    } finally {
        removeScratchDir($scratch);
    }
});

it('treats both guards at once as a hard failure with no continuation path', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        foreach (['restores' => 'restore-guard', 'recoveries' => 'recovery-guard'] as $namespace => $name) {
            mkdir($scratch.'/run/'.$namespace.'/parity-target', 0o700, true);
            file_put_contents(
                $scratch.'/run/'.$namespace.'/parity-target/'.$name,
                json_encode(['operation' => '20260115-041233-9be21c', 'target' => 'parity-target', 'status' => 'held']),
            );
        }

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('BOTH a restore guard and a recovery guard')
            ->toContain('resolve it by hand');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// --check is strictly read-only
// =============================================================================

it('creates no lock, workspace, guard, staging directory or database in check mode', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('RECOVERABLE: YES');

        // Nothing at all under the run root, and nothing downloaded.
        expect(File::exists($scratch.'/run'))->toBeFalse('--check must create no lock file and no run root');
        expect(File::exists($scratch.'/recoveries'))->toBeFalse();
        expect(File::get($scratch.'/rclone.log'))->toBe('', '--check must download nothing');
        expect(File::exists($scratch.'/target/shared/storage/app'))->toBeFalse();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/createdb.log'))->toBe('');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl stop');

        // No machine-readable result: --check is a read-only report, not a
        // terminal outcome a workflow branches on.
        expect($result['output'])->not->toContain('RATEGURU_RECOVER_RESULT=');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Backup selection: offsite only, exact, no fallback
// =============================================================================

it('offers no source selector, no latest and no local fallback', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $withSource = recoverHostRun($scratch, [
            '--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-023000',
        ]);
        expect($withSource['exit'])->not->toBe(0);
        expect($withSource['output'])->toContain('unknown argument: --source');

        $withoutBackup = recoverHostRun($scratch, ['--apply', '--target', 'parity-target']);
        expect($withoutBackup['exit'])->not->toBe(0);
        expect($withoutBackup['output'])->toContain("there is no 'latest'");

        $malformed = recoverHostRun($scratch, ['--apply', '--target', 'parity-target', '--backup', 'latest']);
        expect($malformed['exit'])->not->toBe(0);
        expect($malformed['output'])->toContain('invalid backup ID');

        $impossible = recoverHostRun($scratch, ['--apply', '--target', 'parity-target', '--backup', '20261399-023000']);
        expect($impossible['exit'])->not->toBe(0);
        expect($impossible['output'])->toContain('not a real UTC timestamp');
    } finally {
        removeScratchDir($scratch);
    }

    // And the source is fixed in the script's CODE — the prose above it says
    // there is no selector, which is exactly the sentence a whole-file grep
    // would read as one.
    $source = executableSourceLines(File::get(recoverHostScript()));

    expect(preg_match_all('/--source \w+/', $source, $matches))->toBeGreaterThan(0);
    expect(array_values(array_unique($matches[0])))->toBe(['--source offsite']);
});

it('drives the existing fetch-backup and verify-backup rather than downloading anything itself', function () {
    $source = File::get(recoverHostScript());

    // No second implementation of any backup mechanic.
    foreach ([
        'rclone',
        'sha256sum',
        'pg_restore',
        'tar -xzf',
        'manifest.json',
        'SHA256SUMS',
    ] as $forbidden) {
        expect(executableSourceLines($source))->not->toContain($forbidden);
    }

    expect($source)
        ->toContain('"${RESTORE_FETCH_BACKUP_BIN}"')
        ->toContain('"${RESTORE_VERIFY_BACKUP_BIN}"')
        ->toContain('"${RESTORE_DATABASE_BIN}"')
        ->toContain('"${RESTORE_STORAGE_BIN}"')
        ->toContain('--for-restore');
});

it('refuses a backup whose release.json carries no full 40-character source_sha', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => [
            'release_json' => ['project' => 'rateguru', 'release' => FIXTURE_RELEASE, 'source_sha' => 'abc1234'],
        ]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a recovery rebuilds an exact commit and will not guess one');

        // Refused before anything canonical was replaced.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup whose checksums do not verify, before any activation', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => ['corrupt_after_checksum' => true]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('failed SHA-256 verification');

        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::exists($scratch.'/target/shared/storage/app'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup that belongs to another target, before any activation', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => [
            'manifest' => backupManifestFixture(['target' => 'somebody-else']),
        ]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup manifest target mismatch');

        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The .env equality rule
// =============================================================================

it('fails closed before any activation when the prepared environment differs from the backup', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => [
            'environment' => preparedEnvironmentContents()."# a different environment\n",
        ]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('environment material: MISMATCH')
            ->toContain('Fix the external material the GitHub Environment supplies to Prepare Host');

        // Nothing was activated, and the prepared environment was not rewritten.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/target/shared/.env'))->toBe(preparedEnvironmentContents());
    } finally {
        removeScratchDir($scratch);
    }
});

it('never prints the content, digest or size of either environment file', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => [
            'environment' => "APP_ENV=staging\nDB_PASSWORD=a-completely-different-secret\n",
        ]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);

        foreach (['s3cr3t-not-logged', 'a-completely-different-secret', 'DB_PASSWORD'] as $secret) {
            expect($result['output'])->not->toContain($secret);
        }

        // No digest of either file, and no byte-length comparison. The only
        // long hex string a recovery ever prints is the backup's own
        // source_sha, which is public build identity and the whole point of
        // the operation — so it is excluded by name rather than by weakening
        // the rule.
        $hexRun = preg_replace('/\b'.FIXTURE_SOURCE_SHA.'\b/', '', $result['output']);

        expect($hexRun)->not->toMatch('/\b[0-9a-f]{32,}\b/');
        expect($result['output'])->not->toContain('bytes differ');
        expect($result['output'])->not->toContain('differ: byte');
    } finally {
        removeScratchDir($scratch);
    }

    // Structurally: the comparison is cmp -s, which prints nothing at all.
    expect(File::get(recoverHostScript()))
        ->toContain('cmp -s "${backup_env}" "${SHARED_ENV}"')
        ->not->toContain('sha256sum "${SHARED_ENV}"')
        ->not->toContain('diff ');
});

it('never applies environment.env or server-configuration.tar.gz', function () {
    $source = executableSourceLines(File::get(recoverHostScript()));

    // environment.env is READ, once, for the comparison — and never extracted,
    // installed or copied.
    expect(substr_count($source, 'environment.env'))->toBe(2);
    expect($source)->not->toContain('server-configuration.tar.gz');

    foreach (preg_split('/\R/', $source) as $line) {
        if (! str_contains($line, 'environment.env')) {
            continue;
        }

        foreach (['tar ', 'install ', 'cp ', 'mv ', '>'] as $mutation) {
            expect($line)->not->toContain($mutation, "environment.env must only be compared: {$line}");
        }
    }
});

// =============================================================================
// A successful --apply
// =============================================================================

it('restores the data and leaves the host deliberately not serving, awaiting code', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoveryApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);

        $operation = recoveryOperationIdIn($result['output']);

        expect($result['output'])
            ->toContain('DATA RESTORED: YES')
            ->toContain('CODE DEPLOYED: NO')
            ->toContain('TARGET SERVING: NO')
            ->toContain('RECOVERY STATUS: AWAITING CODE');

        // Exactly one machine-readable result, carrying identity only.
        expect(substr_count($result['output'], 'RATEGURU_RECOVER_RESULT='))->toBe(1);

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $result['output'], $matches);
        $payload = json_decode($matches[1], true);

        expect($payload)->toMatchArray([
            'status' => 'awaiting-code',
            'operation' => $operation,
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup' => '20260115-023000',
            'backup_release' => FIXTURE_RELEASE,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'data_restored' => true,
        ]);

        // The guard says awaiting-code and carries the exact required commit.
        expect(recoveryGuard($scratch))->toMatchArray([
            'operation' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'status' => 'awaiting-code',
        ]);

        // The data is canonical, and the pre-recovery copies are RETAINED.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('42');
        expect(File::exists($scratch.'/target/shared/storage/app/restored-marker.txt'))->toBeTrue();
        expect(File::exists($scratch.'/target/shared/storage/.pre-restore-app-'.$operation))->toBeTrue();

        // The runtime is held, target-scoped, with no maintenance mode at all.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(File::get($scratch.'/supervisor.log'))->toContain('supervisorctl stop parity-queue:*');
        expect(trim(File::get($scratch.'/supervisor-state')))->toBe('STOPPED');
        expect(File::get($scratch.'/php.log'))->toBe('', 'a PRE_DEPLOY target has no artisan to run');
        expect(File::get($scratch.'/health-check.log'))->toBe('', '--apply never health-checks a host with no code');

        // No release pointers were invented.
        expect(File::exists($scratch.'/target/current'))->toBeFalse();
        expect(File::exists($scratch.'/target/previous'))->toBeFalse();

        // No emergency backup, and no restore-test of an empty prepared state.
        expect(File::get($scratch.'/backup.log'))->toBe('');
        expect(File::get($scratch.'/restore-test.log'))->toBe('');

        $state = recoveryOperationState($scratch, $operation);
        expect($state)->toMatchArray([
            'operation_kind' => 'host-recovery',
            'status' => 'awaiting-code',
            'phase' => 'awaiting-code',
            'source' => 'offsite',
        ]);

        // And the history journal recorded it, identity only.
        $history = json_decode(trim(File::get($scratch.'/recoveries/recovery-history.jsonl')), true);
        expect($history)->toMatchArray([
            'status' => 'awaiting-code',
            'operation' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'data_restored' => true,
        ]);
        expect(File::get($scratch.'/recoveries/recovery-history.jsonl'))->not->toContain('s3cr3t');
    } finally {
        removeScratchDir($scratch);
    }
});

it('writes its workspace, guard and history root root-only', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoveryApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $operation = recoveryOperationIdIn($result['output']);
        $workspace = $scratch.'/run/recoveries/parity-target/'.$operation;

        expect(substr(sprintf('%o', fileperms($workspace)), -4))->toBe('0700');
        expect(substr(sprintf('%o', fileperms($workspace.'/state.json')), -4))->toBe('0600');
        expect(substr(sprintf('%o', fileperms($scratch.'/run/recoveries/parity-target/recovery-guard')), -4))->toBe('0600');
        expect(substr(sprintf('%o', fileperms($scratch.'/recoveries')), -4))->toBe('0700');
        expect(substr(sprintf('%o', fileperms($scratch.'/recoveries/recovery-history.jsonl')), -4))->toBe('0600');

        // A recovery lives in its own namespace, never the restore one.
        expect(File::exists($scratch.'/run/restores/parity-target/'.$operation))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('leaves the prepared canonical state untouched when staging fails', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoveryApply($scratch, ['RGTEST_PG_RESTORE_EXIT' => '3']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('the live database parity_db was never touched');

        // Nothing canonical replaced, no guard left behind, staged copies gone.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('0');

        // The runtime is exactly as Prepare Host left it: the hold is taken
        // only once the guard is on disk, immediately before activation.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl stop');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Activation failure and compensation
// =============================================================================

it('puts the scheduler back when a failure lands after the hold but before any activation', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // The FIRST catalog statement of activation — the connection barrier —
        // is unreachable, so the run fails with the runtime already held and
        // nothing canonical replaced.
        $result = recoveryApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_']);

        expect($result['exit'])->not->toBe(0);

        // Held, then released: the machine is a prepared PRE_DEPLOY host again.
        expect(File::get($scratch.'/supervisor.log'))->toContain('supervisorctl stop parity-queue:*');
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
        expect(recoveryGuard($scratch))->toBeNull();
    } finally {
        removeScratchDir($scratch);
    }
});

it('returns a failed activation to the prepared PRE_DEPLOY state', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // The FIRST activation rename fails, so the prepared database is never
        // moved aside: compensation finds nothing to undo, which is the state
        // it must recognise rather than "repair".
        $result = recoveryApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('could not rename parity_db aside')
            ->toContain('database compensation: nothing to undo');

        // The prepared, EMPTY database is canonical, and the staged copy is
        // gone rather than left behind on a replacement host.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('0');

        // The prepared storage tree is empty again, and the staged one is gone.
        expect(File::exists($scratch.'/target/shared/storage/app/restored-marker.txt'))->toBeFalse();
        expect(glob($scratch.'/target/shared/storage/.restore-*') ?: [])->toBe([]);

        // The guard was cleared and the scheduler put back: the host is a
        // prepared PRE_DEPLOY machine again.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();

        // The queue is left STOPPED — it was never running before, and
        // starting it would invent a state the machine never had.
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');

        $history = json_decode(trim(File::get($scratch.'/recoveries/recovery-history.jsonl')), true);
        expect($history['status'])->toBe('failed-recovered');
        expect($history['compensation_status'])->toBe('complete');
    } finally {
        removeScratchDir($scratch);
    }
});

it('holds the host and keeps the guard when compensation cannot complete', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // Every rename INTO the canonical name fails: the activation cannot
        // finish its second rename, and compensation cannot put the prepared
        // database back either.
        $result = recoveryApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'parity_db']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('The retained pre-recovery database and storage tree were NOT dropped.');

        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse('a held host keeps its scheduler out of cron.d');

        // The retained pre-recovery database still exists: nothing is dropped
        // while a recovery is held.
        expect(File::get($scratch.'/dropdb.log'))->not->toContain('rateguru_pre_');

        $history = json_decode(trim(File::get($scratch.'/recoveries/recovery-history.jsonl')), true);
        expect($history['status'])->toBe('failed-held');
        expect($history['compensation_status'])->toBe('incomplete');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// --inspect
// =============================================================================

it('reports what a host is waiting for without changing anything', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        $before = File::get($scratch.'/run/recoveries/parity-target/'.$operation.'/state.json');
        $guardBefore = File::get($scratch.'/run/recoveries/parity-target/recovery-guard');

        $result = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('STATUS: AWAITING CODE')
            ->toContain('REQUIRED SOURCE SHA: '.FIXTURE_SOURCE_SHA)
            ->toContain('CURRENT RELEASE: absent')
            ->toContain('nothing was changed');

        expect(substr_count($result['output'], 'RATEGURU_RECOVER_RESULT='))->toBe(1);

        expect(File::get($scratch.'/run/recoveries/parity-target/'.$operation.'/state.json'))->toBe($before);
        expect(File::get($scratch.'/run/recoveries/parity-target/recovery-guard'))->toBe($guardBefore);
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(File::get($scratch.'/health-check.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to inspect or resume an operation that belongs to a different target or does not exist', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $missing = recoverHostRun($scratch, [
            '--inspect', '--target', 'parity-target', '--operation', '20260115-041233-9be21c',
        ]);

        expect($missing['exit'])->not->toBe(0);
        expect($missing['output'])->toContain('recovery operation workspace does not exist');

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        // A restore operation's workspace is not a recovery's.
        restoreWorkspaceFixture($scratch, '20260115-120000-abc123');
        $wrongKind = recoverHostRun($scratch, [
            '--inspect', '--target', 'parity-target', '--operation', '20260115-120000-abc123',
        ]);

        expect($wrongKind['exit'])->not->toBe(0);
        expect($wrongKind['output'])->toContain('recovery operation workspace does not exist');

        // And a guard that names a different operation refuses the one asked for.
        $guard = json_decode(File::get($scratch.'/run/recoveries/parity-target/recovery-guard'), true);
        $guard['operation'] = '20260115-999999-aaaaaa';
        file_put_contents(
            $scratch.'/run/recoveries/parity-target/recovery-guard',
            json_encode($guard),
        );

        $mismatched = recoverHostRun($scratch, [
            '--inspect', '--target', 'parity-target', '--operation', $operation,
        ]);

        expect($mismatched['exit'])->not->toBe(0);
        expect($mismatched['output'])->toContain('is being recovered by operation 20260115-999999-aaaaaa');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// --resume
// =============================================================================

/** Simulates the controlled recovery deployment: a release, and current. */
function deployRecoveredRelease(string $scratch, string $sourceSha = FIXTURE_SOURCE_SHA, string $release = FIXTURE_RELEASE): void
{
    $root = $scratch.'/target';
    mkdir($root.'/releases/'.$release, 0o755, true);

    file_put_contents(
        $root.'/releases/'.$release.'/release.json',
        json_encode(['project' => 'rateguru', 'release' => $release, 'source_sha' => $sourceSha]),
    );

    symlink($root.'/releases/'.$release, $root.'/current');
}

it('finishes the recovery once the exact commit is deployed', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('HOST RECOVERY COMPLETE')
            ->toContain('HEALTH: PASS   QUEUE: RUNNING   SCHEDULER: PRESENT');

        expect(substr_count($result['output'], 'RATEGURU_RECOVER_RESULT='))->toBe(1);

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $result['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray([
            'status' => 'completed',
            'operation' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'current_release' => FIXTURE_RELEASE,
            'source_sha' => FIXTURE_SOURCE_SHA,
            'health' => 'pass',
            'queue' => 'running',
            'scheduler' => 'present',
        ]);

        // Only now are the retained pre-recovery copies committed.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/dropdb.log'))->toContain(preRestoreDatabaseName($operation));
        expect(File::exists($scratch.'/target/shared/storage/.pre-restore-app-'.$operation))->toBeFalse();
        expect(File::exists($scratch.'/target/shared/storage/app/restored-marker.txt'))->toBeTrue();

        // Runtime restored, target-scoped.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
        expect(trim(File::get($scratch.'/supervisor-state')))->toBe('RUNNING');
        expect(File::get($scratch.'/supervisor.log'))->toContain('supervisorctl start parity-queue:*');
        expect(File::get($scratch.'/health-check.log'))->toContain('health-check --target parity-target');

        // Guard cleared, workspace cleaned, history completed.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(File::exists($scratch.'/run/recoveries/parity-target/'.$operation))->toBeFalse();

        $records = array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(preg_split('/\R/', File::get($scratch.'/recoveries/recovery-history.jsonl'))),
        );
        expect(end($records))->toMatchArray([
            'status' => 'completed',
            'current_release' => FIXTURE_RELEASE,
            'current_source_sha' => FIXTURE_SOURCE_SHA,
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume when the deployed commit is not the one the data belongs to', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch, str_repeat('b', 40));

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('the target stays held, and no runtime was started');

        // Nothing was resumed, nothing was committed, the guard stands.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'awaiting-code']);
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume before any code has been deployed', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still has no current release');

        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'awaiting-code']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume a host whose previous link was invented', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);
        symlink($scratch.'/target/releases/'.FIXTURE_RELEASE, $scratch.'/target/previous');

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a recovery deployment leaves no implicit rollback target');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume when the migration count changed, and keeps the host held', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        // Something migrated the recovered data — which a recovery never does.
        file_put_contents($scratch.'/pg/migrations/parity_db', "23\n");

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('something migrated this data, which a recovery never does');

        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the host held when the health check fails after code alignment', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        $result = recoverHostRun($scratch, [
            '--resume', '--target', 'parity-target', '--operation', $operation,
        ], ['RGTEST_HEALTH_CHECK_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('health check failed after the recovery deployment')
            ->toContain('MANUAL RECOVERY REQUIRED');

        // The guard is NOT cleared, the pre-recovery copies are NOT dropped,
        // and the code is not rolled back to nothing.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
        expect(File::exists($scratch.'/target/current'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// --verify
// =============================================================================

it('verifies a fully recovered host, and accepts an absent previous link', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);
        deployRecoveredRelease($scratch);

        expect(recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation])['exit'])
            ->toBe(0);

        $result = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RECOVERED: YES')
            ->toContain('PREVIOUS: absent (normal for a freshly recovered host)');

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $result['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray([
            'status' => 'verified',
            'target' => 'parity-target',
            'health' => 'pass',
            'queue' => 'running',
            'scheduler' => 'present',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to verify a host that still carries a recovery guard', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);

        $result = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still carries a recovery guard');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Isolation
// =============================================================================

it('never stops a global service, never runs a migration and never rotates a secret', function () {
    $source = executableSourceLines(File::get(recoverHostScript()));

    foreach ([
        'systemctl stop',
        'systemctl restart',
        'service cron',
        'supervisorctl stop all',
        'supervisorctl shutdown',
        'artisan migrate',
        'migrate --force',
        'ALTER ROLE',
        'ALTER USER',
        'DROP SCHEMA',
        'CREATE SCHEMA',
        'PASSWORD',
        'certbot',
        'cloudflare',
        'route53',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden, "recover-host must never: {$forbidden}");
    }

    // Everything Supervisor-shaped is scoped to this target's own program.
    preg_match_all('/supervisorctl[^\n]*/', $source, $matches);
    foreach ($matches[0] as $line) {
        expect($line)->toContain('${SUPERVISOR_PROGRAM}');
    }
});

it('leaves another active target and every host-global service untouched', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // A second target's cron entry, and a host-global one.
        file_put_contents($scratch.'/cron.d/other-target-scheduler', "* * * * * root true\n");
        file_put_contents($scratch.'/cron.d/rateguru-backups', "30 2 * * * root true\n");

        $result = recoveryApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(File::exists($scratch.'/cron.d/other-target-scheduler'))->toBeTrue();
        expect(File::exists($scratch.'/cron.d/rateguru-backups'))->toBeTrue();

        foreach (preg_split('/\R/', File::get($scratch.'/supervisor.log')) as $line) {
            if (trim($line) === '') {
                continue;
            }

            expect($line)->toContain('parity-queue');
        }
    } finally {
        removeScratchDir($scratch);
    }
});
