<?php

use Illuminate\Support\Facades\File;

/**
 * the controlled code alignment: /usr/local/sbin/rateguru-restore — the ONE server-side perimeter
 * a restricted deploy credential can reach a restore through.
 *
 * The wrapper is deliberately not shaped like rateguru-deploy. That one
 * forwards every argument it does not recognize, because a deployment's flag
 * set is the deploying operation's business. A restore replaces a live
 * database and a live storage tree, so this one is a CLOSED WHITELIST: five
 * flags, each with a validated closed-class value, assembled into a fixed
 * argument vector. Nothing is passed through, so no future restore-target flag
 * becomes remotely reachable by accident.
 *
 * Every test below runs the REAL committed wrapper — never a reimplementation
 * — against a self-contained stub restore-target through the same gated
 * RATEGURU_* override contract every other operational script uses.
 */
function restoreWrapperPath(): string
{
    return base_path('infrastructure/config/wrappers/rateguru-restore');
}

function restoreWrapperScratch(): string
{
    $dir = sys_get_temp_dir().'/restore-wrapper-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

/** A stub restore-target that records the exact argv it was handed. */
function restoreWrapperStub(string $scratch): string
{
    $path = $scratch.'/bin/restore-target';

    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'printf "ARGV:"'."\n"
        .'for arg in "$@"; do printf " [%s]" "${arg}"; done'."\n"
        .'printf "\n"'."\n"
        // Proves env -i really scrubbed: nothing RATEGURU_* survives the exec.
        .'printf "LEAKED:%s\n" "$(env | grep -c "^RATEGURU_" || true)"'."\n"
        .'printf "PATH:%s\n" "${PATH}"'."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function restoreWrapperRun(string $scratch, array $arguments, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    $env = array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => base_path('infrastructure/scripts/common'),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => deploymentConfFixture($scratch),
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
        'RATEGURU_RESTORE_TARGET_BIN' => restoreWrapperStub($scratch),
    ], $envOverrides);

    // Sourced, not executed: the wrapper's own source guard means main() never
    // auto-runs, so the root gate can be bypassed for argument coverage. The
    // production root gate itself is proven separately below, against a real
    // non-root subprocess.
    $harness = $scratch.'/harness.sh';
    file_put_contents(
        $harness,
        "set -Eeuo pipefail\n"
            .'source '.escapeshellarg(restoreWrapperPath())."\n"
            ."require_root() { :; }\n"
            .'main '.implode(' ', array_map('escapeshellarg', $arguments))."\n",
    );

    return runInfraScript($harness, [], $env);
}

