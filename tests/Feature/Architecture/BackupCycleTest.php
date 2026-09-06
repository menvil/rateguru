<?php

use Illuminate\Support\Facades\File;

/**
 * : target-aware backup-cycle orchestration.
 *
 * These tests execute the real shipped `infrastructure/scripts/backup-cycle`
 * — never a reimplementation of its logic — driving five stub child
 * binaries (backup, restore-test, offsite-backup, offsite-retention,
 * offsite-restore-test) so the full pipeline, its ordering, its arguments
 * and its fail-fast behaviour are all proven against the genuine script,
 * without ever touching a real database, filesystem backup or Backblaze B2
 * bucket.
 *
 * backup-cycle's own `install -d -o root -g root` call needs real root the
 * same way backup/offsite-backup's own root-only install calls do — see
 * BackupTest.php's file-level doc comment for the full rationale. The full-
 * pipeline tests below run against a byte-for-byte patched copy of the real
 * script with every "-o root"/"-g root" replaced by this test process's own
 * numeric uid/gid (backupCyclePatchedScript).
 */
function backupCycleScript(): string
{
    return base_path('infrastructure/scripts/backup-cycle');
}

function backupCycleSource(): string
{
    return File::get(backupCycleScript());
}

function backupCycleCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function backupCycleTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function backupCycleRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function backupCycleDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function backupCycleScratchDir(): string
{
    $dir = sys_get_temp_dir().'/backup-cycle-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function backupCycleCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function backupCycleExec(string $scriptPath, array $env): array
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
function backupCycleRunScript(array $arguments, array $env, ?string $scriptPath = null): array
{
    $scriptPath ??= backupCycleScript();
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', $scriptPath], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start backup-cycle subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function backupCycleRunHarness(string $scratch, string $body, array $env = [], ?string $scriptPath = null): array
{
    $scriptPath ??= backupCycleScript();
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg($scriptPath)."\n".$body."\n";
    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return backupCycleExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function backupCycleBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => backupCycleCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => backupCycleDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => backupCycleRegistryPath(),
        'RATEGURU_TARGETS_CLI' => backupCycleTargetsCli(),
    ], $overrides);
}

