<?php

use Illuminate\Support\Facades\File;

/**
 * Prepare Host: infrastructure/scripts/prepare-host — the one authoritative
 * server-side preparation entry point, which turns a clean supported VPS into
 * infrastructure ready to host one RateGuru target.
 *
 * Every test executes the real, shipped script as a subprocess — never a
 * reimplementation — against fixture/shim children: one stub per slice that
 * logs every invocation, answers its read-only modes from a per-child
 * "compliant" toggle, converges that toggle on --apply, and records every
 * mutating invocation in a dedicated mutation log. All injected through
 * RATEGURU_PREPAREHOST_* overrides the script honors only alongside
 * RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here touches the CI host.
 *
 * What matters architecturally: the exact slice order, the lifecycle gate
 * firing before any target-specific mutation, verify-skip-apply convergence
 * (so a second run is SKIPs rather than blind reinstallation), bootstrap-host
 * being reused rather than decomposed, and the complete absence of deploy,
 * migration, restore and secret-generation behaviour.
 */

// =============================================================================
// Harness
// =============================================================================

function prepScript(): string
{
    return base_path('infrastructure/scripts/prepare-host');
}

function prepSource(): string
{
    return File::get(prepScript());
}

function prepScratchDir(): string
{
    $dir = sys_get_temp_dir().'/prepare-host-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/log', '/toggles'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

/**
 * A registry fixture for the lifecycle gate.
 *
 * The committed registry supplies the SHAPE — every field the real `targets`
 * validator demands, without this file having to restate a schema it does not
 * own — but the fixture then states the two facts these tests are about
 * outright: one active target and one planned one. Flipping a lifecycle in the
 * committed registry therefore cannot change what the tests below mean, which a
 * verbatim copy would not have achieved.
 *
 * The separate test at the end of this file is what asserts the real registry
 * still gives those two targets those two lifecycles.
 */
function prepRegistryFixture(string $scratch): string
{
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    foreach (['staging-main' => 'active', 'tits-guru' => 'planned'] as $target => $lifecycle) {
        // toHaveKey's second argument is an expected VALUE, not a message, so
        // the diagnostic lives here: the fixture borrows the committed
        // registry's shape and needs both entries to exist to borrow it from.
        expect($registry['targets'])->toHaveKey($target);

        $registry['targets'][$target]['lifecycle'] = $lifecycle;
    }

    $path = $scratch.'/deployment-targets.json';

    file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $path;
}

function prepCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function prepRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', prepScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start prepare-host subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function prepWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/** @return list<string> */
function prepLog(string $scratch, string $name): array
{
    $path = $scratch.'/log/'.$name.'.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

/**
 * One stub per child. Read-only modes answer from the "<slice>-compliant"
 * toggle and print a child-shaped SUMMARY the orchestrator excerpts; --apply
 * logs a mutation and converges the toggle unless a failure toggle is set.
 *
 * The prerequisite installer is one binary serving two slices, exactly as it
 * is in production, so the stub keys its toggle off the --scope it was given —
 * which is also how these tests prove the two scopes really are ordered around
 * the bootstrap slice.
 */
function prepWriteChildStubs(string $scratch): void
{
    prepWriteStub($scratch.'/bin/prerequisites', <<<'STUB'
        #!/bin/bash
        printf 'prerequisites %s\n' "$*" >> "${STUB_LOG}/children.log"
        scope=host
        case "$*" in *"--scope target"*) scope=target ;; esac
        key="prerequisites-${scope}"
        case "$*" in
            *--apply*)
                printf '%s %s\n' "${key}" "$*" >> "${STUB_LOG}/mutations.log"
                if [[ -e "${STUB_TOGGLES}/${key}-apply-fail" ]]; then
                    echo "ERROR: external prerequisite tls-private-key: already present and DIFFERS from the supplied material"
                    exit 1
                fi
                touch "${STUB_TOGGLES}/${key}-compliant"
                echo "${key} apply done"
                exit 0
                ;;
            *--check*)
                # The material-aware mode: satisfied only when the destination
                # holds what the run supplied. A conflict toggle makes it
                # refuse even though the material-blind verify would pass.
                if [[ -e "${STUB_TOGGLES}/${key}-material-conflict" ]]; then
                    echo "SUMMARY"
                    echo "conflicts 1"
                    exit 1
                fi
                if [[ -e "${STUB_TOGGLES}/${key}-compliant" ]]; then
                    echo "SUMMARY"
                    echo "${key} CONTRACT: SATISFIED"
                    exit 0
                fi
                echo "SUMMARY"
                echo "${key} CONTRACT: NOT SATISFIED"
                exit 1
                ;;
            *)
                if [[ -e "${STUB_TOGGLES}/${key}-compliant" ]]; then
                    echo "SUMMARY"
                    echo "${key} CONTRACT: SATISFIED"
                    exit 0
                fi
                echo "SUMMARY"
                echo "${key} CONTRACT: NOT SATISFIED"
                exit 1
                ;;
        esac
        STUB);

    foreach (['runtime', 'bootstrap', 'database'] as $child) {
        prepWriteStub($scratch.'/bin/'.$child, <<<'STUB'
            #!/bin/bash
            me="$(basename "$0")"
            printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/children.log"
            case "$*" in
                *--apply*)
                    printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/mutations.log"
                    if [[ -e "${STUB_TOGGLES}/${me}-apply-fail" ]]; then
                        echo "ERROR: ${me} simulated apply failure"
                        exit 1
                    fi
                    if [[ ! -e "${STUB_TOGGLES}/${me}-apply-no-converge" ]]; then
                        touch "${STUB_TOGGLES}/${me}-compliant"
                    fi
                    echo "${me} apply done"
                    exit 0
                    ;;
                *)
                    if [[ -e "${STUB_TOGGLES}/${me}-readonly-exit-130" ]]; then
                        exit 130
                    fi
                    if [[ -e "${STUB_TOGGLES}/${me}-compliant" ]]; then
                        echo "SUMMARY"
                        echo "${me} CONTRACT: SATISFIED"
                        exit 0
                    fi
                    echo "SUMMARY"
                    echo "${me} CONTRACT: NOT SATISFIED"
                    exit 1
                    ;;
            esac
            STUB);
    }
}

