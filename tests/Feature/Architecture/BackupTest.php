<?php

use Illuminate\Support\Facades\File;

/**
 * : target-aware local backup.
 *
 * These tests execute the real shipped `infrastructure/scripts/backup` —
 * never a reimplementation of its logic — with every host dependency
 * (common's deployment.conf, the target registry, the targets validator, the
 * pg_dump/php binaries, runuser) supplied through the RATEGURU_* test-override
 * contract already established for health-check/status/cleanup/deploy/
 * rollback, plus the new overrides specific to this slice
 * (RATEGURU_BACKUP_BASE, RATEGURU_RUN_ROOT, RATEGURU_SYSTEM_ROOT,
 * RATEGURU_PG_DUMP_BIN, RATEGURU_PHP_BIN).
 *
 * backup's own source guard means sourcing the file never auto-runs main() —
 * tests `source` the real file directly, then call
 * parse_backup_args/resolve_backup_subject/perform_backup (bypassing
 * require_root, for parsing/resolution coverage that doesn't need real root)
 * or invoke the script as a genuine subprocess (which does run main(),
 * requiring root first, exactly as production).
 *
 * perform_backup itself always chowns its root-only artifacts with the
 * literal `-o root -g root` — by design: a backup containing database dumps
 * and `.env` secrets must stay root-only regardless of which target or
 * environment produced it, unlike deploy's DEPLOY_ACCOUNT/CODE_GROUP, which
 * vary per target and were designed to be test-driveable directly. Since
 * chowning to a *different* uid requires real root, the full-pipeline tests
 * below run against a byte-for-byte patched copy of the real script with
 * every "-o root"/"-g root" replaced by this test process's own numeric
 * uid/gid (backupOpsPatchedScript) — the same non-root-testability technique
 * DeployTest/RollbackTest/CleanupTest already use for their own parity
 * registries and patched `targets` validators. Everything that does not
 * depend on real root ownership (selector parsing, resolution, source
 * structure, lifecycle ordering, lock behaviour) runs against the real,
 * unpatched script.
 */
function backupOpsScript(): string
{
    return base_path('infrastructure/scripts/backup');
}

function backupOpsSource(): string
{
    return File::get(backupOpsScript());
}

function backupOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function backupOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function backupOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function backupOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function backupOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/backup-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function backupOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function backupOpsExec(string $scriptPath, array $env): array
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
 * Run the real backup script as a genuine subprocess (never sourced) — this
 * is what makes BASH_SOURCE[0] == $0 true inside it, so its own source guard
 * calls main(), which calls require_root first, exactly like production.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function backupOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', backupOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start backup subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Sources $scriptPath (defaulting to the real, unpatched backup script; the
 * full-pipeline tests pass a patched copy instead — see
 * backupOpsPatchedScript) then runs $body, which can call
 * parse_backup_args/resolve_backup_subject/perform_backup/main directly.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function backupOpsRunHarness(string $scratch, string $body, array $env = [], ?string $scriptPath = null): array
{
    $scriptPath ??= backupOpsScript();
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg($scriptPath)."\n".$body."\n";
    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return backupOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function backupOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => backupOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => backupOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => backupOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => backupOpsTargetsCli(),
    ], $overrides);
}

function backupOpsCurrentAccount(): string
{
    exec('id -un', $output);

    return trim($output[0] ?? '');
}

function backupOpsCurrentGroup(): string
{
    exec('id -gn', $output);

    return trim($output[0] ?? '');
}

/**
 * A byte-for-byte patched copy of the real backup script with every literal
 * "-o root"/"-g root" replaced by this test process's own numeric uid/gid —
 * see the file-level doc comment for why this is necessary and why it is
 * safe (it never changes anything about the script's logic, only who its
 * root-only artifacts are chowned to). Written once per scratch dir.
 */
function backupOpsPatchedScript(string $scratch): string
{
    $path = $scratch.'/patched-backup';

    if (file_exists($path)) {
        return $path;
    }

    $source = backupOpsSource();
    $source = str_replace('-o root', '-o '.getmyuid(), $source);
    $source = str_replace('-g root', '-g '.getmygid(), $source);

    file_put_contents($path, $source);
    chmod($path, 0o755);

    return $path;
}

/**
 * PATH-shadowed runuser stub: drops "-u VALUE --" and execs the remaining
 * command directly as the current (test) user — the same technique
 * DeployTest's deployOpsInstallCoreStubs already established.
 */
