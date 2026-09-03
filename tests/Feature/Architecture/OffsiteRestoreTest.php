<?php

use Illuminate\Support\Facades\File;

/**
 * : target-aware offsite restore test.
 *
 * These tests execute the real shipped
 * `infrastructure/scripts/offsite-restore-test` — never a reimplementation of
 * its logic — against a genuine local rclone "remote" (see
 * OffsiteBackupTest.php's file-level doc comment for why this is preferred
 * over a stubbed rclone binary) and stubbed createdb/dropdb/pg_restore/psql
 * binaries (mirroring RestoreTest.php's own established technique for local
 * restore-test).
 *
 * offsite-restore-test's own `install -d -o root -g root` calls need real
 * root the same way backup/offsite-backup's own root-only install calls do —
 * see BackupTest.php's file-level doc comment for the full rationale. The
 * full-pipeline tests below run against a byte-for-byte patched copy of the
 * real script with every "-o root"/"-g root" replaced by this test process's
 * own numeric uid/gid (offsiteRestoreOpsPatchedScript).
 */
function offsiteRestoreOpsScript(): string
{
    return base_path('infrastructure/scripts/offsite-restore-test');
}

function offsiteRestoreOpsSource(): string
{
    return File::get(offsiteRestoreOpsScript());
}

function offsiteRestoreOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function offsiteRestoreOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function offsiteRestoreOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function offsiteRestoreOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function offsiteRestoreOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/offsite-restore-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function offsiteRestoreOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function offsiteRestoreOpsExec(string $scriptPath, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $scriptPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function offsiteRestoreOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', offsiteRestoreOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start offsite-restore-test subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function offsiteRestoreOpsRunHarness(string $scratch, string $body, array $env = [], ?string $scriptPath = null): array
{
    $scriptPath ??= offsiteRestoreOpsScript();
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg($scriptPath)."\n".$body."\n";
    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return offsiteRestoreOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

function offsiteRestoreOpsRcloneConfig(string $scratch): string
{
    $path = $scratch.'/rclone-'.uniqid('', true).'.conf';
    file_put_contents($path, "[rateguru-b2]\ntype = local\n");

    return $path;
}

function offsiteRestoreOpsRcloneBin(): string
{
    static $path = null;

    if ($path === null) {
        $path = trim((string) shell_exec('command -v rclone'));
        expect($path)->not->toBe('', 'rclone must be installed on PATH to run these tests (e.g. `brew install rclone`)');
    }

    return $path;
}

function offsiteRestoreOpsInstallRunuserStub(string $scratch): void
{
    $path = $scratch.'/bin/runuser';

    if (file_exists($path)) {
        return;
    }

    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'shift 2; shift'."\n"
        .'exec "$@"'."\n");
    chmod($path, 0o755);
}

function offsiteRestoreOpsCreatedbStub(string $scratch): string
{
    $path = $scratch.'/bin/createdb-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "createdb $*" >> "${RESTORE_STUB_CREATEDB_LOG}"'."\n"
        .'exit "${RESTORE_STUB_CREATEDB_EXIT:-0}"'."\n");
    chmod($path, 0o755);

    return $path;
}

function offsiteRestoreOpsDropdbStub(string $scratch): string
{
    $path = $scratch.'/bin/dropdb-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "dropdb $*" >> "${RESTORE_STUB_DROPDB_LOG}"'."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

function offsiteRestoreOpsPgRestoreStub(string $scratch): string
{
    $path = $scratch.'/bin/pg_restore-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "pg_restore $*" >> "${RESTORE_STUB_PG_RESTORE_LOG}"'."\n"
        ."cat >/dev/null\n"
        .'exit "${RESTORE_STUB_PG_RESTORE_EXIT:-0}"'."\n");
    chmod($path, 0o755);

    return $path;
}

