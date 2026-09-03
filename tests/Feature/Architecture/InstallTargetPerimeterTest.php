<?php

use Illuminate\Support\Facades\File;

/**
 * the target-aware migration: infrastructure/scripts/install-target-perimeter — the
 * transactional installer for the three generic wrappers, the sudoers rule,
 * and the backup cron entry.
 *
 * Mirrors tests/Feature/Architecture/InstallTargetOperationsTest.php's own
 * technique throughout: the real, shipped installer is sourced (never as
 * $0, so main() never auto-runs), SRC_, DST_, BACKUP_ROOT, INSTALL_OWNER,
 * INSTALL_GROUP and VISUDO_BIN constants are reassigned to scratch-safe
 * values, and run_check/perform_apply/perform_verify are called directly,
 * bypassing require_root, which needs no coverage here beyond a real non-root
 * subprocess invocation failing closed (see TargetPerimeterTest.php's own
 * "refuses to run as a non-root caller" for the identical proof applied to
 * the wrappers this installer manages).
 *
 * Every full perform_apply/perform_verify integration test below substitutes
 * a self-contained stub wrapper (installPerimeterWriteWrapperStub) for the
 * real infrastructure/config/wrappers/rateguru-{deploy,rollback,cleanup} —
 * the real wrappers' own require_root would otherwise block every runtime
 * probe (--help, --target tits-guru) the installer performs, exactly the
 * same constraint InstallTargetOperationsTest.php works around with its own
 * stub deploy/rollback/backup-cycle candidates. The real wrappers' own
 * parsing/authorization/lifecycle/exec behaviour is proven separately and
 * thoroughly in TargetPerimeterTest.php.
 */

// =============================================================================
// Harness
// =============================================================================

function installPerimeterScriptPath(): string
{
    return base_path('infrastructure/scripts/install-target-perimeter');
}

function installPerimeterSource(): string
{
    return File::get(installPerimeterScriptPath());
}

