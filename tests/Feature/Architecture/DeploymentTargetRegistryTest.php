<?php

use Illuminate\Support\Facades\File;

/**
 * the target-aware migration: the deployment target registry.
 *
 * These tests exercise the shipped artefacts — the committed registry, the real
 * `targets` CLI, and the target helper block extracted from `common` — rather
 * than reimplementing their rules.
 */
function targetRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function targetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

/**
 * Run the `targets` CLI. Returns [exitCode, combined output].
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function runTargetsCli(array $arguments): array
{
    $command = escapeshellarg(targetsCli());

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $output = [];
    $exit = 0;
    exec($command.' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

/**
 * Run the `targets` CLI with stdout and stderr captured separately.
 *
 * Needed where the distinction matters: a failing command must emit nothing on
 * stdout, so a caller piping `list` or `show` never receives partial data.
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: array{stdout: string, stderr: string}}
 */
function runTargetsCliStreams(array $arguments): array
{
    $command = escapeshellarg(targetsCli());

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);

    expect($process)->not->toBeFalse('could not start the targets CLI');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    return [$exit, ['stdout' => $stdout, 'stderr' => $stderr]];
}

/**
 * Write a mutated copy of the committed registry and return its path.
 *
 * The mutation is a jq program applied to the real file, so every negative case
 * starts from a registry that is otherwise valid — proving the specific rule
 * under test is what rejects it.
 *
 * The jq exit code is asserted: a failed mutation would otherwise leave an
 * empty file behind and every "rejected" assertion would pass for the wrong
 * reason.
 *
 * Caller owns the returned path and should unlink it.
 */
function buildMutatedRegistry(string $jqProgram): string
{
    $path = sys_get_temp_dir().'/target-registry-'.uniqid().'.json';

    $build = sprintf(
        'jq %s %s > %s',
        escapeshellarg($jqProgram),
        escapeshellarg(targetRegistryPath()),
        escapeshellarg($path),
    );

    exec($build.' 2>&1', $buildOutput, $buildExit);

    expect($buildExit)->toBe(
        0,
        "could not build mutated registry with [{$jqProgram}]:\n".implode("\n", $buildOutput),
    );
    expect(filesize($path))->toBeGreaterThan(0, "mutation produced an empty registry: {$jqProgram}");

    return $path;
}

/**
 * Build a mutated registry and run `targets validate` against it.
 *
 * @return array{0: int, 1: string}
 */
function validateMutatedRegistry(string $jqProgram): array
{
    $path = buildMutatedRegistry($jqProgram);

    try {
        return runTargetsCli(['validate', '--file', $path]);
    } finally {
        @unlink($path);
    }
}

/**
 * The target helper block from `common`, runnable standalone.
 *
 * `common` sources /home/www/rateguru/config/deployment.conf at the top, which
 * does not exist in CI, so the block is extracted and given a `fail` stub. The
 * extraction markers double as proof the block is still delimited in `common`.
 */
function targetHelperHarness(): string
{
    $common = File::get(base_path('infrastructure/scripts/common'));

    expect(preg_match(
        '/# --- deployment target registry \(begin\) ---\n(.*?)\n# --- deployment target registry \(end\) ---/s',
        $common,
        $matches,
    ))->toBe(1, 'could not locate the target registry block in scripts/common');

    return "set -uo pipefail\n"
        ."fail() { printf '[ERR] %s\\n' \"\$*\" >&2; exit 1; }\n"
        .$matches[1]."\n";
}

/**
 * Run a snippet against the extracted helper block.
 *
 * @return array{0: int, 1: string}
 */
