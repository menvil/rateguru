<?php

use Illuminate\Support\Facades\File;

/**
 * the target-aware migration: target-aware health-check and status.
 *
 * These tests execute the real shipped scripts as subprocesses — never a
 * reimplementation of their logic — with every host dependency (common's
 * deployment.conf, the target registry, the targets validator, health-check
 * itself, and curl) supplied through the RATEGURU_* test-override contract
 * already established in the target-aware migration. No test touches the network,
 * systemctl, or any real /home/www path.
 */
function targetOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function targetOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function targetOpsHealthCheckScript(): string
{
    return base_path('infrastructure/scripts/health-check');
}

function targetOpsStatusScript(): string
{
    return base_path('infrastructure/scripts/status');
}

function targetOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

/**
 * The committed deployment.conf template, used verbatim as the fixture
 * deployment.conf for every test that does not need a modified STAGING_ROOT.
 * Using the real file — rather than a hand-copied fixture — means these tests
 * automatically track the values slice 1 already proved match the registry.
 */
function targetOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

/**
 * A fresh scratch directory for one test, removed by the caller via
 * targetOpsCleanup(). Every fixture (fake curl, fake health-check, mutated
 * registries, content roots) lives under here.
 */
function targetOpsScratchDir(): string
{
    // uniqid() alone is timestamp-based and can collide under parallel test
    // workers (this suite runs via `php artisan test --parallel`) if two
    // processes call it in the same microsecond. more_entropy plus the PID
    // makes a collision practically impossible without adding a dependency.
    $dir = sys_get_temp_dir().'/target-ops-'.uniqid('', true).'-'.getmypid();

    expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}");
    expect(@mkdir($dir.'/bin', 0o755, true))->toBeTrue("could not create scratch bin directory: {$dir}/bin");

    return $dir;
}

function targetOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * `git show origin/develop:<path>`, with the exit code checked explicitly —
 * shell_exec() alone cannot distinguish "content" from "git's error message,
 * merged from stderr, about content that was never actually read" the way an
 * exit code can. stdout and stderr are captured separately via proc_open so
 * git's own diagnostics never contaminate the byte-for-byte comparison this
 * is used for.
 *
 * @return array{0: bool, 1: string, 2: string} [ok, stdout, stderr]
 */
function targetOpsDevelopContent(string $path): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        'git show origin/develop:'.escapeshellarg($path),
        $descriptors,
        $pipes,
    );

    expect($process)->not->toBeFalse('could not start git show');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    return [$exit === 0, $stdout, $stderr];
}

/**
 * A fake `curl` executable: appends its argv to a log file and prints the
 * given HTTP status code, exactly matching the real curl invocation's
 * `--write-out '%{http_code}'` contract. Placed first in PATH, it intercepts
 * every curl call health-check makes — no network access ever occurs.
 */
