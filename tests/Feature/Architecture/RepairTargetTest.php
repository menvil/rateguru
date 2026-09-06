<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/repair-target — converge ONE live target's own
 * infrastructure back onto the committed source of truth, without touching the
 * code it serves or the data it holds.
 *
 * Every test executes the real, shipped script as a subprocess against a fully
 * simulated target: a scratch application root with a real deployed release, a
 * registry whose application_root points into it, and recording stubs standing
 * in for the two owning installers, the health check and supervisorctl. All of
 * it is injected through RATEGURU_REPAIR_* / RATEGURU_RUN_ROOT overrides the
 * script only honors alongside RATEGURU_ALLOW_TEST_OVERRIDES=true.
 *
 * The properties these tests exist to hold, in the order they matter:
 *
 *   1. It never changes the code, the environment file, the shared storage
 *      structure or the rollback pointer — and it PROVES that afterwards
 *      rather than promising it.
 *   2. It refuses, before the first mutation, everything it must not decide:
 *      a held or in-progress restore, a deliberate maintenance window, a
 *      target with no canonical deployed release, host-level damage, and drift
 *      that is never converged automatically.
 *   3. It never implements layout or service logic itself. Every convergence
 *      is delegated to the installer that owns the contract, in its
 *      target-scoped mode.
 */

// =============================================================================
// Harness
// =============================================================================

function repairScript(): string
{
    return base_path('infrastructure/scripts/repair-target');
}

function repairSource(): string
{
    return File::get(repairScript());
}

