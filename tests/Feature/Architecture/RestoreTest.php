<?php

use Illuminate\Support\Facades\File;

/**
 * : target-aware local restore test.
 *
 * These tests execute the real shipped `infrastructure/scripts/restore-test`
 * — never a reimplementation of its logic — with every host dependency
 * (common's deployment.conf, the target registry, the targets validator, the
 * createdb/dropdb/pg_restore/psql binaries, runuser) supplied through the
 * RATEGURU_* test-override contract, mirroring BackupTest.php exactly.
 *
 * restore-test's own `install -d -o root -g root -m 0700 "${RUN_ROOT}"` call
 * needs real root the same way backup's own root-only install calls do —
 * see BackupTest.php's file-level doc comment for the full rationale. The
 * full-pipeline tests below run against a byte-for-byte patched copy of the
 * real script with every "-o root"/"-g root" replaced by this test process's
 * own numeric uid/gid (restoreTestOpsPatchedScript).
 */
function restoreTestOpsScript(): string
{
    return base_path('infrastructure/scripts/restore-test');
}

function restoreTestOpsSource(): string
{
    return File::get(restoreTestOpsScript());
}

function restoreTestOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function restoreTestOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function restoreTestOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function restoreTestOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function restoreTestOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/restore-test-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function restoreTestOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function restoreTestOpsExec(string $scriptPath, array $env): array
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
function restoreTestOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', restoreTestOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start restore-test subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function restoreTestOpsRunHarness(string $scratch, string $body, array $env = [], ?string $scriptPath = null): array
{
    $scriptPath ??= restoreTestOpsScript();
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg($scriptPath)."\n".$body."\n";
    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return restoreTestOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function restoreTestOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => restoreTestOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => restoreTestOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => restoreTestOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => restoreTestOpsTargetsCli(),
    ], $overrides);
}

function restoreTestOpsPatchedScript(string $scratch): string
{
    $path = $scratch.'/patched-restore-test';

    if (file_exists($path)) {
        return $path;
    }

    $source = restoreTestOpsSource();
    $source = str_replace('-o root', '-o '.getmyuid(), $source);
    $source = str_replace('-g root', '-g '.getmygid(), $source);

    file_put_contents($path, $source);
    chmod($path, 0o755);

    return $path;
}

function restoreTestOpsInstallRunuserStub(string $scratch): void
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

function restoreTestOpsCreatedbStub(string $scratch): string
{
    $path = $scratch.'/bin/createdb-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "createdb $*" >> "${RESTORE_STUB_CREATEDB_LOG}"'."\n"
        .'exit "${RESTORE_STUB_CREATEDB_EXIT:-0}"'."\n");
    chmod($path, 0o755);

    return $path;
}

