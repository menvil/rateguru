<?php

use Illuminate\Support\Facades\File;

/**
 * : target-aware offsite retention.
 *
 * These tests execute the real shipped `infrastructure/scripts/offsite-retention`
 * — never a reimplementation of its logic — against a genuine local rclone
 * "remote" (see OffsiteBackupTest.php's file-level doc comment for why this
 * is preferred over a stubbed rclone binary). rclone itself is wrapped in a
 * thin, real-rclone-executing logging shim (offsiteRetentionOpsRcloneWrapper)
 * so tests can assert *which* rclone subcommands actually ran — in
 * particular, proving dry-run invokes `lsf` but never `purge`, in any form —
 * without losing any of rclone's own real behaviour.
 */
function offsiteRetentionOpsScript(): string
{
    return base_path('infrastructure/scripts/offsite-retention');
}

function offsiteRetentionOpsSource(): string
{
    return File::get(offsiteRetentionOpsScript());
}

function offsiteRetentionOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function offsiteRetentionOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function offsiteRetentionOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function offsiteRetentionOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function offsiteRetentionOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/offsite-retention-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function offsiteRetentionOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function offsiteRetentionOpsExec(string $scriptPath, array $env): array
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
function offsiteRetentionOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', offsiteRetentionOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start offsite-retention subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function offsiteRetentionOpsRunHarness(string $scratch, string $body, array $env = [], ?string $scriptPath = null): array
{
    $scriptPath ??= offsiteRetentionOpsScript();
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg($scriptPath)."\n".$body."\n";
    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return offsiteRetentionOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

function offsiteRetentionOpsRcloneConfig(string $scratch): string
{
    $path = $scratch.'/rclone-'.uniqid('', true).'.conf';
    file_put_contents($path, "[rateguru-b2]\ntype = local\n");

    return $path;
}

function offsiteRetentionOpsRealRcloneBin(): string
{
    static $path = null;

    if ($path === null) {
        $path = trim((string) shell_exec('command -v rclone'));
        expect($path)->not->toBe('', 'rclone must be installed on PATH to run these tests (e.g. `brew install rclone`)');
    }

    return $path;
}

/**
 * A thin wrapper that logs every rclone subcommand invoked (one per line,
 * appended to RCLONE_INVOCATION_LOG) and then execs the real rclone binary
 * with the exact same arguments — real rclone semantics, with subcommand-
 * level observability layered on top. Also supports injecting a side effect
 * immediately before the Nth `lsf` call (RCLONE_INJECT_ON_LSF_COUNT /
 * RCLONE_INJECT_ON_LSF_PATH create that directory) — used to simulate a
 * backup uploaded concurrently between retention's unlocked preview pass and
 * its locked, authoritative pass.
 */
function offsiteRetentionOpsRcloneWrapper(string $scratch): string
{
    $path = $scratch.'/bin/rclone-wrapper';

    if (file_exists($path)) {
        return $path;
    }

    file_put_contents($path, <<<'SH'
        #!/usr/bin/env bash
        set -Eeuo pipefail

        # The subcommand is the first non-flag argument, skipping --config
        # and its own value — never simply "$1", which is "--config" on
        # every real invocation from offsite-backup/offsite-retention/
        # offsite-restore-test.
        subcommand=""
        skip_next=false
        for arg in "$@"; do
            if [[ "${skip_next}" == true ]]; then
                skip_next=false
                continue
            fi
            case "${arg}" in
                --config)
                    skip_next=true
                    ;;
                -*)
                    ;;
                *)
                    subcommand="${arg}"
                    break
                    ;;
            esac
        done

        printf '%s\n' "${subcommand}" >> "${RCLONE_INVOCATION_LOG}"

        if [[ "${subcommand}" == "lsf" ]]; then
            count_file="${RCLONE_LSF_COUNT_FILE}"
            count=0
            [[ -f "${count_file}" ]] && count="$(cat "${count_file}")"
            count=$((count + 1))
            printf '%s' "${count}" > "${count_file}"

            if [[ -n "${RCLONE_INJECT_ON_LSF_COUNT:-}" ]] \
                && [[ "${count}" == "${RCLONE_INJECT_ON_LSF_COUNT}" ]] \
                && [[ -n "${RCLONE_INJECT_ON_LSF_PATH:-}" ]]
            then
                mkdir -p "${RCLONE_INJECT_ON_LSF_PATH}"
            fi
        fi

        exec "${REAL_RCLONE_BIN}" "$@"
        SH);
    chmod($path, 0o755);

    return $path;
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function offsiteRetentionOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => offsiteRetentionOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => offsiteRetentionOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => offsiteRetentionOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => offsiteRetentionOpsTargetsCli(),
        'RATEGURU_RCLONE_CONFIG' => offsiteRetentionOpsRcloneConfig($scratch),
        'RATEGURU_RCLONE_REMOTE' => 'rateguru-b2',
        'RATEGURU_RCLONE_BIN' => offsiteRetentionOpsRcloneWrapper($scratch),
        'REAL_RCLONE_BIN' => offsiteRetentionOpsRealRcloneBin(),
        'RCLONE_INVOCATION_LOG' => $scratch.'/rclone-invocations.log',
        'RCLONE_LSF_COUNT_FILE' => $scratch.'/rclone-lsf-count-'.uniqid('', true),
    ], $overrides);
}

