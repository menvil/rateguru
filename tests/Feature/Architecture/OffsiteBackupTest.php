<?php

use Illuminate\Support\Facades\File;

/**
 * : target-aware offsite backup.
 *
 * These tests execute the real shipped `infrastructure/scripts/offsite-backup`
 * — never a reimplementation of its logic — against a genuine local rclone
 * "remote": a minimal rclone.conf declaring a `type = local` remote named
 * `rateguru-b2` pointed at a scratch directory tree, so `rclone lsf`,
 * `rclone copy --immutable --check-first --checksum`, `rclone check` and
 * `rclone size --json` all run for real, with all their real semantics
 * (including `--immutable` genuinely refusing to overwrite a remote object
 * whose content differs) — never a stubbed rclone binary pretending to
 * understand those flags.
 *
 * offsite-backup's own `install -d -o root -g root` calls need real root the
 * same way backup's own root-only install calls do — see BackupTest.php's
 * file-level doc comment for the full rationale. The full-pipeline tests
 * below run against a byte-for-byte patched copy of the real script with
 * every "-o root"/"-g root" replaced by this test process's own numeric
 * uid/gid (offsiteBackupOpsPatchedScript).
 */
function offsiteBackupOpsScript(): string
{
    return base_path('infrastructure/scripts/offsite-backup');
}

function offsiteBackupOpsSource(): string
{
    return File::get(offsiteBackupOpsScript());
}

function offsiteBackupOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function offsiteBackupOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function offsiteBackupOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function offsiteBackupOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function offsiteBackupOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/offsite-backup-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function offsiteBackupOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function offsiteBackupOpsExec(string $scriptPath, array $env): array
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
function offsiteBackupOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', offsiteBackupOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start offsite-backup subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function offsiteBackupOpsRunHarness(string $scratch, string $body, array $env = [], ?string $scriptPath = null): array
{
    $scriptPath ??= offsiteBackupOpsScript();
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg($scriptPath)."\n".$body."\n";
    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return offsiteBackupOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * A minimal rclone.conf declaring a single `type = local` remote named
 * rateguru-b2 — this is what lets every test run genuine rclone commands
 * against an ordinary scratch directory tree instead of Backblaze B2.
 */
function offsiteBackupOpsRcloneConfig(string $scratch): string
{
    $path = $scratch.'/rclone-'.uniqid('', true).'.conf';
    file_put_contents($path, "[rateguru-b2]\ntype = local\n");

    return $path;
}

/**
 * The real, installed rclone binary — production defaults to /usr/bin/rclone,
 * which does not exist on this (or any non-Linux) development machine, so
 * every test must override RATEGURU_RCLONE_BIN to the real one actually on
 * PATH. Resolved once and cached, since `which` is a subprocess call.
 */
function offsiteBackupOpsRcloneBin(): string
{
    static $path = null;

    if ($path === null) {
        $path = trim((string) shell_exec('command -v rclone'));
        expect($path)->not->toBe('', 'rclone must be installed on PATH to run these tests (e.g. `brew install rclone`)');
    }

    return $path;
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function offsiteBackupOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => offsiteBackupOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => offsiteBackupOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => offsiteBackupOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => offsiteBackupOpsTargetsCli(),
        'RATEGURU_RCLONE_CONFIG' => offsiteBackupOpsRcloneConfig($scratch),
        'RATEGURU_RCLONE_REMOTE' => 'rateguru-b2',
        'RATEGURU_RCLONE_BIN' => offsiteBackupOpsRcloneBin(),
    ], $overrides);
}