function repairScratchDir(): string
{
    $dir = sys_get_temp_dir().'/repair-target-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/cron.d', '/run', '/log'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function repairCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function repairRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', repairScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start repair-target subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function repairWriteStub(string $path, string $body): void
{
    file_put_contents($path, $body);
    chmod($path, 0o755);
}

function repairTargetRoot(string $scratch): string
{
    return $scratch.'/root';
}

/**
 * A live, correctly deployed staging-main: a real release directory with
 * release.json and artisan, `current` pointing at it, a regular shared/.env
 * and a real shared/storage tree.
 */
function repairBuildTarget(string $scratch): void
{
    $root = repairTargetRoot($scratch);

    foreach ([
        '/locks', '/deployments', '/shared/storage/framework', '/shared/storage/logs',
        '/releases/20260101120000', '/releases/20250101120000',
    ] as $sub) {
        @mkdir($root.$sub, 0o755, true);
    }

    file_put_contents(
        $root.'/releases/20260101120000/release.json',
        json_encode(['release' => '20260101120000', 'source_sha' => 'a1b2c3d'])."\n",
    );
    touch($root.'/releases/20260101120000/artisan');

    file_put_contents($root.'/shared/.env', "APP_KEY=base64:fixture\nDB_PASSWORD=fixture\n");

    symlink('releases/20260101120000', $root.'/current');
    symlink('releases/20250101120000', $root.'/previous');
}

/**
 * The stubs, all of them recording every invocation into calls.log.
 *
 * Each installer stub answers --check from toggle files, so a test describes a
 * host state rather than scripting a sequence, and applies the sabotage a test
 * asks for so the immutability proof can be exercised against a child that
 * misbehaves.
 */
function repairWriteStubs(string $scratch): void
{
    $root = repairTargetRoot($scratch);

    foreach (['host-layout', 'services'] as $child) {
        repairWriteStub($scratch.'/bin/'.$child, <<<STUB
            #!/usr/bin/env bash
            me="\$(basename "\$0")"
            printf '%s %s\\n' "\${me}" "\$*" >> "{$scratch}/calls.log"

            if [[ "\$1" == "--check" ]]; then
                [[ -e "{$scratch}/toggles/\${me}-hostreq" ]] && { echo "  HOST-REQ host:/var/log/rateguru — absent"; exit 1; }
                [[ -e "{$scratch}/toggles/\${me}-conflict" ]] && { echo "  CONFLICT path:/x — a regular file occupies a managed directory path"; exit 1; }
                [[ -e "{$scratch}/toggles/\${me}-broken" ]] && { echo "the installer could not run"; exit 2; }
                # A child that fails while printing neither MISSING nor DRIFT.
                [[ -e "{$scratch}/toggles/\${me}-errors-only" ]] && { echo "ERROR: something went wrong"; exit 1; }
                # The services installer verifies this target's layout as its
                # own prerequisite. While the layout is drifted it reports that
                # as DRIFT — repairable, by a named owner — never as a conflict.
                if [[ "\${me}" == services ]] && [[ -e "{$scratch}/toggles/host-layout-drift" ]]; then
                    echo "  DRIFT    prerequisite:install-bootstrap-host-layout — verify failed"
                    exit 1
                fi
                [[ -e "{$scratch}/toggles/\${me}-drift" ]] && { echo "  MISSING  link:/etc/nginx/sites-enabled/rateguru-staging — absent"; exit 1; }
                exit 0
            fi

            if [[ "\$1" == "--apply" ]]; then
                [[ -e "{$scratch}/toggles/sabotage-env" ]] && printf 'APP_KEY=rewritten\\n' > "{$root}/shared/.env"
                [[ -e "{$scratch}/toggles/sabotage-storage" ]] && mkdir -p "{$root}/shared/storage/injected"
                [[ -e "{$scratch}/toggles/sabotage-current" ]] && ln -sfn releases/20250101120000 "{$root}/current"
                [[ -e "{$scratch}/toggles/sabotage-previous" ]] && ln -sfn releases/20260101120000 "{$root}/previous"
                [[ -e "{$scratch}/toggles/sabotage-release-json" ]] && printf '{"release":"other","source_sha":"9999999"}\\n' > "{$root}/releases/20260101120000/release.json"
                [[ -e "{$scratch}/toggles/sabotage-scheduler" ]] && rm -f "{$scratch}/cron.d/rateguru-staging-scheduler"
                # The service-log directory this installer legitimately owns.
                [[ -e "{$scratch}/toggles/repair-service-logs" ]] && chmod 2770 "{$root}/shared/storage/logs"
                [[ -e "{$scratch}/toggles/\${me}-apply-fail" ]] && exit 1
                rm -f "{$scratch}/toggles/\${me}-drift"
                exit 0
            fi

            exit 0
            STUB);
    }

    repairWriteStub($scratch.'/bin/health-check', <<<STUB
        #!/usr/bin/env bash
        printf 'health-check %s\\n' "\$*" >> "{$scratch}/calls.log"
        [[ -e "{$scratch}/toggles/health-fail" ]] && exit 1
        exit 0
        STUB);

    repairWriteStub($scratch.'/bin/supervisorctl', <<<STUB
        #!/usr/bin/env bash
        printf 'supervisorctl %s\\n' "\$*" >> "{$scratch}/calls.log"

        if [[ "\$1" == "status" ]]; then
            if [[ -e "{$scratch}/toggles/queue-down" ]]; then
                echo "rateguru-staging-queue:worker_00   STOPPED   Not started"
                exit 3
            fi
            echo "rateguru-staging-queue:worker_00   RUNNING   pid 4242, uptime 1:00:00"
            exit 0
        fi

        if [[ "\$1" == "start" ]]; then
            [[ -e "{$scratch}/toggles/queue-unstartable" ]] && exit 1
            rm -f "{$scratch}/toggles/queue-down"
            exit 0
        fi

        exit 0
        STUB);

    // A permissive registry validator: the shipped one rejects an
    // application_root outside the real RateGuru namespace, and every path in
    // this fixture lives in a scratch directory by design.
    repairWriteStub($scratch.'/bin/targets', "#!/usr/bin/env bash\nexit 0\n");
}

/**
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function repairFixture(string $scratch, array $options = []): array
{
    @mkdir($scratch.'/toggles', 0o755, true);

    repairBuildTarget($scratch);
    repairWriteStubs($scratch);

    touch($scratch.'/cron.d/rateguru-staging-scheduler');

    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);
    $registry['targets']['staging-main']['application_root'] = repairTargetRoot($scratch);

    foreach ($options['registry'] ?? [] as $key => $value) {
        $registry['targets']['staging-main'][$key] = $value;
    }

    file_put_contents(
        $scratch.'/deployment-targets.json',
        json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    file_put_contents($scratch.'/deployment.conf', implode("\n", [
        "RELEASE_ID_REGEX='^[0-9]{14}$'",
        'PHP_BIN=/usr/bin/php',
        'PHP_FPM_SERVICE=php-noop-fpm',
        '',
    ]));

    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => base_path('infrastructure/scripts/common'),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => $scratch.'/deployment.conf',
        'RATEGURU_TARGET_REGISTRY_FILE' => $scratch.'/deployment-targets.json',
        'RATEGURU_TARGETS_CLI' => $scratch.'/bin/targets',
        'RATEGURU_REPAIR_EUID' => $options['euid'] ?? '0',
        'RATEGURU_REPAIR_HOSTLAYOUT_BIN' => $scratch.'/bin/host-layout',
        'RATEGURU_REPAIR_SERVICES_BIN' => $scratch.'/bin/services',
        'RATEGURU_REPAIR_HEALTH_CHECK_BIN' => $scratch.'/bin/health-check',
        'RATEGURU_REPAIR_SUPERVISORCTL_BIN' => $scratch.'/bin/supervisorctl',
        'RATEGURU_REPAIR_CRON_D_ROOT' => $scratch.'/cron.d',
        'RATEGURU_RUN_ROOT' => $scratch.'/run',
        'RATEGURU_REPAIR_QUEUE_WAIT_ATTEMPTS' => '2',
        'RATEGURU_REPAIR_QUEUE_RETRY_DELAY' => '0',
    ];
}

function repairToggle(string $scratch, string $name): void
{
    touch($scratch.'/toggles/'.$name);
}

function repairCalls(string $scratch): string
{
    return is_file($scratch.'/calls.log') ? File::get($scratch.'/calls.log') : '';
}

function repairResetCalls(string $scratch): void
{
    file_put_contents($scratch.'/calls.log', '');
}

function repairGuard(string $scratch, string $status, string $operation = '20260101-120000-abc123'): void
{
    @mkdir($scratch.'/run/restores/staging-main', 0o755, true);

    file_put_contents(
        $scratch.'/run/restores/staging-main/restore-guard',
        json_encode(['status' => $status, 'operation' => $operation, 'target' => 'staging-main'])."\n",
    );
}

/**
 * Structure + content snapshot of everything the repair must not change.
 *
 * @return array<string, string>
 */
function repairImmutableSnapshot(string $scratch): array
{
    $root = repairTargetRoot($scratch);
    $snapshot = [
        'env' => File::get($root.'/shared/.env'),
        'current' => readlink($root.'/current'),
        'previous' => readlink($root.'/previous'),
        'release.json' => File::get($root.'/releases/20260101120000/release.json'),
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/shared/storage', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $path => $info) {
        $snapshot['storage:'.substr((string) $path, strlen($root))] = $info->isDir() ? 'dir' : 'file';
    }

    ksort($snapshot);

    return $snapshot;
}

/**
 * The one machine-readable line, decoded.
 *
 * @return array<string, mixed>
 */
function repairResult(string $output): array
{
    expect($output)->toContain('RATEGURU_REPAIR_RESULT=');

    preg_match('/^RATEGURU_REPAIR_RESULT=(.*)$/m', $output, $matches);

    return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
}

// =============================================================================
// Shipping and CLI contract
// =============================================================================

it('ships executable, syntax-clean and listed in the required-CLI manifest', function () {
    expect(is_file(repairScript()))->toBeTrue();
    expect(is_executable(repairScript()))->toBeTrue();

    exec('bash -n '.escapeshellarg(repairScript()).' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));

    expect(File::get(base_path('infrastructure/config/required-clis.txt')))
        ->toContain("repair-target\n");
});

it('rejects every malformed invocation before resolving anything', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        $cases = [
            [[], 'one of --check, --apply or --verify is required'],
            [['--check'], '--target is required'],
            [['--target', 'staging-main'], 'one of --check, --apply or --verify is required'],
            [['--check', '--apply', '--target', 'staging-main'], 'only one of --check, --apply or --verify may be given'],
            [['--check', '--target'], '--target requires a value'],
            [['--check', '--target', '--apply'], '--target requires a value, not another option'],
            [['--check', '--target', 'a', '--target', 'b'], '--target given more than once'],
            [['--frobnicate'], 'unknown argument: --frobnicate'],
        ];

        foreach ($cases as [$arguments, $needle]) {
            [$exit, $output] = repairRun($arguments, $env);

            expect($exit)->not->toBe(0, 'must reject: '.implode(' ', $arguments));
            expect($output)->toContain($needle);
        }

        expect(repairCalls($scratch))->toBe('', 'a malformed invocation reached a child');
    } finally {
        repairCleanup($scratch);
    }
});