function offsiteRestoreOpsPsqlStub(string $scratch): string
{
    $path = $scratch.'/bin/psql-stub';
    file_put_contents($path, <<<'BASH'
        #!/usr/bin/env bash
        echo "psql $*" >> "${RESTORE_STUB_PSQL_LOG}"
        cmd=""
        for arg in "$@"; do
            case "${arg}" in
                --command=*) cmd="${arg#--command=}" ;;
            esac
        done
        if [[ "${cmd}" == *"information_schema.tables"* ]]; then
            printf '%s\n' "${RESTORE_STUB_TABLE_COUNT:-5}"
        elif [[ "${cmd}" == *"migrations"* ]]; then
            printf '%s\n' "${RESTORE_STUB_MIGRATION_COUNT:-12}"
        else
            printf '0\n'
        fi
        BASH);
    chmod($path, 0o755);

    return $path;
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function offsiteRestoreOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => offsiteRestoreOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => offsiteRestoreOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => offsiteRestoreOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => offsiteRestoreOpsTargetsCli(),
        'RATEGURU_RCLONE_CONFIG' => offsiteRestoreOpsRcloneConfig($scratch),
        'RATEGURU_RCLONE_REMOTE' => 'rateguru-b2',
        'RATEGURU_RCLONE_BIN' => offsiteRestoreOpsRcloneBin(),
    ], $overrides);
}

function offsiteRestoreOpsPatchedScript(string $scratch): string
{
    $path = $scratch.'/patched-offsite-restore-test';

    if (file_exists($path)) {
        return $path;
    }

    $source = offsiteRestoreOpsSource();
    $source = str_replace('-o root', '-o '.getmyuid(), $source);
    $source = str_replace('-g root', '-g '.getmygid(), $source);

    file_put_contents($path, $source);
    chmod($path, 0o755);

    return $path;
}

/**
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function offsiteRestoreOpsParityRegistry(string $scratch, string $backupNamespace = 'parity', string $databaseName = 'parity_db'): array
{
    $account = trim((string) shell_exec('id -un'));
    $group = trim((string) shell_exec('id -gn'));

    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(offsiteRestoreOpsTargetsCli()),
    );
    $patchedTargets = str_replace(
        'elif [[ "${application_root}" != /home/www/rateguru/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'elif [[ "${incoming}" != /home/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_group}" ]]; then',
        'if false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_user}" ]]; then',
        'if false; then',
        $patchedTargets,
    );

    $targetsPath = $scratch.'/parity-targets-'.uniqid('', true);
    file_put_contents($targetsPath, $patchedTargets);
    chmod($targetsPath, 0o755);

    $registry = [
        'schema_version' => 1,
        'targets' => [
            'parity-target' => [
                'id' => 'parity-target',
                'lifecycle' => 'active',
                'environment_class' => 'staging',
                'application_root' => $scratch.'/unused-root',
                'runtime_user' => $account,
                'runtime_group' => $group,
                'deploy_user' => 'parity-deploy',
                'code_group' => $group,
                'incoming_artifacts' => $scratch.'/incoming-unused',
                'release_retention' => 5,
                'database' => ['name' => $databaseName, 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example'],
                'backup' => ['namespace' => $backupNamespace, 'local_retention_days' => 14, 'offsite_retention_days' => 90, 'minimum_retained_backups' => 2],
                'php_fpm' => ['pool' => 'parity-pool', 'socket' => '/run/php/parity.sock'],
                'supervisor' => ['program' => 'parity-queue', 'queue' => 'parity'],
                'scheduler' => ['name' => 'parity-scheduler'],
                'nginx' => ['site_name' => 'parity-site', 'internal_hostname' => 'parity.internal'],
                'environment_template' => 'infrastructure/templates/environment/staging.env.example',
            ],
        ],
    ];

    $registryPath = $scratch.'/parity-registry-'.uniqid('', true).'.json';
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "parity-target fixture failed validation:\n".implode("\n", $validateOutput));

    return [$registryPath, $targetsPath];
}

function offsiteRestoreOpsManifestSchema2(string $selector, ?string $target, string $environment, string $namespace, string $database): array
{
    return [
        'manifest_schema_version' => 2,
        'project' => 'rateguru',
        'selector' => $selector,
        'target' => $target,
        'environment' => $environment,
        'backup_namespace' => $namespace,
        'created_at' => '2026-01-01T00:00:00Z',
        'hostname' => 'test-host',
        'database' => $database,
        'release' => 'v1.0.0-20260101-000000-aaa1111',
        'postgres_version' => 'pg_dump (PostgreSQL) 16.4',
        'php_version' => '8.5.0',
    ];
}

function offsiteRestoreOpsManifestSchema1(string $environment, string $database): array
{
    return [
        'project' => 'rateguru',
        'environment' => $environment,
        'created_at' => '2026-01-01T00:00:00Z',
        'hostname' => 'test-host',
        'database' => $database,
        'release' => 'v1.0.0-20260101-000000-aaa1111',
        'postgres_version' => 'pg_dump (PostgreSQL) 16.4',
        'php_version' => '8.5.0',
    ];
}

/**
 * Builds a real, on-disk *remote* backup directory (under the local-backend
 * rclone bucket root) — a genuine gzip storage archive, arbitrary
 * database.dump/environment.env/release.json/server-configuration.tar.gz
 * content, the given manifest (or none at all, when $manifest is null), and
 * a genuine SHA256SUMS.
 */
