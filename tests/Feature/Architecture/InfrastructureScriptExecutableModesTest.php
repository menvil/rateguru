<?php

use Illuminate\Support\Facades\File;

/**
 * Guards the regression that made install-target-operations unusable on the
 * real staging VPS: every file under infrastructure/scripts arrived at mode
 * 0640 because a Git blob's own stored index mode was wrong. deploy's own
 * permission normalization (see InfrastructureBaselineTest.php) only ever
 * preserves whatever executable bit the release archive already carries —
 * it cannot invent one that Git never recorded. This test is the thing that
 * must catch a bad mode before it ever reaches an artifact build.
 *
 * Deliberately does *not* infer executability from a shebang line: common
 * has one too (it is a plain bash file, just never invoked directly), so a
 * shebang-based check would incorrectly demand it be executable.
 */
function infrastructureScriptGitModes(): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['git', '-C', base_path(), 'ls-files', '--stage', '--', 'infrastructure/scripts'],
        $descriptors,
        $pipes,
    );

    expect($process)->not->toBeFalse('could not start git ls-files');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, "git ls-files --stage failed: {$stderr}");

    $modes = [];

    foreach (preg_split('/\R/', trim($stdout)) as $line) {
        if ($line === '') {
            continue;
        }

        // "<mode> <blob-sha> <stage>\t<path>"
        expect(preg_match('/^(\d+) [0-9a-f]+ \d+\t(.+)$/', $line, $matches))
            ->toBe(1, "unexpected git ls-files --stage line: {$line}");

        $mode = $matches[1];
        $path = $matches[2];

        // Only flat files directly in infrastructure/scripts/, matching
        // "git ls-files --stage infrastructure/scripts" from the runbook —
        // a subdirectory would need its own classification, not a blanket one.
        if (str_contains(substr($path, strlen('infrastructure/scripts/')), '/')) {
            continue;
        }

        $modes[$path] = $mode;
    }

    return $modes;
}

it('keeps every infrastructure CLI script executable and every sourced library non-executable in the Git index', function () {
    $cliAllowlist = array_map(
        fn (string $name): string => "infrastructure/scripts/{$name}",
        requiredCliManifestNames(),
    );

    // Two sourced libraries since Restore Target Data: common, and the restore-only
    // restore-common the five restore primitives share.
    $sourcedLibraries = array_map(
        fn (string $name): string => "infrastructure/scripts/{$name}",
        sourcedLibraryNames(),
    );

    $modes = infrastructureScriptGitModes();

    foreach ($cliAllowlist as $path) {
        expect(array_key_exists($path, $modes))->toBeTrue("expected CLI is missing from the repository: {$path}");
        expect($modes[$path])->toBe('100755', "{$path} must be Git mode 100755 (executable) — is {$modes[$path]}");
    }

    foreach ($sourcedLibraries as $sourcedLibrary) {
        expect(array_key_exists($sourcedLibrary, $modes))->toBeTrue("sourced library is missing from the repository: {$sourcedLibrary}");
        expect($modes[$sourcedLibrary])
            ->toBe('100644', "{$sourcedLibrary} is a sourced library, never executed directly, and must be Git mode 100644 — is {$modes[$sourcedLibrary]}");
    }

    // No expected CLI is missing from the allowlist, and nothing untracked
    // slipped in unclassified: the flat files directly under
    // infrastructure/scripts/ are exactly the allowlist plus the libraries.
    $expectedPaths = [...$cliAllowlist, ...$sourcedLibraries];
    sort($expectedPaths);
    $actualPaths = array_keys($modes);
    sort($actualPaths);

    expect($actualPaths)->toBe($expectedPaths, 'infrastructure/scripts/ contains a file this test does not know how to classify — add it to infrastructure/config/required-clis.txt or to sourcedLibraryNames() in tests/Pest.php');
});