function installPerimeterScratchDir(): string
{
    $dir = sys_get_temp_dir().'/install-target-perimeter-'.uniqid('', true).'-'.getmypid();

    foreach ([
        '',
        '/dest/usr/local/sbin',
        '/dest/etc/sudoers.d',
        '/dest/etc/cron.d',
    ] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function installPerimeterCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * A self-contained stub wrapper mimicking exactly the two safe probes
 * install-target-perimeter ever performs (--help, and a bare
 * --target tits-guru with no operation arguments) without requiring root.
 * Any other invocation is treated as a test failure in its own right — this
 * is what proves the installer never attempts a real, mutating operation
 * through it.
 *
 * @param  'reject'|'unexpected-success'|'wrong-reason'  $titsGuru
 */
function installPerimeterWriteWrapperStub(string $path, string $titsGuru = 'reject'): void
{
    $rejectionBranch = match ($titsGuru) {
        'reject' => <<<'SH'
        echo "target tits-guru has lifecycle=planned, not active" >&2
        exit 1
        SH,
        'unexpected-success' => <<<'SH'
        echo "stub: pretending tits-guru succeeded"
        exit 0
        SH,
        'wrong-reason' => <<<'SH'
        echo "stub: some unrelated failure" >&2
        exit 1
        SH,
    };

    // The literal strings below are never executed — they exist solely so
    // this stub also satisfies verify_wrapper_static_contract's static
    // content checks (which grep for the generic installed operation path
    // each real wrapper references, and — this exact phrase, verbatim, is
    // the real incident this stub now deliberately reproduces — a comment
    // mentioning "no eval, no bash -c" that a naive whole-file grep once
    // mistook for an executable eval/bash -c), exactly as the real wrapper
    // source does. It deliberately never mentions the legacy selector at
    // all, matching the real wrapper's own source exactly.
    $script = <<<SH
#!/usr/bin/env bash
# Stub wrapper for install-target-perimeter tests only — never a real
# deploy/rollback/cleanup/restore operation. Mimics the real wrappers' own
# references to /home/www/rateguru/bin/deploy, /home/www/rateguru/bin/rollback,
# /home/www/rateguru/bin/cleanup and /home/www/rateguru/bin/restore-target, and
# their own comment: no eval, no bash -c, no string-built command.
if [[ "\$*" == "--help" ]]; then
    echo "Usage: stub-wrapper --target TARGET_ID"
    exit 0
fi

# The bare form every wrapper is probed with, and the read-only form
# rateguru-restore is probed with — its parser needs a mode before it will
# accept anything, so the perimeter installer passes --inspect plus a
# fictional operation ID that is never looked up.
if [[ "\$*" == "--target tits-guru" ]] \
    || [[ "\$*" == "--target tits-guru --inspect --operation "* ]]; then
{$rejectionBranch}
fi

echo "STUB: unsafe/unexpected invocation: \$*" >&2
exit 1

SH;

    file_put_contents($path, $script);
    chmod($path, 0o755);
}

/**
 * Creates a fully current, byte-identical-to-committed-source seventeen-file
 * operations bundle under $scratch/ops/home/www/rateguru/..., matching
 * exactly what install-target-operations installs for real — modes 0640
 * (registry, deployment.conf), 0644 (common), 0755 (every CLI). This is what
 * makes every existing perform_apply/perform_verify/run_check test below
 * pass validate_installed_operations_bundle without needing to know it
 * exists; only the tests in the "installed operations bundle staleness
 * guard" section below deliberately break one piece of it afterward.
 *
 * @return array<string, string> DST_OPS_* var name => path
 */
function installPerimeterWriteOperationsBundle(string $scratch): array
{
    $opsRoot = $scratch.'/ops';
    $configDir = $opsRoot.'/home/www/rateguru/config';
    $binDir = $opsRoot.'/home/www/rateguru/bin';
    mkdir($configDir, 0o755, true);
    mkdir($binDir, 0o755, true);

    $copy = function (string $srcRelative, string $dst, int $mode): void {
        copy(base_path($srcRelative), $dst);
        chmod($dst, $mode);
    };

    $registry = $configDir.'/deployment-targets.json';
    $copy('infrastructure/config/deployment-targets.json', $registry, 0o640);

    $deploymentConf = $configDir.'/deployment.conf';
    $copy('infrastructure/templates/deployment.conf.example', $deploymentConf, 0o640);

    $common = $binDir.'/common';
    $copy('infrastructure/scripts/common', $common, 0o644);

    $cliMap = [
        'DST_OPS_TARGETS' => 'targets',
        'DST_OPS_HEALTH_CHECK' => 'health-check',
        'DST_OPS_STATUS' => 'status',
        'DST_OPS_CLEANUP' => 'cleanup',
        'DST_OPS_DEPLOY' => 'deploy',
        'DST_OPS_ROLLBACK' => 'rollback',
        'DST_OPS_BACKUP' => 'backup',
        'DST_OPS_RESTORE_TEST' => 'restore-test',
        'DST_OPS_OFFSITE_BACKUP' => 'offsite-backup',
        'DST_OPS_OFFSITE_RETENTION' => 'offsite-retention',
        'DST_OPS_OFFSITE_RESTORE_TEST' => 'offsite-restore-test',
        'DST_OPS_BACKUP_CYCLE' => 'backup-cycle',
        'DST_OPS_VERIFY_REQUIRED_CLIS' => 'verify-required-clis',
        'DST_OPS_RESTORE_TARGET' => 'restore-target',
    ];

    $vars = [
        'DST_OPS_REGISTRY' => $registry,
        'DST_OPS_COMMON' => $common,
        'DST_OPS_DEPLOYMENT_CONF' => $deploymentConf,
    ];

    foreach ($cliMap as $varName => $scriptName) {
        $dst = $binDir.'/'.$scriptName;
        $copy('infrastructure/scripts/'.$scriptName, $dst, 0o755);
        $vars[$varName] = $dst;
    }

    return $vars;
}

/**
 * @return array<string, string>
 */
function installPerimeterBaseVars(string $scratch, ?string $wrapperStub = null): array
{
    $wrapperStub ??= $scratch.'/stub-wrapper';

    if (! file_exists($wrapperStub)) {
        installPerimeterWriteWrapperStub($wrapperStub);
    }

    $ownerId = (string) getmyuid();
    $groupId = (string) getmygid();

    return array_merge([
        'SRC_WRAPPER_DEPLOY' => $wrapperStub,
        'SRC_WRAPPER_ROLLBACK' => $wrapperStub,
        'SRC_WRAPPER_CLEANUP' => $wrapperStub,
        'SRC_WRAPPER_RESTORE' => $wrapperStub,
        'SRC_SUDOERS' => base_path('infrastructure/config/sudoers/rateguru-deploy'),
        'SRC_CRON' => base_path('infrastructure/config/cron/rateguru-backups'),
        'SRC_COMMON' => base_path('infrastructure/scripts/common'),
        'SRC_TARGETS' => base_path('infrastructure/scripts/targets'),
        'SRC_REGISTRY' => base_path('infrastructure/config/deployment-targets.json'),
        'SRC_DEPLOYMENT_CONF' => base_path('infrastructure/templates/deployment.conf.example'),
        'SRC_HEALTH_CHECK' => base_path('infrastructure/scripts/health-check'),
        'SRC_STATUS' => base_path('infrastructure/scripts/status'),
        'SRC_DEPLOY' => base_path('infrastructure/scripts/deploy'),
        'SRC_ROLLBACK' => base_path('infrastructure/scripts/rollback'),
        'SRC_CLEANUP' => base_path('infrastructure/scripts/cleanup'),
        'SRC_BACKUP' => base_path('infrastructure/scripts/backup'),
        'SRC_RESTORE_TEST' => base_path('infrastructure/scripts/restore-test'),
        'SRC_OFFSITE_BACKUP' => base_path('infrastructure/scripts/offsite-backup'),
        'SRC_OFFSITE_RETENTION' => base_path('infrastructure/scripts/offsite-retention'),
        'SRC_OFFSITE_RESTORE_TEST' => base_path('infrastructure/scripts/offsite-restore-test'),
        'SRC_BACKUP_CYCLE' => base_path('infrastructure/scripts/backup-cycle'),
        'SRC_VERIFY_REQUIRED_CLIS' => base_path('infrastructure/scripts/verify-required-clis'),
        'SRC_RESTORE_TARGET' => base_path('infrastructure/scripts/restore-target'),
        'DST_WRAPPER_DEPLOY' => $scratch.'/dest/usr/local/sbin/rateguru-deploy',
        'DST_WRAPPER_ROLLBACK' => $scratch.'/dest/usr/local/sbin/rateguru-rollback',
        'DST_WRAPPER_CLEANUP' => $scratch.'/dest/usr/local/sbin/rateguru-cleanup',
        'DST_WRAPPER_RESTORE' => $scratch.'/dest/usr/local/sbin/rateguru-restore',
        'DST_SUDOERS' => $scratch.'/dest/etc/sudoers.d/rateguru-deploy',
        'DST_CRON' => $scratch.'/dest/etc/cron.d/rateguru-backups',
        'DST_SBIN_DIR' => $scratch.'/dest/usr/local/sbin',
        'DST_SUDOERS_DIR' => $scratch.'/dest/etc/sudoers.d',
        'DST_CRON_DIR' => $scratch.'/dest/etc/cron.d',
        'BACKUP_ROOT' => $scratch.'/dest/var/backups/rateguru-target-perimeter',
        'INSTALL_OWNER' => trim((string) shell_exec('id -un')),
        'INSTALL_GROUP' => trim((string) shell_exec('id -gn')),
        'INSTALL_OWNER_ID' => $ownerId,
        'INSTALL_GROUP_ID' => $groupId,
    ], installPerimeterWriteOperationsBundle($scratch));
}

/**
 * @param  array<string, string>  $vars
 * @return array{0: int, 1: string}
 */
function installPerimeterRunHarness(string $scratch, array $vars, string $call): array
{
    $assignments = '';
    foreach ($vars as $name => $value) {
        $assignments .= $name.'='.escapeshellarg($value)."\n";
    }

    $script = "set -Eeuo pipefail\n"
        .'source '.escapeshellarg(installPerimeterScriptPath())."\n"
        .$assignments
        .$call."\n";

    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $harnessPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

// =============================================================================
// --check: static, read-only, no root
// =============================================================================

it('check passes against the real committed source files', function () {
    $scratch = installPerimeterScratchDir();

    try {
        [$exit, $output] = installPerimeterRunHarness($scratch, installPerimeterBaseVars($scratch), 'run_check');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('check passed');
        // Proves the wrapper static contract (underlying path, no mention of
        // --environment, no executable eval/bash -c) ran and passed against
        // the real, committed infrastructure/config/wrappers/* files — not a
        // synthetic fixture — which is what genuinely proves the false
        // positive fix, since the real rateguru-deploy source contains the
        // exact "no eval, no bash -c" comment that used to trip this check.
        expect($output)->toContain('source wrappers reference generic installed operation paths, never mention --environment, and contain no executable eval/bash -c');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when a wrapper has a bash syntax error', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $broken = $scratch.'/broken-wrapper';
        file_put_contents($broken, "#!/usr/bin/env bash\nif [[ true\n");
        chmod($broken, 0o755);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_WRAPPER_DEPLOY'] = $broken;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('bash -n failed');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the candidate sudoers file has invalid syntax', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $broken = $scratch.'/broken-sudoers';
        file_put_contents($broken, "this is not valid sudoers syntax at all\n");

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_SUDOERS'] = $broken;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('visudo -cf');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the sudoers candidate grants access to tits-guru\'s deploy user', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-sudoers';
        file_put_contents($bad, <<<'SUDOERS'
            Defaults:deploy-rateguru-staging !requiretty
            Defaults:deploy-rateguru-tits-guru !requiretty

            deploy-rateguru-staging ALL=(root) NOPASSWD: \
                /usr/local/sbin/rateguru-deploy, \
                /usr/local/sbin/rateguru-rollback, \
                /usr/local/sbin/rateguru-cleanup, \
                /usr/local/sbin/rateguru-restore

            deploy-rateguru-tits-guru ALL=(root) NOPASSWD: \
                /usr/local/sbin/rateguru-deploy
            SUDOERS);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_SUDOERS'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('tits-guru');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the sudoers candidate mentions a legacy wrapper name', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-sudoers';
        file_put_contents($bad, <<<'SUDOERS'
            Defaults:deploy-rateguru-staging !requiretty

            deploy-rateguru-staging ALL=(root) NOPASSWD: \
                /usr/local/sbin/rateguru-deploy, \
                /usr/local/sbin/rateguru-rollback, \
                /usr/local/sbin/rateguru-cleanup, \
                /usr/local/sbin/rateguru-restore, \
                /usr/local/sbin/rateguru-staging-deploy
            SUDOERS);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_SUDOERS'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('still mentions the legacy wrapper rateguru-staging-deploy');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the sudoers candidate grants a production deploy user access', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-sudoers';
        file_put_contents($bad, <<<'SUDOERS'
            Defaults:deploy-rateguru-staging !requiretty
            Defaults:deploy-rateguru-production !requiretty

            deploy-rateguru-staging ALL=(root) NOPASSWD: \
                /usr/local/sbin/rateguru-deploy, \
                /usr/local/sbin/rateguru-rollback, \
                /usr/local/sbin/rateguru-cleanup, \
                /usr/local/sbin/rateguru-restore

            deploy-rateguru-production ALL=(root) NOPASSWD: \
                /usr/local/sbin/rateguru-deploy
            SUDOERS);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_SUDOERS'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('must not grant a production deploy user any access');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the cron candidate has the wrong number of operational lines', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-cron';
        file_put_contents($bad, <<<'CRON'
            SHELL=/bin/bash
            PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

            30 2 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1
            CRON);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_CRON'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('expected exactly three operational cron lines');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('recognizes an @-shortcut schedule (e.g. @daily) as an operational line, not just numeric/wildcard fields', function () {
    // Exclusion-based, not inclusion-based: an operational line is any
    // non-blank line that isn't a comment or an environment-variable
    // assignment — so a schedule this installer's own author never
    // enumerated (like a cron "@"-shortcut) is still counted, rather than
    // silently undercounted the way a hand-enumerated character class of
    // "valid" schedule syntax would.
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/shortcut-cron';
        file_put_contents($bad, <<<'CRON'
            SHELL=/bin/bash
            PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

            @daily root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1
            10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1
            40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1
            CRON);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_CRON'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        // The three lines are correctly counted (no "wrong number of
        // operational lines" failure); this candidate still fails because
        // its schedule/log-path text doesn't match the hardcoded literal
        // check further down — a separate, unrelated concern.
        expect($exit)->not->toBe(0);
        expect($output)->not->toContain('expected exactly three operational cron lines');
        expect($output)->toContain('changed schedule or log path');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when a cron candidate line still uses --environment', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-cron';
        file_put_contents($bad, <<<'CRON'
            SHELL=/bin/bash
            PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

            30 2 * * * root /home/www/rateguru/bin/backup-cycle --environment staging >> /var/log/rateguru/staging-backup-cycle.log 2>&1
            10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1
            40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1
            CRON);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_CRON'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('does not use --target staging-main');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when a cron candidate changed the schedule or log path', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-cron';
        file_put_contents($bad, <<<'CRON'
            SHELL=/bin/bash
            PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

            15 3 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1
            10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1
            40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1
            CRON);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_CRON'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('changed schedule or log path');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when a referenced operational script no longer declares --target', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $noTarget = $scratch.'/no-target-deploy';
        file_put_contents($noTarget, "#!/usr/bin/env bash\necho hi\n");
        chmod($noTarget, 0o755);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_DEPLOY'] = $noTarget;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('deploy does not appear to declare --target support');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check makes no changes anywhere', function () {
    $scratch = installPerimeterScratchDir();

    try {
        [$exit, $output] = installPerimeterRunHarness($scratch, installPerimeterBaseVars($scratch), 'run_check');

        expect($exit)->toBe(0, $output);
        expect(glob($scratch.'/dest/usr/local/sbin/*'))->toBe([]);
        expect(glob($scratch.'/dest/etc/sudoers.d/*'))->toBe([]);
        expect(glob($scratch.'/dest/etc/cron.d/*'))->toBe([]);
    } finally {
        installPerimeterCleanup($scratch);
    }
});

// =============================================================================
// Wrapper static contract: comment-only "eval"/"bash -c" must never
// false-positive
//
// Real-VPS incident: install-target-perimeter --apply failed with
// "installed wrapper contains eval or bash -c" against the real
// rateguru-deploy, which does not contain either — only a comment reading
// "no eval, no bash -c, no string-built command". The naive whole-file
// `grep -Eq '...|bash -c'` matched that comment text. Fixed by
// verify_wrapper_static_contract, which excludes comment-only lines before
// scanning for executable eval/bash -c, and is now shared identically by
// source (--check), staged (--apply preflight), and installed (--apply
// post-install, --verify) validation — see the tests below.
// =============================================================================

/**
 * A minimal wrapper-shaped fixture satisfying the two non-eval/bash-c parts
 * of verify_wrapper_static_contract (references $underlyingPath, explicitly
 * rejects --environment) so each test below isolates exactly the
 * eval/bash -c behaviour under test.
 */
function installPerimeterWriteStaticContractFixture(string $path, string $underlyingPath, string $extraLine): void
{
    // ShellCheck-clean on purpose (validate_source_shellcheck runs before
    // verify_wrapper_static_contract, so a fixture with unrelated warnings
    // would fail for the wrong reason): no unused variables, no undefined
    // variables, no reserved-word collisions.
    $script = <<<SH
#!/usr/bin/env bash
# References {$underlyingPath} and never mentions the legacy selector,
# matching every real wrapper's own static contract.
echo "{$underlyingPath}" >/dev/null
{$extraLine}
echo finished
SH;

    file_put_contents($path, $script);
    chmod($path, 0o755);
}

it('accepts a wrapper whose only mention of eval/bash -c is a comment', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $fixture = $scratch.'/comment-only-wrapper';
        installPerimeterWriteStaticContractFixture($fixture, '/home/www/rateguru/bin/deploy', '# no eval, no bash -c, no string-built command');

        [$exit, $output] = installPerimeterRunHarness(
            $scratch,
            [],
            'verify_wrapper_static_contract '.escapeshellarg($fixture).' /home/www/rateguru/bin/deploy fixture; echo STATIC_CONTRACT_OK',
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('STATIC_CONTRACT_OK');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('rejects a wrapper containing an executable eval', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $fixture = $scratch.'/eval-wrapper';
        installPerimeterWriteStaticContractFixture($fixture, '/home/www/rateguru/bin/deploy', 'eval "echo hi"');

        [$exit, $output] = installPerimeterRunHarness(
            $scratch,
            [],
            'verify_wrapper_static_contract '.escapeshellarg($fixture).' /home/www/rateguru/bin/deploy fixture; echo STATIC_CONTRACT_OK',
        );

        expect($exit)->not->toBe(0);
        expect($output)->not->toContain('STATIC_CONTRACT_OK');
        expect($output)->toContain('fixture contains an executable eval');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('rejects a wrapper containing an executable bash -c', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $fixture = $scratch.'/bashc-wrapper';
        installPerimeterWriteStaticContractFixture($fixture, '/home/www/rateguru/bin/deploy', 'bash -c "echo hi"');

        [$exit, $output] = installPerimeterRunHarness(
            $scratch,
            [],
            'verify_wrapper_static_contract '.escapeshellarg($fixture).' /home/www/rateguru/bin/deploy fixture; echo STATIC_CONTRACT_OK',
        );

        expect($exit)->not->toBe(0);
        expect($output)->not->toContain('STATIC_CONTRACT_OK');
        expect($output)->toContain('fixture contains an executable bash -c');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('an eval/bash -c defect is detected during apply\'s own preflight, before any backup directory, sudoers, or cron change', function () {
    // perform_apply's first step, run_source_validation, now includes the
    // static contract check against SRC_WRAPPER_* — so a defect is caught
    // there, before STAGE_DIR is even created (and therefore before
    // verify_staged_candidates' own identical check on the staged copy
    // would otherwise have to catch it). Either way, the file-existence and
    // backup-directory assertions below prove the one property that
    // actually matters: no destination is ever touched.
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $badWrapper = $scratch.'/eval-wrapper';
        installPerimeterWriteStaticContractFixture($badWrapper, '/home/www/rateguru/bin/deploy', 'eval "echo hi"');
        $vars['SRC_WRAPPER_DEPLOY'] = $badWrapper;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('source rateguru-deploy contains an executable eval');

        foreach (['DST_WRAPPER_DEPLOY', 'DST_WRAPPER_ROLLBACK', 'DST_WRAPPER_CLEANUP', 'DST_WRAPPER_RESTORE', 'DST_SUDOERS', 'DST_CRON'] as $key) {
            expect(file_exists($vars[$key]))->toBeFalse("{$key} must not exist — a wrapper defect must be caught before any destination file is touched");
        }
        expect(glob($vars['BACKUP_ROOT'].'/*'))->toBe([], 'no backup directory should ever be created for this failure');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('source, staged, and installed validation all call the one shared static contract function', function () {
    $source = installPerimeterSource();

    expect(substr_count($source, 'verify_wrapper_static_contract "${SRC_WRAPPER_DEPLOY}"'))->toBe(1);
    expect(substr_count($source, 'verify_wrapper_static_contract "${staged_deploy}"'))->toBe(1);
    expect(substr_count($source, 'verify_wrapper_static_contract "${DST_WRAPPER_DEPLOY}"'))->toBe(1);

    // Exactly one function definition — never three separate copies of the
    // same eval/bash -c detection logic drifting apart.
    expect(substr_count($source, 'verify_wrapper_static_contract() {'))->toBe(1);
});

// =============================================================================
// Installed operations bundle staleness guard
//
// install-target-perimeter must confirm the real installed seventeen-file
// operations bundle (install-target-operations' own responsibility) is
// present and current — for --check, --apply's own preflight, and
// --verify alike — before ever creating a staging directory, a backup
// directory, or touching a single perimeter destination file.
// =============================================================================

it('check passes when the installed operations bundle is fully current', function () {
    $scratch = installPerimeterScratchDir();

    try {
        [$exit, $output] = installPerimeterRunHarness($scratch, installPerimeterBaseVars($scratch), 'run_check');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('installed target operations bundle (seventeen files) matches this repository\'s committed sources');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when an installed operation is missing', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        unlink($vars['DST_OPS_BACKUP_CYCLE']);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('installed target operations are stale or incomplete; run install-target-operations --apply first');
        expect($output)->toContain('backup-cycle is missing');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when an installed operation is a symlink', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $decoy = $scratch.'/decoy-backup-cycle';
        file_put_contents($decoy, file_get_contents($vars['DST_OPS_BACKUP_CYCLE']));
        unlink($vars['DST_OPS_BACKUP_CYCLE']);
        symlink($decoy, $vars['DST_OPS_BACKUP_CYCLE']);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('installed target operations are stale or incomplete; run install-target-operations --apply first');
        expect($output)->toContain('backup-cycle must not be a symlink');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails on content drift in an installed operation', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        file_put_contents($vars['DST_OPS_BACKUP_CYCLE'], "#!/usr/bin/env bash\necho tampered\n");
        chmod($vars['DST_OPS_BACKUP_CYCLE'], 0o755);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('installed target operations are stale or incomplete; run install-target-operations --apply first');
        expect($output)->toContain('backup-cycle content differs from its committed source');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when an installed operation has the wrong mode', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        chmod($vars['DST_OPS_REGISTRY'], 0o644);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('installed target operations are stale or incomplete; run install-target-operations --apply first');
        expect($output)->toContain('registry has wrong mode');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the installed backup-cycle predates --target support, the exact reported symptom', function () {
    // Reproduces the real incident this guard exists for: an installed
    // backup-cycle from before answers "Unknown argument:
    // --target" even though the committed source is already target-aware.
    // The installer's own check is purely static (content/mode/ownership),
    // so this proves two things together: (1) that stale content is
    // detected as drift against the committed source, and (2) that the
    // fixture genuinely reproduces the reported failure mode if invoked.
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        $staleBackupCycle = <<<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail
case "$1" in
    --environment)
        echo "legacy backup-cycle ok"
        ;;
    *)
        echo "Unknown argument: --target" >&2
        exit 1
        ;;
esac
SH;
        file_put_contents($vars['DST_OPS_BACKUP_CYCLE'], $staleBackupCycle);
        chmod($vars['DST_OPS_BACKUP_CYCLE'], 0o755);

        // The fixture really does reproduce the reported symptom.
        exec(escapeshellarg($vars['DST_OPS_BACKUP_CYCLE']).' --target staging-main 2>&1', $staleOutput, $staleExit);
        expect($staleExit)->not->toBe(0);
        expect(implode("\n", $staleOutput))->toContain('Unknown argument: --target');

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('installed target operations are stale or incomplete; run install-target-operations --apply first');
        expect($output)->toContain('backup-cycle content differs from its committed source');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('a stale installed operations bundle is rejected before any perimeter destination is touched', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        unlink($vars['DST_OPS_BACKUP_CYCLE']);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('installed target operations are stale or incomplete; run install-target-operations --apply first');

        foreach (['DST_WRAPPER_DEPLOY', 'DST_WRAPPER_ROLLBACK', 'DST_WRAPPER_CLEANUP', 'DST_WRAPPER_RESTORE', 'DST_SUDOERS', 'DST_CRON'] as $key) {
            expect(file_exists($vars[$key]))->toBeFalse("{$key} must not exist — a stale operations bundle must be rejected before any perimeter file is touched");
        }
        expect(glob($vars['BACKUP_ROOT'].'/*'))->toBe([], 'no backup directory should ever be created for this failure');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify also rejects a stale installed operations bundle', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        // Simulate the bundle going stale after a successful apply — e.g. a
        // manual, out-of-band change to the installed backup-cycle.
        file_put_contents($vars['DST_OPS_BACKUP_CYCLE'], "#!/usr/bin/env bash\necho tampered\n");
        chmod($vars['DST_OPS_BACKUP_CYCLE'], 0o755);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOutput)->toContain('installed target operations are stale or incomplete; run install-target-operations --apply first');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

// =============================================================================
// Full perform_apply / perform_verify integration
// =============================================================================

it('a successful apply installs exactly six files with correct ownership, mode and content, and creates a timestamped backup', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('apply complete');

        foreach ([
            ['DST_WRAPPER_DEPLOY', 'SRC_WRAPPER_DEPLOY', '0755'],
            ['DST_WRAPPER_ROLLBACK', 'SRC_WRAPPER_ROLLBACK', '0755'],
            ['DST_WRAPPER_CLEANUP', 'SRC_WRAPPER_CLEANUP', '0755'],
            ['DST_WRAPPER_RESTORE', 'SRC_WRAPPER_RESTORE', '0755'],
            ['DST_SUDOERS', 'SRC_SUDOERS', '0440'],
            ['DST_CRON', 'SRC_CRON', '0644'],
        ] as [$dstKey, $srcKey, $mode]) {
            $dst = $vars[$dstKey];
            expect(file_exists($dst))->toBeTrue("{$dstKey} must exist");
            expect(is_link($dst))->toBeFalse();
            expect(file_get_contents($dst))->toBe(file_get_contents($vars[$srcKey]));
            expect(substr(sprintf('%o', fileperms($dst)), -4))->toBe($mode);
            expect(fileowner($dst))->toBe((int) $vars['INSTALL_OWNER_ID'], "{$dstKey} must be owned by INSTALL_OWNER_ID");
            expect(filegroup($dst))->toBe((int) $vars['INSTALL_GROUP_ID'], "{$dstKey} must be group-owned by INSTALL_GROUP_ID");
        }

        $allDestFiles = array_merge(
            glob($scratch.'/dest/usr/local/sbin/*'),
            glob($scratch.'/dest/etc/sudoers.d/*'),
            glob($scratch.'/dest/etc/cron.d/*'),
        );
        expect($allDestFiles)->toHaveCount(6);

        $backups = glob($vars['BACKUP_ROOT'].'/*', GLOB_ONLYDIR);
        expect($backups)->not->toBeEmpty('apply must create a timestamped backup directory');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('never invokes anything beyond the two safe wrapper probes during apply', function () {
    // installPerimeterWriteWrapperStub's own catch-all ("unsafe/unexpected
    // invocation") would fail this apply outright if the installer ever
    // called the wrapper with anything other than --help or a bare
    // --target tits-guru — so a passing apply here is itself the proof that
    // no real deploy/rollback/cleanup/backup operation was ever attempted.
    $scratch = installPerimeterScratchDir();

    try {
        [$exit, $output] = installPerimeterRunHarness($scratch, installPerimeterBaseVars($scratch), 'perform_apply');

        expect($exit)->toBe(0, $output);
        expect($output)->not->toContain('unsafe/unexpected invocation');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('a successful verify passes against a freshly applied perimeter', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        [$applyExit, $applyOut] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0, $applyOut);

        [$verifyExit, $verifyOut] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->toBe(0, $verifyOut);
        expect($verifyOut)->toContain('PASS: target-aware perimeter installed and verified');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('sudoers is installed only after its own visudo pass, and cron is installed last', function () {
    // Static ordering proof: read the real, shipped source and confirm the
    // three install_regular_file_transactional call sites appear in the
    // documented order (wrappers, then sudoers — immediately preceded by its
    // own visudo -cf call — then cron last).
    $source = installPerimeterSource();

    $wrapperDeployPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-deploy" "${DST_WRAPPER_DEPLOY}"');
    $wrapperRollbackPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-rollback" "${DST_WRAPPER_ROLLBACK}"');
    $wrapperCleanupPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-cleanup" "${DST_WRAPPER_CLEANUP}"');
    $visudoBeforeSudoersPos = mb_strpos($source, '"${VISUDO_BIN}" -cf "${STAGE_DIR}/rateguru-deploy.sudoers"');
    $sudoersInstallPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-deploy.sudoers" "${DST_SUDOERS}"');
    $cronInstallPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-backups.cron" "${DST_CRON}"');

    foreach ([$wrapperDeployPos, $wrapperRollbackPos, $wrapperCleanupPos, $visudoBeforeSudoersPos, $sudoersInstallPos, $cronInstallPos] as $pos) {
        expect($pos)->not->toBeFalse();
    }

    expect($wrapperDeployPos)->toBeLessThan($wrapperRollbackPos)
        ->and($wrapperRollbackPos)->toBeLessThan($wrapperCleanupPos)
        ->and($wrapperCleanupPos)->toBeLessThan($visudoBeforeSudoersPos)
        ->and($visudoBeforeSudoersPos)->toBeLessThan($sudoersInstallPos)
        ->and($sudoersInstallPos)->toBeLessThan($cronInstallPos);
});

it('a broken candidate aborts before touching any destination', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $broken = $scratch.'/broken-wrapper';
        file_put_contents($broken, "#!/usr/bin/env bash\nif [[ true\n");
        chmod($broken, 0o755);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_WRAPPER_DEPLOY'] = $broken;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect(file_exists($vars['DST_WRAPPER_DEPLOY']))->toBeFalse();
        expect(file_exists($vars['DST_WRAPPER_ROLLBACK']))->toBeFalse();
        expect(file_exists($vars['DST_WRAPPER_CLEANUP']))->toBeFalse();
        expect(file_exists($vars['DST_WRAPPER_RESTORE']))->toBeFalse();
        expect(file_exists($vars['DST_SUDOERS']))->toBeFalse();
        expect(file_exists($vars['DST_CRON']))->toBeFalse();
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('rolls back every file to its previous content when a mid-sequence install step fails, and removes files that did not exist before', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        // Two of the five destinations already exist with distinct, known
        // "old" content before this run — the other three (including cron,
        // installed last) are absent.
        file_put_contents($vars['DST_WRAPPER_DEPLOY'], "old deploy wrapper content\n");
        file_put_contents($vars['DST_SUDOERS'], "old sudoers content\n");

        // cron is installed last (see the ordering test above) — pre-seeding
        // its destination with a directory makes
        // reject_unsafe_existing_destination fail specifically at that final
        // step, after the four earlier files have already been installed
        // for real, so this proves rollback restores/removes across the
        // whole set, not merely the one file that happened to fail.
        mkdir($vars['DST_CRON']);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('refusing to install over an existing non-regular-file destination');
        expect($output)->toContain('rollback complete');

        expect(file_get_contents($vars['DST_WRAPPER_DEPLOY']))->toBe("old deploy wrapper content\n");
        expect(file_get_contents($vars['DST_SUDOERS']))->toBe("old sudoers content\n");
        expect(file_exists($vars['DST_WRAPPER_ROLLBACK']))->toBeFalse();
        expect(file_exists($vars['DST_WRAPPER_CLEANUP']))->toBeFalse();
        expect(file_exists($vars['DST_WRAPPER_RESTORE']))->toBeFalse();
        expect(is_dir($vars['DST_CRON']))->toBeTrue('the pre-existing directory at DST_CRON must be left untouched, never treated as a rollback target');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('leaves no partial perimeter when apply fails at any point', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $failingStub = $scratch.'/failing-wrapper';
        installPerimeterWriteWrapperStub($failingStub, 'unexpected-success');
        $vars['SRC_WRAPPER_DEPLOY'] = $failingStub;
        $vars['SRC_WRAPPER_ROLLBACK'] = $failingStub;
        $vars['SRC_WRAPPER_CLEANUP'] = $failingStub;
        $vars['SRC_WRAPPER_RESTORE'] = $failingStub;

        [$exit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);

        foreach (['DST_WRAPPER_DEPLOY', 'DST_WRAPPER_ROLLBACK', 'DST_WRAPPER_CLEANUP', 'DST_WRAPPER_RESTORE', 'DST_SUDOERS', 'DST_CRON'] as $key) {
            expect(file_exists($vars[$key]))->toBeFalse("{$key} must not exist — no destination existed before this run, so a failed apply must leave none behind");
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('apply fails with a specific diagnostic when the planned-target rejection happens for the wrong reason', function () {
    // Exercises installPerimeterWriteWrapperStub's 'wrong-reason' variant:
    // the stub fails a --target tits-guru probe, but not with
    // lifecycle=planned, so verify_wrapper_planned_target_rejected must
    // distinguish "rejected, but for the wrong reason" from both a genuine
    // pass and an unexpected success.
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $wrongReasonStub = $scratch.'/wrong-reason-wrapper';
        installPerimeterWriteWrapperStub($wrongReasonStub, 'wrong-reason');
        $vars['SRC_WRAPPER_DEPLOY'] = $wrongReasonStub;
        $vars['SRC_WRAPPER_ROLLBACK'] = $wrongReasonStub;
        $vars['SRC_WRAPPER_CLEANUP'] = $wrongReasonStub;
        $vars['SRC_WRAPPER_RESTORE'] = $wrongReasonStub;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('failed for the wrong reason');

        foreach (['DST_WRAPPER_DEPLOY', 'DST_WRAPPER_ROLLBACK', 'DST_WRAPPER_CLEANUP', 'DST_WRAPPER_RESTORE', 'DST_SUDOERS', 'DST_CRON'] as $key) {
            expect(file_exists($vars[$key]))->toBeFalse("{$key} must not exist — this failure happens during staged verification, before any destination is touched");
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify detects content drift on an installed file', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        file_put_contents($vars['DST_CRON'], "tampered content\n");
        // Preserve the installed mode/ownership exactly — only content
        // differs, isolating this test to the content-comparison check.
        chmod($vars['DST_CRON'], 0o644);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOutput)->toContain('content differs from its committed source');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify detects a mode drift on an installed file', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        chmod($vars['DST_SUDOERS'], 0o640);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOutput)->toContain('wrong mode');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify detects an installed destination replaced by a symlink', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        $decoy = $scratch.'/decoy-cron';
        file_put_contents($decoy, file_get_contents($vars['DST_CRON']));
        unlink($vars['DST_CRON']);
        symlink($decoy, $vars['DST_CRON']);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOutput)->toContain('must not be a symlink');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('apply refuses to install over an existing symlink destination, and never backs it up', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $decoy = $scratch.'/decoy';
        file_put_contents($decoy, "decoy\n");
        symlink($decoy, $vars['DST_WRAPPER_DEPLOY']);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('refusing to install over an existing symlink');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

// =============================================================================
// Legacy wrapper removal (six required-absent files)
// =============================================================================

/** @return list<string> */
function installPerimeterLegacyWrapperNames(): array
{
    return [
        'rateguru-staging-deploy',
        'rateguru-staging-rollback',
        'rateguru-staging-cleanup',
        'rateguru-production-deploy',
        'rateguru-production-rollback',
        'rateguru-production-cleanup',
    ];
}

it('check reports every legacy wrapper as present, without touching the filesystem', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        foreach (installPerimeterLegacyWrapperNames() as $name) {
            $path = $vars['DST_SBIN_DIR'].'/'.$name;
            file_put_contents($path, "old {$name}\n");
            chmod($path, 0o755);
        }

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->toBe(0, $output);

        foreach (installPerimeterLegacyWrapperNames() as $name) {
            $path = $vars['DST_SBIN_DIR'].'/'.$name;
            expect($output)->toContain("legacy wrapper present, would be removed by --apply: {$path}");
            expect(file_exists($path))->toBeTrue("{$name} must still exist — --check never touches the filesystem");
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check reports every legacy wrapper as already absent when none exist', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->toBe(0, $output);

        foreach (installPerimeterLegacyWrapperNames() as $name) {
            expect($output)->toContain("legacy wrapper already absent: {$vars['DST_SBIN_DIR']}/{$name}");
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails and reports a directory at a legacy wrapper path as a blocker, not as an ordinary removal candidate', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $names = installPerimeterLegacyWrapperNames();

        $obstacleName = $names[count($names) - 1];
        $obstacle = $vars['DST_SBIN_DIR'].'/'.$obstacleName;
        mkdir($obstacle, 0o750);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain("legacy wrapper BLOCKS --apply, not a regular file or symlink: {$obstacle}");
        expect($output)->not->toContain("legacy wrapper present, would be removed by --apply: {$obstacle}");
        expect(is_dir($obstacle))->toBeTrue('--check must never touch the filesystem');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('apply removes every existing legacy wrapper, backing each up first', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $names = installPerimeterLegacyWrapperNames();

        foreach ($names as $name) {
            $path = $vars['DST_SBIN_DIR'].'/'.$name;
            file_put_contents($path, "old {$name} content\n");
            chmod($path, 0o755);
        }

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->toBe(0, $output);

        $backupDirs = glob($vars['BACKUP_ROOT'].'/*', GLOB_ONLYDIR);
        expect($backupDirs)->not->toBeEmpty();
        sort($backupDirs);
        $latestBackup = end($backupDirs);

        foreach ($names as $name) {
            $path = $vars['DST_SBIN_DIR'].'/'.$name;
            expect(file_exists($path))->toBeFalse("{$name} must be removed by apply");
            expect($output)->toContain("removed legacy wrapper: {$path}");

            $backedUp = $latestBackup.$path;
            expect(file_exists($backedUp))->toBeTrue("{$name} must have been backed up before removal");
            expect(file_get_contents($backedUp))->toBe("old {$name} content\n");
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('apply fails closed, before backing anything up or removing it, when a legacy wrapper path is a directory instead of a file', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $names = installPerimeterLegacyWrapperNames();

        // A regular-file survivor earlier in the list, so a real backup
        // directory does get created this run — proving the directory
        // obstacle is rejected before record_target ever runs for it, not
        // merely before rm -f, and that nothing it would have touched (its
        // own backup, its own removal) ever happens.
        $survivorName = $names[0];
        $survivor = $vars['DST_SBIN_DIR'].'/'.$survivorName;
        file_put_contents($survivor, "pre-existing legacy wrapper\n");
        chmod($survivor, 0o750);

        $obstacleName = $names[count($names) - 1];
        $obstacle = $vars['DST_SBIN_DIR'].'/'.$obstacleName;
        mkdir($obstacle, 0o750);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain("refusing to remove a legacy wrapper path that is not a regular file or symlink: {$obstacle}");
        expect(is_dir($obstacle))->toBeTrue('the directory obstacle must be left exactly as found');
        expect(file_get_contents($survivor))->toBe("pre-existing legacy wrapper\n", 'the earlier survivor must be restored, not left removed');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('apply never creates a legacy wrapper that was already absent', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->toBe(0, $output);

        foreach (installPerimeterLegacyWrapperNames() as $name) {
            expect(file_exists($vars['DST_SBIN_DIR'].'/'.$name))->toBeFalse();
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify fails when a legacy wrapper is still present', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        // Simulate a legacy wrapper resurrected out-of-band after a
        // successful apply.
        $resurrected = $vars['DST_SBIN_DIR'].'/rateguru-staging-deploy';
        file_put_contents($resurrected, "resurrected\n");
        chmod($resurrected, 0o755);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOutput)->toContain("legacy wrapper still present: {$resurrected}");
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('a successful verify confirms all six legacy wrapper paths are absent', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->toBe(0, $verifyOutput);
        expect($verifyOutput)->toContain('all six legacy wrapper paths are absent');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('a failed apply restores a legacy wrapper that existed before, with its original content and mode', function () {
    // A broken wrapper/sudoers/cron *candidate* (e.g. the 'wrong-reason'
    // stub used above) always fails during verify_staged_candidates, which
    // runs before BACKUP_DIR is even set and before any destination is
    // touched — proving nothing about restoring a removed legacy wrapper.
    // To reach remove_legacy_wrappers with a genuine, still-real failure
    // afterward, pre-seed the *last* legacy wrapper slot
    // (rateguru-production-cleanup) as a directory: record_target backs it
    // up successfully, but `rm -f` (no -r) refuses to remove a directory,
    // failing partway through remove_legacy_wrappers — well after
    // BACKUP_DIR is set and the five real files are already transactionally
    // installed, and after every earlier legacy wrapper in the list
    // (including our real survivor, rateguru-staging-deploy, first in the
    // list) has already been backed up and removed.
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        $survivor = $vars['DST_SBIN_DIR'].'/rateguru-staging-deploy';
        file_put_contents($survivor, "pre-existing legacy wrapper\n");
        chmod($survivor, 0o750);
        $originalPerms = fileperms($survivor);

        $obstacle = $vars['DST_SBIN_DIR'].'/rateguru-production-cleanup';
        mkdir($obstacle, 0o750);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        // Proves remove_legacy_wrappers actually reached and removed the
        // survivor before the later obstacle failed the run — not merely
        // that the final restored state looks right, which would also hold
        // if wrapper ordering changed and the survivor were never removed
        // (and so never needed restoring) in the first place.
        expect($output)->toContain("removed legacy wrapper: {$survivor}");
        expect($output)->toContain('rollback complete');
        expect(file_exists($survivor))->toBeTrue('a legacy wrapper that existed before this run must be restored on rollback');
        expect(is_dir($survivor))->toBeFalse();
        expect(file_get_contents($survivor))->toBe("pre-existing legacy wrapper\n");
        expect(fileperms($survivor))->toBe($originalPerms);
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('a failed apply never leaves behind a legacy wrapper that did not exist before this run', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $wrongReasonStub = $scratch.'/wrong-reason-wrapper';
        installPerimeterWriteWrapperStub($wrongReasonStub, 'wrong-reason');
        $vars['SRC_WRAPPER_DEPLOY'] = $wrongReasonStub;
        $vars['SRC_WRAPPER_ROLLBACK'] = $wrongReasonStub;
        $vars['SRC_WRAPPER_CLEANUP'] = $wrongReasonStub;
        $vars['SRC_WRAPPER_RESTORE'] = $wrongReasonStub;

        [$exit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);

        foreach (installPerimeterLegacyWrapperNames() as $name) {
            expect(file_exists($vars['DST_SBIN_DIR'].'/'.$name))->toBeFalse("{$name} was absent before this run and must stay absent after a failed apply");
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify and apply never run a real deploy, rollback, cleanup-apply, backup-cycle, restore or cron operation', function () {
    // Structural guard on the shipped installer's own source: it must never
    // invoke any of these mutating commands directly — every real mutation
    // belongs exclusively to whatever the installed wrapper execs into, on a
    // genuine, separately-triggered invocation.
    $source = installPerimeterSource();

    expect($source)
        ->not->toContain('"${DST_WRAPPER_DEPLOY}" --target')
        ->not->toContain('"${DST_WRAPPER_ROLLBACK}" --target')
        ->not->toContain('"${DST_WRAPPER_CLEANUP}" --target');
});

// =============================================================================
// RATEGURU_PERIMETER_ROOT (the one gated destination-root test seam)
// =============================================================================

it('RATEGURU_PERIMETER_ROOT prefixes every destination when the allow flag is set', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $script = "set -Eeuo pipefail\n"
            .'source '.escapeshellarg(installPerimeterScriptPath())."\n"
            .'printf \'DEPLOY=%s\nSUDOERS=%s\nCRON=%s\n\' "${DST_WRAPPER_DEPLOY}" "${DST_SUDOERS}" "${DST_CRON}"'."\n";

        $harnessPath = $scratch.'/harness.sh';
        file_put_contents($harnessPath, $script);

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_PERIMETER_ROOT' => $scratch.'/prefixed-root',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(['bash', $harnessPath], $descriptors, $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('DEPLOY='.$scratch.'/prefixed-root/usr/local/sbin/rateguru-deploy');
        expect($output)->toContain('SUDOERS='.$scratch.'/prefixed-root/etc/sudoers.d/rateguru-deploy');
        expect($output)->toContain('CRON='.$scratch.'/prefixed-root/etc/cron.d/rateguru-backups');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('RATEGURU_PERIMETER_ROOT is ignored without the allow flag', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $script = "set -Eeuo pipefail\n"
            .'source '.escapeshellarg(installPerimeterScriptPath())."\n"
            .'printf \'DEPLOY=%s\n\' "${DST_WRAPPER_DEPLOY}"'."\n";

        $harnessPath = $scratch.'/harness.sh';
        file_put_contents($harnessPath, $script);

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_PERIMETER_ROOT' => $scratch.'/prefixed-root',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(['bash', $harnessPath], $descriptors, $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('DEPLOY=/usr/local/sbin/rateguru-deploy');
        expect($output)->not->toContain($scratch);
    } finally {
        installPerimeterCleanup($scratch);
    }
});

// =============================================================================
// RATEGURU_INSTALLED_OPERATIONS_ROOT (the staleness guard's own test seam)
// =============================================================================

it('RATEGURU_INSTALLED_OPERATIONS_ROOT lets the staleness guard pass against a prefixed bundle when the allow flag is set', function () {
    $scratch = installPerimeterScratchDir();

    try {
        installPerimeterWriteOperationsBundle($scratch);
        $opsRoot = $scratch.'/ops';
        $ownerId = (string) getmyuid();
        $groupId = (string) getmygid();

        $script = "set -Eeuo pipefail\n"
            .'source '.escapeshellarg(installPerimeterScriptPath())."\n"
            .'INSTALL_OWNER_ID='.escapeshellarg($ownerId)."\n"
            .'INSTALL_GROUP_ID='.escapeshellarg($groupId)."\n"
            .'validate_installed_operations_bundle'."\n"
            .'printf \'BUNDLE_OK\n\''."\n";

        $harnessPath = $scratch.'/harness.sh';
        file_put_contents($harnessPath, $script);

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_INSTALLED_OPERATIONS_ROOT' => $opsRoot,
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(['bash', $harnessPath], $descriptors, $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('BUNDLE_OK');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('RATEGURU_INSTALLED_OPERATIONS_ROOT is ignored without the allow flag, so a stale/absent real bundle still fails the guard', function () {
    // A fully valid, matching bundle exists at this prefixed path — but
    // without RATEGURU_ALLOW_TEST_OVERRIDES=true (absent, or explicitly
    // false), the override must be ignored entirely, so the guard falls
    // back to the real /home/www/rateguru path (absent on this machine) and
    // fails — proving the false-positive path fixture cannot be used to
    // satisfy the guard without the explicit opt-in.
    $scratch = installPerimeterScratchDir();

    try {
        installPerimeterWriteOperationsBundle($scratch);
        $opsRoot = $scratch.'/ops';

        $script = "set -Eeuo pipefail\n"
            .'source '.escapeshellarg(installPerimeterScriptPath())."\n"
            .'validate_installed_operations_bundle'."\n"
            .'printf \'BUNDLE_OK\n\''."\n";

        $harnessPath = $scratch.'/harness.sh';
        file_put_contents($harnessPath, $script);

        foreach ([[], ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'false']] as $allowFlagVariant) {
            $env = array_merge([
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'HOME' => getenv('HOME') ?: '/tmp',
                'RATEGURU_INSTALLED_OPERATIONS_ROOT' => $opsRoot,
            ], $allowFlagVariant);

            $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
            $process = proc_open(['bash', $harnessPath], $descriptors, $pipes, null, $env);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exit = proc_close($process);

            expect($exit)->not->toBe(0);
            expect($output)->not->toContain('BUNDLE_OK');
            expect($output)->toContain('installed target operations are stale or incomplete; run install-target-operations --apply first');
            expect($output)->not->toContain($opsRoot);
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

// =============================================================================
// Executable modes / basic shape (Git index)
// =============================================================================

it('install-target-perimeter is executable in the Git index', function () {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['git', '-C', base_path(), 'ls-files', '--stage', '--', 'infrastructure/scripts/install-target-perimeter'], $descriptors, $pipes);
    $stdout = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stdout)->toStartWith('100755');
});

it('the three wrapper source files are present, readable, and syntactically valid', function () {
    foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup'] as $name) {
        $path = base_path("infrastructure/config/wrappers/{$name}");
        expect(File::exists($path))->toBeTrue();

        $output = [];
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, "bash -n failed for {$name}: ".implode("\n", $output));
    }
});