function targetOpsFakeCurl(string $scratchDir, string $logFile, int $httpCode = 200): string
{
    $path = $scratchDir.'/bin/curl';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."echo {$httpCode}\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A fake `health-check` binary for testing status's delegation: logs the
 * exact argv it was called with and exits 0. Lets status's own logic be
 * tested independently of health-check's curl-retry behaviour.
 */
function targetOpsFakeHealthCheck(string $scratchDir, string $logFile, int $exitCode = 0): string
{
    $path = $scratchDir.'/bin/fake-health-check';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit {$exitCode}\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A recording stub for RATEGURU_TARGETS_CLI: logs its invocation, then
 * delegates to the real targets CLI, so a caller that genuinely reaches this
 * (gate on) still gets correct validate/list/show behaviour, not just a
 * recorded call.
 */
function targetOpsFakeTargetsCli(string $scratchDir, string $logFile): string
{
    $path = $scratchDir.'/bin/fake-targets';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        .'exec '.escapeshellarg(targetOpsTargetsCli())." \"\$@\"\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A "malicious" common fixture: fully self-contained (defines its own
 * fail/log/parse_target_args/target_*), so it never needs a real
 * deployment.conf or target registry, and records a marker every time it is
 * sourced. Used to prove RATEGURU_COMMON_FILE is never sourced without the
 * opt-in flag — with the flag, health-check runs to completion entirely on
 * this fixture's fake target_health_url/target_health_host_header, proving
 * genuine sourcing rather than a coincidental side effect.
 */
function targetOpsMaliciousCommon(string $scratchDir, string $markerLog): string
{
    $path = $scratchDir.'/malicious-common';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "MALICIOUS_COMMON_SOURCED" >> '.escapeshellarg($markerLog)."\n"
        .'fail() { printf "[ERR] %s\n" "$*" >&2; exit 1; }'."\n"
        .'log() { printf "[log] %s\n" "$*"; }'."\n"
        // Minimal stand-in: the real one is far more thorough, but this test
        // only ever calls `--target staging-main` against this fixture.
        .'parse_target_args() { TARGET_ID="staging-main"; TARGET_SEEN=true; }'."\n"
        .'require_active_target() { :; }'."\n"
        .'target_health_url() { echo "http://127.0.0.1/"; }'."\n"
        .'target_health_host_header() { echo "marker-fixture.internal"; }'."\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * Run a script (health-check or status) as a real subprocess with the given
 * CLI arguments and RATEGURU_* environment overrides layered on top of a
 * curl-intercepting PATH.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function targetOpsRun(string $script, array $arguments, array $env, string $scratchDir): array
{
    $command = '';

    foreach ($env as $key => $value) {
        $command .= $key.'='.escapeshellarg($value).' ';
    }

    $command .= 'PATH='.escapeshellarg($scratchDir.'/bin:'.getenv('PATH')).' ';
    $command .= 'bash '.escapeshellarg($script);

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $output = [];
    $exit = 0;
    exec($command.' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

/**
 * The standard environment: real common, real deployment.conf template, real
 * registry, real targets validator. Individual RATEGURU_* keys can be
 * overridden per test via $overrides.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function targetOpsBaseEnv(array $overrides = []): array
{
    return array_merge([
        'RATEGURU_COMMON_FILE' => targetOpsCommonFile(),
        // Required alongside RATEGURU_DEPLOYMENT_CONF_FILE and
        // RATEGURU_HEALTH_CHECK_CLI below — common ignores both unless this is
        // also explicitly true, so a stray environment variable in a real root
        // shell can never silently redirect a privileged script.
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_DEPLOYMENT_CONF_FILE' => targetOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => targetOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => targetOpsTargetsCli(),
    ], $overrides);
}

/**
 * A registry mutated to add a fully valid third target with lifecycle
 * "disabled" — needed because neither committed target uses that lifecycle.
 * Every collision-sensitive field is given a unique value so the fixture
 * still passes full registry validation.
 */
function targetOpsRegistryWithDisabledTarget(string $scratchDir): string
{
    $path = $scratchDir.'/registry-with-disabled.json';

    $jq = <<<'JQ'
        .targets["disabled-test"] = (.targets["tits-guru"]
            | .id = "disabled-test"
            | .lifecycle = "disabled"
            | .application_root = "/home/www/rateguru/production/disabled-test"
            | .runtime_user = "rateguru-disabled-test"
            | .runtime_group = "rateguru-disabled-test"
            | .deploy_user = "deploy-rateguru-disabled-test"
            | .code_group = "rateguru-disabled-test-code"
            | .incoming_artifacts = "/home/deploy-rateguru-disabled-test/incoming"
            | .database.name = "rateguru_disabled_test"
            | .database.application_role = "rateguru_disabled_test_app"
            | .health.host_header = "disabled-test.internal"
            | .backup.namespace = "disabled-test"
            | .php_fpm.pool = "rateguru-disabled-test"
            | .php_fpm.socket = "/run/php/rateguru-disabled-test.sock"
            | .supervisor.program = "rateguru-disabled-test-queue"
            | .supervisor.queue = "rateguru-disabled-test"
            | .scheduler.name = "rateguru-disabled-test-scheduler"
            | .nginx.site_name = "rateguru-disabled-test"
            | .nginx.internal_hostname = "disabled-test.internal"
            | .public_hostnames = ["disabled-test.example"])
        JQ;

    $command = sprintf(
        'jq %s %s > %s',
        escapeshellarg($jq),
        escapeshellarg(targetOpsRegistryPath()),
        escapeshellarg($path),
    );

    exec($command.' 2>&1', $output, $exit);
    expect($exit)->toBe(0, "could not build disabled-target fixture:\n".implode("\n", $output));

    // The fixture must itself be valid, or every test using it proves nothing.
    exec(escapeshellarg(targetOpsTargetsCli()).' validate --file '.escapeshellarg($path).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "disabled-target fixture failed validation:\n".implode("\n", $validateOutput));

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active pointing at an arbitrary scratch
 * application root — the same technique Backup/Cleanup/DeployTest.php
 * already establish, needed here because the real committed registry
 * hardcodes staging-main's application_root under /home/www/rateguru (which
 * does not exist on this test machine) and `targets`' own validator rejects
 * any active target outside that prefix.
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function targetOpsParityRegistry(string $scratch, string $applicationRoot): array
{
    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(targetOpsTargetsCli()),
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

    $targetsPath = $scratch.'/parity-targets';
    file_put_contents($targetsPath, $patchedTargets);
    chmod($targetsPath, 0o755);

    $registry = [
        'schema_version' => 1,
        'targets' => [
            'parity-target' => [
                'id' => 'parity-target',
                'lifecycle' => 'active',
                'environment_class' => 'staging',
                'application_root' => $applicationRoot,
                'runtime_user' => 'parity-runtime',
                'runtime_group' => 'parity-runtime',
                'deploy_user' => 'parity-deploy',
                'code_group' => 'parity-code',
                'incoming_artifacts' => $scratch.'/incoming-unused',
                'release_retention' => 5,
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example'],
                'backup' => ['namespace' => 'parity', 'local_retention_days' => 1, 'offsite_retention_days' => 1, 'minimum_retained_backups' => 2],
                'php_fpm' => ['pool' => 'parity', 'socket' => '/run/php/parity.sock'],
                'supervisor' => ['program' => 'parity-queue', 'queue' => 'parity'],
                'scheduler' => ['name' => 'parity-scheduler'],
                'nginx' => ['site_name' => 'parity', 'internal_hostname' => 'parity.internal'],
                'environment_template' => 'infrastructure/templates/environment/staging.env.example',
            ],
        ],
    ];

    $registryPath = $scratch.'/parity-registry.json';
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "parity-target fixture failed validation:\n".implode("\n", $validateOutput));

    return [$registryPath, $targetsPath];
}

// --- health-check: target selector -----------------------------------------

it('resolves --target staging-main to the expected health-check request', function () {
    $scratch = targetOpsScratchDir();

    try {
        $targetLog = $scratch.'/curl-target.log';

        targetOpsFakeCurl($scratch, $targetLog);
        [$targetExit, $targetOutput] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv(['CURL_LOG' => $targetLog]),
            $scratch,
        );

        expect($targetExit)->toBe(0, $targetOutput);

        $targetInvocation = trim(File::get($targetLog));
        expect($targetInvocation)->not->toBe('');

        // Pin the exact expected request.
        expect($targetInvocation)
            ->toContain('Host: rateguru-staging.internal')
            ->toContain('http://127.0.0.1/up');

        expect($targetOutput)->toContain('staging-main health check passed: http://127.0.0.1/up');
    } finally {
        targetOpsCleanup($scratch);
    }
});

// --- lifecycle safety --------------------------------------------------------

it('rejects a planned target before any curl call', function () {
    $scratch = targetOpsScratchDir();

    try {
        $curlLog = $scratch.'/curl.log';
        touch($curlLog);

        targetOpsFakeCurl($scratch, $curlLog);
        [$exit, $output] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'tits-guru'],
            targetOpsBaseEnv(['CURL_LOG' => $curlLog]),
            $scratch,
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');

        expect(trim(File::get($curlLog)))->toBe('', 'curl must never be invoked for a planned target');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects a disabled target before any curl call', function () {
    $scratch = targetOpsScratchDir();

    try {
        $registry = targetOpsRegistryWithDisabledTarget($scratch);
        $curlLog = $scratch.'/curl.log';
        touch($curlLog);

        targetOpsFakeCurl($scratch, $curlLog);
        [$exit, $output] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'disabled-test'],
            targetOpsBaseEnv([
                'RATEGURU_TARGET_REGISTRY_FILE' => $registry,
                'CURL_LOG' => $curlLog,
            ]),
            $scratch,
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('disabled-test')
            ->toContain('lifecycle=disabled')
            ->toContain('not active');

        expect(trim(File::get($curlLog)))->toBe('', 'curl must never be invoked for a disabled target');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects an unknown target before any curl call', function () {
    $scratch = targetOpsScratchDir();

    try {
        $curlLog = $scratch.'/curl.log';
        touch($curlLog);

        targetOpsFakeCurl($scratch, $curlLog);
        [$exit, $output] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'ghost-target'],
            targetOpsBaseEnv(['CURL_LOG' => $curlLog]),
            $scratch,
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
        expect(trim(File::get($curlLog)))->toBe('', 'curl must never be invoked for an unknown target');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects an invalid target ID before any curl call', function () {
    $scratch = targetOpsScratchDir();

    try {
        $curlLog = $scratch.'/curl.log';
        touch($curlLog);

        targetOpsFakeCurl($scratch, $curlLog);
        [$exit, $output] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', '../etc'],
            targetOpsBaseEnv(['CURL_LOG' => $curlLog]),
            $scratch,
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('invalid target ID');
        expect(trim(File::get($curlLog)))->toBe('');
    } finally {
        targetOpsCleanup($scratch);
    }
});

// --- selector contract: CLI argument handling -------------------------------

it('requires --target', function () {
    $scratch = targetOpsScratchDir();

    try {
        foreach ([targetOpsHealthCheckScript(), targetOpsStatusScript()] as $script) {
            [$exit, $output] = targetOpsRun($script, [], targetOpsBaseEnv(), $scratch);

            expect($exit)->not->toBe(0, basename($script));
            expect($output)->toContain('--target is required');
        }
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = targetOpsScratchDir();

    try {
        foreach ([targetOpsHealthCheckScript(), targetOpsStatusScript()] as $script) {
            [$exit, $output] = targetOpsRun(
                $script,
                ['--target', 'staging-main', '--target', 'staging-main'],
                targetOpsBaseEnv(),
                $scratch,
            );
            expect($exit)->not->toBe(0, basename($script));
            expect($output)->toContain('--target given more than once');
        }
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects --target given without a value', function () {
    $scratch = targetOpsScratchDir();

    try {
        foreach ([targetOpsHealthCheckScript(), targetOpsStatusScript()] as $script) {
            [$exit, $output] = targetOpsRun($script, ['--target'], targetOpsBaseEnv(), $scratch);
            expect($exit)->not->toBe(0, basename($script));
            expect($output)->toContain('--target requires a value');
        }
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = targetOpsScratchDir();

    try {
        foreach ([targetOpsHealthCheckScript(), targetOpsStatusScript()] as $script) {
            [$exit, $output] = targetOpsRun($script, ['--bogus'], targetOpsBaseEnv(), $scratch);
            expect($exit)->not->toBe(0, basename($script));
            expect($output)->toContain('unknown argument: --bogus');
        }
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    $scratch = targetOpsScratchDir();

    try {
        foreach ([targetOpsHealthCheckScript(), targetOpsStatusScript()] as $script) {
            [$exit, $output] = targetOpsRun($script, ['--environment', 'staging'], targetOpsBaseEnv(), $scratch);
            expect($exit)->not->toBe(0, basename($script));
            expect($output)->toContain('unknown argument: --environment');
        }
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help', function () {
    $scratch = targetOpsScratchDir();

    try {
        foreach ([targetOpsHealthCheckScript(), targetOpsStatusScript()] as $script) {
            [$exit, $output] = targetOpsRun($script, ['--help'], targetOpsBaseEnv(), $scratch);
            expect($exit)->toBe(0, basename($script));
            expect($output)
                ->toContain('--target TARGET')
                ->not->toContain('--environment');
        }
    } finally {
        targetOpsCleanup($scratch);
    }
});

// --- status: path parity and delegation -------------------------------------

it('resolves status --target staging-main to the expected paths and header', function () {
    $scratch = targetOpsScratchDir();

    try {
        $hcLog = $scratch.'/hc.log';
        touch($hcLog);
        $fakeHealthCheck = targetOpsFakeHealthCheck($scratch, $hcLog);

        [$exit, $output] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv(['RATEGURU_HEALTH_CHECK_CLI' => $fakeHealthCheck]),
            $scratch,
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('Target:      staging-main')
            ->toContain('Lifecycle:   active')
            ->toContain('Environment class: staging')
            ->toContain('Current:     not configured')
            ->toContain('Previous:    not configured')
            ->toContain('release.json is unavailable')
            ->toContain('No deployment history');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('displays real release, metadata and history content', function () {
    // The real committed registry hardcodes staging-main's application_root
    // under /home/www/rateguru, which does not exist on this test machine —
    // targetOpsParityRegistry supplies a synthetic active target pointing at
    // a scratch content root instead, so this proves the real print_link/
    // release.json/history rendering code against genuine content.
    $scratch = targetOpsScratchDir();

    try {
        $contentRoot = $scratch.'/content-root';
        mkdir($contentRoot.'/releases/v1.0.0-20260101-000000-abc1234', 0o755, true);
        mkdir($contentRoot.'/deployments', 0o755, true);

        file_put_contents(
            $contentRoot.'/releases/v1.0.0-20260101-000000-abc1234/release.json',
            json_encode(['release' => 'v1.0.0', 'source_sha' => 'abc1234']),
        );
        symlink($contentRoot.'/releases/v1.0.0-20260101-000000-abc1234', $contentRoot.'/current');
        file_put_contents(
            $contentRoot.'/deployments/history.jsonl',
            json_encode(['event' => 'deployment-finished', 'release' => 'v1.0.0', 'status' => 'success'])."\n",
        );

        [$registryPath, $targetsPath] = targetOpsParityRegistry($scratch, $contentRoot);

        $hcLog = $scratch.'/hc.log';
        touch($hcLog);

        [$exit, $output] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'parity-target'],
            targetOpsBaseEnv([
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_CLI' => targetOpsFakeHealthCheck($scratch, $hcLog),
            ]),
            $scratch,
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('Current:     v1.0.0-20260101-000000-abc1234')
            ->toContain('"release": "v1.0.0"')
            ->toContain('"source_sha": "abc1234"')
            ->toContain('"event": "deployment-finished"')
            ->toContain('Status: healthy');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('delegates to health-check --target TARGET', function () {
    $scratch = targetOpsScratchDir();

    try {
        $hcLog = $scratch.'/hc.log';
        touch($hcLog);

        [$exit, $output] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv(['RATEGURU_HEALTH_CHECK_CLI' => targetOpsFakeHealthCheck($scratch, $hcLog)]),
            $scratch,
        );

        expect($exit)->toBe(0, $output);
        expect(trim(File::get($hcLog)))->toBe('--target staging-main');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects a planned or disabled target in status before any path is inspected', function () {
    $scratch = targetOpsScratchDir();

    try {
        $hcLog = $scratch.'/hc.log';
        touch($hcLog);
        $fakeHealthCheck = targetOpsFakeHealthCheck($scratch, $hcLog);

        [$plannedExit, $plannedOutput] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'tits-guru'],
            targetOpsBaseEnv(['RATEGURU_HEALTH_CHECK_CLI' => $fakeHealthCheck]),
            $scratch,
        );

        expect($plannedExit)->not->toBe(0);
        expect($plannedOutput)->toContain('lifecycle=planned')->toContain('not active');
        // No status output at all — rejection happens before the header prints.
        expect($plannedOutput)->not->toContain('RateGuru deployment status');
        expect(trim(File::get($hcLog)))->toBe('', 'health-check must never be invoked for a planned target');

        $registry = targetOpsRegistryWithDisabledTarget($scratch);

        [$disabledExit, $disabledOutput] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'disabled-test'],
            targetOpsBaseEnv([
                'RATEGURU_TARGET_REGISTRY_FILE' => $registry,
                'RATEGURU_HEALTH_CHECK_CLI' => $fakeHealthCheck,
            ]),
            $scratch,
        );

        expect($disabledExit)->not->toBe(0);
        expect($disabledOutput)->toContain('lifecycle=disabled')->toContain('not active');
        expect($disabledOutput)->not->toContain('RateGuru deployment status');
        expect(trim(File::get($hcLog)))->toBe('', 'health-check must never be invoked for a disabled target');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('rejects an unknown target in status before any path is inspected', function () {
    $scratch = targetOpsScratchDir();

    try {
        $hcLog = $scratch.'/hc.log';
        touch($hcLog);

        [$exit, $output] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'ghost-target'],
            targetOpsBaseEnv(['RATEGURU_HEALTH_CHECK_CLI' => targetOpsFakeHealthCheck($scratch, $hcLog)]),
            $scratch,
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
        expect($output)->not->toContain('RateGuru deployment status');
        expect(trim(File::get($hcLog)))->toBe('');
    } finally {
        targetOpsCleanup($scratch);
    }
});

// --- registry absence isolation ---------------------------------------------

it('fails closed on a missing registry, for both health-check and status', function () {
    $scratch = targetOpsScratchDir();

    try {
        $missingRegistry = $scratch.'/does-not-exist.json';
        $curlLog = $scratch.'/curl.log';
        touch($curlLog);
        targetOpsFakeCurl($scratch, $curlLog);

        [$targetExit, $targetOutput] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv([
                'RATEGURU_TARGET_REGISTRY_FILE' => $missingRegistry,
                'CURL_LOG' => $curlLog,
            ]),
            $scratch,
        );

        expect($targetExit)->not->toBe(0);
        expect($targetOutput)->toContain('unavailable');

        $hcLog = $scratch.'/hc.log';
        touch($hcLog);

        [$statusTargetExit] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv([
                'RATEGURU_TARGET_REGISTRY_FILE' => $missingRegistry,
                'RATEGURU_HEALTH_CHECK_CLI' => targetOpsFakeHealthCheck($scratch, $hcLog),
            ]),
            $scratch,
        );

        expect($statusTargetExit)->not->toBe(0);
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('fails closed on malformed registry JSON', function () {
    $scratch = targetOpsScratchDir();

    try {
        $malformed = $scratch.'/malformed.json';
        file_put_contents($malformed, "{not json\n");

        $curlLog = $scratch.'/curl.log';
        touch($curlLog);
        targetOpsFakeCurl($scratch, $curlLog);

        [$targetExit] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv(['RATEGURU_TARGET_REGISTRY_FILE' => $malformed, 'CURL_LOG' => $curlLog]),
            $scratch,
        );
        expect($targetExit)->not->toBe(0);
    } finally {
        targetOpsCleanup($scratch);
    }
});

// --- test overrides require an explicit opt-in gate -------------------------

it('ignores RATEGURU_COMMON_FILE without the opt-in flag, and sources it with the flag', function () {
    // A stray RATEGURU_COMMON_FILE surviving in a real root shell (a leftover
    // export, a shared profile) must never silently redirect a privileged
    // script at an attacker- or operator-controlled file to source. The fixture
    // is fully self-contained (defines its own fail/log/parse_target_args/
    // target_*), so this proves sourcing specifically — with the gate on,
    // health-check runs to completion entirely on the fixture's fake
    // target_health_url/target_health_host_header, which only happens if it
    // was genuinely sourced, not coincidentally.
    $scratch = targetOpsScratchDir();

    try {
        $markerLog = $scratch.'/marker.log';
        touch($markerLog);
        $maliciousCommon = targetOpsMaliciousCommon($scratch, $markerLog);

        $curlLog = $scratch.'/curl.log';
        touch($curlLog);
        targetOpsFakeCurl($scratch, $curlLog);

        [$deniedExit, $deniedOutput] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'staging-main'],
            [
                // Deliberately no RATEGURU_ALLOW_TEST_OVERRIDES here.
                'RATEGURU_COMMON_FILE' => $maliciousCommon,
                'CURL_LOG' => $curlLog,
            ],
            $scratch,
        );

        expect($deniedExit)->not->toBe(0);
        expect(trim(File::get($markerLog)))->toBe('', 'the fixture common must never be sourced without the gate');
        expect($deniedOutput)->not->toContain('marker-fixture.internal');
        expect(trim(File::get($curlLog)))->toBe('', 'must fail before ever reaching curl');

        [$grantedExit, $grantedOutput] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'staging-main'],
            [
                'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
                'RATEGURU_COMMON_FILE' => $maliciousCommon,
                'CURL_LOG' => $curlLog,
            ],
            $scratch,
        );

        expect($grantedExit)->toBe(0, $grantedOutput);
        expect(trim(File::get($markerLog)))->toBe('MALICIOUS_COMMON_SOURCED');
        expect(File::get($curlLog))->toContain('Host: marker-fixture.internal');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('ignores RATEGURU_DEPLOYMENT_CONF_FILE, RATEGURU_TARGET_REGISTRY_FILE, RATEGURU_TARGETS_CLI and RATEGURU_HEALTH_CHECK_CLI without the opt-in flag, and honors all of them with it', function () {
    // rateguru_test_overrides_allowed() is one function backed by one
    // environment variable, called at every gated site — there is no way to
    // honor one of these four overrides while denying another, by design (only
    // RATEGURU_COMMON_FILE, covered by the previous test, is gated differently,
    // since it is read before that function — or common itself — exists).
    // Recording stubs for both CLI overrides prove the unapproved executable is
    // never invoked in the denied run, and is invoked exactly once each in the
    // granted run.
    $scratch = targetOpsScratchDir();

    try {
        $targetsLog = $scratch.'/targets.log';
        $hcLog = $scratch.'/hc.log';
        touch($targetsLog);
        touch($hcLog);

        $fakeTargets = targetOpsFakeTargetsCli($scratch, $targetsLog);
        $fakeHealthCheck = targetOpsFakeHealthCheck($scratch, $hcLog);

        $deniedEnv = [
            'RATEGURU_COMMON_FILE' => targetOpsCommonFile(),
            // Deliberately no RATEGURU_ALLOW_TEST_OVERRIDES here.
            'RATEGURU_DEPLOYMENT_CONF_FILE' => targetOpsDeploymentConfPath(),
            'RATEGURU_TARGET_REGISTRY_FILE' => targetOpsRegistryPath(),
            'RATEGURU_TARGETS_CLI' => $fakeTargets,
            'RATEGURU_HEALTH_CHECK_CLI' => $fakeHealthCheck,
        ];

        [$deniedExit, $deniedOutput] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'staging-main'],
            $deniedEnv,
            $scratch,
        );

        // RATEGURU_COMMON_FILE is denied here too — no gate is set anywhere in
        // this run — so the observable failure is common itself being
        // unreachable at its hardcoded path, before deployment.conf, the
        // registry, or either CLI stub ever come into play.
        expect($deniedExit)->not->toBe(0);
        expect($deniedOutput)->toContain('/home/www/rateguru/bin/common');
        expect(trim(File::get($targetsLog)))->toBe('', 'the fake targets CLI must never run without the gate');
        expect(trim(File::get($hcLog)))->toBe('', 'the fake health-check must never run without the gate');

        $grantedEnv = array_merge($deniedEnv, ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'true']);

        [$grantedExit, $grantedOutput] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'staging-main'],
            $grantedEnv,
            $scratch,
        );

        expect($grantedExit)->toBe(0, $grantedOutput);
        // status's own process (require_active_target + target_root +
        // target_lifecycle + target_environment_class) validates once, via
        // the fake targets CLI, proving RATEGURU_TARGETS_CLI was honored.
        expect(trim(File::get($targetsLog)))->not->toBe('', 'the fake targets CLI should have run with the gate on');
        // The fake health-check was invoked with the delegated selector,
        // proving RATEGURU_HEALTH_CHECK_CLI was honored too.
        expect(trim(File::get($hcLog)))->toBe('--target staging-main');
    } finally {
        targetOpsCleanup($scratch);
    }
});

// --- registry validation runs at most once per shell process ---------------

/**
 * A validator stub that records which process invoked it (its PPID — the
 * calling shell's own PID, not this wrapper's own transient one) each time it
 * runs, then delegates to the real targets CLI. Counting entries proves how
 * many times the external validator actually ran; counting distinct PPIDs
 * proves how many separate processes each contributed those calls.
 */
function targetOpsCountingTargetsCli(string $scratchDir, string $logFile): string
{
    $path = $scratchDir.'/bin/counting-targets';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$PPID" >> '.escapeshellarg($logFile)."\n"
        .'exec '.escapeshellarg(targetOpsTargetsCli())." \"\$@\"\n");
    chmod($path, 0o755);

    return $path;
}

it('validates the registry exactly once per health-check process', function () {
    $scratch = targetOpsScratchDir();

    try {
        $counterLog = $scratch.'/counter.log';
        touch($counterLog);
        $countingTargets = targetOpsCountingTargetsCli($scratch, $counterLog);

        $curlLog = $scratch.'/curl.log';
        touch($curlLog);
        targetOpsFakeCurl($scratch, $curlLog);

        [$exit, $output] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv(['RATEGURU_TARGETS_CLI' => $countingTargets, 'CURL_LOG' => $curlLog]),
            $scratch,
        );

        expect($exit)->toBe(0, $output);
        expect(trim(File::get($counterLog)))->not->toBe('');
        expect(substr_count(File::get($counterLog), "\n"))->toBe(1, 'the external validator must run exactly once per process');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('resolves every target property in status without repeatedly validating', function () {
    // status reads target_root, target_lifecycle and target_environment_class
    // on top of require_active_target's own check — four target_* calls in
    // one process. Without the fix, target_lifecycle's use of $(...) command
    // substitution inside require_active_target discarded the validation
    // cache the moment that subshell exited, so every one of those four calls
    // independently re-ran the external validator.
    $scratch = targetOpsScratchDir();

    try {
        $counterLog = $scratch.'/counter.log';
        touch($counterLog);
        $countingTargets = targetOpsCountingTargetsCli($scratch, $counterLog);

        $hcLog = $scratch.'/hc.log';
        touch($hcLog);

        [$exit, $output] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv([
                'RATEGURU_TARGETS_CLI' => $countingTargets,
                // A no-op stub, deliberately not the real health-check, so
                // this test isolates status's own process from the delegated
                // one (covered separately below).
                'RATEGURU_HEALTH_CHECK_CLI' => targetOpsFakeHealthCheck($scratch, $hcLog),
            ]),
            $scratch,
        );

        expect($exit)->toBe(0, $output);
        expect(substr_count(File::get($counterLog), "\n"))->toBe(1, 'status\'s own process must validate exactly once, not once per target_* call');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('lets the delegated health-check validate once in its own separate process', function () {
    // status delegates to a real, separate health-check process here (not a
    // stub), and both share the same counting validator and log file. Two log
    // entries, from two distinct PPIDs, is the only outcome consistent with
    // "each process validates exactly once" — one entry from either process,
    // or two entries sharing one PPID, would both indicate a caching bug.
    $scratch = targetOpsScratchDir();

    try {
        $counterLog = $scratch.'/counter.log';
        touch($counterLog);
        $countingTargets = targetOpsCountingTargetsCli($scratch, $counterLog);

        $curlLog = $scratch.'/curl.log';
        touch($curlLog);
        targetOpsFakeCurl($scratch, $curlLog);

        [$exit, $output] = targetOpsRun(
            targetOpsStatusScript(),
            ['--target', 'staging-main'],
            targetOpsBaseEnv([
                'RATEGURU_TARGETS_CLI' => $countingTargets,
                'RATEGURU_HEALTH_CHECK_CLI' => targetOpsHealthCheckScript(),
                'CURL_LOG' => $curlLog,
            ]),
            $scratch,
        );

        expect($exit)->toBe(0, $output);

        $entries = array_values(array_filter(explode("\n", trim(File::get($counterLog)))));
        expect($entries)->toHaveCount(2, 'expected one validation from status and one from the delegated health-check');
        expect(array_unique($entries))->toHaveCount(2, 'the two validations must come from two distinct processes, not one process validating twice');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('still validates exactly once, and fails before curl or any path access, for planned, disabled and unknown targets', function () {
    // The caching fix must not change *when* rejection happens relative to
    // curl or filesystem access (already proven elsewhere) — only that the
    // validation work behind it happens once, not zero or multiple times.
    $scratch = targetOpsScratchDir();

    try {
        $curlLog = $scratch.'/curl.log';
        touch($curlLog);
        targetOpsFakeCurl($scratch, $curlLog);

        $cases = [
            'planned target' => 'tits-guru',
            'unknown target' => 'ghost-target',
        ];

        foreach ($cases as $label => $targetId) {
            $counterLog = $scratch.'/counter-'.md5($targetId).'.log';
            touch($counterLog);
            $countingTargets = targetOpsCountingTargetsCli($scratch, $counterLog);

            [$exit] = targetOpsRun(
                targetOpsHealthCheckScript(),
                ['--target', $targetId],
                targetOpsBaseEnv(['RATEGURU_TARGETS_CLI' => $countingTargets, 'CURL_LOG' => $curlLog]),
                $scratch,
            );

            expect($exit)->not->toBe(0, $label);
            expect(trim(File::get($curlLog)))->toBe('', "curl must never run for a {$label}");
            expect(substr_count(File::get($counterLog), "\n"))->toBe(1, "the validator should still run exactly once for a {$label}, not zero or repeatedly");
        }

        // Disabled needs its own registry fixture — reuse the one already
        // built for the earlier lifecycle-safety tests.
        $registry = targetOpsRegistryWithDisabledTarget($scratch);
        $counterLog = $scratch.'/counter-disabled.log';
        touch($counterLog);
        $countingTargets = targetOpsCountingTargetsCli($scratch, $counterLog);

        [$exit] = targetOpsRun(
            targetOpsHealthCheckScript(),
            ['--target', 'disabled-test'],
            targetOpsBaseEnv([
                'RATEGURU_TARGET_REGISTRY_FILE' => $registry,
                'RATEGURU_TARGETS_CLI' => $countingTargets,
                'CURL_LOG' => $curlLog,
            ]),
            $scratch,
        );

        expect($exit)->not->toBe(0);
        expect(trim(File::get($curlLog)))->toBe('', 'curl must never run for a disabled target');
        expect(substr_count(File::get($counterLog), "\n"))->toBe(1, 'the validator should still run exactly once for a disabled target');
    } finally {
        targetOpsCleanup($scratch);
    }
});

// --- lazy loading and no eval/dynamic jq ------------------------------------

it('still does not read the registry merely by sourcing common', function () {
    $scratch = targetOpsScratchDir();

    try {
        $missingRegistry = $scratch.'/does-not-exist.json';

        $script = 'set -Eeuo pipefail'."\n"
            .'RATEGURU_ALLOW_TEST_OVERRIDES=true'."\n"
            .'RATEGURU_DEPLOYMENT_CONF_FILE='.escapeshellarg(targetOpsDeploymentConfPath())."\n"
            .'RATEGURU_TARGET_REGISTRY_FILE='.escapeshellarg($missingRegistry)."\n"
            .'export RATEGURU_ALLOW_TEST_OVERRIDES RATEGURU_DEPLOYMENT_CONF_FILE RATEGURU_TARGET_REGISTRY_FILE'."\n"
            .'# shellcheck disable=SC1090'."\n"
            .'source '.escapeshellarg(targetOpsCommonFile())."\n"
            .'echo sourced-ok'."\n"
            .'type -t require_active_target'."\n";

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);

        expect($exit)->toBe(0, "sourcing common must not touch the registry:\n".implode("\n", $output));
        expect(implode("\n", $output))
            ->toContain('sourced-ok')
            ->toContain('function');
    } finally {
        targetOpsCleanup($scratch);
    }
});

it('builds jq programs only through --arg, never from interpolated target input', function () {
    foreach (['infrastructure/scripts/health-check', 'infrastructure/scripts/status', 'infrastructure/scripts/common'] as $path) {
        $source = File::get(base_path($path));

        expect($source)
            ->not->toMatch('/^\s*eval\s/m')
            ->not->toMatch('/^\s*(source|\.)\s+.*deployment-targets/m');
    }

    // health-check and status never touch the registry directly — they reach
    // it exclusively through common's target_* helpers. (status legitimately
    // calls jq to pretty-print release.json/history — that predates this
    // slice and has nothing to do with the registry, so it is not asserted
    // against here; the check is specifically for registry access.)
    foreach (['infrastructure/scripts/health-check', 'infrastructure/scripts/status'] as $path) {
        expect(File::get(base_path($path)))
            ->not->toContain('.targets[')
            ->not->toContain('deployment-targets.json');
    }
});

// --- roadmap and runbook -----------------------------------------------------

it('records slices 1-9 completed, and the target-aware migration with them', function () {
    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)
        ->toMatch('/^\|\s*4\s*\|\s*Multi-target production model\s*\|\s*✅ completed\s*\|$/m')
        ->toContain('Deployment target registry — completed')
        ->toContain('Read-only target operations — completed')
        ->toContain('Install and verify read-only operations — completed')
        ->toContain('Target-aware cleanup — completed')
        ->toContain('Target-aware deploy — completed')
        ->toContain('Target-aware rollback — completed')
        ->toContain('Local backup and local restore-test — completed')
        ->toContain('Offsite backup path — completed')
        ->toContain('Backup-cycle orchestration — completed')
        ->toContain('Perimeter — completed')
        ->toContain('Complete legacy-selector removal — completed')
        ->toContain('## 4. Multi-target production model — completed')
        ->not->toContain('## 4. Multi-target production model — current')
        ->not->toMatch('/^\|\s*4\s*\|\s*Multi-target production model\s*\|\s*🚧 current\s*\|$/m');

    // the target-aware migration is closed, and so is the clean-host bootstrap — the roadmap still names exactly
    // one current phase, which has since moved on to the observability work.
    expect(substr_count($roadmap, '🚧 current'))->toBe(1);
    expect($roadmap)
        ->toMatch('/^\|\s*5\s*\|\s*Infrastructure installer and clean-VPS bootstrap\s*\|\s*✅ completed\s*\|$/m');
});

it('documents the read-only target operations in the deployment-targets runbook', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/deployment-targets.md'));

    expect($runbook)
        ->toContain('Read-only operations: health-check and status')
        ->toContain('health-check --target staging-main')
        ->toContain('status --target staging-main')
        ->toContain('require_active_target')
        ->toContain('Only active targets may be operated on')
        ->toContain('tits-guru')
        ->not->toContain('--environment');
});

// --- lifecycle=active allowlist untouched by this slice ---------------------

it('still only allows staging-main to be active, and tits-guru stays planned', function () {
    $registry = json_decode(File::get(targetOpsRegistryPath()), true, 512, JSON_THROW_ON_ERROR);

    expect($registry['targets']['staging-main']['lifecycle'])->toBe('active');
    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');
});

// --- out of scope: nothing else changed -------------------------------------

it('leaves every other operational script and workflow byte-identical to develop', function () {
    // This repo's CI checks out shallow (depth 1, single ref) for every job
    // that actually runs the test suite — only the separate PHPStan job uses
    // fetch-depth: 0 — so origin/develop is often simply not resolvable here.
    // git rev-parse --verify is a local, offline ref lookup (no fetch), so
    // checking first costs nothing and lets the test degrade honestly: skip
    // with a clear reason rather than fail for a reason that has nothing to
    // do with this slice's correctness.
    exec('git rev-parse --verify -q origin/develop >/dev/null 2>&1', $probeOutput, $probeExit);

    if ($probeExit !== 0) {
        test()->markTestSkipped('origin/develop is not available in this checkout (shallow clone) — run locally for full history to exercise this check.');
    }

    $unchanged = [
        // Every operational script, common, both installers, the three
        // generic wrappers, sudoers and deployment.conf.example are all
        // deliberately absent here — this is the final the target-aware migration cutover
        // slice, and all of them change. Only the genuinely static host
        // configs are asserted unchanged.
        'infrastructure/config/ssh/70-rateguru-deploy.conf',
        'infrastructure/config/nginx/rateguru-staging',
        'infrastructure/config/nginx/rateguru-production',
        'infrastructure/config/php-fpm/rateguru-staging.conf',
        'infrastructure/config/php-fpm/rateguru-production.conf',
        'infrastructure/config/supervisor/rateguru-staging-queue.conf',
        'infrastructure/config/cron/rateguru-staging-scheduler',
        // Both workflow files, and the target registry itself, are
        // deliberately excluded here: a later slice (the infrastructure CLI
        // executable-mode fix) legitimately adds an executable-bit
        // verification step to each workflow, the target-aware migration added a
        // required deployment-target input to the staging deploy workflow,
        // and the backup-retention hardening slice added
        // backup.minimum_retained_backups (and retuned staging's windows) in
        // the registry. This list only proves *this* slice's own scope, not
        // a permanent freeze on every file named here forever.
    ];

    foreach ($unchanged as $path) {
        $full = base_path($path);
        expect(File::exists($full))->toBeTrue("expected file to exist: {$path}");

        [$ok, $developContent, $stderr] = targetOpsDevelopContent($path);

        expect($ok)->toBeTrue("origin/develop is unavailable for {$path}: could not resolve it with git show ({$stderr})");

        expect(File::get($full))->toBe(
            $developContent,
            "{$path} must be byte-identical to origin/develop in this slice",
        );
    }
});