it('requires root in every mode', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch, ['euid' => '1000']);

        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = repairRun([$mode, '--target', 'staging-main'], $env);

            expect($exit)->not->toBe(0);
            expect($output)->toContain('must run as root');
        }
    } finally {
        repairCleanup($scratch);
    }
});

it('prints usage on --help without resolving a target', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        [$exit, $output] = repairRun(['--help'], $env);

        expect($exit)->toBe(0);
        expect($output)->toContain('--check')->toContain('--apply')->toContain('--verify');
        expect($output)->toContain('RATEGURU_REPAIR_RESULT');
        expect(repairCalls($scratch))->toBe('');
    } finally {
        repairCleanup($scratch);
    }
});

it('refuses an unknown target and a planned target', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        [$unknownExit, $unknownOutput] = repairRun(['--check', '--target', 'not-a-target'], $env);
        expect($unknownExit)->not->toBe(0);
        expect($unknownOutput)->toContain('unknown target: not-a-target');

        // tits-guru stays lifecycle=planned. A repair converges the
        // infrastructure of a target that is already live; it never activates
        // or provisions one.
        [$plannedExit, $plannedOutput] = repairRun(['--apply', '--target', 'tits-guru'], $env);
        expect($plannedExit)->not->toBe(0);
        expect($plannedOutput)->toContain('lifecycle=planned, not active');

        expect(repairCalls($scratch))->toBe('');
    } finally {
        repairCleanup($scratch);
    }
});

// =============================================================================
// --check: the read-only plan
// =============================================================================

it('reports a healthy target as needing no repair, creating nothing at all', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        $before = repairImmutableSnapshot($scratch);

        [$exit, $output] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('REPAIR REQUIRED: NO');
        expect($output)->toContain('release:current — 20260101120000 (source a1b2c3d)');
        expect($output)->toContain('PASS         runtime:queue');
        expect($output)->toContain('PASS         runtime:health');

        // --check takes no lock. Nothing at all is created by an inspection.
        expect(is_file(repairTargetRoot($scratch).'/locks/deployment.lock'))->toBeFalse();
        expect(repairCalls($scratch))->not->toContain('--apply');
        expect(repairImmutableSnapshot($scratch))->toBe($before);
    } finally {
        repairCleanup($scratch);
    }
});

