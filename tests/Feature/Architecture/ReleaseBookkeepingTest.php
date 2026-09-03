<?php

use Illuminate\Support\Facades\File;

/**
 * Release bookkeeping does not belong in code.
 *
 * A phase number, a slice number or a PR reference describes the schedule that
 * produced a file. It says nothing about what the file does, it is opaque to
 * anyone reading it later, and it goes stale the moment the plan changes. This
 * repository had 672 such mentions across 111 files, plus four test files and
 * thirty-nine helpers named after the release step that introduced them, before
 * they were removed.
 *
 * This guard exists so they do not come back — the convention in CLAUDE.md is
 * checked here rather than only written down.
 *
 * Scope is the OPERATIONAL surface plus the architecture tests that guard it.
 * Two things are deliberately outside it, because in both the plan itself is
 * the subject:
 *
 *   infrastructure/ROADMAP.md   a roadmap of phases
 *   infrastructure/runbooks/    operator prose written ABOUT the numbered
 *                               bootstrap steps, structured by them
 *   docs/ + tests/Feature/Docs  the product's own numbered review checklists,
 *                               a separate numbering from infrastructure work
 *   scripts/bootstrap-host      identifies its children by slice number in
 *                               control flow and operator output
 */

/**
 * The operational surface: everything that runs on a host or in CI, plus the
 * runbooks an operator reads.
 *
 * @return list<string>
 */
function releaseBookkeepingOperationalFiles(): array
{
    $configFiles = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('infrastructure/config'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($tree as $entry) {
        if ($entry->isFile()) {
            $configFiles[] = $entry->getPathname();
        }
    }

    return array_values(array_filter(array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
        array_values(array_filter(
            glob(base_path('infrastructure/scripts/*')) ?: [],
            // bootstrap-host identifies its children BY slice number —
            // SLICE_IDS, the case arms that dispatch on them, the state it
            // writes and the progress an operator reads. Renaming those is a
            // behaviour change with its own test surface, not a comment
            // cleanup, so it is deliberately left for a separate change
            // rather than half-done here.
            static fn (string $path): bool => basename($path) !== 'bootstrap-host',
        )),
        $configFiles,
    ), 'is_file'));
}

/**
 * The architecture guards themselves, which must not carry the vocabulary they
 * exist to keep out of everything else.
 *
 * @return list<string>
 */
function releaseBookkeepingScannedFiles(): array
{
    return array_values(array_filter(array_merge(
        releaseBookkeepingOperationalFiles(),
        glob(base_path('tests/Feature/Architecture/*.php')) ?: [],
        [base_path('tests/Pest.php')],
    ), 'is_file'));
}

it('encodes no phase or slice number anywhere in the operational surface', function () {
    $offenders = [];

    foreach (releaseBookkeepingScannedFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (preg_split('/\R/', File::get($path)) as $number => $line) {
            // The one legitimate reason a test mentions a phase number: it is
            // asserting on ROADMAP.md, whose subject is the plan.
            if (str_contains($line, 'roadmap') || str_contains($line, 'ROADMAP')) {
                continue;
            }

            if (preg_match('/\bPhase \d+(\.\d+)?[A-C]?\b|\bslice \d+\.\d+\b/', $line)) {
                $offenders[] = $relative.':'.($number + 1).' — '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "release bookkeeping in code:\n".implode("\n", $offenders));
});

it('references no pull request from the operational surface', function () {
    // Deliberately NOT a commit-SHA rule. A 40-hex string in these files is
    // almost always a supply-chain control — a pinned `uses: actions/foo@<sha>`,
    // an apt or rclone signing-key fingerprint — and forbidding those would
    // trade a real defence for a naming preference. A PR number buys nothing
    // and goes stale; that is the difference.
    //
    // Tests are outside this one: a test may legitimately quote ROADMAP.md,
    // where the PR history is the subject.
    $offenders = [];

    foreach (releaseBookkeepingOperationalFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (preg_split('/\R/', File::get($path)) as $number => $line) {
            if (preg_match('/\bPR #\d+|\bpull request #\d+/i', $line)) {
                $offenders[] = $relative.':'.($number + 1).' — '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "pull request references in the operational surface:\n".implode("\n", $offenders));
});

it('names no test file or helper after the release step that produced it', function () {
    $files = collect(glob(base_path('tests/Feature/Architecture/*.php')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->all();

    foreach ($files as $file) {
        expect($file)->not->toMatch('/^Phase\d/', "test file named after a release step: {$file}");
    }

    // The helper prefix that used to key fixtures to the phase that added them.
    // They are shared across phases in practice, which is exactly why the
    // prefix was a lie.
    $helpers = [];

    foreach (array_merge(glob(base_path('tests/Feature/Architecture/*.php')) ?: [], [base_path('tests/Pest.php')]) as $path) {
        if (preg_match_all('/\bfunction (p\d{2}[A-Z][A-Za-z]*)/', File::get($path), $matches)) {
            foreach ($matches[1] as $name) {
                $helpers[] = str_replace(base_path().'/', '', $path).': '.$name;
            }
        }
    }

    expect($helpers)->toBe([], "helpers named after a release step:\n".implode("\n", $helpers));
});

it('states the convention in CLAUDE.md so the rule is discoverable, not just enforced', function () {
    expect(File::exists(base_path('CLAUDE.md')))->toBeTrue();

    expect(File::get(base_path('CLAUDE.md')))
        ->toContain('Name things by what they do')
        ->toContain('ROADMAP.md')
        ->toContain('tests/Pest.php');
});