function backupOpsInstallRunuserStub(string $scratch): void
{
    file_put_contents($scratch.'/bin/runuser', "#!/usr/bin/env bash\n"
        .'shift 2; shift'."\n"
        .'exec "$@"'."\n");
    chmod($scratch.'/bin/runuser', 0o755);
}

/**
 * A stub matching pg_dump's real --version/dump-to-stdout shape closely
 * enough for backup's own purposes: logs every invocation's arguments (after
 * runuser's stub has already dropped "-u postgres --"), prints a fake
 * version string for --version, and otherwise writes deterministic fake dump
 * content to stdout.
 */
function backupOpsPgDumpStub(string $scratch, string $logFile): string
{
    $path = $scratch.'/bin/pg_dump-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "pg_dump $*" >> '.escapeshellarg($logFile)."\n"
        .'if [[ "${1:-}" == "--version" ]]; then'."\n"
        .'    printf '."'pg_dump (PostgreSQL) 16.4\\n'\n"
        .'    exit 0'."\n"
        .'fi'."\n"
        .'printf '."'FAKE-PG-DUMP-CONTENT\\n'\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A stub matching php's `-r 'echo PHP_VERSION;'` shape used only for the
 * manifest's php_version field.
 */
function backupOpsPhpStub(string $scratch): string
{
    $path = $scratch.'/bin/php-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'printf %s "8.5.0"'."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A pg_dump stub that always fails — used by the "failed backup leaves no
 * final directory" regression test.
 */
function backupOpsFailingPgDumpStub(string $scratch): string
{
    $path = $scratch.'/bin/pg_dump-failing';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'if [[ "${1:-}" == "--version" ]]; then printf '."'pg_dump (PostgreSQL) 16.4\\n'; exit 0; fi\n"
        ."echo 'simulated pg_dump failure' >&2\n"
        ."exit 1\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * An application-root fixture: shared/storage/app (with one file, so the tar
 * archive is non-empty), shared/.env, and a current release with
 * release.json.
 *
 * @return array{root: string, release: string}
 */
function backupOpsBuildFixture(string $scratch): array
{
    $id = uniqid('', true);
    $root = $scratch.'/app-'.$id;
    $releaseId = 'v1.0.0-20260101-000000-aaa1111';

    foreach ([$root.'/shared/storage/app', $root.'/releases/'.$releaseId] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }

    file_put_contents($root.'/shared/storage/app/marker.txt', "app data\n");
    file_put_contents($root.'/shared/.env', "APP_ENV=testing\nAPP_KEY=test-key\n");
    file_put_contents($root.'/releases/'.$releaseId.'/release.json', json_encode(['release' => $releaseId]));

    symlink($root.'/releases/'.$releaseId, $root.'/current');

    return ['root' => $root, 'release' => $releaseId];
}

/**
 * A scratch, writable copy of the real committed deployment.conf.example,
 * verbatim. The template no longer carries any target-specific field at all
 * (see infrastructure/templates/deployment.conf.example's own header
 * comment): the application root now comes exclusively from the target
 * registry.
 */
function backupOpsDeploymentConfForFixture(string $scratch): string
{
    $path = $scratch.'/deployment-'.uniqid('', true).'.conf';
    file_put_contents($path, File::get(backupOpsDeploymentConfPath()));

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active pointing at the fixture's own
 * application root — the same technique Deploy/Rollback/CleanupTest already
 * established. nginx.site_name/php_fpm.pool/supervisor.program/
 * scheduler.name/deploy_user are distinctive, test-owned values (not shared
 * with any other test file's parity registry) so the target-specific
 * server-configuration snapshot test can assert on them unambiguously.
 *
 * @param  array{root: string}  $fixture
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function backupOpsParityRegistry(string $scratch, array $fixture, int $localRetentionDays = 14, int $minimumRetainedBackups = 2): array
{
    $account = backupOpsCurrentAccount();
    $group = backupOpsCurrentGroup();

    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(backupOpsTargetsCli()),
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
                'application_root' => $fixture['root'],
                'runtime_user' => $account,
                'runtime_group' => $group,
                'deploy_user' => 'parity-deploy',
                'code_group' => $group,
                'incoming_artifacts' => $scratch.'/incoming-unused',
                'release_retention' => 5,
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example'],
                'backup' => ['namespace' => 'parity', 'local_retention_days' => $localRetentionDays, 'offsite_retention_days' => 1, 'minimum_retained_backups' => $minimumRetainedBackups],
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
 * Builds a SYSTEM_ROOT scratch tree containing exactly the target's own
 * server-configuration files (matching the parity registry's nginx/php_fpm/
 * supervisor/scheduler/deploy_user values above) plus a second, unrelated
 * target's files that must never appear in the first target's archive.
 */
function backupOpsBuildSystemRoot(string $scratch): string
{
    $sysroot = $scratch.'/sysroot-'.uniqid('', true);

    foreach ([
        'home/www/rateguru/bin',
        'home/www/rateguru/config',
        'home/www/rateguru/runbooks',
        'etc/nginx/sites-available',
        'etc/php/8.5/fpm/pool.d',
        'etc/supervisor/conf.d',
        'etc/cron.d',
        'home/parity-deploy/.ssh',
        'home/other-deploy/.ssh',
        'etc/sudoers.d',
        'etc/ssh/sshd_config.d',
    ] as $dir) {
        expect(@mkdir($sysroot.'/'.$dir, 0o755, true))->toBeTrue();
    }

    file_put_contents($sysroot.'/home/www/rateguru/bin/marker', "bin\n");
    file_put_contents($sysroot.'/home/www/rateguru/DEPLOYMENT_POLICY', "policy\n");

    // This target's own files.
    file_put_contents($sysroot.'/etc/nginx/sites-available/parity-site', "server {}\n");
    file_put_contents($sysroot.'/etc/nginx/parity-site.htpasswd', "user:hash\n");
    file_put_contents($sysroot.'/etc/php/8.5/fpm/pool.d/parity-pool.conf', "[parity-pool]\n");
    file_put_contents($sysroot.'/etc/supervisor/conf.d/parity-queue.conf', "[program:parity-queue]\n");
    file_put_contents($sysroot.'/etc/cron.d/parity-scheduler', "* * * * * true\n");
    file_put_contents($sysroot.'/home/parity-deploy/.ssh/authorized_keys', "ssh-ed25519 AAAA parity\n");
    file_put_contents($sysroot.'/etc/sudoers.d/rateguru-deploy', "# rateguru sudoers\n");

    // A second, unrelated target's files — must never appear in
    // parity-target's own archive.
    file_put_contents($sysroot.'/etc/nginx/sites-available/other-site', "server {}\n");
    file_put_contents($sysroot.'/etc/php/8.5/fpm/pool.d/other-pool.conf', "[other-pool]\n");
    file_put_contents($sysroot.'/etc/supervisor/conf.d/other-queue.conf', "[program:other-queue]\n");
    file_put_contents($sysroot.'/etc/cron.d/other-scheduler', "* * * * * true\n");
    file_put_contents($sysroot.'/home/other-deploy/.ssh/authorized_keys', "ssh-ed25519 AAAA other\n");

    return $sysroot;
}

/**
 * Runs a real, full perform_backup pass (against the root-bypassed patched
 * script) using --target parity-target.
 *
 * @return array{exit: int, output: string, fixture: array, backupBase: string, runRoot: string, sysroot: string, pgDumpLog: string}
 */
function backupOpsRunFullBackup(string $scratch, int $localRetentionDays = 14, bool $failPgDump = false, int $minimumRetainedBackups = 2): array
{
    $fixture = backupOpsBuildFixture($scratch);
    $sysroot = backupOpsBuildSystemRoot($scratch);
    backupOpsInstallRunuserStub($scratch);

    $pgDumpLog = $scratch.'/pg_dump-'.uniqid('', true).'.log';
    touch($pgDumpLog);
    $pgDumpStub = $failPgDump ? backupOpsFailingPgDumpStub($scratch) : backupOpsPgDumpStub($scratch, $pgDumpLog);
    $phpStub = backupOpsPhpStub($scratch);

    $backupBase = $scratch.'/backups-'.uniqid('', true);
    $runRoot = $scratch.'/run-'.uniqid('', true);

    [$registryPath, $targetsPath] = backupOpsParityRegistry($scratch, $fixture, $localRetentionDays, $minimumRetainedBackups);

    $env = backupOpsBaseEnv($scratch, [
        'RATEGURU_BACKUP_BASE' => $backupBase,
        'RATEGURU_RUN_ROOT' => $runRoot,
        'RATEGURU_SYSTEM_ROOT' => $sysroot,
        'RATEGURU_PG_DUMP_BIN' => $pgDumpStub,
        'RATEGURU_PHP_BIN' => $phpStub,
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
    ]);

    [$exit, $output] = backupOpsRunHarness(
        $scratch,
        "parse_backup_args --target parity-target\nresolve_backup_subject\nperform_backup",
        $env,
        backupOpsPatchedScript($scratch),
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'fixture' => $fixture,
        'backupBase' => $backupBase,
        'runRoot' => $runRoot,
        'sysroot' => $sysroot,
        'pgDumpLog' => $pgDumpLog,
    ];
}

function backupOpsLatestBackupDir(string $backupBase, string $namespace): string
{
    $namespaceRoot = $backupBase.'/'.$namespace;
    $entries = array_values(array_filter(scandir($namespaceRoot) ?: [], fn ($entry) => preg_match('/^\d{8}-\d{6}$/', $entry) === 1));
    sort($entries);

    expect($entries)->not->toBeEmpty("no timestamped backup directory found under {$namespaceRoot}");

    return $namespaceRoot.'/'.end($entries);
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = backupOpsScratchDir();

    try {
        $fixture = backupOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = backupOpsParityRegistry($scratch, $fixture);

        [$exit, $output] = backupOpsRunHarness($scratch, <<<'BASH'
            parse_backup_args --target parity-target
            resolve_backup_subject
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, backupOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness($scratch, 'parse_backup_args', backupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness(
            $scratch,
            'parse_backup_args --target a --target b',
            backupOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('rejects --target given without a value, with an empty value, or with a flag-shaped value', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness($scratch, 'parse_backup_args --target', backupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');

        [$exit, $output] = backupOpsRunHarness($scratch, "parse_backup_args --target ''", backupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');

        [$exit, $output] = backupOpsRunHarness($scratch, 'parse_backup_args --target --bogus', backupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --bogus');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness($scratch, 'parse_backup_args --help', backupOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->not->toContain('--environment');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness($scratch, 'parse_backup_args --bogus', backupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness($scratch, 'parse_backup_args --environment staging', backupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        backupOpsCleanup($scratch);
    }
});

// =============================================================================
// Root-first, lifecycle ordering
// =============================================================================

it('requires root before anything else, even for --target tits-guru', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunScript(['--target', 'tits-guru'], backupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('rejects a planned target before any filesystem, database or lock work', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness($scratch, <<<'BASH'
            parse_backup_args --target tits-guru
            resolve_backup_subject
            echo "SHOULD NOT REACH HERE"
            BASH, backupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness($scratch, <<<'BASH'
            parse_backup_args --target ghost-target
            resolve_backup_subject
            BASH, backupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        backupOpsCleanup($scratch);
    }
});

// =============================================================================
// Value resolution: target from the registry only
// =============================================================================

it('resolves values only from the registry, never from deployment.conf', function () {
    $scratch = backupOpsScratchDir();

    try {
        $fixture = backupOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = backupOpsParityRegistry($scratch, $fixture, 7);

        // deployment.conf no longer carries any target-specific field at all
        // post-cutover, so a real, unmodified copy already proves the
        // registry is the *only* source of TARGET_ROOT — there is nothing
        // left in deployment.conf for resolve_backup_subject to consult.
        $brokenConfPath = backupOpsDeploymentConfForFixture($scratch);

        [$exit, $output] = backupOpsRunHarness($scratch, <<<'BASH'
            parse_backup_args --target parity-target
            resolve_backup_subject
            printf 'DATABASE_NAME=%s\n' "${DATABASE_NAME}"
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            printf 'RETENTION_DAYS=%s\n' "${RETENTION_DAYS}"
            printf 'TARGET_ROOT=%s\n' "${TARGET_ROOT}"
            BASH, backupOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $brokenConfPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('DATABASE_NAME=parity_db')
            ->toContain('BACKUP_NAMESPACE=parity')
            ->toContain('RETENTION_DAYS=7')
            ->toContain('TARGET_ROOT='.$fixture['root']);
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('resolve_backup_subject only ever calls target_* registry helpers', function () {
    $source = backupOpsSource();

    expect(preg_match(
        '/^resolve_backup_subject\(\) \{\n(.*?)^\}\n/ms',
        $source,
        $matches,
    ))->toBe(1, 'could not locate resolve_backup_subject() in scripts/backup');

    $body = $matches[1];

    expect($body)
        ->toContain('require_active_target')
        ->toContain('target_root')
        ->toContain('target_database_name')
        ->toContain('target_backup_namespace')
        ->toContain('target_local_backup_retention')
        ->not->toContain('environment_root')
        ->not->toContain('environment_database_name')
        ->not->toContain('environment_backup_namespace')
        ->not->toContain('environment_local_backup_retention')
        ->not->toContain('validate_environment')
        ->not->toContain('SELECTOR_TYPE');
});

// =============================================================================
// Shared namespace, root and lock across selectors
// =============================================================================

it('the real committed staging-main target resolves the staging backup namespace', function () {
    $scratch = backupOpsScratchDir();

    try {
        [$exit, $output] = backupOpsRunHarness($scratch, <<<'BASH'
            parse_backup_args --target staging-main
            resolve_backup_subject
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            BASH, backupOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('BACKUP_NAMESPACE=staging');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('the lock filename is built from the backup namespace, never from the selector label', function () {
    $source = backupOpsSource();

    expect($source)->toContain('LOCK_FILE="${RUN_ROOT}/backup-${BACKUP_NAMESPACE}.lock"');
    expect($source)->not->toContain('LOCK_FILE="${RUN_ROOT}/backup-${LABEL}.lock"');
});

it('cannot run two backups concurrently against the same namespace', function () {
    // Proves the shared-lock contract for real: holds the exact lock file
    // "backup --target parity-target" would acquire (built from the
    // namespace, not the selector label), then runs a real
    // "backup --target parity-target" pointed at that same namespace and
    // confirms it is refused as already running — never silently proceeding
    // to write into the same namespace concurrently.
    $scratch = backupOpsScratchDir();

    try {
        $fixture = backupOpsBuildFixture($scratch);
        $confPath = backupOpsDeploymentConfForFixture($scratch);
        [$registryPath, $targetsPath] = backupOpsParityRegistry($scratch, $fixture);

        $runRoot = $scratch.'/run-'.uniqid('', true);
        mkdir($runRoot, 0o755, true);
        $lockFile = $runRoot.'/backup-parity.lock';

        $lockHandle = fopen($lockFile, 'c');
        expect(flock($lockHandle, LOCK_EX | LOCK_NB))->toBeTrue('could not pre-acquire the shared namespace lock');

        try {
            [$exit, $output] = backupOpsRunHarness($scratch, <<<'BASH'
                parse_backup_args --target parity-target
                resolve_backup_subject
                perform_backup
                BASH, backupOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_BACKUP_BASE' => $scratch.'/backups',
                'RATEGURU_RUN_ROOT' => $runRoot,
            ]), backupOpsPatchedScript($scratch));

            expect($exit)->not->toBe(0);
            expect($output)->toContain('already running');
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    } finally {
        backupOpsCleanup($scratch);
    }
});

// =============================================================================
// Full pipeline (root-bypassed via backupOpsPatchedScript)
// =============================================================================

it('creates all seven required backup files, with a passing SHA256SUMS', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunFullBackup($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $backupDir = backupOpsLatestBackupDir($result['backupBase'], 'parity');

        foreach ([
            'database.dump', 'storage-app.tar.gz', 'environment.env',
            'release.json', 'server-configuration.tar.gz', 'manifest.json', 'SHA256SUMS',
        ] as $file) {
            expect(file_exists($backupDir.'/'.$file))->toBeTrue("missing required backup file: {$file}");
        }

        exec('cd '.escapeshellarg($backupDir).' && sha256sum --check SHA256SUMS 2>&1', $checkOutput, $checkExit);
        expect($checkExit)->toBe(0, implode("\n", $checkOutput));
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('database dump command receives the resolved target database name', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunFullBackup($scratch);
        expect($result['exit'])->toBe(0, $result['output']);
        expect(File::get($result['pgDumpLog']))->toContain('parity_db');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('storage archive contains the app directory', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunFullBackup($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $backupDir = backupOpsLatestBackupDir($result['backupBase'], 'parity');
        exec('tar -tzf '.escapeshellarg($backupDir.'/storage-app.tar.gz'), $listing);

        expect($listing)->toContain('app/', 'app/marker.txt');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('copies .env and release metadata into the backup', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunFullBackup($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $backupDir = backupOpsLatestBackupDir($result['backupBase'], 'parity');

        expect(File::get($backupDir.'/environment.env'))->toContain('APP_ENV=testing');
        expect(json_decode(File::get($backupDir.'/release.json'), true))->toBe(['release' => $result['fixture']['release']]);
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('writes a schema 2 manifest with the correct selector, target, environment, namespace and database', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunFullBackup($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $backupDir = backupOpsLatestBackupDir($result['backupBase'], 'parity');
        $manifest = json_decode(File::get($backupDir.'/manifest.json'), true);

        expect($manifest)->toMatchArray([
            'manifest_schema_version' => 2,
            'project' => 'rateguru',
            'selector' => 'target',
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup_namespace' => 'parity',
            'database' => 'parity_db',
        ]);
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('the server-configuration archive contains only this target\'s own configuration', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunFullBackup($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $backupDir = backupOpsLatestBackupDir($result['backupBase'], 'parity');
        exec('tar -tzf '.escapeshellarg($backupDir.'/server-configuration.tar.gz'), $listing);
        $listing = implode("\n", $listing);

        expect($listing)
            ->toContain('etc/nginx/sites-available/parity-site')
            ->toContain('etc/nginx/parity-site.htpasswd')
            ->toContain('etc/php/8.5/fpm/pool.d/parity-pool.conf')
            ->toContain('etc/supervisor/conf.d/parity-queue.conf')
            ->toContain('etc/cron.d/parity-scheduler')
            ->toContain('home/parity-deploy/.ssh/authorized_keys')
            ->toContain('etc/sudoers.d/rateguru-deploy')
            ->toContain('home/www/rateguru/bin');

        expect($listing)
            ->not->toContain('etc/nginx/sites-available/other-site')
            ->not->toContain('etc/php/8.5/fpm/pool.d/other-pool.conf')
            ->not->toContain('etc/supervisor/conf.d/other-queue.conf')
            ->not->toContain('etc/cron.d/other-scheduler')
            ->not->toContain('home/other-deploy/.ssh/authorized_keys');
    } finally {
        backupOpsCleanup($scratch);
    }
});

/**
 * Runs a real perform_backup pass with pre-seeded timestamped directories
 * (and optional auxiliary entries) inside the parity namespace root, so
 * local retention runs against a controlled listing.
 *
 * @param  list<string>  $seededTimestamps
 * @param  list<string>  $auxiliaryDirs
 * @param  list<string>  $auxiliaryFiles
 * @return array{exit: int, output: string, namespaceRoot: string}
 */
function backupOpsRunRetentionScenario(
    string $scratch,
    array $seededTimestamps,
    int $localRetentionDays = 1,
    bool $failPgDump = false,
    array $auxiliaryDirs = [],
    array $auxiliaryFiles = [],
): array {
    $fixture = backupOpsBuildFixture($scratch);
    $sysroot = backupOpsBuildSystemRoot($scratch);
    backupOpsInstallRunuserStub($scratch);
    $pgDumpLog = $scratch.'/pg_dump-'.uniqid('', true).'.log';
    touch($pgDumpLog);
    $pgDumpStub = $failPgDump ? backupOpsFailingPgDumpStub($scratch) : backupOpsPgDumpStub($scratch, $pgDumpLog);
    $phpStub = backupOpsPhpStub($scratch);

    [$registryPath, $targetsPath] = backupOpsParityRegistry($scratch, $fixture, $localRetentionDays);

    $backupBase = $scratch.'/backups-'.uniqid('', true);
    $namespaceRoot = $backupBase.'/parity';
    mkdir($namespaceRoot, 0o755, true);

    foreach ($seededTimestamps as $timestamp) {
        mkdir($namespaceRoot.'/'.$timestamp, 0o755, true);
        file_put_contents($namespaceRoot.'/'.$timestamp.'/marker.txt', "seeded\n");
    }

    foreach ($auxiliaryDirs as $entry) {
        mkdir($namespaceRoot.'/'.$entry, 0o755, true);
        file_put_contents($namespaceRoot.'/'.$entry.'/marker.txt', "auxiliary\n");
    }

    foreach ($auxiliaryFiles as $entry) {
        file_put_contents($namespaceRoot.'/'.$entry, "auxiliary file\n");
    }

    $runRoot = $scratch.'/run-'.uniqid('', true);

    [$exit, $output] = backupOpsRunHarness(
        $scratch,
        "parse_backup_args --target parity-target\nresolve_backup_subject\nperform_backup",
        backupOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_BACKUP_BASE' => $backupBase,
            'RATEGURU_RUN_ROOT' => $runRoot,
            'RATEGURU_SYSTEM_ROOT' => $sysroot,
            'RATEGURU_PG_DUMP_BIN' => $pgDumpStub,
            'RATEGURU_PHP_BIN' => $phpStub,
        ]),
        backupOpsPatchedScript($scratch),
    );

    return ['exit' => $exit, 'output' => $output, 'namespaceRoot' => $namespaceRoot];
}

function backupOpsTimestampDaysAgo(int $days): string
{
    return gmdate('Ymd-His', time() - ($days * 86400));
}

/** @return list<string> */
function backupOpsRemainingTimestamps(string $namespaceRoot): array
{
    return array_values(array_filter(
        scandir($namespaceRoot) ?: [],
        fn ($e) => preg_match('/^\d{8}-\d{6}$/', $e) === 1 && is_dir($namespaceRoot.'/'.$e),
    ));
}

it('deletes the third-newest backup once it is past the retention window, keeping the newest two', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunRetentionScenario($scratch, ['20200101-000000', '20200102-000000'], localRetentionDays: 1);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(is_dir($result['namespaceRoot'].'/20200101-000000'))->toBeFalse('third-newest expired backup must be deleted');
        expect(is_dir($result['namespaceRoot'].'/20200102-000000'))->toBeTrue('second-newest backup is inside the protected minimum');

        $remaining = backupOpsRemainingTimestamps($result['namespaceRoot']);
        expect($remaining)->toHaveCount(2, 'the fresh backup plus the protected minimum survivor must remain');

        expect($result['output'])->toContain('DELETE expired: 20200101-000000');
        expect($result['output'])->toContain('KEEP minimum: 20200102-000000');
        expect(substr_count($result['output'], 'KEEP minimum:'))->toBe(2);
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('always keeps the newest two backups regardless of age', function () {
    $scratch = backupOpsScratchDir();

    try {
        // One ancient pre-existing backup plus the fresh one: exactly two in
        // total, both protected by the minimum even though 20200101-000000 is
        // years past the 1-day age window.
        $result = backupOpsRunRetentionScenario($scratch, ['20200101-000000'], localRetentionDays: 1);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(is_dir($result['namespaceRoot'].'/20200101-000000'))->toBeTrue('minimum count has priority over the age cutoff');
        expect(backupOpsRemainingTimestamps($result['namespaceRoot']))->toHaveCount(2);
        expect($result['output'])->toContain('KEEP minimum: 20200101-000000');
        expect($result['output'])->not->toContain('DELETE expired:');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('keeps backups beyond the minimum count while they are within the retention window', function () {
    $scratch = backupOpsScratchDir();

    try {
        $recent = [backupOpsTimestampDaysAgo(1), backupOpsTimestampDaysAgo(2), backupOpsTimestampDaysAgo(3)];
        $result = backupOpsRunRetentionScenario($scratch, $recent, localRetentionDays: 30);

        expect($result['exit'])->toBe(0, $result['output']);

        foreach ($recent as $timestamp) {
            expect(is_dir($result['namespaceRoot'].'/'.$timestamp))->toBeTrue("recent backup {$timestamp} must be kept");
        }

        expect(backupOpsRemainingTimestamps($result['namespaceRoot']))->toHaveCount(4);
        expect($result['output'])->toContain('KEEP recent:');
        expect($result['output'])->not->toContain('DELETE expired:');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('a single fresh backup always survives retention', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunRetentionScenario($scratch, [], localRetentionDays: 1);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(backupOpsRemainingTimestamps($result['namespaceRoot']))->toHaveCount(1, 'the just-created backup must never be a deletion candidate');
        expect($result['output'])->toContain('KEEP minimum:');
        expect($result['output'])->not->toContain('DELETE expired:');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('retention only considers timestamped directories and never touches auxiliary entries', function () {
    $scratch = backupOpsScratchDir();

    try {
        // 20200199-000000 matches the timestamp shape but is not a real
        // calendar date (day 99) — and it sorts ABOVE 20200102-000000, so it
        // would consume a protected-minimum slot if it were not excluded
        // before the minimum window is applied.
        $result = backupOpsRunRetentionScenario(
            $scratch,
            ['20200101-000000', '20200102-000000', '20200199-000000'],
            localRetentionDays: 1,
            auxiliaryDirs: ['database', 'manifests', 'uploads', 'not-a-backup', '2020-01-01'],
            auxiliaryFiles: ['20190101-000000'],
        );

        expect($result['exit'])->toBe(0, $result['output']);

        // Retention still applied to the real timestamped directories...
        expect(is_dir($result['namespaceRoot'].'/20200101-000000'))->toBeFalse();
        expect(is_dir($result['namespaceRoot'].'/20200102-000000'))->toBeTrue();

        // ...while every auxiliary entry survives untouched: named auxiliary
        // directories, non-timestamp names, and even a plain FILE whose name
        // matches the timestamp pattern (only directories participate).
        foreach (['database', 'manifests', 'uploads', 'not-a-backup', '2020-01-01'] as $entry) {
            expect(is_dir($result['namespaceRoot'].'/'.$entry))->toBeTrue("auxiliary directory {$entry} must never be touched");
            expect(file_exists($result['namespaceRoot'].'/'.$entry.'/marker.txt'))->toBeTrue();
        }

        expect(is_file($result['namespaceRoot'].'/20190101-000000'))->toBeTrue('a timestamp-named file is not a backup directory');

        // The calendar-invalid directory is skipped, never deleted, and never
        // counted toward the protected minimum (20200102-000000 above kept
        // its minimum slot despite sorting below it).
        expect(is_dir($result['namespaceRoot'].'/20200199-000000'))->toBeTrue('a calendar-invalid timestamp name must never be deleted');
        expect($result['output'])->toContain('SKIP malformed: 20200199-000000');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('does not run retention when backup creation fails', function () {
    $scratch = backupOpsScratchDir();

    try {
        // Three ancient backups, all far beyond the age window and one beyond
        // the minimum count — yet a failed creation must delete nothing.
        $seeded = ['20190101-000000', '20200101-000000', '20200102-000000'];
        $result = backupOpsRunRetentionScenario($scratch, $seeded, localRetentionDays: 1, failPgDump: true);

        expect($result['exit'])->not->toBe(0);

        foreach ($seeded as $timestamp) {
            expect(is_dir($result['namespaceRoot'].'/'.$timestamp))->toBeTrue('retention must only run after a fully successful backup');
        }

        expect($result['output'])->not->toContain('DELETE expired:');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('leaves no final backup directory when pg_dump fails', function () {
    $scratch = backupOpsScratchDir();

    try {
        $result = backupOpsRunFullBackup($scratch, failPgDump: true);

        expect($result['exit'])->not->toBe(0);

        $namespaceRoot = $result['backupBase'].'/parity';
        $entries = is_dir($namespaceRoot) ? (scandir($namespaceRoot) ?: []) : [];
        $timestamped = array_filter($entries, fn ($e) => preg_match('/^\d{8}-\d{6}$/', $e) === 1);

        expect($timestamped)->toBeEmpty('a failed backup must never leave a final timestamped directory');
    } finally {
        backupOpsCleanup($scratch);
    }
});

it('removes the temporary working directory on both success and failure', function () {
    $scratch = backupOpsScratchDir();

    try {
        $successResult = backupOpsRunFullBackup($scratch);
        expect($successResult['exit'])->toBe(0, $successResult['output']);

        $namespaceRoot = $successResult['backupBase'].'/parity';
        $tempEntries = array_filter(scandir($namespaceRoot) ?: [], fn ($e) => str_starts_with($e, '.') && str_ends_with($e, '.tmp'));
        expect($tempEntries)->toBeEmpty('no .tmp working directory may remain after a successful backup');

        $failureResult = backupOpsRunFullBackup($scratch, failPgDump: true);
        expect($failureResult['exit'])->not->toBe(0);

        $failureNamespaceRoot = $failureResult['backupBase'].'/parity';
        $failureTempEntries = is_dir($failureNamespaceRoot)
            ? array_filter(scandir($failureNamespaceRoot) ?: [], fn ($e) => str_starts_with($e, '.') && str_ends_with($e, '.tmp'))
            : [];
        expect($failureTempEntries)->toBeEmpty('no .tmp working directory may remain after a failed backup either');
    } finally {
        backupOpsCleanup($scratch);
    }
});