it('names the owning installer as the repair for target-scoped drift', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-drift');

        [$exit, $output] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('REPAIR REQUIRED: YES');
        expect($output)->toContain('DRIFT        services:install-bootstrap-services');
        expect($output)->toContain('repair: services --apply --target staging-main');

        // The child's own diagnosis is shown rather than restated: this
        // orchestrator never decides what drifted.
        expect($output)->toContain('link:/etc/nginx/sites-enabled/rateguru-staging');
    } finally {
        repairCleanup($scratch);
    }
});

it('classifies a failing health check by whether there is anything to converge', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'health-fail');

        // Nothing to converge: a repair would change nothing, so calling it
        // "repair required" would be a lie.
        [$aloneExit, $aloneOutput] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($aloneExit)->toBe(1, $aloneOutput);
        expect($aloneOutput)->toContain('CONFLICT     runtime:health');
        expect($aloneOutput)->toContain('outside this operation\'s scope');
        expect($aloneOutput)->toContain('REPAIR REQUIRED: BLOCKED');

        // With drift present the same failure is its symptom.
        repairToggle($scratch, 'services-drift');

        [$withDriftExit, $withDriftOutput] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($withDriftExit)->toBe(1, $withDriftOutput);
        expect($withDriftOutput)->toContain('DRIFT        runtime:health');
        expect($withDriftOutput)->toContain('REPAIR REQUIRED: YES');
    } finally {
        repairCleanup($scratch);
    }
});

// =============================================================================
// The interlocks: what a repair must never decide
// =============================================================================

it('refuses every restore guard state with its own diagnosis, and never clears one', function () {
    $guardStates = [
        'held' => 'intentionally HELD for controlled code alignment',
        'in-progress' => 'in progress or was interrupted',
        'failed-held' => 'failed and left the target held',
        'unrecognised' => 'does not recognise',
    ];

    foreach ($guardStates as $status => $needle) {
        $scratch = repairScratchDir();

        try {
            $env = repairFixture($scratch);
            repairGuard($scratch, $status);

            [$checkExit, $checkOutput] = repairRun(['--check', '--target', 'staging-main'], $env);

            expect($checkExit)->toBe(1, $checkOutput);
            expect($checkOutput)->toContain('RESTORE-HOLD restore:guard');
            expect($checkOutput)->toContain($needle);
            expect($checkOutput)->toContain('REPAIR REQUIRED: BLOCKED');

            [$applyExit, $applyOutput] = repairRun(['--apply', '--target', 'staging-main'], $env);

            expect($applyExit)->not->toBe(0, $applyOutput);
            expect($applyOutput)->toContain('No mutation was performed');
            expect(repairCalls($scratch))->not->toContain('--apply');

            // The guard is left exactly as it was found. Clearing it is a
            // restore decision, and this operation does not get to make it.
            $guard = json_decode(File::get($scratch.'/run/restores/staging-main/restore-guard'), true, 512, JSON_THROW_ON_ERROR);
            expect($guard['status'])->toBe($status);
        } finally {
            repairCleanup($scratch);
        }
    }
});

it('points a held target at the restore workflow rather than at itself', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairGuard($scratch, 'held');

        [, $output] = repairRun(['--check', '--target', 'staging-main'], $env);

        // status=held with code alignment required is exactly what
        // mode=continue-held exists for; a repair must not be offered as an
        // alternative to finishing that operation.
        expect($output)->toContain('mode=continue-held');
    } finally {
        repairCleanup($scratch);
    }
});

it('refuses a deliberate maintenance window and never brings the target up', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        touch(repairTargetRoot($scratch).'/shared/storage/framework/down');

        [$checkExit, $checkOutput] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($checkExit)->toBe(1, $checkOutput);
        expect($checkOutput)->toContain('MAINTENANCE  runtime:maintenance');
        expect($checkOutput)->toContain("never runs 'artisan up'");

        [$applyExit, $applyOutput] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->not->toBe(0);
        expect($applyOutput)->toContain('No mutation was performed');

        // The flag is still there: only an operator decides the window is over.
        expect(is_file(repairTargetRoot($scratch).'/shared/storage/framework/down'))->toBeTrue();
    } finally {
        repairCleanup($scratch);
    }
});

it('reports a held target as one problem rather than two', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairGuard($scratch, 'held');
        touch(repairTargetRoot($scratch).'/shared/storage/framework/down');

        [, $output] = repairRun(['--check', '--target', 'staging-main'], $env);

        // A held target is SUPPOSED to be in maintenance. Counting that as a
        // second, independent blocker would misrepresent one situation.
        expect(substr_count($output, 'RESTORE-HOLD'))->toBe(1);
        expect($output)->not->toContain('MAINTENANCE  runtime:maintenance');
        expect($output)->toContain('BLOCKED: 1');
    } finally {
        repairCleanup($scratch);
    }
});

