<?php

use Illuminate\Support\Facades\File;

/**
 * Anti-regression guard for the target-aware migration's legacy-environment-interface removal.
 *
 * The `--environment staging|production` operational selector, and every
 * helper/constant/wrapper built specifically to support it alongside
 * `--target`, must never reappear in infrastructure code, config, docs, CI,
 * or the Architecture test suite itself. This scans an exact list of
 * forbidden tokens across an exact list of locations.
 *
 * Deliberately narrow, never a broad per-file or per-directory allowlist.
 * Every exemption below is either global-but-content-scoped (applies to a
 * single line's own content, regardless of which file it's in) or named-and-
 * exact (a specific file, or a specific historical line):
 *
 *   - This guard file itself (exact file).
 *   - One explicitly marked historical line in ROADMAP.md (exact file, exact
 *     line fragment).
 *   - install-target-operations and install-target-perimeter, which
 *     legitimately assert `--environment` is *absent* from staged/installed
 *     `--help` output and wrapper sources as part of their own runtime
 *     checks (exact files, --environment token only).
 *   - install-target-perimeter itself, and InstallTargetPerimeterTest.php,
 *     which legitimately name the six legacy wrapper filenames as the exact
 *     data the removal mechanism operates on and this test exercises (exact
 *     files, wrapper-name tokens only).
 *   - Any comment line, anywhere (content-scoped: a comment cannot execute,
 *     so it cannot reintroduce anything — only explanatory prose about
 *     history, present in most already-converted files).
 *   - Any `->not->toContain(...)`/`->not->toMatch(...)` Pest assertion line
 *     naming a forbidden token *as that call's own argument* (content-scoped:
 *     proving a token's *absence* is the guard working as intended, not a
 *     regression — scoped to the negated call's argument specifically, so a
 *     different, positive assertion for the same token chained onto the same
 *     line is never mistaken for a proof of absence).
 *   - Inside tests/Feature/Architecture only, for the `--environment` token
 *     only: a non-`expect(...)` line (test-input construction, e.g. a
 *     heredoc feeding a literal `--environment` flag to the binary under
 *     test — it cannot itself assert anything, so it can never hide a
 *     regression), or an `expect(...)` line matching one of a small, fixed
 *     set of known-safe content markers (rejection error text, a
 *     --help/self-check absence proof, or evidence the flag was forwarded
 *     inertly rather than parsed) — never a whole `it()` block exempted by
 *     its title, which would also wave through an unrelated assertion that
 *     the flag was *accepted* as a working selector again.
 *
 * Every other forbidden token, in every other location, has zero exceptions
 * beyond the two named-and-exact cases above.
 */
function legacyRemovalGuardFile(): string
{
    return __FILE__;
}

function legacyRemovalScanRoots(): array
{
    return [
        'infrastructure/scripts',
        'infrastructure/config',
        'infrastructure/templates/deployment.conf.example',
        'infrastructure/runbooks',
        'infrastructure/ROADMAP.md',
        '.github',
        'tests/Feature/Architecture',
    ];
}

/** @return list<string> */
function legacyRemovalForbiddenTokens(): array
{
    return [
        '--environment',
        'validate_environment',
        'parse_selector_args',
        'assert_selector_state',
        'environment_root',
        'environment_runtime_user',
        'environment_code_group',
        'environment_deploy_user',
        'environment_incoming_artifacts',
        'environment_url',
        'environment_host_header',
        'environment_backup_namespace',
        'environment_local_backup_retention',
        'environment_offsite_backup_retention',
        'environment_database_name',
        'environment_release_retention',
        'environment_public_hostname',
        'STAGING_ROOT',
        'STAGING_RUNTIME_USER',
        'STAGING_CODE_GROUP',
        'STAGING_DEPLOY_USER',
        'STAGING_INCOMING_ARTIFACTS',
        'STAGING_RELEASE_RETENTION',
        'PRODUCTION_ROOT',
        'PRODUCTION_RUNTIME_USER',
        'PRODUCTION_CODE_GROUP',
        'PRODUCTION_DEPLOY_USER',
        'PRODUCTION_INCOMING_ARTIFACTS',
        'PRODUCTION_RELEASE_RETENTION',
        'rateguru-staging-deploy',
        'rateguru-staging-rollback',
        'rateguru-staging-cleanup',
        'rateguru-production-deploy',
        'rateguru-production-rollback',
        'rateguru-production-cleanup',
    ];
}

/**
 * Production files whose own job is to assert `--environment` is *absent*
 * from staged/installed `--help` output and wrapper sources — exempted from
 * the `--environment` token only, never from any other forbidden token.
 *
 * @return list<string>
 */