it('ships an executable, syntactically valid wrapper that names only the restore binary', function () {
    expect(File::exists(restoreWrapperPath()))->toBeTrue()
        ->and(is_executable(restoreWrapperPath()))->toBeTrue();

    exec('bash -n '.escapeshellarg(restoreWrapperPath()).' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));

    $source = File::get(restoreWrapperPath());

    expect($source)->toContain('RESTORE_TARGET_BIN_DEFAULT="/home/www/rateguru/bin/restore-target"');

    // Not a per-environment wrapper, and not a second implementation of
    // anything: one generic, target-aware perimeter.
    foreach ([
        'rateguru-restore-staging',
        'rateguru-restore-production',
        '--environment',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // No shell construction anywhere in executable code.
    $executable = executableSourceLines($source);

    foreach (['eval ', 'bash -c', 'sh -c', '$(cat'] as $forbidden) {
        expect($executable)->not->toContain($forbidden);
    }
});

it('requires root for a real invocation, before anything else runs', function () {
    $scratch = restoreWrapperScratch();

    try {
        // A genuine subprocess, so BASH_SOURCE[0] == $0 and the inline EUID
        // gate at the top of the file fires — before common is even sourced.
        [$exit, $output] = runInfraScript(restoreWrapperPath(), ['--help'], [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
        ]);

        if (getmyuid() === 0) {
            expect($exit)->toBe(0, $output);

            return;
        }

        expect($exit)->not->toBe(0);
        expect($output)->toContain('must be executed as root');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('builds the exact restore-target command for each mode, and forwards nothing else', function (
    array $arguments,
    string $expected,
) {
    $scratch = restoreWrapperScratch();

    try {
        [$exit, $output] = restoreWrapperRun($scratch, $arguments);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain($expected);

        // env -i really scrubbed: not one RATEGURU_* variable survived into
        // the operation, and PATH is the fixed production one.
        expect($output)->toContain('LEAKED:0');
        expect($output)->toContain('PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'apply' => [
        ['--apply', '--target', 'parity-target', '--source', 'offsite', '--backup', '20260115-120000'],
        'ARGV: [--apply] [--target] [parity-target] [--source] [offsite] [--backup] [20260115-120000]',
    ],
    'apply from local' => [
        ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000'],
        'ARGV: [--apply] [--target] [parity-target] [--source] [local] [--backup] [20260115-120000]',
    ],
    'inspect' => [
        ['--inspect', '--target', 'parity-target', '--operation', '20260115-120000-abcdef'],
        'ARGV: [--inspect] [--target] [parity-target] [--operation] [20260115-120000-abcdef]',
    ],
    'resume' => [
        ['--resume', '--target', 'parity-target', '--operation', '20260115-120000-abcdef'],
        'ARGV: [--resume] [--target] [parity-target] [--operation] [20260115-120000-abcdef]',
    ],
]);

it('refuses everything outside the restore-target contract', function (array $arguments, string $expected) {
    $scratch = restoreWrapperScratch();

    try {
        [$exit, $output] = restoreWrapperRun($scratch, $arguments);

        expect($exit)->not->toBe(0, $output);
        expect($output)->toContain($expected);

        // Nothing was ever executed.
        expect($output)->not->toContain('ARGV:');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'no mode' => [
        ['--target', 'parity-target'],
        'exactly one of --apply, --inspect or --resume is required',
    ],
    'two modes' => [
        ['--apply', '--resume', '--target', 'parity-target'],
        'only one of --apply, --inspect or --resume may be given',
    ],
    'no target' => [
        ['--apply', '--source', 'local', '--backup', '20260115-120000'],
        '--target is required',
    ],
    'duplicate target' => [
        ['--apply', '--target', 'a', '--target', 'b'],
        '--target given more than once',
    ],
    'equals form' => [
        ['--apply', '--target=parity-target'],
        "must be given as '--target VALUE'",
    ],
    'apply without a source' => [
        ['--apply', '--target', 'parity-target', '--backup', '20260115-120000'],
        '--source is required with --apply',
    ],
    'apply without a backup' => [
        ['--apply', '--target', 'parity-target', '--source', 'local'],
        "there is no 'latest'",
    ],
    'apply with an operation' => [
        ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000', '--operation', '20260115-120000-abcdef'],
        '--operation is only valid with --inspect or --resume',
    ],
    'unknown source' => [
        ['--apply', '--target', 'parity-target', '--source', 'anywhere', '--backup', '20260115-120000'],
        '--source must be local or offsite',
    ],
    'malformed backup' => [
        ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '2026-01-15'],
        'invalid backup ID',
    ],
    'path-shaped backup' => [
        ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '../../etc/passwd'],
        'invalid backup ID',
    ],
    'resume without an operation' => [
        ['--resume', '--target', 'parity-target'],
        '--resume requires --operation',
    ],
    'inspect without an operation' => [
        ['--inspect', '--target', 'parity-target'],
        '--inspect requires --operation',
    ],
    'malformed operation' => [
        ['--inspect', '--target', 'parity-target', '--operation', 'yesterday'],
        'invalid restore operation ID',
    ],
    'path-shaped operation' => [
        ['--resume', '--target', 'parity-target', '--operation', '../../../root'],
        'invalid restore operation ID',
    ],
    'resume with a backup' => [
        ['--resume', '--target', 'parity-target', '--operation', '20260115-120000-abcdef', '--backup', '20260115-120000'],
        '--backup is only valid with --apply',
    ],
    'resume with a source' => [
        ['--resume', '--target', 'parity-target', '--operation', '20260115-120000-abcdef', '--source', 'local'],
        '--source is only valid with --apply',
    ],
    // The whole point of a closed whitelist: nothing is forwarded, so a flag
    // restore-target does not have (and one it does) are both rejected here.
    'unknown flag' => [
        ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000', '--force'],
        'unknown rateguru-restore argument: --force',
    ],
    'bare positional' => [
        ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000', '/etc/passwd'],
        'unknown rateguru-restore argument: /etc/passwd',
    ],
    'shell fragment' => [
        ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000; rm -rf /'],
        'invalid backup ID',
    ],
]);

it('refuses a planned target before the operation is ever reached', function () {
    $scratch = restoreWrapperScratch();

    try {
        [$exit, $output] = restoreWrapperRun($scratch, [
            '--inspect', '--target', 'planned-target', '--operation', '20260115-120000-abcdef',
        ]);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('lifecycle=planned');
        expect($output)->not->toContain('ARGV:');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('authorizes only the target own registered deploy user', function () {
    $scratch = restoreWrapperScratch();

    try {
        $arguments = ['--inspect', '--target', 'parity-target', '--operation', '20260115-120000-abcdef'];

        // The registry declares parity-deploy as this target's deploy user.
        [$authorizedExit, $authorizedOutput] = restoreWrapperRun($scratch, $arguments, [
            'SUDO_USER' => 'parity-deploy',
        ]);

        expect($authorizedExit)->toBe(0, $authorizedOutput);
        expect($authorizedOutput)->toContain('ARGV:');

        [$rejectedExit, $rejectedOutput] = restoreWrapperRun($scratch, $arguments, [
            'SUDO_USER' => 'someone-else',
        ]);

        expect($rejectedExit)->not->toBe(0);
        expect($rejectedOutput)->toContain('deploy user someone-else is not authorized for target parity-target');
        expect($rejectedOutput)->not->toContain('ARGV:');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('grants the restricted deploy account the wrapper and never the binary behind it', function () {
    $sudoers = File::get(base_path('infrastructure/config/sudoers/rateguru-deploy'));

    expect($sudoers)
        ->toContain('/usr/local/sbin/rateguru-deploy')
        ->toContain('/usr/local/sbin/rateguru-rollback')
        ->toContain('/usr/local/sbin/rateguru-cleanup')
        ->toContain('/usr/local/sbin/rateguru-restore');

    // No shell, no arbitrary command, no direct path to an operational binary,
    // and no wildcard: a grant naming restore-target directly would let the
    // deploy account call it with arguments the wrapper exists to reject.
    foreach ([
        '/home/www/rateguru/bin/',
        '/bin/bash',
        '/bin/sh',
        'ALL=(ALL)',
        'NOPASSWD: ALL',
        '/usr/local/sbin/*',
    ] as $forbidden) {
        expect($sudoers)->not->toContain($forbidden);
    }

    // Still staging-only: tits-guru stays lifecycle=planned and its deploy
    // user gains nothing here.
    expect($sudoers)->toContain('deploy-rateguru-staging')
        ->not->toContain('deploy-rateguru-tits-guru')
        ->not->toContain('deploy-rateguru-production');
});

it('installs the wrapper and its sudo grant through the existing perimeter installer', function () {
    $installer = File::get(base_path('infrastructure/scripts/install-target-perimeter'));

    // Extended, never duplicated: there is exactly one installer that owns
    // /usr/local/sbin wrappers and the sudoers rule, and a clean-host bootstrap
    // therefore gets the restore perimeter with no manual step.
    expect($installer)
        ->toContain('SRC_WRAPPER_RESTORE="${REPO_ROOT}/infrastructure/config/wrappers/rateguru-restore"')
        ->toContain('DST_WRAPPER_RESTORE="${PERIMETER_ROOT}/usr/local/sbin/rateguru-restore"')
        ->toContain('install -m 0700 "${SRC_WRAPPER_RESTORE}" "${STAGE_DIR}/rateguru-restore"')
        ->toContain('install_regular_file_transactional "${STAGE_DIR}/rateguru-restore" "${DST_WRAPPER_RESTORE}"')
        ->toContain('verify_wrapper_static_contract "${DST_WRAPPER_RESTORE}" "/home/www/rateguru/bin/restore-target" "installed rateguru-restore"')
        ->toContain('verify_wrapper_help "${DST_WRAPPER_RESTORE}" "rateguru-restore"')
        ->toContain('sudoers file does not grant access to rateguru-restore');

    // And the restore primitive the wrapper needs is now part of the bundle
    // staleness guard: a host whose operations bundle predates restore-target
    // must not be told its restore perimeter is working.
    expect($installer)
        ->toContain('DST_OPS_RESTORE_TARGET="${INSTALLED_OPERATIONS_ROOT}/home/www/rateguru/bin/restore-target"')
        ->toContain('check_installed_operation_file "${DST_OPS_RESTORE_TARGET}" "${SRC_RESTORE_TARGET}" "${OPS_CLI_MODE}" "restore-target"');

    // No second installer appeared alongside it.
    foreach ([
        'infrastructure/scripts/install-restore-perimeter',
        'infrastructure/scripts/install-target-restore',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} would be a second perimeter installer");
    }
});