function offsiteRestoreOpsBuildRemoteBackup(string $bucketRoot, string $namespace, string $timestamp, ?array $manifest, array $options = []): string
{
    $dir = "{$bucketRoot}/rateguru/{$namespace}/{$timestamp}";
    mkdir($dir, 0o755, true);

    file_put_contents($dir.'/database.dump', $options['database_dump'] ?? "FAKE-DUMP-BYTES\n");

    $stagingDir = $dir.'.storage-src';
    mkdir($stagingDir.'/app', 0o755, true);
    file_put_contents($stagingDir.'/app/marker.txt', "app\n");
    exec('tar -C '.escapeshellarg($stagingDir).' -czf '.escapeshellarg($dir.'/storage-app.tar.gz').' app');
    exec('rm -rf '.escapeshellarg($stagingDir));

    if (! empty($options['corrupt_storage_archive'])) {
        file_put_contents($dir.'/storage-app.tar.gz', "not actually a gzip archive\n");
    }

    file_put_contents($dir.'/environment.env', "APP_ENV=testing\n");
    file_put_contents($dir.'/release.json', json_encode(['release' => 'v1.0.0-20260101-000000-aaa1111']));
    file_put_contents($dir.'/server-configuration.tar.gz', "fake-server-config\n");

    $files = ['database.dump', 'storage-app.tar.gz', 'environment.env', 'release.json', 'server-configuration.tar.gz'];

    if ($manifest !== null) {
        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $files[] = 'manifest.json';
    }

    $lines = [];
    foreach ($files as $file) {
        $hash = hash_file('sha256', $dir.'/'.$file);
        $lines[] = "{$hash}  {$file}";
    }
    file_put_contents($dir.'/SHA256SUMS', implode("\n", $lines)."\n");

    if (! empty($options['corrupt_checksum'])) {
        file_put_contents($dir.'/database.dump', "TAMPERED-AFTER-CHECKSUM\n");
    }

    return $dir;
}

/**
 * Runs a real, full perform_offsite_restore_test pass (against the
 * root-bypassed patched script, genuine local-backend rclone, and stubbed
 * database binaries) against a purpose-built remote backup.
 *
 * $useParityTarget selects which target's registry values populate the
 * fixture: false uses staging-main's own committed registry values
 * (namespace "staging", database "rateguru_staging") against the real
 * committed registry; true uses a synthetic parity-target with independently
 * chosen defaults (namespace "parity", database "parity_db") via
 * offsiteRestoreOpsParityRegistry() — e.g. for schema-2 target-mismatch
 * coverage, which needs a target ID distinct from the manifest's own.
 *
 * @return array{exit: int, output: string, bucketRoot: string, remoteDir: string, resultFile: string, createdbLog: string, dropdbLog: string, pgRestoreLog: string, psqlLog: string, backupBase: string, targetId: string}
 */