/**
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function prepFixture(string $scratch, array $options = []): array
{
    prepWriteChildStubs($scratch);

    foreach ($options['compliant'] ?? [] as $slice) {
        touch($scratch.'/toggles/'.$slice.'-compliant');
    }

    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_PREPAREHOST_EUID' => $options['euid'] ?? '0',
        // The real `targets` CLI — it is the authoritative reader and the thing
        // whose behaviour matters — pointed at a registry fixture inside the
        // scratch tree. Both are named explicitly, so a run can never pick up
        // an installed runtime registry, and an edit to the committed one
        // cannot silently change what these tests mean.
        'RATEGURU_PREPAREHOST_TARGETS_CLI_BIN' => $options['targets_cli']
            ?? base_path('infrastructure/scripts/targets'),
        'RATEGURU_PREPAREHOST_SOURCE_REGISTRY' => $options['registry']
            ?? prepRegistryFixture($scratch),
        'RATEGURU_PREPAREHOST_RUNTIME_INSTALLER_BIN' => $scratch.'/bin/runtime',
        'RATEGURU_PREPAREHOST_PREREQUISITES_INSTALLER_BIN' => $scratch.'/bin/prerequisites',
        'RATEGURU_PREPAREHOST_BOOTSTRAP_HOST_BIN' => $scratch.'/bin/bootstrap',
        'RATEGURU_PREPAREHOST_DATABASE_INSTALLER_BIN' => $scratch.'/bin/database',
        'STUB_LOG' => $scratch.'/log',
        'STUB_TOGGLES' => $scratch.'/toggles',
    ];
}

/** Every slice compliant — an already prepared host. */
function prepPreparedFixture(string $scratch): array
{
    return prepFixture($scratch, [
        'compliant' => [
            'runtime',
            'prerequisites-host',
            'bootstrap',
            'prerequisites-target',
            'database',
        ],
    ]);
}