function restoreTestOpsDropdbStub(string $scratch): string
{
    $path = $scratch.'/bin/dropdb-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "dropdb $*" >> "${RESTORE_STUB_DROPDB_LOG}"'."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

function restoreTestOpsPgRestoreStub(string $scratch): string
{
    $path = $scratch.'/bin/pg_restore-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "pg_restore $*" >> "${RESTORE_STUB_PG_RESTORE_LOG}"'."\n"
        ."cat >/dev/null\n"
        .'exit "${RESTORE_STUB_PG_RESTORE_EXIT:-0}"'."\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * Distinguishes the two psql queries restore-test runs by grepping the
 * --command=... payload for a distinctive substring, and answers each from a
 * RESTORE_STUB_*_COUNT environment variable so each test controls both
 * counts independently without regenerating this stub.
 */
function restoreTestOpsPsqlStub(string $scratch): string
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
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active. restore-test never reads
 * application_root, so it is left pointed at a directory that need not
 * exist.
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function restoreTestOpsParityRegistry(string $scratch, string $backupNamespace = 'parity', string $databaseName = 'parity_db'): array
{
    $account = trim((string) shell_exec('id -un'));
    $group = trim((string) shell_exec('id -gn'));

    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(restoreTestOpsTargetsCli()),
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
                'backup' => ['namespace' => $backupNamespace, 'local_retention_days' => 14, 'offsite_retention_days' => 1, 'minimum_retained_backups' => 2],
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

/**
 * Builds a real, on-disk backup directory: a genuine gzip storage archive
 * (containing app/marker.txt), arbitrary database.dump/environment.env/
 * release.json/server-configuration.tar.gz content, the given manifest (or
 * none at all, when $manifest is null), and a genuine SHA256SUMS covering the
 * six files in the exact order backup itself produces them — computed with
 * real sha256sum so restore-test's own `sha256sum --check` is a real check,
 * not a rigged one.
 */
function restoreTestOpsBuildBackupDirectory(string $namespaceRoot, string $timestamp, ?array $manifest, array $options = []): string
{
    $dir = $namespaceRoot.'/'.$timestamp;
    mkdir($dir, 0o755, true);

    file_put_contents($dir.'/database.dump', $options['database_dump'] ?? "FAKE-DUMP-BYTES\n");

    $stagingDir = $dir.'.storage-src';
    mkdir($stagingDir.'/app', 0o755, true);
    file_put_contents($stagingDir.'/app/marker.txt', "app\n");
    exec('tar -C '.escapeshellarg($stagingDir).' -czf '.escapeshellarg($dir.'/storage-app.tar.gz').' app');
    exec('rm -rf '.escapeshellarg($stagingDir));

    file_put_contents($dir.'/environment.env', "APP_ENV=testing\n");
    file_put_contents($dir.'/release.json', json_encode(['release' => 'v1.0.0-20260101-000000-aaa1111']));
    file_put_contents($dir.'/server-configuration.tar.gz', "fake-server-config\n");

    // A null $manifest omits manifest.json entirely — rather than writing an
    // empty-but-present '{}' — so a test can exercise
    // validate_backup_manifest's own missing-manifest failure
    // ([[ -f "${manifest_path}" ]] || fail ...), not just an empty one.
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

    if (! empty($options['corrupt_storage_archive'])) {
        file_put_contents($dir.'/storage-app.tar.gz', "not actually a gzip archive\n");
        // Recompute SHA256SUMS so this failure is specifically about the
        // storage archive being unreadable, not a checksum mismatch masking it.
        $lines = [];
        foreach ($files as $file) {
            $hash = hash_file('sha256', $dir.'/'.$file);
            $lines[] = "{$hash}  {$file}";
        }
        file_put_contents($dir.'/SHA256SUMS', implode("\n", $lines)."\n");
    }

    return $dir;
}

function restoreTestOpsManifestSchema2(string $selector, ?string $target, string $environment, string $namespace, string $database): array
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

function restoreTestOpsManifestSchema1(string $environment, string $database): array
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
 * Runs a real, full perform_restore_test pass (against the root-bypassed
 * patched script) against a purpose-built backup directory, using
 * --target parity-target.
 *
 * $options['backupBase'] overrides the freshly generated default, letting a
 * caller pre-compute sibling backup paths (e.g. for $options['extra_backups'])
 * that land beneath the exact base this run will scan.
 *
 * @return array{exit: int, output: string, backupBase: string, namespaceRoot: string, backupDir: string, createdbLog: string, dropdbLog: string, pgRestoreLog: string, psqlLog: string, runRoot: string}
 */
function restoreTestOpsRunFullRestore(string $scratch, ?array $manifest, array $options = []): array
{
    $backupBase = $options['backupBase'] ?? $scratch.'/backups-'.uniqid('', true);
    $namespace = $options['namespace'] ?? 'parity';
    $databaseName = $options['database_name'] ?? 'parity_db';
    $namespaceRoot = $backupBase.'/'.$namespace;
    if (! is_dir($namespaceRoot)) {
        mkdir($namespaceRoot, 0o755, true);
    }

    $timestamp = $options['timestamp'] ?? '20260115-120000';
    $backupDir = restoreTestOpsBuildBackupDirectory($namespaceRoot, $timestamp, $manifest, $options);

    if (isset($options['extra_backups'])) {
        foreach ($options['extra_backups'] as [$extraNamespaceRoot, $extraTimestamp, $extraManifest]) {
            restoreTestOpsBuildBackupDirectory($extraNamespaceRoot, $extraTimestamp, $extraManifest);
        }
    }

    $runRoot = $scratch.'/run-'.uniqid('', true);

    restoreTestOpsInstallRunuserStub($scratch);
    $createdbLog = $scratch.'/createdb-'.uniqid('', true).'.log';
    touch($createdbLog);
    $dropdbLog = $scratch.'/dropdb-'.uniqid('', true).'.log';
    touch($dropdbLog);
    $pgRestoreLog = $scratch.'/pg_restore-'.uniqid('', true).'.log';
    touch($pgRestoreLog);
    $psqlLog = $scratch.'/psql-'.uniqid('', true).'.log';
    touch($psqlLog);

    [$registryPath, $targetsPath] = restoreTestOpsParityRegistry($scratch, $namespace, $databaseName);

    $env = restoreTestOpsBaseEnv($scratch, [
        'RATEGURU_BACKUP_BASE' => $backupBase,
        'RATEGURU_RUN_ROOT' => $runRoot,
        'RATEGURU_CREATEDB_BIN' => restoreTestOpsCreatedbStub($scratch),
        'RATEGURU_DROPDB_BIN' => restoreTestOpsDropdbStub($scratch),
        'RATEGURU_PG_RESTORE_BIN' => restoreTestOpsPgRestoreStub($scratch),
        'RATEGURU_PSQL_BIN' => restoreTestOpsPsqlStub($scratch),
        'RESTORE_STUB_CREATEDB_LOG' => $createdbLog,
        'RESTORE_STUB_DROPDB_LOG' => $dropdbLog,
        'RESTORE_STUB_PG_RESTORE_LOG' => $pgRestoreLog,
        'RESTORE_STUB_PSQL_LOG' => $psqlLog,
        'RESTORE_STUB_TABLE_COUNT' => (string) ($options['table_count'] ?? 5),
        'RESTORE_STUB_MIGRATION_COUNT' => (string) ($options['migration_count'] ?? 12),
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
    ]);

    [$exit, $output] = restoreTestOpsRunHarness(
        $scratch,
        "parse_restore_args --target parity-target\nresolve_restore_subject\nperform_restore_test",
        $env,
        restoreTestOpsPatchedScript($scratch),
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'backupBase' => $backupBase,
        'namespaceRoot' => $namespaceRoot,
        'backupDir' => $backupDir,
        'createdbLog' => $createdbLog,
        'dropdbLog' => $dropdbLog,
        'pgRestoreLog' => $pgRestoreLog,
        'psqlLog' => $psqlLog,
        'runRoot' => $runRoot,
    ];
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = restoreTestOpsParityRegistry($scratch);

        [$exit, $output] = restoreTestOpsRunHarness($scratch, <<<'BASH'
            parse_restore_args --target parity-target
            resolve_restore_subject
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, restoreTestOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness($scratch, 'parse_restore_args', restoreTestOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness(
            $scratch,
            'parse_restore_args --target a --target b',
            restoreTestOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects --target given without a value, with an empty value, or with a flag-shaped value', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness($scratch, 'parse_restore_args --target', restoreTestOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');

        [$exit, $output] = restoreTestOpsRunHarness($scratch, "parse_restore_args --target ''", restoreTestOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');

        [$exit, $output] = restoreTestOpsRunHarness($scratch, 'parse_restore_args --target --bogus', restoreTestOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --bogus');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness($scratch, 'parse_restore_args --help', restoreTestOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->not->toContain('--environment');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness($scratch, 'parse_restore_args --bogus', restoreTestOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness($scratch, 'parse_restore_args --environment staging', restoreTestOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

// =============================================================================
// Root-first, lifecycle ordering
// =============================================================================

it('requires root before anything else, even for --target tits-guru', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunScript(['--target', 'tits-guru'], restoreTestOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a planned target before any filesystem, lock, or database work', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness($scratch, <<<'BASH'
            parse_restore_args --target tits-guru
            resolve_restore_subject
            echo "SHOULD NOT REACH HERE"
            BASH, restoreTestOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness($scratch, <<<'BASH'
            parse_restore_args --target ghost-target
            resolve_restore_subject
            BASH, restoreTestOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

// =============================================================================
// Value resolution and source isolation
// =============================================================================

it('resolves values only from the registry, never from deployment.conf', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = restoreTestOpsParityRegistry($scratch);

        $brokenConfPath = $scratch.'/broken-deployment.conf';
        file_put_contents($brokenConfPath, "RELEASE_ID_REGEX='^v'\n");

        [$exit, $output] = restoreTestOpsRunHarness($scratch, <<<'BASH'
            parse_restore_args --target parity-target
            resolve_restore_subject
            printf 'DATABASE_NAME=%s\n' "${DATABASE_NAME}"
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            BASH, restoreTestOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $brokenConfPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('DATABASE_NAME=parity_db')
            ->toContain('BACKUP_NAMESPACE=parity');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('resolve_restore_subject only ever calls target_* registry helpers', function () {
    $source = restoreTestOpsSource();

    expect(preg_match(
        '/^resolve_restore_subject\(\) \{\n(.*?)^\}\n/ms',
        $source,
        $matches,
    ))->toBe(1, 'could not locate resolve_restore_subject() in scripts/restore-test');

    $body = $matches[1];

    expect($body)
        ->toContain('require_active_target')
        ->toContain('target_database_name')
        ->toContain('target_backup_namespace')
        ->toContain('target_environment_class')
        ->not->toContain('environment_database_name')
        ->not->toContain('environment_backup_namespace')
        ->not->toContain('validate_environment')
        ->not->toContain('SELECTOR_TYPE');
});

// =============================================================================
// Shared namespace and lock
// =============================================================================

it('the real committed staging-main target resolves the staging backup namespace', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        [$exit, $output] = restoreTestOpsRunHarness($scratch, <<<'BASH'
            parse_restore_args --target staging-main
            resolve_restore_subject
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            BASH, restoreTestOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('BACKUP_NAMESPACE=staging');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('the lock filename is built from the backup namespace, never from the selector label', function () {
    $source = restoreTestOpsSource();

    expect($source)->toContain('LOCK_FILE="${RUN_ROOT}/restore-test-${BACKUP_NAMESPACE}.lock"');
    expect($source)->not->toContain('LOCK_FILE="${RUN_ROOT}/restore-test-${LABEL}.lock"');
});

it('cannot run two restore tests concurrently against the same namespace', function () {
    // Proves the shared-lock contract for real: holds the exact lock file
    // "restore-test --target parity-target" would acquire (built from the
    // namespace, not the selector label), then runs a real
    // "restore-test --target parity-target" pointed at that same namespace
    // and confirms it is refused as already running — never silently
    // proceeding to run a second restore test concurrently.
    $scratch = restoreTestOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = restoreTestOpsParityRegistry($scratch, 'parity', 'parity_db');

        $backupBase = $scratch.'/backups';
        $namespaceRoot = $backupBase.'/parity';
        restoreTestOpsBuildBackupDirectory(
            $namespaceRoot,
            '20260115-120000',
            restoreTestOpsManifestSchema2('target', 'parity-target', 'staging', 'parity', 'parity_db'),
        );

        $runRoot = $scratch.'/run-'.uniqid('', true);
        mkdir($runRoot, 0o755, true);
        $lockFile = $runRoot.'/restore-test-parity.lock';

        $lockHandle = fopen($lockFile, 'c');
        expect(flock($lockHandle, LOCK_EX | LOCK_NB))->toBeTrue('could not pre-acquire the shared namespace lock');

        try {
            restoreTestOpsInstallRunuserStub($scratch);

            [$exit, $output] = restoreTestOpsRunHarness($scratch, <<<'BASH'
                parse_restore_args --target parity-target
                resolve_restore_subject
                perform_restore_test
                BASH, restoreTestOpsBaseEnv($scratch, [
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_BACKUP_BASE' => $backupBase,
                'RATEGURU_RUN_ROOT' => $runRoot,
                'RATEGURU_CREATEDB_BIN' => restoreTestOpsCreatedbStub($scratch),
                'RATEGURU_DROPDB_BIN' => restoreTestOpsDropdbStub($scratch),
                'RATEGURU_PG_RESTORE_BIN' => restoreTestOpsPgRestoreStub($scratch),
                'RATEGURU_PSQL_BIN' => restoreTestOpsPsqlStub($scratch),
                'RESTORE_STUB_CREATEDB_LOG' => $scratch.'/createdb.log',
                'RESTORE_STUB_DROPDB_LOG' => $scratch.'/dropdb.log',
                'RESTORE_STUB_PG_RESTORE_LOG' => $scratch.'/pg_restore.log',
                'RESTORE_STUB_PSQL_LOG' => $scratch.'/psql.log',
            ]), restoreTestOpsPatchedScript($scratch));

            expect($exit)->not->toBe(0);
            expect($output)->toContain('already running');
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

// =============================================================================
// Full pipeline (root-bypassed via restoreTestOpsPatchedScript)
// =============================================================================

it('selects the latest timestamped backup only within the resolved namespace', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $backupBase = $scratch.'/backups-'.uniqid('', true);

        // An older sibling in the same (resolved) namespace, and a newer one
        // in a completely different namespace — neither must be picked. Built
        // through extra_backups, keyed off the same $backupBase the primary
        // backup below is created under and this run will scan — not an
        // unrelated directory the run never looks at.
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema1('staging', 'parity_db'), options: [
            'backupBase' => $backupBase,
            'timestamp' => '20260115-120000',
            'extra_backups' => [
                [$backupBase.'/parity', '20260101-000000', restoreTestOpsManifestSchema1('staging', 'parity_db')],
                [$backupBase.'/other-namespace', '20260201-000000', restoreTestOpsManifestSchema1('staging', 'rateguru_staging')],
            ],
        ]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('20260115-120000');
        expect($result['output'])->not->toContain('20260101-000000');
        expect($result['output'])->not->toContain('20260201-000000');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('runs checksum validation before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema1('staging', 'parity_db'), options: [
            'corrupt_checksum' => true,
        ]);

        expect($result['exit'])->not->toBe(0);
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run before checksum validation passes');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('runs storage archive validation before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema1('staging', 'parity_db'), options: [
            'corrupt_storage_archive' => true,
        ]);

        expect($result['exit'])->not->toBe(0);
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run before storage archive validation passes');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a backup with no manifest.json at all before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: null);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup manifest is missing');
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run before manifest validation passes');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('validates a schema 1 (legacy) manifest and remains restorable through --target', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema1('staging', 'parity_db'));

        expect($result['exit'])->toBe(0, $result['output']);
        expect(File::get($result['createdbLog']))->not->toBe('');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a schema 2 manifest with the wrong project before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'staging', 'parity', 'parity_db');
        $manifest['project'] = 'not-rateguru';

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('project mismatch');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a schema 2 manifest with the wrong environment before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'production', 'parity', 'parity_db');

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('environment mismatch');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a schema 2 manifest with the wrong database before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'staging', 'parity', 'rateguru_production');

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('database mismatch');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a schema 2 manifest with the wrong backup_namespace before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'staging', 'some-other-namespace', 'parity_db');

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup_namespace mismatch');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a schema 2 target-selector manifest naming a different target before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('target', 'some-other-target', 'staging', 'parity', 'parity_db');

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('target mismatch');
        expect(trim(File::get($result['createdbLog'])))->toBe('');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('accepts a schema 2 manifest with a null target under --target, when project/environment/database/namespace all match', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'staging', 'parity', 'parity_db');

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

// =============================================================================
// manifest_schema_version classification: strict, by JSON type, never by
// `jq -r '... // empty'` alone — see validate_backup_manifest's own comment
// for why a raw-output comparison against the literal string "2" used to be
// fail-open (a schema_version of 3, 0, the JSON string "2", "garbage", an
// array, an object or a boolean all quietly fell through as schema 1
// instead of being rejected).
// =============================================================================

it('accepts a manifest with no manifest_schema_version field at all as schema 1', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema1('staging', 'parity_db');

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('accepts a manifest with manifest_schema_version explicitly JSON null as schema 1', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema1('staging', 'parity_db');
        $manifest['manifest_schema_version'] = null;

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('accepts a manifest with a numeric manifest_schema_version of 2 as schema 2', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'staging', 'parity', 'parity_db');

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a numeric manifest_schema_version of 3 before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'staging', 'parity', 'parity_db');
        $manifest['manifest_schema_version'] = 3;

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unsupported backup manifest schema_version: 3');
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run for an unsupported schema_version');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a string manifest_schema_version of "2" before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'staging', 'parity', 'parity_db');
        $manifest['manifest_schema_version'] = '2';

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unsupported backup manifest schema_version: "2"');
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run for an unsupported schema_version');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a malformed (array-typed) manifest_schema_version before creating the temporary database', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $manifest = restoreTestOpsManifestSchema2('environment', null, 'staging', 'parity', 'parity_db');
        $manifest['manifest_schema_version'] = [1, 2];

        $result = restoreTestOpsRunFullRestore($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unsupported backup manifest schema_version: [1,2]');
        expect(trim(File::get($result['createdbLog'])))->toBe('', 'createdb must never run for an unsupported schema_version');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('sanitizes the temporary database name from a namespace containing characters unsafe for a PostgreSQL identifier', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema2('target', 'parity-target', 'staging', 'tits-guru', 'rateguru_tits_guru'), options: [
            'namespace' => 'tits-guru',
            'database_name' => 'rateguru_tits_guru',
        ]);

        expect($result['exit'])->toBe(0, $result['output']);

        $createdbLine = trim(File::get($result['createdbLog']));
        expect($createdbLine)->toMatch('/rateguru_restore_tits_guru_\d{14}_\d+/');
        expect($createdbLine)->not->toContain('tits-guru');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('createdb, pg_restore, psql and dropdb all receive the exact same temporary database name', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema1('staging', 'parity_db'));
        expect($result['exit'])->toBe(0, $result['output']);

        preg_match('/rateguru_restore_parity_\d{14}_\d+/', File::get($result['createdbLog']), $matches);
        expect($matches)->not->toBeEmpty('could not find the temporary database name in the createdb log');
        $databaseName = $matches[0];

        expect(File::get($result['pgRestoreLog']))->toContain("--dbname={$databaseName}");
        expect(File::get($result['psqlLog']))->toContain("--dbname={$databaseName}");
        expect(File::get($result['dropdbLog']))->toContain($databaseName);
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('rejects a restored database with zero public tables, still drops the temporary database, and writes no history', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema1('staging', 'parity_db'), options: [
            'table_count' => 0,
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('no public tables');
        expect(File::get($result['dropdbLog']))->not->toBe('', 'the temporary database must still be dropped on failure');
        expect(file_exists($result['namespaceRoot'].'/restore-tests.jsonl'))->toBeFalse('a failed restore test must never write history');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('drops the temporary database on success too', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema1('staging', 'parity_db'));

        expect($result['exit'])->toBe(0, $result['output']);
        expect(File::get($result['dropdbLog']))->not->toBe('');
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});

it('writes history only after a successful restore, including selector/target/environment/namespace', function () {
    $scratch = restoreTestOpsScratchDir();

    try {
        $result = restoreTestOpsRunFullRestore($scratch, manifest: restoreTestOpsManifestSchema2('target', 'parity-target', 'staging', 'parity', 'parity_db'), options: [
            'migration_count' => 7,
            'table_count' => 9,
        ]);

        expect($result['exit'])->toBe(0, $result['output']);

        $historyFile = $result['namespaceRoot'].'/restore-tests.jsonl';
        expect(file_exists($historyFile))->toBeTrue();

        $lines = array_values(array_filter(explode("\n", trim(File::get($historyFile)))));
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
    } finally {
        restoreTestOpsCleanup($scratch);
    }
});