function offsiteBackupOpsPatchedScript(string $scratch): string
{
    $path = $scratch.'/patched-offsite-backup';

    if (file_exists($path)) {
        return $path;
    }

    $source = offsiteBackupOpsSource();
    $source = str_replace('-o root', '-o '.getmyuid(), $source);
    $source = str_replace('-g root', '-g '.getmygid(), $source);

    file_put_contents($path, $source);
    chmod($path, 0o755);

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active — the same technique
 * Backup/RestoreTest.php already establish.
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function offsiteBackupOpsParityRegistry(string $scratch, string $backupNamespace = 'parity', int $offsiteRetentionDays = 90): array
{
    $account = trim((string) shell_exec('id -un'));
    $group = trim((string) shell_exec('id -gn'));

    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(offsiteBackupOpsTargetsCli()),
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
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example'],
                'backup' => ['namespace' => $backupNamespace, 'local_retention_days' => 14, 'offsite_retention_days' => $offsiteRetentionDays, 'minimum_retained_backups' => 2],
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

function offsiteBackupOpsManifestSchema2(string $selector, ?string $target, string $environment, string $namespace, string $database): array
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

function offsiteBackupOpsManifestSchema1(string $environment, string $database): array
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
 * Builds a real, on-disk local backup directory: a genuine gzip storage
 * archive, arbitrary database.dump/environment.env/release.json/
 * server-configuration.tar.gz content, the given manifest, and a genuine
 * SHA256SUMS covering all six files in the exact order backup itself
 * produces them.
 */
function offsiteBackupOpsBuildLocalBackup(string $localRoot, string $timestamp, ?array $manifest, array $options = []): string
{
    $dir = $localRoot.'/'.$timestamp;
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

    if ($manifest !== null) {
        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    $files = ['database.dump', 'storage-app.tar.gz', 'environment.env', 'release.json', 'server-configuration.tar.gz'];

    if ($manifest !== null) {
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
 * Runs a real, full perform_offsite_backup pass (against the root-bypassed
 * patched script, and a genuine local-backend rclone "remote") against a
 * purpose-built local backup directory.
 *
 * $options['backupBase'] overrides the freshly generated default, letting a
 * caller pre-seed sibling backups under the exact base this run will scan.
 *
 * @return array{exit: int, output: string, backupBase: string, bucketRoot: string, localDir: string, historyFile: string, remoteDir: string}
 */
function offsiteBackupOpsRunFullOffsiteBackup(string $scratch, ?array $manifest, array $options = []): array
{
    $backupBase = $options['backupBase'] ?? $scratch.'/backups-'.uniqid('', true);
    $namespace = $options['namespace'] ?? 'parity';
    $localRoot = $backupBase.'/'.$namespace;
    if (! is_dir($localRoot)) {
        mkdir($localRoot, 0o755, true);
    }

    $timestamp = $options['timestamp'] ?? '20260115-120000';
    $localDir = offsiteBackupOpsBuildLocalBackup($localRoot, $timestamp, $manifest, $options);

    $runRoot = $scratch.'/run-'.uniqid('', true);
    // Pre-created, matching a real, already-provisioned B2 bucket: rclone's
    // local backend (unlike B2 itself) errors on `lsf` against a directory
    // that does not exist at all, which a brand-new scratch path would be.
    $bucketRoot = $scratch.'/bucket-'.uniqid('', true);
    mkdir($bucketRoot, 0o755, true);

    [$registryPath, $targetsPath] = offsiteBackupOpsParityRegistry($scratch, $namespace);

    $env = offsiteBackupOpsBaseEnv($scratch, [
        'RATEGURU_BACKUP_BASE' => $backupBase,
        'RATEGURU_RUN_ROOT' => $runRoot,
        'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
    ]);

    [$exit, $output] = offsiteBackupOpsRunHarness(
        $scratch,
        "parse_offsite_backup_args --target parity-target\nresolve_offsite_backup_subject\nperform_offsite_backup",
        $env,
        offsiteBackupOpsPatchedScript($scratch),
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'backupBase' => $backupBase,
        'bucketRoot' => $bucketRoot,
        'localDir' => $localDir,
        'historyFile' => $backupBase.'/offsite-history.jsonl',
        'remoteDir' => "{$bucketRoot}/rateguru/{$namespace}/{$timestamp}",
    ];
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteBackupOpsParityRegistry($scratch);

        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_backup_args --target parity-target
            resolve_offsite_backup_subject
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, offsiteBackupOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, 'parse_offsite_backup_args', offsiteBackupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness(
            $scratch,
            'parse_offsite_backup_args --target a --target b',
            offsiteBackupOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects --target given without a value, with an empty value, or with a flag-shaped value', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, 'parse_offsite_backup_args --target', offsiteBackupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');

        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, "parse_offsite_backup_args --target ''", offsiteBackupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');

        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, 'parse_offsite_backup_args --target --bogus', offsiteBackupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --bogus');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, 'parse_offsite_backup_args --help', offsiteBackupOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->not->toContain('--environment');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, 'parse_offsite_backup_args --bogus', offsiteBackupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, 'parse_offsite_backup_args --environment staging', offsiteBackupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

// =============================================================================
// Root-first, lifecycle ordering
// =============================================================================

it('requires root before anything else, even for --target tits-guru', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunScript(['--target', 'tits-guru'], offsiteBackupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects a planned target before any rclone config, remote listing, lock, or filesystem work', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_backup_args --target tits-guru
            resolve_offsite_backup_subject
            echo "SHOULD NOT REACH HERE"
            BASH, offsiteBackupOpsBaseEnv($scratch, ['RATEGURU_RCLONE_CONFIG' => '/definitely/missing/rclone.conf']));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_backup_args --target ghost-target
            resolve_offsite_backup_subject
            BASH, offsiteBackupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

// =============================================================================
// Value resolution and source isolation
// =============================================================================

it('target mode resolves values only from the registry, never from deployment.conf', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteBackupOpsParityRegistry($scratch);

        $brokenConfPath = $scratch.'/broken-deployment.conf';
        file_put_contents($brokenConfPath, "RELEASE_ID_REGEX='^v'\n");

        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_backup_args --target parity-target
            resolve_offsite_backup_subject
            printf 'ENVIRONMENT_CLASS=%s\n' "${ENVIRONMENT_CLASS}"
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            BASH, offsiteBackupOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $brokenConfPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('ENVIRONMENT_CLASS=staging')
            ->toContain('BACKUP_NAMESPACE=parity');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('resolve_offsite_backup_subject only ever calls target_* registry helpers', function () {
    $source = offsiteBackupOpsSource();

    expect(preg_match(
        '/^resolve_offsite_backup_subject\(\) \{\n(.*?)^\}\n/ms',
        $source,
        $matches,
    ))->toBe(1, 'could not locate resolve_offsite_backup_subject() in scripts/offsite-backup');

    $body = $matches[1];

    expect($body)
        ->toContain('require_active_target')
        ->toContain('target_environment_class')
        ->toContain('target_backup_namespace')
        ->not->toContain('environment_backup_namespace')
        ->not->toContain('validate_environment')
        ->not->toContain('SELECTOR_TYPE');
});

// =============================================================================
// Shared namespace and lock
// =============================================================================

it('the real committed staging-main target resolves the staging backup namespace', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_backup_args --target staging-main
            resolve_offsite_backup_subject
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            BASH, offsiteBackupOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('BACKUP_NAMESPACE=staging');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('the lock filename and remote root are built from the backup namespace, never from the selector label', function () {
    $source = offsiteBackupOpsSource();

    expect($source)->toContain('LOCK_FILE="${RUN_ROOT}/offsite-backup-${BACKUP_NAMESPACE}.lock"');
    expect($source)->toContain('REMOTE_ROOT="${RCLONE_REMOTE}:${BUCKET}/rateguru/${BACKUP_NAMESPACE}"');
    expect($source)->not->toContain('offsite-backup-${LABEL}.lock');
});

it('cannot run two offsite backups concurrently against the same namespace', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $backupBase = $scratch.'/backups';
        $localRoot = $backupBase.'/parity';
        offsiteBackupOpsBuildLocalBackup($localRoot, '20260115-120000', offsiteBackupOpsManifestSchema1('staging', 'parity_db'));

        [$registryPath, $targetsPath] = offsiteBackupOpsParityRegistry($scratch, 'parity');

        $runRoot = $scratch.'/run-'.uniqid('', true);
        mkdir($runRoot, 0o755, true);
        $lockFile = $runRoot.'/offsite-backup-parity.lock';

        $lockHandle = fopen($lockFile, 'c');
        expect(flock($lockHandle, LOCK_EX | LOCK_NB))->toBeTrue('could not pre-acquire the shared namespace lock');

        try {
            [$exit, $output] = offsiteBackupOpsRunHarness($scratch, <<<'BASH'
                parse_offsite_backup_args --target parity-target
                resolve_offsite_backup_subject
                perform_offsite_backup
                BASH, offsiteBackupOpsBaseEnv($scratch, [
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_BACKUP_BASE' => $backupBase,
                'RATEGURU_RUN_ROOT' => $runRoot,
                'RATEGURU_RCLONE_BUCKET' => $scratch.'/bucket',
            ]), offsiteBackupOpsPatchedScript($scratch));

            expect($exit)->not->toBe(0);
            expect($output)->toContain('already running');
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

// =============================================================================
// Full pipeline (root-bypassed via offsiteBackupOpsPatchedScript, genuine
// local-backend rclone)
// =============================================================================

it('uploads the latest local backup and writes a full schema 2 history record', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: offsiteBackupOpsManifestSchema2('target', 'parity-target', 'staging', 'parity', 'parity_db'));

        expect($result['exit'])->toBe(0, $result['output']);
        expect(is_dir($result['remoteDir']))->toBeTrue('the remote directory must exist after a successful upload');
        expect(file_exists($result['remoteDir'].'/database.dump'))->toBeTrue();
        expect(file_exists($result['remoteDir'].'/manifest.json'))->toBeTrue();

        $lines = array_values(array_filter(explode("\n", trim(File::get($result['historyFile'])))));
        expect($lines)->toHaveCount(1);

        $entry = json_decode($lines[0], true);
        expect($entry)->toMatchArray([
            'status' => 'ok',
            'selector' => 'target',
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup_namespace' => 'parity',
            'backup' => '20260115-120000',
        ]);
        expect($entry)->toHaveKey('uploaded_at');
        expect($entry)->toHaveKey('remote');
        expect($entry['files'])->toBeInt()->toBeGreaterThan(0);
        expect($entry['bytes'])->toBeInt()->toBeGreaterThan(0);
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('selects the latest local backup only within the resolved namespace', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $backupBase = $scratch.'/backups';
        $parityRoot = $backupBase.'/parity';
        $otherRoot = $backupBase.'/other-namespace';
        mkdir($parityRoot, 0o755, true);
        mkdir($otherRoot, 0o755, true);

        // An older sibling in the same (resolved) namespace, and a newer one
        // in a completely different namespace — neither must be picked.
        offsiteBackupOpsBuildLocalBackup($parityRoot, '20260101-000000', offsiteBackupOpsManifestSchema1('staging', 'rateguru_staging'));
        offsiteBackupOpsBuildLocalBackup($otherRoot, '20260201-000000', offsiteBackupOpsManifestSchema1('staging', 'rateguru_staging'));

        // Must run against the very same $backupBase the two siblings above
        // were created under — otherwise the helper's own freshly generated
        // base would never see them, and this test would pass regardless of
        // whether backup selection actually respects namespace isolation.
        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: offsiteBackupOpsManifestSchema1('staging', 'rateguru_staging'), options: [
            'backupBase' => $backupBase,
            'timestamp' => '20260115-120000',
        ]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('20260115-120000');
        expect($result['output'])->not->toContain('20260101-000000');
        expect($result['output'])->not->toContain('20260201-000000');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('requires all seven local backup files before rclone is ever invoked', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $backupBase = $scratch.'/backups';
        $localRoot = $backupBase.'/parity';
        $dir = offsiteBackupOpsBuildLocalBackup($localRoot, '20260115-120000', offsiteBackupOpsManifestSchema1('staging', 'rateguru_staging'));
        unlink($dir.'/release.json');

        $runRoot = $scratch.'/run-'.uniqid('', true);
        [$registryPath, $targetsPath] = offsiteBackupOpsParityRegistry($scratch);

        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_backup_args --target parity-target
            resolve_offsite_backup_subject
            perform_offsite_backup
            BASH, offsiteBackupOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_BACKUP_BASE' => $backupBase,
            'RATEGURU_RUN_ROOT' => $runRoot,
            'RATEGURU_RCLONE_BUCKET' => $scratch.'/bucket',
        ]), offsiteBackupOpsPatchedScript($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('missing required backup file: release.json');
        expect(is_dir($scratch.'/bucket'))->toBeFalse('rclone must never run when a required file is missing');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('runs local checksum validation before rclone is ever invoked', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: offsiteBackupOpsManifestSchema1('staging', 'rateguru_staging'), options: [
            'corrupt_checksum' => true,
        ]);

        expect($result['exit'])->not->toBe(0);
        expect(is_dir($result['bucketRoot'].'/rateguru'))->toBeFalse('rclone must never run before checksum validation passes');
        expect(file_exists($result['historyFile']))->toBeFalse();
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('validates the manifest before rclone is ever invoked, and accepts schema 1', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: offsiteBackupOpsManifestSchema1('staging', 'rateguru_staging'));

        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects a schema 2 manifest with the wrong environment before rclone is ever invoked', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        // Only the environment field is wrong here — backup_namespace stays
        // 'parity', matching the run's actual resolved namespace, so this
        // isolates the environment-mismatch check from an unrelated
        // namespace mismatch that would otherwise also be present.
        $manifest = offsiteBackupOpsManifestSchema2('environment', null, 'production', 'parity', 'rateguru_staging');

        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('environment mismatch');
        expect(is_dir($result['bucketRoot'].'/rateguru'))->toBeFalse();
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects a schema 2 manifest with the wrong backup_namespace before rclone is ever invoked', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $manifest = offsiteBackupOpsManifestSchema2('environment', null, 'staging', 'some-other-namespace', 'rateguru_staging');

        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup_namespace mismatch');
        expect(is_dir($result['bucketRoot'].'/rateguru'))->toBeFalse();
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects a schema 2 target-selector manifest naming a different target before rclone is ever invoked', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $manifest = offsiteBackupOpsManifestSchema2('target', 'some-other-target', 'staging', 'parity', 'parity_db');

        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('target mismatch');
        expect(is_dir($result['bucketRoot'].'/rateguru'))->toBeFalse();
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects a manifest with a missing or empty database field before rclone is ever invoked', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $manifest = offsiteBackupOpsManifestSchema1('staging', '');

        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('database is missing or empty');
        expect(is_dir($result['bucketRoot'].'/rateguru'))->toBeFalse();
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects an unsupported numeric manifest schema_version before rclone is ever invoked', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $manifest = offsiteBackupOpsManifestSchema2('environment', null, 'staging', 'staging', 'rateguru_staging');
        $manifest['manifest_schema_version'] = 3;

        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unsupported backup manifest schema_version: 3');
        expect(is_dir($result['bucketRoot'].'/rateguru'))->toBeFalse();
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('rejects a string manifest schema_version of "2" before rclone is ever invoked', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $manifest = offsiteBackupOpsManifestSchema2('environment', null, 'staging', 'staging', 'rateguru_staging');
        $manifest['manifest_schema_version'] = '2';

        $result = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: $manifest);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unsupported backup manifest schema_version: "2"');
        expect(is_dir($result['bucketRoot'].'/rateguru'))->toBeFalse();
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});

it('uploads with the immutable, check-first and checksum flags — a differing remote object is never overwritten', function () {
    $scratch = offsiteBackupOpsScratchDir();

    try {
        $timestamp = '20260115-120000';
        $namespace = 'parity';

        $first = offsiteBackupOpsRunFullOffsiteBackup($scratch, manifest: offsiteBackupOpsManifestSchema1('staging', 'rateguru_staging'), options: [
            'timestamp' => $timestamp,
        ]);
        expect($first['exit'])->toBe(0, $first['output']);

        $remoteDatabaseDump = $first['remoteDir'].'/database.dump';
        $originalRemoteContent = File::get($remoteDatabaseDump);

        // A second local backup at the identical timestamp, with genuinely
        // different content — immutable must refuse to overwrite the
        // already-uploaded remote object.
        $backupBase = $first['backupBase'];
        $localRoot = $backupBase.'/'.$namespace;
        exec('rm -rf '.escapeshellarg($localRoot.'/'.$timestamp));
        offsiteBackupOpsBuildLocalBackup($localRoot, $timestamp, offsiteBackupOpsManifestSchema1('staging', 'rateguru_staging'), [
            'database_dump' => "DIFFERENT-DUMP-CONTENT\n",
        ]);

        $runRoot = $scratch.'/run-second-'.uniqid('', true);
        [$registryPath, $targetsPath] = offsiteBackupOpsParityRegistry($scratch);

        [$exit, $output] = offsiteBackupOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_backup_args --target parity-target
            resolve_offsite_backup_subject
            perform_offsite_backup
            BASH, offsiteBackupOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_BACKUP_BASE' => $backupBase,
            'RATEGURU_RUN_ROOT' => $runRoot,
            'RATEGURU_RCLONE_BUCKET' => $first['bucketRoot'],
        ]), offsiteBackupOpsPatchedScript($scratch));

        expect($exit)->not->toBe(0);
        expect(File::get($remoteDatabaseDump))->toBe($originalRemoteContent, 'an immutable remote object must never be overwritten with different content');
    } finally {
        offsiteBackupOpsCleanup($scratch);
    }
});