function backupCyclePatchedScript(string $scratch): string
{
    $path = $scratch.'/patched-backup-cycle';

    if (file_exists($path)) {
        return $path;
    }

    $source = backupCycleSource();
    $source = str_replace('-o root', '-o '.getmyuid(), $source);
    $source = str_replace('-g root', '-g '.getmygid(), $source);

    file_put_contents($path, $source);
    chmod($path, 0o755);

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active — the same technique
 * Backup/RestoreTest.php and OffsiteBackupTest.php already establish.
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function backupCycleParityRegistry(string $scratch, string $backupNamespace = 'parity'): array
{
    $account = trim((string) shell_exec('id -un'));
    $group = trim((string) shell_exec('id -gn'));

    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(backupCycleTargetsCli()),
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

/**
 * Writes a self-contained stub child binary at $path that appends its own
 * basename plus its received arguments to BACKUP_CYCLE_STUB_LOG (a path
 * supplied via environment variable at run time), then exits 0 — or, when
 * $path's basename matches $failStep, prints a recognizable failure line to
 * stderr and exits $failExitCode instead. This is what lets the tests below
 * prove exact call order, exact arguments, and fail-fast behaviour against
 * the real, unmodified backup-cycle script, without ever touching a real
 * backup, database or B2 bucket.
 */
function backupCycleWriteStub(string $path, ?string $failStep, int $failExitCode): void
{
    $name = basename($path);
    $exitLine = $name === $failStep
        ? "printf 'STUB FAILURE: %s\\n' \"\$(basename \"\$0\")\" >&2\nexit {$failExitCode}"
        : "printf 'STUB OK: %s\\n' \"\$(basename \"\$0\")\"\nexit 0";

    $script = <<<SH
#!/usr/bin/env bash
set -uo pipefail
printf '%s %s\\n' "\$(basename "\$0")" "\$*" >> "\${BACKUP_CYCLE_STUB_LOG}"
{$exitLine}

SH;

    file_put_contents($path, $script);
    chmod($path, 0o755);
}

/**
 * @return array<string, string> step name => stub path
 */
function backupCycleBuildStubs(string $scratch, ?string $failStep = null, int $failExitCode = 1): array
{
    $dir = $scratch.'/stub-bin-'.uniqid('', true);
    mkdir($dir, 0o755, true);

    $names = ['backup', 'restore-test', 'offsite-backup', 'offsite-retention', 'offsite-restore-test'];
    $paths = [];

    foreach ($names as $name) {
        $path = $dir.'/'.$name;
        backupCycleWriteStub($path, $failStep, $failExitCode);
        $paths[$name] = $path;
    }

    return $paths;
}

/**
 * Runs a real, full perform_backup_cycle pass (against the root-bypassed
 * patched script) driving five stub child binaries, and returns everything a
 * test needs: exit code, combined output, the ordered list of stub
 * invocations (as "name args..." strings), and the history/lock paths used.
 *
 * $useParityTarget selects which target's registry values populate the run:
 * false uses staging-main's own committed registry values (namespace
 * "staging") against the real committed registry; true uses a synthetic
 * parity-target via backupCycleParityRegistry().
 *
 * @param  array{namespace?: string, fail_step?: string, fail_exit_code?: int, backup_base?: string, run_root?: string, extra_env?: array<string, string>}  $options
 * @return array{exit: int, output: string, calls: list<string>, backupBase: string, runRoot: string, historyFile: string, namespace: string, targetId: string}
 */
function backupCycleRunFullCycle(string $scratch, bool $useParityTarget, array $options = []): array
{
    $backupBase = $options['backup_base'] ?? $scratch.'/backups-'.uniqid('', true);
    $runRoot = $options['run_root'] ?? $scratch.'/run-'.uniqid('', true);
    @mkdir($backupBase, 0o755, true);
    @mkdir($runRoot, 0o755, true);

    $stubLog = $scratch.'/call-log-'.uniqid('', true).'.txt';
    file_put_contents($stubLog, '');

    $namespace = $options['namespace'] ?? ($useParityTarget ? 'parity' : 'staging');
    $failStep = $options['fail_step'] ?? null;
    $failExitCode = $options['fail_exit_code'] ?? 7;

    $stubs = backupCycleBuildStubs($scratch, $failStep, $failExitCode);

    $env = backupCycleBaseEnv($scratch, array_merge([
        'BACKUP_CYCLE_STUB_LOG' => $stubLog,
        'RATEGURU_BACKUP_BASE' => $backupBase,
        'RATEGURU_RUN_ROOT' => $runRoot,
        'RATEGURU_BACKUP_BIN' => $stubs['backup'],
        'RATEGURU_RESTORE_TEST_BIN' => $stubs['restore-test'],
        'RATEGURU_OFFSITE_BACKUP_BIN' => $stubs['offsite-backup'],
        'RATEGURU_OFFSITE_RETENTION_BIN' => $stubs['offsite-retention'],
        'RATEGURU_OFFSITE_RESTORE_TEST_BIN' => $stubs['offsite-restore-test'],
    ], $options['extra_env'] ?? []));

    if ($useParityTarget) {
        $targetId = 'parity-target';
        [$registryPath, $targetsPath] = backupCycleParityRegistry($scratch, $namespace);
        $env['RATEGURU_TARGET_REGISTRY_FILE'] = $registryPath;
        $env['RATEGURU_TARGETS_CLI'] = $targetsPath;
    } else {
        $targetId = 'staging-main';
    }

    [$exit, $output] = backupCycleRunHarness(
        $scratch,
        "parse_backup_cycle_args --target {$targetId}\nresolve_backup_cycle_subject\nperform_backup_cycle",
        $env,
        backupCyclePatchedScript($scratch),
    );

    $rawLog = trim((string) @file_get_contents($stubLog));
    $calls = $rawLog === '' ? [] : explode("\n", $rawLog);

    return [
        'exit' => $exit,
        'output' => $output,
        'calls' => $calls,
        'backupBase' => $backupBase,
        'runRoot' => $runRoot,
        'historyFile' => $backupBase.'/backup-cycles.jsonl',
        'namespace' => $namespace,
        'targetId' => $targetId,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function backupCycleHistoryLines(string $historyFile): array
{
    if (! file_exists($historyFile)) {
        return [];
    }

    $lines = array_values(array_filter(explode("\n", trim(File::get($historyFile)))));

    return array_map(fn (string $line) => json_decode($line, true), $lines);
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$registryPath, $targetsPath] = backupCycleParityRegistry($scratch);

        [$exit, $output] = backupCycleRunHarness($scratch, <<<'BASH'
            parse_backup_cycle_args --target parity-target
            resolve_backup_cycle_subject
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, backupCycleBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunHarness($scratch, 'parse_backup_cycle_args', backupCycleBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    // --environment is no longer a recognized flag at all — there is no
    // special deprecation message, just the same generic rejection any
    // other bogus flag gets.
    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunHarness($scratch, 'parse_backup_cycle_args --environment staging', backupCycleBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunHarness(
            $scratch,
            'parse_backup_cycle_args --target a --target b',
            backupCycleBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('rejects a selector given without a value, with an empty value, or with a flag-shaped value', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunHarness($scratch, 'parse_backup_cycle_args --target', backupCycleBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');

        [$exit, $output] = backupCycleRunHarness($scratch, "parse_backup_cycle_args --target ''", backupCycleBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');

        [$exit, $output] = backupCycleRunHarness($scratch, 'parse_backup_cycle_args --target --help', backupCycleBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --help');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunHarness($scratch, 'parse_backup_cycle_args --help', backupCycleBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->not->toContain('--environment');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunHarness($scratch, 'parse_backup_cycle_args --bogus', backupCycleBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        backupCycleCleanup($scratch);
    }
});

// =============================================================================
// Root-first, lifecycle ordering
// =============================================================================

it('requires root before anything else, even for --target tits-guru', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunScript(['--target', 'tits-guru'], backupCycleBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('rejects a planned target before any lock, history or child-command work', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunHarness($scratch, <<<'BASH'
            parse_backup_cycle_args --target tits-guru
            resolve_backup_cycle_subject
            echo "SHOULD NOT REACH HERE"
            BASH, backupCycleBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$exit, $output] = backupCycleRunHarness($scratch, <<<'BASH'
            parse_backup_cycle_args --target ghost-target
            resolve_backup_cycle_subject
            BASH, backupCycleBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        backupCycleCleanup($scratch);
    }
});

// =============================================================================
// Value resolution and source isolation
// =============================================================================

it('resolves values only from the registry', function () {
    $scratch = backupCycleScratchDir();

    try {
        [$registryPath, $targetsPath] = backupCycleParityRegistry($scratch);

        $brokenConfPath = $scratch.'/broken-deployment.conf';
        file_put_contents($brokenConfPath, "RELEASE_ID_REGEX='^v'\n");

        [$exit, $output] = backupCycleRunHarness($scratch, <<<'BASH'
            parse_backup_cycle_args --target parity-target
            resolve_backup_cycle_subject
            printf 'ENVIRONMENT_CLASS=%s\n' "${ENVIRONMENT_CLASS}"
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            BASH, backupCycleBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $brokenConfPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('ENVIRONMENT_CLASS=staging')
            ->toContain('BACKUP_NAMESPACE=parity');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('resolve_backup_cycle_subject resolves values only through require_active_target and the target_* accessors', function () {
    $source = backupCycleSource();

    expect(preg_match(
        '/^resolve_backup_cycle_subject\(\) \{\n(.*?)\n\}\n/ms',
        $source,
        $matches,
    ))->toBe(1, 'could not locate resolve_backup_cycle_subject() in scripts/backup-cycle');

    expect($matches[1])
        ->toContain('require_active_target')
        ->toContain('target_environment_class')
        ->toContain('target_backup_namespace')
        ->toContain('CHILD_TARGET_ARGS=(--target')
        ->not->toContain('environment_backup_namespace')
        ->not->toContain('validate_environment');
});

// =============================================================================
// Namespace-keyed lock
// =============================================================================

it('the lock filename is built from the backup namespace, never from the selector label', function () {
    $source = backupCycleSource();

    expect($source)->toContain('LOCK_FILE="${RUN_ROOT}/backup-cycle-${BACKUP_NAMESPACE}.lock"');
    expect($source)->not->toContain('backup-cycle-${LABEL}.lock');
});

it('cannot run two backup cycles concurrently against the same namespace lock, and writes no history on contention', function () {
    $scratch = backupCycleScratchDir();

    try {
        $runRoot = $scratch.'/run-'.uniqid('', true);
        mkdir($runRoot, 0o755, true);
        $lockFile = $runRoot.'/backup-cycle-parity.lock';

        $lockHandle = fopen($lockFile, 'c');
        expect(flock($lockHandle, LOCK_EX | LOCK_NB))->toBeTrue('could not pre-acquire the shared namespace lock');

        try {
            $result = backupCycleRunFullCycle($scratch, useParityTarget: true, options: [
                'namespace' => 'parity',
                'run_root' => $runRoot,
            ]);

            expect($result['exit'])->not->toBe(0);
            expect($result['output'])->toContain('already running');
            expect($result['calls'])->toBe([], 'no child command may ever run while the namespace lock is held elsewhere');
            expect(file_exists($result['historyFile']))->toBeFalse('lock contention must never write a history record');
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('different namespaces never conflict for the same lock root', function () {
    $scratch = backupCycleScratchDir();

    try {
        $runRoot = $scratch.'/run-'.uniqid('', true);
        mkdir($runRoot, 0o755, true);

        $otherLockFile = $runRoot.'/backup-cycle-some-other-namespace.lock';
        $lockHandle = fopen($otherLockFile, 'c');
        expect(flock($lockHandle, LOCK_EX | LOCK_NB))->toBeTrue();

        try {
            $result = backupCycleRunFullCycle($scratch, useParityTarget: true, options: [
                'namespace' => 'parity',
                'run_root' => $runRoot,
            ]);

            expect($result['exit'])->toBe(0, $result['output']);
            expect($result['calls'])->toHaveCount(5);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('holds the cycle lock while every child step runs', function () {
    $scratch = backupCycleScratchDir();

    try {
        $runRoot = $scratch.'/run-'.uniqid('', true);
        mkdir($runRoot, 0o755, true);
        $lockFile = $runRoot.'/backup-cycle-staging.lock';
        $probeResultFile = $scratch.'/lock-probe-result.txt';

        $dir = $scratch.'/stub-bin-'.uniqid('', true);
        mkdir($dir, 0o755, true);

        foreach (['backup', 'restore-test', 'offsite-retention', 'offsite-restore-test'] as $name) {
            backupCycleWriteStub($dir.'/'.$name, null, 1);
        }

        // A specialized probe stub standing in for offsite-backup (the
        // middle step): while the parent still holds the cycle lock on fd 9,
        // this attempts its own independent, non-blocking flock on the exact
        // same lock file from a different process — proving the lock is
        // still held, not merely acquired-then-released before the pipeline
        // runs.
        file_put_contents($dir.'/offsite-backup', <<<SH
#!/usr/bin/env bash
set -uo pipefail
exec 8>"{$lockFile}"
if flock -n 8; then
    printf 'LOCK WAS FREE (unexpected)\\n' > "{$probeResultFile}"
    flock -u 8
else
    printf 'LOCK HELD (expected)\\n' > "{$probeResultFile}"
fi
exit 0

SH);
        chmod($dir.'/offsite-backup', 0o755);

        $backupBase = $scratch.'/backups';
        $stubLog = $scratch.'/call-log-'.uniqid('', true).'.txt';
        file_put_contents($stubLog, '');

        $env = backupCycleBaseEnv($scratch, [
            'BACKUP_CYCLE_STUB_LOG' => $stubLog,
            'RATEGURU_BACKUP_BASE' => $backupBase,
            'RATEGURU_RUN_ROOT' => $runRoot,
            'RATEGURU_BACKUP_BIN' => $dir.'/backup',
            'RATEGURU_RESTORE_TEST_BIN' => $dir.'/restore-test',
            'RATEGURU_OFFSITE_BACKUP_BIN' => $dir.'/offsite-backup',
            'RATEGURU_OFFSITE_RETENTION_BIN' => $dir.'/offsite-retention',
            'RATEGURU_OFFSITE_RESTORE_TEST_BIN' => $dir.'/offsite-restore-test',
        ]);

        [$exit, $output] = backupCycleRunHarness($scratch, <<<'BASH'
            parse_backup_cycle_args --target staging-main
            resolve_backup_cycle_subject
            perform_backup_cycle
            BASH, $env, backupCyclePatchedScript($scratch));

        expect($exit)->toBe(0, $output);
        expect(trim(File::get($probeResultFile)))->toBe('LOCK HELD (expected)');
    } finally {
        backupCycleCleanup($scratch);
    }
});

// =============================================================================
// Full pipeline: order, arguments, retention --apply, unsuppressed output
// =============================================================================

it('runs backup, restore-test, offsite-backup, offsite-retention and offsite-restore-test in exact order for --target staging-main, and retention receives --apply', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['calls'])->toBe([
            'backup --target staging-main',
            'restore-test --target staging-main',
            'offsite-backup --target staging-main',
            'offsite-retention --target staging-main --apply',
            'offsite-restore-test --target staging-main',
        ]);
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('runs the same five commands in the same order for --target parity-target, passing --target to all five, and retention receives --apply', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: true);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['calls'])->toBe([
            'backup --target parity-target',
            'restore-test --target parity-target',
            'offsite-backup --target parity-target',
            'offsite-retention --target parity-target --apply',
            'offsite-restore-test --target parity-target',
        ]);
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('never mixes targets within one cycle: every one of the five commands gets the identical --target', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: true);

        expect($result['exit'])->toBe(0, $result['output']);

        foreach ($result['calls'] as $call) {
            expect($call)->toContain('--target parity-target');
            expect($call)->not->toContain('--environment');
        }
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('passes real child stdout and stderr straight through, unsuppressed', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false);

        expect($result['exit'])->toBe(0, $result['output']);

        foreach (['backup', 'restore-test', 'offsite-backup', 'offsite-retention', 'offsite-restore-test'] as $name) {
            expect($result['output'])->toContain("STUB OK: {$name}");
        }
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('prints a numbered step header for each of the five steps and a success banner naming the label', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: true);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('[1/5]')
            ->toContain('[2/5]')
            ->toContain('[3/5]')
            ->toContain('[4/5]')
            ->toContain('[5/5]')
            ->toContain('Backup cycle for parity-target completed successfully');
    } finally {
        backupCycleCleanup($scratch);
    }
});

// =============================================================================
// Fail-fast: a failure at step N blocks every later step, preserves the
// original exit code, and records exactly the steps that actually completed.
// =============================================================================

it('failure at step 1 (backup) blocks steps 2-5, preserves the exit code, and prints the failed-step banner', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false, options: ['fail_step' => 'backup', 'fail_exit_code' => 101]);

        expect($result['exit'])->toBe(101);
        expect($result['calls'])->toBe(['backup --target staging-main']);
        expect($result['output'])->toContain('Backup cycle for staging-main failed at step: backup');

        $records = backupCycleHistoryLines($result['historyFile']);
        expect($records)->toHaveCount(1);
        expect($records[0])->toMatchArray([
            'status' => 'failed',
            'selector' => 'target',
            'target' => 'staging-main',
            'environment' => 'staging',
            'backup_namespace' => 'staging',
            'completed_steps' => [],
            'failed_step' => 'backup',
            'exit_code' => 101,
        ]);
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('failure at step 2 (restore-test) blocks steps 3-5', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false, options: ['fail_step' => 'restore-test', 'fail_exit_code' => 102]);

        expect($result['exit'])->toBe(102);
        expect($result['calls'])->toBe([
            'backup --target staging-main',
            'restore-test --target staging-main',
        ]);
        expect($result['output'])->toContain('Backup cycle for staging-main failed at step: restore-test');

        $records = backupCycleHistoryLines($result['historyFile']);
        expect($records)->toHaveCount(1);
        expect($records[0]['completed_steps'])->toBe(['backup']);
        expect($records[0]['failed_step'])->toBe('restore-test');
        expect($records[0]['exit_code'])->toBe(102);
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('failure at step 3 (offsite-backup) blocks steps 4-5 — retention is never applied on an unverified upload', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false, options: ['fail_step' => 'offsite-backup', 'fail_exit_code' => 103]);

        expect($result['exit'])->toBe(103);
        expect($result['calls'])->toBe([
            'backup --target staging-main',
            'restore-test --target staging-main',
            'offsite-backup --target staging-main',
        ]);
        expect($result['output'])->toContain('Backup cycle for staging-main failed at step: offsite-backup');

        $records = backupCycleHistoryLines($result['historyFile']);
        expect($records[0]['completed_steps'])->toBe(['backup', 'restore-test']);
        expect($records[0]['failed_step'])->toBe('offsite-backup');
        expect($records[0]['exit_code'])->toBe(103);
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('failure at step 4 (offsite-retention) blocks step 5', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false, options: ['fail_step' => 'offsite-retention', 'fail_exit_code' => 104]);

        expect($result['exit'])->toBe(104);
        expect($result['calls'])->toBe([
            'backup --target staging-main',
            'restore-test --target staging-main',
            'offsite-backup --target staging-main',
            'offsite-retention --target staging-main --apply',
        ]);
        expect($result['output'])->toContain('Backup cycle for staging-main failed at step: offsite-retention');

        $records = backupCycleHistoryLines($result['historyFile']);
        expect($records[0]['completed_steps'])->toBe(['backup', 'restore-test', 'offsite-backup']);
        expect($records[0]['failed_step'])->toBe('offsite-retention');
        expect($records[0]['exit_code'])->toBe(104);
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('failure at step 5 (offsite-restore-test) still ends the cycle failed, with retention already applied and not rolled back', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false, options: ['fail_step' => 'offsite-restore-test', 'fail_exit_code' => 105]);

        expect($result['exit'])->toBe(105);
        expect($result['calls'])->toBe([
            'backup --target staging-main',
            'restore-test --target staging-main',
            'offsite-backup --target staging-main',
            'offsite-retention --target staging-main --apply',
            'offsite-restore-test --target staging-main',
        ]);
        expect($result['output'])->toContain('Backup cycle for staging-main failed at step: offsite-restore-test');

        $records = backupCycleHistoryLines($result['historyFile']);
        expect($records)->toHaveCount(1);
        expect($records[0]['completed_steps'])->toBe(['backup', 'restore-test', 'offsite-backup', 'offsite-retention']);
        expect($records[0]['failed_step'])->toBe('offsite-restore-test');
        expect($records[0]['exit_code'])->toBe(105);
    } finally {
        backupCycleCleanup($scratch);
    }
});

// =============================================================================
// Cycle history: schema, atomicity, and the two write-failure semantics
// =============================================================================

it('writes a single compact-JSONL success record with the full schema for --target staging-main', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false);
        expect($result['exit'])->toBe(0, $result['output']);

        $lines = array_values(array_filter(explode("\n", trim(File::get($result['historyFile'])))));
        expect($lines)->toHaveCount(1);
        expect($lines[0])->not->toContain("\n", 'the record must be exactly one compact line, never pretty-printed');

        $entry = json_decode($lines[0], true);
        expect($entry)->toMatchArray([
            'status' => 'ok',
            'selector' => 'target',
            'target' => 'staging-main',
            'environment' => 'staging',
            'backup_namespace' => 'staging',
            'completed_steps' => ['backup', 'restore-test', 'offsite-backup', 'offsite-retention', 'offsite-restore-test'],
            'failed_step' => null,
        ]);
        expect($entry)->toHaveKey('started_at');
        expect($entry)->toHaveKey('completed_at');
        expect($entry)->not->toHaveKey('exit_code', 'a successful cycle must never carry an exit_code field');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('writes a single compact-JSONL success record with the full schema for --target parity-target', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: true);
        expect($result['exit'])->toBe(0, $result['output']);

        $lines = array_values(array_filter(explode("\n", trim(File::get($result['historyFile'])))));
        expect($lines)->toHaveCount(1);

        $entry = json_decode($lines[0], true);
        expect($entry)->toMatchArray([
            'status' => 'ok',
            'selector' => 'target',
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup_namespace' => 'parity',
            'completed_steps' => ['backup', 'restore-test', 'offsite-backup', 'offsite-retention', 'offsite-restore-test'],
            'failed_step' => null,
        ]);
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('timestamps are present, ISO-8601 UTC, and started_at is never after completed_at', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false);
        expect($result['exit'])->toBe(0, $result['output']);

        $records = backupCycleHistoryLines($result['historyFile']);
        $entry = $records[0];

        expect($entry['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
        expect($entry['completed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
        expect(strtotime($entry['started_at']))->toBeLessThanOrEqual(strtotime($entry['completed_at']));
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('history directory and file are created with root-only permissions', function () {
    $scratch = backupCycleScratchDir();

    try {
        $result = backupCycleRunFullCycle($scratch, useParityTarget: false);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(fileperms($result['historyFile']) & 0o777)->toBe(0o600);
        expect(fileperms($result['backupBase']) & 0o777)->toBe(0o700);
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('never writes history when a planned target is rejected', function () {
    $scratch = backupCycleScratchDir();

    try {
        $backupBase = $scratch.'/backups-'.uniqid('', true);
        $runRoot = $scratch.'/run-'.uniqid('', true);

        [$exit, $output] = backupCycleRunHarness($scratch, <<<'BASH'
            parse_backup_cycle_args --target tits-guru
            resolve_backup_cycle_subject
            echo "SHOULD NOT REACH HERE"
            BASH, backupCycleBaseEnv($scratch, [
            'RATEGURU_BACKUP_BASE' => $backupBase,
            'RATEGURU_RUN_ROOT' => $runRoot,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
        expect(file_exists($backupBase.'/backup-cycles.jsonl'))->toBeFalse();
        expect(is_dir($backupBase))->toBeFalse('the backup base directory itself must never be created before the lifecycle check passes');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('a history write failure after a successful pipeline turns the cycle into a failure, without re-running any step', function () {
    $scratch = backupCycleScratchDir();

    try {
        $backupBase = $scratch.'/backups-'.uniqid('', true);
        mkdir($backupBase, 0o755, true);
        // The history file path itself is a directory — printf's append can
        // never succeed against it, so append_cycle_history is guaranteed to
        // fail here, purely to exercise this path deterministically.
        mkdir($backupBase.'/backup-cycles.jsonl', 0o755, true);

        $result = backupCycleRunFullCycle($scratch, useParityTarget: false, options: ['backup_base' => $backupBase]);

        expect($result['exit'])->not->toBe(0);
        expect($result['calls'])->toHaveCount(5, 'all five steps must have genuinely run before the history write failure is detected');
        expect($result['output'])->toContain('backup cycle history write failed after a successful pipeline');
    } finally {
        backupCycleCleanup($scratch);
    }
});

it('a history write failure on the failure path never masks the original child exit code', function () {
    $scratch = backupCycleScratchDir();

    try {
        $backupBase = $scratch.'/backups-'.uniqid('', true);
        mkdir($backupBase, 0o755, true);
        mkdir($backupBase.'/backup-cycles.jsonl', 0o755, true);

        $result = backupCycleRunFullCycle($scratch, useParityTarget: false, options: [
            'backup_base' => $backupBase,
            'fail_step' => 'restore-test',
            'fail_exit_code' => 66,
        ]);

        expect($result['exit'])->toBe(66, 'the original child failure exit code must survive a secondary history-write failure');
        expect($result['output'])
            ->toContain('Backup cycle for staging-main failed at step: restore-test')
            ->toContain('ERROR: failed to write backup cycle history');
    } finally {
        backupCycleCleanup($scratch);
    }
});

// =============================================================================
// Test overrides
// =============================================================================

it('gates every child-binary and backup/run-root override behind the shared rateguru_test_overrides_allowed check', function () {
    $source = backupCycleSource();

    expect(preg_match('/if rateguru_test_overrides_allowed; then\n(.*?)\nfi\n/ms', $source, $matches))
        ->toBe(1, 'could not locate the gated overrides block in scripts/backup-cycle');

    $gatedBlock = $matches[1];

    foreach ([
        'RATEGURU_BACKUP_BIN',
        'RATEGURU_RESTORE_TEST_BIN',
        'RATEGURU_OFFSITE_BACKUP_BIN',
        'RATEGURU_OFFSITE_RETENTION_BIN',
        'RATEGURU_OFFSITE_RESTORE_TEST_BIN',
        'RATEGURU_BACKUP_BASE',
        'RATEGURU_RUN_ROOT',
    ] as $variable) {
        expect($gatedBlock)->toContain($variable);
    }
});

it('documents the production defaults for every override, matching the runbook', function () {
    expect(backupCycleSource())
        ->toContain('BACKUP_BIN_DEFAULT="/home/www/rateguru/bin/backup"')
        ->toContain('RESTORE_TEST_BIN_DEFAULT="/home/www/rateguru/bin/restore-test"')
        ->toContain('OFFSITE_BACKUP_BIN_DEFAULT="/home/www/rateguru/bin/offsite-backup"')
        ->toContain('OFFSITE_RETENTION_BIN_DEFAULT="/home/www/rateguru/bin/offsite-retention"')
        ->toContain('OFFSITE_RESTORE_TEST_BIN_DEFAULT="/home/www/rateguru/bin/offsite-restore-test"')
        ->toContain('BACKUP_BASE_DEFAULT="/home/www/rateguru/backups"')
        ->toContain('RUN_ROOT_DEFAULT="/home/www/rateguru/run"');
});

it('honors every child-binary and backup/run-root override when the allow flag is granted', function () {
    $scratch = backupCycleScratchDir();

    try {
        $customBackupBin = $scratch.'/custom-backup';
        $customRestoreTestBin = $scratch.'/custom-restore-test';
        $customOffsiteBackupBin = $scratch.'/custom-offsite-backup';
        $customOffsiteRetentionBin = $scratch.'/custom-offsite-retention';
        $customOffsiteRestoreTestBin = $scratch.'/custom-offsite-restore-test';
        $customBackupBase = $scratch.'/custom-backups';
        $customRunRoot = $scratch.'/custom-run';

        [$exit, $output] = backupCycleRunHarness($scratch, <<<'BASH'
            parse_backup_cycle_args --target staging-main
            printf 'BACKUP_BIN=%s\n' "${BACKUP_BIN}"
            printf 'RESTORE_TEST_BIN=%s\n' "${RESTORE_TEST_BIN}"
            printf 'OFFSITE_BACKUP_BIN=%s\n' "${OFFSITE_BACKUP_BIN}"
            printf 'OFFSITE_RETENTION_BIN=%s\n' "${OFFSITE_RETENTION_BIN}"
            printf 'OFFSITE_RESTORE_TEST_BIN=%s\n' "${OFFSITE_RESTORE_TEST_BIN}"
            printf 'BACKUP_BASE=%s\n' "${BACKUP_BASE}"
            printf 'RUN_ROOT=%s\n' "${RUN_ROOT}"
            BASH, backupCycleBaseEnv($scratch, [
            'RATEGURU_BACKUP_BIN' => $customBackupBin,
            'RATEGURU_RESTORE_TEST_BIN' => $customRestoreTestBin,
            'RATEGURU_OFFSITE_BACKUP_BIN' => $customOffsiteBackupBin,
            'RATEGURU_OFFSITE_RETENTION_BIN' => $customOffsiteRetentionBin,
            'RATEGURU_OFFSITE_RESTORE_TEST_BIN' => $customOffsiteRestoreTestBin,
            'RATEGURU_BACKUP_BASE' => $customBackupBase,
            'RATEGURU_RUN_ROOT' => $customRunRoot,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain("BACKUP_BIN={$customBackupBin}")
            ->toContain("RESTORE_TEST_BIN={$customRestoreTestBin}")
            ->toContain("OFFSITE_BACKUP_BIN={$customOffsiteBackupBin}")
            ->toContain("OFFSITE_RETENTION_BIN={$customOffsiteRetentionBin}")
            ->toContain("OFFSITE_RESTORE_TEST_BIN={$customOffsiteRestoreTestBin}")
            ->toContain("BACKUP_BASE={$customBackupBase}")
            ->toContain("RUN_ROOT={$customRunRoot}");
    } finally {
        backupCycleCleanup($scratch);
    }
});