it('refuses a target with no canonical deployed release, in every shape', function () {
    $shapes = [
        'no current link' => function (string $root) {
            unlink($root.'/current');
        },
        'current is a directory' => function (string $root) {
            unlink($root.'/current');
            mkdir($root.'/current');
        },
        'current resolves outside the releases directory' => function (string $root) {
            unlink($root.'/current');
            mkdir($root.'/elsewhere');
            symlink('elsewhere', $root.'/current');
        },
        'release has no release.json' => function (string $root) {
            unlink($root.'/releases/20260101120000/release.json');
        },
        'release.json carries no source_sha' => function (string $root) {
            file_put_contents($root.'/releases/20260101120000/release.json', json_encode(['release' => 'x'])."\n");
        },
        'release contains no artisan' => function (string $root) {
            unlink($root.'/releases/20260101120000/artisan');
        },
        'shared/.env is a symlink' => function (string $root) {
            unlink($root.'/shared/.env');
            symlink('/etc/hostname', $root.'/shared/.env');
        },
    ];

    foreach ($shapes as $description => $break) {
        $scratch = repairScratchDir();

        try {
            $env = repairFixture($scratch);
            $break(repairTargetRoot($scratch));

            [$checkExit, $checkOutput] = repairRun(['--check', '--target', 'staging-main'], $env);

            expect($checkExit)->toBe(1, "{$description}:\n{$checkOutput}");
            expect($checkOutput)->toContain('CONFLICT     release:current');
            expect($checkOutput)->toContain('never picks a release for you');

            // With no canonical release there is nothing to converge AROUND,
            // so the infrastructure sections are not even reported.
            expect($checkOutput)->not->toContain('TARGET INFRASTRUCTURE');

            [$applyExit, $applyOutput] = repairRun(['--apply', '--target', 'staging-main'], $env);

            expect($applyExit)->not->toBe(0, "{$description}:\n{$applyOutput}");
            expect(repairCalls($scratch))->not->toContain('--apply');
        } finally {
            repairCleanup($scratch);
        }
    }
});

it('refuses a target whose own lock directory is missing, rather than failing on the redirection', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        // The one piece of the layout a repair needs BEFORE it can converge
        // the layout. There is no way around the ordering: taking the lock is
        // what makes converging safe.
        exec('rm -rf '.escapeshellarg(repairTargetRoot($scratch).'/locks'));

        [$checkExit, $checkOutput] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($checkExit)->toBe(1, $checkOutput);
        expect($checkOutput)->toContain('CONFLICT     release:lock');
        expect($checkOutput)->toContain('install-bootstrap-host-layout --apply --target staging-main');

        [$applyExit, $applyOutput] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->not->toBe(0);
        expect($applyOutput)->toContain('cannot be taken');
        expect($applyOutput)->toContain('No mutation was performed');

        // A bare shell redirection error would be an unhelpful diagnosis on a
        // directory a repair is supposed to be told about.
        expect($applyOutput)->not->toContain('No such file or directory');
        expect(repairCalls($scratch))->not->toContain('--apply');
    } finally {
        repairCleanup($scratch);
    }
});

it('refuses host-level damage instead of becoming a host bootstrap', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'host-layout-hostreq');

        [$checkExit, $checkOutput] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($checkExit)->toBe(1, $checkOutput);
        expect($checkOutput)->toContain('HOST-REQ     layout:install-bootstrap-host-layout');
        expect($checkOutput)->toContain('WITHOUT --target');
        expect($checkOutput)->toContain('REPAIR REQUIRED: BLOCKED');

        [$applyExit, $applyOutput] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->not->toBe(0);
        expect($applyOutput)->toContain('host-level prerequisites are not satisfied');
        expect($applyOutput)->toContain('No mutation was performed');
        expect(repairCalls($scratch))->not->toContain('--apply');
    } finally {
        repairCleanup($scratch);
    }
});

it('refuses drift that is never converged automatically, and a child that cannot report', function () {
    foreach (['conflict' => 'never repaired automatically', 'broken' => 'could not even be established'] as $toggle => $needle) {
        $scratch = repairScratchDir();

        try {
            $env = repairFixture($scratch);
            repairToggle($scratch, 'services-'.$toggle);

            [$checkExit, $checkOutput] = repairRun(['--check', '--target', 'staging-main'], $env);

            expect($checkExit)->toBe(1, $checkOutput);
            expect($checkOutput)->toContain('CONFLICT     services:install-bootstrap-services');
            expect($checkOutput)->toContain('REPAIR REQUIRED: BLOCKED');

            [$applyExit, $applyOutput] = repairRun(['--apply', '--target', 'staging-main'], $env);

            expect($applyExit)->not->toBe(0);
            expect($applyOutput)->toContain($needle);
            expect($applyOutput)->toContain('No mutation was performed');
            expect(repairCalls($scratch))->not->toContain('--apply');
        } finally {
            repairCleanup($scratch);
        }
    }
});