function runTargetHelper(string $snippet, ?string $registryFile = null, ?string $validator = null): array
{
    $script = targetHelperHarness().$snippet;

    $command = '';

    // RATEGURU_TARGET_REGISTRY_FILE and RATEGURU_TARGETS_CLI are only honored
    // alongside RATEGURU_ALLOW_TEST_OVERRIDES=true (a stray environment
    // variable in a real root shell must never silently redirect a privileged
    // script), so any test that sets either must set this too.
    if ($registryFile !== null || $validator !== null) {
        $command .= 'RATEGURU_ALLOW_TEST_OVERRIDES=true ';
    }

    if ($registryFile !== null) {
        $command .= 'RATEGURU_TARGET_REGISTRY_FILE='.escapeshellarg($registryFile).' ';
    }

    // Helpers run the full validator on first use. Tests that only exercise
    // path resolution or the cheap file checks leave this unset, which points
    // the helper at the real CLI beside `common`.
    if ($validator !== null) {
        $command .= 'RATEGURU_TARGETS_CLI='.escapeshellarg($validator).' ';
    }

    $command .= 'bash -c '.escapeshellarg($script);

    $output = [];
    $exit = 0;
    exec($command.' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

// --- the committed registry -------------------------------------------------

it('ships a registry that is valid JSON and passes its own validator', function () {
    expect(File::exists(targetRegistryPath()))->toBeTrue('missing deployment-targets.json');

    exec('jq empty '.escapeshellarg(targetRegistryPath()).' 2>&1', $jqOutput, $jqExit);
    expect($jqExit)->toBe(0, "jq empty failed:\n".implode("\n", $jqOutput));

    [$exit, $output] = runTargetsCli(['validate', '--file', targetRegistryPath()]);
    expect($exit)->toBe(0, "committed registry failed validation:\n{$output}");
});

it('declares schema_version 1 and exactly the two expected targets', function () {
    $registry = json_decode(File::get(targetRegistryPath()), true, 512, JSON_THROW_ON_ERROR);

    expect($registry['schema_version'])->toBe(1);
    expect(array_keys($registry['targets']))->toEqualCanonicalizing(['staging-main', 'tits-guru']);
});

it('mirrors current staging infrastructure exactly in staging-main', function () {
    $target = json_decode(File::get(targetRegistryPath()), true, 512, JSON_THROW_ON_ERROR)['targets']['staging-main'];

    // Every value below is cross-checked against a committed file in the
    // "matches the committed sources" test; these are the expected constants.
    expect($target)->toMatchArray([
        'id' => 'staging-main',
        'lifecycle' => 'active',
        'environment_class' => 'staging',
        'application_root' => '/home/www/rateguru/staging',
        'runtime_user' => 'rateguru-staging',
        'runtime_group' => 'rateguru-staging',
        'deploy_user' => 'deploy-rateguru-staging',
        'code_group' => 'rateguru-staging-code',
        'incoming_artifacts' => '/home/deploy-rateguru-staging/incoming',
        'release_retention' => 5,
        'environment_template' => 'infrastructure/templates/environment/staging.env.example',
    ]);

    expect($target['database'])->toMatchArray([
        'name' => 'rateguru_staging',
        'application_role' => 'rateguru_staging_app',
    ]);

    expect($target['health'])->toMatchArray([
        'url' => 'http://127.0.0.1/',
        'host_header' => 'rateguru-staging.internal',
    ]);

    expect($target['public_hostnames'])->toBe(['rateguru.staging.myprojects.pp.ua']);

    // The backup namespace must stay "staging" so no existing local or B2
    // backup path moves when the backup scripts are migrated later. Staging
    // keeps a short age window on both tiers, backstopped by the minimum
    // count that age-based deletion can never cut below.
    expect($target['backup'])->toMatchArray([
        'namespace' => 'staging',
        'local_retention_days' => 5,
        'offsite_retention_days' => 14,
        'minimum_retained_backups' => 2,
    ]);

    expect($target['php_fpm'])->toMatchArray([
        'pool' => 'rateguru-staging',
        'socket' => '/run/php/rateguru-staging.sock',
    ]);

    expect($target['supervisor'])->toMatchArray([
        'program' => 'rateguru-staging-queue',
        'queue' => 'rateguru-staging',
    ]);

    expect($target['scheduler']['name'])->toBe('rateguru-staging-scheduler');

    expect($target['nginx'])->toMatchArray([
        'site_name' => 'rateguru-staging',
        'internal_hostname' => 'rateguru-staging.internal',
    ]);
});

it('derives staging-main from the committed infrastructure sources', function () {
    // SCOPE: this proves the registry agrees with the committed configuration
    // in this repository. It does NOT prove the registry agrees with the
    // running staging VPS — nothing in CI can reach that host.
    //
    // Runtime parity is a manual step, documented in
    // runbooks/deployment-targets.md under "Verifying runtime parity", and must
    // be re-run on the VPS before the target is used for a real deployment.
    $target = json_decode(File::get(targetRegistryPath()), true, 512, JSON_THROW_ON_ERROR)['targets']['staging-main'];

    $source = fn (string $path): string => File::get(base_path('infrastructure/'.$path));

    // PHP-FPM pool — pool name, socket, and the runtime user it runs as.
    $pool = $source('config/php-fpm/rateguru-staging.conf');
    expect($pool)
        ->toContain('['.$target['php_fpm']['pool'].']')
        ->toContain('listen = '.$target['php_fpm']['socket'])
        ->toContain('user = '.$target['runtime_user']);

    // Supervisor — program name and the queue it consumes.
    $supervisor = $source('config/supervisor/rateguru-staging-queue.conf');
    expect($supervisor)
        ->toContain('[program:'.$target['supervisor']['program'].']')
        ->toContain('--queue='.$target['supervisor']['queue'])
        ->toContain('user='.$target['runtime_user']);

    // Nginx — internal hostname, public hostname, and the socket it dials.
    $nginx = $source('config/nginx/'.$target['nginx']['site_name']);
    expect($nginx)
        ->toContain('server_name '.$target['nginx']['internal_hostname'].';')
        ->toContain('server_name '.$target['public_hostnames'][0].';')
        ->toContain('fastcgi_pass unix:'.$target['php_fpm']['socket'].';');

    // Backup — since the legacy --environment interface was removed, every
    // registry-derived value (database name, backup namespace, retention,
    // health URL/host header, nginx/scheduler names) is resolved generically
    // at runtime through common's target_*() accessors, reading straight from
    // the registry itself — there is no longer a separate, hardcoded
    // per-target copy in these scripts to compare against for drift. What
    // remains provable structurally is the shared, target-agnostic template
    // shape these scripts build from that resolved value.
    $backup = $source('scripts/backup');
    expect($backup)
        ->toContain('BACKUP_ROOT="${BACKUP_BASE}/${BACKUP_NAMESPACE}"')
        ->toContain('BACKUP_BASE_DEFAULT="/home/www/rateguru/backups"');

    expect($source('scripts/offsite-retention'))
        ->toContain('REMOTE_ROOT="${RCLONE_REMOTE}:${BUCKET}/rateguru/${BACKUP_NAMESPACE}"');

    // The scheduler cron file exists under the recorded name and runs as the
    // runtime user.
    expect($source('config/cron/'.$target['scheduler']['name']))
        ->toContain($target['runtime_user']);

    // The referenced environment template really exists.
    expect(File::exists(base_path($target['environment_template'])))->toBeTrue();
});

it('declares tits-guru completely but leaves it planned', function () {
    $target = json_decode(File::get(targetRegistryPath()), true, 512, JSON_THROW_ON_ERROR)['targets']['tits-guru'];

    expect($target)->toMatchArray([
        'id' => 'tits-guru',
        'lifecycle' => 'planned',
        'environment_class' => 'production',
        'application_root' => '/home/www/rateguru/production/tits-guru',
        'runtime_user' => 'rateguru-tits-guru',
        'runtime_group' => 'rateguru-tits-guru',
        'deploy_user' => 'deploy-rateguru-tits-guru',
        'code_group' => 'rateguru-tits-guru-code',
        'incoming_artifacts' => '/home/deploy-rateguru-tits-guru/incoming',
        'release_retention' => 10,
        'environment_template' => 'infrastructure/templates/environment/tits-guru.env.example',
    ]);

    expect($target['database'])->toMatchArray([
        'name' => 'rateguru_tits_guru',
        'application_role' => 'rateguru_tits_guru_app',
    ]);
    expect($target['health'])->toMatchArray([
        'url' => 'http://127.0.0.1/',
        'host_header' => 'tits-guru.internal',
    ]);
    expect($target['public_hostnames'])->toBe(['tits.guru']);
    expect($target['backup'])->toMatchArray([
        'namespace' => 'tits-guru',
        'local_retention_days' => 30,
        'offsite_retention_days' => 90,
        'minimum_retained_backups' => 2,
    ]);
    expect($target['php_fpm'])->toMatchArray([
        'pool' => 'rateguru-tits-guru',
        'socket' => '/run/php/rateguru-tits-guru.sock',
    ]);
    expect($target['supervisor'])->toMatchArray([
        'program' => 'rateguru-tits-guru-queue',
        'queue' => 'rateguru-tits-guru',
    ]);
    expect($target['scheduler']['name'])->toBe('rateguru-tits-guru-scheduler');
    expect($target['nginx'])->toMatchArray([
        'site_name' => 'rateguru-tits-guru',
        'internal_hostname' => 'tits-guru.internal',
    ]);

    // Every required key is present, so "planned" means unprovisioned rather
    // than under-specified.
    expect(array_keys($target))->toContain(
        'id', 'lifecycle', 'environment_class', 'application_root', 'runtime_user',
        'runtime_group', 'deploy_user', 'code_group', 'incoming_artifacts', 'release_retention',
        'database', 'health', 'public_hostnames', 'backup', 'php_fpm',
        'supervisor', 'scheduler', 'nginx', 'environment_template',
    );
});

it('creates no production infrastructure for the planned target', function () {
    // A planned target must remain a declaration only: this slice must not add
    // its environment template, Nginx site, pool, queue or cron entry.
    foreach ([
        'templates/environment/tits-guru.env.example',
        'config/nginx/rateguru-tits-guru',
        'config/php-fpm/rateguru-tits-guru.conf',
        'config/supervisor/rateguru-tits-guru-queue.conf',
        'config/cron/rateguru-tits-guru-scheduler',
    ] as $path) {
        expect(File::exists(base_path('infrastructure/'.$path)))
            ->toBeFalse("planned target must not be provisioned in this slice: {$path}");
    }
});

// --- CLI behaviour ----------------------------------------------------------

it('lists targets deterministically', function () {
    [$exit, $output] = runTargetsCli(['list', '--file', targetRegistryPath()]);

    expect($exit)->toBe(0, $output);
    expect($output)->toBe(
        "staging-main\tactive\tstaging\n"
        ."tits-guru\tplanned\tproduction"
    );

    // Stable across runs, and independent of key order in the file.
    [, $again] = runTargetsCli(['list', '--file', targetRegistryPath()]);
    expect($again)->toBe($output);
});

it('shows exactly one normalized target', function () {
    [$exit, $output] = runTargetsCli(['show', '--target', 'staging-main', '--file', targetRegistryPath()]);

    expect($exit)->toBe(0, $output);

    $shown = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    expect($shown['id'])->toBe('staging-main');
    expect($shown)->not->toHaveKey('targets');

    // Key-sorted, so the output is diffable and stable.
    $keys = array_keys($shown);
    $sorted = $keys;
    sort($sorted);
    expect($keys)->toBe($sorted);
});

it('refuses to list or show an invalid registry', function () {
    // list and show once checked only the file and schema version, so they
    // returned data for a registry validate rejected. Reading a target whose
    // socket or retention is invalid is as wrong as validating it.
    $cases = [
        'missing database.name' => 'del(.targets["staging-main"].database.name)',
        'invalid PHP-FPM socket' => '.targets["staging-main"].php_fpm.socket = "/tmp/elsewhere.sock"',
        'socket without .sock' => '.targets["staging-main"].php_fpm.socket = "/run/php/pool.txt"',
        'negative retention' => '.targets["staging-main"].release_retention = -1',
        'fractional retention' => '.targets["staging-main"].release_retention = 1.5',
        'overlapping public hostname' => '.targets["tits-guru"].public_hostnames = ["tits.guru", "rateguru.staging.myprojects.pp.ua"]',
        'secret-like property name' => '.targets["staging-main"].db_password = "x"',
        'collapsed code group' => '.targets["tits-guru"].code_group = .targets["tits-guru"].runtime_group',
        'unsafe application_root' => '.targets["staging-main"].application_root = "/opt/elsewhere"',
    ];

    foreach ($cases as $label => $mutation) {
        $path = buildMutatedRegistry($mutation);

        try {
            // validate is the reference behaviour.
            [$validateExit] = runTargetsCli(['validate', '--file', $path]);
            expect($validateExit)->not->toBe(0, "validate should reject: {$label}");

            // list and show must agree with it, and emit nothing on stdout.
            [$listExit, $listOutput] = runTargetsCliStreams(['list', '--file', $path]);
            expect($listExit)->not->toBe(0, "list should reject: {$label}");
            expect($listOutput['stdout'])->toBe('', "list must print nothing for an invalid registry: {$label}");
            expect($listOutput['stderr'])->not->toBe('', "list should explain the rejection: {$label}");

            [$showExit, $showOutput] = runTargetsCliStreams(['show', '--target', 'staging-main', '--file', $path]);
            expect($showExit)->not->toBe(0, "show should reject: {$label}");
            expect($showOutput['stdout'])->toBe('', "show must print nothing for an invalid registry: {$label}");
            expect($showOutput['stderr'])->not->toBe('', "show should explain the rejection: {$label}");
        } finally {
            @unlink($path);
        }
    }
});

it('keeps normal output clean on a valid registry', function () {
    // The shared validation path must not leak its own chatter into list/show.
    [$listExit, $list] = runTargetsCliStreams(['list', '--file', targetRegistryPath()]);
    expect($listExit)->toBe(0, $list['stderr']);
    expect($list['stdout'])->toBe(
        "staging-main\tactive\tstaging\n"
        ."tits-guru\tplanned\tproduction\n"
    );
    expect($list['stdout'])->not->toContain('registry is valid');

    [$showExit, $show] = runTargetsCliStreams(['show', '--target', 'staging-main', '--file', targetRegistryPath()]);
    expect($showExit)->toBe(0, $show['stderr']);
    expect(json_decode($show['stdout'], true, 512, JSON_THROW_ON_ERROR)['id'])->toBe('staging-main');
    expect($show['stdout'])->not->toContain('registry is valid');

    // Only validate announces success.
    [$validateExit, $validate] = runTargetsCliStreams(['validate', '--file', targetRegistryPath()]);
    expect($validateExit)->toBe(0);
    expect($validate['stdout'])->toContain('target registry is valid');
});

it('rejects malformed CLI invocations', function () {
    $registry = targetRegistryPath();

    $cases = [
        'unknown command' => ['bogus', '--file', $registry],
        'unknown argument' => ['validate', '--bogus', '--file', $registry],
        'duplicate --file' => ['validate', '--file', $registry, '--file', $registry],
        '--file without value' => ['validate', '--file'],
        '--target without value' => ['show', '--target'],
        'show without --target' => ['show', '--file', $registry],
        '--target outside show' => ['list', '--target', 'staging-main', '--file', $registry],
        'no arguments' => [],
    ];

    foreach ($cases as $label => $arguments) {
        [$exit] = runTargetsCli($arguments);
        expect($exit)->not->toBe(0, "should have failed: {$label}");
    }
});

it('fails on an unknown or malformed target ID', function () {
    foreach (['ghost', 'Staging-Main', '../etc', 'a', ''] as $targetId) {
        [$exit] = runTargetsCli(['show', '--target', $targetId, '--file', targetRegistryPath()]);
        expect($exit)->not->toBe(0, "should have rejected target ID: '{$targetId}'");
    }
});

it('fails on an absent, malformed or symlinked registry', function () {
    $missing = sys_get_temp_dir().'/absent-registry-'.uniqid().'.json';
    [$exit] = runTargetsCli(['validate', '--file', $missing]);
    expect($exit)->not->toBe(0, 'absent registry must fail');

    $malformed = sys_get_temp_dir().'/malformed-'.uniqid().'.json';
    file_put_contents($malformed, "{not json\n");
    [$exit] = runTargetsCli(['validate', '--file', $malformed]);
    expect($exit)->not->toBe(0, 'malformed JSON must fail');
    @unlink($malformed);

    // A symlink is refused even outside runtime mode: it lets the validated
    // path and the read path diverge.
    $link = sys_get_temp_dir().'/registry-link-'.uniqid().'.json';
    symlink(targetRegistryPath(), $link);

    try {
        [$exit, $output] = runTargetsCli(['validate', '--file', $link]);
        expect($exit)->not->toBe(0, 'symlinked registry must fail');
        expect($output)->toContain('symlink');
    } finally {
        @unlink($link);
    }
});

it('enforces runtime ownership and mode rules on the installed path', function () {
    $script = File::get(targetsCli());

    // The stricter checks are bound to the installed default path only, so
    // repository validation works without root.
    expect(preg_match(
        '/if \[\[ "\$\{REGISTRY_FILE\}" == "\$\{REGISTRY_DEFAULT_FILE\}" \]\]; then(.*?)\n    fi/s',
        $script,
        $matches,
    ))->toBe(1, 'could not locate the runtime-mode guard in scripts/targets');

    $runtimeGuard = $matches[1];

    expect($runtimeGuard)
        ->toContain("stat -c '%u:%g'")
        ->toContain('0:0')
        ->toContain('group-writable')
        ->toContain('world-writable')
        ->toContain('8#020')
        ->toContain('8#002');

    expect($script)->toContain('REGISTRY_DEFAULT_FILE="/home/www/rateguru/config/deployment-targets.json"');

    // Prove the mode arithmetic actually classifies modes correctly, rather
    // than only asserting the source text.
    $probe = <<<'BASH'
    check() {
        mode="$1"
        group=0; world=0
        (( (8#${mode} & 8#020) != 0 )) && group=1
        (( (8#${mode} & 8#002) != 0 )) && world=1
        printf '%s:%s%s\n' "${mode}" "${group}" "${world}"
    }
    check 644
    check 664
    check 646
    check 666
    check 600
    BASH;

    exec('bash -c '.escapeshellarg($probe).' 2>&1', $probeOutput, $probeExit);
    expect($probeExit)->toBe(0, implode("\n", $probeOutput));
    expect($probeOutput)->toBe(['644:00', '664:10', '646:01', '666:11', '600:00']);
});

// --- schema validation ------------------------------------------------------

it('rejects unsupported schema versions', function () {
    foreach (['.schema_version = 2', '.schema_version = 0', '.schema_version = 1.5', 'del(.schema_version)', '.schema_version = "1"'] as $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
    }
});

it('rejects missing, null and wrong-typed required properties', function () {
    $paths = [
        '.runtime_user', '.runtime_group', '.deploy_user', '.code_group', '.application_root',
        '.incoming_artifacts', '.lifecycle', '.environment_class',
        '.database.name', '.database.application_role',
        '.health.url', '.health.host_header',
        '.backup.namespace', '.php_fpm.pool', '.php_fpm.socket',
        '.supervisor.program', '.supervisor.queue', '.scheduler.name',
        '.nginx.site_name', '.nginx.internal_hostname', '.environment_template',
    ];

    foreach ($paths as $path) {
        foreach ([
            'missing' => "del(.targets[\"staging-main\"]{$path})",
            'null' => ".targets[\"staging-main\"]{$path} = null",
            'wrong type' => ".targets[\"staging-main\"]{$path} = 42",
            'empty' => ".targets[\"staging-main\"]{$path} = \"\"",
        ] as $kind => $mutation) {
            [$exit] = validateMutatedRegistry($mutation);
            expect($exit)->not->toBe(0, "should have rejected {$kind} {$path}");
        }
    }
});

it('rejects non-positive and non-integer retention values', function () {
    foreach (['.release_retention', '.backup.local_retention_days', '.backup.offsite_retention_days'] as $path) {
        foreach (['0', '-1', '2.5', '"5"', 'null'] as $value) {
            [$exit] = validateMutatedRegistry(".targets[\"staging-main\"]{$path} = {$value}");
            expect($exit)->not->toBe(0, "should have rejected {$path} = {$value}");
        }
    }
});

it('declares an integer minimum_retained_backups of at least 2 on every target', function () {
    $targets = json_decode(File::get(targetRegistryPath()), true, 512, JSON_THROW_ON_ERROR)['targets'];

    foreach ($targets as $id => $target) {
        $minimum = $target['backup']['minimum_retained_backups'] ?? null;
        expect($minimum)->toBeInt("{$id} must declare backup.minimum_retained_backups as a JSON integer");
        expect($minimum)->toBeGreaterThanOrEqual(2, "{$id} must never permit fewer than two retained backups");
    }
});

it('rejects a minimum_retained_backups that is missing, non-integer, boolean, or below 2', function () {
    // Strict: a JSON string "2", a float, null, a boolean, and any value
    // below the hard floor of 2 must all fail — age-based retention must
    // never be able to reduce the backup count below two.
    foreach ([
        'del(.targets["staging-main"].backup.minimum_retained_backups)',
        '.targets["staging-main"].backup.minimum_retained_backups = "2"',
        '.targets["staging-main"].backup.minimum_retained_backups = 2.5',
        '.targets["staging-main"].backup.minimum_retained_backups = null',
        '.targets["staging-main"].backup.minimum_retained_backups = true',
        '.targets["staging-main"].backup.minimum_retained_backups = 1',
        '.targets["staging-main"].backup.minimum_retained_backups = 0',
        '.targets["staging-main"].backup.minimum_retained_backups = -1',
    ] as $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
    }
});

it('rejects unsafe filesystem paths', function () {
    $cases = [
        '.targets["staging-main"].application_root = "relative/path"',
        '.targets["staging-main"].application_root = "/home/www/rateguru/../../etc"',
        '.targets["staging-main"].application_root = "/home/www/rateguru/staging/"',
        '.targets["staging-main"].application_root = "/home/www//rateguru"',
        '.targets["staging-main"].application_root = "/opt/elsewhere"',
        '.targets["staging-main"].incoming_artifacts = "/tmp/incoming"',
        '.targets["staging-main"].incoming_artifacts = "/home/x/../../etc"',
        '.targets["staging-main"].php_fpm.socket = "/run/other.sock"',
        '.targets["staging-main"].php_fpm.socket = "/run/php/pool.txt"',
        '.targets["staging-main"].environment_template = "/etc/passwd"',
        '.targets["staging-main"].environment_template = "infrastructure/../../etc/x"',
    ];

    foreach ($cases as $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
    }
});

it('rejects invalid hostnames and non-loopback health URLs', function () {
    $cases = [
        '.targets["staging-main"].health.url = "https://example.com/"',
        '.targets["staging-main"].health.url = "http://127.0.0.1:8080/"',
        '.targets["staging-main"].health.host_header = "http://host"',
        '.targets["staging-main"].health.host_header = "host:8080"',
        '.targets["staging-main"].health.host_header = "*.host"',
        '.targets["staging-main"].health.host_header = "host name"',
        '.targets["staging-main"].health.host_header = "host/path"',
        '.targets["staging-main"].health.host_header = ".host"',
        '.targets["staging-main"].health.host_header = "host."',
        '.targets["staging-main"].health.host_header = "a..b"',
        '.targets["staging-main"].nginx.internal_hostname = "http://x"',
        '.targets["staging-main"].public_hostnames = []',
        '.targets["staging-main"].public_hostnames = "notanarray"',
        '.targets["staging-main"].public_hostnames = ["dup.example", "dup.example"]',
        '.targets["staging-main"].public_hostnames = ["*.wildcard.example"]',
        '.targets["staging-main"].public_hostnames = ["https://scheme.example"]',
    ];

    foreach ($cases as $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
    }
});

it('keeps the runtime group and the code group as separate roles', function () {
    $targets = json_decode(File::get(targetRegistryPath()), true, 512, JSON_THROW_ON_ERROR)['targets'];

    foreach ($targets as $id => $target) {
        // runtime_group matching runtime_user is the normal Linux convention
        // for a primary group.
        expect($target['runtime_group'])->toBe($target['runtime_user'], "{$id}: runtime_group should be the runtime user's own group");

        // code_group must be a distinct shared group. Collapsing it into the
        // runtime user's group would give the runtime user ownership of the
        // code it executes.
        expect($target['code_group'])
            ->not->toBe($target['runtime_group'], "{$id}: code_group must differ from runtime_group")
            ->not->toBe($target['runtime_user'], "{$id}: code_group must not be the runtime user's own group")
            ->toEndWith('-code', "{$id}: code_group should follow the -code convention");
    }

    // The validator enforces it, not just the committed data.
    foreach ([
        'code_group == runtime_group' => '.targets["tits-guru"].code_group = .targets["tits-guru"].runtime_group',
        'code_group == runtime_user' => '.targets["tits-guru"].code_group = .targets["tits-guru"].runtime_user',
        'missing runtime_group' => 'del(.targets["staging-main"].runtime_group)',
        'duplicate runtime_group' => '.targets["tits-guru"].runtime_group = .targets["staging-main"].runtime_group',
        'unsafe runtime_group' => '.targets["staging-main"].runtime_group = "BAD GROUP"',
    ] as $label => $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$label}");
    }
});

it('requires every public hostname to be a non-empty string', function () {
    // `tostring` used to run before validation, so 123/true/null became
    // "123"/"true"/"null" — each of which satisfies the bare-hostname pattern.
    foreach ([
        '[123]',
        '[true]',
        '[false]',
        '[null]',
        '[{}]',
        '[[]]',
        '[""]',
        '["ok.example", 123]',
        '[123, "ok.example"]',
        '["ok.example", null]',
    ] as $value) {
        [$exit, $output] = validateMutatedRegistry(
            ".targets[\"staging-main\"].public_hostnames = {$value}",
        );

        expect($exit)->not->toBe(0, "should have rejected public_hostnames = {$value}");
        expect($output)->toContain('public');
    }

    // A well-formed array of strings still passes.
    [$exit] = validateMutatedRegistry(
        '.targets["staging-main"].public_hostnames = ["one.example", "two.example"]',
    );
    expect($exit)->toBe(0, 'a valid list of hostname strings must be accepted');
});

it('resolves the registry path identically in common and the CLI', function () {
    $registry = targetRegistryPath();
    $scratch = sys_get_temp_dir().'/registry-resolution-'.uniqid();
    mkdir($scratch);

    $viaConf = $scratch.'/from-conf.json';
    copy($registry, $viaConf);

    // deployment.conf assigns without `export`, so the CLI cannot rely on the
    // variable reaching it through the environment — it must read the file.
    $conf = $scratch.'/deployment.conf';
    file_put_contents($conf, "PHP_BIN=/usr/bin/php8.5\nTARGET_REGISTRY_FILE=\"{$viaConf}\"\n");

    $override = $scratch.'/override.json';
    copy($registry, $override);

    $cli = targetsCli();

    // Level 3: TARGET_REGISTRY_FILE parsed out of deployment.conf.
    exec(
        'RATEGURU_DEPLOYMENT_CONF_FILE='.escapeshellarg($conf).' '
        .escapeshellarg($cli).' list 2>&1',
        $confOutput,
        $confExit,
    );
    expect($confExit)->toBe(0, implode("\n", $confOutput));
    expect(implode("\n", $confOutput))->toContain('staging-main');

    // Level 2 beats level 3.
    exec(
        'RATEGURU_DEPLOYMENT_CONF_FILE='.escapeshellarg($conf).' '
        .'RATEGURU_TARGET_REGISTRY_FILE='.escapeshellarg($override).' '
        .escapeshellarg($cli).' validate 2>&1',
        $overrideOutput,
        $overrideExit,
    );
    expect($overrideExit)->toBe(0, implode("\n", $overrideOutput));
    expect(implode("\n", $overrideOutput))->toContain($override);

    // Level 1 (--file) beats everything.
    exec(
        'RATEGURU_DEPLOYMENT_CONF_FILE='.escapeshellarg($conf).' '
        .'RATEGURU_TARGET_REGISTRY_FILE='.escapeshellarg($override).' '
        .escapeshellarg($cli).' validate --file '.escapeshellarg($registry).' 2>&1',
        $fileOutput,
        $fileExit,
    );
    expect($fileExit)->toBe(0, implode("\n", $fileOutput));
    expect(implode("\n", $fileOutput))->toContain($registry);

    // Level 4: nothing set at all falls through to the installed default.
    $absentConf = $scratch.'/no-such.conf';
    exec(
        'RATEGURU_DEPLOYMENT_CONF_FILE='.escapeshellarg($absentConf).' '
        .escapeshellarg($cli).' validate 2>&1',
        $defaultOutput,
        $defaultExit,
    );
    expect($defaultExit)->not->toBe(0);
    expect(implode("\n", $defaultOutput))
        ->toContain('/home/www/rateguru/config/deployment-targets.json');

    // A value carrying shell metacharacters is ignored rather than interpreted.
    $unsafeConf = $scratch.'/unsafe.conf';
    file_put_contents($unsafeConf, "TARGET_REGISTRY_FILE=\"/tmp/x;touch {$scratch}/pwned\"\n");
    exec(
        'RATEGURU_DEPLOYMENT_CONF_FILE='.escapeshellarg($unsafeConf).' '
        .escapeshellarg($cli).' validate 2>&1',
        $unsafeOutput,
        $unsafeExit,
    );
    expect($unsafeExit)->not->toBe(0);
    expect(file_exists($scratch.'/pwned'))->toBeFalse('deployment.conf value must never be executed');
    expect(implode("\n", $unsafeOutput))
        ->toContain('/home/www/rateguru/config/deployment-targets.json');

    // `common` documents and implements the same contract.
    [$exit, $output] = runTargetHelper('target_registry_file', $override);
    expect($exit)->toBe(0);
    expect($output)->toBe($override);

    $script = targetHelperHarness().'target_registry_file';
    exec('TARGET_REGISTRY_FILE='.escapeshellarg($viaConf).' bash -c '.escapeshellarg($script).' 2>&1', $commonConf, $commonExit);
    expect($commonExit)->toBe(0);
    expect(implode("\n", $commonConf))->toBe($viaConf);

    exec('rm -rf '.escapeshellarg($scratch));
});

it('validates the whole target before returning any helper value', function () {
    // Reading application_root must not succeed on a target whose other
    // required properties are missing or invalid — the caller will use those
    // too. Each mutation leaves application_root itself untouched.
    $cases = [
        'missing database.name' => 'del(.targets["staging-main"].database.name)',
        'null runtime_user' => '.targets["staging-main"].runtime_user = null',
        'negative retention' => '.targets["staging-main"].release_retention = -1',
        'fractional retention' => '.targets["staging-main"].release_retention = 2.5',
        'invalid PHP-FPM socket' => '.targets["staging-main"].php_fpm.socket = "/tmp/bad.sock"',
        'empty PHP-FPM socket' => '.targets["staging-main"].php_fpm.socket = ""',
        'missing lifecycle' => 'del(.targets["staging-main"].lifecycle)',
        'unsafe runtime_user' => '.targets["staging-main"].runtime_user = "BAD USER"',
        'non-string public hostname' => '.targets["staging-main"].public_hostnames = [123]',
    ];

    foreach ($cases as $label => $mutation) {
        $path = buildMutatedRegistry($mutation);

        [$exit, $output] = runTargetHelper('target_root staging-main', $path, targetsCli());
        expect($exit)->not->toBe(0, "target_root should have failed: {$label}\n{$output}");
        expect($output)->toContain('validation');

        @unlink($path);
    }

    // The untouched registry still returns the value.
    [$exit, $output] = runTargetHelper('target_root staging-main', targetRegistryPath(), targetsCli());
    expect($exit)->toBe(0, $output);
    expect($output)->toBe('/home/www/rateguru/staging');
});

it('runs the full validator only once per shell process', function () {
    $scratch = sys_get_temp_dir().'/validator-cache-'.uniqid();
    mkdir($scratch);

    $log = $scratch.'/calls.log';
    $stub = $scratch.'/counting-targets';

    // Counts invocations, then delegates to the real validator.
    file_put_contents($stub, "#!/usr/bin/env bash\n"
        .'echo call >> '.escapeshellarg($log)."\n"
        .'exec '.escapeshellarg(targetsCli())." \"\$@\"\n");
    chmod($stub, 0o755);
    touch($log);

    $snippet = 'for f in target_root target_runtime_user target_database_name '
        .'target_php_fpm_socket target_queue_name target_backup_namespace; do '
        ."\$f staging-main >/dev/null; done\n"
        ."target_get staging-main >/dev/null\n"
        .'target_exists staging-main';

    [$exit, $output] = runTargetHelper($snippet, targetRegistryPath(), $stub);
    expect($exit)->toBe(0, $output);

    $calls = substr_count(file_get_contents($log), "call\n");
    expect($calls)->toBe(1, "eight helper calls should validate once, ran {$calls} times");

    exec('rm -rf '.escapeshellarg($scratch));
});

it('fails helpers when the validator itself is unavailable', function () {
    [$exit, $output] = runTargetHelper(
        'target_root staging-main',
        targetRegistryPath(),
        sys_get_temp_dir().'/no-such-validator-'.uniqid(),
    );

    expect($exit)->not->toBe(0, 'a missing validator must not be silently skipped');
    expect($output)->toContain('validator is unavailable');
});

it('rejects unsafe database identifiers and account names', function () {
    $cases = [
        '.targets["staging-main"].database.name = "bad-name"',
        '.targets["staging-main"].database.name = "9leading"',
        '.targets["staging-main"].database.name = "DROP TABLE users"',
        '.targets["staging-main"].database.name = "name;--"',
        '.targets["staging-main"].database.application_role = "bad-role"',
        '.targets["staging-main"].runtime_user = "1leading"',
        '.targets["staging-main"].runtime_user = "-leading"',
        '.targets["staging-main"].runtime_user = "has space"',
        '.targets["staging-main"].runtime_user = "UPPER"',
        '.targets["staging-main"].deploy_user = ("x" * 33)',
        '.targets["staging-main"].code_group = "bad$group"',
    ];

    foreach ($cases as $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
    }
});

it('rejects invalid lifecycle and environment class values', function () {
    foreach ([
        '.targets["staging-main"].lifecycle = "enabled"',
        '.targets["staging-main"].lifecycle = "ACTIVE"',
        '.targets["staging-main"].environment_class = "dev"',
        '.targets["staging-main"].environment_class = "prod"',
    ] as $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
    }
});

it('allows lifecycle active only for staging-main', function () {
    [$exit, $output] = validateMutatedRegistry('.targets["tits-guru"].lifecycle = "active"');

    expect($exit)->not->toBe(0, 'a planned target must not be flippable to active without review');
    expect($output)->toContain('staging-main');

    // planned and disabled stay acceptable for a non-allowlisted target.
    foreach (['planned', 'disabled'] as $lifecycle) {
        [$ok] = validateMutatedRegistry(".targets[\"tits-guru\"].lifecycle = \"{$lifecycle}\"");
        expect($ok)->toBe(0, "lifecycle {$lifecycle} should remain valid for tits-guru");
    }
});

it('rejects an id that does not match its object key', function () {
    [$exit] = validateMutatedRegistry('.targets["staging-main"].id = "something-else"');
    expect($exit)->not->toBe(0);
});

it('rejects invalid target ID keys', function () {
    foreach ([
        '.targets["BAD_ID"] = .targets["staging-main"]',
        '.targets["a"] = .targets["staging-main"]',
        '.targets["-leading"] = .targets["staging-main"]',
        '.targets["has space"] = .targets["staging-main"]',
        '.targets["'.str_repeat('x', 40).'"] = .targets["staging-main"]',
    ] as $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
    }
});

it('rejects an empty or non-object targets map', function () {
    foreach (['.targets = {}', '.targets = []', '.targets = "x"', 'del(.targets)'] as $mutation) {
        [$exit] = validateMutatedRegistry($mutation);
        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
    }
});

it('rejects collision-sensitive values shared between targets', function () {
    $fields = [
        '.application_root',
        '.incoming_artifacts',
        '.runtime_user',
        '.runtime_group',
        '.deploy_user',
        '.code_group',
        '.database.name',
        '.database.application_role',
        '.health.host_header',
        '.backup.namespace',
        '.php_fpm.pool',
        '.php_fpm.socket',
        '.supervisor.program',
        '.supervisor.queue',
        '.scheduler.name',
        '.nginx.site_name',
        '.nginx.internal_hostname',
    ];

    foreach ($fields as $field) {
        $mutation = ".targets[\"tits-guru\"]{$field} = .targets[\"staging-main\"]{$field}";
        [$exit, $output] = validateMutatedRegistry($mutation);

        expect($exit)->not->toBe(0, "should have rejected duplicate {$field}");
        expect($output)->toContain('duplicate');
    }

    // The enumeration above must stay in step with the script's own list, so a
    // field added there without a test here is caught.
    $script = File::get(targetsCli());

    expect(preg_match('/COLLISION_FIELDS=\((.*?)\n\)/s', $script, $matches))
        ->toBe(1, 'could not locate COLLISION_FIELDS in scripts/targets');

    preg_match_all("/^\s*'([^|]+)\|/m", $matches[1], $declared);

    // public_hostnames is covered separately below because it is an array.
    expect(array_values(array_diff($declared[1], [...$fields, '.public_hostnames[]?'])))
        ->toBe([], 'a collision field is declared in scripts/targets but untested here');
});

it('rejects a single overlapping public hostname between targets', function () {
    // Element-wise, not whole-array: tits-guru keeps its own hostname and adds
    // one of staging-main's, which must still collide.
    [$exit, $output] = validateMutatedRegistry(
        '.targets["tits-guru"].public_hostnames = ["tits.guru", "rateguru.staging.myprojects.pp.ua"]',
    );

    expect($exit)->not->toBe(0, 'an overlapping public hostname must be rejected');
    expect($output)
        ->toContain('duplicate')
        ->toContain('rateguru.staging.myprojects.pp.ua');
});

it('rejects secret-like property names at any depth', function () {
    $cases = [
        '.targets["staging-main"].password = "x"',
        '.targets["staging-main"].database.password = "x"',
        '.targets["staging-main"].db_secret = "x"',
        '.targets["staging-main"].api_token = "x"',
        '.targets["staging-main"].private_key = "x"',
        '.targets["staging-main"].b2_credential = "x"',
        '.targets["staging-main"].sentry_dsn = "x"',
        '.targets["staging-main"].nginx.basic_auth_password = "x"',
        '.targets["staging-main"].deep = {"a":{"b":{"c":{"access_key":"x"}}}}',
        '.targets["staging-main"].UPPER_SECRET = "x"',
        '.secrets = {"anything":"x"}',
    ];

    foreach ($cases as $mutation) {
        [$exit, $output] = validateMutatedRegistry($mutation);

        expect($exit)->not->toBe(0, "should have rejected: {$mutation}");
        expect($output)->toContain('secret-like');
    }
});

it('matches secret-like names as substrings, deliberately', function () {
    // The guard is intentionally conservative: it substring-matches, so names
    // that merely embed a secret word are rejected too.
    //
    // The alternative — matching whole underscore-separated segments — would
    // accept `tokens_per_day`, but it would also start accepting `secretkey`
    // and `passwordhash`, which are unambiguously secret names. A false
    // positive here costs one rename; a false negative commits a credential to
    // a world-readable file. This test pins that choice so a future loosening
    // is a deliberate decision rather than an accident.
    foreach (['tokens_per_day', 'dsn_disabled', 'credentials_owner_note'] as $name) {
        [$exit, $output] = validateMutatedRegistry(".targets[\"staging-main\"].{$name} = 1");

        expect($exit)->not->toBe(0, "conservative guard should still reject: {$name}");
        expect($output)->toContain('secret-like');
    }

    // Names with no secret substring at all pass cleanly, so the guard is not
    // simply rejecting every added property.
    foreach (['daily_rate_limit', 'owner_note', 'telemetry_enabled'] as $name) {
        [$exit, $output] = validateMutatedRegistry(".targets[\"staging-main\"].{$name} = 1");

        expect($exit)->toBe(0, "benign property name should be accepted: {$name}\n{$output}");
        expect($output)->not->toContain('secret-like');
    }
});

it('contains no secret-like values in the committed registry', function () {
    $raw = File::get(targetRegistryPath());

    // Belt-and-braces beside the validator's property-name check: no value in
    // the committed file may look like a credential or key material.
    foreach ([
        'BEGIN RSA', 'BEGIN OPENSSH', 'BEGIN PRIVATE',
        'ssh-rsa', 'ssh-ed25519',
        'base64:',            // Laravel APP_KEY prefix
        'https://', 'postgres://', 'smtp://',
        '@sentry.io',
    ] as $needle) {
        expect(str_contains($raw, $needle))
            ->toBeFalse("registry contains secret-like content: {$needle}");
    }
});

// --- common helpers ---------------------------------------------------------

it('reads target values through the common helpers', function () {
    $expected = [
        'target_lifecycle staging-main' => 'active',
        'target_environment_class staging-main' => 'staging',
        'target_root staging-main' => '/home/www/rateguru/staging',
        'target_runtime_user staging-main' => 'rateguru-staging',
        'target_runtime_group staging-main' => 'rateguru-staging',
        'target_deploy_user staging-main' => 'deploy-rateguru-staging',
        'target_code_group staging-main' => 'rateguru-staging-code',
        'target_incoming_artifacts staging-main' => '/home/deploy-rateguru-staging/incoming',
        'target_release_retention staging-main' => '5',
        'target_database_name staging-main' => 'rateguru_staging',
        'target_database_role staging-main' => 'rateguru_staging_app',
        'target_health_url staging-main' => 'http://127.0.0.1/',
        'target_health_host_header staging-main' => 'rateguru-staging.internal',
        'target_backup_namespace staging-main' => 'staging',
        'target_local_backup_retention staging-main' => '5',
        'target_offsite_backup_retention staging-main' => '14',
        'target_minimum_retained_backups staging-main' => '2',
        'target_minimum_retained_backups tits-guru' => '2',
        'target_php_fpm_pool staging-main' => 'rateguru-staging',
        'target_php_fpm_socket staging-main' => '/run/php/rateguru-staging.sock',
        'target_supervisor_program staging-main' => 'rateguru-staging-queue',
        'target_queue_name staging-main' => 'rateguru-staging',
        'target_scheduler_name staging-main' => 'rateguru-staging-scheduler',
        'target_nginx_site_name staging-main' => 'rateguru-staging',
        'target_php_fpm_socket tits-guru' => '/run/php/rateguru-tits-guru.sock',
        'target_backup_namespace tits-guru' => 'tits-guru',
    ];

    foreach ($expected as $call => $value) {
        [$exit, $output] = runTargetHelper($call, targetRegistryPath(), targetsCli());

        expect($exit)->toBe(0, "{$call} failed:\n{$output}");
        expect($output)->toBe($value, "{$call} returned the wrong value");
    }
});

it('fails clearly in every target helper error mode', function () {
    $registry = targetRegistryPath();

    // Absent registry.
    [$exit, $output] = runTargetHelper('target_root staging-main', sys_get_temp_dir().'/absent-'.uniqid().'.json');
    expect($exit)->not->toBe(0);
    expect($output)->toContain('unavailable');

    // Invalid JSON.
    $malformed = sys_get_temp_dir().'/bad-'.uniqid().'.json';
    file_put_contents($malformed, "{oops\n");
    [$exit, $output] = runTargetHelper('target_root staging-main', $malformed);
    expect($exit)->not->toBe(0);
    expect($output)->toContain('not valid JSON');
    @unlink($malformed);

    // Unsupported schema version.
    $unsupported = buildMutatedRegistry('.schema_version = 9');
    [$exit, $output] = runTargetHelper('target_root staging-main', $unsupported);
    expect($exit)->not->toBe(0);
    expect($output)->toContain('schema_version');
    @unlink($unsupported);

    // Symlink.
    $link = sys_get_temp_dir().'/link-'.uniqid().'.json';
    symlink($registry, $link);
    [$exit, $output] = runTargetHelper('target_root staging-main', $link);
    expect($exit)->not->toBe(0);
    expect($output)->toContain('symlink');
    @unlink($link);

    // Unknown target and malformed IDs.
    [$exit, $output] = runTargetHelper('target_root ghost', $registry, targetsCli());
    expect($exit)->not->toBe(0);
    expect($output)->toContain('unknown target');

    foreach (['Staging-Main', '../etc', 'a'] as $badId) {
        [$exit, $output] = runTargetHelper("target_root '{$badId}'", $registry, targetsCli());
        expect($exit)->not->toBe(0, "should have rejected ID: {$badId}");
        expect($output)->toContain('invalid target ID');
    }

    // Missing / null / wrong-typed property.
    foreach ([
        'del(.targets["staging-main"].application_root)',
        '.targets["staging-main"].application_root = null',
        '.targets["staging-main"].application_root = 42',
    ] as $mutation) {
        $path = buildMutatedRegistry($mutation);

        [$exit, $output] = runTargetHelper('target_root staging-main', $path, targetsCli());
        expect($exit)->not->toBe(0, "should have failed for: {$mutation}");
        expect($output)->toContain('application_root');

        @unlink($path);
    }
});

it('does not touch the registry until a target helper is called', function () {
    // The whole point of lazy loading: scripts already installed on the VPS
    // gain these functions but must keep working while the registry is absent.
    $absent = sys_get_temp_dir().'/never-created-'.uniqid().'.json';

    [$exit, $output] = runTargetHelper(
        'echo "sourced fine"; type -t target_root; echo "registry file: $(target_registry_file)"',
        $absent,
    );

    expect($exit)->toBe(0, "sourcing must not fail without a registry:\n{$output}");
    expect($output)
        ->toContain('sourced fine')
        ->toContain('function')
        ->toContain($absent);

    // Only calling a helper reads the file.
    [$callExit, $callOutput] = runTargetHelper('target_root staging-main', $absent);
    expect($callExit)->not->toBe(0);
    expect($callOutput)->toContain('unavailable');
});

it('resolves the registry path in the documented order', function () {
    $common = File::get(base_path('infrastructure/scripts/common'));

    expect($common)
        ->toContain('TARGET_REGISTRY_DEFAULT_FILE="/home/www/rateguru/config/deployment-targets.json"')
        ->toContain('RATEGURU_TARGET_REGISTRY_FILE')
        ->toContain('TARGET_REGISTRY_FILE');

    // Override wins over everything.
    [$exit, $output] = runTargetHelper('target_registry_file', '/tmp/override.json');
    expect($exit)->toBe(0);
    expect($output)->toBe('/tmp/override.json');

    // With no override, TARGET_REGISTRY_FILE from deployment.conf is used.
    $script = targetHelperHarness().'target_registry_file';
    exec('TARGET_REGISTRY_FILE=/tmp/from-conf.json bash -c '.escapeshellarg($script).' 2>&1', $confOutput, $confExit);
    expect($confExit)->toBe(0);
    expect(implode("\n", $confOutput))->toBe('/tmp/from-conf.json');

    // With neither, the installed default.
    exec('bash -c '.escapeshellarg($script).' 2>&1', $defaultOutput, $defaultExit);
    expect($defaultExit)->toBe(0);
    expect(implode("\n", $defaultOutput))->toBe('/home/www/rateguru/config/deployment-targets.json');
});

it('never builds a jq program from untrusted target input', function () {
    foreach (['infrastructure/scripts/common', 'infrastructure/scripts/targets'] as $path) {
        $source = File::get(base_path($path));

        // The registry is data: never sourced, never eval'd.
        expect($source)
            ->not->toMatch('/^\s*eval\s/m')
            ->not->toMatch('/^\s*(source|\.)\s+.*deployment-targets/m');

        // Target IDs reach jq through --arg, never by interpolation. The unsafe
        // shape is a shell expansion *inside a jq path expression* — e.g.
        // .targets["${target_id}"] — as opposed to `--arg id "${target_id}"`,
        // which is the safe form and must keep appearing.
        expect($source)->toContain('--arg id');
        expect($source)->not->toMatch('/\.targets\[[^\]]*\$\{/');

        // Prove the guard actually discriminates: the safe form present in the
        // real source must not trip it, and the unsafe form must.
        expect(preg_match('/\.targets\[[^\]]*\$\{/', 'jq --arg id "${target_id}" \'.targets[$id]\''))->toBe(0);
        expect(preg_match('/\.targets\[[^\]]*\$\{/', 'jq ".targets[\"${target_id}\"]"'))->toBe(1);
    }
});

// --- no operational behaviour changed --------------------------------------

it('changes no runtime configuration in this slice', function () {
    // Nginx, PHP-FPM, Supervisor and the Laravel scheduler cron are
    // untouched: nothing in them may reference the registry or a target ID.
    // config/cron/rateguru-backups and config/sudoers/rateguru-deploy
    // legitimately graduated to --target/staging-main in the target-aware migration —
    // see TargetPerimeterTest.php for their own coverage — and are
    // deliberately absent here.
    $configs = [
        'config/nginx/rateguru-staging',
        'config/nginx/rateguru-production',
        'config/php-fpm/rateguru-staging.conf',
        'config/php-fpm/rateguru-production.conf',
        'config/supervisor/rateguru-staging-queue.conf',
        'config/cron/rateguru-staging-scheduler',
    ];

    foreach ($configs as $path) {
        $source = File::get(base_path('infrastructure/'.$path));

        expect($source)
            ->not->toContain('deployment-targets')
            ->not->toContain('--target')
            ->not->toContain('staging-main')
            ->not->toContain('tits-guru');
    }

    // GitHub Actions deployment is likewise untouched.
    foreach (['deploy-staging.yml', 'release.yml'] as $workflow) {
        $source = File::get(base_path('.github/workflows/'.$workflow));

        expect($source)
            ->not->toContain('deployment-targets')
            ->not->toContain('--target ');
    }
});

it('records the registry as a completed the target-aware migration slice inside a completed phase', function () {
    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)
        ->toMatch('/^\|\s*4\s*\|\s*Multi-target production model\s*\|\s*✅ completed\s*\|$/m')
        ->toContain('## 4. Multi-target production model — completed')
        // The registry slice is one of the nine that closed the phase.
        ->toContain('Deployment target registry — completed')
        ->toContain('runbooks/deployment-targets.md')
        // No stale "current" wording left on the phase.
        ->not->toContain('## 4. Multi-target production model — current')
        ->not->toMatch('/^\|\s*4\s*\|\s*Multi-target production model\s*\|\s*🚧 current\s*\|$/m');

    // the target-aware migration is closed, and the clean-host bootstrap has since closed behind it; the roadmap
    // still names exactly one current phase.
    expect(substr_count($roadmap, '🚧 current'))->toBe(1);
    expect($roadmap)
        ->toMatch('/^\|\s*5\s*\|\s*Infrastructure installer and clean-VPS bootstrap\s*\|\s*✅ completed\s*\|$/m');
});

it('documents the registry model in a runbook', function () {
    $runbook = base_path('infrastructure/runbooks/deployment-targets.md');
    expect(File::exists($runbook))->toBeTrue();

    $contents = File::get($runbook);

    expect($contents)
        // Target ID versus environment class.
        ->toContain('Target versus environment class')
        ->toContain('staging-main')
        ->toContain('tits-guru')
        // Source and future runtime destination.
        ->toContain('infrastructure/config/deployment-targets.json')
        ->toContain('/home/www/rateguru/config/deployment-targets.json')
        // Lifecycle semantics and why planned is not deployable.
        ->toContain('Lifecycle')
        ->toContain('Why a planned target cannot be deployed')
        // Non-secret values and where secrets stay.
        ->toContain('What is non-secret')
        ->toContain('Where secrets stay')
        ->toContain('rclone.conf')
        // The interface is now target-only; History points at the full
        // migration record instead of restating it.
        ->toContain('The `--target` interface')
        ->toContain('## History')
        ->not->toContain('--environment');

    // The History section points at ROADMAP.md rather than re-deriving the
    // migration order itself, so this test no longer checks step ordering —
    // DeploymentTargetRegistryTest's own ROADMAP-facing tests cover that.
    expect(preg_match('/## History\n(.*?)(?=\n## )/s', $contents, $sectionMatch))
        ->toBe(1, 'could not locate the History section');
    expect($sectionMatch[1])->toContain('infrastructure/ROADMAP.md');
});

it('documents the registry resolution contract the code actually implements', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/deployment-targets.md'));
    $cli = File::get(targetsCli());
    $common = File::get(base_path('infrastructure/scripts/common'));

    // GUARD: the runbook previously claimed the CLI ignored TARGET_REGISTRY_FILE
    // and deployment.conf. That became false once the CLI learned to parse the
    // file, and the stale claim survived a review round. These assertions make
    // the claim un-reintroducible.
    $normalized = preg_replace('/\s+/', ' ', $runbook);

    foreach ([
        'TARGET_REGISTRY_FILE` is not consulted at all',
        'is not consulted at all',
        'does not consult TARGET_REGISTRY_FILE',
        'ignores TARGET_REGISTRY_FILE',
        'ignores deployment.conf',
    ] as $staleClaim) {
        expect(str_contains($normalized, $staleClaim))
            ->toBeFalse("runbook reintroduced a stale claim: {$staleClaim}");
    }

    // Both consumers are documented, each level present and in order.
    foreach ([
        '`RATEGURU_TARGET_REGISTRY_FILE`',
        '`TARGET_REGISTRY_FILE`, loaded from `deployment.conf`',
        '`--file PATH`, when given',
        '`TARGET_REGISTRY_FILE` exported in the environment',
        '`TARGET_REGISTRY_FILE` parsed out of `deployment.conf`',
        '/home/www/rateguru/config/deployment-targets.json',
    ] as $level) {
        expect(str_contains($normalized, preg_replace('/\s+/', ' ', $level)))
            ->toBeTrue("runbook is missing a resolution level: {$level}");
    }

    // The safety properties the CLI actually implements are stated.
    expect($normalized)
        ->toContain('never sources or evaluates `deployment.conf`.')
        ->toContain('rejects any value still containing shell metacharacters')
        ->toContain('assigns **without** `export`');

    // ...and the code backs each of them up.
    expect($cli)
        ->toContain('deployment_conf_registry_file')
        ->toContain('TARGET_REGISTRY_FILE')
        // Parsed, never sourced or eval'd.
        ->toContain("sed -n 's/^[[:space:]]*TARGET_REGISTRY_FILE")
        ->not->toMatch('/^\s*(source|\.)\s+.*DEPLOYMENT_CONF_FILE/m')
        ->not->toMatch('/^\s*eval\s/m');

    // The documented order matches the implemented order: --file, then the
    // override, then the environment, then the parsed file.
    // Anchored to column 0: the same condition also guards duplicate --file
    // during argument parsing, nested and indented.
    expect(preg_match(
        '/^if \[\[ "\$\{FILE_SEEN\}" == true \]\]; then$(.*?)^fi$/ms',
        $cli,
        $selection,
    ))->toBe(1, 'could not locate the registry selection block in scripts/targets');

    $previous = -1;

    foreach ([
        'FILE_SEEN',
        'RATEGURU_TARGET_REGISTRY_FILE',
        'TARGET_REGISTRY_FILE',
        'deployment_conf_registry_file',
        'REGISTRY_DEFAULT_FILE',
    ] as $step) {
        $position = strpos($selection[1], $step);
        // FILE_SEEN is the `if` itself, so it may be absent from the body.
        if ($position === false && $step === 'FILE_SEEN') {
            continue;
        }

        expect($position)->not->toBeFalse("selection step missing: {$step}");
        expect($position)->toBeGreaterThan($previous, "selection step out of order: {$step}");
        $previous = $position;
    }

    // `common` documents and implements the same first three levels.
    expect($common)
        ->toContain('RATEGURU_TARGET_REGISTRY_FILE')
        ->toContain('TARGET_REGISTRY_FILE')
        ->toContain('TARGET_REGISTRY_DEFAULT_FILE');
});

it('documents how to verify runtime parity against the VPS', function () {
    $contents = File::get(base_path('infrastructure/runbooks/deployment-targets.md'));

    expect($contents)
        ->toContain('Verifying runtime parity')
        // States plainly that repository tests are not evidence about the host.
        ->toContain('They cannot prove it agrees with the **running VPS**')
        // Names the real drift that motivated the section.
        ->toContain('rateguru-staging-code')
        ->toContain('two distinct
groups')
        // The concrete commands an operator runs.
        ->toContain('/home/www/rateguru/config/deployment.conf')
        ->toContain('stat -Lc')
        ->toContain('getent group rateguru-staging-code');
});