// =============================================================================
// Shipping and CLI contract
// =============================================================================

it('ships prepare-host as an executable script in the infrastructure bundle', function () {
    expect(File::exists(prepScript()))->toBeTrue();
    expect(is_executable(prepScript()))->toBeTrue('prepare-host must be executable');
    expect(prepSource())->toStartWith("#!/usr/bin/env bash\n");
});

it('requires --target in every mode', function () {
    $scratch = prepScratchDir();

    try {
        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = prepRun([$mode], prepFixture($scratch));

            expect($exit)->toBe(1, "{$mode} without --target must fail");
            expect($output)->toContain('--target is required');
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('requires exactly one mode and rejects unknown arguments', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);

        [$exit, $output] = prepRun(['--target', 'staging-main'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('one of --check, --apply or --verify is required');

        [$exit, $output] = prepRun(['--check', '--apply', '--target', 'staging-main'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('mode given more than once');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main', '--force'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('unknown argument: --force');
    } finally {
        prepCleanup($scratch);
    }
});

it('has no --force, --skip or --continue-on-error escape hatch', function () {
    $source = prepSource();

    foreach (['--force', '--skip', '--continue-on-error'] as $flag) {
        expect($source)->not->toMatch('/^\s*'.preg_quote($flag, '/').'\)/m',
            "prepare-host must not accept {$flag}");
    }
});

it('requires root in every mode', function () {
    $scratch = prepScratchDir();

    try {
        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = prepRun(
                [$mode, '--target', 'staging-main'],
                prepFixture($scratch, ['euid' => '1000']),
            );

            expect($exit)->toBe(1);
            expect($output)->toContain('must run as root');
        }

        expect(prepLog($scratch, 'children'))->toBe([], 'no child may run for a non-root caller');
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Target lifecycle — the gate that keeps production unprovisioned
// =============================================================================

it('refuses a lifecycle=planned target before any child runs', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--apply', '--target', 'tits-guru'],
            prepFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('lifecycle=planned, not active');

        // The whole point: nothing ran at all, so no host-global bootstrap
        // could have provisioned a planned production target as a side effect.
        expect(prepLog($scratch, 'children'))->toBe([]);
        expect(prepLog($scratch, 'mutations'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('refuses an unknown target before any child runs', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--apply', '--target', 'not-a-real-target'],
            prepFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('unknown or unusable target: not-a-real-target');
        expect(prepLog($scratch, 'mutations'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('accepts the lifecycle=active staging target', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--verify', '--target', 'staging-main'],
            prepPreparedFixture($scratch),
        );

        expect($exit)->toBe(0);
        expect($output)->toContain('Target staging-main: lifecycle=active');
        expect($output)->toContain('TARGET PREPARED: YES');
    } finally {
        prepCleanup($scratch);
    }
});

it('rejects a malformed target ID without consulting the registry', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--apply', '--target', 'Staging Main; rm -rf /'],
            prepFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('invalid target ID');
        expect(prepLog($scratch, 'mutations'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Slice order and reuse of bootstrap-host
// =============================================================================

it('runs the slices in the only order that can succeed on a clean host', function () {
    $scratch = prepScratchDir();

    try {
        [$exit] = prepRun(['--apply', '--target', 'staging-main'], prepFixture($scratch));

        expect($exit)->toBe(0);

        $mutations = prepLog($scratch, 'mutations');
        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            $mutations,
        );

        // Host-scope material before bootstrap, because install-bootstrap-services fails closed
        // without it; target-scope material after, because its parent
        // directories and owning accounts are created by install-bootstrap-host-layout; the
        // database last, because it needs both PostgreSQL and the credentials
        // inside shared/.env.
        expect($order)->toBe([
            'runtime',
            'prerequisites-host',
            'bootstrap',
            'prerequisites-target',
            'database',
        ]);
    } finally {
        prepCleanup($scratch);
    }
});

it('reuses bootstrap-host rather than duplicating its slices', function () {
    $source = prepSource();

    // The bootstrap pipeline is invoked, not reimplemented. The children
    // prepare-host resolves are exactly the four it declares — bootstrap-host
    // among them — and never 5.3's, 5.4's or the preflight's own installers,
    // which stay bootstrap-host's to sequence. (The header prose names them to
    // explain the ordering; only the resolved binaries are asserted here.)
    preg_match_all('/^([A-Z_]+_BIN)="\$\(gated_default \S+ "\$\{SCRIPT_DIR\}\/([a-z-]+)"\)"/m', $source, $matches);

    expect($matches[2])->toBe([
        'install-bootstrap-runtime',
        'install-target-prerequisites',
        'bootstrap-host',
        'install-target-database',
        'targets',
    ]);

    $scratch = prepScratchDir();

    try {
        prepRun(['--apply', '--target', 'staging-main'], prepFixture($scratch));

        $bootstrapCalls = array_values(array_filter(
            prepLog($scratch, 'children'),
            static fn (string $line): bool => str_starts_with($line, 'bootstrap '),
        ));

        // Exactly the child's own modes, never a per-slice flag of its own.
        foreach ($bootstrapCalls as $call) {
            expect($call)->toMatch('/^bootstrap --(verify|apply)$/');
        }

        expect($bootstrapCalls)->not->toBeEmpty();
    } finally {
        prepCleanup($scratch);
    }
});

it('passes --target only to the target-aware children, never to bootstrap-host', function () {
    $scratch = prepScratchDir();

    try {
        prepRun(['--apply', '--target', 'staging-main'], prepFixture($scratch));

        foreach (prepLog($scratch, 'children') as $call) {
            if (str_starts_with($call, 'bootstrap ') || str_starts_with($call, 'runtime ')) {
                // Host-global children take no target: a host is
                // bootstrapped once and can carry several targets.
                expect($call)->not->toContain('--target');

                continue;
            }

            expect($call)->toContain('--target staging-main');
        }
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Convergence — verify, skip, apply
// =============================================================================

it('skips a slice whose own verify already passes instead of reapplying it', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--apply', '--target', 'staging-main'],
            prepFixture($scratch, ['compliant' => ['runtime', 'prerequisites-host', 'bootstrap']]),
        );

        expect($exit)->toBe(0);
        expect($output)->toContain('SKIP — already satisfied');

        // Only the two genuinely unsatisfied slices mutated anything.
        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            prepLog($scratch, 'mutations'),
        );

        expect($order)->toBe(['prerequisites-target', 'database']);
    } finally {
        prepCleanup($scratch);
    }
});

it('surfaces conflicting material on a prepared host instead of skipping past it', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepPreparedFixture($scratch);

        // The host is fully prepared and its material-blind verify passes, but
        // the operator supplied a rotated secret. Preparation must report the
        // conflict, not quietly succeed.
        touch($scratch.'/toggles/prerequisites-host-material-conflict');
        touch($scratch.'/toggles/prerequisites-host-apply-fail');

        [$exit, $output] = prepRun(
            ['--apply', '--target', 'staging-main', '--material-dir', '/root/material'],
            $env,
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('DIFFERS from the supplied material');
        expect($output)->toContain('resume at slice host-prerequisites');
    } finally {
        prepCleanup($scratch);
    }
});

it('is idempotent: a second run on a prepared host mutates nothing at all', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);

        [$firstExit] = prepRun(['--apply', '--target', 'staging-main'], $env);
        expect($firstExit)->toBe(0);
        expect(prepLog($scratch, 'mutations'))->toHaveCount(5);

        // The convergence the whole safe-to-press-twice contract rests on.
        @unlink($scratch.'/log/mutations.log');

        [$secondExit, $secondOutput] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($secondExit)->toBe(0);
        expect(prepLog($scratch, 'mutations'))->toBe([],
            'a second prepare run must reinstall nothing');
        expect(substr_count($secondOutput, 'SKIP — already satisfied'))->toBe(5);
        expect($secondOutput)->toContain('TARGET PREPARED: YES');
    } finally {
        prepCleanup($scratch);
    }
});

it('stops at the first failing slice and never reaches later ones', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        touch($scratch.'/toggles/prerequisites-host-apply-fail');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('re-run prepare-host --apply to resume at slice host-prerequisites');

        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            prepLog($scratch, 'mutations'),
        );

        // The runtime slice converged and stays converged; bootstrap, the
        // target material and the database were never touched.
        expect($order)->toBe(['runtime', 'prerequisites-host']);
    } finally {
        prepCleanup($scratch);
    }
});

it('fails when a child apply reports success but its own verify still does not pass', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        touch($scratch.'/toggles/database-apply-no-converge');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('slice database post-apply verification failed');
    } finally {
        prepCleanup($scratch);
    }
});

it('propagates an abnormal child status verbatim and never escalates it into an apply', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch, ['compliant' => ['runtime', 'prerequisites-host']]);
        touch($scratch.'/toggles/bootstrap-readonly-exit-130');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(130, 'a signal-derived child status must survive');
        expect($output)->toContain('slice bootstrap pre-apply verification failed');
        expect(prepLog($scratch, 'mutations'))->toBe([],
            'an interrupted verification must never trigger a child apply');
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Read-only modes really are read-only
// =============================================================================

it('uses the material-aware check as the pre-apply gate for the prerequisite slices', function () {
    $scratch = prepScratchDir();

    try {
        // A prerequisite slice's --verify is deliberately material-blind, so
        // skipping on it would let a re-run whose supplied material CONFLICTS
        // with the host sail past the one diagnostic that exists to catch it.
        prepRun(
            ['--apply', '--target', 'staging-main', '--material-dir', '/root/material'],
            prepPreparedFixture($scratch),
        );

        $prerequisiteChecks = array_values(array_filter(
            prepLog($scratch, 'children'),
            static fn (string $line): bool => str_starts_with($line, 'prerequisites '),
        ));

        expect($prerequisiteChecks)->not->toBeEmpty();

        foreach ($prerequisiteChecks as $call) {
            expect($call)->toContain('--check');
            expect($call)->toContain('--material-dir /root/material');
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('falls back to the material-blind verify when no material was supplied', function () {
    $scratch = prepScratchDir();

    try {
        prepRun(['--apply', '--target', 'staging-main'], prepPreparedFixture($scratch));

        foreach (prepLog($scratch, 'children') as $call) {
            if (str_starts_with($call, 'prerequisites ')) {
                expect($call)->toContain('--verify');
                expect($call)->not->toContain('--material-dir');
            }
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('never invokes a child apply from --check or --verify', function () {
    $scratch = prepScratchDir();

    try {
        foreach (['--check', '--verify'] as $mode) {
            prepRun([$mode, '--target', 'staging-main'], prepFixture($scratch));
        }

        expect(prepLog($scratch, 'mutations'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('reports downstream slices as BLOCKED rather than misjudging them', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--check', '--target', 'staging-main'],
            prepFixture($scratch, ['compliant' => ['runtime']]),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('NEEDS_APPLY');
        expect($output)->toContain('BLOCKED until slice host-prerequisites is satisfied');
        expect($output)->toContain('TARGET PREPARED: NO');

        // A database installer cannot meaningfully judge a host that has no
        // PostgreSQL on it yet, so it is never asked.
        $children = prepLog($scratch, 'children');
        $databaseCalls = array_filter($children, static fn (string $l): bool => str_starts_with($l, 'database '));
        expect($databaseCalls)->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('never passes the material directory to a verification', function () {
    $scratch = prepScratchDir();

    try {
        prepRun(
            ['--apply', '--target', 'staging-main', '--material-dir', '/root/material'],
            prepFixture($scratch),
        );

        foreach (prepLog($scratch, 'children') as $call) {
            if (str_contains($call, '--verify')) {
                // A prepared host is judged by what is installed on it,
                // never by what was uploaded beside the run.
                expect($call)->not->toContain('--material-dir');
            }
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('refuses --material-dir on --verify', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--verify', '--target', 'staging-main', '--material-dir', '/root/material'],
            prepFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('--verify never consults supplied material');
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// What preparation deliberately is not
// =============================================================================

it('never deploys, migrates, restores or fabricates release state', function () {
    $source = prepSource();

    // Not a single one of these verbs may appear as an operation this script
    // performs. They are named only in the header that explains why they are
    // absent, so the assertions target executable shapes.
    foreach ([
        '/artisan\s+migrate/',
        '/\bpg_restore\b/',
        '/\brateguru-deploy\b/',
        '/\bcurrent\b\s*->/',
        '/ln\s+-s/',
        '/tar\s+-x/',
    ] as $pattern) {
        expect($source)->not->toMatch($pattern,
            "prepare-host must not contain {$pattern}");
    }

    // And it takes no application-source input of any kind.
    foreach (['--ref', '--release', '--source-sha', '--branch', '--tag', '--migrate'] as $flag) {
        expect($source)->not->toMatch('/^\s*'.preg_quote($flag, '/').'\)/m',
            "prepare-host must not accept {$flag}");
    }
});

it('generates no secret material of any kind', function () {
    $source = prepSource();

    foreach ([
        '/openssl\s+(genrsa|req|rand|dhparam)/',
        '/ssh-keygen\s+-t/',
        '/htpasswd\s/',
        '/artisan\s+key:generate/',
        '/certbot\s/',
    ] as $pattern) {
        expect($source)->not->toMatch($pattern,
            "prepare-host must never generate secret material ({$pattern})");
    }
});

it('reports plainly that a prepared target has no application release', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--verify', '--target', 'staging-main'],
            prepPreparedFixture($scratch),
        );

        expect($exit)->toBe(0);
        expect($output)->toContain('APPLICATION DEPLOYED: NOT REQUIRED');
    } finally {
        prepCleanup($scratch);
    }
});

it('gates on the same lifecycle values the committed registry actually declares', function () {
    // The tests above run against a fixture that states its own lifecycles, so
    // this is what ties them back to reality: the two target IDs they exercise,
    // with the lifecycles the committed registry really gives them.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['staging-main']['lifecycle'])->toBe('active');
    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    // And `active` is the only value prepare-host accepts, however it is
    // spelled.
    preg_match_all('/lifecycle[^\n]*?[!=]=\s*"?([a-z]+)"?/', prepSource(), $matches);

    expect(array_values(array_unique($matches[1])))->toBe(['active']);
});

it('is not installed into the operational bundle or reachable through a deploy sudo wrapper', function () {
    // Preparation is a root/operator operation run from the trusted bootstrap
    // bundle, exactly like bootstrap-host. Handing the restricted deploy user
    // a sudo path to it would turn a deployment credential into a host
    // administration credential.
    expect(File::get(base_path('infrastructure/scripts/install-target-operations')))
        ->not->toContain('prepare-host');

    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-deploy')))
        ->not->toContain('prepare-host');

    foreach (glob(base_path('infrastructure/config/wrappers/*')) ?: [] as $wrapper) {
        expect(File::get($wrapper))->not->toContain('prepare-host');
    }
});