it('collects every blocker it can judge before anything is converged', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairGuard($scratch, 'in-progress');
        repairToggle($scratch, 'host-layout-hostreq');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('2 blocker(s)');
        expect($output)->toContain('restore guard:');
        expect($output)->toContain('host-layout:');
        expect($output)->toContain('No mutation was performed');
        expect(repairCalls($scratch))->not->toContain('--apply');
    } finally {
        repairCleanup($scratch);
    }
});

it('repairs layout drift even though the services contract depends on that layout', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        // The situation this whole operation exists for. The services
        // installer verifies this target's layout as its own prerequisite, so
        // while the layout is broken its contract report describes exactly the
        // drift about to be repaired. Judging that report before converging
        // the layout would make a repair refuse its primary case.
        repairToggle($scratch, 'host-layout-drift');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, "layout drift must not block itself:\n{$output}");
        expect($output)->toContain('CHANGED: TRUE');
        expect($output)->toContain('re-reading the services contract now that the layout is converged');

        $calls = repairCalls($scratch);

        expect($calls)->toContain('host-layout --apply --target staging-main');

        // The services contract is judged BEFORE any mutation, and read again
        // afterwards only to decide whether it still needs applying.
        expect(strpos($calls, 'services --check'))
            ->toBeLessThan(strpos($calls, 'host-layout --apply'));
        expect(strrpos($calls, 'services --check'))
            ->toBeGreaterThan(strpos($calls, 'host-layout --apply'));
    } finally {
        repairCleanup($scratch);
    }
});

it('mutates nothing when the services contract is unrepairable, even with layout drift to fix', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'host-layout-drift');
        repairToggle($scratch, 'services-conflict');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('No mutation was performed');

        // The guarantee this restores: converging the layout and only then
        // discovering the services contract is unrepairable would leave a
        // target partially repaired, still broken, and different from what the
        // operator inspected. Both contracts are judged first.
        $calls = repairCalls($scratch);

        expect($calls)->not->toContain('--apply --target');
        expect($calls)->toContain('host-layout --check');
        expect($calls)->toContain('services --check');
    } finally {
        repairCleanup($scratch);
    }
});

it('repairs the service-log directory it owns without failing its own immutability proof', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-drift');

        // install-bootstrap-services owns shared/storage/logs and may create
        // it, chown it and chmod it — that is one of the things a repair is
        // for. Counting it as user data would make a legitimate repair fail.
        repairToggle($scratch, 'repair-service-logs');
        chmod(repairTargetRoot($scratch).'/shared/storage/logs', 0o700);

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, "repairing the service-log directory must not fail the repair:\n{$output}");
        expect($output)->toContain('STORAGE STRUCTURE: unchanged');

        // The directory really was converged.
        expect(substr(sprintf('%o', fileperms(repairTargetRoot($scratch).'/shared/storage/logs')), -4))
            ->toBe('2770');

        // And everything that IS user data is still guarded: an entry created
        // beside it still fails the repair.
        repairToggle($scratch, 'services-drift');
        repairToggle($scratch, 'sabotage-storage');

        [$sabotagedExit, $sabotagedOutput] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($sabotagedExit)->not->toBe(0);
        expect($sabotagedOutput)->toContain('shared storage structure changed during the repair');
    } finally {
        repairCleanup($scratch);
    }
});

it('reads an installer that aborted as a tooling failure, never as repairable drift', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-errors-only');

        [$exit, $output] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1, $output);

        // The installers abort through fail(), which prints ERROR: and exits
        // 1 — the same exit code an ordinary "contract not satisfied" report
        // uses. Told apart only by the exit code, an unreadable registry would
        // be read as repairable drift.
        expect($output)->toContain('CONFLICT     services:install-bootstrap-services');
        expect($output)->toContain('aborted rather than reporting a contract');
        expect($output)->not->toContain('DRIFT        services:install-bootstrap-services');

        // And the summary still prints: the report filters the child's output
        // for specific lines, and under `set -e` with `pipefail` a filter that
        // matches nothing would take the run down before it.
        expect($output)->toContain('SUMMARY');
        expect($output)->toContain('REPAIR REQUIRED: BLOCKED');
    } finally {
        repairCleanup($scratch);
    }
});

it('mutates nothing when an installer aborts, even with layout drift to fix', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'host-layout-drift');
        repairToggle($scratch, 'services-errors-only');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('No mutation was performed');
        expect($output)->toContain('aborted rather than reporting a contract');

        // The layout really did have repairable drift, and it stays unrepaired:
        // an installer that could not run tells us nothing about whether the
        // rest of the target is repairable, so nothing is converged on the
        // strength of a guess.
        expect(repairCalls($scratch))->not->toContain('--apply --target');
    } finally {
        repairCleanup($scratch);
    }
});

