<?php

use Illuminate\Support\Facades\File;

/**
 * the Nightwatch evaluation: the Supervisor-managed Nightwatch agent, its committed program
 * file, its installer and the deploy transition that keeps it following
 * `current`.
 *
 * Everything here exercises the shipped artefacts — the real
 * install-nightwatch-agent, the real `common` allowlist and the real deploy
 * block — against a scratch filesystem with stubbed binaries. Nothing
 * reimplements a rule it is checking.
 */
function nightwatchInstallerScript(): string
{
    return base_path('infrastructure/scripts/install-nightwatch-agent');
}

function nightwatchProgramName(): string
{
    return 'rateguru-staging-nightwatch';
}

function nightwatchProgramFile(): string
{
    return base_path('infrastructure/config/supervisor/'.nightwatchProgramName().'.conf');
}

/** @return array<string, mixed> */
function nightwatchRegistryTarget(string $id = 'staging-main'): array
{
    $registry = json_decode(
        (string) File::get(base_path('infrastructure/config/deployment-targets.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $registry['targets'][$id];
}

function nightwatchScratch(): string
{
    $dir = sys_get_temp_dir().'/nightwatch-agent-'.uniqid('', true).'-'.getmypid();

    foreach ([
        '/bin',
        '/fs/etc/supervisor/conf.d',
        // the deployment observability work: the installer also owns the deployment-marker primitive,
        // its sudo wrapper and its sudoers grant. Their destination directories
        // are created by host bootstrap on a real host.
        '/fs/etc/sudoers.d',
        '/fs/usr/local/sbin',
        '/fs/home/www/rateguru/bin',
        '/log',
        '/state',
    ] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

/**
 * A deployed staging target under the scratch filesystem root: a real
 * `current` symlink into a release directory, a shared .env and the service
 * log directory the service bootstrap owns.
 *
 * @param  array<string, string>  $env  NIGHTWATCH_* keys for shared/.env
 */
function nightwatchDeployedTarget(string $scratch, array $env = []): string
{
    $root = $scratch.'/fs'.nightwatchRegistryTarget()['application_root'];

    foreach (['/releases/v1.0.0', '/shared/storage/logs'] as $sub) {
        expect(@mkdir($root.$sub, 0o755, true))->toBeTrue("could not create {$root}{$sub}");
    }

    symlink($root.'/releases/v1.0.0', $root.'/current');

    $env = array_merge([
        'NIGHTWATCH_ENABLED' => 'true',
        'NIGHTWATCH_TOKEN' => 'nw-scratch-token-never-printed',
        'NIGHTWATCH_INGEST_URI' => '127.0.0.1:2407',
    ], $env);

    $lines = '';
    foreach ($env as $key => $value) {
        if ($value !== null) {
            $lines .= "{$key}={$value}\n";
        }
    }

    file_put_contents($root.'/shared/.env', "APP_ENV=staging\n".$lines);

    return $root;
}

/**
 * Stubs for every binary the installer resolves through a gated override.
 *
 * `chown` and `chmod` only log: this test process is not root, so the real
 * `chown root:root` would fail. What the installer *asks for* is asserted from
 * those logs instead, which is the enforceable part without a privileged CI.
 */
function nightwatchStubs(string $scratch, array $options = []): void
{
    $state = $scratch.'/state';
    $log = $scratch.'/log';

    $listener = $options['listener'] ?? '127.0.0.1:2407';
    $statusExit = $options['status_exit'] ?? 0;

    file_put_contents($scratch.'/bin/supervisorctl', "#!/usr/bin/env bash\n"
        .'echo "supervisorctl $*" >> '.escapeshellarg($log.'/supervisorctl.log')."\n"
        .'state='.escapeshellarg($state)."\n"
        .'program='.escapeshellarg(nightwatchProgramName())."\n"
        .'conf='.escapeshellarg($scratch.'/fs/etc/supervisor/conf.d/'.nightwatchProgramName().'.conf')."\n"
        .<<<'STUB'
        case "${1:-}" in
            status)
                # Group-aware on purpose: answering for any requested group
                # would let the installer query the wrong program and still
                # look healthy.
                if [[ "${2:-}" != "${program}:*" ]]; then
                    echo "${2:-}: ERROR (no such process)"
                    exit 1
                fi
                if [[ -e "${state}/running" ]]; then
                    echo "${program}:${program}_00   RUNNING   pid 321, uptime 0:00:10"
                    exit 0
                fi
                if [[ -e "${state}/registered" ]]; then
                    echo "${program}:${program}_00   STOPPED   Not started"
                    exit 0
                fi
                echo "${program}:*: ERROR (no such process)"
                exit 1
                ;;
            reread)
                if [[ -e "${state}/reread-error" ]]; then
                    echo "ERROR: could not parse config"
                    exit 0
                fi
                echo "${program}: available"
                exit 0
                ;;
            restart)
                # restart does not reload configuration, so unlike update it
                # is indifferent to whether the program file is still on disk.
                if [[ -e "${state}/restart-fail" ]]; then
                    echo "ERROR (abnormal termination)"
                    exit 1
                fi
                if [[ -e "${state}/start-fail" ]]; then
                    rm -f "${state}/running"
                    exit 0
                fi
                touch "${state}/registered" "${state}/running"
                exit 0
                ;;
            update|start)
                # Models the real thing: update applies whatever config files
                # are on disk, so a program whose file has been deleted is
                # unregistered rather than restarted.
                if [[ ! -f "${conf}" ]]; then
                    rm -f "${state}/registered" "${state}/running"
                    exit 0
                fi
                if [[ -e "${state}/start-fail" ]]; then
                    exit 0
                fi
                touch "${state}/registered" "${state}/running"
                exit 0
                ;;
            stop)
                rm -f "${state}/running"
                exit 0
                ;;
        esac
        exit 0
        STUB."\n");
    chmod($scratch.'/bin/supervisorctl', 0o755);

    file_put_contents($scratch.'/bin/ss-stub', "#!/usr/bin/env bash\n"
        .'echo "ss $*" >> '.escapeshellarg($log.'/ss.log')."\n"
        .'if [[ -e '.escapeshellarg($state.'/no-listener')." ]]; then exit 0; fi\n"
        .'printf "LISTEN 0 128 %s 0.0.0.0:* users:((\"php8.5\",pid=321,fd=7))\n" '.escapeshellarg($listener)."\n");
    chmod($scratch.'/bin/ss-stub', 0o755);

    file_put_contents($scratch.'/bin/runuser-stub', "#!/usr/bin/env bash\n"
        .'echo "runuser $*" >> '.escapeshellarg($log.'/runuser.log')."\n"
        ."exit {$statusExit}\n");
    chmod($scratch.'/bin/runuser-stub', 0o755);

    file_put_contents($scratch.'/bin/chown-stub', "#!/usr/bin/env bash\n"
        .'echo "chown $*" >> '.escapeshellarg($log.'/chown.log')."\n"
        ."exit 0\n");
    chmod($scratch.'/bin/chown-stub', 0o755);

    // Log-only, and deliberately not delegating: the installer uses the GNU
    // `=MODE` operator (a plain numeric chmod preserves setgid, which is the
    // bug the rest of the RateGuru installers pin against) and BSD chmod on a
    // macOS developer machine rejects it outright. What is asserted is the
    // mode the installer asks for, which is the part this repository owns.
    file_put_contents($scratch.'/bin/chmod-stub', "#!/usr/bin/env bash\n"
        .'echo "chmod $*" >> '.escapeshellarg($log.'/chmod.log')."\n"
        ."exit 0\n");
    chmod($scratch.'/bin/chmod-stub', 0o755);

    // Reports root:root 0644 for any path. The installer's real ownership
    // enforcement is asserted from the chown/chmod logs in the apply test; a
    // negative case below points this at a stub that lies the other way, which
    // is what proves the check is not decorative.
    //
    // The mode answer is per-destination rather than a single constant: the
    // installer owns a 0644 Supervisor program, two 0755 executables and a
    // 0440 sudoers grant, and a stub that answered 644 everywhere would make
    // three of those four checks impossible to fail.
    $modeByDestination = 'case "$3" in'
        .' *"/etc/sudoers.d/"*) echo 440;;'
        .' *"/usr/local/sbin/"*|*"/rateguru/bin/"*) echo 755;;'
        .' *) echo 644;; esac';

    // Stubbed like every other external tool this file touches, so the suite
    // does not hard-depend on the `sudo` package being installed. The negative
    // case below points it at a stub that rejects, which is what proves the
    // installer's sudoers validation is not decorative.
    file_put_contents($scratch.'/bin/visudo-stub', "#!/usr/bin/env bash\nexit 0\n");
    chmod($scratch.'/bin/visudo-stub', 0o755);

    file_put_contents($scratch.'/bin/visudo-reject', "#!/usr/bin/env bash\n"
        ."echo 'parse error near line 1' >&2\nexit 1\n");
    chmod($scratch.'/bin/visudo-reject', 0o755);

    file_put_contents($scratch.'/bin/stat-root', "#!/usr/bin/env bash\n"
        .'case "${2:-}" in "%U") echo root;; "%G") echo root;; "%a") '.$modeByDestination.';; *) echo "";; esac'."\n");
    chmod($scratch.'/bin/stat-root', 0o755);

    file_put_contents($scratch.'/bin/stat-wrong-owner', "#!/usr/bin/env bash\n"
        .'case "${2:-}" in "%U") echo nobody;; "%G") echo root;; "%a") '.$modeByDestination.';; *) echo "";; esac'."\n");
    chmod($scratch.'/bin/stat-wrong-owner', 0o755);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $overrides
 * @return array{0: int, 1: string}
 */
function runNightwatchInstaller(string $scratch, array $arguments, array $overrides = []): array
{
    $env = array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => base_path('infrastructure/scripts/common'),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),
        'RATEGURU_TARGET_REGISTRY_FILE' => base_path('infrastructure/config/deployment-targets.json'),
        'RATEGURU_TARGETS_CLI' => base_path('infrastructure/scripts/targets'),
        'RATEGURU_NIGHTWATCH_EUID' => '0',
        'RATEGURU_NIGHTWATCH_FS_ROOT' => $scratch.'/fs',
        'RATEGURU_NIGHTWATCH_REPO_ROOT' => base_path(),
        'RATEGURU_NIGHTWATCH_SS_BIN' => $scratch.'/bin/ss-stub',
        'RATEGURU_NIGHTWATCH_RUNUSER_BIN' => $scratch.'/bin/runuser-stub',
        'RATEGURU_NIGHTWATCH_CHOWN_BIN' => $scratch.'/bin/chown-stub',
        'RATEGURU_NIGHTWATCH_CHMOD_BIN' => $scratch.'/bin/chmod-stub',
        'RATEGURU_NIGHTWATCH_STAT_BIN' => $scratch.'/bin/stat-root',
        'RATEGURU_NIGHTWATCH_VISUDO_BIN' => $scratch.'/bin/visudo-stub',
        'RATEGURU_NIGHTWATCH_WAIT_ATTEMPTS' => '1',
        'RATEGURU_NIGHTWATCH_RETRY_DELAY' => '0',
        'RATEGURU_NIGHTWATCH_STABILITY_WAIT' => '0',
    ], $overrides);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', nightwatchInstallerScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start the installer subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/**
 * Runs a snippet against the target-registry block extracted from `common`.
 *
 * `common` sources /home/www/rateguru/config/deployment.conf at the top, which
 * does not exist in CI, so the block is extracted and given a `fail` stub —
 * and the extraction markers double as proof the block is still delimited.
 * Deliberately local to this file: the target-registry test has its own,
 * older copy of this harness, and a helper shared between two test files would
 * have to move into tests/Pest.php to survive Pest's parallel runner.
 */