function offsiteRetentionOpsPatchedScript(string $scratch): string
{
    $path = $scratch.'/patched-offsite-retention';

    if (file_exists($path)) {
        return $path;
    }

    $source = File::get(offsiteRetentionOpsScript());
    $source = str_replace('-o root', '-o '.getmyuid(), $source);
    $source = str_replace('-g root', '-g '.getmygid(), $source);

    file_put_contents($path, $source);
    chmod($path, 0o755);

    return $path;
}

/**
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function offsiteRetentionOpsParityRegistry(string $scratch, string $backupNamespace = 'parity', int $offsiteRetentionDays = 17, int $minimumRetainedBackups = 2): array
{
    $account = trim((string) shell_exec('id -un'));
    $group = trim((string) shell_exec('id -gn'));

    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(offsiteRetentionOpsTargetsCli()),
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
                'backup' => ['namespace' => $backupNamespace, 'local_retention_days' => 14, 'offsite_retention_days' => $offsiteRetentionDays, 'minimum_retained_backups' => $minimumRetainedBackups],
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
 * Creates a fake remote timestamped backup directory (a single marker file
 * is enough — offsite-retention never inspects a backup's own contents).
 * compute_candidates classifies age purely from the timestamp encoded in the
 * directory name itself (see offsite-retention's own compute_candidates) —
 * never from filesystem mtime — so this fixture only needs that name right.
 */
function offsiteRetentionOpsBuildRemoteBackup(string $bucketRoot, string $namespace, string $timestamp): string
{
    $dir = "{$bucketRoot}/rateguru/{$namespace}/{$timestamp}";
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/database.dump', "fake\n");

    return $dir;
}