// =============================================================================
// --apply: orchestration, and the proof that nothing else moved
// =============================================================================

it('converges only the child that drifted, and reports honestly when nothing did', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-drift');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('CHANGED: TRUE');

        $calls = repairCalls($scratch);

        expect($calls)->toContain('services --apply --target staging-main');
        expect($calls)->not->toContain('host-layout --apply');

        // Each child's contract is read exactly once: the plan an operator was
        // shown is the plan that ran.
        expect(substr_count($calls, 'host-layout --check --target staging-main'))->toBe(1);
        expect(substr_count($calls, 'services --check --target staging-main'))->toBe(1);

        // A second run has nothing to do and says so.
        repairResetCalls($scratch);

        [$secondExit, $secondOutput] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($secondExit)->toBe(0, $secondOutput);
        expect($secondOutput)->toContain('CHANGED: FALSE');
        expect($secondOutput)->toContain('no target-scoped drift to converge');
        expect(repairCalls($scratch))->not->toContain('--apply --target');
    } finally {
        repairCleanup($scratch);
    }
});

it('converges layout before services', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'host-layout-drift');
        repairToggle($scratch, 'services-drift');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);

        $calls = repairCalls($scratch);

        // Identities and directories must exist before the services that run
        // inside them are configured.
        expect(strpos($calls, 'host-layout --apply'))->toBeLessThan(strpos($calls, 'services --apply'));
    } finally {
        repairCleanup($scratch);
    }
});

it('starts only this target program group, and never the whole Supervisor', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-drift');
        repairToggle($scratch, 'queue-down');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain("this target's group only");

        $calls = repairCalls($scratch);

        expect($calls)->toContain('supervisorctl start rateguru-staging-queue:*');
        expect($calls)->not->toContain('start all');
        expect($calls)->not->toContain('supervisorctl restart');
        expect($calls)->not->toContain('supervisorctl reload');
        expect($calls)->not->toContain('supervisorctl shutdown');
    } finally {
        repairCleanup($scratch);
    }
});

it('fails the repair when the queue cannot be proven running afterwards', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-drift');
        repairToggle($scratch, 'queue-down');
        repairToggle($scratch, 'queue-unstartable');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('could not be proven RUNNING');
        expect($output)->toContain('reporting it as repaired would be false');

        // A failed repair emits no success result.
        expect($output)->not->toContain('RATEGURU_REPAIR_RESULT=');
    } finally {
        repairCleanup($scratch);
    }
});

it('fails the repair when the health check still fails afterwards', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-drift');
        repairToggle($scratch, 'health-fail');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('health check failed after the repair');
        expect($output)->not->toContain('RATEGURU_REPAIR_RESULT=');
    } finally {
        repairCleanup($scratch);
    }
});

it('stops at the first failing child and never runs the next repair step', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'host-layout-drift');
        repairToggle($scratch, 'services-drift');
        repairToggle($scratch, 'host-layout-apply-fail');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('no further repair step ran');
        expect(repairCalls($scratch))->not->toContain('services --apply');
    } finally {
        repairCleanup($scratch);
    }
});

it('never runs a migration, a build, a deployment or a data operation', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'host-layout-drift');
        repairToggle($scratch, 'services-drift');
        repairToggle($scratch, 'queue-down');

        [$exit] = repairRun(['--apply', '--target', 'staging-main'], $env);
        expect($exit)->toBe(0);

        $calls = repairCalls($scratch);

        foreach (['migrate', 'artisan', 'composer', 'npm', 'psql', 'pg_dump', 'pg_restore', 'rclone', 'tar '] as $forbidden) {
            expect($calls)->not->toContain($forbidden);
        }
    } finally {
        repairCleanup($scratch);
    }
});

it('proves afterwards that the code, the pointers, the environment file and the storage structure are untouched', function () {
    $sabotages = [
        'sabotage-env' => 'shared/.env changed during the repair',
        'sabotage-storage' => 'shared storage structure changed during the repair',
        'sabotage-current' => 'current changed during the repair',
        'sabotage-previous' => 'previous changed during the repair',
        'sabotage-release-json' => 'release identity changed during the repair',
        'sabotage-scheduler' => 'is still absent after the service convergence',
    ];

    foreach ($sabotages as $toggle => $needle) {
        $scratch = repairScratchDir();

        try {
            $env = repairFixture($scratch);
            repairToggle($scratch, 'services-drift');
            repairToggle($scratch, $toggle);

            [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

            expect($exit)->not->toBe(0, "{$toggle} must fail the repair:\n{$output}");
            expect($output)->toContain($needle);
            expect($output)->not->toContain('RATEGURU_REPAIR_RESULT=');
        } finally {
            repairCleanup($scratch);
        }
    }
});

it('leaves everything it must not change byte-identical on a successful repair', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'host-layout-drift');
        repairToggle($scratch, 'services-drift');
        repairToggle($scratch, 'queue-down');

        $before = repairImmutableSnapshot($scratch);

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);
        expect(repairImmutableSnapshot($scratch))->toBe($before);
    } finally {
        repairCleanup($scratch);
    }
});