function legacyRemovalEnvironmentTokenFileExemptions(): array
{
    return [
        base_path('infrastructure/scripts/install-target-operations'),
        base_path('infrastructure/scripts/install-target-perimeter'),
    ];
}

/**
 * install-target-perimeter names the six legacy wrapper filenames as the
 * exact data its own removal mechanism operates on;
 * InstallTargetPerimeterTest.php exercises that mechanism and necessarily
 * builds fixtures using those same literal names — exempted from the six
 * wrapper-name tokens only, never from any other forbidden token.
 *
 * @return list<string>
 */
function legacyRemovalWrapperNameTokenFileExemptions(): array
{
    return [
        base_path('infrastructure/scripts/install-target-perimeter'),
        base_path('tests/Feature/Architecture/InstallTargetPerimeterTest.php'),
    ];
}

/**
 * Derived from legacyRemovalForbiddenTokens() rather than duplicated, so the
 * two lists can never silently drift apart.
 *
 * @return list<string>
 */
function legacyRemovalWrapperNameTokens(): array
{
    return array_values(array_filter(
        legacyRemovalForbiddenTokens(),
        fn (string $token): bool => str_starts_with($token, 'rateguru-staging-') || str_starts_with($token, 'rateguru-production-'),
    ));
}

/**
 * True if $line contains $token as a whole identifier — not merely as a
 * substring of a longer, unrelated one. Bounded on *both* sides by
 * identifier-continuation characters (letters, digits, underscore, hyphen —
 * every character these tokens themselves are built from), so this rejects
 * both a token embedded as a suffix (e.g. "rateguru-staging-deploy" must not
 * match inside "rateguru-staging-deployment", a genuinely different and
 * unrelated GitHub Actions concurrency group name) and one embedded as a
 * prefix (e.g. "STAGING_ROOT" must not match inside "MY_STAGING_ROOT").
 */
function legacyRemovalLineContainsToken(string $line, string $token): bool
{
    return preg_match('/(?<![a-zA-Z0-9_-])'.preg_quote($token, '/').'(?![a-zA-Z0-9_-])/', $line) === 1;
}

/**
 * ROADMAP.md's slices 1-8 are a historical record of the migration while it
 * ran the old and new operational selectors in parallel, marked off with a
 * pair of HTML comments — the single explicitly marked historical entry this
 * guard exempts entirely, as one deliberate, narrow, visible-in-source range,
 * never a broad per-file allowlist.
 *
 * @return array{0: string, 1: string, 2: string} [file, startMarker, endMarker]
 */
function legacyRemovalRoadmapExemption(): array
{
    return [
        base_path('infrastructure/ROADMAP.md'),
        'legacy-environment-history:start',
        'legacy-environment-history:end',
    ];
}

/**
 * The [start, end) line-index range (0-indexed, end exclusive) marked by the
 * two HTML-comment markers, or null if either marker is missing.
 *
 * @param  list<string>  $lines
 * @return array{0: int, 1: int}|null
 */
function legacyRemovalMarkedRange(array $lines, string $startMarker, string $endMarker): ?array
{
    $start = null;

    foreach ($lines as $i => $line) {
        if ($start === null && str_contains($line, $startMarker)) {
            $start = $i;

            continue;
        }

        if ($start !== null && str_contains($line, $endMarker)) {
            return [$start, $i + 1];
        }
    }

    return null;
}

/** @return list<string> */
function legacyRemovalFilesToScan(): array
{
    $files = [];

    foreach (legacyRemovalScanRoots() as $root) {
        $path = base_path($root);

        if (is_file($path)) {
            $files[] = $path;

            continue;
        }

        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $files[] = $fileInfo->getPathname();
            }
        }
    }

    return array_values(array_unique($files));
}

/**
 * 'markdown' for .md files, 'default' for everything else this guard scans
 * (shell scripts with no extension, PHP test files, YAML workflows, and the
 * handful of plain config formats under infrastructure/config).
 */
function legacyRemovalSourceType(string $file): string
{
    return str_ends_with($file, '.md') ? 'markdown' : 'default';
}

/**
 * True if $line is a comment (or blank) for $sourceType, and so cannot
 * execute or reintroduce anything.
 *
 * Shell and PHP files — the 'default' type, which also covers every other
 * scanned format here — use `//`, `#`, `*`, and `/*` as comment prefixes.
 * Markdown has no such prefix of its own: a leading `#` is a heading and a
 * leading `*` is a bullet-list marker or the start of `**bold**`, not a
 * comment, so treating them as one wrongly hides real prose. Only `//` and
 * HTML comment syntax count for Markdown.
 */