function offsiteRetentionOpsTimestampDaysAgo(int $days): string
{
    return gmdate('Ymd-His', time() - ($days * 86400));
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRetentionOpsParityRegistry($scratch);

        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target parity-target
            resolve_offsite_retention_subject
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, 'parse_offsite_retention_args', offsiteRetentionOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    // --environment is no longer a recognized flag at all — there is no
    // special deprecation message, just the same generic rejection any
    // other bogus flag gets.
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, 'parse_offsite_retention_args --environment staging', offsiteRetentionOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness(
            $scratch,
            'parse_offsite_retention_args --target a --target b',
            offsiteRetentionOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('rejects a selector given without a value, with an empty value, or with a flag-shaped value', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, 'parse_offsite_retention_args --target', offsiteRetentionOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');

        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, "parse_offsite_retention_args --target ''", offsiteRetentionOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');

        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, 'parse_offsite_retention_args --target --apply', offsiteRetentionOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --apply');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, 'parse_offsite_retention_args --help', offsiteRetentionOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->not->toContain('--environment');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, 'parse_offsite_retention_args --bogus', offsiteRetentionOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

// =============================================================================
// Root-first, lifecycle ordering
// =============================================================================

it('requires root before anything else, even for --target tits-guru', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunScript(['--target', 'tits-guru'], offsiteRetentionOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('rejects a planned target before any rclone config, remote listing, lock, or purge', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target tits-guru
            resolve_offsite_retention_subject
            echo "SHOULD NOT REACH HERE"
            BASH, offsiteRetentionOpsBaseEnv($scratch, ['RATEGURU_RCLONE_CONFIG' => '/definitely/missing/rclone.conf']));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target ghost-target
            resolve_offsite_retention_subject
            BASH, offsiteRetentionOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

// =============================================================================
// Target-specific retention
// =============================================================================

it('resolves retention from the registry', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRetentionOpsParityRegistry($scratch, 'parity', 17);

        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target parity-target
            resolve_offsite_retention_subject
            printf 'RETENTION=%s\n' "${OFFSITE_RETENTION_DAYS}"
            BASH, offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('RETENTION=17');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('purges a 20-day-old backup under a 17-day retention cutoff but retains the same-age backup under a 30-day cutoff', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRetentionOpsParityRegistry($scratch, 'parity', 17);

        // Independent bucket roots per retention window — three backups each,
        // so the 20-day-old backup sits beyond the protected minimum of two
        // and its fate is decided purely by the age window.
        $shortBucketRoot = $scratch.'/bucket-short';
        mkdir($shortBucketRoot, 0o755, true);
        $shortOldTs = offsiteRetentionOpsTimestampDaysAgo(20);
        offsiteRetentionOpsBuildRemoteBackup($shortBucketRoot, 'parity', $shortOldTs);
        offsiteRetentionOpsBuildRemoteBackup($shortBucketRoot, 'parity', offsiteRetentionOpsTimestampDaysAgo(2));
        offsiteRetentionOpsBuildRemoteBackup($shortBucketRoot, 'parity', offsiteRetentionOpsTimestampDaysAgo(1));

        // Calendar-invalid, shape-matching name: --apply must never purge it.
        offsiteRetentionOpsBuildRemoteBackup($shortBucketRoot, 'parity', '20200199-000000');

        [$shortExit, $shortOutput] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target parity-target --apply
            resolve_offsite_retention_subject
            perform_offsite_retention
            BASH, offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_RCLONE_BUCKET' => $shortBucketRoot,
            'RATEGURU_RUN_ROOT' => $scratch.'/run-short',
        ]), offsiteRetentionOpsPatchedScript($scratch));

        expect($shortExit)->toBe(0, $shortOutput);
        expect($shortOutput)->toContain('DELETE: ')->toContain($shortOldTs);
        expect(is_dir("{$shortBucketRoot}/rateguru/parity/{$shortOldTs}"))
            ->toBeFalse('a 20-day-old backup must be purged under a 17-day retention window');
        expect($shortOutput)->toContain('SKIP malformed: 20200199-000000');
        expect(is_dir("{$shortBucketRoot}/rateguru/parity/20200199-000000"))
            ->toBeTrue('a calendar-invalid timestamp name must never be purged');

        // The same-age backup under a 30-day window: still beyond the
        // protected minimum, but inside the age window this time.
        [$longRegistryPath, $longTargetsPath] = offsiteRetentionOpsParityRegistry($scratch, 'parity', 30);

        $longBucketRoot = $scratch.'/bucket-long';
        mkdir($longBucketRoot, 0o755, true);
        $longOldTs = offsiteRetentionOpsTimestampDaysAgo(20);
        offsiteRetentionOpsBuildRemoteBackup($longBucketRoot, 'parity', $longOldTs);
        offsiteRetentionOpsBuildRemoteBackup($longBucketRoot, 'parity', offsiteRetentionOpsTimestampDaysAgo(2));
        offsiteRetentionOpsBuildRemoteBackup($longBucketRoot, 'parity', offsiteRetentionOpsTimestampDaysAgo(1));

        [$longExit, $longOutput] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target parity-target --apply
            resolve_offsite_retention_subject
            perform_offsite_retention
            BASH, offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $longRegistryPath,
            'RATEGURU_TARGETS_CLI' => $longTargetsPath,
            'RATEGURU_RCLONE_BUCKET' => $longBucketRoot,
            'RATEGURU_RUN_ROOT' => $scratch.'/run-long',
        ]), offsiteRetentionOpsPatchedScript($scratch));

        expect($longExit)->toBe(0, $longOutput);
        expect($longOutput)->toContain("KEEP recent: {$longOldTs}");
        expect(is_dir("{$longBucketRoot}/rateguru/parity/{$longOldTs}"))
            ->toBeTrue('the same-age backup must be retained under a 30-day retention window');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('locks and lists the remote namespace by the resolved BACKUP_NAMESPACE', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target staging-main
            resolve_offsite_retention_subject
            printf 'BACKUP_NAMESPACE=%s\n' "${BACKUP_NAMESPACE}"
            BASH, offsiteRetentionOpsBaseEnv($scratch));
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('BACKUP_NAMESPACE=staging');

        $source = offsiteRetentionOpsSource();
        expect($source)->toContain('LOCK_FILE="${RUN_ROOT}/offsite-retention-${BACKUP_NAMESPACE}.lock"');
        expect($source)->toContain('REMOTE_ROOT="${RCLONE_REMOTE}:${BUCKET}/rateguru/${BACKUP_NAMESPACE}"');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('cannot run two offsite retentions concurrently against the same namespace lock', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRetentionOpsParityRegistry($scratch, 'parity');

        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'parity', offsiteRetentionOpsTimestampDaysAgo(1));

        $runRoot = $scratch.'/run-'.uniqid('', true);
        mkdir($runRoot, 0o755, true);
        $lockFile = $runRoot.'/offsite-retention-parity.lock';

        $lockHandle = fopen($lockFile, 'c');
        expect(flock($lockHandle, LOCK_EX | LOCK_NB))->toBeTrue('could not pre-acquire the shared namespace lock');

        try {
            [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
                parse_offsite_retention_args --target parity-target --apply
                resolve_offsite_retention_subject
                perform_offsite_retention
                BASH, offsiteRetentionOpsBaseEnv($scratch, [
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
                'RATEGURU_RUN_ROOT' => $runRoot,
            ]), offsiteRetentionOpsPatchedScript($scratch));

            expect($exit)->not->toBe(0);
            expect($output)->toContain('already running');
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

// =============================================================================
// Candidate computation: newest minimum protected, recent protected, old
// candidate, deterministic order
// =============================================================================

it('protects the newest two backups as the minimum, keeps recent backups beyond it, and selects only old backups as candidates', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);

        // staging-main's committed offsite window is 14 days: two ancient
        // candidates, one backup that is old-ish but inside the window, and
        // two newest entries protected purely by the minimum count.
        $tsOld1 = offsiteRetentionOpsTimestampDaysAgo(40);
        $tsOld2 = offsiteRetentionOpsTimestampDaysAgo(35);
        $tsRecent = offsiteRetentionOpsTimestampDaysAgo(10);
        $tsSecondNewest = offsiteRetentionOpsTimestampDaysAgo(5);
        $tsNewest = offsiteRetentionOpsTimestampDaysAgo(1);

        foreach ([$tsOld1, $tsOld2, $tsRecent, $tsSecondNewest, $tsNewest] as $ts) {
            offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', $ts);
        }

        // Matches the timestamp shape but is not a real calendar date (day
        // 99): it must be skipped outright — never a candidate, never a
        // consumer of a protected-minimum slot.
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', '20200199-000000');

        $env = offsiteRetentionOpsBaseEnv($scratch, ['RATEGURU_RCLONE_BUCKET' => $bucketRoot]);

        [$exit, $output] = offsiteRetentionOpsRunHarness(
            $scratch,
            "parse_offsite_retention_args --target staging-main\nresolve_offsite_retention_subject\nperform_offsite_retention",
            $env,
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain("KEEP minimum: {$tsNewest}")
            ->toContain("KEEP minimum: {$tsSecondNewest}")
            ->toContain("KEEP recent: {$tsRecent}")
            ->toContain('SKIP malformed: 20200199-000000')
            ->toContain('WOULD DELETE: ')
            ->toContain($tsOld1)
            ->toContain($tsOld2)
            ->toContain('Retention candidates: 2');

        // Dry-run: nothing actually removed.
        expect(is_dir("{$bucketRoot}/rateguru/staging/{$tsOld1}"))->toBeTrue();
        expect(is_dir("{$bucketRoot}/rateguru/staging/{$tsOld2}"))->toBeTrue();
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('never deletes the newest two backups under --apply, no matter how old they are', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRetentionOpsParityRegistry($scratch, 'parity', 17);

        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);
        $tsAncient1 = offsiteRetentionOpsTimestampDaysAgo(400);
        $tsAncient2 = offsiteRetentionOpsTimestampDaysAgo(300);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'parity', $tsAncient1);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'parity', $tsAncient2);

        $invocationLog = $scratch.'/rclone-invocations.log';

        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target parity-target --apply
            resolve_offsite_retention_subject
            perform_offsite_retention
            BASH, offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
            'RATEGURU_RUN_ROOT' => $scratch.'/run-'.uniqid('', true),
            'RCLONE_INVOCATION_LOG' => $invocationLog,
        ]), offsiteRetentionOpsPatchedScript($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain("KEEP minimum: {$tsAncient1}")
            ->toContain("KEEP minimum: {$tsAncient2}")
            ->toContain('Retention candidates: 0');
        expect(is_dir("{$bucketRoot}/rateguru/parity/{$tsAncient1}"))->toBeTrue('minimum count has priority over the age cutoff');
        expect(is_dir("{$bucketRoot}/rateguru/parity/{$tsAncient2}"))->toBeTrue('minimum count has priority over the age cutoff');
        expect(File::get($invocationLog))->not->toContain('purge');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('never deletes a single remaining backup under --apply, no matter how old it is', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        [$registryPath, $targetsPath] = offsiteRetentionOpsParityRegistry($scratch, 'parity', 17);

        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);
        $tsAncient = offsiteRetentionOpsTimestampDaysAgo(500);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'parity', $tsAncient);

        $invocationLog = $scratch.'/rclone-invocations.log';

        [$exit, $output] = offsiteRetentionOpsRunHarness($scratch, <<<'BASH'
            parse_offsite_retention_args --target parity-target --apply
            resolve_offsite_retention_subject
            perform_offsite_retention
            BASH, offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
            'RATEGURU_RUN_ROOT' => $scratch.'/run-'.uniqid('', true),
            'RCLONE_INVOCATION_LOG' => $invocationLog,
        ]), offsiteRetentionOpsPatchedScript($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain("KEEP minimum: {$tsAncient}");
        expect(is_dir("{$bucketRoot}/rateguru/parity/{$tsAncient}"))->toBeTrue('the last backup must never be deleted');
        expect(File::get($invocationLog))->not->toContain('purge');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('produces deterministic candidate ordering across repeated runs', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);

        // Ages deliberately well clear of staging's 14-day cutoff (never
        // exactly 14) — a fixture built at precisely the cutoff is a genuine
        // boundary case the script correctly (and deliberately) treats as
        // "keep, inclusive", which would make this specific test flaky
        // depending on whether the fixture and the script's own `date`
        // evaluation land in the same wall-clock second.
        $timestamps = [];
        foreach ([60, 55, 50, 45, 40] as $age) {
            $ts = offsiteRetentionOpsTimestampDaysAgo($age);
            offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', $ts);
            $timestamps[] = $ts;
        }
        $latestTs = offsiteRetentionOpsTimestampDaysAgo(1);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', $latestTs);

        $env = offsiteRetentionOpsBaseEnv($scratch, ['RATEGURU_RCLONE_BUCKET' => $bucketRoot]);

        $firstRunOutput = null;
        for ($i = 0; $i < 2; $i++) {
            [$exit, $output] = offsiteRetentionOpsRunHarness(
                $scratch,
                "parse_offsite_retention_args --target staging-main\nresolve_offsite_retention_subject\nperform_offsite_retention",
                $env,
            );
            expect($exit)->toBe(0, $output);

            preg_match_all('/WOULD DELETE: (\S+)/', $output, $matches);
            $order = $matches[1];

            if ($firstRunOutput === null) {
                $firstRunOutput = $order;
                // Six backups total; the newest old one joins the fresh
                // latest inside the protected minimum of two, leaving four
                // genuine candidates.
                expect($order)->toHaveCount(4);
            } else {
                expect($order)->toBe($firstRunOutput, 'candidate order must be deterministic across repeated runs against identical input');
            }
        }
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

// =============================================================================
// Dry-run is genuinely side-effect free: no lock, no purge in any form
// =============================================================================

it('dry-run never invokes rclone purge, not even with --dry-run, and acquires no lock', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);
        $oldTs = offsiteRetentionOpsTimestampDaysAgo(40);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', $oldTs);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', offsiteRetentionOpsTimestampDaysAgo(1));

        $runRoot = $scratch.'/run-'.uniqid('', true);
        $invocationLog = $scratch.'/rclone-invocations.log';

        $env = offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
            'RATEGURU_RUN_ROOT' => $runRoot,
            'RCLONE_INVOCATION_LOG' => $invocationLog,
        ]);

        [$exit, $output] = offsiteRetentionOpsRunHarness(
            $scratch,
            "parse_offsite_retention_args --target staging-main\nresolve_offsite_retention_subject\nperform_offsite_retention",
            $env,
        );

        expect($exit)->toBe(0, $output);

        $invocations = array_values(array_filter(explode("\n", trim(File::get($invocationLog)))));
        expect($invocations)->toBe(['lsf'], 'dry-run must call rclone exactly once, and only lsf — never purge in any form');

        expect(is_dir($runRoot))->toBeFalse('dry-run must never create the lock directory — it acquires no lock at all');
        expect(is_dir("{$bucketRoot}/rateguru/staging/{$oldTs}"))->toBeTrue('dry-run must delete nothing');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