function offsiteRestoreOpsRunFullOffsiteRestoreTest(string $scratch, bool $useParityTarget, ?array $manifest, array $options = []): array
{
    $backupBase = $scratch.'/backups-'.uniqid('', true);
    $namespace = $options['namespace'] ?? ($useParityTarget ? 'parity' : 'staging');
    $databaseName = $options['database_name'] ?? ($useParityTarget ? 'parity_db' : 'rateguru_staging');
    $bucketRoot = $scratch.'/bucket-'.uniqid('', true);
    mkdir($bucketRoot, 0o755, true);

    $timestamp = $options['timestamp'] ?? '20260115-120000';
    $remoteDir = offsiteRestoreOpsBuildRemoteBackup($bucketRoot, $namespace, $timestamp, $manifest, $options);

    if (isset($options['extra_backups'])) {
        foreach ($options['extra_backups'] as [$extraNamespace, $extraTimestamp, $extraManifest]) {
            offsiteRestoreOpsBuildRemoteBackup($bucketRoot, $extraNamespace, $extraTimestamp, $extraManifest);
        }
    }

    $runRoot = $scratch.'/run-'.uniqid('', true);

    offsiteRestoreOpsInstallRunuserStub($scratch);
    $createdbLog = $scratch.'/createdb-'.uniqid('', true).'.log';
    touch($createdbLog);
    $dropdbLog = $scratch.'/dropdb-'.uniqid('', true).'.log';
    touch($dropdbLog);
    $pgRestoreLog = $scratch.'/pg_restore-'.uniqid('', true).'.log';
    touch($pgRestoreLog);
    $psqlLog = $scratch.'/psql-'.uniqid('', true).'.log';
    touch($psqlLog);

    $env = offsiteRestoreOpsBaseEnv($scratch, [
        'RATEGURU_BACKUP_BASE' => $backupBase,
        'RATEGURU_RUN_ROOT' => $runRoot,
        'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
        'RATEGURU_CREATEDB_BIN' => offsiteRestoreOpsCreatedbStub($scratch),
        'RATEGURU_DROPDB_BIN' => offsiteRestoreOpsDropdbStub($scratch),
        'RATEGURU_PG_RESTORE_BIN' => offsiteRestoreOpsPgRestoreStub($scratch),
        'RATEGURU_PSQL_BIN' => offsiteRestoreOpsPsqlStub($scratch),
        'RESTORE_STUB_CREATEDB_LOG' => $createdbLog,
        'RESTORE_STUB_DROPDB_LOG' => $dropdbLog,
        'RESTORE_STUB_PG_RESTORE_LOG' => $pgRestoreLog,
        'RESTORE_STUB_PSQL_LOG' => $psqlLog,
        'RESTORE_STUB_TABLE_COUNT' => (string) ($options['table_count'] ?? 5),
        'RESTORE_STUB_MIGRATION_COUNT' => (string) ($options['migration_count'] ?? 12),
    ]);

    if ($useParityTarget) {
        $targetId = 'parity-target';
        [$registryPath, $targetsPath] = offsiteRestoreOpsParityRegistry($scratch, $namespace, $databaseName);
        $env['RATEGURU_TARGET_REGISTRY_FILE'] = $registryPath;
        $env['RATEGURU_TARGETS_CLI'] = $targetsPath;
    } else {
        $targetId = 'staging-main';
    }

    [$exit, $output] = offsiteRestoreOpsRunHarness(
        $scratch,
        "parse_offsite_restore_args --target {$targetId}\nresolve_offsite_restore_subject\nperform_offsite_restore_test",
        $env,
        offsiteRestoreOpsPatchedScript($scratch),
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'bucketRoot' => $bucketRoot,
        'remoteDir' => $remoteDir,
        'resultFile' => $backupBase.'/offsite-restore-tests.jsonl',
        'createdbLog' => $createdbLog,
        'dropdbLog' => $dropdbLog,
        'pgRestoreLog' => $pgRestoreLog,
        'psqlLog' => $psqlLog,
        'backupBase' => $backupBase,
        'targetId' => $targetId,
    ];
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRestoreOpsParityRegistry($scratch);

        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_restore_args --target parity-target
            resolve_offsite_restore_subject
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, offsiteRestoreOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, 'parse_offsite_restore_args', offsiteRestoreOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    // --environment is no longer a recognized flag at all — there is no
    // special deprecation message, just the same generic rejection any
    // other bogus flag gets.
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, 'parse_offsite_restore_args --environment staging', offsiteRestoreOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunHarness(
            $scratch,
            'parse_offsite_restore_args --target a --target b',
            offsiteRestoreOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a selector given without a value, with an empty value, or with a flag-shaped value', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, 'parse_offsite_restore_args --target', offsiteRestoreOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');

        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, "parse_offsite_restore_args --target ''", offsiteRestoreOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');

        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, 'parse_offsite_restore_args --target --help', offsiteRestoreOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --help');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, 'parse_offsite_restore_args --help', offsiteRestoreOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->not->toContain('--environment');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, 'parse_offsite_restore_args --bogus', offsiteRestoreOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

// =============================================================================
// Root-first, lifecycle ordering
// =============================================================================

it('requires root before anything else, even for --target tits-guru', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunScript(['--target', 'tits-guru'], offsiteRestoreOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a planned target before any rclone config, remote listing, lock, temporary directory or database work', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_restore_args --target tits-guru
            resolve_offsite_restore_subject
            echo "SHOULD NOT REACH HERE"
            BASH, offsiteRestoreOpsBaseEnv($scratch, ['RATEGURU_RCLONE_CONFIG' => '/definitely/missing/rclone.conf']));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_restore_args --target ghost-target
            resolve_offsite_restore_subject
            BASH, offsiteRestoreOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

// =============================================================================
// Value resolution and source isolation
// =============================================================================

it('resolves values only from the registry', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRestoreOpsParityRegistry($scratch);

        $brokenConfPath = $scratch.'/broken-deployment.conf';
        file_put_contents($brokenConfPath, "RELEASE_ID_REGEX='^v'\n");

        [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_restore_args --target parity-target
            resolve_offsite_restore_subject
            printf 'ENVIRONMENT_CLASS=%s\n' "${ENVIRONMENT_CLASS}"
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            BASH, offsiteRestoreOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $brokenConfPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('ENVIRONMENT_CLASS=staging')
            ->toContain('BACKUP_NAMESPACE=parity');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('resolve_offsite_restore_subject resolves values only through require_active_target and the target_* accessors', function () {
    $source = offsiteRestoreOpsSource();

    expect(preg_match(
        '/^resolve_offsite_restore_subject\(\) \{\n(.*?)\n\}\n/ms',
        $source,
        $matches,
    ))->toBe(1, 'could not locate resolve_offsite_restore_subject() in scripts/offsite-restore-test');

    expect($matches[1])
        ->toContain('require_active_target')
        ->toContain('target_environment_class')
        ->toContain('target_backup_namespace')
        ->not->toContain('environment_backup_namespace')
        ->not->toContain('validate_environment');
});

// =============================================================================
// Namespace-keyed lock and remote root
// =============================================================================

it('the lock filename and remote root are built from the backup namespace, never from the selector label', function () {
    $source = offsiteRestoreOpsSource();

    expect($source)->toContain('LOCK_FILE="${RUN_ROOT}/offsite-restore-test-${BACKUP_NAMESPACE}.lock"');
    expect($source)->toContain('REMOTE_ROOT="${RCLONE_REMOTE}:${BUCKET}/rateguru/${BACKUP_NAMESPACE}"');
    expect($source)->not->toContain('offsite-restore-test-${LABEL}.lock');
});

it('cannot run two offsite restore tests concurrently against the same namespace lock', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRestoreOpsParityRegistry($scratch, 'parity', 'parity_db');

        $bucketRoot = $scratch.'/bucket';
        offsiteRestoreOpsBuildRemoteBackup($bucketRoot, 'parity', '20260115-120000', offsiteRestoreOpsManifestSchema2('target', 'parity-target', 'staging', 'parity', 'parity_db'));

        $runRoot = $scratch.'/run-'.uniqid('', true);
        mkdir($runRoot, 0o755, true);
        $lockFile = $runRoot.'/offsite-restore-test-parity.lock';

        $lockHandle = fopen($lockFile, 'c');
        expect(flock($lockHandle, LOCK_EX | LOCK_NB))->toBeTrue('could not pre-acquire the shared namespace lock');

        try {
            offsiteRestoreOpsInstallRunuserStub($scratch);

            [$exit, $output] = offsiteRestoreOpsRunHarness($scratch, <<<'BASH'
                parse_offsite_restore_args --target parity-target
                resolve_offsite_restore_subject
                perform_offsite_restore_test
                BASH, offsiteRestoreOpsBaseEnv($scratch, [
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
                'RATEGURU_RUN_ROOT' => $runRoot,
                'RATEGURU_BACKUP_BASE' => $scratch.'/backups',
                'RATEGURU_CREATEDB_BIN' => offsiteRestoreOpsCreatedbStub($scratch),
                'RATEGURU_DROPDB_BIN' => offsiteRestoreOpsDropdbStub($scratch),
                'RATEGURU_PG_RESTORE_BIN' => offsiteRestoreOpsPgRestoreStub($scratch),
                'RATEGURU_PSQL_BIN' => offsiteRestoreOpsPsqlStub($scratch),
                'RESTORE_STUB_CREATEDB_LOG' => $scratch.'/createdb.log',
                'RESTORE_STUB_DROPDB_LOG' => $scratch.'/dropdb.log',
                'RESTORE_STUB_PG_RESTORE_LOG' => $scratch.'/pg_restore.log',
                'RESTORE_STUB_PSQL_LOG' => $scratch.'/psql.log',
            ]), offsiteRestoreOpsPatchedScript($scratch));

            expect($exit)->not->toBe(0);
            expect($output)->toContain('already running');
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

// =============================================================================
// Full pipeline (root-bypassed via offsiteRestoreOpsPatchedScript, genuine
// local-backend rclone, stubbed database binaries)
// =============================================================================

it('selects the latest remote backup only within the resolved namespace', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        // Both extra backups land in the exact same bucket root the helper
        // itself creates (via the extra_backups option) — an older one in
        // the resolved "staging" namespace, and an even newer one in an
        // unrelated namespace that must be ignored.
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'), options: [
            'timestamp' => '20260115-120000',
            'extra_backups' => [
                ['staging', '20260101-000000', offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging')],
                ['other-namespace', '20260201-000000', offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging')],
            ],
        ]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('20260115-120000');
        expect($result['output'])->not->toContain('20260201-000000');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('runs checksum validation before creating the temporary database', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'), options: [
            'corrupt_checksum' => true,
        ]);

        expect($result['exit'])->not->toBe(0);
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run before checksum validation passes');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('runs storage archive validation before creating the temporary database', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'), options: [
            'corrupt_storage_archive' => true,
        ]);

        expect($result['exit'])->not->toBe(0);
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run before storage archive validation passes');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a remote backup with no manifest.json at all before creating the temporary database', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: null);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('missing downloaded backup file: manifest.json');
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run before manifest validation passes');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('validates a schema 1 (legacy) manifest and remains restorable through --target staging-main', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'));

        expect($result['exit'])->toBe(0, $result['output']);
        expect(File::get($result['createdbLog']))->not->toBe('');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('validates a schema 1 (legacy) manifest and remains restorable through --target for the same namespace', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: true, manifest: offsiteRestoreOpsManifestSchema1('staging', 'parity_db'));

        expect($result['exit'])->toBe(0, $result['output']);
        expect(File::get($result['createdbLog']))->not->toBe('');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a schema 2 manifest with the wrong environment before creating the temporary database', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $manifest = offsiteRestoreOpsManifestSchema2('environment', null, 'production', 'staging', 'rateguru_staging');

        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('environment mismatch');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a schema 2 manifest with the wrong backup_namespace before creating the temporary database', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $manifest = offsiteRestoreOpsManifestSchema2('environment', null, 'staging', 'some-other-namespace', 'rateguru_staging');

        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup_namespace mismatch');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a schema 2 target-selector manifest naming a different target before creating the temporary database', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $manifest = offsiteRestoreOpsManifestSchema2('target', 'some-other-target', 'staging', 'parity', 'parity_db');

        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: true, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('target mismatch');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects an unsupported numeric manifest schema_version before creating the temporary database', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $manifest = offsiteRestoreOpsManifestSchema2('environment', null, 'staging', 'staging', 'rateguru_staging');
        $manifest['manifest_schema_version'] = 3;

        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unsupported backup manifest schema_version: 3');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a string manifest schema_version of "2" before creating the temporary database', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $manifest = offsiteRestoreOpsManifestSchema2('environment', null, 'staging', 'staging', 'rateguru_staging');
        $manifest['manifest_schema_version'] = '2';

        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unsupported backup manifest schema_version: "2"');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('sanitizes the temporary database name from a namespace containing characters unsafe for a PostgreSQL identifier', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: true, manifest: offsiteRestoreOpsManifestSchema2('target', 'parity-target', 'staging', 'tits-guru', 'rateguru_tits_guru'), options: [
            'namespace' => 'tits-guru',
            'database_name' => 'rateguru_tits_guru',
        ]);

        expect($result['exit'])->toBe(0, $result['output']);

        $createdbLine = trim(File::get($result['createdbLog']));
        expect($createdbLine)->toMatch('/rateguru_offsite_restore_tits_guru_\d{14}_\d+/');
        expect($createdbLine)->not->toContain('tits-guru');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('createdb, pg_restore, psql and dropdb all receive the exact same temporary database name', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'));
        expect($result['exit'])->toBe(0, $result['output']);

        preg_match('/rateguru_offsite_restore_staging_\d{14}_\d+/', File::get($result['createdbLog']), $matches);
        expect($matches)->not->toBeEmpty('could not find the temporary database name in the createdb log');
        $databaseName = $matches[0];

        expect(File::get($result['pgRestoreLog']))->toContain("--dbname={$databaseName}");
        expect(File::get($result['psqlLog']))->toContain("--dbname={$databaseName}");
        expect(File::get($result['dropdbLog']))->toContain($databaseName);
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects a restored database with zero public tables, still drops the temporary database, and writes no history', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'), options: [
            'table_count' => 0,
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('no public tables');
        expect(File::get($result['dropdbLog']))->not->toBe('', 'the temporary database must still be dropped on failure');
        expect(file_exists($result['resultFile']))->toBeFalse('a failed restore test must never write history');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('rejects an unparseable migrations count before writing history', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'), options: [
            'migration_count' => 'not-a-number',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unable to determine migrations table row count');
        expect(file_exists($result['resultFile']))->toBeFalse();
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('drops the temporary database and removes the temp directory on success too', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'));

        expect($result['exit'])->toBe(0, $result['output']);
        expect(File::get($result['dropdbLog']))->not->toBe('');

        $tempParent = $result['backupBase'].'/.offsite-restore';
        $leftovers = is_dir($tempParent) ? array_diff(scandir($tempParent), ['.', '..']) : [];
        expect($leftovers)->toBeEmpty('no temporary download directory may remain after a successful restore test');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('writes history only after a successful restore, including selector/target/environment/namespace', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: true, manifest: offsiteRestoreOpsManifestSchema2('target', 'parity-target', 'staging', 'parity', 'parity_db'), options: [
            'migration_count' => 7,
            'table_count' => 9,
        ]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(file_exists($result['resultFile']))->toBeTrue();

        $lines = array_values(array_filter(explode("\n", trim(File::get($result['resultFile'])))));
        expect($lines)->toHaveCount(1);

        $entry = json_decode($lines[0], true);
        expect($entry)->toMatchArray([
            'status' => 'ok',
            'selector' => 'target',
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup_namespace' => 'parity',
            'tables' => 9,
            'migrations' => 7,
        ]);
        expect($entry)->toHaveKey('tested_at');
        expect($entry)->toHaveKey('backup');
        expect($entry)->toHaveKey('remote');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});

it('writes history with the real target ID, even when the restored manifest is a schema 1 (legacy) manifest with no target field of its own', function () {
    $scratch = offsiteRestoreOpsScratchDir();

    try {
        $result = offsiteRestoreOpsRunFullOffsiteRestoreTest($scratch, useParityTarget: false, manifest: offsiteRestoreOpsManifestSchema1('staging', 'rateguru_staging'));

        expect($result['exit'])->toBe(0, $result['output']);

        $lines = array_values(array_filter(explode("\n", trim(File::get($result['resultFile'])))));
        $entry = json_decode($lines[0], true);

        expect($entry['selector'])->toBe('target');
        expect($entry['target'])->toBe($result['targetId']);
        expect($entry['backup_namespace'])->toBe('staging');
    } finally {
        offsiteRestoreOpsCleanup($scratch);
    }
});