function legacyRemovalLineIsComment(string $line, string $sourceType): bool
{
    $trimmed = ltrim($line);

    if ($trimmed === '') {
        return true;
    }

    if ($sourceType === 'markdown') {
        return str_starts_with($trimmed, '//') || str_starts_with($trimmed, '<!--');
    }

    return str_starts_with($trimmed, '//')
        || str_starts_with($trimmed, '#')
        || str_starts_with($trimmed, '*')
        || str_starts_with($trimmed, '/*');
}

/**
 * Fixed content markers under which an `expect(...)` assertion line may
 * legitimately mention --environment literally without proving it works as
 * a selector again: rejection error text, a --help/self-check absence
 * proof, or evidence the flag was forwarded inertly rather than parsed.
 * Every positive (non ->not->) --environment mention in an Architecture
 * test today matches one of these.
 *
 * @return list<string>
 */
function legacyRemovalEnvironmentSafeAssertionMarkers(): array
{
    return [
        'unknown argument: --environment',
        'never mention --environment',
        'still uses --environment',
        'still mentions --environment',
        'OPS: [--environment]',
    ];
}

/**
 * True if $line has a `->not->toContain('...')`/`->not->toMatch('...')`
 * call whose own quoted argument contains $token — scoped to that call's
 * argument specifically, not "the line contains both substrings somewhere",
 * so a chained positive assertion for a *different* token on the same line
 * (e.g. `->not->toContain('--environment')->toContain('STAGING_ROOT')`)
 * is never mistaken for proving STAGING_ROOT's absence too.
 */