// =============================================================================
// Apply: locks, re-lists, recomputes, purges only from the fresh computation
// =============================================================================

it('apply lists remote backups twice — an unlocked preview, then a locked, authoritative pass — and only purges from the second', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);
        $oldTs = offsiteRetentionOpsTimestampDaysAgo(40);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', $oldTs);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', offsiteRetentionOpsTimestampDaysAgo(2));
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', offsiteRetentionOpsTimestampDaysAgo(1));

        $runRoot = $scratch.'/run-'.uniqid('', true);
        $invocationLog = $scratch.'/rclone-invocations.log';

        $env = offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
            'RATEGURU_RUN_ROOT' => $runRoot,
            'RCLONE_INVOCATION_LOG' => $invocationLog,
        ]);

        [$exit, $output] = offsiteRetentionOpsRunHarness(
            $scratch,
            "parse_offsite_retention_args --target staging-main --apply\nresolve_offsite_retention_subject\nperform_offsite_retention",
            $env,
            offsiteRetentionOpsPatchedScript($scratch),
        );

        expect($exit)->toBe(0, $output);

        $invocations = array_values(array_filter(explode("\n", trim(File::get($invocationLog)))));
        expect($invocations)->toBe(['lsf', 'lsf', 'purge'], 'apply must list twice (preview, then locked) and purge only after the second listing');

        expect(is_dir("{$bucketRoot}/rateguru/staging/{$oldTs}"))->toBeFalse('the old candidate must actually be purged under --apply');
        expect(is_dir($runRoot))->toBeTrue('apply must create the lock directory');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('protects a backup uploaded concurrently between the preview pass and the locked pass, and never purges it', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);

        // At preview time, this is the only (and therefore latest) backup —
        // young enough to survive on its own, but about to be superseded.
        $wouldBeLatestAtPreview = offsiteRetentionOpsTimestampDaysAgo(2);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', $wouldBeLatestAtPreview);

        // Injected immediately before the SECOND `lsf` call (the locked,
        // authoritative one) — simulating a real backup uploaded
        // concurrently by another process while retention was starting.
        $injectedDuringLock = "{$bucketRoot}/rateguru/staging/".offsiteRetentionOpsTimestampDaysAgo(0);

        $runRoot = $scratch.'/run-'.uniqid('', true);
        $invocationLog = $scratch.'/rclone-invocations.log';

        $env = offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
            'RATEGURU_RUN_ROOT' => $runRoot,
            'RCLONE_INVOCATION_LOG' => $invocationLog,
            'RCLONE_INJECT_ON_LSF_COUNT' => '2',
            'RCLONE_INJECT_ON_LSF_PATH' => $injectedDuringLock,
        ]);

        [$exit, $output] = offsiteRetentionOpsRunHarness(
            $scratch,
            "parse_offsite_retention_args --target staging-main --apply\nresolve_offsite_retention_subject\nperform_offsite_retention",
            $env,
            offsiteRetentionOpsPatchedScript($scratch),
        );

        expect($exit)->toBe(0, $output);

        // The concurrently-injected backup is now genuinely the latest, by
        // name — the locked, authoritative listing must protect it, and the
        // backup that only *looked* latest during the unlocked preview must
        // never be purged either, since it is younger than the retention
        // cutoff on its own.
        expect(is_dir($injectedDuringLock))->toBeTrue('a backup uploaded concurrently during the locked pass must never be purged');
        expect(is_dir("{$bucketRoot}/rateguru/staging/{$wouldBeLatestAtPreview}"))->toBeTrue();
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('only apply ever invokes rclone purge — dry-run never does, under any circumstances', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', offsiteRetentionOpsTimestampDaysAgo(40));
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', offsiteRetentionOpsTimestampDaysAgo(2));
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', offsiteRetentionOpsTimestampDaysAgo(1));

        $dryRunLog = $scratch.'/dry-run-invocations.log';
        $dryRunEnv = offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
            'RCLONE_INVOCATION_LOG' => $dryRunLog,
        ]);

        [$dryRunExit] = offsiteRetentionOpsRunHarness(
            $scratch,
            "parse_offsite_retention_args --target staging-main\nresolve_offsite_retention_subject\nperform_offsite_retention",
            $dryRunEnv,
        );
        expect($dryRunExit)->toBe(0);
        expect(File::get($dryRunLog))->not->toContain('purge');

        $applyLog = $scratch.'/apply-invocations.log';
        $applyEnv = offsiteRetentionOpsBaseEnv($scratch, [
            'RATEGURU_RCLONE_BUCKET' => $bucketRoot,
            'RATEGURU_RUN_ROOT' => $scratch.'/run-apply',
            'RCLONE_INVOCATION_LOG' => $applyLog,
        ]);

        [$applyExit] = offsiteRetentionOpsRunHarness(
            $scratch,
            "parse_offsite_retention_args --target staging-main --apply\nresolve_offsite_retention_subject\nperform_offsite_retention",
            $applyEnv,
            offsiteRetentionOpsPatchedScript($scratch),
        );
        expect($applyExit)->toBe(0);
        expect(File::get($applyLog))->toContain('purge');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('reports no candidates when every remote backup is inside the protected minimum or within retention', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot, 0o755, true);
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', offsiteRetentionOpsTimestampDaysAgo(5));
        offsiteRetentionOpsBuildRemoteBackup($bucketRoot, 'staging', offsiteRetentionOpsTimestampDaysAgo(1));

        $env = offsiteRetentionOpsBaseEnv($scratch, ['RATEGURU_RCLONE_BUCKET' => $bucketRoot]);

        [$exit, $output] = offsiteRetentionOpsRunHarness(
            $scratch,
            "parse_offsite_retention_args --target staging-main\nresolve_offsite_retention_subject\nperform_offsite_retention",
            $env,
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('Retention candidates: 0');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});

it('reports no timestamped remote backups found without failing when the namespace is empty', function () {
    $scratch = offsiteRetentionOpsScratchDir();

    try {
        $bucketRoot = $scratch.'/bucket';
        mkdir($bucketRoot.'/rateguru/staging', 0o755, true);

        $env = offsiteRetentionOpsBaseEnv($scratch, ['RATEGURU_RCLONE_BUCKET' => $bucketRoot]);

        [$exit, $output] = offsiteRetentionOpsRunHarness(
            $scratch,
            "parse_offsite_retention_args --target staging-main\nresolve_offsite_retention_subject\nperform_offsite_retention",
            $env,
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('No timestamped remote backups found.');
    } finally {
        offsiteRetentionOpsCleanup($scratch);
    }
});
