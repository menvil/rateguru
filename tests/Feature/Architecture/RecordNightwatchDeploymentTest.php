<?php

use Illuminate\Support\Facades\File;

/**
 * the deployment observability work: infrastructure/scripts/record-nightwatch-deployment and its
 * generic sudo wrapper — the narrow server-side primitive that records ONE
 * already-completed deployment state transition in Laravel Nightwatch.
 *
 * Every test runs the real, shipped script as a subprocess against a fixture
 * target tree and a stub `runuser`, through the RATEGURU_* overrides the script
 * honors only alongside RATEGURU_ALLOW_TEST_OVERRIDES=true.
 *
 * What matters architecturally: the identity gate (the caller's asserted
 * release and commit must match what the server is ACTUALLY serving before
 * anything is executed), the closed argument set, the single hard-coded artisan
 * command, and the fact that nothing here can become a general "run something
 * on the server" facility.
 */

// =============================================================================
// The installed package contract — never guessed
// =============================================================================

it('uses the deployment primitive the installed Nightwatch package actually provides', function () {
    $command = base_path('vendor/laravel/nightwatch/src/Console/DeployCommand.php');

    expect(File::exists($command))->toBeTrue(
        'laravel/nightwatch must ship a deployment command; re-check the package before changing the integration');

    $source = File::get($command);

    // The exact supported interface, read off the installed implementation
    // rather than invented: a command name, a positional deploy identifier and
    // four options.
    expect($source)->toContain("name: 'nightwatch:deploy'");
    expect($source)->toContain('{deploy? :');
    expect($source)->toContain('{--ref=');
    expect($source)->toContain('{--name=');
    expect($source)->toContain('{--url=');
    expect($source)->toContain('{--timestamp=');

    // And the success line the server-side primitive requires as positive
    // confirmation. DeployCommand returns 0 even when the API rejects the
    // deployment, so this string is the only thing that distinguishes a
    // recorded marker from a silently dropped one. A package upgrade that
    // renames it must break this test rather than silently degrade telemetry.
    expect($source)->toContain('Deployment sent to Nightwatch successfully.');

    expect(File::get(base_path('infrastructure/scripts/record-nightwatch-deployment')))
        ->toContain('Deployment sent to Nightwatch successfully.');
});

it('lets the application report the same release identity Nightwatch is told about', function () {
    // config('nightwatch.deployment') already resolves to release.json's
    // release for every request this application serves, which is why the
    // marker uses that exact value as its deploy identifier: events and markers
    // land on one key.
    expect(File::get(base_path('config/nightwatch.php')))
        ->toContain('DeploymentMetadata::fromBasePath(dirname(__DIR__))->release()');
});

// =============================================================================
// Harness
// =============================================================================

function nwScript(): string
{
    return base_path('infrastructure/scripts/record-nightwatch-deployment');
}

function nwWrapper(): string
{
    return base_path('infrastructure/config/wrappers/rateguru-nightwatch-deployment');
}

function nwScratchDir(): string
{
    $dir = sys_get_temp_dir().'/record-nightwatch-'.uniqid('', true).'-'.getmypid();
    expect(@mkdir($dir.'/bin', 0o755, true))->toBeTrue();

    return $dir;
}

function nwCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * A minimal, self-contained stand-in for the installed `common` library: the
 * handful of helpers the script actually uses, backed by the repository's real
 * target registry so lifecycle and target resolution are exercised for real.
 */