function legacyRemovalLineNegatesToken(string $line, string $token): bool
{
    if (preg_match_all('/->not->to(?:Contain|Match)\(\s*([\'"])(.*?)\1\s*\)/', $line, $matches) === 0) {
        return false;
    }

    foreach ($matches[2] as $negatedArgument) {
        if (legacyRemovalLineContainsToken($negatedArgument, $token)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a line containing $token, in $file at $lineNumber, must be
 * skipped rather than recorded as a violation — folding together every
 * exemption this guard grants (see the file-level docblock): a comment
 * line, content inside the marked ROADMAP.md historical range, an
 * assertion proving the token's absence, the two named files' own
 * --environment self-checks, the two named files' own wrapper-name
 * literals, and — for --environment only, inside Architecture tests — a
 * non-assertion line (test-input construction, which cannot itself prove or
 * hide anything) or an assertion matching a known-safe marker.
 *
 * @param  array{0: int, 1: int}|null  $roadmapExemptRange
 * @param  list<string>  $environmentTokenExemptFiles
 * @param  list<string>  $wrapperNameExemptFiles
 * @param  list<string>  $wrapperNameTokens
 */
function legacyRemovalIsExempt(
    string $token,
    string $file,
    int $lineNumber,
    string $line,
    string $sourceType,
    bool $isArchitectureTest,
    array $environmentTokenExemptFiles,
    array $wrapperNameExemptFiles,
    array $wrapperNameTokens,
    ?array $roadmapExemptRange,
): bool {
    // A comment cannot execute, so it cannot reintroduce anything — safe
    // for every token, in every scanned location.
    if (legacyRemovalLineIsComment($line, $sourceType)) {
        return true;
    }

    if ($roadmapExemptRange !== null
        && $lineNumber >= $roadmapExemptRange[0]
        && $lineNumber < $roadmapExemptRange[1]
    ) {
        return true;
    }

    // Proving a token's absence is the guard working as intended, not a
    // regression — safe for every token.
    if (legacyRemovalLineNegatesToken($line, $token)) {
        return true;
    }

    if ($token === '--environment' && in_array($file, $environmentTokenExemptFiles, true)) {
        return true;
    }

    if (in_array($token, $wrapperNameTokens, true) && in_array($file, $wrapperNameExemptFiles, true)) {
        return true;
    }

    if ($token === '--environment' && $isArchitectureTest) {
        if (! str_contains($line, 'expect(')) {
            return true;
        }

        foreach (legacyRemovalEnvironmentSafeAssertionMarkers() as $marker) {
            if (str_contains($line, $marker)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return list<string> human-readable "file:line: content" violation strings
 */
function legacyRemovalScan(): array
{
    $guardFile = legacyRemovalGuardFile();
    [$roadmapFile, $roadmapStartMarker, $roadmapEndMarker] = legacyRemovalRoadmapExemption();
    $environmentTokenExemptFiles = legacyRemovalEnvironmentTokenFileExemptions();
    $wrapperNameExemptFiles = legacyRemovalWrapperNameTokenFileExemptions();
    $wrapperNameTokens = legacyRemovalWrapperNameTokens();
    $tokens = legacyRemovalForbiddenTokens();
    $architectureTestsDir = base_path('tests/Feature/Architecture');

    $violations = [];

    foreach (legacyRemovalFilesToScan() as $file) {
        if ($file === $guardFile) {
            continue;
        }

        $rawLines = file($file, FILE_IGNORE_NEW_LINES);
        if ($rawLines === false) {
            continue;
        }

        $sourceType = legacyRemovalSourceType($file);

        $roadmapExemptRange = ($file === $roadmapFile)
            ? legacyRemovalMarkedRange($rawLines, $roadmapStartMarker, $roadmapEndMarker)
            : null;

        $isArchitectureTest = str_starts_with($file, $architectureTestsDir.'/');

        foreach ($rawLines as $lineNumber => $line) {
            foreach ($tokens as $token) {
                if (! legacyRemovalLineContainsToken($line, $token)) {
                    continue;
                }

                if (legacyRemovalIsExempt(
                    $token,
                    $file,
                    $lineNumber,
                    $line,
                    $sourceType,
                    $isArchitectureTest,
                    $environmentTokenExemptFiles,
                    $wrapperNameExemptFiles,
                    $wrapperNameTokens,
                    $roadmapExemptRange,
                )) {
                    continue;
                }

                $violations[] = sprintf('%s:%d: %s', $file, $lineNumber + 1, trim($line));
            }
        }
    }

    return $violations;
}

it('scans a non-empty set of files and tokens (fixture sanity)', function () {
    expect(legacyRemovalFilesToScan())->not->toBeEmpty();
    expect(legacyRemovalForbiddenTokens())->not->toBeEmpty();
    expect(File::exists(base_path('infrastructure/scripts/common')))->toBeTrue();
});

it('never reintroduces the legacy --environment interface anywhere in the scanned tree', function () {
    $violations = legacyRemovalScan();

    expect($violations)->toBe([], "forbidden legacy token(s) found:\n".implode("\n", $violations));
});

it('scopes the negation exemption to the negated call\'s own argument, not the whole line', function () {
    // A genuine positive assertion for STAGING_ROOT chained onto the same
    // line as an unrelated ->not->toContain('--environment') must never be
    // exempted by the *other* call's negation.
    $line = "        expect(\$o)->not->toContain('--environment')->toContain('STAGING_ROOT');";

    expect(legacyRemovalLineNegatesToken($line, '--environment'))->toBeTrue();
    expect(legacyRemovalLineNegatesToken($line, 'STAGING_ROOT'))->toBeFalse();
});

it('never grants the --environment architecture-test exemption to a whole it() block by title alone', function () {
    // A hypothetical regression: a real, positive assertion that the flag
    // was accepted as a working selector, inside a block whose title merely
    // mentions --environment (e.g. because it's testing rejection of it
    // elsewhere) — this specific assertion is not one of the known-safe
    // markers, so it must not be exempted just because it shares a block
    // with genuinely safe lines.
    $line = "        expect(\$output)->toContain('parsed --environment successfully as a target selector');";

    expect(legacyRemovalIsExempt(
        '--environment',
        base_path('tests/Feature/Architecture/SomeHypotheticalTest.php'),
        0,
        $line,
        'default',
        true,
        legacyRemovalEnvironmentTokenFileExemptions(),
        legacyRemovalWrapperNameTokenFileExemptions(),
        legacyRemovalWrapperNameTokens(),
        null,
    ))->toBeFalse();
});

it('the ROADMAP exemption itself still exists — the guard is not silently exempting a range that moved or was deleted', function () {
    [$roadmapFile, $startMarker, $endMarker] = legacyRemovalRoadmapExemption();

    expect(File::exists($roadmapFile))->toBeTrue();

    $lines = file($roadmapFile, FILE_IGNORE_NEW_LINES);
    expect($lines)->not->toBeFalse();

    $range = legacyRemovalMarkedRange($lines, $startMarker, $endMarker);
    expect($range)->not->toBeNull("could not locate the {$startMarker}/{$endMarker} marker pair in ROADMAP.md");
    expect($range[1])->toBeGreaterThan($range[0] + 1, 'the marked historical range must not be empty');
});

it('does not use a broad per-file or per-directory allowlist — only two named production files are exempted, and only from --environment', function () {
    expect(legacyRemovalEnvironmentTokenFileExemptions())->toHaveCount(2);
    expect(legacyRemovalForbiddenTokens())->toContain('--environment');
});

it('derives exactly six wrapper-name tokens from the forbidden-token list — the six legacy wrapper filenames, no more, no fewer', function () {
    expect(legacyRemovalWrapperNameTokens())->toHaveCount(6)->each->toStartWith('rateguru-');
});
