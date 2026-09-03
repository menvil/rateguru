<?php

use Illuminate\Support\Facades\File;

/**
 * : infrastructure/scripts/bootstrap-host — the one
 * authoritative host-bootstrap entry point that composes the existing
 * slices (5.2 install-bootstrap-runtime, 5.3 install-bootstrap-host-layout,
 * 5.4 install-bootstrap-services) plus the final bootstrap-host-preflight.
 *
 * Every test executes the real, shipped script as a subprocess — never a
 * reimplementation — against fixture/shim child scripts: one stub per child
 * slice that logs every invocation, answers its read-only --check/--verify
 * from a per-child "compliant" toggle, converges that toggle on --apply
 * (unless a failure toggle simulates a missing external prerequisite or a
 * post-apply verify failure), and records every mutating invocation in a
 * dedicated mutation log. All injected through RATEGURU_BOOTSTRAPHOST_*
 * overrides the script honors only alongside
 * RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here touches the CI host.
 *
 * What matters architecturally: the exact 5.2 -> 5.3 -> 5.4 -> preflight
 * order, dependency-aware BLOCKED reporting on --check, verify-skip-apply
 * convergence with fail-fast and no destructive rollback (resume semantics),
 * strict read-only --check/--verify (no path ever reaches a child --apply),
 * child/signal exit-status preservation, and the absence of any --force/
 * --skip escape hatch, deploy invocation, secret generation or
 * planned-target provisioning.
 */

// =============================================================================
// Harness
// =============================================================================

function bhostScript(): string
{
    return base_path('infrastructure/scripts/bootstrap-host');
}

function bhostSource(): string
{
    return File::get(bhostScript());
}