it('takes the target own deployment lock, so a deploy and a repair cannot overlap', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        // Hold the lock the way deploy, rollback and cleanup hold it.
        $lock = repairTargetRoot($scratch).'/locks/deployment.lock';
        touch($lock);

        $holder = proc_open(
            ['bash', '-c', 'exec 200>"$1"; flock -n 200 || exit 9; sleep 30', 'bash', $lock],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $holderPipes,
        );

        usleep(300_000);

        try {
            [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

            expect($exit)->not->toBe(0);
            expect($output)->toContain('another deployment operation is already running');
            expect(repairCalls($scratch))->not->toContain('--apply');
        } finally {
            proc_terminate($holder);
            fclose($holderPipes[1]);
            proc_close($holder);
        }
    } finally {
        repairCleanup($scratch);
    }
})->skip(fn () => trim(shell_exec('command -v flock 2>/dev/null') ?? '') === '', 'flock is unavailable');

it('records the repair in the target own deployment history', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-drift');

        [$exit] = repairRun(['--apply', '--target', 'staging-main'], $env);
        expect($exit)->toBe(0);

        $history = File::get(repairTargetRoot($scratch).'/deployments/history.jsonl');
        $entry = json_decode(trim($history), true, 512, JSON_THROW_ON_ERROR);

        expect($entry['event'])->toBe('repair');
        expect($entry['status'])->toBe('completed');
        expect($entry['release'])->toBe('20260101120000');
    } finally {
        repairCleanup($scratch);
    }
});

// =============================================================================
// The machine-readable result
// =============================================================================

it('emits exactly one machine-readable result on a completed repair', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);
        repairToggle($scratch, 'services-drift');

        [$exit, $output] = repairRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);
        expect(substr_count($output, 'RATEGURU_REPAIR_RESULT='))->toBe(1);

        expect(repairResult($output))->toBe([
            'status' => 'completed',
            'target' => 'staging-main',
            'environment' => 'staging',
            'changed' => true,
            'current_release' => '20260101120000',
            'source_sha' => 'a1b2c3d',
            'health' => 'pass',
            'queue' => 'running',
            'scheduler' => 'present',
        ]);
    } finally {
        repairCleanup($scratch);
    }
});

it('emits a verified result only when the contract actually holds', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        [$exit, $output] = repairRun(['--verify', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);

        $result = repairResult($output);

        expect($result['status'])->toBe('verified');
        expect($result['changed'])->toBeFalse();
        expect($result['current_release'])->toBe('20260101120000');

        // A target that does not satisfy the contract produces no result at
        // all: the line means "verified", never "inspected".
        repairToggle($scratch, 'services-drift');

        [$driftExit, $driftOutput] = repairRun(['--verify', '--target', 'staging-main'], $env);

        expect($driftExit)->toBe(1);
        expect($driftOutput)->not->toContain('RATEGURU_REPAIR_RESULT=');
    } finally {
        repairCleanup($scratch);
    }
});

it('never emits a result from --check, which is an inspection and not an outcome', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        [$exit, $output] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0);
        expect($output)->not->toContain('RATEGURU_REPAIR_RESULT=');
    } finally {
        repairCleanup($scratch);
    }
});

// =============================================================================
// Structural guarantees
// =============================================================================

it('honors its overrides only alongside the explicit test-override gate', function () {
    $scratch = repairScratchDir();

    try {
        $env = repairFixture($scratch);

        // Without the gate the script resolves its installed defaults, which
        // do not exist here — so it must fail rather than silently obeying a
        // stray RATEGURU_* variable in a real root shell.
        unset($env['RATEGURU_ALLOW_TEST_OVERRIDES']);

        [$exit, $output] = repairRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0, $output);
        expect($output)->not->toContain('REPAIR REQUIRED: NO');
        expect(repairCalls($scratch))->toBe('');
    } finally {
        repairCleanup($scratch);
    }
});

it('implements no layout or service check of its own', function () {
    $source = repairSource();

    // Every convergence is delegated. If any of these ever appears here, this
    // file has started being a second implementation of a contract that
    // already has an owner.
    foreach ([
        'sites-enabled',
        'sites-available',
        'php-fpm',
        'pool.d',
        'setfacl',
        'getfacl',
        'usermod',
        'groupadd',
        'useradd',
        'nginx -t',
        'sshd -t',
        'systemctl',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // And it never learns a database credential: the "a repair never touches
    // the database" guarantee is structural, not a promise.
    foreach (['DB_PASSWORD', 'DB_USERNAME', 'PGPASSWORD', 'psql', 'pg_dump'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('delegates to the installers from its own bundle, never from the deployed release', function () {
    $source = repairSource();

    // The release under `current` is the thing being repaired around; it may
    // itself be damaged or stale, and it must never define what "repaired"
    // means.
    expect($source)->toContain('SCRIPT_DIR');
    expect($source)->not->toContain('/current/infrastructure');
});