function nwWriteCommon(string $scratch): string
{
    $registry = base_path('infrastructure/config/deployment-targets.json');
    $path = $scratch.'/common';

    file_put_contents($path, <<<COMMON
        #!/usr/bin/env bash
        RELEASE_ID_REGEX='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'
        PHP_BIN="\${STUB_PHP_BIN}"
        REGISTRY="{$registry}"

        log() { printf '[log] %s\\n' "\$*"; }
        fail() { printf 'ERROR: %s\\n' "\$*" >&2; exit 1; }
        require_root() { [[ "\${STUB_EUID:-0}" == 0 ]] || fail "this command must be executed as root"; }
        acquire_deployment_lock() {
            exec 200>"\$1/locks/deployment.lock"
            flock -n 200 || fail "another deployment operation is already running"
        }
        rateguru_test_overrides_allowed() { [[ "\${RATEGURU_ALLOW_TEST_OVERRIDES:-false}" == true ]]; }
        validate_release_id() {
            [[ "\$1" =~ \${RELEASE_ID_REGEX} ]] || fail "invalid release ID: \$1"
        }
        require_flag_value() {
            local flag="\$1" remaining="\$2" value="\${3-}"
            [[ "\${remaining}" -ge 2 ]] || fail "\${flag} requires a value"
            [[ -n "\${value}" ]] || fail "\${flag} requires a non-empty value"
            case "\${value}" in -*) fail "\${flag} requires a value, not another option: \${value}" ;; esac
        }
        _prop() { jq -r --arg id "\$1" ".targets[\\\$id].\$2 // empty" "\${REGISTRY}"; }
        target_lifecycle() { _prop "\$1" 'lifecycle'; }
        target_deploy_user() { _prop "\$1" 'deploy_user'; }
        target_runtime_user() { _prop "\$1" 'runtime_user'; }
        target_root() { printf '%s\\n' "\${STUB_TARGET_ROOT}"; }
        require_active_target() {
            local lifecycle
            [[ "\$1" =~ ^[a-z0-9][a-z0-9-]{1,31}\$ ]] || fail "invalid target ID: \$1"
            lifecycle="\$(target_lifecycle "\$1")"
            [[ -n "\${lifecycle}" ]] || fail "unknown target: \$1"
            [[ "\${lifecycle}" == active ]] || fail "target \$1 has lifecycle=\${lifecycle}, not active"
        }
        COMMON);

    return $path;
}

/**
 * A target tree serving one release, with the metadata the identity gate reads.
 *
 * @param  array<string, mixed>  $options
 */