it('carries every infrastructure script through checkout and deploy normalization with the correct final mode', function () {
    // Ties the Git-index guarantee above to InfrastructureBaselineTest.php's
    // generic proof of deploy's own `-perm /111` normalization, end to end,
    // for the *real* shipped files rather than synthetic fixtures: git
    // checkout is a standard, well-defined operation (mode 100755 checks out
    // executable, 100644 does not, on any Linux runner), and rsync --archive
    // / tar both preserve whatever mode is already on disk — so the working
    // tree's current on-disk permissions are exactly what a fresh checkout,
    // rsync'd and tarred, would also produce.
    $modes = infrastructureScriptGitModes();

    foreach ($modes as $path => $mode) {
        clearstatcache(true, base_path($path));
        $onDisk = is_executable(base_path($path));
        $expectedExecutable = $mode === '100755';

        expect($onDisk)->toBe(
            $expectedExecutable,
            "working tree permissions for {$path} (executable={$onDisk}) do not match its Git index mode {$mode} — checkout, rsync and tar would disagree with what Git actually records",
        );
    }

    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    expect(preg_match(
        '/# --- normalize release permissions \(begin\) ---\n(.*?)\n\s*# --- normalize release permissions \(end\) ---/s',
        $deploy,
        $matches,
    ))->toBe(1, 'could not locate the release permission normalization block in scripts/deploy');

    $block = $matches[1];

    exec('find '.escapeshellarg(sys_get_temp_dir()).' -maxdepth 0 -perm /111 >/dev/null 2>&1', $probe, $probeExit);
    if ($probeExit !== 0) {
        test()->markTestSkipped('host find does not support `-perm /111` (GNU find only)');
    }

    $root = sys_get_temp_dir().'/infra-script-modes-e2e-'.uniqid('', true);

    try {
        mkdir($root.'/infrastructure/scripts', 0o755, true);

        foreach (array_keys($modes) as $path) {
            $dest = $root.'/'.$path;
            copy(base_path($path), $dest);
            chmod($dest, is_executable(base_path($path)) ? 0o755 : 0o644);
        }

        $script = 'set -euo pipefail'."\n".'TEMP_RELEASE_ROOT='.escapeshellarg($root)."\n".$block;

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, "normalization block failed:\n".implode("\n", $output));

        clearstatcache();

        foreach (array_keys($modes) as $path) {
            $finalMode = fileperms($root.'/'.$path) & 0o777;
            $expected = $modes[$path] === '100755' ? 0o750 : 0o640;

            expect($finalMode)->toBe(
                $expected,
                sprintf('%s ended up mode %o after deploy normalization, expected %o', $path, $finalMode, $expected),
            );
        }
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/**
 * Runs the real, shipped infrastructure/scripts/verify-required-clis — the
 * single algorithm shared by deploy itself and both artifact-build
 * workflows, rather than a reimplementation of it.
 *
 * @return array{0: int, 1: string}
 */
function runVerifyRequiredClis(string $releaseRoot): array
{
    $script = base_path('infrastructure/scripts/verify-required-clis');

    $output = [];
    $exit = 0;
    exec('bash '.escapeshellarg($script).' --release-root '.escapeshellarg($releaseRoot).' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

it('verify-required-clis accepts a correctly normalized release and reports it verified', function () {
    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        [$exit, $output] = runVerifyRequiredClis($root);

        expect($exit)->toBe(0, "correctly normalized release was rejected:\n{$output}");
        expect($output)->toContain('verified: every required infrastructure CLI retains its executable mode after release normalization');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('verify-required-clis fails closed when one required CLI is normalized to 0640, matching the real incident', function () {
    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        chmod($root.'/infrastructure/scripts/targets', 0o640);

        [$exit, $output] = runVerifyRequiredClis($root);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('required CLI lost executable mode after extraction: targets');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('verify-required-clis fails closed when a required CLI is missing from the release', function () {
    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        unlink($root.'/infrastructure/scripts/targets');

        [$exit, $output] = runVerifyRequiredClis($root);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('release is missing required CLI: targets');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('verify-required-clis fails closed when a sourced library is wrongly executable', function (string $library) {
    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        chmod($root.'/infrastructure/scripts/'.$library, 0o755);

        [$exit, $output] = runVerifyRequiredClis($root);

        expect($exit)->not->toBe(0);
        expect($output)->toContain("{$library} must remain a non-executable sourced library");
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
})->with(fn (): array => sourcedLibraryNames());

it('verify-required-clis fails closed when a sourced library is missing from the release', function (string $library) {
    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        unlink($root.'/infrastructure/scripts/'.$library);

        [$exit, $output] = runVerifyRequiredClis($root);

        expect($exit)->not->toBe(0);
        expect($output)->toContain("release is missing infrastructure/scripts/{$library}");
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
})->with(fn (): array => sourcedLibraryNames());

it('verify-required-clis fails closed when the required-CLI manifest is missing from the release', function () {
    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        unlink($root.'/infrastructure/config/required-clis.txt');

        [$exit, $output] = runVerifyRequiredClis($root);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('release is missing required CLI manifest');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('verify-required-clis fails closed when the required-CLI manifest is present but empty', function () {
    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        file_put_contents($root.'/infrastructure/config/required-clis.txt', "\n\n");

        [$exit, $output] = runVerifyRequiredClis($root);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('required CLI manifest is empty');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('verify-required-clis requires --release-root and rejects malformed arguments', function () {
    $script = base_path('infrastructure/scripts/verify-required-clis');

    [$exitMissing, $outputMissing] = [0, ''];
    exec('bash '.escapeshellarg($script).' 2>&1', $outputMissing, $exitMissing);
    expect($exitMissing)->not->toBe(0);
    expect(implode("\n", $outputMissing))->toContain('--release-root is required');

    [$exitDuplicate, $outputDuplicate] = [0, ''];
    exec('bash '.escapeshellarg($script).' --release-root /tmp --release-root /tmp 2>&1', $outputDuplicate, $exitDuplicate);
    expect($exitDuplicate)->not->toBe(0);
    expect(implode("\n", $outputDuplicate))->toContain('--release-root given more than once');

    [$exitUnknown, $outputUnknown] = [0, ''];
    exec('bash '.escapeshellarg($script).' --bogus 2>&1', $outputUnknown, $exitUnknown);
    expect($exitUnknown)->not->toBe(0);
    expect(implode("\n", $outputUnknown))->toContain('unknown argument: --bogus');

    [$exitHelp, $outputHelp] = [0, ''];
    exec('bash '.escapeshellarg($script).' --help 2>&1', $outputHelp, $exitHelp);
    expect($exitHelp)->toBe(0);
    expect(implode("\n", $outputHelp))->toContain('Usage: verify-required-clis');
});

it('never chmods any file inside verify-required-clis', function () {
    // This script must fail closed, never paper over a bad mode by fixing it.
    $source = File::get(base_path('infrastructure/scripts/verify-required-clis'));

    foreach (preg_split('/\R/', $source) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        expect($trimmed)->not->toMatch('/(^|[;&|]\s*)chmod\b/');
    }
});

it('deploy delegates its release-side CLI executable-bit guard to the shared verify-required-clis, never reimplementing it', function () {
    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    expect(preg_match(
        '/# --- verify infrastructure CLI executable bits \(begin\) ---\n(.*?)\n\s*# --- verify infrastructure CLI executable bits \(end\) ---/s',
        $deploy,
        $matches,
    ))->toBe(1, 'could not locate the CLI executable-bit verification block in scripts/deploy');

    // the target-aware migration: the hardcoded absolute path became the gated
    // VERIFY_REQUIRED_CLIS_BIN constant (defaulting to the exact same path),
    // so deploys can be pointed at a stub in tests via
    // RATEGURU_VERIFY_REQUIRED_CLIS_BIN — see DeployTest.php.
    expect(trim($matches[1]))->toBe(
        '"${VERIFY_REQUIRED_CLIS_BIN}" --release-root "${TEMP_RELEASE_ROOT}"',
    );
    expect($deploy)
        ->toContain('VERIFY_REQUIRED_CLIS_BIN_DEFAULT="/home/www/rateguru/bin/verify-required-clis"');

    // Positioned after permission normalization ends and before the release
    // is moved into its final, immutable path.
    expect(mb_strpos($deploy, '# --- normalize release permissions (end) ---'))
        ->toBeLessThan(mb_strpos($deploy, '# --- verify infrastructure CLI executable bits (begin) ---'))
        ->and(mb_strpos($deploy, '# --- verify infrastructure CLI executable bits (end) ---'))
        ->toBeLessThan(mb_strpos($deploy, '    "${RELEASE_ROOT}"'));
});