function nightwatchTargetHelper(string $snippet): string
{
    $common = File::get(base_path('infrastructure/scripts/common'));

    expect(preg_match(
        '/# --- deployment target registry \(begin\) ---\n(.*?)\n# --- deployment target registry \(end\) ---/s',
        $common,
        $matches,
    ))->toBe(1, 'could not locate the target registry block in scripts/common');

    $script = "set -uo pipefail\n"
        ."fail() { printf '[ERR] %s\\n' \"\$*\" >&2; exit 1; }\n"
        .$matches[1]."\n"
        .$snippet."\n";

    exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);

    return implode("\n", $output);
}

function nightwatchStubLog(string $scratch, string $name): string
{
    $path = $scratch.'/log/'.$name.'.log';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

// --- the committed Supervisor program ---------------------------------------

it('ships a Supervisor program derived from the registry, never from a hard-coded path', function () {
    $target = nightwatchRegistryTarget();
    $conf = File::get(nightwatchProgramFile());

    expect($conf)
        ->toContain('[program:'.nightwatchProgramName().']')
        // The working directory follows the deployment symlink, so the agent
        // moves with `current` instead of pinning the release it started in.
        ->toContain("directory={$target['application_root']}/current")
        ->toContain("user={$target['runtime_user']}")
        ->toContain('artisan nightwatch:agent')
        ->toContain('autostart=true')
        ->toContain('autorestart=true')
        ->toContain('redirect_stderr=true')
        ->toContain("stdout_logfile={$target['application_root']}/shared/storage/logs/nightwatch-agent.log");

    // Never a specific release: a pinned release directory would leave the
    // agent running out of a tree `cleanup` eventually deletes.
    expect($conf)->not->toContain('/releases/');

    // Never root, never the deploy account, never www-data.
    foreach (['user=root', 'user=deploy-', 'user=www-data'] as $forbidden) {
        expect($conf)->not->toContain($forbidden);
    }
});

it('pins no listen address in the Supervisor program, so it cannot contradict the .env', function () {
    // artisan nightwatch:agent takes its --listen-on default from
    // config('nightwatch.ingest.uri'), which is NIGHTWATCH_INGEST_URI — the
    // same value the application sends to and the installer validates. Pinning
    // a port in the program file is the one way those could ever disagree,
    // with the installer checking one address and the agent binding another.
    expect(File::get(nightwatchProgramFile()))->not->toContain('--listen-on');
});

it('keeps the environment token out of the Supervisor program entirely', function () {
    // A program file is root:root 0644 and is echoed verbatim by supervisorctl.
    // The token belongs in the target's shared .env, which is not world
    // readable, and is read from there through Laravel's own config.
    $conf = File::get(nightwatchProgramFile());

    expect($conf)
        ->not->toContain('NIGHTWATCH_TOKEN')
        ->not->toContain('NIGHTWATCH_INGEST_URI');
});

it('matches the conventions of the queue worker program beside it', function () {
    $agent = File::get(nightwatchProgramFile());
    $queue = File::get(base_path('infrastructure/config/supervisor/rateguru-staging-queue.conf'));

    foreach (['process_name=%(program_name)s_%(process_num)02d', 'numprocs=1', 'startsecs=3', 'stopasgroup=true', 'killasgroup=true', 'environment=APP_ENV="staging"'] as $convention) {
        expect($queue)->toContain($convention);
        expect($agent)->toContain($convention);
    }
});

// --- the allowlist ----------------------------------------------------------

it('names a Nightwatch program for staging-main and for no other target', function () {
    // Staging-only is structural, not procedural: a production target cannot be
    // activated by editing a .env, a registry file or a command-line argument,
    // because it has no program to activate.
    expect(trim(nightwatchTargetHelper('target_nightwatch_program staging-main')))
        ->toBe(nightwatchProgramName());

    foreach (['tits-guru', 'food-guru', 'animals-guru', '', 'staging', 'staging-main-2'] as $target) {
        $snippet = 'target_nightwatch_program '.escapeshellarg($target).' || echo NO-AGENT';

        expect(trim(nightwatchTargetHelper($snippet)))
            ->toBe('NO-AGENT', "target [{$target}] must not name a Nightwatch program");
    }
});

it('leaves every planned production target untouched by the evaluation', function () {
    // No token key, no agent program, no registry field — the production
    // template and the production targets are exactly as the Sentry integration left them.
    $production = File::get(base_path('infrastructure/templates/environment/production.env.example'));
    expect($production)->not->toContain('NIGHTWATCH');

    $registry = File::get(base_path('infrastructure/config/deployment-targets.json'));
    expect($registry)->not->toContain('nightwatch');

    $supervisorConfigs = glob(base_path('infrastructure/config/supervisor/*nightwatch*.conf')) ?: [];
    expect(array_map('basename', $supervisorConfigs))->toBe([nightwatchProgramName().'.conf']);
});

// --- the installer ----------------------------------------------------------

it('is a shipped, executable, syntactically valid CLI', function () {
    expect(is_file(nightwatchInstallerScript()))->toBeTrue();
    expect(is_executable(nightwatchInstallerScript()))->toBeTrue();
    expect(requiredCliManifestNames())->toContain('install-nightwatch-agent');

    exec('bash -n '.escapeshellarg(nightwatchInstallerScript()).' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));
});

it('requires root before anything else', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        [$exit, $output] = runNightwatchInstaller($scratch, ['--check', '--target', 'staging-main'], [
            'RATEGURU_NIGHTWATCH_EUID' => '1000',
        ]);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('must be executed as root');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('refuses a target that is not on the Nightwatch allowlist', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'tits-guru']);

        expect($exit)->not->toBe(0);
        // Rejected as a planned target first — before the allowlist is even
        // consulted, and long before any filesystem or supervisorctl call.
        expect($output)->toContain('tits-guru');
        expect(nightwatchStubLog($scratch, 'supervisorctl'))->toBe('');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('rejects malformed arguments and prints usage on --help', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);

        [$exit, $output] = runNightwatchInstaller($scratch, ['--help']);
        expect($exit)->toBe(0);
        expect($output)->toContain('install-nightwatch-agent --apply');

        [$exit, $output] = runNightwatchInstaller($scratch, ['--check']);
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');

        [$exit, $output] = runNightwatchInstaller($scratch, ['--check', '--apply', '--target', 'staging-main']);
        expect($exit)->not->toBe(0);
        expect($output)->toContain('only one mode may be given');

        [$exit, $output] = runNightwatchInstaller($scratch, ['--bogus']);
        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('installs the program, applies it and requires a stably RUNNING agent', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        $root = nightwatchDeployedTarget($scratch);

        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->toBe(0, $output);

        $installed = $scratch.'/fs/etc/supervisor/conf.d/'.nightwatchProgramName().'.conf';
        expect(is_file($installed))->toBeTrue('the Supervisor program was not installed');
        expect(file_get_contents($installed))->toBe(File::get(nightwatchProgramFile()));

        // Validated before it is applied, then only the target's own program
        // group is ever touched — no global reread-and-update-everything.
        $supervisorctl = nightwatchStubLog($scratch, 'supervisorctl');
        expect($supervisorctl)
            ->toContain('supervisorctl reread')
            ->toContain('supervisorctl update '.nightwatchProgramName())
            ->toContain('supervisorctl status '.nightwatchProgramName().':*');

        // Ownership and mode are asked for explicitly, and the mode uses the
        // GNU '=' operator so a setgid bit can never survive.
        expect(nightwatchStubLog($scratch, 'chown'))->toContain('root:root');
        expect(nightwatchStubLog($scratch, 'chmod'))->toContain('=0644');

        // The official status command, run as the registry's runtime user from
        // the deployed release.
        expect(nightwatchStubLog($scratch, 'runuser'))
            ->toContain('-u '.nightwatchRegistryTarget()['runtime_user'])
            ->toContain('artisan nightwatch:status');

        // The token is never printed, in any form.
        expect($output)->not->toContain('nw-scratch-token-never-printed');
        expect($output)->toContain('a token is present (value not printed)');

        expect($root)->toContain('staging');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('fails when the ingest listener is not loopback-only', function (string $listener) {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch, ['listener' => $listener]);
        nightwatchDeployedTarget($scratch);

        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('publicly reachable');

        // The transaction rolled back: the program file this run installed is
        // gone again, because there was no previous one to restore.
        expect(is_file($scratch.'/fs/etc/supervisor/conf.d/'.nightwatchProgramName().'.conf'))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with(['0.0.0.0:2407', '[::]:2407', '203.0.113.9:2407']);

it('follows the configured ingest port rather than assuming the package default', function () {
    // The port is not hardcoded anywhere in the installer: the application,
    // the agent and this verification all read the same NIGHTWATCH_INGEST_URI.
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch, ['listener' => '127.0.0.1:2408']);
        nightwatchDeployedTarget($scratch, ['NIGHTWATCH_INGEST_URI' => '127.0.0.1:2408']);

        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('ingest 127.0.0.1:2408 is loopback');
        expect($output)->toContain('loopback-only on port 2408');

        // And it really asked the kernel about that port, not 2407.
        expect(nightwatchStubLog($scratch, 'ss'))->toContain('-H -l -n -t');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('fails when the agent listens somewhere other than the configured port', function () {
    // The failure a hardcoded 2407 would hide: configuration moved, the agent
    // did not.
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch, ['listener' => '127.0.0.1:2407']);
        nightwatchDeployedTarget($scratch, ['NIGHTWATCH_INGEST_URI' => '127.0.0.1:2408']);

        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('nothing is listening on port 2408');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('refuses to install when the ingest URI itself is not loopback', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        nightwatchDeployedTarget($scratch, ['NIGHTWATCH_INGEST_URI' => '0.0.0.0:2407']);

        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('not a loopback address');
        // Refused before anything was installed or applied.
        expect(nightwatchStubLog($scratch, 'supervisorctl'))->toBe('');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('refuses to install without a token, an enabled flag, or a deployed release', function (array $env, bool $deployed, string $expected) {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);

        if ($deployed) {
            nightwatchDeployedTarget($scratch, $env);
        } else {
            // the service bootstrap owns the log directory; the agent's working directory
            // is `current`, which does not exist before the first deployment.
            @mkdir($scratch.'/fs'.nightwatchRegistryTarget()['application_root'].'/shared/storage/logs', 0o755, true);
        }

        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->not->toBe(0);
        expect($output)->toContain($expected);
        expect($output)->not->toContain('nw-scratch-token-never-printed');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'no token' => [['NIGHTWATCH_TOKEN' => null], true, 'NIGHTWATCH_TOKEN is missing or empty'],
    'not enabled' => [['NIGHTWATCH_ENABLED' => 'false'], true, "NIGHTWATCH_ENABLED is not 'true'"],
    'not deployed' => [[], false, 'deploy the target before installing'],
]);

it('verifies an installed agent, and fails when its ownership has drifted', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        nightwatchDeployedTarget($scratch);

        [$exit] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);
        expect($exit)->toBe(0);

        [$exit, $output] = runNightwatchInstaller($scratch, ['--verify', '--target', 'staging-main']);
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('PASS: Nightwatch agent and deployment-marker primitive verified');

        // the deployment observability work: the marker primitive, its wrapper and its sudoers grant
        // are installed and verified alongside the agent, so the Nightwatch decision
        // rejection can remove the whole integration in one step.
        foreach ([
            '/fs/home/www/rateguru/bin/record-nightwatch-deployment',
            '/fs/usr/local/sbin/rateguru-nightwatch-deployment',
            '/fs/etc/sudoers.d/rateguru-nightwatch-deployment',
        ] as $installed) {
            expect(is_file($scratch.$installed))->toBeTrue("{$installed} should have been installed");
        }

        // The ownership check is not decorative: point stat the other way and
        // the same verification fails.
        [$exit, $output] = runNightwatchInstaller($scratch, ['--verify', '--target', 'staging-main'], [
            'RATEGURU_NIGHTWATCH_STAT_BIN' => $scratch.'/bin/stat-wrong-owner',
        ]);
        expect($exit)->not->toBe(0);
        expect($output)->toContain('is owned by nobody');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('fails verification when the application cannot reach its own agent', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        nightwatchDeployedTarget($scratch);

        [$exit] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);
        expect($exit)->toBe(0);

        // A broken local integration is surfaced at install/verify time, which
        // is where a human is already looking — never by failing a deployment.
        nightwatchStubs($scratch, ['status_exit' => 1]);

        [$exit, $output] = runNightwatchInstaller($scratch, ['--verify', '--target', 'staging-main']);
        expect($exit)->not->toBe(0);
        expect($output)->toContain('nightwatch:status');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('refuses to install the deployment-marker grant when visudo rejects it', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        nightwatchDeployedTarget($scratch);

        // A sudoers file the parser rejects would break every operational
        // wrapper on the host, not just this one, so it must never reach
        // /etc/sudoers.d under its real name.
        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main'], [
            'RATEGURU_NIGHTWATCH_VISUDO_BIN' => $scratch.'/bin/visudo-reject',
        ]);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('failed visudo -cf');
        expect(is_file($scratch.'/fs/etc/sudoers.d/rateguru-nightwatch-deployment'))->toBeFalse();
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('refuses to manage a symlinked marker destination, and leaves it alone', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        nightwatchDeployedTarget($scratch);

        // Rejected before the transaction arms: a symlink recorded as "no
        // previous file" would be deleted by a rollback that believed it was
        // cleaning up after itself.
        file_put_contents($scratch.'/fs/decoy', "decoy\n");
        symlink($scratch.'/fs/decoy', $scratch.'/fs/usr/local/sbin/rateguru-nightwatch-deployment');

        [$exit, $output] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('refusing to manage a symlinked destination');
        expect(is_link($scratch.'/fs/usr/local/sbin/rateguru-nightwatch-deployment'))->toBeTrue();
        expect(file_get_contents($scratch.'/fs/decoy'))->toBe("decoy\n");
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('removes the agent and proves the ingest port is closed', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        nightwatchDeployedTarget($scratch);

        [$exit] = runNightwatchInstaller($scratch, ['--apply', '--target', 'staging-main']);
        expect($exit)->toBe(0);

        $installed = $scratch.'/fs/etc/supervisor/conf.d/'.nightwatchProgramName().'.conf';
        expect(is_file($installed))->toBeTrue();

        // The agent stops listening once it is stopped.
        touch($scratch.'/state/no-listener');

        [$exit, $output] = runNightwatchInstaller($scratch, ['--remove', '--target', 'staging-main']);

        expect($exit)->toBe(0, $output);
        expect(is_file($installed))->toBeFalse('the Supervisor program was not uninstalled');
        expect(nightwatchStubLog($scratch, 'supervisorctl'))->toContain('supervisorctl stop '.nightwatchProgramName().':*');

        // the deployment observability work: the whole integration goes, sudo grant included. A grant
        // left behind would point the deploy user at a wrapper that no longer
        // exists.
        foreach ([
            '/fs/etc/sudoers.d/rateguru-nightwatch-deployment',
            '/fs/usr/local/sbin/rateguru-nightwatch-deployment',
            '/fs/home/www/rateguru/bin/record-nightwatch-deployment',
        ] as $removed) {
            expect(is_file($scratch.$removed))->toBeFalse("{$removed} should have been removed");
        }

        // Removal stops telemetry infrastructure; it does not un-evaluate
        // Nightwatch, so the package and the target .env survive.
        expect($output)->toContain('are untouched');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

// --- the deploy transition --------------------------------------------------

/**
 * Sources the real deploy script — its source guard means main() never
 * auto-runs — and calls perform_nightwatch_transition directly.
 *
 * @return array{0: int, 1: string}
 */
function runNightwatchDeployTransition(string $scratch, string $program): array
{
    $body = <<<BASH
        TARGET_ID=staging-main
        NIGHTWATCH_PROGRAM={$program}
        perform_nightwatch_transition
        printf 'TRANSITION_DONE=%s\\n' "\$?"
        BASH;

    $script = "set -Eeuo pipefail\n"
        .'source '.escapeshellarg(base_path('infrastructure/scripts/deploy'))."\n"
        .$body."\n";

    $harness = $scratch.'/deploy-harness.sh';
    file_put_contents($harness, $script);

    $env = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => base_path('infrastructure/scripts/common'),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),
        'RATEGURU_TARGET_REGISTRY_FILE' => base_path('infrastructure/config/deployment-targets.json'),
        'RATEGURU_TARGETS_CLI' => base_path('infrastructure/scripts/targets'),
        'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '1',
        'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '0',
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $harness], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start the deploy harness');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

it('restarts a running agent after a deployment, so it follows the new current', function () {
    // Supervisor resolves `directory=` when it spawns the process, so a
    // running agent keeps the release it started in until it is restarted —
    // a directory `cleanup` eventually deletes.
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        touch($scratch.'/state/registered');
        touch($scratch.'/state/running');

        [$exit, $output] = runNightwatchDeployTransition($scratch, nightwatchProgramName());

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('RUNNING against the new release');
        expect(nightwatchStubLog($scratch, 'supervisorctl'))
            ->toContain('supervisorctl restart '.nightwatchProgramName().':*');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('does nothing at all for a target with no Nightwatch agent', function () {
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);

        [$exit, $output] = runNightwatchDeployTransition($scratch, '');

        expect($exit)->toBe(0, $output);
        // Not one supervisorctl call: a production deployment must be
        // completely unaffected by the evaluation.
        expect(nightwatchStubLog($scratch, 'supervisorctl'))->toBe('');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('leaves an agent that is registered but stopped exactly as it found it', function () {
    // Stopped is an operator decision — the evaluation was disabled, or
    // --remove is half done. A deployment does not overrule it.
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        touch($scratch.'/state/registered');

        [$exit, $output] = runNightwatchDeployTransition($scratch, nightwatchProgramName());

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('left as it is');
        expect(nightwatchStubLog($scratch, 'supervisorctl'))->not->toContain('restart');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('warns but never fails the deployment when the agent will not come back', function (string $toggle) {
    // Fail-open, by design and in the same spirit as the Sentry deployment
    // marker: a healthy release that has already passed its health check is
    // never undone because a monitoring sidecar had a bad minute. The loud
    // version of this failure belongs to install-nightwatch-agent --verify.
    $scratch = nightwatchScratch();

    try {
        nightwatchStubs($scratch);
        touch($scratch.'/state/registered');
        touch($scratch.'/state/running');
        touch($scratch.'/state/'.$toggle);

        [$exit, $output] = runNightwatchDeployTransition($scratch, nightwatchProgramName());

        expect($exit)->toBe(0, 'a Nightwatch agent problem must never fail a deployment');
        expect($output)->toContain('WARNING');
        expect($output)->toContain('the deployment stands');
        expect($output)->toContain('install-nightwatch-agent --verify --target staging-main');
        expect($output)->toContain('TRANSITION_DONE=0');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'the restart command fails' => 'restart-fail',
    'the agent never returns to RUNNING' => 'start-fail',
]);

it('runs the agent transition after the queue transition and before the success record', function () {
    $source = File::get(base_path('infrastructure/scripts/deploy'));

    // Measured inside perform_deploy, not across the file. Since the controlled code alignment,
    // finalize_restore_alignment — defined ABOVE perform_deploy — also calls
    // perform_nightwatch_transition, so a whole-file position comparison
    // would be about declaration order rather than about the normal
    // deployment sequence this test is describing.
    $pipelineStart = mb_strpos($source, "\nperform_deploy() {\n");
    $pipelineEnd = mb_strpos($source, "\n}\n", $pipelineStart);

    expect($pipelineStart)->not->toBeFalse();
    expect($pipelineEnd)->not->toBeFalse();

    $deploy = mb_substr($source, $pipelineStart, $pipelineEnd - $pipelineStart);

    $queue = mb_strpos($deploy, "\n    perform_queue_transition\n");
    $agent = mb_strpos($deploy, "\n    perform_nightwatch_transition\n");
    $success = mb_strpos($deploy, 'deployed successfully');

    expect($queue)->not->toBeFalse();
    expect($agent)->not->toBeFalse();
    expect($success)->not->toBeFalse();

    expect($queue)->toBeLessThan($agent);
    expect($agent)->toBeLessThan($success);

    // And the block is delimited, so it can be extracted and removed cleanly
    // if the Nightwatch decision rejects Nightwatch. Matched against the whole file: the
    // definition lives outside perform_deploy.
    expect(preg_match(
        '/# --- nightwatch agent transition \(begin\) ---\n(.*?)\n# --- nightwatch agent transition \(end\) ---/s',
        $source,
    ))->toBe(1);
});