function nwTargetRoot(string $scratch, array $options = []): string
{
    $release = $options['release'] ?? 'v0.0.0-20260101-120000-abc1234';
    $metadataRelease = $options['metadata_release'] ?? $release;
    $sha = $options['metadata_sha'] ?? 'abc1234def5678';

    $root = $scratch.'/target';
    $releaseRoot = $root.'/releases/'.$release;

    mkdir($releaseRoot, 0o755, true);
    // The deployment lock directory install-bootstrap-host-layout creates on a real host.
    mkdir($root.'/locks', 0o755, true);
    touch($releaseRoot.'/artisan');
    file_put_contents($releaseRoot.'/release.json', json_encode([
        'release' => $metadataRelease,
        'source_sha' => $sha,
    ], JSON_PRETTY_PRINT));

    if ($options['current_outside_releases'] ?? false) {
        // A release-shaped directory somewhere else entirely: same basename,
        // same release.json, but not a release this target owns.
        $foreign = $scratch.'/foreign/'.$release;
        mkdir($foreign, 0o755, true);
        touch($foreign.'/artisan');
        copy($releaseRoot.'/release.json', $foreign.'/release.json');
        symlink($foreign, $root.'/current');

        return $root;
    }

    if ($options['current'] ?? true) {
        symlink($releaseRoot, $root.'/current');
    }

    return $root;
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function nwRun(array $arguments, array $env, ?string $script = null): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', $script ?? nwScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/**
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function nwFixture(string $scratch, array $options = []): array
{
    $common = nwWriteCommon($scratch);
    $root = nwTargetRoot($scratch, $options);

    // Stub runuser: logs its full argv, then either prints the package's real
    // success line or simulates a rejected send (which the real command also
    // reports with exit status 0).
    $runuser = $scratch.'/bin/runuser';
    file_put_contents($runuser, <<<'STUB'
        #!/bin/bash
        printf '%s\n' "$*" >> "${STUB_LOG}"
        if [[ -n "${STUB_NIGHTWATCH_REJECTS:-}" ]]; then
            echo "  ERROR  Deployment could not be sent to Nightwatch: [401] unauthorized."
            exit 0
        fi
        echo "  INFO  Deployment sent to Nightwatch successfully."
        exit 0
        STUB);
    chmod($runuser, 0o755);

    $php = $scratch.'/bin/php';
    file_put_contents($php, "#!/bin/bash\nexit 0\n");
    chmod($php, 0o755);

    return array_filter([
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => $common,
        'RATEGURU_NIGHTWATCH_RUNUSER_BIN' => $runuser,
        'STUB_TARGET_ROOT' => $root,
        'STUB_PHP_BIN' => $php,
        'STUB_LOG' => $scratch.'/runuser.log',
        'STUB_EUID' => $options['euid'] ?? '0',
        'STUB_NIGHTWATCH_REJECTS' => $options['nightwatch_rejects'] ?? null,
    ], static fn ($value): bool => $value !== null);
}

function nwRunuserArgs(string $scratch): array
{
    $path = $scratch.'/runuser.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

// =============================================================================
// Shipping and CLI contract
// =============================================================================

it('ships the primitive and its wrapper as executable scripts', function () {
    foreach ([nwScript(), nwWrapper()] as $path) {
        expect(File::exists($path))->toBeTrue();
        expect(is_executable($path))->toBeTrue("{$path} must be executable");
    }
});

it('requires --target, --release and --source-sha', function () {
    $scratch = nwScratchDir();

    try {
        $env = nwFixture($scratch);

        $cases = [
            [[], '--target is required'],
            [['--target', 'staging-main'], '--release is required'],
            [['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234'], '--source-sha is required'],
        ];

        foreach ($cases as [$arguments, $expected]) {
            [$exit, $output] = nwRun($arguments, $env);

            expect($exit)->toBe(1);
            expect($output)->toContain($expected);
        }
    } finally {
        nwCleanup($scratch);
    }
});

it('rejects an unknown flag rather than passing it through to artisan', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678', '--command', 'migrate'],
            nwFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('unknown argument: --command');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('requires root', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234', '--source-sha', 'abc1234def5678'],
            nwFixture($scratch, ['euid' => '1000']),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('must be executed as root');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('rejects a lifecycle=planned target before anything runs', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'tits-guru', '--release', 'v0.0.0-20260101-120000-abc1234', '--source-sha', 'abc1234def5678'],
            nwFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('lifecycle=planned, not active');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

// =============================================================================
// The identity gate
// =============================================================================

it('records the marker for a release the target is genuinely serving', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678'],
            nwFixture($scratch),
        );

        expect($exit)->toBe(0);
        expect($output)->toContain('verified active release v0.0.0-20260101-120000-abc1234');
        expect($output)->toContain('Nightwatch deployment marker recorded');

        $invocation = nwRunuserArgs($scratch)[0] ?? '';

        // Exactly one artisan command, spelled as a literal in the script, with
        // the release as the deploy identifier and the commit as the ref.
        expect($invocation)->toContain('artisan nightwatch:deploy v0.0.0-20260101-120000-abc1234');
        expect($invocation)->toContain('--ref abc1234def5678');
        expect($invocation)->toContain('-u rateguru-staging');
    } finally {
        nwCleanup($scratch);
    }
});

it('refuses to record a release the server is not actually serving', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v9.9.9-20260101-120000-fff9999',
                '--source-sha', 'abc1234def5678'],
            nwFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('is serving v0.0.0-20260101-120000-abc1234, not the asserted v9.9.9-20260101-120000-fff9999');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('refuses when the asserted commit disagrees with the active release.json', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'deadbee'],
            nwFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('release metadata records source SHA abc1234def5678, not the asserted deadbee');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('refuses when release.json disagrees with the current symlink', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678'],
            nwFixture($scratch, ['metadata_release' => 'v0.0.0-20250101-120000-9999999']),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('release metadata names v0.0.0-20250101-120000-9999999');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('records the run URL and a human-readable name alongside the release', function () {
    $scratch = nwScratchDir();

    try {
        [$exit] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678',
                '--run-url', 'https://github.com/menvil/RateGuru/actions/runs/123456'],
            nwFixture($scratch),
        );

        expect($exit)->toBe(0);

        $invocation = nwRunuserArgs($scratch)[0] ?? '';

        // The correlation half of the marker: the operation that produced the
        // transition, and a name an operator can read in the Nightwatch UI.
        expect($invocation)->toContain('--url https://github.com/menvil/RateGuru/actions/runs/123456');
        expect($invocation)->toContain('--name staging-main v0.0.0-20260101-120000-abc1234');
    } finally {
        nwCleanup($scratch);
    }
});