function bhostScratchDir(): string
{
    $dir = sys_get_temp_dir().'/bootstrap-host-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/log', '/toggles'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function bhostCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function bhostRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', bhostScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start bootstrap-host subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

function bhostWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * Every child invocation, in order (empty when no child was ever reached).
 *
 * @return list<string>
 */
function bhostChildrenLog(string $scratch): array
{
    $path = $scratch.'/log/children.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

/**
 * Every mutating (--apply) child invocation.
 *
 * @return list<string>
 */
function bhostMutationsLog(string $scratch): array
{
    $path = $scratch.'/log/mutations.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

/**
 * One stub per child slice installer. Read-only modes (--check/--verify)
 * answer from the "<name>-compliant" toggle and print a child-shaped
 * SUMMARY the orchestrator excerpts; --apply logs a mutation and converges
 * the toggle, unless "<name>-apply-fail" (simulating e.g. a missing
 * external prerequisite the child fails closed on) or
 * "<name>-apply-no-converge" (apply reports success but the authoritative
 * verify still fails) is present. "<name>-readonly-exit-130" simulates an
 * interrupted read-only child (SIGINT-derived exit status).
 */
function bhostWriteChildStubs(string $scratch): void
{
    foreach (['runtime-installer', 'hostlayout-installer', 'services-installer'] as $child) {
        bhostWriteStub($scratch.'/bin/'.$child, <<<'STUB'
            #!/bin/bash
            me="$(basename "$0")"
            printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/children.log"
            case "$*" in
                *--apply*)
                    printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/mutations.log"
                    if [[ -e "${STUB_TOGGLES}/${me}-apply-fail" ]]; then
                        echo "ERROR: ${me} simulated apply failure (EXTERNAL PREREQUISITE MISSING: fixture-category (/fixture/path))"
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
                        echo "${me} report line"
                        echo "SUMMARY"
                        echo "PASS: 10"
                        echo "MISSING: 0"
                        echo "${me} CONTRACT: SATISFIED"
                        exit 0
                    fi
                    echo "${me} report line"
                    echo "SUMMARY"
                    echo "PASS: 3"
                    echo "MISSING: 7"
                    echo "${me} CONTRACT: NOT SATISFIED"
                    exit 1
                    ;;
            esac
            STUB);
    }

    // The final preflight: read-only --check only, answered from the
    // preflight-fail toggle (a correctly bootstrapped PRE_DEPLOY host
    // passes, so the default is success).
    bhostWriteStub($scratch.'/bin/preflight', <<<'STUB'
        #!/bin/bash
        printf 'preflight %s\n' "$*" >> "${STUB_LOG}/children.log"
        if [[ -e "${STUB_TOGGLES}/preflight-fail" ]]; then
            echo "SUMMARY"
            echo "MISSING: 2"
            echo "HOST BOOTSTRAP READY: NO"
            exit 1
        fi
        echo "SUMMARY"
        echo "MISSING: 0"
        echo "DEFERRED: 4"
        echo "HOST BOOTSTRAP READY: YES"
        echo "APPLICATION READY: DEFERRED — no release has been deployed (PRE_DEPLOY)"
        exit 0
        STUB);
}

/**
 * Build the fixture and return the environment to run the script against
 * it.
 *
 * Options:
 *   compliant: list<string> of '5.2'|'5.3'|'5.4' whose child verify/check
 *              already passes (default: none — a clean host)
 *   euid:      string (default '0')
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function bhostFixture(string $scratch, array $options = []): array
{
    bhostWriteChildStubs($scratch);

    $childBySlice = [
        '5.2' => 'runtime-installer',
        '5.3' => 'hostlayout-installer',
        '5.4' => 'services-installer',
    ];

    foreach ($options['compliant'] ?? [] as $slice) {
        touch($scratch.'/toggles/'.$childBySlice[$slice].'-compliant');
    }

    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_BOOTSTRAPHOST_EUID' => $options['euid'] ?? '0',
        'RATEGURU_BOOTSTRAPHOST_RUNTIME_INSTALLER_BIN' => $scratch.'/bin/runtime-installer',
        'RATEGURU_BOOTSTRAPHOST_HOSTLAYOUT_INSTALLER_BIN' => $scratch.'/bin/hostlayout-installer',
        'RATEGURU_BOOTSTRAPHOST_SERVICES_INSTALLER_BIN' => $scratch.'/bin/services-installer',
        'RATEGURU_BOOTSTRAPHOST_PREFLIGHT_BIN' => $scratch.'/bin/preflight',
        'STUB_LOG' => $scratch.'/log',
        'STUB_TOGGLES' => $scratch.'/toggles',
    ];
}

// =============================================================================
// Shipping and CLI contract
// =============================================================================

it('ships the orchestrator executable, syntax-clean and listed in the required-CLI manifest', function () {
    expect(is_file(bhostScript()))->toBeTrue();
    expect(is_executable(bhostScript()))->toBeTrue();

    exec('bash -n '.escapeshellarg(bhostScript()).' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));

    expect(requiredCliManifestNames())->toContain('bootstrap-host');
});

it('prints usage on --help and rejects unknown, missing or duplicated modes', function () {
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];

    [$exit, $output] = bhostRun(['--help'], $env);
    expect($exit)->toBe(0);
    expect($output)->toContain('--check')->toContain('--apply')->toContain('--verify')->toContain('root');

    [$exit, $output] = bhostRun([], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('one of --check, --apply or --verify is required');

    [$exit, $output] = bhostRun(['--check', '--verify'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('mode given more than once');

    [$exit, $output] = bhostRun(['--frobnicate'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('unknown argument: --frobnicate');
});

it('offers no force/skip/continue escape hatch — every such flag is an unknown argument', function () {
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];

    foreach ([
        '--force', '--skip', '--ignore-errors', '--continue-on-error',
        '--skip-services', '--skip-preflight',
    ] as $flag) {
        [$exit, $output] = bhostRun([$flag], $env);

        expect($exit)->toBe(1, "{$flag} must be rejected");
        expect($output)->toContain("unknown argument: {$flag}");
    }
});

it('requires root for every mode and reaches no child without it', function () {
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['euid' => '1000']);

        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = bhostRun([$mode], $env);

            expect($exit)->toBe(1);
            expect($output)->toContain(substr($mode, 2).' must run as root');
        }

        expect(bhostChildrenLog($scratch))->toBe([], 'a non-root invocation must never reach a child');
        expect(bhostMutationsLog($scratch))->toBe([]);
    } finally {
        bhostCleanup($scratch);
    }
});

// =============================================================================
// --check: dependency-aware, strictly read-only
// =============================================================================

it('--check on a clean host reports 5.2 NEEDS_APPLY with 5.3/5.4/preflight BLOCKED, judging no downstream slice', function () {
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch);
        [$exit, $output] = bhostRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('PHASE 5.2 runtime/packages');
        expect($output)->toContain('NEEDS_APPLY');
        expect($output)->toContain('PHASE 5.3 users/groups/filesystem');
        expect($output)->toContain('BLOCKED until the runtime bootstrap is satisfied');
        expect($output)->toContain('PHASE 5.4 services/configuration');
        expect($output)->toContain('BLOCKED until the host-layout bootstrap is satisfied');
        expect($output)->toContain("FINAL PREFLIGHT\n  BLOCKED until the service bootstrap is satisfied");
        expect($output)->toContain("5.2 NEEDS_APPLY\n5.3 BLOCKED\n5.4 BLOCKED");
        expect($output)->toContain('BOOTSTRAP READY: NO');

        // The failing slice's own concise summary is shown, not hidden.
        expect($output)->toContain('runtime-installer CONTRACT: NOT SATISFIED');

        // Only 5.2's read-only check ever ran: a blocked slice is not
        // probed on a host its prerequisite has not prepared.
        expect(bhostChildrenLog($scratch))->toBe(['runtime-installer --check']);
        expect(bhostMutationsLog($scratch))->toBe([]);
    } finally {
        bhostCleanup($scratch);
    }
});

it('--check walks forward exactly as far as slices are satisfied', function () {
    // 5.2 satisfied, 5.3 not: 5.3 is the NEEDS_APPLY slice and 5.4 is
    // BLOCKED on it.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2']]);
        [$exit, $output] = bhostRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain("5.2 PASS\n5.3 NEEDS_APPLY\n5.4 BLOCKED");
        expect(bhostChildrenLog($scratch))->toBe([
            'runtime-installer --check',
            'hostlayout-installer --check',
        ]);
    } finally {
        bhostCleanup($scratch);
    }

    // All three satisfied and the preflight passing: BOOTSTRAP READY: YES,
    // exit 0.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3', '5.4']]);
        [$exit, $output] = bhostRun(['--check'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain("5.2 PASS\n5.3 PASS\n5.4 PASS\nFINAL PREFLIGHT PASS");
        expect($output)->toContain('BOOTSTRAP READY: YES');
        expect(bhostChildrenLog($scratch))->toBe([
            'runtime-installer --check',
            'hostlayout-installer --check',
            'services-installer --check',
            'preflight --check',
        ]);
    } finally {
        bhostCleanup($scratch);
    }
});

it('inherits a real read-only guarantee: the 5.4 child it delegates to never runs the mutating mail verifier', function () {
    // bootstrap-host's own read-only guarantee is only as real as the chain
    // beneath it. It passes --check/--verify to install-bootstrap-services
    // (asserted here), which in turn only ever invokes
    // `verify-mail-capture --read-only` (asserted in
    // InstallBootstrapServicesTest), which performs zero mutation (asserted in
    // MailCaptureInfrastructureTest, by running the real script). A stub alone
    // could never establish this, so each link is pinned where it lives.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3', '5.4']]);

        bhostRun(['--check'], $env);
        bhostRun(['--verify'], $env);

        $invocations = bhostChildrenLog($scratch);
        expect($invocations)->not->toBe([]);

        foreach ($invocations as $invocation) {
            expect($invocation)->toMatch(
                '/ --(check|verify)$/',
                "a read-only orchestration mode passed something other than --check/--verify: {$invocation}",
            );
        }

        // The real services installer is the one that must carry --read-only
        // down to the mail verifier; prove the source actually does so.
        $services = File::get(base_path('infrastructure/scripts/install-bootstrap-services'));
        preg_match_all('/\$\{VERIFY_MAIL_CAPTURE_BIN\}"(.*)$/m', $services, $matches);

        expect($matches[1])->not->toBe([], 'no verify-mail-capture call sites found — assertion would be vacuous');

        foreach ($matches[1] as $callSite) {
            // toContain() is variadic in Pest — a second argument would be
            // another needle, not a failure message.
            expect(str_contains($callSite, '--read-only'))
                ->toBeTrue("mail verifier invoked without --read-only: {$callSite}");
        }
    } finally {
        bhostCleanup($scratch);
    }
});

it('--check and --verify contain no path that executes a child --apply', function () {
    foreach ([[], ['5.2'], ['5.2', '5.3'], ['5.2', '5.3', '5.4']] as $compliant) {
        $scratch = bhostScratchDir();

        try {
            $env = bhostFixture($scratch, ['compliant' => $compliant]);

            bhostRun(['--check'], $env);
            bhostRun(['--verify'], $env);

            expect(bhostMutationsLog($scratch))->toBe([], 'read-only modes must never mutate');

            foreach (bhostChildrenLog($scratch) as $invocation) {
                expect($invocation)->not->toContain('--apply');
            }
        } finally {
            bhostCleanup($scratch);
        }
    }
});

// =============================================================================
// --apply: convergent, fail-fast, resumable
// =============================================================================

it('converges a clean host in the exact 5.2 -> 5.3 -> 5.4 -> preflight order, verifying each slice after its apply', function () {
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch);
        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('HOST BOOTSTRAP READY: YES');
        expect($output)->toContain('5.2 PASS (applied and verified)');
        expect($output)->toContain('5.3 PASS (applied and verified)');
        expect($output)->toContain('5.4 PASS (applied and verified)');
        expect($output)->toContain('PASS bootstrap host ready');

        // The exact child invocation order: per slice verify -> apply ->
        // verify, strictly in dependency order, ending with the preflight.
        expect(bhostChildrenLog($scratch))->toBe([
            'runtime-installer --verify',
            'runtime-installer --apply',
            'runtime-installer --verify',
            'hostlayout-installer --verify',
            'hostlayout-installer --apply',
            'hostlayout-installer --verify',
            'services-installer --verify',
            'services-installer --apply',
            'services-installer --verify',
            'preflight --check',
        ]);
    } finally {
        bhostCleanup($scratch);
    }
});

it('skips already-satisfied slices and applies only the unsatisfied ones', function () {
    // 5.2 already satisfied: only 5.3 and 5.4 are applied.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2']]);
        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('5.2 PASS (already satisfied — skipped)');
        expect(bhostChildrenLog($scratch))->toBe([
            'runtime-installer --verify',
            'hostlayout-installer --verify',
            'hostlayout-installer --apply',
            'hostlayout-installer --verify',
            'services-installer --verify',
            'services-installer --apply',
            'services-installer --verify',
            'preflight --check',
        ]);
        expect(bhostMutationsLog($scratch))->toBe([
            'hostlayout-installer --apply',
            'services-installer --apply',
        ]);
    } finally {
        bhostCleanup($scratch);
    }

    // 5.2 and 5.3 satisfied, 5.4 drifted: only 5.4 is applied.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3']]);
        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect(bhostMutationsLog($scratch))->toBe(['services-installer --apply']);
    } finally {
        bhostCleanup($scratch);
    }
});

it('fails fast at install-bootstrap-services, keeps 5.2/5.3 converged without rollback, and resumes at 5.4 on the next apply', function () {
    $scratch = bhostScratchDir();

    try {
        // A clean host whose 5.4 apply fails (the child fails closed on a
        // missing external prerequisite).
        $env = bhostFixture($scratch);
        touch($scratch.'/toggles/services-installer-apply-fail');

        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('EXTERNAL PREREQUISITE MISSING');
        expect($output)->toContain('install-bootstrap-services apply failed');
        expect($output)->toContain('re-run bootstrap-host --apply to resume at install-bootstrap-services');

        // 5.2/5.3 converged and stay converged: no destructive rollback.
        expect(file_exists($scratch.'/toggles/runtime-installer-compliant'))->toBeTrue();
        expect(file_exists($scratch.'/toggles/hostlayout-installer-compliant'))->toBeTrue();

        // The preflight was never reached.
        expect(implode("\n", bhostChildrenLog($scratch)))->not->toContain('preflight');

        // The operator supplies the missing prerequisite, then re-runs:
        // 5.2/5.3 are skipped and 5.4 completes.
        unlink($scratch.'/toggles/services-installer-apply-fail');
        file_put_contents($scratch.'/log/children.log', '');
        file_put_contents($scratch.'/log/mutations.log', '');

        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect(bhostChildrenLog($scratch))->toBe([
            'runtime-installer --verify',
            'hostlayout-installer --verify',
            'services-installer --verify',
            'services-installer --apply',
            'services-installer --verify',
            'preflight --check',
        ]);
        expect(bhostMutationsLog($scratch))->toBe(['services-installer --apply']);
    } finally {
        bhostCleanup($scratch);
    }
});

it('stops at the first child failure: later slices and the preflight are never invoked', function () {
    // 5.2 apply failure: 5.3/5.4/preflight never called.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch);
        touch($scratch.'/toggles/runtime-installer-apply-fail');

        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('install-bootstrap-runtime apply failed');

        $children = implode("\n", bhostChildrenLog($scratch));
        expect($children)->not->toContain('hostlayout-installer');
        expect($children)->not->toContain('services-installer');
        expect($children)->not->toContain('preflight');
    } finally {
        bhostCleanup($scratch);
    }

    // 5.2 post-apply verify failure: stop before 5.3.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch);
        touch($scratch.'/toggles/runtime-installer-apply-no-converge');

        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('install-bootstrap-runtime post-apply verification failed');
        expect(implode("\n", bhostChildrenLog($scratch)))->not->toContain('hostlayout-installer');
    } finally {
        bhostCleanup($scratch);
    }

    // 5.3 apply failure: 5.4/preflight never called.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch);
        touch($scratch.'/toggles/hostlayout-installer-apply-fail');

        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('install-bootstrap-host-layout apply failed');

        $children = implode("\n", bhostChildrenLog($scratch));
        expect($children)->not->toContain('services-installer');
        expect($children)->not->toContain('preflight');
    } finally {
        bhostCleanup($scratch);
    }

    // Final preflight failure: bootstrap-host returns non-zero.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3', '5.4']]);
        touch($scratch.'/toggles/preflight-fail');

        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('final bootstrap preflight failed');
    } finally {
        bhostCleanup($scratch);
    }
});

it('is idempotent on a fully compliant host: zero child applies on the first run and on the second', function () {
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3', '5.4']]);

        [$exit1, $out1] = bhostRun(['--apply'], $env);
        expect($exit1)->toBe(0, $out1);
        expect($out1)->toContain('5.2 PASS (already satisfied — skipped)');
        expect($out1)->toContain('5.3 PASS (already satisfied — skipped)');
        expect($out1)->toContain('5.4 PASS (already satisfied — skipped)');
        expect($out1)->toContain('HOST BOOTSTRAP READY: YES');
        expect(bhostMutationsLog($scratch))->toBe([], 'a compliant host must see zero child applies');

        [$exit2, $out2] = bhostRun(['--apply'], $env);
        expect($exit2)->toBe(0, $out2);
        expect($out2)->toBe($out1, 'a second apply must be identical');
        expect(bhostMutationsLog($scratch))->toBe([]);
    } finally {
        bhostCleanup($scratch);
    }
});

// =============================================================================
// --verify
// =============================================================================

it('--verify requires every slice verify plus the final preflight, blocking downstream slices on the first failure', function () {
    // All satisfied: HOST BOOTSTRAP READY: YES, exit 0, verify-only calls.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3', '5.4']]);
        [$exit, $output] = bhostRun(['--verify'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('HOST BOOTSTRAP READY: YES');
        expect(bhostChildrenLog($scratch))->toBe([
            'runtime-installer --verify',
            'hostlayout-installer --verify',
            'services-installer --verify',
            'preflight --check',
        ]);
    } finally {
        bhostCleanup($scratch);
    }

    // 5.3 failing: FAIL there, 5.4 and the preflight BLOCKED, non-zero.
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2']]);
        [$exit, $output] = bhostRun(['--verify'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain("5.2 PASS\n5.3 FAIL\n5.4 BLOCKED");
        expect($output)->toContain('HOST BOOTSTRAP READY: NO');
        expect(bhostChildrenLog($scratch))->toBe([
            'runtime-installer --verify',
            'hostlayout-installer --verify',
        ]);
    } finally {
        bhostCleanup($scratch);
    }
});

// =============================================================================
// Signal/exit-status semantics
// =============================================================================

it('propagates a child signal-derived exit status instead of reinterpreting it as a contract failure', function () {
    $scratch = bhostScratchDir();

    try {
        // An interrupted 5.4 verify (exit 130, the SIGINT convention): the
        // orchestrator terminates with that same status and never continues
        // to the preflight.
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3']]);
        touch($scratch.'/toggles/services-installer-readonly-exit-130');

        [$exit, $output] = bhostRun(['--apply'], $env);

        expect($exit)->toBe(130, $output);
        expect(implode("\n", bhostChildrenLog($scratch)))->not->toContain('preflight');
        expect(bhostMutationsLog($scratch))->toBe([]);
    } finally {
        bhostCleanup($scratch);
    }
});

// =============================================================================
// Long-child visibility
// =============================================================================

it('announces potentially long child verifications before invoking them', function () {
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3', '5.4']]);
        [, $output] = bhostRun(['--verify'], $env);

        expect($output)->toContain('VERIFY 5.4 services/configuration');
        expect($output)->toContain('runs several child contract verifies — this may take a while');

        // The old wording claimed normal 5.4 verification performs
        // end-to-end mail checks. It no longer does — it uses
        // verify-mail-capture --read-only — so that claim must not reappear.
        expect($output)->not->toContain('end-to-end');
        expect($output)->toContain('VERIFY final bootstrap readiness');
    } finally {
        bhostCleanup($scratch);
    }
});

// =============================================================================
// Override gate
// =============================================================================

it('ignores every RATEGURU_BOOTSTRAPHOST_* override unless test overrides are explicitly allowed', function () {
    $scratch = bhostScratchDir();

    try {
        $env = bhostFixture($scratch, ['compliant' => ['5.2', '5.3', '5.4']]);
        unset($env['RATEGURU_ALLOW_TEST_OVERRIDES']);

        // Without the flag the EUID override is ignored, so the real
        // (non-root) test process fails the root gate — and no fixture
        // child is ever reached, proving the child-bin overrides were
        // ignored too.
        if (getmyuid() === 0) {
            test()->markTestSkipped('this test process is running as root — the require-root gate cannot prove the denied override');
        }

        [$exit, $output] = bhostRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('must run as root');
        expect(bhostChildrenLog($scratch))->toBe([]);
    } finally {
        bhostCleanup($scratch);
    }
});

// =============================================================================
// Architecture regression protection
// =============================================================================

it('resolves children as canonical repository siblings, in the exact 5.2 -> 5.3 -> 5.4 order, with no absorbed child logic', function () {
    $source = bhostSource();

    // Canonical children, resolved beside the script — never through
    // /home/www/rateguru/current or /home/www/rateguru/bin.
    expect($source)->toContain('${SCRIPT_DIR}/install-bootstrap-runtime');
    expect($source)->toContain('${SCRIPT_DIR}/install-bootstrap-host-layout');
    expect($source)->toContain('${SCRIPT_DIR}/install-bootstrap-services');
    expect($source)->toContain('${SCRIPT_DIR}/bootstrap-host-preflight');

    // The runtime tree is only ever mentioned in the header comment that
    // documents why it is NOT used — no executed line references it.
    foreach (explode("\n", $source) as $line) {
        if (str_contains($line, '/home/www/rateguru')) {
            expect(ltrim($line))->toStartWith('#', "non-comment line references the runtime tree: {$line}");
        }
    }

    // Dependency order is structural: the slice table maps 5.2/5.3/5.4 to
    // the three children in ascending source positions.
    $runtime = strpos($source, '5.2) printf');
    $layout = strpos($source, '5.3) printf');
    $services = strpos($source, '5.4) printf');
    expect($runtime)->not->toBeFalse();
    expect($runtime)->toBeLessThan($layout);
    expect($layout)->toBeLessThan($services);

    // Orchestration only: none of the children's own mutating vocabulary
    // exists here — no package, identity, filesystem, service or secret
    // command of any kind.
    foreach ([
        'apt-get', 'useradd', 'groupadd', 'usermod', 'chown', 'chmod',
        'systemctl', 'supervisorctl', 'nginx', 'install -',
        'openssl', 'certbot', 'ssh-keygen', 'htpasswd -',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // No application deploy and no planned-target provisioning: the
    // orchestrator never references the deploy pipeline, artifacts,
    // releases or any concrete target.
    foreach ([
        'scripts/deploy', '--artifact', '--release', '--migrate',
        'tits-guru', 'staging-main',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

// =============================================================================
// Bootstrap source safety: the script FILE is canonicalized, not just its
// directory, so a symlinked invocation cannot redirect sibling resolution.
// =============================================================================

it('never executes attacker-controlled siblings when invoked through a symlinked script file', function () {
    // Resolving only the directory (cd "$(dirname "$0")" && pwd -P) physically
    // resolves the path components but NOT a symlink used as the bootstrap-host
    // file itself — so an attacker-writable
    // /tmp/evil/infrastructure/scripts/bootstrap-host symlinked at the real
    // script would keep SCRIPT_DIR inside /tmp/evil, pass the layout check, and
    // make this root command run the attacker's install-bootstrap-* siblings.
    $scratch = bhostScratchDir();

    try {
        $evil = $scratch.'/evil/infrastructure/scripts';
        expect(@mkdir($evil, 0o755, true))->toBeTrue("could not create fake layout: {$evil}");

        // The real script, reachable under the attacker-controlled directory.
        expect(symlink(bhostScript(), $evil.'/bootstrap-host'))->toBeTrue();

        // Malicious siblings that record the fact they ran.
        $siblings = [
            'install-bootstrap-runtime',
            'install-bootstrap-host-layout',
            'install-bootstrap-services',
            'bootstrap-host-preflight',
        ];

        foreach ($siblings as $sibling) {
            bhostWriteStub($evil.'/'.$sibling, "#!/bin/bash\ntouch ".escapeshellarg($scratch.'/PWNED-'.$sibling)."\nexit 0\n");
        }

        // Invoked through the symlink, with NO test overrides in play beyond
        // the root gate — production child resolution must apply.
        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(
            ['bash', $evil.'/bootstrap-host', '--check'],
            $descriptors,
            $pipes,
            null,
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'HOME' => getenv('HOME') ?: '/tmp',
                'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
                'RATEGURU_BOOTSTRAPHOST_EUID' => '0',
            ],
        );
        expect($process)->not->toBeFalse();
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        // The attacker's siblings must never have run — whether because the
        // canonical real siblings were used instead, or because the invocation
        // failed closed. Both outcomes are acceptable; execution is not.
        foreach ($siblings as $sibling) {
            expect(file_exists($scratch.'/PWNED-'.$sibling))
                ->toBeFalse("attacker-controlled sibling was executed: {$sibling}\n{$output}");
        }

        // Positive confirmation that resolution landed on the real checkout.
        expect($output)->toContain(dirname(bhostScript()).'/install-bootstrap-runtime');
        expect($output)->not->toContain($evil.'/install-bootstrap-runtime');
    } finally {
        bhostCleanup($scratch);
    }
});

it('canonicalizes the script file before its directory, and fails closed if that cannot be resolved', function () {
    $source = bhostSource();

    // The file is canonicalized first; the directory is derived from the
    // canonical path, never from the invocation path.
    expect($source)->toContain('readlink -f -- "${BASH_SOURCE[0]}"');
    expect($source)->toContain('dirname -- "${SCRIPT_PATH}"');

    // The pre-fix form — deriving the directory straight from BASH_SOURCE —
    // must be gone.
    expect($source)->not->toContain('dirname -- "${BASH_SOURCE[0]}"');

    // Resolution failure is fatal, never a silent fallback.
    expect($source)->toContain('cannot canonicalize the bootstrap-host script path');
});

it('is never part of the installed operational bundle and grants the deploy user no path to it', function () {
    // bootstrap-host is a source/bootstrap command run by root from a
    // repository checkout — never installed under /home/www/rateguru/bin by
    // install-target-operations, never named in the target perimeter's
    // sudoers/wrappers.
    expect(File::get(base_path('infrastructure/scripts/install-target-operations')))
        ->not->toMatch('/bootstrap-host(?!-preflight)/');

    expect(File::get(base_path('infrastructure/scripts/install-target-perimeter')))
        ->not->toContain('bootstrap-host');

    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-deploy')))
        ->not->toContain('bootstrap-host');
});

// =============================================================================
// Documentation and roadmap
// =============================================================================

it('documents the canonical operator flow in the runbook and README', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/bootstrap-host.md'));

    expect($runbook)
        ->toContain('bootstrap-host --check')
        ->toContain('bootstrap-host --apply')
        ->toContain('bootstrap-host --verify')
        ->toContain('BLOCKED')
        ->toContain('PRE_DEPLOY')
        ->toContain('never rolled back')
        ->toContain('never generated');

    // deploy performs the PRE_DEPLOY -> DEPLOYED transition and activates
    // the deferred worker.
    expect($runbook)->toContain('PRE_DEPLOY');
    expect($runbook)->toContain('DEPLOYED');
    expect($runbook)->toContain('Supervisor');

    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('infrastructure/scripts/bootstrap-host');
});

it('records every bootstrap slice completed after its own real acceptance', function () {
    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)->toContain('5.4 Services and configuration — completed');
    expect($roadmap)->toContain('5.5 Bootstrap orchestrator — completed');
    expect($roadmap)->toContain('5.6 Clean-VPS acceptance — completed');

    // A mutating host slice is only ever marked completed after acceptance on
    // a real host, so each of the two must carry its own evidence.
    expect($roadmap)
        ->toContain('**Accepted on the real staging VPS:** the host was already largely')
        ->toContain('**Accepted on a real clean VPS.**');

    // No stale "awaiting acceptance" wording survives anywhere.
    expect($roadmap)->not->toContain('5.5 Bootstrap orchestrator — implemented');

    // the clean-host bootstrap closed and handed over; there is still exactly one current phase.
    expect(substr_count($roadmap, '🚧 current'))->toBe(1);
    expect($roadmap)
        ->toMatch('/^\|\s*5\s*\|\s*Infrastructure installer and clean-VPS bootstrap\s*\|\s*✅ completed\s*\|$/m')
        ->toContain('## 5. Infrastructure installer and clean-VPS bootstrap — completed');
});

it('keeps the clean-host bootstrap and the disaster-recovery work rehearsal gates distinct', function () {
    // The offsite restore-test that passed in 5.6 proves a backup is
    // restorable. It does not prove the application can be reconstructed
    // after server/data loss, and must never be read as closing the disaster-recovery work.
    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)
        ->toContain('Three distinct rehearsal gates')
        ->toContain('**Still outstanding.**')
        ->toMatch('/^\|\s*7\s*\|[^|]+\|\s*⏳ planned\s*\|$/m');

    // The two defects clean-host bootstrap found, and the MRs that fixed them.
    expect($roadmap)
        ->toContain('PR #1124')
        ->toContain('PR #1125');
});