it('refuses a current symlink that resolves outside the target releases directory', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678'],
            nwFixture($scratch, ['current_outside_releases' => true]),
        );

        // A basename match is not ownership: this command runs that tree's
        // artisan as the runtime user.
        expect($exit)->toBe(1);
        expect($output)->toContain('current resolves outside');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('refuses a symlinked releases root, which would normalize a foreign tree into containment', function () {
    $scratch = nwScratchDir();

    try {
        $env = nwFixture($scratch);

        // With releases/ itself a link, `readlink -f` would resolve a foreign
        // tree to a path that looks contained — defeating the containment
        // check rather than satisfying it.
        $root = $scratch.'/target';
        exec('mv '.escapeshellarg($root.'/releases').' '.escapeshellarg($scratch.'/elsewhere'));
        symlink($scratch.'/elsewhere', $root.'/releases');

        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678'],
            $env,
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('releases root must not be a symlink');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('takes the target deployment lock, so a concurrent deploy cannot decorrelate the marker', function () {
    $scratch = nwScratchDir();

    try {
        $env = nwFixture($scratch);

        // Hold the same lock a deploy or rollback would hold. Without the
        // lock, `current` could be switched between the identity check and the
        // artisan call, and Nightwatch would be told about a release the
        // target had already stopped serving.
        $lockPath = $scratch.'/target/locks/deployment.lock';
        $lock = fopen($lockPath, 'c');
        expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();

        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678'],
            $env,
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('another deployment operation is already running');
        expect(nwRunuserArgs($scratch))->toBe([]);

        flock($lock, LOCK_UN);
        fclose($lock);

        // And it succeeds again the moment the lock is free.
        [$exit] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678'],
            $env,
        );

        expect($exit)->toBe(0);
    } finally {
        nwCleanup($scratch);
    }
});

it('refuses when the target has no current release at all', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678'],
            nwFixture($scratch, ['current' => false]),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('no current release symlink');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('rejects a malformed release ID and a malformed source SHA', function () {
    $scratch = nwScratchDir();

    try {
        $env = nwFixture($scratch);

        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'rollback-123', '--source-sha', 'abc1234def5678'],
            $env,
        );
        expect($exit)->toBe(1);
        expect($output)->toContain('invalid release ID: rollback-123');

        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234', '--source-sha', 'not-a-sha'],
            $env,
        );
        expect($exit)->toBe(1);
        expect($output)->toContain('invalid source SHA: not-a-sha');

        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('rejects a run URL that is not a GitHub run URL', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678', '--run-url', 'https://evil.example/x'],
            nwFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('invalid --run-url');
        expect(nwRunuserArgs($scratch))->toBe([]);
    } finally {
        nwCleanup($scratch);
    }
});

it('reports an unconfirmed send as a failure rather than a fabricated success', function () {
    $scratch = nwScratchDir();

    try {
        [$exit, $output] = nwRun(
            ['--target', 'staging-main', '--release', 'v0.0.0-20260101-120000-abc1234',
                '--source-sha', 'abc1234def5678'],
            nwFixture($scratch, ['nightwatch_rejects' => '1']),
        );

        // DeployCommand exits 0 even when the API rejects it, so a naive
        // implementation would report success here.
        expect($exit)->toBe(1);
        expect($output)->toContain('Nightwatch did not confirm the deployment marker');
        expect($output)->toContain('the deployment itself is unaffected and must not be rolled back for this');
    } finally {
        nwCleanup($scratch);
    }
});

// =============================================================================
// No arbitrary command is reachable
// =============================================================================

it('can only ever run one artisan command, spelled as a literal', function () {
    $source = File::get(nwScript());

    expect($source)->toContain('NIGHTWATCH_COMMAND="nightwatch:deploy"');

    // The single executable invocation passes an argv array whose first
    // element is that constant; no artisan subcommand is ever spelled at a
    // call site. (Prose in the header and the usage text legitimately names
    // the command, so only real invocations are examined.)
    preg_match_all('/"\$\{PHP_BIN\}"\s+artisan\s+(\S+)/', $source, $matches);

    expect($matches[1])->toBe(['"${artisan_args[@]}"']);

    // And the invocation is a fixed argv array, never an interpolated string
    // handed to a shell.
    expect($source)->toContain('local -a artisan_args=(');
    expect($source)->not->toMatch('/\beval\b/');
    expect($source)->not->toMatch('/bash\s+-c/');
    expect($source)->not->toMatch('/sh\s+-c/');
});

it('exposes only the closed flag set through the sudo wrapper', function () {
    $wrapper = File::get(nwWrapper());

    // The wrapper mirrors the accepted rateguru-deploy / rateguru-rollback
    // shape exactly: --target is parsed, everything else is passed through
    // unexamined to a binary that validates each value against a closed
    // format, and the environment is scrubbed before exec.
    expect($wrapper)->toContain('exec env -i');
    expect($wrapper)->toContain('PATH="${PRODUCTION_PATH}"');
    expect($wrapper)->toContain('require_active_target "${TARGET_ID}"');
    expect($wrapper)->toContain('authorize_caller');
    expect($wrapper)->toContain('/home/www/rateguru/bin/record-nightwatch-deployment');

    expect($wrapper)->not->toMatch('/\beval\b/');
    expect($wrapper)->not->toMatch('/bash\s+-c/');
});

it('grants the deploy user the marker wrapper and nothing else', function () {
    $sudoers = File::get(base_path('infrastructure/config/sudoers/rateguru-nightwatch-deployment'));

    expect($sudoers)->toContain('/usr/local/sbin/rateguru-nightwatch-deployment');
    expect($sudoers)->toContain('deploy-rateguru-staging');

    // The observability grant must never widen the deploy user's operational
    // privileges under cover of telemetry, and must never reach a production
    // deploy user while tits-guru stays lifecycle=planned.
    foreach ([
        '/usr/local/sbin/rateguru-deploy',
        '/usr/local/sbin/rateguru-rollback',
        '/usr/local/sbin/rateguru-cleanup',
        'deploy-rateguru-tits-guru',
    ] as $forbidden) {
        expect($sudoers)->not->toContain($forbidden);
    }
});

it('keeps the whole Nightwatch integration removable in one step', function () {
    $installer = File::get(base_path('infrastructure/scripts/install-nightwatch-agent'));

    // The marker primitive, its wrapper and its sudoers grant are owned by the
    // Nightwatch installer — not by install-target-operations' bundle or
    // install-target-perimeter's wrappers — so the Nightwatch decision rejection removes the
    // entire integration with `--remove`, and no host is ever required to
    // carry any of it.
    // The full repository-relative paths, each asserted on its own: the three
    // files share a basename, so a basename check would pass on any one of
    // them and prove nothing about the other two.
    expect($installer)
        ->toContain('SRC_RECORD_BIN="${REPO_ROOT}/infrastructure/scripts/record-nightwatch-deployment"')
        ->toContain('SRC_MARKER_WRAPPER="${REPO_ROOT}/infrastructure/config/wrappers/rateguru-nightwatch-deployment"')
        ->toContain('SRC_MARKER_SUDOERS="${REPO_ROOT}/infrastructure/config/sudoers/rateguru-nightwatch-deployment"');

    // And each is backed up under its own key, because a basename-keyed backup
    // would have the sudoers grant and the wrapper share one slot — and a
    // rollback restore sudoers content over an executable.
    expect($installer)->toContain('backup_path="${TXN_MARKER_BACKUP_DIR}/$(backup_key "${dst}")"');

    expect($installer)->toContain('remove_marker_files');

    // The accepted the clean-host bootstrap contracts are untouched by Prepare Host.
    expect(File::get(base_path('infrastructure/scripts/install-target-operations')))
        ->not->toContain('record-nightwatch-deployment');
    expect(File::get(base_path('infrastructure/scripts/install-target-perimeter')))
        ->not->toContain('rateguru-nightwatch-deployment');
    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-deploy')))
        ->not->toContain('nightwatch');
});
